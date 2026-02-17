<?php
declare(strict_types=1);

/**
 * CapabilityManager
 *
 * Manages FFC user capabilities, roles, and permission granting.
 * Extracted from UserManager (v4.12.2) for single-responsibility.
 *
 * @since 4.12.2
 * @package FreeFormCertificate\UserDashboard
 */

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CapabilityManager {

    /**
     * Context constants for capability granting
     */
    public const CONTEXT_CERTIFICATE = 'certificate';
    public const CONTEXT_APPOINTMENT = 'appointment';
    public const CONTEXT_AUDIENCE    = 'audience';

    /**
     * All certificate-related capabilities
     */
    public const CERTIFICATE_CAPABILITIES = array(
        'view_own_certificates',
        'download_own_certificates',
        'view_certificate_history',
    );

    /**
     * All appointment-related capabilities
     */
    public const APPOINTMENT_CAPABILITIES = array(
        'ffc_book_appointments',
        'ffc_view_self_scheduling',
        'ffc_cancel_own_appointments',
    );

    /**
     * All audience-related capabilities
     *
     * @since 4.9.3
     */
    public const AUDIENCE_CAPABILITIES = array(
        'ffc_view_audience_bookings',
    );

    /**
     * Admin-level capabilities (not granted by default)
     *
     * @since 4.9.3
     */
    public const ADMIN_CAPABILITIES = array(
        'ffc_scheduling_bypass',
        'ffc_manage_reregistration',
    );

    /**
     * Future capabilities (disabled by default)
     *
     * @since 4.9.3
     */
    public const FUTURE_CAPABILITIES = array(
        'ffc_reregistration',
        'ffc_certificate_update',
    );

    /**
     * Get all FFC capabilities consolidated
     *
     * @since 4.9.3
     * @return array<int, string> All FFC capability names
     */
    public static function get_all_capabilities(): array {
        return array_merge(
            self::CERTIFICATE_CAPABILITIES,
            self::APPOINTMENT_CAPABILITIES,
            self::AUDIENCE_CAPABILITIES,
            self::ADMIN_CAPABILITIES,
            self::FUTURE_CAPABILITIES
        );
    }

    /**
     * Grant capabilities based on context
     *
     * @since 4.4.0
     * @param int    $user_id WordPress user ID
     * @param string $context Context ('certificate', 'appointment', or 'audience')
     * @return void
     */
    public static function grant_context_capabilities( int $user_id, string $context ): void {
        switch ( $context ) {
            case self::CONTEXT_CERTIFICATE:
                self::grant_certificate_capabilities( $user_id );
                break;
            case self::CONTEXT_APPOINTMENT:
                self::grant_appointment_capabilities( $user_id );
                break;
            case self::CONTEXT_AUDIENCE:
                self::grant_audience_capabilities( $user_id );
                break;
        }
    }

    /**
     * Grant certificate capabilities to a user
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_certificate_capabilities( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $newly_granted = array();

        foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
            if ( ! $user->has_cap( $cap ) ) {
                $user->add_cap( $cap, true );
                $newly_granted[] = $cap;
            }
        }

        if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted certificate capabilities',
                array(
                    'user_id'      => $user_id,
                    'capabilities' => self::CERTIFICATE_CAPABILITIES,
                )
            );
        }

        if ( ! empty( $newly_granted ) ) {
            self::log_and_notify_capability_grant( $user, 'certificate', $newly_granted );
        }
    }

    /**
     * Grant appointment capabilities to a user
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_appointment_capabilities( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $newly_granted = array();

        foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
            if ( ! $user->has_cap( $cap ) ) {
                $user->add_cap( $cap, true );
                $newly_granted[] = $cap;
            }
        }

        if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted appointment capabilities',
                array(
                    'user_id'      => $user_id,
                    'capabilities' => self::APPOINTMENT_CAPABILITIES,
                )
            );
        }

        if ( ! empty( $newly_granted ) ) {
            self::log_and_notify_capability_grant( $user, 'appointment', $newly_granted );
        }
    }

    /**
     * Grant audience capabilities to a user
     *
     * @since 4.9.3
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function grant_audience_capabilities( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $newly_granted = array();

        foreach ( self::AUDIENCE_CAPABILITIES as $cap ) {
            if ( ! $user->has_cap( $cap ) ) {
                $user->add_cap( $cap, true );
                $newly_granted[] = $cap;
            }
        }

        if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'Granted audience capabilities',
                array(
                    'user_id'      => $user_id,
                    'capabilities' => self::AUDIENCE_CAPABILITIES,
                )
            );
        }

        if ( ! empty( $newly_granted ) ) {
            self::log_and_notify_capability_grant( $user, 'audience', $newly_granted );
        }
    }

    /**
     * Log capability grant to activity log and send email notification
     *
     * @since 4.9.9
     * @param \WP_User $user         User who received capabilities
     * @param string   $context      Context: 'certificate', 'appointment', 'audience'
     * @param array<int, string> $capabilities Newly granted capabilities
     * @return void
     */
    private static function log_and_notify_capability_grant( \WP_User $user, string $context, array $capabilities ): void {
        if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log_capabilities_granted(
                $user->ID,
                $context,
                $capabilities
            );
        }

        $settings       = get_option( 'ffc_settings', array() );
        $notify_enabled = ! empty( $settings['notify_capability_grant'] );

        if ( ! $notify_enabled || empty( $user->user_email ) ) {
            return;
        }

        $site_name     = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $dashboard_url = get_permalink( get_option( 'ffc_dashboard_page_id' ) );

        $context_labels = array(
            'certificate' => __( 'Certificates', 'ffcertificate' ),
            'appointment' => __( 'Appointments', 'ffcertificate' ),
            'audience'    => __( 'Audience Groups', 'ffcertificate' ),
        );
        $context_label = $context_labels[ $context ] ?? $context;

        /* translators: %1$s: site name, %2$s: feature name */
        $subject = sprintf( __( '[%1$s] Access granted: %2$s', 'ffcertificate' ), $site_name, $context_label );

        /* translators: %s: user display name */
        $message  = sprintf( __( 'Hello %s,', 'ffcertificate' ), $user->display_name ) . "\n\n";
        /* translators: %1$s: feature name, %2$s: site name */
        $message .= sprintf( __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), $context_label, $site_name ) . "\n\n";

        if ( $dashboard_url ) {
            /* translators: %s: dashboard URL */
            $message .= sprintf( __( 'Access your dashboard: %s', 'ffcertificate' ), $dashboard_url ) . "\n\n";
        }

        $message .= __( 'This is an automated message.', 'ffcertificate' ) . "\n";

        wp_mail( $user->user_email, $subject, $message );
    }

    /**
     * Check if user has any certificate capabilities
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function has_certificate_access( int $user_id ): bool {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
            if ( $user->has_cap( $cap ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has any appointment capabilities
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return bool
     */
    public static function has_appointment_access( int $user_id ): bool {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
            if ( $user->has_cap( $cap ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all FFC capabilities for a user
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return array<string, bool> Associative array of capability => boolean
     */
    public static function get_user_ffc_capabilities( int $user_id ): array {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return array();
        }

        $capabilities = array();

        foreach ( self::get_all_capabilities() as $cap ) {
            $capabilities[ $cap ] = $user->has_cap( $cap );
        }

        return $capabilities;
    }

    /**
     * Set a specific FFC capability for a user
     *
     * @since 4.4.0
     * @param int    $user_id    WordPress user ID
     * @param string $capability Capability name
     * @param bool   $grant      Whether to grant (true) or revoke (false)
     * @return bool True on success
     */
    public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return false;
        }

        $all_ffc_caps = self::get_all_capabilities();

        if ( ! in_array( $capability, $all_ffc_caps, true ) ) {
            return false;
        }

        if ( $grant ) {
            $user->add_cap( $capability, true );
        } else {
            $user->add_cap( $capability, false );
        }

        if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
            \FreeFormCertificate\Core\Debug::log_user_manager(
                'User capability changed',
                array(
                    'user_id'    => $user_id,
                    'capability' => $capability,
                    'granted'    => $grant,
                )
            );
        }

        return true;
    }

    /**
     * Reset all FFC capabilities for a user to false
     *
     * @since 4.4.0
     * @param int $user_id WordPress user ID
     * @return void
     */
    public static function reset_user_ffc_capabilities( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        foreach ( self::get_all_capabilities() as $cap ) {
            $user->add_cap( $cap, false );
        }
    }

    /**
     * Register ffc_user role on plugin activation
     *
     * @return void
     */
    public static function register_role(): void {
        $existing_role = get_role( 'ffc_user' );

        if ( $existing_role ) {
            self::upgrade_role( $existing_role );
            return;
        }

        $capabilities = array( 'read' => true );
        foreach ( self::get_all_capabilities() as $cap ) {
            $capabilities[ $cap ] = false;
        }

        add_role(
            'ffc_user',
            __( 'FFC User', 'ffcertificate' ),
            $capabilities
        );
    }

    /**
     * Upgrade existing ffc_user role with new capabilities
     *
     * @since 4.4.0
     * @param \WP_Role $role Existing role object
     * @return void
     */
    private static function upgrade_role( \WP_Role $role ): void {
        foreach ( self::get_all_capabilities() as $cap ) {
            if ( ! isset( $role->capabilities[ $cap ] ) ) {
                $role->add_cap( $cap, false );
            }
        }
    }

    /**
     * Remove ffc_user role on plugin deactivation
     *
     * @return void
     */
    public static function remove_role(): void {
        remove_role( 'ffc_user' );
    }
}
