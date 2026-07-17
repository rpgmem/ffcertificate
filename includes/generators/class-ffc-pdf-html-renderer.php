<?php
/**
 * PdfHtmlRenderer
 *
 * HTML rendering and placeholder processing for FFC PDFs. Split out of
 * PdfGenerator (#589 phase-2) so that data-assembly (PdfGenerator) and
 * HTML-rendering / placeholder-substitution (this class) are decoupled.
 *
 * Output is byte-identical to the pre-split PdfGenerator: every HTML string,
 * placeholder syntax, escaping rule, QR markup and template text is carried
 * over verbatim.
 *
 * @package FreeFormCertificate\Generators
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders certificate / receipt HTML and resolves template placeholders.
 */
class PdfHtmlRenderer {

	/**
	 * Generate HTML from template
	 *
	 * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
	 * This is now the single source of truth for HTML generation
	 *
	 * Supported placeholders:
	 * - {{name}}, {{email}}, {{auth_code}}, etc.
	 * - {{submission_date}} - Date when submission was created (from database)
	 * - {{print_date}} - Current date/time when PDF is being generated
	 * - {{main_address}} - Institutional address from Settings > General
	 * - {{site_name}} - WordPress site name
	 * - {{qr_code}} - QR Code with default settings
	 * - {{qr_code:size=150}} - Custom size
	 * - {{qr_code:size=200:margin=0}} - Custom size and margin
	 * - {{validation_url}} - Validation link with magic token
	 * - {{validation_url link:m>v}} - Custom link format
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @param string               $form_title Form title.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @param int|null             $submission_date Submission creation instant (unix UTC seconds since 6.6.0).
	 * @return string Generated HTML
	 */
	public function generate_html( array $data, string $form_title, array $form_config, ?int $submission_date = null ): string {
		$layout = isset( $form_config['pdf_layout'] ) && is_string( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';

		// Use default template if none configured.
		if ( empty( $layout ) ) {
			return $this->generate_default_html( $data, $form_title );
		}

		// {{submission_date}} - Submission creation date in DB (to avoid issues with reprinting)
		if ( ! empty( $submission_date ) ) {
			$layout = str_replace( '{{submission_date}}', \FreeFormCertificate\Core\DateFormatter::format_date( $submission_date, 'pdf' ), $layout );
		}

		// {{print_date}} - Current date/time of PDF generation/printing.
		$layout = str_replace( '{{print_date}}', \FreeFormCertificate\Core\DateFormatter::format_date( time(), 'pdf' ), $layout );

		$layout = str_replace( '{{form_title}}', esc_html( (string) $form_title ), $layout );

		// Inject settings-based placeholders into data (v4.6.10).
		$main_address = \FreeFormCertificate\Settings\SettingsReader::get( 'main_address', '' );
		if ( ! isset( $data['main_address'] ) && ! empty( $main_address ) ) {
			$data['main_address'] = $main_address;
		}
		if ( ! isset( $data['site_name'] ) ) {
			$data['site_name'] = get_bloginfo( 'name' );
		}

		// Ensure email field exists.
		if ( ! isset( $data['email'] ) && isset( $data['user_email'] ) ) {
			$data['email'] = $data['user_email'];
		}

		// Quiz score aliases: map {{score}}, {{max_score}}, {{score_percent}} to internal keys.
		if ( isset( $data['_quiz_score'] ) ) {
			$data['score'] = $data['_quiz_score'];
		}
		if ( isset( $data['_quiz_max_score'] ) ) {
			$data['max_score'] = $data['_quiz_max_score'];
		}
		if ( isset( $data['_quiz_percent'] ) ) {
			$data['score_percent'] = $data['_quiz_percent'];
		}

		// Replace field placeholders with formatted values.
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			// Format documents (CPF, RF, RG).
			if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ), true ) ) {
				$value = \FreeFormCertificate\Core\DocumentFormatter::format_document( $value );
			}

			// Format auth code with certificate prefix.
			if ( 'auth_code' === $key ) {
				$value = \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $value, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE );
			}

			// Apply allowed HTML filtering.
			$safe_value = wp_kses( $value, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() );
			$layout     = str_replace( '{{' . $key . '}}', $safe_value, $layout );
		}

		// Fix relative URLs to absolute.
		$site_url = untrailingslashit( get_home_url() );
		$layout   = preg_replace( '/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $layout ) ?? $layout;

		// Process QR Code placeholders.
		if ( strpos( $layout, '{{qr_code' ) !== false ) {
			$layout = $this->process_qrcode_placeholders( $layout, $data, $form_config );
		}

		// Process Validation URL placeholders.
		if ( strpos( $layout, '{{validation_url' ) !== false ) {
			$layout = \FreeFormCertificate\Generators\ValidationUrlPlaceholders::process( $layout, $data );
		}

		return $layout;
	}

	/**
	 * Process QR Code placeholders in template
	 *
	 * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
	 *
	 * Replaces {{qr_code}} and variants with actual QR Code images
	 *
	 * Supports formats:
	 * - {{qr_code}} - Default size
	 * - {{qr_code:size=150}} - Custom size
	 * - {{qr_code:size=200:margin=0}} - Size + margin
	 * - {{qr_code:size=250:margin=3:error=H}} - All params
	 *
	 * @param string               $layout Template HTML.
	 * @param array<string, mixed> $data Submission data.
	 * @param array<string, mixed> $form_config Form configuration.
	 * @return string Processed HTML
	 */
	private function process_qrcode_placeholders( string $layout, array $data, array $form_config ): string {
		// Autoloader handles class loading.
		$qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();

		// Determine target URL (magic link or verification page).
		$target_url = $this->get_qr_code_target_url( $data );

		\FreeFormCertificate\Core\Debug::log_pdf(
			'QR Code placeholder processing',
			array(
				'target_url'        => $target_url,
				'has_magic_token'   => isset( $data['magic_token'] ),
				'placeholder_found' => ( strpos( $layout, '{{qr_code' ) !== false ),
			)
		);

		// Get submission ID for caching.
		$submission_id = isset( $data['submission_id'] ) ? absint( $data['submission_id'] ) : 0;

		// Replace all QR Code placeholders.
		$layout = preg_replace_callback(
			'/\{\{qr_code(?::([^}]+))?\}\}/',
			function ( $matches ) use ( $qr_generator, $target_url, $submission_id ) {
				$placeholder = $matches[0];
				$result      = $qr_generator->parse_and_generate( $placeholder, $target_url, $submission_id );

				\FreeFormCertificate\Core\Debug::log_pdf(
					'QR Code placeholder replaced',
					array(
						'placeholder'   => $placeholder,
						'result_length' => strlen( $result ),
						'has_img_tag'   => ( strpos( $result, '<img' ) !== false ),
					)
				);

				return $result;
			},
			$layout
		) ?? $layout;

		return $layout;
	}

	/**
	 * Generate QR code for submission magic link
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $size QR code size (default: 200).
	 * @return string QR code image URL or data URI
	 */
	public static function generate_magic_link_qr( int $submission_id, int $size = 200 ): string {
		$magic_token = ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->findMagicTokenById( $submission_id );

		if ( null === $magic_token ) {
			return '';
		}

		// Use helper to generate magic link.
		$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );

		if ( empty( $magic_link ) ) {
			return '';
		}

		// Generate QR code.
		$qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();
		return $qr_generator->generate( $magic_link, array( 'size' => $size ) );
	}

	/**
	 * Get target URL for QR Code
	 *
	 * ✅ MOVED FROM FFC_Email_Handler (v2.9.14)
	 *
	 * Priority order:
	 * 1. Magic link with hash format (if token exists)
	 * 2. Verification page without parameters (fallback)
	 *
	 * Format: /valid#token=xxx (hash prevents WordPress redirects)
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return string URL
	 */
	private function get_qr_code_target_url( array $data ): string {
		$verification_url = untrailingslashit( site_url( 'valid' ) );

		// Priority 1: Magic link (if exists).
		$magic_token = isset( $data['magic_token'] ) ? $data['magic_token'] : '';
		$magic_url   = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $magic_token );

		return ! empty( $magic_url ) ? $magic_url : $verification_url;
	}

	/**
	 * Generate default HTML template when none configured
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @param string               $form_title Form title.
	 * @return string Default HTML
	 */
	private function generate_default_html( array $data, string $form_title ): string {
		$layout  = '<div style="text-align:center; padding: 50px;">';
		$layout .= '<h1>' . esc_html( $form_title ) . '</h1>';
		$layout .= '<p>' . esc_html__( 'We certify that the holder of the data below has completed the event.', 'ffcertificate' ) . '</p>';

		// Show name if exists.
		if ( isset( $data['name'] ) ) {
			$layout .= '<h2>' . esc_html( $data['name'] ) . '</h2>';
		}

		// Show auth code if exists.
		if ( isset( $data['auth_code'] ) ) {
			$layout .= '<p>' . esc_html__( 'Authenticity:', 'ffcertificate' ) . ' ' . esc_html( \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $data['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE ) ) . '</p>';
		}

		$layout .= '</div>';

		return $layout;
	}

	/**
	 * Get appointment receipt HTML template
	 *
	 * Loads from plugin default file. Can be overridden via filter.
	 *
	 * @since 4.2.0
	 * @return string HTML template with placeholders
	 */
	public function get_appointment_receipt_template(): string {
		$default_file = FFC_PLUGIN_DIR . 'html/default_appointment_receipt_1.html';

		// Allow override via filter.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix
		$template_file = apply_filters( 'ffcertificate_appointment_receipt_template_file', $default_file );

		// Validate: only allow paths inside the plugin or theme directories to prevent path traversal.
		$normalized = wp_normalize_path( (string) $template_file );
		$allowed    = array(
			wp_normalize_path( FFC_PLUGIN_DIR ),
			wp_normalize_path( get_template_directory() ),
			wp_normalize_path( get_stylesheet_directory() ),
		);
		$in_allowed = false;
		foreach ( $allowed as $base ) {
			if ( 0 === strpos( $normalized, $base ) ) {
				$in_allowed = true;
				break;
			}
		}
		if ( ! $in_allowed ) {
			$template_file = $default_file;
		}

		if ( file_exists( $template_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template file.
			$template = file_get_contents( $template_file );
			if ( ! empty( $template ) ) {
				return $template;
			}
		}

		// Fallback: minimal receipt template.
		return '<div style="width:1123px;height:794px;padding:60px;box-sizing:border-box;font-family:Arial,sans-serif">'
			. '<h1 style="text-align:center;color:#0073aa">' . esc_html__( 'Appointment Receipt', 'ffcertificate' ) . '</h1>'
			. '<p><strong>' . esc_html__( 'Name:', 'ffcertificate' ) . '</strong> {{name}}</p>'
			. '<p><strong>' . esc_html__( 'Event:', 'ffcertificate' ) . '</strong> {{calendar_title}}</p>'
			. '<p><strong>' . esc_html__( 'Date:', 'ffcertificate' ) . '</strong> {{appointment_date}}</p>'
			. '<p><strong>' . esc_html__( 'Time:', 'ffcertificate' ) . '</strong> {{appointment_time}}</p>'
			. '<p><strong>' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</strong> {{validation_code}}</p>'
			. '</div>';
	}
}
