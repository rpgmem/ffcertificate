<?php
/**
 * Math captcha fields.
 *
 * Extracted from the two inline copies that had drifted apart (#1053 PR2).
 *
 * @package FreeFormCertificate
 * @since 6.23.0
 *
 * @var string $ffc_captcha_label Challenge question, already translated.
 * @var string $ffc_captcha_token Signed, expiring challenge token.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-captcha-row">
	<label for="ffc_captcha_ans">
		<span class="ffc-captcha-label-text"><?php echo esc_html( $ffc_captcha_label ); ?></span> <span class="required">*</span>
	</label>
	<input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required aria-required="true">
	<input type="hidden" name="ffc_captcha_hash" id="ffc_captcha_hash" value="<?php echo esc_attr( $ffc_captcha_token ); ?>">
</div>
