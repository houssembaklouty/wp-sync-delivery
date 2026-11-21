<?php
defined( 'ABSPATH' ) || exit;

/**
 * Passerelle SMS HTTP générique (configurable pour n'importe quel fournisseur)
 * + envoi manuel et automatique tracés.
 *
 * Configuration (options) :
 *  codcrm_sms_enabled   yes|no
 *  codcrm_sms_url       URL du fournisseur (peut contenir {to} {message} {sender} {api_key})
 *  codcrm_sms_method    GET|POST
 *  codcrm_sms_format    form|json   (encodage du corps POST)
 *  codcrm_sms_params    un paramètre par ligne « clé=valeur » (placeholders acceptés)
 *  codcrm_sms_headers   un en-tête par ligne « Nom: valeur » (placeholders acceptés)
 *  codcrm_sms_api_key   clé / token (jamais journalisé)
 *  codcrm_sms_sender    nom d'expéditeur
 *  codcrm_sms_phone     format du numéro : 216 | +216 | local
 *  codcrm_sms_success   texte attendu dans la réponse (vide = HTTP 2xx suffit)
 *  codcrm_sms_auto      déclencheurs automatiques actifs (array)
 *  codcrm_sms_templates modèles par déclencheur (array)
 */
class CODCRM_SMS_Gateway {

	const TRIGGER_OUT_FOR_DELIVERY = 'out_for_delivery';
	const TRIGGER_ATTEMPT_FAILED   = 'attempt_failed';
	const TRIGGER_RESCHEDULED      = 'rescheduled';

	public static function init(): void {
		add_action( 'wp_ajax_codcrm_send_sms', [ __CLASS__, 'ajax_send' ] );
		add_action( 'admin_post_codcrm_sms_test', [ __CLASS__, 'handle_test' ] );

		add_action( 'codcrm_stage_changed', [ __CLASS__, 'on_stage_changed' ], 10, 3 );
		add_action( 'codcrm_attempt_failed', [ __CLASS__, 'on_attempt_failed' ], 10, 3 );
	}

	// ── Configuration ──────────────────────────────────────────

	public static function is_configured(): bool {
		return 'yes' === get_option( 'codcrm_sms_enabled', 'no' ) && '' !== trim( (string) get_option( 'codcrm_sms_url', '' ) );
	}

	/** @return array<string,string> déclencheur => libellé */
	public static function triggers(): array {
		return [
			self::TRIGGER_OUT_FOR_DELIVERY => __( 'Colis en cours de livraison', 'cod-tracker-crm' ),
			self::TRIGGER_ATTEMPT_FAILED   => __( 'Tentative de livraison échouée', 'cod-tracker-crm' ),
			self::TRIGGER_RESCHEDULED      => __( 'Livraison reportée / rendez-vous', 'cod-tracker-crm' ),
		];
	}

	/** Contexte de la bibliothèque de modèles utilisé par un déclencheur automatique. */
	public static function trigger_context( string $trigger ): string {
		$map = [
			self::TRIGGER_OUT_FOR_DELIVERY => 'out_for_delivery',
			self::TRIGGER_ATTEMPT_FAILED   => 'attempt_failed',
			self::TRIGGER_RESCHEDULED      => 'rescheduled',
		];
		return (string) apply_filters( 'codcrm_sms_trigger_context', $map[ $trigger ] ?? '', $trigger );
	}

	public static function auto_enabled( string $trigger ): bool {
		$auto = get_option( 'codcrm_sms_auto', [] );
		return is_array( $auto ) && in_array( $trigger, $auto, true );
	}

	/** Numéro 8 chiffres → format attendu par le fournisseur. */
	private static function format_phone( string $phone8 ): string {
		// Comparaison stricte : avec switch/==, '216' == '+216' en PHP (chaînes numériques).
		$format = (string) get_option( 'codcrm_sms_phone', '216' );
		if ( '+216' === $format ) {
			return '+216' . $phone8;
		}
		if ( 'local' === $format ) {
			return $phone8;
		}
		return '216' . $phone8;
	}

