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
		add_action( 'wp_ajax_codcrm_queue_live', [ __CLASS__, 'ajax_live' ] );
		add_action( 'wp_ajax_codcrm_bulk_assign', [ __CLASS__, 'ajax_bulk_assign' ] );
	}

	// ── Affectation groupée (responsables) ────────────────────

	/** Liste « Affecter à… » + bouton, dans la barre d'actions groupées. */
	private static function assign_controls(): string {
		$h = ' <span class="codcrm-assign"><select class="codcrm-assign-to" disabled><option value="">' . esc_html__( '— Affecter à… —', 'cod-tracker-crm' ) . '</option>';
		foreach ( CODCRM_Agents::get_agents() as $u ) {
			$h .= '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
		}
		$h .= '<option value="0">' . esc_html__( 'Retirer l\'agent', 'cod-tracker-crm' ) . '</option></select>';
		$h .= ' <button type="button" class="button codcrm-assign-go" disabled>' . CODCRM_Icons::svg( 'user-plus' ) . ' ' . esc_html__( 'Affecter', 'cod-tracker-crm' ) . '</button></span>';
		return $h;
	}

	/** AJAX : affecte (ou désaffecte) les commandes cochées, ou tous les résultats du filtre (≤ 1000). */
	public static function ajax_bulk_assign(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'cod-tracker-crm' ) ], 403 );
		}
		$agent = (int) ( $_POST['agent'] ?? -1 );
		if ( $agent < 0 || ( $agent > 0 && ! user_can( $agent, CODCRM_Agents::CAP_WORK ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Choisissez un agent.', 'cod-tracker-crm' ) ] );
		}
		// « Tous les résultats » : le filtre de la page est dans l'URL de l'appel (comme les envois groupés).
		$ids = ! empty( $_POST['all'] )
			? self::filtered_ids( CODCRM_Bulk::MAX_ORDERS, 0 )
			: array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ) ) ), 0, CODCRM_Bulk::MAX_ORDERS );
		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'Aucune commande sélectionnée.', 'cod-tracker-crm' ) ] );
		}
		$done = 0;
		$same = 0;
		$uid  = get_current_user_id();
		foreach ( $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o ) {
				continue;
			}
			if ( CODCRM_Agents::agent_of( $o ) === $agent ) {
				$same++;
				continue;
			}
			if ( CODCRM_Agents::assign( $o, $agent, $uid, 'agent', __( 'Affectation groupée', 'cod-tracker-crm' ), 'manual' ) ) {
				if ( $agent ) {
					$o->delete_meta_data( CODCRM_Dispatcher::META_WAITING );
					$o->save_meta_data();
				}
				$done++;
			}
		}
		wp_send_json_success( [ 'done' => $done, 'same' => $same ] );
	}

	// ── Mode « en direct » ─────────────────────────────────────

	/** Sons proposés (générés par le navigateur, Web Audio) : clé => libellé. */
	public static function sounds(): array {
		return [
			'bell'   => __( 'Clochette', 'cod-tracker-crm' ),
			'chime'  => __( 'Carillon (3 notes)', 'cod-tracker-crm' ),
			'beep'   => __( 'Bip court', 'cod-tracker-crm' ),
			'double' => __( 'Double bip', 'cod-tracker-crm' ),
			'matricula' => __( 'Matricula (fichier audio)', 'cod-tracker-crm' ),
			'none'   => __( 'Aucun son', 'cod-tracker-crm' ),
		];
	}

	/** Sons qui sont des fichiers audio du plugin : clé => URL (les autres sont générés par le navigateur). */
	public static function sound_files(): array {
		return [ 'matricula' => CODCRM_URL . 'assets/sounds/matricula.mp3?v=' . CODCRM_VERSION ];
	}

	public static function live_sound(): string {
		$s = (string) get_option( 'codcrm_live_sound', 'bell' );
		return isset( self::sounds()[ $s ] ) ? $s : 'bell';
	}

	/** Son des alertes activé (réglage) ? */
	public static function sound_enabled(): bool {
		return 'yes' === get_option( 'codcrm_sound_enabled', 'yes' );
	}

	/** Son réellement joué : celui choisi, ou « none » si le son des alertes est désactivé. */
	public static function effective_sound(): string {
		return self::sound_enabled() ? self::live_sound() : 'none';
	}

	public static function live_volume(): int {
		return max( 0, min( 100, (int) get_option( 'codcrm_live_volume', 70 ) ) );
	}

	/** Repères temporels du serveur : UTC (date_updated_gmt) et heure de la base (created_at des événements). */
	private static function live_now(): array {
		global $wpdb;
		$r = $wpdb->get_row( 'SELECT UTC_TIMESTAMP() AS u, NOW() AS d', ARRAY_A );
		return [ 'utc' => (string) ( $r['u'] ?? gmdate( 'Y-m-d H:i:s' ) ), 'db' => (string) ( $r['d'] ?? current_time( 'mysql' ) ) ];
	}

	/**
	 * Interrogé toutes les 5 s par « Mes suivis » : lignes modifiées / nouvelles / retirées depuis le dernier passage.
	 * Le filtre de la page est rejoué (un agent ne voit jamais que ses commandes).
	 */
	public static function ajax_live(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [], 403 );
		}
		global $wpdb;
		$ts_ok = static function ( $v ) { return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ? $v : ''; };
		$since_u = $ts_ok( wp_unslash( $_POST['since_utc'] ?? '' ) );
		$since_d = $ts_ok( wp_unslash( $_POST['since_db'] ?? '' ) );
		$shown   = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ) ) ), 0, 100 );
		$page    = max( 1, absint( $_POST['paged'] ?? 1 ) );

		// Le filtre de la page est lu dans $_GET par filtered_ids_sql() (comme pour les envois groupés).
		$prev_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$_GET     = [
			'stage' => sanitize_text_field( wp_unslash( $_POST['stage'] ?? 'actif' ) ),
			'flt'   => sanitize_key( wp_unslash( $_POST['flt'] ?? '' ) ),
			'agent' => sanitize_text_field( wp_unslash( $_POST['agent'] ?? 'all' ) ),
		];
		try {
			$still = $shown ? self::filtered_ids_sql( count( $shown ), 0, false, $shown ) : [];
			if ( null === $still ) { // hors HPOS : mises à jour des lignes affichées seulement.
				$still = $shown;
				$top   = [];
				$total = 0;
			} else {
				$top   = 1 === $page ? (array) self::filtered_ids_sql( self::PER_PAGE, 0 ) : [];
				$total = self::filtered_total();
			}
			$removed = array_values( array_diff( $shown, $still ) );

			// Lignes modifiées : commande enregistrée ou nouvel événement (appel, message, attribution…).
			$changed = [];
			if ( $still && '' !== $since_u ) {
				$in      = implode( ',', array_map( 'absint', $still ) );
				$orders  = $wpdb->prefix . 'wc_orders';
				$events  = CODCRM_Installer::table_events();
				$changed = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
					"SELECT id FROM {$orders} WHERE id IN ({$in}) AND date_updated_gmt >= DATE_SUB(%s, INTERVAL 2 SECOND)
					 UNION SELECT order_id FROM {$events} WHERE order_id IN ({$in}) AND created_at >= DATE_SUB(%s, INTERVAL 2 SECOND)",
					$since_u,
					'' !== $since_d ? $since_d : $since_u
				) ) );
			}
			$added_ids = array_values( array_diff( array_map( 'intval', $top ), $shown ) );

			$render = array_values( array_unique( array_merge( $changed, $added_ids ) ) );
			$html   = [];
			$meta   = [];
			if ( $render ) {
				$orders_obj = array_values( array_filter( array_map( 'wc_get_order', $render ) ) );
				$tasks      = CODCRM_Tasks::next_open_for_orders( $render );
				$stats      = CODCRM_Customers::stats_for_phones( array_map( static function ( $o ) { return $o->get_billing_phone(); }, $orders_obj ) );
				$zero       = [ 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'calls' => 0, 'answered' => 0 ];
				foreach ( $orders_obj as $o ) {
					ob_start();
					self::render_row( $o, $tasks[ $o->get_id() ] ?? null, $stats[ self::norm_phone( $o->get_billing_phone() ) ] ?? $zero );
					$html[ $o->get_id() ] = ob_get_clean();
					$meta[ $o->get_id() ] = [ 'abandoned' => CODCRM_Abandoned::SLUG === $o->get_status(), 'phone' => $o->get_billing_phone(), 'number' => $o->get_order_number() ];
				}
			}
		} finally {
			$_GET = $prev_get;
		}

		wp_send_json_success( [
			'now'     => self::live_now(),
			'updated' => array_intersect_key( $html, array_flip( $changed ) ),
			'added'   => array_map( static function ( $id ) use ( $html, $meta ) { return [ 'id' => $id, 'html' => $html[ $id ] ?? '', 'meta' => $meta[ $id ] ?? [] ]; }, array_values( array_filter( $added_ids, static function ( $id ) use ( $html ) { return isset( $html[ $id ] ); } ) ) ),
			'removed' => $removed,
			'total'   => $total,
		] );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Suivi COD', 'cod-tracker-crm' ),
			__( 'Suivi COD', 'cod-tracker-crm' ),
			CODCRM_Agents::CAP_WORK,
			self::PAGE_QUEUE,
			[ __CLASS__, 'render_queue' ],
			CODCRM_Icon_Assets::menu_icon(),
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
		CODCRM_Icon_Assets::register();
		wp_enqueue_script( 'codcrm-sounds', self::asset( 'assets/js/sounds.js' ), [], null, true );
		wp_localize_script( 'codcrm-sounds', 'codcrmSoundFiles', self::sound_files() );
		wp_enqueue_script( 'codcrm-queue', self::asset( 'assets/js/work-queue.js' ), [ 'jquery', 'codcrm-sounds', 'codcrm-icons' ], null, true );
		wp_localize_script( 'codcrm-queue', 'codcrm', [
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'codcrm' ),
			'outcomes' => CODCRM_Contacts::call_outcomes(),
			'orderScreen' => $order_scr,
			'live'        => false !== strpos( $hook, self::PAGE_QUEUE ) ? [ 'now' => self::live_now(), 'interval' => 5000, 'sound' => self::effective_sound(), 'volume' => self::live_volume(), 'soundAbandoned' => 'yes' === get_option( 'codcrm_live_sound_abandoned', 'yes' ), 'perPage' => self::PER_PAGE ] : null,
			'contexts'    => array_diff_key( CODCRM_Templates::contexts(), [ 'custom' => 1 ] ),
			'sms'         => CODCRM_SMS_Gateway::is_configured(),
			'bulkMax'     => CODCRM_Bulk::MAX_ORDERS,
			'i18n'     => [
				'call_title'  => __( 'Résultat de l\'appel', 'cod-tracker-crm' ),
				'outcome'     => __( 'Résultat', 'cod-tracker-crm' ),
				'choose'      => __( '— Choisir —', 'cod-tracker-crm' ),
				'callback_at' => __( 'Rappeler le', 'cod-tracker-crm' ),
				'note'        => __( 'Commentaire', 'cod-tracker-crm' ),
				'save'        => __( 'Enregistrer', 'cod-tracker-crm' ),
				'bulk_title'  => __( 'Message groupé', 'cod-tracker-crm' ),
				'bulk_count'  => __( 'colis', 'cod-tracker-crm' ),
				'bulk_auto'   => __( 'Langue de chaque client (auto)', 'cod-tracker-crm' ),
				'bulk_preview' => __( 'Aperçu (premier colis sélectionné)', 'cod-tracker-crm' ),
				'bulk_sms_info' => __( 'Les SMS partent en arrière-plan, par lots ; vous pouvez fermer la page et suivre la progression dans « Envois groupés ».', 'cod-tracker-crm' ),
				'bulk_wa_info'  => __( 'WhatsApp ne permet pas l\'envoi automatique : une liste de liens à ouvrir un par un sera créée.', 'cod-tracker-crm' ),
				'bulk_create' => __( 'Créer l\'envoi', 'cod-tracker-crm' ),
				'bulk_confirm' => __( 'Créer cet envoi groupé ?', 'cod-tracker-crm' ),
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
				'msg_sent'    => __( 'Message envoyé et tracé dans la chronologie (actualisez la page pour la voir).', 'cod-tracker-crm' ),
				'cancel'      => __( 'Annuler', 'cod-tracker-crm' ),
				'error'       => __( 'Erreur réseau.', 'cod-tracker-crm' ),
				'assign_confirm' => __( 'Affecter %1$s commande(s) à %2$s ?', 'cod-tracker-crm' ),
				'assign_done' => __( '%d commande(s) affectée(s).', 'cod-tracker-crm' ),
				'live_on'     => __( 'En direct', 'cod-tracker-crm' ),
				'live_off'    => __( 'En pause', 'cod-tracker-crm' ),
				'live_new'    => __( 'Nouvelle commande', 'cod-tracker-crm' ),
				'live_abandoned' => __( 'Commande abandonnée', 'cod-tracker-crm' ),
				'live_enable_sound' => __( 'Activer le son', 'cod-tracker-crm' ),
				'live_mute'   => __( 'Couper le son sur ce navigateur', 'cod-tracker-crm' ),
				'ed_title'    => __( 'Modifier la commande n°', 'cod-tracker-crm' ),
				'ed_locked'   => __( 'Colis déjà créé chez le transporteur : client, produits, frais et transporteur sont verrouillés (le transporteur n\'est pas mis à jour).', 'cod-tracker-crm' ),
				'ed_unsync_confirm' => __( 'Retirer le code à barre ? La commande repassera à « Confirmée » et pourra être modifiée, puis re-synchronisée. Si le transporteur n\'autorise pas l\'annulation, supprimez aussi le colis dans son espace expéditeur.', 'cod-tracker-crm' ),
				'ed_open_dashboard' => __( 'Ouvrir l\'espace expéditeur', 'cod-tracker-crm' ),
				'ed_unsync_local' => __( 'Je l\'ai supprimé chez le transporteur — retirer le code ici', 'cod-tracker-crm' ),
				'ed_unsync_local_confirm' => __( 'Retirer le code de la commande ? Si le colis existe encore chez le transporteur, il sera livré quand même : supprimez-le d\'abord là-bas.', 'cod-tracker-crm' ),
				'ed_not_deleted' => __( 'Le colis n\'est PAS supprimé chez le transporteur', 'cod-tracker-crm' ),
				'ed_unsync_done' => __( 'Colis supprimé. Commande remise à « Confirmée ».', 'cod-tracker-crm' ),
				'ed_unsaved'  => __( 'Fermer sans enregistrer les modifications ?', 'cod-tracker-crm' ),
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
		} elseif ( 'abandoned' === $flt ) {
			$args['status'] = CODCRM_Abandoned::SLUG;
		} elseif ( 'waiting' === $flt ) {
			$meta[] = [ 'key' => CODCRM_Dispatcher::META_WAITING, 'value' => '1' ];
		} elseif ( 'risk' === $flt ) {
			$meta[] = [ 'key' => CODCRM_Attempts::META_RISK, 'value' => '1' ];
		}

		$args = [
			'type'       => 'shop_order',
			'status'     => array_keys( wc_get_order_statuses() ),
			// Pas de COUNT : sur une grosse boutique il coûtait ~50 s (jointures meta). On lit une
			// ligne de plus pour savoir s'il existe une page suivante.
			'limit'      => self::PER_PAGE + 1,
			'offset'     => ( $paged - 1 ) * self::PER_PAGE,
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

	/** Identifiants de tous les colis du filtre en cours (envoi groupé « tous les résultats », lu par tranches : la requête complète est lente). */
	public static function filtered_ids( int $limit, int $offset = 0 ): array {
		$fast = self::filtered_ids_sql( $limit, $offset );
		if ( null !== $fast ) {
			return $fast;
		}
		[ $args ]       = self::build_query( 1 );
		$args['limit']  = $limit;
		$args['offset'] = $offset;
		$args['return'] = 'ids';
		$ids            = wc_get_orders( $args );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/** Nombre total de colis du filtre (COUNT instantané en SQL direct ; repli lent hors HPOS). */
	public static function filtered_total(): int {
		$fast = self::filtered_ids_sql( 0, 0, true );
		if ( null !== $fast ) {
			return $fast[0];
		}
		[ $args ]         = self::build_query( 1 );
		$args['limit']    = 1;
		$args['paginate'] = true;
		$res              = wc_get_orders( $args );
		return is_object( $res ) ? (int) $res->total : 0;
	}

	/**
	 * Pagination à la WordPress (comme les listes natives) : « N éléments », « ‹‹ ‹ Page X sur Y › »» ».
	 */
	public static function tablenav( int $total, int $per_page, int $paged, string $which = 'top' ): void {
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$link  = static function ( int $p, string $label, string $title, bool $on ) {
			return $on
				? sprintf( '<a class="%s-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">%s</span></a>', 'first' === $title ? 'first' : ( 'last' === $title ? 'last' : ( 'prev' === $title ? 'prev' : 'next' ) ), esc_url( add_query_arg( 'paged', $p ) ), esc_html( $title ), esc_html( $label ) )
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . esc_html( $label ) . '</span>';
		};
		echo '<div class="tablenav ' . esc_attr( $which ) . '"><div class="tablenav-pages' . ( $pages < 2 ? ' one-page' : '' ) . '">';
		echo '<span class="displaying-num">' . esc_html( sprintf( /* translators: %s nombre */ _n( '%s élément', '%s éléments', $total, 'cod-tracker-crm' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo '<span class="pagination-links">';
			echo $link( 1, '«', 'first', $paged > 1 ) . "
"; // phpcs:ignore WordPress.Security.EscapeOutput
			echo $link( max( 1, $paged - 1 ), '‹', 'prev', $paged > 1 ) . "
"; // phpcs:ignore WordPress.Security.EscapeOutput
			// Champ « numéro de page » (Entrée pour aller à la page), comme les listes WordPress.
			$hidden = '';
			foreach ( (array) $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( 'paged' !== $k && is_scalar( $v ) ) {
					$hidden .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
				}
			}
			echo '<form method="get" class="paging-input" style="display:inline">' . $hidden // phpcs:ignore WordPress.Security.EscapeOutput
				. '<label for="codcrm-page-' . esc_attr( $which ) . '" class="screen-reader-text">' . esc_html__( 'Page actuelle', 'cod-tracker-crm' ) . '</label>'
				. '<input class="current-page" id="codcrm-page-' . esc_attr( $which ) . '" type="text" name="paged" value="' . (int) $paged . '" size="' . strlen( (string) $pages ) . '" inputmode="numeric" />'
				. '<span class="tablenav-paging-text"> ' . esc_html__( 'sur', 'cod-tracker-crm' ) . ' <span class="total-pages">' . esc_html( number_format_i18n( $pages ) ) . '</span></span></form>';
			echo $link( min( $pages, $paged + 1 ), '›', 'next', $paged < $pages ) . "
"; // phpcs:ignore WordPress.Security.EscapeOutput
			echo $link( $pages, '»', 'last', $paged < $pages ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</span>';
		}
		echo '</div><br class="clear" /></div>';
	}

	/**
	 * Même filtre que build_query() en SQL direct (EXISTS sur les métadonnées indexées) : ~100× plus
	 * rapide que wc_get_orders() avec ses jointures. Uniquement si les commandes sont dans les tables
	 * HPOS ; sinon null (repli sur wc_get_orders).
	 *
	 * @return int[]|null
	 */
	private static function filtered_ids_sql( int $limit, int $offset, bool $count = false, array $only_ids = [] ): ?array {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) || ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return null;
		}
		global $wpdb;
		$o = $wpdb->prefix . 'wc_orders';
		$m = $wpdb->prefix . 'wc_orders_meta';

		$is_manager = current_user_can( CODCRM_Agents::CAP_MANAGE );
		$stage = sanitize_text_field( wp_unslash( $_GET['stage'] ?? 'actif' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$flt   = sanitize_key( wp_unslash( $_GET['flt'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$agent = $is_manager ? sanitize_text_field( wp_unslash( $_GET['agent'] ?? 'all' ) ) : (string) get_current_user_id(); // phpcs:ignore WordPress.Security.NonceVerification

		$has = static function ( string $key, string $extra = '' ) use ( $wpdb, $m, $o ): string {
			return $wpdb->prepare( "EXISTS (SELECT 1 FROM {$m} x WHERE x.order_id = {$o}.id AND x.meta_key = %s", $key ) . ( $extra ? " AND {$extra}" : '' ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL
		};

		$statuses = array_keys( wc_get_order_statuses() );
		$where    = [
			"{$o}.type = 'shop_order'",
			$wpdb->prepare( "{$o}.status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses ), // phpcs:ignore WordPress.DB.PreparedSQL
			'(' . implode( ' OR ', [
				$has( '_wcmcs_sync_status', $wpdb->prepare( 'x.meta_value = %s', 'synced' ) ),
				$has( CODCRM_Agents::META_AGENT ),
				$has( CODCRM_Dispatcher::META_WAITING, $wpdb->prepare( 'x.meta_value = %s', '1' ) ),
			] ) . ')',
		];

		$stage_key = CODCRM_Event_Collector::META_STAGE;
		if ( 'actif' === $stage ) {
			$term    = CODCRM_Stages::TERMINAL;
			$where[] = 'NOT ' . $has( $stage_key, $wpdb->prepare( 'x.meta_value IN (' . implode( ',', array_fill( 0, count( $term ), '%s' ) ) . ')', $term ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} elseif ( 'tous' !== $stage && isset( CODCRM_Stages::all()[ $stage ] ) ) {
			$where[] = $has( $stage_key, $wpdb->prepare( 'x.meta_value = %s', $stage ) );
		}

		$agent_for_tasks = 0;
		if ( 'none' === $agent ) {
			$where[] = 'NOT ' . $has( CODCRM_Agents::META_AGENT );
		} elseif ( ctype_digit( $agent ) && (int) $agent > 0 ) {
			$where[]         = $has( CODCRM_Agents::META_AGENT, $wpdb->prepare( 'x.meta_value = %s', (string) (int) $agent ) );
			$agent_for_tasks = (int) $agent;
		}

		if ( 'failed' === $flt ) {
			$where[] = $has( CODCRM_Attempts::META_FAILED, 'CAST(x.meta_value AS SIGNED) >= 1' );
		} elseif ( 'abandoned' === $flt ) {
			$where[] = $wpdb->prepare( "{$o}.status = %s", CODCRM_Abandoned::STATUS ); // phpcs:ignore WordPress.DB.PreparedSQL
		} elseif ( 'waiting' === $flt ) {
			$where[] = $has( CODCRM_Dispatcher::META_WAITING, $wpdb->prepare( 'x.meta_value = %s', '1' ) );
		} elseif ( 'risk' === $flt ) {
			$where[] = $has( CODCRM_Attempts::META_RISK, $wpdb->prepare( 'x.meta_value = %s', '1' ) );
		} elseif ( in_array( $flt, [ 'due', 'overdue' ], true ) ) {
			$ids     = array_map( 'intval', CODCRM_Tasks::order_ids_due( 'due' === $flt ? 'today' : 'overdue', $agent_for_tasks ) ) ?: [ 0 ];
			$where[] = "{$o}.id IN (" . implode( ',', $ids ) . ')';
		}

		if ( $only_ids ) { // parmi ces commandes seulement (mode « en direct » : celles qui sont toujours dans le filtre).
			$where[] = "{$o}.id IN (" . implode( ',', array_map( 'absint', $only_ids ) ) . ')';
		}

		if ( $count ) {
			return [ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$o} WHERE " . implode( ' AND ', $where ) ) ]; // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$sql = "SELECT {$o}.id FROM {$o} WHERE " . implode( ' AND ', $where ) . $wpdb->prepare( " ORDER BY {$o}.id DESC LIMIT %d OFFSET %d", $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
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
		// Identifiants par SQL direct (instantané), puis chargement des seules 25 commandes affichées.
		$total      = self::filtered_total();
		$paged      = min( $paged, max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
		$ids        = self::filtered_ids( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$orders     = array_values( array_filter( array_map( 'wc_get_order', $ids ) ) );
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
			'risk'    => __( 'Risque de retour', 'cod-tracker-crm' ),
			'abandoned' => __( 'Commandes abandonnées', 'cod-tracker-crm' ),
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
		echo '</form>';

		// Barre d'actions groupées (sélection par cases à cocher).
		echo '<div class="codcrm-bulkbar"><strong class="codcrm-selcount">0</strong> ' . esc_html__( 'sélectionné(s)', 'cod-tracker-crm' )
			. ' <button type="button" class="button button-primary codcrm-bulk-open" disabled>' . CODCRM_Icons::svg( 'chat-bubble-left-right' ) . ' ' . esc_html__( 'Message groupé', 'cod-tracker-crm' ) . '</button>'
			. ( $is_manager ? self::assign_controls() : '' )
			. ' <a href="#" class="codcrm-sel-all" style="display:none">' . esc_html( sprintf( /* translators: %d plafond */ __( 'Sélectionner tous les résultats du filtre (jusqu\'à %d)', 'cod-tracker-crm' ), CODCRM_Bulk::MAX_ORDERS ) ) . '</a>'
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CODCRM_Bulk_UI::PAGE ) ) . '">' . esc_html__( 'Voir les envois groupés', 'cod-tracker-crm' ) . '</a></div>';

		self::tablenav( $total, self::PER_PAGE, $paged, 'top' );
		echo '<table class="widefat striped codcrm-table"><thead><tr><td class="check-column"><input type="checkbox" class="codcrm-cb-all" /></td>';
		foreach ( [ __( 'Commande', 'cod-tracker-crm' ), __( 'Client', 'cod-tracker-crm' ), __( 'Produits', 'cod-tracker-crm' ), __( 'Statut', 'cod-tracker-crm' ), __( 'Tentatives', 'cod-tracker-crm' ), __( 'Dernier contact', 'cod-tracker-crm' ), __( 'Prochaine tâche', 'cod-tracker-crm' ), __( 'Agent', 'cod-tracker-crm' ), __( 'Actions', 'cod-tracker-crm' ) ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $orders ) {
			echo '<tr><td colspan="10">' . esc_html__( 'Aucun colis à afficher avec ces filtres.', 'cod-tracker-crm' ) . '</td></tr>';
		}

		$stats = CODCRM_Customers::stats_for_phones( array_map( static function ( $o ) { return $o->get_billing_phone(); }, $orders ) );
		$zero  = [ 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'calls' => 0, 'answered' => 0 ];
		foreach ( $orders as $order ) {
			self::render_row( $order, $tasks[ $order->get_id() ] ?? null, $stats[ self::norm_phone( $order->get_billing_phone() ) ] ?? $zero );
		}
		echo '</tbody></table>';

		self::tablenav( $total, self::PER_PAGE, $paged, 'bottom' );
		echo '</div>';
	}

	public static function render_row( WC_Order $order, $task, ?array $stats = null ): void {
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

		echo '<tr data-order="' . (int) $id . '"><th class="check-column"><input type="checkbox" class="codcrm-cb" value="' . (int) $id . '"' . ( $phone8 ? '' : ' data-nophone="1"' ) . ' /></th>';
		$slug = (string) $order->get_meta( '_wcmcs_carrier_slug' );
		$logo = $slug && class_exists( 'WCMCS_Carrier_Detector' ) ? WCMCS_Carrier_Detector::get_logo_url( $slug ) : '';
		printf( '<td><a href="%s"><strong>#%s</strong></a>%s<br><small>%s%s</small></td>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			'1' === (string) $order->get_meta( CODCRM_Order_Editor::META_EXCHANGE ) ? ' <span class="codcrm-badge-ex">' . esc_html__( 'Échange', 'cod-tracker-crm' ) . '</span>' : '',
			$logo ? '<img class="codcrm-carrier-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $slug ) . '" title="' . esc_attr( $slug ) . '" /> · ' : ( $slug ? esc_html( $slug ) . ' · ' : '' ),
			esc_html( wc_format_decimal( $order->get_total(), 2 ) . ' DT' )
		);

		// Client + historique par téléphone (statistiques groupées, voir CODCRM_Customers).
		$stats = $stats ?? ( CODCRM_Customers::stats_for_phones( [ $phone ] )[ $phone8 ] ?? null );
		$ph2   = (string) $order->get_meta( CODCRM_Order_Editor::META_PHONE2 );
		printf( '<td><strong>%s</strong><br><small>%s%s<br>%s</small>%s</td>',
			esc_html( $client ),
			esc_html( $phone ),
			$ph2 ? ' / ' . esc_html( $ph2 ) : '',
			esc_html( $order->get_billing_state() ?: $order->get_billing_city() ),
			CODCRM_Customers::badges( $order, $stats ) // phpcs:ignore WordPress.Security.EscapeOutput
		);

		// Produits (vignette + pastille de quantité).
		$thumbs = '';
		$n      = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( ++$n > 3 ) {
				continue;
			}
			$img    = CODCRM_Customers::item_image( $item );
			$attrs  = CODCRM_Customers::item_attrs( $item );
			$title  = trim( $item->get_name() . ( $attrs ? ' (' . $attrs . ')' : '' ) ) . ' × ' . (int) $item->get_quantity();
			$thumbs .= '<span class="codcrm-prod' . ( $item->get_variation_id() || $order->get_status() !== 'codcrm-abandoned' ? '' : ' is-partial' ) . '" title="' . esc_attr( $title ) . '">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="" />' : '<span class="codcrm-noimg">' . CODCRM_Icons::svg( 'cube' ) . '</span>' ) . '<b>' . (int) $item->get_quantity() . '</b></span>';
		}
		if ( $n > 3 ) {
			$thumbs .= '<span class="codcrm-prod-more">+' . ( $n - 3 ) . '</span>';
		}
		echo '<td class="codcrm-prods">' . ( $thumbs ?: '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

		printf( '<td>%s</td>', $stage
			? '<span class="codcrm-pill codcrm-status-pill" data-order="' . (int) $id . '" style="' . esc_attr( CODCRM_Stages::badge_style( CODCRM_Stages::color( $stage ) ) ) . '">' . esc_html( CODCRM_Stages::label( $stage ) ) . '</span>'
			: '—' );

		printf( '<td>%d / %d%s</td>', (int) $order->get_meta( CODCRM_Attempts::META_ATTEMPTS ), CODCRM_Attempts::threshold(), $risk ? ' <span class="codcrm-pill" style="background:#c0392b">' . CODCRM_Icons::svg( 'exclamation-triangle' ) . '</span>' : '' );
		printf( '<td>%s</td>', $last ? esc_html( human_time_diff( strtotime( $last ), current_time( 'timestamp' ) ) ) : '—' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		unset( $failed );

		if ( $task ) {
			printf( '<td class="%s">%s<br><small>%s</small> <a href="#" class="codcrm-done" data-task="%d">' . CODCRM_Icons::svg( 'check' ) . '</a></td>',
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
		printf( '<a class="codcrm-act codcrm-edit" href="#" data-order="%d" title="%s">' . CODCRM_Icons::svg( 'pencil-square' ) . ' %s</a>', (int) $id, esc_attr__( 'Modifier la commande', 'cod-tracker-crm' ), esc_html__( 'Modifier', 'cod-tracker-crm' ) );
		if ( $phone8 ) {
			printf( '<a class="codcrm-act codcrm-call" href="tel:+216%s" data-order="%d" title="%s" aria-label="%3$s">' . CODCRM_Icons::svg( 'phone' ) . '</a>', esc_attr( $phone8 ), (int) $id, esc_attr__( 'Appeler (résultat tracé)', 'cod-tracker-crm' ) );
			// WhatsApp et SMS, FR et AR : une seule fenêtre « Message » (modèle suggéré selon l'étape).
			printf( '<a class="codcrm-act codcrm-msg" href="#" data-order="%d" title="%s" aria-label="%2$s">' . CODCRM_Icons::svg( 'chat-bubble-left-right' ) . '</a>', (int) $id, esc_attr__( 'Envoyer un message (WhatsApp / SMS)', 'cod-tracker-crm' ) );
		} else {
			echo '<br><em class="codcrm-muted">' . esc_html__( 'Numéro invalide', 'cod-tracker-crm' ) . '</em>';
		}
		echo '</td></tr>';
	}
}
