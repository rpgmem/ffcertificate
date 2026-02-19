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
     * @var array<string, string> Initialization errors per strategy key
     */
    private $strategy_errors = array();

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
        $this->try_create_strategy( 'split_cpf_rf' );

        // Allow plugins to register custom strategies
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        $this->strategies = apply_filters( 'ffcertificate_migration_strategies', $this->strategies );
    }

    /**
     * Try to create a strategy instance
     *
     * If the strategy already exists, does nothing.
     * If creation fails, logs the error for later reporting.
     *
     * @param string $migration_key Migration identifier
     * @return void
     */
    private function try_create_strategy( string $migration_key ): void {
        if ( isset( $this->strategies[ $migration_key ] ) ) {
            return;
        }

        try {
            switch ( $migration_key ) {
                case 'split_cpf_rf':
                    // Load strategy files explicitly — use include (not require_once)
                    // because a prior autoloader attempt may have marked the file as
                    // "already included" even though the class definition failed.
                    $strategy_dir = __DIR__ . '/strategies/';
                    $core_dir     = dirname( __DIR__ ) . '/core/';

                    if ( ! trait_exists( '\\FreeFormCertificate\\Core\\DatabaseHelperTrait', false ) ) {
                        $trait_file = $core_dir . 'class-ffc-database-helper-trait.php';
                        if ( file_exists( $trait_file ) ) {
                            include $trait_file;
                        }
                    }
                    if ( ! interface_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\MigrationStrategyInterface', false ) ) {
                        $iface_file = $strategy_dir . 'interface-ffc-migration-strategy-interface.php';
                        if ( file_exists( $iface_file ) ) {
                            include $iface_file;
                        }
                    }
                    if ( ! class_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\CpfRfSplitMigrationStrategy', false ) ) {
                        $class_file = $strategy_dir . 'class-ffc-cpf-rf-split-migration-strategy.php';
                        if ( file_exists( $class_file ) ) {
                            include $class_file;
                        }
                    }

                    // If class still not found after explicit loading, provide diagnostic info
                    if ( ! class_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\CpfRfSplitMigrationStrategy', false ) ) {
                        throw new \RuntimeException( sprintf(
                            'CpfRfSplitMigrationStrategy not defined after include. file_exists=%s, trait=%s, interface=%s, path=%s',
                            file_exists( $strategy_dir . 'class-ffc-cpf-rf-split-migration-strategy.php' ) ? 'yes' : 'no',
                            trait_exists( '\\FreeFormCertificate\\Core\\DatabaseHelperTrait', false ) ? 'yes' : 'no',
                            interface_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\MigrationStrategyInterface', false ) ? 'yes' : 'no',
                            $strategy_dir . 'class-ffc-cpf-rf-split-migration-strategy.php'
                        ) );
                    }

                    $this->strategies['split_cpf_rf'] = new \FreeFormCertificate\Migrations\Strategies\CpfRfSplitMigrationStrategy();
                    unset( $this->strategy_errors['split_cpf_rf'] );
                    break;
            }
        } catch ( \Throwable $e ) {
            $this->strategy_errors[ $migration_key ] = $e->getMessage();
            if ( class_exists( '\\FreeFormCertificate\\Core\\Utils' ) ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Failed to initialize migration strategy', array(
                    'key'   => $migration_key,
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ) );
            }
        }
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
        // Lazy retry: if strategy wasn't loaded during init, try again now
        if ( ! isset( $this->strategies[ $migration_key ] ) ) {
            $this->try_create_strategy( $migration_key );
        }

        if ( ! isset( $this->strategies[ $migration_key ] ) ) {
            $error_detail = isset( $this->strategy_errors[ $migration_key ] )
                ? ': ' . $this->strategy_errors[ $migration_key ]
                : '';
            return new WP_Error(
                'strategy_not_found',
                /* translators: %s: migration key and optional error detail */
                sprintf( __( 'No strategy found for migration: %s', 'ffcertificate' ), $migration_key ) . $error_detail
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
