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
 * @var string $pdf_filename Name the PDF is saved under (optional)
 *
 * @package FreeFormCertificate
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_magic_link = isset( $magic_link ) ? (string) $magic_link : '';
$ffc_auth_code  = isset( $auth_code ) ? (string) $auth_code : '';
$ffc_filename   = isset( $pdf_filename ) ? (string) $pdf_filename : '';
?>

<div class="ffc-certificate-preview ffc-success-response" role="status" aria-live="polite">
	<div class="ffc-preview-header">
		<span class="ffc-status-badge ffc-status-badge-success ffc-icon-success">
			<?php esc_html_e( 'Certificate Issued', 'ffcertificate' ); ?>
		</span>
	</div>

	<div class="ffc-preview-body">
		<h3><?php esc_html_e( 'Certificate Details', 'ffcertificate' ); ?></h3>

		<?php if ( ! empty( $ffc_auth_code ) ) : ?>
			<div class="ffc-detail-row ffc-success-auth-code">
				<span class="ffc-detail-label"><?php esc_html_e( 'Authentication Code:', 'ffcertificate' ); ?></span>
				<span class="ffc-detail-value ffc-detail-value-code">
					<code class="ffc-success-code"><?php echo esc_html( $ffc_auth_code ); ?></code>
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'    => __( 'Copy', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'copy',
							'class'    => 'ffc-copy-btn',
							'attrs'    => array(
								'data-ffc-copy' => $ffc_auth_code,
								'aria-label'    => __( 'Copy authentication code', 'ffcertificate' ),
							),
						)
					);
					?>
				</span>
			</div>
			<p class="ffc-success-row-hint">
				<?php esc_html_e( 'Save this code — it lets you re-issue your certificate anytime.', 'ffcertificate' ); ?>
			</p>
		<?php endif; ?>

		<div class="ffc-detail-row">
			<span class="ffc-detail-label"><?php esc_html_e( 'Form:', 'ffcertificate' ); ?></span>
			<span class="ffc-detail-value"><?php echo esc_html( $form_title ); ?></span>
		</div>

		<div class="ffc-detail-row">
			<span class="ffc-detail-label"><?php esc_html_e( 'Date:', 'ffcertificate' ); ?></span>
			<span class="ffc-detail-value"><?php echo esc_html( $date_formatted ); ?></span>
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
				<?php
				\FreeFormCertificate\Core\Button::render(
					array(
						'label'    => __( 'Copy', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'copy',
						'class'    => 'ffc-copy-btn',
						'attrs'    => array(
							'data-ffc-copy' => $ffc_magic_link,
							'aria-label'    => __( 'Copy link', 'ffcertificate' ),
						),
					)
				);
				?>
			</div>
		<?php endif; ?>
	</div>

	<div class="ffc-preview-actions ffc-success-actions">
		<?php
		// `ffc-download-btn`, `ffc-download-pdf-btn` and `ffc-success-download-btn`
		// are the hooks scripts and an install's own CSS read (#1880).
		\FreeFormCertificate\Core\Button::render(
			array(
				'label'    => __( 'Download PDF again', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'download',
				'class'    => 'ffc-download-btn ffc-download-pdf-btn ffc-success-download-btn',
			)
		);
		?>

		<details class="ffc-success-where-is-file" aria-live="polite">
			<summary class="ffc-success-where-title">
				<?php esc_html_e( "Can't find the downloaded PDF?", 'ffcertificate' ); ?>
			</summary>
			<ul>
				<li data-platform="android">
					<?php
					if ( '' !== $ffc_filename ) {
						echo esc_html(
							sprintf(
								/* translators: %s: the name the certificate PDF was saved under. */
								__( 'Android: open the Downloads folder, or use the Files app and look for "%s".', 'ffcertificate' ),
								$ffc_filename
							)
						);
					} else {
						esc_html_e( 'Android: open the Downloads folder, or use the Files app.', 'ffcertificate' );
					}
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
