<?php
defined( 'ABSPATH' ) || exit;

/** Page « Envois groupés » : liste des tâches, progression en direct, liste WhatsApp, actions. */
class CODCRM_Bulk_UI {

	const PAGE     = 'codcrm-bulk';
	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	public static function menu(): void {
		add_submenu_page( CODCRM_Work_Queue::PAGE_QUEUE, __( 'Envois groupés', 'cod-tracker-crm' ), __( 'Envois groupés', 'cod-tracker-crm' ), CODCRM_Agents::CAP_WORK, self::PAGE, [ __CLASS__, 'render' ] );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		$v = CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . 'assets/js/bulk.js' );
		wp_enqueue_style( 'codcrm-queue', CODCRM_URL . 'assets/css/work-queue.css?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . 'assets/css/work-queue.css' ), [], null );
		wp_enqueue_script( 'codcrm-bulk', CODCRM_URL . 'assets/js/bulk.js?v=' . $v, [ 'jquery' ], null, true );
		wp_localize_script( 'codcrm-bulk', 'codcrmBulk', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'codcrm' ),
			'job'   => absint( $_GET['job'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
			'error' => __( 'Erreur réseau.', 'cod-tracker-crm' ),
		] );
	}

	private static function bar( int $done, int $total ): string {
		$pct = $total ? min( 100, (int) round( 100 * $done / $total ) ) : 0;
		return '<div class="codcrm-bar"><span style="width:' . $pct . '%;background:#00a651"></span></div>';
	}

