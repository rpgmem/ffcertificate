<?php
/**
 * "Send a test email" box of the Email Model tab.
 *
 * Its own card, printed by {@see \FreeFormCertificate\Settings\Tabs\TabEmailModel::render_unlocked()}
 * OUTSIDE the Settings read-only lock: the FFC Settings Operator may send the
 * test (`ffc_test_settings_smtp`, #1860) without being able to change the
 * Email Model, and a control inside the lock's disabled fieldset can never be
 * pressed. The form posts `ffc_send_test_email`, handled by
 * {@see \FreeFormCertificate\Admin\SettingsActionHandler::handle_send_test_email()}.
 *
 * @package FreeFormCertificate\Settings\Views
 * @since   6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_current_user  = wp_get_current_user();
$ffc_test_email_to = is_object( $ffc_current_user ) ? (string) $ffc_current_user->user_email : '';
?>
<div class="card ffc-email-model-test">
	<h2 class="ffc-icon-send"><?php esc_html_e( 'Send a test email', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php
		if ( '' !== $ffc_test_email_to ) {
			printf(
				/* translators: %s: the current user's account email address. */
				esc_html__( 'Send a test message to your own account (%s) to confirm SMTP and the Email Model are working. The message is always sent to you, never to another address.', 'ffcertificate' ),
				'<code>' . esc_html( $ffc_test_email_to ) . '</code>'
			);
		} else {
			esc_html_e( 'Your account has no email address on file, so a test email cannot be sent.', 'ffcertificate' );
		}
		?>
	</p>
	<form method="post">
		<?php wp_nonce_field( 'ffc_send_test_email' ); ?>
		<input type="hidden" name="ffc_send_test_email" value="1">
		<?php submit_button( __( 'Send Test Email', 'ffcertificate' ), 'secondary', 'ffc_send_test_email_submit', false, '' === $ffc_test_email_to ? array( 'disabled' => 'disabled' ) : array() ); ?>
	</form>
</div>
