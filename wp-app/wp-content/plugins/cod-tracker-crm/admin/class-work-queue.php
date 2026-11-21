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
		add_action( 'admin_post_codcrm_save_settings', [ __CLASS__, 'save_settings' ] );
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
		add_submenu_page( self::PAGE_QUEUE, __( 'Réglages', 'cod-tracker-crm' ), __( 'Réglages', 'cod-tracker-crm' ), CODCRM_Agents::CAP_MANAGE, self::PAGE_SETTINGS, [ __CLASS__, 'render_settings' ] );
	}

	/** URL d'asset avec cache-busting par mtime (les mu-plugins retirent ?ver=). */
	private static function asset( string $rel ): string {
		return CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_QUEUE ) ) {
			return;
		}
		wp_enqueue_style( 'codcrm-queue', self::asset( 'assets/css/work-queue.css' ), [], null );
		wp_enqueue_script( 'codcrm-queue', self::asset( 'assets/js/work-queue.js' ), [ 'jquery' ], null, true );
		wp_localize_script( 'codcrm-queue', 'codcrm', [
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'codcrm' ),
			'outcomes' => CODCRM_Contacts::call_outcomes(),
			'smsTpl'   => CODCRM_SMS_Gateway::is_configured() ? self::sms_template_options() : [],
			'i18n'     => [
				'call_title'  => __( 'Résultat de l\'appel', 'cod-tracker-crm' ),
				'outcome'     => __( 'Résultat', 'cod-tracker-crm' ),
				'choose'      => __( '— Choisir —', 'cod-tracker-crm' ),
				'callback_at' => __( 'Rappeler le', 'cod-tracker-crm' ),
				'note'        => __( 'Commentaire', 'cod-tracker-crm' ),
				'save'        => __( 'Enregistrer', 'cod-tracker-crm' ),
				'sms_title'   => __( 'Envoyer un SMS', 'cod-tracker-crm' ),
				'sms_send'    => __( 'Envoyer', 'cod-tracker-crm' ),
				'sms_text'    => __( 'Message (modifiable)', 'cod-tracker-crm' ),
				'sms_tpl'     => __( 'Modèle', 'cod-tracker-crm' ),
				'cancel'      => __( 'Annuler', 'cod-tracker-crm' ),
				'error'       => __( 'Erreur réseau.', 'cod-tracker-crm' ),
			],
		] );
	}

	/** Modèles SMS proposés à l'agent (bruts, avec placeholders ; rendus côté serveur à l'envoi). */
	private static function sms_template_options(): array {
		$out = [];
		foreach ( CODCRM_SMS_Gateway::triggers() as $k => $label ) {
			$out[] = [ 'label' => $label, 'text' => CODCRM_SMS_Gateway::template( $k ) ];
		}
		return $out;
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

		$meta = [ 'relation' => 'AND', [ 'key' => '_wcmcs_sync_status', 'value' => 'synced' ] ];

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

	/** Modèles WhatsApp (option) avec défauts FR + AR. @return array<int,array{label:string,text:string}> */
	public static function wa_templates(): array {
		$saved = get_option( 'codcrm_wa_templates' );
		if ( is_array( $saved ) && $saved ) {
			return $saved;
		}
		return [
			[ 'label' => 'FR', 'text' => "Bonjour {client}, votre commande #{commande} ({montant} DT) est en cours de livraison avec {transporteur}. Pouvez-vous confirmer que vous serez disponible ? Merci !" ],
			[ 'label' => 'AR', 'text' => "مرحبا {client}، طلبيتك رقم {commande} بقيمة {montant} د.ت في طريقها إليك مع {transporteur}. المرجو تأكيد توفرك لاستلامها. شكرا!" ],
		];
	}

	private static function wa_url( WC_Order $order, string $phone8, string $template ): string {
		$text = CODCRM_Contacts::render_template( $order, $template );
		return 'https://wa.me/216' . $phone8 . '?text=' . rawurlencode( $text );
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
		$templates  = self::wa_templates();

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
			self::render_row( $order, $tasks[ $order->get_id() ] ?? null, $templates );
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

	private static function render_row( WC_Order $order, $task, array $templates ): void {
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
			if ( CODCRM_SMS_Gateway::is_configured() ) {
				printf( '<a class="button codcrm-sms" href="#" data-order="%d" title="SMS">✉ SMS</a> ', (int) $id );
			}
			foreach ( $templates as $t ) {
				printf( '<a class="button codcrm-wa" href="#" data-order="%d" data-url="%s" title="WhatsApp %s">💬 %s</a> ', (int) $id, esc_url( self::wa_url( $order, $phone8, (string) $t['text'] ) ), esc_attr( $t['label'] ), esc_html( $t['label'] ) );
			}
		} else {
			echo '<em>' . esc_html__( 'Numéro invalide', 'cod-tracker-crm' ) . '</em>';
		}
		echo '</td></tr>';
	}

	// ── Réglages ───────────────────────────────────────────────

	public static function render_settings(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}
		$active = CODCRM_Agents::active_agent_ids();

		echo '<div class="wrap"><h1>' . esc_html__( 'Suivi COD — réglages', 'cod-tracker-crm' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Réglages enregistrés.', 'cod-tracker-crm' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="codcrm_save_settings" />';
		wp_nonce_field( 'codcrm_settings' );
		echo '<table class="form-table">';

		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="auto_assign" value="yes"%s /> %s</label></td></tr>',
			esc_html__( 'Assignation automatique', 'cod-tracker-crm' ),
			checked( 'yes', get_option( 'codcrm_auto_assign', 'no' ), false ),
			esc_html__( 'À la création du colis, assigner la commande aux agents actifs à tour de rôle.', 'cod-tracker-crm' )
		);

		echo '<tr><th>' . esc_html__( 'Agents actifs', 'cod-tracker-crm' ) . '</th><td>';
		$agents = CODCRM_Agents::get_agents();
		if ( ! $agents ) {
			echo '<em>' . esc_html__( 'Aucun agent : créez des utilisateurs avec le rôle « Agent suivi COD » (Utilisateurs → Ajouter).', 'cod-tracker-crm' ) . '</em>';
		}
		foreach ( $agents as $u ) {
			printf( '<label style="display:block"><input type="checkbox" name="agents[]" value="%d"%s /> %s</label>', (int) $u->ID, checked( in_array( (int) $u->ID, $active, true ), true, false ), esc_html( $u->display_name ) );
		}
		echo '</td></tr>';

		printf( '<tr><th>%s</th><td><input type="number" min="1" max="10" name="threshold" value="%d" class="small-text" /> <span class="description">%s</span></td></tr>',
			esc_html__( 'Seuil de tentatives', 'cod-tracker-crm' ),
			CODCRM_Attempts::threshold(),
			esc_html__( 'Nombre d\'échecs de livraison avant « risque de retour » (défaut 3).', 'cod-tracker-crm' )
		);

		echo '</table>';

		self::render_sms_settings();

		submit_button();
		echo '</form>';

		// Test d'envoi (formulaire séparé).
		if ( CODCRM_SMS_Gateway::is_configured() ) {
			echo '<h2>' . esc_html__( 'Tester la passerelle', 'cod-tracker-crm' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="codcrm_sms_test" />';
			wp_nonce_field( 'codcrm_sms_test' );
			echo '<input type="text" name="test_phone" placeholder="55123456" /> ';
			submit_button( __( 'Envoyer un SMS test', 'cod-tracker-crm' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function render_sms_settings(): void {
		$o = static function ( string $k, $d = '' ) { return get_option( $k, $d ); };

		echo '<h2>' . esc_html__( 'Passerelle SMS', 'cod-tracker-crm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Compatible avec tout fournisseur offrant une API HTTP. Placeholders : {to} {message} {sender} {api_key}.', 'cod-tracker-crm' ) . '</p>';
		if ( isset( $_GET['sms_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$r = sanitize_text_field( wp_unslash( $_GET['sms_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice %s"><p>%s</p></div>', 'ok' === $r ? 'notice-success' : 'notice-error', esc_html( 'ok' === $r ? __( 'SMS test envoyé.', 'cod-tracker-crm' ) : $r ) );
		}

		echo '<table class="form-table">';
		printf( '<tr><th>%s</th><td><label><input type="checkbox" name="sms_enabled" value="yes"%s /> %s</label></td></tr>', esc_html__( 'Activer', 'cod-tracker-crm' ), checked( 'yes', $o( 'codcrm_sms_enabled', 'no' ), false ), esc_html__( 'Autoriser l\'envoi de SMS', 'cod-tracker-crm' ) );
		printf( '<tr><th>URL</th><td><input type="url" class="large-text" name="sms_url" value="%s" placeholder="https://api.fournisseur.tn/sms/send" /></td></tr>', esc_attr( (string) $o( 'codcrm_sms_url' ) ) );
		printf( '<tr><th>%s</th><td><select name="sms_method"><option value="POST"%s>POST</option><option value="GET"%s>GET</option></select> <select name="sms_format"><option value="form"%s>form-urlencoded</option><option value="json"%s>JSON</option></select></td></tr>',
			esc_html__( 'Méthode / format', 'cod-tracker-crm' ),
			selected( 'POST', $o( 'codcrm_sms_method', 'POST' ), false ), selected( 'GET', $o( 'codcrm_sms_method', 'POST' ), false ),
			selected( 'form', $o( 'codcrm_sms_format', 'form' ), false ), selected( 'json', $o( 'codcrm_sms_format', 'form' ), false )
		);
		printf( '<tr><th>%s</th><td><textarea name="sms_params" rows="5" class="large-text code" placeholder="to={to}&#10;text={message}&#10;from={sender}">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Paramètres', 'cod-tracker-crm' ), esc_textarea( (string) $o( 'codcrm_sms_params' ) ), esc_html__( 'Un par ligne : clé=valeur.', 'cod-tracker-crm' ) );
		printf( '<tr><th>%s</th><td><textarea name="sms_headers" rows="2" class="large-text code" placeholder="Authorization: Bearer {api_key}">%s</textarea></td></tr>', esc_html__( 'En-têtes', 'cod-tracker-crm' ), esc_textarea( (string) $o( 'codcrm_sms_headers' ) ) );
		printf( '<tr><th>%s</th><td><input type="password" name="sms_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="%s" /></td></tr>', esc_html__( 'Clé API / token', 'cod-tracker-crm' ), '' !== (string) $o( 'codcrm_sms_api_key' ) ? esc_attr__( '•••••• (laisser vide pour conserver)', 'cod-tracker-crm' ) : '' );
		printf( '<tr><th>%s</th><td><input type="text" name="sms_sender" value="%s" class="regular-text" /></td></tr>', esc_html__( 'Expéditeur', 'cod-tracker-crm' ), esc_attr( (string) $o( 'codcrm_sms_sender' ) ) );
		printf( '<tr><th>%s</th><td><select name="sms_phone"><option value="216"%s>216XXXXXXXX</option><option value="+216"%s>+216XXXXXXXX</option><option value="local"%s>XXXXXXXX</option></select></td></tr>',
			esc_html__( 'Format du numéro {to}', 'cod-tracker-crm' ),
			selected( '216', $o( 'codcrm_sms_phone', '216' ), false ), selected( '+216', $o( 'codcrm_sms_phone', '216' ), false ), selected( 'local', $o( 'codcrm_sms_phone', '216' ), false )
		);
		printf( '<tr><th>%s</th><td><input type="text" name="sms_success" value="%s" class="regular-text" /><p class="description">%s</p></td></tr>', esc_html__( 'Texte de succès', 'cod-tracker-crm' ), esc_attr( (string) $o( 'codcrm_sms_success' ) ), esc_html__( 'Texte à retrouver dans la réponse du fournisseur. Vide = HTTP 2xx suffit.', 'cod-tracker-crm' ) );

		echo '<tr><th>' . esc_html__( 'Envois automatiques', 'cod-tracker-crm' ) . '</th><td>';
		$auto = (array) $o( 'codcrm_sms_auto', [] );
		foreach ( CODCRM_SMS_Gateway::triggers() as $k => $label ) {
			printf( '<p><label><input type="checkbox" name="sms_auto[]" value="%1$s"%2$s /> <strong>%3$s</strong></label><br><textarea name="sms_tpl[%1$s]" rows="2" class="large-text">%4$s</textarea></p>',
				esc_attr( $k ), checked( in_array( $k, $auto, true ), true, false ), esc_html( $label ), esc_textarea( CODCRM_SMS_Gateway::template( $k ) ) );
		}
		echo '<p class="description">' . esc_html__( 'Désactivés par défaut. Un seul SMS par déclencheur et par tournée. Placeholders : {client} {commande} {suivi} {transporteur} {montant}.', 'cod-tracker-crm' ) . '</p>';
		echo '</td></tr></table>';
	}

	public static function save_settings(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( 'codcrm_settings' );

		update_option( 'codcrm_auto_assign', isset( $_POST['auto_assign'] ) ? 'yes' : 'no' );
		update_option( 'codcrm_active_agents', array_map( 'absint', (array) wp_unslash( $_POST['agents'] ?? [] ) ) );
		update_option( 'codcrm_attempts_threshold', max( 1, min( 10, absint( $_POST['threshold'] ?? 3 ) ) ) );

		// Passerelle SMS.
		update_option( 'codcrm_sms_enabled', isset( $_POST['sms_enabled'] ) ? 'yes' : 'no' );
		update_option( 'codcrm_sms_url', esc_url_raw( trim( wp_unslash( $_POST['sms_url'] ?? '' ) ) ) );
		update_option( 'codcrm_sms_method', 'GET' === ( $_POST['sms_method'] ?? '' ) ? 'GET' : 'POST' );
		update_option( 'codcrm_sms_format', 'json' === ( $_POST['sms_format'] ?? '' ) ? 'json' : 'form' );
		update_option( 'codcrm_sms_params', sanitize_textarea_field( wp_unslash( $_POST['sms_params'] ?? '' ) ) );
		update_option( 'codcrm_sms_headers', sanitize_textarea_field( wp_unslash( $_POST['sms_headers'] ?? '' ) ) );
		update_option( 'codcrm_sms_sender', sanitize_text_field( wp_unslash( $_POST['sms_sender'] ?? '' ) ) );
		update_option( 'codcrm_sms_success', sanitize_text_field( wp_unslash( $_POST['sms_success'] ?? '' ) ) );
		$phone_fmt = sanitize_text_field( wp_unslash( $_POST['sms_phone'] ?? '216' ) );
		update_option( 'codcrm_sms_phone', in_array( $phone_fmt, [ '216', '+216', 'local' ], true ) ? $phone_fmt : '216' );
		$key = trim( (string) wp_unslash( $_POST['sms_api_key'] ?? '' ) );
		if ( '' !== $key ) { // vide = on conserve la clé existante.
			update_option( 'codcrm_sms_api_key', $key, false );
		}
		$valid_triggers = array_keys( CODCRM_SMS_Gateway::triggers() );
		update_option( 'codcrm_sms_auto', array_values( array_intersect( $valid_triggers, array_map( 'sanitize_key', (array) wp_unslash( $_POST['sms_auto'] ?? [] ) ) ) ) );
		$tpl = [];
		foreach ( $valid_triggers as $k ) {
			$tpl[ $k ] = sanitize_textarea_field( wp_unslash( $_POST['sms_tpl'][ $k ] ?? '' ) );
		}
		update_option( 'codcrm_sms_templates', $tpl );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SETTINGS, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
