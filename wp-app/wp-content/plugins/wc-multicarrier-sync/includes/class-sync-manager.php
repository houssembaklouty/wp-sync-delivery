<?php
/**
 * Gestionnaire de synchronisation.
 * Orchestre le workflow : détection → validation → envoi API → sauvegarde résultat.
 *
 * PRINCIPE CLÉ : chaque commande est traitée indépendamment.
 * Une erreur n'arrête JAMAIS le batch.
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Sync_Manager {

    // ─────────────────────────────────────────────
    //  Synchronisation en masse
    // ─────────────────────────────────────────────

    /**
     * Synchronise un tableau d'IDs de commandes.
     * Retourne un rapport détaillé.
     *
     * @param int[]  $order_ids      IDs des commandes à synchroniser.
     * @param bool   $async          Passer par la queue async ?
     * @return array {
     *   'total'     => int,
     *   'success'   => int,
     *   'failed'    => int,
     *   'skipped'   => int,
     *   'results'   => array<int, array{'status':string, 'message':string, 'tracking_id':string}>
     * }
     */
    public static function sync_bulk( array $order_ids, bool $async = true ): array {
        $report = [
            'total'   => count( $order_ids ),
            'success' => 0,
            'failed'  => 0,
            'skipped' => 0,
            'queued'  => 0,
            'results' => [],
        ];

        if ( empty( $order_ids ) ) {
            return $report;
        }

        WCMCS_Logger::info( sprintf( 'Lancement sync bulk : %d commande(s).', count( $order_ids ) ) );

        if ( $async && WCMCS_WC_Compat::has_action_scheduler() ) {
            // Mode async : ajouter à la queue
            return self::queue_bulk( $order_ids, $report );
        }

        // Mode synchrone (fallback ou forcé)
        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id );
            if ( ! $order_id ) {
                $report['skipped']++;
                continue;
            }

            $result = self::sync_single( $order_id );
            $report['results'][ $order_id ] = $result;

            if ( $result['status'] === 'success' ) {
                $report['success']++;
            } elseif ( $result['status'] === 'skipped' ) {
                $report['skipped']++;
            } else {
                $report['failed']++;
            }
        }

        WCMCS_Logger::info( sprintf(
            'Sync bulk terminée : %d succès, %d échecs, %d ignorés.',
            $report['success'],
            $report['failed'],
            $report['skipped']
        ) );

        do_action( 'wcmcs_bulk_sync_complete', $report );

        return $report;
    }

    /**
     * Ajoute les commandes à la queue async Action Scheduler.
     *
     * @param int[]  $order_ids
     * @param array  $report     Rapport partiel (sera complété async).
     * @return array
     */
    private static function queue_bulk( array $order_ids, array $report ): array {
        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id );
            if ( ! $order_id ) {
                continue;
            }

            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            // Marquer en queue
            WCMCS_Order_Meta::mark_queued( $order );

            // Planifier dans Action Scheduler (délai 0 = ASAP)
            WCMCS_WC_Compat::schedule_action(
                time(),
                'wcmcs_process_single_order',
                [ 'order_id' => $order_id ],
                'wcmcs'
            );

            $report['queued']++;

            $report['results'][ $order_id ] = [
                'status'      => 'queued',
                'message'     => __( 'Commande mise en queue pour traitement asynchrone.', 'wc-multicarrier-sync' ),
                'tracking_id' => '',
            ];
        }

        WCMCS_Logger::info( sprintf( '%d commande(s) ajoutées à la queue async.', $report['queued'] ) );
        return $report;
    }

    // ─────────────────────────────────────────────
    //  Synchronisation d'une commande unique
    // ─────────────────────────────────────────────

    /**
     * Synchronise une seule commande.
     *
     * IMPORTANT : toutes les exceptions sont catchées ici.
     * Cette fonction NE doit JAMAIS propager d'exception.
     *
     * @param int $order_id
     * @return array {'status': 'success'|'failed'|'skipped', 'message': string, 'tracking_id': string, 'carrier': string}
     */
    public static function sync_single( int $order_id ): array {
        $result = [
            'order_id'    => $order_id,
            'status'      => 'failed',
            'message'     => '',
            'tracking_id' => '',
            'carrier'     => '',
        ];

        try {
            // ── Charger la commande ────────────────
            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( ! $order ) {
                $result['message'] = sprintf( __( 'Commande #%d introuvable.', 'wc-multicarrier-sync' ), $order_id );
                WCMCS_Logger::error( $result['message'] );
                return $result;
            }

            // ── Vérifier si déjà synchronisée ─────
            $current_status = WCMCS_Order_Meta::get_status( $order );
            if ( $current_status === WCMCS_Order_Meta::STATUS_SYNCED ) {
                // On autorise la re-sync ? Vérifier le setting
                $allow_resync = get_option( 'wcmcs_allow_resync', 'no' );
                if ( $allow_resync !== 'yes' ) {
                    $result['status']      = 'skipped';
                    $result['message']     = __( 'Commande déjà synchronisée (re-sync désactivée).', 'wc-multicarrier-sync' );
                    $result['tracking_id'] = WCMCS_Order_Meta::get_tracking_id( $order );
                    return $result;
                }
            }

            // ── Détection du transporteur ──────────
            $provider = WCMCS_Carrier_Detector::detect( $order );
            if ( is_wp_error( $provider ) ) {
                $error_msg = $provider->get_error_message();
                WCMCS_Order_Meta::mark_failed( $order, $error_msg );
                $result['message'] = $error_msg;
                WCMCS_Logger::error( "Order #{$order_id}: {$error_msg}" );
                return $result;
            }

            $result['carrier'] = $provider->get_slug();

            // ── Vérifier la configuration du provider ─
            if ( ! $provider->is_configured() ) {
                $error_msg = sprintf(
                    __( 'Le transporteur %s n\'est pas configuré (clés API manquantes).', 'wc-multicarrier-sync' ),
                    $provider->get_label()
                );
                WCMCS_Order_Meta::mark_failed( $order, $error_msg, $provider->get_slug() );
                $result['message'] = $error_msg;
                WCMCS_Logger::error( "Order #{$order_id}: {$error_msg}" );
                return $result;
            }

            // ── Validation de la commande ──────────
            $validation = $provider->validate_order( $order );
            if ( is_wp_error( $validation ) ) {
                $error_msg = $validation->get_error_message();
                WCMCS_Order_Meta::mark_failed( $order, $error_msg, $provider->get_slug() );
                $result['message'] = $error_msg;
                WCMCS_Logger::error( "Order #{$order_id} validation échouée via {$provider->get_slug()}: {$error_msg}" );
                return $result;
            }

            // ── Hook pre-sync ──────────────────────
            $pre = apply_filters( 'wcmcs_pre_sync_order', true, $order, $provider );
            if ( ! $pre || is_wp_error( $pre ) ) {
                $error_msg = is_wp_error( $pre ) ? $pre->get_error_message() : __( 'Sync annulée par un hook tiers.', 'wc-multicarrier-sync' );
                $result['status']  = 'skipped';
                $result['message'] = $error_msg;
                return $result;
            }

            // ── Appel API : création expédition ────
            WCMCS_Logger::info( "Order #{$order_id}: envoi vers API {$provider->get_slug()}..." );
            $api_result = $provider->create_shipment( $order );

            if ( is_wp_error( $api_result ) ) {
                $error_msg = $api_result->get_error_message();
                $raw       = $api_result->get_error_data() ?? [];
                WCMCS_Order_Meta::mark_failed( $order, $error_msg, $provider->get_slug(), is_array( $raw ) ? $raw : [] );
                $result['message'] = $error_msg;
                WCMCS_Logger::error( "Order #{$order_id} API {$provider->get_slug()} error: {$error_msg}" );
                return $result;
            }

            // ── Succès ─────────────────────────────
            WCMCS_Order_Meta::mark_synced( $order, $api_result, $provider->get_slug() );

            $result['status']      = 'success';
            $result['message']     = sprintf(
                __( 'Synchronisé via %s.', 'wc-multicarrier-sync' ),
                $provider->get_label()
            );
            $result['tracking_id'] = $api_result['tracking_id'] ?? '';

            WCMCS_Logger::info( sprintf(
                'Order #%d synchronisée via %s. Tracking: %s',
                $order_id,
                $provider->get_slug(),
                $result['tracking_id']
            ) );

        } catch ( \Throwable $e ) {
            // SÉCURITÉ : attraper toute exception inattendue
            $error_msg = sprintf(
                __( 'Erreur inattendue : %s', 'wc-multicarrier-sync' ),
                $e->getMessage()
            );
            WCMCS_Logger::error( "Order #{$order_id} exception: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

            if ( isset( $order ) && $order instanceof WC_Order ) {
                WCMCS_Order_Meta::mark_failed( $order, $error_msg, $result['carrier'] ?? '' );
            }

            $result['message'] = $error_msg;
        }

        return $result;
    }

    // ─────────────────────────────────────────────
    //  Retry d'une commande échouée
    // ─────────────────────────────────────────────

    /**
     * Réessaye la synchronisation d'une commande.
     *
     * @param int $order_id
     * @return array
     */
    public static function retry( int $order_id ): array {
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            // Reset le statut pour permettre la re-sync
            WCMCS_Order_Meta::reset( $order );
        }

        WCMCS_Logger::info( "Retry commande #{$order_id}." );
        return self::sync_single( $order_id );
    }

    // ─────────────────────────────────────────────
    //  Formatage du rapport
    // ─────────────────────────────────────────────

    /**
     * Génère un message admin lisible depuis le rapport.
     *
     * @param array $report
     * @return string HTML
     */
    public static function format_report_message( array $report ): string {
        $parts = [];

        if ( $report['success'] > 0 ) {
            $parts[] = sprintf(
                '<strong style="color:#28a745">%d %s</strong>',
                $report['success'],
                _n( 'succès', 'succès', $report['success'], 'wc-multicarrier-sync' )
            );
        }

        if ( $report['failed'] > 0 ) {
            $parts[] = sprintf(
                '<strong style="color:#dc3545">%d %s</strong>',
                $report['failed'],
                _n( 'échec', 'échecs', $report['failed'], 'wc-multicarrier-sync' )
            );
        }

        if ( $report['skipped'] > 0 ) {
            $parts[] = sprintf(
                '<strong style="color:#6c757d">%d ignoré(s)</strong>',
                $report['skipped']
            );
        }

        if ( isset( $report['queued'] ) && $report['queued'] > 0 ) {
            $parts[] = sprintf(
                '<strong style="color:#fd7e14">%d en queue</strong>',
                $report['queued']
            );
        }

        $summary = sprintf(
            __( 'Sync batch : %d commandes → %s.', 'wc-multicarrier-sync' ),
            $report['total'],
            implode( ', ', $parts )
        );

        // Détail des erreurs
        $errors_html = '';
        foreach ( $report['results'] as $order_id => $res ) {
            if ( $res['status'] === 'failed' ) {
                $errors_html .= sprintf(
                    '<li>#%d → %s</li>',
                    absint( $order_id ),
                    esc_html( $res['message'] )
                );
            }
        }

        if ( $errors_html ) {
            $summary .= '<ul style="margin:8px 0 0 16px;color:#dc3545">' . $errors_html . '</ul>';
        }

        return $summary;
    }
}
