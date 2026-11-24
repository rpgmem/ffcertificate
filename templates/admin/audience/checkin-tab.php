<?php
/**
 * Template: Scheduling Settings — Check-in tab (#2747, #2748, #2782).
 * In-scope: $can_edit, $terminal_camera, $site_limit, $event_limit,
 * $auto_end_hours, $path_qr, $path_terminal, $active_count, $presence_url.
 *
 * Five sections and one Save button, as in the approved screen (#2782); the
 * screen departs from the settings autosave on purpose, and CLAUDE.md
 * "Admin layout" records it.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Audience\AudienceCheckinSettings;

$ffc_disabled = empty( $can_edit ) ? ' disabled' : '';
?>
<form method="post" class="ffc-checkin-settings">
	<?php wp_nonce_field( 'ffc_checkin_settings', 'ffc_checkin_settings_nonce' ); ?>
	<input type="hidden" name="ffc_action" value="save_checkin_settings">

	<div class="card">
		<h2 class="ffc-icon-split"><?php esc_html_e( 'Check-in paths for new events', 'ffcertificate' ); ?></h2>
		<?php
		AdminUI::render_toggle(
			array(
				'name'        => 'checkin_path_qr',
				'checked'     => ! empty( $path_qr ),
				'disabled'    => empty( $can_edit ),
				'label'       => __( 'QR code projected by the organiser', 'ffcertificate' ),
				'description' => __( 'The participant scans it with their phone. It needs no equipment.', 'ffcertificate' ),
			)
		);
		AdminUI::render_toggle(
			array(
				'name'        => 'checkin_path_terminal',
				'checked'     => ! empty( $path_terminal ),
				'disabled'    => empty( $can_edit ),
				'label'       => __( 'Self-service terminal', 'ffcertificate' ),
				'description' => __( 'The participant shows the app\'s QR code. It only works while a terminal is paired.', 'ffcertificate' ),
			)
		);
		?>
		<p class="description"><?php esc_html_e( 'Each event can change this on its own screen. Someone who checks in by both paths counts once.', 'ffcertificate' ); ?></p>
	</div>

	<div class="card">
		<h2 class="ffc-icon-monitor"><?php esc_html_e( 'Terminals', 'ffcertificate' ); ?></h2>
		<div class="ffc-checkin-fields">
			<p class="ffc-checkin-field">
				<label for="ffc-terminal-site-limit"><?php esc_html_e( 'Active terminals on the site', 'ffcertificate' ); ?></label>
				<input type="number" id="ffc-terminal-site-limit" name="terminal_site_limit" class="small-text" required
					min="1" max="<?php echo esc_attr( (string) AudienceCheckinSettings::SITE_LIMIT_MAX ); ?>"
					value="<?php echo esc_attr( (string) $site_limit ); ?>"<?php echo esc_attr( $ffc_disabled ); ?>>
				<span class="description">
					<?php
					/* translators: 1: active terminals now, 2: the site's limit */
					echo esc_html( sprintf( __( 'Today: %1$d of %2$d.', 'ffcertificate' ), (int) $active_count, (int) $site_limit ) );
					?>
				</span>
			</p>
			<p class="ffc-checkin-field">
				<label for="ffc-terminal-event-limit"><?php esc_html_e( 'Per event or fixed place', 'ffcertificate' ); ?></label>
				<input type="number" id="ffc-terminal-event-limit" name="terminal_event_limit" class="small-text" required
					min="1" max="<?php echo esc_attr( (string) AudienceCheckinSettings::EVENT_LIMIT_MAX ); ?>"
					value="<?php echo esc_attr( (string) $event_limit ); ?>"<?php echo esc_attr( $ffc_disabled ); ?>>
			</p>
			<p class="ffc-checkin-field">
				<label for="ffc-terminal-auto-end"><?php esc_html_e( 'End by itself after the end (default)', 'ffcertificate' ); ?></label>
				<select id="ffc-terminal-auto-end" name="terminal_auto_end_hours"<?php echo esc_attr( $ffc_disabled ); ?>>
					<?php foreach ( AudienceCheckinSettings::auto_end_choices( (int) $auto_end_hours ) as $ffc_hours => $ffc_label ) : ?>
						<option value="<?php echo esc_attr( (string) $ffc_hours ); ?>"<?php selected( (int) $ffc_hours, (int) $auto_end_hours ); ?>><?php echo esc_html( $ffc_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<p class="description"><?php esc_html_e( 'Only active terminals count. A terminal downloads no list of participants: each QR code carries its own proof, signed by the site. It keeps only the readings of the people who passed it, until it syncs.', 'ffcertificate' ); ?></p>
	</div>

	<div class="card">
		<h2 class="ffc-icon-qr"><?php esc_html_e( 'QR reading', 'ffcertificate' ); ?></h2>
		<?php
		AdminUI::render_toggle(
			array(
				'name'        => 'terminal_camera',
				'checked'     => ! empty( $terminal_camera ),
				'disabled'    => empty( $can_edit ),
				'label'       => __( 'Allow the device camera', 'ffcertificate' ),
				'description' => __( 'For tablets without a USB reader. It needs HTTPS and the camera permission on the device.', 'ffcertificate' ),
			)
		);
		?>
	</div>

	<div class="card">
		<h2 class="ffc-icon-lock"><?php esc_html_e( 'Pairing lock', 'ffcertificate' ); ?></h2>
		<ul class="ffc-checkin-rules">
			<li><?php esc_html_e( '3 wrong codes in a row on the same device: a 15-minute wait.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Each further wrong code after that: a 30-minute wait.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A correct pairing, or an hour without attempts, resets the count.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Per network address: at most 20 wrong codes an hour, across every device.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="card">
		<h2 class="ffc-icon-shield"><?php esc_html_e( 'Presence devices', 'ffcertificate' ); ?></h2>
		<ul class="ffc-checkin-rules">
			<li><?php esc_html_e( 'One active device per user: activating a new one deactivates the previous one, with an e-mail.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'A device stays reserved to the same account for 30 days.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'At most 3 device changes per user every 30 days.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The QR code works without internet for 7 days; after that the app needs to connect.', 'ffcertificate' ); ?></li>
		</ul>
		<p><a href="<?php echo esc_url( (string) $presence_url ); ?>"><?php esc_html_e( 'See devices and releases', 'ffcertificate' ); ?></a></p>
	</div>

	<?php if ( ! empty( $can_edit ) ) : ?>
		<?php AdminUI::render_save_button( __( 'Save changes', 'ffcertificate' ) ); ?>
	<?php endif; ?>
</form>
