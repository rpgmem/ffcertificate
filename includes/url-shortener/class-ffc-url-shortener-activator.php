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
	 * Create the short URLs table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            short_code varchar(10) NOT NULL,
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
		// Guarda por versao (#1231) -- ver a nota logo acima da assinatura.
		$ffc_schema_option = 'ffc_url_shortener_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		$table_name = self::get_table_name();

		if ( ! self::table_exists( $table_name ) ) {
			self::create_tables();
		}

		// Add qr_cache column for QR code caching (avoids regeneration on every admin load).
		self::add_column_if_missing( $table_name, 'qr_cache', 'LONGTEXT NULL', 'status' );

		update_option( $ffc_schema_option, FFC_VERSION );
	}
}
