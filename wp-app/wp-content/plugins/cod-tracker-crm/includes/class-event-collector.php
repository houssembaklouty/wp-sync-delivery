<?php
defined( 'ABSPATH' ) || exit;

/**
 * Alimente le journal depuis WooCommerce et MultiCarrier Sync.
 */
class CODCRM_Event_Collector {

	const META_STAGE       = '_codcrm_stage';
	const META_STAGE_SINCE = '_codcrm_stage_since';

	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 4 );
		add_action( 'wcmcs_order_synced', [ __CLASS__, 'on_order_synced' ], 10, 3 );
		add_action( 'wcmcs_order_cancelled', [ __CLASS__, 'on_order_cancelled' ], 10, 2 );
		add_action( 'wcmcs_tracking_state_changed', [ __CLASS__, 'on_tracking_state_changed' ], 10, 6 );
	}

	/** Acteur courant : utilisateur connecté, sinon système. */
	private static function actor(): array {
		$uid = get_current_user_id();
		return [ $uid, $uid ? 'agent' : 'system' ];
	}

	public static function on_order_status_changed( $order_id, $from, $to, $order ): void {
		[ $uid, $source ] = self::actor();
		$stage = CODCRM_Stage_Mapper::from_order_status( (string) $to );

		CODCRM_Events::add( (int) $order_id, [
			'type'     => 'status',
			'stage'    => $stage,
			'note'     => sprintf( '%s → %s', wc_get_order_status_name( $from ), wc_get_order_status_name( $to ) ),
			'actor_id' => $uid,
			'source'   => $source,
			'payload'  => [ 'from' => $from, 'to' => $to ],
		] );

		if ( '' !== $stage && $order instanceof WC_Order ) {
			self::set_current_stage( $order, $stage );
		}
	}

	public static function on_order_synced( $order, $result, $carrier_slug ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		[ $uid, $source ] = self::actor();
		CODCRM_Events::add( $order->get_id(), [
			'type'         => 'carrier_sync',
			'stage'        => CODCRM_Stages::SYNCHRONISEE,
			'carrier_slug' => (string) $carrier_slug,
			'note'         => sprintf( __( 'Colis créé — suivi %s', 'cod-tracker-crm' ), (string) ( $result['tracking_id'] ?? '' ) ),
			'actor_id'     => $uid,
			'source'       => $source,
			'dedupe_key'   => md5( 'sync|' . ( $result['tracking_id'] ?? '' ) ),
		] );
	}

	public static function on_order_cancelled( $order, $carrier_slug = '' ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		[ $uid, $source ] = self::actor();
		CODCRM_Events::add( $order->get_id(), [
			'type'         => 'carrier_sync',
			'stage'        => CODCRM_Stages::ANNULEE,
			'carrier_slug' => (string) $carrier_slug,
			'note'         => __( 'Colis annulé chez le transporteur', 'cod-tracker-crm' ),
			'actor_id'     => $uid,
			'source'       => $source,
		] );
	}

	/**
	 * Changement d'état transporteur (hook MCS ≥ 2.10.5).
	 *
	 * @param WC_Order $order    Commande.
	 * @param string   $slug     Transporteur.
	 * @param string   $old      Ancien état.
	 * @param string   $new      Nouvel état.
	 * @param string   $category Catégorie MCS (unknown|transit|delivered|returned).
	 * @param array    $status   Résultat du suivi (raw.history optionnel).
	 */
	public static function on_tracking_state_changed( $order, $slug, $old, $new, $category, $status ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::record_carrier_state( $order, (string) $slug, (string) $new, is_array( $status ) ? $status : [], true );
	}

	/**
	 * Enregistre l'état transporteur (+ historique daté s'il est fourni).
	 * Réutilisé par le backfill.
	 *
	 * @param bool $live True pour un vrai changement d'état détecté maintenant ;
	 *                   false pour un import (backfill) : aucune notification client.
	 */
	public static function record_carrier_state( WC_Order $order, string $slug, string $state, array $status, bool $live = false ): void {
		$history = isset( $status['raw']['history'] ) && is_array( $status['raw']['history'] ) ? $status['raw']['history'] : [];

		if ( $history ) {
			foreach ( $history as $h ) {
				$label = CODCRM_Stage_Mapper::fix_mojibake( (string) ( $h['state'] ?? '' ) );
				$at    = (string) ( $h['at'] ?? '' );
				if ( '' === $label || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at ) ) {
					continue;
				}
				CODCRM_Events::add( $order->get_id(), [
					'type'          => 'carrier_state',
					'stage'         => CODCRM_Stage_Mapper::from_carrier_state( $label ) ?: CODCRM_Stages::INCONNU,
					'carrier_slug'  => $slug,
					'carrier_state' => $label,
					'note'          => (string) ( $h['note'] ?? '' ),
					'source'        => 'carrier_poll',
					'dedupe_key'    => md5( $slug . '|' . $label . '|' . $at ),
					'occurred_at'   => $at . ':00',
				] );
			}
		} elseif ( '' !== trim( $state ) ) {
			$label = CODCRM_Stage_Mapper::fix_mojibake( $state );
			CODCRM_Events::add( $order->get_id(), [
				'type'          => 'carrier_state',
				'stage'         => CODCRM_Stage_Mapper::from_carrier_state( $label ) ?: CODCRM_Stages::INCONNU,
				'carrier_slug'  => $slug,
				'carrier_state' => $label,
				'source'        => 'carrier_poll',
				// Pas d'horodatage transporteur : une seule fois par état et par jour.
				'dedupe_key'    => md5( $slug . '|' . $label . '|' . current_time( 'Y-m-d' ) ),
			] );
		}

		// Étape courante = dernière étape transporteur connue du journal.
		$old_stage = (string) $order->get_meta( self::META_STAGE );
		$last      = CODCRM_Events::last_carrier_event( $order->get_id() );
		$new_stage = $last ? (string) $last->stage : '';
		if ( $new_stage ) {
			// « Depuis » = date réelle de l'événement transporteur (pas l'heure du traitement).
			self::set_current_stage( $order, $new_stage, (string) $last->occurred_at );
		}

		CODCRM_Attempts::refresh( $order, $live );

		if ( $live && $new_stage && $new_stage !== $old_stage ) {
			/**
			 * L'étape transporteur d'une commande vient de changer (suivi en direct).
			 *
			 * @param WC_Order $order     Commande.
			 * @param string   $old_stage Ancienne étape.
			 * @param string   $new_stage Nouvelle étape.
			 */
			do_action( 'codcrm_stage_changed', $order, $old_stage, $new_stage );
		}
	}

	public static function set_current_stage( WC_Order $order, string $stage, string $since = '' ): void {
		if ( $order->get_meta( self::META_STAGE ) === $stage ) {
			return;
		}
		// delete + add : garantit UNE seule ligne de meta par clé (pas de doublon).
		foreach ( [ self::META_STAGE => $stage, self::META_STAGE_SINCE => '' !== $since ? $since : current_time( 'mysql' ) ] as $k => $v ) {
			$order->delete_meta_data( $k );
			$order->add_meta_data( $k, $v, true );
		}
		$order->save_meta_data();
	}
}
