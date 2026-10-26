<?php
/**
 * Notificateur email — alerte le développeur en cas de problème de sync.
 *
 * Hook sur `wcmcs_order_failed` (chaque échec de sync) + `wcmcs_bulk_sync_complete` (résumé).
 * Throttling anti-spam : max 1 email par période configurable (défaut 15 min).
 *
 * @package WC_MultiCarrier_Sync
 */

defined( 'ABSPATH' ) || exit;

class WCMCS_Email_Notifier {

	/** Option on/off. */
	const OPTION_ENABLED = 'wcmcs_email_alerts';

	/** Option email du dev. */
	const OPTION_DEV_EMAIL = 'wcmcs_email_dev';

	/** Option throttle (minutes entre 2 emails). */
	const OPTION_THROTTLE_MIN = 'wcmcs_email_throttle';

	/** Option transient key (throttle). */
	const TRANSIENT_THROTTLE = 'wcmcs_email_last_sent';

	/** Option transient key (digest — erreurs collectées). */
	const TRANSIENT_DIGEST = 'wcmcs_email_digest';

	/** Option : récupérer une notification quand une commande échoue puis réussit (recovery). */
	const OPTION_RECOVERY = 'wcmcs_email_recovery';

	public function __construct() {
		add_action( 'wcmcs_order_failed', [ $this, 'on_order_failed' ], 10, 3 );
		add_action( 'wcmcs_bulk_sync_complete', [ $this, 'on_bulk_complete' ], 10, 1 );
		add_action( 'wcmcs_order_synced', [ $this, 'on_order_synced_recovery' ], 10, 3 );
	}

	/**
	 * Emails activés ? (défaut : oui)
	 */
	public static function is_enabled(): bool {
		return get_option( self::OPTION_ENABLED, 'yes' ) === 'yes';
	}

	/**
	 * Email du dev (défaut : houssem.baklouty@gmail.com).
	 */
	public static function get_dev_email(): string {
		$email = get_option( self::OPTION_DEV_EMAIL, 'houssem.baklouty@gmail.com' );
		return is_email( $email ) ? $email : 'houssem.baklouty@gmail.com';
	}

	/**
	 * Throttle en minutes (défaut : 15).
	 */
	private static function get_throttle_minutes(): int {
		return max( 5, (int) get_option( self::OPTION_THROTTLE_MIN, 15 ) );
	}

	// ─────────────────────────────────────────────
	//  Hooks
	// ─────────────────────────────────────────────

	/**
	 * Hook : une commande vient d'échouer → collecter pour digest.
	 */
	public function on_order_failed( $order, string $error_message, string $carrier_slug ): void {
		if ( ! self::is_enabled() || ! $order instanceof WC_Order ) {
			return;
		}

		// Collecter dans le digest (batch anti-spam).
		$digest = get_transient( self::TRANSIENT_DIGEST );
		if ( ! is_array( $digest ) ) {
			$digest = [];
		}

		$digest[ $order->get_id() ] = [
			'order_id'   => $order->get_id(),
			'carrier'    => $carrier_slug,
			'error'      => $error_message,
			'total'      => $order->get_total(),
			'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'phone'      => $order->get_billing_phone(),
		];

		set_transient( self::TRANSIENT_DIGEST, $digest, HOUR_IN_SECONDS );

		// Envoyer immédiatement si throttle écoulé.
		$this->maybe_send_digest();
	}

	/**
	 * Hook : bulk sync terminée → envoyer le digest immédiatement (résumé).
	 */
	public function on_bulk_complete( array $report ): void {
		if ( ! self::is_enabled() || empty( $report['failed'] ) ) {
			return;
		}

		// Le digest est envoyé directement après un bulk (pas d'attente throttle).
		$this->send_digest_now();
	}

