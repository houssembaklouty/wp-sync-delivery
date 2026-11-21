<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contacts client tracés (appel, WhatsApp, SMS) — endpoints AJAX.
 */
class CODCRM_Contacts {

	const META_LAST_CONTACT = '_codcrm_last_contact_at';

	public static function init(): void {
		add_action( 'wp_ajax_codcrm_log_contact', [ __CLASS__, 'ajax_log_contact' ] );
		add_action( 'wp_ajax_codcrm_complete_task', [ __CLASS__, 'ajax_complete_task' ] );
	}

	/** Remplace {client} {commande} {suivi} {transporteur} {montant} dans un modèle. */
	public static function render_template( WC_Order $order, string $template ): string {
		$phone = (string) get_option( 'codcrm_shop_phone', '' );
		if ( '' === $phone ) {
			// Téléphone non renseigné : on retire le placeholder ET l'espace qui le précède.
			$template = (string) preg_replace( '/[ \t]*\{telephone_boutique\}/u', '', $template );
		}
		return strtr( $template, [
			'{client}'             => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'{commande}'           => $order->get_order_number(),
			'{suivi}'              => (string) $order->get_meta( '_wcmcs_tracking_id' ),
			'{transporteur}'       => (string) $order->get_meta( '_wcmcs_carrier_slug' ),
			'{montant}'            => wc_format_decimal( $order->get_total(), 2 ),
			'{boutique}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{telephone_boutique}' => $phone,
			'{date}'               => wp_date( 'd/m/Y' ),
		] );
	}

	/** @return array<string,string> résultat d'appel => libellé */
	public static function call_outcomes(): array {
		return [
			'joint_confirme'      => __( 'Joint — livraison confirmée', 'cod-tracker-crm' ),
			'injoignable'         => __( 'Injoignable / pas de réponse', 'cod-tracker-crm' ),
			'reporte'             => __( 'Reporté (rappeler plus tard)', 'cod-tracker-crm' ),
			'refus'               => __( 'Refuse la commande', 'cod-tracker-crm' ),
			'faux_numero'         => __( 'Faux numéro', 'cod-tracker-crm' ),
			'annulation_demandee' => __( 'Demande l\'annulation', 'cod-tracker-crm' ),
		];
	}

	public static function outcome_label( string $channel, string $outcome ): string {
		if ( 'call' === $channel ) {
			$all = self::call_outcomes();
			return $all[ $outcome ] ?? $outcome;
		}
		return 'envoye' === $outcome ? __( 'Envoyé', 'cod-tracker-crm' ) : $outcome;
	}

	public static function channel_label( string $channel ): string {
		$map = [
			'call'     => __( 'Appel', 'cod-tracker-crm' ),
			'whatsapp' => 'WhatsApp',
			'sms'      => 'SMS',
		];
		return $map[ $channel ] ?? $channel;
	}

	public static function ajax_log_contact(): void {
		check_ajax_referer( 'codcrm', 'nonce' );

		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}

		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Commande introuvable ou non assignée à vous.', 'cod-tracker-crm' ) ], 403 );
		}

		$channel = sanitize_key( wp_unslash( $_POST['channel'] ?? '' ) );
		if ( ! in_array( $channel, [ 'call', 'whatsapp', 'sms' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Canal invalide.', 'cod-tracker-crm' ) ] );
		}

		$outcome = sanitize_key( wp_unslash( $_POST['outcome'] ?? '' ) );
		if ( 'call' === $channel ) {
			if ( ! isset( self::call_outcomes()[ $outcome ] ) ) {
				wp_send_json_error( [ 'message' => __( 'Le résultat de l\'appel est obligatoire.', 'cod-tracker-crm' ) ] );
			}
		} else {
			$outcome = 'envoye';
		}

		$note = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 255 );

		// Message envoyé depuis la bibliothèque de modèles : on garde la situation et la langue.
		$msg_ctx  = sanitize_key( wp_unslash( $_POST['context'] ?? '' ) );
		$msg_lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$payload  = null;
		if ( isset( CODCRM_Templates::contexts()[ $msg_ctx ] ) && in_array( $msg_lang, CODCRM_Templates::LANGS, true ) ) {
			$payload = [ 'context' => $msg_ctx, 'lang' => $msg_lang ];
			if ( '' === $note ) {
				$note = sprintf( '%s (%s)', CODCRM_Templates::context_label( $msg_ctx ), strtoupper( $msg_lang ) );
			}
		}

		// Heure de rappel (champ datetime-local, heure du site).
		$callback_at = '';
		if ( 'reporte' === $outcome ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['callback_at'] ?? '' ) );
			$dt  = DateTime::createFromFormat( 'Y-m-d\TH:i', $raw, wp_timezone() );
			if ( ! $dt ) {
				wp_send_json_error( [ 'message' => __( 'Indiquez la date et l\'heure du rappel.', 'cod-tracker-crm' ) ] );
			}
			$callback_at = $dt->format( 'Y-m-d H:i:s' );
		}

		$user_id = get_current_user_id();
		$now     = current_time( 'mysql' );

		CODCRM_Events::add( $order->get_id(), [
			'type'     => 'contact',
			'channel'  => $channel,
			'outcome'  => $outcome,
			'note'     => $note,
			'actor_id' => $user_id,
			'source'   => 'agent',
			'payload'  => $payload,
		] );

		$order->update_meta_data( self::META_LAST_CONTACT, $now );
		$order->save_meta_data();

		$agent = CODCRM_Agents::agent_of( $order ) ?: $user_id;

		if ( 'call' === $channel ) {
			if ( 'reporte' === $outcome ) {
				CODCRM_Tasks::create( $order->get_id(), $agent, CODCRM_Tasks::KIND_CALLBACK, $callback_at, $note, $user_id );
			} elseif ( 'injoignable' === $outcome ) {
				$due = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + 2 * HOUR_IN_SECONDS );
				CODCRM_Tasks::create( $order->get_id(), $agent, CODCRM_Tasks::KIND_CALLBACK, $due, __( 'Réessayer (injoignable)', 'cod-tracker-crm' ), $user_id );
			} else {
				// Client joint / décision prise : les rappels en attente sont traités.
				CODCRM_Tasks::complete_open( $order->get_id(), [ CODCRM_Tasks::KIND_CALLBACK, CODCRM_Tasks::KIND_FOLLOWUP ], $user_id );
			}
		}

		do_action( 'codcrm_contact_logged', $order, $channel, $outcome, $user_id );

		wp_send_json_success( [ 'message' => __( 'Contact enregistré.', 'cod-tracker-crm' ) ] );
	}

	public static function ajax_complete_task(): void {
		check_ajax_referer( 'codcrm', 'nonce' );

		if ( ! current_user_can( CODCRM_Agents::CAP_WORK ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'cod-tracker-crm' ) ], 403 );
		}

		global $wpdb;
		$task_id = absint( $_POST['task_id'] ?? 0 );
		$table   = CODCRM_Installer::table_tasks();
		$task    = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE id = %d", $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$order   = $task ? wc_get_order( (int) $task->order_id ) : false;

		if ( ! $order || ! CODCRM_Agents::can_handle( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'Tâche introuvable ou non autorisée.', 'cod-tracker-crm' ) ], 403 );
		}

		CODCRM_Tasks::complete( $task_id, get_current_user_id() );
		wp_send_json_success();
	}
}
