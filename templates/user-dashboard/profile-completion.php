<?php
/**
 * The app screen that asks for the details missing from a profile (#2962).
 *
 * @package FreeFormCertificate
 *
 * @var \WP_User           $user    The person.
 * @var list<string>       $missing What is missing, as ProfileCompletion::MISSING_* values.
 * @var list<string>       $shown   The fields to fill in, as HTML built by the reregistration renderer.
 * @var list<string>|false $errors  Messages of a refused save, or false.
 */

use FreeFormCertificate\Reregistration\ProfileCompletion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_back  = add_query_arg( array() );
$ffc_email = ProfileCompletion::masked_email( $user );
?>
<div class="ffc-shortcode ffc-profile-completion">
	<?php if ( array() === $missing ) : ?>
		<div class="ffc-notice ffc-notice-success" role="status"><p><?php esc_html_e( 'Your details are complete.', 'ffcertificate' ); ?></p></div>
	<?php else : ?>
		<p><?php esc_html_e( 'These details identify you on your certificates and records. You fill them in once.', 'ffcertificate' ); ?></p>
		<?php if ( is_array( $errors ) && array() !== $errors ) : ?>
			<div class="ffc-notice ffc-notice-error" role="alert">
				<?php foreach ( $errors as $ffc_error ) : ?>
					<p><?php echo esc_html( (string) $ffc_error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<form class="ffc-profile-completion-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php
			foreach ( $shown as $ffc_field ) {
				echo $ffc_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by the reregistration form's renderer.
			}
			?>
			<?php if ( in_array( ProfileCompletion::MISSING_IDENTITY, $missing, true ) ) : ?>
				<p class="ffc-profile-completion-hint"><?php esc_html_e( 'Fill in your CPF, your RF or both. Once saved, only an administrator can change them.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $ffc_email ) : ?>
				<p class="ffc-profile-completion-email">
					<span><?php esc_html_e( 'E-mail', 'ffcertificate' ); ?></span>
					<strong><?php echo esc_html( $ffc_email ); ?></strong>
					<span><?php esc_html_e( 'The e-mail of your account. To change it, ask an administrator.', 'ffcertificate' ); ?></span>
				</p>
			<?php endif; ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( ProfileCompletion::ACTION ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( (string) $ffc_back ); ?>">
			<?php wp_nonce_field( ProfileCompletion::ACTION ); ?>
			<p><?php echo ProfileCompletion::submit_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Button::html() escapes every value. ?></p>
		</form>
	<?php endif; ?>
</div>
