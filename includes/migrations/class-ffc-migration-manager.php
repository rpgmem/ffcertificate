<?php
/**
 * MigrationManager (Facade)
 *
 * ⭐ REFACTORED v3.1.0 - Strategy Pattern Implementation ⭐
 *
 * This class is now a FACADE that delegates to specialized components:
 * - MigrationRegistry (configuration)
 * - MigrationStatusCalculator (status calculation with strategies)
 * - DataSanitizer (utilities)
 * - Migration Strategies (execution)
 *
 * BEFORE Refactoring:
 * - 1,262 lines of monolithic code
 * - 23 methods mixing concerns
 * - 223-line get_migration_status() method
 * - God Class anti-pattern
 *
 * AFTER Refactoring:
 * - ~400 lines of clean delegation
 * - 13 public methods (same API)
 * - Strategy Pattern for extensibility
 * - High testability and maintainability
 *
 * @since 2.9.13
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @version 3.1.0 (Refactored)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MigrationManager {

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Migration registry instance
     *
     * @var MigrationRegistry
     */
    private $registry;

    /**
     * Status calculator instance
     *
     * @var MigrationStatusCalculator
     */
    private $status_calculator;

    /**
     * Constructor
     *
     * Initializes the facade and loads all required components.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // Autoloader handles all class loading
        // Initialize components
        $this->registry = new MigrationRegistry();
        $this->status_calculator = new MigrationStatusCalculator( $this->registry );
    }

    /**
     * Get all registered migrations
     *
     * Delegates to Registry.
     *
     * @return array Array of migration definitions
     */
    public function get_migrations(): array {
        return $this->registry->get_all_migrations();
    }

    /**
     * Check if a migration is available
     *
     * Delegates to Registry.
     *
     * @param string $migration_key Migration identifier
     * @return bool True if available
     */
    public function is_migration_available( string $migration_key ): bool {
        return $this->registry->is_available( $migration_key );
    }

    /**
     * Get migration status
     *
     * ⭐ BEFORE: 223 lines of nested conditionals
     * ⭐ AFTER: 1 line of delegation to Status Calculator
     *
     * This is the BIGGEST WIN of the refactoring!
     *
     * @param string $migration_key Migration identifier
     * @return array|WP_Error Status array or error
     */
    public function get_migration_status( string $migration_key ) {
        return $this->status_calculator->calculate( $migration_key );
    }

    /**
     * Get a single migration definition
     *
     * Delegates to Registry.
     *
     * @param string $migration_key Migration identifier
     * @return array|null Migration definition or null
     */
    public function get_migration( string $migration_key ): ?array {
        return $this->registry->get_migration( $migration_key );
    }

    /**
     * Check if migration can be executed
     *
     * Delegates to Status Calculator.
     *
     * @param string $migration_key Migration identifier
     * @return bool|WP_Error True if can run, WP_Error if cannot
     */
    public function can_run_migration( string $migration_key ) {
        return $this->status_calculator->can_run( $migration_key );
    }

    /**
     * Execute a migration
     *
     * Delegates to Status Calculator which delegates to appropriate Strategy.
     *
     * @param string $migration_key Migration identifier
     * @param int $batch_number Batch number to process (0-indexed)
     * @return array|WP_Error Execution result
     */
    public function run_migration( string $migration_key, int $batch_number = 0 ) {
        return $this->status_calculator->execute( $migration_key, $batch_number );
    }

    /**
     * Encrypt sensitive data (LGPD compliance)
     *
     * Convenience method that delegates to Encryption Strategy via Status Calculator.
     *
     * @param int $offset Record offset
     * @param int $limit Batch size
     * @return array|WP_Error Execution result
     */
    public function migrate_encryption( int $offset = 0, int $limit = 50 ) {
        // Calculate batch number from offset and limit
        $batch_number = ( $limit > 0 ) ? floor( $offset / $limit ) + 1 : 1;

        // Update migration config with custom limit
        add_filter( 'ffcertificate_migrations_registry', function( $migrations ) use ( $limit ) {
            if ( isset( $migrations['encrypt_sensitive_data'] ) ) {
                $migrations['encrypt_sensitive_data']['batch_size'] = $limit;
            }
            return $migrations;
        });

        // Delegate to Status Calculator → Encryption Strategy
        return $this->status_calculator->execute( 'encrypt_sensitive_data', $batch_number );
    }

    /**
     * Cleanup unencrypted data (15+ days old)
     *
     * Convenience method that delegates to Cleanup Strategy via Status Calculator.
     *
     * @param int $offset Record offset
     * @param int $limit Batch size
     * @return array Execution result
     */
    public function cleanup_unencrypted_data( int $offset = 0, int $limit = 100 ): array {
        // Calculate batch number from offset and limit
        $batch_number = ( $limit > 0 ) ? floor( $offset / $limit ) + 1 : 1;

        // Update migration config with custom limit
        add_filter( 'ffcertificate_migrations_registry', function( $migrations ) use ( $limit ) {
            if ( isset( $migrations['cleanup_unencrypted'] ) ) {
                $migrations['cleanup_unencrypted']['batch_size'] = $limit;
            }
            return $migrations;
        });

        // Delegate to Status Calculator → Cleanup Strategy
        return $this->status_calculator->execute( 'cleanup_unencrypted', $batch_number );
    }

    /**
     * Cleanup old data (NULLIFY unencrypted columns)
     *
     * Sets old columns to NULL after successful encryption migration.
     * This is REVERSIBLE - columns remain, just data is removed.
     *
     * @return array|WP_Error Result with cleaned count
     */
    public function cleanup_old_data() {
        global $wpdb;

        // Check if encryption migration is 100% complete
        $status = $this->get_migration_status( 'encrypt_sensitive_data' );

        if ( is_wp_error( $status ) ) {
            return $status;
        }

        if ( ! $status['is_complete'] ) {
            return new WP_Error(
                'encryption_not_complete',
                __( 'Encryption migration must be 100% complete before cleanup.', 'ffcertificate' )
            );
        }

        // Check 15-day grace period
        $encryption_completed_date = get_option( 'ffc_encryption_migration_completed_date' );

        if ( ! $encryption_completed_date ) {
            return new WP_Error(
                'no_completion_date',
                __( 'Encryption completion date not found.', 'ffcertificate' )
            );
        }

        $days_since_completion = floor( ( time() - strtotime( $encryption_completed_date ) ) / DAY_IN_SECONDS );

        if ( $days_since_completion < 15 ) {
            return new WP_Error(
                'grace_period_not_met',
                sprintf(
                    /* translators: %d: number of days remaining */
                    __( 'Must wait 15 days after encryption completion. Days remaining: %d', 'ffcertificate' ),
                    15 - $days_since_completion
                )
            );
        }

        // User confirmation required
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling admin action handler.
        if ( ! isset( $_POST['confirm_cleanup'] ) || sanitize_text_field( wp_unslash( $_POST['confirm_cleanup'] ) ) !== 'CONFIRMAR EXCLUSÃO' ) {
            return new WP_Error(
                'confirmation_required',
                __( 'User confirmation required. Type "CONFIRMAR EXCLUSÃO" to proceed.', 'ffcertificate' )
            );
        }

        // Execute cleanup via Cleanup Strategy
        $result = $this->cleanup_unencrypted_data( 0, 1000 ); // Large batch

        if ( ! is_wp_error( $result ) && $result['success'] ) {
            // Log cleanup action
            if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
                \FreeFormCertificate\Core\ActivityLog::log(
                    'data_cleanup_executed',
                    \FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
                    array(
                        'cleaned' => $result['processed'],
                        'user_id' => get_current_user_id()
                    )
                );
            }
        }

        return $result;
    }

    /**
     * Drop old columns (IRREVERSIBLE)
     *
     * Permanently removes old unencrypted columns from database.
     * Requires 30 days after encryption completion.
     *
     * @return array|WP_Error Result
     */
    public function drop_old_columns() {
        global $wpdb;

        // Verify can drop
        $can_drop = $this->can_drop_columns();

        if ( is_wp_error( $can_drop ) ) {
            return $can_drop;
        }

        // User confirmation required (IRREVERSIBLE operation!)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling admin action handler.
        if ( ! isset( $_POST['confirm_drop'] ) || sanitize_text_field( wp_unslash( $_POST['confirm_drop'] ) ) !== 'CONFIRMAR EXCLUSÃO' ) {
            return new WP_Error(
                'confirmation_required',
                __( 'CRITICAL: This is IRREVERSIBLE! Type "CONFIRMAR EXCLUSÃO" to proceed.', 'ffcertificate' )
            );
        }

        // Drop columns
        $columns_to_drop = array( 'email', 'cpf_rf', 'user_ip', 'data' );
        $dropped = array();
        $errors = array();

        foreach ( $columns_to_drop as $column ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query( "ALTER TABLE {$this->table_name} DROP COLUMN {$column}" );

            if ( $result !== false ) {
                $dropped[] = $column;
            } else {
                $errors[] = sprintf(
                    'Failed to drop column %s: %s',
                    $column,
                    $wpdb->last_error
                );
            }
        }

        // Log critical action
        if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'columns_dropped',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_CRITICAL,
                array(
                    'dropped_columns' => $dropped,
                    'errors' => $errors,
                    'user_id' => get_current_user_id()
                )
            );
        }

        // Save drop date
        if ( count( $dropped ) > 0 ) {
            update_option( 'ffc_columns_dropped_date', current_time( 'mysql' ) );
        }

        return array(
            'success' => count( $errors ) === 0,
            'dropped' => $dropped,
            'errors' => $errors
        );
    }

    /**
     * Check if old columns can be dropped
     *
     * Verifies 30-day grace period after encryption completion.
     *
     * @return bool|WP_Error True if can drop, WP_Error if cannot
     */
    public function can_drop_columns() {
        // Check encryption is complete
        $status = $this->get_migration_status( 'encrypt_sensitive_data' );

        if ( is_wp_error( $status ) ) {
            return $status;
        }

        if ( ! $status['is_complete'] ) {
            return new WP_Error(
                'encryption_not_complete',
                __( 'Encryption migration must be 100% complete.', 'ffcertificate' )
            );
        }

        // Check 30-day grace period
        $encryption_completed_date = get_option( 'ffc_encryption_migration_completed_date' );

        if ( ! $encryption_completed_date ) {
            return new WP_Error(
                'no_completion_date',
                __( 'Encryption completion date not found.', 'ffcertificate' )
            );
        }

        $days_since_completion = floor( ( time() - strtotime( $encryption_completed_date ) ) / DAY_IN_SECONDS );

        if ( $days_since_completion < 30 ) {
            return new WP_Error(
                'grace_period_not_met',
                sprintf(
                    /* translators: %d: number of days remaining */
                    __( 'Must wait 30 days after encryption completion. Days remaining: %d', 'ffcertificate' ),
                    30 - $days_since_completion
                )
            );
        }

        return true;
    }

    /**
     * Get remaining days before columns can be dropped
     *
     * @return int Days remaining (0 if can drop now)
     */
    public function get_drop_days_remaining(): int {
        $encryption_completed_date = get_option( 'ffc_encryption_migration_completed_date' );

        if ( ! $encryption_completed_date ) {
            return 30; // Max wait time
        }

        $days_since_completion = floor( ( time() - strtotime( $encryption_completed_date ) ) / DAY_IN_SECONDS );
        $days_remaining = max( 0, 30 - $days_since_completion );

        return $days_remaining;
    }
}
