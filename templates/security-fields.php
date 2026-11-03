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
 * @var string $ffc_captcha_fields  Provider-rendered challenge HTML, pre-escaped.
 * @var string $ffc_captcha_context What the challenge is bound to; the cached-page
 *                                  refresh asks for one bound to the same thing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-security-container" data-ffc-captcha-context="<?php echo esc_attr( $ffc_captcha_context ); ?>">
	<div class="ffc-honeypot-field" aria-hidden="true">
		<label><?php esc_html_e( 'Do not fill this field if you are human:', 'ffcertificate' ); ?>
			<input type="text" name="<?php echo esc_attr( \FreeFormCertificate\Core\Honeypot::TRAP_FIELD ); ?>" value="" tabindex="-1" autocomplete="off">
		</label>
	</div>
	<input type="hidden" name="<?php echo esc_attr( \FreeFormCertificate\Core\Honeypot::STAMP_FIELD ); ?>" value="<?php echo esc_attr( \FreeFormCertificate\Core\Honeypot::stamp() ); ?>">

	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- provider markup, escaped at its own output points in templates/captcha/*.php.
	echo $ffc_captcha_fields;
	?>
</div>
