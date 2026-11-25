<?php
defined( 'ABSPATH' ) || exit;

/**
 * Alertes « nouvelle commande » sur TOUTES les pages de l'admin (pas seulement « Mes suivis ») :
 * son, bandeau cliquable, notification de bureau du navigateur, pastille dans le menu.
 * Un agent est alerté pour les commandes qui lui sont attribuées ; un responsable pour toute nouvelle commande.
 */
class CODCRM_Notify {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ], 20 );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 90 );
		add_action( 'wp_ajax_codcrm_notify_poll', [ __CLASS__, 'ajax_poll' ] );
	}

	public static function enabled(): bool {
		return 'yes' === get_option( 'codcrm_notify_enabled', 'yes' );
	}

	private static function allowed(): bool {
		return self::enabled() && current_user_can( CODCRM_Agents::CAP_WORK );
	}

	public static function assets( string $hook ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$v = static function ( string $rel ) {
			return CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel );
		};
		if ( ! wp_script_is( 'codcrm-sounds', 'enqueued' ) ) {
			wp_enqueue_script( 'codcrm-sounds', $v( 'assets/js/sounds.js' ), [], null, true );
			wp_localize_script( 'codcrm-sounds', 'codcrmSoundFiles', CODCRM_Work_Queue::sound_files() );
		}
		wp_enqueue_style( 'codcrm-notify', $v( 'assets/css/notify.css' ), [], null );
		wp_enqueue_script( 'codcrm-notify', $v( 'assets/js/notify.js' ), [ 'jquery', 'codcrm-sounds' ], null, true );
		$now = self::now();
		wp_localize_script( 'codcrm-notify', 'codcrmNotify', [
			'ajax'           => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'codcrm' ),
			'queueUrl'       => admin_url( 'admin.php?page=' . CODCRM_Work_Queue::PAGE_QUEUE . '&stage=tous' ),
			'onQueue'        => false !== strpos( $hook, CODCRM_Work_Queue::PAGE_QUEUE ),
			'now'            => $now,
			'sound'          => CODCRM_Work_Queue::effective_sound(),
			'volume'         => CODCRM_Work_Queue::live_volume(),
			'soundAbandoned' => 'yes' === get_option( 'codcrm_live_sound_abandoned', 'yes' ),
			'icon'           => (string) get_site_icon_url( 128 ),
			'i18n'           => [
				'new'       => __( 'Nouvelle commande', 'cod-tracker-crm' ),
				'abandoned' => __( 'Commande abandonnée', 'cod-tracker-crm' ),
				'enable'    => __( 'Activer les notifications du navigateur', 'cod-tracker-crm' ),
				'enabled'   => __( 'Notifications du navigateur activées', 'cod-tracker-crm' ),
				'blocked'   => __( 'Notifications bloquées pour ce site : cliquez sur le cadenas de la barre d\'adresse → Notifications : Autoriser, puis rechargez la page.', 'cod-tracker-crm' ),
				'bannerText' => __( 'Activez les alertes pour être prévenu des nouvelles commandes, même quand vous êtes sur un autre onglet ou un autre site (garder un onglet de l\'admin ouvert).', 'cod-tracker-crm' ),
				'enableAll' => __( 'Activer les alertes (son + notifications)', 'cod-tracker-crm' ),
				'later'     => __( 'Plus tard', 'cod-tracker-crm' ),
				'soundOff'  => __( 'Son bloqué par le navigateur : cliquez n\'importe où sur la page pour l\'activer (ou Chrome → cadenas → Paramètres du site → Son : Autoriser).', 'cod-tracker-crm' ),
				'title'     => __( 'Suivi COD — nouvelles commandes', 'cod-tracker-crm' ),
				'testBody'  => __( 'Les alertes sont activées. Vous serez prévenu ici des nouvelles commandes.', 'cod-tracker-crm' ),
			],
		] );
	}

	/** Barre d'administration : cloche + nombre de nouvelles commandes non vues, lien vers « Mes suivis ». */
	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'codcrm-notify',
			'title' => '<span class="ab-icon dashicons dashicons-bell" aria-hidden="true"></span><span class="codcrm-ab-count" hidden></span>',
			'href'  => admin_url( 'admin.php?page=' . CODCRM_Work_Queue::PAGE_QUEUE ),
			'meta'  => [ 'title' => __( 'Suivi COD — nouvelles commandes', 'cod-tracker-crm' ) ],
		] );
		$bar->add_node( [
			'parent' => 'codcrm-notify',
			'id'     => 'codcrm-notify-perm',
			'title'  => esc_html__( 'Activer les notifications du navigateur', 'cod-tracker-crm' ),
			'href'   => '#',
		] );
	}

	/** Repères temporels : UTC (date_created_gmt) et heure de la base (created_at des événements). */
	private static function now(): array {
		global $wpdb;
		$r = $wpdb->get_row( 'SELECT UTC_TIMESTAMP() AS u, NOW() AS d', ARRAY_A );
		return [ 'utc' => (string) ( $r['u'] ?? gmdate( 'Y-m-d H:i:s' ) ), 'db' => (string) ( $r['d'] ?? current_time( 'mysql' ) ) ];
	}

	public static function ajax_poll(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! self::allowed() ) {
			wp_send_json_error( [], 403 );
		}
		global $wpdb;
		$ts = static function ( $v ) { return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ? $v : ''; };
		$su = $ts( wp_unslash( $_POST['since_utc'] ?? '' ) );
		$sd = $ts( wp_unslash( $_POST['since_db'] ?? '' ) );
		$now = self::now();
		$ids = [];

		// Curseur absent ou trop ancien (> 6 h) : on repart de maintenant, sans rattrapage.
		if ( '' !== $su && '' !== $sd && strtotime( $su . ' UTC' ) > time() - 6 * HOUR_IN_SECONDS ) {
			$orders = $wpdb->prefix . 'wc_orders';
			if ( current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
				$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
					"SELECT id FROM {$orders} WHERE type = 'shop_order' AND date_created_gmt >= DATE_SUB(%s, INTERVAL 2 SECOND)
					 AND status NOT IN ('trash','auto-draft','wc-checkout-draft') ORDER BY id DESC LIMIT 20",
					$su
				) );
			} else {
				$cand = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
					'SELECT DISTINCT order_id FROM ' . CODCRM_Installer::table_events() . " WHERE type = 'assignment' AND outcome <> 'waiting'
					 AND created_at >= DATE_SUB(%s, INTERVAL 2 SECOND) ORDER BY order_id DESC LIMIT 50",
					$sd
				) );
				foreach ( (array) $cand as $id ) {
					$o = wc_get_order( (int) $id );
					if ( $o && CODCRM_Agents::agent_of( $o ) === get_current_user_id() ) {
						$ids[] = (int) $id;
					}
				}
			}
		}
		$items = [];
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, 20 ) as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$items[] = [ 'id' => $id, 'number' => $o->get_order_number(), 'phone' => $o->get_billing_phone(), 'abandoned' => CODCRM_Abandoned::SLUG === $o->get_status() ];
			}
		}
		wp_send_json_success( [ 'now' => $now, 'items' => $items ] );
	}
}
