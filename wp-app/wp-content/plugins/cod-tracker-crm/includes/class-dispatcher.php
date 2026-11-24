<?php
defined( 'ABSPATH' ) || exit;

/**
 * Répartition automatique des commandes entre les agents COD.
 *
 * - Mode : auto (répartition automatique) | manual (attribution manuelle).
 * - Méthode globale : equal (distribution équitable) | weighted (pourcentage).
 * - Horaires de travail par agent (plages par jour) : seuls les agents en
 *   service reçoivent des commandes ; sinon la commande attend.
 * - Règles (conditions → membres), évaluées par priorité croissante.
 * - Réattribution si l'agent ne traite pas la commande dans le délai.
 */
class CODCRM_Dispatcher {

	const META_WAITING     = '_codcrm_dispatch_waiting';
	const META_ASSIGNED_AT = '_codcrm_assigned_at';
	const META_ASSIGNED_BY = '_codcrm_assigned_by'; // auto | manual
	const META_REASSIGNED  = '_codcrm_reassign_count';
	const META_TRIED       = '_codcrm_tried_agents';

	const CRON_HOOK  = 'codcrm_dispatch_tick';
	const ASYNC_HOOK = 'codcrm_dispatch_async';

	/** Statuts où la commande n'est plus à attribuer. */
	const CLOSED_STATUSES = [ 'cancelled', 'refunded', 'failed', 'completed', 'wcmcs-delivered', 'wcmcs-returned', 'trash' ];

	/** Statuts « pas encore traitée » (non confirmée) pour la réattribution. */
	const UNTREATED_STATUSES = [ 'pending', 'processing', 'on-hold' ];

	/** Horloge injectable (tests). */
	public static $now = null;

