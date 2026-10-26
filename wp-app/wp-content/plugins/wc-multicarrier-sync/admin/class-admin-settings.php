<?php
/**
 * Page de configuration du plugin.
 *
 * Onglets :
 *  - Général (transporteur défaut, mode debug)
 *  - Règles de détection (inclut les cards transporteurs avec modal config)
 *  - Logs
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Admin_Settings {

    const PAGE_SLUG = 'wcmcs-settings';
    const OPTION_GROUP = 'wcmcs_options';

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu_page' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_post_wcmcs_export_history', [ $this, 'export_history_csv' ] );
        add_action( 'admin_notices', [ $this, 'settings_saved_notice' ] );
    }

    // ─────────────────────────────────────────────
    //  Menu
    // ─────────────────────────────────────────────

    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'MultiCarrier Sync', 'wc-multicarrier-sync' ),
            __( 'MultiCarrier Sync TN', 'wc-multicarrier-sync' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    // ─────────────────────────────────────────────
    //  Register Settings API
    // ─────────────────────────────────────────────

    public function register_settings(): void {
        // Paramètres généraux — sanitizers adaptés au type de chaque champ
        register_setting( self::OPTION_GROUP . '_general', 'wcmcs_default_carrier', [
            'sanitize_callback' => [ $this, 'sanitize_carrier_slug' ],
        ] );
        register_setting( self::OPTION_GROUP . '_general', 'wcmcs_debug_logs', [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_general', 'wcmcs_allow_resync', [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_general', 'wcmcs_auto_order_status', [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_general', 'wcmcs_max_auto_retries', [
            'sanitize_callback' => [ $this, 'sanitize_max_retries' ],
        ] );
        // Options du cycle de vie — onglet « Statuts de commande ».
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_ENABLE_SYNCED, [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_ENABLE_DELIVERED, [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_ENABLE_RETURNED, [
            'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_LABEL_SYNCED, [
            'sanitize_callback' => [ $this, 'sanitize_status_label' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_LABEL_DELIVERED, [
            'sanitize_callback' => [ $this, 'sanitize_status_label' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_LABEL_RETURNED, [
            'sanitize_callback' => [ $this, 'sanitize_status_label' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_ON_PAYMENT, [
            'sanitize_callback' => 'sanitize_key',
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_AFTER_SYNC, [
            'sanitize_callback' => 'sanitize_key',
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_LABEL_CONFIRMED, [
            'sanitize_callback' => [ $this, 'sanitize_status_label' ],
        ] );
        register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_LABEL_PACKED, [
            'sanitize_callback' => [ $this, 'sanitize_status_label' ],
        ] );
        foreach ( [ 'en', 'ar' ] as $lang_suffix ) {
            foreach ( array_keys( WCMCS_Order_Statuses::OPTION_LABELS ) as $label_which ) {
                register_setting(
                    self::OPTION_GROUP . '_statuses',
                    WCMCS_Order_Statuses::OPTION_LABELS[ $label_which ] . '_' . $lang_suffix,
                    [ 'sanitize_callback' => [ $this, 'sanitize_status_label' ] ]
                );
            }
        }
        foreach ( array_keys( WCMCS_Order_Statuses::COLOR_DEFAULTS ) as $color_key ) {
            register_setting( self::OPTION_GROUP . '_statuses', WCMCS_Order_Statuses::OPT_COLOR . $color_key, [
                'sanitize_callback' => [ $this, 'sanitize_status_color' ],
            ] );
        }

        // Paramètres providers — injectés dynamiquement depuis chaque provider
        $providers = WCMCS_Carrier_Detector::get_all_providers();
        foreach ( $providers as $provider ) {
            $group = self::OPTION_GROUP . '_' . $provider->get_slug();
            foreach ( $provider->get_settings_fields() as $field ) {
                if ( $field['type'] === 'title' ) {
                    continue;
                }
                if ( $field['type'] === 'note_multiselect' ) {
                    register_setting( $group, $field['id'], [ 'sanitize_callback' => [ $this, 'sanitize_note_fields' ] ] );
                    continue;
                }
                if ( $field['type'] === 'mapping_row' ) {
                    register_setting( $group, $field['id'], [ 'sanitize_callback' => 'sanitize_text_field' ] );
                    $fallback_id = $field['fallback_id'] ?? $field['id'] . '_fallback';
                    register_setting( $group, $fallback_id, [ 'sanitize_callback' => 'sanitize_text_field' ] );
                    continue;
                }
                $sanitize = $field['type'] === 'checkbox' ? [ $this, 'sanitize_checkbox' ] : 'sanitize_text_field';
                if ( $field['type'] === 'textarea' || $field['type'] === 'password' ) {
                    $sanitize = 'sanitize_textarea_field';
                }
                if ( $field['type'] === 'number' ) {
                    $sanitize = 'absint';
                }
                register_setting( $group, $field['id'], [
                    'sanitize_callback' => $sanitize,
                ] );
            }
        }

        // Paramètres règles détection
        register_setting( self::OPTION_GROUP . '_rules', 'wcmcs_rules_by_shipping_method', [
            'sanitize_callback' => [ $this, 'sanitize_rules_array' ],
        ] );
        register_setting( self::OPTION_GROUP . '_rules', 'wcmcs_rules_by_state', [
            'sanitize_callback' => [ $this, 'sanitize_rules_array' ],
        ] );
        register_setting( self::OPTION_GROUP . '_rules', 'wcmcs_rules_by_category', [
            'sanitize_callback' => [ $this, 'sanitize_rules_array' ],
        ] );

        // Activation transporteurs par l'admin
        $carrier_registry = WCMCS_Carrier_Detector::get_carrier_registry();
        foreach ( $carrier_registry as $slug => $info ) {
            if ( $info['active'] ) {
                register_setting( self::OPTION_GROUP . '_rules', "wcmcs_carrier_enabled_{$slug}", [
                    'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                ] );
            }
        }
    }

    /**
     * Sanitize le sélecteur multiple « Note / Commentaire » : liste blanche
     * des clés de sources WC valides (wc_field_options sans l'entrée vide).
     *
     * @param mixed $input
     * @return string[]
     */
    public function sanitize_note_fields( $input ): array {
        $valid = WCMCS_Field_Mapping::wc_field_options();
        unset( $valid[''] );
        $keys = array_map( 'sanitize_key', (array) $input );
        return array_values( array_intersect( $keys, array_keys( $valid ) ) );
    }

    // ─────────────────────────────────────────────
    //  Render
    // ─────────────────────────────────────────────

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $providers  = WCMCS_Carrier_Detector::get_all_providers();
        ?>
        <div class="wrap wcmcs-settings-wrap">
            <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <img src="<?php echo esc_url( WCMCS_PLUGIN_URL . 'assets/images/plugin-logo.png' ); ?>"
                     alt="MultiCarrier Sync TN" style="height:48px;width:48px;border-radius:8px">
                <?php esc_html_e( 'MultiCarrier Sync TN — Configuration', 'wc-multicarrier-sync' ); ?>
                <?php if ( WCMCS_WC_Compat::is_hpos() ) : ?>
                <span class="wcmcs-badge-hpos" style="font-size:12px;background:#0073aa;color:#fff;padding:2px 8px;border-radius:3px;vertical-align:middle">
                    HPOS ✓
                </span>
                <?php endif; ?>
                <?php if ( WCMCS_WC_Compat::has_action_scheduler() ) : ?>
                <span class="wcmcs-badge-as" style="font-size:12px;background:#28a745;color:#fff;padding:2px 8px;border-radius:3px;vertical-align:middle">
                    Action Scheduler ✓
                </span>
                <?php endif; ?>
            </h1>

            <nav class="nav-tab-wrapper">
                <?php $this->render_tab( 'general', __( '⚙ Général', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'statuses', __( '🔄 Statuts de commande', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'license', __( '🔑 Licence', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'rules', __( '🗺 Règles de détection', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'history', __( '📜 Historique', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'logs', __( '📋 Logs', 'wc-multicarrier-sync' ), $active_tab ); ?>
            </nav>

            <div class="wcmcs-tab-content" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-top:none">
                <?php
                switch ( $active_tab ) {
                    case 'general':
                        $this->render_general_tab();
                        break;
                    case 'statuses':
                        $this->render_statuses_tab();
                        break;
                    case 'license':
                        $this->render_license_tab();
                        break;
                    case 'rules':
                        $this->render_rules_tab( $providers );
                        break;
                    case 'history':
                        $this->render_history_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Général
    // ─────────────────────────────────────────────

    private function render_general_tab(): void {
        $enabled_providers = WCMCS_Carrier_Detector::get_enabled_providers();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP . '_general' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Transporteur par défaut', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="wcmcs_default_carrier">
                            <option value=""><?php esc_html_e( '— Aucun (détection auto uniquement) —', 'wc-multicarrier-sync' ); ?></option>
                            <?php foreach ( $enabled_providers as $p ) : ?>
                            <option value="<?php echo esc_attr( $p->get_slug() ); ?>"
                                    <?php selected( get_option( 'wcmcs_default_carrier' ), $p->get_slug() ); ?>>
                                <?php echo esc_html( $p->get_label() ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Utilisé si aucune règle ne correspond à la commande.', 'wc-multicarrier-sync' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Logs de debug', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wcmcs_debug_logs" value="yes"
                                   <?php checked( get_option( 'wcmcs_debug_logs', 'no' ), 'yes' ); ?>>
                            <?php esc_html_e( 'Activer les logs de debug (verbose)', 'wc-multicarrier-sync' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: link to WC logs */
                                esc_html__( 'Consultez les logs dans %s', 'wc-multicarrier-sync' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">WooCommerce → Statut → Logs</a>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Autoriser re-synchronisation', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wcmcs_allow_resync" value="yes"
                                   <?php checked( get_option( 'wcmcs_allow_resync', 'no' ), 'yes' ); ?>>
                            <?php esc_html_e( 'Permettre de re-synchroniser des commandes déjà synchronisées', 'wc-multicarrier-sync' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Mise à jour auto du statut de commande', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wcmcs_auto_order_status" value="yes"
                                   <?php checked( get_option( 'wcmcs_auto_order_status', 'yes' ), 'yes' ); ?>>
                            <?php esc_html_e( 'Suivi automatique du colis (toutes les heures) :', 'wc-multicarrier-sync' ); ?>
                            <?php esc_html_e( 'livré → « Livrée », retour → « Retournée », colis « En attente »/« En cours » (FirstDelivery) → « En attente », note à chaque changement d\'état.', 'wc-multicarrier-sync' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Retries automatiques', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <input type="number" name="wcmcs_max_auto_retries" min="0" max="5" style="width:80px"
                               value="<?php echo esc_attr( get_option( 'wcmcs_max_auto_retries', 0 ) ); ?>">
                        <p class="description">
                            <?php esc_html_e( 'Nombre de retries automatiques en cas d\'échec (0 = désactivé). Backoff exponentiel.', 'wc-multicarrier-sync' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Enregistrer', 'wc-multicarrier-sync' ) ); ?>
        </form>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Statuts de commande (cycle de vie)
    // ─────────────────────────────────────────────

    /**
     * Cycle de vie complet d'une commande : statut à chaque étape.
     *
     * Commande passée (COD) → En attente → [sync] Téléchargée → Livrée / Retournée.
     */
    private function render_statuses_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP . '_statuses' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( '1 · Commande passée (COD / non payée d’avance)', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( WCMCS_Order_Statuses::OPT_ON_PAYMENT ); ?>">
                            <option value="on-hold" <?php selected( get_option( WCMCS_Order_Statuses::OPT_ON_PAYMENT, WCMCS_Order_Statuses::ON_PAYMENT_DEFAULT ), 'on-hold' ); ?>>
                                <?php esc_html_e( 'En attente — reçue non payée, file de confirmation', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="processing" <?php selected( get_option( WCMCS_Order_Statuses::OPT_ON_PAYMENT, WCMCS_Order_Statuses::ON_PAYMENT_DEFAULT ), 'processing' ); ?>>
                                <?php esc_html_e( 'En cours — traitement immédiat', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="" <?php selected( get_option( WCMCS_Order_Statuses::OPT_ON_PAYMENT, WCMCS_Order_Statuses::ON_PAYMENT_DEFAULT ), '' ); ?>>
                                <?php esc_html_e( 'Ne pas changer (comportement WooCommerce)', 'wc-multicarrier-sync' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( '2 · Après synchronisation', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( WCMCS_Order_Statuses::OPT_AFTER_SYNC ); ?>">
                            <option value="synced" <?php selected( get_option( WCMCS_Order_Statuses::OPT_AFTER_SYNC, 'synced' ), 'synced' ); ?>>
                                <?php esc_html_e( 'Téléchargée — étiquette tirée, colis au transporteur', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="on-hold" <?php selected( get_option( WCMCS_Order_Statuses::OPT_AFTER_SYNC, 'synced' ), 'on-hold' ); ?>>
                                <?php esc_html_e( 'En attente', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="processing" <?php selected( get_option( WCMCS_Order_Statuses::OPT_AFTER_SYNC, 'synced' ), 'processing' ); ?>>
                                <?php esc_html_e( 'En cours', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="" <?php selected( get_option( WCMCS_Order_Statuses::OPT_AFTER_SYNC, 'synced' ), '' ); ?>>
                                <?php esc_html_e( 'Ne pas changer', 'wc-multicarrier-sync' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( '3 · À la livraison', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( WCMCS_Order_Statuses::OPT_ENABLE_DELIVERED ); ?>">
                            <option value="yes" <?php checked( get_option( WCMCS_Order_Statuses::OPT_ENABLE_DELIVERED, 'yes' ), 'yes' ); ?>>
                                <?php esc_html_e( 'Livrée (statut personnalisé)', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="no" <?php checked( get_option( WCMCS_Order_Statuses::OPT_ENABLE_DELIVERED, 'yes' ), 'no' ); ?>>
                                <?php esc_html_e( 'Terminée', 'wc-multicarrier-sync' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( '4 · Au retour', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( WCMCS_Order_Statuses::OPT_ENABLE_RETURNED ); ?>">
                            <option value="yes" <?php checked( get_option( WCMCS_Order_Statuses::OPT_ENABLE_RETURNED, 'yes' ), 'yes' ); ?>>
                                <?php esc_html_e( 'Retournée (statut personnalisé)', 'wc-multicarrier-sync' ); ?>
                            </option>
                            <option value="no" <?php checked( get_option( WCMCS_Order_Statuses::OPT_ENABLE_RETURNED, 'yes' ), 'no' ); ?>>
                                <?php esc_html_e( 'En attente', 'wc-multicarrier-sync' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Apparence des statuts', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <?php
                        // Grille : 1 ligne = 1 statut × (aperçu, FR, EN, AR, couleur, reset).
                        $design_rows = [
                            // Statuts plugin : libellés éditables dans les 3 langues.
                            'wcmcs-confirmed' => [ 'which' => 'confirmed' ],
                            'wcmcs-packed'    => [ 'which' => 'packed' ],
                            'wcmcs-synced'    => [ 'which' => 'synced' ],
                            'wcmcs-delivered' => [ 'which' => 'delivered' ],
                            'wcmcs-returned'  => [ 'which' => 'returned' ],
                            // Statuts WooCommerce natifs : libellés figés, couleur seule éditable.
                            'processing'      => [ 'which' => null, 'nom' => __( 'En cours (WC natif)', 'wc-multicarrier-sync' ) ],
                            'on-hold'         => [ 'which' => null, 'nom' => __( 'En attente (WC natif)', 'wc-multicarrier-sync' ) ],
                        ];
                        $design_langs  = [ 'fr' => 'FR', 'en' => 'EN', 'ar' => 'AR' ];
                        $cur_lang      = WCMCS_Order_Statuses::current_language();
                        ?>
                        <div class="wcmcs-design-wrap" style="overflow-x:auto">
                        <table class="widefat striped wcmcs-status-design" data-curlang="<?php echo esc_attr( $cur_lang ); ?>" style="max-width:1100px">
                            <thead>
                                <tr>
                                    <th style="width:150px"><?php esc_html_e( 'Statut', 'wc-multicarrier-sync' ); ?></th>
                                    <th style="width:120px"><?php esc_html_e( 'Aperçu', 'wc-multicarrier-sync' ); ?></th>
                                    <?php foreach ( $design_langs as $lcle => $lsc ) : ?>
                                        <th style="width:150px"<?php echo 'ar' === $lcle ? ' class="wcmcs-rtl"' : ''; ?>><?php echo esc_html( $lsc ); ?></th>
                                    <?php endforeach; ?>
                                    <th style="width:90px"><?php esc_html_e( 'Couleur', 'wc-multicarrier-sync' ); ?></th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <?php foreach ( $design_rows as $cle => $row ) : ?>
                                <?php
                                $which      = $row['which'];
                                $defaut     = WCMCS_Order_Statuses::COLOR_DEFAULTS[ $cle ] ?? '#777777';
                                $courant    = WCMCS_Order_Statuses::status_color( $cle );
                                $couleur    = $courant ? $courant : $defaut;
                                $titre_ligne = $which ? WCMCS_Order_Statuses::LABEL_DEFAULTS['fr'][ $which ] : $row['nom'];
                                $chip_txt   = $which ? WCMCS_Order_Statuses::label( $which ) : $titre_ligne;
                                ?>
                                <tr data-key="<?php echo esc_attr( $cle ); ?>">
                                    <td><strong class="wcmcs-status-name"><?php echo esc_html( $titre_ligne ); ?></strong></td>
                                    <td>
                                        <span class="wcmcs-design-chip" style="background: <?php echo esc_attr( $couleur ); ?>; color: <?php echo esc_attr( WCMCS_Order_Statuses::text_for_color( $couleur ) ); ?>"><?php echo esc_html( $chip_txt ); ?></span>
                                    </td>
                                    <?php foreach ( $design_langs as $lcle => $lsc ) :
                                        if ( ! $which ) : ?>
                                            <td class="wcmcs-design-native"><span aria-hidden="true">—</span></td>
                                            <?php continue; endif;
                                        // FR = options héritées (suffixe vide) ; EN/AR = suffixe _en/_ar.
                                        $opt  = ( 'fr' === $lcle ) ? WCMCS_Order_Statuses::OPTION_LABELS[ $which ] : WCMCS_Order_Statuses::OPTION_LABELS[ $which ] . '_' . $lcle;
                                        $valeur_defaut = WCMCS_Order_Statuses::LABEL_DEFAULTS[ $lcle ][ $which ] ?? '';
                                        ?>
                                        <td<?php echo 'ar' === $lcle ? ' class="wcmcs-rtl"' : ''; ?>>
                                            <input type="text" class="wcmcs-design-label" data-lang="<?php echo esc_attr( $lcle ); ?>"
                                                name="<?php echo esc_attr( $opt ); ?>"
                                                placeholder="<?php echo esc_attr( $valeur_defaut ); ?>"
                                                value="<?php echo esc_attr( get_option( $opt, '' ) ); ?>"></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <input type="color" class="wcmcs-design-color"
                                            name="<?php echo esc_attr( WCMCS_Order_Statuses::OPT_COLOR . $cle ); ?>"
                                            value="<?php echo esc_attr( $couleur ); ?>"
                                            data-default="<?php echo esc_attr( $defaut ); ?>"></td>
                                    <td>
                                        <button type="button" class="button wcmcs-design-reset" title="<?php esc_attr_e( 'Remettre la couleur par défaut', 'wc-multicarrier-sync' ); ?>" data-default="<?php echo esc_attr( $defaut ); ?>">↺</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'Aperçu live : le badge se met à jour à la frappe et au changement de couleur. Laissez vos libellés vide pour le défaut de la langue. Statuts natifs WooCommerce (En cours / En attente) : libellés figés, couleur seule modifiable.', 'wc-multicarrier-sync' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Enregistrer', 'wc-multicarrier-sync' ) ); ?>
        </form>
        <p class="description" style="margin-top:12px">
            <?php esc_html_e( 'Pipeline par défaut : Commande (COD) → En attente → Confirmée → Emballée → Téléchargée → Livrée / Retournée. L’admin peut passer d’une étape à l’autre librement (ex. En attente → Téléchargée). Le suivi automatique et le scan des statuts suivent toutes les étapes.', 'wc-multicarrier-sync' ); ?>
        </p>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Licence
    // ─────────────────────────────────────────────

    private function render_license_tab(): void {
        $license = WCMCS_License::get_instance();

        if ( isset( $_POST['wcmcs_activate_license'] ) && check_admin_referer( 'wcmcs_license_action', 'wcmcs_license_nonce' ) ) {
            $key    = sanitize_text_field( wp_unslash( $_POST['wcmcs_license_key'] ?? '' ) );
            $result = $license->activate_license( $key );
            if ( is_wp_error( $result ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            } else {
                printf(
                    '<div class="notice notice-success"><p>%s</p></div>',
                    esc_html( sprintf(
                        /* translators: %s domaine lié */
                        __( 'Licence activée avec succès — liée au domaine %s.', 'wc-multicarrier-sync' ),
                        $license->get_site_fingerprint()
                    ) )
                );
            }
        }

        if ( isset( $_POST['wcmcs_deactivate_license'] ) && check_admin_referer( 'wcmcs_license_action', 'wcmcs_license_nonce' ) ) {
            $license->deactivate_license();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Licence désactivée — le domaine est libéré, vous pouvez réutiliser la clé sur un autre site.', 'wc-multicarrier-sync' ) . '</p></div>';
        }

        $info            = $license->get_license_info();
        $is_licensed     = $license->is_licensed();
        $is_trial_active = $license->is_trial_active();
        $card_class      = $is_licensed ? 'wcmcs-status-licensed'
            : ( $is_trial_active ? 'wcmcs-status-trial' : 'wcmcs-status-free' );
        ?>
        <div class="wcmcs-license-page">
            <div class="wcmcs-license-status-card <?php echo esc_attr( $card_class ); ?>">
                <div class="wcmcs-license-status-icon">
                    <?php if ( $is_licensed ) : ?>
                        <span style="font-size:40px">🔑</span>
                    <?php elseif ( $is_trial_active ) : ?>
                        <span style="font-size:40px">⏱</span>
                    <?php else : ?>
                        <span style="font-size:40px">🆓</span>
                    <?php endif; ?>
                </div>
                <div class="wcmcs-license-status-info">
                    <h2>
                        <?php if ( $is_licensed ) : ?>
                            <?php esc_html_e( 'Licence active (à vie)', 'wc-multicarrier-sync' ); ?>
                        <?php elseif ( $is_trial_active ) : ?>
                            <?php esc_html_e( 'Période d\'essai gratuite', 'wc-multicarrier-sync' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Mode gratuit', 'wc-multicarrier-sync' ); ?>
                        <?php endif; ?>
                    </h2>
                    <?php if ( $is_licensed ) : ?>
                        <p><?php esc_html_e( 'Merci pour votre confiance ! Synchronisations illimitées sur ce site.', 'wc-multicarrier-sync' ); ?></p>
                        <ul style="margin:8px 0 0;list-style:disc;padding-left:18px">
                            <li>
                                <?php printf(
                                    /* translators: %s domaine */
                                    esc_html__( 'Domaine lié : %s (une licence = un site)', 'wc-multicarrier-sync' ),
                                    '<code>' . esc_html( $info['fingerprint'] ) . '</code>'
                                ); ?>
                            </li>
                            <?php if ( $info['last_check'] ) : ?>
                            <li>
                                <?php printf(
                                    /* translators: %s date */
                                    esc_html__( 'Dernière vérification : %s', 'wc-multicarrier-sync' ),
                                    '<strong>' . esc_html( date_i18n( 'd/m/Y H:i', $info['last_check'] ) ) . '</strong>'
                                ); ?>
                            </li>
                            <?php endif; ?>
                            <?php if ( $info['offline_since'] ) : ?>
                            <li style="color:#b35900">
                                <?php printf(
                                    /* translators: 1: jours écoulés, 2: tolérance */
                                    esc_html__( 'Serveur de licences injoignable depuis %1$d j (tolérance %2$d j) — la licence reste active localement.', 'wc-multicarrier-sync' ),
                                    (int) floor( ( time() - $info['offline_since'] ) / DAY_IN_SECONDS ),
                                    WCMCS_License::GRACE_PERIOD_DAYS
                                ); ?>
                            </li>
                            <?php endif; ?>
                        </ul>
                    <?php elseif ( $is_trial_active ) : ?>
                        <p>
                            <?php printf(
                                esc_html__( 'Plus que %s pour tester le plugin en illimité.', 'wc-multicarrier-sync' ),
                                '<strong>' . esc_html( $info['trial_remaining'] ) . '</strong>'
                            ); ?>
                        </p>
                        <p class="wcmcs-expires-info">
                            <?php printf(
                                esc_html__( 'Essai expire le : %s — ensuite, mode gratuit (%d syncs/jour).', 'wc-multicarrier-sync' ),
                                '<strong>' . esc_html( $info['trial_expires'] ) . '</strong>',
                                (int) $info['daily_limit']
                            ); ?>
                        </p>
                    <?php else : ?>
                        <p>
                            <?php printf(
                                /* translators: %d limite quotidienne */
                                esc_html__( 'Toutes les fonctionnalités, sans licence : %d synchronisations réussies par jour.', 'wc-multicarrier-sync' ),
                                (int) $info['daily_limit']
                            ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Aujourd\'hui :', 'wc-multicarrier-sync' ); ?></strong>
                            <?php echo esc_html( sprintf( '%d/%d syncs', (int) $info['daily_count'], (int) $info['daily_limit'] ) ); ?>
                            <span style="display:inline-block;background:#fff;border:1px solid #f0ad4e;border-radius:3px;height:12px;width:160px;margin-left:8px;vertical-align:middle;overflow:hidden">
                                <span style="display:block;height:100%;width:<?php echo esc_attr( min( 100, (int) round( 100 * (int) $info['daily_count'] / max( 1, (int) $info['daily_limit'] ) ) ) ); ?>%;background:#f0ad4e"></span>
                            </span>
                            <?php if ( (int) $info['daily_remaining'] > 0 ) : ?>
                                <em><?php printf( esc_html__( '— %d restante(s)', 'wc-multicarrier-sync' ), (int) $info['daily_remaining'] ); ?></em>
                            <?php else : ?>
                                <em style="color:#b35900"><?php esc_html_e( '— quota atteint, nouvelle sync demain', 'wc-multicarrier-sync' ); ?></em>
                            <?php endif; ?>
                        </p>
                        <?php if ( ! empty( $info['status'] ) ) : ?>
                        <p style="color:#dc3545;margin-top:6px">
                            <strong><?php echo esc_html( $info['status_label'] ); ?></strong>
                            <?php esc_html_e( '— retour au mode gratuit.', 'wc-multicarrier-sync' ); ?>
                        </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" style="margin-top:24px;max-width:500px">
                <?php wp_nonce_field( 'wcmcs_license_action', 'wcmcs_license_nonce' ); ?>
                <h3 style="margin-bottom:12px"><?php esc_html_e( 'Clé de licence', 'wc-multicarrier-sync' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="wcmcs_license_key"><?php esc_html_e( 'Clé', 'wc-multicarrier-sync' ); ?></label></th>
                        <td>
                            <?php if ( $is_licensed ) : ?>
                                <input type="text" id="wcmcs_license_key" name="wcmcs_license_key"
                                       value="<?php echo esc_attr( $info['key'] ); ?>"
                                       class="regular-text" style="background:#f0f0f0" readonly>
                            <?php else : ?>
                                <input type="text" id="wcmcs_license_key" name="wcmcs_license_key"
                                       value=""
                                       class="regular-text"
                                       placeholder="WCMCS-XXXX-XXXX-XXXX"
                                       autocomplete="off">
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e( 'Sans licence : mode gratuit (syncs limitées par jour). La clé lève la limite — une licence = un site (liaison automatique au domaine).', 'wc-multicarrier-sync' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php if ( $is_licensed ) : ?>
                    <button type="submit" name="wcmcs_deactivate_license" class="button" style="margin-top:8px">
                        <?php esc_html_e( 'Désactiver la licence (libère le domaine)', 'wc-multicarrier-sync' ); ?>
                    </button>
                <?php else : ?>
                    <button type="submit" name="wcmcs_activate_license" class="button button-primary" style="margin-top:8px">
                        <?php esc_html_e( 'Activer la licence', 'wc-multicarrier-sync' ); ?>
                    </button>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Règles de détection
    // ─────────────────────────────────────────────

    private function render_rules_tab( array $providers ): void {
        $enabled_providers = WCMCS_Carrier_Detector::get_enabled_providers();
        $provider_options = '<option value="">' . esc_html__( '— Choisir —', 'wc-multicarrier-sync' ) . '</option>';
        foreach ( $enabled_providers as $p ) {
            $provider_options .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr( $p->get_slug() ),
                esc_html( $p->get_label() )
            );
        }

        $carrier_registry = WCMCS_Carrier_Detector::get_carrier_registry();

        // Actifs (intégrés) toujours en premier — ordre alphabétique conservé dans chaque groupe.
        $active_carriers   = array_filter( $carrier_registry, static fn( array $c ) => ! empty( $c['active'] ) );
        $inactive_carriers = array_filter( $carrier_registry, static fn( array $c ) => empty( $c['active'] ) );
        $carrier_registry  = $active_carriers + $inactive_carriers;

        $rules_method   = get_option( 'wcmcs_rules_by_shipping_method', [] );
        $rules_state    = get_option( 'wcmcs_rules_by_state', [] );
        $rules_category = get_option( 'wcmcs_rules_by_category', [] );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP . '_rules' ); ?>

            <h2><?php esc_html_e( 'Transporteurs disponibles', 'wc-multicarrier-sync' ); ?></h2>
            <p class="wcmcs-carrier-search-wrap">
                <label for="wcmcs-carrier-search" class="screen-reader-text"><?php esc_html_e( 'Rechercher un transporteur', 'wc-multicarrier-sync' ); ?></label>
                <input type="search"
                       id="wcmcs-carrier-search"
                       placeholder="<?php esc_attr_e( 'Rechercher un transporteur…', 'wc-multicarrier-sync' ); ?>"
                       autocomplete="off">
            </p>
            <div class="wcmcs-carriers-grid">
                <?php foreach ( $carrier_registry as $slug => $info ) :
                    $is_active       = ! empty( $info['active'] );
                    $is_enabled      = WCMCS_Carrier_Detector::is_carrier_enabled( $slug );
                    $has_provider    = isset( $providers[ $slug ] );
                    $is_configured   = $has_provider && $providers[ $slug ]->is_configured();
                    $icon            = $info['icon'] ?? '📦';
                    $search_data     = strtolower( remove_accents( $info['label'] . ' ' . $slug ) );
                ?>
                <div class="wcmcs-carrier-card <?php echo $is_active ? 'wcmcs-card-active' : 'wcmcs-card-inactive'; ?>"
                     data-search="<?php echo esc_attr( $search_data ); ?>"
                     title="<?php echo esc_attr( $info['label'] ); ?>">
                    <div class="wcmcs-card-header">
                        <?php echo $this->get_carrier_icon_html( $slug, $icon, $info['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé en interne ?>
                        <div class="wcmcs-card-title">
                    <?php if ( $is_active ) :
                        $features = WCMCS_Carrier_Detector::get_carrier_features( $slug );
                        $feature_labels = [
                            'bordereau'  => __( '📄 Bordereau', 'wc-multicarrier-sync' ),
                            'suivi'      => __( '📍 Suivi', 'wc-multicarrier-sync' ),
                            'annulation' => __( '↺ Annulation', 'wc-multicarrier-sync' ),
                        ];
                        if ( $features ) : ?>
                    <div class="wcmcs-card-features">
                        <?php foreach ( $feature_labels as $fkey => $flabel ) :
                            $fon = ! empty( $features[ $fkey ] ); ?>
                        <span class="wcmcs-feature-chip <?php echo $fon ? 'wcmcs-feature-on' : 'wcmcs-feature-off'; ?>">
                            <?php echo esc_html( $flabel ); ?> <?php echo $fon ? '✓' : '✗'; ?>
                        </span>
                <?php endforeach; ?>
            </div>
                    <?php endif; endif; ?>
                    <?php if ( $is_active ) : ?>
                                <span class="wcmcs-card-status <?php echo $is_enabled ? 'wcmcs-status-active' : 'wcmcs-status-disabled'; ?>">
                                    <?php echo $is_enabled ? esc_html__( 'Activé', 'wc-multicarrier-sync' ) : esc_html__( 'Désactivé', 'wc-multicarrier-sync' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="wcmcs-card-status wcmcs-status-soon"><?php esc_html_e( 'Bientôt disponible', 'wc-multicarrier-sync' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $is_active ) : ?>
                    <div class="wcmcs-card-body">
                        <label class="wcmcs-toggle">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( "wcmcs_carrier_enabled_{$slug}" ); ?>"
                                   value="yes"
                                   data-slug="<?php echo esc_attr( $slug ); ?>"
                                   <?php checked( $is_enabled, true ); ?>>
                            <span class="wcmcs-toggle-slider"></span>
                            <span class="wcmcs-toggle-label"><?php esc_html_e( 'Activé', 'wc-multicarrier-sync' ); ?></span>
                        </label>
                    </div>
                    <div class="wcmcs-card-footer">
                        <?php if ( $has_provider ) : ?>
                            <button type="button"
                                    class="<?php echo $is_configured ? 'wcmcs-card-config-btn wcmcs-card-configured' : 'wcmcs-card-config-btn'; ?>"
                                    data-provider="<?php echo esc_attr( $slug ); ?>">
                                <?php echo $is_configured
                                    ? '✓ ' . esc_html__( 'Configuré', 'wc-multicarrier-sync' )
                                    : '⚙ ' . esc_html__( 'Configurer', 'wc-multicarrier-sync' ); ?>
                            </button>
                        <?php else : ?>
                            <span class="wcmcs-footer-badge wcmcs-footer-na">—</span>
                        <?php endif; ?>
                    </div>
                    <?php else : ?>
                    <div class="wcmcs-card-body">
                        <p class="wcmcs-card-soon-text"><?php esc_html_e( 'Cette intégration sera bientôt disponible.', 'wc-multicarrier-sync' ); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <p id="wcmcs-carrier-no-results" class="wcmcs-carrier-empty" style="display:none">
                <?php esc_html_e( 'Aucun transporteur trouvé.', 'wc-multicarrier-sync' ); ?>
            </p>

            <hr style="margin:20px 0;border:0;border-top:1px solid #ccd0d4">

            <h2><?php esc_html_e( '1. Règles par méthode de livraison', 'wc-multicarrier-sync' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Clé : ID de la méthode WC (ex: flat_rate:1, free_shipping:2). Priorité 1.', 'wc-multicarrier-sync' ); ?>
            </p>
            <?php $this->render_rules_editor( 'wcmcs_rules_by_shipping_method', $rules_method, $provider_options, __( 'Method ID (ex: flat_rate:1)', 'wc-multicarrier-sync' ) ); ?>

            <h2><?php esc_html_e( '2. Règles par gouvernorat / pays', 'wc-multicarrier-sync' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Clé : code pays ISO ou "PAYS:ETAT" (ex: TN, TN:SFAX, FR). Priorité 2.', 'wc-multicarrier-sync' ); ?>
            </p>
            <?php $this->render_rules_editor( 'wcmcs_rules_by_state', $rules_state, $provider_options, __( 'Pays:État (ex: TN:SFAX)', 'wc-multicarrier-sync' ) ); ?>

            <h2><?php esc_html_e( '3. Règles par catégorie de produit', 'wc-multicarrier-sync' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Clé : slug de la catégorie WooCommerce (ex: electronics). Priorité 3.', 'wc-multicarrier-sync' ); ?>
            </p>
            <?php $this->render_rules_editor( 'wcmcs_rules_by_category', $rules_category, $provider_options, __( 'Slug catégorie (ex: electronics)', 'wc-multicarrier-sync' ) ); ?>

            <?php submit_button( __( 'Enregistrer les règles', 'wc-multicarrier-sync' ) ); ?>
        </form>

        <?php
        // ── Modals de configuration par transporteur ─────
        foreach ( $providers as $provider ) :
            if ( ! $provider->is_active() || ! isset( $providers[ $provider->get_slug() ] ) ) {
                continue;
            }
            $slug  = $provider->get_slug();
            $icon  = WCMCS_Carrier_Detector::get_carrier_registry()[ $slug ]['icon'] ?? '📦';
            $group = self::OPTION_GROUP . '_' . $slug;
            $warning_class = ! $provider->is_configured() ? 'notice notice-warning inline' : '';
        ?>
        <div class="wcmcs-config-overlay" id="wcmcs-config-overlay-<?php echo esc_attr( $slug ); ?>">
            <div class="wcmcs-config-modal" id="wcmcs-config-modal-<?php echo esc_attr( $slug ); ?>" role="dialog" aria-modal="true">
                <div class="wcmcs-config-modal-header">
                    <div class="wcmcs-config-modal-title">
                        <?php echo $this->get_carrier_icon_html( $slug, $icon ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé en interne ?>
                        <span><?php echo esc_html( $provider->get_label() ); ?></span>
                    </div>
                    <button type="button" class="wcmcs-config-modal-close" data-close="<?php echo esc_attr( $slug ); ?>" title="<?php esc_attr_e( 'Fermer', 'wc-multicarrier-sync' ); ?>">&times;</button>
                </div>
                <?php if ( ! $provider->is_configured() ) : ?>
                <div class="notice notice-warning inline" style="margin:12px 16px 0">
                    <p><?php printf( esc_html__( '⚠ %s n\'est pas encore configuré.', 'wc-multicarrier-sync' ), esc_html( $provider->get_label() ) ); ?></p>
                </div>
                <?php endif; ?>
                <div class="wcmcs-config-modal-body">
                    <form method="post" action="options.php" id="wcmcs-config-form-<?php echo esc_attr( $slug ); ?>">
                        <?php settings_fields( $group ); ?>
                        <table class="form-table">
                            <?php foreach ( $provider->get_settings_fields() as $field ) : ?>
                            <?php if ( $field['type'] === 'title' ) : ?>
                                <tr><td colspan="2"><h3 style="margin-top:20px;padding-bottom:4px;border-bottom:1px solid #ccc"><?php echo esc_html( $field['title'] ); ?></h3></td></tr>
                            <?php else : ?>
                            <tr>
                                <th>
                                    <label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
                                </th>
                                <td>
                                    <?php $this->render_field( $field ); ?>
                                    <?php if ( ! empty( $field['description'] ) ) : ?>
                                    <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </table>
                        <?php submit_button( __( 'Enregistrer', 'wc-multicarrier-sync' ) ); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <script>
        jQuery(function($){
            // Recherche transporteurs temps réel (champ hors formulaire — jamais soumis)
            var $carrierCards = $('.wcmcs-carrier-card');
            var $carrierSearch = $('#wcmcs-carrier-search');
            if ($carrierSearch.length) {
                $carrierSearch.on('input', function(){
                    var q = String(this.value || '').toLowerCase();
                    if (q.normalize) {
                        q = q.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                    }
                    var matched = 0;
                    $carrierCards.each(function(){
                        var hay = String($(this).data('search') || '');
                        var show = hay.indexOf(q) !== -1;
                        $(this).toggleClass('wcmcs-carrier-hidden', !show);
                        if (show) { matched++; }
                    });
                    $('#wcmcs-carrier-no-results').toggle(matched === 0);
                });
                // Entrée dans la recherche ne doit pas soumettre le formulaire options.php
                $carrierSearch.on('keydown', function(e){
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            }

            $('.wcmcs-add-rule').on('click', function(){
                var container = $(this).closest('.wcmcs-rules-container');
                var template  = container.find('.wcmcs-rule-row:first').clone();
                template.find('input').val('');
                template.find('select').val('');
                container.find('.wcmcs-rules-list').append(template);
            });
            $(document).on('click', '.wcmcs-remove-rule', function(){
                var list = $(this).closest('.wcmcs-rules-list');
                if(list.find('.wcmcs-rule-row').length > 1) {
                    $(this).closest('.wcmcs-rule-row').remove();
                } else {
                    $(this).closest('.wcmcs-rule-row').find('input,select').val('');
                }
            });

            // Config modal open
            $(document).on('click', '.wcmcs-card-config-btn', function(){
                var slug = $(this).data('provider');
                $('#wcmcs-config-overlay-' + slug).addClass('wcmcs-config-visible');
                $('body').addClass('wcmcs-modal-open');
            });

            // Config modal close
            $(document).on('click', '.wcmcs-config-modal-close, .wcmcs-config-overlay', function(e){
                if (e.target === this) {
                    var slug = $(this).data('close') || $(this).attr('id').replace('wcmcs-config-overlay-', '');
                    $('#wcmcs-config-overlay-' + slug).removeClass('wcmcs-config-visible');
                    $('body').removeClass('wcmcs-modal-open');
                }
            });

            // Escape key close
            $(document).on('keydown', function(e){
                if (e.key === 'Escape' && $('body').hasClass('wcmcs-modal-open')) {
                    $('.wcmcs-config-visible').removeClass('wcmcs-config-visible');
                    $('body').removeClass('wcmcs-modal-open');
                }
            });
        });
        </script>
        <?php
    }

    private function render_rules_editor( string $option_name, array $rules, string $provider_options, string $key_placeholder ): void {
        // S'assurer au moins une ligne
        if ( empty( $rules ) ) {
            $rules = [ '' => '' ];
        }
        ?>
        <div class="wcmcs-rules-container">
            <div class="wcmcs-rules-list">
                <?php foreach ( $rules as $key => $carrier_slug ) : ?>
                <div class="wcmcs-rule-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                    <input type="text"
                           name="<?php echo esc_attr( $option_name ); ?>[key][]"
                           value="<?php echo esc_attr( $key ); ?>"
                           placeholder="<?php echo esc_attr( $key_placeholder ); ?>"
                           style="width:250px">
                    <span>→</span>
                    <select name="<?php echo esc_attr( $option_name ); ?>[carrier][]">
                        <?php
                        // Remplacer la valeur sélectionnée
                        echo str_replace(
                            'value="' . esc_attr( $carrier_slug ) . '"',
                            'value="' . esc_attr( $carrier_slug ) . '" selected',
                            $provider_options
                        );
                        ?>
                    </select>
                    <button type="button" class="button wcmcs-remove-rule">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button wcmcs-add-rule" style="margin-top:6px">
                + <?php esc_html_e( 'Ajouter une règle', 'wc-multicarrier-sync' ); ?>
            </button>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Historique (sync)
    // ─────────────────────────────────────────────

    private function render_history_tab(): void {
        $filter_order   = isset( $_GET['wcmcs_h_order'] ) ? absint( $_GET['wcmcs_h_order'] ) : 0;
        $filter_carrier = isset( $_GET['wcmcs_h_carrier'] ) ? sanitize_key( wp_unslash( $_GET['wcmcs_h_carrier'] ) ) : '';
        $filter_status  = isset( $_GET['wcmcs_h_status'] ) ? sanitize_key( wp_unslash( $_GET['wcmcs_h_status'] ) ) : '';
        $filter_from    = isset( $_GET['wcmcs_h_from'] ) ? sanitize_text_field( wp_unslash( $_GET['wcmcs_h_from'] ) ) : '';
        $filter_to      = isset( $_GET['wcmcs_h_to'] ) ? sanitize_text_field( wp_unslash( $_GET['wcmcs_h_to'] ) ) : '';
        $page           = isset( $_GET['wcmcs_h_page'] ) ? max( 1, absint( $_GET['wcmcs_h_page'] ) ) : 1;

        $per_page = 20;
        $result   = WCMCS_Order_Meta::get_sync_history_all( [
            'order_id' => $filter_order,
            'carrier'  => $filter_carrier,
            'status'   => $filter_status,
            'date_from' => $filter_from,
            'date_to'  => $filter_to,
            'page'     => $page,
            'per_page' => $per_page,
        ] );
        $rows  = $result['rows'];
        $total = $result['total'];
        $pages = (int) ceil( $total / $per_page );

        $base_query = [
            'page'           => self::PAGE_SLUG,
            'tab'            => 'history',
            'wcmcs_h_order'  => $filter_order ?: '',
            'wcmcs_h_carrier' => $filter_carrier,
            'wcmcs_h_status' => $filter_status,
            'wcmcs_h_from'   => $filter_from,
            'wcmcs_h_to'     => $filter_to,
        ];
        ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
            <input type="hidden" name="tab" value="history" />
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
                <div>
                    <label><strong><?php esc_html_e( 'Commande #', 'wc-multicarrier-sync' ); ?></strong></label><br>
                    <input type="number" name="wcmcs_h_order" value="<?php echo esc_attr( $filter_order ); ?>" style="width:100px">
                </div>
                <div>
                    <label><strong><?php esc_html_e( 'Transporteur', 'wc-multicarrier-sync' ); ?></strong></label><br>
                    <select name="wcmcs_h_carrier">
                        <option value=""><?php esc_html_e( '— Tous —', 'wc-multicarrier-sync' ); ?></option>
                        <?php foreach ( WCMCS_Carrier_Detector::get_all_providers() as $slug => $p ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filter_carrier, $slug ); ?>><?php echo esc_html( $p->get_label() ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><strong><?php esc_html_e( 'Statut', 'wc-multicarrier-sync' ); ?></strong></label><br>
                    <select name="wcmcs_h_status">
                        <option value=""><?php esc_html_e( '— Tous —', 'wc-multicarrier-sync' ); ?></option>
                        <?php foreach ( [ 'pending', 'synced', 'failed', 'queued' ] as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $filter_status, $st ); ?>><?php echo esc_html( WCMCS_Order_Meta::get_status_label( $st ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><strong><?php esc_html_e( 'Du', 'wc-multicarrier-sync' ); ?></strong></label><br>
                    <input type="date" name="wcmcs_h_from" value="<?php echo esc_attr( $filter_from ); ?>">
                </div>
                <div>
                    <label><strong><?php esc_html_e( 'Au', 'wc-multicarrier-sync' ); ?></strong></label><br>
                    <input type="date" name="wcmcs_h_to" value="<?php echo esc_attr( $filter_to ); ?>">
                </div>
                <button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'wc-multicarrier-sync' ); ?></button>
                <a class="button button-secondary"
                   href="<?php echo esc_url( wp_nonce_url(
                       add_query_arg( array_filter( $base_query, static fn( $v ) => '' !== $v ) + [ 'action' => 'wcmcs_export_history' ], admin_url( 'admin-post.php' ) ),
                       'wcmcs_export_history'
                   ) ); ?>">
                    ⬇ <?php esc_html_e( 'Exporter CSV', 'wc-multicarrier-sync' ); ?>
                </a>
            </div>
        </form>

        <p style="margin:0 0 8px">
            <strong><?php printf( esc_html__( '%d tentative(s)', 'wc-multicarrier-sync' ), $total ); ?></strong>
            <?php if ( $pages > 1 ) : ?>
            — <?php printf( esc_html__( 'page %1$d / %2$d', 'wc-multicarrier-sync' ), $page, $pages ); ?>
            <?php endif; ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'wc-multicarrier-sync' ); ?></th>
                    <th><?php esc_html_e( 'Commande', 'wc-multicarrier-sync' ); ?></th>
                    <th><?php esc_html_e( 'Transporteur', 'wc-multicarrier-sync' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'wc-multicarrier-sync' ); ?></th>
                    <th><?php esc_html_e( 'Tracking', 'wc-multicarrier-sync' ); ?></th>
                    <th><?php esc_html_e( 'Erreur', 'wc-multicarrier-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( $rows ) : foreach ( $rows as $r ) :
                    $r_provider = WCMCS_Carrier_Detector::get_provider_by_slug( $r->carrier_slug );
                    $r_label    = $r_provider ? $r_provider->get_label() : ( $r->carrier_slug ?: '—' );
                ?>
                <tr>
                    <td><?php echo esc_html( $r->synced_at ); ?></td>
                    <td><a href="<?php echo esc_url( WCMCS_WC_Compat::get_order_edit_url( (int) $r->order_id ) ); ?>">#<?php echo esc_html( $r->order_id ); ?></a></td>
                    <td><?php echo esc_html( $r_label ); ?></td>
                    <td><?php echo wp_kses_post( WCMCS_Order_Meta::get_status_badge( $r->status ) ); ?></td>
                    <td><?php echo $r->tracking_id ? '<code>' . esc_html( $r->tracking_id ) . '</code>' : '—'; ?></td>
                    <td <?php if ( $r->error_msg ) : ?>title="<?php echo esc_attr( $r->error_msg ); ?>"<?php endif; ?>>
                        <?php echo $r->error_msg ? esc_html( wp_html_excerpt( $r->error_msg, 60, '…' ) ) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; else : ?>
                <tr><td colspan="6"><?php esc_html_e( 'Aucune tentative enregistrée pour ces filtres.', 'wc-multicarrier-sync' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
        <p style="margin:12px 0 0">
            <?php
            if ( $page > 1 ) {
                printf( '<a class="button" href="%s">‹ %s</a> ', esc_url( add_query_arg( $base_query + [ 'wcmcs_h_page' => $page - 1 ], admin_url( 'admin.php' ) ) ), esc_html__( 'Précédent', 'wc-multicarrier-sync' ) );
            }
            if ( $page < $pages ) {
                printf( '<a class="button" href="%s">%s ›</a>', esc_url( add_query_arg( $base_query + [ 'wcmcs_h_page' => $page + 1 ], admin_url( 'admin.php' ) ) ), esc_html__( 'Suivant', 'wc-multicarrier-sync' ) );
            }
            ?>
        </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Export CSV de l'historique (filtres courants) — admin-post.
     * BOM UTF-8 + séparateur « ; » pour Excel FR.
     */
    public function export_history_csv(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ), '', 403 );
        }
        check_admin_referer( 'wcmcs_export_history' );

        $result = WCMCS_Order_Meta::get_sync_history_all( [
            'order_id' => isset( $_GET['wcmcs_h_order'] ) ? absint( $_GET['wcmcs_h_order'] ) : 0,
            'carrier'  => isset( $_GET['wcmcs_h_carrier'] ) ? sanitize_key( wp_unslash( $_GET['wcmcs_h_carrier'] ) ) : '',
            'status'   => isset( $_GET['wcmcs_h_status'] ) ? sanitize_key( wp_unslash( $_GET['wcmcs_h_status'] ) ) : '',
            'date_from' => isset( $_GET['wcmcs_h_from'] ) ? sanitize_text_field( wp_unslash( $_GET['wcmcs_h_from'] ) ) : '',
            'date_to'  => isset( $_GET['wcmcs_h_to'] ) ? sanitize_text_field( wp_unslash( $_GET['wcmcs_h_to'] ) ) : '',
            'per_page' => 0, // sans limite
        ] );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wcmcs-historique-' . gmdate( 'Y-m-d-Hi' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 (Excel)
        fputcsv( $out, [ 'Date', 'Commande', 'Transporteur', 'Statut', 'Tracking', 'Erreur' ], ';' );

        foreach ( $result['rows'] as $r ) {
            fputcsv( $out, [
                $r->synced_at,
                $r->order_id,
                $r->carrier_slug,
                WCMCS_Order_Meta::get_status_label( $r->status ),
                $r->tracking_id,
                $r->error_msg,
            ], ';' );
        }

        fclose( $out );
        exit;
    }

    // ─────────────────────────────────────────────
    //  Onglet Logs
    // ─────────────────────────────────────────────

    private function render_logs_tab(): void {
        $recent_logs = WCMCS_Logger::get_recent_logs( 200 );
        ?>
        <h2><?php esc_html_e( 'Logs récents', 'wc-multicarrier-sync' ); ?></h2>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=wc-multicarrier-sync' ) ); ?>"
               class="button" target="_blank">
                <?php esc_html_e( 'Ouvrir dans WC Logs', 'wc-multicarrier-sync' ); ?>
            </a>

            <?php if ( WCMCS_WC_Compat::has_action_scheduler() ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&group=wcmcs' ) ); ?>"
               class="button" target="_blank">
                <?php esc_html_e( 'Action Scheduler — Queue WCMCS', 'wc-multicarrier-sync' ); ?>
            </a>
            <?php endif; ?>
        </p>

        <div style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;white-space:pre-wrap">
            <?php echo esc_html( $recent_logs ?: __( 'Aucun log disponible.', 'wc-multicarrier-sync' ) ); ?>
        </div>

        <p style="margin-top:12px">
            <strong><?php esc_html_e( 'Statut du système :', 'wc-multicarrier-sync' ); ?></strong>
        </p>
        <table class="widefat" style="max-width:600px">
            <tr><td><?php esc_html_e( 'Version WooCommerce', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( WCMCS_WC_Compat::wc_version() ); ?></td></tr>
            <tr><td><?php esc_html_e( 'HPOS activé', 'wc-multicarrier-sync' ); ?></td><td><?php echo WCMCS_WC_Compat::is_hpos() ? '✅ Oui' : '❌ Non'; ?></td></tr>
            <tr><td><?php esc_html_e( 'Action Scheduler', 'wc-multicarrier-sync' ); ?></td><td><?php echo WCMCS_WC_Compat::has_action_scheduler() ? '✅ Disponible' : '❌ Requis (fourni avec WooCommerce)'; ?></td></tr>
            <tr><td><?php esc_html_e( 'Queue en attente', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( WCMCS_Queue_Manager::get_queue_size() ); ?> commande(s)</td></tr>
            <?php
            global $wpdb;
            $log_table   = $wpdb->prefix . 'wcmcs_sync_log';
            $log_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table}" ); // phpcs:ignore WordPress.DB
            ?>
            <tr><td><?php esc_html_e( 'Tentatives journalisées', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( number_format_i18n( $log_count ) ); ?></td></tr>
            <tr><td><?php esc_html_e( 'PHP', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
            <tr><td><?php esc_html_e( 'Plugin version', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( WCMCS_VERSION ); ?></td></tr>
        </table>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Helpers UI
    // ─────────────────────────────────────────────

    /**
     * Logo HTML d'un transporteur : image réelle (assets/images/carriers/)
     * si présente, emoji de secours sinon. HTML entièrement échappé.
     */
    private function get_carrier_icon_html( string $slug, string $fallback_icon = '📦', string $label = '' ): string {
        static $cache = [];
        $cache_key = $slug . '|' . $label;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $html = '<span class="wcmcs-card-icon">' . esc_html( $fallback_icon ) . '</span>';
        foreach ( [ 'png', 'svg', 'webp', 'jpg', 'jpeg' ] as $ext ) {
            $file = WCMCS_PLUGIN_DIR . 'assets/images/carriers/' . $slug . '.' . $ext;
            if ( file_exists( $file ) ) {
                $url  = WCMCS_PLUGIN_URL . 'assets/images/carriers/' . $slug . '.' . $ext;
                $alt  = $label ?: __( 'Logo transporteur', 'wc-multicarrier-sync' );
                $html = sprintf(
                    '<img src="%s" alt="%s" class="wcmcs-carrier-logo">',
                    esc_url( $url ),
                    esc_attr( $alt )
                );
                break;
            }
        }

        $cache[ $cache_key ] = $html;
        return $html;
    }

    private function render_tab( string $slug, string $label, string $active_tab, bool $warn = false ): void {
        $class = ( $slug === $active_tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url   = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
        printf(
            '<a href="%s" class="%s">%s%s</a>',
            esc_url( $url ),
            esc_attr( $class ),
            esc_html( $label ),
            $warn ? ' <span style="color:#dc3545">⚠</span>' : ''
        );
    }

    private function render_field( array $field ): void {
        $value = get_option( $field['id'], $field['default'] ?? '' );
        $id    = esc_attr( $field['id'] );
        $name  = esc_attr( $field['id'] );
        $type  = $field['type'] ?? 'text';

        switch ( $type ) {
            case 'password':
            case 'text':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
                    esc_attr( $type ),
                    $id, $name,
                    esc_attr( $value ),
                    esc_attr( $field['placeholder'] ?? '' )
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" min="%s" max="%s" step="%s" class="small-text" placeholder="%s">',
                    $id, $name,
                    esc_attr( $value ),
                    esc_attr( $field['min'] ?? '0' ),
                    esc_attr( $field['max'] ?? '9999' ),
                    esc_attr( $field['step'] ?? '1' ),
                    esc_attr( $field['placeholder'] ?? '' )
                );
                break;

            case 'checkbox':
                printf(
                    '<label><input type="checkbox" id="%s" name="%s" value="yes" %s> %s</label>',
                    $id, $name,
                    checked( $value, 'yes', false ),
                    esc_html( $field['description'] ?? '' )
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>',
                    $id, $name,
                    esc_textarea( $value )
                );
                break;

            case 'select':
                $options = $field['options'] ?? [];
                echo '<select id="' . $id . '" name="' . $name . '">';
                foreach ( $options as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        selected( $value, $opt_val, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'note_multiselect':
                $options = $field['options'] ?? [];
                $value   = get_option( $field['id'], $field['default'] ?? [] );
                if ( ! is_array( $value ) ) {
                    $value = [];
                }
                printf(
                    '<select id="%s" name="%s[]" multiple size="8" style="min-width:280px;min-height:180px;">',
                    $id, $name
                );
                foreach ( $options as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        in_array( (string) $opt_val, array_map( 'strval', $value ), true ) ? 'selected' : '',
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                break;

            case 'mapping_row':
                $source_value     = get_option( $field['id'], $field['default'] ?? '' );
                $fallback_id      = $field['fallback_id'] ?? $field['id'] . '_fallback';
                $fallback_value   = get_option( $fallback_id, $field['fallback_default'] ?? '' );
                $options          = $field['options'] ?? [];
                $source_id_attr   = esc_attr( $field['id'] );
                $source_name_attr = esc_attr( $field['id'] );
                $fb_id_attr       = esc_attr( $fallback_id );
                $fb_name_attr     = esc_attr( $fallback_id );

                echo '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
                echo '<label for="' . $source_id_attr . '" style="white-space:nowrap;">Source :</label>';
                echo '<select id="' . $source_id_attr . '" name="' . $source_name_attr . '">';
                foreach ( $options as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        selected( $source_value, $opt_val, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                echo '<label for="' . $fb_id_attr . '" style="white-space:nowrap;margin-left:8px;">Secours :</label>';
                echo '<select id="' . $fb_id_attr . '" name="' . $fb_name_attr . '">';
                foreach ( $options as $opt_val => $opt_label ) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr( $opt_val ),
                        selected( $fallback_value, $opt_val, false ),
                        esc_html( $opt_label )
                    );
                }
                echo '</select>';
                echo '</div>';
                break;

            case 'title':
                $title_text = $field['title'] ?? $field['label'] ?? '';
                if ( ! empty( $title_text ) ) {
                    printf( '<h3 style="margin-top:20px;padding-bottom:4px;border-bottom:1px solid #ccc">%s</h3>', esc_html( $title_text ) );
                }
                break;
        }
    }

    // ─────────────────────────────────────────────
    //  Sanitize
    // ─────────────────────────────────────────────

    public function sanitize_checkbox( $value ): string {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /**
     * Valide un slug de transporteur (vide = aucun).
     */
    public function sanitize_carrier_slug( $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            return '';
        }
        return array_key_exists( $value, WCMCS_Carrier_Detector::get_all_providers() ) ? $value : '';
    }

    /**
     * Retries auto : entier 0..5.
     */
    public function sanitize_max_retries( $value ): int {
        return min( 5, absint( $value ) );
    }

    /**
     * Libellé de statut personnalisé : texte court 1..40 caractères.
     * Vide → '' (le libellé traduit par défaut est utilisé).
     */
    public function sanitize_status_label( $value ): string {
        $label = trim( sanitize_text_field( (string) $value ) );
        if ( '' === $label ) {
            return '';
        }
        return mb_substr( $label, 0, 40, 'UTF-8' );
    }

    /**
     * Sanitize une couleur de badge (hex), vide si invalide.
     * Pour le picker natif : réglage de valeur hex ; vide → défaut.
     */
    public function sanitize_status_color( $value ): string {
        $color = sanitize_hex_color( trim( (string) $value ) );
        return $color ? $color : '';
    }

    /**
     * Sanitize les règles key→carrier.
     * Input : ['key' => [...], 'carrier' => [...]]
     * Output : ['key1' => 'carrier1', ...]
     *
     * @param array $input
     * @return array
     */
    public function sanitize_rules_array( $input ): array {
        if ( ! is_array( $input ) || ! isset( $input['key'], $input['carrier'] ) ) {
            return [];
        }

        $rules = [];
        $keys     = array_values( (array) $input['key'] );
        $carriers = array_values( (array) $input['carrier'] );

        foreach ( $keys as $i => $key ) {
            $clean_key     = sanitize_text_field( $key );
            $clean_carrier = sanitize_text_field( $carriers[ $i ] ?? '' );

            if ( ! empty( $clean_key ) && ! empty( $clean_carrier ) ) {
                $rules[ $clean_key ] = $clean_carrier;
            }
        }

        return $rules;
    }

    // ─────────────────────────────────────────────
    //  Notice
    // ─────────────────────────────────────────────

    public function settings_saved_notice(): void {
        if ( isset( $_GET['settings-updated'], $_GET['page'] )
            && sanitize_text_field( wp_unslash( $_GET['page'] ) ) === self::PAGE_SLUG ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Paramètres enregistrés.', 'wc-multicarrier-sync' )
                . '</p></div>';
        }
    }
}
