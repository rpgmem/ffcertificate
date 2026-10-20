<?php
/**
 * BulkIssueActivator
 *
 * Creates the two staging tables of the bulk-issue import (#1945):
 *
 * - `ffc_bulk_issue_jobs`: one row per import. Counters, state and dates only.
 *   It holds no personal data and survives the import as its audit trail.
 * - `ffc_bulk_issue_rows`: one row per file line. The only table of the import
 *   that holds personal data, encrypted, and emptied when the job ends.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and heals the bulk-issue tables.
 */
class BulkIssueActivator {

	/** Schema version of the two tables. Bump it when a column changes. */
	public const DB_VERSION = '1';

	/** Option holding the schema version that was last created. */
	public const DB_VERSION_OPTION = 'ffc_bulk_issue_db_version';

	/**
	 * Table name of the import jobs.
	 *
	 * @return string
	 */
	public static function jobs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ffc_bulk_issue_jobs';
	}

	/**
	 * Table name of the staged file rows.
	 *
	 * @return string
	 */
	public static function rows_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ffc_bulk_issue_rows';
	}

	/**
	 * Creates the tables when the stored schema version differs. Called from the
	 * Loader on every request, so an in-place update heals the schema without a
	 * reactivation.
	 *
	 * @return void
	 */
	public static function maybe_create_tables(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION, '' ) ) {
			return;
		}

		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Creates both tables with dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs            = self::jobs_table();
		$rows            = self::rows_table();

		$sql_jobs = "CREATE TABLE $jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'staging',
			total int(10) unsigned NOT NULL DEFAULT 0,
			issued int(10) unsigned NOT NULL DEFAULT 0,
			skipped int(10) unsigned NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL,
			finished_at bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status)
		) $charset_collate";

		$sql_rows = "CREATE TABLE $rows (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			line int(10) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			skip_reason varchar(40) DEFAULT NULL,
			cpf_hash varchar(64) DEFAULT NULL,
			rf_hash varchar(64) DEFAULT NULL,
			payload_encrypted longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_job_status (job_id, status),
			KEY idx_cpf_hash (cpf_hash),
			KEY idx_rf_hash (rf_hash)
		) $charset_collate";

		dbDelta( $sql_jobs );
		dbDelta( $sql_rows );
	}
}
