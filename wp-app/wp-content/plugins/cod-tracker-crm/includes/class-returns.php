<?php
defined( 'ABSPATH' ) || exit;

/**
 * Colis retournés : réception physique (scan « Retour reçu » ou « Marquer reçu »),
 * remise en stock, et alerte quand un retour définitif annoncé par le transporteur
 * n'est toujours pas revenu à la boutique après N jours (colis potentiellement perdu).
 */
class CODCRM_Returns {

	const META_RECEIVED    = '_codcrm_return_received';
	const META_RECEIVED_AT = '_codcrm_return_received_at';

	/** États du transporteur qui annoncent un retour terminé (le colis doit revenir). */
	const DEFINITIVE = [ CODCRM_Stages::RETOUR_EXPEDITEUR, CODCRM_Stages::RETOUR_DEFINITIF, CODCRM_Stages::RETOUR_PAYE ];

	/** Activité d'expédition : si elle suit une réception, un nouveau cycle (réexpédition) a commencé. */
	const FORWARD = [
		CODCRM_Stages::EN_ATTENTE, CODCRM_Stages::A_ENLEVER, CODCRM_Stages::ENLEVES, CODCRM_Stages::AU_DEPOT,
		CODCRM_Stages::TRANSFERT, CODCRM_Stages::EN_COURS_LIVRAISON, CODCRM_Stages::RENDEZ_VOUS, CODCRM_Stages::SYNCHRONISEE,
	];

	/** Compteur stocké (option) : recalculé par le cron, jamais pendant le rendu d'une page. */
	const CACHE_COUNT = 'codcrm_returns_missing_count';
	const OPT_NOTIFIED = 'codcrm_returns_notified';
	const RECOUNT_HOOK = 'codcrm_returns_recount';

