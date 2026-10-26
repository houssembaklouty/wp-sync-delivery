<?php
/**
 * Plugin Name:       MultiCarrier Sync TN
 * Plugin URI:        https://baklouty.tn
 * Description:       Synchronisation manuelle multi-transporteurs pour WooCommerce. Supporte FirstDelivery, Colissimo et plus.
 * Version:           2.10.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Houssem Baklouti
 * Author URI:        https://baklouty.tn
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
define( 'WCMCS_VERSION',     '2.10.0' );
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
        wp_die( esc_html__( 'MultiCarrier Sync TN nécessite PHP 7.4 ou supérieur.', 'wc-multicarrier-sync' ) );
    }
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( WCMCS_PLUGIN_BASE );
        wp_die( esc_html__( 'MultiCarrier Sync TN nécessite WooCommerce.', 'wc-multicarrier-sync' ) );
    }
    require_once WCMCS_PLUGIN_DIR . 'includes/class-installer.php';
    require_once WCMCS_PLUGIN_DIR . 'includes/class-license.php';
    WCMCS_Installer::install();
    WCMCS_License::activate();
}

register_deactivation_hook( __FILE__, 'wcmcs_deactivation' );
function wcmcs_deactivation() {
    // Purger la queue Action Scheduler du plugin (actions orphelines sinon).
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( '', [], 'wcmcs' );
    }
    // Purger le job de suivi automatique (statut de commande).
    if ( class_exists( 'WCMCS_Order_Status_Sync' ) ) {
        WCMCS_Order_Status_Sync::unschedule();
    }
    WCMCS_License::deactivate();
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
//  Migration une fois par version
// ─────────────────────────────────────────────
if ( get_option( 'wcmcs_version' ) !== WCMCS_VERSION ) {
    // v2.10.0 : suppression de l'interprétation des codes gouvernorats
    // (ISO TN-XX, codes personnalisés, auto-apprentissage) — purge des
    // mappings appris en base qui pouvaient contenir de faux positifs
    // (ex : un code de thème interprété ISO → Bizerte envoyé à Jendouba).
    delete_option( 'wcmcs_learned_governing_codes' );
    delete_option( 'wcmcs_custom_gouvernorat_codes' );
    update_option( 'wcmcs_version', WCMCS_VERSION, false );
}

// ─────────────────────────────────────────────
//  Autoloader PSR-4 léger
// ─────────────────────────────────────────────
spl_autoload_register( function( $class ) {
    $prefix = 'WCMCS_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $map = [
        'WCMCS_License'                => 'includes/class-license.php',
        'WCMCS_WC_Compat'              => 'compatibility/class-wc-compat.php',
        'WCMCS_Logger'                 => 'logs/class-logger.php',
        'WCMCS_Installer'              => 'includes/class-installer.php',
        'WCMCS_Order_Meta'             => 'includes/class-order-meta.php',
        'WCMCS_Order_Status_Sync'      => 'includes/class-order-status-sync.php',
        'WCMCS_Localization'           => 'includes/class-localization.php',
        'WCMCS_Email_Notifier'          => 'includes/class-email-notifier.php',
        'WCMCS_Order_Statuses'          => 'includes/class-order-statuses.php',
        'WCMCS_Carrier_Detector'       => 'includes/class-carrier-detector.php',
        'WCMCS_Sync_Manager'           => 'includes/class-sync-manager.php',
        'WCMCS_Queue_Manager'          => 'queues/class-queue-manager.php',
        'WCMCS_Updater'                => 'includes/class-updater.php',
        'WCMCS_Admin_Orders'           => 'admin/class-admin-orders.php',
        'WCMCS_Admin_Settings'         => 'admin/class-admin-settings.php',
        'WCMCS_Field_Mapping'          => 'includes/trait-field-mapping.php',
        'WCMCS_FirstDelivery_Provider' => 'integrations/firstdelivery/class-firstdelivery-provider.php',
        'WCMCS_Colissimo_Provider'     => 'integrations/colissimo/class-colissimo-provider.php',
        'WCMCS_Jetpack_Provider'       => 'integrations/jetpack/class-jetpack-provider.php',
        'WCMCS_Fiabilo_Provider'       => 'integrations/fiabilo/class-fiabilo-provider.php',
        'WCMCS_Adex_Provider'          => 'integrations/adex/class-adex-provider.php',
        'WCMCS_Intigo_Provider'       => 'integrations/intigo/class-intigo-provider.php',
        'WCMCS_Afex_Provider'         => 'integrations/afex/class-afex-provider.php',
        'WCMCS_Aramex_Provider'       => 'integrations/aramex/class-aramex-provider.php',
        'WCMCS_Navex_Provider'        => 'integrations/navex/class-navex-provider.php',
        'WCMCS_Shipper_Provider'      => 'integrations/shipper/class-shipper-provider.php',
        'WCMCS_Ciblex_Provider'       => 'integrations/ciblex/class-ciblex-provider.php',
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
                . esc_html__( 'MultiCarrier Sync TN nécessite WooCommerce pour fonctionner.', 'wc-multicarrier-sync' )
                . '</p></div>';
        } );
        return;
    }

    load_plugin_textdomain( 'wc-multicarrier-sync', false, dirname( WCMCS_PLUGIN_BASE ) . '/languages' );

    // Classes core
    WCMCS_WC_Compat::init();
    WCMCS_License::get_instance();

    // Admin uniquement
    if ( is_admin() ) {
        new WCMCS_Admin_Orders();
        new WCMCS_Admin_Settings();
    }

    // Queue processing (front + admin)
    new WCMCS_Queue_Manager();

    // Suivi automatique du colis + mise à jour du statut de commande (job horaire)
    new WCMCS_Order_Status_Sync();

    // Email alerte au développeur en cas d'échec de sync
    new WCMCS_Email_Notifier();

    // Statuts de commande personnalisés (Téléchargée / Livrée / Retournée)
    new WCMCS_Order_Statuses();

    // Auto-nettoyage des actions AS bloquées (> 30 min)
    add_action( 'admin_init', [ 'WCMCS_Queue_Manager', 'cleanup_stuck_actions' ] );

    // Auto-updates via GitHub Releases (repo de distribution privé)
    WCMCS_Updater::init();

    // NB : le hook 'wcmcs_register_providers' est tiré par
    // WCMCS_Carrier_Detector::ensure_initialized() — une seule fois.
}

// ─────────────────────────────────────────────
//  Fonction globale d'accès au registre
// ─────────────────────────────────────────────
function wcmcs_get_provider( string $slug ): ?WCMCS_ShippingProviderInterface {
    return WCMCS_Carrier_Detector::get_provider_by_slug( $slug );
}
