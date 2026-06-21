<?php
/**
 * Success HTML Renderer
 *
 * Renders the post-submission success card. Extracted from {@see \FreeFormCertificate\Core\Utils}
 * (#563 Sprint 5 phase 2, B1) into the submission namespace, next to its sole
 * caller {@see SuccessResponder} — it is a frontend view concern, not a
 * general-purpose utility.
 *
 * @package FreeFormCertificate\Frontend\Submission
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the success-card HTML for a completed form submission.
 */
final class SuccessHtmlRenderer {

	/**
	 * Generate success HTML response for frontend form submission.
	 *
	 * @param array<string, mixed> $submission_data Submission data.
	 * @param int                  $form_id Form ID.
	 * @param int|string           $submission_date Submission date — unix UTC int (since 6.6.0, reprint flow)
	 *                                              or MySQL `Y-m-d H:i:s` string (fresh submission via `current_time('mysql')`).
	 *                                              DateFormatter::format_datetime accepts both.
	 * @param string               $success_message Success message.
	 * @param int                  $submission_id Submission ID (used to surface the magic link in the success card; 0 to skip).
	 * @param object|null          $submission_handler Handler that knows how to ensure/load the submission's magic token.
	 * @return string HTML content
	 */
	public static function generate_success_html( array $submission_data, int $form_id, int|string $submission_date, string $success_message = '', int $submission_id = 0, ?object $submission_handler = null ): string {
		// Get form configuration.
		$form_config = get_post_meta( $form_id, '_ffc_form_config', true );
		if ( ! is_array( $form_config ) ) {
			$form_config = array();
		}

		// Get form title.
		$form_post  = get_post( $form_id );
		$form_title = $form_post ? $form_post->post_title : __( 'Certificate', 'ffcertificate' );

		// Default success message.
		if ( empty( $success_message ) ) {
			$success_message = isset( $form_config['success_message'] ) && ! empty( $form_config['success_message'] )
				? $form_config['success_message']
				: __( 'Success! Your certificate has been generated.', 'ffcertificate' );
		}

		$date_formatted = DateFormatter::format_datetime( $submission_date );

		// Auth code (formatted for display with certificate prefix).
		$auth_code = isset( $submission_data['auth_code'] ) ? DocumentFormatter::format_auth_code( $submission_data['auth_code'], DocumentFormatter::PREFIX_CERTIFICATE ) : '';

		// Magic link — survives the tab close, so the user can come back
		// later from a different device and re-issue the certificate.
		$magic_link = '';
		if ( $submission_id > 0 && $submission_handler && class_exists( '\FreeFormCertificate\Generators\MagicLinkHelper' ) ) {
			$magic_link = \FreeFormCertificate\Generators\MagicLinkHelper::get_submission_magic_link( $submission_id, $submission_handler );
		}

		// Load template.
		ob_start();
		include FFC_PLUGIN_DIR . 'templates/submission-success.php';
		$rendered = ob_get_clean();
		return $rendered ? $rendered : '';
	}
}
