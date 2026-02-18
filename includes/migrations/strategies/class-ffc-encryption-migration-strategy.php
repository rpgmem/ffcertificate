<?php
declare(strict_types=1);

/**
 * EncryptionMigrationStrategy
 *
 * Strategy for encrypting sensitive data (LGPD compliance).
 * Encrypts email, cpf_rf, user_ip, and JSON data.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations\Strategies;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EncryptionMigrationStrategy implements MigrationStrategyInterface {

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
     * Calculate encryption migration status
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return array<string, mixed> Status information
     */
    public function calculate_status( string $migration_key, array $migration_config ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name ) );

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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $migrated = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
            WHERE (
                (email_encrypted IS NOT NULL AND email_encrypted != '')
                OR (data_encrypted IS NOT NULL AND data_encrypted != '')
                OR (email IS NULL AND data IS NULL AND user_ip IS NULL)
            )",
            $this->table_name
        ) );

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
     * @param array<string, mixed> $migration_config Migration configuration
     * @param int $batch_number Batch number
     * @return array<string, mixed> Execution result
     */
    public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
        global $wpdb;

        $batch_size = isset( $migration_config['batch_size'] ) ? intval( $migration_config['batch_size'] ) : 50;

        // Get submissions that need encryption
        // Always use OFFSET 0 because encrypted records won't appear in next query
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                WHERE (email_encrypted IS NULL OR email_encrypted = '')
                AND email IS NOT NULL
                LIMIT %d",
                $this->table_name,
                $batch_size
            ),
            ARRAY_A
        );

        if ( empty( $submissions ) ) {
            return array(
                'success' => true,
                'processed' => 0,
                'has_more' => false,
                'message' => __( 'No submissions to encrypt', 'ffcertificate' )
            );
        }

        $migrated = 0;
        $errors = array();
        $offset = 0;

        foreach ( $submissions as $submission ) {
            try {
                // Encrypt email
                $email_encrypted = null;
                $email_hash = null;
                if ( ! empty( $submission['email'] ) ) {
                    $email_encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $submission['email'] );
                    $email_hash = \FreeFormCertificate\Core\Encryption::hash( $submission['email'] );
                }

                // Encrypt CPF/RF (legacy + split columns)
                $cpf_rf_encrypted = null;
                $cpf_rf_hash = null;
                $cpf_split_encrypted = null;
                $cpf_split_hash = null;
                $rf_split_encrypted = null;
                $rf_split_hash = null;
                if ( ! empty( $submission['cpf_rf'] ) ) {
                    $clean_id = preg_replace( '/[^0-9]/', '', $submission['cpf_rf'] );
                    $cpf_rf_encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $clean_id );
                    $cpf_rf_hash = \FreeFormCertificate\Core\Encryption::hash( $clean_id );

                    // Also populate split columns
                    $id_len = strlen( $clean_id );
                    if ( $id_len === 11 ) {
                        $cpf_split_encrypted = $cpf_rf_encrypted;
                        $cpf_split_hash = $cpf_rf_hash;
                    } elseif ( $id_len === 7 ) {
                        $rf_split_encrypted = $cpf_rf_encrypted;
                        $rf_split_hash = $cpf_rf_hash;
                    } else {
                        $cpf_split_encrypted = $cpf_rf_encrypted;
                        $cpf_split_hash = $cpf_rf_hash;
                    }
                }

                // Encrypt IP
                $ip_encrypted = null;
                if ( ! empty( $submission['user_ip'] ) ) {
                    $ip_encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $submission['user_ip'] );
                }

                // Encrypt JSON data
                $data_encrypted = null;
                if ( ! empty( $submission['data'] ) ) {
                    $data_encrypted = \FreeFormCertificate\Core\Encryption::encrypt( $submission['data'] );
                }

                // Update database
                $update_data = array(
                    'email_encrypted'  => $email_encrypted,
                    'email_hash'       => $email_hash,
                    'cpf_rf_encrypted' => $cpf_rf_encrypted,
                    'cpf_rf_hash'      => $cpf_rf_hash,
                    'cpf_encrypted'    => $cpf_split_encrypted,
                    'cpf_hash'         => $cpf_split_hash,
                    'rf_encrypted'     => $rf_split_encrypted,
                    'rf_hash'          => $rf_split_hash,
                    'user_ip_encrypted' => $ip_encrypted,
                    'data_encrypted'   => $data_encrypted,
                );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $updated = $wpdb->update(
                    $this->table_name,
                    $update_data,
                    array( 'id' => $submission['id'] ),
                    array_fill( 0, count( $update_data ), '%s' ),
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
        if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log(
                'encryption_migration_batch',
                \FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
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
            /* translators: %d: number of records */
            'message' => sprintf( __( 'Encrypted %d submissions', 'ffcertificate' ), $migrated ),
            'errors' => $errors
        );
    }

    /**
     * Check if encryption migration can run
     *
     * @param string $migration_key Migration identifier
     * @param array<string, mixed> $migration_config Migration configuration
     * @return bool|WP_Error
     */
    public function can_run( string $migration_key, array $migration_config ) {
        // Check if FFC_Encryption class exists
        if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
            return new WP_Error(
                'encryption_class_missing',
                __( 'FFC_Encryption class not found. Please ensure class-ffc-encryption.php is loaded.', 'ffcertificate' )
            );
        }

        // Check if encryption is configured
        if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption keys not configured. WordPress SECURE_AUTH_KEY and LOGGED_IN_KEY are required.', 'ffcertificate' )
            );
        }

        return true;
    }

    /**
     * Count submissions pending encryption
     *
     * @return int Number of submissions without encrypted data
     */
    private function count_pending_encryption(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i
            WHERE (email_encrypted IS NULL OR email_encrypted = '')
            AND email IS NOT NULL",
            $this->table_name
        ) );
    }

    /**
     * Get strategy name
     *
     * @return string
     */
    public function get_name(): string {
        return __( 'Encryption Migration Strategy', 'ffcertificate' );
    }
}
