<?php
/**
 * FFC_Cleanup_Migration_Strategy
 *
 * Strategy for cleaning up unencrypted data after encryption (LGPD compliance).
 * Nullifies email, cpf_rf, user_ip, and data columns for submissions 15+ days old
 * that have been successfully encrypted. Also normalizes ALL empty strings to NULL
 * for data consistency.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 1.2.0 - Inverted execution order: cleanup first, then normalize ALL columns
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Cleanup_Migration_Strategy implements FFC_Migration_Strategy {

    /**
     * @var string Database table name
     */
    private $table_name;

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
    public function calculate_status( $migration_key, $migration_config ) {
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
    public function execute( $migration_key, $migration_config, $batch_number = 0 ) {
        global $wpdb;

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 100;

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

        $cleaned = 0;
        $errors = array();

        foreach ( $submissions as $submission ) {
            // Nullify unencrypted columns
            $result = $wpdb->update(
                $this->table_name,
                array(
                    'email'   => null,
                    'cpf_rf'  => null,
                    'user_ip' => null,
                    'data'    => null
                ),
                array( 'id' => $submission->id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            if ( $result !== false ) {
                $cleaned++;
            } else {
                $errors[] = sprintf(
                    'Failed to cleanup submission ID %d: %s',
                    $submission->id,
                    $wpdb->last_error
                );
            }
        }

        // STEP 2: Normalize ALL empty strings to NULL (data consistency)
        // This ensures all empty values across ALL columns are truly NULL, not empty strings
        // Only runs after cleanup to avoid normalizing data that will be deleted anyway
        $wpdb->query(
            "UPDATE {$this->table_name}
            SET data = CASE WHEN data = '' THEN NULL ELSE data END,
                user_ip = CASE WHEN user_ip = '' THEN NULL ELSE user_ip END,
                email = CASE WHEN email = '' THEN NULL ELSE email END,
                magic_token = CASE WHEN magic_token = '' THEN NULL ELSE magic_token END,
                cpf_rf = CASE WHEN cpf_rf = '' THEN NULL ELSE cpf_rf END,
                auth_code = CASE WHEN auth_code = '' THEN NULL ELSE auth_code END,
                email_encrypted = CASE WHEN email_encrypted = '' THEN NULL ELSE email_encrypted END,
                email_hash = CASE WHEN email_hash = '' THEN NULL ELSE email_hash END,
                cpf_rf_encrypted = CASE WHEN cpf_rf_encrypted = '' THEN NULL ELSE cpf_rf_encrypted END,
                cpf_rf_hash = CASE WHEN cpf_rf_hash = '' THEN NULL ELSE cpf_rf_hash END,
                user_ip_encrypted = CASE WHEN user_ip_encrypted = '' THEN NULL ELSE user_ip_encrypted END,
                data_encrypted = CASE WHEN data_encrypted = '' THEN NULL ELSE data_encrypted END,
                consent_ip = CASE WHEN consent_ip = '' THEN NULL ELSE consent_ip END,
                consent_text = CASE WHEN consent_text = '' THEN NULL ELSE consent_text END,
                qr_code_cache = CASE WHEN qr_code_cache = '' THEN NULL ELSE qr_code_cache END
            WHERE data = '' OR user_ip = '' OR email = '' OR magic_token = '' OR
                  cpf_rf = '' OR auth_code = '' OR email_encrypted = '' OR email_hash = '' OR
                  cpf_rf_encrypted = '' OR cpf_rf_hash = '' OR user_ip_encrypted = '' OR
                  data_encrypted = '' OR consent_ip = '' OR consent_text = '' OR qr_code_cache = ''"
        );

        // Count remaining
        $remaining = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND (email IS NOT NULL OR data IS NOT NULL OR user_ip IS NOT NULL OR cpf_rf IS NOT NULL)"
        );

        $has_more = $remaining > 0;

        return array(
            'success' => count( $errors ) === 0,
            'processed' => $cleaned,
            'has_more' => $has_more,
            'message' => sprintf( __( 'Cleaned %d submissions', 'ffc' ), $cleaned ),
            'errors' => $errors
        );
    }

    /**
     * Check if cleanup migration can run
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( $migration_key, $migration_config ) {
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
    public function get_name() {
        return __( 'Cleanup Unencrypted Data Strategy', 'ffc' );
    }
}
