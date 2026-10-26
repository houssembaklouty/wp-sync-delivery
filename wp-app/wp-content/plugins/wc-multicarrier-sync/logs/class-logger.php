<?php
/**
 * Système de logs unifié.
 *
 * Utilise WC_Logger (WC_Log_Handler_File) si disponible,
 * sinon fallback sur error_log PHP + fichier custom.
 *
 * Niveaux : debug | info | warning | error
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Logger {

    const SOURCE = 'wc-multicarrier-sync';
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /** @var WC_Logger|null */
    private static ?WC_Logger $wc_logger = null;

    /** @var string Chemin du fichier log custom */
    private static string $custom_log_file = '';

    /** @var bool|null Debug mode */
    private static ?bool $debug_mode = null;

    // ─────────────────────────────────────────────
    //  Initialisation
    // ─────────────────────────────────────────────

    private static function get_wc_logger(): ?WC_Logger {
        if ( self::$wc_logger === null ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                self::$wc_logger = wc_get_logger();
            }
        }
        return self::$wc_logger;
    }

    private static function is_debug_mode(): bool {
        if ( self::$debug_mode === null ) {
            self::$debug_mode = ( get_option( 'wcmcs_debug_logs', 'no' ) === 'yes' )
                || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
        }
        return self::$debug_mode;
    }

    private static function get_custom_log_file(): string {
        if ( empty( self::$custom_log_file ) ) {
            $upload_dir = wp_upload_dir();
            $log_dir    = $upload_dir['basedir'] . '/wcmcs-logs/';
            if ( ! file_exists( $log_dir ) ) {
                wp_mkdir_p( $log_dir );
                // Protéger contre accès direct
                file_put_contents( $log_dir . '.htaccess', 'deny from all' );
                file_put_contents( $log_dir . 'index.php', '<?php // silence' );
            }
            self::$custom_log_file = $log_dir . 'sync-' . gmdate( 'Y-m-d' ) . '.log';
        }
        return self::$custom_log_file;
    }

    // ─────────────────────────────────────────────
    //  Protection PII
    // ─────────────────────────────────────────────

    /**
     * Clés considérées comme données personnelles / sensibles (RGPD) dans
     * les payloads — tous les namings observés chez les 11 transporteurs :
     * FR (client, nom, tel, prix…), EN (name, phone, address, city…),
     * INTIGO (ClientName, ClientPhone…), Aramex (PersonName, EmailAddress,
     * Line1…), Fiabilo (token !) et variantes.
     */
    const PII_KEYS = [
        // FR — Colissimo / FirstDelivery / Jetpack / Fiabilo / Navex / Adex / AFEX
        'client', 'nom', 'adresse', 'ville', 'prix', 'msg', 'commentaire',
        'tel', 'tel1', 'tel2', 'telephone', 'telephone2', 'cp',
        // EN / Shipper / Ciblex / AFEX
        'name', 'phone', 'phone1', 'phone2', 'address', 'address1', 'address2',
        'city', 'price', 'client_name',
        // INTIGO
        'clientname', 'clientphone', 'subdivision', 'dropoff', 'pickup',
        'additionalinfo', 'descproduit', 'amount',
        // Aramex
        'personname', 'emailaddress', 'line1', 'line2', 'line3',
        // Identifiants (Fiabilo token en body !)
        'token', 'apikey', 'api_key', 'password', 'accountpin', 'authorization',
    ];

    /**
     * Masque les données personnelles d'un payload avant logging debug.
     *
     * @param array $payload Payload à nettoyer (supporte l'imbrication).
     * @return array
     */
    public static function redact_pii( array $payload ): array {
        foreach ( $payload as $key => $value ) {
            if ( is_array( $value ) ) {
                $payload[ $key ] = self::redact_pii( $value );
                continue;
            }
            if ( in_array( strtolower( (string) $key ), self::PII_KEYS, true )
                && $value !== '' && $value !== null ) {
                $payload[ $key ] = '[REDACTED]';
            }
        }
        return $payload;
    }

    /**
     * Redacte un corps de réponse API brut avant log debug : le JSON est
     * décodé → redacté → ré-encodé ; un corps non-JSON n'est JAMAIS loggé
     * tel quel (seuls le code HTTP et la taille sont conservés — un HTML/CDATA
     * de réponse peut contenir les coordonnées du destinataire).
     *
     * @param string $raw_body   Corps brut.
     * @param int    $max_length Troncature (0 = sans limite).
     * @return string Corps redacté (ou résumé si non-JSON).
     */
    public static function redact_body( string $raw_body, int $max_length = 1000 ): string {
        $decoded = json_decode( $raw_body, true );
        if ( is_array( $decoded ) ) {
            $redacted = wp_json_encode( self::redact_pii( $decoded ), JSON_UNESCAPED_UNICODE );
            return substr( (string) $redacted, 0, $max_length );
        }
        // Non-JSON (HTML d'erreur, XML SOAP…) : ne pas logger le contenu
        // potentiellement personnel — taille seule.
        return sprintf( '[non-JSON — %d octets masqués]', strlen( $raw_body ) );
    }

    // ─────────────────────────────────────────────
    //  Méthodes publiques de log
    // ─────────────────────────────────────────────

    public static function debug( string $message, array $context = [] ): void {
        if ( ! self::is_debug_mode() ) {
            return;
        }
        self::log( self::LEVEL_DEBUG, $message, $context );
    }

    public static function info( string $message, array $context = [] ): void {
        self::log( self::LEVEL_INFO, $message, $context );
    }

    public static function warning( string $message, array $context = [] ): void {
        self::log( self::LEVEL_WARNING, $message, $context );
    }

    public static function error( string $message, array $context = [] ): void {
        self::log( self::LEVEL_ERROR, $message, $context );
    }

    // ─────────────────────────────────────────────
    //  Core
    // ─────────────────────────────────────────────

    /**
     * Enregistre un message de log.
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     */
    private static function log( string $level, string $message, array $context = [] ): void {
        // Préparer le message
        $full_message = self::format( $message, $context );

        // ── WC_Logger ─────────────────────────────
        $wc_logger = self::get_wc_logger();
        if ( $wc_logger ) {
            $wc_logger->log(
                $level,
                $full_message,
                [ 'source' => self::SOURCE ]
            );
        } else {
            // ── Fallback custom log ────────────────
            self::write_custom_log( $level, $full_message );
        }

        // Toujours aussi dans error_log pour les erreurs critiques
        if ( $level === self::LEVEL_ERROR ) {
            error_log( '[WCMCS][ERROR] ' . $full_message );
        }
    }

    /**
     * Formate le message avec contexte.
     *
     * @param string $message
     * @param array  $context
     * @return string
     */
    private static function format( string $message, array $context ): string {
        if ( empty( $context ) ) {
            return $message;
        }
        // Remplacement simple : {key} → value
        $replace = [];
        foreach ( $context as $key => $val ) {
            $replace[ '{' . $key . '}' ] = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );
        }
        return strtr( $message, $replace );
    }

    /**
     * Écrit dans le fichier log custom.
     *
     * @param string $level
     * @param string $message
     */
    private static function write_custom_log( string $level, string $message ): void {
        try {
            $log_file = self::get_custom_log_file();
            $line     = sprintf(
                '[%s] [%s] %s' . PHP_EOL,
                gmdate( 'Y-m-d H:i:s' ),
                strtoupper( $level ),
                $message
            );
            file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
        } catch ( \Throwable $e ) {
            error_log( '[WCMCS] Erreur écriture log custom : ' . $e->getMessage() );
        }
    }

    // ─────────────────────────────────────────────
    //  Nettoyage des logs (rotation)
    // ─────────────────────────────────────────────

    /**
     * Supprime les fichiers log de plus de N jours.
     *
     * @param int $days  Default 30 jours.
     */
    public static function cleanup_old_logs( int $days = 30 ): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/wcmcs-logs/';

        if ( ! is_dir( $log_dir ) ) {
            return;
        }

        $files     = glob( $log_dir . '*.log' );
        $threshold = time() - ( $days * DAY_IN_SECONDS );

        foreach ( (array) $files as $file ) {
            if ( filemtime( $file ) < $threshold ) {
                wp_delete_file( $file );
            }
        }
    }

    /**
     * Retourne les dernières lignes du log courant.
     *
     * @param int $lines Nombre de lignes.
     * @return string
     */
    public static function get_recent_logs( int $lines = 100 ): string {
        // Essayer via WC_Logger d'abord
        $wc_log_dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : ( WP_CONTENT_DIR . '/uploads/wc-logs/' );
        $wc_files   = glob( $wc_log_dir . self::SOURCE . '-*.log' );

        $log_file = '';
        if ( ! empty( $wc_files ) ) {
            $log_file = end( $wc_files );
        } else {
            $log_file = self::get_custom_log_file();
        }

        if ( ! file_exists( $log_file ) ) {
            return __( 'Aucun log disponible.', 'wc-multicarrier-sync' );
        }

        // Lire les dernières N lignes efficacement
        $all_lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! $all_lines ) {
            return '';
        }

        $recent = array_slice( $all_lines, -$lines );
        return implode( PHP_EOL, array_reverse( $recent ) );
    }
}
