<?php
defined( 'ABSPATH' ) || exit;

/**
 * Historique client (par téléphone, 8 derniers chiffres) : nombre de commandes, taux de livraison,
 * taux de réponse aux appels, et liste des commandes au survol dans « Mes suivis ».
 * Calculs groupés : 2 requêtes pour toute la page, quel que soit le nombre de lignes.
 */
class CODCRM_Customers {

	/** Résultats d'appel où le client a décroché. */
	const ANSWERED = [ 'joint_confirme', 'reporte', 'refus', 'annulation_demandee' ];

	/** @var array<string,array> cache de la requête en cours */
	private static $cache = [];

	public static function init(): void {
		add_action( 'wp_ajax_codcrm_client_history', [ __CLASS__, 'ajax_history' ] );
	}

	private static function hpos(): bool {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** Expression SQL : 8 derniers chiffres du téléphone. */
	private static function key_sql( string $col ): string {
		return "RIGHT(REGEXP_REPLACE({$col}, '[^0-9]', ''), 8)";
	}

	/**
	 * @param string[] $phones téléphones bruts
	 * @return array<string,array{orders:int,delivered:int,returned:int,calls:int,answered:int}> indexé par 8 chiffres
	 */
	public static function stats_for_phones( array $phones ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( array_map( [ 'CODCRM_Work_Queue', 'norm_phone' ], array_map( 'strval', $phones ) ) ) ) );
		$out  = [];
		$need = [];
		foreach ( $keys as $k ) {
			if ( isset( self::$cache[ $k ] ) ) {
				$out[ $k ] = self::$cache[ $k ];
			} else {
				$need[] = $k;
			}
		}
		if ( ! $need || ! self::hpos() ) {
			return $out;
		}

