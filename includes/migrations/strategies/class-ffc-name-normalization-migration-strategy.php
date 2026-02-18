<?php
declare(strict_types=1);

/**
 * NameNormalizationMigrationStrategy
 *
 * Strategy for normalizing name fields in existing submissions.
 * Handles encrypted data: decrypts, normalizes Brazilian names, re-encrypts.
 *
 * @since 4.3.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NameNormalizationMigrationStrategy implements MigrationStrategyInterface {

    /**
     * @var string Database table name
     */
    private string $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        $this->table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
    }

    /**
     * Calculate migration status
     *
     * Since we can't easily determine which names need normalization without
     * decrypting all data, we track migration via an option flag.
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return array<string, mixed> Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        // Check if migration has been run
        $last_run = get_option( 'ffc_migration_name_normalization_last_run', '' );
        $last_changes = get_option( 'ffc_migration_name_normalization_changes', array() );

        // Get total submissions with encrypted data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
             WHERE data_encrypted IS NOT NULL
             AND data_encrypted != ''",
            $this->table_name
        ) );

        // If migration was run, show as complete
        if ( ! empty( $last_run ) ) {
            return array(
                'total' => (int) $total,
                'migrated' => (int) $total,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true,
                'last_run' => $last_run,
                'changes_count' => count( $last_changes ),
            );
        }

        return array(
            'total' => (int) $total,
            'migrated' => 0,
            'pending' => (int) $total,
            'percent' => 0,
            'is_complete' => false,
        );
    }

    /**
     * Execute name normalization migration
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @param int $batch_number Batch number (unused - processes all at once)
     * @return array<string, mixed> Result array
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        $result = \FreeFormCertificate\Migrations\MigrationNameNormalization::run(
            $migration_config['batch_size'] ?? 100,
            false // Not a dry run
        );

        // Mark migration as run
        if ( $result['success'] ) {
            update_option( 'ffc_migration_name_normalization_last_run', current_time( 'mysql' ) );
        }

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
     * @param array<string, mixed> $migration_config Migration configuration
     * @return bool|\WP_Error True if can run, WP_Error if cannot
     */
    public function can_run( string $migration_key, array $migration_config ) {
        // Check if encryption is configured
        if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
            return new \WP_Error(
                'encryption_not_available',
                __( 'Encryption class not available.', 'ffcertificate' )
            );
        }

        if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
            return new \WP_Error(
                'encryption_not_configured',
                __( 'Encryption is not configured. Cannot process encrypted data.', 'ffcertificate' )
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
        return __( 'Name Normalization Migration', 'ffcertificate' );
    }
}
