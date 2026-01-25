<?php
declare(strict_types=1);

/**
 * FFC_Cleanup_Migration_Strategy
 *
 * Strategy for cleaning up unencrypted data after encryption (LGPD compliance).
 * Nullifies email, cpf_rf, user_ip, and data columns for submissions 15+ days old
 * that have been successfully encrypted. Also normalizes ALL empty strings to NULL
 * for data consistency.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 3.3.0 - Added strict types and type hints
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Cleanup_Migration_Strategy implements FFC_Migration_Strategy {

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
     * Calculate cleanup migration status
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return array Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        // Count submissions eligible for cleanup (15+ days old with encrypted data)
        $total_eligible = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)"
        );

        if ( $total_eligible == 0 ) {
            return array(
                'total' => 0,
                'migrated' => 0,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true,
                'message' => __( 'No submissions older than 15 days with encrypted data', 'ffc' )
            );
        }

        // Count how many already have NULL in old columns
        $cleaned = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND email IS NULL
            AND data IS NULL
            AND user_ip IS NULL
            AND cpf_rf IS NULL"
        );

        $pending = $total_eligible - $cleaned;
        $percent = ( $total_eligible > 0 ) ? ( $cleaned / $total_eligible ) * 100 : 100;

        return array(
            'total' => $total_eligible,
            'migrated' => $cleaned,
            'pending' => $pending,
            'percent' => round( $percent, 2 ),
            'is_complete' => ( $pending == 0 ),
            'message' => sprintf(
                __( '%d submissions eligible for cleanup (15+ days old with encrypted data)', 'ffc' ),
                $total_eligible
            )
        );
    }

    /**
     * Execute cleanup for a batch
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @param int $batch_number Batch number
     * @return array Execution result
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        global $wpdb;

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 100;

        // STEP 0: On first batch, normalize empty strings to NULL BEFORE cleanup
        // This prevents issues with columns that have empty strings instead of NULL
        if ( $batch_number === 0 ) {
            $normalized = $this->normalize_empty_strings_to_null();
            if ( $normalized !== false && $normalized > 0 ) {
                error_log( sprintf( 'FFC Cleanup: Normalized %d empty strings to NULL', $normalized ) );
            }
        }

        // STEP 1: Get submissions eligible for cleanup (15+ days old, encrypted, still have plain data)
        // Always use OFFSET 0 because cleaned records won't appear in next query
        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$this->table_name}
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND (email IS NOT NULL OR data IS NOT NULL OR user_ip IS NOT NULL OR cpf_rf IS NOT NULL)
            ORDER BY id ASC
            LIMIT %d",
            $batch_size
        ) );

        if ( empty( $submissions ) ) {
            return array(
                'success' => true,
                'processed' => 0,
                'has_more' => false,
                'message' => __( 'No submissions to cleanup', 'ffc' )
            );
        }

        // STEP 2: Clean submissions in batch using single UPDATE query
        $ids = array_map( function( $s ) { return intval( $s->id ); }, $submissions );
        $ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name}
            SET
                email = NULL,
                cpf_rf = NULL,
                user_ip = NULL,
                data = NULL
            WHERE id IN ($ids_placeholder)",
            $ids
        ) );

        $cleaned = ( $result !== false ) ? count( $ids ) : 0;

        // Count remaining
        $remaining = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND (email IS NOT NULL OR data IS NOT NULL OR user_ip IS NOT NULL OR cpf_rf IS NOT NULL)"
        );

        $has_more = $remaining > 0;

        return array(
            'success' => ( $result !== false ),
            'processed' => $cleaned,
            'has_more' => $has_more,
            'message' => sprintf( __( 'Cleaned %d submissions', 'ffc' ), $cleaned ),
            'errors' => ( $result === false ) ? array( $wpdb->last_error ) : array()
        );
    }

    /**
     * Normalize all empty strings to NULL for data consistency
     * Uses NULLIF for cleaner, more efficient SQL
     *
     * @return int|false Number of rows affected or false on error
     */
    private function normalize_empty_strings_to_null() {
        global $wpdb;

        // Normalize ALL empty strings to NULL (data consistency)
        // This ensures all empty values across ALL columns are truly NULL, not empty strings
        // Using NULLIF is more concise and performant than CASE WHEN
        return $wpdb->query(
            "UPDATE {$this->table_name}
            SET
                data = NULLIF(data, ''),
                user_ip = NULLIF(user_ip, ''),
                email = NULLIF(email, ''),
                magic_token = NULLIF(magic_token, ''),
                cpf_rf = NULLIF(cpf_rf, ''),
                auth_code = NULLIF(auth_code, ''),
                email_encrypted = NULLIF(email_encrypted, ''),
                email_hash = NULLIF(email_hash, ''),
                cpf_rf_encrypted = NULLIF(cpf_rf_encrypted, ''),
                cpf_rf_hash = NULLIF(cpf_rf_hash, ''),
                user_ip_encrypted = NULLIF(user_ip_encrypted, ''),
                data_encrypted = NULLIF(data_encrypted, ''),
                consent_ip = NULLIF(consent_ip, ''),
                consent_text = NULLIF(consent_text, ''),
                qr_code_cache = NULLIF(qr_code_cache, '')
            WHERE
                data = '' OR user_ip = '' OR email = '' OR magic_token = '' OR
                cpf_rf = '' OR auth_code = '' OR email_encrypted = '' OR email_hash = '' OR
                cpf_rf_encrypted = '' OR cpf_rf_hash = '' OR user_ip_encrypted = '' OR
                data_encrypted = '' OR consent_ip = '' OR consent_text = '' OR qr_code_cache = ''"
        );
    }

    /**
     * Check if cleanup migration can run
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( string $migration_key, array $migration_config ) {
        // Check if FFC_Encryption class exists
        if ( ! class_exists( 'FFC_Encryption' ) ) {
            return new WP_Error(
                'encryption_class_missing',
                __( 'FFC_Encryption class required for cleanup. Encrypt data first.', 'ffc' )
            );
        }

        // Check if encryption is configured
        if ( ! FFC_Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption must be configured before cleanup.', 'ffc' )
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
        return __( 'Cleanup Unencrypted Data Strategy', 'ffc' );
    }
}