	public static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	public static function init(): void {
		// À la création de la commande (articles déjà enregistrés).
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 20, 1 );
		// Commandes créées autrement (admin, REST) : différé de 60 s, idempotent.
		add_action( 'woocommerce_new_order', [ __CLASS__, 'on_new_order' ], 20, 1 );
		add_action( self::ASYNC_HOOK, [ __CLASS__, 'on_async' ], 10, 1 );
		// Filet de sécurité : colis créé pour une commande encore sans agent.
		add_action( 'wcmcs_order_synced', [ __CLASS__, 'on_checkout' ], 25, 1 );

		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'tick' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'codcrm_5min', self::CRON_HOOK );
		}
	}

	public static function cron_schedules( array $s ): array {
		$s['codcrm_5min'] = [ 'interval' => 300, 'display' => 'COD CRM — 5 minutes' ];
		return $s;
	}

	// ── Configuration ──────────────────────────────────────────

	public static function mode(): string {
		$mode = get_option( 'codcrm_assign_mode', null );
		if ( null === $mode ) { // migration depuis l'ancienne case « assignation automatique ».
			$mode = 'yes' === get_option( 'codcrm_auto_assign', 'no' ) ? 'auto' : 'manual';
		}
		return 'auto' === $mode ? 'auto' : 'manual';
	}

	public static function method(): string {
		return 'weighted' === get_option( 'codcrm_assign_method', 'equal' ) ? 'weighted' : 'equal';
	}

	public static function reassign_enabled(): bool {
		return 'no' !== get_option( 'codcrm_reassign_enabled', 'yes' );
	}

	public static function reassign_minutes(): int {
		return max( 5, min( 1440, (int) get_option( 'codcrm_reassign_minutes', 30 ) ) );
	}

	public static function reassign_max(): int {
		return max( 1, min( 10, (int) get_option( 'codcrm_reassign_max', 2 ) ) );
	}

	/**
	 * Configuration par agent (fusionnée avec les agents existants).
	 *
	 * @return array<int,array{active:bool,weight:int,days:array}>
	 */
	public static function agent_config(): array {
		$saved  = get_option( 'codcrm_agent_config', null );
		$legacy = get_option( 'codcrm_active_agents', null ); // migration.
		$out    = [];
		foreach ( CODCRM_Agents::get_agents() as $u ) {
			$id  = (int) $u->ID;
			$cfg = is_array( $saved ) && isset( $saved[ $id ] ) ? (array) $saved[ $id ] : [];
			$out[ $id ] = [
				'active' => isset( $cfg['active'] ) ? (bool) $cfg['active'] : ( ! is_array( $legacy ) || in_array( $id, array_map( 'intval', $legacy ), true ) ),
				'weight' => isset( $cfg['weight'] ) ? max( 0, min( 100, (int) $cfg['weight'] ) ) : 0,
				'days'   => isset( $cfg['days'] ) && is_array( $cfg['days'] ) ? $cfg['days'] : [],
			];
		}
		return $out;
	}

	/** @return int[] */
	public static function active_agents(): array {
		return array_keys( array_filter( self::agent_config(), static function ( $c ) { return $c['active']; } ) );
	}

	/** Agent en service à l'instant $ts ? (aucun horaire défini = toujours en service). */
	public static function on_duty( int $agent, int $ts, ?array $config = null ): bool {
		$config = $config ?? self::agent_config();
		$days   = $config[ $agent ]['days'] ?? [];
		if ( ! $days || ! array_filter( $days ) ) {
			return true;
		}
		$dt    = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
		$dow   = (int) $dt->format( 'N' ); // 1 = lundi … 7 = dimanche.
		$hm    = $dt->format( 'H:i' );
		foreach ( (array) ( $days[ $dow ] ?? [] ) as $range ) {
			if ( isset( $range[0], $range[1] ) && $range[0] <= $hm && $hm < $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array> règles triées par priorité croissante. */
	public static function rules(): array {
		$rules = get_option( 'codcrm_assign_rules', [] );
		$rules = is_array( $rules ) ? array_values( $rules ) : [];
		usort( $rules, static function ( $a, $b ) {
			return [ (int) ( $a['priority'] ?? 10 ), (int) ( $a['id'] ?? 0 ) ] <=> [ (int) ( $b['priority'] ?? 10 ), (int) ( $b['id'] ?? 0 ) ];
		} );
		return $rules;
	}

	// ── Conditions ─────────────────────────────────────────────

	/** @return int[] produits de la commande (parents des variations inclus). */
	private static function order_products( WC_Order $o ): array {
		$ids = [];
		foreach ( $o->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$ids[] = (int) $item->get_product_id();
			if ( $item->get_variation_id() ) {
				$ids[] = (int) $item->get_variation_id();
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** @return int[] catégories (product_cat) des produits de la commande. */
	private static function order_categories( WC_Order $o ): array {
		$cats = [];
		foreach ( $o->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $item->get_product_id() ) {
				$cats = array_merge( $cats, (array) wc_get_product_term_ids( (int) $item->get_product_id(), 'product_cat' ) );
			}
		}
		return array_values( array_unique( array_map( 'intval', $cats ) ) );
	}

	/** Transporteur du colis, sinon prédiction de MultiCarrier Sync. */
	private static function order_carrier( WC_Order $o ): string {
		$slug = (string) $o->get_meta( '_wcmcs_carrier_slug' );
		if ( '' === $slug && class_exists( 'WCMCS_Carrier_Detector' ) ) {
			try {
				$d = WCMCS_Carrier_Detector::detect( $o );
				if ( is_string( $d ) ) {
					$slug = $d;
				} elseif ( is_object( $d ) && method_exists( $d, 'get_slug' ) ) {
					$slug = (string) $d->get_slug();
				}
			} catch ( \Throwable $e ) {
				$slug = '';
			}
		}
		return $slug;
	}

	/** Conditions combinées par ET ; les valeurs d'une condition par OU. */
	public static function match_rule( array $rule, WC_Order $o ): bool {
		$conds = (array) ( $rule['conditions'] ?? [] );
		if ( ! $conds ) {
			return false; // une règle sans condition ne capte rien (sécurité).
		}
		foreach ( $conds as $c ) {
			$values = array_values( (array) ( $c['values'] ?? [] ) );
			switch ( $c['type'] ?? '' ) {
				case 'product':
					$ok = (bool) array_intersect( array_map( 'intval', $values ), self::order_products( $o ) );
					break;
				case 'category':
					$ok = (bool) array_intersect( array_map( 'intval', $values ), self::order_categories( $o ) );
					break;
				case 'state':
					$ok = in_array( (string) $o->get_billing_state(), array_map( 'strval', $values ), true );
					break;
				case 'carrier':
					$ok = in_array( self::order_carrier( $o ), array_map( 'strval', $values ), true );
					break;
				case 'total':
					$t   = (float) $o->get_total();
					$min = isset( $c['min'] ) && '' !== $c['min'] ? (float) $c['min'] : null;
					$max = isset( $c['max'] ) && '' !== $c['max'] ? (float) $c['max'] : null;
					$ok  = ( null === $min || $t >= $min ) && ( null === $max || $t <= $max ) && ( null !== $min || null !== $max );
					break;
				default:
					$ok = false;
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	// ── Choix de l'agent ───────────────────────────────────────

	private static function counts_key( int $ts ): string {
		return 'codcrm_assign_counts_' . wp_date( 'Ymd', $ts );
	}

	/** @return array<int,int> attributions automatiques du jour par agent. */
	public static function counts_today( int $ts ): array {
		$c = get_option( self::counts_key( $ts ), [] );
		return is_array( $c ) ? array_map( 'intval', $c ) : [];
	}

	private static function bump( int $agent, int $ts ): void {
		$key            = self::counts_key( $ts );
		$c              = self::counts_today( $ts );
		$c[ $agent ]    = ( $c[ $agent ] ?? 0 ) + 1;
		update_option( $key, $c, false );
	}

	/** Le moins chargé aujourd'hui ; égalité → rotation après le dernier servi. */
	private static function pick_equal( array $cands, array $counts ): int {
		sort( $cands );
		$min  = min( array_map( static function ( $a ) use ( $counts ) { return $counts[ $a ] ?? 0; }, $cands ) );
		$tied = array_values( array_filter( $cands, static function ( $a ) use ( $counts, $min ) { return ( $counts[ $a ] ?? 0 ) === $min; } ) );
		$last = (int) get_option( 'codcrm_rr_cursor', 0 );
		foreach ( $tied as $a ) {
			if ( $a > $last ) {
				return $a;
			}
		}
		return $tied[0];
	}

	/** Pourcentage : minimise attribuées / poids (poids 0 = exclu). */
	private static function pick_weighted( array $cands, array $counts, array $config ): int {
		$best = 0;
		$best_score = null;
		sort( $cands );
		foreach ( $cands as $a ) {
			$w = (int) ( $config[ $a ]['weight'] ?? 0 );
			if ( $w <= 0 ) {
				continue;
			}
			$score = ( ( $counts[ $a ] ?? 0 ) + 1 ) / $w;
			if ( null === $best_score || $score < $best_score ) {
				$best       = $a;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Choisit l'agent d'une commande (rien n'est modifié).
	 *
	 * @param int[] $exclude Agents à écarter (réattribution).
	 * @return array{agent:int,reason:string}
	 */
	public static function pick( WC_Order $o, int $ts, array $exclude = [] ): array {
		$config = self::agent_config();
		$active = array_values( array_diff( self::active_agents(), array_map( 'intval', $exclude ) ) );
		$counts = self::counts_today( $ts );
		$duty   = static function ( array $ids ) use ( $ts, $config ) {
			return array_values( array_filter( $ids, static function ( $a ) use ( $ts, $config ) { return CODCRM_Dispatcher::on_duty( (int) $a, $ts, $config ); } ) );
		};

		foreach ( self::rules() as $rule ) {
			if ( empty( $rule['active'] ) || ! self::match_rule( $rule, $o ) ) {
				continue;
			}
			$name  = (string) ( $rule['name'] ?? '' );
			$cands = $duty( array_values( array_intersect( array_map( 'intval', (array) ( $rule['agents'] ?? [] ) ), $active ) ) );
			if ( ! $cands ) {
				/* translators: %s nom de la règle */
				return [ 'agent' => 0, 'reason' => sprintf( __( 'En attente : aucun membre de la règle « %s » en service', 'cod-tracker-crm' ), $name ) ];
			}
			/* translators: %s nom de la règle */
			return [ 'agent' => self::pick_equal( $cands, $counts ), 'reason' => sprintf( __( 'Règle « %s »', 'cod-tracker-crm' ), $name ) ];
		}

		$cands = $duty( $active );
		if ( ! $cands ) {
			return [ 'agent' => 0, 'reason' => __( 'En attente : aucun agent en service', 'cod-tracker-crm' ) ];
		}
		if ( 'weighted' === self::method() ) {
			$a = self::pick_weighted( $cands, $counts, $config );
			return $a
				? [ 'agent' => $a, 'reason' => __( 'Répartition par pourcentage', 'cod-tracker-crm' ) ]
				: [ 'agent' => 0, 'reason' => __( 'En attente : aucun agent en service avec un pourcentage > 0', 'cod-tracker-crm' ) ];
		}
		return [ 'agent' => self::pick_equal( $cands, $counts ), 'reason' => __( 'Distribution équitable', 'cod-tracker-crm' ) ];
	}

	// ── Attribution ────────────────────────────────────────────

	private static function is_closed( WC_Order $o ): bool {
		return in_array( $o->get_status(), self::CLOSED_STATUSES, true );
	}

	/**
	 * Attribue automatiquement une commande sans agent.
	 *
	 * @return bool True si un agent a été attribué.
	 */
	public static function dispatch( WC_Order $o ): bool {
		if ( 'auto' !== self::mode() || CODCRM_Agents::agent_of( $o ) || self::is_closed( $o ) ) {
			return false;
		}
		// Commande abandonnée : on laisse au client le temps de finir de remplir le formulaire.
		if ( (int) $o->get_meta( CODCRM_Abandoned::META_UNTIL ) > self::now() ) {
			return false;
		}
		$ts  = self::now();
		$res = self::pick( $o, $ts );

		if ( ! $res['agent'] ) {
			if ( '1' !== (string) $o->get_meta( self::META_WAITING ) ) {
				$o->update_meta_data( self::META_WAITING, '1' );
				$o->save_meta_data();
				CODCRM_Events::add( $o->get_id(), [ 'type' => 'assignment', 'outcome' => 'waiting', 'note' => $res['reason'], 'source' => 'system' ] );
			}
			return false;
		}

		$o->delete_meta_data( self::META_WAITING );
		$o->save_meta_data();
		CODCRM_Agents::assign( $o, $res['agent'], 0, 'system', $res['reason'], 'auto' );
		self::bump( $res['agent'], $ts );
		update_option( 'codcrm_rr_cursor', $res['agent'], false );
		return true;
	}

	public static function on_checkout( $order ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		if ( $order ) {
			self::dispatch( $order );
		}
	}

	public static function on_new_order( $order_id ): void {
		if ( 'auto' !== self::mode() ) {
			return;
		}
		$args = [ (int) $order_id ];
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::ASYNC_HOOK, $args, 'codcrm' ) ) {
				as_schedule_single_action( time() + 60, self::ASYNC_HOOK, $args, 'codcrm' );
			}
		} elseif ( ! wp_next_scheduled( self::ASYNC_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 60, self::ASYNC_HOOK, $args );
		}
	}

	public static function on_async( $order_id ): void {
		$o = wc_get_order( (int) $order_id );
		if ( $o && 'shop_order' === $o->get_type() ) {
			self::dispatch( $o );
		}
	}

	// ── Tâche périodique : file d'attente + réattribution ──────

	public static function tick(): void {
		self::dispatch_waiting();
		self::reassign_due();
	}

	/** Commandes en attente d'un agent (7 derniers jours). @return int attribuées */
	public static function dispatch_waiting(): int {
		if ( 'auto' !== self::mode() ) {
			return 0;
		}
		$ids = wc_get_orders( [
			'limit'        => 200,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'date_created' => '>' . ( self::now() - 7 * DAY_IN_SECONDS ),
			'meta_query'   => [ [ 'key' => self::META_WAITING, 'value' => '1' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o ) {
				continue;
			}
			if ( self::is_closed( $o ) || CODCRM_Agents::agent_of( $o ) ) {
				$o->delete_meta_data( self::META_WAITING );
				$o->save_meta_data();
				continue;
			}
			$n += self::dispatch( $o ) ? 1 : 0;
		}
		return $n;
	}

	/** La commande a-t-elle été traitée depuis son attribution ? */
	public static function is_treated( WC_Order $o ): bool {
		if ( ! in_array( $o->get_status(), self::UNTREATED_STATUSES, true ) ) {
			return true; // confirmée, expédiée, annulée…
		}
		$since = (string) $o->get_meta( self::META_ASSIGNED_AT );
		global $wpdb;
		$t = CODCRM_Installer::table_events();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$t} WHERE order_id = %d AND type = 'contact' AND occurred_at >= %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$o->get_id(),
			'' !== $since ? $since : '1970-01-01 00:00:00'
		) );
	}

	/** Réattribue les commandes non traitées dans le délai. @return int réattribuées */
	public static function reassign_due(): int {
		if ( 'auto' !== self::mode() || ! self::reassign_enabled() ) {
			return 0;
		}
		$ts     = self::now();
		$mins   = self::reassign_minutes();
		$cutoff = wp_date( 'Y-m-d H:i:s', $ts - $mins * MINUTE_IN_SECONDS );

		$ids = wc_get_orders( [
			'limit'      => 200,
			'return'     => 'ids',
			'type'       => 'shop_order',
			'status'     => self::UNTREATED_STATUSES,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[ 'key' => self::META_ASSIGNED_BY, 'value' => 'auto' ],
				[ 'key' => self::META_ASSIGNED_AT, 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME' ],
			],
		] );

		$n = 0;
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o || self::is_treated( $o ) || (int) $o->get_meta( self::META_REASSIGNED ) >= self::reassign_max() ) {
				continue;
			}
			$current = CODCRM_Agents::agent_of( $o );
			$tried   = array_map( 'intval', (array) ( $o->get_meta( self::META_TRIED ) ?: [] ) );
			$tried[] = $current;
			$res     = self::pick( $o, $ts, array_values( array_unique( $tried ) ) );
			if ( ! $res['agent'] ) {
				continue; // personne d'autre en service : on retente au prochain passage.
			}

			$o->update_meta_data( self::META_TRIED, array_values( array_unique( $tried ) ) );
			$o->update_meta_data( self::META_REASSIGNED, (int) $o->get_meta( self::META_REASSIGNED ) + 1 );
			$o->save_meta_data();

			$note = sprintf(
				/* translators: 1 minutes 2 agent 3 raison */
				__( 'Réattribuée : non traitée en %1$d min par %2$s (%3$s)', 'cod-tracker-crm' ),
				$mins,
				CODCRM_Agents::agent_name( $current ) ?: '—',
				$res['reason']
			);
			CODCRM_Agents::assign( $o, $res['agent'], 0, 'system', $note, 'auto', 'reassigned' );
			self::bump( $res['agent'], $ts );
			$n++;
		}
		return $n;
	}
}
