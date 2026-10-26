<?php
/**
 * Installer — Gère la création de tables et la mise à jour du schéma.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Installer {

    const DB_VERSION_OPTION = 'wcmcs_db_version';
    const DB_VERSION        = '1.0.0';

    /**
     * Exécuté à l'activation et aux mises à jour.
     */
    public static function install(): void {
        self::create_tables();
        self::set_default_options();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Crée ou met à jour les tables custom.
     * Note : Pour ce plugin, on utilise principalement les meta WooCommerce.
     * Cette table est un log centralisé optionnel pour les performances.
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'wcmcs_sync_log';

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id     BIGINT(20) UNSIGNED NOT NULL,
            carrier_slug VARCHAR(50)         NOT NULL DEFAULT '',
            status       VARCHAR(20)         NOT NULL DEFAULT 'pending',
            tracking_id  VARCHAR(100)        NOT NULL DEFAULT '',
            error_msg    TEXT,
            api_response LONGTEXT,
            synced_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id     (order_id),
            KEY carrier_slug (carrier_slug),
            KEY status       (status),
            KEY synced_at    (synced_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function set_default_options(): void {
        $defaults = [
            'wcmcs_debug_logs'          => 'no',
            'wcmcs_allow_resync'        => 'no',
            'wcmcs_max_auto_retries'    => 0,
            'wcmcs_default_carrier'     => '',
            'wcmcs_rules_by_shipping_method' => [],
            'wcmcs_rules_by_state'      => [],
            'wcmcs_rules_by_category'   => [],
        ];

        foreach ( $defaults as $option => $value ) {
            if ( get_option( $option ) === false ) {
                add_option( $option, $value, '', 'no' );
            }
        }
    }
}
