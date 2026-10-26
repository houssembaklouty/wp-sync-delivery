<?php
/**
 * Intégration Colissimo TN (colissimodelivery.tn).
 *
 * API v1 (https://colissimodelivery.tn/api/v1/post.php) : ajout de colis via
 * token (compte expéditeur), réponse JSON {status, lien, lien_zebra,
 * status_message = code à barres}. Gouvernorats avec orthographe exacte
 * imposée (Béja→Beja, La Manouba→Mannouba…), téléphone = 8 chiffres.
 *
 * Legacy SOAP (wsColissimoGo) conservé UNIQUEMENT pour les colis créés
 * avant la migration : suivi getColis, suppression SupprimerColis,
 * étiquette getColisPdf.
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Colissimo_Provider implements WCMCS_ShippingProviderInterface {
    use WCMCS_Field_Mapping;

    const SLUG  = 'colissimo';
    const LABEL = 'Colissimo TN';

    /** API v1 — ajout de colis (token compte expéditeur). */
    const API_URL = 'https://colissimodelivery.tn/api/v1/post.php';

    /** Legacy SOAP — colis créés avant la migration uniquement. */
    const WSDL_URL     = 'http://delivery.colissimo.com.tn/wsColissimoGo/wsColissimoGo.asmx?wsdl';
    const ENDPOINT_URL = 'http://delivery.colissimo.com.tn/wsColissimoGo/wsColissimoGo.asmx';

    /**
     * Gouvernorats — orthographe EXACTE imposée par la doc API v1
     * (sans accents : Beja, Gabes… ; « LE Kef », « Mannouba »).
     */
    const GOVERNORATS = [
        'Ariana', 'Beja', 'Ben Arous', 'Bizerte', 'Gabes',
        'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kebili',
        'LE Kef', 'Mahdia', 'Mannouba', 'Medenine', 'Monastir',
        'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
        'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
    ];

    /** Alias normalisés (clé = valeur d'entrée normalisée) → liste stricte. */
    const GOV_ALIASES = [
        'manouba'   => 'mannouba',
        'lamanouba' => 'mannouba',
        'kef'       => 'lekef',
    ];

    /** type_colis : 1 = petit / 2 = moyen / 3 = grand (doc API v1). */
    const TYPE_COLIS_OPTIONS = [
        1 => '1 — Petit',
        2 => '2 — Moyen',
        3 => '3 — Grand',
    ];

    /** États de suivi documentés par l'API v1 (tracking). */
    const TRACKING_STATES = [
        'En attente', 'En cours', 'Livre', 'Echange', 'Retour Expediteur',
        'Supprime', 'Retour Inter Agence', 'Au depot', 'Non recu', 'Livre paye',
        'Retour depot', 'A enlever', 'Enleve', 'A verifier', 'Retour paye',
        'Retour definitif', 'Retour recu paye', 'Inconnu',
    ];

    // ─────────────────────────────────────────────
    //  Mapping par défaut des champs Colissimo TN
    // ─────────────────────────────────────────────

    const FIELD_DEFAULTS = [
        'client'       => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
        'adresse'      => [ 'source' => 'full_address',        'fallback' => 'billing_full_address' ],
        'ville'        => [ 'source' => 'shipping_city',       'fallback' => 'billing_city' ],
        'gouvernorat'  => [ 'source' => 'shipping_state',      'fallback' => 'billing_state' ],
        'tel1'         => [ 'source' => 'shipping_phone',       'fallback' => 'billing_phone' ],
        'tel2'         => [ 'source' => '',                    'fallback' => '' ],
        'commentaire'  => [ 'source' => '',      'fallback' => '' ],
    ];

    /** @var string Token API v1 (compte expéditeur). */
    private string $token;

    /** @var string Legacy : identifiant SOAP (colis pré-migration). */
    private string $utilisateur;

    /** @var string Legacy : mot de passe SOAP. */
    private string $pass;

    /** @var int type_colis (1/2/3). */
    private int $type_colis;

    /** @var int Options colis : ouvrir (0/1). */
    private int $ouvrir;

    /** @var int Options colis : fragile (0/1). */
    private int $fragile;

    /** @var string Options colis : échange (oui/non). */
    private string $echange;

    /** @var string URL de suivi API v1 (optionnel, à ce jour non documentée). */
    private string $tracking_url;

    /** @var int Timeout HTTP. */
    private int $http_timeout;

    public function __construct() {
        // Token API v1 — migration automatique depuis l'ancien champ « Mot de
        // passe » que le transporteur réutilisait pour le compte expéditeur.
        $this->token = (string) get_option( 'wcmcs_colissimo_token', '' );
        if ( '' === $this->token ) {
            $legacy_pass = (string) get_option( 'wcmcs_colissimo_pass', '' );
            if ( '' !== $legacy_pass ) {
                $this->token = $legacy_pass;
                update_option( 'wcmcs_colissimo_token', $legacy_pass );
            }
        }

        // Legacy SOAP (suivi/annulation/étiquette des colis pré-migration).
        $this->utilisateur = (string) get_option( 'wcmcs_colissimo_utilisateur', '' );
        $this->pass        = (string) get_option( 'wcmcs_colissimo_pass', '' );

        // type_colis : migration des anciens codes VO/VM/GV/EXP → 1/2/3
        // (doc API v1 : 1 = petit · 2 = moyen · 3 = grand ; anciens types :
        // VO = petit, VM = moyen, GV = grand, EXP sans équivalent → petit).
        $this->type_colis = (int) get_option( 'wcmcs_colissimo_type_colis', 0 );
        if ( 0 === $this->type_colis ) {
            $old_type        = strtoupper( (string) get_option( 'wcmcs_colissimo_default_type', 'VO' ) );
            $legacy_map      = [ 'VO' => 1, 'VM' => 2, 'GV' => 3 ];
            $this->type_colis = $legacy_map[ $old_type ] ?? 1;
            update_option( 'wcmcs_colissimo_type_colis', $this->type_colis );
        }

        $this->ouvrir       = ( 'oui' === (string) get_option( 'wcmcs_colissimo_ouvrir', 'non' ) ) ? 1 : 0;
        $this->fragile      = ( 'oui' === (string) get_option( 'wcmcs_colissimo_fragile', 'non' ) ) ? 1 : 0;
        $this->echange      = ( 'oui' === (string) get_option( 'wcmcs_colissimo_echange', 'non' ) ) ? 'oui' : 'non';
        $this->tracking_url = trim( (string) get_option( 'wcmcs_colissimo_tracking_url', '' ) );
        $this->http_timeout = (int) apply_filters( 'wcmcs_colissimo_timeout', 30 );
    }

    // ─────────────────────────────────────────────
    //  Interface impl
    // ─────────────────────────────────────────────

    public function get_slug(): string {
        return self::SLUG;
    }

    public function mapping_option_prefix(): string {
        return 'wcmcs_colissimo_map_';
    }

    public function get_label(): string {
        return self::LABEL;
    }

    public function is_active(): bool {
        return true;
    }

    public function is_configured(): bool {
        return '' !== $this->token;
    }

    public function get_settings_fields(): array {
        $fields = [
            [
                'id'    => 'wcmcs_colissimo_header_v1',
                'type'  => 'title',
                'label' => '',
                'title' => __( 'Compte Colissimo (API v1 — colissimodelivery.tn)', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_colissimo_token',
                'label'       => __( 'Token API', 'wc-multicarrier-sync' ),
                'type'        => 'password',
                'description' => __( 'Token de votre compte expéditeur (API AjouterColiS : colissimodelivery.tn/expediteur/api.php). Migration automatique depuis l\'ancien champ « Mot de passe » si présent.', 'wc-multicarrier-sync' ),
            ],
            [
                'id'    => 'wcmcs_colissimo_header_legacy',
                'type'  => 'title',
                'label' => '',
                'title' => __( 'Anciens identifiants SOAP (colis créés avant la migration)', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_colissimo_utilisateur',
                'label'       => __( 'Identifiant legacy', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'description' => __( 'Sert uniquement au suivi / annulation / étiquette des colis créés via l\'ancienne API SOAP.', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_colissimo_pass',
                'label'       => __( 'Mot de passe legacy', 'wc-multicarrier-sync' ),
                'type'        => 'password',
            ],
            [
                'id'          => 'wcmcs_colissimo_type_colis',
                'label'       => __( 'Type de colis par défaut', 'wc-multicarrier-sync' ),
                'type'        => 'select',
                'options'     => self::TYPE_COLIS_OPTIONS,
            ],
            [
                'id'          => 'wcmcs_colissimo_ouvrir',
                'label'       => __( 'Ouvrir le colis en livraison', 'wc-multicarrier-sync' ),
                'type'        => 'select',
                'options'     => [ 'non' => 'Non', 'oui' => 'Oui' ],
            ],
            [
                'id'          => 'wcmcs_colissimo_fragile',
                'label'       => __( 'Colis fragile', 'wc-multicarrier-sync' ),
                'type'        => 'select',
                'options'     => [ 'non' => 'Non', 'oui' => 'Oui' ],
            ],
            [
                'id'          => 'wcmcs_colissimo_echange',
                'label'       => __( 'Échange', 'wc-multicarrier-sync' ),
                'type'        => 'select',
                'options'     => [ 'non' => 'Non', 'oui' => 'Oui' ],
                'description' => __( '« Oui » active l\'échange à la livraison (l\'API attend la désignation côté article, non géré pour l\'instant).', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_colissimo_tracking_url',
                'label'       => __( 'URL du suivi (optionnel — API v1)', 'wc-multicarrier-sync' ),
                'type'        => 'text',
                'placeholder' => 'https://colissimodelivery.tn/api/v1/…',
                'description' => __( 'La doc API v1 publie les états mais ne documente pas l\'endpoint de suivi. Renseignez ici l\'URL fournie par le transporteur (code+token passés en query) ; sinon le suivi des nouveaux colis reste indisponible et l\'ancien SOAP sert de repli aux colis legacy.', 'wc-multicarrier-sync' ),
            ],
        ];

        // ── Section Mapping des champs ──────────
        $fields[] = [
            'id'    => 'wcmcs_colissimo_mapping_header',
            'type'  => 'title',
            'label' => '',
            'title' => __( 'Mapping des champs WooCommerce → Colissimo TN', 'wc-multicarrier-sync' ),
        ];

        $field_labels = [
            'client'      => __( 'Client (nom)', 'wc-multicarrier-sync' ),
            'adresse'     => __( 'Adresse', 'wc-multicarrier-sync' ),
            'ville'       => __( 'Ville', 'wc-multicarrier-sync' ),
            'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
            'tel1'        => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
            'tel2'        => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
            'commentaire' => __( 'Note / Commentaire', 'wc-multicarrier-sync' ),
        ];

        foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
        	if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
        		$fields[] = $this->note_settings_field();
        		continue;
        	}
            $fields[] = [
                'id'               => 'wcmcs_colissimo_map_' . $field_key,
                'label'            => $field_labels[ $field_key ],
                'type'             => 'mapping_row',
                'options'          => $this->wc_field_options(),
                'default'          => $defaults['source'],
                'fallback_id'      => 'wcmcs_colissimo_map_' . $field_key . '_fallback',
                'fallback_default' => $defaults['fallback'],
                'description'      => __( 'Champ source et champ de secours si vide.', 'wc-multicarrier-sync' ),
            ];
        }

        return $fields;
    }

    // ─────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────

    public function validate_order( WC_Order $order ) {
        $errors = new WP_Error();

        $required_fields = [
            'client'       => __( 'Nom du client', 'wc-multicarrier-sync' ),
            'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
            'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
            'gouvernorat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
            'tel1'         => __( 'Téléphone', 'wc-multicarrier-sync' ),
        ];

        foreach ( $required_fields as $field_key => $label ) {
            $value = $this->get_mapped_field( $order, $field_key );
            if ( empty( $value ) ) {
                $errors->add( 'missing_' . $field_key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
            }
        }

        // Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
        // valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).

        // Validation : téléphone = 8 chiffres exactement (contrainte API v1).
        // Normalisation SANS troncature arbitraire : seul le préfixe 216/00216
        // est strippé (11/13 chiffres) — un numéro erroné (>8 chiffres, sans
        // aucun chiffre) est bloqué ICI avec un message clair, au lieu d'être
        // tronqué silencieusement ou envoyé vide à l'API.
        if ( ! $errors->has_errors() ) {
            foreach ( [ 'tel1', 'tel2' ] as $tel_key ) {
                $tel = $this->get_mapped_field( $order, $tel_key );
                if ( '' === $tel ) {
                    continue; // tel2 facultatif
                }
                $digits = $this->normalize_tel( $tel );
                if ( '' === $digits || ! ctype_digit( $digits ) || 8 !== strlen( $digits ) ) {
                    $errors->add(
                        'invalid_tel',
                        sprintf(
                            __( 'Téléphone "%s" : l\'API Colissimo attend exactement 8 chiffres.', 'wc-multicarrier-sync' ),
                            $tel
                        )
                    );
                }
            }
        }

        if ( (float) $order->get_total() <= 0 ) {
            $errors->add( 'zero_total', __( 'Le montant total de la commande est nul.', 'wc-multicarrier-sync' ) );
        }

        if ( $errors->has_errors() ) {
            return new WP_Error( 'validation_failed', implode( ' | ', $errors->get_error_messages() ) );
        }

        return true;
    }

    // ─────────────────────────────────────────────
    //  Résolution du gouvernorat
    // ─────────────────────────────────────────────

    /**
     * Résout le gouvernorat : constante stricte key→value de la doc
     * Colissimo (orthographe exacte : Beja, Gabes, LE Kef, Mannouba…).
     * Hors liste → erreur bloquante (jamais de valeur brute envoyée).
     *
     * @param WC_Order $order
     * @return string|WP_Error  Le gouvernorat exact (orthographe API) ou WP_Error.
     */
    private function resolve_gouvernorat( WC_Order $order ) {
        $state_field = $this->get_mapped_field( $order, 'gouvernorat' );
        $ville_field = $this->get_mapped_field( $order, 'ville' );

        $gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'colissimo' );
        if ( null !== $gouvernorat ) {
            return $gouvernorat;
        }

        $raw = WCMCS_Localization::translate_gouvernorat( $state_field, $ville_field );
        return new WP_Error(
            'gouvernorat_invalide',
            sprintf(
                /* translators: 1: valeur reçue, 2: liste officielle */
                __( 'Gouvernorat invalide « %1$s » — hors liste Colissimo. Liste officielle : %2$s. Corrigez l\'adresse de livraison de la commande.', 'wc-multicarrier-sync' ),
                '' !== $raw ? $raw : '(vide)',
                implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'colissimo' ) )
            )
        );
    }

    /**
     * Cherche une correspondance insensible à la casse et aux accents,
     * puis passe par les alias (Mannouba, LE Kef…).
     *
     * @param string $input
     * @return string|false  Le gouvernorat correspondant ou false.
     */
    private function match_gouvernorat( string $input ) {
        $normalized_input = $this->normalize( $input );

        foreach ( self::GOVERNORATS as $gouv ) {
            if ( $this->normalize( $gouv ) === $normalized_input ) {
                return $gouv;
            }
        }

        foreach ( self::GOV_ALIASES as $alias => $canonical_norm ) {
            if ( $normalized_input === $alias ) {
                foreach ( self::GOVERNORATS as $gouv ) {
                    if ( $this->normalize( $gouv ) === $canonical_norm ) {
                        return $gouv;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Normalise une chaîne : minuscules, suppression des accents.
     *
     * @param string $str
     * @return string
     */
    private function normalize( string $str ): string {
        $str = mb_strtolower( $str, 'UTF-8' );
        $str = remove_accents( $str );
        $str = preg_replace( '/[^a-z0-9]/', '', $str );
        return (string) $str;
    }

    /**
     * Normalise un téléphone tunisien : chiffres seuls, préfixe 216/00216
     * strippé. Aucune troncature arbitraire — la validation exige ensuite
     * 8 chiffres exacts (doc API v1).
     *
     * @param string $tel
     * @return string Chiffres (peut être '' ou ≠ 8 — la validation tranche).
     */
    private function normalize_tel( string $tel ): string {
        $digits = preg_replace( '/\D+/', '', (string) $tel );
        $digits = (string) $digits;

        // Préfixe international tunisien : 216 (11 chiffres) / 00216 (13 chiffres).
        // AUCUNE troncature arbitraire : un numéro >8 chiffres sans préfixe
        // connu reste tel quel et est bloqué par la validation (8 chiffres exacts).
        if ( strlen( $digits ) === 11 && 0 === strpos( $digits, '216' ) ) {
            $digits = substr( $digits, 3 );
        } elseif ( strlen( $digits ) === 13 && 0 === strpos( $digits, '00216' ) ) {
            $digits = substr( $digits, 5 );
        }

        return $digits;
    }

    // ─────────────────────────────────────────────
    //  Création d'expédition (API v1 — POST JSON)
    // ─────────────────────────────────────────────

    public function create_shipment( WC_Order $order ) {
        $payload = $this->build_shipment_payload( $order );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        $payload = apply_filters( 'wcmcs_colissimo_payload', $payload, $order );

        // Token jamais loggé.
        $log_payload = $payload;
        unset( $log_payload['token'] );
        WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers Colissimo API v1 (post.php)' );
        WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Colissimo v1 payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $log_payload ) ) );

        $as_json = (bool) apply_filters( 'wcmcs_colissimo_post_json', false, $order );
        if ( $as_json ) {
            $args = [
                'method'  => 'POST',
                'timeout' => $this->http_timeout,
                'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
                'body'    => wp_json_encode( $payload ),
            ];
        } else {
            // Défaut : POST form-urlencoded (compte expéditeur PHP).
            $args = [
                'method'  => 'POST',
                'timeout' => $this->http_timeout,
                'body'    => $payload,
            ];
        }

        $response = wp_remote_post( self::API_URL, $args );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            WCMCS_Logger::error( "Colissimo v1 HTTP error: {$msg} | URL: " . self::API_URL );
            return new WP_Error( 'colissimo_http_error', sprintf( __( 'Colissimo : erreur HTTP — %s', 'wc-multicarrier-sync' ), $msg ) );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        WCMCS_Logger::info( "Colissimo v1 response [{$status}]" );
        WCMCS_Logger::debug( "Colissimo v1 response body: " . WCMCS_Logger::redact_body( $raw ) );

        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            $snippet = strlen( $raw ) ? substr( wp_strip_all_tags( $raw ), 0, 200 ) : __( 'réponse vide', 'wc-multicarrier-sync' );
            return new WP_Error(
                'colissimo_invalid_response',
                sprintf( __( 'Colissimo : réponse inattendue (HTTP %d). Vérifiez le token. Détail : %s', 'wc-multicarrier-sync' ), $status, $snippet ),
                [ 'http_status' => $status, 'body' => substr( $raw, 0, 2000 ) ]
            );
        }

        $decoded = apply_filters( 'wcmcs_colissimo_result', $decoded, $order );

        if ( ! ( isset( $decoded['status'] ) && (int) $decoded['status'] === 1 ) ) {
            $msg = (string) ( $decoded['status_message'] ?? '' );
            if ( '' === $msg ) {
                $msg = wp_json_encode( $decoded );
            }
            WCMCS_Logger::error( 'Colissimo v1 API error: ' . $msg );
            return new WP_Error(
                'colissimo_api_error',
                sprintf( __( 'Colissimo : %s', 'wc-multicarrier-sync' ), $msg ),
                [ 'raw' => $decoded ]
            );
        }

        $tracking_id = (string) ( $decoded['status_message'] ?? '' );
        if ( '' === $tracking_id ) {
            return new WP_Error(
                'no_tracking_id',
                __( 'Colissimo : aucun code à barres dans la réponse.', 'wc-multicarrier-sync' ),
                [ 'raw' => $decoded ]
            );
        }

        return [
            'tracking_id' => $tracking_id,
            'label_url'   => (string) ( $decoded['lien'] ?? '' ),
            'raw'         => $decoded,
        ];
    }

    // ─────────────────────────────────────────────
    //  Annulation (legacy SOAP — pas d'annulation en API v1)
    // ─────────────────────────────────────────────

    public function cancel_shipment( string $tracking_id, WC_Order $order ) {
        $result = $this->soap_request( 'SupprimerColis', [ 'code_barre' => $tracking_id ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parsed = $this->parse_soap_result( $result, 'SupprimerColis' );
        if ( is_wp_error( $parsed ) ) {
            return new WP_Error(
                'colissimo_cancel_manual',
                sprintf(
                    /* translators: %s : code barre */
                    __( 'Colissimo : suppression de %1$s impossible via l’API (les colis créés en API v1 ne sont pas visibles par le service legacy). Supprimez-le depuis le dashboard expéditeur https://colissimodelivery.tn/expediteur/ — statut « %2$s ».', 'wc-multicarrier-sync' ),
                    $tracking_id,
                    is_wp_error( $parsed ) ? $parsed->get_error_message() : 'erreur'
                )
            );
        }

        WCMCS_Logger::info( "Colissimo TN legacy: colis {$tracking_id} supprimé avec succès." );
        return true;
    }

    // ─────────────────────────────────────────────
    //  Tracking
    // ─────────────────────────────────────────────

    /**
     * Suivi d'un colis.
     *
     * Stratégie : 1. URL de suivi API v1 si configurée (option/filtre —
     * réponse parsée souple : clé etat/status/state) · 2. Legacy SOAP
     * getColis (colis pré-migration) sinon erreur.
     *
     * @param string $tracking_id
     * @return array|WP_Error  ['state_code' => '', 'state_label' => '…', 'raw' => …]
     */
    public function get_tracking_status( string $tracking_id ) {
        if ( '' !== $this->tracking_url ) {
            $rest = $this->tracking_via_rest( $tracking_id );
            if ( ! is_wp_error( $rest ) ) {
                return $rest;
            }
            WCMCS_Logger::warning( 'Colissimo : suivi REST v1 en échec — repli SOAP legacy.' );
        }

        return $this->tracking_via_soap( $tracking_id );
    }

    private function tracking_via_rest( string $tracking_id ) {
        $url = add_query_arg(
            [
                'token' => $this->token,
                'code'  => $tracking_id,
            ],
            $this->tracking_url
        );
        $url = (string) apply_filters( 'wcmcs_colissimo_tracking_request_url', $url, $tracking_id );

        $response = wp_remote_get( $url, [ 'timeout' => $this->http_timeout ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'colissimo_tracking_http', sprintf( __( 'Colissimo : erreur HTTP suivi — %s', 'wc-multicarrier-sync' ), $response->get_error_message() ) );
        }

        $raw = wp_remote_retrieve_body( $response );

        $decoded = json_decode( $raw, true );
        $state   = '';
        if ( is_array( $decoded ) ) {
            $state = (string) ( $decoded['etat'] ?? $decoded['status'] ?? $decoded['state'] ?? '' );
        } elseif ( '' !== trim( $raw ) ) {
            $state = trim( wp_strip_all_tags( $raw ) );
        }

        $state = (string) apply_filters( 'wcmcs_colissimo_tracking_state', $state, $decoded, $tracking_id );

        if ( '' === $state ) {
            return new WP_Error( 'colissimo_tracking_empty', __( 'Colissimo : état de suivi vide dans la réponse.', 'wc-multicarrier-sync' ) );
        }

        return [
            'state_code'  => '',
            'state_label' => $state,
            'raw'         => is_array( $decoded ) ? $decoded : [ 'raw' => $raw ],
        ];
    }

    private function tracking_via_soap( string $tracking_id ) {
        $result = $this->soap_request( 'getColis', [ 'code_barre' => $tracking_id ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parsed = $this->parse_soap_result( $result, 'getColis' );

        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $state = (string) ( $parsed['etat'] ?? $parsed['state'] ?? '' );

        return [
            'state_code'  => $state,
            'state_label' => $state,
            'raw'         => $parsed,
        ];
    }

    // ─────────────────────────────────────────────
    //  Étiquette (BL API v1 = lien imprimable / legacy = PDF SOAP)
    // ─────────────────────────────────────────────

    /**
     * Retourne l'URL du fichier PDF enregistré (callsites admin attendent une URL).
     *
     * Colis v1 : le lien BL (`lien`, imprimer.php) est stocké à la création
     * dans _wcmcs_label_url. On tente de télécharger le PDF pour l'héberger
     * localement ; sinon on retourne le lien public (imprimable côté admin).
     * Colis legacy : getColisPdf SOAP (base64).
     *
     * @param string $tracking_id  Code barre du colis.
     * @param int    $order_id     ID de la commande.
     * @return string|WP_Error     URL des étiquettes ou WP_Error.
     */
    public function get_label_pdf( string $tracking_id, int $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order instanceof WC_Order ) {
            $link = (string) WCMCS_Order_Meta::get_label_url( $order );

            // Étiquette DÉJÀ sauvegardée localement (URL /wcmcs-labels/ —
            // 1er téléchargement ou sync v1) : la retourner telle quelle.
            // L'ancien code branchait alors sur le SOAP legacy, qui échoue
            // systématiquement avec un barcode v1 → chaque RÉIMPRESSION
            // était cassée alors que le PDF existe sur le disque
            // (revue de code 2.10.0). get_label_display_url() régénère le
            // nonce frais côté admin.
            if ( '' !== $link && false !== strpos( $link, '/wcmcs-labels/' ) ) {
                return $link;
            }

            if ( '' !== $link && false === strpos( $link, '/wcmcs-labels/' ) ) {
                $response = wp_remote_get( $link, [ 'timeout' => $this->http_timeout ] );

                if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
                    $body = (string) wp_remote_retrieve_body( $response );
                    if ( 0 === strpos( trim( $body ), '%PDF' ) ) {
                        $saved = $this->save_label_pdf( $tracking_id, base64_encode( $body ), $order_id );
                        if ( ! is_wp_error( $saved ) ) {
                            return $saved;
                        }
                        WCMCS_Logger::warning( 'Colissimo: sauvegarde locale du BL en échec — lien public conservé.' );
                    }
                }

                return $link;
            }
        }

        return $this->get_label_pdf_legacy( $tracking_id, $order_id );
    }

    /**
     * Legacy : PDF via SOAP getColisPdf (colis pré-migration).
     */
    private function get_label_pdf_legacy( string $tracking_id, int $order_id ) {
        $result = $this->soap_request( 'getColisPdf', [ 'code_barre' => $tracking_id ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parsed = $this->parse_soap_result( $result, 'getColisPdf' );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $pdf_data = $parsed['getColisPdfResult'] ?? $parsed['result_content'] ?? '';

        if ( is_array( $pdf_data ) ) {
            $pdf_data = $pdf_data[0] ?? '';
        }

        if ( empty( $pdf_data ) ) {
            return new WP_Error( 'no_label', __( 'Colissimo TN : étiquette PDF non disponible.', 'wc-multicarrier-sync' ) );
        }

        return $this->save_label_pdf( $tracking_id, (string) $pdf_data, $order_id );
    }

    // ─────────────────────────────────────────────
    //  Liste des gouvernorats (liste stricte doc v1)
    // ─────────────────────────────────────────────

    /**
     * @return string[]
     */
    public function list_gouvernorats(): array {
        return self::GOVERNORATS;
    }

    // ─────────────────────────────────────────────
    //  Construction du payload (API v1)
    // ─────────────────────────────────────────────

    /**
     * Construit le payload API v1 post.php.
     *
     * Champs (doc) : prix, nom, gouvernerat, ville, adresse, tel (8 chiffres),
     * tel2, designation, nb_article, msg, echange (oui/non), article,
     * nb_echange, ouvrir (0/1), fragile (0/1), type_colis (1/2/3), token.
     *
     * @return array|WP_Error  Payload, ou WP_Error si le gouvernorat est invalide.
     */
    private function build_shipment_payload( WC_Order $order ) {
        $total_qty  = 0;
        $item_names = [];
        foreach ( $order->get_items() as $item ) {
            $qty          = $item->get_quantity();
            $total_qty   += $qty;
            $item_names[] = WCMCS_Localization::item_designation_french( $item ) . ' x' . $qty;
        }

        $client      = $this->get_mapped_field( $order, 'client' );
        $adresse     = $this->get_mapped_field( $order, 'adresse' );
        $ville       = $this->get_mapped_field( $order, 'ville' );
        $tel1        = $this->get_mapped_field( $order, 'tel1' );
        $tel2        = $this->get_mapped_field( $order, 'tel2' );

        $gouvernorat = $this->resolve_gouvernorat( $order );
        if ( is_wp_error( $gouvernorat ) ) {
            WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
            return $gouvernorat;
        }

        // Ville arabe → français (fallback : gouvernorat résolu).
        $ville = WCMCS_Localization::translate_ville( $ville, $gouvernorat );

        $commentaire = $this->get_mapped_field( $order, 'commentaire' );

        return [
            'prix'        => (string) round( (float) $order->get_total(), 2 ),
            'nom'         => $client,
            'gouvernerat' => $gouvernorat, // orthographe exacte de la doc
            'ville'       => $ville,
            'adresse'     => $adresse,
            'tel'         => $this->normalize_tel( $tel1 ),
            'tel2'        => $this->normalize_tel( $tel2 ),
            'designation' => implode( '; ', $item_names ),
            'nb_article'  => max( 1, $total_qty ),
            'msg'         => $commentaire,
            'echange'     => $this->echange, // 'oui' / 'non'
            'article'     => '',
            'nb_echange'  => 0,
            'ouvrir'      => $this->ouvrir,   // 0/1
            'fragile'     => $this->fragile,  // 0/1
            'type_colis'  => $this->type_colis, // 1/2/3
            'token'       => $this->token,
        ];
    }

    // ─────────────────────────────────────────────
    //  Legacy SOAP Transport (colis pré-migration)
    // ─────────────────────────────────────────────

    /**
     * Effectue une requête SOAP vers l'API legacy Colissimo TN via
     * wp_remote_request(). Ne nécessite PAS l'extension php-soap.
     *
     * @param string $operation  Nom de l'opération SOAP (ex: getColis).
     * @param array  $params     Paramètres de l'opération.
     * @return array|WP_Error    Réponse parsée ou WP_Error.
     */
    private function soap_request( string $operation, array $params ) {
        $url         = self::ENDPOINT_URL;
        $soap_action = 'http://tempuri.org/' . $operation;

        WCMCS_Logger::info( "Colissimo legacy SOAP request: {$operation}" );
        WCMCS_Logger::debug( "Colissimo legacy SOAP params: " . wp_json_encode( $params ) );

        $xml_body = $this->build_soap_envelope( $operation, $params );

        $log_xml = preg_replace( '/<tem:AuthHeader>.*?<\/tem:AuthHeader>/s', '<tem:AuthHeader>[REDACTED]</tem:AuthHeader>', $xml_body );
        WCMCS_Logger::debug( "Colissimo legacy SOAP request XML: " . $log_xml );

        $args = [
            'method'  => 'POST',
            'timeout' => $this->http_timeout,
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => '"' . $soap_action . '"',
            ],
            'body'    => $xml_body,
        ];

        $response = wp_remote_request( $url, $args );

        // Certificat transporteur expiré/invalide : retenter AUTOMATIQUEMENT une
        // fois sans vérification SSL avant de remonter l'erreur.
        if ( is_wp_error( $response ) && $this->is_ssl_verify_error( $response ) ) {
            WCMCS_Logger::warning( 'Colissimo legacy: erreur de vérification SSL — nouvelle tentative sans vérification SSL.' );
            $args['sslverify'] = false;
            $response = wp_remote_request( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            WCMCS_Logger::error( "Colissimo legacy HTTP error: {$msg} | URL: {$url}" );
            return new WP_Error( 'colissimo_http_error', $msg );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        WCMCS_Logger::info( "Colissimo legacy SOAP response [{$status}]" );
        WCMCS_Logger::debug( "Colissimo legacy SOAP response body: " . WCMCS_Logger::redact_body( $raw ) );

        if ( stripos( $raw, '<html' ) !== false || stripos( $raw, '<!DOCTYPE' ) !== false ) {
            WCMCS_Logger::error( "Colissimo legacy: HTML response received instead of XML SOAP." );
            $summary = substr( strip_tags( $raw ), 0, 200 );
            return new WP_Error(
                'colissimo_html_response',
                sprintf( __( 'Colissimo : réponse HTML inattendue (HTTP %d). Vérifiez l\'URL de l\'API.', 'wc-multicarrier-sync' ), $status ),
                [ 'http_status' => $status, 'body_snippet' => $summary ]
            );
        }

        $fault = $this->parse_soap_fault( $raw );
        if ( $fault !== null ) {
            WCMCS_Logger::error( "Colissimo legacy SOAP fault: {$fault}" );
            return new WP_Error( 'colissimo_soap_fault', sprintf( __( 'Erreur SOAP Colissimo : %s', 'wc-multicarrier-sync' ), $fault ) );
        }

        return $this->parse_soap_xml_response( $raw, $operation );
    }

    /**
     * L'erreur concerne-t-elle la vérification du certificat SSL ?
     */
    private function is_ssl_verify_error( $error ): bool {
        $msg = is_wp_error( $error ) ? (string) $error->get_error_message() : '';
        return (bool) preg_match(
            '/cURL error (60|58|77)\b|SSL certificate|certificate (has expired|verify failed)/i',
            $msg
        );
    }

    /**
     * Construit l'envelope XML SOAP pour l'API legacy Colissimo TN.
     *
     * @param string $operation  Nom de l'opération.
     * @param array  $params     Paramètres key→value.
     * @return string            Envelope XML complet.
     */
    private function build_soap_envelope( string $operation, array $params ): string {
        $body_parts = [];
        foreach ( $params as $key => $value ) {
            $escaped_value = htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
            $body_parts[]  = '<tem:' . $key . '>' . $escaped_value . '</tem:' . $key . '>';
        }

        $body_xml = implode( "\n      ", $body_parts );

        return '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:tem="http://tempuri.org/">
  <soap:Header>
    <tem:AuthHeader>
      <tem:Uilisateur>' . htmlspecialchars( $this->utilisateur, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</tem:Uilisateur>
      <tem:Pass>' . htmlspecialchars( $this->pass, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</tem:Pass>
    </tem:AuthHeader>
  </soap:Header>
  <soap:Body>
    <tem:' . $operation . '>
      ' . $body_xml . '
    </tem:' . $operation . '>
  </soap:Body>
</soap:Envelope>';
    }

    /**
     * Détecte un SOAP Fault dans la réponse XML.
     *
     * @param string $raw_xml  Corps de la réponse HTTP.
     * @return string|null      Message du fault, ou null si aucun fault.
     */
    private function parse_soap_fault( string $raw_xml ): ?string {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $raw_xml );

        if ( $xml === false ) {
            libxml_clear_errors();
            return null;
        }

        $xml->registerXPathNamespace( 'soap', 'http://schemas.xmlsoap.org/soap/envelope/' );
        $xml->registerXPathNamespace( 'soap12', 'http://www.w3.org/2003/05/soap-envelope' );

        $fault_nodes = $xml->xpath( '//soap:Fault/faultstring | //soap12:Fault/soap12:Reason/soap12:Text | //faultstring' );

        if ( ! empty( $fault_nodes ) ) {
            return (string) $fault_nodes[0];
        }

        $fault_nodes = $xml->xpath( '//soap:Fault | //soap12:Fault' );
        if ( ! empty( $fault_nodes ) ) {
            return (string) $fault_nodes[0]->asXML();
        }

        return null;
    }

    /**
     * Parse la réponse XML SOAP legacy et extrait le résultat JSON.
     *
     * @param string $raw_xml    Corps XML de la réponse HTTP.
     * @param string $operation  Nom de l'opération.
     * @return array|WP_Error    Données parsées ou WP_Error.
     */
    private function parse_soap_xml_response( string $raw_xml, string $operation ) {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $raw_xml );

        if ( $xml === false ) {
            $errors    = libxml_get_errors();
            $error_msg = ! empty( $errors ) ? trim( $errors[0]->message ) : 'Unknown XML error';
            libxml_clear_errors();
            WCMCS_Logger::error( "Colissimo legacy XML parse error: {$error_msg}" );
            return new WP_Error(
                'colissimo_xml_error',
                sprintf( __( 'Colissimo : erreur de parsing XML (%s)', 'wc-multicarrier-sync' ), $error_msg )
            );
        }

        $xml->registerXPathNamespace( 'tem', 'http://tempuri.org/' );

        $result_key   = $operation . 'Result';
        $result_nodes = $xml->xpath( "//tem:{$result_key}" );

        if ( empty( $result_nodes ) ) {
            $result_nodes = $xml->xpath( "//*[local-name()='{$result_key}']" );
        }

        if ( empty( $result_nodes ) ) {
            WCMCS_Logger::warning( "Colissimo legacy: could not find {$result_key} in XML response, returning raw." );
            return [ 'raw' => $raw_xml ];
        }

        $result_string = (string) $result_nodes[0];

        $decoded = json_decode( $result_string, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            return $decoded;
        }

        return [ 'value' => $result_string ];
    }

    /**
     * Parse le résultat d'une opération SOAP legacy.
     *
     * @param array  $result     Réponse SOAP convertie en tableau.
     * @param string $operation  Nom de l'opération (pour le log).
     * @return array|WP_Error    result_content parsé ou WP_Error.
     */
    private function parse_soap_result( array $result, string $operation ) {
        if ( ! is_array( $result ) ) {
            $result = [ 'raw' => (string) $result ];
        }

        $result_type    = $result['result_type'] ?? '';
        $result_code    = $result['result_code'] ?? '';
        $result_content = $result['result_content'] ?? '';

        if ( is_string( $result_content ) ) {
            $nested = json_decode( $result_content, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $nested ) ) {
                $result_content = $nested;
            }
        }

        WCMCS_Logger::info( "Colissimo legacy SOAP {$operation} result: type={$result_type}, code={$result_code}" );

        if ( $result_type !== 'success' ) {
            $error_msg = is_string( $result_content ) ? $result_content : wp_json_encode( $result_content );
            WCMCS_Logger::error( "Colissimo legacy API error: type={$result_type}, code={$result_code}, content={$error_msg}" );
            return new WP_Error(
                'colissimo_api_error',
                sprintf( __( 'Colissimo : %s (%s)', 'wc-multicarrier-sync' ), $error_msg, $result_code ),
                is_array( $result_content ) ? $result_content : [ 'raw' => $result_content ]
            );
        }

        return is_array( $result_content ) ? $result_content : [ 'value' => $result_content ];
    }

    // ─────────────────────────────────────────────
    //  Sauvegarde étiquette PDF (localement, accès protégé)
    // ─────────────────────────────────────────────

    private function save_label_pdf( string $tracking_id, string $base64_pdf, int $order_id ) {
        try {
            $upload_dir = wp_upload_dir();
            $label_dir  = $upload_dir['basedir'] . '/wcmcs-labels/' . $order_id . '/';

            if ( ! file_exists( $label_dir ) ) {
                wp_mkdir_p( $label_dir );
            }
            // Durcir l'accès : blocage direct Apache 2.2/2.4 (les fichiers ne sont
            // servis que via le endpoint authentifié wcmcs_download_label).
            if ( ! file_exists( $label_dir . '.htaccess' ) ) {
                file_put_contents( $label_dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
            }
            if ( ! file_exists( $label_dir . 'index.php' ) ) {
                file_put_contents( $label_dir . 'index.php', '<?php // silence' );
            }

            $filename  = sanitize_file_name( 'colissimo_tn_' . $tracking_id . '_' . time() . '.pdf' );
            $file_path = $label_dir . $filename;

            $decoded = base64_decode( $base64_pdf, true );
            if ( $decoded === false ) {
                WCMCS_Logger::error( "Colissimo TN: échec décodage base64 label pour tracking {$tracking_id}." );
                return new WP_Error( 'label_decode_failed', __( 'Colissimo TN : échec décodage étiquette PDF.', 'wc-multicarrier-sync' ) );
            }

            $written = file_put_contents( $file_path, $decoded );
            if ( $written === false || $written !== strlen( $decoded ) ) {
                WCMCS_Logger::error( "Colissimo TN: échec écriture label PDF pour tracking {$tracking_id} (écrit {$written}/" . strlen( $decoded ) . ' octets).' );
                return new WP_Error( 'label_write_failed', __( 'Colissimo TN : échec sauvegarde étiquette PDF sur le disque.', 'wc-multicarrier-sync' ) );
            }

            return $upload_dir['baseurl'] . '/wcmcs-labels/' . $order_id . '/' . $filename;

        } catch ( \Throwable $e ) {
            WCMCS_Logger::error( "Colissimo TN: erreur sauvegarde label PDF: " . $e->getMessage() );
            return new WP_Error( 'label_save_failed', sprintf( __( 'Colissimo TN : erreur sauvegarde étiquette — %s', 'wc-multicarrier-sync' ), $e->getMessage() ) );
        }
    }
}
