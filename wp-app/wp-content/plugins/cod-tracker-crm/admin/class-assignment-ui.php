<?php
defined( 'ABSPATH' ) || exit;

/**
 * Assignation dans la liste des commandes : colonne « Agent » + actions de masse.
 * Compatible HPOS (woocommerce_page_wc-orders) et stockage legacy (edit-shop_order).
 */
class CODCRM_Assignment_UI {

	public static function init(): void {
		foreach ( [ 'woocommerce_page_wc-orders', 'edit-shop_order' ] as $screen ) {
			add_filter( "bulk_actions-{$screen}", [ __CLASS__, 'bulk_actions' ] );
			add_filter( "handle_bulk_actions-{$screen}", [ __CLASS__, 'handle_bulk' ], 10, 3 );
		}

		// Colonne Agent.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column_hpos' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_legacy' ], 10, 2 );

		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	public static function bulk_actions( array $actions ): array {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			return $actions;
		}
		foreach ( CODCRM_Agents::get_agents() as $u ) {
			/* translators: %s: nom de l'agent */
			$actions[ 'codcrm_assign_' . (int) $u->ID ] = sprintf( __( 'Assigner à %s', 'cod-tracker-crm' ), $u->display_name );
		}
		$actions['codcrm_unassign'] = __( 'Retirer l\'assignation', 'cod-tracker-crm' );
		return $actions;
	}

	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( 0 !== strpos( $action, 'codcrm_' ) || ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			return $redirect;
		}

		$agent_id = 0;
		if ( 0 === strpos( $action, 'codcrm_assign_' ) ) {
			$agent_id = (int) substr( $action, strlen( 'codcrm_assign_' ) );
			if ( ! $agent_id || ! user_can( $agent_id, CODCRM_Agents::CAP_WORK ) ) {
				return $redirect;
			}
		} elseif ( 'codcrm_unassign' !== $action ) {
			return $redirect;
		}

		$n = 0;
		foreach ( array_map( 'absint', $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order && CODCRM_Agents::assign( $order, $agent_id, get_current_user_id() ) ) {
				$n++;
			}
		}
		return add_query_arg( 'codcrm_assigned', $n, $redirect );
	}

	public static function notice(): void {
		if ( isset( $_GET['codcrm_assigned'] ) && current_user_can( CODCRM_Agents::CAP_MANAGE ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( _n( '%d commande mise à jour.', '%d commandes mises à jour.', absint( $_GET['codcrm_assigned'] ), 'cod-tracker-crm' ), absint( $_GET['codcrm_assigned'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
			);
		}
	}

	public static function add_column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['codcrm_agent'] = __( 'Agent', 'cod-tracker-crm' );
			}
		}
		if ( ! isset( $out['codcrm_agent'] ) ) {
			$out['codcrm_agent'] = __( 'Agent', 'cod-tracker-crm' );
		}
		return $out;
	}

	public static function render_column_hpos( string $column, $order ): void {
		if ( 'codcrm_agent' === $column && $order instanceof WC_Order ) {
			self::render_cell( $order );
		}
	}

	public static function render_column_legacy( string $column, $post_id ): void {
		if ( 'codcrm_agent' === $column ) {
			$order = wc_get_order( (int) $post_id );
			if ( $order ) {
				self::render_cell( $order );
			}
		}
	}

	private static function render_cell( WC_Order $order ): void {
		$name = CODCRM_Agents::agent_name( CODCRM_Agents::agent_of( $order ) );
		echo $name ? esc_html( $name ) : '<span aria-hidden="true">—</span>';
	}
}
