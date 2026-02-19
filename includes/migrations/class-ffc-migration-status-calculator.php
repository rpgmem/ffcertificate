<?php
declare(strict_types=1);

/**
 * MigrationStatusCalculator
 *
 * Delegates status calculation to appropriate strategies.
 *
 * @since 3.1.0 (Migration Manager refactor - Phase 2)
 * @version 5.0.0 - Retired 10 completed migrations, kept only split_cpf_rf
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MigrationStatusCalculator {

    /**
     * @var MigrationRegistry
     */
    private $registry;

    /**
     * @var array<string, \FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface> Strategy instances mapped by migration key
     */
    private $strategies = array();

    /**
     * Constructor
     *
     * @param MigrationRegistry $registry Migration registry instance
     */
    public function __construct( MigrationRegistry $registry ) {
        $this->registry = $registry;

        // Initialize strategies
        $this->initialize_strategies();
    }

    /**
     * Initialize all migration strategies
     *
     * v5.0.0: Only split_cpf_rf remains after retiring 10 completed migrations.
     *
     * @return void
     */
    private function initialize_strategies(): void {
        try {
            $this->strategies['split_cpf_rf'] = new \FreeFormCertificate\Migrations\Strategies\CpfRfSplitMigrationStrategy();
        } catch ( \Throwable $e ) {
            // Strategy failed to initialize (e.g. missing table, opcache stale bytecode).
            // Log and continue — the tab will show the error gracefully.
            if ( class_exists( '\\FreeFormCertificate\\Core\\Utils' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Failed to initialize CpfRfSplitMigrationStrategy', array(
                    'error' => $e->getMessage(),
                ) );
            }
        }

        // Allow plugins to register custom strategies
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        $this->strategies = apply_filters( 'ffcertificate_migration_strategies', $this->strategies );
    }

    /**
     * Calculate migration status
     *
     * @param string $migration_key Migration identifier
     * @return array<string, mixed>|WP_Error Status array or error
     */
    public function calculate( string $migration_key ) {
        // Validate migration exists
        if ( ! $this->registry->exists( $migration_key ) ) {
            return new WP_Error( 'invalid_migration', __( 'Migration not found', 'ffcertificate' ) );
        }

        // Get strategy for this migration
        $strategy = $this->get_strategy_for_migration( $migration_key );

        if ( is_wp_error( $strategy ) ) {
            return $strategy;
        }

        // Get migration configuration
        $migration_config = $this->registry->get_migration( $migration_key );

        // Delegate to strategy
        return $strategy->calculate_status( $migration_key, $migration_config );
    }

    /**
     * Get strategy instance for a specific migration
     *
     * @param string $migration_key Migration identifier
     * @return \FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface|WP_Error Strategy instance or error
     */
    private function get_strategy_for_migration( string $migration_key ) {
        if ( ! isset( $this->strategies[ $migration_key ] ) ) {
            return new WP_Error(
                'strategy_not_found',
                /* translators: %s: migration key */
                sprintf( __( 'No strategy found for migration: %s', 'ffcertificate' ), $migration_key )
            );
        }

        return $this->strategies[ $migration_key ];
    }

    /**
     * Check if a migration can be executed
     *
     * Delegates to strategy's can_run() method.
     *
     * @param string $migration_key Migration identifier
     * @return bool|WP_Error True if can run, WP_Error if cannot
     */
    public function can_run( string $migration_key ) {
        // Get strategy
        $strategy = $this->get_strategy_for_migration( $migration_key );

        if ( is_wp_error( $strategy ) ) {
            return $strategy;
        }

        // Get migration configuration
        $migration_config = $this->registry->get_migration( $migration_key );

        // Delegate to strategy
        return $strategy->can_run( $migration_key, $migration_config );
    }

    /**
     * Execute a migration
     *
     * Delegates to strategy's execute() method.
     *
     * @param string $migration_key Migration identifier
     * @param int $batch_number Batch number to process
     * @return array<string, mixed>|WP_Error Execution result
     */
    public function execute( string $migration_key, int $batch_number = 0 ) {
        // Check if can run
        $can_run = $this->can_run( $migration_key );

        if ( is_wp_error( $can_run ) ) {
            return $can_run;
        }

        // Get strategy
        $strategy = $this->get_strategy_for_migration( $migration_key );

        if ( is_wp_error( $strategy ) ) {
            return $strategy;
        }

        // Get migration configuration
        $migration_config = $this->registry->get_migration( $migration_key );

        // Delegate to strategy
        return $strategy->execute( $migration_key, $migration_config, $batch_number );
    }

    /**
     * Get all registered strategies
     *
     * Useful for debugging and testing.
     *
     * @return array<string, \FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface> Strategy instances
     */
    public function get_strategies(): array {
        return $this->strategies;
    }
}
