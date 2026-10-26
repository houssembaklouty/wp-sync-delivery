<?php
/**
 * Intégration Jetpack (jetpack.tn).
 *
 * API v2 — deux endpoints, POST form-urlencoded + header X-Api-Key :
 *  - Ajouter une commande : https://jetpack.tn/apis-v2/add_order.php (token d'ajout)
 *  - Suivi de commande   : https://jetpack.tn/apis-v2/track_order.php (token de tracking)
 *
 * NB : l'API ne propose ni annulation ni étiquette PDF.
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Jetpack_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG         = 'jetpack';
	const LABEL        = 'Jetpack';
	const API_BASE_URL = 'https://jetpack.tn/apis-v2';

	/**
	 * Gouvernorats tunisiens acceptés par l'API (orthographe Jetpack).
	 */
	const GOVERNORATS = [
		'Ariana', 'Ben Arous', 'Bizerte', 'Béja', 'Gabès',
		'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili',
		'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine',
		'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse',
		'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs Jetpack
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'gouvernerat'  => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'tel'          => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'tel2'         => [ 'source' => '',                   'fallback' => '' ],
		'msg'          => [ 'source' => '',                     'fallback' => '' ],
	];

	/** @var string Token d'ajout de commandes. */
	private $add_token;

	/** @var string Token de tracking. */
	private $track_token;

	/** @var int Commande avec échange (0/1). */
	private $echange;

	/** @var int Colis fragile (0/1). */
	private $fragile;

	/** @var int Autoriser l'ouverture du colis (0/1). */
	private $ouvrir;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->add_token   = (string) get_option( 'wcmcs_jetpack_add_token', '' );
		$this->track_token = (string) get_option( 'wcmcs_jetpack_track_token', '' );
		$this->echange     = get_option( 'wcmcs_jetpack_echange', 'no' ) === 'yes' ? 1 : 0;
		$this->fragile     = get_option( 'wcmcs_jetpack_fragile', 'no' ) === 'yes' ? 1 : 0;
		$this->ouvrir      = get_option( 'wcmcs_jetpack_ouvrir', 'no' ) === 'yes' ? 1 : 0;
		$this->api_timeout = (int) apply_filters( 'wcmcs_jetpack_api_timeout', 30 );
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
		return 'wcmcs_jetpack_map_';
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
				'id'          => 'wcmcs_jetpack_add_token',
				'label'       => __( 'Token d\'ajout (API)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token « d\'ajout » de votre compte — Espace expéditeur → Paramètres → API (jetpack.tn/expediteur/api.php).', 'wc-multicarrier-sync' ),
				'placeholder' => 'dodoshoes-…',
			],
			[
				'id'          => 'wcmcs_jetpack_track_token',
				'label'       => __( 'Token de tracking (API)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Token « de tracking » — attention : jetpack.tn utilise deux tokens différents.', 'wc-multicarrier-sync' ),
				'placeholder' => 'compte-etat-…',
			],
			[
				'id'      => 'wcmcs_jetpack_echange',
				'label'   => __( 'Commande avec échange', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_jetpack_fragile',
				'label'   => __( 'Colis fragile', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
			[
				'id'      => 'wcmcs_jetpack_ouvrir',
				'label'   => __( 'Autoriser l\'ouverture du colis', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => [ 'no' => __( 'Non', 'wc-multicarrier-sync' ), 'yes' => __( 'Oui', 'wc-multicarrier-sync' ) ],
				'default' => 'no',
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_jetpack_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Jetpack', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du client', 'wc-multicarrier-sync' ),
			'gouvernerat'  => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone principal', 'wc-multicarrier-sync' ),
			'tel2'         => __( 'Téléphone secondaire', 'wc-multicarrier-sync' ),
			'msg'          => __( 'Message pour le livreur', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_jetpack_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_jetpack_map_' . $field_key . '_fallback',
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

		// Constante stricte key→value de la doc Jetpack — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'jetpack' );
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
				'Jetpack',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'jetpack' ) )
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
		$payload = apply_filters( 'wcmcs_jetpack_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Jetpack (add_order)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Jetpack payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( 'add_order.php', $payload, $this->add_token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['status'] ) ) {
			$msg = (string) ( $response['status_message'] ?? __( 'Erreur inconnue Jetpack.', 'wc-multicarrier-sync' ) );
			WCMCS_Logger::error( "Jetpack API error: {$msg}" );
			return new WP_Error( 'jetpack_api_error', sprintf( __( 'Jetpack : %s', 'wc-multicarrier-sync' ), $msg ), $response );
		}

		$tracking_id = trim( (string) ( $response['status_message'] ?? '' ) );
		if ( empty( $tracking_id ) ) {
			return new WP_Error( 'no_tracking_id', __( 'Jetpack : aucun code à barre dans la réponse.', 'wc-multicarrier-sync' ), $response );
		}

		WCMCS_Logger::info( "Jetpack: colis créé code={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => '',
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Annulation (non supportée par l'API)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error(
			'jetpack_cancel_unsupported',
			__( 'Jetpack : l\'annulation via l\'API n\'est pas disponible. Contactez votre agence Jetpack.', 'wc-multicarrier-sync' )
		);
	}

	// ─────────────────────────────────────────────
	//  Tracking
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		// Doc Jetpack : DEUX tokens distincts (ajout / suivi) — sans token de
		// suivi, chaque interrogation échouerait avec l'erreur API « Token
		// invalide » : erreur locale explicite dès l'amont.
		if ( '' === $this->track_token ) {
			return new WP_Error(
				'jetpack_track_token_missing',
				__( 'Jetpack : token de suivi manquant — renseignez-le dans les réglages Jetpack (espace expéditeur → Paramètres → API, champ token de tracking, distinct du token d\'ajout).', 'wc-multicarrier-sync' )
			);
		}

		$response = $this->api_request( 'track_order.php', [ 'codes' => $tracking_id ], $this->track_token );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['status'] ) ) {
			$msg = (string) ( $response['status_message'] ?? __( 'Erreur inconnue Jetpack.', 'wc-multicarrier-sync' ) );
			return new WP_Error( 'jetpack_api_error', sprintf( __( 'Jetpack : %s', 'wc-multicarrier-sync' ), $msg ), $response );
		}

		$cmd = $response['commandes'][ $tracking_id ] ?? null;
		if ( ! is_array( $cmd ) ) {
			return new WP_Error( 'jetpack_not_found', __( 'Jetpack : commande introuvable.', 'wc-multicarrier-sync' ), $response );
		}
		// NB : une commande TROUVÉE a status = "payee"|"Non payee" (string) ;
		// une commande absente a status = 0 (numérique). Comparaison stricte.
		if ( isset( $cmd['status'] ) && ( $cmd['status'] === 0 || $cmd['status'] === '0' ) ) {
			return new WP_Error( 'jetpack_not_found', sprintf( __( 'Jetpack : %s', 'wc-multicarrier-sync' ), (string) ( $cmd['status_message'] ?? __( 'Commande introuvable.', 'wc-multicarrier-sync' ) ) ), $response );
		}

		return [
			'tracking_id'    => $tracking_id,
			'state_code'     => (string) ( $cmd['etat'] ?? '' ),
			'state_label'    => (string) ( $cmd['etat'] ?? '' ),
			'payment_status' => (string) ( $cmd['status'] ?? '' ),            // payee | Non payee
			'motif'          => (string) ( $cmd['motif'] ?? '' ),
			'pre_etat'       => (string) ( $cmd['pre-etat'] ?? '' ),           // info live livreur avant clôture runsheet
			'pre_motif'      => (string) ( $cmd['pre-motif'] ?? '' ),
			'raw'            => WCMCS_Logger::redact_pii( $cmd ),
		];
	}

	// ─────────────────────────────────────────────
	//  Construction du payload
	// ─────────────────────────────────────────────

	/**
	 * Payload add_order — format prix millimes (« 120.000 »).
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
		$msg     = $this->get_mapped_field( $order, 'msg' );

        $gouvernorat = $this->resolve_gouvernorat( $order );
        if ( is_wp_error( $gouvernorat ) ) {
            WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
            return $gouvernorat;
        }

        // Ville arabe → français (fallback : gouvernorat résolu).
        $ville = WCMCS_Localization::translate_ville( $ville, $gouvernorat );

        return [
			'nom'          => $nom,
			'tel'          => $tel,
			'tel2'         => $tel2,
			'adresse'      => $adresse,
			'ville'        => $ville,
			'gouvernerat'  => $gouvernorat,
			'prix'         => number_format( (float) $order->get_total(), 3, '.', '' ),
			'designation'  => implode( '; ', $item_names ),
			'article'      => $item_names[0] ?? '',
			'nb_article'   => max( 1, $total_qty ),
			'echange'      => $this->echange,
			'nb_echange'   => 0,
			'fragile'      => $this->fragile,
			'ouvrir'       => $this->ouvrir,
			'msg'          => $msg,
		];
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (form-urlencoded + X-Api-Key)
	// ─────────────────────────────────────────────

	/**
	 * Appel API Jetpack.
	 *
	 * @param string $endpoint add_order.php|track_order.php.
	 * @param array  $body     Champs POST.
	 * @param string $token    Token API adapté au endpoint.
	 * @return array|WP_Error
	 */
	private function api_request( string $endpoint, array $body, string $token ) {
		$url = rtrim( self::API_BASE_URL, '/' ) . '/' . $endpoint;

		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [
				'X-Api-Key'     => $token,
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			],
			'body'    => http_build_query( $body ),
		];

		WCMCS_Logger::info( "Jetpack API request: {$endpoint}" );
		WCMCS_Logger::debug( 'Jetpack request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Jetpack HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'jetpack_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Jetpack response [{$status_code}]" );
		WCMCS_Logger::debug( 'Jetpack response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "Jetpack: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'jetpack_invalid_json',
				sprintf( __( 'Jetpack : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}

		return $parsed;
	}
}
