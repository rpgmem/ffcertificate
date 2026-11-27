<?php
/**
 * The app's Certificates screen completing one certificate (#2936).
 *
 * A terminal reading recorded attendance and left the certificate waiting on
 * profile data. The form opens here, filled from the profile, so a form used
 * only at the terminal, with no page of its own, can still be finished.
 *
 * @package FreeFormCertificate\Shortcodes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

/**
 * From the screen class.
 *
 * @var string $ffc_back  The list's address.
 * @var string $ffc_error Why the form does not open; '' when it does.
 * @var string $ffc_title The form's title.
 * @var string $ffc_form  The rendered form; '' with an error.
 * @var string $ffc_code  The certificate's code; '' when the reading reserved none.
 */
$ffc_back  = isset( $ffc_back ) ? (string) $ffc_back : '';
$ffc_error = isset( $ffc_error ) ? (string) $ffc_error : '';
$ffc_title = isset( $ffc_title ) ? (string) $ffc_title : '';
$ffc_form  = isset( $ffc_form ) ? (string) $ffc_form : '';
$ffc_code  = isset( $ffc_code ) ? (string) $ffc_code : '';
?>
<div class="ffc-shortcode ffc-app-certificates ffc-app-certificates-completing">
	<?php
	Button::render(
		array(
			'label'    => __( 'Back to certificates', 'ffcertificate' ),
			'category' => 'neutral',
			'icon'     => 'chevron-left',
			'href'     => $ffc_back,
		)
	);
	?>
	<h2 class="ffc-app-certificates-completing-title ffc-icon-edit"><?php echo esc_html( $ffc_title ); ?></h2>
	<?php if ( '' !== $ffc_error ) : ?>
		<?php echo Notice::render( 'warning', 'warning', $ffc_error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	<?php else : ?>
		<p class="ffc-app-certificates-complete-meta"><?php esc_html_e( 'Your attendance is recorded. Fill in what is missing and send the form to receive the certificate.', 'ffcertificate' ); ?></p>
		<p class="ffc-app-certificates-complete-meta ffc-app-certificates-complete-state">
			<span class="ffc-app-certificate-status ffc-app-certificate-status-incomplete"><?php esc_html_e( 'Incomplete', 'ffcertificate' ); ?></span>
			<?php if ( '' !== $ffc_code ) : ?>
				<?php
				/* translators: %s: the certificate's authentication code */
				echo esc_html( sprintf( __( 'Code %s', 'ffcertificate' ), $ffc_code ) );
				?>
			<?php endif; ?>
		</p>
		<?php echo $ffc_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the [ffc_form] shortcode escapes its own markup. ?>
	<?php endif; ?>
</div>
