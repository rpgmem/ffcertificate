<?php
/**
 * Screenshot viewer in the "View details" modal.
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the viewer that opens the modal's screenshots in an overlay instead
 * of a new tab (`assets/js/ffc-screenshots.js`).
 *
 * The modal is core's `plugin-install.php?tab=plugin-information` page, shown
 * in an iframe, and it fires `admin_enqueue_scripts` like any admin screen.
 * Its sections pass through kses, which strips scripts, so the viewer has to
 * arrive as an enqueued file. It loads only on that page and only for this
 * plugin's slug, so no other plugin's modal and no other screen carries it.
 *
 * @since 6.39.0
 */
final class ScreenshotViewer {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'ffc-screenshots';

	/**
	 * Register the enqueue hook. Default priority: nothing else on that page
	 * depends on the order.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the viewer on this plugin's "View details" modal.
	 *
	 * @param string $hook_suffix The admin page being loaded.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! self::is_our_modal( $hook_suffix ) ) {
			return;
		}

		// Standalone on purpose: core's modal is not an FFC screen, so
		// ffc-common (and its palette) is not loaded there.
		$s = AssetHelper::asset_suffix();
		wp_enqueue_style( 'ffc-screenshots', FFC_PLUGIN_URL . "assets/css/ffc-screenshots{$s}.css", array(), FFC_VERSION );
		wp_enqueue_script( self::HANDLE, FFC_PLUGIN_URL . "assets/js/ffc-screenshots{$s}.js", array(), FFC_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'ffcScreenshots',
			array(
				'dialog'   => __( 'Screenshots', 'ffcertificate' ),
				'close'    => __( 'Close', 'ffcertificate' ),
				'previous' => __( 'Previous screenshot', 'ffcertificate' ),
				'next'     => __( 'Next screenshot', 'ffcertificate' ),
				'zoomIn'   => __( 'Zoom in', 'ffcertificate' ),
				'zoomOut'  => __( 'Zoom out', 'ffcertificate' ),
				/* translators: %1$s: number of the screenshot shown, %2$s: number of screenshots */
				'counter'  => __( '%1$s of %2$s', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Is this the "View details" modal for this plugin?
	 *
	 * A read-only check of the page's own query arguments: core's modal link
	 * carries no nonce, and nothing is changed here beyond loading two files.
	 *
	 * @param string $hook_suffix The admin page being loaded.
	 */
	private static function is_our_modal( string $hook_suffix ): bool {
		return 'plugin-install.php' === $hook_suffix
			&& 'plugin-information' === RequestInput::get_get_key( 'tab' )
			&& GithubUpdater::SLUG === RequestInput::get_get_key( 'plugin' );
	}
}
