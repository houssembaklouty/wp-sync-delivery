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
		// La répartition automatique est gérée par CODCRM_Dispatcher.

		// WooCommerce renvoie vers « Mon compte » tout utilisateur sans droit d'édition (agents compris) et
		// masque leur barre d'administration : les agents doivent pouvoir travailler dans l'admin.
		add_filter( 'woocommerce_prevent_admin_access', [ __CLASS__, 'allow_admin' ], 20 );
		add_filter( 'woocommerce_disable_admin_bar', [ __CLASS__, 'allow_admin' ], 20 );
		// Après connexion : un agent arrive directement sur « Mes suivis ».
		add_filter( 'login_redirect', [ __CLASS__, 'login_redirect' ], 20, 3 );
		add_filter( 'woocommerce_login_redirect', [ __CLASS__, 'wc_login_redirect' ], 20, 2 );
		// Sites déjà installés : ajoute la capacité manquante au rôle existant.
		add_action( 'init', [ __CLASS__, 'maybe_upgrade_role' ] );
	}

	/** Filtre WooCommerce : false = ne pas bloquer / ne pas masquer pour un agent COD. */
	public static function allow_admin( $value ) {
		return ( current_user_can( self::CAP_WORK ) && ! current_user_can( 'edit_posts' ) ) ? false : $value;
	}

	private static function is_agent_only( $user ): bool {
		return $user instanceof WP_User && user_can( $user, self::CAP_WORK ) && ! user_can( $user, 'edit_posts' );
	}

	public static function login_redirect( $to, $requested, $user ) {
		return self::is_agent_only( $user ) && ( '' === (string) $requested || false !== strpos( (string) $to, 'wp-admin' ) || false !== strpos( (string) $to, 'my-account' ) )
			? admin_url( 'admin.php?page=codcrm-queue' ) : $to;
	}

	public static function wc_login_redirect( $to, $user ) {
		return self::is_agent_only( $user ) ? admin_url( 'admin.php?page=codcrm-queue' ) : $to;
	}

	public static function maybe_upgrade_role(): void {
		$role = get_role( self::ROLE );
		if ( $role && ! $role->has_cap( 'view_admin_dashboard' ) ) {
			$role->add_cap( 'view_admin_dashboard' ); // capacité prévue par WooCommerce pour autoriser l'admin.
		}
	}

	/** Crée le rôle agent et donne les capacités au propriétaire (idempotent). */
	public static function install_roles(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Agent suivi COD', 'cod-tracker-crm' ), [
				'read'                 => true,
				'view_admin_dashboard' => true,
				self::CAP_WORK         => true,
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

	/** Agents actifs pour la répartition automatique. @return int[] */
	public static function active_agent_ids(): array {
		return CODCRM_Dispatcher::active_agents();
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
	/**
	 * @param string $reason  Raison affichée dans la timeline (règle, méthode, réattribution…).
	 * @param string $by      'auto' (moteur de répartition, réattribuable) | 'manual' (jamais réattribuée).
	 * @param string $outcome Résultat de l'événement ('reassigned' pour une réattribution).
	 */
	public static function assign( WC_Order $order, int $agent_id, int $actor_id = 0, string $source = 'agent', string $reason = '', string $by = 'manual', string $outcome = '' ): bool {
		$old = self::agent_of( $order );
		if ( $old === $agent_id ) {
			return false;
		}
		if ( $agent_id && ! user_can( $agent_id, self::CAP_WORK ) ) {
			return false;
		}

		if ( $agent_id ) {
			$order->update_meta_data( self::META_AGENT, $agent_id );
			$order->update_meta_data( CODCRM_Dispatcher::META_ASSIGNED_AT, wp_date( 'Y-m-d H:i:s', CODCRM_Dispatcher::now() ) );
			$order->update_meta_data( CODCRM_Dispatcher::META_ASSIGNED_BY, 'auto' === $by ? 'auto' : 'manual' );
			$order->delete_meta_data( CODCRM_Dispatcher::META_WAITING );
		} else {
			$order->delete_meta_data( self::META_AGENT );
			$order->delete_meta_data( CODCRM_Dispatcher::META_ASSIGNED_AT );
			$order->delete_meta_data( CODCRM_Dispatcher::META_ASSIGNED_BY );
		}
		$order->save_meta_data();

		$note = $agent_id
			? sprintf( __( 'Assignée à %s', 'cod-tracker-crm' ), self::agent_name( $agent_id ) )
			: __( 'Assignation retirée', 'cod-tracker-crm' );
		if ( '' !== $reason ) {
			$note .= ' — ' . $reason;
		}

		CODCRM_Events::add( $order->get_id(), [
			'type'     => 'assignment',
			'outcome'  => '' !== $outcome ? $outcome : ( 'auto' === $by ? 'auto' : 'manual' ),
			'note'     => $note,
			'actor_id' => $actor_id,
			'source'   => $source,
			'payload'  => [ 'from' => $old, 'to' => $agent_id ],
		] );

		// Les tâches ouvertes suivent le nouvel agent.
		CODCRM_Tasks::reassign_open( $order->get_id(), $agent_id );

		do_action( 'codcrm_order_assigned', $order, $agent_id, $old );
		return true;
	}
}
