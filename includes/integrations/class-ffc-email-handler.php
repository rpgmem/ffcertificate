<?php
declare(strict_types=1);

/**
 * EmailHandler
 * Handles email configuration and sending with magic links.
 *
 * Architecture:
 * - Email Handler: Sends emails (SMTP + delivery)
 * - PDF Generator: Generates certificate HTML/PDF (single source of truth)
 *
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v3.1.0: Added send_wp_user_notification for WordPress user creation emails
 * v3.0.0: REFACTORED - Removed HTML generation logic (now uses FFC_PDF_Generator)
 *         Simplified emails to send only magic link (no certificate preview)
 *         Removed: generate_pdf_html(), process_qr_code_placeholders(), process_validation_url_placeholders()
 *         All HTML generation now handled by FFC_PDF_Generator
 * v2.10.0: ENCRYPTION - Compatible (receives pre-encryption data via parameters)
 * v2.9.0: Added QR Code placeholder support with hash-based URLs
 * v2.8.0: Added magic link support in emails
 * v2.9.11: Using FFC_Utils for document formatting
 */

namespace FreeFormCertificate\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EmailHandler {

    public function __construct() {
        add_action( 'ffc_process_submission_hook', array( $this, 'async_process_submission' ), 10, 8 );
        add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
    }

