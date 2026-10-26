<?php
/**
 * Intégration Navex (navex.tn).
 *
 * API v1 — auth Basic (token depuis l'espace expéditeur Navex).
 *  - Création : POST https://app.navex.tn/api/v1/post.php (form-urlencoded)
 *  - NB : l'API publique ne documente QUE la création (pas de suivi/annulation/label).
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Navex_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'navex';
	const LABEL    = 'Navex';
	const API_URL  = 'https://app.navex.tn/api/v1/post.php';

	const GOVERNORATS = [
		'Ariana', 'Ben Arous', 'Bizerte', 'Béja', 'Gabès',
		'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
		'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine',
		'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
		'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
	];

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'tel'          => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'tel2'         => [ 'source' => '',                   'fallback' => '' ],
		'msg'          => [ 'source' => '',                     'fallback' => '' ],
	];

	/** @var string Token Navex (Basic auth). */
	private $token;

	/** @var string Nom de l'expéditeur (sender_name — marketplaces). */
	private $sender_name;

	/** @var string Adresse de l'expéditeur (sender_location). */
	private $sender_location;

	/** @var string Gouvernorat de l'expéditeur (sender_gouvernorat). */
	private $sender_gouvernorat;

	/** @var int Échange (0/1). */
	private $echange;

	/** @var int Autoriser l'ouverture (0/1). */
	private $ouvrir;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->token             = (string) get_option( 'wcmcs_navex_token', '' );
		$this->sender_name       = (string) get_option( 'wcmcs_navex_sender_name', '' );
		$this->sender_location  = (string) get_option( 'wcmcs_navex_sender_location', '' );
		$this->sender_gouvernorat = (string) get_option( 'wcmcs_navex_sender_gouvernorat', '' );
		$this->echange          = get_option( 'wcmcs_navex_echange', 'no' ) === 'yes' ? 1 : 0;
		$this->ouvrir            = get_option( 'wcmcs_navex_ouvrir', 'no' ) === 'yes' ? 1 : 0;
		$this->api_timeout       = (int) apply_filters( 'wcmcs_navex_api_timeout', 30 );
	}

	public function get_slug(): string { return self::SLUG; }
	public function get_label(): string { return self::LABEL; }
	public function mapping_option_prefix(): string { return 'wcmcs_navex_map_'; }
	public function is_active(): bool { return true; }
	public function is_configured(): bool { return ! empty( $this->token ); }

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_navex_token',
				'label'       => __( 'Token API (Basic auth)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token depuis votre espace expéditeur Navex (navex.tn).', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_navex_sender_name',
				'label'       => __( 'Nom de l\'expéditeur (sender_name)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Votre nom/boutique — requis par Navex (marketplaces).', 'wc-multicarrier-sync' ),
			],
			[
				'id'    => 'wcmcs_navex_sender_location',
				'label' => __( 'Adresse expéditeur (sender_location)', 'wc-multicarrier-sync' ),
				'type'  => 'text',
			],
			[
				'id'          => 'wcmcs_navex_sender_gouvernorat',
				'label'       => __( 'Gouvernorat expéditeur', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Gouvernorat de votre boutique/entrepôt.', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_navex_echange',
				'label'   => __( 'Commande avec échange', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_navex_ouvrir',
				'label'   => __( 'Autoriser l\'ouverture du colis', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
		];

		$fields[] = [
			'id'    => 'wcmcs_navex_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Navex', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du destinataire', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
			'tel2'         => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
			'msg'          => __( 'Message', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_navex_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_navex_map_' . $field_key . '_fallback',
				'fallback_default' => $defaults['fallback'],
			];
		}

		return $fields;
	}

		private function resolve_gouvernorat( WC_Order $order ) {
		$state_field = $this->get_mapped_field( $order, 'gouvernorat' );
		$ville_field = $this->get_mapped_field( $order, 'ville' );

		// Constante stricte key→value de la doc Navex — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'navex' );
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
				'Navex',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'navex' ) )
			)
		);
	}

	private function normalize( string $str ): string {
		$str = mb_strtolower( $str, 'UTF-8' );
		$str = remove_accents( $str );
		return preg_replace( '/[^a-z0-9]/', '', $str );
	}

	public function validate_order( WC_Order $order ) {
		$errors = new WP_Error();
		foreach ( [ 'nom' => __( 'Nom', 'wc-multicarrier-sync' ), 'adresse' => __( 'Adresse', 'wc-multicarrier-sync' ), 'ville' => __( 'Ville', 'wc-multicarrier-sync' ), 'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ), 'tel' => __( 'Téléphone', 'wc-multicarrier-sync' ) ] as $key => $label ) {
			if ( empty( $this->get_mapped_field( $order, $key ) ) ) {
				$errors->add( 'missing_' . $key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
			}
		}
		// Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
		// valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).
		if ( (float) $order->get_total() <= 0 ) {
			$errors->add( 'zero_total', __( 'Montant nul.', 'wc-multicarrier-sync' ) );
		}
		return $errors->has_errors() ? new WP_Error( 'validation_failed', implode( ' | ', $errors->get_error_messages() ) ) : true;
	}

	public function create_shipment( WC_Order $order ) {
		$payload = $this->build_shipment_payload( $order );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload = apply_filters( 'wcmcs_navex_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Navex (post.php)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Navex payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Response : { "status": ..., "colis": { code_barre|id|reference } }.
		// Le tracking est le code du bloc « colis » — AUCUN fallback sur
		// status_message : un message (« Product Added. ») polluait
		// _wcmcs_tracking_id (le poll horaire échouait dessus ensuite).
		$colis = $response['colis'] ?? [];
		$tracking_id = '';
		if ( is_array( $colis ) ) {
			$tracking_id = (string) ( $colis['code_barre'] ?? $colis['id'] ?? $colis['reference'] ?? '' );
		}
		if ( '' === $tracking_id ) {
			WCMCS_Logger::error( 'Navex: réponse sans code de suivi exploitable — ' . wp_json_encode( WCMCS_Logger::redact_pii( $response ) ) );
			return new WP_Error(
				'navex_no_tracking',
				__( 'Navex : aucun code de suivi dans la réponse de création (bloc « colis » absent). Détail dans les logs — contactez Navex si cela persiste.', 'wc-multicarrier-sync' )
			);
		}
		WCMCS_Logger::info( "Navex: colis créé ref={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => '',
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error( 'navex_cancel_unsupported', __( 'Navex : l\'annulation via l\'API n\'est pas disponible (endpoint non documenté). Contactez Navex.', 'wc-multicarrier-sync' ) );
	}

	public function get_tracking_status( string $tracking_id ) {
		return new WP_Error( 'navex_tracking_unsupported', __( 'Navex : le suivi via l\'API n\'est pas disponible (endpoint non documenté). Suivez sur navex.tn.', 'wc-multicarrier-sync' ) );
	}

	private function build_shipment_payload( WC_Order $order ) {
		$total_qty = 0;
		$item_names = [];
		foreach ( $order->get_items() as $item ) {
			$qty = $item->get_quantity();
			$total_qty += $qty;
			$item_names[] = WCMCS_Localization::item_designation_french( $item ) . ' x' . $qty;
		}

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			return $gouvernorat;
		}

		$ville = WCMCS_Localization::translate_ville( $this->get_mapped_field( $order, 'ville' ), $gouvernorat );

		return [
			'prix'               => number_format( (float) $order->get_total(), 3, '.', '' ),
			'nom'                => $this->get_mapped_field( $order, 'nom' ),
			'gouvernerat'       => $gouvernorat,
			'ville'              => $ville,
			'adresse'            => $this->get_mapped_field( $order, 'adresse' ),
			'tel'                => $this->get_mapped_field( $order, 'tel' ),
			'tel2'               => $this->get_mapped_field( $order, 'tel2' ),
			'designation'        => implode( '; ', $item_names ),
			'nb_article'         => max( 1, $total_qty ),
			'msg'                => $this->get_mapped_field( $order, 'msg' ),
			'echange'            => $this->echange,
			'article'            => $this->echange ? implode( '; ', $item_names ) : '',
			'nb_echange'         => $this->echange ? max( 1, $total_qty ) : 0,
			'ouvrir'             => $this->ouvrir,
			'sender_name'        => $this->sender_name,
			'sender_location'    => $this->sender_location,
			'sender_gouvernorat'  => $this->sender_gouvernorat,
		];
	}

	private function api_request( array $body ) {
		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $this->token . ':' ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			],
			'body'    => http_build_query( $body ),
		];

		WCMCS_Logger::info( 'Navex API request: ' . self::API_URL );
		WCMCS_Logger::debug( 'Navex request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( self::API_URL, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Navex HTTP error: {$msg}" );
			return new WP_Error( 'navex_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Navex response [{$status_code}]" );
		WCMCS_Logger::debug( 'Navex response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'navex_invalid_json', sprintf( __( 'Navex : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code ) );
		}
		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error( 'navex_auth', __( 'Navex : token invalide — vérifiez votre token dans les réglages Navex.', 'wc-multicarrier-sync' ) );
		}
		if ( $status_code >= 400 ) {
			$msg = (string) ( $parsed['status_message'] ?? $parsed['message'] ?? '' );
			return new WP_Error( 'navex_api_error', ( $msg ? sprintf( __( 'Navex : %s', 'wc-multicarrier-sync' ), $msg ) : sprintf( __( 'Navex : erreur HTTP %d.', 'wc-multicarrier-sync' ), $status_code ) ) );
		}

		return $parsed;
	}
}
