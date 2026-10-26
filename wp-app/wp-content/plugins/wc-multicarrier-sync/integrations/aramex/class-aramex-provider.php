<?php
/**
 * Intégration Aramex Tunisie (aramex.com/tn).
 *
 * API REST/JSON v2 — auth par compte Aramex business (email + password + account# + PIN + entity).
 *  - Création   : POST {base}/CreateShipments → AWB + label PDF (base64 ou URL)
 *  - Suivi      : POST {base}/TrackShipments (WaybillNumber) → UpdateCode + UpdateDescription
 *
 * ⚠ Modèle FREIGHT ≠ les autres transporteurs locaux :
 *  - ProductGroup (DOM/EXP) + ProductType (PDX/PPX) + PaymentType ("P")
 *  - Poids obligatoire (ActualWeight en KG) + NumberOfPieces
 *  - COD via CashOnDeliveryAmount (Aramex encaisse puis reverse au marchand)
 *  - Shipper (adresse marchand) obligatoire en plus du Consignee
 *
 * ⚠ Codes produit Tunisie à confirmer avec l'account manager Aramex (entity TUN ?).
 * Docs : aramex.com/tn/en/developers-solution-center/aramex-apis (PDF publics)
 *
 * @package WC_MultiCarrier_Sync
 * @subpackage Integrations
 */

defined( 'ABSPATH' ) || exit;

require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';
require_once WCMCS_PLUGIN_DIR . 'includes/trait-field-mapping.php';

class WCMCS_Aramex_Provider implements WCMCS_ShippingProviderInterface {
	use WCMCS_Field_Mapping;

	const SLUG     = 'aramex';
	const LABEL    = 'Aramex';
	const API_BASE = 'https://ws.aramex.net/shippingapi.v2/shipping/service_1_0.svc/json';

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

	const PRODUCT_GROUPS = [
		'DOM' => 'DOM — Domestique',
		'EXP' => 'EXP — Express international',
	];

	const PRODUCT_TYPES = [
		'PDX' => 'PDX — Parcel Domestic Express',
		'PPX' => 'PPX — Parcel Priority Express',
		'OND' => 'OND — Overnight Domestic',
	];

	// ─────────────────────────────────────────────
	//  Mapping par défaut des champs Aramex (Consignee)
	// ─────────────────────────────────────────────

	const FIELD_DEFAULTS = [
		'nom'          => [ 'source' => 'shipping_full_name', 'fallback' => 'billing_full_name' ],
		'adresse'      => [ 'source' => 'full_address',       'fallback' => 'billing_full_address' ],
		'gouvernorat' => [ 'source' => 'shipping_state',     'fallback' => 'billing_state' ],
		'ville'        => [ 'source' => 'shipping_city',      'fallback' => 'billing_city' ],
		'tel'          => [ 'source' => 'shipping_phone',     'fallback' => 'billing_phone' ],
		'commentaire'  => [ 'source' => '',      'fallback' => '' ],
	];

	/** @var string Email compte Aramex. */
	private $username;

	/** @var string Password compte Aramex. */
	private $password;

	/** @var string Numéro de compte Aramex. */
	private $account_number;

	/** @var string PIN du compte Aramex. */
	private $account_pin;

	/** @var string Code entity Aramex (ex : TUN). */
	private $entity;

	/** @var string Product group (DOM/EXP). */
	private $product_group;

	/** @var bool ProductGroup aligné automatiquement sur le ProductType (Annexe A). */
	private $product_group_was_aligned = false;

	/** @var string Product type (PDX/PPX/OND). */
	private $product_type;

	/** @var float Poids par défaut (KG). */
	private $default_weight;

	/** @var string Adresse de l'expéditeur (marchand). */
	private $shipper_address;

	/** @var string Ville de l'expéditeur (marchand). */
	private $shipper_city;

	/** @var string Nom du contact expéditeur. */
	private $shipper_name;

	/** @var string Téléphone de l'expéditeur (marchand). */
	private $shipper_phone;

	/** @var int Timeout API (s) — Aramex mondial, peut être lent depuis la Tunisie. */
	private $api_timeout;

