<?php
/**
 * The app's Calendar screen (#2781).
 *
 * The markup only: `assets/js/ffc-app-agenda.js` fills the day cards and
 * the Book view from the dashboard's routes.
 *
 * @package FreeFormCertificate\Shortcodes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Core\Notice;
?>
<div class="ffc-shortcode ffc-app-agenda" data-ffc-app-agenda>
	<noscript>
		<?php echo Notice::render( 'warning', 'warning', __( 'This screen needs JavaScript. Turn it on in the browser and open it again.', 'ffcertificate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice::render() escapes its own output. ?>
	</noscript>
	<div class="ffc-app-agenda-views" role="tablist" aria-label="<?php esc_attr_e( 'Calendar', 'ffcertificate' ); ?>">
		<button type="button" class="ffc-app-agenda-view" role="tab" aria-selected="true" data-ffc-app-view="mine"><?php esc_html_e( 'My bookings', 'ffcertificate' ); ?></button>
		<button type="button" class="ffc-app-agenda-view" role="tab" aria-selected="false" data-ffc-app-view="book"><?php esc_html_e( 'Book', 'ffcertificate' ); ?></button>
	</div>
	<p class="ffc-app-agenda-status" role="status" aria-live="polite" data-ffc-app-part="status"></p>
	<div data-ffc-app-panel="mine">
		<h2 class="ffc-app-agenda-month" data-ffc-app-part="month"></h2>
		<details class="ffc-app-agenda-past" data-ffc-app-part="past" hidden>
			<summary data-ffc-app-part="past-label"></summary>
			<ol class="ffc-app-agenda-list" data-ffc-app-part="past-list"></ol>
		</details>
		<ol class="ffc-app-agenda-list" data-ffc-app-part="list"></ol>
		<button type="button" class="ffc-app-agenda-new ffc-icon-plus" data-ffc-app-part="new"><?php esc_html_e( 'New booking', 'ffcertificate' ); ?></button>
	</div>
	<div data-ffc-app-panel="book" hidden>
		<ul class="ffc-app-agenda-book" data-ffc-app-part="book"></ul>
	</div>
</div>
