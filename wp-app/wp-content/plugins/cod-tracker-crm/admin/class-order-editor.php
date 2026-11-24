<?php
defined( 'ABSPATH' ) || exit;

/**
 * « Mes suivis » : historique des statuts au survol + fenêtre « Modifier la commande ».
 *
 * Après la synchro (colis créé chez le transporteur) les sections client / produits / frais /
 * transporteur sont verrouillées CÔTÉ SERVEUR : MultiCarrier Sync ne met pas le colis à jour.
 */
class CODCRM_Order_Editor {

	const META_PHONE2   = '_codcrm_phone2';
	const META_EXCHANGE = '_codcrm_exchange';
	const LOCKED_STATUS = [ 'wcmcs-synced', 'wcmcs-packed', 'wcmcs-delivered', 'wcmcs-returned' ];

	public static function init(): void {
		add_action( 'wp_ajax_codcrm_status_history', [ __CLASS__, 'ajax_history' ] );
		add_action( 'wp_ajax_codcrm_order_get', [ __CLASS__, 'ajax_get' ] );
		add_action( 'wp_ajax_codcrm_order_save', [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_codcrm_product_search', [ __CLASS__, 'ajax_product_search' ] );
	}

	/** Commande de la requête, après nonce + droits (sinon réponse d'erreur). */
	private static function order_from_request(): WC_Order {
		check_ajax_referer( 'codcrm', 'nonce' );
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! current_user_can( CODCRM_Agents::CAP_WORK ) || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'cod-tracker-crm' ) ], 403 );
		}
		return $order;
	}

	/** Attributs lisibles d'une variation (« Noir, 40 »). */
	private static function attrs( $product ): string {
		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return '';
		}
		return wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) );
	}

	private static function stock_label( $product ): string {
		if ( ! $product ) {
			return '';
		}
		if ( ! $product->is_in_stock() ) {
			return __( 'Rupture', 'cod-tracker-crm' );
		}
		return $product->managing_stock() ? sprintf( /* translators: %d quantité */ __( 'Stock : %d', 'cod-tracker-crm' ), (int) $product->get_stock_quantity() ) : '';
	}

	/** Agents sélectionnables, en incluant toujours l'agent actuel (même sans le rôle agent). */
	private static function agent_list( int $current ): array {
		$list = array_map( static function ( $u ) { return [ 'id' => (int) $u->ID, 'name' => $u->display_name ]; }, CODCRM_Agents::get_agents() );
		if ( $current && ! in_array( $current, array_column( $list, 'id' ), true ) ) {
			$list[] = [ 'id' => $current, 'name' => CODCRM_Agents::agent_name( $current ) ?: '#' . $current ];
		}
		return $list;
	}

	public static function is_locked( WC_Order $o ): bool {
		return 'synced' === (string) $o->get_meta( '_wcmcs_sync_status' ) || in_array( $o->get_status(), self::LOCKED_STATUS, true );
	}

	// ── Historique des statuts ─────────────────────────────────

	public static function ajax_history(): void {
		$order = self::order_from_request();
		$cards = [];
		$prev  = '';
		foreach ( CODCRM_Events::for_order( $order->get_id() ) as $e ) {
			if ( ! in_array( $e->type, [ 'status', 'carrier_state' ], true ) || '' === (string) $e->stage ) {
				continue;
			}
			$key = $e->stage . '|' . $e->carrier_state;
			if ( $key === $prev ) {
				continue;
			}
			$prev = $key;
			$who  = '';
			if ( 'carrier_state' === $e->type && $e->carrier_slug ) {
				$logo = class_exists( 'WCMCS_Carrier_Detector' ) ? WCMCS_Carrier_Detector::get_logo_url( (string) $e->carrier_slug ) : '';
				$who  = $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $e->carrier_slug ) . '" class="codcrm-h-logo" />' : '<strong>' . esc_html( $e->carrier_slug ) . '</strong>';
			} elseif ( (int) $e->actor_id && ( $u = get_userdata( (int) $e->actor_id ) ) ) {
				$who = '<strong>' . esc_html( $u->display_name ) . '</strong>';
			} else {
				$who = '<strong>' . esc_html( CODCRM_Stages::COMMANDE === $e->stage ? __( 'Client', 'cod-tracker-crm' ) : __( 'Système', 'cod-tracker-crm' ) ) . '</strong>';
			}
			$raw = ( 'carrier_state' === $e->type && '' !== (string) $e->carrier_state && CODCRM_Stages::label( $e->stage ) !== $e->carrier_state )
				? '<small class="codcrm-muted">' . esc_html( $e->carrier_state ) . '</small>' : '';
			$cards[] = '<div class="codcrm-h-card">' . $who
				. '<div class="codcrm-h-date">' . esc_html( mysql2date( 'd M Y, H:i', $e->occurred_at ) ) . '</div>'
				. '<span class="codcrm-pill" style="' . esc_attr( CODCRM_Stages::badge_style( CODCRM_Stages::color( $e->stage ) ) ) . '">' . esc_html( CODCRM_Stages::label( $e->stage ) ) . '</span> ' . $raw . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		wp_send_json_success( [ 'html' => $cards ? implode( '', $cards ) : '<p class="codcrm-muted">' . esc_html__( 'Aucun historique.', 'cod-tracker-crm' ) . '</p>' ] );
	}

	// ── Lecture ────────────────────────────────────────────────

	public static function ajax_get(): void {
		$o      = self::order_from_request();
		$locked = self::is_locked( $o );
		$items  = [];
		foreach ( $o->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$p       = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );
			$items[] = [
				'item_id'      => $item->get_id(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $p && $p->is_type( 'variation' ) ? get_the_title( $item->get_product_id() ) : $item->get_name(),
				'attrs'        => self::attrs( $p ),
				'image'        => $p ? wp_get_attachment_image_url( $p->get_image_id() ?: ( $p->get_parent_id() ? (int) get_post_thumbnail_id( $p->get_parent_id() ) : 0 ), 'thumbnail' ) : '',
				'qty'          => $qty,
				'price'        => (float) wc_format_decimal( (float) $item->get_subtotal() / $qty, 3 ),
			];
		}
		$ship = 0.0;
		foreach ( $o->get_items( 'shipping' ) as $s ) {
			$ship += (float) $s->get_total();
		}
		$carriers = [];
		$logos    = [];
		if ( class_exists( 'WCMCS_Carrier_Detector' ) ) {
			foreach ( WCMCS_Carrier_Detector::get_enabled_providers() as $slug => $prov ) {
				$carriers[ $slug ] = method_exists( $prov, 'get_name' ) ? (string) $prov->get_name() : $slug;
				$logos[ $slug ]    = WCMCS_Carrier_Detector::get_logo_url( $slug );
			}
			$now = (string) $o->get_meta( '_wcmcs_carrier_slug' );
			if ( $now && ! isset( $logos[ $now ] ) ) {
				$logos[ $now ] = WCMCS_Carrier_Detector::get_logo_url( $now );
			}
		}
		$states = WC()->countries->get_states( 'TN' ) ?: [];
		asort( $states );
		$statuses = wc_get_order_statuses();
		$colors   = [];
		foreach ( array_keys( $statuses ) as $st ) {
			$colors[ $st ] = CODCRM_Stages::color( CODCRM_Stage_Mapper::from_order_status( substr( $st, 3 ) ) );
		}
		$strikes  = function_exists( 'wparabic_phone_strikes' ) ? (int) wparabic_phone_strikes( $o->get_billing_phone() ) : 0;

		wp_send_json_success( [
			'id'        => $o->get_id(),
			'number'    => $o->get_order_number(),
			'locked'    => $locked,
			'manager'   => current_user_can( CODCRM_Agents::CAP_MANAGE ),
			'status'    => 'wc-' . $o->get_status(),
			'statuses'  => $statuses,
			'status_colors' => $colors,
			'logos'     => $logos,
			'carrier'   => (string) $o->get_meta( '_wcmcs_forced_carrier' ),
			'carrier_now' => (string) $o->get_meta( '_wcmcs_carrier_slug' ),
			'tracking'  => (string) $o->get_meta( '_wcmcs_tracking_id' ),
			'carriers'  => $carriers,
			'agent'     => CODCRM_Agents::agent_of( $o ),
			'agents'    => self::agent_list( CODCRM_Agents::agent_of( $o ) ),
			'exchange'  => '1' === (string) $o->get_meta( self::META_EXCHANGE ),
			'first'     => $o->get_billing_first_name(),
			'last'      => $o->get_billing_last_name(),
			'phone'     => $o->get_billing_phone(),
			'phone2'    => (string) $o->get_meta( self::META_PHONE2 ),
			'state'     => $o->get_billing_state(),
			'states'    => $states,
			'address'   => $o->get_billing_address_1(),
			'email'     => $o->get_billing_email(),
			'note'      => $o->get_customer_note(),
			'strikes'   => $strikes,
			'items'     => $items,
			'shipping'  => $ship,
			'coupons'   => array_values( $o->get_coupon_codes() ),
			'subtotal'  => (float) $o->get_subtotal(),
			'discount'  => (float) $o->get_discount_total(),
			'total'     => (float) $o->get_total(),
		] );
	}

	/** Recherche produits / variations pour l'ajout dans la fenêtre. */
	public static function ajax_product_search(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [], 403 );
		}
		$q   = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$out = [];
		if ( strlen( $q ) >= 2 ) {
			foreach ( wc_get_products( [ 's' => $q, 'limit' => 10, 'status' => 'publish', 'return' => 'objects' ] ) as $p ) {
				$list = $p->is_type( 'variable' ) ? array_map( 'wc_get_product', array_slice( $p->get_children(), 0, 30 ) ) : [ $p ];
				foreach ( array_filter( $list ) as $v ) {
					$out[] = [
						'product_id'   => $v->is_type( 'variation' ) ? $v->get_parent_id() : $v->get_id(),
						'variation_id' => $v->is_type( 'variation' ) ? $v->get_id() : 0,
						'name'         => $p->get_name(),
						'attrs'        => self::attrs( $v ),
						'stock'        => self::stock_label( $v ),
						'price'        => (float) $v->get_price(),
						'image'        => wp_get_attachment_image_url( $v->get_image_id() ?: $p->get_image_id(), 'thumbnail' ),
					];
				}
			}
		}
		wp_send_json_success( array_slice( $out, 0, 40 ) );
	}

	// ── Enregistrement ─────────────────────────────────────────

	public static function ajax_save(): void {
		$o    = self::order_from_request();
		$id   = $o->get_id();
		$lock = 'codcrm_lock_edit_' . $id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 60 ) {
				wp_send_json_error( [ 'message' => __( 'Cette commande est en cours de modification.', 'cod-tracker-crm' ) ] );
			}
			update_option( $lock, time(), false );
		}
		try {
			$res = self::save( $o, (array) json_decode( (string) wp_unslash( $_POST['data'] ?? '' ), true ) );
		} finally {
			delete_option( $lock );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message(), 'field' => $res->get_error_code() ] );
		}
		$o = wc_get_order( $id );
		ob_start();
		CODCRM_Work_Queue::render_row( $o, CODCRM_Tasks::next_open_for_orders( [ $id ] )[ $id ] ?? null );
		wp_send_json_success( [ 'row' => ob_get_clean(), 'changed' => $res ] );
	}

	/** @return string[]|WP_Error champs modifiés */
	public static function save( WC_Order $o, array $d ) {
		$locked  = self::is_locked( $o );
		$manager = current_user_can( CODCRM_Agents::CAP_MANAGE );
		$uid     = get_current_user_id();
		$changed = [];

		// ── Validation (rien n'est écrit avant) ──
		$client = null;
		if ( ! $locked ) {
			$first = sanitize_text_field( (string) ( $d['first'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $d['last'] ?? '' ) );
			$phone = sanitize_text_field( (string) ( $d['phone'] ?? '' ) );
			$ph2   = sanitize_text_field( (string) ( $d['phone2'] ?? '' ) );
			$state = sanitize_text_field( (string) ( $d['state'] ?? '' ) );
			$email = sanitize_email( (string) ( $d['email'] ?? '' ) );
			if ( '' === CODCRM_Work_Queue::norm_phone( $phone ) ) {
				return new WP_Error( 'phone', __( 'Téléphone invalide (8 chiffres).', 'cod-tracker-crm' ) );
			}
			if ( '' !== $ph2 && '' === CODCRM_Work_Queue::norm_phone( $ph2 ) ) {
				return new WP_Error( 'phone2', __( 'Téléphone 2 invalide (8 chiffres).', 'cod-tracker-crm' ) );
			}
			if ( '' !== $state && $state !== $o->get_billing_state() && ! isset( ( WC()->countries->get_states( 'TN' ) ?: [] )[ $state ] ) ) {
				return new WP_Error( 'state', __( 'Gouvernorat invalide.', 'cod-tracker-crm' ) );
			}
			$items = [];
			foreach ( (array) ( $d['items'] ?? [] ) as $it ) {
				$qty   = (int) ( $it['qty'] ?? 0 );
				$price = (float) ( $it['price'] ?? -1 );
				$pid   = absint( $it['product_id'] ?? 0 );
				if ( ! $pid || $qty < 1 || $price < 0 || ! wc_get_product( absint( $it['variation_id'] ?? 0 ) ?: $pid ) ) {
					return new WP_Error( 'items', __( 'Produit, quantité ou prix invalide.', 'cod-tracker-crm' ) );
				}
				$items[] = [ 'item_id' => absint( $it['item_id'] ?? 0 ), 'product_id' => $pid, 'variation_id' => absint( $it['variation_id'] ?? 0 ), 'qty' => $qty, 'price' => $price ];
			}
			if ( ! $items ) {
				return new WP_Error( 'items', __( 'Ajoutez au moins un produit.', 'cod-tracker-crm' ) );
			}
			$ship = max( 0.0, (float) ( $d['shipping'] ?? 0 ) );
			$client = compact( 'first', 'last', 'phone', 'ph2', 'state', 'email', 'items', 'ship' );
		}
		$status = sanitize_text_field( (string) ( $d['status'] ?? '' ) );
		if ( '' !== $status && ! isset( wc_get_order_statuses()[ $status ] ) ) {
			return new WP_Error( 'status', __( 'Statut invalide.', 'cod-tracker-crm' ) );
		}

		// ── Client / produits / frais / transporteur (commande non synchronisée) ──
		if ( $client ) {
			$map = [
				'first_name' => [ $o->get_billing_first_name(), $client['first'], 'set_billing_first_name' ],
				'last_name'  => [ $o->get_billing_last_name(), $client['last'], 'set_billing_last_name' ],
				'phone'      => [ $o->get_billing_phone(), $client['phone'], 'set_billing_phone' ],
				'state'      => [ $o->get_billing_state(), $client['state'], 'set_billing_state' ],
				'address'    => [ $o->get_billing_address_1(), sanitize_text_field( (string) ( $d['address'] ?? '' ) ), 'set_billing_address_1' ],
				'email'      => [ $o->get_billing_email(), $client['email'], 'set_billing_email' ],
			];
			foreach ( $map as $label => [ $old, $new, $setter ] ) {
				if ( (string) $old !== (string) $new ) {
					$o->$setter( $new );
					$changed[] = $label;
				}
			}
			// Livraison = même contact que la facturation (formulaire COD).
			$o->set_shipping_first_name( $client['first'] );
			$o->set_shipping_last_name( $client['last'] );
			$o->set_shipping_phone( $client['phone'] );
			$o->set_shipping_state( $client['state'] );
			$o->set_shipping_address_1( $o->get_billing_address_1() );
			if ( (string) $o->get_meta( self::META_PHONE2 ) !== $client['ph2'] ) {
				$o->update_meta_data( self::META_PHONE2, $client['ph2'] );
				$changed[] = 'phone2';
			}
			$carrier = sanitize_key( (string) ( $d['carrier'] ?? '' ) );
			if ( $carrier !== (string) $o->get_meta( '_wcmcs_forced_carrier' ) ) {
				'' === $carrier ? $o->delete_meta_data( '_wcmcs_forced_carrier' ) : $o->update_meta_data( '_wcmcs_forced_carrier', $carrier );
				$changed[] = 'carrier';
			}

			$o->save();
			if ( self::sync_items( $o, $client['items'] ) ) {
				$changed[] = 'items';
			}
			$o   = wc_get_order( $o->get_id() );
			$cur = 0.0;
			$sh  = $o->get_items( 'shipping' );
			foreach ( $sh as $s ) {
				$cur += (float) $s->get_total();
			}
			if ( abs( $cur - $client['ship'] ) > 0.0005 ) {
				if ( $sh ) {
					$first_item = reset( $sh );
					$first_item->set_total( (string) $client['ship'] );
					$first_item->save();
					foreach ( array_slice( $sh, 1, null, true ) as $extra ) {
						$extra->set_total( '0' );
						$extra->save();
					}
				} else {
					$s = new WC_Order_Item_Shipping();
					$s->set_method_title( __( 'Livraison', 'cod-tracker-crm' ) );
					$s->set_total( (string) $client['ship'] );
					$o->add_item( $s );
				}
				$changed[] = 'shipping';
			}
			$coupon = wc_format_coupon_code( (string) ( $d['coupon'] ?? '' ) );
			if ( '' !== $coupon && ! in_array( $coupon, $o->get_coupon_codes(), true ) ) {
				$r = $o->apply_coupon( $coupon );
				if ( is_wp_error( $r ) ) {
					return new WP_Error( 'coupon', $r->get_error_message() );
				}
				$changed[] = 'coupon';
			}
			$o->calculate_totals( false );
			$o->save();
		}

		// ── Toujours modifiable : note client, échange, agent, note privée, relance, statut ──
		$o = wc_get_order( $o->get_id() );
		$cn = sanitize_textarea_field( (string) ( $d['note'] ?? $o->get_customer_note() ) );
		if ( $cn !== $o->get_customer_note() ) {
			$o->set_customer_note( $cn );
			$changed[] = 'note_client';
		}
		$ex = ! empty( $d['exchange'] ) ? '1' : '';
		if ( $ex !== (string) $o->get_meta( self::META_EXCHANGE ) ) {
			'' === $ex ? $o->delete_meta_data( self::META_EXCHANGE ) : $o->update_meta_data( self::META_EXCHANGE, $ex );
			$changed[] = 'exchange';
		}
		$o->save();

		if ( $manager && isset( $d['agent'] ) && (int) $d['agent'] !== CODCRM_Agents::agent_of( $o ) ) {
			if ( CODCRM_Agents::assign( $o, absint( $d['agent'] ), $uid, 'agent', '', 'manual' ) ) {
				$changed[] = 'agent';
			}
		}
		$private = sanitize_textarea_field( (string) ( $d['private_note'] ?? '' ) );
		if ( '' !== $private ) {
			$o->add_order_note( '[COD] ' . $private . ' — ' . wp_get_current_user()->display_name );
			$changed[] = 'note_privee';
		}
		if ( ! empty( $d['reminder'] ) ) {
			$ts  = strtotime( (string) ( $d['reminder_at'] ?? '' ) );
			$due = $ts && $ts > time() - DAY_IN_SECONDS ? wp_date( 'Y-m-d H:i:s', $ts ) : wp_date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS );
			CODCRM_Tasks::create( $o->get_id(), CODCRM_Agents::agent_of( $o ) ?: $uid, CODCRM_Tasks::KIND_CALLBACK, $due, __( 'Relance depuis la fiche commande', 'cod-tracker-crm' ), $uid );
			$changed[] = 'relance';
		}
		if ( '' !== $status && $status !== 'wc-' . $o->get_status() ) {
			$o->update_status( $status, '[COD] ' . wp_get_current_user()->display_name . ' : ' );
			$changed[] = 'status';
		}

		if ( $changed ) {
			CODCRM_Events::add( $o->get_id(), [
				'type'     => 'note',
				'note'     => sprintf( /* translators: %s champs */ __( 'Commande modifiée : %s', 'cod-tracker-crm' ), implode( ', ', $changed ) ),
				'actor_id' => $uid,
				'source'   => 'agent',
			] );
		}
		return $changed;
	}

	/** Aligne les articles de la commande sur la liste reçue. @return bool modifié */
	private static function sync_items( WC_Order $o, array $items ): bool {
		$dirty = false;
		$keep  = [];
		foreach ( $items as $it ) {
			$existing = $it['item_id'] ? $o->get_item( $it['item_id'] ) : null;
			if ( $existing instanceof WC_Order_Item_Product && (int) $existing->get_variation_id() === $it['variation_id'] && (int) $existing->get_product_id() === $it['product_id'] ) {
				$old_qty   = (int) $existing->get_quantity();
				$old_price = $old_qty ? (float) $existing->get_subtotal() / $old_qty : 0.0;
				if ( $old_qty !== $it['qty'] || abs( $old_price - $it['price'] ) > 0.0005 ) {
					$existing->set_quantity( $it['qty'] );
					$existing->set_subtotal( (string) ( $it['price'] * $it['qty'] ) );
					$existing->set_total( (string) ( $it['price'] * $it['qty'] ) );
					$existing->save();
					wc_maybe_adjust_line_item_product_stock( $existing );
					$dirty = true;
				}
				$keep[] = $existing->get_id();
				continue;
			}
			$product = wc_get_product( $it['variation_id'] ?: $it['product_id'] );
			$new_id  = $o->add_product( $product, $it['qty'], [ 'subtotal' => $it['price'] * $it['qty'], 'total' => $it['price'] * $it['qty'] ] );
			$o->save();
			$new = $o->get_item( $new_id );
			if ( $new instanceof WC_Order_Item_Product ) {
				wc_maybe_adjust_line_item_product_stock( $new );
			}
			$keep[] = (int) $new_id;
			$dirty  = true;
		}
		foreach ( $o->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && ! in_array( $item->get_id(), $keep, true ) ) {
				wc_maybe_adjust_line_item_product_stock( $item, 0 );
				$o->remove_item( $item->get_id() );
				$dirty = true;
			}
		}
		if ( $dirty ) {
			$o->save();
		}
		return $dirty;
	}
}
