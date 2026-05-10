<?php
/**
 * Page de configuration du plugin.
 *
 * Onglets :
 *  - Général (transporteur défaut, mode debug)
 *  - FirstDelivery
 *  - Colissimo
 *  - Règles de détection
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
        add_action( 'admin_notices', [ $this, 'settings_saved_notice' ] );
    }

    // ─────────────────────────────────────────────
    //  Menu
    // ─────────────────────────────────────────────

    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'MultiCarrier Sync', 'wc-multicarrier-sync' ),
            __( '📦 MultiCarrier Sync', 'wc-multicarrier-sync' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    // ─────────────────────────────────────────────
    //  Register Settings API
    // ─────────────────────────────────────────────

    public function register_settings(): void {
        // Paramètres généraux
        $general_options = [
            'wcmcs_default_carrier',
            'wcmcs_debug_logs',
            'wcmcs_allow_resync',
            'wcmcs_batch_size',
            'wcmcs_max_auto_retries',
        ];
        foreach ( $general_options as $option ) {
            register_setting( self::OPTION_GROUP . '_general', $option, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }

        // Paramètres providers — injectés dynamiquement depuis chaque provider
        $providers = WCMCS_Carrier_Detector::get_all_providers();
        foreach ( $providers as $provider ) {
            foreach ( $provider->get_settings_fields() as $field ) {
                $sanitize = $field['type'] === 'checkbox' ? [ $this, 'sanitize_checkbox' ] : 'sanitize_text_field';
                if ( $field['type'] === 'textarea' || $field['type'] === 'password' ) {
                    $sanitize = 'sanitize_textarea_field';
                }
                register_setting( self::OPTION_GROUP . '_' . $provider->get_slug(), $field['id'], [
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
            <h1>
                📦 <?php esc_html_e( 'WC MultiCarrier Sync — Configuration', 'wc-multicarrier-sync' ); ?>
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
                <?php foreach ( $providers as $provider ) : ?>
                    <?php $this->render_tab( $provider->get_slug(), $provider->get_label(), $active_tab, ! $provider->is_configured() ); ?>
                <?php endforeach; ?>
                <?php $this->render_tab( 'rules', __( '🗺 Règles de détection', 'wc-multicarrier-sync' ), $active_tab ); ?>
                <?php $this->render_tab( 'logs', __( '📋 Logs', 'wc-multicarrier-sync' ), $active_tab ); ?>
            </nav>

            <div class="wcmcs-tab-content" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-top:none">
                <?php
                switch ( $active_tab ) {
                    case 'general':
                        $this->render_general_tab();
                        break;
                    case 'rules':
                        $this->render_rules_tab( $providers );
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        if ( isset( $providers[ $active_tab ] ) ) {
                            $this->render_provider_tab( $providers[ $active_tab ] );
                        }
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
        $providers = WCMCS_Carrier_Detector::get_all_providers();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP . '_general' ); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Transporteur par défaut', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <select name="wcmcs_default_carrier">
                            <option value=""><?php esc_html_e( '— Aucun (détection auto uniquement) —', 'wc-multicarrier-sync' ); ?></option>
                            <?php foreach ( $providers as $p ) : ?>
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
                    <th><?php esc_html_e( 'Taille du batch', 'wc-multicarrier-sync' ); ?></th>
                    <td>
                        <input type="number" name="wcmcs_batch_size" min="1" max="50" style="width:80px"
                               value="<?php echo esc_attr( get_option( 'wcmcs_batch_size', 10 ) ); ?>">
                        <p class="description">
                            <?php esc_html_e( 'Nombre de commandes traitées par cycle WP Cron (si Action Scheduler absent).', 'wc-multicarrier-sync' ); ?>
                        </p>
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
    //  Onglet Provider
    // ─────────────────────────────────────────────

    private function render_provider_tab( WCMCS_ShippingProviderInterface $provider ): void {
        $group = self::OPTION_GROUP . '_' . $provider->get_slug();
        ?>
        <?php if ( ! $provider->is_configured() ) : ?>
        <div class="notice notice-warning inline">
            <p><?php printf( esc_html__( '⚠ %s n\'est pas encore configuré. Renseignez les informations ci-dessous.', 'wc-multicarrier-sync' ), esc_html( $provider->get_label() ) ); ?></p>
        </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( $group ); ?>
            <table class="form-table">
                <?php foreach ( $provider->get_settings_fields() as $field ) : ?>
                <tr>
                    <th>
                        <label for="<?php echo esc_attr( $field['id'] ); ?>">
                            <?php echo esc_html( $field['label'] ); ?>
                        </label>
                    </th>
                    <td>
                        <?php $this->render_field( $field ); ?>
                        <?php if ( ! empty( $field['description'] ) ) : ?>
                        <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( __( 'Enregistrer', 'wc-multicarrier-sync' ) ); ?>
        </form>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Onglet Règles de détection
    // ─────────────────────────────────────────────

    private function render_rules_tab( array $providers ): void {
        $provider_options = '<option value="">' . esc_html__( '— Choisir —', 'wc-multicarrier-sync' ) . '</option>';
        foreach ( $providers as $p ) {
            $provider_options .= sprintf(
                '<option value="%s">%s</option>',
                esc_attr( $p->get_slug() ),
                esc_html( $p->get_label() )
            );
        }

        $rules_method   = get_option( 'wcmcs_rules_by_shipping_method', [] );
        $rules_state    = get_option( 'wcmcs_rules_by_state', [] );
        $rules_category = get_option( 'wcmcs_rules_by_category', [] );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( self::OPTION_GROUP . '_rules' ); ?>

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

        <script>
        jQuery(function($){
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
            <tr><td><?php esc_html_e( 'Action Scheduler', 'wc-multicarrier-sync' ); ?></td><td><?php echo WCMCS_WC_Compat::has_action_scheduler() ? '✅ Disponible' : '⚠ Fallback WP Cron'; ?></td></tr>
            <tr><td><?php esc_html_e( 'Queue en attente', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( WCMCS_Queue_Manager::get_queue_size() ); ?> commande(s)</td></tr>
            <tr><td><?php esc_html_e( 'PHP', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
            <tr><td><?php esc_html_e( 'Plugin version', 'wc-multicarrier-sync' ); ?></td><td><?php echo esc_html( WCMCS_VERSION ); ?></td></tr>
        </table>
        <?php
    }

    // ─────────────────────────────────────────────
    //  Helpers UI
    // ─────────────────────────────────────────────

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
        $value = get_option( $field['id'], '' );
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
        }
    }

    // ─────────────────────────────────────────────
    //  Sanitize
    // ─────────────────────────────────────────────

    public function sanitize_checkbox( $value ): string {
        return $value === 'yes' ? 'yes' : 'no';
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
        if ( isset( $_GET['settings-updated'] ) && $_GET['page'] === self::PAGE_SLUG ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Paramètres enregistrés.', 'wc-multicarrier-sync' )
                . '</p></div>';
        }
    }
}
