<?php
declare(strict_types=1);

/**
 * MigrationStatusCalculator
 *
 * ⭐ CRITICAL COMPONENT ⭐
 *
 * Replaces the MONOLITHIC 223-line get_migration_status() method
 * with a clean Strategy Pattern implementation.
 *
 * This class delegates status calculation to appropriate strategies,
 * reducing complexity from 223 lines of conditionals to ~50 lines of delegation.
 *
 * @since 3.1.0 (Migration Manager refactor - Phase 2)
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
     * Maps migration keys to their corresponding strategy instances.
     * This is where the 223-line conditional becomes simple delegation!
     *
     * @return void
     */
    private function initialize_strategies(): void {
        // Field migration strategy (handles email, cpf_rf, auth_code)
        $field_strategy = new \FreeFormCertificate\Migrations\Strategies\FieldMigrationStrategy( $this->registry );

        $this->strategies['email']     = $field_strategy;
        $this->strategies['cpf_rf']    = $field_strategy;
        $this->strategies['auth_code'] = $field_strategy;

        // Special migration strategies
        $this->strategies['magic_tokens']          = new \FreeFormCertificate\Migrations\Strategies\MagicTokenMigrationStrategy();
        $this->strategies['encrypt_sensitive_data'] = new \FreeFormCertificate\Migrations\Strategies\EncryptionMigrationStrategy();
        $this->strategies['cleanup_unencrypted']   = new \FreeFormCertificate\Migrations\Strategies\CleanupMigrationStrategy();

        $this->strategies['user_link'] = new \FreeFormCertificate\Migrations\Strategies\UserLinkMigrationStrategy();
        $this->strategies['name_normalization'] = new \FreeFormCertificate\Migrations\Strategies\NameNormalizationMigrationStrategy();
        $this->strategies['user_capabilities'] = new \FreeFormCertificate\Migrations\Strategies\UserCapabilitiesMigrationStrategy();

        // Allow plugins to register custom strategies
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        $this->strategies = apply_filters( 'ffcertificate_migration_strategies', $this->strategies );
    }

    /**
     * Calculate migration status
     *
     * ⭐ THIS METHOD REPLACES 223 LINES OF CONDITIONALS ⭐
     *
     * Instead of a giant if/elseif chain, we simply:
     * 1. Get the strategy for this migration
     * 2. Get the migration config
     * 3. Delegate to the strategy
     *
     * BEFORE: 223 lines of conditional logic
     * AFTER: ~10 lines of delegation
     *
     * @param string $migration_key Migration identifier
     * @return array<string, mixed>|WP_Error Status array or error
     */
    public function calculate( string $migration_key ) {
        // Validate migration exists
        if ( ! $this->registry->exists( $migration_key ) ) {
            return new WP_Error( 'invalid_migration', __( 'Migration not found', 'ffcertificate' ) );
        }

        // Handle data_cleanup special case (option-based, not strategy-based)
        if ( $migration_key === 'data_cleanup' ) {
            return $this->calculate_data_cleanup_status();
        }

        // Get strategy for this migration
        $strategy = $this->get_strategy_for_migration( $migration_key );

        if ( is_wp_error( $strategy ) ) {
            return $strategy;
        }

        // Get migration configuration
        $migration_config = $this->registry->get_migration( $migration_key );

        // Delegate to strategy (THIS IS THE MAGIC! 🎩✨)
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
     * Calculate data_cleanup status (special case - option-based)
     *
     * This migration doesn't follow the standard pattern, so we handle it separately.
     *
     * @return array<string, mixed> Status information
     */
    private function calculate_data_cleanup_status(): array {
        $completed = get_option( 'ffc_migration_data_cleanup_completed', false );

        return array(
            'total' => 0,
            'migrated' => $completed ? 1 : 0,
            'pending' => $completed ? 0 : 1,
            'percent' => $completed ? 100 : 0,
            'is_complete' => $completed
        );
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
        // Handle data_cleanup special case
        if ( $migration_key === 'data_cleanup' ) {
            return true; // Option-based, always can run
        }

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

        // Handle data_cleanup special case
        if ( $migration_key === 'data_cleanup' ) {
            return $this->execute_data_cleanup();
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
     * Execute data_cleanup migration (special case)
     *
     * @return array<string, mixed> Execution result
     */
    private function execute_data_cleanup(): array {
        // Mark as complete
        update_option( 'ffc_migration_data_cleanup_completed', true );

        return array(
            'success' => true,
            'processed' => 1,
            'has_more' => false,
            'message' => __( 'Data cleanup completed', 'ffcertificate' )
        );
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
