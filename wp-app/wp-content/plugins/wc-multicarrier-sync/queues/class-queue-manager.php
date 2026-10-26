<?php
/**
 * Gestionnaire de queue asynchrone (Action Scheduler).
 *
 * Action Scheduler est bundlé avec WooCommerce — c'est le seul chemin de
 * traitement async supporté. (Un ancien fallback WP Cron a été retiré :
 * jamais alimenté, il planifiait des événements sans callback enregistré.)
 *
 * Implémente un traitement une-commande-une-action pour éviter :
 *  - timeout PHP
 *  - memory overflow
 *  - blocage de l'admin
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Queue_Manager {

    /** Hook pour traitement d'une commande unique */
    const HOOK_SINGLE = 'wcmcs_process_single_order';

    /** Groupe Action Scheduler */
    const GROUP = 'wcmcs';

    public function __construct() {
        if ( WCMCS_WC_Compat::has_action_scheduler() ) {
            add_action( self::HOOK_SINGLE, [ $this, 'process_single_as' ], 10, 1 );
        }
    }

    // ─────────────────────────────────────────────
    //  Action Scheduler
    // ─────────────────────────────────────────────

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

        // Retry automatique si échec (configurable).
        // Le budget (retry_count) est dédié aux retries AS : les échecs des
        // syncs manuelles admin ne le consomment plus. max_retries = nombre
        // de RE-rèelles (tentatives totales = 1 + max).
        $max_retries = (int) get_option( 'wcmcs_max_auto_retries', 0 );
        if ( $result['status'] === 'failed' && $max_retries > 0 ) {
            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( $order ) {
                $used = WCMCS_Order_Meta::get_retry_count( $order );
                $next = $used + 1;
                if ( $next <= $max_retries ) {
                    WCMCS_Order_Meta::set_retry_count( $order, $next );
                    $delay = min( 300 * $next, 3600 ); // Backoff exponentiel, max 1h
                    WCMCS_WC_Compat::schedule_action(
                        time() + $delay,
                        self::HOOK_SINGLE,
                        [ 'order_id' => $order_id ],
                        self::GROUP
                    );
                    WCMCS_Logger::info( "AS: retry #{$next}/{$max_retries} planifié pour commande #{$order_id} dans {$delay}s." );
                }
            }
        }
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
        if ( ! WCMCS_WC_Compat::has_action_scheduler()
            || ! function_exists( 'as_get_scheduled_actions' )
            || ! class_exists( '\ActionScheduler_Store' ) ) {
            return 0;
        }

        return (int) as_get_scheduled_actions( [
            'hook'     => self::HOOK_SINGLE,
            'group'    => self::GROUP,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ], 'count' );
    }

    /**
     * Nettoie les actions Action Scheduler en attente depuis plus de 30 minutes
     * (backlog / runner bloqué) : l'action est re-planifiée à +60 s afin que
     * la commande ne reste PAS orpheline, et son statut repasse en pending.
     *
     * Appelé sur admin_init pour assurer la résilience de la queue.
     *
     * @return int Nombre de commandes re-planifiées.
     */
    public static function cleanup_stuck_actions(): int {
        if ( ! WCMCS_WC_Compat::has_action_scheduler()
            || ! function_exists( 'as_get_scheduled_actions' )
            || ! class_exists( '\ActionScheduler_Store' ) ) {
            return 0;
        }

        $cutoff  = time() - ( 30 * MINUTE_IN_SECONDS );
        $cleaned = 0;

        $stuck_actions = as_get_scheduled_actions( [
            'hook'     => self::HOOK_SINGLE,
            'group'    => self::GROUP,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100,
        ] );

        foreach ( $stuck_actions as $action_id => $action ) {
            $schedule = $action->get_schedule();
            // API AS 3.x : get_timestamp() ; fallback get_date() (déprécié) sinon.
            $scheduled_ts = method_exists( $schedule, 'get_timestamp' )
                ? $schedule->get_timestamp()
                : ( $schedule->get_date() ? $schedule->get_date()->getTimestamp() : 0 );
            if ( ! $scheduled_ts || $scheduled_ts > $cutoff ) {
                continue;
            }

            $args = $action->get_args();
            $order_id = absint( $args['order_id'] ?? 0 );
            if ( ! $order_id ) {
                continue;
            }

            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( ! $order || WCMCS_Order_Meta::get_status( $order ) !== WCMCS_Order_Meta::STATUS_QUEUED ) {
                continue;
            }

            // Annuler l'action coincée puis la RE-planifier à +60 s :
            // la commande reste dans le pipeline au lieu d'être orpheline.
            as_unschedule_action( self::HOOK_SINGLE, $args, self::GROUP );
            WCMCS_WC_Compat::schedule_action(
                time() + MINUTE_IN_SECONDS,
                self::HOOK_SINGLE,
                [ 'order_id' => $order_id ],
                self::GROUP
            );
            WCMCS_Order_Meta::reset( $order );
            $cleaned++;
        }

        if ( $cleaned > 0 ) {
            WCMCS_Logger::info( sprintf(
                'Queue cleanup: %d commande(s) re-planifiée(s) (actions coincées > 30 min).',
                $cleaned
            ) );
        }

        return $cleaned;
    }
}
