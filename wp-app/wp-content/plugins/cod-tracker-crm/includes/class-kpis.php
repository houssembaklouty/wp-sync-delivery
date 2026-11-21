<?php
defined( 'ABSPATH' ) || exit;

/**
 * Indicateurs (KPIs) du suivi COD.
 *
 * Cohorte = commandes synchronisées (colis créé) créées pendant la période.
 * Les lignes brutes sont lues en une requête (HPOS ou stockage legacy) puis
 * mises en cache 10 min ; les filtres (transporteur, agent, gouvernorat) et les
 * agrégats sont calculés en mémoire.
 */
class CODCRM_KPIs {

	const CACHE_TTL = 600;

	/** Étapes « bloquées » si elles durent trop longtemps. */
	const STUCK_STAGES = [
		CODCRM_Stages::ENLEVES, CODCRM_Stages::AU_DEPOT, CODCRM_Stages::TRANSFERT,
		CODCRM_Stages::RENDEZ_VOUS, CODCRM_Stages::NON_RECUS, CODCRM_Stages::EN_ANOMALIE,
	];

	public static function init(): void {
		add_action( 'codcrm_daily', [ __CLASS__, 'create_stuck_tasks' ] );
		if ( ! wp_next_scheduled( 'codcrm_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'codcrm_daily' );
		}
	}

	/** Jours avant qu'un colis non enlevé par le transporteur soit signalé. */
	public static function pickup_days(): int {
		return max( 1, (int) apply_filters( 'codcrm_pickup_days', (int) get_option( 'codcrm_pickup_days', 2 ) ) );
	}

	public static function stuck_days(): int {
		return max( 1, (int) apply_filters( 'codcrm_stuck_days', (int) get_option( 'codcrm_stuck_days', 3 ) ) );
	}

	// ── Lecture des lignes brutes ──────────────────────────────

	private static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * @param string $from Y-m-d (heure du site).
	 * @param string $to   Y-m-d (heure du site).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( string $from, string $to, bool $refresh = false ): array {
		$key = 'codcrm_kpi_' . md5( $from . '|' . $to );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$from_gmt = get_gmt_from_date( $from . ' 00:00:00' );
		$to_gmt   = get_gmt_from_date( $to . ' 23:59:59' );

		// Valeur la plus récente de chaque meta (robuste à d'éventuels doublons).
		$pick = static function ( string $meta_key, string $alias, string $kcol, string $vcol, string $idcol ) {
			return "SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN m.{$kcol} = '{$meta_key}' THEN m.{$vcol} END ORDER BY m.{$idcol} DESC SEPARATOR '|'), '|', 1) AS {$alias}";
		};

		if ( self::hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$addr   = $wpdb->prefix . 'wc_order_addresses';
			$sql    = "SELECT o.id AS id, o.status AS status, o.date_created_gmt AS created, a.state AS state,
					" . $pick( '_wcmcs_carrier_slug', 'carrier', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_wcmcs_sync_status', 'sync', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_codcrm_stage', 'stage', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_codcrm_stage_since', 'since', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_codcrm_attempts', 'attempts', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_codcrm_failed', 'failed', 'meta_key', 'meta_value', 'id' ) . ",
					" . $pick( '_codcrm_agent_id', 'agent', 'meta_key', 'meta_value', 'id' ) . "
				FROM {$orders} o
				LEFT JOIN {$meta} m ON m.order_id = o.id AND m.meta_key IN ('_wcmcs_carrier_slug','_wcmcs_sync_status','_codcrm_stage','_codcrm_stage_since','_codcrm_attempts','_codcrm_failed','_codcrm_agent_id')
				LEFT JOIN {$addr} a ON a.order_id = o.id AND a.address_type = 'billing'
				WHERE o.type = 'shop_order' AND o.date_created_gmt BETWEEN %s AND %s
				GROUP BY o.id, o.status, o.date_created_gmt, a.state
				HAVING sync = 'synced'";
		} else {
			$posts = $wpdb->posts;
			$meta  = $wpdb->postmeta;
			$sql   = "SELECT p.ID AS id, p.post_status AS status, p.post_date_gmt AS created,
					" . $pick( '_billing_state', 'state', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_wcmcs_carrier_slug', 'carrier', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_wcmcs_sync_status', 'sync', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_codcrm_stage', 'stage', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_codcrm_stage_since', 'since', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_codcrm_attempts', 'attempts', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_codcrm_failed', 'failed', 'meta_key', 'meta_value', 'meta_id' ) . ",
					" . $pick( '_codcrm_agent_id', 'agent', 'meta_key', 'meta_value', 'meta_id' ) . "
				FROM {$posts} p
				LEFT JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key IN ('_billing_state','_wcmcs_carrier_slug','_wcmcs_sync_status','_codcrm_stage','_codcrm_stage_since','_codcrm_attempts','_codcrm_failed','_codcrm_agent_id')
				WHERE p.post_type = 'shop_order' AND p.post_date_gmt BETWEEN %s AND %s
				GROUP BY p.ID, p.post_status, p.post_date_gmt
				HAVING sync = 'synced'";
		}

		$raw  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $from_gmt, $to_gmt ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = [];
		foreach ( $raw as $r ) {
			$rows[ (int) $r['id'] ] = [
				'id'       => (int) $r['id'],
				'created'  => (string) $r['created'],
				'status'   => preg_replace( '/^wc-/', '', (string) $r['status'] ),
				'carrier'  => (string) $r['carrier'],
				'state'    => (string) $r['state'],
				'stage'    => (string) $r['stage'],
				'since'    => (string) $r['since'],
				'attempts' => (int) $r['attempts'],
				'failed'   => (int) $r['failed'],
				'agent'    => (int) $r['agent'],
			];
		}

		// Date de création du colis et de livraison (journal) → délai de livraison.
		$ids   = array_keys( $rows );
		$table = CODCRM_Installer::table_events();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$ph  = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$picked_in = "'" . implode( "','", CODCRM_Stages::PICKED ) . "'";
			$ev  = $wpdb->get_results( $wpdb->prepare(
				"SELECT order_id, MIN(occurred_at) AS first_at,
					MIN(CASE WHEN stage IN ('LIVRES','LIVRES_PAYES') THEN occurred_at END) AS delivered_at,
					MAX(CASE WHEN stage IN ({$picked_in}) OR stage LIKE 'RETOUR\\_%' THEN 1 ELSE 0 END) AS picked
				 FROM {$table} WHERE type = 'carrier_state' AND order_id IN ({$ph}) GROUP BY order_id", // phpcs:ignore WordPress.DB.PreparedSQL
				$chunk
			), ARRAY_A );
			foreach ( (array) $ev as $e ) {
				$rows[ (int) $e['order_id'] ]['first_at']     = (string) $e['first_at'];
				$rows[ (int) $e['order_id'] ]['delivered_at'] = (string) $e['delivered_at'];
				$rows[ (int) $e['order_id'] ]['picked']       = (int) $e['picked'];
			}
		}

		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	// ── Classification ─────────────────────────────────────────

	/**
	 * delivered | returned | cancelled | open (enlevé, en transit) | not_picked
	 * (créé chez le transporteur mais jamais récupéré par la société de livraison).
	 */
	public static function outcome( array $r ): string {
		$stage = $r['stage'];
		if ( in_array( $stage, [ CODCRM_Stages::LIVRES, CODCRM_Stages::LIVRES_PAYES ], true ) || 'wcmcs-delivered' === $r['status'] ) {
			return 'delivered';
		}
		if ( 0 === strpos( $stage, 'RETOUR_' ) || 'wcmcs-returned' === $r['status'] ) {
			return 'returned';
		}
		if ( in_array( $stage, [ CODCRM_Stages::SUPPRIME, CODCRM_Stages::ANNULEE ], true ) || 'cancelled' === $r['status'] ) {
			return 'cancelled';
		}
		// Enlevé = un état du transporteur l'atteste (journal) ou son étape actuelle l'implique.
		if ( ! empty( $r['picked'] ) || CODCRM_Stages::is_picked_stage( $stage ) ) {
			return 'open';
		}
		return 'not_picked';
	}

	/** Filtres : carrier, agent ('all'|'none'|id), state. */
	public static function filter( array $rows, array $f ): array {
		return array_filter( $rows, static function ( $r ) use ( $f ) {
			if ( ! empty( $f['carrier'] ) && $r['carrier'] !== $f['carrier'] ) {
				return false;
			}
			if ( ! empty( $f['state'] ) && $r['state'] !== $f['state'] ) {
				return false;
			}
			$agent = $f['agent'] ?? 'all';
			if ( 'none' === $agent && $r['agent'] ) {
				return false;
			}
			if ( ctype_digit( (string) $agent ) && (int) $agent > 0 && $r['agent'] !== (int) $agent ) {
				return false;
			}
			return true;
		} );
	}

	/** Compteurs + taux d'un groupe de lignes. */
	private static function tally( array $rows ): array {
		$t = [ 'total' => 0, 'delivered' => 0, 'returned' => 0, 'open' => 0, 'not_picked' => 0, 'cancelled' => 0, 'with_failed' => 0, 'recovered' => 0 ];
		foreach ( $rows as $r ) {
			$o = self::outcome( $r );
			$t['total']++;
			$t[ $o ]++;
			if ( $r['failed'] >= 1 ) {
				$t['with_failed']++;
				if ( 'delivered' === $o ) {
					$t['recovered']++;
				}
			}
		}
		// Enlevés = livrés + retournés + en transit. Les taux ne portent QUE sur les colis
		// enlevés : un colis jamais récupéré par la société de livraison n'y entre pas.
		$t['picked']        = $t['delivered'] + $t['returned'] + $t['open'];
		$closed             = $t['delivered'] + $t['returned'];
		$t['delivery_rate'] = $closed ? round( 100 * $t['delivered'] / $closed, 1 ) : null;
		$t['return_rate']   = $closed ? round( 100 * $t['returned'] / $closed, 1 ) : null;
		$t['recovery_rate'] = $t['with_failed'] ? round( 100 * $t['recovered'] / $t['with_failed'], 1 ) : null;
		return $t;
	}

	/** @return array<string,array> clé de groupe => tally (trié par volume). */
	private static function group( array $rows, string $field ): array {
		$groups = [];
		foreach ( $rows as $r ) {
			$groups[ (string) $r[ $field ] ][] = $r;
		}
		$out = [];
		foreach ( $groups as $k => $g ) {
			$out[ $k ] = self::tally( $g );
		}
		uasort( $out, static function ( $a, $b ) { return $b['total'] <=> $a['total']; } );
		return $out;
	}

	/**
	 * Tous les indicateurs pour un jeu de lignes filtré.
	 */
	public static function compute( array $rows ): array {
		$overall = self::tally( $rows );

		// Délai moyen de livraison (jours) + tournées moyennes des colis livrés.
		$days = [];
		$hist = [ '0' => 0, '1' => 0, '2' => 0, '3+' => 0 ];
		foreach ( $rows as $r ) {
			if ( 'delivered' !== self::outcome( $r ) ) {
				continue;
			}
			// Délai et tournées ne sont fiables que si le transporteur fournit un historique
			// daté (≥ 2 étapes datées différentes) ; sinon début = fin = date du backfill.
			if ( ! empty( $r['first_at'] ) && ! empty( $r['delivered_at'] ) && $r['delivered_at'] > $r['first_at'] ) {
				$days[] = ( strtotime( $r['delivered_at'] ) - strtotime( $r['first_at'] ) ) / DAY_IN_SECONDS;
				$a      = $r['attempts'];
				$hist[ $a >= 3 ? '3+' : (string) $a ]++;
			}
		}

		// Colis bloqués.
		$limit  = current_time( 'timestamp' ) - self::stuck_days() * DAY_IN_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$stuck  = [];
		foreach ( $rows as $r ) {
			if ( 'open' !== self::outcome( $r ) || ! in_array( $r['stage'], self::STUCK_STAGES, true ) || '' === $r['since'] ) {
				continue;
			}
			$ts = strtotime( $r['since'] );
			if ( $ts && $ts < $limit ) {
				$stuck[] = $r + [ 'days' => (int) floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS ) ]; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			}
		}
		usort( $stuck, static function ( $a, $b ) { return $b['days'] <=> $a['days']; } );

		// Colis non enlevés depuis plus de N jours (créés chez le transporteur, jamais récupérés).
		$pickup_limit = time() - self::pickup_days() * DAY_IN_SECONDS;
		$pickup       = [];
		foreach ( $rows as $r ) {
			if ( 'not_picked' !== self::outcome( $r ) || '' === $r['created'] ) {
				continue;
			}
			$ts = strtotime( $r['created'] . ' UTC' );
			if ( $ts && $ts < $pickup_limit ) {
				$pickup[] = $r + [ 'days' => (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) ];
			}
		}
		usort( $pickup, static function ( $a, $b ) { return $b['days'] <=> $a['days']; } );

		// Statut WooCommerce en retard sur la réalité transporteur.
		$lagging = 0;
		foreach ( $rows as $r ) {
			$o = self::outcome( $r );
			if ( in_array( $o, [ 'delivered', 'returned' ], true ) && in_array( $r['status'], [ 'wcmcs-synced', 'wcmcs-packed', 'processing', 'on-hold', 'wcmcs-confirmed' ], true ) ) {
				$lagging++;
			}
		}

		return [
			'overall'      => $overall,
			'avg_days'     => $days ? round( array_sum( $days ) / count( $days ), 1 ) : null,
			'avg_days_n'   => count( $days ),
			'attempt_hist' => $hist,
			'by_carrier'   => self::group( $rows, 'carrier' ),
			'by_state'     => self::group( $rows, 'state' ),
			'by_agent'     => self::group( $rows, 'agent' ),
			'stuck'        => $stuck,
			'pickup'       => $pickup,
			'lagging'      => $lagging,
		];
	}

