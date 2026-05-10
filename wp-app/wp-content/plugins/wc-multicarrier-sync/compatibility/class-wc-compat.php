<?php
/**
 * Couche de compatibilité WooCommerce.
 * Gère HPOS (Custom Order Tables) vs meta classique,
 * et les différences entre WC 5.x → 9.x.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCMCS_WC_Compat
 */
class WCMCS_WC_Compat {

    /** @var string Version WC détectée */
    private static string $wc_version = '';

    /** @var bool HPOS activé ? */
    private static bool $hpos_enabled = false;

    /** @var bool Action Scheduler disponible ? */
    private static bool $action_scheduler = false;

    // ─────────────────────────────────────────────
    //  Initialisation
    // ─────────────────────────────────────────────

    public static function init(): void {
        self::$wc_version      = defined( 'WC_VERSION' ) ? WC_VERSION : '0.0.0';
        self::$hpos_enabled    = self::detect_hpos();
        self::$action_scheduler = self::detect_action_scheduler();
    }

    // ─────────────────────────────────────────────
    //  Détection des features
    // ─────────────────────────────────────────────

    private static function detect_hpos(): bool {
        // WC 7.1+ : Automattic\WooCommerce\Utilities\OrderUtil
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            try {
                return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            } catch ( \Throwable $e ) {
                return false;
            }
        }
        return false;
    }

    private static function detect_action_scheduler(): bool {
        return function_exists( 'as_schedule_single_action' )
            || class_exists( 'ActionScheduler' );
    }

    // ─────────────────────────────────────────────
    //  Getters publics
    // ─────────────────────────────────────────────

    public static function wc_version(): string {
        return self::$wc_version;
    }

    public static function is_hpos(): bool {
        return self::$hpos_enabled;
    }

    public static function has_action_scheduler(): bool {
        return self::$action_scheduler;
    }

    public static function wc_version_gte( string $version ): bool {
        return version_compare( self::$wc_version, $version, '>=' );
    }

    // ─────────────────────────────────────────────
    //  Récupération de commandes (HPOS-safe)
    // ─────────────────────────────────────────────

    /**
     * Récupère une commande WC — compatible HPOS et classique.
     *
     * @param int|WC_Order $order
     * @return WC_Order|false
     */
    public static function get_order( $order ) {
        if ( $order instanceof WC_Order ) {
            return $order;
        }
        return wc_get_order( absint( $order ) );
    }

    /**
     * Récupère des commandes selon des arguments.
     * Utilise wc_get_orders() toujours (HPOS-safe depuis WC 3.1).
     *
     * @param array $args
     * @return WC_Order[]
     */
    public static function get_orders( array $args = [] ): array {
        $defaults = [
            'limit'  => -1,
            'return' => 'objects',
        ];
        $args = wp_parse_args( $args, $defaults );

        if ( self::wc_version_gte( '3.1' ) ) {
            $results = wc_get_orders( $args );
            return is_array( $results ) ? $results : [];
        }

        // Fallback très ancien WC
        $query = new WP_Query( array_merge( $args, [ 'post_type' => 'shop_order' ] ) );
        $orders = [];
        foreach ( $query->posts as $post ) {
            $o = wc_get_order( $post->ID );
            if ( $o ) {
                $orders[] = $o;
            }
        }
        return $orders;
    }

    // ─────────────────────────────────────────────
    //  Meta management (HPOS-safe)
    // ─────────────────────────────────────────────

    /**
     * Lit une meta de commande, compatible HPOS.
     *
     * @param WC_Order $order
     * @param string   $key
     * @param bool     $single
     * @return mixed
     */
    public static function get_order_meta( WC_Order $order, string $key, bool $single = true ) {
        if ( self::$hpos_enabled && method_exists( $order, 'get_meta' ) ) {
            return $order->get_meta( $key, $single );
        }
        // Fallback legacy get_post_meta
        return get_post_meta( $order->get_id(), $key, $single );
    }

    /**
     * Écrit une meta de commande, compatible HPOS.
     *
     * @param WC_Order $order
     * @param string   $key
     * @param mixed    $value
     * @param bool     $save   Sauvegarder immédiatement ?
     */
    public static function update_order_meta( WC_Order $order, string $key, $value, bool $save = true ): void {
        if ( self::$hpos_enabled && method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( $key, $value );
            if ( $save ) {
                $order->save_meta_data();
            }
        } else {
            update_post_meta( $order->get_id(), $key, $value );
        }
    }

    /**
     * Supprime une meta de commande, compatible HPOS.
     *
     * @param WC_Order $order
     * @param string   $key
     */
    public static function delete_order_meta( WC_Order $order, string $key ): void {
        if ( self::$hpos_enabled && method_exists( $order, 'delete_meta_data' ) ) {
            $order->delete_meta_data( $key );
            $order->save_meta_data();
        } else {
            delete_post_meta( $order->get_id(), $key );
        }
    }

    // ─────────────────────────────────────────────
    //  Colonnes admin (HPOS-safe hooks)
    // ─────────────────────────────────────────────

    /**
     * Retourne le hook correct pour les colonnes de la liste des commandes.
     * HPOS utilise woocommerce_shop_order_list_table_columns.
     *
     * @return string
     */
    public static function orders_list_columns_hook(): string {
        if ( self::$hpos_enabled ) {
            return 'woocommerce_shop_order_list_table_columns';
        }
        return 'manage_edit-shop_order_columns';
    }

    /**
     * Retourne le hook pour le contenu des colonnes.
     *
     * @return string
     */
    public static function orders_list_column_content_hook(): string {
        if ( self::$hpos_enabled ) {
            return 'woocommerce_shop_order_list_table_custom_column';
        }
        return 'manage_shop_order_posts_custom_column';
    }

    /**
     * Retourne le hook pour les bulk actions.
     *
     * @return string
     */
    public static function bulk_actions_hook(): string {
        if ( self::$hpos_enabled ) {
            return 'bulk_actions-woocommerce_page_wc-orders';
        }
        return 'bulk_actions-edit-shop_order';
    }

    /**
     * Retourne le hook pour le handle des bulk actions.
     *
     * @return string
     */
    public static function handle_bulk_actions_hook(): string {
        if ( self::$hpos_enabled ) {
            return 'handle_bulk_actions-woocommerce_page_wc-orders';
        }
        return 'handle_bulk_actions-edit-shop_order';
    }

    // ─────────────────────────────────────────────
    //  Action Scheduler wrapper
    // ─────────────────────────────────────────────

    /**
     * Schedule une action unique.
     * Utilise Action Scheduler si dispo, sinon wp_schedule_single_event.
     *
     * @param int    $timestamp   Unix timestamp.
     * @param string $hook        Nom du hook.
     * @param array  $args        Arguments passés au hook.
     * @param string $group       Groupe Action Scheduler.
     * @return int|bool  ID de l'action ou true/false.
     */
    public static function schedule_action( int $timestamp, string $hook, array $args = [], string $group = 'wcmcs' ) {
        if ( self::$action_scheduler ) {
            // Éviter les doublons
            if ( as_next_scheduled_action( $hook, $args, $group ) ) {
                return 0; // Déjà planifié
            }
            return as_schedule_single_action( $timestamp, $hook, $args, $group );
        }
        // Fallback WP Cron
        if ( ! wp_next_scheduled( $hook, $args ) ) {
            return wp_schedule_single_event( $timestamp, $hook, $args );
        }
        return false;
    }

    /**
     * Annule une action planifiée.
     *
     * @param string $hook
     * @param array  $args
     * @param string $group
     */
    public static function unschedule_action( string $hook, array $args = [], string $group = 'wcmcs' ): void {
        if ( self::$action_scheduler ) {
            as_unschedule_action( $hook, $args, $group );
        } else {
            wp_clear_scheduled_hook( $hook, $args );
        }
    }

    // ─────────────────────────────────────────────
    //  Admin Order URL (HPOS-safe)
    // ─────────────────────────────────────────────

    /**
     * Retourne l'URL d'édition d'une commande compatible HPOS.
     *
     * @param int $order_id
     * @return string
     */
    public static function get_order_edit_url( int $order_id ): string {
        if ( self::$hpos_enabled && self::wc_version_gte( '7.1' ) ) {
            return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
        }
        return get_edit_post_link( $order_id ) ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }
}
