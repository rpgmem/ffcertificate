<?php
declare(strict_types=1);

/**
 * CpfRfSplitMigrationStrategy
 *
 * Migrates data from the combined cpf_rf/cpf_rf_encrypted/cpf_rf_hash columns
 * into separate cpf_*/rf_* columns based on identifier length:
 *   - 11 digits (CPF) → cpf, cpf_encrypted, cpf_hash
 *   - 7 digits (RF)   → rf, rf_encrypted, rf_hash
 *
 * After migration, the old cpf_rf columns are set to NULL.
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
        $pending = $total - $migrated;
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
     * Requires encryption to be configured (to decrypt cpf_rf_encrypted)
     * and the new columns to exist.
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
     * @param string $table_name Table to check
     * @return array{total: int, migrated: int}
     */
    private function count_table_status( string $table_name ): array {
        global $wpdb;

        if ( ! self::table_exists( $table_name ) || ! self::column_exists( $table_name, 'cpf_rf_hash' ) ) {
            return array( 'total' => 0, 'migrated' => 0 );
        }

        // Check if new columns exist
        $has_new_columns = self::column_exists( $table_name, 'cpf_hash' );
        if ( ! $has_new_columns ) {
            return array( 'total' => 0, 'migrated' => 0 );
        }

        // Total = records that have cpf_rf data (encrypted or hash)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''",
            $table_name
        ) );

        // Migrated = records that already have cpf_hash OR rf_hash populated
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $migrated = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''
             AND (
                 (cpf_hash IS NOT NULL AND cpf_hash != '')
                 OR (rf_hash IS NOT NULL AND rf_hash != '')
             )",
            $table_name
        ) );

        return array( 'total' => $total, 'migrated' => $migrated );
    }

    /**
     * Process a batch of records from a single table
     *
     * For each record:
     * 1. Decrypt cpf_rf_encrypted to get the plain value
     * 2. Classify by digit length: 11 = CPF, 7 = RF
     * 3. Encrypt and hash into the appropriate new columns
     * 4. NULL out the old cpf_rf columns
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

                $update_data = array(
                    'cpf_rf'           => null,
                    'cpf_rf_encrypted' => null,
                    'cpf_rf_hash'      => null,
                );

                if ( $len === self::CPF_LENGTH ) {
                    // It's a CPF
                    $update_data['cpf']           = null; // Don't store plain text
                    $update_data['cpf_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $digits );
                    $update_data['cpf_hash']      = \FreeFormCertificate\Core\Encryption::hash( $digits );
                } elseif ( $len === self::RF_LENGTH ) {
                    // It's an RF
                    $update_data['rf']           = null; // Don't store plain text
                    $update_data['rf_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $digits );
                    $update_data['rf_hash']      = \FreeFormCertificate\Core\Encryption::hash( $digits );
                } else {
                    // Unknown length — store as CPF (most common) but log warning
                    $update_data['cpf']           = null;
                    $update_data['cpf_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $digits );
                    $update_data['cpf_hash']      = \FreeFormCertificate\Core\Encryption::hash( $digits );

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
}
