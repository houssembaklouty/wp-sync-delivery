<?php
/**
 * Intégration FirstDelivery v2.
 *
 * API REST : https://www.firstdeliverygroup.com/api/v2
 * Endpoints : /create, /etat, /cancel-orders, /localities, /filter
 * Auth : Bearer Token
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_FirstDelivery_Provider implements WCMCS_ShippingProviderInterface {
    use WCMCS_Field_Mapping;

    const SLUG         = 'firstdelivery';
    const API_BASE_URL = 'https://www.firstdeliverygroup.com/api/v2';

    /**
     * Gouvernorats tunisiens acceptés (validation stricte, insensible casse/accents).
     */
    const GOVERNORATS = [
        'Ariana', 'Ben Arous', 'Bizerte', 'Béja', 'Gabès',
        'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
        'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine',
        'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
        'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
    ];

    const STATUS_MAP = [
        0   => 'En attente',
        1   => 'En cours',
        2   => 'Livré',
        3   => 'Échange',
        5   => 'Retour expéditeur',
        6   => 'Supprimé',
        7   => 'Rtn client/agence',
        8   => 'Au magasin',
        11  => 'Rtn dépôt',
        20  => 'À vérifier',
        30  => 'Retour reçu',
        31  => 'Retour définitif',
        100 => 'Demande d\'enlèvement créée',
        101 => 'Demande d\'enlèvement assignée',
        102 => 'En cours d\'enlèvement',
        103 => 'Enlevé',
        104 => 'Demande d\'enlèvement annulée',
        201 => 'Retour assigné',
        202 => 'Retour en cours d\'expédition',
        203 => 'Retour enlevé',
        204 => 'Retour annulé',
    ];

    // ─────────────────────────────────────────────
    //  Mapping par défaut des champs FirstDelivery
    // ─────────────────────────────────────────────

    const FIELD_DEFAULTS = [
        'nom'           => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
        'gouvernerat'   => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
        'ville'         => [ 'source' => 'shipping_city',       'fallback' => 'billing_city' ],
        'adresse'       => [ 'source' => 'full_address',        'fallback' => 'billing_full_address' ],
        'telephone'     => [ 'source' => 'shipping_phone',      'fallback' => 'billing_phone' ],
        'telephone2'    => [ 'source' => '',                   'fallback' => '' ],
        'commentaire'   => [ 'source' => '',      'fallback' => '' ],
    ];

    /** @var string */
    private $api_token;

    /** @var int */
    private $default_nombre_article;

    /** @var int */
    private $default_nombre_echange;

    /** @var int|null */
    private $default_locality_id;

    /** @var int */
    private $api_timeout;

    public function __construct() {
        $this->api_token               = (string) get_option( 'wcmcs_firstdelivery_api_token', '' );
        $this->default_nombre_article   = (int) get_option( 'wcmcs_firstdelivery_default_nombre_article', 1 );
        $this->default_nombre_echange   = (int) get_option( 'wcmcs_firstdelivery_default_nombre_echange', 0 );
        $this->default_locality_id      = (int) get_option( 'wcmcs_firstdelivery_default_locality_id', 0 ) ?: null;
        $this->est_fragile              = get_option( 'wcmcs_firstdelivery_est_fragile', 'no' ) === 'yes' ? 'oui' : 'non';
        $this->ouvrir_colis             = get_option( 'wcmcs_firstdelivery_ouvrir_colis', 'no' ) === 'yes' ? 'oui' : 'non';
        $this->api_timeout              = (int) apply_filters( 'wcmcs_firstdelivery_api_timeout', 30 );
    }

    // ─────────────────────────────────────────────
    //  Interface impl
    // ─────────────────────────────────────────────

    public function get_slug(): string {
        return self::SLUG;
    }

    public function mapping_option_prefix(): string {
        return 'wcmcs_firstdelivery_map_';
    }

    public function get_label(): string {
        return 'FirstDelivery';
    }

    public function is_active(): bool {
        return true;
    }

    public function is_configured(): bool {
        return ! empty( $this->api_token );
    }

    public function get_settings_fields(): array {
        $fields = [
            [
                'id'          => 'wcmcs_firstdelivery_api_token',
                'label'       => __( 'Token API', 'wc-multicarrier-sync' ),
                'type'        => 'password',
                'description' => __( 'Token Bearer fourni par FirstDelivery.', 'wc-multicarrier-sync' ),
                'placeholder' => 'fd_live_xxxxxxxxxxxx',
            ],
            [
                'id'          => 'wcmcs_firstdelivery_default_nombre_article',
                'label'       => __( 'Nombre d\'articles par défaut', 'wc-multicarrier-sync' ),
                'type'        => 'number',
                'default'     => 1,
                'description' => __( 'Nombre d\'articles par défaut si la quantité réelle de la commande ne peut être déterminée (la quantité réelle est prioritaire).', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_firstdelivery_default_nombre_echange',
                'label'       => __( 'Nombre d\'échanges par défaut', 'wc-multicarrier-sync' ),
                'type'        => 'number',
                'default'     => 0,
                'description' => __( 'Nombre d\'échanges par défaut (0 = aucun échange).', 'wc-multicarrier-sync' ),
            ],
            [
                'id'          => 'wcmcs_firstdelivery_default_locality_id',
                'label'       => __( 'Localité par défaut (secours)', 'wc-multicarrier-sync' ),
                'type'        => 'number',
                'default'     => 0,
                'min'         => 0,
                'description' => __( 'La localité (locality_id) est résolue automatiquement PAR COMMANDE depuis la liste /localities de FirstDelivery (match ville/délégation + gouvernorat). Ce réglage n\'est qu\'un SECOURS si la localité de la commande n\'est pas résolue. Laisser 0 pour ne pas envoyer de secours.', 'wc-multicarrier-sync' ),
            ],
            [
                'id'      => 'wcmcs_firstdelivery_est_fragile',
                'label'   => __( 'Colis fragile', 'wc-multicarrier-sync' ),
                'type'    => 'select',
                'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
                'default' => 'no',
                'description' => __( '« Oui » envoie estFragile=oui à l\'API (champ de la nouvelle API FirstDelivery — ignoré si Non).', 'wc-multicarrier-sync' ),
            ],
            [
                'id'      => 'wcmcs_firstdelivery_ouvrir_colis',
                'label'   => __( 'Autoriser l\'ouverture du colis à la livraison', 'wc-multicarrier-sync' ),
                'type'    => 'select',
                'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
                'default' => 'no',
                'description' => __( '« Oui » envoie ouvrirColis=oui à l\'API (champ de la nouvelle API FirstDelivery — ignoré si Non).', 'wc-multicarrier-sync' ),
            ],
        ];

        // ── Section Mapping des champs ──────────
        $fields[] = [
            'id'    => 'wcmcs_firstdelivery_mapping_header',
            'type'  => 'title',
            'label' => '',
            'title' => __( 'Mapping des champs WooCommerce → FirstDelivery', 'wc-multicarrier-sync' ),
        ];

        $field_labels = [
            'nom'          => __( 'Nom du client', 'wc-multicarrier-sync' ),
            'gouvernerat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
            'ville'         => __( 'Ville', 'wc-multicarrier-sync' ),
            'adresse'       => __( 'Adresse', 'wc-multicarrier-sync' ),
            'telephone'     => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
            'telephone2'    => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
            'commentaire'   => __( 'Note / Commentaire', 'wc-multicarrier-sync' ),
        ];

        foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
        	if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
        		$fields[] = $this->note_settings_field();
        		continue;
        	}
            $fields[] = [
                'id'               => 'wcmcs_firstdelivery_map_' . $field_key,
                'label'            => $field_labels[ $field_key ],
                'type'             => 'mapping_row',
                'options'          => $this->wc_field_options(),
                'default'          => $defaults['source'],
                'fallback_id'      => 'wcmcs_firstdelivery_map_' . $field_key . '_fallback',
                'fallback_default' => $defaults['fallback'],
                'description'      => __( 'Champ source et champ de secours si vide.', 'wc-multicarrier-sync' ),
            ];
        }

        return $fields;
    }

    // ─────────────────────────────────────────────
    //  Résolution du gouvernorat
    // ─────────────────────────────────────────────

    /**
     * Résout le gouvernorat : constante stricte key→value de la doc
     * FirstDelivery (orthographe exacte, toujours en français). Hors liste
     * → erreur bloquante (jamais de valeur brute non française envoyée).
     *
     * @return string|WP_Error
     */
    private function resolve_gouvernorat( WC_Order $order ) {
        $state_field = $this->get_mapped_field( $order, 'gouvernerat' );
        $ville_field = $this->get_mapped_field( $order, 'ville' );

        $gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'firstdelivery' );
        if ( null !== $gouvernorat ) {
            return $gouvernorat;
        }

        $raw = WCMCS_Localization::translate_gouvernorat( $state_field, $ville_field );
        return new WP_Error(
            'gouvernorat_invalide',
            sprintf(
                /* translators: 1: valeur reçue, 2: liste officielle */
                __( 'Gouvernorat invalide « %1$s » — hors liste FirstDelivery. Liste officielle : %2$s. Corrigez l\'adresse de livraison de la commande.', 'wc-multicarrier-sync' ),
                '' !== $raw ? $raw : '(vide)',
                implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'firstdelivery' ) )
            )
        );
    }

    /**
     * Correspondance insensible à la casse et aux accents.
     *
     * @return string|false
     */
    private function match_gouvernorat( string $input ) {
        $normalized = $this->normalize( $input );
        foreach ( self::GOVERNORATS as $gouv ) {
            if ( $this->normalize( $gouv ) === $normalized ) {
                return $gouv;
            }
        }
        return false;
    }

    private function normalize( string $str ): string {
        $str = mb_strtolower( $str, 'UTF-8' );
        $str = remove_accents( $str );
        $str = preg_replace( '/[^a-z0-9]/', '', $str );
        return $str;
    }

    // ─────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────

    public function validate_order( WC_Order $order ) {
        $errors = new WP_Error();

        $required_fields = [
            'nom'          => __( 'Nom du client', 'wc-multicarrier-sync' ),
            'gouvernerat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
            'ville'         => __( 'Ville', 'wc-multicarrier-sync' ),
            'adresse'       => __( 'Adresse', 'wc-multicarrier-sync' ),
            'telephone'     => __( 'Téléphone', 'wc-multicarrier-sync' ),
        ];

        foreach ( $required_fields as $field_key => $label ) {
            $value = $this->get_mapped_field( $order, $field_key );
            if ( empty( $value ) ) {
                $errors->add( 'missing_' . $field_key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
            }
        }

        // Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
        // valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).

        $phone = $this->get_mapped_field( $order, 'telephone' );
        if ( ! empty( $phone ) && ! $this->is_valid_phone( $phone ) ) {
            $errors->add( 'invalid_phone', sprintf( __( 'Téléphone invalide : %s', 'wc-multicarrier-sync' ), $phone ) );
        }

        if ( (float) $order->get_total() <= 0 ) {
            $errors->add( 'zero_total', __( 'Le montant total de la commande est nul.', 'wc-multicarrier-sync' ) );
        } elseif ( (float) $order->get_total() > 999 ) {
            $errors->add( 'total_too_high', __( 'Le montant total dépasse la limite de 999 DT.', 'wc-multicarrier-sync' ) );
        }

        if ( $errors->has_errors() ) {
            $messages = implode( ' | ', $errors->get_error_messages() );
            return new WP_Error( 'validation_failed', $messages );
        }

        return true;
    }

    // ─────────────────────────────────────────────
    //  Création d'expédition
    // ─────────────────────────────────────────────

    public function create_shipment( WC_Order $order ) {
        $payload = $this->build_shipment_payload( $order );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        $payload = apply_filters( 'wcmcs_firstdelivery_payload', $payload, $order );

        WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API FirstDelivery (POST /create)' );
        WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': FirstDelivery payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

        // NB : la doc v2 mentionne « POST /orders » mais l'API live répond
        // « Method not allowed » sur cet endpoint — /create est l'endpoint
        // réel (prouvé en production).
        $response = $this->api_request( 'POST', '/create', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $result = $response['result'] ?? $response;

        $tracking_id = $result['barCode'] ?? $result['barcode'] ?? $result['bar_code'] ?? '';
        if ( is_array( $tracking_id ) ) {
            $tracking_id = $tracking_id[0] ?? '';
        }
        $tracking_id = (string) $tracking_id;

        if ( empty( $tracking_id ) ) {
            return new WP_Error(
                'no_tracking_id',
                __( 'FirstDelivery : aucun code barre dans la réponse.', 'wc-multicarrier-sync' ),
                $response
            );
        }

        $label_url = $result['link'] ?? $response['link'] ?? '';

        WCMCS_Logger::info( "FirstDelivery: colis créé barCode={$tracking_id}" );

        return [
            'tracking_id' => $tracking_id,
            'label_url'   => (string) $label_url,
            'raw'          => WCMCS_Logger::redact_pii( $response ),
        ];
    }

    // ─────────────────────────────────────────────
    //  Annulation
    // ─────────────────────────────────────────────

    public function cancel_shipment( string $tracking_id, WC_Order $order ) {
        $payload = [ 'barCodes' => [ $tracking_id ] ];

        WCMCS_Logger::info( "FirstDelivery: annulation du colis {$tracking_id}" );

        $response = $this->api_request( 'POST', '/cancel-orders', $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $cancelled = $response['result'] ?? null;

        // Confirmation explicite requise : la réponse doit contenir le code barre annulé.
        if ( ! is_array( $cancelled ) || empty( $cancelled ) || ! in_array( $tracking_id, $cancelled, true ) ) {
            return new WP_Error(
                'cancel_failed',
                sprintf( __( 'FirstDelivery : l\'annulation du colis %s n\'a pas été confirmée par l\'API.', 'wc-multicarrier-sync' ), $tracking_id ),
                $response
            );
        }

        WCMCS_Logger::info( "FirstDelivery: colis {$tracking_id} annulé avec succès." );
        return true;
    }

    // ─────────────────────────────────────────────
    //  Tracking
    // ─────────────────────────────────────────────

    public function get_tracking_status( string $tracking_id ) {
        // NB : « GET /orders?barCode= » de la doc v2 renvoie « Method not
        // allowed » sur l'API live — POST /etat est l'endpoint réel (prouvé).
        $response = $this->api_request( 'POST', '/etat', [ 'barCode' => $tracking_id ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $result = $response['result'] ?? $response;

        // GET /orders peut retourner une LISTE d'orders : retrouver celle du barCode.
        if ( is_array( $result ) && isset( $result[0] ) && is_array( $result[0] ) ) {
            $found = null;
            foreach ( $result as $item ) {
                $bc = $item['barCode'] ?? $item['barcode'] ?? $item['bar_code'] ?? '';
                if ( (string) $bc === (string) $tracking_id ) {
                    $found = $item;
                    break;
                }
            }
            $result = $found ?? ( $result[0] ?? [] );
        }

        $state_raw = $result['state'] ?? null;
        if ( $state_raw === null ) {
            return $response;
        }

        $state_label = self::STATUS_MAP[ $state_raw ] ?? (string) $state_raw;
        $bar_code    = $result['barCode'] ?? $tracking_id;

        return [
            'tracking_id'  => (string) $bar_code,
            'state_code'   => $state_raw,
            'state_label'  => $state_label,
            'raw'          => WCMCS_Logger::redact_pii( $response ),
        ];
    }

    // ─────────────────────────────────────────────
    //  Localités
    // ─────────────────────────────────────────────

    /**
     * Récupère la liste des localités depuis l'API (avec cache transient).
     *
     * @return array|WP_Error
     */
    public function get_localities() {
        $cached = get_transient( 'wcmcs_firstdelivery_localities' );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        // Cache NÉGATIF court (revue de code 2.10.0) : en cas de panne de
        // /localities, sans lui chaque commande d'un batch re-tentait un
        // appel condamné (pression rate-limit 1 req/s → 429 en cascade).
        if ( 'yes' === get_transient( 'wcmcs_firstdelivery_localities_failed' ) ) {
            return new WP_Error( 'fd_localities_unreachable', __( 'FirstDelivery : localités indisponibles (échec récent, nouvelle tentative dans quelques minutes).', 'wc-multicarrier-sync' ) );
        }

        $response = $this->api_request( 'GET', '/localities', [] );

        if ( is_wp_error( $response ) ) {
            set_transient( 'wcmcs_firstdelivery_localities_failed', 'yes', 5 * MINUTE_IN_SECONDS );
            return $response;
        }

        $localities = $response['result'] ?? $response;

        if ( ! is_array( $localities ) ) {
            set_transient( 'wcmcs_firstdelivery_localities_failed', 'yes', 5 * MINUTE_IN_SECONDS );
            return new WP_Error( 'invalid_localities', __( 'FirstDelivery : réponse localités invalide.', 'wc-multicarrier-sync' ) );
        }

        set_transient( 'wcmcs_firstdelivery_localities', $localities, DAY_IN_SECONDS );
        return $localities;
    }

    // ─────────────────────────────────────────────
    //  Construction du payload
    // ─────────────────────────────────────────────

    /**
     * Construit le payload v2 (Client/Produit).
     *
     * @return array|WP_Error  Payload, ou WP_Error si le gouvernorat est invalide.
     */
    private function build_shipment_payload( WC_Order $order ) {
        $total_qty = 0;
        $item_names = [];
        foreach ( $order->get_items() as $item ) {
            $qty          = $item->get_quantity();
            $total_qty   += $qty;
            $item_names[] = WCMCS_Localization::item_designation_french( $item ) . ' x' . $qty;
        }

        $nom         = $this->get_mapped_field( $order, 'nom' );
        $adresse     = $this->get_mapped_field( $order, 'adresse' );
        $telephone   = $this->get_mapped_field( $order, 'telephone' );
        $telephone2  = $this->get_mapped_field( $order, 'telephone2' );
        $commentaire  = $this->get_mapped_field( $order, 'commentaire' );
        $ville       = $this->get_mapped_field( $order, 'ville' );

        $gouvernerat = $this->resolve_gouvernorat( $order );
        if ( is_wp_error( $gouvernerat ) ) {
            WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernerat->get_error_message() );
            return $gouvernerat;
        }

        // Ville arabe → français (fallback : gouvernorat résolu).
        $ville = WCMCS_Localization::translate_ville( $ville, $gouvernerat );

        // Localité FirstDelivery résolue PAR COMMANDE (match ville/délégation
        // dans le gouvernorat via GET /localities, cache 24 h — 1756 localités,
        // « strongly recommended » doc v2 pour un routage précis). Fallback :
        // réglage global « Localité par défaut ».
        $locality_id = $this->resolve_locality_id( $gouvernerat, $ville, (int) $order->get_id() );

        $prix = (float) $order->get_total();

        $client = [
            'nom'         => $nom,
            'gouvernerat' => $gouvernerat,
            'ville'       => $ville,
            'adresse'     => $adresse,
            'telephone'   => $telephone,
        ];

        if ( ! empty( $telephone2 ) ) {
            $client['telephone2'] = $telephone2;
        }

        if ( $locality_id ) {
            $client['locality_id'] = (int) $locality_id;
        }

        return [
            'Client'  => $client,
            'Produit' => [
                'prix'           => $prix,
                'designation'    => implode( '; ', $item_names ),
                'nombreArticle'  => max( 1, $total_qty ?: $this->default_nombre_article ),
                'commentaire'    => $commentaire,
                'article'        => implode( '; ', $item_names ),
                'nombreEchange'  => $this->default_nombre_echange ?: 0,
                // Doc v2 §5 : booléens standardisés strictement en « oui »/« non »
                // — envoyés explicitement, jamais omis.
                'estFragile'    => 'oui' === $this->est_fragile ? 'oui' : 'non',
                'ouvrirColis'   => 'oui' === $this->ouvrir_colis ? 'oui' : 'non',
            ],
        ];
    }

    // ─────────────────────────────────────────────
    //  Résolution de la localité (routage par commande)
    // ─────────────────────────────────────────────

    /**
     * Résout la localité FirstDelivery POUR CETTE COMMANDE.
     *
     * GET /localities (cache transient 24 h) → structure :
     * {locality_id, locality_name, delegation_name, governorate_name}.
     *
     * Ordre de résolution (matching insensible casse/accents) :
     * 1. localité exacte (locality_name == ville) dans le gouvernorat résolu ;
     * 2. délégation == ville dans le gouvernorat résolu (localité homonyme,
     *    sinon première localité de la délégation) ;
     * 3. délégation CONTENANT la ville dans le gouvernorat résolu, si une
     *    seule délégation la contient (ex. « Ettadhamen » → « Cité
     *    Ettadhamen ») — localité homonyme sinon première ;
     * 4. localité exacte UNIQUE hors gouvernorat résolu (gouvernorat non
     *    reconnu — ex. code brut) — plusieurs gouvernorats = ambigu → aucun ;
     * 5. réglage global « Localité par défaut » (secours) ;
     * 6. null → champ omis + warning.
     *
     * @param string $gouvernorat Gouvernorat résolu (français ou valeur brute).
     * @param string $ville       Ville/délégation traduite (français si possible).
     * @param int    $order_id    ID commande (logs).
     * @return int|null
     */
    private function resolve_locality_id( string $gouvernorat, string $ville, int $order_id ): ?int {
        $ville = trim( $ville );
        if ( '' === $ville ) {
            return $this->default_locality_id;
        }

        $norm_ville = $this->normalize( $ville );
        if ( '' === $norm_ville ) {
            return $this->default_locality_id;
        }
        $norm_gouv = '' !== trim( $gouvernorat ) ? $this->normalize( $gouvernorat ) : '';

        $localities = $this->get_localities();
        if ( is_wp_error( $localities ) ) {
            WCMCS_Logger::warning( "Order #{$order_id}: localités FirstDelivery indisponibles — " . $localities->get_error_message() );
            return $this->default_locality_id;
        }

        $exact_in_gouv = null;
        $deleg_in_gouv = null;   // Première localité de la délégation matchée.
        $deleg_homonym = null;   // Localité homonyme de la délégation (préférée).
        $contains_deleg = [];    // Passes 3 : délégations contenant la ville (gouvernorat résolu).
        $global_exact   = [];    // Localités exactes hors gouvernorat résolu.

        foreach ( $localities as $loc ) {
            if ( ! is_array( $loc ) || empty( $loc['locality_id'] ) ) {
                continue;
            }
            $loc_gouv = $this->normalize( (string) ( $loc['governorate_name'] ?? '' ) );
            $loc_name = $this->normalize( (string) ( $loc['locality_name'] ?? '' ) );
            $loc_dele = $this->normalize( (string) ( $loc['delegation_name'] ?? '' ) );
            $in_gouv  = '' !== $norm_gouv && $loc_gouv === $norm_gouv;

            // 1. Localité exacte dans le gouvernorat résolu.
            if ( $in_gouv && $loc_name === $norm_ville ) {
                WCMCS_Logger::info( "Order #{$order_id}: localité FirstDelivery résolue → {$loc['locality_id']} ({$loc['locality_name']} / {$loc['governorate_name']})" );
                return (int) $loc['locality_id'];
            }

            // 2. Délégation == ville dans le gouvernorat résolu.
            if ( $in_gouv && $loc_dele === $norm_ville ) {
                if ( null === $deleg_in_gouv ) {
                    $deleg_in_gouv = $loc;
                }
                if ( null === $deleg_homonym && $loc_name === $loc_dele ) {
                    $deleg_homonym = $loc;
                }
            }

            // 3. Délégation contenant la ville (gouvernorat résolu) — ex.
            //    « Ettadhamen » → « Cité Ettadhamen ». Distinctes par délégation.
            if ( $in_gouv && '' !== $loc_dele && false !== strpos( $loc_dele, $norm_ville ) ) {
                if ( ! isset( $contains_deleg[ $loc_dele ] ) ) {
                    $contains_deleg[ $loc_dele ] = $loc;
                }
                if ( $loc_name === $loc_dele ) {
                    $contains_deleg[ $loc_dele ] = $loc; // Homonyme privilégié.
                }
            }

            // 4. Localité exacte hors gouvernorat (collecte pour unicité).
            if ( ! $in_gouv && $loc_name === $norm_ville ) {
                $global_exact[ (int) $loc['locality_id'] ] = $loc;
            }
        }

        // 2bis. Délégation exacte retenue : localité homonyme sinon première.
        $deleg = $deleg_homonym ?? $deleg_in_gouv;
        if ( $deleg ) {
            WCMCS_Logger::info( "Order #{$order_id}: localité FirstDelivery (délégation) → {$deleg['locality_id']} ({$deleg['locality_name']} / {$deleg['delegation_name']})" );
            return (int) $deleg['locality_id'];
        }

        // 3bis. Une SEULE délégation du gouvernorat contient la ville.
        if ( 1 === count( $contains_deleg ) ) {
            $loc = reset( $contains_deleg );
            WCMCS_Logger::info( "Order #{$order_id}: localité FirstDelivery (délégation contenant) → {$loc['locality_id']} ({$loc['locality_name']} / {$loc['delegation_name']})" );
            return (int) $loc['locality_id'];
        }

        // 4bis. Localité exacte UNIQUE hors gouvernorat résolu.
        if ( 1 === count( $global_exact ) ) {
            $loc = reset( $global_exact );
            WCMCS_Logger::info( "Order #{$order_id}: localité FirstDelivery (hors gouvernorat, unique) → {$loc['locality_id']} ({$loc['locality_name']} / {$loc['governorate_name']})" );
            return (int) $loc['locality_id'];
        }
        if ( count( $global_exact ) > 1 ) {
            WCMCS_Logger::warning( "Order #{$order_id}: localité « {$ville} » ambiguë (plusieurs gouvernorats) — réglage global ou omission." );
        }

        if ( $this->default_locality_id ) {
            WCMCS_Logger::warning( "Order #{$order_id}: localité non résolue pour « {$ville} » / « {$gouvernorat} » — réglage global appliqué ({$this->default_locality_id})." );
            return $this->default_locality_id;
        }

        WCMCS_Logger::warning( "Order #{$order_id}: localité FirstDelivery non résolue pour « {$ville} » / « {$gouvernorat} » — champ locality_id omis." );
        return null;
    }

    // ─────────────────────────────────────────────
    //  HTTP Client
    // ─────────────────────────────────────────────

    /**
     * Effectue une requête HTTP vers l'API FirstDelivery v2.
     *
     * @param string $method    GET|POST
     * @param string $endpoint  Chemin relatif (ex: /create, /etat).
     * @param array  $body      Corps de la requête (pour POST).
     * @return array|WP_Error
     */
    private function api_request( string $method, string $endpoint, array $body ) {
        $url = rtrim( self::API_BASE_URL, '/' ) . $endpoint;

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => $this->api_timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        } elseif ( ! empty( $body ) ) {
            // GET : les données passent en query string (ex : /orders?barCode=X).
            $url .= '?' . build_query( $body );
        }

        WCMCS_Logger::info( "FirstDelivery API request: {$method} {$endpoint}" );
        WCMCS_Logger::debug( 'FirstDelivery request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

        $response = wp_remote_request( $url, $args );

        // Rate limit doc v2 : max 1 req/s (opérations unitaires). HTTP 429 →
        // pause 3 s + 1 unique retry (même pattern que le backoff du scan admin).
        if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
            WCMCS_Logger::warning( "FirstDelivery API: HTTP 429 (rate limit) sur {$endpoint} — pause 3 s puis nouvel essai." );
            sleep( 3 );
            $response = wp_remote_request( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            WCMCS_Logger::error( "FirstDelivery HTTP error: {$msg} | URL: {$url}" );
            return new WP_Error( 'fd_http_error', $msg );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $parsed      = json_decode( $raw_body, true );

        WCMCS_Logger::info( "FirstDelivery response [{$status_code}]" );
        WCMCS_Logger::debug( 'FirstDelivery response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

        if ( ! is_array( $parsed ) ) {
            WCMCS_Logger::error( "FirstDelivery: réponse JSON invalide (HTTP {$status_code})" );
            return new WP_Error(
                'fd_invalid_json',
                sprintf( __( 'FirstDelivery : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
            );
        }

        // API erreur signalée par isError
        if ( ! empty( $parsed['isError'] ) ) {
            $api_message = $parsed['message'] ?? __( 'Erreur inconnue FirstDelivery.', 'wc-multicarrier-sync' );
            WCMCS_Logger::error( "FirstDelivery API error: {$api_message}" );
            return new WP_Error(
                'fd_api_error',
                $api_message,
                $parsed
            );
        }

        // HTTP erreur sans isError
        if ( $status_code < 200 || $status_code >= 300 ) {
            $api_message = $parsed['message']
                ?? $parsed['error']
                ?? $parsed['detail']
                ?? sprintf( __( 'Erreur HTTP %d depuis FirstDelivery.', 'wc-multicarrier-sync' ), $status_code );

            WCMCS_Logger::error( "FirstDelivery API error {$status_code}: {$api_message}" );
            return new WP_Error(
                'fd_api_error_' . $status_code,
                $api_message,
                $parsed
            );
        }

        return $parsed;
    }

    // ─────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────

    /**
     * Valide un numéro de téléphone.
     *
     * @param string $phone
     * @return bool
     */
    private function is_valid_phone( string $phone ): bool {
        $clean = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );
        return (bool) preg_match( '/^(\+|00)?[1-9]\d{7,14}$/', $clean );
    }
}