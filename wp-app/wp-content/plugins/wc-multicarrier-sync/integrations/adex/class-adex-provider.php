<?php
/**
 * Intégration Adex (my.adex.tn).
 *
 * API REST v2.0 — auth « Utilisateur » + « Pass » (token UUID) DANS LE BODY JSON.
 *  - Création   : POST /api/rest/StColis/AjouterVColis (ville + gouvernorat par noms)
 *  - Suivi      : POST /api/rest/StColis/getColis (etat + code_etat 0-6)
 *  - Annulation : POST /api/rest/StColis/supprimerColis (uniquement « En Attente »)
 *  - BL PDF     : GET  /api/rest/StColis/printColis/{code}?Utilisateur=…&Pass=…
 *
 * NB : le token est masqué dans tous les logs debug (Pass: [REDACTED]).
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Adex_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'adex';
	const LABEL    = 'Adex';
	const API_BASE = 'https://my.adex.tn/api/rest/StColis';

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

	/**
	 * Types de colis acceptés par l'API (champ requis « type »).
	 */
	const TYPES_COLIS = [
		'FIX' => 'FIX',
		'LGR' => 'LGR',
		'MOY' => 'MOY',
		'GRD' => 'GRD',
		'ONP' => 'ONP',
		'BLK' => 'BLK',
		'SMD' => 'SMD',
	];

	/**
	 * Tailles de pièces (type_colis_tab : 0/1/2 par pièce).
	 */
	const TAILLES = [
		'0' => 'Léger (petit colis)',
		'1' => 'Moyen',
		'2' => 'Grand',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs Adex
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'client'       => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'tel1'         => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'tel2'         => [ 'source' => '',                   'fallback' => '' ],
		'commentaire'  => [ 'source' => '',      'fallback' => '' ],
	];

	/** @var string Identifiant API (login expéditeur). */
	private $utilisateur;

	/** @var string Token API (UUID). */
	private $token;

	/** @var string Type de colis (FIX/LGR/…). */
	private $type;

	/** @var string Taille des pièces (0/1/2) pour type_colis_tab. */
	private $taille;

	/** @var int Fragile (0/1). */
	private $fragile;

	/** @var int Autoriser l'ouverture (0/1). */
	private $autorisation_ouv;

	/** @var int Autoriser le paiement par chèque (0/1). */
	private $autorisation_chq;

	/** @var int Échange (0/1). */
	private $echange;

	/** @var string Produit à recevoir (si échange). */
	private $produit_arecevoir;

	/** @var int Adresse d'enlèvement (comptes multi-adresses, 0 = absent). */
	private $adresse_enlevement;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->utilisateur        = (string) get_option( 'wcmcs_adex_utilisateur', '' );
		$this->token             = (string) get_option( 'wcmcs_adex_token', '' );
		$this->type              = (string) get_option( 'wcmcs_adex_type', 'FIX' );
		if ( ! isset( self::TYPES_COLIS[ $this->type ] ) ) {
			$this->type = 'FIX';
		}
		$this->taille            = (string) get_option( 'wcmcs_adex_taille', '0' );
		if ( ! isset( self::TAILLES[ $this->taille ] ) ) {
			$this->taille = '0';
		}
		$this->fragile           = get_option( 'wcmcs_adex_fragile', 'no' ) === 'yes' ? 1 : 0;
		$this->autorisation_ouv = get_option( 'wcmcs_adex_ouv', 'no' ) === 'yes' ? 1 : 0;
		$this->autorisation_chq = get_option( 'wcmcs_adex_chq', 'no' ) === 'yes' ? 1 : 0;
		$this->echange          = get_option( 'wcmcs_adex_echange', 'no' ) === 'yes' ? 1 : 0;
		$this->produit_arecevoir = (string) get_option( 'wcmcs_adex_produit_arecevoir', '' );
		$this->adresse_enlevement = (int) get_option( 'wcmcs_adex_adresse_enlevement', 0 );
		$this->api_timeout       = (int) apply_filters( 'wcmcs_adex_api_timeout', 30 );
	}

	// ─────────────────────────────────────────────
	//  Interface impl
	// ─────────────────────────────────────────────

	public function get_slug(): string {
		return self::SLUG;
	}

	public function get_label(): string {
		return self::LABEL;
	}

	public function mapping_option_prefix(): string {
		return 'wcmcs_adex_map_';
	}

	public function is_active(): bool {
		return true;
	}

	public function is_configured(): bool {
		return ! empty( $this->utilisateur ) && ! empty( $this->token );
	}

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_adex_utilisateur',
				'label'       => __( 'Utilisateur (API)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Identifiant de connexion (login) — visible dans votre profil Adex.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_adex_token',
				'label'       => __( 'Token API (Pass)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Généré dans my.adex.tn → Mon Profil → Accès API REST → Générer un nouveau token (UUID, valable 1 an).', 'wc-multicarrier-sync' ),
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
			],
			[
				'id'      => 'wcmcs_adex_type',
				'label'   => __( 'Type de colis', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => self::TYPES_COLIS,
				'default' => 'FIX',
			],
			[
				'id'          => 'wcmcs_adex_taille',
				'label'       => __( 'Taille des pièces', 'wc-multicarrier-sync' ),
				'type'        => 'select',
				'options'     => self::TAILLES,
				'default'     => '0',
				'description' => __( 'Construit automatiquement type_colis_tab (une valeur par pièce, ex : « 0/0 » pour 2 pièces).', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_adex_fragile',
				'label'   => __( 'Colis fragile', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_adex_ouv',
				'label'   => __( 'Autoriser l\'ouverture du colis', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_adex_chq',
				'label'   => __( 'Autoriser le paiement par chèque', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_adex_echange',
				'label'   => __( 'Commande avec échange', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'          => 'wcmcs_adex_produit_arecevoir',
				'label'       => __( 'Produit à recevoir (échange)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Obligatoire si « échange » est activé — description du produit à récupérer en échange.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_adex_adresse_enlevement',
				'label'       => __( 'Adresse d\'enlèvement (multi-adresses)', 'wc-multicarrier-sync' ),
				'type'        => 'number',
				'default'     => 0,
				'min'         => 0,
				'description' => __( 'ID d\'adresse de ramassage (comptes multi-adresses uniquement — sinon laisser 0). Une valeur inconnue fait REFUSER le colis.', 'wc-multicarrier-sync' ),
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_adex_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Adex', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'client'       => __( 'Nom du client', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville (délégation)', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'tel1'         => __( 'Téléphone principal (8 chiffres)', 'wc-multicarrier-sync' ),
			'tel2'         => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
			'commentaire'  => __( 'Commentaire / instructions', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_adex_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_adex_map_' . $field_key . '_fallback',
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
	 * Résout le gouvernorat (traduction arabe/latin → français). Valeur non
	 * reconnue : envoyée telle quelle — aucune interprétation de code.
	 *
	 * @return string|WP_Error  WP_Error uniquement si le champ est vide.
	 */
		private function resolve_gouvernorat( WC_Order $order ) {
		$state_field = $this->get_mapped_field( $order, 'gouvernorat' );
		$ville_field = $this->get_mapped_field( $order, 'ville' );

		// Constante stricte key→value de la doc Adex — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'adex' );
		if ( null !== $gouvernorat ) {
			return $gouvernorat;
		}

		$raw = WCMCS_Localization::translate_gouvernorat( $state_field, $ville_field );
		return new WP_Error(
			'gouvernorat_invalide',
			sprintf(
				/* translators: 1: valeur reçue, 2: transporteur, 3: liste officielle */
				__( 'Gouvernorat invalide « %1$s » — hors liste %2$s. Liste officielle : %3$s. Corrigez l\'adresse de livraison de la commande.', 'wc-multicarrier-sync' ),
				'' !== $raw ? $raw : '(vide)',
				'Adex',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'adex' ) )
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
	//  Normalisation du téléphone (8 chiffres strict)
	// ─────────────────────────────────────────────

	/**
	 * Normalise un téléphone tunisien : chiffres uniquement, préfixe 216
	 * strippé si 11 chiffres. Retourne '' si le résultat ne fait pas 8 chiffres.
	 */
	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone );
		$digits = (string) $digits;

		// Préfixe international tunisien : 216 (11 chiffres) ET 00216
		// (13 chiffres — aligné sur Colissimo/AFEX, revue de code 2.10.0 :
		// la branche manquante bloquait des numéros parfaitement valides).
		if ( strlen( $digits ) === 11 && 0 === strpos( $digits, '216' ) ) {
			$digits = substr( $digits, 3 );
		} elseif ( strlen( $digits ) === 13 && 0 === strpos( $digits, '00216' ) ) {
			$digits = substr( $digits, 5 );
		}

		return ( strlen( $digits ) === 8 ) ? $digits : '';
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
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'tel1'         => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
		];

		foreach ( $required_fields as $field_key => $label ) {
			$value = $this->get_mapped_field( $order, $field_key );
			if ( empty( $value ) ) {
				$errors->add( 'missing_' . $field_key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
			}
		}

		// Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
		// valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).

		// Adex exige EXACTEMENT 8 chiffres (tel_incorrect sinon).
		$tel1 = $this->normalize_phone( $this->get_mapped_field( $order, 'tel1' ) );
		if ( '' === $tel1 ) {
			$errors->add(
				'invalid_tel1',
				__( 'Téléphone principal invalide : Adex exige exactement 8 chiffres (ex : 22582700).', 'wc-multicarrier-sync' )
			);
		}

		$tel2_raw = $this->get_mapped_field( $order, 'tel2' );
		if ( '' !== trim( $tel2_raw ) && '' === $this->normalize_phone( $tel2_raw ) ) {
			$errors->add(
				'invalid_tel2',
				__( 'Téléphone secondaire invalide : Adex exige exactement 8 chiffres.', 'wc-multicarrier-sync' )
			);
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
	//  Création d'expédition
	// ─────────────────────────────────────────────

	public function create_shipment( WC_Order $order ) {
		$payload = $this->build_shipment_payload( $order );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload = apply_filters( 'wcmcs_adex_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Adex (AjouterVColis)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Adex payload: ' . wp_json_encode( $this->redact_payload( $payload ) ) );

		$response = $this->api_request( 'AjouterVColis', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Format création : { "success": 1, "num_suivi_cmd": "...", "url_bl": "..." }
		if ( empty( $response['success'] ) ) {
			return $this->map_api_error( $response );
		}

		$tracking_id = trim( (string) ( $response['num_suivi_cmd'] ?? '' ) );
		if ( empty( $tracking_id ) ) {
			return new WP_Error( 'no_tracking_id', __( 'Adex : aucun numéro de suivi dans la réponse.', 'wc-multicarrier-sync' ), $response );
		}

		// BL PDF (url_bl) : URL PUBLQUE stockée SANS credentials — le token
		// (valable 1 an) n'est plus persisté dans _wcmcs_label_url ni exposé
		// dans les liens admin (revue de code 2.10.0). Le téléchargement passe
		// par get_label_pdf() (fetch serveur + sauvegarde locale).
		$label_url = '';
		if ( ! empty( $response['url_bl'] ) ) {
			$label_url = esc_url_raw( (string) $response['url_bl'] );
		}

		WCMCS_Logger::info( "Adex: colis créé num_suivi={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => $label_url,
			'raw'         => $this->redact_payload( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Étiquette PDF (fetch serveur + sauvegarde locale)
	// ─────────────────────────────────────────────

	/**
	 * Télécharge le BL PDF côté serveur (printColis/{codeBar} GET avec auth
	 * en query — doc Adex) puis le sauvegarde localement (pattern Colissimo).
	 * Les credentials ne quittent JAMAIS le serveur : plus de token (1 an)
	 * exposé dans _wcmcs_label_url / liens admin (revue de code 2.10.0).
	 *
	 * @return string|WP_Error URL locale du PDF (nonce frais à l'affichage).
	 */
	public function get_label_pdf( string $tracking_id, int $order_id ) {
		$order = wc_get_order( $order_id );

		// L'API renvoie url_bl à la création — la re-construire sinon.
		$url = '';
		if ( $order instanceof WC_Order ) {
			$stored = (string) WCMCS_Order_Meta::get_label_url( $order );
			if ( '' !== $stored ) {
				$url = $stored;
			}
		}
		if ( '' === $url ) {
			$url = rtrim( self::API_BASE, '/' ) . '/printColis/' . rawurlencode( $tracking_id );
		}

		// Auth en query (doc : printColis/{codeBar}?Utilisateur=…&Pass=…).
		$authed = add_query_arg( [
			'Utilisateur' => rawurlencode( $this->utilisateur ),
			'Pass'        => rawurlencode( $this->token ),
		], $url );

		WCMCS_Logger::info( "Adex: téléchargement du BL {$tracking_id} (serveur → local)." );

		$response = wp_remote_get( $authed, [ 'timeout' => $this->api_timeout ] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'adex_label_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || 0 !== strpos( trim( $body ), '%PDF' ) ) {
			return new WP_Error(
				'adex_label_invalid',
				sprintf( __( 'Adex : BL PDF non disponible (HTTP %d) — vérifiez que le colis est encore annulable/existant.', 'wc-multicarrier-sync' ), $code )
			);
		}

		return $this->save_label_pdf( $tracking_id, base64_encode( $body ), $order_id );
	}

	/**
	 * Sauvegarde le BL PDF localement (pattern Colissimo/Aramex) — URL
	 * publique {uploads}/wcmcs-labels/ : get_label_display_url() régénère
	 * un nonce frais à chaque affichage.
	 *
	 * @return string|WP_Error URL locale du fichier.
	 */
	private function save_label_pdf( string $tracking_id, string $base64_pdf, int $order_id ) {
		try {
			$upload_dir = wp_upload_dir();
			$label_dir   = $upload_dir['basedir'] . '/wcmcs-labels/' . $order_id . '/';

			if ( ! file_exists( $label_dir ) ) {
				wp_mkdir_p( $label_dir );
			}
			if ( ! file_exists( $label_dir . '.htaccess' ) ) {
				file_put_contents( $label_dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
			}

			$decoded = base64_decode( $base64_pdf, true );
			if ( false === $decoded ) {
				return new WP_Error( 'adex_label_decode', __( 'Adex : échec décodage BL PDF.', 'wc-multicarrier-sync' ) );
			}

			$filename = sanitize_file_name( 'adex_' . $tracking_id . '_' . time() . '.pdf' );
			$written  = file_put_contents( $label_dir . $filename, $decoded );
			if ( false === $written || $written !== strlen( $decoded ) ) {
				return new WP_Error( 'adex_label_write', __( 'Adex : échec sauvegarde BL PDF.', 'wc-multicarrier-sync' ) );
			}

			return $upload_dir['baseurl'] . '/wcmcs-labels/' . $order_id . '/' . $filename;

		} catch ( \Throwable $e ) {
			return new WP_Error( 'adex_label_error', $e->getMessage() );
		}
	}

	// ─────────────────────────────────────────────
	//  Annulation (RÉELLE — uniquement « En Attente »)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		WCMCS_Logger::info( "Adex: annulation du colis {$tracking_id}" );

		$response = $this->api_request( 'supprimerColis', [ 'codeBar' => $tracking_id ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['result_type'] ) || 'success' !== $response['result_type'] ) {
			$code = (string) ( $response['result_code'] ?? '' );
			if ( 'colis_non_supprimable' === $code ) {
				return new WP_Error(
					'adex_cancel_state',
					__( 'Adex : le colis ne peut plus être annulé (uniquement en état « En Attente », avant enlèvement).', 'wc-multicarrier-sync' ),
					$this->redact_payload( $response )
				);
			}
			return $this->map_api_error( $response );
		}

		WCMCS_Logger::info( "Adex: colis {$tracking_id} supprimé avec succès." );
		return true;
	}

	// ─────────────────────────────────────────────
	//  Tracking
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		$response = $this->api_request( 'getColis', [ 'codeBar' => $tracking_id ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['result_type'] ) || 'success' !== $response['result_type'] ) {
			$code = (string) ( $response['result_code'] ?? '' );
			if ( 'colis_non_trouve' === $code ) {
				return new WP_Error( 'adex_not_found', __( 'Adex : colis introuvable.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			}
			return $this->map_api_error( $response );
		}

		$content = $response['result_content'] ?? [];
		if ( ! is_array( $content ) ) {
			return new WP_Error( 'adex_invalid_response', __( 'Adex : réponse de suivi inattendue.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
		}

		return [
			'tracking_id'  => $tracking_id,
			'state_code'   => (string) ( $content['code_etat'] ?? '' ),
			'state_label'  => (string) ( $content['etat'] ?? '' ),          // 7 états : En Attente → Retour Expéditeur
			'ville'        => (string) ( $content['ville'] ?? '' ),          // délégation du destinataire
			'gouvernorat'  => (string) ( $content['gouvernorat'] ?? '' ),
			'raw'          => $content,
		];
	}

	// ─────────────────────────────────────────────
	//  Construction du payload
	// ─────────────────────────────────────────────

	/**
	 * Payload AjouterVColis.
	 *
	 * @return array|WP_Error
	 */
	private function build_shipment_payload( WC_Order $order ) {
		$total_qty  = 0;
		$item_names = [];
		foreach ( $order->get_items() as $item ) {
			$qty          = $item->get_quantity();
			$total_qty   += $qty;
			$item_names[] = WCMCS_Localization::item_designation_french( $item ) . ' x' . $qty;
		}
		$nb_pieces = max( 1, $total_qty );

		$client      = $this->get_mapped_field( $order, 'client' );
		$adresse     = $this->get_mapped_field( $order, 'adresse' );
		$tel1        = $this->normalize_phone( $this->get_mapped_field( $order, 'tel1' ) );
		$tel2_raw    = $this->get_mapped_field( $order, 'tel2' );
		$tel2        = '' !== trim( $tel2_raw ) ? $this->normalize_phone( $tel2_raw ) : '';
		$commentaire = $this->get_mapped_field( $order, 'commentaire' );
		$ville       = $this->get_mapped_field( $order, 'ville' );

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
			return $gouvernorat;
		}

		// Ville arabe → français (fallback : gouvernorat résolu).
		$ville = WCMCS_Localization::translate_ville( $ville, $gouvernorat );

		// type_colis_tab : une taille par pièce (ex : « 0/0/2 » pour 3 pièces).
		$type_colis_tab = implode( '/', array_fill( 0, $nb_pieces, $this->taille ) );

		$payload = [
			'Utilisateur'  => $this->utilisateur,
			'Pass'         => $this->token,
			'reference'    => (string) $order->get_id(),
			'client'       => $client,
			'adresse'      => $adresse,
			'ville'        => $ville,
			'gouvernorat'  => $gouvernorat,
			'nb_pieces'    => $nb_pieces,
			'prix'         => number_format( (float) $order->get_total(), 3, '.', '' ),
			'tel1'         => $tel1,
			'designation'  => implode( '; ', $item_names ),
			'type'         => $this->type,
			'type_colis_tab' => $type_colis_tab,
			'fragile'      => $this->fragile,
			'autorisation_ouv' => $this->autorisation_ouv,
			'autorisation_chq' => $this->autorisation_chq,
			'echange'      => $this->echange,
		];

		if ( '' !== $tel2 ) {
			$payload['tel2'] = $tel2;
		}
		if ( '' !== $commentaire ) {
			$payload['commentaire'] = $commentaire;
		}
		if ( 1 === $this->echange ) {
			// produit_arecevoir obligatoire si echange=1.
			$payload['produit_arecevoir'] = '' !== $this->produit_arecevoir
				? $this->produit_arecevoir
				: implode( '; ', $item_names );
		}
		if ( $this->adresse_enlevement > 0 ) {
			$payload['adresse_enlevement'] = $this->adresse_enlevement;
		}

		return $payload;
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (JSON, auth dans le body)
	// ─────────────────────────────────────────────

	/**
	 * Appel API Adex — POST JSON, Utilisateur/Pass injectés dans le body.
	 *
	 * @param string $endpoint AjouterVColis|getColis|supprimerColis|…
	 * @param array  $body     Champs (auth injectée automatiquement).
	 * @return array|WP_Error
	 */
	private function api_request( string $endpoint, array $body ) {
		$body['Utilisateur'] = $this->utilisateur;
		$body['Pass']        = $this->token;

		$url  = rtrim( self::API_BASE, '/' ) . '/' . $endpoint;
		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		];

		WCMCS_Logger::info( "Adex API request: {$endpoint}" );
		WCMCS_Logger::debug( 'Adex request payload: ' . wp_json_encode( $this->redact_payload( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Adex HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'adex_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Adex response [{$status_code}]" );
		WCMCS_Logger::debug( 'Adex response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "Adex: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'adex_invalid_json',
				sprintf( __( 'Adex : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}

		return $parsed;
	}

	// ─────────────────────────────────────────────
	//  Sécurité des logs + erreurs
	// ─────────────────────────────────────────────

	/**
	 * Masque le token (Pass) + redacte la PII avant tout logging.
	 */
	private function redact_payload( array $payload ): array {
		if ( isset( $payload['Pass'] ) ) {
			$payload['Pass'] = '[REDACTED]';
		}
		return WCMCS_Logger::redact_pii( $payload );
	}

	/**
	 * Mappe une erreur result_type=erreur en WP_Error FR lisible.
	 */
	private function map_api_error( array $response ): WP_Error {
		$code = (string) ( $response['result_code'] ?? '' );
		$msg  = (string) ( $response['result_content'] ?? '' );

		switch ( $code ) {
			case 'auth_manquante':
			case 'auth_invalide':
			case 'token_invalide':
			case 'token_expire':
				return new WP_Error( 'adex_auth', __( 'Adex : identifiants ou token API invalides/expirés — vérifiez Utilisateur et Token dans les réglages Adex.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			case 'tel_incorrect':
				return new WP_Error( 'adex_tel', __( 'Adex : téléphone invalide (exactement 8 chiffres requis).', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			case 'ville_incorrecte':
				return new WP_Error( 'adex_ville', __( 'Adex : ville non reconnue — vérifiez la ville (délégation) de la commande.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			case 'champ_manquant':
				return new WP_Error( 'adex_champ', __( 'Adex : un champ requis est manquant.', 'wc-multicarrier-sync' ) . ( $msg ? ' (' . $msg . ')' : '' ), $this->redact_payload( $response ) );
			case 'produit_arecevoir_requis':
				return new WP_Error( 'adex_echange', __( 'Adex : le champ « produit à recevoir » est obligatoire en cas d\'échange — renseignez-le dans les réglages Adex.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			case 'type_colis_tab_incorrect':
				return new WP_Error( 'adex_type_colis', __( 'Adex : type_colis_tab incohérent avec le nombre de pièces.', 'wc-multicarrier-sync' ) . ( $msg ? ' (' . $msg . ')' : '' ), $this->redact_payload( $response ) );
			case 'adresse_enlevement_incorrecte':
				return new WP_Error( 'adex_adresse', __( 'Adex : adresse d\'enlèvement inconnue — vérifiez l\'ID dans les réglages Adex.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
			default:
				if ( $msg ) {
					return new WP_Error( 'adex_api_error', sprintf( __( 'Adex : %s', 'wc-multicarrier-sync' ), $msg ) . ( $code ? " ({$code})" : '' ), $this->redact_payload( $response ) );
				}
				return new WP_Error( 'adex_api_error', __( 'Adex : requête refusée par l\'API.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
		}
	}
}
