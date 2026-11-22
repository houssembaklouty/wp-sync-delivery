<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tables du plugin (dbDelta, versionnées par l'option codcrm_db_version).
 */
class CODCRM_Installer {

	const DB_VERSION = '6';

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

	public static function table_cash(): string {
		global $wpdb;
		return $wpdb->prefix . 'codcrm_cash';
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

		$cash = self::table_cash();

		// Rapprochement du cash COD (montants en DT, 3 décimales).
		// status : pending | collected | paid_out | disputed | returned | cancelled.
		$sql_cash = "CREATE TABLE {$cash} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id      BIGINT(20) UNSIGNED NOT NULL,
			carrier_slug  VARCHAR(40)         NOT NULL DEFAULT '',
			tracking      VARCHAR(64)         NOT NULL DEFAULT '',
			expected      DECIMAL(12,3)       NOT NULL DEFAULT 0,
			collected     DECIMAL(12,3)       DEFAULT NULL,
			fee           DECIMAL(12,3)       NOT NULL DEFAULT 0,
			status        VARCHAR(12)         NOT NULL DEFAULT 'pending',
			statement_ref VARCHAR(80)         NOT NULL DEFAULT '',
			note          VARCHAR(255)        NOT NULL DEFAULT '',
			collected_at  DATETIME            DEFAULT NULL,
			paid_at       DATETIME            DEFAULT NULL,
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY order_id (order_id),
			KEY tracking (tracking),
			KEY status_carrier (status, carrier_slug)
		) {$charset};";

		// Envois groupés : tâches et éléments (file d'attente).
		$jobs  = CODCRM_Bulk::table_jobs();
		$items = CODCRM_Bulk::table_items();
		$sql_jobs = "CREATE TABLE {$jobs} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_by  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			channel     VARCHAR(12)         NOT NULL DEFAULT 'sms',
			context     VARCHAR(40)         NOT NULL DEFAULT '',
			lang        VARCHAR(6)          NOT NULL DEFAULT 'auto',
			status      VARCHAR(12)         NOT NULL DEFAULT 'running',
			total       INT(10) UNSIGNED    NOT NULL DEFAULT 0,
			note        VARCHAR(255)        NOT NULL DEFAULT '',
			filter      TEXT,
			created_at  DATETIME            NOT NULL,
			finished_at DATETIME            DEFAULT NULL,
			PRIMARY KEY (id),
			KEY status_channel (status, channel)
		) {$charset};";
		// status : pending | sending | sent | failed | skipped.
		$sql_items = "CREATE TABLE {$items} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id       BIGINT(20) UNSIGNED NOT NULL,
			order_id     BIGINT(20) UNSIGNED NOT NULL,
			status       VARCHAR(10)         NOT NULL DEFAULT 'pending',
			tries        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			error        VARCHAR(255)        NOT NULL DEFAULT '',
			processed_at DATETIME            DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY job_order (job_id, order_id),
			KEY job_status (job_id, status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_jobs );
		dbDelta( $sql_items );
		dbDelta( $sql );
		dbDelta( $sql_tasks );
		dbDelta( $sql_cash );

		CODCRM_Agents::install_roles();

		// Autoload : lue à chaque requête par maybe_upgrade().
		update_option( 'codcrm_db_version', self::DB_VERSION, true );
	}
}
