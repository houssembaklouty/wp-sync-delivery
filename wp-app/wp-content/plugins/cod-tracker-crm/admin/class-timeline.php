<?php
defined( 'ABSPATH' ) || exit;

/**
 * Metabox « Suivi COD » : chronologie complète d'une commande.
 */
class CODCRM_Timeline {

	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save_agent' ], 20, 1 );
	}

	public static function register(): void {
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'codcrm-timeline', __( 'Suivi COD — chronologie', 'cod-tracker-crm' ), [ __CLASS__, 'render' ], $screen, 'normal', 'default' );
		}
	}

	/** @param WP_Post|WC_Order $post_or_order */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$attempts = (int) $order->get_meta( CODCRM_Attempts::META_ATTEMPTS );
		$failed   = (int) $order->get_meta( CODCRM_Attempts::META_FAILED );
		$risk     = '1' === (string) $order->get_meta( CODCRM_Attempts::META_RISK );
		$stage    = (string) $order->get_meta( CODCRM_Event_Collector::META_STAGE );
		$events   = array_reverse( CODCRM_Events::for_order( $order->get_id() ) ); // récents d'abord.

		echo '<style>
			.codcrm-head{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
			.codcrm-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff}
			.codcrm-tl{list-style:none;margin:0;padding:0 0 0 22px;border-left:2px solid #dcdcde}
			.codcrm-tl li{position:relative;padding:0 0 14px 14px}
			.codcrm-tl li:before{content:"";position:absolute;left:-30px;top:2px;width:14px;height:14px;border-radius:50%;background:var(--c);border:2px solid #fff;box-shadow:0 0 0 1px var(--c)}
			.codcrm-tl .t{font-weight:600}.codcrm-tl .m{color:#646970;font-size:12px}
		</style>';

		// Agent assigné (le propriétaire peut le changer).
		wp_nonce_field( 'codcrm_agent_' . $order->get_id(), 'codcrm_agent_nonce' );
		$agent_id = CODCRM_Agents::agent_of( $order );
		echo '<p class="codcrm-agent"><strong>' . esc_html__( 'Agent de suivi :', 'cod-tracker-crm' ) . '</strong> ';
		if ( current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			echo '<select name="codcrm_agent"><option value="0">' . esc_html__( '— Non assignée —', 'cod-tracker-crm' ) . '</option>';
			foreach ( CODCRM_Agents::get_agents() as $u ) {
				printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $agent_id, (int) $u->ID, false ), esc_html( $u->display_name ) );
			}
			echo '</select>';
		} else {
			echo esc_html( CODCRM_Agents::agent_name( $agent_id ) ?: '—' );
		}
		if ( CODCRM_Agents::can_handle( $order ) && CODCRM_Work_Queue::norm_phone( $order->get_billing_phone() ) ) {
			printf( ' <a href="#" class="button button-small codcrm-msg" data-order="%d">✉ %s</a>', (int) $order->get_id(), esc_html__( 'Message', 'cod-tracker-crm' ) );
		}
		echo '</p>';

		echo '<div class="codcrm-head">';
		if ( $stage ) {
			printf( '<span class="codcrm-pill" style="background:%s">%s</span>', esc_attr( CODCRM_Stages::color( $stage ) ), esc_html( CODCRM_Stages::label( $stage ) ) );
		}
		printf( '<span>%s <strong>%d</strong> · %s <strong>%d / %d</strong></span>',
			esc_html__( 'Tournées :', 'cod-tracker-crm' ), $attempts,
			esc_html__( 'Échecs :', 'cod-tracker-crm' ), $failed, CODCRM_Attempts::threshold()
		);
		if ( $risk ) {
			printf( '<span class="codcrm-pill" style="background:#c0392b">⚠ %s</span>', esc_html__( 'Risque de retour', 'cod-tracker-crm' ) );
		}
		echo '</div>';

		if ( ! $events ) {
			echo '<p>' . esc_html__( 'Aucun événement enregistré pour le moment.', 'cod-tracker-crm' ) . '</p>';
			return;
		}

		echo '<ul class="codcrm-tl">';
		foreach ( $events as $e ) {
			if ( 'contact' === $e->type ) {
				$title = sprintf( '%s — %s', CODCRM_Contacts::channel_label( (string) $e->channel ), CODCRM_Contacts::outcome_label( (string) $e->channel, (string) $e->outcome ) );
			} elseif ( in_array( $e->type, [ 'assignment', 'task' ], true ) ) {
				$title = ( 'task' === $e->type ? ( 'done' === $e->outcome ? '✓ ' : '⏰ ' ) : '👤 ' ) . $e->note;
			} else {
				$title = '' !== $e->carrier_state ? $e->carrier_state : ( '' !== $e->stage ? CODCRM_Stages::label( $e->stage ) : $e->note );
			}
			$meta  = [ mysql2date( 'd/m/Y H:i', $e->occurred_at ) ];
			if ( '' !== $e->carrier_slug ) {
				$meta[] = $e->carrier_slug;
			}
			if ( (int) $e->actor_id ) {
				$u = get_userdata( (int) $e->actor_id );
				if ( $u ) {
					$meta[] = $u->display_name;
				}
			}
			if ( '' !== $e->note && $e->note !== $title ) {
				$meta[] = $e->note;
			}
			printf(
				'<li style="--c:%s"><div class="t">%s</div><div class="m">%s</div></li>',
				esc_attr( '' !== $e->stage ? CODCRM_Stages::color( (string) $e->stage ) : ( 'contact' === $e->type ? '#2271b1' : '#8c8f94' ) ),
				esc_html( $title ),
				esc_html( implode( ' · ', $meta ) )
			);
		}
		echo '</ul>';
	}

	/** Sauvegarde de l'agent choisi dans la metabox (propriétaire uniquement). */
	public static function save_agent( $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! isset( $_POST['codcrm_agent'], $_POST['codcrm_agent_nonce'] ) || ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['codcrm_agent_nonce'] ) ), 'codcrm_agent_' . $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			CODCRM_Agents::assign( $order, absint( $_POST['codcrm_agent'] ), get_current_user_id() );
		}
	}
}