	/**
	 * Hook : commande réussie → envoyer un recovery email si elle avait échoué avant.
	 */
	public function on_order_synced_recovery( $order, array $result, string $carrier_slug ): void {
		if ( ! self::is_enabled() || ! $order instanceof WC_Order ) {
			return;
		}
		if ( get_option( self::OPTION_RECOVERY, 'yes' ) !== 'yes' ) {
			return;
		}

		// Seulement si cette commande avait échoué avant (retry réussi).
		$digest = get_transient( self::TRANSIENT_DIGEST );
		if ( ! is_array( $digest ) || ! isset( $digest[ $order->get_id() ] ) ) {
			return;
		}

		$subject = sprintf( '[%s] ✓ Commande #%d récupérée — %s', get_bloginfo( 'name' ), $order->get_id(), $carrier_slug );
		$body = sprintf(
			"La commande #%d qui avait échoué vient de réussir via %s.\n\n" .
			"Tracking : %s\n" .
			"Voir : %s\n\n— %s (MultiCarrier Sync TN)",
			$order->get_id(),
			$carrier_slug,
			$result['tracking_id'] ?? 'N/A',
			admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			get_bloginfo( 'name' )
		);

		wp_mail( self::get_dev_email(), $subject, $body );
	}

	// ─────────────────────────────────────────────
	//  Envoi (throttle + digest)
	// ─────────────────────────────────────────────

	/**
	 * Envoie le digest si le throttle est écoulé.
	 */
	private function maybe_send_digest(): void {
		$last_sent = (int) get_transient( self::TRANSIENT_THROTTLE );
		$throttle_sec = self::get_throttle_minutes() * MINUTE_IN_SECONDS;

		if ( $last_sent && ( time() - $last_sent ) < $throttle_sec ) {
			return; // Throttle actif.
		}

		$this->send_digest_now();
	}

	/**
	 * Envoie le digest immédiatement.
	 */
	private function send_digest_now(): void {
		$digest = get_transient( self::TRANSIENT_DIGEST );
		if ( ! is_array( $digest ) || empty( $digest ) ) {
			return;
		}

		$subject = sprintf( '[%s] ⚠ %d commande(s) échouée(s) — MultiCarrier Sync TN', get_bloginfo( 'name' ), count( $digest ) );

		$body = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333">';
		$body .= '<h2 style="color:#dc3545;margin:0 0 16px">⚠ ' . count( $digest ) . ' commande(s) échouée(s)</h2>';
		$body .= '<p style="color:#666;font-size:12px">' . esc_html( get_bloginfo( 'name' ) ) . ' — ' . esc_html( home_url() ) . '</p>';
		$body .= '<table style="border-collapse:collapse;width:100%;margin-top:16px" border="1" cellpadding="8" cellspacing="0">';
		$body .= '<tr style="background:#f8d7da;font-weight:bold"><th>Commande</th><th>Transporteur</th><th>Erreur</th><th>Client</th><th>Montant</th><th>Voir</th></tr>';

		foreach ( array_slice( $digest, 0, 50 ) as $row ) {
			$body .= sprintf(
				'<tr style="%s"><td>#%d</td><td>%s</td><td style="color:#dc3545;font-size:12px">%s</td><td>%s</td><td>%s DT</td><td><a href="%s">→</a></td></tr>',
				$row['order_id'] % 2 === 0 ? 'background:#fafafa' : '',
				$row['order_id'],
				esc_html( strtoupper( $row['carrier'] ?: '—' ) ),
				esc_html( mb_strimwidth( $row['error'], 0, 80, '…' ) ),
				esc_html( $row['customer'] ),
				esc_html( $row['total'] ),
				esc_url( admin_url( 'post.php?post=' . $row['order_id'] . '&action=edit' ) )
			);
		}

		$body .= '</table>';
		$body .= '<p style="margin-top:16px;font-size:12px;color:#999">' . sprintf( __( '%d commande(s) affichée(s) sur %d.', 'wc-multicarrier-sync' ), min( 50, count( $digest ) ), count( $digest ) ) . '</p>';
		$body .= '<p style="margin-top:8px;font-size:12px;color:#999">— MultiCarrier Sync TN v' . WCMCS_VERSION . '</p>';
		$body .= '</body></html>';

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( self::get_dev_email(), $subject, $body, $headers );

		if ( $sent ) {
			delete_transient( self::TRANSIENT_DIGEST );
			set_transient( self::TRANSIENT_THROTTLE, time(), self::get_throttle_minutes() * MINUTE_IN_SECONDS );
			WCMCS_Logger::info( sprintf( 'Email alerte envoyé à %s (%d échecs).', self::get_dev_email(), count( $digest ) ) );
		} else {
			WCMCS_Logger::error( sprintf( 'Échec envoi email alerte à %s.', self::get_dev_email() ) );
		}
	}
}
