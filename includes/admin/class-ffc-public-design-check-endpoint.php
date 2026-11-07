<?php
/**
 * PublicDesignCheckEndpoint
 *
 * The live contrast check of Settings → Public Design.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\PublicDesign;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Measures the colours on the screen before they are saved (#2341).
 *
 * The warning used to appear only after Save. The tab now asks as the
 * colours change, and the answer comes from the same
 * `PublicDesign::contrast_warning()` the save uses, so the browser carries
 * no copy of the contrast maths that could disagree with the server's.
 * Nothing is written.
 */
final class PublicDesignCheckEndpoint {

	/**
	 * AJAX action, which is also the nonce action.
	 */
	public const ACTION = 'ffc_public_design_check';

	/**
	 * Register the handler (admin-only: the tab is an admin screen).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Answer with the warning the posted colours earn ('' when none).
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change the public design.', 'ffcertificate' ) ), 403 );
		}

		$raw = array( 'enabled' => 1 );
		foreach ( array( 'accent', 'border', 'muted', 'error', 'info' ) as $field ) {
			// The constructor validates each colour and falls back on a bad one.
			$raw[ $field ] = \FreeFormCertificate\Core\RequestInput::get_post_string( $field );
		}

		wp_send_json_success( array( 'warning' => ( new PublicDesign( $raw ) )->contrast_warning() ) );
	}
}
