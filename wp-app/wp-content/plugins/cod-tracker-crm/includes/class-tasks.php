<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rappels / relances des agents (table codcrm_tasks).
 */
class CODCRM_Tasks {

	const KIND_CALLBACK  = 'callback';
	const KIND_FOLLOWUP  = 'attempt_followup';
	const KIND_STUCK     = 'stuck_parcel';
	const KIND_CASH      = 'cash_dispute';
	const KIND_PICKUP    = 'pickup_pending';
	const KIND_RETURN    = 'return_missing';

	public static function init(): void {
		add_action( 'codcrm_attempt_failed', [ __CLASS__, 'on_attempt_failed' ], 10, 3 );
	}

	public static function kind_label( string $kind ): string {
		$map = [
			self::KIND_CALLBACK => __( 'Rappel client', 'cod-tracker-crm' ),
			self::KIND_FOLLOWUP => __( 'Relance après échec', 'cod-tracker-crm' ),
			self::KIND_STUCK    => __( 'Colis bloqué', 'cod-tracker-crm' ),
			self::KIND_CASH     => __( 'Litige cash', 'cod-tracker-crm' ),
			self::KIND_PICKUP   => __( 'Enlèvement en attente', 'cod-tracker-crm' ),
			self::KIND_RETURN   => __( 'Retour non reçu', 'cod-tracker-crm' ),
		];
		return $map[ $kind ] ?? $kind;
	}

	/**
	 * Crée une tâche. Les relances automatiques (attempt_followup / stuck_parcel)
	 * ne sont créées qu'une fois tant qu'une tâche du même type reste ouverte.
	 *
	 * @return int ID de la tâche, 0 si ignorée.
	 */
	public static function create( int $order_id, int $agent_id, string $kind, string $due_at, string $note = '', int $created_by = 0 ): int {
		global $wpdb;
		$table = CODCRM_Installer::table_tasks();

		if ( in_array( $kind, [ self::KIND_FOLLOWUP, self::KIND_STUCK, self::KIND_PICKUP, self::KIND_RETURN ], true ) ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND kind = %s AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL
				$order_id,
				$kind
			) );
			if ( $exists ) {
				return 0;
			}
		}

		$ok = $wpdb->insert( $table, [
			'order_id'   => $order_id,
			'agent_id'   => $agent_id,
			'kind'       => $kind,
			'status'     => 'open',
			'due_at'     => $due_at,
			'note'       => substr( sanitize_text_field( $note ), 0, 255 ),
			'created_by' => $created_by,
			'created_at' => current_time( 'mysql' ),
		], [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ] );

		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		CODCRM_Events::add( $order_id, [
			'type'     => 'task',
			'outcome'  => 'created',
			'note'     => sprintf( '%s — %s', self::kind_label( $kind ), mysql2date( 'd/m/Y H:i', $due_at ) ),
			'actor_id' => $created_by,
			'source'   => $created_by ? 'agent' : 'system',
			'payload'  => [ 'task_id' => $id, 'kind' => $kind, 'agent_id' => $agent_id ],
		] );

		return $id;
	}

	/** Marque une tâche faite. L'appelant vérifie les droits. */
	public static function complete( int $task_id, int $actor_id ): bool {
		global $wpdb;
		$table = CODCRM_Installer::table_tasks();
		$task  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $task || 'open' !== $task->status ) {
			return false;
		}
		$wpdb->update( $table, [ 'status' => 'done', 'done_at' => current_time( 'mysql' ) ], [ 'id' => $task_id ], [ '%s', '%s' ], [ '%d' ] );

		CODCRM_Events::add( (int) $task->order_id, [
			'type'     => 'task',
			'outcome'  => 'done',
			'note'     => self::kind_label( (string) $task->kind ),
			'actor_id' => $actor_id,
			'source'   => 'agent',
			'payload'  => [ 'task_id' => $task_id ],
		] );
		return true;
	}

	/** Clôture les tâches ouvertes d'un type donné sur une commande (ex. rappel traité par un appel). */
	public static function complete_open( int $order_id, array $kinds, int $actor_id ): int {
		global $wpdb;
		$table = CODCRM_Installer::table_tasks();
		if ( ! $kinds ) {
			return 0;
		}
		$ph  = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE order_id = %d AND status = 'open' AND kind IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( [ $order_id ], $kinds )
		) );
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$n += self::complete( (int) $id, $actor_id ) ? 1 : 0;
		}
		return $n;
	}

	public static function reassign_open( int $order_id, int $agent_id ): void {
		global $wpdb;
		$wpdb->update( CODCRM_Installer::table_tasks(), [ 'agent_id' => $agent_id ], [ 'order_id' => $order_id, 'status' => 'open' ], [ '%d' ], [ '%d', '%s' ] );
	}

	/** Tâche ouverte la plus proche par commande. @return array<int,object> order_id => tâche */
	public static function next_open_for_orders( array $order_ids ): array {
		global $wpdb;
		$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
		if ( ! $order_ids ) {
			return [];
		}
		$table = CODCRM_Installer::table_tasks();
		$ph    = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'open' AND order_id IN ({$ph}) ORDER BY due_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			$order_ids
		) );
		$out = [];
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $out[ (int) $r->order_id ] ) ) {
				$out[ (int) $r->order_id ] = $r;
			}
		}
		return $out;
	}

	/**
	 * Commandes ayant une tâche ouverte échue.
	 *
	 * @param string $mode  'today' (échéance ≤ fin de journée) | 'overdue' (échéance passée).
	 * @param int    $agent 0 = tous les agents.
	 * @return int[]
	 */
	public static function order_ids_due( string $mode, int $agent = 0 ): array {
		global $wpdb;
		$table = CODCRM_Installer::table_tasks();
		$limit = 'overdue' === $mode ? current_time( 'mysql' ) : current_time( 'Y-m-d' ) . ' 23:59:59';
		$sql   = "SELECT DISTINCT order_id FROM {$table} WHERE status = 'open' AND due_at <= %s";
		$args  = [ $limit ];
		if ( $agent ) {
			$sql   .= ' AND agent_id = %d';
			$args[] = $agent;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Tâche automatique : une tentative a échoué. */
	public static function on_attempt_failed( $order, $failed, $at_risk ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$agent = CODCRM_Agents::agent_of( $order );
		if ( (int) $failed >= 2 || $at_risk ) {
			self::create(
				$order->get_id(),
				$agent,
				self::KIND_FOLLOWUP,
				current_time( 'mysql' ),
				sprintf( __( 'Échec n°%d — joindre le client avant la prochaine tournée', 'cod-tracker-crm' ), (int) $failed )
			);
		}
		if ( $at_risk ) {
			$to = (string) get_option( 'admin_email' );
			if ( $to ) {
				wp_mail(
					$to,
					sprintf( '[COD] Risque de retour — commande #%s', $order->get_order_number() ),
					sprintf(
						"La commande #%s a %d tentative(s) de livraison échouée(s) (seuil %d).\nAgent : %s\n%s",
						$order->get_order_number(),
						(int) $failed,
						CODCRM_Attempts::threshold(),
						CODCRM_Agents::agent_name( $agent ) ?: '—',
						$order->get_edit_order_url()
					)
				);
			}
		}
	}
}