	public function __construct() {
		$this->username       = (string) get_option( 'wcmcs_aramex_username', '' );
		$this->password       = (string) get_option( 'wcmcs_aramex_password', '' );
		$this->account_number = (string) get_option( 'wcmcs_aramex_account_number', '' );
		$this->account_pin    = (string) get_option( 'wcmcs_aramex_account_pin', '' );
		$this->entity         = (string) get_option( 'wcmcs_aramex_entity', 'TUN' );
		$this->product_group  = (string) get_option( 'wcmcs_aramex_product_group', 'EXP' );
		if ( ! isset( self::PRODUCT_GROUPS[ $this->product_group ] ) ) {
			$this->product_group = 'EXP';
		}
		$this->product_type  = (string) get_option( 'wcmcs_aramex_product_type', 'PDX' );
		if ( ! isset( self::PRODUCT_TYPES[ $this->product_type ] ) ) {
			$this->product_type = 'PDX';
		}

		// Annexe A du manuel Aramex : PDX/PPX = groupe EXP — seul OND = DOM.
		// Une combinaison incohérente (ex. DOM+PDX, défaut historique) est
		// refusée par l'API : le groupe est aligné automatiquement sur le type.
		// NB : le warning d'incohérence est loggé dans build_shipment_payload
		// (une fois par sync) — le constructeur tourne à chaque requête HTTP
		// et spammait les logs (revue de code 2.10.0).
		$expected_group = ( 'OND' === $this->product_type ) ? 'DOM' : 'EXP';
		if ( $this->product_group !== $expected_group ) {
			$this->product_group = $expected_group;
			$this->product_group_was_aligned = true;
		}
		$this->default_weight = (float) get_option( 'wcmcs_aramex_default_weight', 1.0 );
		$this->shipper_address = (string) get_option( 'wcmcs_aramex_shipper_address', '' );
		$this->shipper_city    = (string) get_option( 'wcmcs_aramex_shipper_city', 'Tunis' );
		$this->shipper_name    = (string) get_option( 'wcmcs_aramex_shipper_name', '' );
		$this->shipper_phone   = (string) get_option( 'wcmcs_aramex_shipper_phone', '' );
		$this->api_timeout     = (int) apply_filters( 'wcmcs_aramex_api_timeout', 45 );
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
		return 'wcmcs_aramex_map_';
	}

	public function is_active(): bool {
		return true;
	}

	public function is_configured(): bool {
		return ! empty( $this->username )
			&& ! empty( $this->password )
			&& ! empty( $this->account_number )
			&& ! empty( $this->account_pin )
			&& ! empty( $this->shipper_address );
	}

