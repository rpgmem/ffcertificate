<?php
/**
 * FFC_Encryption_Migration_Strategy
 *
 * Strategy for encrypting sensitive data (LGPD compliance).
 * Encrypts email, cpf_rf, user_ip, and JSON data.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Encryption_Migration_Strategy implements FFC_Migration_Strategy {

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
     * Calculate encryption migration status
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @return array Status information
     */
    public function calculate_status( $migration_key, $migration_config ) {
        global $wpdb;

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

        if ( $total == 0 ) {
            return array(
                'total' => 0,
                'migrated' => 0,
                'pending' => 0,
                'percent' => 100,
                'is_complete' => true
            );
        }

        // Count as migrated if:
        // 1. Has encrypted data (email_encrypted OR data_encrypted has data)
        // 2. OR all sensitive columns are NULL (already cleaned)
        $migrated = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE (
                (email_encrypted IS NOT NULL AND email_encrypted != '')
                OR (data_encrypted IS NOT NULL AND data_encrypted != '')
                OR (email IS NULL AND data IS NULL AND user_ip IS NULL)
            )"
        );

        $pending = $total - $migrated;
        $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

        return array(
            'total' => $total,
            'migrated' => $migrated,
            'pending' => $pending,
            'percent' => round( $percent, 2 ),
            'is_complete' => ( $pending == 0 )
        );
    }

    /**
     * Execute encryption for a batch
     *
     * @param string $migration_key Migration identifier
     * @param array $migration_config Migration configuration
     * @param int $batch_number Batch number
     * @return array Execution result
     */
    public function execute( $migration_key, $migration_config, $batch_number = 0 ) {
        global $wpdb;

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 50;
        $offset = $batch_number > 0 ? ( $batch_number - 1 ) * $batch_size : 0;

        // Get submissions that need encryption
        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE (email_encrypted IS NULL OR email_encrypted = '')
                AND email IS NOT NULL
                LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ),
            ARRAY_A
        );

        if ( empty( $submissions ) ) {
            return array(
                'success' => true,
                'processed' => 0,
                'has_more' => false,
                'message' => __( 'No submissions to encrypt', 'ffc' )
            );
        }

        $migrated = 0;
        $errors = array();

        foreach ( $submissions as $submission ) {
            try {
                // Encrypt email
                $email_encrypted = null;
                $email_hash = null;
                if ( ! empty( $submission['email'] ) ) {
                    $email_encrypted = FFC_Encryption::encrypt( $submission['email'] );
                    $email_hash = FFC_Encryption::hash( $submission['email'] );
                }

                // Encrypt CPF/RF
                $cpf_encrypted = null;
                $cpf_hash = null;
                if ( ! empty( $submission['cpf_rf'] ) ) {
                    $cpf_encrypted = FFC_Encryption::encrypt( $submission['cpf_rf'] );
                    $cpf_hash = FFC_Encryption::hash( $submission['cpf_rf'] );
                }

                // Encrypt IP
                $ip_encrypted = null;
                if ( ! empty( $submission['user_ip'] ) ) {
                    $ip_encrypted = FFC_Encryption::encrypt( $submission['user_ip'] );
                }

                // Encrypt JSON data
                $data_encrypted = null;
                if ( ! empty( $submission['data'] ) ) {
                    $data_encrypted = FFC_Encryption::encrypt( $submission['data'] );
                }

                // Update database
                $updated = $wpdb->update(
                    $this->table_name,
                    array(
                        'email_encrypted' => $email_encrypted,
                        'email_hash' => $email_hash,
                        'cpf_rf_encrypted' => $cpf_encrypted,
                        'cpf_rf_hash' => $cpf_hash,
                        'user_ip_encrypted' => $ip_encrypted,
                        'data_encrypted' => $data_encrypted
                    ),
                    array( 'id' => $submission['id'] ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );

                if ( $updated !== false ) {
                    $migrated++;
                } else {
                    $errors[] = sprintf(
                        'Failed to update submission ID %d: %s',
                        $submission['id'],
                        $wpdb->last_error
                    );
                }

            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    'Encryption error for submission ID %d: %s',
                    $submission['id'],
                    $e->getMessage()
                );
            }
        }

        // Log migration batch
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log(
                'encryption_migration_batch',
                FFC_Activity_Log::LEVEL_INFO,
                array(
                    'offset' => $offset,
                    'migrated' => $migrated,
                    'errors' => count( $errors )
                )
            );
        }

        // Calculate remaining
        $total_pending = $this->count_pending_encryption();
        $has_more = $total_pending > 0;

        // If migration complete, save completion date
        if ( ! $has_more ) {
            update_option( 'ffc_encryption_migration_completed_date', current_time( 'mysql' ) );
        }

        return array(
            'success' => count( $errors ) === 0,
            'processed' => $migrated,
            'has_more' => $has_more,
            'message' => sprintf( __( 'Encrypted %d submissions', 'ffc' ), $migrated ),
            'errors' => $errors
        );
    }

    /**
     * Check if encryption migration can run
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
                __( 'FFC_Encryption class not found. Please ensure class-ffc-encryption.php is loaded.', 'ffc' )
            );
        }

        // Check if encryption is configured
        if ( ! FFC_Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption keys not configured. WordPress SECURE_AUTH_KEY and LOGGED_IN_KEY are required.', 'ffc' )
            );
        }

        return true;
    }

    /**
     * Count submissions pending encryption
     *
     * @return int Number of submissions without encrypted data
     */
    private function count_pending_encryption() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}
            WHERE (email_encrypted IS NULL OR email_encrypted = '')
            AND email IS NOT NULL"
        );
    }

    /**
     * Get strategy name
     *
     * @return string
     */
    public function get_name() {
        return __( 'Encryption Migration Strategy', 'ffc' );
    }
}
