<?php
/**
 * Gestion des meta-données de synchronisation sur les commandes.
 *
 * Meta keys utilisées :
 *   _wcmcs_sync_status    → pending | synced | failed
 *   _wcmcs_tracking_id    → ID de suivi retourné par l'API
 *   _wcmcs_carrier_slug   → slug du transporteur utilisé
 *   _wcmcs_sync_date      → timestamp de la dernière sync
 *   _wcmcs_error_message  → message d'erreur humain
 *   _wcmcs_api_response   → réponse brute JSON de l'API
 *   _wcmcs_retry_count    → nombre de tentatives
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Order_Meta {

    // ─── Constantes des statuts ──────────────────
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED  = 'synced';
    const STATUS_FAILED  = 'failed';
    const STATUS_QUEUED  = 'queued'; // En attente dans la queue async

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

        do_action( 'wcmcs_order_synced', $order, $result, $carrier_slug );
    }

    /**
     * Marque une commande comme échouée.
     *
     * @param WC_Order $order
     * @param string   $error_message
     * @param string   $carrier_slug
     * @param array    $raw_response
     */
    public static function mark_failed( WC_Order $order, string $error_message, string $carrier_slug = '', array $raw_response = [] ): void {
        $retry = self::get_retry_count( $order );

        WCMCS_WC_Compat::update_order_meta( $order, self::META_STATUS,       self::STATUS_FAILED,                      false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_ERROR_MSG,    sanitize_textarea_field( $error_message ), false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_SYNC_DATE,    current_time( 'mysql' ),                   false );
        WCMCS_WC_Compat::update_order_meta( $order, self::META_RETRY_COUNT,  $retry + 1,                                false );

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
    //  Helpers UI
    // ─────────────────────────────────────────────

    /**
     * Retourne le badge HTML du statut.
     *
     * @param string $status
     * @return string HTML
     */
    public static function get_status_badge( string $status ): string {
        $map = [
            self::STATUS_PENDING => [ 'label' => __( 'En attente', 'wc-multicarrier-sync' ), 'color' => '#999' ],
            self::STATUS_SYNCED  => [ 'label' => __( 'Synchronisé', 'wc-multicarrier-sync' ), 'color' => '#28a745' ],
            self::STATUS_FAILED  => [ 'label' => __( 'Échec', 'wc-multicarrier-sync' ), 'color' => '#dc3545' ],
            self::STATUS_QUEUED  => [ 'label' => __( 'En queue', 'wc-multicarrier-sync' ), 'color' => '#fd7e14' ],
        ];

        $info = $map[ $status ] ?? $map[ self::STATUS_PENDING ];

        return sprintf(
            '<span class="wcmcs-badge wcmcs-badge--%1$s" style="background:%2$s;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">%3$s</span>',
            esc_attr( $status ),
            esc_attr( $info['color'] ),
            esc_html( $info['label'] )
        );
    }
}
