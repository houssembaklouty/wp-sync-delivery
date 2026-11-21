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
			// Verrou : deux backfills simultanés créent des lignes de meta en double.
			$lock = (int) get_option( 'codcrm_backfill_lock', 0 );
			if ( $lock && time() - $lock < 3 * HOUR_IN_SECONDS && ! isset( $assoc_args['force'] ) ) {
				WP_CLI::error( 'Un backfill est déjà en cours (verrou). Utilisez --force si c\'est une erreur.' );
			}
			update_option( 'codcrm_backfill_lock', time(), false );
			register_shutdown_function( static function () {
				delete_option( 'codcrm_backfill_lock' );
			} );

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

		/**
		 * Répare les données : doublons de meta, étapes recalculées avec les règles actuelles.
		 *
		 * ## EXAMPLES
		 *
		 *     wp codcrm repair
		 *
		 * @param array $args       Arguments positionnels.
		 * @param array $assoc_args Options.
		 */
		public function repair( $args, $assoc_args ) {
			global $wpdb;

			// 1. Doublons de meta _codcrm_* : on garde la ligne la plus récente.
			$tables = [ [ $wpdb->prefix . 'wc_orders_meta', 'order_id', 'id' ], [ $wpdb->postmeta, 'post_id', 'meta_id' ] ];
			$removed = 0;
			foreach ( $tables as [ $table, $col, $pk ] ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
					continue;
				}
				$removed += (int) $wpdb->query(
					"DELETE m FROM {$table} m JOIN {$table} n ON n.{$col} = m.{$col} AND n.meta_key = m.meta_key AND n.{$pk} > m.{$pk} WHERE m.meta_key LIKE '\\_codcrm\\_%'" // phpcs:ignore WordPress.DB.PreparedSQL
				);
			}
			WP_CLI::log( sprintf( '%d ligne(s) de meta en double supprimée(s).', $removed ) );

			// 2. Étapes recalculées depuis le libellé transporteur (règles actuelles).
			$events = CODCRM_Installer::table_events();
			$rows   = $wpdb->get_results( "SELECT DISTINCT carrier_state, stage FROM {$events} WHERE type = 'carrier_state'" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$fixed  = 0;
			foreach ( $rows as $r ) {
				$new = CODCRM_Stage_Mapper::from_carrier_state( (string) $r->carrier_state ) ?: CODCRM_Stages::INCONNU;
				if ( $new !== $r->stage ) {
					$fixed += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$events} SET stage = %s WHERE type = 'carrier_state' AND carrier_state = %s", $new, $r->carrier_state ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			}
			WP_CLI::log( sprintf( '%d événement(s) reclassé(s).', $fixed ) );

			// 3. Étape courante + tentatives recalculées pour chaque commande suivie.
			$ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$events} WHERE type = 'carrier_state'" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $ids as $id ) {
				$order = wc_get_order( (int) $id );
				if ( ! $order ) {
					continue;
				}
				$last = CODCRM_Events::last_carrier_event( (int) $id );
				if ( $last ) {
					// Force la réécriture (étape + « depuis » = date réelle de l'événement).
					$order->delete_meta_data( CODCRM_Event_Collector::META_STAGE );
					$order->save_meta_data();
					CODCRM_Event_Collector::set_current_stage( $order, (string) $last->stage, (string) $last->occurred_at );
				}
				CODCRM_Attempts::refresh( $order );
			}
			WP_CLI::log( sprintf( '%d commande(s) recalculée(s).', count( $ids ) ) );

			// 4. Cash COD : ligne par colis synchronisé + statut déduit de l'étape transporteur.
			$made = 0;
			$sync = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$events} WHERE type IN ('carrier_state','carrier_sync')" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $sync as $id ) {
				$order = wc_get_order( (int) $id );
				if ( ! $order ) {
					continue;
				}
				$had = CODCRM_Cash::get( (int) $id );
				if ( CODCRM_Cash::ensure( $order ) && ! $had ) {
					$made++;
				}
				$last = CODCRM_Events::last_carrier_event( (int) $id );
				if ( $last ) {
					CODCRM_Cash::apply_stage( $order, (string) $last->stage, (string) $last->occurred_at );
				}
			}
			WP_CLI::success( sprintf( 'Cash : %d ligne(s) créée(s).', $made ) );
		}
	}

	WP_CLI::add_command( 'codcrm', 'CODCRM_CLI' );
}
