<?php
/**
 * Gestionnaire de licence et période d'essai.
 *
 * Licences gérées via Keygen.sh (API de licensing) :
 *  - Validation par clé + fingerprint (domaine du site, « www. » ignoré)
 *  - 1 site par licence (policy côté Keygen) ; migration de site possible
 *    via le bouton « Désactiver la licence » (libère le slot domaine)
 *  - Revalidation quotidienne (cron) + à la volée sur l'admin (throttle 12 h,
 *    couvre les sites avec DISABLE_WP_CRON)
 *  - Grace period 7 jours si le serveur de licence est injoignable
 *  - Trial local de 3 jours (indépendant de Keygen)
 *
 * Sécurité : aucun secret embarqué — les appels Keygen s'authentifient
 * par la clé de licence du client (Authorization: License <clé>).
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_License {

    // ─────────────────────────────────────────────
    //  Keygen
    // ─────────────────────────────────────────────

    /**
     * Account slug Keygen (information publique).
     * Filtrable via `wcmcs_keygen_account` (ex: tests, autre compte).
     */
    const KEYGEN_ACCOUNT = 'houssem-baklouty';

    /**
     * Base de l'API Keygen — filtrable via `wcmcs_license_api_base`
     * (ex: Keygen CE self-hosted à l'avenir).
     */
    const KEYGEN_API_BASE = 'https://api.keygen.sh/v1/accounts';

    /** Timeout des appels au serveur de licences (secondes). */
    const KEYGEN_TIMEOUT = 15;

    // ─────────────────────────────────────────────
    //  Trial & tolérances
    // ─────────────────────────────────────────────

    const TRIAL_DAYS        = 3;
    const GRACE_PERIOD_DAYS = 7;

    // ─────────────────────────────────────────────
    //  Options
    // ─────────────────────────────────────────────

    const OPTION_ACTIVATED_AT             = 'wcmcs_activated_at';
    const OPTION_LICENSE_KEY              = 'wcmcs_license_key';
    const OPTION_LICENSE_NOTICE_DISMISSED = 'wcmcs_license_notice_dismissed';
    const OPTION_MACHINE_ID               = 'wcmcs_license_machine_id';
    const OPTION_LICENSE_STATUS           = 'wcmcs_license_status';
    const OPTION_LICENSE_LAST_CHECK       = 'wcmcs_license_last_check';
    const OPTION_LICENSE_OFFLINE_SINCE    = 'wcmcs_license_offline_since';
    const OPTION_LICENSE_FINGERPRINT      = 'wcmcs_license_fingerprint';

    // ── Mode gratuit : quota quotidien ──
    const OPTION_DAILY_SYNC_COUNT         = 'wcmcs_daily_sync_count';
    const OPTION_DAILY_SYNC_DATE          = 'wcmcs_daily_sync_date';

    /** Hook cron de revalidation quotidienne. */
    const CRON_HOOK = 'wcmcs_license_revalidate';

    private static $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action( 'wp_ajax_wcmcs_dismiss_license_notice', [ $this, 'ajax_dismiss_notice' ] );
        add_action( self::CRON_HOOK, [ $this, 'revalidate' ] );

        // Compteur du mode gratuit : une sync réussie = un point (tous les modes,
        // mais le check de quota ne s'applique qu'en mode gratuit).
        add_action( 'wcmcs_order_synced', [ $this, 'increment_sync_count' ] );

        // Revalidation à la volée sur l'admin (couvre DISABLE_WP_CRON), throttle 12 h.
        add_action( 'admin_init', [ $this, 'maybe_revalidate_on_admin' ] );

        // Auto-planification du cron (couvre les installations déjà actives).
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    // ─────────────────────────────────────────────
    //  Cycle de vie du plugin
    // ─────────────────────────────────────────────

    public static function activate(): void {
        if ( ! get_option( self::OPTION_ACTIVATED_AT ) ) {
            add_option( self::OPTION_ACTIVATED_AT, current_time( 'timestamp' ), '', 'no' );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::OPTION_LICENSE_NOTICE_DISMISSED );
        // NB : la clé de licence et l'ancrage du trial sont conservés —
        // désactiver le plugin ne libère PAS le domaine. La libération se
        // fait uniquement via le bouton « Désactiver la licence ».
    }

    // ─────────────────────────────────────────────
    //  Trial (local, 3 jours)
    // ─────────────────────────────────────────────

    public function is_trial_active(): bool {
        $activated = get_option( self::OPTION_ACTIVATED_AT );
        if ( ! $activated ) {
            return false;
        }
        $expires = $activated + ( self::TRIAL_DAYS * DAY_IN_SECONDS );
        return current_time( 'timestamp' ) < $expires;
    }

    public function get_trial_remaining(): string {
        $activated = get_option( self::OPTION_ACTIVATED_AT );
        if ( ! $activated ) {
            return __( 'Expiré', 'wc-multicarrier-sync' );
        }
        $expires   = $activated + ( self::TRIAL_DAYS * DAY_IN_SECONDS );
        $remaining = $expires - current_time( 'timestamp' );
        if ( $remaining <= 0 ) {
            return __( 'Expiré', 'wc-multicarrier-sync' );
        }
        $days = (int) ceil( $remaining / DAY_IN_SECONDS );
        if ( $days === 1 ) {
            return sprintf( __( '%d jour restant', 'wc-multicarrier-sync' ), 1 );
        }
        return sprintf( __( '%d jours restants', 'wc-multicarrier-sync' ), $days );
    }

    public function get_trial_expires_date(): string {
        $activated = get_option( self::OPTION_ACTIVATED_AT );
        if ( ! $activated ) {
            return '';
        }
        $expires = $activated + ( self::TRIAL_DAYS * DAY_IN_SECONDS );
        return date_i18n( 'd/m/Y', $expires );
    }

    // ─────────────────────────────────────────────
    //  État global
    // ─────────────────────────────────────────────

    public function is_licensed(): bool {
        $key = get_option( self::OPTION_LICENSE_KEY, '' );
        if ( empty( $key ) ) {
            return false;
        }
        if ( get_option( self::OPTION_LICENSE_STATUS, '' ) !== 'valid' ) {
            return false;
        }
        // Le domaine lié doit correspondre au site courant.
        $bound = get_option( self::OPTION_LICENSE_FINGERPRINT, '' );
        if ( $bound && $bound !== $this->get_site_fingerprint() ) {
            return false;
        }
        // Grace period : blocage après 7 jours d'injoignabilité du serveur.
        $offline_since = (int) get_option( self::OPTION_LICENSE_OFFLINE_SINCE );
        if ( $offline_since && ( time() - $offline_since ) > ( self::GRACE_PERIOD_DAYS * DAY_IN_SECONDS ) ) {
            return false;
        }
        return true;
    }

    public function is_operational(): bool {
        /**
         * Bypass dev/local (défaut false). Ex dans un mu-plugin local :
         * add_filter( 'wcmcs_license_dev_bypass', '__return_true' );
         * Ne jamais activer en production.
         */
        if ( apply_filters( 'wcmcs_license_dev_bypass', false ) ) {
            return true;
        }
        return $this->is_trial_active() || $this->is_licensed();
    }

    // ─────────────────────────────────────────────
    //  Mode gratuit — quota quotidien de syncs
    // ─────────────────────────────────────────────

    /**
     * Limite de syncs réussies par jour en mode gratuit (filtrable).
     */
    public function get_daily_sync_limit(): int {
        return max( 0, (int) apply_filters( 'wcmcs_daily_sync_limit', 3 ) );
    }

    /**
     * Compteur de syncs réussies du jour.
     * Reset paresseux à minuit (heure du site) : pas de cron nécessaire.
     */
    public function get_daily_sync_count(): int {
        $today = current_time( 'Y-m-d' );
        $date  = (string) get_option( self::OPTION_DAILY_SYNC_DATE, '' );
        if ( $date !== $today ) {
            update_option( self::OPTION_DAILY_SYNC_DATE, $today, false );
            update_option( self::OPTION_DAILY_SYNC_COUNT, 0, false );
            return 0;
        }
        return (int) get_option( self::OPTION_DAILY_SYNC_COUNT, 0 );
    }

    /**
     * Syncs restantes aujourd'hui (mode gratuit).
     */
    public function get_remaining_today(): int {
        return max( 0, $this->get_daily_sync_limit() - $this->get_daily_sync_count() );
    }

    /**
     * Quota du jour atteint ? (ne s'applique qu'en mode gratuit ;
     * trial et licence restent illimités)
     */
    public function is_sync_quota_exceeded(): bool {
        if ( $this->is_operational() ) {
            return false;
        }
        return $this->get_remaining_today() <= 0;
    }

    /**
     * Compte une sync réussie (hook wcmcs_order_synced).
     */
    public function increment_sync_count(): void {
        $count = $this->get_daily_sync_count(); // reset lazy inclus
        update_option( self::OPTION_DAILY_SYNC_COUNT, $count + 1, false );
    }

    public function get_license_key(): string {
        return get_option( self::OPTION_LICENSE_KEY, '' );
    }

    /**
     * Retourne l'état détaillé de la licence pour l'UI.
     */
    public function get_license_info(): array {
        return [
            'key'          => get_option( self::OPTION_LICENSE_KEY, '' ),
            'status'       => get_option( self::OPTION_LICENSE_STATUS, '' ),
            'status_label' => $this->get_status_label( get_option( self::OPTION_LICENSE_STATUS, '' ) ),
            'fingerprint'  => get_option( self::OPTION_LICENSE_FINGERPRINT, '' ),
            'machine_id'   => get_option( self::OPTION_MACHINE_ID, '' ),
            'last_check'   => (int) get_option( self::OPTION_LICENSE_LAST_CHECK ),
            'offline_since' => (int) get_option( self::OPTION_LICENSE_OFFLINE_SINCE ),
            'trial_active'  => $this->is_trial_active(),
            'trial_remaining' => $this->get_trial_remaining(),
            'trial_expires' => $this->get_trial_expires_date(),
            'free_mode'     => ! $this->is_operational(),
            'daily_count'   => $this->get_daily_sync_count(),
            'daily_limit'   => $this->get_daily_sync_limit(),
            'daily_remaining' => $this->get_remaining_today(),
        ];
    }

    // ─────────────────────────────────────────────
    //  Activation / Désactivation
    // ─────────────────────────────────────────────

    /**
     * Active une licence : validation Keygen + liaison du domaine (machine).
     *
     * @param string $key Clé de licence.
     * @return true|WP_Error
     */
    public function activate_license( string $key ) {
        $key = trim( $key );
        if ( empty( $key ) ) {
            return new WP_Error( 'wcmcs_empty_key', __( 'Veuillez entrer une clé de licence.', 'wc-multicarrier-sync' ) );
        }
        if ( empty( $this->keygen_account() ) ) {
            return new WP_Error( 'wcmcs_no_account', __( 'Serveur de licences non configuré (account Keygen manquant).', 'wc-multicarrier-sync' ) );
        }

        $fingerprint = $this->get_site_fingerprint();
        if ( empty( $fingerprint ) ) {
            return new WP_Error( 'wcmcs_no_domain', __( 'Impossible de déterminer le domaine du site.', 'wc-multicarrier-sync' ) );
        }

        // ── 1. Valider la clé ─────────────────────
        $validation = $this->keygen_request(
            '/licenses/actions/validate-key',
            'POST',
            [ 'meta' => [ 'key' => $key ] ]
        );

        if ( is_wp_error( $validation ) ) {
            return new WP_Error(
                'wcmcs_license_network',
                __( 'Impossible de joindre le serveur de licences (réseau). Vérifiez la connexion et réessayez.', 'wc-multicarrier-sync' )
            );
        }

        if ( (int) $validation['code'] >= 400 || empty( $validation['data']['meta']['valid'] ) ) {
            return new WP_Error( 'wcmcs_invalid_key', $this->map_validation_error( $validation ) );
        }

        // ID licence requis pour la création du machine (relation JSON:API).
        $license_id = (string) ( $validation['data']['data']['id'] ?? '' );

        // ── 2. Machines existantes (idempotence sur le même domaine) ──
        $machines = $this->keygen_request( '/machines', 'GET', [], $key );
        $machine_id = '';

        if ( ! is_wp_error( $machines ) && (int) $machines['code'] < 400 ) {
            foreach ( (array) ( $machines['data']['data'] ?? [] ) as $machine ) {
                $machine_fp = strtolower( (string) ( $machine['attributes']['fingerprint'] ?? '' ) );
                if ( $machine_fp === $fingerprint ) {
                    $machine_id = (string) ( $machine['id'] ?? '' );
                    break;
                }
            }
            if ( ! $machine_id ) {
                // Licence déjà liée à un autre domaine ?
                $other = (array) ( $machines['data']['data'] ?? [] );
                if ( ! empty( $other ) ) {
                    $other_fp = $other[0]['attributes']['fingerprint'] ?? '';
                    return new WP_Error(
                        'wcmcs_domain_taken',
                        sprintf(
                            __( 'Cette licence est déjà utilisée sur le domaine « %s ». Désactivez-la depuis ce site (migration) ou contactez le support.', 'wc-multicarrier-sync' ),
                            $other_fp ?: __( 'un autre site', 'wc-multicarrier-sync' )
                        )
                    );
                }
            }
        }

        // ── 3. Créer le machine (liaison du domaine) ──
        if ( ! $machine_id ) {
            $created = $this->keygen_request( '/machines', 'POST', [
                'data' => [
                    'type'       => 'machine',
                    'attributes' => [
                        'fingerprint' => $fingerprint,
                        'metadata'    => [
                            'site'     => home_url(),
                            'wp'       => get_bloginfo( 'version' ),
                            'plugin'   => WCMCS_VERSION,
                        ],
                    ],
                    'relationships' => [
                        'license' => [ 'data' => [ 'type' => 'license', 'id' => $license_id ] ],
                    ],
                ],
            ], $key );

            if ( is_wp_error( $created ) ) {
                return new WP_Error(
                    'wcmcs_license_network',
                    __( 'Impossible de joindre le serveur de licences (réseau). Vérifiez la connexion et réessayez.', 'wc-multicarrier-sync' )
                );
            }
            if ( (int) $created['code'] >= 400 || empty( $created['data']['data']['id'] ) ) {
                return new WP_Error( 'wcmcs_activation_failed', $this->map_validation_error( $created ) );
            }
            $machine_id = (string) $created['data']['data']['id'];
        }

        // ── 4. Persister l'état ────────────────────
        update_option( self::OPTION_LICENSE_KEY, sanitize_text_field( $key ) );
        update_option( self::OPTION_MACHINE_ID, sanitize_text_field( $machine_id ) );
        update_option( self::OPTION_LICENSE_STATUS, 'valid' );
        update_option( self::OPTION_LICENSE_FINGERPRINT, $fingerprint );
        update_option( self::OPTION_LICENSE_LAST_CHECK, time() );
        delete_option( self::OPTION_LICENSE_OFFLINE_SINCE );

        WCMCS_Logger::info( sprintf( 'Licence activée — domaine %s (machine %s).', $fingerprint, $machine_id ) );

        return true;
    }

    /**
     * Désactive la licence : libère le slot domaine côté Keygen
     * (permet la migration vers un autre site) et purge l'état local.
     */
    public function deactivate_license(): void {
        $key        = get_option( self::OPTION_LICENSE_KEY, '' );
        $machine_id = get_option( self::OPTION_MACHINE_ID, '' );

        if ( $key && $machine_id ) {
            $deleted = $this->keygen_request(
                '/machines/' . rawurlencode( $machine_id ),
                'DELETE',
                [],
                $key
            );
            if ( is_wp_error( $deleted ) ) {
                WCMCS_Logger::warning( 'Libération du domaine impossible (réseau) : ' . $deleted->get_error_message() );
            } elseif ( (int) $deleted['code'] >= 400 ) {
                WCMCS_Logger::warning( sprintf( 'Libération du domaine refusée par le serveur (HTTP %d).', $deleted['code'] ) );
            } else {
                WCMCS_Logger::info( sprintf( 'Licence désactivée — domaine libéré (machine %s).', $machine_id ) );
            }
        }

        delete_option( self::OPTION_LICENSE_KEY );
        delete_option( self::OPTION_MACHINE_ID );
        delete_option( self::OPTION_LICENSE_STATUS );
        delete_option( self::OPTION_LICENSE_LAST_CHECK );
        delete_option( self::OPTION_LICENSE_OFFLINE_SINCE );
        delete_option( self::OPTION_LICENSE_FINGERPRINT );
        update_option( self::OPTION_LICENSE_NOTICE_DISMISSED, 'no' );
    }

    // ─────────────────────────────────────────────
    //  Revalidation
    // ─────────────────────────────────────────────

    /**
     * Revalidation quotidienne : vérifie clé + domaine auprès de Keygen.
     * Réseau injoignable → cache conservé + grace period 7 jours.
     */
    public function revalidate(): void {
        $key = get_option( self::OPTION_LICENSE_KEY, '' );
        if ( empty( $key ) ) {
            return;
        }

        $fingerprint = $this->get_site_fingerprint();

        $result = $this->keygen_request( '/licenses/actions/validate-key', 'POST', [
            'meta' => [
                'key'   => $key,
                'scope' => [ 'fingerprint' => $fingerprint ],
            ],
        ] );

        update_option( self::OPTION_LICENSE_LAST_CHECK, time() );

        if ( is_wp_error( $result ) ) {
            // Réseau : déclencher la grace period (si pas déjà active).
            if ( empty( (int) get_option( self::OPTION_LICENSE_OFFLINE_SINCE ) ) ) {
                update_option( self::OPTION_LICENSE_OFFLINE_SINCE, time() );
            }
            WCMCS_Logger::warning( 'Revalidation licence impossible (réseau) — grace period enclenchée.' );
            return;
        }

        delete_option( self::OPTION_LICENSE_OFFLINE_SINCE );

        $valid = ! empty( $result['data']['meta']['valid'] );
        $code  = (string) ( $result['data']['meta']['code'] ?? '' );

        if ( $valid ) {
            update_option( self::OPTION_LICENSE_STATUS, 'valid' );
            WCMCS_Logger::info( 'Revalidation licence : OK (' . $code . ').' );
        } else {
            update_option( self::OPTION_LICENSE_STATUS, $code ?: 'invalid' );
            WCMCS_Logger::warning( sprintf( 'Revalidation licence : %s (%s).', $this->map_validation_error( $result ), $code ) );
        }
    }

    /**
     * Revalidation à la volée sur l'admin — throttle 12 h
     * (couvre les sites où WP-Cron est désactivé).
     */
    public function maybe_revalidate_on_admin(): void {
        if ( empty( get_option( self::OPTION_LICENSE_KEY ) ) ) {
            return;
        }
        $last = (int) get_option( self::OPTION_LICENSE_LAST_CHECK );
        if ( $last && ( time() - $last ) < 12 * HOUR_IN_SECONDS ) {
            return;
        }
        $this->revalidate();
    }

    // ─────────────────────────────────────────────
    //  HTTP Keygen
    // ─────────────────────────────────────────────

    private function keygen_account(): string {
        return trim( (string) apply_filters( 'wcmcs_keygen_account', self::KEYGEN_ACCOUNT ) );
    }

    /**
     * Appel à l'API Keygen.
     *
     * @param string $endpoint Chemin relatif (ex: '/licenses/actions/validate-key').
     * @param string $method   GET|POST|DELETE.
     * @param array  $body     Corps JSON.
     * @param string $key      Clé de licence (authentification).
     * @return array|WP_Error  ['code' => int, 'data' => array] ou WP_Error réseau.
     */
    private function keygen_request( string $endpoint, string $method = 'POST', array $body = [], string $key = '' ) {
        $account = $this->keygen_account();
        $base    = trim( (string) apply_filters( 'wcmcs_license_api_base', self::KEYGEN_API_BASE ) );
        $url     = rtrim( $base, '/' ) . '/' . rawurlencode( $account ) . $endpoint;

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => self::KEYGEN_TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];
        if ( ! empty( $key ) ) {
            $args['headers']['Authorization'] = 'License ' . $key;
        }
        if ( ! empty( $body ) && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        return [
            'code' => (int) wp_remote_retrieve_response_code( $response ),
            'data' => is_array( $data ) ? $data : [],
        ];
    }

    /**
     * Fingerprint du site : domaine en minuscules, « www. » ignoré.
     */
    public function get_site_fingerprint(): string {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host ) {
            return '';
        }
        return strtolower( preg_replace( '/^www\./', '', $host ) );
    }

    // ─────────────────────────────────────────────
    //  Messages
    // ─────────────────────────────────────────────

    /**
     * Mappe une erreur/réponse Keygen en message français lisible.
     */
    private function map_validation_error( array $result ): string {
        $meta   = $result['data']['meta'] ?? [];
        $code   = (string) ( $meta['code'] ?? '' );
        $err    = (array) ( $result['data']['errors'] ?? [] );
        $title  = (string) ( $err[0]['code'] ?? '' );
        $detail = (string) ( $meta['detail'] ?? ( $err[0]['detail'] ?? '' ) );

        // Keygen renvoie NOT_FOUND pour l'account ET pour la clé : distinguer via le détail.
        if ( ( $code ?: $title ) === 'NOT_FOUND' && stripos( $detail, 'account' ) !== false ) {
            return __( 'Serveur de licences non configuré (account Keygen inconnu).', 'wc-multicarrier-sync' );
        }

        switch ( $code ?: $title ) {
            case 'ACCOUNT_NOT_FOUND':
                return __( 'Serveur de licences non configuré (account Keygen inconnu).', 'wc-multicarrier-sync' );
            case 'NOT_FOUND':
            case 'LICENSE_NOT_FOUND':
                return __( 'Clé de licence inconnue. Vérifiez la clé ou contactez le support.', 'wc-multicarrier-sync' );
            case 'SUSPENDED':
                return __( 'Licence suspendue. Contactez le support.', 'wc-multicarrier-sync' );
            case 'REVOKED':
                return __( 'Licence révoquée. Contactez le support.', 'wc-multicarrier-sync' );
            case 'EXPIRED':
                return __( 'Licence expirée. Contactez le support pour la renouveler.', 'wc-multicarrier-sync' );
            case 'FINGERPRINT_SCOPE_MISMATCH':
            case 'FINGERPRINT_NOT_FOUND':
                return __( 'Cette licence n\'est pas liée à ce domaine. Désactivez-la depuis le site d\'origine ou contactez le support.', 'wc-multicarrier-sync' );
            case 'TOO_MANY_MACHINES':
                return __( 'Cette licence est déjà utilisée sur un autre domaine. Désactivez-la depuis ce site ou contactez le support.', 'wc-multicarrier-sync' );
            default:
                if ( $detail ) {
                    return sprintf( __( 'Licence refusée par le serveur : %s', 'wc-multicarrier-sync' ), $detail );
                }
                return __( 'Clé de licence refusée par le serveur.', 'wc-multicarrier-sync' );
        }
    }

    /**
     * Libellé français d'un statut de licence.
     */
    public function get_status_label( string $status ): string {
        switch ( strtoupper( $status ) ) {
            case 'VALID':
                return __( 'Active (à vie)', 'wc-multicarrier-sync' );
            case 'SUSPENDED':
                return __( 'Suspendue', 'wc-multicarrier-sync' );
            case 'REVOKED':
                return __( 'Révoquée', 'wc-multicarrier-sync' );
            case 'EXPIRED':
                return __( 'Expirée', 'wc-multicarrier-sync' );
            case 'FINGERPRINT_SCOPE_MISMATCH':
                return __( 'Liée à un autre domaine', 'wc-multicarrier-sync' );
            case '':
                return __( 'Aucune', 'wc-multicarrier-sync' );
            default:
                return sprintf( __( 'Statut : %s', 'wc-multicarrier-sync' ), $status );
        }
    }

    /**
     * Message d'erreur bloquant une sync, selon le contexte.
     * (NB : depuis la v1.3.0, le mode gratuit ne bloque plus jamais
     * définitivement — seul le quota du jour peut écarter une sync.)
     */
    public function get_block_error(): WP_Error {
        $status = strtoupper( (string) get_option( self::OPTION_LICENSE_STATUS, '' ) );

        if ( $status === 'SUSPENDED' ) {
            $msg = __( 'Licence suspendue. Contactez le support.', 'wc-multicarrier-sync' );
        } elseif ( $status === 'REVOKED' ) {
            $msg = __( 'Licence révoquée. Contactez le support.', 'wc-multicarrier-sync' );
        } elseif ( $status === 'EXPIRED' ) {
            $msg = __( 'Licence expirée. Contactez le support pour la renouveler.', 'wc-multicarrier-sync' );
        } else {
            $msg = sprintf(
                /* translators: 1: syncs du jour, 2: limite quotidienne */
                __( 'Limite gratuite atteinte (%1$d/%2$d syncs aujourd\'hui). Activez votre licence pour synchroniser sans limite.', 'wc-multicarrier-sync' ),
                $this->get_daily_sync_count(),
                $this->get_daily_sync_limit()
            );
        }

        return new WP_Error( 'wcmcs_sync_not_allowed', $msg );
    }

    // ─────────────────────────────────────────────
    //  Notices admin
    // ─────────────────────────────────────────────

    public function admin_notices(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'woocommerce_page_wcmcs-settings' ) {
            return;
        }
        if ( $this->is_licensed() ) {
            if ( get_option( self::OPTION_LICENSE_NOTICE_DISMISSED ) === 'yes' ) {
                return;
            }
            $dismiss_url = add_query_arg(
                [ 'action' => 'wcmcs_dismiss_license_notice', '_wpnonce' => wp_create_nonce( 'wcmcs_dismiss_notice' ) ],
                admin_url( 'admin-ajax.php' )
            );
            printf(
                '<div class="notice notice-success is-dismissible wcmcs-license-notice">' .
                '<p><span class="dashicons dashicons-yes-alt" style="color:#28a745"></span> ' .
                '<strong>MultiCarrier Sync TN :</strong> %s. ' .
                '<a href="%s">%s</a></p></div>',
                esc_html__( 'Licence activée avec succès', 'wc-multicarrier-sync' ),
                esc_url( admin_url( 'admin.php?page=wcmcs-settings&tab=license' ) ),
                esc_html__( 'Détails', 'wc-multicarrier-sync' )
            );
            ?>
            <script>
            jQuery('.wcmcs-license-notice .notice-dismiss').on('click', function(){
                jQuery.post('<?php echo esc_url( $dismiss_url ); ?>');
            });
            </script>
            <?php
            return;
        }
        if ( ! $this->is_trial_active() && ! $this->is_licensed() ) {
            // Mode gratuit permanent : quota quotidien, aucune fonctionnalité retirée.
            printf(
                '<div class="notice notice-info wcmcs-license-notice">' .
                '<p><span class="dashicons dashicons-unlock" style="color:#0073aa"></span> ' .
                '<strong>MultiCarrier Sync TN :</strong> %s. ' .
                '<a href="%s" class="button button-primary" style="margin-left:8px">%s</a></p></div>',
                esc_html( sprintf(
                    /* translators: %d nombre de syncs par jour */
                    __( 'mode gratuit actif — %d synchronisations par jour', 'wc-multicarrier-sync' ),
                    $this->get_daily_sync_limit()
                ) ),
                esc_url( admin_url( 'admin.php?page=wcmcs-settings&tab=license' ) ),
                esc_html__( 'Passer à l\'illimité', 'wc-multicarrier-sync' )
            );
            return;
        }
        $remaining = $this->get_trial_remaining();
        printf(
            '<div class="notice notice-info wcmcs-license-notice">' .
            '<p><span class="dashicons dashicons-clock" style="color:#0073aa"></span> ' .
            '<strong>MultiCarrier Sync TN :</strong> ' .
            esc_html__( 'Période d\'essai gratuite active', 'wc-multicarrier-sync' ) .
            ' — <strong>%s</strong>. ' .
            '<a href="%s">%s</a></p></div>',
            esc_html( $remaining ),
            esc_url( admin_url( 'admin.php?page=wcmcs-settings&tab=license' ) ),
            esc_html__( 'Activez votre licence', 'wc-multicarrier-sync' )
        );
    }

    public function ajax_dismiss_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die();
        }
        check_ajax_referer( 'wcmcs_dismiss_notice', '_wpnonce' );
        update_option( self::OPTION_LICENSE_NOTICE_DISMISSED, 'yes' );
        wp_die();
    }
}
