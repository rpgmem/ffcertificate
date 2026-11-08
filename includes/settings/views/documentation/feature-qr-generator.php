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
	<p><?php esc_html_e( 'A capacity meter (in bytes: a plain letter takes one, an accented letter two) and readability warnings help keep the code readable, and the preview panel can also print it. Every warning shows at once: low contrast, inverted colours, a transparent background, a caption hard to read on its ground, a margin of 0 with no quiet zone around the code, and a dense code. An event code can open the event itself, a pre-filled Google Calendar link, or a signed link to an .ics file; only that link can carry a "valid until" date, after which it answers that it has expired, while a link without one never expires. The generator reopens with each user\'s last downloaded design (design only, never content), and "Reset to default" restores the global one.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The fields check what is typed the way the rest of the plugin does. A phone number takes the (DD) 98765-4321 mask and the code adds +55, so a call, an SMS or a WhatsApp chat works from anywhere; an e-mail is checked and stored in lower case; a contact card\'s website must look like a web address. On a contact card the postal code comes first and, when the CEP lookup is on (Settings → General), fills in the street, the city and the state.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The contact card is a vCard 3.0, the version phones read most widely; the type links its specification. The department goes into the organisation, after its name. vCard 3.0 has a single street line, so the number and the neighbourhood are written on it after the street.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A contact card also has a Role, apart from the job title, and a Download .vcf button under the code saves the card itself, the same one the code carries, to send by e-mail or import into a phone or an e-mail client. Nothing is stored.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Each type opens with example content, so the preview shows a code at once: the site address, its name, an open network, the administrator\'s e-mail, a sample phone number, your own contact card, a profile named after the site, or an event today from 9:00 to 10:00. Replace it with your own. A type you already typed into keeps what you typed.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Short URLs created here', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'This is where manual short URLs are made: the Short URLs list has no form of its own, and its "New short URL" button opens the generator on the Website type. A short URL created here appears on the list like any other and counts the clicks of the printed code.', 'ffcertificate' ); ?> <a href="#feature-url-shortener"><?php esc_html_e( 'See Short URLs', 'ffcertificate' ); ?></a>.</p>
	<p><?php esc_html_e( 'While the content is still the example, the code carries it directly and no short URL is created, not even on a download or a print. Once you change the content, the short URL works as described above: it is created on the first download or print.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Design', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The design sections are the same as on Settings → QR Code, and start from the global design (or from the user\'s last downloaded one) whether or not it is applied to certificates or short URLs there. The margin and the error-correction level start from the QR Code defaults too; the default size does not apply, since the download sets its own width. A change made in the generator applies to this code only and never alters the global settings.', 'ffcertificate' ); ?> <a href="#config-qr-code"><?php esc_html_e( 'See QR Code settings', 'ffcertificate' ); ?></a>.</p>
</div>
