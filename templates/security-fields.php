<?php
/**
 * Security fields: the honeypot plus the active captcha provider's challenge.
 *
 * Single source of the block that the certificate form, the public CSV
 * download and the self-scheduling booking form all render (#1053 PR2).
 *
 * @package FreeFormCertificate
 * @since 6.23.0
 *
 * @var string $ffc_captcha_fields Provider-rendered challenge HTML, pre-escaped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-security-container">
	<div class="ffc-honeypot-field">
		<label><?php esc_html_e( 'Do not fill this field if you are human:', 'ffcertificate' ); ?>
			<input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
		</label>
	</div>

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- provider markup, escaped at its own output points in templates/captcha/*.php.
	echo $ffc_captcha_fields;
	?>
</div>
