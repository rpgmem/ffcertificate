<?php
/**
 * Template: Scheduling Settings — Check-in tab (#2747, #2748).
 * In-scope: $can_edit, $terminal_camera, $site_limit, $event_limit,
 * $auto_end_hours, $path_qr, $path_terminal.
 *
 * Each field saves on its own through the settings autosave
 * (`data-ffc-autosave-key`), so the tab has no form and no Save button.
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Audience\AudienceCheckinSettings;

$ffc_disabled = empty( $can_edit ) ? ' disabled' : '';
?>
<div class="card">
	<h2 class="ffc-icon-qr"><?php esc_html_e( 'Check-in', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'How attendance is taken at audience calendar events: the QR code an organiser projects, and the terminals at the entrance that read each participant\'s own code.', 'ffcertificate' ); ?></p>
</div>

<div class="card">
	<h2 class="ffc-icon-split"><?php esc_html_e( 'Check-in paths for new events', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'The ways a new event takes check-in. Each event keeps its own switches afterwards, on its Check-in tab; changing these does not change events that already exist.', 'ffcertificate' ); ?></p>
	<?php
	\FreeFormCertificate\Admin\AdminUI::render_toggle(
		array(
			'name'        => 'checkin_path_qr',
			'checked'     => ! empty( $path_qr ),
			'disabled'    => empty( $can_edit ),
			'label'       => __( 'Projected QR code', 'ffcertificate' ),
			'description' => __( 'An organiser opens check-in and projects a code that participants scan with their own phone.', 'ffcertificate' ),
			'data'        => array( 'ffc-autosave-key' => 'checkin_path_qr' ),
		)
	);
	\FreeFormCertificate\Admin\AdminUI::render_toggle(
		array(
			'name'        => 'checkin_path_terminal',
			'checked'     => ! empty( $path_terminal ),
			'disabled'    => empty( $can_edit ),
			'label'       => __( 'Terminal', 'ffcertificate' ),
			'description' => __( 'A paired terminal at the entrance reads each participant\'s presence code.', 'ffcertificate' ),
			'data'        => array( 'ffc-autosave-key' => 'checkin_path_terminal' ),
		)
	);
	?>
</div>

<div class="card">
	<h2 class="ffc-icon-monitor"><?php esc_html_e( 'Check-in terminals', 'ffcertificate' ); ?></h2>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="ffc-terminal-site-limit"><?php esc_html_e( 'Terminals on the site', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="ffc-terminal-site-limit" name="terminal_site_limit" class="small-text" required
						min="1" max="<?php echo esc_attr( (string) AudienceCheckinSettings::SITE_LIMIT_MAX ); ?>"
						value="<?php echo esc_attr( (string) $site_limit ); ?>"
						data-ffc-autosave-key="terminal_site_limit"<?php echo esc_attr( $ffc_disabled ); ?>>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: highest accepted value, 2: default value */
								__( 'How many terminals may be active at once, from 1 to %1$d (default %2$d). Pairing one more is refused until a terminal is ended.', 'ffcertificate' ),
								AudienceCheckinSettings::SITE_LIMIT_MAX,
								AudienceCheckinSettings::SITE_LIMIT
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ffc-terminal-event-limit"><?php esc_html_e( 'Terminals per event', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="ffc-terminal-event-limit" name="terminal_event_limit" class="small-text" required
						min="1" max="<?php echo esc_attr( (string) AudienceCheckinSettings::EVENT_LIMIT_MAX ); ?>"
						value="<?php echo esc_attr( (string) $event_limit ); ?>"
						data-ffc-autosave-key="terminal_event_limit"<?php echo esc_attr( $ffc_disabled ); ?>>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: highest accepted value, 2: default value */
								__( 'How many terminals may check in one event at once, from 1 to %1$d (default %2$d).', 'ffcertificate' ),
								AudienceCheckinSettings::EVENT_LIMIT_MAX,
								AudienceCheckinSettings::EVENT_LIMIT
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ffc-terminal-auto-end"><?php esc_html_e( 'Ends on its own after', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="ffc-terminal-auto-end" name="terminal_auto_end_hours" class="small-text" required
						min="1" max="<?php echo esc_attr( (string) AudienceCheckinSettings::AUTO_END_HOURS_MAX ); ?>"
						value="<?php echo esc_attr( (string) $auto_end_hours ); ?>"
						data-ffc-autosave-key="terminal_auto_end_hours"<?php echo esc_attr( $ffc_disabled ); ?>>
					<?php esc_html_e( 'hours', 'ffcertificate' ); ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: highest accepted value, 2: default value */
								__( 'A terminal stops this many hours after the last of its events ends, from 1 to %1$d (default %2$d). It applies to terminals paired or changed from now on.', 'ffcertificate' ),
								AudienceCheckinSettings::AUTO_END_HOURS_MAX,
								AudienceCheckinSettings::AUTO_END_HOURS
							)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Camera', 'ffcertificate' ); ?></th>
				<td>
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
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div class="card">
	<h2 class="ffc-icon-shield"><?php esc_html_e( 'Rules that always apply', 'ffcertificate' ); ?></h2>
	<p class="description"><?php esc_html_e( 'These are fixed, to keep pairing and presence codes hard to abuse.', 'ffcertificate' ); ?></p>
	<ul class="ffc-checkin-rules">
		<li><?php esc_html_e( 'Pairing: a code lives five minutes and pairs one terminal once. Three wrong codes in a row lock that terminal for 15 minutes, and each further one for 30; one network address may send at most 20 wrong codes an hour.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'A terminal holds at most 10 events, each ending within the next 7 days, and no two whose check-in windows cross.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Presence devices: one active phone per person; a phone stays reserved to its account for 30 days; at most 3 phone changes per person in 30 days. More needs an administrator, under Scheduling → Presence devices.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'A presence code changes every 30 seconds and carries only the person\'s short name, never a CPF or RF.', 'ffcertificate' ); ?></li>
	</ul>
</div>
