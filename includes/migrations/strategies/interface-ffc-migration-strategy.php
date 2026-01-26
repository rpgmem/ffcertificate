<?php
/**
 * MigrationStrategyInterface
 *
 * Defines the contract that all migration strategies must follow.
 * Part of the Strategy Pattern implementation for migration system refactoring.
 *
 * @since 3.1.0 (Migration Manager refactor)
 * @version 3.3.0 - Added type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations\Strategies;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface MigrationStrategyInterface {

    /**
     * Calculate migration status
     *
     * Returns information about total records, migrated count, pending count,
     * completion percentage, and whether migration is complete.
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration from registry
     * @return array Status array with keys: total, migrated, pending, percent, is_complete
     */
    public function calculate_status( string $migration_key, array $migration_config ): array;

    /**
     * Execute the migration for a batch of records
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration from registry
     * @param int $batch_number Batch number to process (0-indexed)
     * @return array Result array with keys: success, processed, message
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array;

    /**
     * Check if migration can be executed
     *
     * Validates prerequisites like required database columns, class availability, etc.
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration from registry
     * @return bool|WP_Error True if can run, WP_Error with reason if cannot
     */
    public function can_run( string $migration_key, array $migration_config );

    /**
     * Get human-readable name for this strategy
     *
     * @return string Strategy name
     */
    public function get_name(): string;
}
