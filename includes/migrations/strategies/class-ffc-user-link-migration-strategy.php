<?php
declare(strict_types=1);

/**
 * UserLinkMigrationStrategy
 *
 * Strategy for linking submissions to WordPress users.
 * Adds user_id column and links existing submissions based on CPF/RF and email.
 *
 * @since 3.1.1
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations\Strategies;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class UserLinkMigrationStrategy implements MigrationStrategyInterface {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * @var string Database table name
     */
    private string $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = \FreeFormCertificate\Core\Utils::get_submissions_table();
    }

    /**
     * Calculate user link migration status
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return array Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        if ( !self::column_exists( $this->table_name, 'user_id' ) ) {
            // Column doesn't exist yet - all records pending
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
            return array(
                'total' => $total,
                'migrated' => 0,
                'pending' => $total,
                'percent' => 0,
                'is_complete' => false
            );
        }

        // Count total submissions with CPF/RF
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE cpf_rf_hash IS NOT NULL
             AND cpf_rf_hash != ''"
        );

        if ( $total == 0 ) {
            return array(
                'total' => 0,
                'migrated' => 0,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true
            );
        }

        // Count submissions already linked to users
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $migrated = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE cpf_rf_hash IS NOT NULL
             AND cpf_rf_hash != ''
             AND user_id IS NOT NULL"
        );

        $pending = $total - $migrated;
        $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

        return array(
            'total' => (int) $total,
            'migrated' => (int) $migrated,
            'pending' => (int) $pending,
            'percent' => round( $percent, 2 ),
            'is_complete' => ( $pending == 0 )
        );
    }

    /**
     * Execute user linking migration
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @param int $batch_number Batch number (unused for this migration)
     * @return array Result array
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        // Autoloader handles class loading
        $result = \FreeFormCertificate\Migrations\MigrationUserLink::run();

        return array(
            'success' => $result['success'],
            'processed' => $result['processed'],
            'message' => $result['message']
        );
    }

    /**
     * Check if migration can be executed
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return bool|WP_Error True if can run, WP_Error if cannot
     */
    public function can_run( string $migration_key, array $migration_config ) {
        // Check if encryption is configured (required for decrypting emails)
        if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
            return new WP_Error(
                'encryption_not_available',
                __( 'Encryption class not available. Cannot link users without decrypting emails.', 'ffcertificate' )
            );
        }

        if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption is not configured. Please configure encryption keys first.', 'ffcertificate' )
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
        return __( 'User Link Migration', 'ffcertificate' );
    }
}