	// ── Envoi ──────────────────────────────────────────────────

	/**
	 * Envoie un SMS via le fournisseur configuré.
	 *
	 * @param string $phone Numéro (n'importe quel format tunisien).
	 * @return array{ok:bool,message:string}
	 */
	public static function send( string $phone, string $message ): array {
		if ( ! self::is_configured() ) {
			return [ 'ok' => false, 'message' => __( 'Passerelle SMS non configurée.', 'cod-tracker-crm' ) ];
		}

		$phone8 = CODCRM_Work_Queue::norm_phone( $phone );
		if ( '' === $phone8 ) {
			return [ 'ok' => false, 'message' => __( 'Numéro invalide (8 chiffres attendus).', 'cod-tracker-crm' ) ];
		}
		$message = trim( $message );
		if ( '' === $message ) {
			return [ 'ok' => false, 'message' => __( 'Message vide.', 'cod-tracker-crm' ) ];
		}

		$method = 'GET' === strtoupper( (string) get_option( 'codcrm_sms_method', 'POST' ) ) ? 'GET' : 'POST';
		$vars   = [
			'{to}'      => self::format_phone( $phone8 ),
			'{message}' => $message,
			'{sender}'  => (string) get_option( 'codcrm_sms_sender', '' ),
			'{api_key}' => (string) get_option( 'codcrm_sms_api_key', '' ),
		];

		// Paramètres « clé=valeur » (une par ligne).
		$params = [];
		foreach ( preg_split( '/\R/', (string) get_option( 'codcrm_sms_params', '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			[ $k, $v ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$params[ $k ] = strtr( $v, $vars );
		}

		// En-têtes « Nom: valeur ».
		$headers = [];
		foreach ( preg_split( '/\R/', (string) get_option( 'codcrm_sms_headers', '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			[ $k, $v ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$headers[ $k ] = strtr( $v, $vars );
		}

		// Placeholders dans l'URL (valeurs encodées).
		$enc = [];
		foreach ( $vars as $k => $v ) {
			$enc[ $k ] = rawurlencode( $v );
		}
		$url = strtr( trim( (string) get_option( 'codcrm_sms_url', '' ) ), $enc );

		$args = [ 'method' => $method, 'timeout' => 15, 'headers' => $headers ];
		if ( 'GET' === $method ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		} elseif ( 'json' === get_option( 'codcrm_sms_format', 'form' ) ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $params, JSON_UNESCAPED_UNICODE );
		} else {
			$args['body'] = $params;
		}

		// wp_safe_* : refuse les adresses privées / locales (SSRF) — l'URL est saisie en admin.
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$need = trim( (string) get_option( 'codcrm_sms_success', '' ) );

		$ok = $code >= 200 && $code < 300 && ( '' === $need || false !== stripos( $body, $need ) );

		// Le corps de réponse peut refléter le token ou le numéro : jamais journalisé tel quel.
		return [
			'ok'      => $ok,
			'message' => $ok ? __( 'SMS envoyé.', 'cod-tracker-crm' ) : sprintf( /* translators: %d code HTTP */ __( 'Refusé par le fournisseur (HTTP %d).', 'cod-tracker-crm' ), $code ),
		];
	}

	/** Envoi lié à une commande : trace l'événement et le dernier contact. */
	public static function send_for_order( WC_Order $order, string $message, string $source, int $actor_id = 0, string $dedupe_key = '', array $meta = [] ): array {
		$res = self::send( $order->get_billing_phone(), $message );

		CODCRM_Events::add( $order->get_id(), [
			'type'       => 'contact',
			'channel'    => 'sms',
			'outcome'    => $res['ok'] ? 'envoye' : 'echec',
			'note'       => $res['ok'] ? mb_substr( $message, 0, 255 ) : mb_substr( $res['message'], 0, 255 ),
			'actor_id'   => $actor_id,
			'source'     => $source,
			'dedupe_key' => '' !== $dedupe_key ? $dedupe_key : null,
			'payload'    => $meta ?: null,
		] );

		if ( $res['ok'] ) {
			$order->update_meta_data( CODCRM_Contacts::META_LAST_CONTACT, current_time( 'mysql' ) );
			$order->save_meta_data();
		}
		return $res;
	}

	// ── Déclencheurs automatiques ──────────────────────────────

	public static function on_stage_changed( $order, $old, $new ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( CODCRM_Stages::EN_COURS_LIVRAISON === $new ) {
			self::auto_send( $order, self::TRIGGER_OUT_FOR_DELIVERY );
		} elseif ( CODCRM_Stages::RENDEZ_VOUS === $new ) {
			self::auto_send( $order, self::TRIGGER_RESCHEDULED );
		}
	}

	public static function on_attempt_failed( $order, $failed, $at_risk ): void {
		if ( $order instanceof WC_Order ) {
			self::auto_send( $order, self::TRIGGER_ATTEMPT_FAILED, (int) $failed );
		}
	}

	/** Un seul SMS automatique par déclencheur et par tournée / échec. */
	private static function auto_send( WC_Order $order, string $trigger, int $n = 0 ): void {
		if ( ! self::is_configured() || ! self::auto_enabled( $trigger ) ) {
			return;
		}
		if ( in_array( $order->get_status(), [ 'completed', 'cancelled', 'refunded', 'failed', 'wcmcs-delivered', 'wcmcs-returned' ], true ) ) {
			return;
		}

		$n   = $n ?: (int) $order->get_meta( CODCRM_Attempts::META_ATTEMPTS );
		$key = md5( 'sms-auto|' . $trigger . '|' . $n );
		if ( CODCRM_Events::exists( $order->get_id(), $key ) ) {
			return;
		}

		// Le modèle suit la langue du client (arabe / français) ; texte éditable dans Réglages → Messages.
		$ctx  = self::trigger_context( $trigger );
		$lang = CODCRM_Templates::detect_lang( $order );
		$text = CODCRM_Templates::message( $order, $ctx, $lang, 'sms' );
		if ( '' === $text ) {
			return;
		}
		self::send_for_order( $order, $text, 'sms', 0, $key, [ 'context' => $ctx, 'lang' => $lang, 'auto' => true ] );
	}

	// ── Manuel (AJAX) + test ───────────────────────────────────

	public static function ajax_send(): void {
		check_ajax_referer( 'codcrm', 'nonce' );
		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}

		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Commande introuvable ou non assignée à vous.', 'cod-tracker-crm' ) ], 403 );
		}

		$text = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) ), 0, 480 );

		$msg_ctx  = sanitize_key( wp_unslash( $_POST['context'] ?? '' ) );
		$msg_lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$meta     = isset( CODCRM_Templates::contexts()[ $msg_ctx ] ) && in_array( $msg_lang, CODCRM_Templates::LANGS, true )
			? [ 'context' => $msg_ctx, 'lang' => $msg_lang ]
			: [];

		$res = self::send_for_order( $order, CODCRM_Contacts::render_template( $order, $text ), 'agent', get_current_user_id(), '', $meta );

		if ( $res['ok'] ) {
			wp_send_json_success( [ 'message' => $res['message'] ] );
		}
		wp_send_json_error( [ 'message' => $res['message'] ] );
	}

	public static function handle_test(): void {
		if ( ! current_user_can( CODCRM_Agents::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'cod-tracker-crm' ), 403 );
		}
		check_admin_referer( 'codcrm_sms_test' );

		$res = self::send( sanitize_text_field( wp_unslash( $_POST['test_phone'] ?? '' ) ), __( 'Test COD Tracker CRM : la passerelle SMS fonctionne.', 'cod-tracker-crm' ) );

		wp_safe_redirect( add_query_arg( [
			'page'     => CODCRM_Work_Queue::PAGE_SETTINGS,
			'tab'      => 'sms',
			'sms_test' => $res['ok'] ? 'ok' : rawurlencode( $res['message'] ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}
