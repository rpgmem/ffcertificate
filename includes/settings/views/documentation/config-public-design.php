<?php
/**
 * Documentation partial — Settings → Public Design.
 *
 * What the public design changes, where it applies, and how it lives with
 * dark mode and page caching.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Public Design Section -->
<div class="card">
	<h3 id="config-public-design" class="ffc-icon-palette"><?php esc_html_e( 'Public Design', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Settings → Public Design sets the look of every screen visitors see: forms, certificate verification, the CSV download, geofenced content, scheduling, audiences, the user dashboard and recruitment. It is off by default, and the screens keep the plugin\'s own look until it is switched on.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Colours', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The accent of buttons, links and the focus outline, the field borders, hints and secondary text, and errors. The text on buttons is chosen automatically, black or white, whichever reads best on the accent. A colour that reads poorly on white (below 4.5:1 for text, 3:1 for borders, as WCAG asks) is still saved, with a warning naming it.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The colours apply to the light theme only. With Dark Mode on, the plugin keeps its own dark palette, because a colour chosen for a white page is often unreadable on a dark one.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Shape and Font', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The corner radius of fields and buttons (0 makes them square), the widest a form or verification card grows (its fields are 150 px narrower), and either the site theme\'s font or the system font of the admin screens. These apply in both themes.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Cached pages', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The design is part of the page, so a page cache keeps the old look until it is purged. After changing it, purge the cache of the pages with forms.', 'ffcertificate' ); ?></p>
</div>
