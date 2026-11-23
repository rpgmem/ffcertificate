<?php
/**
 * Template: Scheduling Settings — Check-in tab (#2747).
 * In-scope: $can_edit, $terminal_camera.
 *
 * Each field saves on its own through the settings autosave
 * (`data-ffc-autosave-key`), so the tab has no form and no Save button.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card">
	<h2 class="ffc-icon-qr"><?php esc_html_e( 'Check-in', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'How attendance is taken at audience calendar events: the QR code an organiser projects, and the terminals at the entrance that read each participant\'s own code.', 'ffcertificate' ); ?></p>
</div>

<div class="card">
	<h2 class="ffc-icon-monitor"><?php esc_html_e( 'Check-in terminals', 'ffcertificate' ); ?></h2>
	<?php
	\FreeFormCertificate\Admin\AdminUI::render_toggle(
		array(
			'name'        => 'terminal_camera',
			'checked'     => ! empty( $terminal_camera ),
			'disabled'    => empty( $can_edit ),
			'label'       => __( 'Allow the device camera', 'ffcertificate' ),
			'description' => __( 'A terminal may read presence codes with its own camera, where the browser can read QR codes by itself and the site is served over HTTPS. Elsewhere the option shows as unavailable, with its reason. A reader connected to the device stays the recommended way.', 'ffcertificate' ),
			'data'        => array( 'ffc-autosave-key' => 'terminal_camera' ),
		)
	);
	?>
</div>
