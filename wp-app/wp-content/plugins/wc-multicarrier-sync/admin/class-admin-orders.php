<?php
/**
 * Interface admin - Liste des commandes WooCommerce.
 *
 * Ajoute :
 *  - Bulk action "Synchroniser commandes"
 *  - Colonnes "Transporteur", "Statut sync", "Tracking"
 *  - Row actions : Synchroniser, Retry, Voir erreur
 *
 * Compatible HPOS (Custom Order Tables) et classique.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Admin_Orders {

    public function __construct() {
        $this->register_hooks();
    }

    private function register_hooks(): void {
        // ── Colonnes ──────────────────────────────────
        add_filter( WCMCS_WC_Compat::orders_list_columns_hook(),         [ $this, 'add_columns' ], 20 );
        add_action( WCMCS_WC_Compat::orders_list_column_content_hook(),  [ $this, 'render_column' ], 10, 2 );

        // ── Bulk Actions ──────────────────────────────
        add_filter( WCMCS_WC_Compat::bulk_actions_hook(),                [ $this, 'register_bulk_actions' ] );
        add_filter( WCMCS_WC_Compat::handle_bulk_actions_hook(),         [ $this, 'handle_bulk_actions' ], 10, 3 );

        // ── Notice après bulk ─────────────────────────
        add_action( 'admin_notices',                                      [ $this, 'bulk_action_notice' ] );

        // ── Row actions ───────────────────────────────
        add_filter( 'woocommerce_admin_order_actions',                   [ $this, 'add_row_actions' ], 10, 2 );
        add_action( 'admin_head',                                        [ $this, 'inject_row_action_css' ] );

        // ── AJAX handlers ─────────────────────────────
        add_action( 'wp_ajax_wcmcs_sync_single',   [ $this, 'ajax_sync_single' ] );
        add_action( 'wp_ajax_wcmcs_retry_order',   [ $this, 'ajax_retry_order' ] );
        add_action( 'wp_ajax_wcmcs_get_error',     [ $this, 'ajax_get_error' ] );

        // ── Scripts & Styles ──────────────────────────
        add_action( 'admin_enqueue_scripts',       [ $this, 'enqueue_admin_assets' ] );

        // ── Metabox sur la commande individuelle ──────
        add_action( 'add_meta_boxes',              [ $this, 'add_order_metabox' ] );

        // ── Forcer le transporteur ────────────────────
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_forced_carrier' ] );
    }

    // ─────────────────────────────────────────────
    //  Colonnes
    // ─────────────────────────────────────────────

    public function add_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            // Insérer après la colonne "statut"
            if ( $key === 'order_status' || $key === 'wc_actions' ) {
                $new['wcmcs_carrier']    = __( 'Transporteur', 'wc-multicarrier-sync' );
                $new['wcmcs_sync_status'] = __( 'Sync', 'wc-multicarrier-sync' );
                $new['wcmcs_tracking']   = __( 'Tracking', 'wc-multicarrier-sync' );
            }
        }
        // Si les colonnes n'ont pas encore été ajoutées (colonne ordre différent)
        if ( ! isset( $new['wcmcs_carrier'] ) ) {
            $new['wcmcs_carrier']    = __( 'Transporteur', 'wc-multicarrier-sync' );
            $new['wcmcs_sync_status'] = __( 'Sync', 'wc-multicarrier-sync' );
            $new['wcmcs_tracking']   = __( 'Tracking', 'wc-multicarrier-sync' );
        }
        return $new;
    }

    public function render_column( string $column, $post_or_order ): void {
        // Compatible HPOS : le 2e argument peut être WC_Order ou WP_Post
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } else {
            $order = wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );
        }

        if ( ! $order ) {
            return;
        }

        switch ( $column ) {
            case 'wcmcs_carrier':
                $slug = WCMCS_Order_Meta::get_carrier_slug( $order );
                if ( $slug ) {
                    $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $slug );
                    echo '<span class="wcmcs-carrier-label">'
                        . esc_html( $provider ? $provider->get_label() : strtoupper( $slug ) )
                        . '</span>';
                } else {
                    echo '<span class="wcmcs-na">—</span>';
                }
                break;

            case 'wcmcs_sync_status':
                $status = WCMCS_Order_Meta::get_status( $order );
                echo wp_kses_post( WCMCS_Order_Meta::get_status_badge( $status ) );
                // Bouton retry si failed
                if ( $status === WCMCS_Order_Meta::STATUS_FAILED ) {
                    $retry_url = wp_nonce_url(
                        add_query_arg( [
                            'wcmcs_action'   => 'retry',
                            'wcmcs_order_id' => $order->get_id(),
                        ] ),
                        'wcmcs_retry_' . $order->get_id()
                    );
                    printf(
                        '<br><a href="%s" class="wcmcs-retry-link" title="%s">↺ %s</a>',
                        esc_url( $retry_url ),
                        esc_attr__( 'Réessayer la synchronisation', 'wc-multicarrier-sync' ),
                        esc_html__( 'Retry', 'wc-multicarrier-sync' )
                    );
                }
                break;

            case 'wcmcs_tracking':
                $tracking = WCMCS_Order_Meta::get_tracking_id( $order );
                if ( $tracking ) {
                    $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
                    $tracking_url = $this->get_tracking_url( $tracking, $carrier_slug );
                    if ( $tracking_url ) {
                        printf(
                            '<a href="%s" target="_blank" class="wcmcs-tracking-link">%s</a>',
                            esc_url( $tracking_url ),
                            esc_html( $tracking )
                        );
                    } else {
                        echo esc_html( $tracking );
                    }

                    // Lien étiquette PDF
                    $label_url = WCMCS_Order_Meta::get_label_url( $order );
                    if ( $label_url ) {
                        printf(
                            ' <a href="%s" target="_blank" class="wcmcs-label-link" title="%s">📄</a>',
                            esc_url( $label_url ),
                            esc_attr__( 'Télécharger étiquette', 'wc-multicarrier-sync' )
                        );
                    }
                } else {
                    echo '<span class="wcmcs-na">—</span>';
                }
                break;
        }
    }

    // ─────────────────────────────────────────────
    //  Bulk Actions
    // ─────────────────────────────────────────────

    public function register_bulk_actions( array $actions ): array {
        $actions['wcmcs_sync'] = __( '📦 Synchroniser commandes', 'wc-multicarrier-sync' );
        return $actions;
    }

    /**
     * Traite la bulk action.
     *
     * @param string   $redirect_to URL de redirection.
     * @param string   $action      Nom de l'action.
     * @param int[]    $post_ids    IDs sélectionnés.
     * @return string URL modifiée.
     */
    public function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
        if ( $action !== 'wcmcs_sync' ) {
            return $redirect_to;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) );
        }

        $order_ids = array_map( 'absint', $post_ids );
        $order_ids = array_filter( $order_ids );

        if ( empty( $order_ids ) ) {
            return $redirect_to;
        }

        WCMCS_Logger::info( sprintf(
            'Bulk sync déclenchée par l\'admin pour %d commandes : %s',
            count( $order_ids ),
            implode( ', ', $order_ids )
        ) );

        // Utiliser async si Action Scheduler disponible
        $use_async = WCMCS_WC_Compat::has_action_scheduler();
        $report    = WCMCS_Sync_Manager::sync_bulk( $order_ids, $use_async );

        // Stocker le rapport en transient pour affichage notice
        $transient_key = 'wcmcs_bulk_report_' . get_current_user_id();
        set_transient( $transient_key, $report, 60 );

        return add_query_arg(
            [ 'wcmcs_bulk_done' => 1 ],
            $redirect_to
        );
    }

    // ─────────────────────────────────────────────
    //  Admin Notice
    // ─────────────────────────────────────────────

    public function bulk_action_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $valid_screens = [ 'edit-shop_order', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $screen->id, $valid_screens, true ) ) {
            return;
        }

        // Retry individuel
        if ( isset( $_GET['wcmcs_action'], $_GET['wcmcs_order_id'] ) && $_GET['wcmcs_action'] === 'retry' ) {
            $order_id = absint( $_GET['wcmcs_order_id'] );
            check_admin_referer( 'wcmcs_retry_' . $order_id );

            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }

            $result = WCMCS_Sync_Manager::retry( $order_id );
            $class  = $result['status'] === 'success' ? 'notice-success' : 'notice-error';
            $msg    = $result['status'] === 'success'
                ? sprintf( __( 'Commande #%d synchronisée : %s', 'wc-multicarrier-sync' ), $order_id, $result['tracking_id'] )
                : sprintf( __( 'Échec retry #%d : %s', 'wc-multicarrier-sync' ), $order_id, $result['message'] );
            printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $msg ) );
            return;
        }

        // Rapport bulk
        if ( ! isset( $_GET['wcmcs_bulk_done'] ) ) {
            return;
        }

        $transient_key = 'wcmcs_bulk_report_' . get_current_user_id();
        $report        = get_transient( $transient_key );
        delete_transient( $transient_key );

        if ( ! $report ) {
            return;
        }

        $class = $report['failed'] > 0 ? 'notice-warning' : 'notice-success';
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr( $class ),
            wp_kses_post( WCMCS_Sync_Manager::format_report_message( $report ) )
        );
    }

    // ─────────────────────────────────────────────
    //  AJAX handlers
    // ─────────────────────────────────────────────

    public function ajax_sync_single(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID commande invalide.', 'wc-multicarrier-sync' ) ] );
        }

        $result = WCMCS_Sync_Manager::sync_single( $order_id );
        if ( $result['status'] === 'success' ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_retry_order(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID commande invalide.', 'wc-multicarrier-sync' ) ] );
        }

        $result = WCMCS_Sync_Manager::retry( $order_id );
        if ( $result['status'] === 'success' ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_get_error(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = WCMCS_WC_Compat::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Commande introuvable.', 'wc-multicarrier-sync' ) ] );
        }

        wp_send_json_success( [
            'error_message' => WCMCS_Order_Meta::get_error_message( $order ),
            'api_response'  => WCMCS_Order_Meta::get_api_response( $order ),
            'sync_date'     => WCMCS_Order_Meta::get_sync_date( $order ),
            'retry_count'   => WCMCS_Order_Meta::get_retry_count( $order ),
        ] );
    }

    // ─────────────────────────────────────────────
    //  Metabox commande individuelle
    // ─────────────────────────────────────────────

    public function add_order_metabox(): void {
        $screen = WCMCS_WC_Compat::is_hpos() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        add_meta_box(
            'wcmcs_sync_box',
            __( '📦 Synchronisation transporteur', 'wc-multicarrier-sync' ),
            [ $this, 'render_order_metabox' ],
            $screen,
            'side',
            'high'
        );
    }

    public function render_order_metabox( $post_or_order ): void {
        $order = $post_or_order instanceof WC_Order
            ? $post_or_order
            : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            return;
        }

        $status      = WCMCS_Order_Meta::get_status( $order );
        $tracking    = WCMCS_Order_Meta::get_tracking_id( $order );
        $carrier     = WCMCS_Order_Meta::get_carrier_slug( $order );
        $error       = WCMCS_Order_Meta::get_error_message( $order );
        $sync_date   = WCMCS_Order_Meta::get_sync_date( $order );
        $retry_count = WCMCS_Order_Meta::get_retry_count( $order );
        $label_url   = WCMCS_Order_Meta::get_label_url( $order );

        $providers = WCMCS_Carrier_Detector::get_all_providers();
        $forced    = WCMCS_WC_Compat::get_order_meta( $order, '_wcmcs_forced_carrier' );

        wp_nonce_field( 'wcmcs_metabox_' . $order->get_id(), 'wcmcs_metabox_nonce' );
        ?>
        <div class="wcmcs-metabox">
            <p>
                <strong><?php esc_html_e( 'Statut :', 'wc-multicarrier-sync' ); ?></strong>
                <?php echo wp_kses_post( WCMCS_Order_Meta::get_status_badge( $status ) ); ?>
            </p>

            <?php if ( $carrier ) : ?>
            <p>
                <strong><?php esc_html_e( 'Transporteur :', 'wc-multicarrier-sync' ); ?></strong>
                <?php
                $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $carrier );
                echo esc_html( $provider ? $provider->get_label() : strtoupper( $carrier ) );
                ?>
            </p>
            <?php endif; ?>

            <?php if ( $tracking ) : ?>
            <p>
                <strong><?php esc_html_e( 'Tracking :', 'wc-multicarrier-sync' ); ?></strong>
                <code><?php echo esc_html( $tracking ); ?></code>
                <?php if ( $label_url ) : ?>
                <a href="<?php echo esc_url( $label_url ); ?>" target="_blank">
                    📄 <?php esc_html_e( 'Étiquette', 'wc-multicarrier-sync' ); ?>
                </a>
                <?php endif; ?>
            </p>
            <?php endif; ?>

            <?php if ( $sync_date ) : ?>
            <p>
                <strong><?php esc_html_e( 'Date sync :', 'wc-multicarrier-sync' ); ?></strong>
                <?php echo esc_html( $sync_date ); ?>
            </p>
            <?php endif; ?>

            <?php if ( $error ) : ?>
            <div class="wcmcs-error-box" style="background:#fff3f3;border:1px solid #dc3545;padding:8px;border-radius:4px;margin:8px 0;">
                <strong style="color:#dc3545">⚠ <?php esc_html_e( 'Erreur :', 'wc-multicarrier-sync' ); ?></strong>
                <p style="margin:4px 0"><?php echo esc_html( $error ); ?></p>
                <?php if ( $retry_count > 0 ) : ?>
                <small><?php printf( esc_html__( '%d tentative(s)', 'wc-multicarrier-sync' ), $retry_count ); ?></small>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Forcer le transporteur -->
            <p>
                <label for="wcmcs_forced_carrier">
                    <strong><?php esc_html_e( 'Forcer le transporteur :', 'wc-multicarrier-sync' ); ?></strong>
                </label>
                <select id="wcmcs_forced_carrier" name="wcmcs_forced_carrier" style="width:100%;margin-top:4px">
                    <option value=""><?php esc_html_e( '— Détection automatique —', 'wc-multicarrier-sync' ); ?></option>
                    <?php foreach ( $providers as $p ) : ?>
                    <option value="<?php echo esc_attr( $p->get_slug() ); ?>" <?php selected( $forced, $p->get_slug() ); ?>>
                        <?php echo esc_html( $p->get_label() ); ?>
                        <?php if ( ! $p->is_configured() ) echo esc_html__( ' (non configuré)', 'wc-multicarrier-sync' ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <!-- Boutons d'action -->
            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap">
                <button type="button"
                        class="button button-primary wcmcs-btn-sync"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>">
                    ⇄ <?php esc_html_e( 'Synchroniser', 'wc-multicarrier-sync' ); ?>
                </button>

                <?php if ( $status === WCMCS_Order_Meta::STATUS_FAILED ) : ?>
                <button type="button"
                        class="button wcmcs-btn-retry"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>">
                    ↺ <?php esc_html_e( 'Retry', 'wc-multicarrier-sync' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="wcmcs-ajax-result" style="margin-top:8px"></div>
        </div>

        <style>
        .wcmcs-metabox p { margin: 6px 0; font-size: 13px; }
        .wcmcs-btn-sync, .wcmcs-btn-retry { font-size: 12px !important; }
        </style>
        <?php
    }

    public function save_forced_carrier( int $order_id ): void {
        if ( ! isset( $_POST['wcmcs_metabox_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_metabox_nonce'] ) ), 'wcmcs_metabox_' . $order_id ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $forced = sanitize_text_field( $_POST['wcmcs_forced_carrier'] ?? '' );
        WCMCS_WC_Compat::update_order_meta( $order, '_wcmcs_forced_carrier', $forced );
    }

    // ─────────────────────────────────────────────
    //  Enqueue scripts & styles
    // ─────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $valid_hooks = [ 'edit.php', 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $hook, $valid_hooks, true ) ) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'wcmcs-admin',
            WCMCS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WCMCS_VERSION
        );

        // JS
        wp_enqueue_script(
            'wcmcs-admin',
            WCMCS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WCMCS_VERSION,
            true
        );

        wp_localize_script( 'wcmcs-admin', 'wcmcsAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wcmcs_ajax' ),
            'i18n'    => [
                'syncing'     => __( 'Synchronisation...', 'wc-multicarrier-sync' ),
                'retrying'    => __( 'Retry en cours...', 'wc-multicarrier-sync' ),
                'success'     => __( 'Synchronisé !', 'wc-multicarrier-sync' ),
                'error'       => __( 'Erreur :', 'wc-multicarrier-sync' ),
                'confirm_sync' => __( 'Synchroniser cette commande ?', 'wc-multicarrier-sync' ),
            ],
        ] );
    }

    public function inject_row_action_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }
        echo '<style>
        .wcmcs-na { color: #999; }
        .wcmcs-retry-link { color: #f0ad4e; font-size: 11px; text-decoration: none; }
        .wcmcs-tracking-link { font-family: monospace; font-size: 12px; }
        .column-wcmcs_carrier, .column-wcmcs_sync_status, .column-wcmcs_tracking { width: 100px; }
        </style>' . "\n";
    }

    public function add_row_actions( array $actions, WC_Order $order ): array {
        // Bouton sync individuel
        $actions['wcmcs_sync'] = [
            'url'    => '#',
            'name'   => __( '⇄ Sync', 'wc-multicarrier-sync' ),
            'action' => 'wcmcs-sync',
            'title'  => __( 'Synchroniser avec le transporteur', 'wc-multicarrier-sync' ),
        ];

        return $actions;
    }

    // ─────────────────────────────────────────────
    //  Tracking URLs
    // ─────────────────────────────────────────────

    private function get_tracking_url( string $tracking_id, string $carrier_slug ): string {
        $urls = apply_filters( 'wcmcs_tracking_urls', [
            'colissimo'    => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking_id}',
            'firstdelivery' => 'https://tracking.firstdelivery.tn/?tracking={tracking_id}',
        ] );

        if ( ! isset( $urls[ $carrier_slug ] ) ) {
            return '';
        }

        return str_replace( '{tracking_id}', rawurlencode( $tracking_id ), $urls[ $carrier_slug ] );
    }
}
