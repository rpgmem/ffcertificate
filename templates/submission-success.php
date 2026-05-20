<?php
/**
 * Template: Submission Success Message
 *
 * Variables available:
 *
 * @var string $success_message Success message text
 * @var string $form_title Form title
 * @var string $date_formatted Formatted submission date
 * @var string $auth_code Authentication code (optional)
 * @var string $magic_link Magic-link URL for re-issuing the certificate later (optional)
 *
 * @package FreeFormCertificate
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_magic_link = isset( $magic_link ) ? (string) $magic_link : '';
$ffc_auth_code  = isset( $auth_code ) ? (string) $auth_code : '';
?>

<div class="ffc-success-response" role="status" aria-live="polite">
	<div class="ffc-success-icon" aria-hidden="true">✓</div>
	<h3><?php echo esc_html( $success_message ); ?></h3>
	<div class="ffc-success-details">
		<p>
			<strong><?php esc_html_e( 'Form:', 'ffcertificate' ); ?></strong>
			<?php echo esc_html( $form_title ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Date:', 'ffcertificate' ); ?></strong>
			<?php echo esc_html( $date_formatted ); ?>
		</p>
		<?php if ( ! empty( $ffc_auth_code ) ) : ?>
			<p class="ffc-success-auth-code">
				<strong><?php esc_html_e( 'Authentication Code:', 'ffcertificate' ); ?></strong>
				<code><?php echo esc_html( $ffc_auth_code ); ?></code>
				<button type="button"
					class="ffc-copy-btn"
					data-ffc-copy="<?php echo esc_attr( $ffc_auth_code ); ?>"
					aria-label="<?php esc_attr_e( 'Copy authentication code', 'ffcertificate' ); ?>">
					<?php esc_html_e( 'Copy', 'ffcertificate' ); ?>
				</button>
			</p>
			<p class="ffc-success-auth-hint">
				<?php esc_html_e( 'Save this code — it lets you re-issue your certificate anytime.', 'ffcertificate' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $ffc_magic_link ) ) : ?>
			<p class="ffc-success-magic-link">
				<strong><?php esc_html_e( 'Save this link to download again later:', 'ffcertificate' ); ?></strong>
				<a href="<?php echo esc_url( $ffc_magic_link ); ?>" target="_blank" rel="noopener" class="ffc-magic-link-url">
					<?php echo esc_html( $ffc_magic_link ); ?>
				</a>
				<button type="button"
					class="ffc-copy-btn"
					data-ffc-copy="<?php echo esc_attr( $ffc_magic_link ); ?>"
					aria-label="<?php esc_attr_e( 'Copy link', 'ffcertificate' ); ?>">
					<?php esc_html_e( 'Copy', 'ffcertificate' ); ?>
				</button>
			</p>
		<?php endif; ?>
	</div>

	<div class="ffc-success-actions">
		<button type="button" class="ffc-download-pdf-btn ffc-success-download-btn">
			<?php esc_html_e( 'Download PDF again', 'ffcertificate' ); ?>
		</button>
	</div>

	<div class="ffc-success-where-is-file" aria-live="polite">
		<p class="ffc-success-where-title">
			<strong><?php esc_html_e( 'Where to find your certificate:', 'ffcertificate' ); ?></strong>
		</p>
		<ul>
			<li data-platform="android">
				<?php
				echo esc_html__(
					'Android: open the Downloads folder, or use the Files app and look for "certificate.pdf".',
					'ffcertificate'
				);
				?>
			</li>
			<li data-platform="ios">
				<?php
				echo esc_html__(
					'iPhone / iPad: after the PDF opens, tap Share → "Save to Files" to keep it on your device.',
					'ffcertificate'
				);
				?>
			</li>
			<li data-platform="desktop">
				<?php
				echo esc_html__(
					'Desktop: check the Downloads folder or the download bar at the bottom of your browser.',
					'ffcertificate'
				);
				?>
			</li>
		</ul>
	</div>
</div>
