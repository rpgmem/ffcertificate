<?php
declare(strict_types=1);

/**
 * UserCreator
 *
 * Handles WordPress user creation, linking, and username generation for FFC.
 * Extracted from UserManager (v4.12.2) for single-responsibility.
 *
 * @since 4.12.2
 * @package FreeFormCertificate\UserDashboard
 */

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UserCreator {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Identifier type constants
     */
    public const TYPE_CPF  = 'cpf';
    public const TYPE_RF   = 'rf';
    public const TYPE_AUTO = 'auto';

    /**
     * Get or create WordPress user based on CPF/RF and email
     *
     * Flow:
     * 1. Check if identifier hash already has user_id in submissions table
     * 2. If yes: return existing user_id (and add context-specific capabilities)
     * 3. If no: check if email exists in WordPress
     * 4. If yes: link to existing user (add role + context-specific capabilities)
     * 5. If no: create new user (with only context-specific capabilities)
     *
     * @param string $identifier_hash Hash of CPF or RF
     * @param string $email           Plain email address
     * @param array<string, mixed>  $submission_data Optional submission data for user creation
     * @param string $context         Context for capability granting
     * @param string $identifier_type 'cpf', 'rf', or 'auto' (searches all columns)
     * @return int|\WP_Error User ID or error
     */
    public static function get_or_create_user( string $identifier_hash, string $email, array $submission_data = array(), string $context = CapabilityManager::CONTEXT_CERTIFICATE, string $identifier_type = self::TYPE_AUTO ) {
        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // STEP 1: Check if identifier hash already has user_id in submissions
        // When type is known, search the specific column + legacy fallback
        $hash_where = self::build_hash_where_clause( $identifier_type );
        $hash_params = self::build_hash_params( $identifier_hash, $identifier_type );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM %i
             WHERE ({$hash_where})
             AND user_id IS NOT NULL
             LIMIT 1",
            $table,
            ...$hash_params
        ) );

        if ( $existing_user_id ) {
            CapabilityManager::grant_context_capabilities( (int) $existing_user_id, $context );
            self::link_orphaned_records( $identifier_hash, (int) $existing_user_id, $identifier_type );
            return (int) $existing_user_id;
        }

        // STEP 2: Identifier is new → check if email exists in WordPress
        $existing_user = get_user_by( 'email', $email );

        if ( $existing_user ) {
            $user_id = $existing_user->ID;
            $existing_user->add_role( 'ffc_user' );
            CapabilityManager::grant_context_capabilities( $user_id, $context );

            if ( empty( $existing_user->display_name ) || $existing_user->display_name === $existing_user->user_login ) {
                self::sync_user_metadata( $user_id, $submission_data );
            }

            self::link_orphaned_records( $identifier_hash, $user_id, $identifier_type );
            return $user_id;
        }

        // STEP 3: Email is also new → create new user
        $user_id = self::create_ffc_user( $email, $submission_data, $context );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        self::link_orphaned_records( $identifier_hash, $user_id, $identifier_type );
        return $user_id;
    }

    /**
     * Create new WordPress user for FFC
     *
     * @param string $email           Email address
     * @param array<string, mixed>  $submission_data Submission data for user metadata
     * @param string $context         Context for capability granting
     * @return int|\WP_Error User ID or error
     */
    private static function create_ffc_user( string $email, array $submission_data = array(), string $context = CapabilityManager::CONTEXT_CERTIFICATE ) {
        $password = wp_generate_password( 24, true, true );
        $username = self::generate_username( $email, $submission_data );
        $user_id  = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
                \FreeFormCertificate\Core\Debug::log_user_manager(
                    'Failed to create user',
                    array(
                        'email' => $email,
                        'error' => $user_id->get_error_message(),
                    )
                );
            }
            return $user_id;
        }

        $user = new \WP_User( $user_id );
        $user->set_role( 'ffc_user' );
        CapabilityManager::grant_context_capabilities( $user_id, $context );
        self::sync_user_metadata( $user_id, $submission_data );
        self::create_user_profile( $user_id );

        if ( ! class_exists( '\FreeFormCertificate\Integrations\EmailHandler' ) ) {
            $email_handler_file = FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';
            if ( file_exists( $email_handler_file ) ) {
                require_once $email_handler_file;
            }
        }

        if ( class_exists( '\FreeFormCertificate\Integrations\EmailHandler' ) ) {
            $email_context  = $context === CapabilityManager::CONTEXT_APPOINTMENT ? 'appointment' : 'submission';
            $email_handler = new \FreeFormCertificate\Integrations\EmailHandler();
            $email_handler->send_wp_user_notification( $user_id, $email_context );
        }

        return $user_id;
    }

    /**
     * Build WHERE clause for hash column lookup
     *
     * Targets the specific split column based on identifier type.
     * When 'auto', searches both cpf_hash and rf_hash.
     *
     * @param string $identifier_type 'cpf', 'rf', or 'auto'
     * @return string SQL WHERE fragment
     */
    private static function build_hash_where_clause( string $identifier_type ): string {
        switch ( $identifier_type ) {
            case self::TYPE_CPF:
                return 'cpf_hash = %s';
            case self::TYPE_RF:
                return 'rf_hash = %s';
            default:
                return 'cpf_hash = %s OR rf_hash = %s';
        }
    }

    /**
     * Build parameter array for hash column lookup
     *
     * @param string $hash            The hash value
     * @param string $identifier_type 'cpf', 'rf', or 'auto'
     * @return array<int, string> Parameters matching build_hash_where_clause placeholders
     */
    private static function build_hash_params( string $hash, string $identifier_type ): array {
        switch ( $identifier_type ) {
            case self::TYPE_CPF:
            case self::TYPE_RF:
                return array( $hash );
            default:
                return array( $hash, $hash );
        }
    }

    /**
     * Link orphaned records (submissions and appointments) to a user
     *
     * @since 4.9.6
     * @param string $identifier_hash Hash of CPF or RF
     * @param int    $user_id         WordPress user ID
     * @param string $identifier_type 'cpf', 'rf', or 'auto'
     * @return void
     */
    private static function link_orphaned_records( string $identifier_hash, int $user_id, string $identifier_type = self::TYPE_AUTO ): void {
        global $wpdb;

        $hash_where  = self::build_hash_where_clause( $identifier_type );
        $hash_params = self::build_hash_params( $identifier_hash, $identifier_type );

        $submissions_table = \FreeFormCertificate\Core\Utils::get_submissions_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $linked_submissions = $wpdb->query( $wpdb->prepare(
            "UPDATE %i SET user_id = %d WHERE ({$hash_where}) AND user_id IS NULL",
            $submissions_table,
            $user_id,
            ...$hash_params
        ) );

        $appointments_table  = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $linked_appointments = 0;
        if ( self::table_exists( $appointments_table ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $linked_appointments = $wpdb->query( $wpdb->prepare(
                "UPDATE %i SET user_id = %d WHERE ({$hash_where}) AND user_id IS NULL",
                $appointments_table,
                $user_id,
                ...$hash_params
            ) );

            if ( $linked_appointments > 0 ) {
                CapabilityManager::grant_appointment_capabilities( $user_id );
            }
        }

        if ( ( $linked_submissions > 0 || $linked_appointments > 0 ) && class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Linked orphaned records',
                array(
                    'user_id'              => $user_id,
                    'submissions_linked'   => $linked_submissions,
                    'appointments_linked'  => $linked_appointments,
                )
            );
        }
    }

    /**
     * Generate a unique username for a new FFC user
     *
     * @since 4.9.6
     * @param string $email           Email (used only as last-resort fallback)
     * @param array<string, mixed>  $submission_data Submission data containing name fields
     * @return string Unique username
     */
    public static function generate_username( string $email, array $submission_data = array() ): string {
        $possible_names = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome' );
        $name = '';

        foreach ( $possible_names as $field ) {
            if ( ! empty( $submission_data[ $field ] ) && is_string( $submission_data[ $field ] ) ) {
                $name = trim( $submission_data[ $field ] );
                break;
            }
        }

        if ( ! empty( $name ) ) {
            $slug = sanitize_user( remove_accents( strtolower( $name ) ), true );
            $slug = preg_replace( '/[^a-z0-9._-]/', '', $slug );
            $slug = preg_replace( '/[-_.]+/', '.', $slug );
            $slug = trim( $slug, '.' );

            if ( strlen( $slug ) >= 3 ) {
                if ( ! username_exists( $slug ) ) {
                    return $slug;
                }

                for ( $i = 2; $i <= 99; $i++ ) {
                    $candidate = $slug . '.' . $i;
                    if ( ! username_exists( $candidate ) ) {
                        return $candidate;
                    }
                }
            }
        }

        do {
            $username = 'ffc_' . wp_generate_password( 8, false, false );
        } while ( username_exists( $username ) );

        return $username;
    }

    /**
     * Sync user metadata from submission data
     *
     * @param int   $user_id         WordPress user ID
     * @param array<string, mixed> $submission_data Submission data
     * @return void
     */
    private static function sync_user_metadata( int $user_id, array $submission_data ): void {
        if ( empty( $submission_data ) ) {
            return;
        }

        $nome_completo  = '';
        $possible_names = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome' );

        foreach ( $possible_names as $field ) {
            if ( ! empty( $submission_data[ $field ] ) ) {
                $nome_completo = $submission_data[ $field ];
                break;
            }
        }

        if ( ! empty( $nome_completo ) ) {
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => $nome_completo,
                'first_name'   => $nome_completo,
            ) );
        }

        update_user_meta( $user_id, 'ffc_registration_date', current_time( 'mysql' ) );
    }

    /**
     * Create user profile entry in ffc_user_profiles
     *
     * @since 4.9.4
     * @param int $user_id WordPress user ID
     * @return void
     */
    private static function create_user_profile( int $user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_user_profiles';

        if ( ! self::table_exists( $table ) ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE user_id = %d",
            $table,
            $user_id
        ) );

        if ( $exists ) {
            return;
        }

        $user         = get_userdata( $user_id );
        $display_name = $user ? $user->display_name : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table,
            array(
                'user_id'      => $user_id,
                'display_name' => $display_name,
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }
}
