<?php
/**
 * Intégration Shipper Network (shipper.network/tn).
 *
 * API REST v1 — auth Bearer token.
 *  - Création   : POST /orders (JSON) → { "id": 555 }
 *  - Suivi      : GET /orders/{id} → shipments → tracking_numbers → logs
 *  - Annulation : POST /orders/{id}/cancel ✓
 *  - Webhooks   : POST /webhooks (avec HMAC-SHA256) ✓ (futur)
 *
 * ⚠ Shipper Network est une plateforme de DROPSHIPPING / marketplace :
 *  - Les items référencent des produits ENREGISTRÉS dans leur catalogue (UUIDs)
 *  - Un produit UUID par défaut (réglage) est envoyé pour toutes les commandes
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Shipper_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'shipper';
	const LABEL    = 'Shipper Network';
	const API_BASE = 'https://server.shipper.network/api/v1';

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
	];

	/** @var string API key Shipper (Bearer). */
	private $api_key;

	/** @var string UUID du produit Shipper par défaut (catalogue Shipper). */
	private $default_product_uuid;

	/** @var bool Envoyer directement en fulfilment (auto_fulfill). */
	private $auto_fulfill;

	/** @var float Frais de livraison facturés au client (shipping_total). */
	private $shipping_total;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->api_key             = (string) get_option( 'wcmcs_shipper_api_key', '' );
		$this->default_product_uuid = (string) get_option( 'wcmcs_shipper_product_uuid', '' );
		$this->auto_fulfill        = get_option( 'wcmcs_shipper_auto_fulfill', 'no' ) === 'yes';
		$this->shipping_total      = (float) get_option( 'wcmcs_shipper_shipping_total', 0 );
		$this->api_timeout         = (int) apply_filters( 'wcmcs_shipper_api_timeout', 30 );
	}

	public function get_slug(): string { return self::SLUG; }
	public function get_label(): string { return self::LABEL; }
	public function mapping_option_prefix(): string { return 'wcmcs_shipper_map_'; }
	public function is_active(): bool { return true; }
	public function is_configured(): bool { return ! empty( $this->api_key ) && ! empty( $this->default_product_uuid ); }

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_shipper_api_key',
				'label'       => __( 'Clé API (Bearer)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Clé API depuis votre dashboard Shipper (app.shipper.market) → Integrations → API.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_shipper_product_uuid',
				'label'       => __( 'UUID produit par défaut', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'placeholder' => '2e97b37a-3118-4905-842c-19706bcf1455',
				'description' => __( 'UUID d\'un produit dans votre catalogue Shipper (GET /products). Utilisé pour toutes les commandes — Shipper est une plateforme dropshipping.', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_shipper_auto_fulfill',
				'label'   => __( 'Fulfillement automatique', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non (brouillon)', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui (auto_fulfill)', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
				'description' => __( '« Oui » envoie directement en préparation sans brouillon.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_shipper_shipping_total',
				'label'       => __( 'Frais de livraison facturés (shipping_total)', 'wc-multicarrier-sync' ),
				'type'        => 'number',
				'default'     => 0,
				'min'         => 0,
				'step'        => 0.5,
				'description' => __( 'Frais de livraison facturés au client (en TND). 0 par défaut.', 'wc-multicarrier-sync' ),
			],
		];

		$fields[] = [
			'id'    => 'wcmcs_shipper_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Shipper (destinataire)', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du destinataire', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse (address1)', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat (division_1)', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville/Délégation (division_2)', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone (phone1)', 'wc-multicarrier-sync' ),
			'tel2'         => __( 'Téléphone secondaire (phone2)', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			$fields[] = [
				'id'               => 'wcmcs_shipper_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_shipper_map_' . $field_key . '_fallback',
				'fallback_default' => $defaults['fallback'],
			];
		}

		return $fields;
	}

		private function resolve_gouvernorat( WC_Order $order ) {
		$state_field = $this->get_mapped_field( $order, 'gouvernorat' );
		$ville_field = $this->get_mapped_field( $order, 'ville' );

		// Constante stricte key→value de la doc Shipper — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'shipper' );
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
				'Shipper',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'shipper' ) )
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
		foreach ( [ 'nom' => __( 'Nom', 'wc-multicarrier-sync' ), 'adresse' => __( 'Adresse', 'wc-multicarrier-sync' ), 'tel' => __( 'Téléphone', 'wc-multicarrier-sync' ), 'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ) ] as $key => $label ) {
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
		$payload = apply_filters( 'wcmcs_shipper_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Shipper (POST /orders)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Shipper payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( 'POST', '/orders', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Response : { "id": 555 }
		$order_id = (int) ( $response['id'] ?? 0 );
		if ( ! $order_id ) {
			return new WP_Error( 'shipper_no_id', __( 'Shipper : aucun ID de commande dans la réponse.', 'wc-multicarrier-sync' ), $response );
		}

		WCMCS_Logger::info( "Shipper: commande créée ID={$order_id}" );

		return [
			'tracking_id' => (string) $order_id,
			'label_url'   => '',
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		$id = absint( $tracking_id );
		if ( ! $id ) {
			return new WP_Error( 'shipper_invalid_id', __( 'Shipper : ID de commande invalide.', 'wc-multicarrier-sync' ) );
		}

		$response = $this->api_request( 'POST', "/orders/{$id}/cancel", [] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		WCMCS_Logger::info( "Shipper: commande {$id} annulée." );
		return true;
	}

	public function get_tracking_status( string $tracking_id ) {
		$id = absint( $tracking_id );
		if ( ! $id ) {
			return new WP_Error( 'shipper_invalid_id', __( 'Shipper : ID invalide.', 'wc-multicarrier-sync' ) );
		}

		$response = $this->api_request( 'GET', "/orders/{$id}", [] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (string) ( $response['status'] ?? '' );
		$shipments = $response['shipments'] ?? [];

		// Dernier statut de livraison.
		$state = $status;
		$state_label = $status;
		if ( is_array( $shipments ) && ! empty( $shipments ) ) {
			foreach ( $shipments as $sh ) {
				if ( ! empty( $sh['status'] ) ) {
					$state = (string) $sh['status'];
					$state_label = $state;
					break;
				}
			}
		}

		return [
			'tracking_id' => $tracking_id,
			'state_code'  => $state,
			'state_label' => $state_label,
			'order_status' => $status,
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	private function build_shipment_payload( WC_Order $order ) {
		$total_qty = 0;
		foreach ( $order->get_items() as $item ) {
			$total_qty += $item->get_quantity();
		}

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			return $gouvernorat;
		}

		$ville = WCMCS_Localization::translate_ville( $this->get_mapped_field( $order, 'ville' ), $gouvernorat );

		$payload = [
			'address' => [
				'name'       => $this->get_mapped_field( $order, 'nom' ),
				'phone1'     => $this->get_mapped_field( $order, 'tel' ),
				'phone2'     => $this->get_mapped_field( $order, 'tel2' ),
				'address1'   => $this->get_mapped_field( $order, 'adresse' ),
				'division_1' => $gouvernorat,    // Gouvernorat (nom exact de /countries/TN/divisions)
				'division_2' => $ville,         // Délégation
				'country'    => 'TN',
			],
			'items' => [
				[
					'id'          => $this->default_product_uuid,
					'quantity'    => max( 1, $total_qty ),
					'total_price' => (float) $order->get_total(),
				],
			],
			'shipping_total' => $this->shipping_total,
			'is_cod'         => true,
			'store_name'     => get_bloginfo( 'name' ),
			'external_order_id'  => (string) $order->get_id(),
			'external_order_url' => $order->get_edit_order_url(),
		];

		if ( $this->auto_fulfill ) {
			$payload['auto_fulfill'] = true;
		}

		return $payload;
	}

	private function api_request( string $method, string $endpoint, array $body = [] ) {
		$url  = rtrim( self::API_BASE, '/' ) . $endpoint;
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => $this->api_timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			],
		];

		if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		WCMCS_Logger::info( "Shipper API request: {$method} {$endpoint}" );
		WCMCS_Logger::debug( 'Shipper request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Shipper HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'shipper_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Shipper response [{$status_code}]" );
		WCMCS_Logger::debug( 'Shipper response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error( 'shipper_auth', __( 'Shipper : clé API invalide — vérifiez votre clé dans les réglages Shipper.', 'wc-multicarrier-sync' ) );
		}
		if ( 429 === $status_code ) {
			return new WP_Error( 'shipper_rate_limit', __( 'Shipper : limite de débit atteinte (30 écritures/min). Réessayez dans un instant.', 'wc-multicarrier-sync' ) );
		}
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'shipper_invalid_json', sprintf( __( 'Shipper : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code ) );
		}
		if ( $status_code >= 400 ) {
			$msg = (string) ( $parsed['error'] ?? $parsed['message'] ?? '' );
			return new WP_Error( 'shipper_api_error', ( $msg ? sprintf( __( 'Shipper : %s', 'wc-multicarrier-sync' ), $msg ) : sprintf( __( 'Shipper : erreur HTTP %d.', 'wc-multicarrier-sync' ), $status_code ) ) . " [{$status_code}]", $parsed );
		}

		return $parsed;
	}
}
