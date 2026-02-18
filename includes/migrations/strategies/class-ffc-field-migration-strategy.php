<?php
declare(strict_types=1);

/**
 * FieldMigrationStrategy
 *
 * Generic strategy for migrating fields from JSON data to dedicated columns.
 * Handles email, cpf_rf, auth_code, and any other configured field migrations.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations\Strategies;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class FieldMigrationStrategy implements MigrationStrategyInterface {

    /**
     * @var string Database table name
     */
    private string $table_name;

    /**
     * @var mixed Registry instance (accepts both old and new classes via alias)
     */
    private $registry;

    /**
     * Constructor
     *
     * @param mixed $registry Migration registry instance
     */
    public function __construct( $registry ) {
        global $wpdb;
        $this->table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $this->registry = $registry;
    }

    /**
     * Calculate migration status for a field
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return array<string, mixed> Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        $column = isset( $migration_config['column'] ) ? $migration_config['column'] : null;

        if ( ! $column ) {
            return array(
                'total'       => 0,
                'migrated'    => 0,
                'pending'     => 0,
                'percent'     => 0,
                'is_complete' => false,
                'error'       => __( 'Missing column configuration', 'ffcertificate' ),
            );
        }

        // Count total records
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name ) );

        if ( $total == 0 ) {
            return array(
                'total' => 0,
                'migrated' => 0,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true
            );
        }

        // Check for encrypted column equivalent (v2.10.0+)
        $encrypted_column = $column . '_encrypted';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $encrypted_column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s
            AND TABLE_NAME = %s
            AND COLUMN_NAME = %s",
            DB_NAME,
            $this->table_name,
            $encrypted_column
        ));

        // Count migrated records
        if ( $encrypted_column_exists ) {
            // If encrypted column exists, count records that have EITHER:
            // 1. Data in encrypted column (migrated with encryption)
            // 2. NULL in both columns (already cleaned up)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $migrated = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                WHERE (%i IS NOT NULL AND %i != '')
                OR (%i IS NULL AND %i IS NULL)",
                $this->table_name,
                $encrypted_column, $encrypted_column,
                $column, $encrypted_column
            ));
        } else {
            // No encrypted column, use old logic
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $migrated = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                WHERE %i IS NOT NULL AND %i != ''",
                $this->table_name,
                $column, $column
            ));
        }

        $pending = $total - $migrated;
        $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

        return array(
            'total' => $total,
            'migrated' => $migrated,
            'pending' => $pending,
            'percent' => $percent,
            'is_complete' => ( $pending == 0 )
        );
    }

    /**
     * Execute field migration for a batch
     *
     * @param string $migration_key Migration identifier (field key)
     * @param array<string, mixed> $migration_config Migration configuration
     * @param int $batch_number Batch number
     * @return array<string, mixed> Execution result
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        global $wpdb;

        // Get field definition
        $field_def = $this->registry->get_field_definition( $migration_key );

        if ( ! $field_def ) {
            return array(
                'success' => false,
                'processed' => 0,
                'message' => __( 'Field definition not found', 'ffcertificate' )
            );
        }

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 100;

        $column = $migration_config['column'];

        // Get submissions without migrated field value
        // Always use OFFSET 0 because migrated records won't appear in next query
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, data FROM %i
            WHERE (%i IS NULL OR %i = '')
            AND (data IS NOT NULL AND data != '' AND data != '[]' AND data != '{}')
            ORDER BY id ASC
            LIMIT %d",
            $this->table_name,
            $column, $column,
            $batch_size
        ), ARRAY_A );

        if ( empty( $submissions ) ) {
            return array(
                'success' => true,
                'processed' => 0,
                'has_more' => false,
                'message' => __( 'No submissions to process', 'ffcertificate' )
            );
        }

        $processed = 0;
        foreach ( $submissions as $submission ) {
            $data = json_decode( $submission['data'], true );

            if ( ! is_array( $data ) ) {
                continue;
            }

            // Extract field value using possible JSON keys
            $field_value = \FreeFormCertificate\Migrations\DataSanitizer::extract_field_from_json( $data, $field_def['json_keys'] );

            if ( empty( $field_value ) ) {
                continue;
            }

            // Sanitize value
            $sanitized_value = \FreeFormCertificate\Migrations\DataSanitizer::sanitize_field_value( $field_value, $field_def );

            // Update submission
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $updated = $wpdb->update(
                $this->table_name,
                array( $column => $sanitized_value ),
                array( 'id' => $submission['id'] ),
                array( '%s' ),
                array( '%d' )
            );

            if ( $updated !== false ) {
                $processed++;
            }
        }

        $has_more = count( $submissions ) === $batch_size;

        return array(
            'success' => true,
            'processed' => $processed,
            'has_more' => $has_more,
            /* translators: %d: number of records, %s: error message */
            'message' => sprintf( __( 'Migrated %1$d %2$s values', 'ffcertificate' ), $processed, $field_def['description'] )
        );
    }

    /**
     * Check if field migration can run
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( string $migration_key, array $migration_config ) {
        global $wpdb;

        $column = isset( $migration_config['column'] ) ? $migration_config['column'] : null;

        if ( ! $column ) {
            return new WP_Error( 'invalid_config', __( 'Missing column configuration', 'ffcertificate' ) );
        }

        // Check if column exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s
            AND TABLE_NAME = %s
            AND COLUMN_NAME = %s",
            DB_NAME,
            $this->table_name,
            $column
        ));

        if ( ! $column_exists ) {
            return new WP_Error(
                'missing_column',
                /* translators: %s: date/time */
                sprintf( __( '%s column does not exist. Please update the database schema first.', 'ffcertificate' ), $column )
            );
        }

        // Check if field definition exists
        $field_def = $this->registry->get_field_definition( $migration_key );

        if ( ! $field_def ) {
            return new WP_Error( 'missing_field_def', __( 'Field definition not found in registry', 'ffcertificate' ) );
        }

        return true;
    }

    /**
     * Get strategy name
     *
     * @return string
     */
    public function get_name(): string {
        return __( 'Field Migration Strategy', 'ffcertificate' );
    }
}
