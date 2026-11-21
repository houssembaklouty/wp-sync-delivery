<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page « Suivi COD » : file de travail de l'agent (appel / WhatsApp tracés,
 * tâches) + réglages du propriétaire.
 */
class CODCRM_Work_Queue {

	const PAGE_QUEUE    = 'codcrm-queue';
	const PAGE_SETTINGS = 'codcrm-settings';
	const PER_PAGE      = 25;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Suivi COD', 'cod-tracker-crm' ),
			__( 'Suivi COD', 'cod-tracker-crm' ),
			CODCRM_Agents::CAP_WORK,
			self::PAGE_QUEUE,
			[ __CLASS__, 'render_queue' ],
			'dashicons-phone',
			56
		);
		add_submenu_page( self::PAGE_QUEUE, __( 'Mes suivis', 'cod-tracker-crm' ), __( 'Mes suivis', 'cod-tracker-crm' ), CODCRM_Agents::CAP_WORK, self::PAGE_QUEUE, [ __CLASS__, 'render_queue' ] );
	}

	/** URL d'asset avec cache-busting par mtime (les mu-plugins retirent ?ver=). */
	private static function asset( string $rel ): string {
		return CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel );
	}

	public static function assets( string $hook ): void {
		// Page « Mes suivis » + fiche commande (bouton « Message » de la metabox).
		$screen    = get_current_screen();
		$order_scr = $screen && in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true );
		if ( false === strpos( $hook, self::PAGE_QUEUE ) && ! $order_scr ) {
			return;
		}
		wp_enqueue_style( 'codcrm-queue', self::asset( 'assets/css/work-queue.css' ), [], null );
		wp_enqueue_script( 'codcrm-queue', self::asset( 'assets/js/work-queue.js' ), [ 'jquery' ], null, true );
		wp_localize_script( 'codcrm-queue', 'codcrm', [
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'codcrm' ),
			'outcomes' => CODCRM_Contacts::call_outcomes(),
			'i18n'     => [
				'call_title'  => __( 'Résultat de l\'appel', 'cod-tracker-crm' ),
				'outcome'     => __( 'Résultat', 'cod-tracker-crm' ),
				'choose'      => __( '— Choisir —', 'cod-tracker-crm' ),
				'callback_at' => __( 'Rappeler le', 'cod-tracker-crm' ),
				'note'        => __( 'Commentaire', 'cod-tracker-crm' ),
				'save'        => __( 'Enregistrer', 'cod-tracker-crm' ),
				'msg_title'   => __( 'Envoyer un message', 'cod-tracker-crm' ),
				'msg_channel' => __( 'Canal', 'cod-tracker-crm' ),
				'msg_lang'    => __( 'Langue', 'cod-tracker-crm' ),
				'msg_context' => __( 'Situation / modèle', 'cod-tracker-crm' ),
				'msg_text'    => __( 'Message (modifiable avant envoi)', 'cod-tracker-crm' ),
				'msg_suggest' => __( 'Suggéré', 'cod-tracker-crm' ),
				'msg_send_wa' => __( 'Ouvrir WhatsApp', 'cod-tracker-crm' ),
				'msg_send_sms' => __( 'Envoyer le SMS', 'cod-tracker-crm' ),
				'msg_no_sms'  => __( 'SMS indisponible : passerelle non configurée.', 'cod-tracker-crm' ),
				'msg_chars'   => __( 'caractères', 'cod-tracker-crm' ),
				'msg_parts'   => __( 'SMS', 'cod-tracker-crm' ),
				'msg_loading' => __( 'Chargement…', 'cod-tracker-crm' ),
				'cancel'      => __( 'Annuler', 'cod-tracker-crm' ),
				'error'       => __( 'Erreur réseau.', 'cod-tracker-crm' ),
			],
		] );
	}

	/** Statuts d'étape sélectionnables dans le filtre. */
	private static function stage_filter_options(): array {
		$opts = [ 'actif' => __( 'En cours (non terminés)', 'cod-tracker-crm' ), 'tous' => __( 'Tous', 'cod-tracker-crm' ) ];
		foreach ( CODCRM_Stages::all() as $code => $def ) {
			$opts[ $code ] = $def['label'];
		}
		return $opts;
	}

	/** @return array{0:array,1:int} args wc_get_orders, agent filtré */
	private static function build_query( int $paged ): array {
		$is_manager = current_user_can( CODCRM_Agents::CAP_MANAGE );
		$uid        = get_current_user_id();

		$stage = sanitize_text_field( wp_unslash( $_GET['stage'] ?? 'actif' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$flt   = sanitize_key( wp_unslash( $_GET['flt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$agent = $is_manager ? sanitize_text_field( wp_unslash( $_GET['agent'] ?? 'all' ) ) : (string) $uid; // phpcs:ignore WordPress.Security.NonceVerification

		// Commandes suivies : colis créé, OU déjà attribuée à un agent (dès la création de la
		// commande), OU en attente d'un agent.
		$meta = [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => '_wcmcs_sync_status', 'value' => 'synced' ],
				[ 'key' => CODCRM_Agents::META_AGENT, 'compare' => 'EXISTS' ],
				[ 'key' => CODCRM_Dispatcher::META_WAITING, 'value' => '1' ],
			],
		];

		if ( 'actif' === $stage ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => CODCRM_Event_Collector::META_STAGE, 'compare' => 'NOT EXISTS' ],
				[ 'key' => CODCRM_Event_Collector::META_STAGE, 'value' => CODCRM_Stages::TERMINAL, 'compare' => 'NOT IN' ],
			];
		} elseif ( 'tous' !== $stage && isset( CODCRM_Stages::all()[ $stage ] ) ) {
			$meta[] = [ 'key' => CODCRM_Event_Collector::META_STAGE, 'value' => $stage ];
		}

		$agent_for_tasks = 0;
		if ( 'none' === $agent ) {
			$meta[] = [ 'key' => CODCRM_Agents::META_AGENT, 'compare' => 'NOT EXISTS' ];
		} elseif ( ctype_digit( $agent ) && (int) $agent > 0 ) {
			$meta[]          = [ 'key' => CODCRM_Agents::META_AGENT, 'value' => (int) $agent ];
			$agent_for_tasks = (int) $agent;
		}

		if ( 'failed' === $flt ) {
			$meta[] = [ 'key' => CODCRM_Attempts::META_FAILED, 'value' => 1, 'compare' => '>=', 'type' => 'NUMERIC' ];
		} elseif ( 'waiting' === $flt ) {
			$meta[] = [ 'key' => CODCRM_Dispatcher::META_WAITING, 'value' => '1' ];
		} elseif ( 'risk' === $flt ) {
			$meta[] = [ 'key' => CODCRM_Attempts::META_RISK, 'value' => '1' ];
		}

		$args = [
			'type'       => 'shop_order',
			'status'     => array_keys( wc_get_order_statuses() ),
			'limit'      => self::PER_PAGE,
			'page'       => $paged,
			'paginate'   => true,
			'orderby'    => 'ID',
			'order'      => 'DESC',
			'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
		];

		if ( in_array( $flt, [ 'due', 'overdue' ], true ) ) {
			$ids          = CODCRM_Tasks::order_ids_due( 'due' === $flt ? 'today' : 'overdue', $agent_for_tasks );
			$args['post__in'] = $ids ?: [ 0 ];
		}

		return [ $args, $agent_for_tasks ];
	}

	/** Numéro tunisien sur 8 chiffres (préfixe 216 / 00216 retiré), '' si invalide. */
	public static function norm_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		$d = preg_replace( '/^(00)?216/', '', (string) $d );
		return 8 === strlen( (string) $d ) ? (string) $d : '';
	}

	public static function render_queue(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}

		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_manager = current_user_can( CODCRM_Agents::CAP_MANAGE );
		[ $args ]   = self::build_query( $paged );
		$res        = wc_get_orders( $args );
		$orders     = is_object( $res ) ? (array) $res->orders : [];
		$total      = is_object( $res ) ? (int) $res->total : 0;
		$pages      = is_object( $res ) ? (int) $res->max_num_pages : 1;
		$tasks      = CODCRM_Tasks::next_open_for_orders( array_map( static function ( $o ) { return $o->get_id(); }, $orders ) );

		$cur_stage = sanitize_text_field( wp_unslash( $_GET['stage'] ?? 'actif' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$cur_flt   = sanitize_key( wp_unslash( $_GET['flt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$cur_agent = sanitize_text_field( wp_unslash( $_GET['agent'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap codcrm-wrap"><h1>' . esc_html__( 'Suivi COD — mes colis à suivre', 'cod-tracker-crm' ) . '</h1>';

		// Filtres.
		echo '<form method="get" class="codcrm-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_QUEUE ) . '" />';
		echo '<select name="stage">';
		foreach ( self::stage_filter_options() as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $cur_stage, $k, false ), esc_html( $label ) );
		}
		echo '</select> <select name="flt">';
		$flts = [
			''        => __( 'Tous les colis', 'cod-tracker-crm' ),
			'failed'  => __( 'Avec échec(s) de livraison', 'cod-tracker-crm' ),
			'risk'    => __( '⚠ Risque de retour', 'cod-tracker-crm' ),
			'waiting' => __( 'En attente d\'un agent', 'cod-tracker-crm' ),
			'due'     => __( 'Rappels du jour', 'cod-tracker-crm' ),
			'overdue' => __( 'Rappels en retard', 'cod-tracker-crm' ),
		];
		foreach ( $flts as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $cur_flt, $k, false ), esc_html( $label ) );
		}
		echo '</select> ';
		if ( $is_manager ) {
			echo '<select name="agent"><option value="all">' . esc_html__( 'Tous les agents', 'cod-tracker-crm' ) . '</option>';
			printf( '<option value="none"%s>%s</option>', selected( $cur_agent, 'none', false ), esc_html__( 'Non assignées', 'cod-tracker-crm' ) );
			foreach ( CODCRM_Agents::get_agents() as $u ) {
				printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $cur_agent, (string) $u->ID, false ), esc_html( $u->display_name ) );
			}
			echo '</select> ';
		}
		echo '<button class="button">' . esc_html__( 'Filtrer', 'cod-tracker-crm' ) . '</button> ';
		printf( '<span class="codcrm-count"><strong>%d</strong> %s</span></form>', $total, esc_html__( 'colis', 'cod-tracker-crm' ) );

		echo '<table class="widefat striped codcrm-table"><thead><tr>';
		foreach ( [ __( 'Commande', 'cod-tracker-crm' ), __( 'Client', 'cod-tracker-crm' ), __( 'Étape', 'cod-tracker-crm' ), __( 'Tentatives', 'cod-tracker-crm' ), __( 'Dernier contact', 'cod-tracker-crm' ), __( 'Prochaine tâche', 'cod-tracker-crm' ), __( 'Agent', 'cod-tracker-crm' ), __( 'Actions', 'cod-tracker-crm' ) ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $orders ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Aucun colis à afficher avec ces filtres.', 'cod-tracker-crm' ) . '</td></tr>';
		}

		foreach ( $orders as $order ) {
			self::render_row( $order, $tasks[ $order->get_id() ] ?? null );
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ) . '</div></div>';
		}
		echo '</div>';
	}

	private static function render_row( WC_Order $order, $task ): void {
		$id      = $order->get_id();
		$phone   = $order->get_billing_phone();
		$phone8  = self::norm_phone( $phone );
		$stage   = (string) $order->get_meta( CODCRM_Event_Collector::META_STAGE );
		$failed  = (int) $order->get_meta( CODCRM_Attempts::META_FAILED );
		$risk    = '1' === (string) $order->get_meta( CODCRM_Attempts::META_RISK );
		$last    = (string) $order->get_meta( CODCRM_Contacts::META_LAST_CONTACT );
		$agent   = CODCRM_Agents::agent_name( CODCRM_Agents::agent_of( $order ) );
		$client  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$overdue = $task && $task->due_at < current_time( 'mysql' );

		echo '<tr data-order="' . (int) $id . '">';
		printf( '<td><a href="%s"><strong>#%s</strong></a><br><small>%s · %s</small></td>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			esc_html( (string) $order->get_meta( '_wcmcs_carrier_slug' ) ),
			esc_html( wc_format_decimal( $order->get_total(), 2 ) . ' DT' )
		);

		// Client + historique par téléphone.
		$hist = '';
		if ( $phone8 ) {
			$all  = wc_get_orders( [ 'billing_phone' => $phone, 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
			$ret  = wc_get_orders( [ 'billing_phone' => $phone, 'status' => [ 'wc-wcmcs-returned' ], 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] );
			$hist = sprintf( ' · %d cmd / %d retour(s)', is_object( $all ) ? (int) $all->total : 0, is_object( $ret ) ? (int) $ret->total : 0 );
			if ( function_exists( 'wparabic_phone_strikes' ) ) {
				$strikes = (int) wparabic_phone_strikes( $phone );
				if ( $strikes ) {
					$hist .= sprintf( ' · ⚠ %d strike(s)', $strikes );
				}
			}
		}
		printf( '<td>%s<br><small>%s%s<br>%s</small></td>',
			esc_html( $client ),
			esc_html( $phone ),
			esc_html( $hist ),
			esc_html( $order->get_billing_state() ?: $order->get_billing_city() )
		);

		printf( '<td>%s</td>', $stage
			? '<span class="codcrm-pill" style="background:' . esc_attr( CODCRM_Stages::color( $stage ) ) . '">' . esc_html( CODCRM_Stages::label( $stage ) ) . '</span>'
			: '—' );

		printf( '<td>%d / %d%s</td>', (int) $order->get_meta( CODCRM_Attempts::META_ATTEMPTS ), CODCRM_Attempts::threshold(), $risk ? ' <span class="codcrm-pill" style="background:#c0392b">⚠</span>' : '' );
		printf( '<td>%s</td>', $last ? esc_html( human_time_diff( strtotime( $last ), current_time( 'timestamp' ) ) ) : '—' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		unset( $failed );

		if ( $task ) {
			printf( '<td class="%s">%s<br><small>%s</small> <a href="#" class="codcrm-done" data-task="%d">✓</a></td>',
				$overdue ? 'codcrm-overdue' : '',
				esc_html( CODCRM_Tasks::kind_label( (string) $task->kind ) ),
				esc_html( mysql2date( 'd/m H:i', $task->due_at ) ),
				(int) $task->id
			);
		} else {
			echo '<td>—</td>';
		}

		printf( '<td>%s</td>', $agent ? esc_html( $agent ) : '—' );

		echo '<td class="codcrm-actions">';
		if ( $phone8 ) {
			printf( '<a class="button codcrm-call" href="tel:+216%s" data-order="%d">📞</a> ', esc_attr( $phone8 ), (int) $id );
			// WhatsApp et SMS, FR et AR : une seule fenêtre « Message » (modèle suggéré selon l'étape).
			printf( '<a class="button codcrm-msg" href="#" data-order="%d">✉ %s</a> ', (int) $id, esc_html__( 'Message', 'cod-tracker-crm' ) );
		} else {
			echo '<em>' . esc_html__( 'Numéro invalide', 'cod-tracker-crm' ) . '</em>';
		}
		echo '</td></tr>';
	}
}
