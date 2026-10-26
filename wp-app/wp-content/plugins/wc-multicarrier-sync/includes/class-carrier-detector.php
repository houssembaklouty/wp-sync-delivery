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

    /**
     * Registre complet des transporteurs (actifs et inactifs).
     * Trié par ordre alphabétique du label.
     */
    const CARRIER_REGISTRY = [
        'a2b'            => [ 'label' => 'A2B Delivery',     'active' => false, 'icon' => '📦' ],
        'adex'           => [ 'label' => 'Adex',           'active' => true,  'icon' => '🚚' ],
        'afex'           => [ 'label' => 'Afex',            'active' => true,  'icon' => '📮' ],
        'aramex'         => [ 'label' => 'Aramex',          'active' => true,  'icon' => '🚚' ],
        'bestdelivery'   => [ 'label' => 'Best Delivery',    'active' => false, 'icon' => '📦' ],
        'b2cdelivery'    => [ 'label' => 'B2C Delivery',     'active' => false, 'icon' => '📦' ],
        'ciblex'         => [ 'label' => 'Ciblex Express',  'active' => true,  'icon' => '📦' ],
        'colissimo'      => [ 'label' => 'Colissimo',       'active' => true,  'icon' => '📦' ],
        'droppex'        => [ 'label' => 'Droppex',          'active' => false, 'icon' => '📦' ],
        'fiabilo'        => [ 'label' => 'Fiabilo',         'active' => true,  'icon' => '🚚' ],
        'firstdelivery'  => [ 'label' => 'First Delivery',  'active' => true,  'icon' => '🚀' ],
        'instadelivery'  => [ 'label' => 'Insta Delivery',   'active' => false, 'icon' => '⚡' ],
        'intigo'         => [ 'label' => 'INTIGO',           'active' => true,  'icon' => '⚡' ],
        'jax'            => [ 'label' => 'Jax Delivery',     'active' => false, 'icon' => '📦' ],
        'jetpack'        => [ 'label' => 'Jetpack',         'active' => true,  'icon' => '🛩️' ],
        'linkex'         => [ 'label' => 'Linkex',           'active' => false, 'icon' => '🔗' ],
        'masafaexpress'  => [ 'label' => 'Masafa Express',   'active' => false, 'icon' => '🌍' ],
        'mylerz'         => [ 'label' => 'Mylerz',           'active' => false, 'icon' => '📦' ],
        'navex'          => [ 'label' => 'Navex',            'active' => true,  'icon' => '⛵' ],
        'shipper'        => [ 'label' => 'Shipper Network',  'active' => true,  'icon' => '🚢' ],
        'xdelivery'      => [ 'label' => 'X Delivery',       'active' => false, 'icon' => '✈️' ],
    ];

    /**
     * Matrice de compatibilité des features par transporteur (source unique).
     * bordereau = BL/étiquette PDF · suivi = get_tracking_status · annulation = cancel_shipment réel.
     * Étensible via le filtre `wcmcs_carrier_features` (transporteurs tiers).
     */
    const CARRIER_FEATURES = [
        'adex'          => [ 'bordereau' => true,  'suivi' => true,  'annulation' => true  ],
        'colissimo'     => [ 'bordereau' => true,  'suivi' => true,  'annulation' => true  ],
        'fiabilo'       => [ 'bordereau' => true,  'suivi' => true,  'annulation' => false ],
        'firstdelivery' => [ 'bordereau' => true,  'suivi' => true,  'annulation' => true  ],
        'jetpack'       => [ 'bordereau' => false, 'suivi' => true,  'annulation' => false ],
        'intigo'        => [ 'bordereau' => true,  'suivi' => true,  'annulation' => false ],
        'afex'          => [ 'bordereau' => true,  'suivi' => true,  'annulation' => true  ],
        'aramex'        => [ 'bordereau' => true,  'suivi' => true,  'annulation' => false ],
        'ciblex'        => [ 'bordereau' => false, 'suivi' => true,  'annulation' => false ],
        'navex'         => [ 'bordereau' => false, 'suivi' => false, 'annulation' => false ],
        'shipper'       => [ 'bordereau' => false, 'suivi' => true,  'annulation' => true  ],
    ];

    // ─────────────────────────────────────────────
    //  Registre des providers
    // ─────────────────────────────────────────────

    /**
     * Enregistre un provider.
     * Doit être appelé pendant le hook wcmcs_register_providers.
     *
     * NB (revue de code 2.10.0) : le PREMIER enregistrement gagne — un
     * provider tiers enregistrant un slug built-in (via le hook) ne peut
     * plus remplacer silencieusement l'implémentation officielle et hériter
     * de ses réglages/credentials : collision = warning + refus.
     */
    public static function register_provider( WCMCS_ShippingProviderInterface $provider ): void {
        $slug = $provider->get_slug();
        if ( isset( self::$providers[ $slug ] ) ) {
            WCMCS_Logger::warning( sprintf(
                'Carrier detector : le slug « %s » est déjà enregistré — le provider tiers (%s) est IGNORÉ (les built-ins ne sont pas remplaçables ; choisissez un slug différent).',
                $slug,
                get_class( $provider )
            ) );
            return;
        }
        self::$providers[ $slug ] = $provider;
    }

    /**
     * Retourne tous les providers enregistrés (implémentés).
     *
     * @return WCMCS_ShippingProviderInterface[]
     */
    public static function get_all_providers(): array {
        self::ensure_initialized();
        return self::$providers;
    }

    /**
     * Retourne les providers activés ET enabled par l'admin.
     *
     * @return WCMCS_ShippingProviderInterface[]
     */
    public static function get_enabled_providers(): array {
        self::ensure_initialized();
        $enabled = [];
        foreach ( self::$providers as $slug => $provider ) {
            if ( $provider->is_active() && self::is_carrier_enabled( $slug ) ) {
                $enabled[ $slug ] = $provider;
            }
        }
        return $enabled;
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
     * Retourne le registre complet des transporteurs (actifs + inactifs),
     * trié par ordre alphabétique du label.
     *
     * @return array<string, array{label: string, active: bool, icon: string}>
     */
    public static function get_carrier_registry(): array {
        $registry = self::CARRIER_REGISTRY;
        uasort( $registry, function ( $a, $b ) {
            return strcasecmp( $a['label'], $b['label'] );
        });
        return $registry;
    }

    /**
     * Vérifie si un transporteur est activé par l'admin.
     *
     * Un transporteur figurant au registre avec active=false n'est jamais
     * activable. Un transporteur implémenté (active=true) est activé par
     * défaut, mais peut être désactivé via l'option wcmcs_carrier_enabled_{slug}.
     * Un provider tiers (hors registre, enregistré via le hook
     * wcmcs_register_providers) est considéré activé : il n'est pas
     * admin-toggleable.
     *
     * @param string $slug
     * @return bool
     */
    public static function is_carrier_enabled( string $slug ): bool {
        $registry = self::CARRIER_REGISTRY;
        if ( ! isset( $registry[ $slug ] ) ) {
            // Provider tiers non listé au registre : activé par défaut.
            return true;
        }
        if ( ! $registry[ $slug ]['active'] ) {
            return false;
        }
        return get_option( "wcmcs_carrier_enabled_{$slug}", 'yes' ) === 'yes';
    }

    /**
     * Matrice de compatibilité d'un transporteur (bordereau / suivi / annulation).
     * Étensible via le filtre `wcmcs_carrier_features` (transporteurs tiers).
     *
     * @param string $slug
     * @return array{bordereau:bool, suivi:bool, annulation:bool}
     */
    public static function get_carrier_features( string $slug ): array {
        $features = self::CARRIER_FEATURES[ $slug ] ?? [];
        return apply_filters( 'wcmcs_carrier_features', $features, $slug );
    }

    /**
     * URL du logo d'un transporteur (assets/images/carriers/{slug}.{ext}) :
     * '' si aucun fichier. Cache statique ; format cherché : png/svg/webp/jpg/jpeg.
     *
     * @param string $slug
     * @return string
     */
    public static function get_logo_url( string $slug ): string {
        static $cache = [];
        if ( ! $slug ) {
            return '';
        }
        if ( array_key_exists( $slug, $cache ) ) {
            return $cache[ $slug ];
        }

        $url = '';
        foreach ( [ 'png', 'svg', 'webp', 'jpg', 'jpeg' ] as $ext ) {
            if ( file_exists( WCMCS_PLUGIN_DIR . 'assets/images/carriers/' . $slug . '.' . $ext ) ) {
                $url = WCMCS_PLUGIN_URL . 'assets/images/carriers/' . $slug . '.' . $ext;
                break;
            }
        }

        $cache[ $slug ] = $url;
        return $url;
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
        self::register_provider( new WCMCS_Adex_Provider() );
        self::register_provider( new WCMCS_FirstDelivery_Provider() );
        self::register_provider( new WCMCS_Colissimo_Provider() );
        self::register_provider( new WCMCS_Jetpack_Provider() );
        self::register_provider( new WCMCS_Fiabilo_Provider() );
        self::register_provider( new WCMCS_Intigo_Provider() );
        self::register_provider( new WCMCS_Afex_Provider() );
        self::register_provider( new WCMCS_Aramex_Provider() );
        self::register_provider( new WCMCS_Navex_Provider() );
        self::register_provider( new WCMCS_Shipper_Provider() );
        self::register_provider( new WCMCS_Ciblex_Provider() );

        // Hook pour providers tiers
        do_action( 'wcmcs_register_providers' );
    }

    // ─────────────────────────────────────────────
    //  Résolution du transporteur pour une commande
    // ─────────────────────────────────────────────

    /**
     * Détecte le transporteur pour une commande.
     *
     * Si un transporteur est trouvé mais désactivé par l'admin,
     * la détection continue au niveau suivant.
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
            if ( self::is_carrier_enabled( $forced ) ) {
                WCMCS_Logger::debug( "Order #{$order_id}: transporteur forcé manuellement → {$forced}" );
                return self::$providers[ $forced ];
            }
            WCMCS_Logger::warning( "Order #{$order_id}: transporteur forcé {$forced} désactivé — détection automatique." );
        }

        // ── 2. Règles custom (hook) ───────────────
        $custom = apply_filters( 'wcmcs_detect_carrier_custom', null, $order, self::$providers );
        if ( $custom instanceof WCMCS_ShippingProviderInterface ) {
            if ( self::is_carrier_enabled( $custom->get_slug() ) ) {
                WCMCS_Logger::debug( "Order #{$order_id}: transporteur via règle custom → {$custom->get_slug()}" );
                return $custom;
            }
            WCMCS_Logger::warning( "Order #{$order_id}: {$custom->get_slug()} trouvé via règle custom mais désactivé, suivant..." );
        }

        // ── 3. Règles par méthode de livraison ────
        $by_method = self::detect_by_shipping_method( $order );
        if ( $by_method && self::is_carrier_enabled( $by_method->get_slug() ) ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via shipping method → {$by_method->get_slug()}" );
            return $by_method;
        }
        if ( $by_method ) {
            WCMCS_Logger::warning( "Order #{$order_id}: {$by_method->get_slug()} trouvé via shipping method mais désactivé, suivant..." );
        }

        // ── 4. Règles par gouvernorat / état ──────
        $by_state = self::detect_by_state( $order );
        if ( $by_state && self::is_carrier_enabled( $by_state->get_slug() ) ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via gouvernorat → {$by_state->get_slug()}" );
            return $by_state;
        }
        if ( $by_state ) {
            WCMCS_Logger::warning( "Order #{$order_id}: {$by_state->get_slug()} trouvé via gouvernorat mais désactivé, suivant..." );
        }

        // ── 5. Règles par catégorie produit ───────
        $by_category = self::detect_by_product_category( $order );
        if ( $by_category && self::is_carrier_enabled( $by_category->get_slug() ) ) {
            WCMCS_Logger::debug( "Order #{$order_id}: transporteur via catégorie produit → {$by_category->get_slug()}" );
            return $by_category;
        }
        if ( $by_category ) {
            WCMCS_Logger::warning( "Order #{$order_id}: {$by_category->get_slug()} trouvé via catégorie mais désactivé, suivant..." );
        }

        // ── 6. Transporteur par défaut ────────────
        $default_slug = get_option( 'wcmcs_default_carrier', '' );
        if ( $default_slug && isset( self::$providers[ $default_slug ] ) && self::is_carrier_enabled( $default_slug ) ) {
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

        // Traduction arabe → français de l'état avant le match (boutiques arabes) :
        // une commande « صفاقس » matche la règle « TN:SFAX ».
        $state   = strtolower( trim( WCMCS_Localization::translate_gouvernorat( $order->get_shipping_state(), $order->get_shipping_city() ) ) );
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