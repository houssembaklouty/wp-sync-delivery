<?php
defined( 'ABSPATH' ) || exit;

/**
 * Commandes abandonnées du formulaire COD de la fiche produit.
 *
 * Dès qu'un téléphone valide est saisi, une commande « Abandonnée » est créée puis mise à jour pendant
 * la saisie. Si le client clique sur « Acheter », le thème crée la vraie commande ; l'abandonnée est
 * alors absorbée (agent, chronologie et tâches transférés) puis supprimée.
 */
class CODCRM_Abandoned {

	const STATUS      = 'wc-codcrm-abandoned';
	const SLUG        = 'codcrm-abandoned';
	const META_TOKEN  = '_codcrm_ab_token';
	const META_PRODUCT = '_codcrm_ab_product';
	const META_SIG    = '_codcrm_ab_sig';
	const META_UNTIL  = '_codcrm_ab_pending_until';
	const REUSE_HOURS = 24;

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_status' ] );
		// Jamais dans les rapports de ventes.
		add_filter( 'woocommerce_excluded_report_order_statuses', [ __CLASS__, 'exclude_from_reports' ] );
		add_filter( 'woocommerce_analytics_excluded_statuses', [ __CLASS__, 'exclude_from_reports' ] );

		add_action( 'wp_ajax_nopriv_codcrm_ab_capture', [ __CLASS__, 'ajax_capture' ] );
		add_action( 'wp_ajax_codcrm_ab_capture', [ __CLASS__, 'ajax_capture' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ], 30 );

