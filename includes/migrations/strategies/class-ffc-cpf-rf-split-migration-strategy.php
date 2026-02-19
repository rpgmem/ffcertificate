<?php
declare(strict_types=1);

/**
 * CpfRfSplitMigrationStrategy
 *
 * Migrates data from the combined cpf_rf/cpf_rf_encrypted/cpf_rf_hash columns
 * into separate cpf and rf columns based on identifier length:
 *   - 11 digits (CPF) → cpf, cpf_encrypted, cpf_hash
 *   - 7 digits (RF)   → rf, rf_encrypted, rf_hash
 *
 * Copies existing encrypted/hash values (no re-encryption needed)
 * and NULLs out the legacy cpf_rf_* columns after migration.
 * Processes both submissions and appointments tables.
 *
 * @since 4.13.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CpfRfSplitMigrationStrategy implements MigrationStrategyInterface {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * CPF length (digits only)
     */
    private const CPF_LENGTH = 11;

    /**
     * RF length (digits only)
     */
    private const RF_LENGTH = 7;

    /**
     * @var string Submissions table name
     */
    private string $submissions_table;

    /**
     * @var string Appointments table name
     */
    private string $appointments_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->submissions_table = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $this->appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
    }

    /**
     * Calculate migration status across both tables
     *
     * A record is "pending" if it has cpf_rf_hash but neither cpf_hash nor rf_hash.
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return array<string, mixed> Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        $submissions_status = $this->count_table_status( $this->submissions_table );
        $appointments_status = $this->count_table_status( $this->appointments_table );

        $total = $submissions_status['total'] + $appointments_status['total'];
        $migrated = $submissions_status['migrated'] + $appointments_status['migrated'];
        $pending = $submissions_status['pending'] + $appointments_status['pending'];
        $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

        return array(
            'total'       => $total,
            'migrated'    => $migrated,
            'pending'     => $pending,
            'percent'     => round( $percent, 2 ),
            'is_complete' => ( $pending === 0 ),
        );
    }

    /**
     * Execute the migration for a batch of records
     *
     * Processes submissions first, then appointments.
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @param int $batch_number Batch number (unused — uses OFFSET 0 since migrated records won't reappear)
     * @return array<string, mixed> Execution result
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 50;

        $total_processed = 0;
        $all_errors = array();

        // Process submissions table
        $result = $this->process_table( $this->submissions_table, $batch_size );
        $total_processed += $result['processed'];
        if ( ! empty( $result['errors'] ) ) {
            $all_errors = array_merge( $all_errors, $result['errors'] );
        }

        // Process appointments table (if it exists)
        if ( self::table_exists( $this->appointments_table ) && self::column_exists( $this->appointments_table, 'cpf_rf_hash' ) ) {
            $result = $this->process_table( $this->appointments_table, $batch_size );
            $total_processed += $result['processed'];
            if ( ! empty( $result['errors'] ) ) {
                $all_errors = array_merge( $all_errors, $result['errors'] );
            }
        }

        // Check if there are more records to process
        $status = $this->calculate_status( $migration_key, $migration_config );
        $has_more = $status['pending'] > 0;

        // When all data is migrated, drop legacy columns
        if ( ! $has_more && count( $all_errors ) === 0 ) {
            $this->drop_legacy_columns();
        }

        return array(
            'success'   => count( $all_errors ) === 0,
            'processed' => $total_processed,
            'has_more'  => $has_more,
            /* translators: %d: number of records migrated */
            'message'   => sprintf( __( 'Split CPF/RF for %d records', 'ffcertificate' ), $total_processed ),
            'errors'    => $all_errors,
        );
    }

    /**
     * Check if migration can be executed
     *
     * Requires encryption to be configured (to decrypt cpf_rf_encrypted
     * when plain text is unavailable) and the new columns to exist.
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( string $migration_key, array $migration_config ) {
        if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
            return new WP_Error(
                'encryption_class_missing',
                __( 'Encryption class not found. Required for CPF/RF split migration.', 'ffcertificate' )
            );
        }

        if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption keys not configured. Required for CPF/RF split migration.', 'ffcertificate' )
            );
        }

        // Check that new columns exist in submissions table
        if ( ! self::column_exists( $this->submissions_table, 'cpf_hash' ) ||
             ! self::column_exists( $this->submissions_table, 'rf_hash' ) ) {
            return new WP_Error(
                'columns_missing',
                __( 'New cpf/rf columns not found. Please re-activate the plugin to create them.', 'ffcertificate' )
            );
        }

        return true;
    }

    /**
     * Get strategy name
     *
     * @return string
     */
    public function get_name(): string {
        return __( 'CPF/RF Split Migration', 'ffcertificate' );
    }

    /**
     * Count migration status for a single table
     *
     * - Total:    all rows in the table
     * - Migrated: rows that have cpf_hash OR rf_hash AND do NOT have cpf_rf_hash
     * - Pending:  rows that still have cpf_rf_hash (need migration)
     *
     * @param string $table_name Table to check
     * @return array{total: int, migrated: int, pending: int}
     */
    private function count_table_status( string $table_name ): array {
        global $wpdb;

        if ( ! self::table_exists( $table_name ) ) {
            return array( 'total' => 0, 'migrated' => 0, 'pending' => 0 );
        }

        // If cpf_rf_hash was already dropped, migration is complete for this table
        if ( ! self::column_exists( $table_name, 'cpf_rf_hash' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_name ) );
            return array( 'total' => $total, 'migrated' => $total, 'pending' => 0 );
        }

        if ( ! self::column_exists( $table_name, 'cpf_hash' ) ) {
            return array( 'total' => 0, 'migrated' => 0, 'pending' => 0 );
        }

        // Total = all rows in the table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i",
            $table_name
        ) );

        // Pending = rows that still have cpf_rf_hash (legacy data not yet split)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''",
            $table_name
        ) );

        $migrated = $total - $pending;

        return array( 'total' => $total, 'migrated' => $migrated, 'pending' => $pending );
    }

    /**
     * Process a batch of records from a single table
     *
     * For each record:
     * 1. Resolve the plain-text value to determine digit length
     * 2. Classify: 11 = CPF, 7 = RF
     * 3. Copy existing encrypted/hash to the appropriate new column (no re-encryption)
     * 4. NULL out the legacy cpf_rf_* columns
     *
     * @param string $table_name Table to process
     * @param int $batch_size Number of records per batch
     * @return array{processed: int, errors: string[]}
     */
    private function process_table( string $table_name, int $batch_size ): array {
        global $wpdb;

        $processed = 0;
        $errors = array();

        // Get records that have cpf_rf data but haven't been split yet
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, cpf_rf, cpf_rf_encrypted, cpf_rf_hash
             FROM %i
             WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''
             AND (cpf_hash IS NULL OR cpf_hash = '')
             AND (rf_hash IS NULL OR rf_hash = '')
             LIMIT %d",
            $table_name,
            $batch_size
        ), ARRAY_A );

        if ( empty( $records ) ) {
            return array( 'processed' => 0, 'errors' => array() );
        }

        foreach ( $records as $record ) {
            try {
                $plain_value = $this->resolve_plain_value( $record );

                if ( empty( $plain_value ) ) {
                    $errors[] = sprintf( 'Could not resolve plain value for ID %d in %s', $record['id'], $table_name );
                    continue;
                }

                $digits = preg_replace( '/[^0-9]/', '', $plain_value );
                $len = strlen( $digits );

                // Copy existing encrypted/hash to the correct split column — no re-encryption
                // Then NULL out legacy cpf_rf_* columns
                $update_data = array(
                    'cpf_rf'           => null,
                    'cpf_rf_encrypted' => null,
                    'cpf_rf_hash'      => null,
                );

                if ( $len === self::RF_LENGTH ) {
                    // It's an RF
                    $update_data['rf_encrypted'] = $record['cpf_rf_encrypted'];
                    $update_data['rf_hash']      = $record['cpf_rf_hash'];
                } elseif ( $len === self::CPF_LENGTH ) {
                    // It's a CPF
                    $update_data['cpf_encrypted'] = $record['cpf_rf_encrypted'];
                    $update_data['cpf_hash']      = $record['cpf_rf_hash'];
                } else {
                    // Unknown length — default to CPF, log warning
                    $update_data['cpf_encrypted'] = $record['cpf_rf_encrypted'];
                    $update_data['cpf_hash']      = $record['cpf_rf_hash'];

                    if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
                        \FreeFormCertificate\Core\ActivityLog::log(
                            'cpf_rf_split_unknown_length',
                            \FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
                            array( 'id' => $record['id'], 'table' => $table_name, 'length' => $len )
                        );
                    }
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $updated = $wpdb->update(
                    $table_name,
                    $update_data,
                    array( 'id' => $record['id'] ),
                    array_fill( 0, count( $update_data ), '%s' ),
                    array( '%d' )
                );

                if ( $updated !== false ) {
                    $processed++;
                } else {
                    $errors[] = sprintf(
                        'Failed to update ID %d in %s: %s',
                        $record['id'],
                        $table_name,
                        $wpdb->last_error
                    );
                }
            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    'Error processing ID %d in %s: %s',
                    $record['id'],
                    $table_name,
                    $e->getMessage()
                );
            }
        }

        // Log batch result
        if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'cpf_rf_split_migration_batch',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array(
                    'table'     => $table_name,
                    'processed' => $processed,
                    'errors'    => count( $errors ),
                )
            );
        }

        return array( 'processed' => $processed, 'errors' => $errors );
    }

    /**
     * Resolve the plain-text identifier from a record
     *
     * Tries in order:
     * 1. Plain cpf_rf column (legacy unencrypted)
     * 2. Decrypt cpf_rf_encrypted
     *
     * @param array<string, mixed> $record Database row
     * @return string|null Clean digits or null
     */
    private function resolve_plain_value( array $record ): ?string {
        // Try plain text first (legacy)
        if ( ! empty( $record['cpf_rf'] ) ) {
            return $record['cpf_rf'];
        }

        // Decrypt encrypted value
        if ( ! empty( $record['cpf_rf_encrypted'] ) ) {
            return \FreeFormCertificate\Core\Encryption::decrypt( $record['cpf_rf_encrypted'] );
        }

        return null;
    }

    /**
     * Drop legacy columns after migration completes
     *
     * Removes cpf_rf, cpf_rf_encrypted, cpf_rf_hash, user_ip, and email
     * plaintext columns from both submissions and appointments tables.
     * These are replaced by the split cpf/rf columns and their encrypted
     * counterparts (email_encrypted, user_ip_encrypted).
     *
     * Safe to call multiple times (idempotent).
     *
     * @since 4.14.0
     * @return void
     */
    private function drop_legacy_columns(): void {
        $columns_to_drop = array( 'cpf_rf', 'cpf_rf_encrypted', 'cpf_rf_hash', 'user_ip', 'email' );

        $tables = array( $this->submissions_table );
        if ( self::table_exists( $this->appointments_table ) ) {
            $tables[] = $this->appointments_table;
        }

        foreach ( $tables as $table ) {
            self::drop_columns_if_exist( $table, $columns_to_drop );
        }

        if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'legacy_columns_dropped',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
                array( 'columns' => $columns_to_drop, 'tables' => $tables )
            );
        }
    }

    /**
     * Drop columns from a table if they exist (idempotent)
     *
     * @param string $table Table name
     * @param array<int, string> $columns Column names to drop
     * @return void
     */
    private static function drop_columns_if_exist( string $table, array $columns ): void {
        global $wpdb;

        foreach ( $columns as $column ) {
            if ( self::column_exists( $table, $column ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $column ) );
            }
        }

        // Also drop indexes that reference these columns
        $indexes_to_drop = array( 'cpf_rf', 'cpf_rf_hash', 'email', 'idx_form_cpf' );
        foreach ( $indexes_to_drop as $index_name ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $index_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
                DB_NAME, $table, $index_name
            ) );

            if ( (int) $index_exists > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table, $index_name ) );
            }
        }
    }
}