    /**
     * Configure custom SMTP settings
     *
     * @param PHPMailer $phpmailer PHPMailer instance
     */
    public function configure_custom_smtp( $phpmailer ): void {
        $settings = get_option( 'ffc_settings', array() );

        if ( isset($settings['smtp_mode']) && $settings['smtp_mode'] === 'custom' ) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = isset($settings['smtp_host']) ? $settings['smtp_host'] : '';
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Port       = isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : 587;
            $phpmailer->Username   = isset($settings['smtp_user']) ? $settings['smtp_user'] : '';
            $phpmailer->Password   = isset($settings['smtp_pass']) ? $settings['smtp_pass'] : '';
            $phpmailer->SMTPSecure = isset($settings['smtp_secure']) ? $settings['smtp_secure'] : 'tls';

            if ( ! empty( $settings['smtp_from_email'] ) ) {
                $phpmailer->From     = $settings['smtp_from_email'];
                $phpmailer->FromName = isset($settings['smtp_from_name']) ? $settings['smtp_from_name'] : get_bloginfo( 'name' );
            }
        }
    }

    /**
     * Process submission and send emails asynchronously
     *
     * Called via WP-Cron hook 'ffc_process_submission_hook'
     *
     * @param int $submission_id Submission ID
     * @param int $form_id Form ID
     * @param string $form_title Form title
     * @param array $submission_data Submission data
     * @param string $user_email User email
     * @param array $fields_config Field configuration
     * @param array $form_config Form configuration
     * @param string $magic_token Magic token for verification
     */
    public function async_process_submission( int $submission_id, int $form_id, string $form_title, array $submission_data, string $user_email, array $fields_config, array $form_config, string $magic_token = '' ): void {
        // Send user email if enabled
        if ( isset( $form_config['send_user_email'] ) && $form_config['send_user_email'] == 1 ) {
            $this->send_user_email( $user_email, $form_title, $form_config, $submission_data, $magic_token );
        }

        // Send admin notification
        $this->send_admin_notification( $form_title, $submission_data, $form_config );
    }

    /**
     * Send email to user with magic link
     *
     * Email contains:
     * - Success message
     * - Auth code
     * - Magic link button (to view/download certificate)
     * - Manual verification link
     *
     * NO LONGER INCLUDES: Certificate preview/HTML (use magic link instead)
     *
     * @param string $to Recipient email
     * @param string $form_title Form title
     * @param array $form_config Form configuration
     * @param array $submission_data Submission data
     * @param string $magic_token Magic token
     */
    private function send_user_email( string $to, string $form_title, array $form_config, array $submission_data, string $magic_token = '' ): void {
        // Check if all emails are globally disabled
        $settings = get_option( 'ffc_settings', array() );
        if ( ! empty( $settings['disable_all_emails'] ) ) {
            return; // Emails are globally disabled
        }

        // Email subject
        $subject = ! empty( $form_config['email_subject'] )
            ? $form_config['email_subject']
            : sprintf( __( 'Your Certificate: %s', 'ffc' ), $form_title );

        // Generate magic link URL
        $magic_link_url = '';
        if ( ! empty( $magic_token ) ) {
            $base_url = untrailingslashit( site_url( 'valid' ) );
            $magic_link_url = $base_url . '#token=' . $magic_token;
        }

        // Format auth code
        $auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';
        if ( strlen( $auth_code ) === 12 ) {
            $auth_code = substr( $auth_code, 0, 4 ) . '-' . substr( $auth_code, 4, 4 ) . '-' . substr( $auth_code, 8, 4 );
        }

        // Custom body text from form config
        $body_text = isset( $form_config['email_body'] ) ? wpautop( $form_config['email_body'] ) : '';

        // Build email HTML (simple, clean, no certificate preview)
        $body  = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;">';

        // Main content card
        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">' . esc_html__( 'Your Certificate has been Issued!', 'ffc' ) . '</h2>';

        // Custom message (if configured)
        if ( ! empty( $body_text ) ) {
            $body .= $body_text;
        }

        // Auth code display
        if ( ! empty( $auth_code ) ) {
            $body .= '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__( 'Authentication Code:', 'ffc' ) . '</p>';
            $body .= '<p style="font-size: 24px; font-weight: bold; margin: 0; font-family: monospace; color: #0073aa; letter-spacing: 2px;">' . esc_html( $auth_code ) . '</p>';
            $body .= '</div>';
        }

        // Magic link button (primary CTA)
        if ( ! empty( $magic_link_url ) ) {
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<a href="' . esc_url( $magic_link_url ) . '" style="display: inline-block; background: #0073aa; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(0,115,170,0.3);">';
            $body .= '🔗 ' . esc_html__( 'View and Download Certificate', 'ffc' );
            $body .= '</a>';
            $body .= '<p style="margin: 15px 0 0 0; font-size: 12px; color: #666;">' . esc_html__( 'Click the button above to access your certificate online', 'ffc' ) . '</p>';
            $body .= '</div>';
        }

        $body .= '</div>';

        // Footer with manual verification link
        $body .= '<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #999; text-align: center;">';
        $body .= esc_html__( 'You can also verify this certificate manually at', 'ffc' ) . ' ';
        $body .= '<a href="' . esc_url( untrailingslashit( site_url( 'valid' ) ) ) . '" style="color: #0073aa;">' . esc_url( untrailingslashit( site_url( 'valid' ) ) ) . '</a>';
        $body .= '</p></div>';

        $body .= '</div>';

        // Send email
        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /**
     * Send admin notification email
     *
     * Contains submission data in table format
     *
     * @param string $form_title Form title
     * @param array $data Submission data
     * @param array $form_config Form configuration
     */
    private function send_admin_notification( string $form_title, array $data, array $form_config ): void {
        // Check if all emails are globally disabled
        $settings = get_option( 'ffc_settings', array() );
        if ( ! empty( $settings['disable_all_emails'] ) ) {
            return; // Emails are globally disabled
        }

        // Get admin emails (comma-separated list or default admin_email)
        $admins = isset( $form_config['email_admin'] )
            ? array_filter(array_map('trim', explode( ',', $form_config['email_admin'] )))
            : array( get_option( 'admin_email' ) );

        // Email subject
        $subject = sprintf( __( 'New Issuance: %s', 'ffc' ), $form_title );

        // Build email body with data table
        $body    = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $body   .= '<h3 style="color: #0073aa;">' . __( 'Submission Details:', 'ffc' ) . '</h3>';
        $body   .= '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';

        foreach ( $data as $k => $v ) {
            $display_v = is_array($v) ? implode(', ', $v) : $v;

            // Format documents (CPF, RF, RG)
            if ( in_array( $k, array( 'cpf', 'cpf_rf', 'rg' ) ) ) {
                $display_v = \FFC_Utils::format_document( $display_v );
            }

            // Format auth code
            if ( $k === 'auth_code' ) {
                $display_v = \FFC_Utils::format_auth_code( $display_v );
            }

            $label = ucwords( str_replace('_', ' ', $k) );
            $body .= '<tr>';
            $body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html( $label ) . '</td>';
            $body .= '<td style="border: 1px solid #ddd;">' . wp_kses( $display_v, \FFC_Utils::get_allowed_html_tags() ) . '</td>';
            $body .= '</tr>';
        }
        $body .= '</table></div>';

        // Send to all admin emails
        foreach ( $admins as $email ) {
            if ( is_email( $email ) ) {
                wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
            }
        }
    }

    /**
     * Send WordPress user notification email
     *
     * Sends welcome email to new WordPress users created by FFC.
     * Respects context-specific settings (submission vs migration).
     *
     * @since 3.1.0
     * @param int $user_id WordPress user ID
     * @param string $context Context: 'submission' or 'migration'
     * @return bool True if email was sent, false otherwise
     */
    public function send_wp_user_notification( int $user_id, string $context = 'submission' ): bool {
        $settings = get_option( 'ffc_settings', array() );

        // Check global disable
        if ( ! empty( $settings['disable_all_emails'] ) ) {
            return false;
        }

        // Check context-specific setting
        if ( $context === 'submission' ) {
            // Default: enabled (1)
            $enabled = isset( $settings['send_wp_user_email_submission'] )
                ? absint( $settings['send_wp_user_email_submission'] ) === 1
                : true; // Default enabled for submissions
        } else { // migration
            // Default: disabled (0)
            $enabled = isset( $settings['send_wp_user_email_migration'] )
                && absint( $settings['send_wp_user_email_migration'] ) === 1;
        }

        if ( ! $enabled ) {
            return false;
        }

        // Send WordPress notification (welcome email with password reset link)
        wp_new_user_notification( $user_id, null, 'user' );
        return true;
    }
}
