<?php
/**
 * QR design preview AJAX endpoint.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\QrSvgRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Settings → QR Code preview from the form's UNSAVED values (#1563).
 *
 * Read-only: it draws and reports the scan checks, and writes nothing, so it
 * is gated on the view tier of the settings page. The design is normalised by
 * `QrDesign` exactly as a saved one would be, so the preview cannot show a
 * value the save would refuse.
 */
class QrDesignPreviewAjaxEndpoint {

	public const AJAX_ACTION = 'ffc_qr_design_preview';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Draw the preview.
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! Capabilities::current_user_can_admin_or( 'ffc_view_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}

		$design = new QrDesign( RequestInput::get_post_array( 'design' ) );
		$ecc    = strtoupper( RequestInput::get_post_string( 'error_level', 'M' ) );
		$ecc    = in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ? $ecc : 'M';
		$margin = max( 0, min( 10, RequestInput::get_post_int( 'margin', 2 ) ) );

		// The site's own address stands in for a real payload: it is the
		// typical length of a certificate's verification link.
		$matrix = QrSvgRenderer::matrix( home_url( '/' ), $ecc );
		if ( array() === $matrix ) {
			wp_send_json_error( array( 'message' => __( 'QR generation failed.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				'svg'    => QrSvgRenderer::render_matrix( $matrix, $design, $margin, 240 ),
				'checks' => $design->scan_checks(),
			)
		);
	}
}