	public static function render(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}
		wp_enqueue_style( 'codcrm-dashboard', CODCRM_URL . 'assets/css/dashboard.css?v=' . CODCRM_VERSION, [], null );
		$job_id = absint( $_GET['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap codcrm-wrap"><h1>' . esc_html__( 'Envois groupés', 'cod-tracker-crm' ) . '</h1>';
		if ( $job_id ) {
			$job = CODCRM_Bulk::get_job( $job_id );
			if ( $job && CODCRM_Bulk::can_view( $job ) ) {
				self::render_job( $job );
			} else {
				echo '<p>' . esc_html__( 'Tâche introuvable.', 'cod-tracker-crm' ) . '</p>';
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		global $wpdb;
		$j   = CODCRM_Bulk::table_jobs();
		$mgr = current_user_can( CODCRM_Agents::CAP_MANAGE );
		$where = $mgr ? '1=1' : $wpdb->prepare( 'created_by = %d', get_current_user_id() );
		$jobs  = $wpdb->get_results( "SELECT * FROM {$j} WHERE {$where} ORDER BY id DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL

		echo '<p class="description">' . esc_html__( 'Pour créer un envoi : « Mes suivis » → cochez des colis → « Message groupé ». Les SMS partent en arrière-plan, par lots ; WhatsApp se fait depuis une liste de liens.', 'cod-tracker-crm' ) . '</p>';
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'Aucun envoi groupé pour le moment.', 'cod-tracker-crm' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Date', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Canal', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Situation', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Langue', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Créé par', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Progression', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Statut', 'cod-tracker-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $jobs as $job ) {
			$c    = CODCRM_Bulk::counts( (int) $job->id );
			$done = $c['sent'] + $c['failed'] + $c['skipped'];
			$u    = get_userdata( (int) $job->created_by );
			printf( '<tr><td><a href="%s"><strong>#%d</strong></a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d / %d %s</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&job=' . (int) $job->id ) ), (int) $job->id,
				esc_html( mysql2date( 'd/m H:i', $job->created_at ) ),
				esc_html( 'sms' === $job->channel ? 'SMS' : 'WhatsApp' ),
				esc_html( CODCRM_Templates::context_label( (string) $job->context ) ),
				esc_html( 'auto' === $job->lang ? __( 'Auto', 'cod-tracker-crm' ) : strtoupper( (string) $job->lang ) ),
				esc_html( $u ? $u->display_name : '—' ),
				$done, (int) $job->total, self::bar( $done, (int) $job->total ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( CODCRM_Bulk::status_label( (string) $job->status ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_job( object $job ): void {
		global $wpdb;
		$id  = (int) $job->id;
		$c   = CODCRM_Bulk::counts( $id );
		$wa  = 'whatsapp' === $job->channel;
		$u   = get_userdata( (int) $job->created_by );
		$tot = (int) $job->total;

		printf( '<p><a href="%s">← %s</a></p>', esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ), esc_html__( 'Tous les envois', 'cod-tracker-crm' ) );
		printf( '<h2>#%d — %s · %s · %s</h2>', $id, esc_html( $wa ? 'WhatsApp' : 'SMS' ), esc_html( CODCRM_Templates::context_label( (string) $job->context ) ), esc_html( 'auto' === $job->lang ? __( 'langue de chaque client', 'cod-tracker-crm' ) : strtoupper( (string) $job->lang ) ) );
		printf( '<p class="description">%s %s — %s</p>', esc_html__( 'Créé par', 'cod-tracker-crm' ), esc_html( $u ? $u->display_name : '—' ), esc_html( mysql2date( 'd/m/Y H:i', $job->created_at ) ) );

		echo '<div id="codcrm-bulk-box" data-wa="' . (int) $wa . '" data-status="' . esc_attr( (string) $job->status ) . '">';
		echo '<div class="codcrm-bulk-bar">' . self::bar( $c['sent'] + $c['failed'] + $c['skipped'], $tot ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p id="codcrm-bulk-sum"><strong id="codcrm-bulk-status">%s</strong> — %s <strong data-k="sent">%d</strong> · %s <strong data-k="pending">%d</strong> · %s <strong data-k="failed">%d</strong> · %s <strong data-k="skipped">%d</strong> / %d</p>',
			esc_html( CODCRM_Bulk::status_label( (string) $job->status ) ),
			esc_html( $wa ? __( 'ouverts', 'cod-tracker-crm' ) : __( 'envoyés', 'cod-tracker-crm' ) ), $c['sent'],
			esc_html__( 'en attente', 'cod-tracker-crm' ), $c['pending'] + $c['sending'],
			esc_html__( 'échecs', 'cod-tracker-crm' ), $c['failed'],
			esc_html__( 'ignorés', 'cod-tracker-crm' ), $c['skipped'], $tot );
		echo '</div>';
		if ( '' !== (string) $job->note ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $job->note ) . '</p></div>';
		}

		// Actions.
		$btn = static function ( string $do, string $label, string $confirm = '' ) use ( $id ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '><input type="hidden" name="action" value="codcrm_bulk_action" /><input type="hidden" name="job" value="' . (int) $id . '" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" />';
			wp_nonce_field( 'codcrm_bulk_' . $id );
			echo '<button class="button">' . esc_html( $label ) . '</button></form> ';
		};
		if ( 'running' === $job->status && ! $wa ) {
			$btn( 'pause', __( '⏸ Mettre en pause', 'cod-tracker-crm' ) );
		}
		if ( 'paused' === $job->status ) {
			$btn( 'resume', __( '▶ Reprendre', 'cod-tracker-crm' ) );
		}
		if ( in_array( $job->status, [ 'preparing', 'running', 'paused' ], true ) ) {
			$btn( 'cancel', __( '✕ Annuler les envois restants', 'cod-tracker-crm' ), __( 'Annuler tous les envois en attente ?', 'cod-tracker-crm' ) );
		}
		if ( ! $wa && $c['failed'] ) {
			$btn( 'retry', sprintf( /* translators: %d nombre */ __( '↻ Relancer les %d échecs', 'cod-tracker-crm' ), $c['failed'] ), __( 'Relancer les échecs ? Un SMS « état incertain » a peut-être déjà été reçu par le client.', 'cod-tracker-crm' ) );
		}

		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$filter = $wa ? "status IN ('pending','sent')" : "status IN ('failed','skipped','pending','sending')";
		$t      = CODCRM_Bulk::table_items();
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE job_id = %d AND {$filter}", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$paged  = min( $paged, max( 1, (int) ceil( $total / self::PER_PAGE ) ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, status, error FROM {$t} WHERE job_id = %d AND {$filter} ORDER BY id ASC LIMIT %d OFFSET %d", $id, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		echo '<h3>' . esc_html( $wa ? __( 'Colis à contacter', 'cod-tracker-crm' ) : __( 'En attente, échecs et ignorés', 'cod-tracker-crm' ) ) . '</h3>';
		if ( $wa ) {
			echo '<p class="description">' . esc_html__( 'WhatsApp ne permet pas l\'envoi automatique : cliquez sur chaque ligne ; le message prêt s\'ouvre dans WhatsApp et le contact est tracé dans la chronologie.', 'cod-tracker-crm' ) . '</p>';
		}
		if ( $rows ) {
			CODCRM_Work_Queue::tablenav( $total, self::PER_PAGE, $paged, 'top' );
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Rien à afficher.', 'cod-tracker-crm' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Commande', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Client', 'cod-tracker-crm' ) . '</th><th>' . esc_html__( 'Statut', 'cod-tracker-crm' ) . '</th><th></th></tr></thead><tbody>';
			$labels = [ 'pending' => __( 'À envoyer', 'cod-tracker-crm' ), 'sending' => __( 'En cours', 'cod-tracker-crm' ), 'sent' => __( 'Fait', 'cod-tracker-crm' ), 'failed' => __( 'Échec', 'cod-tracker-crm' ), 'skipped' => __( 'Ignoré', 'cod-tracker-crm' ) ];
			foreach ( $rows as $r ) {
				$o = wc_get_order( (int) $r->order_id );
				printf( '<tr data-order="%d"><td><a href="%s">#%d</a></td><td>%s</td><td class="codcrm-bulk-st">%s%s</td><td>',
					(int) $r->order_id,
					esc_url( $o ? $o->get_edit_order_url() : '#' ), (int) $r->order_id,
					esc_html( $o ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : '—' ),
					esc_html( $labels[ $r->status ] ?? $r->status ),
					'' !== $r->error ? ' <small>— ' . esc_html( $r->error ) . '</small>' : ''
				);
				if ( $wa && in_array( $r->status, [ 'pending', 'sent' ], true ) && 'cancelled' !== $job->status ) {
					echo '<a href="#" class="button button-small codcrm-bulk-wa">' . esc_html( 'pending' === $r->status ? '💬 ' . __( 'Ouvrir WhatsApp', 'cod-tracker-crm' ) : '↻ ' . __( 'Rouvrir', 'cod-tracker-crm' ) ) . '</a>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		CODCRM_Work_Queue::tablenav( $total, self::PER_PAGE, $paged, 'bottom' );
	}
}
