<?php
/**
 * RateLimitActivator v3.3.0
 * Creates database tables - dbDelta compatible
 *
 * V3.3.0 - Added strict types and type hints
 * v3.2.0 - Migrated to namespace (Phase 2)
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for rate limit.
 */
class RateLimitActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Create tables.
	 *
	 * @return bool
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_limits  = $wpdb->prefix . 'ffc_rate_limits';
		$table_logs    = $wpdb->prefix . 'ffc_rate_limit_logs';
		$table_signals = $wpdb->prefix . 'ffc_device_signals';

		// Check if tables exist (using prepared statements via trait).
		if ( ! self::table_exists( $table_limits ) ) {
			$sql_limits = "CREATE TABLE $table_limits (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(20) NOT NULL,
                identifier varchar(255) NOT NULL,
                form_id bigint(20) unsigned DEFAULT NULL,
                count int(10) unsigned NOT NULL DEFAULT 1,
                window_type varchar(20) NOT NULL,
                window_start datetime NOT NULL,
                window_end datetime NOT NULL,
                last_attempt datetime NOT NULL,
                is_blocked tinyint(1) DEFAULT 0,
                blocked_until datetime DEFAULT NULL,
                blocked_reason varchar(255) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_type_identifier (type,identifier),
                KEY idx_form (form_id),
                KEY idx_window (window_end),
                KEY idx_blocked (is_blocked,blocked_until),
                KEY idx_cleanup (window_end,updated_at),
                UNIQUE KEY unique_tracking (type,identifier,form_id,window_type,window_start)
            ) $charset_collate;";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			dbDelta( $sql_limits );
		}

		// TABLE 2: Logs.
		if ( ! self::table_exists( $table_logs ) ) {
			$sql_logs = "CREATE TABLE $table_logs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(20) NOT NULL,
                identifier varchar(255) NOT NULL,
                form_id bigint(20) unsigned DEFAULT NULL,
                action varchar(20) NOT NULL,
                reason varchar(255) DEFAULT NULL,
                ip_address varchar(45) DEFAULT NULL,
                user_agent text DEFAULT NULL,
                current_count int(10) unsigned NOT NULL,
                max_allowed int(10) unsigned NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_type (type),
                KEY idx_identifier (identifier),
                KEY idx_action (action),
                KEY idx_form (form_id),
                KEY idx_created (created_at),
                KEY idx_cleanup (created_at)
            ) $charset_collate;";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			dbDelta( $sql_logs );
		}

		// TABLE 3: Device fingerprint signals (added in 6.3.0, extended in
		// 6.3.2 with 4 additional columns: sig_plugins, sig_permissions,
		// sig_mediaqueries, sig_math). dbDelta is idempotent — it ALTERs
		// existing tables to add missing columns, so we don't gate this
		// behind table_exists() for the signals table; that lets the same
		// call path handle both fresh installs and 6.3.1→6.3.2 upgrades.
		$sql_signals = "CREATE TABLE $table_signals (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) unsigned DEFAULT NULL,
            form_id bigint(20) unsigned NOT NULL,
            sig_cookie char(64) DEFAULT NULL,
            sig_ua char(64) DEFAULT NULL,
            sig_screen char(64) DEFAULT NULL,
            sig_tz char(64) DEFAULT NULL,
            sig_concurrency char(64) DEFAULT NULL,
            sig_memory char(64) DEFAULT NULL,
            sig_canvas char(64) DEFAULT NULL,
            sig_audio char(64) DEFAULT NULL,
            sig_webgl char(64) DEFAULT NULL,
            sig_fonts char(64) DEFAULT NULL,
            sig_plugins char(64) DEFAULT NULL,
            sig_permissions char(64) DEFAULT NULL,
            sig_mediaqueries char(64) DEFAULT NULL,
            sig_math char(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_form (form_id),
            KEY idx_submission (submission_id),
            KEY idx_cookie (sig_cookie),
            KEY idx_canvas (sig_canvas),
            KEY idx_audio (sig_audio),
            KEY idx_webgl (sig_webgl),
            KEY idx_created (created_at)
        ) $charset_collate;";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql_signals );

		update_option( 'ffc_rate_limit_db_version', '1.2.0' );
		return true;
	}

	/**
	 * Run create_tables() once when the stored db version is older than the
	 * code's expected version. Hooked on plugin load to cover in-place
	 * upgrades that don't fire register_activation_hook.
	 */
	public static function maybe_create_tables(): void {
		$stored = get_option( 'ffc_rate_limit_db_version', '' );
		if ( '1.2.0' === $stored ) {
			return;
		}
		self::create_tables();
	}

	/**
	 * Tables exist.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		return self::table_exists( $wpdb->prefix . 'ffc_rate_limits' )
			&& self::table_exists( $wpdb->prefix . 'ffc_rate_limit_logs' )
			&& self::table_exists( $wpdb->prefix . 'ffc_device_signals' );
	}

	/**
	 * Drop tables.
	 *
	 * @return bool
	 */
	public static function drop_tables(): bool {
		global $wpdb;

		$table_limits  = $wpdb->prefix . 'ffc_rate_limits';
		$table_logs    = $wpdb->prefix . 'ffc_rate_limit_logs';
		$table_signals = $wpdb->prefix . 'ffc_device_signals';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_limits ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_logs ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_signals ) );

		delete_option( 'ffc_rate_limit_db_version' );
		return true;
	}
}
