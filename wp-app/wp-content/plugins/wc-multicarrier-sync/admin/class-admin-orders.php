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
        add_action( 'wp_ajax_wcmcs_sync_single',            [ $this, 'ajax_sync_single' ] );
        add_action( 'wp_ajax_wcmcs_retry_order',            [ $this, 'ajax_retry_order' ] );
        add_action( 'wp_ajax_wcmcs_get_error',               [ $this, 'ajax_get_error' ] );
        add_action( 'wp_ajax_wcmcs_get_label_pdf',           [ $this, 'ajax_get_label_pdf' ] );
        add_action( 'wp_ajax_wcmcs_download_label',          [ $this, 'ajax_download_label' ] );
        add_action( 'wp_ajax_wcmcs_bulk_sync_modal_data',    [ $this, 'ajax_bulk_sync_modal_data' ] );
        add_action( 'wp_ajax_wcmcs_bulk_sync_process',      [ $this, 'ajax_bulk_sync_process' ] );
        add_action( 'wp_ajax_wcmcs_check_status',           [ $this, 'ajax_check_status' ] );
        add_action( 'wp_ajax_wcmcs_cancel_shipment',        [ $this, 'ajax_cancel_shipment' ] );
        add_action( 'wp_ajax_wcmcs_status_scan_data',       [ $this, 'ajax_status_scan_data' ] );

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

        echo $this->get_column_html( $column, $order );
    }

    /**
     * Génère le HTML pour une colonne custom de la liste des commandes.
     * Utilisé par render_column() et par les réponses AJAX.
     *
     * @param string   $column  Nom de la colonne.
     * @param WC_Order  $order   La commande.
     * @return string   HTML échappé.
     */
    public function get_column_html( string $column, WC_Order $order ): string {
        switch ( $column ) {
            case 'wcmcs_carrier':
                $slug = WCMCS_Order_Meta::get_carrier_slug( $order );
                if ( $slug ) {
                    $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $slug );
                    $label    = $provider ? $provider->get_label() : strtoupper( $slug );
                    $logo     = WCMCS_Carrier_Detector::get_logo_url( $slug );
                    if ( $logo ) {
                        return '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '" class="wcmcs-carrier-logo">';
                    }
                    return '<span class="wcmcs-carrier-label">' . esc_html( $label ) . '</span>';
                }
                return '<span class="wcmcs-na">—</span>';

            case 'wcmcs_sync_status':
                $status = WCMCS_Order_Meta::get_status( $order );
                $html = wp_kses_post( WCMCS_Order_Meta::get_status_badge( $status ) );
                if ( $status === WCMCS_Order_Meta::STATUS_FAILED ) {
                    $retry_url = wp_nonce_url(
                        add_query_arg( [
                            'wcmcs_action'   => 'retry',
                            'wcmcs_order_id' => $order->get_id(),
                        ] ),
                        'wcmcs_retry_' . $order->get_id()
                    );
                    $html .= sprintf(
                        '<br><a href="%s" class="wcmcs-retry-link" title="%s">↺ %s</a>',
                        esc_url( $retry_url ),
                        esc_attr__( 'Réessayer la synchronisation', 'wc-multicarrier-sync' ),
                        esc_html__( 'Retry', 'wc-multicarrier-sync' )
                    );
                    $html .= sprintf(
                        ' <a href="#" class="wcmcs-view-error" data-order-id="%d" title="%s">⚠ %s</a>',
                        esc_attr( $order->get_id() ),
                        esc_attr__( 'Voir le détail de l\'erreur', 'wc-multicarrier-sync' ),
                        esc_html__( 'Voir erreur', 'wc-multicarrier-sync' )
                    );
                }
                return $html;

            case 'wcmcs_tracking':
                $tracking = WCMCS_Order_Meta::get_tracking_id( $order );
                if ( $tracking ) {
                    $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
                    $tracking_url = $this->get_tracking_url( $tracking, $carrier_slug );
                    $html = '';
                    if ( $tracking_url ) {
                        $html .= sprintf(
                            '<a href="%s" target="_blank" class="wcmcs-tracking-link">%s</a>',
                            esc_url( $tracking_url ),
                            esc_html( $tracking )
                        );
                    } else {
                        $html .= esc_html( $tracking );
                    }

                    $label_url = WCMCS_Order_Meta::get_label_url( $order );
                    if ( $label_url ) {
                        $html .= sprintf(
                            ' <a href="%s" target="_blank" class="wcmcs-label-link" title="%s">📄</a>',
                            esc_url( $this->get_label_display_url( $order, $label_url ) ),
                            esc_attr__( 'Télécharger étiquette', 'wc-multicarrier-sync' )
                        );
                    }
                    return $html;
                }
                return '<span class="wcmcs-na">—</span>';
        }

        return '';
    }

    // ─────────────────────────────────────────────
    //  Étiquettes PDF (accès sécurisé)
    // ─────────────────────────────────────────────

    /**
     * URL du endpoint authentifié de téléchargement d'étiquette (nonce frais).
     */
    private function get_label_download_url( int $order_id ): string {
        return add_query_arg( [
            'action'   => 'wcmcs_download_label',
            'order_id' => $order_id,
            'nonce'    => wp_create_nonce( 'wcmcs_ajax' ),
        ], admin_url( 'admin-ajax.php' ) );
    }

    /**
     * URL d'affichage d'une étiquette : les fichiers locaux (wcmcs-labels/)
     * passent par le endpoint authentifié, les URLs externes (ex. FirstDelivery)
     * restent directes.
     */
    private function get_label_display_url( WC_Order $order, string $label_url ): string {
        $upload_base = wp_upload_dir()['baseurl'];
        if ( 0 === strpos( $label_url, $upload_base . '/wcmcs-labels/' ) ) {
            return $this->get_label_download_url( $order->get_id() );
        }
        return $label_url;
    }

    /**
     * Retrouve le fichier PDF local le plus récent d'une commande.
     *
     * @return string|null Chemin absolu, ou null.
     */
    private function get_local_label_file( int $order_id ): ?string {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'wcmcs-labels/' . $order_id . '/';
        $files      = glob( $dir . '*.pdf' );

        if ( empty( $files ) ) {
            return null;
        }

        $latest = null;
        $mtime  = 0;
        foreach ( $files as $file ) {
            $m = filemtime( $file );
            if ( $m > $mtime ) {
                $mtime  = $m;
                $latest = $file;
            }
        }
        return $latest;
    }

    // ─────────────────────────────────────────────
    //  Bulk Actions
    // ─────────────────────────────────────────────

    public function register_bulk_actions( array $actions ): array {
        $actions['wcmcs_sync'] = __( '📦 Synchroniser commandes', 'wc-multicarrier-sync' );

        // Statuts pipeline : le core WC traite tout « mark_* » automatiquement (ListTable → update_status).
        $actions['mark_wcmcs-confirmed'] = __( '☎ Marquer confirmée', 'wc-multicarrier-sync' );
        $actions['mark_wcmcs-packed']    = __( '📦 Marquer emballée', 'wc-multicarrier-sync' );

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

        // Toujours traiter synchrone — le modal AJAX utilise aussi sync_single() directement
        $report = WCMCS_Sync_Manager::sync_bulk( $order_ids, false );

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

        // Transporteur forcé depuis le dropdown metabox (persisté par
        // sync_single AVANT la détection — honore le choix sans « Mettre à
        // jour » la commande, sinon la détection auto peut envoyer chez le
        // transporteur par défaut).
        $force_carrier = $this->sanitize_forced_carrier( wp_unslash( $_POST['force_carrier'] ?? '' ) );

        $result = WCMCS_Sync_Manager::sync_single( $order_id, $force_carrier );

        // Ajouter le HTML des colonnes pour mise à jour dynamique
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            $result['columns'] = [
                'carrier'      => $this->get_column_html( 'wcmcs_carrier', $order ),
                'sync_status'  => $this->get_column_html( 'wcmcs_sync_status', $order ),
                'tracking'     => $this->get_column_html( 'wcmcs_tracking', $order ),
            ];
        }
        $result['message'] = wp_kses_post( $result['message'] ?? '' );

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

        // Transporteur forcé depuis le dropdown metabox (cf. ajax_sync_single).
        $force_carrier = $this->sanitize_forced_carrier( wp_unslash( $_POST['force_carrier'] ?? '' ) );

        $result = WCMCS_Sync_Manager::retry( $order_id, $force_carrier );

        // Ajouter le HTML des colonnes pour mise à jour dynamique
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            $result['columns'] = [
                'carrier'      => $this->get_column_html( 'wcmcs_carrier', $order ),
                'sync_status'  => $this->get_column_html( 'wcmcs_sync_status', $order ),
                'tracking'     => $this->get_column_html( 'wcmcs_tracking', $order ),
            ];
        }
        $result['message'] = wp_kses_post( $result['message'] ?? '' );

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

    /**
     * Vérification manuelle du statut colis (bouton metabox + row action).
     */
    public function ajax_check_status(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID commande invalide.', 'wc-multicarrier-sync' ) ] );
        }

        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Commande introuvable.', 'wc-multicarrier-sync' ) ] );
        }

        $tracking_id = WCMCS_Order_Meta::get_tracking_id( $order );
        if ( '' === $tracking_id ) {
            wp_send_json_error( [ 'message' => __( 'Aucun n° de suivi sur cette commande.', 'wc-multicarrier-sync' ) ] );
        }

        $carrier  = WCMCS_Order_Meta::get_carrier_slug( $order );
        $provider = $carrier ? WCMCS_Carrier_Detector::get_provider_by_slug( $carrier ) : null;
        if ( ! $provider ) {
            wp_send_json_error( [ 'message' => __( 'Transporteur non disponible pour cette commande.', 'wc-multicarrier-sync' ) ] );
        }
        if ( ! $provider->is_configured() ) {
            wp_send_json_error( [ 'message' => __( 'Transporteur non configuré (identifiants manquants).', 'wc-multicarrier-sync' ) ] );
        }

        $tracking = $provider->get_tracking_status( $tracking_id );
        if ( is_wp_error( $tracking ) ) {
            wp_send_json_error( [ 'message' => $tracking->get_error_message() ] );
        }

        $changed = ( new WCMCS_Order_Status_Sync() )->process_tracking_result( $order, $provider, $tracking );

        $fresh = WCMCS_WC_Compat::get_order( $order_id );
        wp_send_json_success( [
            'tracking_id'   => $tracking_id,
            'state_code'    => (string) ( $tracking['state_code'] ?? '' ),
            'state_label'   => (string) ( $tracking['state_label'] ?? '' ) !== '' ? (string) $tracking['state_label'] : (string) ( $tracking['state_code'] ?? '' ),
            'changed'       => (bool) $changed,
            'order_status'  => $fresh ? $fresh->get_status() : $order->get_status(),
            'order_status_label'  => $fresh ? wc_get_order_status_name( $fresh->get_status() ) : '',
            'columns'       => $fresh ? [
                'carrier'      => $this->get_column_html( 'wcmcs_carrier', $fresh ),
                'sync_status'  => $this->get_column_html( 'wcmcs_sync_status', $fresh ),
                'tracking'     => $this->get_column_html( 'wcmcs_tracking', $fresh ),
            ] : null,
        ] );
    }

    /**
     * Annulation du colis chez le transporteur (bouton metabox + row action).
     * Uniquement pour les transporteurs supportant l'annulation (matrice
     * CARRIER_FEATURES) — voir WCMCS_Sync_Manager::cancel_single().
     */
    public function ajax_cancel_shipment(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID commande invalide.', 'wc-multicarrier-sync' ) ] );
        }

        $result = WCMCS_Sync_Manager::cancel_single( $order_id );

        // Ajouter le HTML des colonnes pour mise à jour dynamique
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            $result['columns'] = [
                'carrier'      => $this->get_column_html( 'wcmcs_carrier', $order ),
                'sync_status'  => $this->get_column_html( 'wcmcs_sync_status', $order ),
                'tracking'     => $this->get_column_html( 'wcmcs_tracking', $order ),
            ];
        }
        $result['message'] = wp_kses_post( $result['message'] ?? '' );

        if ( $result['status'] === 'success' ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Énumère les colis synchronisés vérifiables (bouton « Vérifier tous les
     * statuts » de la page commandes). Balance TOUT (y compris livrés /
     * retournés) tagué par wc_status_key + state_category : le filtrage
     * multi-statuts est fait côté client, instantanément, sans re-fetch.
     */
    public function ajax_status_scan_data(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $limit = absint( $_POST['limit'] ?? 200 );
        if ( $limit < 1 || $limit > 500 ) {
            $limit = 200;
        }

        $sync = new WCMCS_Order_Status_Sync();

        $statuses = [
            'wc-wcmcs-synced',
            'processing',
            'on-hold',
            'pending',
            WCMCS_Order_Statuses::STATUS_CONFIRMED,
            WCMCS_Order_Statuses::STATUS_PACKED,
            'completed',
            'wc-wcmcs-delivered',
            'wc-wcmcs-returned',
        ];

        $orders = $sync->get_scan_candidates( $limit, $statuses );

        $rows = [];
        foreach ( $orders as $order ) {
            $carrier  = WCMCS_Order_Meta::get_carrier_slug( $order );
            $provider = $carrier ? WCMCS_Carrier_Detector::get_provider_by_slug( $carrier ) : null;
            if ( ! $provider ) {
                continue;
            }
            $tracking = WCMCS_Order_Meta::get_tracking_id( $order );
            if ( '' === $tracking ) {
                continue;
            }
            $last_state = (string) WCMCS_WC_Compat::get_order_meta( $order, WCMCS_Order_Status_Sync::META_LAST_STATE );
            $edit_url = WCMCS_WC_Compat::is_hpos()
                ? admin_url( 'admin.php?page=wc-orders&id=' . $order->get_id() . '&action=edit' )
                : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
            $rows[] = [
                'id'             => $order->get_id(),
                'number'         => $order->get_order_number(),
                'url'            => esc_url( $edit_url ),
                'carrier'        => $provider->get_label(),
                'logo'           => WCMCS_Carrier_Detector::get_logo_url( $carrier ),
                'tracking'       => $tracking,
                'wc_status'      => wc_get_order_status_name( $order->get_status() ),
                'wc_status_key'  => $order->get_status(),
                'last_state'     => $last_state,
                'unknown'        => '' === $last_state,
                'configured'     => (bool) $provider->is_configured(),
                'state_category' => $sync->classify_state( $carrier, $last_state ),
            ];
        }

        wp_send_json_success( [
            'orders' => $rows,
            'total'  => count( $rows ),
        ] );
    }

    public function ajax_get_label_pdf(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = WCMCS_WC_Compat::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Commande introuvable.', 'wc-multicarrier-sync' ) ] );
        }

        $tracking_id  = WCMCS_Order_Meta::get_tracking_id( $order );
        $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );

        if ( empty( $tracking_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Aucun numéro de tracking.', 'wc-multicarrier-sync' ) ] );
        }

        $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $carrier_slug );
        if ( ! $provider || ! method_exists( $provider, 'get_label_pdf' ) ) {
            wp_send_json_error( [ 'message' => __( "Ce transporteur ne supporte pas le téléchargement d'étiquette.", 'wc-multicarrier-sync' ) ] );
        }

        $label_url = $provider->get_label_pdf( $tracking_id, $order_id );
        if ( is_wp_error( $label_url ) ) {
            wp_send_json_error( [ 'message' => wp_kses_post( $label_url->get_error_message() ) ] );
        }

        WCMCS_WC_Compat::update_order_meta( $order, WCMCS_Order_Meta::META_LABEL_URL, esc_url_raw( $label_url ) );

        // Ne jamais exposer l'URL publique du fichier local : passer par le endpoint authentifié.
        wp_send_json_success( [ 'label_url' => $this->get_label_display_url( $order, $label_url ) ] );
    }

    /**
     * AJAX/GET : télécharge une étiquette PDF via le endpoint authentifié
     * (remplace l'accès direct public au dossier uploads/wcmcs-labels/).
     */
    public function ajax_download_label(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ), '', 403 );
        }

        $order_id = absint( $_REQUEST['order_id'] ?? 0 );
        $order    = WCMCS_WC_Compat::get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Commande introuvable.', 'wc-multicarrier-sync' ), '', 404 );
        }

        $file = $this->get_local_label_file( $order_id );
        if ( ! $file || ! is_readable( $file ) ) {
            wp_die( esc_html__( 'Étiquette introuvable pour cette commande.', 'wc-multicarrier-sync' ), '', 404 );
        }

        WCMCS_Logger::info( sprintf( 'Téléchargement étiquette commande #%d (endpoint sécurisé)', $order_id ) );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="wcmcs-label-' . $order_id . '.pdf"' );
        header( 'Content-Length: ' . (string) filesize( $file ) );
        readfile( $file );
        exit;
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

        $providers = WCMCS_Carrier_Detector::get_enabled_providers();
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
                <a href="<?php echo esc_url( $this->get_label_display_url( $order, $label_url ) ); ?>" target="_blank">
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

            <?php
            $history = WCMCS_Order_Meta::get_sync_history( $order->get_id() );
            if ( $history ) : ?>
            <div class="wcmcs-history-box" style="margin:8px 0">
                <strong>📜 <?php printf( esc_html__( 'Historique (%d)', 'wc-multicarrier-sync' ), count( $history ) ); ?></strong>
                <ul style="margin:4px 0 0;padding-left:2px;list-style:none;font-size:12px">
                    <?php foreach ( $history as $h ) :
                        $h_provider = WCMCS_Carrier_Detector::get_provider_by_slug( $h->carrier_slug );
                        $h_label    = $h_provider ? $h_provider->get_label() : ( $h->carrier_slug ?: '—' );
                        $h_tail     = $h->tracking_id ? $h->tracking_id : ( $h->error_msg ? wp_html_excerpt( $h->error_msg, 60, '…' ) : '' );
                    ?>
                    <li style="margin:2px 0">
                        <?php echo esc_html( $h->synced_at ); ?> · <?php echo esc_html( $h_label ); ?> ·
                        <?php echo wp_kses_post( WCMCS_Order_Meta::get_status_badge( $h->status ) ); ?>
                        <?php if ( $h_tail ) : ?>
                        <span title="<?php echo esc_attr( $h->tracking_id ? $h->tracking_id : $h->error_msg ); ?>"><?php echo esc_html( $h_tail ); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
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

                <?php
                $status_provider = $tracking && $carrier ? WCMCS_Carrier_Detector::get_provider_by_slug( $carrier ) : null;
                if ( $tracking && $status_provider ) : ?>
                <button type="button"
                        class="button wcmcs-btn-status"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>">
                    📍 <?php esc_html_e( 'Vérifier le statut', 'wc-multicarrier-sync' ); ?>
                </button>
                <?php endif; ?>

                <?php if ( $this->is_cancel_available( $order ) ) : ?>
                <button type="button"
                        class="button wcmcs-btn-cancel"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-tracking="<?php echo esc_attr( $tracking ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>"
                        title="<?php echo esc_attr__( 'Annule le colis chez le transporteur — uniquement s\'il est encore en attente (avant enlèvement). La commande redevient synchronisable.', 'wc-multicarrier-sync' ); ?>">
                    ✕ <?php esc_html_e( 'Annuler le colis', 'wc-multicarrier-sync' ); ?>
                </button>
                <?php endif; ?>

                <?php if ( $status === WCMCS_Order_Meta::STATUS_FAILED ) : ?>
                <button type="button"
                        class="button wcmcs-btn-retry"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>">
                    ↺ <?php esc_html_e( 'Retry', 'wc-multicarrier-sync' ); ?>
                </button>
                <?php endif; ?>

                <?php
                $metabox_provider = $carrier ? WCMCS_Carrier_Detector::get_provider_by_slug( $carrier ) : null;
                if ( $metabox_provider && method_exists( $metabox_provider, 'get_label_pdf' ) && $tracking && $status === WCMCS_Order_Meta::STATUS_SYNCED ) : ?>
                <button type="button"
                        class="button wcmcs-btn-label"
                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmcs_ajax' ) ); ?>">
                    📄 <?php esc_html_e( 'Étiquette PDF', 'wc-multicarrier-sync' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="wcmcs-ajax-result" style="margin-top:8px"></div>
        </div>

        <style>
        .wcmcs-metabox p { margin: 6px 0; font-size: 13px; }
        .wcmcs-btn-sync, .wcmcs-btn-retry { font-size: 12px !important; }
        .wcmcs-btn-cancel { font-size: 12px !important; color: #dc3545; border-color: #dc3545; }
        .wcmcs-btn-cancel:hover { color: #a71d2a; border-color: #a71d2a; }
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

        $forced = sanitize_text_field( wp_unslash( $_POST['wcmcs_forced_carrier'] ?? '' ) );

        // Valider le slug contre les providers réellement enregistrés.
        if ( '' !== $forced && ! array_key_exists( $forced, WCMCS_Carrier_Detector::get_all_providers() ) ) {
            $forced = '';
        }

        WCMCS_WC_Compat::update_order_meta( $order, '_wcmcs_forced_carrier', $forced );
    }

    // ─────────────────────────────────────────────
    //  Bulk Sync — Modal & Process
    // ─────────────────────────────────────────────

    /**
     * AJAX: Retourne les infos des commandes sélectionnées et les transporteurs disponibles.
     */
    public function ajax_bulk_sync_modal_data(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_ids = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] )
            ? array_map( 'absint', $_POST['order_ids'] )
            : [];

        if ( empty( $order_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'Aucune commande sélectionnée.', 'wc-multicarrier-sync' ) ] );
        }

        $orders_data = [];
        foreach ( $order_ids as $order_id ) {
            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $status       = WCMCS_Order_Meta::get_status( $order );
            $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
            $provider     = $carrier_slug ? WCMCS_Carrier_Detector::get_provider_by_slug( $carrier_slug ) : null;

            $orders_data[] = [
                'id'            => $order_id,
                'status'        => $status,
                'status_label'  => WCMCS_Order_Meta::get_status_label( $status ),
                'carrier'       => $carrier_slug,
                'carrier_label' => $provider ? $provider->get_label() : '',
                'tracking_id'   => WCMCS_Order_Meta::get_tracking_id( $order ),
                'already_synced' => ( $status === WCMCS_Order_Meta::STATUS_SYNCED ),
            ];
        }

        $carriers = [];
        foreach ( WCMCS_Carrier_Detector::get_enabled_providers() as $provider ) {
            $carriers[] = [
                'slug'       => $provider->get_slug(),
                'label'      => $provider->get_label(),
                'configured'  => $provider->is_configured(),
            ];
        }

        wp_send_json_success( [
            'orders'   => $orders_data,
            'carriers' => $carriers,
        ] );
    }

    /**
     * AJAX: Synchronise une commande individuelle dans le cadre du bulk sync.
     * Accepte un paramètre force_carrier pour forcer le transporteur.
     */
    public function ajax_bulk_sync_process(): void {
        check_ajax_referer( 'wcmcs_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'wc-multicarrier-sync' ) ] );
        }

        $order_id          = absint( $_POST['order_id'] ?? 0 );
        $force_carrier     = sanitize_text_field( $_POST['force_carrier'] ?? '' );

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'ID commande invalide.', 'wc-multicarrier-sync' ), 'status' => 'failed' ] );
        }

        $result = WCMCS_Sync_Manager::sync_single( $order_id, $force_carrier );

        // Enrichir avec le HTML des colonnes
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            $result['columns'] = [
                'carrier'      => $this->get_column_html( 'wcmcs_carrier', $order ),
                'sync_status'  => $this->get_column_html( 'wcmcs_sync_status', $order ),
                'tracking'     => $this->get_column_html( 'wcmcs_tracking', $order ),
            ];
        }
        $result['message'] = wp_kses_post( $result['message'] ?? '' );

        if ( $result['status'] === 'success' ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ─────────────────────────────────────────────
    //  Enqueue scripts & styles
    // ─────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $valid_hooks = [ 'edit.php', 'post.php', 'post-new.php', 'woocommerce_page_wc-orders', 'woocommerce_page_wcmcs-settings' ];
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
            'statusColors' => WCMCS_Order_Statuses::status_colors(),
            'i18n'    => [
                'syncing'              => __( 'Synchronisation...', 'wc-multicarrier-sync' ),
                'sync_btn'             => __( '⇄ Synchroniser', 'wc-multicarrier-sync' ),
                'retry_btn'            => __( '↺ Retry', 'wc-multicarrier-sync' ),
                'retrying'             => __( 'Retry en cours...', 'wc-multicarrier-sync' ),
                'checking'             => __( 'Vérification...', 'wc-multicarrier-sync' ),
                'status_btn'           => __( '📍 Vérifier le statut', 'wc-multicarrier-sync' ),
                'cancel_btn'           => __( '✕ Annuler le colis', 'wc-multicarrier-sync' ),
                'cancel_short'         => __( 'Annuler', 'wc-multicarrier-sync' ),
                'cancelling'           => __( 'Annulation...', 'wc-multicarrier-sync' ),
                'cancelled_word'       => __( 'Annulé', 'wc-multicarrier-sync' ),
                'confirm_cancel'       => __( 'Annuler le colis chez le transporteur ? Uniquement s\'il est encore en attente (avant enlèvement) — action irréversible.', 'wc-multicarrier-sync' ),
                'state_of_carrier'     => __( 'État transporteur', 'wc-multicarrier-sync' ),
                'status_updated'       => __( 'Statut commande mis à jour →', 'wc-multicarrier-sync' ),
                'scan_btn'             => __( '📍 Vérifier tous les statuts', 'wc-multicarrier-sync' ),
                'scan_title'           => __( 'Vérifier les statuts des colis', 'wc-multicarrier-sync' ),
                'scan_col_order'       => __( 'Commande', 'wc-multicarrier-sync' ),
                'scan_col_carrier'     => __( 'Transporteur', 'wc-multicarrier-sync' ),
                'scan_col_tracking'    => __( 'N° suivi', 'wc-multicarrier-sync' ),
                'scan_col_wc_status'   => __( 'Statut commande', 'wc-multicarrier-sync' ),
                'scan_col_state'       => __( 'État actuel', 'wc-multicarrier-sync' ),
                'scan_col_result'      => __( 'Résultat', 'wc-multicarrier-sync' ),
                'scan_unknown'         => __( 'inconnu', 'wc-multicarrier-sync' ),
                'scan_none'            => __( 'Aucun colis synchronisé à vérifier.', 'wc-multicarrier-sync' ),
                'scan_loading'         => __( 'Chargement des colis...', 'wc-multicarrier-sync' ),
                'scan_updated_word'    => __( 'statut(s) mis à jour', 'wc-multicarrier-sync' ),
                'scan_errors_word'     => __( 'erreur(s) API', 'wc-multicarrier-sync' ),
                'scan_checked_word'    => __( 'colis vérifié(s)', 'wc-multicarrier-sync' ),
                'scan_not_configured'  => __( 'non configuré', 'wc-multicarrier-sync' ),
                'scan_start'           => __( '▶ Lancer la vérification', 'wc-multicarrier-sync' ),
                'scan_none_selected'   => __( 'Aucun colis sélectionné.', 'wc-multicarrier-sync' ),
                'scan_rate_limited'    => __( 'API saturée — pause 3 s puis nouvel essai...', 'wc-multicarrier-sync' ),
                'scan_filter_word'     => __( 'Filtrer', 'wc-multicarrier-sync' ),
                'scan_filters_word'    => __( 'Tout', 'wc-multicarrier-sync' ),
                'scan_filternone_word' => __( 'Aucun', 'wc-multicarrier-sync' ),
                'scan_visible_word'    => __( 'colis affichés', 'wc-multicarrier-sync' ),
                'f_synced'             => WCMCS_Order_Statuses::label( 'synced' ),
                'f_processing'         => __( 'En cours', 'wc-multicarrier-sync' ),
                'f_onhold'             => __( 'En attente', 'wc-multicarrier-sync' ),
                'f_pending'            => __( 'Paiement en attente', 'wc-multicarrier-sync' ),
                'f_completed'          => __( 'Terminée', 'wc-multicarrier-sync' ),
                'f_delivered'          => WCMCS_Order_Statuses::label( 'delivered' ),
                'f_returned'           => WCMCS_Order_Statuses::label( 'returned' ),
                'f_confirmed'          => WCMCS_Order_Statuses::label( 'confirmed' ),
                'f_packed'             => WCMCS_Order_Statuses::label( 'packed' ),
                'badge_unknown'        => __( 'inconnu', 'wc-multicarrier-sync' ),
                'badge_transit'        => __( 'en cours', 'wc-multicarrier-sync' ),
                'badge_delivered'      => __( 'livré', 'wc-multicarrier-sync' ),
                'badge_returned'       => __( 'retour', 'wc-multicarrier-sync' ),
                'fullscreen_on'        => __( 'Plein écran', 'wc-multicarrier-sync' ),
                'fullscreen_off'       => __( 'Réduire', 'wc-multicarrier-sync' ),
                'scan_reload'          => __( 'Fermer & recharger', 'wc-multicarrier-sync' ),
                'success'              => __( 'Synchronisé !', 'wc-multicarrier-sync' ),
                'error'                => __( 'Erreur :', 'wc-multicarrier-sync' ),
                'confirm_sync'         => __( 'Synchroniser cette commande ?', 'wc-multicarrier-sync' ),
                'downloading'          => __( 'Téléchargement...', 'wc-multicarrier-sync' ),
                'label_downloaded'     => __( 'Étiquette téléchargée', 'wc-multicarrier-sync' ),
                'label_pdf'            => __( 'Étiquette PDF', 'wc-multicarrier-sync' ),
                'network_error'        => __( 'Erreur réseau.', 'wc-multicarrier-sync' ),
                'bulk_title'           => __( 'Synchroniser les commandes', 'wc-multicarrier-sync' ),
                'bulk_carrier_label'   => __( 'Transporteur :', 'wc-multicarrier-sync' ),
                'bulk_auto_detect'     => __( '— Détection automatique —', 'wc-multicarrier-sync' ),
                'bulk_order_col'       => __( 'Commande', 'wc-multicarrier-sync' ),
                'bulk_status_col'      => __( 'Statut', 'wc-multicarrier-sync' ),
                'bulk_result_col'      => __( 'Résultat', 'wc-multicarrier-sync' ),
                'bulk_already_synced'  => __( 'Déjà synchronisé', 'wc-multicarrier-sync' ),
                'bulk_start'           => __( 'Synchroniser', 'wc-multicarrier-sync' ),
                'bulk_cancel'          => __( 'Annuler', 'wc-multicarrier-sync' ),
                'bulk_close'           => __( 'Fermer', 'wc-multicarrier-sync' ),
                'bulk_processing'      => __( 'Traitement en cours...', 'wc-multicarrier-sync' ),
                'bulk_done'            => __( 'Terminé', 'wc-multicarrier-sync' ),
                'bulk_no_orders'       => __( 'Aucune commande à synchroniser.', 'wc-multicarrier-sync' ),
                'bulk_all_synced'      => __( 'Toutes les commandes sont déjà synchronisées.', 'wc-multicarrier-sync' ),
                'bulk_not_configured'  => __( 'non configuré', 'wc-multicarrier-sync' ),
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

        // Vérification du statut colis (si déjà synchronisé avec suivi)
        if ( '' !== WCMCS_Order_Meta::get_tracking_id( $order ) && '' !== WCMCS_Order_Meta::get_carrier_slug( $order ) ) {
            $actions['wcmcs_track_status'] = [
                'url'    => '#',
                'name'   => __( '📍 Statut', 'wc-multicarrier-sync' ),
                'action' => 'wcmcs-status',
                'title'  => __( 'Vérifier le statut du colis chez le transporteur', 'wc-multicarrier-sync' ),
            ];
        }

        // Annulation du colis (transporteurs supportant l'annulation)
        if ( $this->is_cancel_available( $order ) ) {
            $actions['wcmcs_cancel_shipment'] = [
                'url'    => '#',
                'name'   => __( '✕ Annuler', 'wc-multicarrier-sync' ),
                'action' => 'wcmcs-cancel',
                'title'  => __( 'Annuler le colis chez le transporteur (colis en attente uniquement)', 'wc-multicarrier-sync' ),
            ];
        }

        return $actions;
    }

    /**
     * Sanitize un slug de transporteur forcé transmis en AJAX.
     * Retourne '' si le slug n'est pas un provider enregistré.
     */
    private function sanitize_forced_carrier( $raw ): string {
        $slug = sanitize_key( is_string( $raw ) ? trim( $raw ) : '' );
        if ( '' === $slug ) {
            return '';
        }
        return array_key_exists( $slug, WCMCS_Carrier_Detector::get_all_providers() ) ? $slug : '';
    }

    /**
     * L'annulation du colis est-elle disponible pour cette commande ?
     *
     * Conditions : synchronisée + tracking + transporteur supportant
     * l'annulation (matrice CARRIER_FEATURES) + provider configuré.
     */
    private function is_cancel_available( WC_Order $order ): bool {
        if ( WCMCS_Order_Meta::get_status( $order ) !== WCMCS_Order_Meta::STATUS_SYNCED ) {
            return false;
        }
        if ( '' === WCMCS_Order_Meta::get_tracking_id( $order ) ) {
            return false;
        }
        $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
        if ( '' === $carrier_slug ) {
            return false;
        }
        $features = WCMCS_Carrier_Detector::get_carrier_features( $carrier_slug );
        if ( empty( $features['annulation'] ) ) {
            return false;
        }
        $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $carrier_slug );
        return $provider && $provider->is_configured();
    }

    // ─────────────────────────────────────────────
    //  Tracking URLs
    // ─────────────────────────────────────────────

    private function get_tracking_url( string $tracking_id, string $carrier_slug ): string {
        $urls = apply_filters( 'wcmcs_tracking_urls', [
            'colissimo'     => 'https://colissimo.com.tn/suivi/{tracking_id}',
            'firstdelivery' => 'https://tracking.firstdelivery.tn/?tracking={tracking_id}',
        ] );

        if ( ! isset( $urls[ $carrier_slug ] ) ) {
            return '';
        }

        return str_replace( '{tracking_id}', rawurlencode( $tracking_id ), $urls[ $carrier_slug ] );
    }
}
