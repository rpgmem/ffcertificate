<?php
declare(strict_types=1);

/**
 * UserCapabilitiesMigrationStrategy
 *
 * Strategy for setting user capabilities based on their history.
 * Users with submissions get certificate capabilities.
 * Users with appointments get appointment capabilities.
 *
 * @since 4.4.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UserCapabilitiesMigrationStrategy implements MigrationStrategyInterface {

    /**
     * Calculate migration status
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return array Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        // Check if migration has been run
        $last_run = get_option( 'ffc_migration_user_capabilities_last_run', '' );
        $last_changes = get_option( 'ffc_migration_user_capabilities_changes', array() );

        // Get total ffc_user count
        $users = get_users(array(
            'role' => 'ffc_user',
            'fields' => 'ID',
        ));
        $total = count($users);

        // If migration was run, show as complete
        if ( ! empty( $last_run ) ) {
            return array(
                'total' => $total,
                'migrated' => $total,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true,
                'last_run' => $last_run,
                'changes_count' => count( $last_changes ),
            );
        }

        return array(
            'total' => $total,
            'migrated' => 0,
            'pending' => $total,
            'percent' => 0,
            'is_complete' => false,
        );
    }

    /**
     * Execute user capabilities migration
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @param int $batch_number Batch number (unused - processes all at once)
     * @return array Result array
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        $result = \FreeFormCertificate\Migrations\MigrationUserCapabilities::run(
            $migration_config['batch_size'] ?? 50,
            false // Not a dry run
        );

        return array(
            'success' => $result['success'],
            'processed' => $result['processed'],
            'message' => $result['message'],
        );
    }

    /**
     * Check if migration can be executed
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return bool|\\WP_Error True if can run, WP_Error if cannot
     */
    public function can_run( string $migration_key, array $migration_config ) {
        // Check if UserManager class is available
        if ( ! class_exists( '\\FreeFormCertificate\\UserDashboard\\UserManager' ) ) {
            return new \WP_Error(
                'user_manager_not_available',
                __( 'UserManager class not available.', 'ffcertificate' )
            );
        }

        return true;
    }

    /**
     * Get strategy name
     *
     * @return string Strategy name
     */
    public function get_name(): string {
        return __( 'User Capabilities Migration', 'ffcertificate' );
    }
}
