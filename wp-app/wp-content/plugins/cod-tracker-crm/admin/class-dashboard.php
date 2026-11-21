<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page « Suivi COD → Tableau de bord » (propriétaire).
 */
class CODCRM_Dashboard {

	const PAGE = 'codcrm-dashboard';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			CODCRM_Work_Queue::PAGE_QUEUE,
			__( 'Tableau de bord', 'cod-tracker-crm' ),
			__( 'Tableau de bord', 'cod-tracker-crm' ),
			CODCRM_Agents::CAP_MANAGE,
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) && false === strpos( $hook, CODCRM_Cash_Page::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'codcrm-dashboard', CODCRM_URL . 'assets/css/dashboard.css?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . 'assets/css/dashboard.css' ), [], null );
	}

	private static function pct( $v ): string {
		return null === $v ? '—' : number_format_i18n( (float) $v, 1 ) . ' %';
	}

	private static function bar( $v, string $color ): string {
		$w = null === $v ? 0 : max( 0, min( 100, (float) $v ) );
		return '<div class="codcrm-bar"><span style="width:' . esc_attr( (string) $w ) . '%;background:' . esc_attr( $color ) . '"></span></div>';
	}

	private static function state_label( string $code ): string {
		if ( '' === $code ) {
			return '—';
		}
		static $states = null;
		if ( null === $states ) {
			$states = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_states( 'TN' ) : [];
		}
		return $states[ $code ] ?? $code;
	}

	public static function render(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}

		$today = current_time( 'Y-m-d' );
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.Security.NonceVerification
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today; // phpcs:ignore WordPress.Security.NonceVerification
		$valid = static function ( $d ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && strtotime( $d ); };
		if ( ! $valid( $from ) || ! $valid( $to ) || $from > $to ) {
			$from = gmdate( 'Y-m-d', strtotime( $today ) - 30 * DAY_IN_SECONDS );
			$to   = $today;
		}

		$f = [
			'carrier' => sanitize_key( wp_unslash( $_GET['carrier'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'agent'   => sanitize_text_field( wp_unslash( $_GET['agent'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'state'   => sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
		];

		$refresh = isset( $_GET['refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$all     = CODCRM_KPIs::rows( $from, $to, $refresh );
		$carriers = array_values( array_unique( array_filter( array_column( $all, 'carrier' ) ) ) );
		$states   = array_values( array_unique( array_filter( array_column( $all, 'state' ) ) ) );
		sort( $carriers );
		sort( $states );

		$rows = CODCRM_KPIs::filter( $all, $f );
		$k    = CODCRM_KPIs::compute( $rows );
		$o    = $k['overall'];

		echo '<div class="wrap codcrm-dash"><h1>' . esc_html__( 'Suivi COD — tableau de bord', 'cod-tracker-crm' ) . '</h1>';

		// Filtres.
		echo '<form method="get" class="codcrm-dash-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		printf( '<label>%s <input type="date" name="from" value="%s" /></label> <label>%s <input type="date" name="to" value="%s" /></label> ', esc_html__( 'Du', 'cod-tracker-crm' ), esc_attr( $from ), esc_html__( 'au', 'cod-tracker-crm' ), esc_attr( $to ) );
		echo '<select name="carrier"><option value="">' . esc_html__( 'Tous transporteurs', 'cod-tracker-crm' ) . '</option>';
		foreach ( $carriers as $c ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $c ), selected( $f['carrier'], $c, false ), esc_html( $c ) );
		}
		echo '</select> <select name="agent"><option value="all">' . esc_html__( 'Tous agents', 'cod-tracker-crm' ) . '</option>';
		printf( '<option value="none"%s>%s</option>', selected( $f['agent'], 'none', false ), esc_html__( 'Non assignés', 'cod-tracker-crm' ) );
		foreach ( CODCRM_Agents::get_agents() as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $f['agent'], (string) $u->ID, false ), esc_html( $u->display_name ) );
		}
		echo '</select> <select name="state"><option value="">' . esc_html__( 'Tous gouvernorats', 'cod-tracker-crm' ) . '</option>';
		foreach ( $states as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $f['state'], $s, false ), esc_html( self::state_label( $s ) ) );
		}
		echo '</select> <button class="button button-primary">' . esc_html__( 'Appliquer', 'cod-tracker-crm' ) . '</button> ';
		printf( '<a class="button" href="%s">%s</a></form>', esc_url( add_query_arg( [ 'page' => self::PAGE, 'from' => $from, 'to' => $to, 'refresh' => 1 ], admin_url( 'admin.php' ) ) ), esc_html__( '↻ Actualiser', 'cod-tracker-crm' ) );

		if ( ! $o['total'] ) {
			echo '<p>' . esc_html__( 'Aucun colis synchronisé sur cette période.', 'cod-tracker-crm' ) . '</p></div>';
			return;
		}

		// Cartes.
		$on_basis = sprintf( /* translators: %d nombre de colis */ __( 'colis enlevés et clôturés (%d)', 'cod-tracker-crm' ), $o['delivered'] + $o['returned'] );
		$cards = [
			[ __( 'Colis créés', 'cod-tracker-crm' ), (string) ( $o['total'] - $o['cancelled'] ), sprintf( _n( '%d annulé exclu', '%d annulés exclus', $o['cancelled'], 'cod-tracker-crm' ), $o['cancelled'] ), '#2271b1' ],
			[ __( 'Enlevés par le transporteur', 'cod-tracker-crm' ), (string) $o['picked'], __( 'base de tous les taux', 'cod-tracker-crm' ), '#1f3a7a' ],
			[ __( 'Non enlevés', 'cod-tracker-crm' ), (string) $o['not_picked'], sprintf( _n( '%d depuis plus de %d j', '%d depuis plus de %d j', count( $k['pickup'] ), 'cod-tracker-crm' ), count( $k['pickup'] ), CODCRM_KPIs::pickup_days() ), '#e67e22' ],
			[ __( 'En transit', 'cod-tracker-crm' ), (string) $o['open'], __( 'enlevés, pas encore clôturés', 'cod-tracker-crm' ), '#0b2a3a' ],
			[ __( 'Retours non reçus', 'cod-tracker-crm' ), (string) CODCRM_Returns::count_missing(), sprintf( /* translators: %d jours */ __( 'retour annoncé il y a plus de %d j', 'cod-tracker-crm' ), CODCRM_Returns::days() ), '#c0392b' ],
			[ __( 'Livrés', 'cod-tracker-crm' ), (string) $o['delivered'], '', '#00a651' ],
			[ __( 'Retournés', 'cod-tracker-crm' ), (string) $o['returned'], '', '#c0392b' ],
			[ __( 'Taux de livraison', 'cod-tracker-crm' ), self::pct( $o['delivery_rate'] ), $on_basis, '#00a651' ],
			[ __( 'Taux de retour', 'cod-tracker-crm' ), self::pct( $o['return_rate'] ), $on_basis, '#c0392b' ],
			[ __( 'Délai moyen de livraison', 'cod-tracker-crm' ), null === $k['avg_days'] ? '—' : number_format_i18n( $k['avg_days'], 1 ) . ' j', sprintf( /* translators: %d nombre de colis */ __( 'colis → livré, sur %d colis avec historique', 'cod-tracker-crm' ), $k['avg_days_n'] ), '#8e44ad' ],
			[ __( 'Récupérés après échec', 'cod-tracker-crm' ), self::pct( $o['recovery_rate'] ), sprintf( '%d / %d', $o['recovered'], $o['with_failed'] ), '#0aa5c0' ],
		];
		echo '<div class="codcrm-cards">';
		foreach ( $cards as $c ) {
			printf( '<div class="codcrm-card" style="border-top-color:%s"><div class="v">%s</div><div class="l">%s</div><div class="s">%s</div></div>', esc_attr( $c[3] ), esc_html( $c[1] ), esc_html( $c[0] ), esc_html( $c[2] ) );
		}
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Les taux ne portent que sur les colis ENLEVÉS par la société de livraison (un état du transporteur l\'atteste) : taux de livraison = livrés ÷ (livrés + retournés). Les colis jamais récupérés et les colis annulés / supprimés en sont exclus. « Retourné » = tout état « Retour… » (y compris retour en cours).', 'cod-tracker-crm' ) . '</p>';

		if ( $k['lagging'] ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( sprintf(
				/* translators: %d nombre de commandes */
				_n( '%d commande a un statut WooCommerce en retard sur le transporteur (colis déjà livré ou retourné). Lancez « Vérifier tous les statuts » dans MultiCarrier Sync.', '%d commandes ont un statut WooCommerce en retard sur le transporteur (colis déjà livrés ou retournés). Lancez « Vérifier tous les statuts » dans MultiCarrier Sync.', $k['lagging'], 'cod-tracker-crm' ),
				$k['lagging']
			) ) );
		}

		// Par transporteur / gouvernorat / agent.
		self::table( __( 'Par transporteur', 'cod-tracker-crm' ), __( 'Transporteur', 'cod-tracker-crm' ), $k['by_carrier'], static function ( $key ) { return '' !== $key ? $key : '—'; } );
		self::table( __( 'Par gouvernorat (top 15)', 'cod-tracker-crm' ), __( 'Gouvernorat', 'cod-tracker-crm' ), array_slice( $k['by_state'], 0, 15, true ), [ __CLASS__, 'state_label' ] );
		self::table( __( 'Par agent assigné', 'cod-tracker-crm' ), __( 'Agent', 'cod-tracker-crm' ), $k['by_agent'], static function ( $key ) { return '0' === (string) $key ? __( 'Non assignés', 'cod-tracker-crm' ) : ( CODCRM_Agents::agent_name( (int) $key ) ?: '#' . $key ); }, true );

		// Activité agents.
		$act = CODCRM_KPIs::agent_activity( $from, $to );
		echo '<h2>' . esc_html__( 'Activité des agents (contacts sur la période)', 'cod-tracker-crm' ) . '</h2>';
		if ( ! $act ) {
			echo '<p>' . esc_html__( 'Aucun contact enregistré sur la période.', 'cod-tracker-crm' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Agent', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Contacts', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Appels', 'cod-tracker-crm' ) . '</th><th>WhatsApp</th><th>SMS</th><th>' . esc_html__( 'Clients joints', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Injoignables', 'cod-tracker-crm' ) . '</th></tr></thead><tbody>';
			foreach ( $act as $uid => $a ) {
				printf( '<tr><td>%s</td><td><strong>%d</strong></td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>', esc_html( CODCRM_Agents::agent_name( (int) $uid ) ?: '#' . $uid ), $a['total'], $a['call'], $a['whatsapp'], $a['sms'], $a['joint'], $a['unreachable'] );
			}
			echo '</tbody></table>';
		}

		// Répartition des tentatives (colis livrés).
		echo '<h2>' . esc_html__( 'Tournées nécessaires pour les colis livrés', 'cod-tracker-crm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Calculé uniquement sur les colis dont le transporteur fournit un historique daté (ex. Colissimo).', 'cod-tracker-crm' ) . '</p><table class="widefat striped codcrm-narrow"><tbody><tr>';
		$tot = max( 1, array_sum( $k['attempt_hist'] ) );
		foreach ( $k['attempt_hist'] as $n => $count ) {
			printf( '<td><strong>%s</strong> %s<br>%d <small>(%s)</small></td>', esc_html( (string) $n ), esc_html__( 'tournée(s)', 'cod-tracker-crm' ), (int) $count, esc_html( number_format_i18n( 100 * $count / $tot, 0 ) . ' %' ) );
		}
		echo '</tr></tbody></table>';

		// Retours définitifs annoncés mais jamais revenus à la boutique (colis potentiellement perdus).
		self::render_missing_returns();

		// Colis non enlevés par la société de livraison.
		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: %d jours */ __( 'En attente d\'enlèvement depuis plus de %d jours', 'cod-tracker-crm' ), CODCRM_KPIs::pickup_days() ) ) );
		if ( ! $k['pickup'] ) {
			echo '<p>' . esc_html__( 'Aucun colis en attente d\'enlèvement au-delà du délai.', 'cod-tracker-crm' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Commande', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Transporteur', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Créé depuis', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Agent', 'cod-tracker-crm' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $k['pickup'], 0, 50 ) as $r ) {
				$order = wc_get_order( $r['id'] );
				printf( '<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( $order ? $order->get_edit_order_url() : '#' ),
					(int) $r['id'],
					esc_html( $r['carrier'] ),
					esc_html( sprintf( _n( '%d jour', '%d jours', $r['days'], 'cod-tracker-crm' ), $r['days'] ) ),
					esc_html( CODCRM_Agents::agent_name( (int) $r['agent'] ) ?: '—' )
				);
			}
			echo '</tbody></table>';
			if ( count( $k['pickup'] ) > 50 ) {
				printf( '<p class="description">%s</p>', esc_html( sprintf( __( '+ %d autres (liste limitée à 50).', 'cod-tracker-crm' ), count( $k['pickup'] ) - 50 ) ) );
			}
			echo '<p class="description">' . esc_html__( 'Une tâche « Enlèvement en attente » est créée chaque jour pour l\'agent assigné : relancez la société de livraison.', 'cod-tracker-crm' ) . '</p>';
		}

		// Colis bloqués.
		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: %d jours */ __( 'Colis bloqués (même étape depuis plus de %d jours)', 'cod-tracker-crm' ), CODCRM_KPIs::stuck_days() ) ) );
		if ( ! $k['stuck'] ) {
			echo '<p>' . esc_html__( 'Aucun colis bloqué. 🎉', 'cod-tracker-crm' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Commande', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Étape', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Depuis', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Transporteur', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Agent', 'cod-tracker-crm' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $k['stuck'], 0, 50 ) as $r ) {
				$order = wc_get_order( $r['id'] );
				printf( '<tr><td><a href="%s">#%d</a></td><td><span class="codcrm-pill" style="%s">%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( $order ? $order->get_edit_order_url() : '#' ),
					(int) $r['id'],
					esc_attr( CODCRM_Stages::badge_style( CODCRM_Stages::color( $r['stage'] ) ) ),
					esc_html( CODCRM_Stages::label( $r['stage'] ) ),
					esc_html( sprintf( _n( '%d jour', '%d jours', $r['days'], 'cod-tracker-crm' ), $r['days'] ) ),
					esc_html( $r['carrier'] ),
					esc_html( CODCRM_Agents::agent_name( (int) $r['agent'] ) ?: '—' )
				);
			}
			echo '</tbody></table>';
			if ( count( $k['stuck'] ) > 50 ) {
				printf( '<p class="description">%s</p>', esc_html( sprintf( __( '+ %d autres (liste limitée à 50).', 'cod-tracker-crm' ), count( $k['stuck'] ) - 50 ) ) );
			}
			echo '<p class="description">' . esc_html__( 'Une tâche « Colis bloqué » est créée chaque jour pour l\'agent assigné.', 'cod-tracker-crm' ) . '</p>';
		}

		echo '</div>';
	}

	/** Section « Retours non reçus » avec bouton « Marquer reçu ». */
	private static function render_missing_returns(): void {
		$rows = CODCRM_Returns::missing( 200 );
		echo '<h2 id="codcrm-returns">' . esc_html( sprintf( /* translators: %d jours */ __( 'Retours non reçus (plus de %d jours après le retour annoncé)', 'cod-tracker-crm' ), CODCRM_Returns::days() ) ) . '</h2>';
		if ( isset( $_GET['return_marked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success inline"><p>' . esc_html( absint( $_GET['return_marked'] ) ? __( 'Retour marqué comme reçu (stock réintégré si applicable).', 'cod-tracker-crm' ) : __( 'Retour déjà reçu ou introuvable.', 'cod-tracker-crm' ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Aucun colis retour manquant. 🎉', 'cod-tracker-crm' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Le transporteur a déclaré ces colis retournés, mais ils n\'ont pas été scannés « Retour reçu ». Réclamez-les au transporteur ; si un colis est bien rentré sans scan, marquez-le reçu.', 'cod-tracker-crm' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Commande', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Transporteur', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Retour annoncé le', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Depuis', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Agent', 'cod-tracker-crm' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 100 ) as $r ) {
			$order = wc_get_order( $r['id'] );
			echo '<tr>';
			printf( '<td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
				esc_url( $order ? $order->get_edit_order_url() : '#' ), (int) $r['id'], esc_html( $r['carrier'] ),
				esc_html( mysql2date( 'd/m/Y', $r['started'] ) ),
				esc_html( sprintf( _n( '%d jour', '%d jours', $r['days'], 'cod-tracker-crm' ), $r['days'] ) ),
				esc_html( CODCRM_Agents::agent_name( (int) $r['agent'] ) ?: '—' )
			);
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="codcrm_return_received" /><input type="hidden" name="order_id" value="' . (int) $r['id'] . '" />';
			wp_nonce_field( 'codcrm_return_' . $r['id'] );
			echo '<button class="button button-small">' . esc_html__( 'Marquer reçu', 'cod-tracker-crm' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $rows ) > 100 ) {
			echo '<p class="description">' . esc_html( sprintf( __( '+ %d autres (liste limitée à 100).', 'cod-tracker-crm' ), count( $rows ) - 100 ) ) . '</p>';
		}
	}

	/** Tableau de groupe : colis, livrés, retours, taux (+ récupération après échec pour les agents). */
	private static function table( string $title, string $col, array $groups, callable $label, bool $recovery = false ): void {
		echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( $col ) . '</th><th>' . esc_html__( 'Colis', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Enlevés', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Non enlevés', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Livrés', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Retournés', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'En transit', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Taux de livraison', 'cod-tracker-crm' ) . '</th>';
		if ( $recovery ) {
			echo '<th>' . esc_html__( 'Récup. après échec', 'cod-tracker-crm' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $groups as $key => $t ) {
			printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s %s</td>',
				esc_html( (string) call_user_func( $label, (string) $key ) ),
				$t['total'] - $t['cancelled'], $t['picked'], $t['not_picked'] ? '<strong style="color:#e67e22">' . (int) $t['not_picked'] . '</strong>' : '0', $t['delivered'], $t['returned'], $t['open'],
				esc_html( self::pct( $t['delivery_rate'] ) ),
				self::bar( $t['delivery_rate'], '#00a651' ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
			if ( $recovery ) {
				printf( '<td>%s <small>(%d/%d)</small></td>', esc_html( self::pct( $t['recovery_rate'] ) ), $t['recovered'], $t['with_failed'] );
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
