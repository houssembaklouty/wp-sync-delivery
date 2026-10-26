<?php
/**
 * Intégration Fiabilo (fiabilo.tn).
 *
 * API v1 — endpoint UNIQUE : https://www.fiabilo.tn/api/v1/post.php
 *  - Ajout de colis : body multipart (token d'ajout dans le champ `token`)
 *  - Tracking       : body multipart (token de tracking dans le champ `token`)
 *  - Réponse ajout : status 1 + `lien` (BL imprimable) + `status_message` (code à barre)
 *
 * NB : l'API ne propose pas d'annulation.
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Fiabilo_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'fiabilo';
	const LABEL    = 'Fiabilo';
	const API_URL  = 'https://www.fiabilo.tn/api/v1/post.php';

	/**
	 * Gouvernorats tunisiens (validation stricte, insensible casse/accents).
	 */
	const GOVERNORATS = [
		'Ariana', 'Ben Arous', 'Bizerte', 'Béja', 'Gabès',
		'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
		'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine',
		'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
		'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs Fiabilo
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'gouvernerat'  => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'cp'           => [ 'source' => 'shipping_postcode',  'fallback' => 'billing_postcode' ],
		'tel'          => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'tel2'         => [ 'source' => '',                   'fallback' => '' ],
		'msg'          => [ 'source' => '',                     'fallback' => '' ],
	];

	/** @var string Token d'ajout de colis. */
	private $add_token;

	/** @var string Token de tracking. */
	private $track_token;

	/** @var int Commande avec échange (0/1). */
	private $echange;

	/** @var int Autoriser l'ouverture du colis (0/1). */
	private $ouvrir;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->add_token   = (string) get_option( 'wcmcs_fiabilo_add_token', '' );
		$this->track_token = (string) get_option( 'wcmcs_fiabilo_track_token', '' );
		$this->echange     = get_option( 'wcmcs_fiabilo_echange', 'no' ) === 'yes' ? 1 : 0;
		$this->ouvrir      = get_option( 'wcmcs_fiabilo_ouvrir', 'no' ) === 'yes' ? 1 : 0;
		$this->api_timeout = (int) apply_filters( 'wcmcs_fiabilo_api_timeout', 30 );
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
		return 'wcmcs_fiabilo_map_';
	}

	public function is_active(): bool {
		return true;
	}

	public function is_configured(): bool {
		return ! empty( $this->add_token );
	}

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_fiabilo_add_token',
				'label'       => __( 'Token d\'ajout (API)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token d\'ajout de votre compte — Espace expéditeur → Vos Tokens (fiabilo.tn).', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_fiabilo_track_token',
				'label'       => __( 'Token de tracking (API)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token de tracking — fiabilo utilise deux tokens distincts.', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_fiabilo_echange',
				'label'   => __( 'Commande avec échange', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_fiabilo_ouvrir',
				'label'   => __( 'Autoriser l\'ouverture du colis', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_fiabilo_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Fiabilo', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du client', 'wc-multicarrier-sync' ),
			'gouvernerat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'cp'           => __( 'Code postal', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
			'tel2'         => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
			'msg'          => __( 'Message / remarque', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_fiabilo_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_fiabilo_map_' . $field_key . '_fallback',
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
	 * Résout le gouvernorat à partir du champ mappé. Valeur non reconnue :
	 * envoyée telle quelle — aucune interprétation de code.
	 *
	 * @return string|WP_Error  WP_Error uniquement si le champ est vide.
	 */
		private function resolve_gouvernorat( WC_Order $order ) {
		$state_field = $this->get_mapped_field( $order, 'gouvernerat' );
		$ville_field = $this->get_mapped_field( $order, 'ville' );

		// Constante stricte key→value de la doc Fiabilo — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'fiabilo' );
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
				'Fiabilo',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'fiabilo' ) )
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
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
			'gouvernerat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'cp'           => __( 'Code postal', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone', 'wc-multicarrier-sync' ),
		];

		foreach ( $required_fields as $field_key => $label ) {
			$value = $this->get_mapped_field( $order, $field_key );
			if ( empty( $value ) ) {
				$errors->add( 'missing_' . $field_key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
			}
		}

		// Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
		// valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).

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
		$payload = apply_filters( 'wcmcs_fiabilo_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Fiabilo (post.php — ajout)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Fiabilo payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( $payload, $this->add_token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['status'] ) ) {
			$msg = (string) ( $response['status_message'] ?? __( 'Erreur inconnue Fiabilo.', 'wc-multicarrier-sync' ) );
			WCMCS_Logger::error( "Fiabilo API error: {$msg}" );
			return new WP_Error( 'fiabilo_api_error', sprintf( __( 'Fiabilo : %s', 'wc-multicarrier-sync' ), $msg ), $response );
		}

		$tracking_id = trim( (string) ( $response['status_message'] ?? '' ) );
		if ( empty( $tracking_id ) ) {
			return new WP_Error( 'no_tracking_id', __( 'Fiabilo : aucun code à barre dans la réponse.', 'wc-multicarrier-sync' ), $response );
		}

		// BL (bon de livraison) imprimable — lien direct fourni par l'API.
		$label_url = esc_url_raw( (string) ( $response['lien'] ?? '' ) );

		WCMCS_Logger::info( "Fiabilo: colis créé code={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => $label_url,
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Annulation (non supportée par l'API)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error(
			'fiabilo_cancel_unsupported',
			__( 'Fiabilo : l\'annulation via l\'API n\'est pas disponible. Contactez votre agence Fiabilo.', 'wc-multicarrier-sync' )
		);
	}

	// ─────────────────────────────────────────────
	//  Tracking
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		$response = $this->api_request( [ 'code' => $tracking_id ], $this->track_token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['status'] ) ) {
			$msg = (string) ( $response['status_message'] ?? __( 'Erreur inconnue Fiabilo.', 'wc-multicarrier-sync' ) );
			return new WP_Error( 'fiabilo_api_error', sprintf( __( 'Fiabilo : %s', 'wc-multicarrier-sync' ), $msg ), $response );
		}

		return [
			'tracking_id' => $tracking_id,
			'state_code'  => (string) ( $response['etat'] ?? '' ),
			'state_label' => (string) ( $response['etat'] ?? '' ),
			'motif'       => (string) ( $response['motif'] ?? '' ),
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Construction du payload
	// ─────────────────────────────────────────────

	/**
	 * Payload d'ajout — le token est un champ du body (pas un header).
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

		$nom     = $this->get_mapped_field( $order, 'nom' );
		$tel     = $this->get_mapped_field( $order, 'tel' );
		$tel2    = $this->get_mapped_field( $order, 'tel2' );
		$adresse = $this->get_mapped_field( $order, 'adresse' );
		$ville   = $this->get_mapped_field( $order, 'ville' );
		$cp      = $this->get_mapped_field( $order, 'cp' );
		$msg     = $this->get_mapped_field( $order, 'msg' );

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
			return $gouvernorat;
		}

		// Ville arabe → français (fallback : gouvernorat résolu).
		$ville = WCMCS_Localization::translate_ville( $ville, $gouvernorat );

		$payload = [
			'prix'         => number_format( (float) $order->get_total(), 3, '.', '' ),
			'nom'          => $nom,
			'gouvernerat'  => $gouvernorat,
			'ville'        => $ville,
			'adresse'      => $adresse,
			'cp'           => $cp,
			'tel'          => $tel,
			'tel2'         => $tel2,
			'designation'  => implode( '; ', $item_names ),
			'nb_article'   => max( 1, $total_qty ),
			'msg'          => $msg,
			'echange'      => $this->echange,
			'article'      => $this->echange ? implode( '; ', $item_names ) : '',
			'nb_echange'   => $this->echange ? max( 1, $total_qty ) : 0,
			'ouvrir'       => $this->ouvrir,
			'token'        => $this->add_token,
		];

		return $payload;
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (multipart form-data, token body)
	// ─────────────────────────────────────────────

	/**
	 * Appel API Fiabilo — body en ARRAY => WordPress génère le
	 * multipart/form-data avec boundary automatiquement.
	 *
	 * @param array  $body  Champs POST (inclut le champ `token`).
	 * @param string $token Token adapté (ajout ou tracking).
	 * @return array|WP_Error
	 */
	private function api_request( array $body, string $token ) {
		$body['token'] = $token;

		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [ 'Accept' => 'application/json' ],
			'body'    => $body, // array → multipart/form-data automatique.
		];

		WCMCS_Logger::info( 'Fiabilo API request: ' . self::API_URL );
		WCMCS_Logger::debug( 'Fiabilo request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( self::API_URL, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Fiabilo HTTP error: {$msg} | URL: " . self::API_URL );
			return new WP_Error( 'fiabilo_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Fiabilo response [{$status_code}]" );
		WCMCS_Logger::debug( 'Fiabilo response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "Fiabilo: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'fiabilo_invalid_json',
				sprintf( __( 'Fiabilo : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}

		return $parsed;
	}
}
