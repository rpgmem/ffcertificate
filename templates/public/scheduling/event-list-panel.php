<?php
/**
 * Template: the list panel beside or below a calendar's month (#2405),
 * shared by the audience calendar (its events) and the personal calendar
 * (its free slots). The script of each calendar fills the content.
 *
 * In-scope: $ffc_panel_heading (string, the panel's heading).
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-event-list-panel" id="ffc-event-list-panel">
	<div class="ffc-event-list-header">
		<h3><?php echo esc_html( $ffc_panel_heading ); ?></h3>
	</div>
	<div class="ffc-event-list-content" id="ffc-event-list-content">
		<div class="ffc-loading"><?php esc_html_e( 'Loading...', 'ffcertificate' ); ?></div>
	</div>
</div>
