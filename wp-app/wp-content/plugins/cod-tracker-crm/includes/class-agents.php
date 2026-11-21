<?php
defined( 'ABSPATH' ) || exit;

/**
 * Agents de suivi COD : rôle, assignation des commandes, round-robin.
 */
class CODCRM_Agents {

	const ROLE         = 'cod_agent';
	const CAP_WORK     = 'codcrm_work';   // travailler sa file de suivi.
	const CAP_MANAGE   = 'codcrm_manage'; // assigner, réglages, voir toutes les files.
	const META_AGENT   = '_codcrm_agent_id';

	public static function init(): void {
		add_action( 'wcmcs_order_synced', [ __CLASS__, 'auto_assign_on_sync' ], 20, 1 );
	}

	/** Crée le rôle agent et donne les capacités au propriétaire (idempotent). */
	public static function install_roles(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Agent suivi COD', 'cod-tracker-crm' ), [
				'read'         => true,
				self::CAP_WORK => true,
			] );
		}
		foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP_WORK );
				$role->add_cap( self::CAP_MANAGE );
			}
		}
	}

	/** @return WP_User[] */
	public static function get_agents(): array {
		return get_users( [ 'role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC' ] );
	}

	/** Agents actifs pour l'auto-assignation (option ; défaut : tous). @return int[] */
	public static function active_agent_ids(): array {
		$all = array_map( static function ( $u ) { return (int) $u->ID; }, self::get_agents() );
		$sel = get_option( 'codcrm_active_agents', null );
		if ( ! is_array( $sel ) ) {
			return $all;
		}
		return array_values( array_intersect( $all, array_map( 'intval', $sel ) ) );
	}

	public static function agent_of( WC_Order $order ): int {
		return (int) $order->get_meta( self::META_AGENT );
	}

	public static function agent_name( int $agent_id ): string {
		$u = $agent_id ? get_userdata( $agent_id ) : false;
		return $u ? $u->display_name : '';
	}

	/** Le user courant peut-il travailler cette commande ? (manager : toutes ; agent : les siennes) */
	public static function can_handle( WC_Order $order, int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		if ( user_can( $user_id, self::CAP_MANAGE ) ) {
			return true;
		}
		return user_can( $user_id, self::CAP_WORK ) && self::agent_of( $order ) === $user_id;
	}

	/**
	 * (Ré)assigne une commande. $agent_id = 0 : retire l'assignation.
	 *
	 * @return bool True si l'assignation a changé.
	 */
	public static function assign( WC_Order $order, int $agent_id, int $actor_id = 0, string $source = 'agent' ): bool {
		$old = self::agent_of( $order );
		if ( $old === $agent_id ) {
			return false;
		}
		if ( $agent_id && ! user_can( $agent_id, self::CAP_WORK ) ) {
			return false;
		}

		if ( $agent_id ) {
			$order->update_meta_data( self::META_AGENT, $agent_id );
		} else {
			$order->delete_meta_data( self::META_AGENT );
		}
		$order->save_meta_data();

		CODCRM_Events::add( $order->get_id(), [
			'type'     => 'assignment',
			'note'     => $agent_id
				? sprintf( __( 'Assignée à %s', 'cod-tracker-crm' ), self::agent_name( $agent_id ) )
				: __( 'Assignation retirée', 'cod-tracker-crm' ),
			'actor_id' => $actor_id,
			'source'   => $source,
			'payload'  => [ 'from' => $old, 'to' => $agent_id ],
		] );

		// Les tâches ouvertes suivent le nouvel agent.
		CODCRM_Tasks::reassign_open( $order->get_id(), $agent_id );

		do_action( 'codcrm_order_assigned', $order, $agent_id, $old );
		return true;
	}

	/** Round-robin à la synchro du colis (option codcrm_auto_assign = yes). */
	public static function auto_assign_on_sync( $order ): void {
		if ( 'yes' !== get_option( 'codcrm_auto_assign', 'no' ) || ! $order instanceof WC_Order ) {
			return;
		}
		if ( self::agent_of( $order ) ) {
			return; // déjà assignée.
		}
		$next = self::next_round_robin();
		if ( $next ) {
			self::assign( $order, $next, 0, 'system' );
		}
	}

	/** Prochain agent actif (curseur circulaire stocké en option). */
	public static function next_round_robin(): int {
		$ids = self::active_agent_ids();
		if ( ! $ids ) {
			return 0;
		}
		sort( $ids );
		$last = (int) get_option( 'codcrm_rr_cursor', 0 );
		$next = $ids[0];
		foreach ( $ids as $id ) {
			if ( $id > $last ) {
				$next = $id;
				break;
			}
		}
		update_option( 'codcrm_rr_cursor', $next, false );
		return $next;
	}
}
