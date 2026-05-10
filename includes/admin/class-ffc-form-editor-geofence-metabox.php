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

use FreeFormCertificate\Security\GeofenceLocationRegistry;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Geofence Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorGeofenceMetabox {

	/**
	 * Render Geofence & DateTime Restrictions Meta Box
	 *
	 * @since 3.0.0
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// Defaults.
		$datetime_enabled   = ( $config['datetime_enabled'] ?? '0' ) === '1' ? '1' : '0';
		$date_start         = $config['date_start'] ?? '';
		$date_end           = $config['date_end'] ?? '';
		$time_start         = $config['time_start'] ?? '';
		$time_end           = $config['time_end'] ?? '';
		$time_mode          = $config['time_mode'] ?? 'daily'; // 'daily' or 'span'
		$datetime_hide_mode = $config['datetime_hide_mode'] ?? 'message';
		$msg_datetime       = $config['msg_datetime'] ?? __( 'This form is not available at this time.', 'ffcertificate' );

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
			<!-- Sentinel: ensures ffc_geofence is always in POST even when all fields are disabled -->
			<input type="hidden" name="ffc_geofence[_save]" value="1">
			<!-- Tab Navigation -->
			<div class="ffc-geofence-tabs">
				<button type="button" class="ffc-geo-tab-btn active ffc-icon-calendar" data-tab="datetime">
					<?php esc_html_e( 'Date & Time', 'ffcertificate' ); ?>
				</button>
				<button type="button" class="ffc-geo-tab-btn ffc-icon-globe" data-tab="geolocation">
					<?php esc_html_e( 'Geolocation', 'ffcertificate' ); ?>
				</button>
			</div>

			<!-- Tab: Date & Time -->
			<div class="ffc-geo-tab-content active" id="ffc-tab-datetime">
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Enable Date/Time Restrictions', 'ffcertificate' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="ffc_geofence[datetime_enabled]" value="1" <?php checked( $datetime_enabled, '1' ); ?>>
								<?php esc_html_e( 'Restrict form access by date and time', 'ffcertificate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Control when users can access this form based on date range and daily hours.', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Date Range', 'ffcertificate' ); ?></label></th>
						<td>
							<label><?php esc_html_e( 'Start:', 'ffcertificate' ); ?> <input type="date" name="ffc_geofence[date_start]" value="<?php echo esc_attr( $date_start ); ?>"></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'End:', 'ffcertificate' ); ?> <input type="date" name="ffc_geofence[date_end]" value="<?php echo esc_attr( $date_end ); ?>"></label>
							<p class="description"><?php esc_html_e( 'Leave empty for no date restriction. Format: YYYY-MM-DD', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Time Range', 'ffcertificate' ); ?></label></th>
						<td>
							<label><?php esc_html_e( 'From:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[time_start]" value="<?php echo esc_attr( $time_start ); ?>"></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'To:', 'ffcertificate' ); ?> <input type="time" name="ffc_geofence[time_end]" value="<?php echo esc_attr( $time_end ); ?>"></label>
							<p class="description"><?php esc_html_e( 'Leave empty for 24/7 access. Default: 00:00 to 23:59', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<tr id="ffc-time-mode-row" class="ffc-conditional-field<?php echo esc_attr( ( ! empty( $date_start ) && ! empty( $date_end ) && $date_start !== $date_end ) ? ' active' : '' ); ?>">
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
						<th><label><?php esc_html_e( 'Display Mode', 'ffcertificate' ); ?></label></th>
						<td>
							<select name="ffc_geofence[datetime_hide_mode]">
								<option value="message" <?php selected( $datetime_hide_mode, 'message' ); ?>><?php esc_html_e( 'Show blocked message (Recommended)', 'ffcertificate' ); ?></option>
								<option value="title_message" <?php selected( $datetime_hide_mode, 'title_message' ); ?>><?php esc_html_e( 'Show title + description + message', 'ffcertificate' ); ?></option>
								<option value="hide" <?php selected( $datetime_hide_mode, 'hide' ); ?>><?php esc_html_e( 'Hide form completely', 'ffcertificate' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How to display the form when date/time is invalid.', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Blocked Message', 'ffcertificate' ); ?></label></th>
						<td>
							<textarea name="ffc_geofence[msg_datetime]" rows="3" class="ffc-w100"><?php echo esc_textarea( $msg_datetime ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Message shown when form is accessed outside allowed date/time.', 'ffcertificate' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Tab: Geolocation -->
			<div class="ffc-geo-tab-content" id="ffc-tab-geolocation">
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Enable Geolocation', 'ffcertificate' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="ffc_geofence[geo_enabled]" value="1" <?php checked( $geo_enabled, '1' ); ?>>
								<?php esc_html_e( 'Restrict form access by geographic location', 'ffcertificate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Limit form access to users within specific geographic areas.', 'ffcertificate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Validation Methods', 'ffcertificate' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" name="ffc_geofence[geo_gps_enabled]" value="1" <?php checked( $geo_gps_enabled, '1' ); ?>>
								<?php esc_html_e( 'GPS (Browser geolocation)', 'ffcertificate' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="ffc_geofence[geo_ip_enabled]" value="1" <?php checked( $geo_ip_enabled, '1' ); ?>>
								<?php esc_html_e( 'IP Address (backend validation)', 'ffcertificate' ); ?>
							</label>
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
							<label>
								<input type="checkbox" name="ffc_geofence[geo_ip_areas_permissive]" value="1" <?php checked( $geo_ip_areas_permissive, '1' ); ?>>
								<?php esc_html_e( 'Use different areas for IP validation', 'ffcertificate' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When unchecked, IP validation uses the same areas as GPS.', 'ffcertificate' ); ?></p>

							<div class="<?php echo esc_attr( 'ffc-ip-areas-container' . ( '1' !== $geo_ip_areas_permissive ? ' ffc-initially-hidden' : '' ) ); ?>">
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
				</table>
			</div>
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

			$('input[name="ffc_geofence[geo_ip_areas_permissive]"]').on('change', function() {
				$('.ffc-ip-areas-container')[$(this).is(':checked') ? 'show' : 'hide']();
			});
		});
		</script>
		<?php
	}
}
