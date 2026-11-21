<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tables du plugin (dbDelta, versionnées par l'option codcrm_db_version).
 */
class CODCRM_Installer {

	const DB_VERSION = '2';

	public static function activate(): void {
		self::create_tables();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'codcrm_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function table_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'codcrm_events';
	}

	public static function table_tasks(): string {
		global $wpdb;
		return $wpdb->prefix . 'codcrm_tasks';
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$events  = self::table_events();

		// Journal unique de tout ce qui arrive à une commande / un colis.
		// occurred_at = date réelle de l'événement (ex. date du transporteur) ;
		// dedupe_key + order_id : un même événement n'est inséré qu'une fois.
		$sql = "CREATE TABLE {$events} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id     BIGINT(20) UNSIGNED NOT NULL,
			type         VARCHAR(20)         NOT NULL DEFAULT 'status',
			stage        VARCHAR(40)         NOT NULL DEFAULT '',
			carrier_slug VARCHAR(40)         NOT NULL DEFAULT '',
			carrier_state VARCHAR(120)       NOT NULL DEFAULT '',
			note         VARCHAR(255)        NOT NULL DEFAULT '',
			attempt_no   SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			channel      VARCHAR(20)         NOT NULL DEFAULT '',
			outcome      VARCHAR(40)         NOT NULL DEFAULT '',
			actor_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source       VARCHAR(20)         NOT NULL DEFAULT 'system',
			payload      LONGTEXT,
			dedupe_key   VARCHAR(64)         DEFAULT NULL,
			occurred_at  DATETIME            NOT NULL,
			created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY order_dedupe (order_id, dedupe_key),
			KEY order_time (order_id, occurred_at),
			KEY type_time  (type, occurred_at),
			KEY stage      (stage)
		) {$charset};";

		$tasks = self::table_tasks();

		// Rappels / relances des agents. kind : callback | attempt_followup |
		// stuck_parcel | cash_dispute ; status : open | done | cancelled.
		$sql_tasks = "CREATE TABLE {$tasks} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id    BIGINT(20) UNSIGNED NOT NULL,
			agent_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			kind        VARCHAR(30)         NOT NULL DEFAULT 'callback',
			status      VARCHAR(12)         NOT NULL DEFAULT 'open',
			due_at      DATETIME            NOT NULL,
			note        VARCHAR(255)        NOT NULL DEFAULT '',
			created_by  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			done_at     DATETIME            DEFAULT NULL,
			PRIMARY KEY (id),
			KEY agent_status_due (agent_id, status, due_at),
			KEY order_status (order_id, status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $sql_tasks );

		CODCRM_Agents::install_roles();

		update_option( 'codcrm_db_version', self::DB_VERSION, false );
	}
}
