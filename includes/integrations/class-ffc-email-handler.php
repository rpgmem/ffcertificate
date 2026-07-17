<?php
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
 *
 * @package FreeFormCertificate\Integrations
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use PHPMailer\PHPMailer\PHPMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for email operations.
 */
class EmailHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'ffcertificate_process_submission_hook', array( $this, 'async_process_submission' ), 10, 8 );
		add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
	}

	/**
	 * Configure custom SMTP settings
	 *
	 * @param PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_custom_smtp( $phpmailer ): void {
		$settings = \FreeFormCertificate\Settings\SettingsReader::all();

		if ( isset( $settings['smtp_mode'] ) && 'custom' === $settings['smtp_mode'] ) {
			$phpmailer->isSMTP();
			$phpmailer->Host       = isset( $settings['smtp_host'] ) ? $settings['smtp_host'] : '';
			$phpmailer->SMTPAuth   = true;
			$phpmailer->Port       = isset( $settings['smtp_port'] ) ? (int) $settings['smtp_port'] : 587;
			$phpmailer->Username   = isset( $settings['smtp_user'] ) ? $settings['smtp_user'] : '';
			$phpmailer->Password   = isset( $settings['smtp_pass'] ) ? $settings['smtp_pass'] : '';
			$phpmailer->SMTPSecure = isset( $settings['smtp_secure'] ) ? $settings['smtp_secure'] : 'tls';

			if ( ! empty( $settings['smtp_from_email'] ) ) {
				$phpmailer->From     = $settings['smtp_from_email'];
				$phpmailer->FromName = isset( $settings['smtp_from_name'] ) ? $settings['smtp_from_name'] : get_bloginfo( 'name' );
			}
		}
	}

	/**
	 * Process submission and send emails asynchronously
	 *
	 * Called via WP-Cron hook 'ffcertificate_process_submission_hook'
	 *
	 * @param int                  $submission_id Submission ID.
	 * @param int                  $form_id Form ID.
	 * @param string               $form_title Form title.
	 * @param array<string, mixed> $submission_data Submission data.
	 * @param string               $user_email User email.
	 * @param array<string, mixed> $fields_config Field configuration.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param string               $magic_token Magic token for verification.
	 */
	public function async_process_submission( int $submission_id, int $form_id, string $form_title, array $submission_data, string $user_email, array $fields_config, array $form_config, string $magic_token = '' ): void {
		/**
		 * Fires before email processing for a submission.
		 *
		 * @since 4.6.4
		 * @param int    $submission_id  Submission ID.
		 * @param string $user_email     User email.
		 * @param int    $form_id        Form ID.
		 * @param array  $form_config    Form configuration.
		 */
		do_action( 'ffcertificate_before_email_send', $submission_id, $user_email, $form_id, $form_config );

		// Send user email if enabled. Loose check — the flag persists as '1'
		// (string) from the metabox but callers may pass int 1; `! empty()`
		// accepts both and rejects '0'/0/'' (#649).
		if ( ! empty( $form_config['send_user_email'] ) ) {
			$this->send_user_email( $user_email, $form_title, $form_config, $submission_data, $magic_token );
		}

		// Send admin notification only when explicitly enabled for the form.
		// Opt-in (default off) so re-wiring the dispatch (#649) doesn't start
		// emailing the site admin on every submission without consent.
		if ( ! empty( $form_config['send_admin_email'] ) ) {
			$this->send_admin_notification( $form_title, $submission_data, $form_config );
		}
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
	 * @param string               $to Recipient email.
	 * @param string               $form_title Form title.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param array<string, mixed> $submission_data Submission data.
	 * @param string               $magic_token Magic token.
	 */
	private function send_user_email( string $to, string $form_title, array $form_config, array $submission_data, string $magic_token = '' ): void {
		if ( self::ffc_emails_disabled() ) {
			return;
		}

		// Format auth code with certificate prefix.
		$raw_code  = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';
		$auth_code = ! empty( $raw_code )
			? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $raw_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE )
			: '';

		// Scalar placeholder map. {{validation_url ...}} is handled by the
		// shared DSL below (m = magic/download link, v = public /valid).
		$replacements = array(
			'{{name}}'       => isset( $submission_data['name'] ) ? (string) $submission_data['name'] : '',
			'{{form_title}}' => $form_title,
			'{{auth_code}}'  => $auth_code,
			'{{date}}'       => \FreeFormCertificate\Core\DateFormatter::format_date( time() ),
		);

		// Subject: default carries {{form_title}}; substitute then filter.
		$subject = ! empty( $form_config['email_subject'] )
			? (string) $form_config['email_subject']
			: \FreeFormCertificate\Core\EmailTemplateDefaults::user_email_subject();
		$subject = self::apply_placeholders( $subject, $replacements );

		/**
		 * Filters the user email subject.
		 *
		 * @since 4.6.4
		 * @param string $subject     Email subject.
		 * @param string $form_title  Form title.
		 * @param array  $form_config Form configuration.
		 */
		$subject = apply_filters( 'ffcertificate_user_email_subject', $subject, $form_title, $form_config );

		/**
		 * Filters the user email recipient.
		 *
		 * @since 4.6.4
		 * @param string $to              Recipient email address.
		 * @param string $form_title      Form title.
		 * @param array  $submission_data Submission data.
		 */
		$to = apply_filters( 'ffcertificate_user_email_recipients', $to, $form_title, $submission_data );

		// The whole email is the (translatable, per-form editable) template —
		// no locked chrome. Fall back to the shipped default when empty.
		$body = ( isset( $form_config['email_body'] ) && '' !== trim( (string) $form_config['email_body'] ) )
			? (string) $form_config['email_body']
			: \FreeFormCertificate\Core\EmailTemplateDefaults::user_email_body();

		// Normalise TinyMCE-encoded braces so placeholders authored in the
		// Visual editor (which may percent-encode `{{`/`}}`) still substitute.
		$body = str_ireplace( array( '%7B%7B', '%7D%7D' ), array( '{{', '}}' ), $body );

		// Substitute scalar tokens + the {{validation_url ...}} DSL BEFORE
		// wp_kses_post, so hrefs are already real URLs when sanitised.
		$body = self::apply_placeholders( $body, $replacements );
		$body = \FreeFormCertificate\Generators\ValidationUrlPlaceholders::process(
			$body,
			array_merge( $submission_data, array( 'magic_token' => $magic_token ) )
		);
		$body = wpautop( wp_kses_post( $body ) );

		/**
		 * Filters the user email body HTML.
		 *
		 * @since 4.6.4
		 * @param string $body            Email body HTML.
		 * @param string $to              Recipient email.
		 * @param string $form_title      Form title.
		 * @param array  $submission_data Submission data.
		 */
		$body = apply_filters( 'ffcertificate_user_email_body', $body, $to, $form_title, $submission_data );

		// Send email.
		self::ffc_send_mail( $to, $subject, $body );
	}

	/**
	 * Replace scalar `{{token}}` placeholders in a string.
	 *
	 * @param string                $text Text with placeholders.
	 * @param array<string, string> $map  Placeholder => value.
	 * @return string
	 */
	private static function apply_placeholders( string $text, array $map ): string {
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}

	/**
	 * Send admin notification email
	 *
	 * Contains submission data in table format
	 *
	 * @param string               $form_title Form title.
	 * @param array<string, mixed> $data Submission data.
	 * @param array<string, mixed> $form_config Form configuration.
	 */
	private function send_admin_notification( string $form_title, array $data, array $form_config ): void {
		if ( self::ffc_emails_disabled() ) {
			return;
		}

		// Get admin emails (comma-separated list or default admin_email).
		$admins = self::ffc_parse_admin_emails( $form_config['email_admin'] ?? '' );

		/**
		 * Filters the admin notification email recipients.
		 *
		 * @since 4.6.4
		 * @param array  $admins     Array of admin email addresses.
		 * @param string $form_title Form title.
		 * @param array  $data       Submission data.
		 */
		$admins = apply_filters( 'ffcertificate_admin_email_recipients', $admins, $form_title, $data );

		// Email subject.
		/* translators: %s: form title */
		$subject = sprintf( __( 'New Issuance: %s', 'ffcertificate' ), $form_title );

		// Build email body with data table.
		$body  = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
		$body .= '<h3 style="color: #0073aa;">' . __( 'Submission Details:', 'ffcertificate' ) . '</h3>';
		$body .= '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';

		foreach ( $data as $k => $v ) {
			$display_v = is_array( $v ) ? implode( ', ', $v ) : $v;

			// Format documents (CPF, RF, RG).
			if ( in_array( $k, array( 'cpf', 'cpf_rf', 'rg' ), true ) ) {
				$display_v = \FreeFormCertificate\Core\DocumentFormatter::format_document( $display_v );
			}

			// Format auth code with certificate prefix.
			if ( 'auth_code' === $k ) {
				$display_v = \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $display_v, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE );
			}

			$label = ucwords( str_replace( '_', ' ', $k ) );
			$body .= '<tr>';
			$body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html( $label ) . '</td>';
			$body .= '<td style="border: 1px solid #ddd;">' . wp_kses( $display_v, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() ) . '</td>';
			$body .= '</tr>';
		}
		$body .= '</table></div>';

		// Send to all admin emails (already validated by ffc_parse_admin_emails).
		foreach ( $admins as $email ) {
			self::ffc_send_mail( $email, $subject, $body );
		}
	}

	/**
	 * Send WordPress user notification email
	 *
	 * Sends welcome email to new WordPress users created by FFC.
	 * Respects context-specific settings (submission vs migration).
	 *
	 * @since 3.1.0
	 * @param int    $user_id WordPress user ID.
	 * @param string $context Context: 'submission', 'appointment', 'csv_import', or 'migration'.
	 * @return bool True if email was sent, false otherwise
	 */
	public function send_wp_user_notification( int $user_id, string $context = 'submission' ): bool {
		$settings = \FreeFormCertificate\Settings\SettingsReader::all();

		// Debug logging.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'send_wp_user_notification called',
				array(
					'user_id'            => $user_id,
					'context'            => $context,
					'disable_all_emails' => isset( $settings['disable_all_emails'] ) ? $settings['disable_all_emails'] : 'NOT SET',
				)
			);
		}

		// Check global disable.
		if ( self::ffc_emails_disabled() ) {
			return false;
		}

		// Check context-specific setting.
		// Each context has its own setting key and default value.
		$context_settings = array(
			'submission'  => array(
				'key'     => 'send_wp_user_email_submission',
				'default' => true,
			),
			'appointment' => array(
				'key'     => 'send_wp_user_email_appointment',
				'default' => true,
			),
			'csv_import'  => array(
				'key'     => 'send_wp_user_email_csv_import',
				'default' => false,
			),
			'migration'   => array(
				'key'     => 'send_wp_user_email_migration',
				'default' => false,
			),
		);

		$ctx     = $context_settings[ $context ] ?? $context_settings['submission'];
		$enabled = isset( $settings[ $ctx['key'] ] )
			? absint( $settings[ $ctx['key'] ] ) === 1
			: $ctx['default'];

		// Debug logging.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'send_wp_user_notification enabled check',
				array(
					'enabled'   => $enabled ? 'YES' : 'NO',
					'will_send' => $enabled ? 'YES' : 'NO',
				)
			);
		}

		if ( ! $enabled ) {
			return false;
		}

		// Send WordPress notification (welcome email with password reset link).
		wp_new_user_notification( $user_id, null, 'user' );

		// Debug logging.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'wp_new_user_notification called',
				array( 'user_id' => $user_id )
			);
		}

		return true;
	}
}
