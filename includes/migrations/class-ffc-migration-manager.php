<?php
/**
 * MigrationManager (Facade)
 *
 * Delegates to specialized components:
 * - MigrationRegistry (configuration)
 * - MigrationStatusCalculator (status calculation with strategies)
 *
 * @since 2.9.13
 * @version 5.0.0 - Retired 10 completed migrations; removed encrypt/cleanup/drop methods
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @version 3.1.0 (Refactored)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MigrationManager {

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
        // Initialize components
        $this->registry = new MigrationRegistry();
        $this->status_calculator = new MigrationStatusCalculator( $this->registry );
    }

    /**
     * Get all registered migrations
     *
     * Delegates to Registry.
     *
     * @return array<string, mixed> Array of migration definitions
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
     * @param string $migration_key Migration identifier
     * @return array<string, mixed>|WP_Error Status array or error
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
     * @return array<string, mixed>|null Migration definition or null
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
     * @return array<string, mixed>|WP_Error Execution result
     */
    public function run_migration( string $migration_key, int $batch_number = 0 ) {
        return $this->status_calculator->execute( $migration_key, $batch_number );
    }
}
