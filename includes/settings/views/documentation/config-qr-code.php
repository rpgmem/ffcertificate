<?php
/**
 * Documentation partial — Settings → QR Code.
 *
 * The plugin-wide QR code defaults and design, where each applies, and what
 * the manual generator takes from them.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- QR Code Settings Section -->
<div class="card">
	<h3 id="config-qr-code" class="ffc-icon-qr"><?php esc_html_e( 'QR Code', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'These settings apply plugin-wide, to every QR code the plugin draws by itself: the {{qr_code}} placeholder of the certificate PDF, and the QR codes of short URLs. The one exception is the QR Code Generator under Short URLs, where each code is designed by hand: it only starts from the global design, and nothing changed there is saved back.', 'ffcertificate' ); ?> <a href="#feature-qr-generator"><?php esc_html_e( 'See the QR Code Generator', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'QR Code defaults', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Default size (px), margin (modules) and error-correction level (L / M / Q / H) applied to the {{qr_code}} placeholder when it does not specify its own. Per-placeholder options always win.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'QR Code Design', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Settings → QR Code also sets the shape of the modules and of the three corner markers, their colours and an optional gradient, with a live preview that warns about low contrast and inverted colours. The design is applied only where it is switched on: the {{qr_code}} placeholder of certificates, and the QR codes of short URLs. Elsewhere the plain black-and-white code is kept.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A logo from the Media Library can be drawn in the centre (error correction is raised to H automatically), and a frame (banner, badge, bubble, pill, speech, circle, brackets or double) can carry a short caption such as "Scan to verify", optionally with an icon. A frame makes the image taller than wide; certificates keep the width the placeholder asks for and grow in height.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The attributes the {{qr_code}} placeholder can set for itself are listed under', 'ffcertificate' ); ?> <a href="#reference-qr-codes"><?php esc_html_e( 'QR Code in the Certificate', 'ffcertificate' ); ?></a>.</p>
</div>
