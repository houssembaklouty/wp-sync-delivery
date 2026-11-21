<?php
defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Commandes WP-CLI de COD Tracker CRM.
	 */
	class CODCRM_CLI {

		/**
		 * Crée les événements initiaux des commandes déjà synchronisées (idempotent).
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<n>]
		 * : Nombre maximal de commandes (défaut : toutes).
		 *
		 * [--refresh]
		 * : Interroge aussi le transporteur pour importer l'historique daté complet.
		 *
		 * [--sleep=<ms>]
		 * : Pause entre deux appels transporteur (défaut 300).
		 *
		 * ## EXAMPLES
		 *
		 *     wp codcrm backfill --limit=20 --refresh
		 *
		 * @param array $args       Arguments positionnels.
		 * @param array $assoc_args Options.
		 */
		public function backfill( $args, $assoc_args ) {
			$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : -1;
			$refresh = isset( $assoc_args['refresh'] );
			$sleep   = isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 300;

			$orders = wc_get_orders( [
				'limit'      => $limit,
				'orderby'    => 'ID',
				'order'      => 'DESC',
				'meta_query' => [
					[ 'key' => '_wcmcs_sync_status', 'value' => 'synced' ],
					[ 'key' => '_wcmcs_tracking_id', 'compare' => 'EXISTS' ],
				],
			] );

			$done = 0;
			$new  = 0;
			foreach ( $orders as $order ) {
				$id   = $order->get_id();
				$slug = (string) $order->get_meta( '_wcmcs_carrier_slug' );

				// Création de la commande + statut courant.
				$new += CODCRM_Events::add( $id, [
					'type' => 'status', 'stage' => CODCRM_Stages::COMMANDE, 'source' => 'backfill',
					'note' => __( 'Commande créée', 'cod-tracker-crm' ), 'dedupe_key' => 'bf-created',
					'occurred_at' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
				] ) ? 1 : 0;

				$cur = CODCRM_Stage_Mapper::from_order_status( $order->get_status() );
				if ( '' !== $cur ) {
					$new += CODCRM_Events::add( $id, [
						'type' => 'status', 'stage' => $cur, 'source' => 'backfill',
						'note' => sprintf( __( 'Statut actuel : %s', 'cod-tracker-crm' ), wc_get_order_status_name( $order->get_status() ) ),
						'dedupe_key' => 'bf-status-' . $order->get_status(),
						'occurred_at' => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
					] ) ? 1 : 0;
				}

				$status = [];
				$state  = (string) $order->get_meta( '_wcmcs_last_state' );

				if ( $refresh && class_exists( 'WCMCS_Carrier_Detector' ) && '' !== $slug ) {
					$provider = WCMCS_Carrier_Detector::get_provider_by_slug( $slug );
					$tracking = (string) $order->get_meta( '_wcmcs_tracking_id' );
					if ( $provider && $provider->is_configured() && '' !== $tracking ) {
						$res = $provider->get_tracking_status( $tracking );
						if ( ! is_wp_error( $res ) ) {
							$status = $res;
							$state  = (string) ( $res['state_label'] ?? $state );
						} else {
							WP_CLI::warning( sprintf( '#%d : %s', $id, $res->get_error_message() ) );
						}
						usleep( $sleep * 1000 );
					}
				}

				if ( '' !== $state || $status ) {
					CODCRM_Event_Collector::record_carrier_state( $order, $slug, $state, $status );
				}
				$done++;
			}

			WP_CLI::success( sprintf( '%d commande(s) traitée(s), %d événement(s) de base créé(s).', $done, $new ) );
		}
	}

	WP_CLI::add_command( 'codcrm', 'CODCRM_CLI' );
}
