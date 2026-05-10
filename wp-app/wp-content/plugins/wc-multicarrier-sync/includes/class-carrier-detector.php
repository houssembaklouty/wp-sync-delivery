<?php
/**
 * Moteur de détection automatique du transporteur.
 *
 * Priorité des règles (ordre de résolution) :
 *  1. Mapping manuel forcé sur la commande
 *  2. Règles custom (hook filtre)
 *  3. Règles par méthode de livraison (shipping method)
 *  4. Règles par gouvernorat / zone géographique
 *  5. Règles par catégorie de produit
 *  6. Transporteur par défaut en settings
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Carrier_Detector {

    /** @var WCMCS_ShippingProviderInterface[] */
    private static array $providers = [];

    /** @var bool Providers initialisés ? */
    private static bool $initialized = false;

    // ─────────────────────────────────────────────
    //  Registre des providers
    // ─────────────────────────────────────────────

    /**
     * Enregistre un provider.
     * Doit être appelé pendant le hook wcmcs_register_providers.
     *
     * @param WCMCS_ShippingProviderInterface $provider
     */
    public static function register_provider( WCMCS_ShippingProviderInterface $provider ): void {
        self::$providers[ $provider->get_slug() ] = $provider;
    }

    /**
     * Retourne tous les providers enregistrés.
     *
     * @return WCMCS_ShippingProviderInterface[]
     */
    public static function get_all_providers(): array {
        self::ensure_initialized();
        return self::$providers;
    }

    /**
     * Retourne un provider par slug.
     *
     * @param string $slug
     * @return WCMCS_ShippingProviderInterface|null
     */
    public static function get_provider_by_slug( string $slug ): ?WCMCS_ShippingProviderInterface {
        self::ensure_initialized();
        return self::$providers[ $slug ] ?? null;
    }

    /**
     * Initialise les providers built-in si pas encore fait.
     */
    private static function ensure_initialized(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Charger les interfaces d'abord
        require_once WCMCS_PLUGIN_DIR . 'includes/interface-shipping-provider.php';

        // Enregistrer les providers built-in
        self::register_provider( new WCMCS_FirstDelivery_Provider() );
        self::register_provider( new WCMCS_Colissimo_Provider() );

        // Hook pour providers tiers
        do_action( 'wcmcs_register_providers' );
    }

    // ─────────────────────────────────────────────
    //  Résolution du transporteur pour une commande
    // ─────────────────────────────────────────────

    /**
     * Détecte le transporteur pour une commande.
     *
     * @param WC_Order $order
     * @return WCMCS_ShippingProviderInterface|WP_Error
     */
    public static function detect( WC_Order $order ) {
        self::ensure_initialized();
        $order_id = $order->get_id();

        // ── 1. Mapping manuel forcé ───────────────
        $forced = WCMCS_WC_Compat::get_order_meta( $order, '_wcmcs_forced_carrier' );
        if ( $forced && isset( self::$providers[ $forced ] ) ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur forcé manuellement → {$forced}" );
            return self::$providers[ $forced ];
        }

        // ── 2. Règles custom (hook) ───────────────
        $custom = apply_filters( 'wcmcs_detect_carrier_custom', null, $order, self::$providers );
        if ( $custom instanceof WCMCS_ShippingProviderInterface ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via règle custom → {$custom->get_slug()}" );
            return $custom;
        }

        // ── 3. Règles par méthode de livraison ────
        $by_method = self::detect_by_shipping_method( $order );
        if ( $by_method ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via shipping method → {$by_method->get_slug()}" );
            return $by_method;
        }

        // ── 4. Règles par gouvernorat / état ──────
        $by_state = self::detect_by_state( $order );
        if ( $by_state ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via gouvernorat → {$by_state->get_slug()}" );
            return $by_state;
        }

        // ── 5. Règles par catégorie produit ───────
        $by_category = self::detect_by_product_category( $order );
        if ( $by_category ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via catégorie produit → {$by_category->get_slug()}" );
            return $by_category;
        }

        // ── 6. Transporteur par défaut ────────────
        $default_slug = get_option( 'wcmcs_default_carrier', '' );
        if ( $default_slug && isset( self::$providers[ $default_slug ] ) ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur par défaut → {$default_slug}" );
            return self::$providers[ $default_slug ];
        }

        // ── Aucun transporteur trouvé ─────────────
        WCMCS_Logger::error( "Order #{$order_id}: aucun transporteur détecté." );
        return new WP_Error(
            'wcmcs_no_carrier',
            sprintf(
                /* translators: %d order ID */
                __( 'Aucun transporteur détecté pour la commande #%d.', 'wc-multicarrier-sync' ),
                $order_id
            )
        );
    }

    // ─────────────────────────────────────────────
    //  Détection par shipping method
    // ─────────────────────────────────────────────

    private static function detect_by_shipping_method( WC_Order $order ): ?WCMCS_ShippingProviderInterface {
        $rules = get_option( 'wcmcs_rules_by_shipping_method', [] );
        if ( empty( $rules ) ) {
            return null;
        }

        $shipping_items = $order->get_items( 'shipping' );
        foreach ( $shipping_items as $item ) {
            /** @var WC_Order_Item_Shipping $item */
            $method_id = $item->get_method_id();
            $instance  = $item->get_instance_id();

            // Chercher exact : method_id:instance
            $key_full = $method_id . ':' . $instance;
            if ( isset( $rules[ $key_full ] ) && isset( self::$providers[ $rules[ $key_full ] ] ) ) {
                return self::$providers[ $rules[ $key_full ] ];
            }

            // Chercher par method_id seul
            if ( isset( $rules[ $method_id ] ) && isset( self::$providers[ $rules[ $method_id ] ] ) ) {
                return self::$providers[ $rules[ $method_id ] ];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    //  Détection par gouvernorat / état
    // ─────────────────────────────────────────────

    private static function detect_by_state( WC_Order $order ): ?WCMCS_ShippingProviderInterface {
        $rules = get_option( 'wcmcs_rules_by_state', [] );
        if ( empty( $rules ) ) {
            return null;
        }

        $state   = strtolower( trim( $order->get_shipping_state() ) );
        $country = strtoupper( trim( $order->get_shipping_country() ) );

        // Chercher avec pays + état : TN:SFAX
        $key_full = $country . ':' . strtoupper( $state );
        if ( isset( $rules[ $key_full ] ) && isset( self::$providers[ $rules[ $key_full ] ] ) ) {
            return self::$providers[ $rules[ $key_full ] ];
        }

        // Chercher par pays seul
        if ( isset( $rules[ $country ] ) && isset( self::$providers[ $rules[ $country ] ] ) ) {
            return self::$providers[ $rules[ $country ] ];
        }

        return null;
    }

    // ─────────────────────────────────────────────
    //  Détection par catégorie produit
    // ─────────────────────────────────────────────

    private static function detect_by_product_category( WC_Order $order ): ?WCMCS_ShippingProviderInterface {
        $rules = get_option( 'wcmcs_rules_by_category', [] );
        if ( empty( $rules ) ) {
            return null;
        }

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $terms = get_the_terms( $product->get_id(), 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                if ( isset( $rules[ $term->slug ] ) && isset( self::$providers[ $rules[ $term->slug ] ] ) ) {
                    return self::$providers[ $rules[ $term->slug ] ];
                }
                if ( isset( $rules[ (string) $term->term_id ] ) && isset( self::$providers[ $rules[ (string) $term->term_id ] ] ) ) {
                    return self::$providers[ $rules[ (string) $term->term_id ] ];
                }
            }
        }

        return null;
    }
}
