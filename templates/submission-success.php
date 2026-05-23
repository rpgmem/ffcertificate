<?php
/**
 * Template: Submission Success Message
 *
 * 6.6.12 — Redesigned to match the `/valid` certificate preview visual
 * language (header badge + body card with `.ffc-detail-row` label/value
 * pairs + bottom action bar). Classes from the pre-6.6.12 version
 * (`.ffc-success-*`, `data-platform`) are kept as secondary class names
 * for JS selectors (`assets/js/ffc-frontend.js::filterPlatformGuidance`)
 * and PHPUnit class-presence assertions (`SuccessHtmlTest`) — they
 * carry no styling weight of their own anymore.
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

<div class="ffc-certificate-preview ffc-success-response" role="status" aria-live="polite">
	<div class="ffc-preview-header">
		<span class="ffc-status-badge success ffc-icon-success">
			<?php esc_html_e( 'Certificate Issued', 'ffcertificate' ); ?>
		</span>
	</div>

	<div class="ffc-preview-body">
		<h3><?php esc_html_e( 'Certificate Details', 'ffcertificate' ); ?></h3>

		<?php if ( ! empty( $ffc_auth_code ) ) : ?>
			<div class="ffc-detail-row ffc-success-auth-code">
				<span class="label"><?php esc_html_e( 'Authentication Code:', 'ffcertificate' ); ?></span>
				<span class="value code">
					<code class="ffc-success-code"><?php echo esc_html( $ffc_auth_code ); ?></code>
					<button type="button"
						class="ffc-copy-btn"
						data-ffc-copy="<?php echo esc_attr( $ffc_auth_code ); ?>"
						aria-label="<?php esc_attr_e( 'Copy authentication code', 'ffcertificate' ); ?>">
						<?php esc_html_e( 'Copy', 'ffcertificate' ); ?>
					</button>
				</span>
			</div>
			<p class="ffc-success-row-hint">
				<?php esc_html_e( 'Save this code — it lets you re-issue your certificate anytime.', 'ffcertificate' ); ?>
			</p>
		<?php endif; ?>

		<div class="ffc-detail-row">
			<span class="label"><?php esc_html_e( 'Form:', 'ffcertificate' ); ?></span>
			<span class="value"><?php echo esc_html( $form_title ); ?></span>
		</div>

		<div class="ffc-detail-row">
			<span class="label"><?php esc_html_e( 'Date:', 'ffcertificate' ); ?></span>
			<span class="value"><?php echo esc_html( $date_formatted ); ?></span>
		</div>

		<?php if ( ! empty( $ffc_magic_link ) ) : ?>
			<h4><?php esc_html_e( 'Save this link to download again later:', 'ffcertificate' ); ?></h4>
			<div class="ffc-detail-row ffc-success-magic-link ffc-success-magic-link-row">
				<a href="<?php echo esc_url( $ffc_magic_link ); ?>"
					target="_blank"
					rel="noopener"
					class="ffc-magic-link-url">
					<?php echo esc_html( $ffc_magic_link ); ?>
				</a>
				<button type="button"
					class="ffc-copy-btn"
					data-ffc-copy="<?php echo esc_attr( $ffc_magic_link ); ?>"
					aria-label="<?php esc_attr_e( 'Copy link', 'ffcertificate' ); ?>">
					<?php esc_html_e( 'Copy', 'ffcertificate' ); ?>
				</button>
			</div>
		<?php endif; ?>
	</div>

	<div class="ffc-preview-actions ffc-success-actions">
		<button type="button" class="ffc-download-btn ffc-download-pdf-btn ffc-success-download-btn ffc-icon-download">
			<?php esc_html_e( 'Download PDF again', 'ffcertificate' ); ?>
		</button>

		<details class="ffc-success-where-is-file" aria-live="polite">
			<summary class="ffc-success-where-title">
				<?php esc_html_e( "Can't find the downloaded PDF?", 'ffcertificate' ); ?>
			</summary>
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
		</details>
	</div>
</div>
