<?php
declare(strict_types=1);

/**
 * FFC_Magic_Token_Migration_Strategy
 *
 * Strategy for generating unique magic tokens for secure certificate access.
 * Generates cryptographically secure tokens for submissions that don't have them.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 3.3.0 - Added strict types and type hints
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Magic_Token_Migration_Strategy implements FFC_Migration_Strategy {

    /**
     * @var string Database table name
     */
    private string $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = FFC_Utils::get_submissions_table();
    }

    /**
     * Calculate migration status
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return array Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
        $with_token = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE magic_token IS NOT NULL AND magic_token != ''" );

        $pending = $total - $with_token;
        $percent = ( $total > 0 ) ? ( $with_token / $total ) * 100 : 100;

        return array(
            'total' => $total,
            'migrated' => $with_token,
            'pending' => $pending,
            'percent' => $percent,
            'is_complete' => ( $pending == 0 )
        );
    }

    /**
     * Execute magic token generation for a batch
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @param int $batch_number Batch number
     * @return array Execution result
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        global $wpdb;

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 100;

        // Get submissions without magic tokens
        // Always use OFFSET 0 because processed records won't appear in next query
        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$this->table_name}
            WHERE (magic_token IS NULL OR magic_token = '')
            ORDER BY id ASC
            LIMIT %d",
            $batch_size
        ), ARRAY_A );

        if ( empty( $submissions ) ) {
            return array(
                'success' => true,
                'processed' => 0,
                'message' => __( 'No submissions to process', 'ffc' )
            );
        }

        $processed = 0;
        foreach ( $submissions as $submission ) {
            // Generate unique magic token
            $magic_token = bin2hex( random_bytes( 16 ) ); // 32 character hex string

            // Update submission
            $updated = $wpdb->update(
                $this->table_name,
                array( 'magic_token' => $magic_token ),
                array( 'id' => $submission['id'] ),
                array( '%s' ),
                array( '%d' )
            );

            if ( $updated !== false ) {
                $processed++;
            }
        }

        $has_more = count( $submissions ) === $batch_size;

        return array(
            'success' => true,
            'processed' => $processed,
            'has_more' => $has_more,
            'message' => sprintf( __( 'Generated %d magic tokens', 'ffc' ), $processed )
        );
    }

    /**
     * Check if migration can run
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( string $migration_key, array $migration_config ) {
        global $wpdb;

        // Check if magic_token column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = %s
            AND TABLE_NAME = %s
            AND COLUMN_NAME = %s",
            DB_NAME,
            $this->table_name,
            'magic_token'
        ));

        if ( ! $column_exists ) {
            return new WP_Error(
                'missing_column',
                __( 'magic_token column does not exist. Please update the database schema first.', 'ffc' )
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
        return __( 'Magic Token Generation Strategy', 'ffc' );
    }
}
