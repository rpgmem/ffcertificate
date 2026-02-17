<?php
declare(strict_types=1);

/**
 * UserManager
 *
 * Manages user profile, data retrieval, and backward-compatible delegation
 * to CapabilityManager and UserCreator.
 *
 * Refactored in v4.12.2: user creation logic moved to UserCreator,
 * capability management moved to CapabilityManager.
 *
 * @version 4.12.2 - Split into UserManager + CapabilityManager + UserCreator
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.1.0
 */

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UserManager {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    // =====================================================================
    // Backward-compatible constant aliases → CapabilityManager
    // =====================================================================

    public const CONTEXT_CERTIFICATE = CapabilityManager::CONTEXT_CERTIFICATE;
    public const CONTEXT_APPOINTMENT = CapabilityManager::CONTEXT_APPOINTMENT;
    public const CONTEXT_AUDIENCE    = CapabilityManager::CONTEXT_AUDIENCE;

    public const CERTIFICATE_CAPABILITIES = CapabilityManager::CERTIFICATE_CAPABILITIES;
    public const APPOINTMENT_CAPABILITIES = CapabilityManager::APPOINTMENT_CAPABILITIES;
    public const AUDIENCE_CAPABILITIES    = CapabilityManager::AUDIENCE_CAPABILITIES;
    public const ADMIN_CAPABILITIES       = CapabilityManager::ADMIN_CAPABILITIES;
    public const FUTURE_CAPABILITIES      = CapabilityManager::FUTURE_CAPABILITIES;

    // =====================================================================
    // Backward-compatible delegation → UserCreator
    // =====================================================================

    /** @see UserCreator::get_or_create_user() */
    public static function get_or_create_user( string $cpf_rf_hash, string $email, array $submission_data = array(), string $context = self::CONTEXT_CERTIFICATE ) {
        return UserCreator::get_or_create_user( $cpf_rf_hash, $email, $submission_data, $context );
    }

    /** @see UserCreator::generate_username() */
    public static function generate_username( string $email, array $submission_data = array() ): string {
        return UserCreator::generate_username( $email, $submission_data );
    }

    // =====================================================================
    // Backward-compatible delegation → CapabilityManager
    // =====================================================================

    /** @see CapabilityManager::get_all_capabilities() */
    public static function get_all_capabilities(): array {
        return CapabilityManager::get_all_capabilities();
    }

    /** @see CapabilityManager::register_role() */
    public static function register_role(): void {
        CapabilityManager::register_role();
    }

    /** @see CapabilityManager::remove_role() */
    public static function remove_role(): void {
        CapabilityManager::remove_role();
    }

    /** @see CapabilityManager::grant_certificate_capabilities() */
    public static function grant_certificate_capabilities( int $user_id ): void {
        CapabilityManager::grant_certificate_capabilities( $user_id );
    }

    /** @see CapabilityManager::grant_appointment_capabilities() */
    public static function grant_appointment_capabilities( int $user_id ): void {
        CapabilityManager::grant_appointment_capabilities( $user_id );
    }

    /** @see CapabilityManager::grant_audience_capabilities() */
    public static function grant_audience_capabilities( int $user_id ): void {
        CapabilityManager::grant_audience_capabilities( $user_id );
    }

    /** @see CapabilityManager::has_certificate_access() */
    public static function has_certificate_access( int $user_id ): bool {
        return CapabilityManager::has_certificate_access( $user_id );
    }

    /** @see CapabilityManager::has_appointment_access() */
    public static function has_appointment_access( int $user_id ): bool {
        return CapabilityManager::has_appointment_access( $user_id );
    }

    /** @see CapabilityManager::get_user_ffc_capabilities() */
    public static function get_user_ffc_capabilities( int $user_id ): array {
        return CapabilityManager::get_user_ffc_capabilities( $user_id );
    }

    /** @see CapabilityManager::set_user_capability() */
    public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
        return CapabilityManager::set_user_capability( $user_id, $capability, $grant );
    }

    // =====================================================================
    // Profile & Data Retrieval (remain in UserManager)
    // =====================================================================

    /**
     * Get user profile from ffc_user_profiles
     *
     * @since 4.9.4
     * @param int $user_id WordPress user ID
     * @return array Profile data
     */
    public static function get_profile( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if ( self::table_exists( $table ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $profile = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM %i WHERE user_id = %d",
                $table,
                $user_id
            ), ARRAY_A );

            if ( $profile ) {
                return $profile;
            }
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return array();
        }

        return array(
            'user_id'      => $user_id,
            'display_name' => $user->display_name,
            'phone'        => '',
            'department'   => '',
            'organization' => '',
            'notes'        => '',
            'preferences'  => null,
            'created_at'   => $user->user_registered,
            'updated_at'   => $user->user_registered,
        );
    }

    /**
     * Update user profile in ffc_user_profiles
     *
     * @since 4.9.4
     * @param int   $user_id WordPress user ID
     * @param array $data    Profile fields to update
     * @return bool True on success
     */
    public static function update_profile( int $user_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        $allowed     = array( 'display_name', 'phone', 'department', 'organization', 'notes' );
        $update_data = array();
        $formats     = array();

        foreach ( $allowed as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $update_data[ $field ] = sanitize_text_field( $data[ $field ] );
                $formats[]             = '%s';
            }
        }

        if ( isset( $data['preferences'] ) && is_array( $data['preferences'] ) ) {
            $update_data['preferences'] = wp_json_encode( $data['preferences'] );
            $formats[]                  = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE user_id = %d",
            $table,
            $user_id
        ) );

        if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update( $table, $update_data, array( 'user_id' => $user_id ), $formats, array( '%d' ) );
        } else {
            $update_data['user_id']    = $user_id;
            $update_data['created_at'] = current_time( 'mysql' );
            $formats[]                 = '%d';
            $formats[]                 = '%s';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert( $table, $update_data, $formats );
        }

        if ( isset( $data['display_name'] ) ) {
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => sanitize_text_field( $data['display_name'] ),
            ) );
        }

        return $result !== false;
    }

    /**
     * Get user's CPF/RF (masked) — first found
     *
     * @param int $user_id WordPress user ID
     * @return string|null Masked CPF/RF or null
     */
    public static function get_user_cpf_masked( int $user_id ): ?string {
        $cpfs = self::get_user_cpfs_masked( $user_id );
        return ! empty( $cpfs ) ? $cpfs[0] : null;
    }

    /**
     * Get all user's CPF/RF values (masked)
     *
     * @since 4.3.0
     * @param int $user_id WordPress user ID
     * @return array Array of masked CPF/RF values
     */
    public static function get_user_cpfs_masked( int $user_id ): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $encrypted_cpfs = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT cpf_rf_encrypted FROM %i
             WHERE user_id = %d
             AND cpf_rf_encrypted IS NOT NULL
             AND cpf_rf_encrypted != ''",
            $table,
            $user_id
        ) );

        if ( empty( $encrypted_cpfs ) ) {
            return array();
        }

        $cpfs_masked = array();

        foreach ( $encrypted_cpfs as $cpf_encrypted ) {
            try {
                $cpf_plain = \FreeFormCertificate\Core\Encryption::decrypt( $cpf_encrypted );
                if ( ! empty( $cpf_plain ) ) {
                    $masked = self::mask_cpf_rf( $cpf_plain );
                    if ( ! in_array( $masked, $cpfs_masked, true ) ) {
                        $cpfs_masked[] = $masked;
                    }
                }
            } catch ( \Exception $e ) {
                if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
                    \FreeFormCertificate\Core\Debug::log_user_manager(
                        'Failed to decrypt CPF/RF',
                        array(
                            'user_id' => $user_id,
                            'error'   => $e->getMessage(),
                        )
                    );
                }
                continue;
            }
        }

        return $cpfs_masked;
    }

    /**
     * Mask CPF/RF for display
     *
     * @param string $cpf_rf CPF or RF (plain)
     * @return string Masked value
     */
    private static function mask_cpf_rf( string $cpf_rf ): string {
        $clean = preg_replace( '/[^0-9]/', '', $cpf_rf );

        if ( strlen( $clean ) === 11 ) {
            return '***.***.' . substr( $clean, 7, 2 ) . '-' . substr( $clean, 9, 2 );
        } elseif ( strlen( $clean ) === 7 ) {
            return '****' . substr( $clean, 4, 3 );
        }

        return str_repeat( '*', strlen( $clean ) - 3 ) . substr( $clean, -3 );
    }

    /**
     * Get all emails used by a user in submissions
     *
     * @param int $user_id WordPress user ID
     * @return array Array of emails
     */
    public static function get_user_emails( int $user_id ): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $encrypted_emails = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT email_encrypted FROM %i
             WHERE user_id = %d
             AND email_encrypted IS NOT NULL
             AND email_encrypted != ''",
            $table,
            $user_id
        ) );

        if ( empty( $encrypted_emails ) ) {
            $user = get_user_by( 'id', $user_id );
            return $user ? array( $user->user_email ) : array();
        }

        $emails = array();

        foreach ( $encrypted_emails as $encrypted ) {
            try {
                $email = \FreeFormCertificate\Core\Encryption::decrypt( $encrypted );
                if ( is_email( $email ) ) {
                    $emails[] = $email;
                }
            } catch ( \Exception $e ) {
                continue;
            }
        }

        $user = get_user_by( 'id', $user_id );
        if ( $user && is_email( $user->user_email ) ) {
            $emails[] = $user->user_email;
        }

        return array_unique( $emails );
    }

    /**
     * Get all distinct names used by a user in submissions
     *
     * @since 4.3.0
     * @param int $user_id WordPress user ID
     * @return array Array of names
     */
    public static function get_user_names( int $user_id ): array {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $submissions = $wpdb->get_col( $wpdb->prepare(
            "SELECT data FROM %i
             WHERE user_id = %d
             AND data IS NOT NULL
             AND data != ''",
            $table,
            $user_id
        ) );

        if ( empty( $submissions ) ) {
            $user = get_user_by( 'id', $user_id );
            return $user ? array( $user->display_name ) : array();
        }

        $names                = array();
        $possible_name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

        foreach ( $submissions as $data_json ) {
            $data = json_decode( $data_json, true );

            if ( ! is_array( $data ) ) {
                continue;
            }

            foreach ( $possible_name_fields as $field ) {
                if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
                    $name = trim( $data[ $field ] );
                    if ( ! empty( $name ) && ! in_array( $name, $names, true ) ) {
                        $names[] = $name;
                    }
                    break;
                }
            }
        }

        if ( empty( $names ) ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user && ! empty( $user->display_name ) ) {
                $names[] = $user->display_name;
            }
        }

        return $names;
    }
}