	/** Activité des agents sur la période : contacts par agent / canal / résultat. */
	public static function agent_activity( string $from, string $to ): array {
		global $wpdb;
		$table = CODCRM_Installer::table_events();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT actor_id, channel, outcome, COUNT(*) AS n FROM {$table}
			 WHERE type = 'contact' AND actor_id > 0 AND occurred_at BETWEEN %s AND %s
			 GROUP BY actor_id, channel, outcome", // phpcs:ignore WordPress.DB.PreparedSQL
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		), ARRAY_A );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$a = (int) $r['actor_id'];
			$out[ $a ] = $out[ $a ] ?? [ 'total' => 0, 'call' => 0, 'whatsapp' => 0, 'sms' => 0, 'joint' => 0, 'unreachable' => 0 ];
			$out[ $a ]['total'] += (int) $r['n'];
			if ( isset( $out[ $a ][ $r['channel'] ] ) ) {
				$out[ $a ][ $r['channel'] ] += (int) $r['n'];
			}
			if ( 'joint_confirme' === $r['outcome'] ) {
				$out[ $a ]['joint'] += (int) $r['n'];
			} elseif ( 'injoignable' === $r['outcome'] ) {
				$out[ $a ]['unreachable'] += (int) $r['n'];
			}
		}
		uasort( $out, static function ( $a, $b ) { return $b['total'] <=> $a['total']; } );
		return $out;
	}

	// ── Colis bloqués → tâches (cron quotidien) ────────────────

	public static function create_stuck_tasks(): void {
		$today = current_time( 'Y-m-d' );
		$rows  = self::rows( gmdate( 'Y-m-d', strtotime( $today ) - 120 * DAY_IN_SECONDS ), $today, true );
		$data  = self::compute( $rows );

		foreach ( $data['stuck'] as $r ) {
			CODCRM_Tasks::create(
				(int) $r['id'],
				(int) $r['agent'],
				CODCRM_Tasks::KIND_STUCK,
				current_time( 'mysql' ),
				sprintf( /* translators: 1 étape 2 jours */ __( 'Bloqué « %1$s » depuis %2$d jours', 'cod-tracker-crm' ), CODCRM_Stages::label( $r['stage'] ), (int) $r['days'] )
			);
		}

		foreach ( $data['pickup'] as $r ) {
			CODCRM_Tasks::create(
				(int) $r['id'],
				(int) $r['agent'],
				CODCRM_Tasks::KIND_PICKUP,
				current_time( 'mysql' ),
				sprintf( /* translators: %d jours */ __( 'Colis non enlevé par le transporteur depuis %d jours — relancer la société de livraison', 'cod-tracker-crm' ), (int) $r['days'] )
			);
		}
	}
}
