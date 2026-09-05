<?php
/**
 * Math captcha fields.
 *
 * Extracted from the two inline copies that had drifted apart (#1053 PR2).
 *
 * The ids carry a per-render suffix (#1056): the plugin supports several forms
 * on one page — `DynamicFragments` has a branch dedicated to it — and fixed ids
 * duplicated across them, which breaks the `<label for>` association a screen
 * reader relies on to announce a required field. Nothing reads these ids
 * programmatically: every script matches the inputs by `name`, scoped to the
 * form. The `name` attributes are the contract with the server and never change.
 *
 * @package FreeFormCertificate
 * @since 6.23.0
 *
 * @var string $ffc_captcha_label    Challenge question, already translated.
 * @var string $ffc_captcha_token    Signed, expiring challenge token.
 * @var string $ffc_captcha_ans_id   Unique id for the answer input.
 * @var string $ffc_captcha_hash_id  Unique id for the hidden token input.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-captcha-row">
	<label for="<?php echo esc_attr( $ffc_captcha_ans_id ); ?>">
		<span class="ffc-captcha-label-text"><?php echo esc_html( $ffc_captcha_label ); ?></span> <span class="required">*</span>
	</label>
	<input type="number" name="ffc_captcha_ans" id="<?php echo esc_attr( $ffc_captcha_ans_id ); ?>" class="ffc-input" required aria-required="true">
	<input type="hidden" name="ffc_captcha_hash" id="<?php echo esc_attr( $ffc_captcha_hash_id ); ?>" value="<?php echo esc_attr( $ffc_captcha_token ); ?>">
</div>
