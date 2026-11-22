<?php
/**
 * Plugin Name:       COD Tracker CRM
 * Description:       Suivi des colis / commandes COD : timeline complète, tentatives de livraison, agents de suivi, KPIs et rapprochement cash. Se synchronise avec MultiCarrier Sync TN.
 * Version:           1.5.0
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Text Domain:       cod-tracker-crm
 * License:           GPL-2.0-or-later
 *
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CODCRM_VERSION', '1.5.0' );
define( 'CODCRM_FILE', __FILE__ );
define( 'CODCRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CODCRM_URL', plugin_dir_url( __FILE__ ) );

// Compatibilité HPOS (tables de commandes personnalisées).
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CODCRM_FILE, true );
	}
} );

require_once CODCRM_DIR . 'includes/class-installer.php';
require_once CODCRM_DIR . 'includes/class-agents.php';
require_once CODCRM_DIR . 'includes/class-dispatcher.php';
require_once CODCRM_DIR . 'includes/class-tasks.php';
require_once CODCRM_DIR . 'includes/class-contacts.php';
require_once CODCRM_DIR . 'includes/class-templates.php';
require_once CODCRM_DIR . 'includes/class-sms-gateway.php';
require_once CODCRM_DIR . 'includes/class-kpis.php';
require_once CODCRM_DIR . 'includes/class-cash.php';
require_once CODCRM_DIR . 'includes/class-returns.php';
require_once CODCRM_DIR . 'includes/class-bulk.php';
require_once CODCRM_DIR . 'admin/class-bulk-ui.php';
require_once CODCRM_DIR . 'includes/class-stages.php';
require_once CODCRM_DIR . 'includes/class-stage-mapper.php';
require_once CODCRM_DIR . 'includes/class-events.php';
require_once CODCRM_DIR . 'includes/class-attempts.php';
require_once CODCRM_DIR . 'includes/class-event-collector.php';
require_once CODCRM_DIR . 'admin/class-timeline.php';
require_once CODCRM_DIR . 'admin/class-assignment-ui.php';
require_once CODCRM_DIR . 'admin/class-work-queue.php';
require_once CODCRM_DIR . 'admin/class-settings.php';
require_once CODCRM_DIR . 'admin/class-scanner.php';
require_once CODCRM_DIR . 'admin/class-dashboard.php';
require_once CODCRM_DIR . 'admin/class-cash-page.php';
require_once CODCRM_DIR . 'includes/class-cli.php';

register_activation_hook( __FILE__, [ 'CODCRM_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( 'codcrm_daily' );
	wp_clear_scheduled_hook( 'codcrm_dispatch_tick' );
} );

add_action( 'plugins_loaded', static function () {
	// Mise à jour du schéma si la version a changé.
	CODCRM_Installer::maybe_upgrade();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p><strong>COD Tracker CRM</strong> : WooCommerce est requis.</p></div>';
		} );
		return;
	}

	if ( ! class_exists( 'WCMCS_Order_Status_Sync' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-warning"><p><strong>COD Tracker CRM</strong> : MultiCarrier Sync TN (≥ 2.10.5) n\'est pas actif — le suivi transporteur ne sera pas alimenté.</p></div>';
		} );
	}

	CODCRM_Event_Collector::init();
	CODCRM_Timeline::init();
	CODCRM_Agents::init();
	CODCRM_Dispatcher::init();
	CODCRM_Tasks::init();
	CODCRM_Contacts::init();
	CODCRM_Templates::init();
	CODCRM_SMS_Gateway::init();
	CODCRM_Assignment_UI::init();
	CODCRM_Work_Queue::init();
	CODCRM_Settings::init();
	CODCRM_Scanner::init();
	CODCRM_KPIs::init();
	CODCRM_Dashboard::init();
	CODCRM_Cash::init();
	CODCRM_Returns::init();
	CODCRM_Bulk::init();
	CODCRM_Bulk_UI::init();
	CODCRM_Cash_Page::init();
}, 20 );
