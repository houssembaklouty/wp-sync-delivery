<?php
defined( 'ABSPATH' ) || exit;

/**
 * Envois groupés (SMS / WhatsApp) avec file d'attente.
 *
 * SMS : traités en arrière-plan par lots (cron chaque minute) via la passerelle ; WhatsApp : impossible
 * en automatique (wa.me exige un clic) → liste « à envoyer » dont chaque clic est tracé.
 * Garde-fous : verrou par tâche, cadence (taille de lot), 3 essais par SMS, jamais de double envoi
 * (un SMS « en cours d'envoi » interrompu est marqué incertain, pas renvoyé), pause automatique si la
 * passerelle échoue en série, pause / reprise / annulation / relance des échecs.
 */
class CODCRM_Bulk {

	const HOOK       = 'codcrm_bulk_tick';
	const MAX_ORDERS = 1000;
	const MAX_TRIES  = 3;
	const TIME_LIMIT = 40; // secondes de traitement par passage.

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		add_action( self::HOOK, [ __CLASS__, 'tick' ] );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'codcrm_1min', self::HOOK );
		}
		add_action( 'wp_ajax_codcrm_bulk_create', [ __CLASS__, 'ajax_create' ] );
		add_action( 'wp_ajax_codcrm_bulk_status', [ __CLASS__, 'ajax_status' ] );
		add_action( 'wp_ajax_codcrm_bulk_wa', [ __CLASS__, 'ajax_wa' ] );
		add_action( 'admin_post_codcrm_bulk_action', [ __CLASS__, 'handle_action' ] );
	}

	public static function cron_schedules( array $s ): array {
		$s['codcrm_1min'] = [ 'interval' => 60, 'display' => 'COD CRM — 1 minute' ];
		return $s;
	}

	public static function table_jobs(): string {
		global $wpdb;
		return $wpdb->prefix . 'codcrm_bulk_jobs';
	}

	public static function table_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'codcrm_bulk_items';
	}

	public static function batch_size(): int {
		return max( 1, min( 200, (int) get_option( 'codcrm_bulk_batch', 20 ) ) );
	}

	// ── Création ───────────────────────────────────────────────

	/**
	 * @param int[] $order_ids
	 * @return array{job:int,added:int,rejected:int}|WP_Error
	 */
	public static function create_job( string $channel, string $context, string $lang, array $order_ids, int $user_id, bool $validate_only = false ) {
		global $wpdb;
		if ( ! in_array( $channel, CODCRM_Templates::CHANNELS, true ) ) {
			return new WP_Error( 'channel', __( 'Canal invalide.', 'cod-tracker-crm' ) );
		}
		if ( 'sms' === $channel && ! CODCRM_SMS_Gateway::is_configured() ) {
			return new WP_Error( 'sms', __( 'Passerelle SMS non configurée.', 'cod-tracker-crm' ) );
		}
		if ( ! isset( CODCRM_Templates::contexts()[ $context ] ) || 'custom' === $context ) {
			return new WP_Error( 'context', __( 'Choisissez une situation (modèle).', 'cod-tracker-crm' ) );
		}
		if ( 'auto' !== $lang && ! in_array( $lang, CODCRM_Templates::LANGS, true ) ) {
			return new WP_Error( 'lang', __( 'Langue invalide.', 'cod-tracker-crm' ) );
		}

		if ( $validate_only ) {
			return [ 'job' => 0, 'added' => 0, 'rejected' => 0 ];
		}
		$ids      = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		$manager  = user_can( $user_id, CODCRM_Agents::CAP_MANAGE );
		$rejected = 0;
		if ( ! $manager ) {
			// Un agent ne peut écrire qu'aux clients de ses commandes.
			$keep = [];
			foreach ( $ids as $id ) {
				$o = wc_get_order( $id );
				if ( $o && CODCRM_Agents::agent_of( $o ) === $user_id ) {
					$keep[] = $id;
				} else {
					$rejected++;
				}
			}
			$ids = $keep;
		}
		if ( count( $ids ) > self::MAX_ORDERS ) {
			$rejected += count( $ids ) - self::MAX_ORDERS;
			$ids       = array_slice( $ids, 0, self::MAX_ORDERS );
		}
		if ( ! $ids ) {
			return new WP_Error( 'empty', __( 'Aucune commande valide sélectionnée.', 'cod-tracker-crm' ) );
		}

		$wpdb->insert( self::table_jobs(), [
			'created_by' => $user_id,
			'channel'    => $channel,
			'context'    => $context,
			'lang'       => $lang,
			'status'     => 'running',
			'total'      => count( $ids ),
			'created_at' => current_time( 'mysql' ),
		] );
		$job = (int) $wpdb->insert_id;
		if ( ! $job ) {
			return new WP_Error( 'db', __( 'Création impossible.', 'cod-tracker-crm' ) );
		}

		$t = self::table_items();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$vals = [];
			foreach ( $chunk as $id ) {
				$vals[] = $wpdb->prepare( '(%d,%d)', $job, $id );
			}
			$wpdb->query( "INSERT IGNORE INTO {$t} (job_id, order_id) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		if ( 'sms' === $channel ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
		do_action( 'codcrm_bulk_created', $job, $channel, $context, count( $ids ) );
		return [ 'job' => $job, 'added' => count( $ids ), 'rejected' => $rejected ];
	}

	/**
	 * « Tous les résultats du filtre » : la liste complète est lente à calculer (jointures sur les
	 * métadonnées). La tâche est créée tout de suite en « préparation » ; le cron lit le filtre par
	 * tranches (voir prepare_job) puis la tâche passe en « en cours ».
	 *
	 * @return array{job:int,added:int,rejected:int}|WP_Error
	 */
	public static function create_job_from_filter( string $channel, string $context, string $lang, array $filter, int $user_id ) {
		global $wpdb;
		// Validations communes via une création vide (rien n'est inséré si invalide).
		$res = self::create_job( $channel, $context, $lang, [ 1 ], $user_id, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$wpdb->insert( self::table_jobs(), [
			'created_by' => $user_id,
			'channel'    => $channel,
			'context'    => $context,
			'lang'       => $lang,
			'status'     => 'preparing',
			'total'      => 0,
			'filter'     => wp_json_encode( [ 'get' => $filter, 'offset' => 0 ] ),
			'created_at' => current_time( 'mysql' ),
		] );
		$job = (int) $wpdb->insert_id;
		wp_schedule_single_event( time() + 3, self::HOOK );
		return [ 'job' => $job, 'added' => 0, 'rejected' => 0 ];
	}

	/** Une tranche de la liste : lit, insère, et passe en « en cours » à la fin. */
	private static function prepare_job( object $job ): void {
		global $wpdb;
		$lock = 'codcrm_lock_bulk_' . (int) $job->id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 300 ) {
				return;
			}
			update_option( $lock, time(), false );
		}
		$prev_user = get_current_user_id();
		$prev_get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			$f      = json_decode( (string) $job->filter, true ) ?: [];
			$offset = (int) ( $f['offset'] ?? 0 );
			// build_query() lit le filtre dans $_GET et le rôle de l'utilisateur (un agent ne voit que ses colis).
			wp_set_current_user( (int) $job->created_by );
			$_GET = array_intersect_key( (array) ( $f['get'] ?? [] ), array_flip( [ 'stage', 'flt', 'agent' ] ) );
			$chunk = 200;
			$ids   = CODCRM_Work_Queue::filtered_ids( $chunk, $offset );

			$t    = self::table_items();
			$room = self::MAX_ORDERS - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE job_id = %d", (int) $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( array_slice( $ids, 0, max( 0, $room ) ) as $id ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (job_id, order_id) VALUES (%d, %d)", (int) $job->id, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE job_id = %d", (int) $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$last  = count( $ids ) < $chunk || $total >= self::MAX_ORDERS;
			$f['offset'] = $offset + $chunk;
			$row = [ 'total' => $total, 'filter' => wp_json_encode( $f ) ];
			if ( $last ) {
				$row['status'] = $total ? 'running' : 'done';
				if ( ! $total ) {
					$row['finished_at'] = current_time( 'mysql' );
					$row['note']        = __( 'Aucun colis ne correspond au filtre.', 'cod-tracker-crm' );
				}
			}
			$wpdb->update( self::table_jobs(), $row, [ 'id' => (int) $job->id ] );
		} finally {
			$_GET = $prev_get;
			wp_set_current_user( $prev_user );
			delete_option( $lock );
		}
	}

	// ── Lecture ────────────────────────────────────────────────

	public static function get_job( int $id ): ?object {
		global $wpdb;
		$j = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_jobs() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $j ?: null;
	}

	public static function can_view( object $job ): bool {
		return current_user_can( CODCRM_Agents::CAP_MANAGE ) || ( current_user_can( CODCRM_Agents::CAP_WORK ) && (int) $job->created_by === get_current_user_id() );
	}

	/** @return array<string,int> pending|sending|sent|failed|skipped */
	public static function counts( int $job ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . self::table_items() . ' WHERE job_id = %d GROUP BY status', $job ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$c    = [ 'pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];
		foreach ( (array) $rows as $r ) {
			$c[ $r->status ] = (int) $r->n;
		}
		return $c;
	}

	public static function status_label( string $s ): string {
		$l = [
			'preparing' => __( 'Préparation de la liste…', 'cod-tracker-crm' ),
			'running'   => __( 'En cours', 'cod-tracker-crm' ),
			'paused'    => __( 'En pause', 'cod-tracker-crm' ),
			'done'      => __( 'Terminé', 'cod-tracker-crm' ),
			'cancelled' => __( 'Annulé', 'cod-tracker-crm' ),
		];
		return $l[ $s ] ?? $s;
	}

	// ── Traitement (file d'attente SMS) ────────────────────────

	/** Passage du cron : traite un lot par tâche SMS en cours. */
	public static function tick(): void {
		global $wpdb;
		update_option( 'codcrm_bulk_last_tick', time(), false );

		// SMS restés « en cours d'envoi » (processus interrompu) : état incertain, jamais renvoyés.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"UPDATE " . self::table_items() . " SET status = 'failed', error = %s WHERE status = 'sending' AND processed_at < %s",
			__( 'Envoi interrompu : état incertain (vérifiez avant de relancer).', 'cod-tracker-crm' ),
			gmdate( 'Y-m-d H:i:s', time() - 600 )
		) );

		// Listes « tous les résultats du filtre » en cours de préparation : une tranche par passage.
		foreach ( (array) $wpdb->get_results( "SELECT * FROM " . self::table_jobs() . " WHERE status = 'preparing' ORDER BY id ASC LIMIT 3" ) as $pj ) { // phpcs:ignore WordPress.DB.PreparedSQL
			self::prepare_job( $pj );
		}

		$jobs = $wpdb->get_col( "SELECT id FROM " . self::table_jobs() . " WHERE status = 'running' AND channel = 'sms' ORDER BY id ASC LIMIT 5" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$deadline = microtime( true ) + self::TIME_LIMIT;
		foreach ( (array) $jobs as $id ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			self::process_job( (int) $id, $deadline );
		}
	}

	private static function process_job( int $job_id, float $deadline ): void {
		global $wpdb;
		$lock = 'codcrm_lock_bulk_' . $job_id;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 120 ) {
				return;
			}
			update_option( $lock, time(), false );
		}
		try {
			$job = self::get_job( $job_id );
			if ( ! $job || 'running' !== $job->status ) {
				return;
			}
			$t     = self::table_items();
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, tries FROM {$t} WHERE job_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d", $job_id, self::batch_size() ) ); // phpcs:ignore WordPress.DB.PreparedSQL

			$fails = 0;
			foreach ( (array) $items as $it ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				// Annulation / pause demandée pendant le lot.
				if ( 'running' !== $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::table_jobs() . ' WHERE id = %d', $job_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
					return;
				}
				$res = self::send_item( $job, $it );
				$fails = ( 'failed_try' === $res ) ? $fails + 1 : 0;
				if ( $fails >= 5 ) {
					// Passerelle manifestement en panne : on met en pause au lieu de brûler les essais.
					$wpdb->update( self::table_jobs(), [ 'status' => 'paused', 'note' => __( 'Pause automatique : la passerelle SMS échoue en série.', 'cod-tracker-crm' ) ], [ 'id' => $job_id ] );
					return;
				}
			}

			if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE job_id = %d AND status IN ('pending','sending')", $job_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->update( self::table_jobs(), [ 'status' => 'done', 'finished_at' => current_time( 'mysql' ) ], [ 'id' => $job_id, 'status' => 'running' ] );
			}
		} finally {
			delete_option( $lock );
		}
	}

	/** @return string sent|skipped|failed|failed_try */
	private static function send_item( object $job, object $it ): string {
		global $wpdb;
		$t = self::table_items();
		$set = static function ( string $status, string $error = '', ?int $tries = null ) use ( $wpdb, $t, $it ): void {
			$row = [ 'status' => $status, 'error' => mb_substr( $error, 0, 255 ), 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ];
			if ( null !== $tries ) {
				$row['tries'] = $tries;
			}
			$wpdb->update( $t, $row, [ 'id' => (int) $it->id ] );
		};

		$order = wc_get_order( (int) $it->order_id );
		if ( ! $order ) {
			$set( 'skipped', __( 'Commande introuvable.', 'cod-tracker-crm' ) );
			return 'skipped';
		}
		if ( '' === CODCRM_Work_Queue::norm_phone( $order->get_billing_phone() ) ) {
			$set( 'skipped', __( 'Numéro invalide.', 'cod-tracker-crm' ) );
			return 'skipped';
		}
		$lang = 'auto' === $job->lang ? CODCRM_Templates::detect_lang( $order ) : (string) $job->lang;
		$text = CODCRM_Templates::message( $order, (string) $job->context, $lang, 'sms' );
		if ( '' === trim( $text ) || mb_strlen( $text ) > 480 ) {
			$set( 'skipped', __( 'Modèle vide ou trop long (> 480).', 'cod-tracker-crm' ) );
			return 'skipped';
		}

		// Marqué AVANT l'envoi : un crash ne provoquera jamais un second SMS.
		$wpdb->update( $t, [ 'status' => 'sending', 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => (int) $it->id ] );
		$res = CODCRM_SMS_Gateway::send_for_order( $order, $text, 'agent', (int) $job->created_by, '', [ 'context' => (string) $job->context, 'lang' => $lang, 'bulk' => (int) $job->id ] );

		if ( $res['ok'] ) {
			$set( 'sent', '' );
			return 'sent';
		}
		$tries = (int) $it->tries + 1;
		if ( $tries >= self::MAX_TRIES ) {
			$set( 'failed', $res['message'], $tries );
			return 'failed';
		}
		$set( 'pending', $res['message'], $tries ); // nouvel essai au prochain passage.
		return 'failed_try';
	}

	// ── AJAX ───────────────────────────────────────────────────

	private static function guard(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}
	}

	/** Création depuis « Mes suivis » : ids cochés, ou tous les résultats du filtre en cours. */
	public static function ajax_create(): void {
		self::guard();
		if ( ! empty( $_POST['all'] ) ) {
			$ids = null;
		} else {
			$ids = array_map( 'absint', (array) wp_unslash( $_POST['ids'] ?? [] ) );
		}
		$args = [
			sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['context'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['lang'] ?? 'auto' ) ),
		];
		if ( null === $ids ) {
			$filter = [
				'stage' => sanitize_text_field( wp_unslash( $_GET['stage'] ?? 'actif' ) ), // phpcs:ignore WordPress.Security.NonceVerification
				'flt'   => sanitize_key( wp_unslash( $_GET['flt'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
				'agent' => sanitize_text_field( wp_unslash( $_GET['agent'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			];
			$res = self::create_job_from_filter( $args[0], $args[1], $args[2], $filter, get_current_user_id() );
		} else {
			$res = self::create_job( $args[0], $args[1], $args[2], $ids, get_current_user_id() );
		}
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		$res['url'] = admin_url( 'admin.php?page=' . CODCRM_Bulk_UI::PAGE . '&job=' . $res['job'] );
		wp_send_json_success( $res );
	}

	/** Progression (sondage de la page). Relance un passage si le cron semble à l'arrêt. */
	public static function ajax_status(): void {
		self::guard();
		$job = self::get_job( absint( $_POST['job'] ?? 0 ) );
		if ( ! $job || ! self::can_view( $job ) ) {
			wp_send_json_error( [ 'message' => __( 'Tâche introuvable.', 'cod-tracker-crm' ) ], 404 );
		}
		if ( ( 'preparing' === $job->status || ( 'running' === $job->status && 'sms' === $job->channel ) ) && (int) get_option( 'codcrm_bulk_last_tick', 0 ) < time() - 120 ) {
			self::tick(); // WP-Cron désactivé / sans visite : on avance pendant que la page est ouverte.
			$job = self::get_job( (int) $job->id );
		}
		wp_send_json_success( [ 'status' => $job->status, 'label' => self::status_label( $job->status ), 'counts' => self::counts( (int) $job->id ), 'total' => (int) $job->total, 'note' => (string) $job->note ] );
	}

	/** WhatsApp : prépare le lien d'un colis de la liste, trace le contact et marque l'élément. */
	public static function ajax_wa(): void {
		global $wpdb;
		self::guard();
		$job = self::get_job( absint( $_POST['job'] ?? 0 ) );
		$t   = self::table_items();
		$it  = $job ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE job_id = %d AND order_id = %d", (int) $job->id, absint( $_POST['order_id'] ?? 0 ) ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $job || ! $it || 'whatsapp' !== $job->channel || ! self::can_view( $job ) ) {
			wp_send_json_error( [ 'message' => __( 'Élément introuvable.', 'cod-tracker-crm' ) ], 404 );
		}
		// Envoi annulé, ou élément ignoré : plus de lien (ni contact tracé, ni changement d'état de la tâche).
		if ( 'cancelled' === $job->status || ! in_array( $it->status, [ 'pending', 'sent' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Cet envoi a été annulé.', 'cod-tracker-crm' ) ] );
		}
		$order = wc_get_order( (int) $it->order_id );
		$phone = $order ? CODCRM_Work_Queue::norm_phone( $order->get_billing_phone() ) : '';
		if ( ! $order || '' === $phone ) {
			$wpdb->update( $t, [ 'status' => 'skipped', 'error' => __( 'Numéro invalide.', 'cod-tracker-crm' ), 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => (int) $it->id ] );
			wp_send_json_error( [ 'message' => __( 'Numéro invalide.', 'cod-tracker-crm' ) ] );
		}

		$lang = 'auto' === $job->lang ? CODCRM_Templates::detect_lang( $order ) : (string) $job->lang;
		$text = CODCRM_Templates::message( $order, (string) $job->context, $lang, 'whatsapp' );

		if ( 'sent' !== $it->status ) {
			CODCRM_Events::add( $order->get_id(), [
				'type'       => 'contact',
				'channel'    => 'whatsapp',
				'outcome'    => 'envoye',
				'note'       => sprintf( '%s (%s)', CODCRM_Templates::context_label( (string) $job->context ), strtoupper( $lang ) ),
				'actor_id'   => get_current_user_id(),
				'source'     => 'agent',
				'dedupe_key' => 'bulk:' . (int) $job->id,
				'payload'    => [ 'context' => (string) $job->context, 'lang' => $lang, 'bulk' => (int) $job->id ],
			] );
			$order->update_meta_data( CODCRM_Contacts::META_LAST_CONTACT, current_time( 'mysql' ) );
			$order->save_meta_data();
			$wpdb->update( $t, [ 'status' => 'sent', 'error' => '', 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => (int) $it->id ] );
			if ( ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE job_id = %d AND status = 'pending'", (int) $job->id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->update( self::table_jobs(), [ 'status' => 'done', 'finished_at' => current_time( 'mysql' ) ], [ 'id' => (int) $job->id ] );
			}
		}
		wp_send_json_success( [ 'url' => 'https://wa.me/216' . $phone . '?text=' . rawurlencode( $text ) ] );
	}

	/** Pause / reprise / annulation / relance des échecs (admin-post + nonce). */
	public static function handle_action(): void {
		global $wpdb;
		$id  = absint( $_POST['job'] ?? 0 );
		$act = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		check_admin_referer( 'codcrm_bulk_' . $id );
		$job = self::get_job( $id );
		if ( ! $job || ! self::can_view( $job ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		$t = self::table_items();
		$j = self::table_jobs();
		switch ( $act ) {
			case 'pause':
				$wpdb->update( $j, [ 'status' => 'paused' ], [ 'id' => $id, 'status' => 'running' ] );
				break;
			case 'resume':
				$wpdb->update( $j, [ 'status' => 'running', 'note' => '' ], [ 'id' => $id, 'status' => 'paused' ] );
				wp_schedule_single_event( time() + 5, self::HOOK );
				break;
			case 'cancel':
				if ( in_array( $job->status, [ 'preparing', 'running', 'paused' ], true ) ) {
					$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'skipped', error = %s WHERE job_id = %d AND status = 'pending'", __( 'Annulé', 'cod-tracker-crm' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					$wpdb->update( $j, [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
				}
				break;
			case 'retry':
				// Relance manuelle de TOUS les échecs, y compris « état incertain » (la confirmation affichée
				// prévient que le client a peut-être déjà reçu ces SMS). Le cron, lui, ne les renvoie jamais.
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'pending', tries = 0, error = '' WHERE job_id = %d AND status = 'failed'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->update( $j, [ 'status' => 'running', 'finished_at' => null, 'note' => '' ], [ 'id' => $id ] );
				wp_schedule_single_event( time() + 5, self::HOOK );
				break;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . CODCRM_Bulk_UI::PAGE . '&job=' . $id ) );
		exit;
	}
}
