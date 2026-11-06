<?php
/**
 * URL Shortener Activator
 *
 * Creates and migrates the ffc_short_urls database table.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\DatabaseHelperTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for url shortener.
 */
class UrlShortenerActivator {

	use DatabaseHelperTrait;

	/**
	 * Get the short URLs table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_short_urls';
	}

	/**
	 * Get the retired codes table name (#2337).
	 *
	 * @return string
	 */
	public static function get_retired_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_short_url_retired';
	}

	/**
	 * Create the short URL tables that are missing.
	 */
	public static function create_tables(): void {
		self::create_retired_table();

		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            short_code varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            target_url text NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) DEFAULT '',
            click_count bigint(20) unsigned DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) DEFAULT 'active',
            qr_cache longtext NULL COMMENT 'Cached QR code payload',
            PRIMARY KEY (id),
            UNIQUE KEY idx_short_code (short_code),
            KEY idx_post_id (post_id),
            KEY idx_status (status)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the table of retired codes (#2337).
	 *
	 * A code leaves `ffc_short_urls` when its link is deleted or when
	 * Regenerate gives the link a new code. Its row here is what stops the
	 * generator from issuing it again, so a printed QR can never start
	 * pointing somewhere else. Title and destination are copied, because the
	 * link they came from may be gone. `retired_at` is an instant, shown to
	 * the administrator, so it is unix seconds (Category A).
	 */
	public static function create_retired_table(): void {
		global $wpdb;

		$table_name = self::get_retired_table_name();
		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            short_code varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            short_url_id bigint(20) unsigned DEFAULT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) DEFAULT '',
            target_url text NOT NULL,
            reason varchar(20) NOT NULL,
            retired_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_short_code (short_code),
            KEY idx_retired_at (retired_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * The definition `short_code` takes in both tables (#2337).
	 *
	 * A binary collation makes `AbC` and `abc` two codes, which the generator
	 * relies on to draw from upper and lower case. The column names its own
	 * character set, so this holds whatever the table was created with.
	 */
	public const SHORT_CODE_COLUMN = 'varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';

	/**
	 * Give `short_code` the binary collation on a table created before it
	 * (#2337).
	 *
	 * Safe on every existing install: the UNIQUE key under the old,
	 * case-insensitive collation never let two codes differ only in case,
	 * so no row collides once case counts. Codes keep the case they were
	 * printed with; only a code typed by hand in another case stops matching.
	 *
	 * @param string $table_name Table.
	 */
	public static function ensure_case_sensitive_codes( string $table_name ): void {
		global $wpdb;

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on the plugin's own table; the answer must be the live one.
		$collation = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table_name,
				'short_code'
			)
		);
		if ( '' === $collation || '_bin' === substr( $collation, -4 ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The appended text is SHORT_CODE_COLUMN, a class constant; the table goes through %i.
		$sql = $wpdb->prepare( 'ALTER TABLE %i MODIFY short_code ' . self::SHORT_CODE_COLUMN, $table_name );
		if ( is_string( $sql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- A one-off column change on the plugin's own table; $sql is the prepare() result above, built from a class constant.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Run on plugins_loaded to handle schema updates.
	 */
	/**
	 * Version gate: the chain below only needs to run once per `FFC_VERSION`
	 * (#1231).
	 *
	 * Without it, `UrlShortenerActivator::maybe_migrate()` probed the schema on
	 * EVERY request -- anonymous frontend included -- because `table_exists()`
	 * is an uncached `SHOW TABLES LIKE` and every `add_column_if_missing()`
	 * fires a `SHOW COLUMNS` before deciding to do nothing. Summed across the
	 * `Loader`'s four chains, that was 48 DDL queries per page on an install
	 * with nothing to migrate.
	 *
	 * **The gate is `FFC_VERSION`, and NOT a one-shot marker, on purpose.**
	 * These calls exist because an in-place plugin update (wp-admin's "Update"
	 * button) does NOT fire `register_activation_hook` -- the property to
	 * preserve is "the schema heals itself after an update", not "it runs on
	 * every request". With `FFC_VERSION` the constant changes on the update and
	 * the chain runs once on the first request after it, identical to what it
	 * did before. With a one-shot boolean, a column introduced in a future
	 * release would never reach whoever already had the marker stored.
	 *
	 * The option is written **after** the body, so a failure partway through
	 * does not lock the chain at a version it never finished applying.
	 */
	public static function maybe_migrate(): void {
		// Version gate (#1231) -- see the note just above the signature.
		$ffc_schema_option = 'ffc_url_shortener_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		$table_name = self::get_table_name();

		// Creates whichever table is missing, the retired codes one included.
		self::create_tables();

		// Add qr_cache column for QR code caching (avoids regeneration on every admin load).
		self::add_column_if_missing( $table_name, 'qr_cache', 'LONGTEXT NULL', 'status' );

		// Case counts in a code (#2337).
		self::ensure_case_sensitive_codes( $table_name );
		self::ensure_case_sensitive_codes( self::get_retired_table_name() );

		update_option( $ffc_schema_option, FFC_VERSION );
	}
}
