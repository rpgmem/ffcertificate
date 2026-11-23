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

use FreeFormCertificate\Core\Notice;
?>
<div class="ffc-shortcode ffc-app-certificates" data-ffc-app-certificates>
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>
	<input type="search" class="ffc-app-certificates-search" data-ffc-app-part="search" aria-label="<?php esc_attr_e( 'Search by course or code', 'ffcertificate' ); ?>" placeholder="<?php esc_attr_e( 'Search by course or code', 'ffcertificate' ); ?>" autocomplete="off">
	<p class="ffc-app-certificates-status" role="status" aria-live="polite" data-ffc-app-part="status"></p>
	<div data-ffc-app-part="featured"></div>
	<ul class="ffc-app-certificates-list" data-ffc-app-part="list"></ul>
</div>
