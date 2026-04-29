<?php
/**
 * Migration: Dynamic Reregistration Fields
 *
 * Upgrades existing wp_ffc_custom_fields and wp_ffc_reregistration_submissions
 * tables to support the unified dynamic field system:
 *
 * - Adds field_group, field_source, field_profile_key, field_mask, is_sensitive
 *   columns to wp_ffc_custom_fields.
 * - Adds auth_code and magic_token columns to wp_ffc_reregistration_submissions
 *   (previously referenced in code but never created).
 * - Seeds the standard reregistration fields for each existing audience.
 *
 * Uses dbDelta so it is idempotent and safe to run multiple times.
 *
 * @package FreeFormCertificate\Migrations
 * @since 4.13.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration Dynamic Rereg Fields.
 */
class MigrationDynamicReregFields {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Option key to track migration status.
	 */
	private const MIGRATION_OPTION = 'ffc_migration_dynamic_rereg_fields_completed';

	/**
	 * Check if migration has been completed.
	 */
	public static function is_completed(): bool {
		return (bool) get_option( self::MIGRATION_OPTION, false );
	}

	/**
	 * Run the migration.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		if ( self::is_completed() ) {
			return array(
				'success' => true,
				'message' => __( 'Migration already completed.', 'ffcertificate' ),
				'details' => array(),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$details     = array();
		$all_success = true;

		$details['ffc_custom_fields']              = self::upgrade_custom_fields_table();
		$details['ffc_reregistration_submissions'] = self::upgrade_reregistration_submissions_table();
		$details['seed_standard_fields']           = self::seed_standard_fields_all_audiences();

		foreach ( $details as $result ) {
			if ( ! $result['success'] ) {
				$all_success = false;
			}
		}

		if ( $all_success ) {
			update_option( self::MIGRATION_OPTION, true );
		}

		return array(
			'success' => $all_success,
			'message' => $all_success
				? __( 'Dynamic reregistration fields migration completed successfully.', 'ffcertificate' )
				: __( 'Dynamic reregistration fields migration encountered issues. Check details.', 'ffcertificate' ),
			'details' => $details,
		);
	}

	/**
	 * Upgrade ffc_custom_fields table: adds new columns via dbDelta.
	 *
	 * DbDelta compares the existing schema with the desired CREATE TABLE and
	 * issues only the necessary ALTERs.
	 *
	 * @return array{success: bool, message: string}
	 */
	private static function upgrade_custom_fields_table(): array {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_custom_fields';
		$charset_collate = $wpdb->get_charset_collate();

		// Full desired schema (same as MigrationCustomFieldsTables, plus new columns).
		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            audience_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_label varchar(250) NOT NULL,
            field_type varchar(50) NOT NULL DEFAULT 'text',
            field_group varchar(100) NOT NULL DEFAULT '',
            field_source varchar(20) NOT NULL DEFAULT 'custom',
            field_profile_key varchar(100) DEFAULT NULL,
            field_mask varchar(50) DEFAULT NULL,
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
            field_options json DEFAULT NULL,
            validation_rules json DEFAULT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_required tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_audience_id (audience_id),
            KEY idx_field_key (field_key),
            KEY idx_sort_order (audience_id, sort_order),
            KEY idx_group_sort (audience_id, field_group, sort_order),
            KEY idx_source (audience_id, field_source)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );

		// Verify at least one of the new columns was added.
		if ( self::column_exists( $table_name, 'field_group' ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: table name */
					__( 'Table %s upgraded with dynamic field columns.', 'ffcertificate' ),
					$table_name
				),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: table name */
				__( 'Failed to upgrade table %s.', 'ffcertificate' ),
				$table_name
			),
		);
	}

	/**
	 * Upgrade ffc_reregistration_submissions table: adds auth_code and magic_token.
	 *
	 * @return array{success: bool, message: string}
	 */
	private static function upgrade_reregistration_submissions_table(): array {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'ffc_reregistration_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reregistration_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            data json DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            submitted_at datetime DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            auth_code varchar(20) DEFAULT NULL,
            magic_token varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_auth_code (auth_code),
            KEY idx_magic_token (magic_token)
        ) {$charset_collate};";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );

		if ( self::column_exists( $table_name, 'auth_code' ) && self::column_exists( $table_name, 'magic_token' ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: table name */
					__( 'Table %s upgraded with auth_code/magic_token columns.', 'ffcertificate' ),
					$table_name
				),
			);
		}

		return array(
			'success' => false,
			'message' => sprintf(
				/* translators: %s: table name */
				__( 'Failed to add auth_code/magic_token to table %s.', 'ffcertificate' ),
				$table_name
			),
		);
	}

	/**
	 * Seed standard reregistration fields for all existing audiences.
	 *
	 * @return array{success: bool, message: string, seeded: int}
	 */
	private static function seed_standard_fields_all_audiences(): array {
		if ( ! class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' ) ) {
			return array(
				'success' => true,
				'message' => __( 'Seeder class not available (will seed on next run).', 'ffcertificate' ),
				'seeded'  => 0,
			);
		}

		$seeded = \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::seed_all_existing_audiences();

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of seeded fields */
				__( 'Seeded %d standard fields.', 'ffcertificate' ),
				$seeded
			),
			'seeded'  => $seeded,
		);
	}

	/**
	 * Check if a column exists in a table.
	 *
	 * @param string $table_name Fully-qualified table name.
	 * @param string $column     Column to check.
	 * @return bool
	 */
	private static function column_exists( string $table_name, string $column ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table_name,
				$column
			)
		);
		return ! empty( $result );
	}

	/**
	 * Get migration status information.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		global $wpdb;
		$custom_fields_table = $wpdb->prefix . 'ffc_custom_fields';
		$subs_table          = $wpdb->prefix . 'ffc_reregistration_submissions';

		return array(
			'completed' => self::is_completed(),
			'columns'   => array(
				'custom_fields.field_group'       => self::column_exists( $custom_fields_table, 'field_group' ),
				'custom_fields.field_source'      => self::column_exists( $custom_fields_table, 'field_source' ),
				'custom_fields.field_profile_key' => self::column_exists( $custom_fields_table, 'field_profile_key' ),
				'custom_fields.field_mask'        => self::column_exists( $custom_fields_table, 'field_mask' ),
				'custom_fields.is_sensitive'      => self::column_exists( $custom_fields_table, 'is_sensitive' ),
				'submissions.auth_code'           => self::column_exists( $subs_table, 'auth_code' ),
				'submissions.magic_token'         => self::column_exists( $subs_table, 'magic_token' ),
			),
		);
	}
}
