<?php
/**
 * The app's Certificates screen (#2781).
 *
 * The markup only: `assets/js/ffc-app-certificates.js` fills the featured
 * card and the list from `user/certificates`.
 *
 * @package FreeFormCertificate\Shortcodes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\Notice;

/**
 * Certificates waiting on profile data, from the screen class.
 *
 * @var list<array{form_id: int, title: string, missing: list<string>, url: string, until: string, code?: string}> $to_complete
 */
$to_complete = isset( $to_complete ) && is_array( $to_complete ) ? $to_complete : array();
?>
<div class="ffc-shortcode ffc-app-certificates" data-ffc-app-certificates>
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>
	<?php if ( array() !== $to_complete ) : ?>
		<section class="ffc-app-certificates-complete" aria-labelledby="ffc-app-certificates-complete-title">
			<h2 id="ffc-app-certificates-complete-title" class="ffc-icon-edit"><?php esc_html_e( 'To complete', 'ffcertificate' ); ?></h2>
			<ul class="ffc-app-certificates-complete-list">
				<?php foreach ( $to_complete as $ffc_item ) : ?>
					<li class="ffc-app-certificates-complete-item">
						<p class="ffc-app-certificates-complete-name"><?php echo esc_html( $ffc_item['title'] ); ?></p>
						<?php if ( array() !== $ffc_item['missing'] ) : ?>
							<p class="ffc-app-certificates-complete-meta">
								<?php
								/* translators: %s: the fields still to fill, separated by commas */
								echo esc_html( sprintf( __( 'Missing: %s', 'ffcertificate' ), implode( ', ', $ffc_item['missing'] ) ) );
								?>
							</p>
						<?php endif; ?>
						<p class="ffc-app-certificates-complete-meta ffc-app-certificates-complete-state">
							<span class="ffc-app-certificate-status ffc-app-certificate-status-incomplete"><?php esc_html_e( 'Incomplete', 'ffcertificate' ); ?></span>
							<?php if ( '' !== ( $ffc_item['code'] ?? '' ) ) : ?>
								<?php
								/* translators: %s: the certificate's authentication code */
								echo esc_html( sprintf( __( 'Code %s', 'ffcertificate' ), $ffc_item['code'] ) );
								?>
							<?php endif; ?>
						</p>
						<p class="ffc-app-certificates-complete-meta">
							<?php
							/* translators: %s: the date the completion link closes */
							echo esc_html( sprintf( __( 'Attendance recorded. Complete it until %s to receive the certificate.', 'ffcertificate' ), $ffc_item['until'] ) );
							?>
						</p>
						<?php
						Button::render(
							array(
								'label'    => __( 'Fill in the missing data', 'ffcertificate' ),
								'category' => 'primary',
								'icon'     => 'edit',
								'href'     => $ffc_item['url'],
							)
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
	<input type="search" class="ffc-app-certificates-search" data-ffc-app-part="search" aria-label="<?php esc_attr_e( 'Search by course or code', 'ffcertificate' ); ?>" placeholder="<?php esc_attr_e( 'Search by course or code', 'ffcertificate' ); ?>" autocomplete="off">
	<p class="ffc-app-certificates-status" role="status" aria-live="polite" data-ffc-app-part="status"></p>
	<div data-ffc-app-part="featured"></div>
	<ul class="ffc-app-certificates-list" data-ffc-app-part="list"></ul>
</div>
