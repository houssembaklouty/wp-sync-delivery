<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scanner QR / code-barres : « Emballée » et « Remis au transporteur ».
 *
 * Même principe que le scan de ShipFlow : chaque code scanné est VÉRIFIÉ sans
 * rien modifier (OK / refus + message), la liste s'accumule, puis « Valider »
 * applique le lot (résultat par ligne).
 */
class CODCRM_Scanner {

	const PAGE = 'codcrm-scanner';

	const MODE_PACK   = 'pack';
	const MODE_RETURN = 'return';

	const MAX_BATCH = 200;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_codcrm_scan_check', [ __CLASS__, 'ajax_check' ] );
		add_action( 'wp_ajax_codcrm_scan_apply', [ __CLASS__, 'ajax_apply' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			CODCRM_Work_Queue::PAGE_QUEUE,
			__( 'Scanner', 'cod-tracker-crm' ),
			__( 'Scanner', 'cod-tracker-crm' ),
			CODCRM_Agents::CAP_WORK,
			self::PAGE,
			[ __CLASS__, 'render' ]
		);
	}

	private static function asset( string $rel ): string {
		return CODCRM_URL . $rel . '?v=' . CODCRM_VERSION . '-' . (int) @filemtime( CODCRM_DIR . $rel );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'codcrm-scanner', self::asset( 'assets/css/scanner.css' ), [], null );
		wp_enqueue_script( 'codcrm-h5q', self::asset( 'assets/vendor/html5-qrcode.min.js' ), [], null, true );
		wp_enqueue_script( 'codcrm-scanner', self::asset( 'assets/js/scanner.js' ), [ 'jquery', 'codcrm-h5q' ], null, true );
		wp_localize_script( 'codcrm-scanner', 'codcrmScan', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'codcrm_scan' ),
			'max'   => self::MAX_BATCH,
			'i18n'  => [
				'dup'       => __( 'Déjà scanné dans cette liste.', 'cod-tracker-crm' ),
				'net'       => __( 'Erreur réseau.', 'cod-tracker-crm' ),
				'none'      => __( 'Aucun colis valide à valider.', 'cod-tracker-crm' ),
				'full'      => __( 'Liste pleine (200 maximum) : validez d\'abord.', 'cod-tracker-crm' ),
				'cam_on'    => __( '📷 Activer la caméra', 'cod-tracker-crm' ),
				'cam_off'   => __( '⏹ Arrêter la caméra', 'cod-tracker-crm' ),
				'cam_err'   => __( 'Caméra indisponible (HTTPS ou localhost requis, et autorisation de la caméra).', 'cod-tracker-crm' ),
				'ok_word'   => __( 'prêts', 'cod-tracker-crm' ),
				'ko_word'   => __( 'refusés', 'cod-tracker-crm' ),
				'done'      => __( 'Fait', 'cod-tracker-crm' ),
				'validated' => __( 'validés', 'cod-tracker-crm' ),
			],
		] );
	}

	public static function render(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ) );
		}
		?>
		<div class="wrap codcrm-scan">
			<h1><?php esc_html_e( 'Scanner les colis', 'cod-tracker-crm' ); ?></h1>

			<div class="codcrm-scan-modes">
				<label><input type="radio" name="codcrm_mode" value="pack" checked /> 📦 <?php esc_html_e( 'Emballée', 'cod-tracker-crm' ); ?></label>
				<label><input type="radio" name="codcrm_mode" value="return" /> ↩ <?php esc_html_e( 'Retour reçu', 'cod-tracker-crm' ); ?></label>
			</div>

			<div class="codcrm-scan-input">
				<input type="text" id="codcrm-code" autocomplete="off" autofocus
					placeholder="<?php esc_attr_e( 'Scanner ou saisir le code-barres du colis puis Entrée…', 'cod-tracker-crm' ); ?>" />
				<button type="button" class="button" id="codcrm-cam"></button>
			</div>

			<div id="codcrm-reader" style="display:none"></div>

			<p class="codcrm-scan-summary"><span id="codcrm-sum"></span></p>

			<table class="widefat striped" id="codcrm-list">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'cod-tracker-crm' ); ?></th>
					<th><?php esc_html_e( 'Commande', 'cod-tracker-crm' ); ?></th>
					<th><?php esc_html_e( 'Client', 'cod-tracker-crm' ); ?></th>
					<th><?php esc_html_e( 'Vérification', 'cod-tracker-crm' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody></tbody>
			</table>

			<p>
				<button type="button" class="button button-primary button-hero" id="codcrm-apply"><?php esc_html_e( 'Valider la liste', 'cod-tracker-crm' ); ?></button>
				<button type="button" class="button" id="codcrm-clear"><?php esc_html_e( 'Vider', 'cod-tracker-crm' ); ?></button>
			</p>
		</div>
		<?php
	}

	// ── Résolution d'un code ───────────────────────────────────

	/**
	 * Code scanné → commande. Priorité : code-barres de l'étiquette transporteur
	 * (_wcmcs_tracking_id), sinon n° de commande (codes courts uniquement).
	 */
	public static function resolve( string $code ): ?WC_Order {
		$code = trim( $code );
		if ( '' === $code || strlen( $code ) > 64 ) {
			return null;
		}

		$found = wc_get_orders( [
			'limit'      => 1,
			'type'       => 'shop_order',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_key'   => '_wcmcs_tracking_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		if ( $found && $found[0] instanceof WC_Order ) {
			return $found[0];
		}

		// Un n° de commande est court (≤ 9 chiffres) ; un code transporteur est plus long.
		if ( ctype_digit( $code ) && strlen( $code ) <= 9 ) {
			$order = wc_get_order( (int) $code );
			if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Vérification d'une commande pour un mode (rien n'est modifié).
	 *
	 * @return array{ok:bool,msg:string,warn:bool}
	 */
	public static function check( WC_Order $order, string $mode ): array {
		$status = $order->get_status(); // sans « wc- ».

		if ( ! $order->get_meta( '_wcmcs_tracking_id' ) ) {
			return [ 'ok' => false, 'warn' => false, 'msg' => __( 'Colis non créé chez le transporteur (pas de code-barres).', 'cod-tracker-crm' ) ];
		}

		if ( self::MODE_PACK === $mode ) {
			if ( 'wcmcs-synced' === $status ) {
				return [ 'ok' => true, 'warn' => false, 'msg' => __( 'Prêt à marquer « Emballée ».', 'cod-tracker-crm' ) ];
			}
			if ( 'wcmcs-packed' === $status ) {
				return [ 'ok' => false, 'warn' => false, 'msg' => __( 'Déjà emballée.', 'cod-tracker-crm' ) ];
			}
			return [ 'ok' => false, 'warn' => false, 'msg' => sprintf( /* translators: %s statut */ __( 'Statut « %s » : seules les commandes « Synchronisée » peuvent être emballées.', 'cod-tracker-crm' ), wc_get_order_status_name( $status ) ) ];
		}

		// Retour reçu : le colis retourné est revenu physiquement à la boutique.
		if ( CODCRM_Returns::is_received( $order ) ) {
			return [ 'ok' => false, 'warn' => false, 'msg' => __( 'Retour déjà reçu.', 'cod-tracker-crm' ) ];
		}
		if ( CODCRM_Returns::is_delivered( $order ) ) {
			return [ 'ok' => false, 'warn' => false, 'msg' => __( 'Colis livré au client : ce n\'est pas un retour.', 'cod-tracker-crm' ) ];
		}
		if ( CODCRM_Returns::is_in_return( $order ) ) {
			return [ 'ok' => true, 'warn' => false, 'msg' => __( 'Retour déclaré par le transporteur — prêt à réceptionner (remise en stock).', 'cod-tracker-crm' ) ];
		}
		// Le colis est physiquement là : on l'accepte, mais on le signale.
		return [ 'ok' => true, 'warn' => true, 'msg' => __( '⚠ Retour pas encore déclaré par le transporteur — réception enregistrée quand même.', 'cod-tracker-crm' ) ];
	}

	// ── AJAX ───────────────────────────────────────────────────

	private static function guard(): void {
		check_ajax_referer( 'codcrm_scan', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}
	}

	private static function mode_from_request(): string {
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		if ( ! in_array( $mode, [ self::MODE_PACK, self::MODE_RETURN ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Mode invalide.', 'cod-tracker-crm' ) ] );
		}
		return $mode;
	}

	/** Vérification d'un code (dry-run). */
	public static function ajax_check(): void {
		self::guard();
		$mode  = self::mode_from_request();
		$code  = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$order = self::resolve( $code );

		if ( ! $order ) {
			wp_send_json_success( [ 'ok' => false, 'warn' => false, 'order_id' => 0, 'number' => '', 'client' => '', 'msg' => __( 'Code inconnu.', 'cod-tracker-crm' ) ] );
		}

		$res = self::check( $order, $mode );
		wp_send_json_success( $res + [
			'order_id' => $order->get_id(),
			'number'   => $order->get_order_number(),
			'client'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		] );
	}

	/** Application d'un lot (les vérifications sont refaites). */
	public static function ajax_apply(): void {
		self::guard();
		$mode = self::mode_from_request();
		$ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ?? [] ) ) ) ) );

		if ( ! $ids || count( $ids ) > self::MAX_BATCH ) {
			wp_send_json_error( [ 'message' => __( 'Lot invalide.', 'cod-tracker-crm' ) ] );
		}

		$user    = wp_get_current_user();
		$results = [];

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$results[ $id ] = [ 'status' => 'REFUSED', 'msg' => __( 'Commande introuvable.', 'cod-tracker-crm' ) ];
				continue;
			}

			$chk = self::check( $order, $mode );
			if ( ! $chk['ok'] ) {
				$results[ $id ] = [ 'status' => 'REFUSED', 'msg' => $chk['msg'] ];
				continue;
			}

			if ( self::MODE_PACK === $mode ) {
				$order->update_status(
					'wcmcs-packed',
					sprintf( /* translators: %s utilisateur */ __( '[COD] Emballée par scan — %s.', 'cod-tracker-crm' ), $user->display_name )
				);
			} else {
				$res = CODCRM_Returns::receive( $order, (int) $user->ID, 'scan' );
				if ( ! $res['ok'] ) {
					$results[ $id ] = [ 'status' => 'REFUSED', 'msg' => $res['msg'] ];
					continue;
				}
				$results[ $id ] = [ 'status' => 'DONE', 'msg' => $res['restocked'] ? sprintf( _n( 'Reçu — %d article remis en stock', 'Reçu — %d articles remis en stock', $res['restocked'], 'cod-tracker-crm' ), $res['restocked'] ) : __( 'Reçu', 'cod-tracker-crm' ) ];
				continue;
			}

			$results[ $id ] = [ 'status' => 'DONE', 'msg' => __( 'Fait', 'cod-tracker-crm' ) ];
		}

		wp_send_json_success( [ 'results' => $results ] );
	}
}
