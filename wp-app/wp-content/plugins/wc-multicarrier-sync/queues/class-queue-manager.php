<?php
/**
 * Gestionnaire de queue asynchrone.
 *
 * Utilise Action Scheduler si disponible (recommandé),
 * sinon fallback sur WP Cron.
 *
 * Implémente un système de batch processing pour éviter :
 *  - timeout PHP
 *  - memory overflow
 *  - blocage de l'admin
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Queue_Manager {

    /** Taille du batch par défaut */
    const DEFAULT_BATCH_SIZE = 10;

    /** Hook pour traitement d'une commande unique */
    const HOOK_SINGLE = 'wcmcs_process_single_order';

    /** Hook pour traitement d'un batch (WP Cron) */
    const HOOK_BATCH = 'wcmcs_process_queue';

    /** Option name pour la queue WP Cron */
    const OPTION_QUEUE = 'wcmcs_pending_queue';

    public function __construct() {
        if ( WCMCS_WC_Compat::has_action_scheduler() ) {
            $this->init_action_scheduler();
        } else {
            $this->init_wp_cron();
        }
    }

    // ─────────────────────────────────────────────
    //  Action Scheduler
    // ─────────────────────────────────────────────

    private function init_action_scheduler(): void {
        add_action( self::HOOK_SINGLE, [ $this, 'process_single_as' ], 10, 1 );
    }

    /**
     * Callback Action Scheduler : traite une commande.
     *
     * @param array $args  ['order_id' => int]
     */
    public function process_single_as( array $args ): void {
        $order_id = absint( $args['order_id'] ?? 0 );
        if ( ! $order_id ) {
            return;
        }

        WCMCS_Logger::info( "AS: traitement commande #{$order_id}." );

        $this->set_time_limit();
        $result = WCMCS_Sync_Manager::sync_single( $order_id );

        WCMCS_Logger::info( sprintf(
            'AS: commande #%d → %s. %s',
            $order_id,
            $result['status'],
            $result['message']
        ) );

        // Retry automatique si échec ? (configurable)
        $max_retries = (int) get_option( 'wcmcs_max_auto_retries', 0 );
        if ( $result['status'] === 'failed' && $max_retries > 0 ) {
            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( $order ) {
                $retry_count = WCMCS_Order_Meta::get_retry_count( $order );
                if ( $retry_count < $max_retries ) {
                    $delay = min( 300 * ( $retry_count + 1 ), 3600 ); // Backoff exponentiel, max 1h
                    WCMCS_WC_Compat::schedule_action(
                        time() + $delay,
                        self::HOOK_SINGLE,
                        [ 'order_id' => $order_id ],
                        'wcmcs'
                    );
                    WCMCS_Logger::info( "AS: retry #{$retry_count} planifié pour commande #{$order_id} dans {$delay}s." );
                }
            }
        }
    }

    // ─────────────────────────────────────────────
    //  WP Cron fallback
    // ─────────────────────────────────────────────

    private function init_wp_cron(): void {
        add_action( self::HOOK_BATCH, [ $this, 'process_batch_cron' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

        // S'assurer que le cron est planifié
        if ( ! wp_next_scheduled( self::HOOK_BATCH ) ) {
            wp_schedule_event( time(), 'wcmcs_every_minute', self::HOOK_BATCH );
        }
    }

    /**
     * Ajoute un intervalle cron d'une minute.
     */
    public function add_cron_schedule( array $schedules ): array {
        $schedules['wcmcs_every_minute'] = [
            'interval' => 60,
            'display'  => __( 'WCMCS - Toutes les minutes', 'wc-multicarrier-sync' ),
        ];
        return $schedules;
    }

    /**
     * Callback WP Cron : traite un batch de la queue.
     */
    public function process_batch_cron(): void {
        $queue = get_option( self::OPTION_QUEUE, [] );
        if ( empty( $queue ) ) {
            return;
        }

        $batch_size = (int) get_option( 'wcmcs_batch_size', self::DEFAULT_BATCH_SIZE );
        $batch      = array_splice( $queue, 0, $batch_size );

        // Sauvegarder la queue restante
        update_option( self::OPTION_QUEUE, $queue, false );

        WCMCS_Logger::info( sprintf( 'WP Cron: traitement d\'un batch de %d commandes.', count( $batch ) ) );

        $this->set_time_limit();

        foreach ( $batch as $order_id ) {
            $result = WCMCS_Sync_Manager::sync_single( absint( $order_id ) );
            WCMCS_Logger::info( "Cron: commande #{$order_id} → {$result['status']}." );
        }
    }

    /**
     * Ajoute des commandes à la queue WP Cron.
     *
     * @param int[] $order_ids
     */
    public static function add_to_cron_queue( array $order_ids ): void {
        $existing = get_option( self::OPTION_QUEUE, [] );
        $merged   = array_unique( array_merge( $existing, array_map( 'absint', $order_ids ) ) );
        update_option( self::OPTION_QUEUE, $merged, false );

        WCMCS_Logger::info( sprintf( 'Cron queue: %d commandes ajoutées.', count( $order_ids ) ) );
    }

    // ─────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────

    /**
     * Augmenter le time limit PHP pour les traitements longs.
     */
    private function set_time_limit(): void {
        if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 300 ); // 5 minutes max
        }
        // Libérer la mémoire si possible
        if ( function_exists( 'wp_ob_end_flush_all' ) ) {
            // Ne pas buffer la sortie pendant le traitement async
        }
    }

    /**
     * Retourne le nombre de commandes en attente dans la queue.
     *
     * @return int
     */
    public static function get_queue_size(): int {
        if ( WCMCS_WC_Compat::has_action_scheduler() ) {
            return (int) as_get_scheduled_actions( [
                'hook'     => self::HOOK_SINGLE,
                'group'    => 'wcmcs',
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ], 'count' );
        }

        $queue = get_option( self::OPTION_QUEUE, [] );
        return count( $queue );
    }
}
