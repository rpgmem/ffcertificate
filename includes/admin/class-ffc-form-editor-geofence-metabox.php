<?php
/**
 * Form Editor Geofence Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\GeofenceLocationRegistry;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Geofence Metabox Renderer.
 *
 * Since the form-editor tabs refactor the two concerns this metabox used to
 * stack behind an inner button-bar — *when* (date/time) and *where*
 * (geolocation) — are rendered as two separate top-level tabs via
 * {@see render_time()} and {@see render_geolocation()}. Both write into the
 * same `ffc_geofence` POST namespace / `_ffc_geofence_config` meta, so the
 * save path is unchanged. {@see render()} keeps the old stacked behaviour for
 * any caller still using the single entry point.
 *
 * @since 3.1.1
 */
class FormEditorGeofenceMetabox {

	/**
	 * Render both sections stacked (back-compat single-metabox entry point).
	 *
	 * The live path is the tabbed container, which calls {@see render_time()}
	 * and {@see render_geolocation()} as separate panels.
	 *
	 * @since 3.0.0
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$this->render_time( $post );
		$this->render_geolocation( $post );
	}

	/**
	 * Read the persisted geofence config blob.
	 *
	 * @param WP_Post $post The post object.
	 * @return array<string, mixed>
	 */
	private function get_config( WP_Post $post ): array {
		$config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Render the date/time-restriction section ("Time" tab) plus the
	 * per-participant schedule-exception sub-section.
	 *
	 * Carries the `ffc_geofence[_save]` sentinel so the namespace is always
	 * in POST even when every field is left disabled.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_time( WP_Post $post ): void {
		$config = $this->get_config( $post );

		$datetime_enabled          = ( $config['datetime_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$date_start                = $config['date_start'] ?? '';
		$date_end                  = $config['date_end'] ?? '';
		$time_start                = $config['time_start'] ?? '';
		$time_end                  = $config['time_end'] ?? '';
		$time_mode                 = $config['time_mode'] ?? 'daily'; // 'daily' or 'span'
		$datetime_hide_mode_before = Geofence::resolve_hide_mode( $config, 'before' );
		$datetime_hide_mode_during = Geofence::resolve_hide_mode( $config, 'during' );
		$datetime_hide_mode_after  = Geofence::resolve_hide_mode( $config, 'after' );
		$msg_datetime              = $config['msg_datetime'] ?? __( 'This form is not available at this time.', 'ffcertificate' );

		// Schedule exception per submission (#366). Four optional keys that
		// live alongside the datetime config: a toggle that enables the
		// operator-driven exception flow, two TIME inputs that override the
		// `{schedule}` placeholder when set (falling back to geofence
		// `time_start`/`time_end` when empty), and a select that controls
		// which mode the operator modal opens in by default.
		$schedule_exception_enabled = ( $config['schedule_exception_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$class_time_start           = $config['class_time_start'] ?? '';
		$class_time_end             = $config['class_time_end'] ?? '';
		$schedule_default_mode      = $config['schedule_default_mode'] ?? 'now';
		if ( ! in_array( $schedule_default_mode, array( 'now', 'manual' ), true ) ) {
			$schedule_default_mode = 'now';
		}

		// Per-input invalid flags for first-paint feedback when the persisted
		// config has an order error (e.g. an import wrote `date_end <
		// date_start`).
		$invalid_fields     = Geofence::analyze_datetime_order( $config );
		$invalid_attr       = static function ( string $field ) use ( $invalid_fields ): string {
			return isset( $invalid_fields[ $field ] ) ? ' class="ffc-input-invalid"' : '';
		};
		$datetime_order_msg = $invalid_fields ? reset( $invalid_fields ) : '';
		?>
		<div class="ffc-geofence-container">
			<!-- Sentinel: ensures ffc_geofence is always in POST even when all fields are disabled -->
			<input type="hidden" name="ffc_geofence[_save]" value="1">
			<table class="form-table">
				<tbody class="ffc-event-schedule-section">
				<tr>
					<th colspan="2">
						<h3 class="ffc-subsection-title"><?php esc_html_e( 'Event Schedule (Reference)', 'ffcertificate' ); ?></h3>
						<p class="description ffc-subsection-description">
							<?php esc_html_e( "When does this event take place? Renders as {{schedule}} on the certificate template (e.g. '9h às 12h'). When filled, the template must contain {{schedule}} — the form save will be blocked until the placeholder is present.", 'ffcertificate' ); ?>
						</p>
					</th>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'From — To', 'ffcertificate' ); ?></label></th>
					<td>
						<label><?php esc_html_e( 'From:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[class_time_start]" value="<?php echo esc_attr( $class_time_start ); ?>"></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'To:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[class_time_end]" value="<?php echo esc_attr( $class_time_end ); ?>"></label>
					</td>
				</tr>
				</tbody>
				<tbody>
				<tr>
					<th><label><?php esc_html_e( 'Enable Date/Time Restrictions', 'ffcertificate' ); ?></label></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[datetime_enabled]',
								'id'      => 'ffc_geofence_datetime_enabled',
								'checked' => '1' === (string) $datetime_enabled,
								'label'   => __( 'Restrict form access by date and time', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'geofence_datetime_enabled' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Control when users can access this form based on date range and daily hours.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
				<tbody class="ffc-collapsed-target<?php echo '1' === $datetime_enabled ? '' : ' ffc-collapsed'; ?>"
					data-ffc-master="ffc_geofence_datetime_enabled"
					aria-hidden="<?php echo '1' === $datetime_enabled ? 'false' : 'true'; ?>">
				<tr>
					<th><label><?php esc_html_e( 'Date Range', 'ffcertificate' ); ?></label></th>
					<td>
						<label><?php esc_html_e( 'Start:', 'ffcertificate' ); ?> <input type="date" name="ffc_geofence[date_start]" value="<?php echo esc_attr( $date_start ); ?>"<?php echo $invalid_attr( 'date_start' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure returns pre-built class attribute. ?>></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'End:', 'ffcertificate' ); ?> <input type="date" name="ffc_geofence[date_end]" value="<?php echo esc_attr( $date_end ); ?>"<?php echo $invalid_attr( 'date_end' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></label>
						<p class="description"><?php esc_html_e( 'Leave empty for no date restriction. Format: YYYY-MM-DD', 'ffcertificate' ); ?></p>
						<p class="description ffc-datetime-order-error"<?php echo $datetime_order_msg ? '' : ' style="display:none;"'; ?>>
							<?php echo esc_html( $datetime_order_msg ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Time Range', 'ffcertificate' ); ?></label></th>
					<td>
						<label><?php esc_html_e( 'From:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[time_start]" value="<?php echo esc_attr( $time_start ); ?>"<?php echo $invalid_attr( 'time_start' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></label>
						&nbsp;&nbsp;
						<label><?php esc_html_e( 'To:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[time_end]" value="<?php echo esc_attr( $time_end ); ?>"<?php echo $invalid_attr( 'time_end' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></label>
						<p class="description"><?php esc_html_e( 'Leave empty for 24/7 access. Default: 00:00 to 23:59', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr id="ffc-time-mode-row">
					<th><label><?php esc_html_e( 'Time Behavior', 'ffcertificate' ); ?></label></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="ffc_geofence[time_mode]" value="span" <?php checked( $time_mode, 'span' ); ?>>
								<strong><?php esc_html_e( 'Time spans across dates', 'ffcertificate' ); ?></strong>
							</label>
							<p class="description ffc-time-description">
								<?php esc_html_e( 'Start time applies to start date, end time applies to end date. Form is open continuously between those timestamps.', 'ffcertificate' ); ?><br>
								<?php esc_html_e( 'Example: Start 01/01 12:00 + End 10/01 23:00 = Open from 12:00 on Jan 1st until 23:00 on Jan 10th', 'ffcertificate' ); ?>
							</p>

							<label>
								<input type="radio" name="ffc_geofence[time_mode]" value="daily" <?php checked( $time_mode, 'daily' ); ?>>
								<strong><?php esc_html_e( 'Time applies to each day individually', 'ffcertificate' ); ?></strong>
							</label>
							<p class="description ffc-time-description">
								<?php esc_html_e( 'Time range applies to every day in the date range. Form respects daily hours.', 'ffcertificate' ); ?><br>
								<?php esc_html_e( 'Example: Start 01/01 + End 10/01 + Time 12:00-23:00 = Open 12:00-23:00 every day from Jan 1-10', 'ffcertificate' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th><label for="ffc_datetime_hide_mode_before"><?php esc_html_e( 'Display before opening', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc_datetime_hide_mode_before" name="ffc_geofence[datetime_hide_mode_before]">
							<option value="message" <?php selected( $datetime_hide_mode_before, 'message' ); ?>><?php esc_html_e( 'Show blocked message (Recommended)', 'ffcertificate' ); ?></option>
							<option value="title_message" <?php selected( $datetime_hide_mode_before, 'title_message' ); ?>><?php esc_html_e( 'Show title + description + message', 'ffcertificate' ); ?></option>
							<option value="hide" <?php selected( $datetime_hide_mode_before, 'hide' ); ?>><?php esc_html_e( 'Hide form completely', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How to display the form before the start date / start time. "Hide form completely" makes the page render as if the form did not exist yet.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr id="ffc-datetime-hide-mode-during-row" class="ffc-datetime-hide-during"<?php echo 'daily' === $time_mode ? '' : ' style="display:none;"'; ?>>
					<th><label for="ffc_datetime_hide_mode_during"><?php esc_html_e( 'Display during, outside daily slot', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc_datetime_hide_mode_during" name="ffc_geofence[datetime_hide_mode_during]">
							<option value="message" <?php selected( $datetime_hide_mode_during, 'message' ); ?>><?php esc_html_e( 'Show blocked message (Recommended)', 'ffcertificate' ); ?></option>
							<option value="title_message" <?php selected( $datetime_hide_mode_during, 'title_message' ); ?>><?php esc_html_e( 'Show title + description + message', 'ffcertificate' ); ?></option>
							<option value="hide" <?php selected( $datetime_hide_mode_during, 'hide' ); ?>><?php esc_html_e( 'Hide form completely', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How to display the form when the campaign is in its date range but the current time is outside today\'s daily slot (only used in Time Behavior = "applies to each day individually").', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ffc_datetime_hide_mode_after"><?php esc_html_e( 'Display after closing', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc_datetime_hide_mode_after" name="ffc_geofence[datetime_hide_mode_after]">
							<option value="message" <?php selected( $datetime_hide_mode_after, 'message' ); ?>><?php esc_html_e( 'Show blocked message (Recommended)', 'ffcertificate' ); ?></option>
							<option value="title_message" <?php selected( $datetime_hide_mode_after, 'title_message' ); ?>><?php esc_html_e( 'Show title + description + message', 'ffcertificate' ); ?></option>
							<option value="hide" <?php selected( $datetime_hide_mode_after, 'hide' ); ?>><?php esc_html_e( 'Hide form completely', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How to display the form after the end date / end time.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Blocked Message', 'ffcertificate' ); ?></label></th>
					<td>
						<textarea name="ffc_geofence[msg_datetime]" rows="3" class="ffc-w100"><?php echo esc_textarea( $msg_datetime ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Message shown when form is accessed outside allowed date/time.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
				<tbody class="ffc-schedule-exception-section">
				<tr>
					<th colspan="2">
						<h3 class="ffc-subsection-title"><?php esc_html_e( 'Per-participant entry/exit exception', 'ffcertificate' ); ?></h3>
						<p class="description ffc-subsection-description">
							<?php esc_html_e( 'Optional. Lets an authenticated operator on the public CSV-download panel create a single submission with a different schedule (e.g. for a participant who left early), overriding the Event Schedule above for that one submission. Independent of the date/time restrictions toggle above.', 'ffcertificate' ); ?>
						</p>
					</th>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Enable Schedule Exception', 'ffcertificate' ); ?></label></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[schedule_exception_enabled]',
								'id'      => 'ffc_geofence_schedule_exception_enabled',
								'checked' => '1' === (string) $schedule_exception_enabled,
								'label'   => __( 'Allow operators to create per-submission schedule exceptions', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'geofence_schedule_exception_enabled' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Off by default. When on, the public CSV-download panel shows an "Entry/exit exception" button to authenticated operators.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
				<tbody class="ffc-collapsed-target<?php echo '1' === $schedule_exception_enabled ? '' : ' ffc-collapsed'; ?>"
					data-ffc-master="ffc_geofence_schedule_exception_enabled"
					aria-hidden="<?php echo '1' === $schedule_exception_enabled ? 'false' : 'true'; ?>">
				<tr>
					<th><label for="ffc_geofence_schedule_default_mode"><?php esc_html_e( 'Default Modal Mode', 'ffcertificate' ); ?></label></th>
					<td>
						<select id="ffc_geofence_schedule_default_mode" name="ffc_geofence[schedule_default_mode]">
							<option value="now" <?php selected( $schedule_default_mode, 'now' ); ?>><?php esc_html_e( 'Now — pre-fill end time with the current moment', 'ffcertificate' ); ?></option>
							<option value="manual" <?php selected( $schedule_default_mode, 'manual' ); ?>><?php esc_html_e( 'Manual — let the operator type both ends', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Which mode the operator exception modal opens in by default. Operators can flip the toggle on a per-exception basis.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the geolocation-restriction section ("Geolocation" tab).
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_geolocation( WP_Post $post ): void {
		$config = $this->get_config( $post );

		$geo_enabled              = ( $config['geo_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$geo_gps_enabled          = ( $config['geo_gps_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$geo_ip_enabled           = ( $config['geo_ip_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$geo_areas                = $config['geo_areas'] ?? '';
		$geo_area_source          = $config['geo_area_source'] ?? 'custom';
		$geo_area_location_ids    = $config['geo_area_location_ids'] ?? array();
		$geo_ip_areas_permissive  = ( $config['geo_ip_areas_permissive'] ?? '0' ) === '1' ? '1' : '0';
		$geo_ip_areas             = $config['geo_ip_areas'] ?? '';
		$geo_ip_area_source       = $config['geo_ip_area_source'] ?? 'custom';
		$geo_ip_area_location_ids = $config['geo_ip_area_location_ids'] ?? array();

		if ( 'auto-draft' === $post->post_status ) {
			$default_gps = GeofenceLocationRegistry::get_default_gps();
			if ( $default_gps ) {
				$geo_area_source       = 'locations';
				$geo_area_location_ids = array( $default_gps['id'] );
			}
			$default_ip = GeofenceLocationRegistry::get_default_ip();
			if ( $default_ip ) {
				$geo_ip_area_source       = 'locations';
				$geo_ip_area_location_ids = array( $default_ip['id'] );
			}
		}

		$all_locations    = GeofenceLocationRegistry::get_all();
		$geo_gps_ip_logic = $config['geo_gps_ip_logic'] ?? 'or';
		$geo_hide_mode    = $config['geo_hide_mode'] ?? 'message';
		$msg_geo_blocked  = $config['msg_geo_blocked'] ?? __( 'This form is not available in your location.', 'ffcertificate' );
		$msg_geo_error    = $config['msg_geo_error'] ?? __( 'Unable to determine your location. Please enable location services.', 'ffcertificate' );
		?>
		<div class="ffc-geofence-container">
			<table class="form-table">
				<tbody>
				<tr>
					<th><label><?php esc_html_e( 'Enable Geolocation', 'ffcertificate' ); ?></label></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[geo_enabled]',
								'id'      => 'ffc_geofence_geo_enabled',
								'checked' => '1' === (string) $geo_enabled,
								'label'   => __( 'Restrict form access by geographic location', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'geofence_geo_enabled' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Limit form access to users within specific geographic areas.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
				<tbody class="ffc-collapsed-target<?php echo '1' === $geo_enabled ? '' : ' ffc-collapsed'; ?>"
					data-ffc-master="ffc_geofence_geo_enabled"
					aria-hidden="<?php echo '1' === $geo_enabled ? 'false' : 'true'; ?>">
				<tr>
					<th><label><?php esc_html_e( 'Validation Methods', 'ffcertificate' ); ?></label></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[geo_gps_enabled]',
								'checked' => '1' === (string) $geo_gps_enabled,
								'label'   => __( 'GPS (Browser geolocation)', 'ffcertificate' ),
							)
						);
						?>
						<br>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[geo_ip_enabled]',
								'checked' => '1' === (string) $geo_ip_enabled,
								'label'   => __( 'IP Address (backend validation)', 'ffcertificate' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Choose one or both methods. GPS is more accurate but requires user permission.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Allowed Areas (GPS)', 'ffcertificate' ); ?></label></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="ffc_geofence[geo_area_source]" value="locations" <?php checked( $geo_area_source, 'locations' ); ?>>
								<?php esc_html_e( 'Registered locations', 'ffcertificate' ); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="radio" name="ffc_geofence[geo_area_source]" value="custom" <?php checked( $geo_area_source, 'custom' ); ?>>
								<?php esc_html_e( 'Custom coordinates', 'ffcertificate' ); ?>
							</label>
						</fieldset>

						<div class="<?php echo esc_attr( 'ffc-geo-source-locations' . ( 'locations' !== $geo_area_source ? ' ffc-initially-hidden' : '' ) ); ?>">
							<select multiple name="ffc_geofence[geo_area_location_ids][]" class="ffc-w100" size="5">
								<?php foreach ( $all_locations as $loc ) : ?>
									<option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php echo in_array( $loc['id'], $geo_area_location_ids, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $loc['name'] . ' (' . $loc['lat'] . ', ' . $loc['lng'] . ', ' . $loc['radius'] . 'm)' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple locations.', 'ffcertificate' ); ?></p>
						</div>

						<div class="<?php echo esc_attr( 'ffc-geo-source-custom' . ( 'custom' !== $geo_area_source ? ' ffc-initially-hidden' : '' ) ); ?>">
							<textarea name="ffc_geofence[geo_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 5000&#10;-22.9068, -43.1729, 10000"><?php echo esc_textarea( $geo_areas ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Format: latitude, longitude, radius(meters) - One per line. Example: -23.5505, -46.6333, 5000', 'ffcertificate' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'IP Geolocation Areas', 'ffcertificate' ); ?></label></th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ffc_geofence[geo_ip_areas_permissive]',
								'id'      => 'ffc_geofence_geo_ip_areas_permissive',
								'checked' => '1' === (string) $geo_ip_areas_permissive,
								'label'   => __( 'Use different areas for IP validation', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-form-key' => 'geofence_geo_ip_areas_permissive' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'When unchecked, IP validation uses the same areas as GPS.', 'ffcertificate' ); ?></p>

						<div class="ffc-ip-areas-container ffc-collapsed-target<?php echo '1' !== $geo_ip_areas_permissive ? ' ffc-collapsed' : ''; ?>"
							data-ffc-master="ffc_geofence_geo_ip_areas_permissive"
							aria-hidden="<?php echo '1' !== $geo_ip_areas_permissive ? 'true' : 'false'; ?>">
							<br>
							<fieldset>
								<label>
									<input type="radio" name="ffc_geofence[geo_ip_area_source]" value="locations" <?php checked( $geo_ip_area_source, 'locations' ); ?>>
									<?php esc_html_e( 'Registered locations', 'ffcertificate' ); ?>
								</label>
								&nbsp;&nbsp;
								<label>
									<input type="radio" name="ffc_geofence[geo_ip_area_source]" value="custom" <?php checked( $geo_ip_area_source, 'custom' ); ?>>
									<?php esc_html_e( 'Custom coordinates', 'ffcertificate' ); ?>
								</label>
							</fieldset>

							<div class="<?php echo esc_attr( 'ffc-geo-source-locations' . ( 'locations' !== $geo_ip_area_source ? ' ffc-initially-hidden' : '' ) ); ?>">
								<select multiple name="ffc_geofence[geo_ip_area_location_ids][]" class="ffc-w100" size="5">
									<?php foreach ( $all_locations as $loc ) : ?>
										<option value="<?php echo esc_attr( $loc['id'] ); ?>" <?php echo in_array( $loc['id'], $geo_ip_area_location_ids, true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $loc['name'] . ' (' . $loc['lat'] . ', ' . $loc['lng'] . ', ' . $loc['radius'] . 'm)' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple locations.', 'ffcertificate' ); ?></p>
							</div>

							<div class="<?php echo esc_attr( 'ffc-geo-source-custom' . ( 'custom' !== $geo_ip_area_source ? ' ffc-initially-hidden' : '' ) ); ?>">
								<textarea name="ffc_geofence[geo_ip_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 50000&#10;-22.9068, -43.1729, 100000"><?php echo esc_textarea( $geo_ip_areas ); ?></textarea>
								<p class="description"><?php esc_html_e( 'IP geolocation is less precise (1-50km). Use larger radius (in meters).', 'ffcertificate' ); ?></p>
							</div>
						</div>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'GPS + IP Logic', 'ffcertificate' ); ?></label></th>
					<td>
						<select name="ffc_geofence[geo_gps_ip_logic]">
							<option value="or" <?php selected( $geo_gps_ip_logic, 'or' ); ?>><?php esc_html_e( 'OR - Allow if GPS OR IP is valid (recommended)', 'ffcertificate' ); ?></option>
							<option value="and" <?php selected( $geo_gps_ip_logic, 'and' ); ?>><?php esc_html_e( 'AND - Require both GPS AND IP to be valid (stricter)', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'When both GPS and IP are enabled, how to combine the results.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Display Mode', 'ffcertificate' ); ?></label></th>
					<td>
						<select name="ffc_geofence[geo_hide_mode]">
							<option value="message" <?php selected( $geo_hide_mode, 'message' ); ?>><?php esc_html_e( 'Show blocked message (Recommended)', 'ffcertificate' ); ?></option>
							<option value="title_message" <?php selected( $geo_hide_mode, 'title_message' ); ?>><?php esc_html_e( 'Show title + description + message', 'ffcertificate' ); ?></option>
							<option value="hide" <?php selected( $geo_hide_mode, 'hide' ); ?>><?php esc_html_e( 'Hide form completely', 'ffcertificate' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'How to display the form when user is outside allowed areas.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Blocked Message', 'ffcertificate' ); ?></label></th>
					<td>
						<textarea name="ffc_geofence[msg_geo_blocked]" rows="2" class="ffc-w100"><?php echo esc_textarea( $msg_geo_blocked ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Message shown when user is outside allowed geographic areas.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Error Message', 'ffcertificate' ); ?></label></th>
					<td>
						<textarea name="ffc_geofence[msg_geo_error]" rows="2" class="ffc-w100"><?php echo esc_textarea( $msg_geo_error ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Message shown when location detection fails (GPS denied, etc).', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				</tbody>
			</table>
		</div>
		<script>
		jQuery(function($) {
			function toggleGeoSource(prefix) {
				var source = $('input[name="ffc_geofence[' + prefix + '_source]"]:checked').val();
				var container = $('input[name="ffc_geofence[' + prefix + '_source]"]').closest('td');
				container.find('.ffc-geo-source-locations')[source === 'locations' ? 'show' : 'hide']();
				container.find('.ffc-geo-source-custom')[source === 'custom' ? 'show' : 'hide']();
			}
			$('input[name="ffc_geofence[geo_area_source]"]').on('change', function() { toggleGeoSource('geo_area'); });
			$('input[name="ffc_geofence[geo_ip_area_source]"]').on('change', function() { toggleGeoSource('geo_ip_area'); });
			toggleGeoSource('geo_area');
			toggleGeoSource('geo_ip_area');

			// IP-Areas-Permissive collapse is now handled by the generic
			// `.ffc-collapsed-target` initializer in ffc-admin.js
			// (#238 / Sprint 3) — the container above carries
			// data-ffc-master="ffc_geofence_geo_ip_areas_permissive".
		});
		</script>
		<?php
	}
}
