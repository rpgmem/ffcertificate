<?php
/**
 * Modules Settings Tab View
 *
 * Placeholder home for the per-module enable/disable controls. The toggle
 * controls land in a follow-up phase; this scaffold establishes the tab and
 * its module-agnostic home now that Settings is a dedicated top-level menu.
 *
 * @package FFC
 * @since 6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-package"><?php esc_html_e( 'Modules', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Enable or disable individual plugin modules. Per-module toggle controls will appear here.', 'ffcertificate' ); ?>
	</p>
</div>

</div>
