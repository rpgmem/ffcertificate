<?php
/**
 * Documentation partial — QR Code Generator.
 *
 * The manual QR code tool under Short URLs: content types, the short URL it
 * can create for a website or a social profile, the per-code design, and the
 * download, print and readability checks.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- QR Code Generator Section -->
<div class="card">
	<h3 id="feature-qr-generator" class="ffc-icon-qr"><?php esc_html_e( 'QR Code Generator', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Short URLs → QR Code Generator makes a QR code for a website, plain text, a Wi-Fi network (Enterprise networks that sign in with a user name included), an e-mail, a phone call, an SMS, a WhatsApp chat, a contact card (vCard), a social profile or an event, styled from the global design and downloaded as PNG or SVG. For a website or a social profile, "Create a short URL" (on by default) puts a short URL in the code instead of the address: the preview shows an example, and the real one is created on the first download or print, then reused until the destination or the title changes. The title is then required; an address that already has a short URL is flagged, with "Use this" to reuse it, and creating another needs an explicit confirmation; an address that is already a short URL of this site is never shortened again. With the switch off nothing is stored. It needs ffc_manage_url_shortener.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A capacity meter and density and contrast warnings help keep the code readable, and the preview panel can also print it. An event code can open the event itself, a pre-filled Google Calendar link, or a signed link to an .ics file; that link may carry an optional "valid until" date, after which it answers that it has expired, while a link without one never expires. The generator reopens with each user\'s last downloaded design (design only, never content), and "Reset to default" restores the global one.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Short URLs created here', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'This is where manual short URLs are made: the Short URLs list has no form of its own, and its "New short URL" button opens the generator on the Website type. A short URL created here appears on the list like any other and counts the clicks of the printed code.', 'ffcertificate' ); ?> <a href="#feature-url-shortener"><?php esc_html_e( 'See Short URLs', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Design', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The design sections are the same as on Settings → QR Code, and start from the global design (or from the user\'s last downloaded one) whether or not it is applied to certificates or short URLs there. A change made in the generator applies to this code only and never alters the global settings; the QR Code defaults for size, margin and error correction do not apply here either, since each code sets its own.', 'ffcertificate' ); ?> <a href="#config-qr-code"><?php esc_html_e( 'See QR Code settings', 'ffcertificate' ); ?></a>.</p>
</div>