		// Avant la répartition (priorité 20) : l'agent de l'abandonnée est repris.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'convert' ], 5, 3 );
		// Les fiches produit en cache n'ont pas le script : on vide les caches une fois par version.
		add_action( 'admin_init', [ __CLASS__, 'maybe_purge_cache' ] );
		add_filter( 'wparabic_cod_order_status', [ __CLASS__, 'final_status' ], 10, 2 );
	}

	/** Vide les caches de pages (une fois par version du plugin) pour que les fiches produit chargent le script. */
	public static function maybe_purge_cache(): void {
		if ( get_option( 'codcrm_ab_cache_ver' ) === CODCRM_VERSION ) {
			return;
		}
		update_option( 'codcrm_ab_cache_ver', CODCRM_VERSION, false );
		self::purge_page_cache();
	}

	public static function purge_page_cache(): void {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'instance' ) ) {
			WPO_Page_Cache::instance()->purge(); // WP-Optimize.
		}
		if ( class_exists( 'WP_Optimize_Minify_Cache_Functions' ) && method_exists( 'WP_Optimize_Minify_Cache_Functions', 'purge' ) ) {
			WP_Optimize_Minify_Cache_Functions::purge();
		}
		do_action( 'wpo_cache_flush' );
		do_action( 'cloudflare_purge_everything' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	// ── Réglages ───────────────────────────────────────────────

	public static function enabled(): bool {
		return 'yes' === get_option( 'codcrm_abandoned_enabled', 'yes' );
	}

	/** Restaurer le formulaire (champs + choix de produit) après un rafraîchissement de la page. */
	public static function restore_enabled(): bool {
		return 'yes' === get_option( 'codcrm_form_restore', 'yes' );
	}

	/** Minutes d'inactivité avant d'attribuer l'abandonnée à un agent. */
	public static function delay(): int {
		return max( 5, min( 120, (int) get_option( 'codcrm_abandoned_delay', 15 ) ) );
	}

	/** Statut des commandes validées du formulaire : on-hold (défaut) ou processing. */
	public static function final_status( $status, $order = null ) {
		$opt = (string) get_option( 'codcrm_cod_final_status', 'on-hold' );
		return in_array( $opt, [ 'on-hold', 'processing' ], true ) ? $opt : $status;
	}

	// ── Statut WooCommerce ─────────────────────────────────────

	public static function register_status(): void {
		register_post_status( self::STATUS, [
			'label'                     => _x( 'Abandonnée', 'order status', 'cod-tracker-crm' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s nombre */
			'label_count'               => _n_noop( 'Abandonnée <span class="count">(%s)</span>', 'Abandonnées <span class="count">(%s)</span>', 'cod-tracker-crm' ),
		] );
	}

	public static function add_status( array $statuses ): array {
		$out = [];
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-pending' === $k ) {
				$out[ self::STATUS ] = _x( 'Abandonnée', 'order status', 'cod-tracker-crm' );
			}
		}
		return isset( $out[ self::STATUS ] ) ? $out : $out + [ self::STATUS => _x( 'Abandonnée', 'order status', 'cod-tracker-crm' ) ];
	}

	public static function exclude_from_reports( $statuses ) {
		$statuses = (array) $statuses;
		$statuses[] = self::SLUG;
		return array_values( array_unique( $statuses ) );
	}

	// ── Script de la fiche produit ─────────────────────────────

	public static function enqueue(): void {
		if ( ( ! self::enabled() && ! self::restore_enabled() ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$rel = 'assets/js/abandoned.js';
		wp_enqueue_script( 'codcrm-abandoned', CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel ), [ 'jquery' ], null, true );
		wp_localize_script( 'codcrm-abandoned', 'codcrmAb', [
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'capture' => self::enabled(),
			'restore' => self::restore_enabled(),
			'pid'     => (int) get_queried_object_id(),
		] );
	}

	// ── Capture (AJAX public) ──────────────────────────────────

	private static function ok(): void {
		wp_send_json_success( [ 'ok' => 1 ] ); // réponse neutre : rien à apprendre à un robot.
	}

	private static function norm_phone( string $p ): string {
		$d = preg_replace( '/\D+/', '', $p );
		$d = preg_replace( '/^(?:00)?216(?=\d{8}$)/', '', (string) $d );
		return preg_match( '/^\d{8}$/', (string) $d ) ? (string) $d : '';
	}

	public static function ajax_capture(): void {
		if ( ! self::enabled() ) {
			self::ok();
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'codplugin_nonce' ) ) {
			wp_send_json_error( [ 'code' => 'nonce' ] );
		}
		if ( ! empty( $_POST['codplugin_website'] ) ) { // piège à robots du formulaire.
			self::ok();
		}
		$token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9]{16,40}$/', $token ) ) {
			self::ok();
		}
		$phone = self::norm_phone( sanitize_text_field( wp_unslash( $_POST['phone_number'] ?? '' ) ) );
		if ( '' === $phone ) {
			self::ok();
		}
		if ( function_exists( 'wparabic_is_phone_banned' ) && wparabic_is_phone_banned( $phone ) ) {
			self::ok(); // numéro bloqué : aucune commande abandonnée.
		}

		// Limite par IP (les opérateurs mobiles partagent une IP : large).
		$ip  = function_exists( 'wparabic_cod_client_ip' ) ? (string) wparabic_cod_client_ip() : '';
		$key = 'codcrm_ab_ip_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( '' !== $ip && $n >= 60 ) {
			self::ok();
		}
		if ( '' !== $ip ) {
			set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		}

		$product = wc_get_product( absint( $_POST['product_id'] ?? 0 ) );
		if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) ) {
			self::ok();
		}

		$lock = 'codcrm_lock_ab_' . $token;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 20 ) {
				self::ok(); // une capture de ce client est déjà en cours.
			}
			update_option( $lock, time(), false );
		}
		try {
			self::upsert( $product, $phone, $token, [
				'name'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) ), 0, 120 ),
				'state'   => sanitize_text_field( wp_unslash( $_POST['codplugin_state'] ?? '' ) ),
				'city'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['codplugin_city'] ?? '' ) ), 0, 120 ),
				'address' => mb_substr( sanitize_text_field( wp_unslash( $_POST['full_address'] ?? '' ) ), 0, 255 ),
				'email'   => sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) ),
				'qty'     => max( 1, min( 20, absint( $_POST['codplugin_c_number'] ?? 1 ) ) ),
				'vars'    => array_slice( array_map( 'absint', (array) wp_unslash( $_POST['vars'] ?? [] ) ), 0, 20 ),
			] );
		} finally {
			delete_option( $lock );
		}
		self::ok();
	}

	/** Commande abandonnée existante : jeton, sinon même téléphone + même produit (< 24 h). */
	public static function find( string $token, string $phone, int $product_id ): ?WC_Order {
		if ( '' !== $token ) {
			$ids = wc_get_orders( [ 'status' => self::SLUG, 'limit' => 1, 'return' => 'ids', 'meta_key' => self::META_TOKEN, 'meta_value' => $token ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $ids ) {
				return wc_get_order( (int) $ids[0] ) ?: null;
			}
		}
		if ( '' !== $phone ) {
			$ids = wc_get_orders( [
				'status'        => self::SLUG,
				'limit'         => 5,
				'return'        => 'ids',
				'billing_phone' => $phone,
				'date_created'  => '>' . ( time() - self::REUSE_HOURS * HOUR_IN_SECONDS ),
			] );
			foreach ( (array) $ids as $id ) {
				$o = wc_get_order( (int) $id );
				if ( $o && (int) $o->get_meta( self::META_PRODUCT ) === $product_id ) {
					return $o;
				}
			}
		}
		return null;
	}

	/** Crée ou met à jour la commande abandonnée. */
	private static function upsert( WC_Product $product, string $phone, string $token, array $d ): void {
		$order = self::find( $token, $phone, $product->get_id() );
		$new   = ! $order;
		if ( $new ) {
			$order = wc_create_order( [ 'created_via' => 'codcrm-abandoned', 'status' => self::STATUS ] );
			if ( is_wp_error( $order ) ) {
				return;
			}
			$order->update_meta_data( self::META_TOKEN, $token );
			$order->update_meta_data( self::META_PRODUCT, $product->get_id() );
			if ( function_exists( 'wparabic_current_lang' ) ) {
				$order->update_meta_data( '_wparabic_lang', wparabic_current_lang() );
			}
			$order->set_customer_ip_address( function_exists( 'wparabic_cod_client_ip' ) ? (string) wparabic_cod_client_ip() : '' );
			$order->set_customer_user_agent( mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 250 ) );
		}

		// Adresse : gouvernorat / délégation validés comme dans le formulaire du thème.
		$governorates = function_exists( 'wparabic_get_tunisian_governorates' ) ? wparabic_get_tunisian_governorates() : [];
		$state        = isset( $governorates[ $d['state'] ] ) ? $d['state'] : '';
		$city         = ( $state && function_exists( 'wparabic_is_valid_delegation' ) && wparabic_is_valid_delegation( $state, $d['city'] ) ) ? $d['city'] : '';
		$address      = [
			'first_name' => '' !== $d['name'] ? $d['name'] : $phone,
			'phone'      => $phone,
			'country'    => 'TN',
			'state'      => ( $state && function_exists( 'wparabic_state_code' ) ) ? wparabic_state_code( $state ) : '',
			'city'       => $city,
			'address_1'  => $d['address'],
		];
		if ( $d['email'] ) {
			$address['email'] = $d['email'];
		}
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );

		// Articles : variations vérifiées (appartiennent au produit), regroupées.
		$lines = [];
		if ( $product->is_type( 'variable' ) ) {
			$groups = [];
			foreach ( array_slice( array_filter( $d['vars'] ), 0, $d['qty'] ) as $vid ) {
				$groups[ $vid ] = ( $groups[ $vid ] ?? 0 ) + 1;
			}
			foreach ( $groups as $vid => $count ) {
				$v = wc_get_product( $vid );
				if ( $v instanceof WC_Product_Variation && $v->get_parent_id() === $product->get_id() ) {
					$lines[] = [ $v, $count ];
				}
			}
		} elseif ( $product->is_purchasable() ) {
			$lines[] = [ $product, $d['qty'] ];
		}
		$sig = md5( wp_json_encode( [ array_map( static function ( $l ) { return [ $l[0]->get_id(), $l[1] ]; }, $lines ), $state ] ) );
		if ( $new || (string) $order->get_meta( self::META_SIG ) !== $sig ) {
			$order->remove_order_items();
			foreach ( $lines as $l ) {
				$order->add_product( $l[0], $l[1] );
			}
			if ( $state && function_exists( 'wparabic_get_shipping_rate' ) && function_exists( 'wparabic_build_shipping_item' ) ) {
				$qty  = array_sum( array_column( $lines, 1 ) ) ?: $d['qty'];
				$rate = wparabic_get_shipping_rate( $state, $product, $qty );
				$order->add_item( wparabic_build_shipping_item( $rate ) );
			}
			$order->update_meta_data( self::META_SIG, $sig );
		}
		$order->set_customer_note( sprintf( /* translators: %s produit */ __( 'Commande abandonnée (formulaire non validé) — %s', 'cod-tracker-crm' ), $product->get_name() ) );
		$order->update_meta_data( self::META_UNTIL, time() + self::delay() * MINUTE_IN_SECONDS );
		$order->calculate_totals();
		$order->save();

		if ( $new ) {
			CODCRM_Event_Collector::set_current_stage( $order, CODCRM_Stages::ABANDONNEE );
			// Attribuée à un agent seulement après le délai d'inactivité (voir CODCRM_Dispatcher::dispatch).
			$order->update_meta_data( CODCRM_Dispatcher::META_WAITING, '1' );
			$order->save_meta_data();
			CODCRM_Events::add( $order->get_id(), [ 'type' => 'note', 'note' => __( 'Commande abandonnée créée (formulaire COD)', 'cod-tracker-crm' ), 'source' => 'system' ] );
		}
	}

	// ── Conversion : le client a cliqué sur « Acheter » ────────

	public static function convert( $order_id, $posted = [], $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order || 'cod-form' !== $order->get_created_via() ) {
			return;
		}
		$token = sanitize_key( wp_unslash( $_POST['codcrm_ab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$phone = self::norm_phone( (string) $order->get_billing_phone() );
		$pid   = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$pid = $item->get_product_id();
				break;
			}
		}
		$ab = self::find( preg_match( '/^[a-z0-9]{16,40}$/', $token ) ? $token : '', $phone, $pid );
		if ( ! $ab || $ab->get_id() === $order->get_id() ) {
			return;
		}
		self::absorb( $ab, $order );
	}

	/** Transfère agent, chronologie et tâches de l'abandonnée vers la vraie commande, puis la supprime. */
	public static function absorb( WC_Order $ab, WC_Order $order ): void {
		global $wpdb;
		$agent = CODCRM_Agents::agent_of( $ab );
		if ( $agent && ! CODCRM_Agents::agent_of( $order ) ) {
			CODCRM_Agents::assign( $order, $agent, 0, 'system', __( 'Reprise de la commande abandonnée', 'cod-tracker-crm' ), 'auto' );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE IGNORE ' . CODCRM_Installer::table_events() . ' SET order_id = %d WHERE order_id = %d', $order->get_id(), $ab->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . CODCRM_Installer::table_tasks() . ' SET order_id = %d WHERE order_id = %d', $order->get_id(), $ab->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$last = (string) $ab->get_meta( CODCRM_Contacts::META_LAST_CONTACT );
		if ( '' !== $last ) {
			$order->update_meta_data( CODCRM_Contacts::META_LAST_CONTACT, $last );
			$order->save_meta_data();
		}
		$order->add_order_note( sprintf( /* translators: %d n° commande */ __( '[COD] Issue de la commande abandonnée #%d (le client a validé le formulaire).', 'cod-tracker-crm' ), $ab->get_id() ) );
		CODCRM_Events::add( $order->get_id(), [ 'type' => 'note', 'note' => sprintf( __( 'Commande abandonnée #%d validée par le client', 'cod-tracker-crm' ), $ab->get_id() ), 'source' => 'system' ] );
		$ab->delete( true );
	}
}