	public static function init(): void {
		add_action( 'codcrm_daily', [ __CLASS__, 'daily' ], 20 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_action( self::RECOUNT_HOOK, [ __CLASS__, 'recount' ] );
		add_action( 'admin_post_codcrm_return_received', [ __CLASS__, 'handle_mark_received' ] );

		// Point de départ de la détection : les retours plus anciens que l'activation de
		// la fonction ont déjà été traités à la main — on n'alerte pas sur l'historique.
		if ( false === get_option( 'codcrm_returns_since' ) ) {
			add_option( 'codcrm_returns_since', current_time( 'mysql' ), '', true );
		}
		if ( false === get_option( self::CACHE_COUNT ) ) {
			self::schedule_recount();
		}
	}

	public static function days(): int {
		return max( 1, min( 60, (int) get_option( 'codcrm_return_days', 5 ) ) );
	}

	public static function since(): string {
		return (string) get_option( 'codcrm_returns_since', current_time( 'mysql' ) );
	}

	/** Dernier événement du journal d'une commande : réception de retour / activité d'expédition. */
	private static function cycle_marks( int $order_id ): array {
		global $wpdb;
		$t  = CODCRM_Installer::table_events();
		$ph = implode( ',', array_fill( 0, count( self::FORWARD ), '%s' ) );
		$r  = $wpdb->get_row( $wpdb->prepare(
			"SELECT MAX(CASE WHEN type = 'scan' AND outcome = 'return_received' THEN occurred_at END) AS recv,
			        MAX(CASE WHEN type IN ('carrier_state','status') AND stage IN ({$ph}) THEN occurred_at END) AS fwd
			 FROM {$t} WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( self::FORWARD, [ $order_id ] )
		) );
		return [ (string) ( $r->recv ?? '' ), (string) ( $r->fwd ?? '' ) ];
	}

	/**
	 * Retour reçu pour le cycle en cours ? Une réception ne vaut que tant qu'aucune activité
	 * d'expédition ne la suit (sinon le colis est reparti : un nouveau retour est possible).
	 */
	public static function is_received( WC_Order $o ): bool {
		[ $recv, $fwd ] = self::cycle_marks( $o->get_id() );
		if ( '' === $recv && '1' === (string) $o->get_meta( self::META_RECEIVED ) ) {
			$recv = (string) $o->get_meta( self::META_RECEIVED_AT ); // marquage sans événement (ancien)
			if ( '' === $recv ) {
				return true;
			}
		}
		return '' !== $recv && ( '' === $fwd || $recv > $fwd );
	}

	/** Le transporteur a annoncé un retour (n'importe quel état « Retour… ») ou statut Retournée ? */
	public static function is_in_return( WC_Order $o ): bool {
		$stage = (string) $o->get_meta( CODCRM_Event_Collector::META_STAGE );
		return 0 === strpos( $stage, 'RETOUR_' ) || 'wcmcs-returned' === $o->get_status();
	}

	/** Livré au client. L'étape transporteur prime : un « Terminé » repassé en retour n'est pas livré. */
	public static function is_delivered( WC_Order $o ): bool {
		if ( self::is_in_return( $o ) ) {
			return false;
		}
		$stage = (string) $o->get_meta( CODCRM_Event_Collector::META_STAGE );
		return in_array( $stage, [ CODCRM_Stages::LIVRES, CODCRM_Stages::LIVRES_PAYES ], true )
			|| in_array( $o->get_status(), [ 'wcmcs-delivered', 'completed' ], true );
	}

	/**
	 * Le colis retour est revenu à la boutique : marquage, remise en stock, timeline,
	 * tâches et cash. Le statut WooCommerce n'est pas modifié (pas d'email client).
	 *
	 * @return array{ok:bool,restocked:int,msg:string}
	 */
	public static function receive( WC_Order $o, int $actor_id, string $source = 'scan' ): array {
		$lock = 'codcrm_lock_return_' . $o->get_id();
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 60 ) {
				return [ 'ok' => false, 'restocked' => 0, 'msg' => __( 'Retour déjà en cours de traitement.', 'cod-tracker-crm' ) ];
			}
			update_option( $lock, time(), false ); // verrou périmé (processus interrompu)
		}
		try {
			return self::do_receive( $o, $actor_id, $source );
		} finally {
			delete_option( $lock );
		}
	}

	private static function do_receive( WC_Order $o, int $actor_id, string $source ): array {
		if ( self::is_received( $o ) ) {
			return [ 'ok' => false, 'restocked' => 0, 'msg' => __( 'Retour déjà reçu.', 'cod-tracker-crm' ) ];
		}

		// Remise en stock : WooCommerce ne réintègre que les quantités réellement déduites
		// (article par article, méta _reduced_stock) et seulement pour les produits qui
		// gèrent leur stock — ni double remise en stock, ni stock gonflé.
		$before = self::reduced_qty( $o );
		if ( $before > 0 && function_exists( 'wc_increase_stock_levels' ) ) {
			wc_increase_stock_levels( $o->get_id() );
			$o = wc_get_order( $o->get_id() );
		}
		$restocked = max( 0, $before - self::reduced_qty( $o ) );

		$o->update_meta_data( self::META_RECEIVED, '1' );
		$o->update_meta_data( self::META_RECEIVED_AT, current_time( 'mysql' ) );
		$o->save_meta_data();

		$how  = 'scan' === $source ? __( 'scan', 'cod-tracker-crm' ) : __( 'marqué manuellement', 'cod-tracker-crm' );
		$note = sprintf(
			/* translators: 1 mode, 2 articles */
			__( 'Retour reçu (%1$s) — %2$s', 'cod-tracker-crm' ),
			$how,
			$restocked
				? sprintf( _n( '%d article remis en stock', '%d articles remis en stock', $restocked, 'cod-tracker-crm' ), $restocked )
				: __( 'aucun stock à réintégrer', 'cod-tracker-crm' )
		);

		CODCRM_Events::add( $o->get_id(), [
			'type'     => 'scan',
			'outcome'  => 'return_received',
			'note'     => $note,
			'actor_id' => $actor_id,
			'source'   => 'scan' === $source ? 'scan' : 'agent',
			'payload'  => [ 'restocked' => $restocked ],
		] );
		$user = $actor_id ? get_userdata( $actor_id ) : null;
		$o->add_order_note( '[COD] ' . $note . ( $user ? ' — ' . $user->display_name : '' ) . '.' );

		CODCRM_Tasks::complete_open( $o->get_id(), [ CODCRM_Tasks::KIND_RETURN ], $actor_id );

		// Cash : rien ne sera encaissé pour ce colis.
		$cash = CODCRM_Cash::get( $o->get_id() );
		if ( $cash && ! in_array( $cash->status, [ CODCRM_Cash::PAID_OUT, CODCRM_Cash::DISPUTED, CODCRM_Cash::RETURNED, CODCRM_Cash::CANCELLED ], true ) ) {
			CODCRM_Cash::set_status( $o->get_id(), CODCRM_Cash::RETURNED, $actor_id, __( 'Colis retour reçu', 'cod-tracker-crm' ) );
		}

		self::schedule_recount();
		do_action( 'codcrm_return_received', $o, $restocked, $source );

		return [ 'ok' => true, 'restocked' => $restocked, 'msg' => $note ];
	}

	/** Quantité encore « déduite du stock » sur les articles dont le produit gère son stock. */
	private static function reduced_qty( WC_Order $o ): int {
		$n = 0;
		foreach ( $o->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && $product->managing_stock() ) {
				$n += (int) $item->get_meta( '_reduced_stock', true );
			}
		}
		return $n;
	}

	// ── Retours non reçus (colis potentiellement perdus) ───────

	/** Début du cycle de retour en cours : 1er retour définitif postérieur à la dernière expédition. */
	public static function started_at( int $order_id ): string {
		global $wpdb;
		$t   = CODCRM_Installer::table_events();
		$ph  = implode( ',', array_fill( 0, count( self::DEFINITIVE ), '%s' ) );
		[ , $fwd ] = self::cycle_marks( $order_id );
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(occurred_at) FROM {$t} WHERE order_id = %d AND type IN ('carrier_state','status') AND stage IN ({$ph}) AND occurred_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( [ $order_id ], self::DEFINITIVE, [ '' === $fwd ? '0000-00-00 00:00:00' : $fwd ] )
		) );
	}

	/**
	 * Retours définitifs annoncés depuis plus de N jours et jamais reçus à la boutique.
	 * Présélection en SQL (une requête) ; WooCommerce n'est sollicité que pour les candidats.
	 *
	 * @param array $filter Optionnel : carrier (slug), agent (id | 'none' | 'all'), state (billing_state).
	 * @return array<int,array{id:int,started:string,days:int,carrier:string,agent:int}>
	 */
	public static function missing( int $limit = 200, array $filter = [] ): array {
		global $wpdb;
		$t      = CODCRM_Installer::table_events();
		$def    = implode( ',', array_fill( 0, count( self::DEFINITIVE ), '%s' ) );
		$fwd    = implode( ',', array_fill( 0, count( self::FORWARD ), '%s' ) );
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id,
			        MIN(CASE WHEN type IN ('carrier_state','status') AND stage IN ({$def}) THEN occurred_at END) AS first_def,
			        MAX(CASE WHEN type IN ('carrier_state','status') AND stage IN ({$def}) THEN occurred_at END) AS last_def,
			        MAX(CASE WHEN type = 'scan' AND outcome = 'return_received' THEN occurred_at END) AS recv,
			        MAX(CASE WHEN type IN ('carrier_state','status') AND stage IN ({$fwd}) THEN occurred_at END) AS fwd
			 FROM {$t} WHERE type IN ('carrier_state','status','scan')
			 GROUP BY order_id
			 HAVING first_def IS NOT NULL AND first_def <= %s AND last_def >= %s
			    AND (fwd IS NULL OR last_def > fwd)
			    AND (recv IS NULL OR (fwd IS NOT NULL AND recv <= fwd))
			 ORDER BY first_def ASC
			 LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( self::DEFINITIVE, self::DEFINITIVE, self::FORWARD, [ $cutoff, self::since() ] )
		) );

		$out   = [];
		$since = self::since();
		foreach ( (array) $rows as $r ) {
			$started = self::started_at( (int) $r->order_id );
			if ( '' === $started || $started > $cutoff || $started < $since ) {
				continue;
			}
			$o = wc_get_order( (int) $r->order_id );
			if ( ! $o || self::is_delivered( $o ) || in_array( $o->get_status(), [ 'cancelled', 'refunded', 'trash' ], true ) ) {
				continue;
			}
			$carrier = (string) $o->get_meta( '_wcmcs_carrier_slug' );
			$agent   = CODCRM_Agents::agent_of( $o );
			if ( ! empty( $filter['carrier'] ) && $filter['carrier'] !== $carrier ) {
				continue;
			}
			$want = (string) ( $filter['agent'] ?? 'all' );
			if ( 'none' === $want ? 0 !== $agent : ( ctype_digit( $want ) && (int) $want !== $agent ) ) {
				continue;
			}
			if ( ! empty( $filter['state'] ) && $filter['state'] !== $o->get_billing_state() ) {
				continue;
			}
			$out[] = [
				'id'      => (int) $r->order_id,
				'started' => $started,
				'days'    => (int) floor( ( time() - (int) strtotime( get_gmt_from_date( $started ) . ' UTC' ) ) / DAY_IN_SECONDS ),
				'carrier' => $carrier,
				'agent'   => $agent,
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		/**
		 * Permet d'exclure / ajuster les colis retour considérés comme manquants.
		 *
		 * @param array $out Liste [ id, started, days, carrier, agent ].
		 */
		return (array) apply_filters( 'codcrm_returns_missing', $out );
	}

	/** Compteur stocké — lu partout (notice, carte) ; recalculé par le cron / après une réception. */
	public static function count_missing(): int {
		return (int) get_option( self::CACHE_COUNT, 0 );
	}

	public static function recount(): int {
		$n = count( self::missing( 1000 ) );
		update_option( self::CACHE_COUNT, $n, true );
		return $n;
	}

	private static function schedule_recount(): void {
		if ( ! wp_next_scheduled( self::RECOUNT_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::RECOUNT_HOOK );
		}
	}

	/** Tâche quotidienne : tâches agents + email récapitulatif (seulement s'il y a du nouveau). */
	public static function daily(): void {
		$rows = self::missing( 500 );
		update_option( self::CACHE_COUNT, count( $rows ), true );
		if ( ! $rows ) {
			update_option( self::OPT_NOTIFIED, [], false );
			return;
		}

		foreach ( $rows as $r ) {
			CODCRM_Tasks::create(
				$r['id'],
				$r['agent'],
				CODCRM_Tasks::KIND_RETURN,
				current_time( 'mysql' ),
				sprintf( /* translators: %d jours */ __( 'Colis retour non reçu depuis %d jours — réclamer au transporteur', 'cod-tracker-crm' ), $r['days'] )
			);
		}

		// Un seul email par colis : on ne renvoie que les nouveaux (les autres restent en rappel).
		$notified = array_map( 'intval', (array) get_option( self::OPT_NOTIFIED, [] ) );
		$ids      = array_column( $rows, 'id' );
		$new      = array_diff( $ids, $notified );
		update_option( self::OPT_NOTIFIED, $ids, false );
		$to = (string) get_option( 'admin_email' );
		if ( ! $new || '' === $to ) {
			return;
		}
		$lines = [];
		foreach ( array_slice( $rows, 0, 50 ) as $r ) {
			$lines[] = sprintf(
				'%s#%d — %s — retour annoncé le %s (%d j) — agent : %s',
				in_array( $r['id'], $new, true ) ? '[NOUVEAU] ' : '',
				$r['id'],
				'' !== $r['carrier'] ? $r['carrier'] : '?',
				mysql2date( 'd/m/Y', $r['started'] ),
				$r['days'],
				CODCRM_Agents::agent_name( $r['agent'] ) ?: __( 'non assigné', 'cod-tracker-crm' )
			);
		}
		if ( count( $rows ) > 50 ) {
			$lines[] = sprintf( '… et %d autre(s).', count( $rows ) - 50 );
		}
		wp_mail(
			$to,
			sprintf( '[COD] %d nouveau(x) colis retour non reçu(s) (%d au total, > %d jours)', count( $new ), count( $rows ), self::days() ),
			"Ces colis ont été déclarés retournés par le transporteur mais n'ont pas été scannés à leur retour à la boutique.\n"
			. "Vérifiez auprès du transporteur (colis possiblement perdu).\n\n"
			. implode( "\n", $lines )
			. "\n\n" . admin_url( 'admin.php?page=codcrm-dashboard#codcrm-returns' )
		);
	}

	public static function admin_notice(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			return;
		}
		$n = self::count_missing();
		if ( ! $n ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>' . CODCRM_Icons::svg( 'exclamation-triangle' ) . ' <strong>%s</strong> <a href="%s">%s</a></p></div>',
			esc_html( sprintf(
				/* translators: 1 nombre, 2 jours */
				_n( '%1$d colis retour n\'est pas revenu à la boutique %2$d jours après le retour annoncé par le transporteur.', '%1$d colis retour ne sont pas revenus à la boutique %2$d jours après le retour annoncé par le transporteur.', $n, 'cod-tracker-crm' ),
				$n,
				self::days()
			) ),
			esc_url( admin_url( 'admin.php?page=codcrm-dashboard#codcrm-returns' ) ),
			esc_html__( 'Voir la liste', 'cod-tracker-crm' )
		);
	}

	/** « Marquer reçu » depuis le tableau de bord (colis rentré sans scan). */
	public static function handle_mark_received(): void {
		$id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( 'codcrm_return_' . $id );

		$o   = wc_get_order( $id );
		$res = $o ? self::receive( $o, get_current_user_id(), 'manual' ) : [ 'ok' => false ];

		wp_safe_redirect( add_query_arg( [ 'page' => 'codcrm-dashboard', 'return_marked' => $res['ok'] ? $id : 0 ], admin_url( 'admin.php' ) ) . '#codcrm-returns' );
		exit;
	}
}
