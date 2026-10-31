<?php
/**
 * Set-password screen for an invited member.
 *
 * Rendered by the dashboard shortcode when the request carries the invitation
 * link (#1212). Markup only: `Core\PasswordInvite` is what validates the key,
 * decides the message and writes the password.
 *
 * The key and the login are not in the markup: they travel in the
 * `PasswordInvite::COOKIE` cookie, never in a form field or a URL (#2246).
 *
 * Variables coming from the caller's scope:
 *
 * @var string $ffc_error    Slug of the error to show, or ''.
 * @var int    $ffc_min_len  Minimum password length.
 *
 * @package FreeFormCertificate\Templates
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_messages = array(
	'mismatch' => __( 'The two passwords did not match. Try again.', 'ffcertificate' ),
	/* translators: %d: minimum number of characters */
	'short'    => sprintf( __( 'Use at least %d characters.', 'ffcertificate' ), (int) $ffc_min_len ),
	'nonce'    => __( 'The form expired before it was sent. Try again.', 'ffcertificate' ),
	'invalid'  => __( 'This link is not valid.', 'ffcertificate' ),
);
?>
<div class="ffc-shortcode ffc-user-dashboard ffc-set-password">
	<div class="ffc-set-password-card">
		<h2><?php esc_html_e( 'Define your password', 'ffcertificate' ); ?></h2>
		<p><?php esc_html_e( 'Choose a password to finish setting up your account. You will go straight to your dashboard.', 'ffcertificate' ); ?></p>

		<?php if ( '' !== $ffc_error && isset( $ffc_messages[ $ffc_error ] ) ) : ?>
			<?php
			$ffc_notice = \FreeFormCertificate\Core\Notice::render(
				'warning',
				'warning',
				$ffc_messages[ $ffc_error ],
				'',
				array(
					'role' => 'alert',
					'hook' => 'set-password-error',
				)
			);
			echo $ffc_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes every value.
			?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ffc-set-password-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( \FreeFormCertificate\Core\PasswordInvite::ACTION ); ?>">
			<?php echo \FreeFormCertificate\Core\PasswordInvite::nonce_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `wp_nonce_field()` returns markup core has already escaped. ?>

			<p class="ffc-set-password-field">
				<label for="ffc_pass1"><?php esc_html_e( 'New password', 'ffcertificate' ); ?></label>
				<input type="password" name="ffc_pass1" id="ffc_pass1" autocomplete="new-password"
					minlength="<?php echo esc_attr( (string) (int) $ffc_min_len ); ?>" required>
			</p>

			<p class="ffc-set-password-field">
				<label for="ffc_pass2"><?php esc_html_e( 'Repeat the password', 'ffcertificate' ); ?></label>
				<input type="password" name="ffc_pass2" id="ffc_pass2" autocomplete="new-password"
					minlength="<?php echo esc_attr( (string) (int) $ffc_min_len ); ?>" required>
			</p>

			<p class="ffc-set-password-actions">
				<?php
				\FreeFormCertificate\Core\Button::render(
					array(
						'label'    => __( 'Save and enter', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'save',
						'type'     => 'submit',
					)
				);
				?>
			</p>
		</form>
	</div>
</div>
