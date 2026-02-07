<?php
declare(strict_types=1);

/**
 * SettingsSaveHandler
 * Handles saving and validation of all settings types
 *
 * Extracted from FFC_Settings (v3.1.1) following Single Responsibility Principle
 *
 * Responsibilities:
 * - Validate and sanitize all settings types
 * - Handle General, SMTP, QR Code, Date Format, User Access settings
 * - Handle Danger Zone (data deletion)
 * - Display success/error messages
 *
 * @package FFC
 * @since 3.1.1
 * @version 4.0.0 - Fixed type hint (Phase 4 Hotfix 8)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsSaveHandler {

    private $submission_handler;

    /**
     * Constructor
     *
     * @param SubmissionHandler $handler Submission handler for danger zone
     */
    public function __construct( SubmissionHandler $handler ) {
        $this->submission_handler = $handler;
    }

    /**
     * Handle all settings submissions
     * Main entry point called by FFC_Settings
     */
    public function handle_all_submissions(): void {
        // Handle General/SMTP/QR Settings
        if ( isset( $_POST['ffc_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffc_settings_nonce'] ) ), 'ffc_settings_action' ) ) {
            $this->save_general_and_specific_settings();
        }

        // Handle User Access Settings (v3.1.0)
        if ( isset( $_POST['ffc_user_access_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffc_user_access_nonce'] ) ), 'ffc_user_access_settings' ) ) {
            $this->save_user_access_settings();
        }

        // Handle Global Data Deletion (Danger Zone)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified via check_admin_referer.
        if ( isset( $_POST['ffc_delete_all_data'] ) && check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' ) ) {
            $this->handle_danger_zone();
        }
    }

    /**
     * Save general and tab-specific settings (General, SMTP, QR Code, Date Format)
     *
     * @return void
     */
    private function save_general_and_specific_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        $current = get_option( 'ffc_settings', array() );
        $new     = isset( $_POST['ffc_settings'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ffc_settings'] ) ) : array();

        $clean = $current;

        // Process each settings type
        $clean = $this->save_general_settings( $clean, $new );
        $clean = $this->save_smtp_settings( $clean, $new );
        $clean = $this->save_qrcode_settings( $clean, $new );
        $clean = $this->save_date_format_settings( $clean, $new );

        update_option( 'ffc_settings', $clean );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        add_settings_error( 'ffc_settings', 'ffc_settings_updated', __( 'Settings saved.', 'ffcertificate' ), 'updated' );
    }

    /**
     * Save General tab settings
     *
     * @param array $clean Current settings
     * @param array $new New settings from POST
     * @return array Updated settings
     */
    private function save_general_settings( array $clean, array $new ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        // Cleanup Days
        if ( isset( $new['cleanup_days'] ) ) {
            $clean['cleanup_days'] = absint( $new['cleanup_days'] );
        }

        // Activity Log (v3.1.1)
        if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'general' ) {
            $clean['enable_activity_log'] = isset( $new['enable_activity_log'] ) ? 1 : 0;
        }

        // Form Cache Settings (v3.1.0)
        if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'general' ) {
            $clean['cache_enabled'] = isset( $new['cache_enabled'] ) ? 1 : 0;

            if ( isset( $new['cache_expiration'] ) ) {
                $clean['cache_expiration'] = absint( $new['cache_expiration'] );
            }

            $clean['cache_auto_warm'] = isset( $new['cache_auto_warm'] ) ? 1 : 0;
        }

        // Debug Settings
        $debug_flags = array(
            'debug_pdf_generator',
            'debug_email_handler',
            'debug_form_processor',
            'debug_encryption',
            'debug_geofence',
            'debug_user_manager',
            'debug_rest_api',
            'debug_migrations',
            'debug_activity_log'
        );

        if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'general' ) {
            foreach ( $debug_flags as $flag ) {
                $clean[ $flag ] = isset( $new[ $flag ] ) ? 1 : 0;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $clean;
    }

    /**
     * Save SMTP tab settings
     *
     * @param array $clean Current settings
     * @param array $new New settings from POST
     * @return array Updated settings
     */
    private function save_smtp_settings( array $clean, array $new ): array {
        // Email Status checkbox (only when on SMTP tab to prevent unchecking from other tabs)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'smtp' ) {
            $clean['disable_all_emails'] = isset( $new['disable_all_emails'] ) ? 1 : 0;
        }

        // User creation email settings (radio buttons - always have a value)
        if ( isset( $new['send_wp_user_email_submission'] ) ) {
            $clean['send_wp_user_email_submission'] = sanitize_text_field( $new['send_wp_user_email_submission'] );
        }

        if ( isset( $new['send_wp_user_email_migration'] ) ) {
            $clean['send_wp_user_email_migration'] = sanitize_text_field( $new['send_wp_user_email_migration'] );
        }

        if ( isset( $new['smtp_mode'] ) ) {
            $clean['smtp_mode'] = sanitize_key( $new['smtp_mode'] );
        }

        if ( isset( $new['smtp_host'] ) ) {
            $clean['smtp_host'] = sanitize_text_field( $new['smtp_host'] );
        }

        if ( isset( $new['smtp_port'] ) ) {
            $clean['smtp_port'] = absint( $new['smtp_port'] );
        }

        if ( isset( $new['smtp_user'] ) ) {
            $clean['smtp_user'] = sanitize_text_field( $new['smtp_user'] );
        }

        if ( isset( $new['smtp_pass'] ) ) {
            $clean['smtp_pass'] = sanitize_text_field( $new['smtp_pass'] );
        }

        if ( isset( $new['smtp_secure'] ) ) {
            $clean['smtp_secure'] = sanitize_key( $new['smtp_secure'] );
        }

        if ( isset( $new['smtp_from_email'] ) ) {
            $clean['smtp_from_email'] = sanitize_email( $new['smtp_from_email'] );
        }

        if ( isset( $new['smtp_from_name'] ) ) {
            $clean['smtp_from_name'] = sanitize_text_field( $new['smtp_from_name'] );
        }

        return $clean;
    }

    /**
     * Save QR Code tab settings
     *
     * @param array $clean Current settings
     * @param array $new New settings from POST
     * @return array Updated settings
     */
    private function save_qrcode_settings( array $clean, array $new ): array {
        // QR Cache (checkbox - only set if on QR tab)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        if ( isset( $_POST['_ffc_tab'] ) && sanitize_key( wp_unslash( $_POST['_ffc_tab'] ) ) === 'qr_code' ) {
            $clean['qr_cache_enabled'] = isset( $new['qr_cache_enabled'] ) ? 1 : 0;
        }

        if ( isset( $new['qr_default_size'] ) ) {
            $clean['qr_default_size'] = absint( $new['qr_default_size'] );
        }

        if ( isset( $new['qr_default_margin'] ) ) {
            $clean['qr_default_margin'] = absint( $new['qr_default_margin'] );
        }

        if ( isset( $new['qr_default_error_level'] ) ) {
            $clean['qr_default_error_level'] = sanitize_text_field( $new['qr_default_error_level'] );
        }

        return $clean;
    }

    /**
     * Save Date Format settings (v2.10.0)
     *
     * @param array $clean Current settings
     * @param array $new New settings from POST
     * @return array Updated settings
     */
    private function save_date_format_settings( array $clean, array $new ): array {
        if ( isset( $new['date_format'] ) ) {
            $clean['date_format'] = sanitize_text_field( $new['date_format'] );
        }

        if ( isset( $new['date_format_custom'] ) ) {
            $clean['date_format_custom'] = sanitize_text_field( $new['date_format_custom'] );
        }

        return $clean;
    }

    /**
     * Save User Access settings (v3.1.0)
     *
     * @return void
     */
    private function save_user_access_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via wp_verify_nonce.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset()/empty()/is_array() are existence and type checks; values are sanitized with wp_unslash + sanitize_text_field/esc_url_raw/sanitize_textarea_field.
        $settings = array(
            'block_wp_admin' => isset( $_POST['block_wp_admin'] ),
            'blocked_roles' => isset( $_POST['blocked_roles'] ) && is_array( $_POST['blocked_roles'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['blocked_roles'] ) )
                : array( 'ffc_user' ),
            'redirect_url' => !empty( $_POST['redirect_url'] )
                ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) )
                : home_url( '/dashboard' ),
            'redirect_message' => isset( $_POST['redirect_message'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['redirect_message'] ) )
                : '',
            'allow_admin_bar' => isset( $_POST['allow_admin_bar'] ),
            'bypass_for_admins' => isset( $_POST['bypass_for_admins'] ),
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

        update_option( 'ffc_user_access_settings', $settings );
        add_settings_error(
            'ffc_user_access_settings',
            'ffc_user_access_updated',
            __( 'User Access settings saved successfully.', 'ffcertificate' ),
            'updated'
        );
    }

    /**
     * Handle Danger Zone - Global Data Deletion
     *
     * @return void
     */
    private function handle_danger_zone(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_all_submissions() via check_admin_referer.
        $target = isset( $_POST['delete_target'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_target'] ) ) : 'all';
        $reset_counter = isset( $_POST['reset_counter'] ) && sanitize_text_field( wp_unslash( $_POST['reset_counter'] ) ) == '1';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = $this->submission_handler->delete_all_submissions(
            $target === 'all' ? null : absint( $target ),
            $reset_counter
        );

        if ( $result !== false ) {
            $message = $reset_counter
                ? __( 'Data deleted and counter reset successfully.', 'ffcertificate' )
                : __( 'Data deleted successfully.', 'ffcertificate' );
            add_settings_error( 'ffc_settings', 'ffc_data_deleted', $message, 'updated' );
        } else {
            add_settings_error( 'ffc_settings', 'ffc_data_delete_failed', __( 'Failed to delete data.', 'ffcertificate' ), 'error' );
        }
    }
}
