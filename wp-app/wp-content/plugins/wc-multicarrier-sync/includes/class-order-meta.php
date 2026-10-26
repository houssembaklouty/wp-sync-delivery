<?php
/**
 * Gestion des meta-données de synchronisation sur les commandes.
 *
 * Meta keys utilisées :
 *   _wcmcs_sync_status    → pending | synced | failed | queued (cancelled = historique seul)
 *   _wcmcs_tracking_id    → ID de suivi retourné par l'API
 *   _wcmcs_carrier_slug   → slug du transporteur utilisé
 *   _wcmcs_sync_date      → timestamp de la dernière sync
 *   _wcmcs_error_message  → message d'erreur humain
 *   _wcmcs_api_response   → réponse brute JSON de l'API
 *   _wcmcs_retry_count    → retries automatiques consommés (budget Action Scheduler)
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Order_Meta {

    // ─── Constantes des statuts ──────────────────
    const STATUS_PENDING   = 'pending';
    const STATUS_SYNCED    = 'synced';
    const STATUS_FAILED    = 'failed';
    const STATUS_QUEUED    = 'queued'; // En attente dans la queue async
    const STATUS_CANCELLED = 'cancelled'; // Colis annulé chez le transporteur (historique uniquement — la commande repasse à pending)

    // ─── Meta keys ──────────────────────────────
    const META_STATUS       = '_wcmcs_sync_status';
    const META_TRACKING_ID  = '_wcmcs_tracking_id';
    const META_CARRIER_SLUG = '_wcmcs_carrier_slug';
    const META_SYNC_DATE    = '_wcmcs_sync_date';
    const META_ERROR_MSG    = '_wcmcs_error_message';
    const META_API_RESPONSE = '_wcmcs_api_response';
    const META_RETRY_COUNT  = '_wcmcs_retry_count';
    const META_LABEL_URL    = '_wcmcs_label_url';

    // ─────────────────────────────────────────────
    //  Lecture
    // ─────────────────────────────────────────────

    public static function get_status( WC_Order $order ): string {
        $status = WCMCS_WC_Compat::get_order_meta( $order, self::META_STATUS );
        return $status ?: self::STATUS_PENDING;
    }

    public static function get_tracking_id( WC_Order $order ): string {
        return (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_TRACKING_ID );
    }

    public static function get_carrier_slug( WC_Order $order ): string {
        return (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_CARRIER_SLUG );
    }

    public static function get_error_message( WC_Order $order ): string {
        return (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_ERROR_MSG );
    }

    public static function get_api_response( WC_Order $order ): array {
        $raw = WCMCS_WC_Compat::get_order_meta( $order, self::META_API_RESPONSE );
        if ( is_string( $raw ) && ! empty( $raw ) ) {
            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return is_array( $raw ) ? $raw : [];
    }

    public static function get_retry_count( WC_Order $order ): int {
        return (int) WCMCS_WC_Compat::get_order_meta( $order, self::META_RETRY_COUNT );
    }

    public static function get_sync_date( WC_Order $order ): string {
        return (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_SYNC_DATE );
    }

    public static function get_label_url( WC_Order $order ): string {
        return (string) WCMCS_WC_Compat::get_order_meta( $order, self::META_LABEL_URL );
    }

    /**
     * Définit le compteur de retries automatiques consommés.
     *
     * Géré exclusivement par WCMCS_Queue_Manager::process_single_as().
     */
    public static function set_retry_count( WC_Order $order, int $count ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_RETRY_COUNT, absint( $count ), true );
    }

    // ─────────────────────────────────────────────
    //  Écriture
    // ─────────────────────────────────────────────

    /**
     * Marque une commande comme synchronisée avec succès.
     *
     * @param WC_Order $order
     * @param array    $result   ['tracking_id', 'label_url', 'raw']
     * @param string   $carrier_slug
     */
    public static function mark_synced( WC_Order $order, array $result, string $carrier_slug ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS,       self::STATUS_SYNCED,                   false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_TRACKING_ID,  sanitize_text_field( $result['tracking_id'] ?? '' ), false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_CARRIER_SLUG, sanitize_text_field( $carrier_slug ),  false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_SYNC_DATE,    current_time( 'mysql' ),               false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_ERROR_MSG,    '',                                    false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_LABEL_URL,    esc_url_raw( $result['label_url'] ?? '' ), false );

        if ( isset( $result['raw'] ) ) {
            WCMCS_WC_Compat::update_order_meta(
                $order,
                self::META_API_RESPONSE,
                wp_json_encode( $result['raw'] ),
                false
            );
        }

        // Reset retry counter
        WCMCS_WC_Compat::update_order_meta( $order, self::META_RETRY_COUNT, 0, false );

        $order->save_meta_data();

        // Note interne dans la commande
        $order->add_order_note(
            sprintf(
                /* translators: 1: carrier slug, 2: tracking ID */
                __( '[WCMCS] Synchronisé via %1$s. Tracking : %2$s', 'wc-multicarrier-sync' ),
                strtoupper( $carrier_slug ),
                $result['tracking_id'] ?? 'N/A'
            ),
            false, // Pas de mail client
            true   // Note interne
        );

        self::log_attempt( $order, $carrier_slug, self::STATUS_SYNCED, sanitize_text_field( $result['tracking_id'] ?? '' ), '', $result['raw'] ?? [] );

        do_action( 'wcmcs_order_synced', $order, $result, $carrier_slug );
    }

    /**
     * Marque une commande comme échouée.
     *
     * NB : n'incrémente PAS le compteur de retries — celui-ci est géré
     * exclusivement par WCMCS_Queue_Manager (budget de retries automatiques),
     * afin que les échecs des syncs manuelles ne le consomment pas.
     *
     * @param WC_Order $order
     * @param string   $error_message
     * @param string   $carrier_slug
     * @param array    $raw_response
     */
    public static function mark_failed( WC_Order $order, string $error_message, string $carrier_slug = '', array $raw_response = [] ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS,       self::STATUS_FAILED,                      false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_ERROR_MSG,    sanitize_textarea_field( $error_message ), false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_SYNC_DATE,    current_time( 'mysql' ),                   false );

        if ( $carrier_slug ) {
            WCMCS_WC_Compat::update_order_meta( $order, self::META_CARRIER_SLUG, sanitize_text_field( $carrier_slug ), false );
        }

        if ( ! empty( $raw_response ) ) {
            WCMCS_WC_Compat::update_order_meta(
                $order,
                self::META_API_RESPONSE,
                wp_json_encode( $raw_response ),
                false
            );
        }

        $order->save_meta_data();

        $order->add_order_note(
            sprintf(
                /* translators: 1: error message */
                __( '[WCMCS] Échec de synchronisation : %s', 'wc-multicarrier-sync' ),
                $error_message
            ),
            false,
            true
        );

        self::log_attempt( $order, $carrier_slug, self::STATUS_FAILED, '', $error_message, $raw_response );

        do_action( 'wcmcs_order_failed', $order, $error_message, $carrier_slug );
    }

    /**
     * Marque une commande en file d'attente.
     *
     * @param WC_Order $order
     * @param string   $carrier_slug
     */
    public static function mark_queued( WC_Order $order, string $carrier_slug = '' ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS, self::STATUS_QUEUED, false );
        if ( $carrier_slug ) {
            WCMCS_WC_Compat::update_order_meta( $order, self::META_CARRIER_SLUG, $carrier_slug, false );
        }
        $order->save_meta_data();

        self::log_attempt( $order, $carrier_slug, self::STATUS_QUEUED );
    }

    /**
     * Marque le colis comme ANNULÉ chez le transporteur.
     *
     * La commande repasse à STATUS_PENDING (re-synchronisable immédiatement) ;
     * le slug transporteur est conservé (détection par défaut à la re-sync,
     * cohérent avec reset()). La ligne « cancelled » de l'historique garde la
     * trace du tracking annulé. Le statut WooCommerce n'est PAS modifié.
     *
     * @param WC_Order $order        Commande.
     * @param string   $carrier_slug Slug du transporteur.
     * @param string   $tracking_id  Tracking annulé chez le transporteur.
     */
    public static function mark_cancelled( WC_Order $order, string $carrier_slug, string $tracking_id ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS,       self::STATUS_PENDING, false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_TRACKING_ID,  '',                    false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_ERROR_MSG,    '',                    false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_LABEL_URL,    '',                    false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_API_RESPONSE, '',                    false );
        // Purges complémentaires (revue de code 2.10.0) : date de sync
        // périmée + mémo du dernier état (collision possible avec le
        // prochain cycle de suivi de la nouvelle expédition).
        WCMCS_WC_Compat::update_order_meta( $order, self::META_SYNC_DATE,    '',                    false );
        WCMCS_WC_Compat::update_order_meta( $order, '_wcmcs_last_state',     '',                    false );
        WCMCS_WC_Compat::update_order_meta( $order, '_wcmcs_awaiting_official_state', '',          false );
        $order->save_meta_data();

        $order->add_order_note(
            sprintf(
                /* translators: 1: carrier slug, 2: tracking ID */
                __( '[WCMCS] Colis annulé chez %1$s (tracking %2$s). Commande re-synchronisable.', 'wc-multicarrier-sync' ),
                strtoupper( $carrier_slug ),
                $tracking_id ?: 'N/A'
            ),
            false,
            true
        );

        self::log_attempt( $order, $carrier_slug, self::STATUS_CANCELLED, sanitize_text_field( $tracking_id ) );

        do_action( 'wcmcs_order_cancelled', $order, $carrier_slug, $tracking_id );
    }

    /**
     * Réinitialise le statut d'une commande (retry).
     *
     * @param WC_Order $order
     */
    public static function reset( WC_Order $order ): void {
        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS,       self::STATUS_PENDING, false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_ERROR_MSG,    '',                   false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_TRACKING_ID,  '',                   false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_API_RESPONSE, '',                   false );
        $order->save_meta_data();
    }

    // ─────────────────────────────────────────────
    //  Journalisation DB (table wcmcs_sync_log)
    // ─────────────────────────────────────────────

    /** @var bool|null Disponibilité de la table (cache par requête) */
    private static ?bool $log_table_exists = null;

    /**
     * Journalise une tentative de synchronisation dans la table
     * {$wpdb->prefix}wcmcs_sync_log (historique persistant des tentatives).
     */
    private static function log_attempt( WC_Order $order, string $carrier_slug, string $status, string $tracking_id = '', string $error_msg = '', array $raw_response = [] ): void {
        global $wpdb;

        if ( self::$log_table_exists === null ) {
            $table = $wpdb->prefix . 'wcmcs_sync_log';
            self::$log_table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        }
        if ( ! self::$log_table_exists ) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'wcmcs_sync_log',
            [
                'order_id'     => $order->get_id(),
                'carrier_slug' => sanitize_text_field( $carrier_slug ),
                'status'       => sanitize_key( $status ),
                'tracking_id'  => sanitize_text_field( $tracking_id ),
                'error_msg'    => sanitize_textarea_field( $error_msg ),
                'api_response' => empty( $raw_response ) ? '' : wp_json_encode( $raw_response ),
                'synced_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // ─────────────────────────────────────────────
    //  Historique de sync (consultation)
    // ─────────────────────────────────────────────

    /**
     * Tentatives de sync d'une commande (plus récentes d'abord).
     *
     * @return object[] Chaque ligne : id, order_id, carrier_slug, status, tracking_id, error_msg, synced_at.
     */
    public static function get_sync_history( int $order_id, int $limit = 10 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcmcs_sync_log';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, order_id, carrier_slug, status, tracking_id, error_msg, synced_at
             FROM {$table}
             WHERE order_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $order_id,
            max( 1, $limit )
        ) );

        return $rows ? $rows : [];
    }

    /**
     * Historique global avec filtres + pagination.
     *
     * @param array $args {
     *   order_id, carrier, status (pending|synced|failed|queued),
     *   date_from (Y-m-d), date_to (Y-m-d), page, per_page (0 = sans limite, export).
     * }
     * @return array { rows: object[], total: int }
     */
    public static function get_sync_history_all( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcmcs_sync_log';

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['order_id'] ) ) {
            $where[]  = 'order_id = %d';
            $params[] = absint( $args['order_id'] );
        }
        if ( ! empty( $args['carrier'] ) ) {
            $where[]  = 'carrier_slug = %s';
            $params[] = sanitize_key( $args['carrier'] );
        }
        $valid_statuses = [ self::STATUS_PENDING, self::STATUS_SYNCED, self::STATUS_FAILED, self::STATUS_QUEUED, self::STATUS_CANCELLED ];
        if ( ! empty( $args['status'] ) && in_array( $args['status'], $valid_statuses, true ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
            $where[]  = 'synced_at >= %s';
            $params[] = $args['date_from'] . ' 00:00:00';
        }
        if ( ! empty( $args['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
            $where[]  = 'synced_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );

        if ( $params ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
        }

        $per_page = (int) ( $args['per_page'] ?? 20 );
        $rows     = [];

        if ( $per_page > 0 ) {
            $page   = max( 1, (int) ( $args['page'] ?? 1 ) );
            $offset = ( $page - 1 ) * $per_page;
            $sql    = "SELECT id, order_id, carrier_slug, status, tracking_id, error_msg, synced_at
                       FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
            $rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, [ $per_page, $offset ] ) ) );
        } else {
            // Sans limite (export CSV).
            $sql = "SELECT id, order_id, carrier_slug, status, tracking_id, error_msg, synced_at
                    FROM {$table} WHERE {$where_sql} ORDER BY id DESC";
            $rows = $params
                ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
                : $wpdb->get_results( $sql );
        }

        return [
            'rows'  => $rows ? $rows : [],
            'total' => $total,
        ];
    }

    // ─────────────────────────────────────────────
    //  Helpers UI
    // ─────────────────────────────────────────────

    /**
     * Libellé français pur d'un statut de sync (réutilisable hors HTML).
     *
     * @param string $status pending|synced|failed|queued.
     * @return string
     */
    public static function get_status_label( string $status ): string {
        $labels = [
            self::STATUS_PENDING   => __( 'En attente', 'wc-multicarrier-sync' ),
            self::STATUS_SYNCED    => __( 'Synchronisé', 'wc-multicarrier-sync' ),
            self::STATUS_FAILED    => __( 'Échec', 'wc-multicarrier-sync' ),
            self::STATUS_QUEUED    => __( 'En queue', 'wc-multicarrier-sync' ),
            self::STATUS_CANCELLED => __( 'Annulé', 'wc-multicarrier-sync' ),
        ];
        return $labels[ $status ] ?? $labels[ self::STATUS_PENDING ];
    }

    /**
     * Retourne le badge HTML du statut.
     *
     * @param string $status
     * @return string HTML
     */
    public static function get_status_badge( string $status ): string {
        $map = [
            self::STATUS_PENDING   => [ 'label' => __( 'En attente', 'wc-multicarrier-sync' ), 'color' => '#999' ],
            self::STATUS_SYNCED    => [ 'label' => __( 'Synchronisé', 'wc-multicarrier-sync' ), 'color' => '#28a745' ],
            self::STATUS_FAILED    => [ 'label' => __( 'Échec', 'wc-multicarrier-sync' ), 'color' => '#dc3545' ],
            self::STATUS_QUEUED    => [ 'label' => __( 'En queue', 'wc-multicarrier-sync' ), 'color' => '#fd7e14' ],
            self::STATUS_CANCELLED => [ 'label' => __( 'Annulé', 'wc-multicarrier-sync' ), 'color' => '#6c757d' ],
        ];

        $info = $map[ $status ] ?? $map[ self::STATUS_PENDING ];

        return sprintf(
            '<span class="wcmcs-badge wcmcs-badge--%1$s" style="background:%2$s;color:#fff;">%3$s</span>',
            esc_attr( $status ),
            esc_attr( $info['color'] ),
            esc_html( $info['label'] )
        );
    }
}