		$o   = $wpdb->prefix . 'wc_orders';
		$a   = $wpdb->prefix . 'wc_order_addresses';
		$m   = $wpdb->prefix . 'wc_orders_meta';
		$e   = CODCRM_Installer::table_events();
		$in  = implode( ',', array_fill( 0, count( $need ), '%s' ) );
		$def = implode( ',', array_fill( 0, count( CODCRM_Returns::DEFINITIVE ), '%s' ) );
		$key = self::key_sql( 'a.phone' );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT p, COUNT(*) AS orders,
				SUM( outcome = 'returned' ) AS returned,
				SUM( outcome = 'delivered' ) AS delivered
			FROM (
				SELECT {$key} AS p,
					CASE
						WHEN st.meta_value IN ({$def}) OR o.status = 'wc-wcmcs-returned' THEN 'returned'
						WHEN st.meta_value IN ('LIVRES','LIVRES_PAYES') OR ( o.status IN ('wc-wcmcs-delivered','wc-completed') AND COALESCE(st.meta_value,'') NOT LIKE 'RETOUR%%' ) THEN 'delivered'
						ELSE 'open'
					END AS outcome
				FROM {$o} o
				JOIN {$a} a ON a.order_id = o.id AND a.address_type = 'billing'
				LEFT JOIN {$m} st ON st.order_id = o.id AND st.meta_key = '_codcrm_stage'
				WHERE o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft','wc-checkout-draft')
					AND {$key} IN ({$in})
			) t GROUP BY p",
			array_merge( CODCRM_Returns::DEFINITIVE, $need )
		) );
		foreach ( $need as $k ) {
			$out[ $k ] = [ 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'calls' => 0, 'answered' => 0 ];
		}
		foreach ( (array) $rows as $r ) {
			$out[ $r->p ]['orders']    = (int) $r->orders;
			$out[ $r->p ]['delivered'] = (int) $r->delivered;
			$out[ $r->p ]['returned']  = (int) $r->returned;
		}

		$ans   = implode( ',', array_fill( 0, count( self::ANSWERED ), '%s' ) );
		$calls = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT {$key} AS p, COUNT(*) AS calls, SUM( e.outcome IN ({$ans}) ) AS answered
			FROM {$e} e JOIN {$a} a ON a.order_id = e.order_id AND a.address_type = 'billing'
			WHERE e.type = 'contact' AND e.channel = 'call' AND {$key} IN ({$in})
			GROUP BY p",
			array_merge( self::ANSWERED, $need )
		) );
		foreach ( (array) $calls as $r ) {
			if ( isset( $out[ $r->p ] ) ) {
				$out[ $r->p ]['calls']    = (int) $r->calls;
				$out[ $r->p ]['answered'] = (int) $r->answered;
			}
		}
		foreach ( $need as $k ) {
			self::$cache[ $k ] = $out[ $k ];
		}
		return $out;
	}

	public static function reset_cache(): void {
		self::$cache = [];
	}

	/** Couleur d'un taux : vert ≥ 70, orange ≥ 40, rouge sinon. */
	private static function tone( int $pct ): string {
		return $pct >= 70 ? 'good' : ( $pct >= 40 ? 'mid' : 'bad' );
	}

	/** Badges HTML de la colonne Client (échappés). */
	public static function badges( WC_Order $order, ?array $s ): string {
		if ( ! $s || ! $s['orders'] ) {
			return '';
		}
		$h = [];
		if ( $s['orders'] >= 2 ) {
			$h[] = sprintf( '<span class="codcrm-cbadge is-reg" title="%s">★ %s</span>', esc_attr( sprintf( /* translators: %d nombre */ __( '%d commandes avec ce numéro', 'cod-tracker-crm' ), $s['orders'] ) ), esc_html__( 'Client régulier', 'cod-tracker-crm' ) );
		} else {
			$h[] = '<span class="codcrm-cbadge is-new">' . esc_html__( 'Nouveau client', 'cod-tracker-crm' ) . '</span>';
		}
		$closed = $s['delivered'] + $s['returned'];
		if ( $closed ) {
			$pct = (int) round( 100 * $s['delivered'] / $closed );
			$h[] = sprintf( '<span class="codcrm-cbadge is-%s" title="%s">🚚 %d%%</span>', self::tone( $pct ), esc_attr( sprintf( /* translators: 1 livrées 2 clôturées */ __( 'Taux de livraison : %1$d livrée(s) sur %2$d colis clôturé(s)', 'cod-tracker-crm' ), $s['delivered'], $closed ) ), $pct );
		}
		if ( $s['calls'] ) {
			$pct = (int) round( 100 * $s['answered'] / $s['calls'] );
			$h[] = sprintf( '<span class="codcrm-cbadge is-%s" title="%s">📞 %d%%</span>', self::tone( $pct ), esc_attr( sprintf( /* translators: 1 répondus 2 appels */ __( 'Taux de réponse : %1$d appel(s) répondu(s) sur %2$d', 'cod-tracker-crm' ), $s['answered'], $s['calls'] ) ), $pct );
		}
		if ( function_exists( 'wparabic_phone_strikes' ) ) {
			$strikes = (int) wparabic_phone_strikes( $order->get_billing_phone() );
			if ( $strikes ) {
				$h[] = sprintf( '<span class="codcrm-cbadge is-bad" title="%s">⚠ %d</span>', esc_attr__( 'Signalements (liste noire)', 'cod-tracker-crm' ), $strikes );
			}
		}
		return '<div class="codcrm-cbadges codcrm-client-hist" data-order="' . (int) $order->get_id() . '">' . implode( '', $h ) . '</div>';
	}

	/** Vignette d'un produit de commande (variation, sinon parent). */
	public static function item_image( WC_Order_Item_Product $item ): string {
		$p  = $item->get_product();
		$id = $p ? (int) $p->get_image_id() : 0;
		if ( ! $id && $item->get_product_id() ) {
			$id = (int) get_post_thumbnail_id( $item->get_product_id() );
		}
		return $id ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
	}

	/** @return array{total:int,orders:WC_Order[]} */
	public static function orders_for_phone( string $phone8, int $limit = 20 ): array {
		global $wpdb;
		if ( '' === $phone8 || ! self::hpos() ) {
			return [ 'total' => 0, 'orders' => [] ];
		}
		$o   = $wpdb->prefix . 'wc_orders';
		$a   = $wpdb->prefix . 'wc_order_addresses';
		$key = self::key_sql( 'a.phone' );
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT o.id FROM {$o} o JOIN {$a} a ON a.order_id = o.id AND a.address_type = 'billing'
			WHERE o.type = 'shop_order' AND o.status NOT IN ('trash','auto-draft','wc-checkout-draft') AND {$key} = %s
			ORDER BY o.date_created_gmt DESC, o.id DESC",
			$phone8
		) );
		return [
			'total'  => count( $ids ),
			'orders' => array_values( array_filter( array_map( 'wc_get_order', array_slice( array_map( 'intval', $ids ), 0, $limit ) ) ) ),
		];
	}

	public static function ajax_history(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! current_user_can( CODCRM_Agents::CAP_WORK ) || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'cod-tracker-crm' ) ], 403 );
		}
		$phone = $order->get_billing_phone();
		$res   = self::orders_for_phone( CODCRM_Work_Queue::norm_phone( $phone ) );
		$list  = admin_url( 'admin.php?page=wc-orders&s=' . rawurlencode( $phone ) );

		$h = '<div class="codcrm-h-head"><strong>' . esc_html( sprintf( /* translators: %d nombre */ __( 'Commandes totales : %d', 'cod-tracker-crm' ), $res['total'] ) ) . '</strong>'
			. '<a href="' . esc_url( $list ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir commandes', 'cod-tracker-crm' ) . '</a></div>';
		foreach ( $res['orders'] as $o ) {
			$stage = (string) $o->get_meta( CODCRM_Event_Collector::META_STAGE );
			if ( '' === $stage ) {
				$stage = CODCRM_Stage_Mapper::from_order_status( $o->get_status() );
			}
			$img = '';
			foreach ( $o->get_items() as $it ) {
				if ( $it instanceof WC_Order_Item_Product ) {
					$img = self::item_image( $it );
					break;
				}
			}
			$date = $o->get_date_created();
			$h   .= sprintf(
				'<a class="codcrm-h-card codcrm-h-order%s" href="%s"><span class="codcrm-h-row"><strong>#%s</strong>%s</span><span class="codcrm-h-date">%s</span><span class="codcrm-h-row">%s<strong>%s</strong></span></a>',
				$o->get_id() === $order->get_id() ? ' is-current' : '',
				esc_url( $o->get_edit_order_url() ),
				esc_html( $o->get_order_number() ),
				$img ? '<img src="' . esc_url( $img ) . '" alt="" />' : '',
				esc_html( $date ? $date->date_i18n( 'd M Y' ) : '' ),
				$stage ? '<span class="codcrm-pill" style="' . esc_attr( CODCRM_Stages::badge_style( CODCRM_Stages::color( $stage ) ) ) . '">' . esc_html( CODCRM_Stages::label( $stage ) ) . '</span>' : '<span></span>',
				esc_html( wc_format_decimal( $o->get_total(), 2 ) . ' TND' )
			);
		}
		if ( $res['total'] > count( $res['orders'] ) ) {
			$h .= '<p class="codcrm-muted">' . esc_html( sprintf( /* translators: %d reste */ __( '… et %d autre(s)', 'cod-tracker-crm' ), $res['total'] - count( $res['orders'] ) ) ) . '</p>';
		}
		wp_send_json_success( [ 'html' => $h ] );
	}
}
