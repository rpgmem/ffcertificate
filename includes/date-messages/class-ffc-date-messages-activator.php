<?php
/**
 * Date Messages Activator
 *
 * Creates the three tables of the date-based messages module.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\DatabaseHelperTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema of the date-based messages module (#1538).
 *
 * - `ffc_date_message_rules` -- what to send, to whom and when, one row per
 *   configured message.
 * - `ffc_date_message_runs`  -- one row per execution (the daily cron or a
 *   manual send), carrying its counters; the manager digest reads it.
 * - `ffc_date_message_log`   -- one row per message handed to `wp_mail()`.
 *   Its UNIQUE key is the deduplication: a second run for the same rule,
 *   person, occurrence and channel cannot insert, so it cannot send.
 *
 * Every timestamp is an instant (Category A, `BIGINT UNSIGNED` from `time()`);
 * the target dates of a run are wall-clock (Category B, `DATE`).
 */
class DateMessagesActivator {

	use DatabaseHelperTrait;

	/**
	 * Schema version option, written after the chain completes. Listed in
	 * `uninstall.php`.
	 */
	public const SCHEMA_OPTION = 'ffc_date_messages_schema_version';

	/**
	 * Marker that the `audience_id` → `audience_ids` move is done (#1648).
	 * Listed in `uninstall.php`.
	 *
	 * Its own marker, not the version gate: the move first reached the
	 * testes site on `develop`, where `FFC_VERSION` does not change, so behind
	 * the gate it never ran -- every save failed on the missing column, and
	 * every existing rule read as reaching everyone.
	 */
	public const AUDIENCE_IDS_OPTION = 'ffc_date_messages_audience_ids_migrated';

	/**
	 * Rules table.
	 *
	 * @return string
	 */
	public static function rules_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_date_message_rules';
	}

	/**
	 * Runs table.
	 *
	 * @return string
	 */
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_date_message_runs';
	}

	/**
	 * Log table.
	 *
	 * @return string
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_date_message_log';
	}

	/**
	 * Create every table that does not exist yet.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::create_rules_table();
		self::create_runs_table();
		self::create_log_table();
	}

	/**
	 * Heal the schema on an install that was never re-activated (#1311),
	 * gated on `FFC_VERSION` (#1231) and recorded after the body; then run
	 * the audience move once, on its own marker, whatever the version.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::SCHEMA_OPTION, '' ) !== FFC_VERSION ) {
			self::create_tables();
			update_option( self::SCHEMA_OPTION, FFC_VERSION );
		}

		if ( '1' !== (string) get_option( self::AUDIENCE_IDS_OPTION, '' ) ) {
			self::migrate_audience_ids();
		}
	}

	/**
	 * Move a rule's single `audience_id` into the `audience_ids` list (#1648).
	 *
	 * Adds the list column, copies every set audience into it as a one-item
	 * list, then drops the old column, which nothing reads any more. Each step
	 * checks before it acts, so a run interrupted halfway resumes: the copy
	 * only fills a list still empty, and the drop runs only after the copy.
	 * The marker is written once the table has the list and no longer the old
	 * column -- read back, not assumed -- so a failed drop is retried.
	 *
	 * @return void
	 */
	public static function migrate_audience_ids(): void {
		global $wpdb;

		$table = self::rules_table();
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		self::add_columns_if_missing(
			$table,
			array(
				'audience_ids' => array(
					'type'  => 'LONGTEXT DEFAULT NULL',
					'after' => 'offset_days',
				),
			)
		);

		if ( self::column_exists( $table, 'audience_id' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-shot schema migration of the plugin's own rules table, which WordPress exposes no API for.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET audience_ids = CONCAT('[', audience_id, ']') WHERE audience_id IS NOT NULL AND audience_id > 0 AND ( audience_ids IS NULL OR audience_ids = '' OR audience_ids = '[]' )",
					$table
				)
			);
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN audience_id', $table ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
		}

		if ( self::column_exists( $table, 'audience_ids' ) && ! self::column_exists( $table, 'audience_id' ) ) {
			update_option( self::AUDIENCE_IDS_OPTION, '1' );
		}
	}

	/**
	 * Rules.
	 *
	 * `offset_days` is signed: 0 sends on the date itself, -7 seven days
	 * before it. `digest_user_ids` is a JSON list of WordPress user ids,
	 * declared `longtext` for the reason `CLAUDE.md` gives about `json`.
	 *
	 * @return void
	 */
	private static function create_rules_table(): void {
		global $wpdb;

		$table_name      = self::rules_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            source varchar(50) NOT NULL DEFAULT 'birthday',
            offset_days smallint(6) NOT NULL DEFAULT 0,
            audience_ids longtext DEFAULT NULL,
            subject varchar(255) NOT NULL,
            body longtext NOT NULL,
            send_to_user tinyint(1) NOT NULL DEFAULT 1,
            digest_enabled tinyint(1) NOT NULL DEFAULT 0,
            digest_mode varchar(20) NOT NULL DEFAULT 'summary',
            digest_user_ids longtext DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_active (is_active)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Runs.
	 *
	 * @return void
	 */
	private static function create_runs_table(): void {
		global $wpdb;

		$table_name      = self::runs_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            trigger_kind varchar(20) NOT NULL DEFAULT 'cron',
            target_from date NOT NULL,
            target_to date NOT NULL,
            started_at bigint(20) unsigned NOT NULL DEFAULT 0,
            finished_at bigint(20) unsigned DEFAULT NULL,
            digest_sent_at bigint(20) unsigned DEFAULT NULL,
            sent int(10) unsigned NOT NULL DEFAULT 0,
            opted_out int(10) unsigned NOT NULL DEFAULT 0,
            no_email int(10) unsigned NOT NULL DEFAULT 0,
            out_of_audience int(10) unsigned NOT NULL DEFAULT 0,
            failed int(10) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_rule (rule_id),
            KEY idx_started (started_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Send log.
	 *
	 * @return void
	 */
	private static function create_log_table(): void {
		global $wpdb;

		$table_name      = self::log_table();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            rule_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            occurrence_key varchar(10) NOT NULL,
            channel varchar(20) NOT NULL DEFAULT 'user',
            sent_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_delivery (rule_id,user_id,occurrence_key,channel),
            KEY idx_run (run_id),
            KEY idx_user (user_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
