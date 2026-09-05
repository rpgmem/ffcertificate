<?php
/**
 * ALTCHA captcha fields.
 *
 * The widget is a custom element, so there is no `<input>` here: it creates
 * its own hidden field named by the `name` attribute and fills it with the
 * base64 solution once the proof of work completes.
 *
 * Only nine attributes exist on the 3.x element — `auto`, `challenge`,
 * `configuration`, `display`, `language`, `name`, `theme`, `type`, `workers`.
 * Everything else people expect to set (`hideLogo`, `hideFooter`,
 * `humanInteractionSignature`, `setCookie`, the floating options) is not an
 * attribute at all and travels as JSON in `configuration`; writing them as
 * attributes fails silently, which is the trap this note exists to prevent.
 *
 * `challenge` holds a URL rather than inline JSON on purpose. The widget
 * treats a string starting with `{` as the challenge itself and anything else
 * as an address to fetch, and a challenge baked into the markup would be
 * served stale — and shared — by a full-page cache.
 *
 * @package FreeFormCertificate
 * @since 6.23.0
 *
 * Translated labels are NOT set here either. The 3.x widget reads them from
 * the `globalThis.$altcha.i18n` store, keyed by language, and picks the entry
 * matching this element's `language` attribute (falling back to the document
 * language, then the browser's, then English). `assets/js/ffc-captcha.js`
 * registers the plugin's own strings there, which is what keeps the 52 KB
 * upstream i18n bundle out of the page.
 *
 * @var string               $ffc_altcha_challenge_url Endpoint that issues challenges.
 * @var array<string, string> $ffc_altcha_attributes    The two configurable attributes.
 * @var string               $ffc_altcha_configuration Widget configuration, JSON.
 * @var string               $ffc_altcha_language      Language key registered in the i18n store.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-security-row ffc-altcha-row">
	<altcha-widget
		name="<?php echo esc_attr( \FreeFormCertificate\Core\Captcha\AltchaCaptcha::FIELD ); ?>"
		challenge="<?php echo esc_url( $ffc_altcha_challenge_url ); ?>"
		configuration="<?php echo esc_attr( $ffc_altcha_configuration ); ?>"
		language="<?php echo esc_attr( $ffc_altcha_language ); ?>"
		type="<?php echo esc_attr( $ffc_altcha_attributes['type'] ); ?>"
		auto="<?php echo esc_attr( $ffc_altcha_attributes['auto'] ); ?>"
	></altcha-widget>
	<noscript>
		<p class="ffc-altcha-noscript">
			<?php esc_html_e( 'This form requires JavaScript to verify that you are not a robot.', 'ffcertificate' ); ?>
		</p>
	</noscript>
</div>
