<?php
/**
 * CertificatesAppScreen
 *
 * The Certificates screen of the installable app (#2781): one search field,
 * the newest certificate as a featured card and the rest as compact rows.
 * It reads the same `user/certificates` route as the dashboard, so the
 * rules about who may download what stay in one place.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Core\AssetHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the app's Certificates screen; the dashboard outside the app keeps
 * its own table.
 */
final class CertificatesAppScreen {

	/**
	 * How long a certificate counts as new, in days.
	 */
	public const NEW_DAYS = 7;

	/**
	 * The screen's markup, with its sheet and script enqueued.
	 *
	 * @return string
	 */
	public static function render(): string {
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();

		$suffix = AssetHelper::asset_suffix();
		wp_enqueue_style(
			'ffc-app-certificates',
			FFC_PLUGIN_URL . "assets/css/ffc-app-certificates{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-app-certificates',
			FFC_PLUGIN_URL . "assets/js/ffc-app-certificates{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-app-certificates',
			'ffcAppCertificates',
			array(
				'rest'    => esc_url_raw( rest_url( 'ffc/v1/user/certificates' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'newDays' => self::NEW_DAYS,
				'strings' => array(
					'loading'  => __( 'Loading your certificates…', 'ffcertificate' ),
					'failed'   => __( 'Your certificates could not be loaded. Check the connection and try again.', 'ffcertificate' ),
					'empty'    => __( 'You have no certificate yet.', 'ffcertificate' ),
					'noMatch'  => __( 'No certificate matches the search.', 'ffcertificate' ),
					'new'      => __( 'New', 'ffcertificate' ),
					/* translators: %s: the date the certificate was issued */
					'issued'   => __( 'Issued on %s', 'ffcertificate' ),
					/* translators: %s: the certificate's authentication code */
					'code'     => __( 'Code %s', 'ffcertificate' ),
					'open'     => __( 'Open PDF', 'ffcertificate' ),
					'share'    => __( 'Share', 'ffcertificate' ),
					'copied'   => __( 'Link copied.', 'ffcertificate' ),
					'noShare'  => __( 'This phone cannot share the link. Open the PDF and share it from there.', 'ffcertificate' ),
					'noAccess' => __( 'Downloading is not available for this account.', 'ffcertificate' ),
				),
			)
		);

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/public/app/certificates.php';
		return (string) ob_get_clean();
	}
}
