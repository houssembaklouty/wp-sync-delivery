<?php
defined( 'ABSPATH' ) || exit;

/**
 * Accès au journal d'événements (table codcrm_events).
 */
class CODCRM_Events {

	/**
	 * Insère un événement. Si dedupe_key est fourni, un doublon (même commande +
	 * même clé) est ignoré.
	 *
	 * @param int   $order_id Commande.
	 * @param array $e        type, stage, carrier_slug, carrier_state, note, attempt_no,
	 *                        channel, outcome, actor_id, source, payload (array), dedupe_key,
	 *                        occurred_at (Y-m-d H:i:s, heure du site).
	 * @return int ID inséré, 0 si doublon / échec.
	 */
	public static function add( int $order_id, array $e ): int {
		global $wpdb;

		$row = [
			'order_id'      => $order_id,
			'type'          => substr( sanitize_key( (string) ( $e['type'] ?? 'status' ) ), 0, 20 ),
			'stage'         => substr( sanitize_text_field( (string) ( $e['stage'] ?? '' ) ), 0, 40 ),
			'carrier_slug'  => substr( sanitize_key( (string) ( $e['carrier_slug'] ?? '' ) ), 0, 40 ),
			'carrier_state' => substr( sanitize_text_field( (string) ( $e['carrier_state'] ?? '' ) ), 0, 120 ),
			'note'          => substr( sanitize_text_field( (string) ( $e['note'] ?? '' ) ), 0, 255 ),
			'attempt_no'    => (int) ( $e['attempt_no'] ?? 0 ),
			'channel'       => substr( sanitize_key( (string) ( $e['channel'] ?? '' ) ), 0, 20 ),
			'outcome'       => substr( sanitize_key( (string) ( $e['outcome'] ?? '' ) ), 0, 40 ),
			'actor_id'      => (int) ( $e['actor_id'] ?? 0 ),
			'source'        => substr( sanitize_key( (string) ( $e['source'] ?? 'system' ) ), 0, 20 ),
			'payload'       => isset( $e['payload'] ) ? wp_json_encode( $e['payload'], JSON_UNESCAPED_UNICODE ) : null,
			'dedupe_key'    => isset( $e['dedupe_key'] ) ? substr( (string) $e['dedupe_key'], 0, 64 ) : null,
			'occurred_at'   => (string) ( $e['occurred_at'] ?? current_time( 'mysql' ) ),
		];

		$table   = CODCRM_Installer::table_events();
		$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ];

		// $wpdb->insert() écrit NULL pour les valeurs null. Un doublon (clé unique
		// order_id + dedupe_key) échoue silencieusement : c'est le comportement voulu.
		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert( $table, $row, $formats );
		$wpdb->suppress_errors( $prev );

		if ( false === $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Événements d'une commande, du plus ancien au plus récent.
	 *
	 * @return array<int,object>
	 */
	public static function for_order( int $order_id, int $limit = 500 ): array {
		global $wpdb;
		$table = CODCRM_Installer::table_events();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY occurred_at ASC, id ASC LIMIT %d", $order_id, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Séquence ordonnée des étapes transporteur d'une commande.
	 *
	 * @return string[]
	 */
	public static function carrier_stage_sequence( int $order_id ): array {
		global $wpdb;
		$table = CODCRM_Installer::table_events();
		$rows  = $wpdb->get_col(
			$wpdb->prepare( "SELECT stage FROM {$table} WHERE order_id = %d AND type = 'carrier_state' AND stage <> '' ORDER BY occurred_at ASC, id ASC", $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		return array_map( 'strval', (array) $rows );
	}
}
