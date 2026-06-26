<?php
/**
 * Reregistration Activator
 *
 * Owns the reregistration module's schema, extracted from the monolithic
 * {@see \FreeFormCertificate\Activator} (#563 Sprint 7, A5). Each module owns
 * its own `create_tables()` installer; the core Activator orchestrates them.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates + migrates the reregistration tables on plugin activation.
 */
class ReregistrationActivator {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Create all reregistration-related tables (and run their migrations).
	 *
	 * Called during plugin activation. Order matches the previous inline
	 * sequence in Activator::activate(): campaigns → audiences junction →
	 * submissions → submission columns back-fill → audience junction migration.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		self::create_reregistrations_table();
		self::create_reregistration_audiences_table();
		self::create_reregistration_submissions_table();
		self::add_reregistration_submissions_columns();
		self::migrate_reregistration_audience_to_junction();
	}

	/**
	 * Create reregistrations (campaigns) table.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistrations_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistrations';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(250) NOT NULL,
            start_date datetime NOT NULL,
            end_date datetime NOT NULL,
            auto_approve tinyint(1) NOT NULL DEFAULT 0,
            email_invitation_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_reminder_enabled tinyint(1) NOT NULL DEFAULT 0,
            email_confirmation_enabled tinyint(1) NOT NULL DEFAULT 0,
            reminder_days int(11) NOT NULL DEFAULT 7,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Create reregistration ↔ audiences junction table.
	 *
	 * @since 4.13.0
	 */
	private static function create_reregistration_audiences_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_audiences';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            reregistration_id bigint(20) unsigned NOT NULL,
            audience_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (reregistration_id, audience_id),
            KEY idx_audience_id (audience_id)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Migrate existing audience_id column data into the junction table.
	 *
	 * @since 4.13.0
	 */
	private static function migrate_reregistration_audience_to_junction(): void {
		global $wpdb;
		$rereg_table    = $wpdb->prefix . 'ffc_reregistrations';
		$junction_table = $wpdb->prefix . 'ffc_reregistration_audiences';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_column = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$rereg_table,
				'audience_id'
			)
		);

		if ( empty( $has_column ) ) {
			return; // Column already dropped — migration done.
		}

		// Copy audience_id into junction table (skip if already migrated).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (reregistration_id, audience_id)
             SELECT id, audience_id FROM %i WHERE audience_id > 0',
				$junction_table,
				$rereg_table
			)
		);

		// Drop the old column and its index.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idx_audience_id', $rereg_table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN audience_id', $rereg_table ) );
	}

	/**
	 * Create reregistration submissions table
	 *
	 * Stores individual user responses to reregistration campaigns.
	 *
	 * @since 4.11.0
	 */
	private static function create_reregistration_submissions_table(): void {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_submissions';
		$charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		// `submitted_at` is Category A (instant) since 6.6.0 — unix UTC
		// seconds. See CLAUDE.md "Date / time storage convention".
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data json DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            submitted_at bigint(20) unsigned DEFAULT NULL,
            reviewed_at bigint(20) unsigned DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
	}

	/**
	 * Add auth_code column to reregistration submissions table for existing installs.
	 *
	 * @since 4.12.0
	 */
	private static function add_reregistration_submissions_columns(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_reregistration_submissions';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		self::add_columns_if_missing(
			$table_name,
			array(
				'auth_code'   => array(
					'type'  => 'VARCHAR(20) DEFAULT NULL',
					'after' => 'status',
					'index' => 'auth_code',
				),
				'magic_token' => array(
					'type'  => 'VARCHAR(64) DEFAULT NULL',
					'after' => 'auth_code',
					'index' => 'magic_token',
				),
			)
		);
	}
}
