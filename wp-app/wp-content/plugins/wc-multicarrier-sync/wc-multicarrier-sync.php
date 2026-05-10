<?php
/**
 * Plugin Name:       WC MultiCarrier Sync
 * Plugin URI:        https://yoursite.com/wc-multicarrier-sync
 * Description:       Synchronisation manuelle multi-transporteurs pour WooCommerce. Supporte FirstDelivery, Colissimo et plus.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://yoursite.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wc-multicarrier-sync
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
//  Constants
// ─────────────────────────────────────────────
define( 'WCMCS_VERSION',     '1.0.0' );
define( 'WCMCS_PLUGIN_FILE', __FILE__ );
define( 'WCMCS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WCMCS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WCMCS_PLUGIN_BASE', plugin_basename( __FILE__ ) );

// ─────────────────────────────────────────────
//  Compatibility check avant tout chargement
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, 'wcmcs_activation_check' );
function wcmcs_activation_check() {
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( WCMCS_PLUGIN_BASE );
        wp_die( esc_html__( 'WC MultiCarrier Sync nécessite PHP 7.4 ou supérieur.', 'wc-multicarrier-sync' ) );
    }
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( WCMCS_PLUGIN_BASE );
        wp_die( esc_html__( 'WC MultiCarrier Sync nécessite WooCommerce.', 'wc-multicarrier-sync' ) );
    }
    require_once WCMCS_PLUGIN_DIR . 'includes/class-installer.php';
    WCMCS_Installer::install();
}

register_deactivation_hook( __FILE__, 'wcmcs_deactivation' );
function wcmcs_deactivation() {
    // Nettoyer les crons enregistrés
    wp_clear_scheduled_hook( 'wcmcs_process_queue' );
}

// ─────────────────────────────────────────────
//  HPOS / Custom Order Tables compatibility
// ─────────────────────────────────────────────
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// ─────────────────────────────────────────────
//  Autoloader PSR-4 léger
// ─────────────────────────────────────────────
spl_autoload_register( function( $class ) {
    $prefix = 'WCMCS_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $map = [
        'WCMCS_WC_Compat'              => 'compatibility/class-wc-compat.php',
        'WCMCS_Logger'                 => 'logs/class-logger.php',
        'WCMCS_Installer'              => 'includes/class-installer.php',
        'WCMCS_Order_Meta'             => 'includes/class-order-meta.php',
        'WCMCS_Carrier_Detector'       => 'includes/class-carrier-detector.php',
        'WCMCS_Sync_Manager'           => 'includes/class-sync-manager.php',
        'WCMCS_Queue_Manager'          => 'queues/class-queue-manager.php',
        'WCMCS_Admin_Orders'           => 'admin/class-admin-orders.php',
        'WCMCS_Admin_Settings'         => 'admin/class-admin-settings.php',
        'WCMCS_FirstDelivery_Provider' => 'integrations/firstdelivery/class-firstdelivery-provider.php',
        'WCMCS_Colissimo_Provider'     => 'integrations/colissimo/class-colissimo-provider.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        $file = WCMCS_PLUGIN_DIR . $map[ $class ];
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
} );

// ─────────────────────────────────────────────
//  Boot : attend que WooCommerce soit prêt
// ─────────────────────────────────────────────
add_action( 'plugins_loaded', 'wcmcs_init', 20 );
function wcmcs_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'WC MultiCarrier Sync nécessite WooCommerce pour fonctionner.', 'wc-multicarrier-sync' )
                . '</p></div>';
        } );
        return;
    }

    load_plugin_textdomain( 'wc-multicarrier-sync', false, dirname( WCMCS_PLUGIN_BASE ) . '/languages' );

    // Charger les classes core
    WCMCS_WC_Compat::init();

    // Admin uniquement
    if ( is_admin() ) {
        new WCMCS_Admin_Orders();
        new WCMCS_Admin_Settings();
    }

    // Queue processing (front + admin)
    new WCMCS_Queue_Manager();

    // Hook pour enregistrer les providers tiers
    do_action( 'wcmcs_register_providers' );
}

// ─────────────────────────────────────────────
//  Fonction globale d'accès au registre
// ─────────────────────────────────────────────
function wcmcs_get_provider( string $slug ): ?WCMCS_ShippingProviderInterface {
    return WCMCS_Carrier_Detector::get_provider_by_slug( $slug );
}
