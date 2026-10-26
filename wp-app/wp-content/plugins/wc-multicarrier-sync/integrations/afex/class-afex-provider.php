<?php
/**
 * Intégration AFEX (afex.tn).
 *
 * API REST v1 — auth « X-API-Key » header (dashboard → Profil).
 *  - Création   : POST /v1/shipments (JSON) → { barcode, agence_source, agence_destination, zone_source, zone_destination }
 *  - Suivi      : GET  /v1/shipments/status/{shipmentId} → state (24 états) + delivered_state + attempt_number + motif
 *  - Bordereau  : GET  /v1/print_labels?barcodes=X (PDF)
 *  - Annulation : POST /v1/tickets (category = shipment_cancel) — ticket-based cancel
 *
 * Docs OpenAPI : https://apis-docs.afex.tn/openapi.yaml
 * Production : https://apis.afex.tn · Sandbox : https://apis-sandbox.afex.tn
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Afex_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'afex';
	const LABEL    = 'Afex';
	const API_BASE = 'https://apis.afex.tn';

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
	 * Types d'envoi (champ requis type_envoi).
	 */
	const TYPES_ENVOI = [
		'Livraison à domicile' => 'Livraison à domicile',
		'Échange'              => 'Échange',
	];

	/**
	 * Modes de règlement (champ requis mode_reglement).
	 */
	const MODES_REGLEMENT = [
		'Seulement en espèces' => 'Seulement en espèces',
		'Chèque ou espèces'    => 'Chèque ou espèces',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs AFEX
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'delegation'   => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'telephone1'   => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'telephone2'   => [ 'source' => '',                   'fallback' => '' ],
	];

	/** @var string Clé API AFEX. */
	private $api_key;

	/** @var string Type d'envoi par défaut. */
	private $type_envoi;

	/** @var string Mode de règlement. */
	private $mode_reglement;

	/** @var string Observation par défaut (mapping commentaire). */
	private $observation_source;

	/** @var int Timeout API (s). */
	private $api_timeout;

	public function __construct() {
		$this->api_key       = (string) get_option( 'wcmcs_afex_api_key', '' );
		$this->type_envoi    = (string) get_option( 'wcmcs_afex_type_envoi', 'Livraison à domicile' );
		if ( ! isset( self::TYPES_ENVOI[ $this->type_envoi ] ) ) {
			$this->type_envoi = 'Livraison à domicile';
		}
		$this->mode_reglement = (string) get_option( 'wcmcs_afex_mode_reglement', 'Seulement en espèces' );
		if ( ! isset( self::MODES_REGLEMENT[ $this->mode_reglement ] ) ) {
			$this->mode_reglement = 'Seulement en espèces';
		}
		$this->api_timeout  = (int) apply_filters( 'wcmcs_afex_api_timeout', 30 );
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
		return 'wcmcs_afex_map_';
	}

	public function is_active(): bool {
		return true;
	}

	public function is_configured(): bool {
		return ! empty( $this->api_key );
	}

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_afex_api_key',
				'label'       => __( 'Clé API (X-API-Key)', 'wc-multicarrier-sync' ),
				'type'        => 'password',
				'description' => __( 'Clé API depuis votre espace expéditeur AFEX (afex.tn).', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_afex_type_envoi',
				'label'   => __( 'Type d\'envoi', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => self::TYPES_ENVOI,
				'default' => 'Livraison à domicile',
			],
			[
				'id'      => 'wcmcs_afex_mode_reglement',
				'label'   => __( 'Mode de règlement', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => self::MODES_REGLEMENT,
				'default' => 'Seulement en espèces',
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_afex_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → AFEX', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du destinataire', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'delegation'   => __( 'Délégation', 'wc-multicarrier-sync' ),
			'telephone1'   => __( 'Téléphone 1', 'wc-multicarrier-sync' ),
			'telephone2'   => __( 'Téléphone 2', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			$fields[] = [
				'id'               => 'wcmcs_afex_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_afex_map_' . $field_key . '_fallback',
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
		$ville_field = $this->get_mapped_field( $order, 'delegation' );

		// Constante stricte key→value de la doc AFEX — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'afex' );
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
				'AFEX',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'afex' ) )
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
			'nom'          => __( 'Nom du destinataire', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse', 'wc-multicarrier-sync' ),
			'telephone1'   => __( 'Téléphone 1', 'wc-multicarrier-sync' ),
		];

		foreach ( $required_fields as $field_key => $label ) {
			$value = $this->get_mapped_field( $order, $field_key );
			if ( empty( $value ) ) {
				$errors->add( 'missing_' . $field_key, sprintf( __( '%s manquant.', 'wc-multicarrier-sync' ), $label ) );
			}
		}

		// Gouvernorat vide déjà couvert par les champs requis ci-dessus ;
		// valeur non reconnue = envoi de la valeur brute (voir resolve_gouvernorat).

		// Téléphones : l'API AFEX attend exactement 8 chiffres (entier, erreur
		// 422 « Format du Téléphone 1 incorrecte » sinon) — indicatif 216/00216
		// strippé, numéro malformé bloqué ICI avec un message clair.
		foreach ( [ 'telephone1', 'telephone2' ] as $tel_key ) {
			$tel_raw = $this->get_mapped_field( $order, $tel_key );
			if ( '' === trim( $tel_raw ) ) {
				continue; // telephone2 facultatif
			}
			$digits = $this->normalize_phone( $tel_raw );
			if ( '' === $digits || ! ctype_digit( $digits ) || 8 !== strlen( $digits ) ) {
				$errors->add(
					'invalid_' . $tel_key,
					sprintf( __( 'Téléphone "%s" : l\'API AFEX attend exactement 8 chiffres.', 'wc-multicarrier-sync' ), $tel_raw )
				);
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
	//  Création d'expédition
	// ─────────────────────────────────────────────

	public function create_shipment( WC_Order $order ) {
		$payload = $this->build_shipment_payload( $order );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload = apply_filters( 'wcmcs_afex_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API AFEX (POST /v1/shipments)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': AFEX payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $payload ) ) );

		$response = $this->api_request( 'POST', '/v1/shipments', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Réponse : { barcode, agence_source, agence_destination, zone_source, zone_destination }
		$barcode = $response['barcode'] ?? null;
		if ( empty( $barcode ) ) {
			$msg = wp_json_encode( WCMCS_Logger::redact_pii( $response ) );
			return new WP_Error( 'afex_no_barcode', __( 'AFEX : aucun code barre dans la réponse.', 'wc-multicarrier-sync' ) . " ({$msg})" );
		}

		$tracking_id = (string) $barcode;

		// Bordereau : PAS d'URL directe stockée — l'auth AFEX passe par le
		// header X-API-Key, un lien navigateur `print_labels?barcodes=` est
		// un 401 systématique (lien mort — revue de code 2.10.0). Le
		// téléchargement passe par get_label_pdf() (fetch serveur + local).
		WCMCS_Logger::info( "AFEX: colis créé barcode={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => '',
			'raw'         => WCMCS_Logger::redact_pii( $response ),
		];
	}

	// ─────────────────────────────────────────────
	//  Étiquette PDF (fetch serveur + sauvegarde locale)
	// ─────────────────────────────────────────────

	/**
	 * Télécharge le bordereau PDF côté serveur (GET /v1/print_labels?barcodes=
	 * avec le header X-API-Key — l'auth par header rend impossible un lien
	 * navigateur direct) puis le sauvegarde localement (pattern Colissimo).
	 *
	 * @return string|WP_Error URL locale du PDF (nonce frais à l'affichage).
	 */
	public function get_label_pdf( string $tracking_id, int $order_id ) {
		$url = rtrim( self::API_BASE, '/' ) . '/v1/print_labels?barcodes=' . rawurlencode( $tracking_id );

		WCMCS_Logger::info( "AFEX: téléchargement du bordereau {$tracking_id} (serveur → local)." );

		$response = wp_remote_get( $url, [
			'timeout' => $this->api_timeout,
			'headers' => [
				'X-API-Key'    => $this->api_key,
				'Accept'       => 'application/pdf',
			],
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'afex_label_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || 0 !== strpos( trim( $body ), '%PDF' ) ) {
			return new WP_Error(
				'afex_label_invalid',
				sprintf( __( 'AFEX : bordereau PDF non disponible (HTTP %d) — vérifiez votre clé API.', 'wc-multicarrier-sync' ), $code )
			);
		}

		return $this->save_label_pdf( $tracking_id, base64_encode( $body ), $order_id );
	}

	/**
	 * Sauvegarde le bordereau PDF localement (pattern Colissimo/Aramex) —
	 * URL publique {uploads}/wcmcs-labels/ : get_label_display_url()
	 * régénère un nonce frais à chaque affichage.
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
				return new WP_Error( 'afex_label_decode', __( 'AFEX : échec décodage bordereau PDF.', 'wc-multicarrier-sync' ) );
			}

			$filename = sanitize_file_name( 'afex_' . $tracking_id . '_' . time() . '.pdf' );
			$written  = file_put_contents( $label_dir . $filename, $decoded );
			if ( false === $written || $written !== strlen( $decoded ) ) {
				return new WP_Error( 'afex_label_write', __( 'AFEX : échec sauvegarde bordereau PDF.', 'wc-multicarrier-sync' ) );
			}

			return $upload_dir['baseurl'] . '/wcmcs-labels/' . $order_id . '/' . $filename;

		} catch ( \Throwable $e ) {
			return new WP_Error( 'afex_label_error', $e->getMessage() );
		}
	}

	// ─────────────────────────────────────────────
	//  Annulation (via ticket category=shipment_cancel)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		WCMCS_Logger::info( "AFEX: annulation du colis {$tracking_id} (ticket shipment_cancel)" );

		$response = $this->api_request( 'POST', '/v1/tickets', [
			'barcode'  => $tracking_id,
			'category' => 'shipment_cancel',
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Doc OpenAPI : /v1/tickets répond TOUJOURS HTTP 200 — l'issue réelle
		// est dans l'enveloppe jsonrpc result.code (200 = OK · 401 = clé
		// invalide · 422 = colis non annulable…). Sans cette vérification,
		// une annulation REJETÉE était journalisée « succès » et la commande
		// purgée → re-sync → DOUBLON d'expédition.
		$result_code = (int) ( $response['result']['code'] ?? 0 );
		if ( 200 !== $result_code ) {
			$result_msg = trim( (string) ( $response['result']['message'] ?? '' ) );
			$message = '' !== $result_msg
				? sprintf( __( 'AFEX : annulation refusée — %s', 'wc-multicarrier-sync' ), $result_msg )
				: sprintf( __( 'AFEX : annulation refusée (code %d) — vérifiez que le colis est encore annulable.', 'wc-multicarrier-sync' ), $result_code ?: 500 );
			WCMCS_Logger::error( "AFEX: ticket d'annulation {$tracking_id} refusé (result.code={$result_code}) — {$result_msg}" );
			return new WP_Error( 'afex_cancel_refused', $message, WCMCS_Logger::redact_pii( $response ) );
		}

		WCMCS_Logger::info( "AFEX: colis {$tracking_id} — ticket d'annulation créé avec succès." );
		return true;
	}

	// ─────────────────────────────────────────────
	//  Tracking
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		$response = $this->api_request( 'GET', '/v1/shipments/status/' . rawurlencode( $tracking_id ), [] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$state = (string) ( $response['state'] ?? '' );
		if ( '' === $state ) {
			return new WP_Error( 'afex_invalid_response', __( 'AFEX : réponse de suivi inattendue.', 'wc-multicarrier-sync' ), $response );
		}

		// 24 états → libellés FR.
		$state_labels = [
			'pre_manifest'       => 'Pré-manifest',
			'awaiting_removal'   => 'En attente d\'enlèvement',
			'being_removed'      => 'En cours d\'enlèvement',
			'awaiting_delivery'  => 'En attente de livraison',
			'awaiting_recovered' => 'En attente de récupération',
			'awaiting_return'    => 'En attente de retour',
			'awaiting_transfert' => 'En attente de transfert',
			'awaiting_rto'       => 'En attente RTO',
			'in_delivery'        => 'En cours de livraison',
			'being_recovered'    => 'En cours de récupération',
			'being_returned'     => 'En cours de retour',
			'in_transfert'       => 'En cours de transfert',
			'in_rto'             => 'En cours RTO',
			'delivered'          => 'Livré',
			'recovered'          => 'Récupéré',
			'returned'           => 'Retourné',
			'transfered'         => 'Transféré',
			'pending'            => 'En attente',
			'canceled'           => 'Annulé',
			'not_recovered'      => 'Non récupéré',
			'anomaly'            => 'Anomalie',
			'exception_refunded' => 'Exception remboursé',
			'exception_not_refunded' => 'Exception non remboursé',
		];

		return [
			'tracking_id'    => $tracking_id,
			'state_code'     => $state,
			'state_label'    => $state_labels[ $state ] ?? $state,
			'delivered_state' => (string) ( $response['delivered_state'] ?? '' ),
			'attempt_number' => (int) ( $response['attempt_number'] ?? 0 ),
			'motif'          => (string) ( $response['motif'] ?? '' ),
			'raw'           => $response,
		];
	}

	// ─────────────────────────────────────────────
	//  Construction du payload
	// ─────────────────────────────────────────────

	/**
	 * Payload POST /v1/shipments.
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

		$nom         = $this->get_mapped_field( $order, 'nom' );
		$adresse     = $this->get_mapped_field( $order, 'adresse' );
		$tel1_raw    = $this->get_mapped_field( $order, 'telephone1' );
		$tel2_raw    = $this->get_mapped_field( $order, 'telephone2' );
		$delegation  = $this->get_mapped_field( $order, 'delegation' );

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
			return $gouvernorat;
		}

		// Délégation arabe → français (fallback : gouvernorat résolu).
		$delegation = WCMCS_Localization::translate_ville( $delegation, $gouvernorat );

		// Nettoyage téléphones : entier 8 chiffres requis par l'API (indicatif
		// 216/00216 strippé — format garanti en amont par validate_order).
		$tel1 = $this->normalize_phone( $tel1_raw );
		$tel2 = '' !== trim( $tel2_raw ) ? $this->normalize_phone( $tel2_raw ) : null;

		$payload = [
			'nom'            => $nom,
			'telephone1'     => (int) $tel1,
			'gouvernorat'    => $gouvernorat,
			'delegation'     => $delegation,
			'adresse'        => $adresse,
			'marchandise'    => implode( '; ', $item_names ),
			'reference'      => (string) $order->get_id(),
			'paquets'        => max( 1, $total_qty ),
			'type_envoi'     => $this->type_envoi,
			'cod'            => (float) $order->get_total(),
			'mode_reglement' => $this->mode_reglement,
		];

		if ( ! empty( $tel2 ) ) {
			$payload['telephone2'] = (int) $tel2;
		}

		return $payload;
	}

	// ─────────────────────────────────────────────
	//  Utilitaires
	// ─────────────────────────────────────────────

	/**
	 * Normalise un téléphone tunisien : chiffres seuls, préfixe 216 (11 ch.) /
	 * 00216 (13 ch.) strippé. Aucune troncature arbitraire — la validation
	 * exige ensuite 8 chiffres exacts (format API AFEX).
	 *
	 * @param string $phone Téléphone brut.
	 * @return string Chiffres (peut être '' ou ≠ 8 — la validation tranche).
	 */
	private function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		$digits = (string) $digits;

		if ( strlen( $digits ) === 11 && 0 === strpos( $digits, '216' ) ) {
			$digits = substr( $digits, 3 );
		} elseif ( strlen( $digits ) === 13 && 0 === strpos( $digits, '00216' ) ) {
			$digits = substr( $digits, 5 );
		}

		return $digits;
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (JSON, X-API-Key header)
	// ─────────────────────────────────────────────

	/**
	 * Appel API AFEX — JSON, auth via header X-API-Key.
	 *
	 * @param string $method   GET|POST|PUT.
	 * @param string $endpoint Chemin relatif (ex: /v1/shipments).
	 * @param array  $body     Corps JSON (POST/PUT).
	 * @return array|WP_Error
	 */
	private function api_request( string $method, string $endpoint, array $body = [] ) {
		$url  = rtrim( self::API_BASE, '/' ) . $endpoint;
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => $this->api_timeout,
			'headers' => [
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		];

		if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		WCMCS_Logger::info( "AFEX API request: {$method} {$endpoint}" );
		WCMCS_Logger::debug( 'AFEX request payload: ' . wp_json_encode( WCMCS_Logger::redact_pii( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "AFEX HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'afex_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "AFEX response [{$status_code}]" );
		WCMCS_Logger::debug( 'AFEX response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( 401 === $status_code ) {
			return new WP_Error( 'afex_auth', __( 'AFEX : clé API invalide — vérifiez votre clé dans les réglages Afex.', 'wc-multicarrier-sync' ) );
		}
		if ( 404 === $status_code ) {
			return new WP_Error( 'afex_not_found', __( 'AFEX : colis introuvable.', 'wc-multicarrier-sync' ) );
		}
		if ( 422 === $status_code ) {
			$msg = (string) ( $parsed['message'] ?? __( 'Données invalides.', 'wc-multicarrier-sync' ) );
			return new WP_Error( 'afex_validation', sprintf( __( 'AFEX : %s', 'wc-multicarrier-sync' ), $msg ), $parsed );
		}
		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "AFEX: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'afex_invalid_json',
				sprintf( __( 'AFEX : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}
		if ( $status_code >= 400 ) {
			$msg = (string) ( $parsed['message'] ?? $parsed['error'] ?? '' );
			return new WP_Error( 'afex_api_error', ( $msg ? sprintf( __( 'AFEX : %s', 'wc-multicarrier-sync' ), $msg ) : sprintf( __( 'AFEX : erreur HTTP %d.', 'wc-multicarrier-sync' ), $status_code ) ) . " [{$status_code}]", $parsed );
		}

		return $parsed;
	}
}
