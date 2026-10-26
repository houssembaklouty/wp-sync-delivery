<?php
/**
 * Intégration Ciblex Express (ciblexexpress.tn).
 *
 * API https://api.ciblex.delivery/api — auth header x-access-token.
 *  - Création  : POST /orders/Create {client_name, phone, address, price}
 *  - Suivi     : POST /orders/GetOrder  {barcode}
 * NB : doc publique — les champs exacts de réponse sont à confirmer avec la
 * documentation du compte Ciblex (à la réception de l'accès API).
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Ciblex_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'ciblex';
	const LABEL    = 'Ciblex Express';
	const API_BASE = 'https://api.ciblex.delivery/api';

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'ville'         => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'tel'           => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
	];

	/** @var string x-access-token Ciblex. */
	private $token;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->token       = (string) get_option( 'wcmcs_ciblex_token', '' );
		$this->api_timeout = (int) apply_filters( 'wcmcs_ciblex_api_timeout', 30 );
	}

	public function get_slug(): string { return self::SLUG; }
	public function get_label(): string { return self::LABEL; }
	public function mapping_option_prefix(): string { return 'wcmcs_ciblex_map_'; }
	public function is_active(): bool { return true; }
	public function is_configured(): bool { return ! empty( $this->token ); }

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_ciblex_token',
				'label'       => __( 'Token API (x-access-token)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token reçu après demande d\'accès API sur ciblexexpress.tn (page API → « Demander un accès API »).', 'wc-multicarrier-sync' ),
			],
		];

		$fields[] = [
			'id'    => 'wcmcs_ciblex_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Ciblex', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du destinataire (client_name)', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse (address)', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat (additionnel)', 'wc-multicarrier-sync' ),
			'ville'         => __( 'Ville/délégation (additionnelle)', 'wc-multicarrier-sync' ),
			'tel'           => __( 'Téléphone (phone)', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			$fields[] = [
				'id'               => 'wcmcs_ciblex_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_ciblex_map_' . $field_key . '_fallback',
				'fallback_default' => $defaults['fallback'],
			];
		}

		return $fields;
	}

	public function validate_order( WC_Order $order ) {
		$errors = new WP_Error();
		foreach ( [ 'nom' => __( 'Nom', 'wc-multicarrier-sync' ), 'adresse' => __( 'Adresse', 'wc-multicarrier-sync' ), 'tel' => __( 'Téléphone', 'wc-multicarrier-sync' ) ] as $key => $label ) {
			if ( empty( $this->get_mapped_field( $order, $key ) ) ) {
				$errors->add( 'missing_' . $key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
			}
		}
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
		$payload = apply_filters( 'wcmcs_ciblex_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Ciblex (Create)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Ciblex payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( 'orders/Create', $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$tracking_id = $this->extract_tracking( $response );
		WCMCS_Logger::info( 'Ciblex: colis créé' . ( $tracking_id ? " barcode={$tracking_id}" : ' (barcode non extrait — à confirmer avec la doc du compte)' ) );
		if ( empty( $tracking_id ) ) {
			return new WP_Error(
				'ciblex_no_tracking',
				sprintf( __( 'Ciblex : création acceptée mais barcode introuvable dans la réponse (HTTP 2xx). Réponse brute : %s', 'wc-multicarrier-sync' ), wp_json_encode( WCMCS_Logger::redact_pii( $response ) ) )
			);
		}

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => '',
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error( 'ciblex_cancel_unsupported', __( 'Ciblex : l\'annulation via l\'API n\'est pas documentée à ce jour. Contactez Ciblex.', 'wc-multicarrier-sync' ) );
	}

	public function get_tracking_status( string $tracking_id ) {
		$response = $this->api_request( 'orders/GetOrder', [ 'barcode' => $tracking_id ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$state = $this->extract_state( $response );
		if ( '' === $state ) {
			WCMCS_Logger::info( 'Ciblex: statut introuvable pour ' . $tracking_id . ' — réponse: ' . wp_json_encode( WCMCS_Logger::redact_pii( $response ) ) );
			return new WP_Error( 'ciblex_no_state', __( 'Ciblex : statut du colis introuvable dans la réponse (à confirmer avec la doc du compte).', 'wc-multicarrier-sync' ) );
		}

		return [
			'state_code'  => '',
			'state_label' => $state,
		];
	}

	// ─────────────────────────────────────────────
	//  Payloads / parsing
	// ─────────────────────────────────────────────

	/**
	 * Payload documenté : client_name, phone, address, price (COD).
	 * Gouvernorat + délégation traduits en français canonique et envoyés en
	 * tant que champs additionnels NON documentés (filtrables via
	 * wcmcs_ciblex_payload) — résolution via le système de mapping.
	 */
	private function build_shipment_payload( WC_Order $order ) {
		$state_field = $this->get_mapped_field( $order, 'gouvernorat' );
		$ville_field = $this->get_mapped_field( $order, 'ville' );

		// Constante stricte key→value de la doc Ciblex — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'ciblex' );
		if ( null === $gouvernorat ) {
			$raw = WCMCS_Localization::translate_gouvernorat( $state_field, $ville_field );
			return new WP_Error(
				'gouvernorat_invalide',
				sprintf(
					/* translators: 1: valeur reçue, 2: liste officielle */
					__( 'Gouvernorat invalide « %1$s » — hors liste Ciblex. Liste officielle : %2$s. Corrigez l\'adresse de livraison de la commande.', 'wc-multicarrier-sync' ),
					'' !== $raw ? $raw : '(vide)',
					implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'ciblex' ) )
				)
			);
		}

		$ville_source = $this->get_mapped_field( $order, 'ville' );
		$ville        = '' !== $ville_source ? WCMCS_Localization::translate_ville( $ville_source, $gouvernorat ) : '';

		$payload = [
			'client_name' => $this->get_mapped_field( $order, 'nom' ),
			'phone'       => $this->get_mapped_field( $order, 'tel' ),
			'address'     => $this->get_mapped_field( $order, 'adresse' ),
			'price'       => (float) $order->get_total(),
		];

		if ( '' !== $gouvernorat ) {
			$payload['gouvernorat'] = $gouvernorat;
		}
		if ( '' !== $ville ) {
			$payload['ville'] = $ville;
		}

		return $payload;
	}

	/**
	 * Extrait le barcode / tracking de la réponse (clés probables, récursif).
	 *
	 * Ordre anti-enveloppe (revue de code 2.10.0) : ① clés FORTES
	 * (barcode, code_barre, reference…) sans ambiguïté · ② récursion dans
	 * les niveaux imbriqués (les données réelles) · ③ clés FAIBLES
	 * (code, id, order_id) en dernier recours avec garde anti-enveloppe —
	 * une réponse {"status":"success","code":200,"data":{barcode}} faisait
	 * capturer "200" comme tracking (poll en échec permanent ensuite).
	 */
	private function extract_tracking( $data, int $depth = 0 ) {
		if ( $depth > 3 || ! is_array( $data ) ) {
			return '';
		}

		// ① Clés fortes : non ambiguës, testées en priorité.
		$strong_keys = [ 'barcode', 'bar_code', 'code_barre', 'reference', 'ref', 'tracking', 'tracking_id' ];
		foreach ( $strong_keys as $key ) {
			if ( isset( $data[ $key ] ) && ( is_string( $data[ $key ] ) || is_numeric( $data[ $key ] ) ) && '' !== (string) $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}

		// ② Récursion : le barcode est plus probablement imbriqué (data/order).
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->extract_tracking( $value, $depth + 1 );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		// ③ Clés faibles en dernier recours, à condition que la valeur ne
		// ressemble pas à une enveloppe (numérique court, mot d'état HTTP).
		$weak_keys = [ 'code', 'order_id', 'id' ];
		foreach ( $weak_keys as $key ) {
			if ( isset( $data[ $key ] ) && ( is_string( $data[ $key ] ) || is_numeric( $data[ $key ] ) )
				&& '' !== (string) $data[ $key ]
				&& ! $this->is_envelope_value( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}
		return '';
	}

	/**
	 * Extrait le libellé d'état de la réponse GetOrder (clés probables).
	 *
	 * Les « enveloppes » typiques du haut de réponse (status: success/OK/200)
	 * sont ignorées — la recherche se poursuit dans les niveaux imbriqués
	 * (données réelles) pour ne pas masquer l'état du colis.
	 */
	private function extract_state( $data, int $depth = 0 ) {
		if ( $depth > 3 || ! is_array( $data ) ) {
			return '';
		}
		$keys = [ 'status', 'statut', 'state', 'etat', 'status_label', 'last_status', 'order_status' ];
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && ( is_string( $data[ $key ] ) || is_numeric( $data[ $key ] ) ) && ! $this->is_envelope_value( $data[ $key ] ) ) {
				$label = trim( (string) $data[ $key ] );
				if ( '' !== $label ) {
					return $label;
				}
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->extract_state( $value, $depth + 1 );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	/**
	 * Détecte une valeur d'enveloppe API (statut de réponse, pas état colis).
	 */
	private function is_envelope_value( $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}
		$v = mb_strtolower( trim( (string) $value ), 'UTF-8' );
		if ( preg_match( '/^(ok|success|successed|true|false|error|failed|fail|invalid|done|sent|succeed)$/u', $v ) ) {
			return true;
		}
		// Codes HTTP / numériques courts (200, 201, 404…) — un état numérique
		// court reste possible mais l'enveloppe est l'hypothèse la plus probable.
		if ( preg_match( '/^\d{1,3}$/', $v ) ) {
			return true;
		}
		return false;
	}

	// ─────────────────────────────────────────────
	//  HTTP
	// ─────────────────────────────────────────────

	private function api_request( string $endpoint, array $body ) {
		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [
				'x-access-token' => $this->token,
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		];

		$url = self::API_BASE . '/' . ltrim( $endpoint, '/' );
		WCMCS_Logger::info( 'Ciblex API request: ' . $url );
		WCMCS_Logger::debug( 'Ciblex request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Ciblex HTTP error: {$msg}" );
			return new WP_Error( 'ciblex_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Ciblex response [{$status_code}]" );
		WCMCS_Logger::debug( 'Ciblex response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'ciblex_invalid_json', sprintf( __( 'Ciblex : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code ) );
		}
		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error( 'ciblex_auth', __( 'Ciblex : token invalide — vérifiez votre x-access-token dans les réglages Ciblex.', 'wc-multicarrier-sync' ) );
		}
		if ( $status_code >= 400 ) {
			$msg = (string) ( $parsed['message'] ?? $parsed['msg'] ?? $parsed['error'] ?? '' );
			return new WP_Error( 'ciblex_api_error', ( $msg ? sprintf( __( 'Ciblex : %s', 'wc-multicarrier-sync' ), $msg ) : sprintf( __( 'Ciblex : erreur HTTP %d.', 'wc-multicarrier-sync' ), $status_code ) ) );
		}

		return $parsed;
	}

	// ─────────────────────────────────────────────
	//  Utilitaires
	// ─────────────────────────────────────────────

	/**
	 * Normalisation insensible à la casse et aux accents (comparaison
	 * gouvernorats) : minuscules, sans accents, alphanumérique.
	 */
	private function normalize( string $str ): string {
		$str = remove_accents( mb_strtolower( trim( $str ), 'UTF-8' ) );
		$str = preg_replace( '/[^a-z0-9]/', '', $str );
		return (string) $str;
	}
}
