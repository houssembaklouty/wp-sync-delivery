<?php
/**
 * Intégration INTIGO (intigo.tn).
 *
 * API REST v1 — auth « apiKey » DANS LE BODY JSON (dashboard → Profile).
 *  - Création   : POST /api/v1/externalapi/adddelivery → { "nid": 5212 }
 *  - Suivi      : POST /api/v1/externalapi/getdelivery → dropOff.status (libellé)
 *  - Bordereau  : GET  /api/v1/externalapi/bordereau/{nid} (PDF)
 *
 * ⚠ MAPPING INVERSÉ vs les autres transporteurs :
 *  - INTIGO « city »        = GOUVERNORAT (Tunis, Ariana, Sfax…)
 *  - INTIGO « subDivision » = DÉLÉGATION (Bardo, La Marsa…) ← notre champ « ville »
 *
 * ⚠ PickUp OBLIGATOIRE : adresse + ville + date + créneau horaire du marchand.
 *
 * NB : aucune annulation documentée — apiKey masquée dans les logs debug.
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Intigo_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'intigo';
	const LABEL    = 'INTIGO';
	const API_BASE = 'https://partner-app.intigo.tn/api/v1/externalapi';

	/**
	 * Liste officielle des régions (doc INTIGO : « Please get the list of
	 * cities and subdivisions to be used via this url ») — endpoint public.
	 */
	const REGIONS_URL = 'https://partner-app.intigo.tn/api/deliveryrequest/regions';

	/**
	 * Gouvernorats tunisiens acceptés (champ INTIGO « city » — validation stricte).
	 */
	const GOVERNORATS = [
		'Ariana', 'Ben Arous', 'Bizerte', 'Béja', 'Gabès',
		'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
		'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine',
		'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
		'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
	];

	/**
	 * Créneaux de ramassage (pickUp.fromTo).
	 */
	const CRENEAUX = [
		'08:00-12:00' => '08:00 – 12:00',
		'14:00-17:00' => '14:00 – 17:00',
		'18:00-21:00' => '18:00 – 21:00',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs INTIGO
	//  (INTIGO ClientName ← nom, address ← adresse, city ← gouvernorat,
	//   subDivision ← ville — INVERSÉ vs les autres transporteurs)
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],     // → INTIGO city
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],       // → INTIGO subDivision
		'tel'          => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'commentaire'  => [ 'source' => '',      'fallback' => '' ],
	];

	/** @var string API key INTIGO. */
	private $api_key;

	/** @var string Adresse de ramassage du marchand (pickUp.address). */
	private $pickup_address;

	/** @var string Ville/gouvernorat de ramassage du marchand (pickUp.city). */
	private $pickup_city;

	/** @var string Créneau de ramassage (pickUp.fromTo). */
	private $pickup_creneau;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->api_key        = (string) get_option( 'wcmcs_intigo_api_key', '' );
		$this->pickup_address = (string) get_option( 'wcmcs_intigo_pickup_address', '' );
		$this->pickup_city    = (string) get_option( 'wcmcs_intigo_pickup_city', '' );
		$this->pickup_creneau = (string) get_option( 'wcmcs_intigo_pickup_creneau', '08:00-12:00' );
		if ( ! isset( self::CRENEAUX[ $this->pickup_creneau ] ) ) {
			$this->pickup_creneau = '08:00-12:00';
		}
		$this->api_timeout    = (int) apply_filters( 'wcmcs_intigo_api_timeout', 30 );
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
		return 'wcmcs_intigo_map_';
	}

	public function is_active(): bool {
		return true;
	}

	public function is_configured(): bool {
		return ! empty( $this->api_key )
			&& ! empty( $this->pickup_address )
			&& ! empty( $this->pickup_city );
	}

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_intigo_api_key',
				'label'       => __( 'Clé API (apiKey)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Clé API depuis votre dashboard INTIGO → Profile (section API).', 'wc-multicarrier-sync' ),
				'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			],
			[
				'id'          => 'wcmcs_intigo_pickup_address',
				'label'       => __( 'Adresse de ramassage (pickUp)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Votre adresse d\'entrepôt/boutique d\'où INTIGO ramasse les colis (obligatoire).', 'wc-multicarrier-sync' ),
				'placeholder' => '54 Rue Mouawiya Ibn Abi Sofiane, Ariana',
			],
			[
				'id'          => 'wcmcs_intigo_pickup_city',
				'label'       => __( 'Ville de ramassage (gouvernorat)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Gouvernorat de votre adresse de ramassage (ex : Ariana, Tunis…).', 'wc-multicarrier-sync' ),
				'placeholder' => 'Ariana',
			],
			[
				'id'      => 'wcmcs_intigo_pickup_creneau',
				'label'   => __( 'Créneau de ramassage', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => self::CRENEAUX,
				'default' => '08:00-12:00',
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_intigo_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → INTIGO', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du client (ClientName)', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse de livraison (address)', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat (→ city)', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville/Délégation (→ subDivision)', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone (ClientPhone)', 'wc-multicarrier-sync' ),
			'commentaire'  => __( 'Informations additionnelles', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_intigo_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_intigo_map_' . $field_key . '_fallback',
				'fallback_default' => $defaults['fallback'],
				'description'      => __( 'Champ source et champ de secours si vide.', 'wc-multicarrier-sync' ),
			];
		}

		return $fields;
	}

	// ─────────────────────────────────────────────
	//  Résolution du gouvernorat (champ INTIGO « city »)
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

		// Constante stricte key→value de la doc INTIGO — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'intigo' );
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
				'INTIGO',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'intigo' ) )
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
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville/Délégation', 'wc-multicarrier-sync' ),
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
		$payload = apply_filters( 'wcmcs_intigo_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API INTIGO (adddelivery)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': INTIGO payload: ' . wp_json_encode( $this->redact_payload( $payload ) ) );

		$response = $this->api_request( 'adddelivery', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Réponse : { "nid": 5212 }
		$nid = $response['nid'] ?? null;
		if ( empty( $nid ) ) {
			$msg = wp_json_encode( $this->redact_payload( $response ) );
			return new WP_Error( 'intigo_no_nid', __( 'INTIGO : aucun identifiant (nid) dans la réponse.', 'wc-multicarrier-sync' ) . " ({$msg})" );
		}

		$tracking_id = (string) $nid;

		// Bordereau PDF (GET /bordereau/{nid}).
		$label_url = esc_url_raw( rtrim( self::API_BASE, '/' ) . '/bordereau/' . rawurlencode( $tracking_id ) );

		WCMCS_Logger::info( "INTIGO: colis créé nid={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => $label_url,
			'raw'         => $this->redact_payload( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Annulation (non documentée)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error(
			'intigo_cancel_unsupported',
			__( 'INTIGO : l\'annulation via l\'API n\'est pas disponible. Contactez INTIGO.', 'wc-multicarrier-sync' )
		);
	}

	// ─────────────────────────────────────────────
	//  Tracking
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		$response = $this->api_request( 'getdelivery', [ 'nid' => $tracking_id ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Statut : dropOff.status (libellé FR) + pickUp.status.
		$drop_status  = (string) ( $response['dropOff']['status'] ?? '' );
		$pick_status  = (string) ( $response['pickUp']['status'] ?? '' );

		if ( '' === $drop_status && '' === $pick_status ) {
			return new WP_Error( 'intigo_invalid_response', __( 'INTIGO : réponse de suivi inattendue.', 'wc-multicarrier-sync' ), $this->redact_payload( $response ) );
		}

		// Le statut pertinent est celui du dropOff (livraison) ; fallback pickUp.
		$state = '' !== $drop_status ? $drop_status : $pick_status;

		return [
			'tracking_id'  => $tracking_id,
			'state_code'   => $state,
			'state_label'  => $state, // libellés FR : En attente de livraison → Commande livrée…
			'pickup_state' => $pick_status,
			'ville'        => (string) ( $response['city'] ?? '' ),          // gouvernorat
			'delegation'   => (string) ( $response['subDivision'] ?? '' ),   // délégation
			'raw'          => $response,
		];
	}

	// ─────────────────────────────────────────────
	//  Régions officielles (doc : GET /api/deliveryrequest/regions)
	// ─────────────────────────────────────────────

	/**
	 * Liste officielle des régions INTIGO — 24 divisions (gouvernorats) avec
	 * leurs subDivisions valides. Endpoint PUBLIC (sans apiKey), cache
	 * transient 24 h.
	 *
	 * @return array|WP_Error  Liste [{division, subDivisions[]}].
	 */
	public function get_regions() {
		$cached = get_transient( 'wcmcs_intigo_regions' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Cache NÉGATIF court (revue de code 2.10.0) : en cas de panne de
		// l'endpoint, sans lui chaque create_shipment d'un batch re-tentait
		// un appel de 20 s → syncs en cascade bloquées.
		if ( 'yes' === get_transient( 'wcmcs_intigo_regions_failed' ) ) {
			return new WP_Error( 'intigo_regions_unreachable', __( 'INTIGO : régions indisponibles (échec récent, nouvelle tentative dans quelques minutes).', 'wc-multicarrier-sync' ) );
		}

		$timeout = (int) apply_filters( 'wcmcs_intigo_regions_timeout', 10 );
		$response = wp_remote_get( self::REGIONS_URL, [ 'timeout' => $timeout ] );
		if ( is_wp_error( $response ) ) {
			set_transient( 'wcmcs_intigo_regions_failed', 'yes', 5 * MINUTE_IN_SECONDS );
			return $response;
		}

		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $parsed ) || empty( $parsed ) ) {
			set_transient( 'wcmcs_intigo_regions_failed', 'yes', 5 * MINUTE_IN_SECONDS );
			return new WP_Error( 'intigo_regions_invalid', __( 'INTIGO : réponse des régions invalide.', 'wc-multicarrier-sync' ) );
		}

		set_transient( 'wcmcs_intigo_regions', $parsed, DAY_IN_SECONDS );
		return $parsed;
	}

	/**
	 * Résout la subDivision OFFICIELLE INTIGO de la ville/délégation de la
	 * commande (doc : la liste des couples city/subDivision doit être
	 * utilisée). Best-effort : match insensible casse/accents dans la
	 * division (gouvernorat) résolue → orthographe officielle renvoyée ;
	 * sans correspondance : valeur envoyée telle quelle + warning (l'API
	 * tranche) — JAMAIS un gouvernorat en subDivision.
	 *
	 * @param string $gouvernorat Gouvernorat résolu (division INTIGO).
	 * @param string $ville       Ville/délégation traduite (latin si possible).
	 * @param int    $order_id    ID commande (logs).
	 * @return string subDivision officielle, ou valeur telle quelle.
	 */
	private function resolve_sub_division( string $gouvernorat, string $ville, int $order_id ): string {
		$ville = trim( $ville );
		if ( '' === $ville ) {
			return $ville;
		}
		$norm_ville = $this->normalize( $ville );
		if ( '' === $norm_ville ) {
			return $ville;
		}
		$norm_gouv = '' !== trim( $gouvernorat ) ? $this->normalize( $gouvernorat ) : '';

		$regions = $this->get_regions();
		if ( is_wp_error( $regions ) ) {
			WCMCS_Logger::warning( "Order #{$order_id}: régions INTIGO indisponibles — " . $regions->get_error_message() . ' — subDivision envoyée telle quelle.' );
			return $ville;
		}

		foreach ( $regions as $region ) {
			if ( ! is_array( $region ) ) {
				continue;
			}
			// Division (gouvernorat) de la commande uniquement.
			if ( '' === $norm_gouv || $this->normalize( (string) ( $region['division'] ?? '' ) ) !== $norm_gouv ) {
				continue;
			}
			foreach ( (array) ( $region['subDivisions'] ?? [] ) as $sub ) {
				if ( $this->normalize( (string) $sub ) === $norm_ville ) {
					WCMCS_Logger::info( "Order #{$order_id}: subDivision INTIGO résolue → « {$sub} » ({$region['division']})" );
					return (string) $sub;
				}
			}
			// Division trouvée mais subDivision inconnue → pas de boucle globale
			// (une subDivision d'une autre division serait invalide).
			break;
		}

		WCMCS_Logger::warning( "Order #{$order_id}: subDivision INTIGO non résolue pour « {$ville} » — envoi de la valeur telle quelle." );
		return $ville;
	}

	// ─────────────────────────────────────────────
	//  Construction du payload (mapping INVERSÉ)
	// ─────────────────────────────────────────────

	/**
	 * Payload adddelivery.
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

		$client_name = $this->get_mapped_field( $order, 'nom' );
		$client_tel  = $this->get_mapped_field( $order, 'tel' );
		$adresse     = $this->get_mapped_field( $order, 'adresse' );
		$ville       = $this->get_mapped_field( $order, 'ville' );        // → subDivision
		$commentaire = $this->get_mapped_field( $order, 'commentaire' );

		// Nettoyage téléphone (espaces, tirets, parenthèses).
		$client_phone_clean = preg_replace( '/[\s\-\(\)\.]+/', '', $client_tel );

		// ⚠ INVERSÉ : INTIGO « city » = GOUVERNORAT résolu (traduction + valeur brute si non reconnu).
		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
			return $gouvernorat;
		}

		// ⚠ INVERSÉ : INTIGO « subDivision » = notre VILLE/DÉLÉGATION.
		// Traduction arabe SANS fallback gouvernorat (un gouvernorat en
		// subDivision est structurellement invalide — division ≠ subDivision)
		// puis résolution de l'orthographe OFFICIELLE via la liste /regions
		// (cache 24 h, doc INTIGO §3) ; sans correspondance : valeur telle
		// quelle + warning (l'API tranche).
		$ville_fr     = WCMCS_Localization::translate_ville( $ville, '' );
		$sub_division = $this->resolve_sub_division( $gouvernorat, $ville_fr, (int) $order->get_id() );

		// PickUp obligatoire : date = aujourd'hui, créneau + adresse + ville = réglages.
		$payload = [
			'apiKey'        => $this->api_key,
			'id'            => (string) $order->get_id(), // notre référence (cid)
			'pickUp'        => [
				'address'    => $this->pickup_address,
				'pickUpDate' => current_time( 'Y-m-d' ),
				'fromTo'     => $this->pickup_creneau,
				'city'       => $this->pickup_city,
			],
			'paymentStatus' => 2, // 2 = collecte à la livraison (COD) — nos syncs sont COD-only
			'amount'        => (float) $order->get_total(),
			'dropOff'       => [
				'address' => $adresse,
			],
			'ClientName'    => $client_name,
			'ClientPhone'   => $client_phone_clean,
			'city'          => $gouvernorat,     // ⚠ GOUVERNORAT
			'subDivision'   => $sub_division,   // ⚠ DÉLÉGATION (notre ville)
			'isExchange'    => false,
		];

		if ( '' !== trim( $commentaire ) ) {
			$payload['additionalInfo'] = $commentaire;
		}
		if ( ! empty( $item_names ) ) {
			$payload['descProduit'] = implode( '; ', $item_names );
		}

		return $payload;
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (JSON, apiKey body)
	// ─────────────────────────────────────────────

	/**
	 * Appel API INTIGO — POST JSON, apiKey injectée dans le body.
	 *
	 * @param string $endpoint adddelivery|getdelivery|deliveries.
	 * @param array  $body     Champs (apiKey injectée automatiquement).
	 * @return array|WP_Error
	 */
	private function api_request( string $endpoint, array $body ) {
		$body['apiKey'] = $this->api_key;

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

		WCMCS_Logger::info( "INTIGO API request: {$endpoint}" );
		WCMCS_Logger::debug( 'INTIGO request payload: ' . wp_json_encode( $this->redact_payload( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "INTIGO HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'intigo_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "INTIGO response [{$status_code}]" );
		WCMCS_Logger::debug( 'INTIGO response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "INTIGO: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'intigo_invalid_json',
				sprintf( __( 'INTIGO : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}

		// 400 = erreur de validation/auth (la réponse peut contenir un message).
		if ( 400 === $status_code ) {
			$msg = '';
			if ( isset( $parsed['message'] ) ) {
				$msg = (string) $parsed['message'];
			} elseif ( isset( $parsed['error'] ) ) {
				$msg = (string) $parsed['error'];
			} elseif ( isset( $parsed[0] ) && is_string( $parsed[0] ) ) {
				$msg = (string) $parsed[0];
			}
			return new WP_Error(
				'intigo_api_error',
				( $msg ? sprintf( __( 'INTIGO : %s', 'wc-multicarrier-sync' ), $msg ) : __( 'INTIGO : requête refusée (HTTP 400).', 'wc-multicarrier-sync' ) ) . " [HTTP {$status_code}]",
				$this->redact_payload( $parsed )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'intigo_api_error_' . $status_code,
				sprintf( __( 'INTIGO : erreur HTTP %d.', 'wc-multicarrier-sync' ), $status_code ),
				$this->redact_payload( $parsed )
			);
		}

		return $parsed;
	}

	// ─────────────────────────────────────────────
	//  Sécurité des logs
	// ─────────────────────────────────────────────

	/**
	 * Masque l'apiKey + redacte la PII avant tout logging.
	 */
	private function redact_payload( array $payload ): array {
		if ( isset( $payload['apiKey'] ) ) {
			$payload['apiKey'] = '[REDACTED]';
		}
		return WCMCS_Logger::redact_pii( $payload );
	}
}
