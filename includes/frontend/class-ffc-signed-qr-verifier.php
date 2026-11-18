<?php
/**
 * SignedQrVerifier
 *
 * What the browser needs to check a signed QR code (#2639): the site's
 * public keys and the strings of the result, for the verification page and
 * for the standalone verifier file.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\SigningKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The verifier's data, and the standalone file built from it.
 */
final class SignedQrVerifier {

	/** Script handle of `assets/js/ffc-signed-qr.js`. */
	public const HANDLE = 'ffc-signed-qr';

	/** The global the localized data is published under. */
	public const OBJECT_NAME = 'ffcSignedQr';

	/**
	 * Enqueue the verifier on the verification page, once a key exists.
	 * Before that no certificate carries a signed QR code, so nothing loads.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( array() === SigningKeys::public_keys() ) {
			return;
		}
		$s = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( self::HANDLE, FFC_PLUGIN_URL . "assets/js/ffc-signed-qr{$s}.js", array(), FFC_VERSION, true );
		wp_localize_script( self::HANDLE, self::OBJECT_NAME, self::data() );
	}

	/**
	 * Public keys and strings, as the script reads them.
	 *
	 * @return array{keys: array<string, string>, strings: array<string, string>}
	 */
	public static function data(): array {
		return array(
			'keys'    => SigningKeys::public_keys(),
			'strings' => self::strings(),
		);
	}

	/**
	 * The result strings, keyed as `FFCSignedQr.describe()` reads them.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'valid'           => __( 'Signed by the issuer', 'ffcertificate' ),
			'validText'       => __( 'The QR code carries this site\'s signature over the data below. Compare it with the paper.', 'ffcertificate' ),
			'superseded'      => __( 'Changed after issue', 'ffcertificate' ),
			'supersededText'  => __( 'The QR code is genuine but was signed for an earlier version of this certificate: its data has changed since. Ask the issuer which version is the right one.', 'ffcertificate' ),
			'invalid'         => __( 'Signature not valid', 'ffcertificate' ),
			'invalidText'     => __( 'The QR code\'s signature does not match its data. It was not issued this way by this site.', 'ffcertificate' ),
			'unknownKeyText'  => __( 'The QR code names a signing key this site does not have.', 'ffcertificate' ),
			'unsupported'     => __( 'Signature not checked', 'ffcertificate' ),
			'unsupportedText' => __( 'This browser cannot check the QR code\'s signature. Use an up-to-date browser, or rely on the result from the site.', 'ffcertificate' ),
			'code'            => __( 'Validation code:', 'ffcertificate' ),
			'issued'          => __( 'Issued on:', 'ffcertificate' ),
			'name'            => __( 'Name:', 'ffcertificate' ),
			'course'          => __( 'Course:', 'ffcertificate' ),
			'workload'        => __( 'Workload (hours):', 'ffcertificate' ),
		);
	}

	/**
	 * The standalone verifier: one HTML file carrying the public keys and the
	 * verifier script, to check a signed QR code with no server.
	 *
	 * @return string
	 */
	public static function standalone_html(): string {
		$script = (string) file_get_contents( FFC_PLUGIN_DIR . 'assets/js/ffc-signed-qr.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The plugin's own file on disk, not a URL.

		$verifier_site   = (string) get_bloginfo( 'name' );
		$verifier_script = $script;
		$verifier_data   = self::data();
		$verifier_date   = \FreeFormCertificate\Core\DateFormatter::format_date( time() );
		$verifier_ui     = array(
			'title'            => __( 'Certificate QR code verifier', 'ffcertificate' ),
			'intro'            => __( 'Checks the signature a certificate\'s QR code carries, with no internet connection. Scan the QR code with the phone\'s camera, copy the link it opens, and paste it below.', 'ffcertificate' ),
			'label'            => __( 'Link the QR code opens', 'ffcertificate' ),
			'check'            => __( 'Check', 'ffcertificate' ),
			'no_payload_title' => __( 'No signature in this link', 'ffcertificate' ),
			'no_payload'       => __( 'This link carries no signature: the certificate was issued with the link only, or the link was cut short. Paste the whole link, including the part after #.', 'ffcertificate' ),
		);

		ob_start();
		include FFC_PLUGIN_DIR . 'templates/signed-qr-verifier.php';
		return (string) ob_get_clean();
	}
}
