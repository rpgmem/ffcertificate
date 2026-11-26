<?php
/**
 * CertificateTerminalActivator
 *
 * Creates `ffc_certificate_terminal_readings` (#2891): what a check-in
 * terminal read for a certificate form, one row per form and person, with
 * the result the person got and the certificate it issued.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and heals the terminal readings table.
 */
class CertificateTerminalActivator {

	/** Schema revision of the table. Bump it when a column changes (#2430). */
	public const SCHEMA_REVISION = '1';

	/** Option holding the schema revision that was last created. */
	public const SCHEMA_REVISION_OPTION = 'ffc_certificate_terminal_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ffc_certificate_terminal_readings';
	}

	/**
	 * Creates the table when the stored schema revision differs. Called from
	 * the Loader on every request, so an in-place update heals the schema
	 * without a reactivation.
	 *
	 * @return void
	 */
	public static function maybe_create_table(): void {
		if ( self::SCHEMA_REVISION === get_option( self::SCHEMA_REVISION_OPTION, '' ) ) {
			return;
		}

		self::create_table();
		update_option( self::SCHEMA_REVISION_OPTION, self::SCHEMA_REVISION, true );
	}

	/**
	 * Creates the table with dbDelta.
	 *
	 * One row per form and person: the unique key is what makes two
	 * terminals reading the same person at once keep one row. A reading is
	 * a record, so deleting the account keeps the row and drops `user_id`
	 * (CLAUDE.md "Security & PII conventions").
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = self::table();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			terminal_id bigint(20) unsigned NOT NULL DEFAULT 0,
			read_at bigint(20) unsigned NOT NULL,
			result varchar(20) NOT NULL,
			reason varchar(64) NOT NULL DEFAULT '',
			missing text DEFAULT NULL,
			submission_id bigint(20) unsigned DEFAULT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_form_user (form_id, user_id),
			KEY idx_form_result (form_id, result),
			KEY idx_user (user_id)
		) $charset_collate";

		dbDelta( $sql );
	}
}
