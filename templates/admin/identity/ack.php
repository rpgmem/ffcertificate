<?php
/**
 * Template: Identity Resolution — acknowledgement box.
 *
 * The box a correction needs once its value is another account's (#1478).
 * Rendered by `$ffc_identity_ack` with `$subject` and `$refused`.
 *
 * Included from `includes/admin/views/identity-resolution-page.php`, inside
 * `IdentityResolutionPage::render_page()`, so the page's `$ffc_identity_*`
 * locals are in scope. Moved verbatim (#1925): the page renders byte for
 * byte as it did when this was inline.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
	<label class="ffc-identity-ack" id="ffc-ack-<?php echo esc_attr( (string) $subject ); ?>"
		<?php echo $refused ? '' : 'hidden'; ?>>
		<?php
		// `required` TRAVELS WITH VISIBILITY, AND NOT AS A CONVENIENCE.
		//
		// Constraint validation ignores whether a control is on screen, so a
		// `required` checkbox inside a hidden block blocks the submit against
		// something nobody can see -- Chrome reports `An invalid form control
		// with name='ffc_acknowledged' is not focusable` and the operator has
		// nothing to act on. That is the #1117 class, and here it would have
		// jammed the ordinary correction: every repair form carries this box
		// now, and most corrections never need it.
		//
		// So the hidden state ships the marker `FFC.setRequiredWithin()`
		// reads and NOT the attribute. Declaring it required-but-off in the
		// markup rather than stripping it in JS at load is what removes the
		// window between the two: there is no moment where a real `required`
		// sits inside a hidden block.
		?>
		<input type="checkbox" name="ffc_acknowledged" value="1"
			<?php echo $refused ? 'required' : 'data-ffc-required-off=""'; ?>>
		<?php esc_html_e( 'I have confirmed this number with HR and mean to write it even though another account already carries it. Both accounts will then hold it, and the merge is decided separately.', 'ffcertificate' ); ?>
	</label>
	<?php
