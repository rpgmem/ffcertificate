<?php
/**
 * CertificateSignatureActivator
 *
 * Creates `ffc_certificate_signatures` (#2639): one row per signed record of
 * a certificate. Editing a certificate signs a new record and marks the
 * previous one superseded, so the table keeps every record a certificate was
 * ever signed with.
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and heals the signatures table.
 */
class CertificateSignatureActivator {

	/** Schema revision of the table. Bump it when a column changes (#2430). */
	public const SCHEMA_REVISION = '1';

	/** Option holding the schema revision that was last created. */
	public const SCHEMA_REVISION_OPTION = 'ffc_certificate_signatures_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ffc_certificate_signatures';
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
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			key_id varchar(16) NOT NULL,
			record_encrypted longtext NOT NULL,
			record_hash char(64) NOT NULL,
			signature_full varchar(100) NOT NULL,
			signature_compact varchar(100) NOT NULL,
			signed_at bigint(20) unsigned NOT NULL,
			superseded_at bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_submission (submission_id, superseded_at)
		) $charset_collate";

		dbDelta( $sql );
	}
}