	public function get_settings_fields(): array {
		$fields = [
			[
				'id'          => 'wcmcs_aramex_username',
				'label'       => __( 'Email (compte Aramex)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Email de votre compte Aramex business (aramex.com).', 'wc-multicarrier-sync' ),
			],
			[
				'id'    => 'wcmcs_aramex_password',
				'label' => __( 'Mot de passe', 'wc-multicarrier-sync' ),
				'type'  => 'password',
			],
			[
				'id'    => 'wcmcs_aramex_account_number',
				'label' => __( 'Numéro de compte', 'wc-multicarrier-sync' ),
				'type'  => 'text',
			],
			[
				'id'    => 'wcmcs_aramex_account_pin',
				'label' => __( 'PIN du compte', 'wc-multicarrier-sync' ),
				'type'  => 'password',
			],
			[
				'id'          => 'wcmcs_aramex_entity',
				'label'       => __( 'Code entity', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'default'     => 'TUN',
				'description' => __( 'Code entity Aramex Tunisie (à confirmer avec votre account manager — ex : TUN).', 'wc-multicarrier-sync' ),
			],
			[
				'id'      => 'wcmcs_aramex_product_group',
				'label'   => __( 'Groupe de produit', 'wc-multicarrier-sync' ),
				'type'    => 'select',
				'options' => self::PRODUCT_GROUPS,
				'default' => 'EXP',
				'description' => __( 'Annexe A Aramex : PDX/PPX = groupe EXP · seul OND = DOM. Une combinaison incohérente est alignée automatiquement sur le type choisi.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_aramex_product_type',
				'label'       => __( 'Type de produit', 'wc-multicarrier-sync' ),
				'type'        => 'select',
				'options'     => self::PRODUCT_TYPES,
				'default'     => 'PDX',
				'description' => __( 'Codes à confirmer avec Aramex Tunisie pour la livraison locale COD (PDX = express domestique recommandé).', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_aramex_default_weight',
				'label'       => __( 'Poids par défaut (KG)', 'wc-multicarrier-sync' ),
				'type'        => 'number',
				'default'     => 1,
				'min'         => 0.1,
				'step'        => 0.1,
				'description' => __( 'Poids utilisé quand la commande n\'a pas de poids défini.', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_aramex_shipper_name',
				'label'       => __( 'Nom du contact expéditeur', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Nom de la personne/entreprise expéditrice (votre boutique).', 'wc-multicarrier-sync' ),
			],
			[
				'id'          => 'wcmcs_aramex_shipper_phone',
				'label'       => __( 'Téléphone expéditeur', 'wc-multicarrier-sync' ),
				'type'        => 'text',
			],
			[
				'id'          => 'wcmcs_aramex_shipper_address',
				'label'       => __( 'Adresse expéditeur (pickUp)', 'wc-multicarrier-sync' ),
				'type'        => 'text',
				'description' => __( 'Adresse de votre boutique/entrepôt d\'où Aramex ramasse les colis (obligatoire).', 'wc-multicarrier-sync' ),
			],
			[
				'id'    => 'wcmcs_aramex_shipper_city',
				'label' => __( 'Ville expéditeur', 'wc-multicarrier-sync' ),
				'type'  => 'text',
			],
		];

		// ── Section Mapping des champs ──────────
		$fields[] = [
			'id'    => 'wcmcs_aramex_mapping_header',
			'type'  => 'title',
			'label' => '',
			'title' => __( 'Mapping des champs WooCommerce → Aramex (destinataire)', 'wc-multicarrier-sync' ),
		];

		$field_labels = [
			'nom'          => __( 'Nom du destinataire (Consignee)', 'wc-multicarrier-sync' ),
			'adresse'      => __( 'Adresse (PartyAddress Line1)', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
			'ville'        => __( 'Ville (City)', 'wc-multicarrier-sync' ),
			'tel'          => __( 'Téléphone (Phone1)', 'wc-multicarrier-sync' ),
			'commentaire'  => __( 'Commentaire', 'wc-multicarrier-sync' ),
		];

		foreach ( self::FIELD_DEFAULTS as $field_key => $defaults ) {
			if ( in_array( $field_key, array( 'commentaire', 'msg' ), true ) ) {
				$fields[] = $this->note_settings_field();
				continue;
			}
			$fields[] = [
				'id'               => 'wcmcs_aramex_map_' . $field_key,
				'label'            => $field_labels[ $field_key ],
				'type'             => 'mapping_row',
				'options'          => $this->wc_field_options(),
				'default'          => $defaults['source'],
				'fallback_id'      => 'wcmcs_aramex_map_' . $field_key . '_fallback',
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

		// Constante stricte key→value de la doc Aramex — toujours en français.
		$gouvernorat = WCMCS_Localization::carrier_gouvernorat( $state_field, $ville_field, 'aramex' );
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
				'Aramex',
				implode( ', ', WCMCS_Localization::carrier_gouvernorat_list( 'aramex' ) )
			)
		);
	}

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
			'ville'        => __( 'Ville', 'wc-multicarrier-sync' ),
			'gouvernorat' => __( 'Gouvernorat', 'wc-multicarrier-sync' ),
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
	//  Création d'expédition (POST CreateShipments)
	// ─────────────────────────────────────────────

	public function create_shipment( WC_Order $order ) {
		$payload = $this->build_shipment_payload( $order );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload = apply_filters( 'wcmcs_aramex_payload', $payload, $order );

		WCMCS_Logger::info( 'Order #' . $order->get_id() . ': envoi vers API Aramex (CreateShipments)' );
		WCMCS_Logger::debug( 'Order #' . $order->get_id() . ': Aramex payload: ' . wp_json_encode( $this->redact_payload( $payload ) ) );

		$response = $this->api_request( 'CreateShipments', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Structure Aramex : { HasErrors, Shipments: [{ ID, ShipmentLabel: { LabelFileContents|LabelURL }, ... }], Notifications }
		$shipment = $response['Shipments'][0] ?? null;
		if ( ! is_array( $shipment ) ) {
			$msg = $this->extract_notifications( $response );
			return new WP_Error( 'aramex_no_shipment', __( 'Aramex : aucun colis créé dans la réponse.', 'wc-multicarrier-sync' ) . ( $msg ? " ({$msg})" : '' ) );
		}

		// AWB = tracking ID.
		$tracking_id = trim( (string) ( $shipment['ID'] ?? '' ) );
		if ( empty( $tracking_id ) ) {
			$msg = $this->extract_notifications( $response );
			return new WP_Error( 'aramex_no_tracking', __( 'Aramex : aucun numéro AWB dans la réponse.', 'wc-multicarrier-sync' ) . ( $msg ? " ({$msg})" : '' ) );
		}

		// Label PDF : LabelURL directe ou base64 → sauvegarder en local.
		$label_url = '';
		$label = $shipment['ShipmentLabel'] ?? [];
		if ( is_array( $label ) ) {
			if ( ! empty( $label['LabelURL'] ) ) {
				$label_url = esc_url_raw( (string) $label['LabelURL'] );
			} elseif ( ! empty( $label['LabelFileContents'] ) ) {
				$saved = $this->save_label_pdf( $tracking_id, (string) $label['LabelFileContents'], $order->get_id() );
				if ( ! is_wp_error( $saved ) ) {
					$label_url = $saved;
				}
			}
		}

		WCMCS_Logger::info( "Aramex: colis créé AWB={$tracking_id}" );

		return [
			'tracking_id' => $tracking_id,
			'label_url'   => $label_url,
			'raw'         => $this->redact_payload( $shipment ),
		];
	}

	// ─────────────────────────────────────────────
	//  Annulation (non supportée directement)
	// ─────────────────────────────────────────────

	public function cancel_shipment( string $tracking_id, WC_Order $order ) {
		return new WP_Error(
			'aramex_cancel_unsupported',
			__( 'Aramex : l\'annulation directe des shipments n\'est pas disponible via l\'API. Contactez votre account manager Aramex ou créez un ticket.', 'wc-multicarrier-sync' )
		);
	}

	// ─────────────────────────────────────────────
	//  Tracking (POST TrackShipments)
	// ─────────────────────────────────────────────

	public function get_tracking_status( string $tracking_id ) {
		$body = [
			'ClientInfo' => $this->client_info(),
			// WSDL vérifié en production : Shipments = ArrayOfstring (liste
			// d'AWB en chaînes) — un objet {WaybillNumber} est rejeté (HTTP 400).
			'Shipments'  => [ (string) $tracking_id ],
			'GetLastTrackingUpdateOnly' => true,
			// DataContract : les 5 références doivent être présentes (sinon 400).
			'Transaction' => [
				'Reference1' => 'track_' . $tracking_id,
				'Reference2' => '',
				'Reference3' => '',
				'Reference4' => '',
				'Reference5' => '',
			],
		];

		$response = $this->api_request( 'TrackShipments', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Structure réelle vérifiée en production : TrackingResults = LISTE de
		// {Key: waybill, Value: [TrackingResult…]} (pas un dictionnaire).
		$results = $response['TrackingResults'] ?? [];
		$tracking = null;
		if ( is_array( $results ) ) {
			foreach ( $results as $entry ) {
				if ( is_array( $entry ) && (string) ( $entry['Key'] ?? '' ) === (string) $tracking_id ) {
					$tracking = $entry;
					break;
				}
			}
			// Filet : certains environnements renvoient un dict indexé par AWB.
			if ( null === $tracking && isset( $results[ $tracking_id ] ) && is_array( $results[ $tracking_id ] ) ) {
				$tracking = [ 'Key' => $tracking_id, 'Value' => $results[ $tracking_id ] ];
			}
		}
		if ( null === $tracking ) {
			return new WP_Error( 'aramex_not_found', __( 'Aramex : colis introuvable.', 'wc-multicarrier-sync' ) );
		}

		// Value = liste de mises à jour — prendre la dernière (la plus récente).
		$updates = $tracking['Value'] ?? [];
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new WP_Error( 'aramex_not_found', __( 'Aramex : colis introuvable.', 'wc-multicarrier-sync' ) );
		}
		$update = $updates[0];
		if ( count( $updates ) > 1 ) {
			$update = end( $updates );
		}
		if ( ! is_array( $update ) ) {
			$update = [];
		}

		$update_code = (string) ( $update['UpdateCode'] ?? '' );
		$update_desc = (string) ( $update['UpdateDescription'] ?? '' );
		$problem_code = (string) ( $update['ProblemCode'] ?? '' );

		return [
			'tracking_id'   => $tracking_id,
			'state_code'    => $update_code,
			'state_label'   => $update_desc !== '' ? $update_desc : $update_code,
			'problem_code'  => $problem_code,
			'raw'           => $tracking,
		];
	}

	// ─────────────────────────────────────────────
	//  Construction du payload (modèle freight)
	// ─────────────────────────────────────────────

	/**
	 * Payload CreateShipments.
	 *
	 * @return array|WP_Error
	 */
	private function build_shipment_payload( WC_Order $order ) {
		// Annexe A : ProductGroup aligné automatiquement sur le ProductType —
		// warning UNE FOIS PAR SYNC (le constructeur tourne à chaque requête
		// HTTP et spammait les logs — revue de code 2.10.0).
		if ( $this->product_group_was_aligned ) {
			WCMCS_Logger::warning( sprintf(
				'Aramex : combinaison ProductGroup réglée incohérente avec ProductType %s (Annexe A) — groupe aligné sur %s. Corrigez le réglage « Groupe de produit ».',
				$this->product_type,
				$this->product_group
			) );
		}

		$total_qty  = 0;
		$item_names = [];
		$total_weight = 0.0;
		foreach ( $order->get_items() as $item ) {
			$qty          = $item->get_quantity();
			$total_qty   += $qty;
			$item_names[] = WCMCS_Localization::item_designation_french( $item ) . ' x' . $qty;
			$product      = $item->get_product();
			if ( $product && $product->get_weight() ) {
				// wc_get_weight : convertit depuis l'unité de la boutique
				// (g/lbs/oz…) vers KG — un poids brut déclarait « 2000 KG »
				// pour 2 kg sur une boutique en grammes (revue 2.10.0).
				$total_weight += (float) wc_get_weight( $product->get_weight(), 'kg' ) * $qty;
			}
		}
		$nb_pieces = max( 1, $total_qty );
		$weight    = $total_weight > 0 ? $total_weight : $this->default_weight;

		$nom         = $this->get_mapped_field( $order, 'nom' );
		$adresse     = $this->get_mapped_field( $order, 'adresse' );
		$ville       = $this->get_mapped_field( $order, 'ville' );
		$tel         = $this->get_mapped_field( $order, 'tel' );

		$gouvernorat = $this->resolve_gouvernorat( $order );
		if ( is_wp_error( $gouvernorat ) ) {
			WCMCS_Logger::warning( 'Order #' . $order->get_id() . ': gouvernorat invalide — ' . $gouvernorat->get_error_message() );
			return $gouvernorat;
		}

		$ville = WCMCS_Localization::translate_ville( $ville, $gouvernorat );

		$payload = [
			'ClientInfo' => $this->client_info(),
			'Shipments'  => [
				[
					'Reference1' => (string) $order->get_id(),
					'Reference2' => '',
					'Shipper' => [
						'Reference1' => '',
						'AccountNumber' => $this->account_number,
						'PartyAddress' => [
							'Line1' => $this->shipper_address,
							'Line2' => '',
							'Line3' => '',
							'City' => $this->shipper_city,
							'StateOrProvinceCode' => '',
							'CountryCode' => 'TN',
							'PostCode' => '',
						],
						'Contact' => [
							'PersonName' => $this->shipper_name ?: get_bloginfo( 'name' ),
							'CompanyName' => get_bloginfo( 'name' ),
							'Phone1' => $this->shipper_phone,
							'Phone2' => '',
							'Fax' => '',
							'CellPhone' => $this->shipper_phone,
							// Table 21/Fig.29 : EmailAddress requis — admin de la boutique.
							'EmailAddress' => get_bloginfo( 'admin_email' ),
						],
					],
					'Consignee' => [
						'Reference1' => '',
						'PartyAddress' => [
							'Line1' => $adresse,
							'Line2' => '',
							'Line3' => '',
							'City' => $ville,
							'StateOrProvinceCode' => $gouvernorat,
							'CountryCode' => 'TN',
							'PostCode' => '',
						],
						'Contact' => [
							'PersonName' => $nom,
							// Table 21 : CompanyName requis — fallback nom du client.
							'CompanyName' => $nom,
							'Phone1' => $tel,
							'Phone2' => '',
							'CellPhone' => $tel,
							// Table 21 : EmailAddress requis — email de facturation.
							'EmailAddress' => (string) $order->get_billing_email(),
						],
					],
					'ShippingDateTime' => '/Date(' . ( time() * 1000 ) . ')/',
					'PickupLocation' => 'Reception',
					// Étiquette PDF : sans LabelInfo, ShipmentLabel n'est JAMAIS
					// retourné (Table 11/26 — ReportID 9201, type « URL » = lien direct).
					'LabelInfo' => [
						'ReportID'   => 9201,
						'ReportType' => 'URL',
					],
					'ActualWeight' => [
						'Unit' => 'KG',
						'Value' => round( $weight, 2 ),
					],
					'ProductGroup' => $this->product_group,
					'ProductType' => $this->product_type,
					// COD tunisien : 'C' = paiement par le destinataire à la
					// livraison + service CODS obligatoire (Table 22/Annexe C :
					// « CashOnDeliveryAmount based on the Services "CODS" »).
					'PaymentType' => 'C',
					'PaymentOptions' => '',
					'Services' => 'CODS',
					'NumberOfPieces' => $nb_pieces,
					// Table 22 : limite 100 caractères.
					'DescriptionOfGoods' => mb_substr( implode( '; ', $item_names ), 0, 100, 'UTF-8' ),
					'GoodsOriginCountryCode' => 'TN',
					// Table 29 : CashOnDeliveryAmount = objet Money {Value, CurrencyCode}
					// (un float brut casse la désérialisation). NB : le manuel
					// mentionne « currency must be in USD » — TND utilisé pour la
					// Tunisie, À CONFIRMER avec l'account manager Aramex.
					'CashOnDeliveryAmount' => [
						'Value'        => round( (float) $order->get_total(), 2 ),
						'CurrencyCode' => 'TND',
					],
					'CashExtraServices' => '',
					'CustomsValueAmount' => null,
					'Items' => [],
				],
			],
			// DataContract vérifié en production : si Transaction est fourni,
			// les 5 références doivent être présentes (sinon HTTP 400
			// « required data members Reference2-5 »).
			'Transaction' => [
				'Reference1' => 'wcmcs_order_' . $order->get_id(),
				'Reference2' => '',
				'Reference3' => '',
				'Reference4' => '',
				'Reference5' => '',
			],
		];

		return $payload;
	}

	/**
	 * ClientInfo Aramex (credentials).
	 */
	private function client_info(): array {
		return [
			'AccountCountryCode' => 'TN',
			'AccountEntity'       => $this->entity,
			'AccountNumber'       => $this->account_number,
			'AccountPin'          => $this->account_pin,
			'UserName'           => $this->username,
			'Password'           => $this->password,
			'Version'            => 'v2.0',
		];
	}

	// ─────────────────────────────────────────────
	//  HTTP Client (JSON, POST)
	// ─────────────────────────────────────────────

	/**
	 * Appel API Aramex — POST JSON à {base}/{Operation}.
	 *
	 * @param string $operation CreateShipments|TrackShipments.
	 * @param array  $body     Corps JSON complet.
	 * @return array|WP_Error
	 */
	private function api_request( string $operation, array $body ) {
		$url  = rtrim( self::API_BASE, '/' ) . '/' . $operation;
		$args = [
			'method'  => 'POST',
			'timeout' => $this->api_timeout,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		];

		WCMCS_Logger::info( "Aramex API request: {$operation}" );
		WCMCS_Logger::debug( 'Aramex request payload: ' . wp_json_encode( $this->redact_payload( $body ) ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			WCMCS_Logger::error( "Aramex HTTP error: {$msg} | URL: {$url}" );
			return new WP_Error( 'aramex_http_error', $msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$parsed      = json_decode( $raw_body, true );

		WCMCS_Logger::info( "Aramex response [{$status_code}]" );
		WCMCS_Logger::debug( 'Aramex response body: ' . WCMCS_Logger::redact_body( $raw_body ) );

		if ( ! is_array( $parsed ) ) {
			WCMCS_Logger::error( "Aramex: réponse JSON invalide (HTTP {$status_code})" );
			return new WP_Error(
				'aramex_invalid_json',
				sprintf( __( 'Aramex : réponse JSON invalide (HTTP %d).', 'wc-multicarrier-sync' ), $status_code )
			);
		}

		// Aramex retourne HasErrors + Notifications même en HTTP 200.
		if ( ! empty( $parsed['HasErrors'] ) ) {
			$msg = $this->extract_notifications( $parsed );
			return new WP_Error( 'aramex_api_error', ( $msg ? sprintf( __( 'Aramex : %s', 'wc-multicarrier-sync' ), $msg ) : __( 'Aramex : requête refusée.', 'wc-multicarrier-sync' ) ) );
		}

		return $parsed;
	}

	/**
	 * Extrait les messages des Notifications Aramex.
	 */
	private function extract_notifications( array $response ): string {
		$notifications = $response['Notifications'] ?? [];
		if ( ! is_array( $notifications ) ) {
			return '';
		}
		$messages = [];
		foreach ( $notifications as $n ) {
			if ( is_array( $n ) && ! empty( $n['Message'] ) ) {
				$messages[] = (string) $n['Message'];
			} elseif ( is_string( $n ) ) {
				$messages[] = $n;
			}
		}
		return implode( ' | ', $messages );
	}

	// ─────────────────────────────────────────────
	//  Label PDF (base64 → fichier local)
	// ─────────────────────────────────────────────

	/**
	 * Sauvegarde le label PDF base64 (pattern Colissimo).
	 *
	 * @return string|WP_Error URL publique du fichier.
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

			$filename = sanitize_file_name( 'aramex_' . $tracking_id . '_' . time() . '.pdf' );
			$decoded  = base64_decode( $base64_pdf, true );
			if ( false === $decoded ) {
				return new WP_Error( 'aramex_label_decode', __( 'Aramex : échec décodage label PDF.', 'wc-multicarrier-sync' ) );
			}

			$file_path = $label_dir . $filename;
			$written   = file_put_contents( $file_path, $decoded );
			if ( false === $written || $written !== strlen( $decoded ) ) {
				return new WP_Error( 'aramex_label_write', __( 'Aramex : échec sauvegarde label PDF.', 'wc-multicarrier-sync' ) );
			}

			// URL PUBLIQUE du répertoire de labels (pattern Colissimo) :
			// get_label_display_url() régénère un nonce frais pour les URLs
			// sous {uploads}/wcmcs-labels/ — une URL admin-ajax stockée avec
			// son nonce mourrait au bout de ~24 h (revue de code 2.10.0).
			return $upload_dir['baseurl'] . '/wcmcs-labels/' . $order_id . '/' . $filename;

		} catch ( \Throwable $e ) {
			return new WP_Error( 'aramex_label_error', $e->getMessage() );
		}
	}

	// ─────────────────────────────────────────────
	//  Sécurité des logs
	// ─────────────────────────────────────────────

	/**
	 * Masque password + PIN + redacte la PII.
	 */
	private function redact_payload( array $payload ): array {
		if ( isset( $payload['ClientInfo']['Password'] ) ) {
			$payload['ClientInfo']['Password'] = '[REDACTED]';
		}
		if ( isset( $payload['ClientInfo']['AccountPin'] ) ) {
			$payload['ClientInfo']['AccountPin'] = '[REDACTED]';
		}
		// Shipments[0] aussi.
		if ( isset( $payload['Shipments'][0]['ShipmentLabel'] ) ) {
			unset( $payload['Shipments'][0]['ShipmentLabel'] ); // base64 trop volumineux pour les logs
		}
		return WCMCS_Logger::redact_pii( $payload );
	}
}
