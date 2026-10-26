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
        // Augmenter le temps d'exécution pour les batchs volumineux
        if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 300 );
        }

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

        /**
         * Tiré aussi en mode async pour que les intégrations tierces
         * (rapport Slack/email...) reçoivent le rapport de queue.
         */
        do_action( 'wcmcs_bulk_sync_complete', $report );

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
     * @param int    $order_id
     * @param string $force_carrier_slug  Slug du transporteur à forcer (vide = détection auto).
     * @return array {'status': 'success'|'failed'|'skipped', 'message': string, 'tracking_id': string, 'carrier': string}
     */
    public static function sync_single( int $order_id, string $force_carrier_slug = '' ): array {
        $result = [
            'order_id'    => $order_id,
            'status'      => 'failed',
            'message'     => '',
            'tracking_id' => '',
            'carrier'     => '',
        ];

        $lock_set   = false;
        $lock_token = '';

        try {
            // ── Licence / trial / mode gratuit ──────
            // Trial et licence : illimité. Mode gratuit (sans licence) :
            // quota quotidien de syncs réussies — aucune fonctionnalité retirée.
            $license = WCMCS_License::get_instance();
            if ( ! $license->is_operational() ) {
                if ( $license->is_sync_quota_exceeded() ) {
                    $result['status']  = 'skipped';
                    $result['message'] = sprintf(
                        /* translators: 1: syncs effectuées aujourd'hui, 2: limite */
                        __( 'Limite gratuite atteinte (%1$d/%2$d syncs aujourd\'hui). Activez votre licence pour synchroniser sans limite.', 'wc-multicarrier-sync' ),
                        $license->get_daily_sync_count(),
                        $license->get_daily_sync_limit()
                    );
                    WCMCS_Logger::info( "Order #{$order_id}: sync ignorée — quota gratuit du jour atteint." );
                    return $result;
                }
                WCMCS_Logger::info( sprintf(
                    'Order #%d: mode gratuit — %d sync(s) restante(s) aujourd\'hui.',
                    $order_id,
                    $license->get_remaining_today()
                ) );
            }

            // ── Charger la commande ────────────────
            $order = WCMCS_WC_Compat::get_order( $order_id );
            if ( ! $order ) {
                $result['message'] = sprintf( __( 'Commande #%d introuvable.', 'wc-multicarrier-sync' ), $order_id );
                WCMCS_Logger::error( $result['message'] );
                return $result;
            }

            // ── Forcer le transporteur si demandé ───
            if ( ! empty( $force_carrier_slug ) ) {
                $valid_slugs = array_keys( WCMCS_Carrier_Detector::get_all_providers() );
                if ( in_array( $force_carrier_slug, $valid_slugs, true ) ) {
                    WCMCS_WC_Compat::update_order_meta( $order, '_wcmcs_forced_carrier', $force_carrier_slug );
                    WCMCS_Logger::info( "Order #{$order_id}: transporteur forcé → {$force_carrier_slug}" );
                } else {
                    WCMCS_Logger::warning( "Order #{$order_id}: slug transporteur invalide ignoré → {$force_carrier_slug}" );
                }
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

            // Si en queue (Action Scheduler bloqué), forcer le traitement synchrone
            if ( $current_status === WCMCS_Order_Meta::STATUS_QUEUED ) {
                WCMCS_Logger::warning( "Order #{$order_id}: statut 'queued' détecté — traitement synchrone forcé." );
                // Désplanifier l'action AS en attente pour éviter un DOUBLE traitement
                // (l'action pourrait sinon créer une seconde expédition chez le transporteur).
                WCMCS_WC_Compat::unschedule_action(
                    WCMCS_Queue_Manager::HOOK_SINGLE,
                    [ 'order_id' => $order_id ],
                    'wcmcs'
                );
                WCMCS_Order_Meta::reset( $order );
            }

            // ── Verrou anti-concurrence ATOMIQUE ──
            // add_option() échoue si le verrou existe déjà → deux runners
            // (admin + Action Scheduler) ne peuvent PAS passer tous les deux
            // le check-then-set (l'ancien verrou en meta de commande était
            // TOCTOU). TTL recyclé, libéré uniquement par son propriétaire
            // (jeton) — un runner ne peut plus supprimer le verrou d'un autre.
            $lock_token = self::acquire_lock( $order_id );
            if ( false === $lock_token ) {
                $result['status']  = 'skipped';
                $result['message'] = __( 'Sync déjà en cours pour cette commande (verrou actif).', 'wc-multicarrier-sync' );
                return $result;
            }
            $lock_set = true;

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

            // ── Vérifier le mode de paiement (COD requis) ──
            // Les transporteurs gérés (Colissimo VO, FirstDelivery) travaillent
            // en versement à domicile : les commandes non-COD sont ignorées.
            $payment_method = $order->get_payment_method();
            $cod_methods    = (array) apply_filters( 'wcmcs_cod_payment_methods', [ 'cod' ] );
            if ( ! in_array( $payment_method, $cod_methods, true ) ) {
                $result['status']  = 'skipped';
                $result['message'] = sprintf(
                    /* translators: %s méthode de paiement */
                    __( 'Commande ignorée : paiement non-COD (%s). Seules les commandes payées à la livraison sont envoyées aux transporteurs.', 'wc-multicarrier-sync' ),
                    $payment_method ?: __( 'aucun', 'wc-multicarrier-sync' )
                );
                WCMCS_Logger::info( "Order #{$order_id}: skip — paiement non-COD ({$payment_method})." );
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
                $error_data = $api_result->get_error_data();
                if ( ! empty( $error_data ) && is_array( $error_data ) ) {
                    WCMCS_Logger::error( "Order #{$order_id} API {$provider->get_slug()} error details: " . wp_json_encode( $error_data ) );
                }
                $raw       = $error_data ?? [];
                WCMCS_Order_Meta::mark_failed( $order, $error_msg, $provider->get_slug(), is_array( $raw ) ? $raw : [] );
                $result['message'] = $error_msg;
                WCMCS_Logger::error( "Order #{$order_id} API {$provider->get_slug()} error: {$error_msg}" );
                return $result;
            }

            // ── Succès ─────────────────────────────
            WCMCS_Order_Meta::mark_synced( $order, $api_result, $provider->get_slug() );

            // Nettoyer le transporteur forcé après sync réussie
            WCMCS_WC_Compat::delete_order_meta( $order, '_wcmcs_forced_carrier' );

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
        } finally {
            // Toujours libérer le verrou — uniquement si ce run l'a posé
            // (jeton : un runner ne supprime jamais le verrou d'un autre).
            if ( $lock_set && '' !== $lock_token ) {
                self::release_lock( $order_id, $lock_token );
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────
    //  Verrou anti-concurrence atomique
    // ─────────────────────────────────────────────

    /**
     * TTL du verrou (s) — un create_shipment avec backoff (429 → pause 3 s)
     * et batch de suivi peut dépasser 90 s : 180 s de marge.
     */
    const LOCK_TTL = 180;

    /**
     * Acquiert le verrou de sync d'une commande — ATOMIQUE : add_option()
     * échoue si l'option existe déjà, donc deux runners ne peuvent pas
     * franchir le check-then-set simultanément (l'ancien verrou en meta de
     * commande était TOCTOU — revue de code 2.10.0). Un verrou expiré
     * (TTL) est recyclé.
     *
     * @param int $order_id ID de la commande.
     * @return string|false Jeton unique si acquis, false si verrou actif.
     */
    private static function acquire_lock( int $order_id ) {
        $option = 'wcmcs_lock_sync_' . $order_id;

        // Jeton = horloge microseconde : unique entre runners.
        $token = (string) microtime( true );

        if ( add_option( $option, $token, '', false ) ) {
            return $token;
        }

        // Verrou existant : périmé (TTL dépassé) → recycler (une tentative).
        $existing = (string) get_option( $option, '' );
        if ( '' !== $existing && ( microtime( true ) - (float) $existing ) > self::LOCK_TTL ) {
            delete_option( $option );
            if ( add_option( $option, $token, '', false ) ) {
                WCMCS_Logger::warning( "Order #{$order_id}: verrou de sync périmé (TTL " . self::LOCK_TTL . 's dépassé) recyclé.' );
                return $token;
            }
        }

        return false;
    }

    /**
     * Libère le verrou — UNIQUEMENT par son propriétaire (jeton) : un runner
     * dont le verrou a expiré en vol ne peut pas supprimer celui, frais,
     * posé par un autre runner entre-temps.
     */
    private static function release_lock( int $order_id, string $token ): void {
        $option = 'wcmcs_lock_sync_' . $order_id;
        if ( (string) get_option( $option, '' ) === $token ) {
            delete_option( $option );
        }
    }

    // ─────────────────────────────────────────────
    //  Retry d'une commande échouée
    // ─────────────────────────────────────────────

    /**
     * Réessaye la synchronisation d'une commande.
     *
     * @param int    $order_id            ID de la commande.
     * @param string $force_carrier_slug  Slug de transporteur forcé (dropdown
     *                                    metabox — transmis par l'AJAX retry,
     *                                    persisté par sync_single avant détection).
     * @return array
     */
    public static function retry( int $order_id, string $force_carrier_slug = '' ): array {
        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( $order ) {
            // Désplanifier toute action AS en attente avant le reset
            // (évite une double expédition si la queue traite la commande en parallèle).
            WCMCS_WC_Compat::unschedule_action(
                WCMCS_Queue_Manager::HOOK_SINGLE,
                [ 'order_id' => $order_id ],
                'wcmcs'
            );
            // Reset le statut pour permettre la re-sync
            WCMCS_Order_Meta::reset( $order );
        }

        WCMCS_Logger::info( "Retry commande #{$order_id}." . ( $force_carrier_slug ? " Transporteur forcé : {$force_carrier_slug}." : '' ) );
        return self::sync_single( $order_id, $force_carrier_slug );
    }

    // ─────────────────────────────────────────────
    //  Annulation du colis chez le transporteur
    // ─────────────────────────────────────────────

    /**
     * Annule le colis d'une commande synchronisée chez son transporteur.
     *
     * Garde-fous : commande synchronisée + tracking présent + transporteur
     * supportant l'annulation (matrice CARRIER_FEATURES) + provider configuré.
     * L'annulation est envoyée telle quelle — si le colis n'est plus « en
     * attente » chez le transporteur, son API rejette et le message est
     * retourné tel quel (ex. Adex « colis non supprimable »).
     *
     * En cas de succès : meta reset → pending (re-synchronisable), statut
     * WooCommerce inchangé, note + historique « cancelled » (voir
     * WCMCS_Order_Meta::mark_cancelled()).
     *
     * @param int $order_id ID de la commande.
     * @return array { order_id, status: success|failed, message, tracking_id, carrier }
     */
    public static function cancel_single( int $order_id ): array {
        $result = [
            'order_id'    => $order_id,
            'status'      => 'failed',
            'message'     => '',
            'tracking_id' => '',
            'carrier'     => '',
        ];

        $order = WCMCS_WC_Compat::get_order( $order_id );
        if ( ! $order ) {
            $result['message'] = sprintf( __( 'Commande #%d introuvable.', 'wc-multicarrier-sync' ), $order_id );
            WCMCS_Logger::error( $result['message'] );
            return $result;
        }

        // ── Garde-fous ─────────────────────────
        $status = WCMCS_Order_Meta::get_status( $order );
        if ( WCMCS_Order_Meta::STATUS_SYNCED !== $status ) {
            $result['message'] = __( 'Seule une commande synchronisée peut être annulée chez le transporteur.', 'wc-multicarrier-sync' );
            return $result;
        }

        $tracking_id = WCMCS_Order_Meta::get_tracking_id( $order );
        if ( '' === $tracking_id ) {
            $result['message'] = __( 'Aucun n° de suivi sur cette commande — rien à annuler.', 'wc-multicarrier-sync' );
            return $result;
        }
        $result['tracking_id'] = $tracking_id;

        $carrier_slug = WCMCS_Order_Meta::get_carrier_slug( $order );
        if ( '' === $carrier_slug ) {
            $result['message'] = __( 'Transporteur inconnu sur cette commande.', 'wc-multicarrier-sync' );
            return $result;
        }
        $result['carrier'] = $carrier_slug;

        $features = WCMCS_Carrier_Detector::get_carrier_features( $carrier_slug );
        if ( empty( $features['annulation'] ) ) {
            $result['message'] = sprintf(
                __( '%s ne supporte pas l\'annulation des colis via l\'API. Contactez votre agence.', 'wc-multicarrier-sync' ),
                strtoupper( $carrier_slug )
            );
            return $result;
        }

        $provider = WCMCS_Carrier_Detector::get_provider_by_slug( $carrier_slug );
        if ( ! $provider ) {
            $result['message'] = __( 'Transporteur non disponible pour cette commande.', 'wc-multicarrier-sync' );
            return $result;
        }
        if ( ! $provider->is_configured() ) {
            $result['message'] = __( 'Transporteur non configuré (identifiants manquants).', 'wc-multicarrier-sync' );
            return $result;
        }

        // ── Verrou + désplanification (anti double-expédition) ──
        // Annulation pendant une re-sync en vol : le verrou atomique bloque
        // (sinon mark_cancelled pouvait écraser le tracking d'un NOUVEAU colis
        // créé entre-temps). Retry Action Scheduler en attente : désplanifié —
        // sinon il re-créait un colis pour une commande ANNULÉE (revue 2.10.0).
        $lock_token = self::acquire_lock( $order_id );
        if ( false === $lock_token ) {
            $result['message'] = __( 'Une synchronisation est en cours pour cette commande — réessayez l\'annulation après.', 'wc-multicarrier-sync' );
            return $result;
        }

        try {
            WCMCS_WC_Compat::unschedule_action(
                WCMCS_Queue_Manager::HOOK_SINGLE,
                [ 'order_id' => $order_id ],
                'wcmcs'
            );

            // ── Annulation chez le transporteur ────
            WCMCS_Logger::info( "Order #{$order_id}: annulation du colis {$tracking_id} chez {$carrier_slug}." );

            $cancelled = $provider->cancel_shipment( $tracking_id, $order );

            if ( is_wp_error( $cancelled ) ) {
                $result['message'] = $cancelled->get_error_message();
                WCMCS_Logger::error( "Order #{$order_id}: annulation refusée — " . $result['message'] );
                return $result;
            }

            // ── Succès : reset + trace ─────────────
            WCMCS_Order_Meta::mark_cancelled( $order, $carrier_slug, $tracking_id );

            $result['status']  = 'success';
            $result['message'] = sprintf(
                /* translators: 1: carrier slug, 2: tracking ID */
                __( 'Colis %2$s annulé chez %1$s. La commande est de nouveau synchronisable.', 'wc-multicarrier-sync' ),
                strtoupper( $carrier_slug ),
                $tracking_id
            );
        } finally {
            // Toujours libérer le verrou (jeton propriétaire uniquement).
            self::release_lock( $order_id, $lock_token );
        }

        return $result;
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
