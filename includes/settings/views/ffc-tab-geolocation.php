<?php
/**
 * Geolocation Settings Tab Template
 *
 * @package FFC
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="ffc-settings-wrap">

<div class="ffc-settings-tab-content">
	<form method="POST" action="">
		<?php wp_nonce_field( 'ffc_geolocation_nonce' ); ?>

		<!-- Geofence Locations -->
		<div class="card">
			<h2 class="ffc-icon-globe"><?php esc_html_e( 'Geofence Locations', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Manage named geofence locations. These locations can be assigned to forms for geolocation restrictions.', 'ffcertificate' ); ?>
			</p>

			<?php
			$ffc_locations      = \FreeFormCertificate\Security\GeofenceLocationRegistry::get_all();
			$ffc_default_gps    = \FreeFormCertificate\Security\GeofenceLocationRegistry::get_default_gps();
			$ffc_default_gps_id = $ffc_default_gps ? $ffc_default_gps['id'] : '';
			$ffc_default_ip     = \FreeFormCertificate\Security\GeofenceLocationRegistry::get_default_ip();
			$ffc_default_ip_id  = $ffc_default_ip ? $ffc_default_ip['id'] : '';
			?>

			<table class="widefat striped" role="presentation">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Latitude', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Longitude', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Radius (m)', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Default GPS', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Default IP', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $ffc_locations ) ) : ?>
						<?php foreach ( $ffc_locations as $ffc_loc ) : ?>
							<tr class="ffc-location-row" data-location-id="<?php echo esc_attr( $ffc_loc['id'] ); ?>">
								<td>
									<input type="text"
											name="ffc_locations[<?php echo esc_attr( $ffc_loc['id'] ); ?>][name]"
											value="<?php echo esc_attr( $ffc_loc['name'] ); ?>"
											class="regular-text ffc-location-field"
											data-field="name"
											required>
									<?php if ( $ffc_loc['id'] === $ffc_default_gps_id ) : ?>
										<span class="description">(GPS)</span>
									<?php endif; ?>
									<?php if ( $ffc_loc['id'] === $ffc_default_ip_id ) : ?>
										<span class="description">(IP)</span>
									<?php endif; ?>
								</td>
								<td>
									<input type="number"
											name="ffc_locations[<?php echo esc_attr( $ffc_loc['id'] ); ?>][lat]"
											value="<?php echo esc_attr( $ffc_loc['lat'] ); ?>"
											step="any" min="-90" max="90"
											class="ffc-location-field ffc-set-w-120"
											data-field="lat"
											required>
								</td>
								<td>
									<input type="number"
											name="ffc_locations[<?php echo esc_attr( $ffc_loc['id'] ); ?>][lng]"
											value="<?php echo esc_attr( $ffc_loc['lng'] ); ?>"
											step="any" min="-180" max="180"
											class="ffc-location-field ffc-set-w-120"
											data-field="lng"
											required>
								</td>
								<td>
									<input type="number"
											name="ffc_locations[<?php echo esc_attr( $ffc_loc['id'] ); ?>][radius]"
											value="<?php echo esc_attr( $ffc_loc['radius'] ); ?>"
											step="any" min="1"
											class="ffc-location-field ffc-set-w-100"
											data-field="radius"
											required>
								</td>
								<td>
									<input type="radio"
											name="ffc_location_default_gps"
											class="ffc-location-default-gps"
											value="<?php echo esc_attr( $ffc_loc['id'] ); ?>"
											<?php checked( $ffc_loc['id'], $ffc_default_gps_id ); ?>>
								</td>
								<td>
									<input type="radio"
											name="ffc_location_default_ip"
											class="ffc-location-default-ip"
											value="<?php echo esc_attr( $ffc_loc['id'] ); ?>"
											<?php checked( $ffc_loc['id'], $ffc_default_ip_id ); ?>>
								</td>
								<td>
									<button type="button" class="button button-small ffc-location-delete">
										<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
									</button>
									<span class="ffc-autosave-badge" hidden></span>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="7"><em><?php esc_html_e( 'No locations registered yet.', 'ffcertificate' ); ?></em></td>
						</tr>
					<?php endif; ?>

					<!-- "None" radio options row -->
					<tr class="ffc-locations-none-row">
						<td colspan="4">
							<em><?php esc_html_e( 'None (no default)', 'ffcertificate' ); ?></em>
						</td>
						<td>
							<input type="radio"
									name="ffc_location_default_gps"
									value=""
									<?php checked( '', $ffc_default_gps_id ); ?>>
						</td>
						<td>
							<input type="radio"
									name="ffc_location_default_ip"
									value=""
									<?php checked( '', $ffc_default_ip_id ); ?>>
						</td>
						<td></td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="7"><?php esc_html_e( 'Add New Location', 'ffcertificate' ); ?></th>
					</tr>
					<tr id="ffc-location-new-row">
						<td>
							<input type="text" name="ffc_location_new[name]" value=""
									class="regular-text ffc-location-new-field"
									data-field="name"
									placeholder="<?php esc_attr_e( 'Location name', 'ffcertificate' ); ?>">
						</td>
						<td>
							<input type="number" name="ffc_location_new[lat]" value=""
									step="any" min="-90" max="90"
									class="ffc-location-new-field ffc-set-w-120"
									data-field="lat"
									placeholder="<?php esc_attr_e( 'Latitude', 'ffcertificate' ); ?>">
						</td>
						<td>
							<input type="number" name="ffc_location_new[lng]" value=""
									step="any" min="-180" max="180"
									class="ffc-location-new-field ffc-set-w-120"
									data-field="lng"
									placeholder="<?php esc_attr_e( 'Longitude', 'ffcertificate' ); ?>">
						</td>
						<td>
							<input type="number" name="ffc_location_new[radius]" value=""
									step="any" min="1"
									class="ffc-location-new-field ffc-set-w-100"
									data-field="radius"
									placeholder="1000">
						</td>
						<td colspan="2">
							<button type="button" class="button button-primary" id="ffc-location-add">
								<?php esc_html_e( 'Add location', 'ffcertificate' ); ?>
							</button>
						</td>
						<td>
							<span class="ffc-autosave-badge" hidden></span>
						</td>
					</tr>
				</tfoot>
			</table>
		</div>


		<!-- IP Geolocation API Section -->
		<div class="card">
			<h2 class="ffc-icon-link"><?php esc_html_e( 'IP Geolocation API', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure external IP geolocation services for backend validation. These services detect user location by IP address.', 'ffcertificate' ); ?>
			</p>

			<table class="form-table" role="presentation"><tbody>
				<!-- Enable IP API -->
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'IP Geolocation', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ip_api_enabled',
								'checked' => (bool) $settings['ip_api_enabled'],
								'label'   => __( 'Enable IP geolocation API for backend validation', 'ffcertificate' ),
								'data'    => array(
									'ffc-autosave-key'   => 'ip_api_enabled',
									// Drives the collapse of the dependent IP-API rows
									// below (Primary Service, Service Cascade, IPInfo
									// Key, IP Cache, Cache TTL) and the two
									// IP-dependent fallback rows further down. See
									// ffc-section-collapse.js for the markup contract.
									'ffc-section-master' => 'ip_api_full',
								),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'When enabled, validates user location by IP address on the server (in addition to GPS).', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- API Service Selection -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label for="ffc_ip_api_service"><?php esc_html_e( 'Primary Service', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ip_api_service" id="ffc_ip_api_service">
							<option value="ip-api" <?php selected( $settings['ip_api_service'], 'ip-api' ); ?>>
								<?php esc_html_e( 'ip-api.com (Free, 45 req/min, no key)', 'ffcertificate' ); ?>
							</option>
							<option value="ipinfo" <?php selected( $settings['ip_api_service'], 'ipinfo' ); ?>>
								<?php esc_html_e( 'ipinfo.io (50k/month free, requires key)', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select which IP geolocation service to use. ip-api.com is free without API key.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- Cascade/Fallback Between Services -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label><?php esc_html_e( 'Service Cascade', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ip_api_cascade',
								'checked' => (bool) $settings['ip_api_cascade'],
								'label'   => __( 'Enable cascade: if primary fails, try the other service', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'ip_api_cascade' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'When enabled, if the primary service fails, automatically try the alternative service.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- IPInfo API Key -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label for="ffc_ipinfo_api_key"><?php esc_html_e( 'IPInfo.io API Key', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text"
								name="ipinfo_api_key"
								id="ffc_ipinfo_api_key"
								value="<?php echo esc_attr( $settings['ipinfo_api_key'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Enter your ipinfo.io API key', 'ffcertificate' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Required only if using ipinfo.io service. Free tier: 50,000 requests/month.', 'ffcertificate' ); ?>
							<a href="https://ipinfo.io/signup" target="_blank"><?php esc_html_e( 'Get your free API key', 'ffcertificate' ); ?></a>
						</p>
					</td>
				</tr>

				<!-- IP Cache Settings -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label><?php esc_html_e( 'IP Cache', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'ip_cache_enabled',
								'checked' => (bool) $settings['ip_cache_enabled'],
								'label'   => __( 'Cache IP geolocation results to reduce API calls', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'ip_cache_enabled' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Recommended. Caches geolocation by IP to avoid repeated API calls.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- Cache TTL -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label for="ffc_ip_cache_ttl"><?php esc_html_e( 'IP Cache Duration (TTL)', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number"
								name="ip_cache_ttl"
								id="ffc_ip_cache_ttl"
								value="<?php echo absint( $settings['ip_cache_ttl'] ); ?>"
								min="300"
								max="3600"
								step="60">
						<?php esc_html_e( 'seconds', 'ffcertificate' ); ?>
						<p class="description">
							<?php esc_html_e( 'How long to cache IP location data. Range: 300-3600 seconds (5 min - 1 hour).', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>


		<!-- GPS Cache Settings Section -->
		<div class="card">
			<h2 class="ffc-icon-package"><?php esc_html_e( 'GPS Cache Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure GPS location caching on the frontend (browser localStorage). GPS cache is always enabled for better performance.', 'ffcertificate' ); ?>
			</p>

			<table class="form-table" role="presentation"><tbody>
				<!-- GPS Cache TTL -->
				<tr>
					<th scope="row">
						<label for="ffc_gps_cache_ttl"><?php esc_html_e( 'GPS Cache Duration (TTL)', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number"
								name="gps_cache_ttl"
								id="ffc_gps_cache_ttl"
								value="<?php echo absint( $settings['gps_cache_ttl'] ); ?>"
								min="60"
								max="3600"
								step="60">
						<?php esc_html_e( 'seconds', 'ffcertificate' ); ?>
						<p class="description">
							<?php esc_html_e( 'How long to cache GPS location in browser. Range: 60-3600 seconds (1 min - 1 hour). Default: 600 (10 min).', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>


		<!-- Fallback Behavior Section -->
		<div class="card">
			<h2 class="ffc-icon-sync"><?php esc_html_e( 'Fallback Behavior', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define what happens when geolocation services fail or are denied by the user.', 'ffcertificate' ); ?>
			</p>

			<table class="form-table" role="presentation"><tbody>
				<!-- GPS Failure Fallback -->
				<tr>
					<th scope="row">
						<label for="ffc_gps_fallback_preset"><?php esc_html_e( 'When GPS Fails', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						$fallback_preset = $settings['gps_fallback_preset'] ?? 'hybrid';
						$fallback_cases  = $settings['gps_fallback_cases'] ?? array();
						$case_labels     = array(
							'permission_denied'    => __( 'User denied location permission', 'ffcertificate' ),
							'no_api'               => __( 'Browser does not support geolocation', 'ffcertificate' ),
							'position_unavailable' => __( 'Location unavailable (GPS off, no signal)', 'ffcertificate' ),
							'timeout'              => __( 'Browser timed out', 'ffcertificate' ),
							'safety_timer'         => __( 'Device never responded (40 s safety timer)', 'ffcertificate' ),
						);
						?>
						<select name="gps_fallback_preset" id="ffc_gps_fallback_preset">
							<option value="tolerant" <?php selected( $fallback_preset, 'tolerant' ); ?>>
								<?php esc_html_e( 'Tolerant — allow access on every failure', 'ffcertificate' ); ?>
							</option>
							<option value="hybrid" <?php selected( $fallback_preset, 'hybrid' ); ?>>
								<?php esc_html_e( 'Hybrid — allow only when the user denied or the browser lacks support', 'ffcertificate' ); ?>
							</option>
							<option value="strict" <?php selected( $fallback_preset, 'strict' ); ?>>
								<?php esc_html_e( 'Strict — block access on every failure', 'ffcertificate' ); ?>
							</option>
							<option value="custom" <?php selected( $fallback_preset, 'custom' ); ?>>
								<?php esc_html_e( 'Custom — choose case by case', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'What to do when geolocation fails. Hybrid is recommended for most sites: the user is let through if they explicitly denied permission or their browser cannot run geolocation, but technical failures (timeout, location unavailable) keep the form locked.', 'ffcertificate' ); ?>
						</p>

						<?php
						// The HTML `hidden` attribute is too weak vs. the WP admin
						// CSS for `.widefat` (which forces display:table at author
						// origin and wins the cascade), so initial visibility is
						// driven by inline display:none instead. The companion JS
						// toggles inline display via jQuery `.toggle()`.
						$table_style = 'margin-top: 12px; max-width: 720px;';
						if ( 'custom' !== $fallback_preset ) {
							$table_style .= ' display: none;';
						}
						?>
						<table class="ffc-gps-fallback-cases widefat striped" style="<?php echo esc_attr( $table_style ); ?>">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Failure case', 'ffcertificate' ); ?></th>
									<th scope="col" class="ffc-set-text-center"><?php esc_html_e( 'Allow', 'ffcertificate' ); ?></th>
									<th scope="col" class="ffc-set-text-center"><?php esc_html_e( 'Block', 'ffcertificate' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $case_labels as $case_key => $case_label ) : ?>
									<?php $case_value = $fallback_cases[ $case_key ] ?? 'block'; ?>
									<tr>
										<td><?php echo esc_html( $case_label ); ?></td>
										<td class="ffc-set-text-center">
											<input type="radio"
												name="gps_fallback_cases[<?php echo esc_attr( $case_key ); ?>]"
												value="allow"
												<?php checked( $case_value, 'allow' ); ?>
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s = failure case label */ __( 'Allow access when %s', 'ffcertificate' ), $case_label ) ); ?>">
										</td>
										<td class="ffc-set-text-center">
											<input type="radio"
												name="gps_fallback_cases[<?php echo esc_attr( $case_key ); ?>]"
												value="block"
												<?php checked( $case_value, 'block' ); ?>
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s = failure case label */ __( 'Block access when %s', 'ffcertificate' ), $case_label ) ); ?>">
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>

				<!-- API Failure Fallback (IP-dependent — collapsed when IP API off). -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label for="ffc_api_fallback"><?php esc_html_e( 'When IP API Fails', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="api_fallback" id="ffc_api_fallback">
							<option value="allow" <?php selected( $settings['api_fallback'], 'allow' ); ?>>
								<?php esc_html_e( 'Allow access (assume valid)', 'ffcertificate' ); ?>
							</option>
							<option value="block" <?php selected( $settings['api_fallback'], 'block' ); ?>>
								<?php esc_html_e( 'Block access (assume invalid)', 'ffcertificate' ); ?>
							</option>
							<option value="gps_only" <?php selected( $settings['api_fallback'], 'gps_only' ); ?>>
								<?php esc_html_e( 'Use GPS only (ignore IP validation)', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'What to do when IP geolocation API is unavailable or returns error.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- Both Fail Fallback (IP-dependent — only meaningful when IP API is on). -->
				<tr data-ffc-section="ip_api_full">
					<th scope="row">
						<label for="ffc_both_fail_fallback"><?php esc_html_e( 'When Both GPS & IP Fail', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="both_fail_fallback" id="ffc_both_fail_fallback">
							<option value="allow" <?php selected( $settings['both_fail_fallback'], 'allow' ); ?>>
								<?php esc_html_e( 'Allow access (better UX)', 'ffcertificate' ); ?>
							</option>
							<option value="block" <?php selected( $settings['both_fail_fallback'], 'block' ); ?>>
								<?php esc_html_e( 'Block access (better security)', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'What to do when both GPS and IP geolocation fail (if both are enabled).', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>


		<!-- Admin Bypass Section -->
		<div class="card">
			<h2 class="ffc-icon-lock"><?php esc_html_e( 'Administrator Bypass', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Allow administrators to bypass geofence restrictions for testing and content management.', 'ffcertificate' ); ?>
			</p>

			<table class="form-table" role="presentation"><tbody>
				<!-- Bypass Date/Time -->
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Bypass Date/Time', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'admin_bypass_datetime',
								'id'      => 'ffc_admin_bypass_datetime',
								'checked' => (bool) $settings['admin_bypass_datetime'],
								'label'   => __( 'Administrators bypass date/time restrictions', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'admin_bypass_datetime' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Logged-in administrators can access forms regardless of date/time configuration. A visual message will appear indicating bypass is active.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<!-- Bypass Geolocation -->
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Bypass Geolocation', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'admin_bypass_geo',
								'id'      => 'ffc_admin_bypass_geo',
								'checked' => (bool) $settings['admin_bypass_geo'],
								'label'   => __( 'Administrators bypass geolocation restrictions', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'admin_bypass_geo' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Logged-in administrators can access forms regardless of GPS/IP geolocation configuration. A visual message will appear indicating bypass is active.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>


		<p class="submit">
			<button type="submit" name="ffc_save_geolocation" class="button button-primary">
				<?php esc_html_e( 'Save Changes', 'ffcertificate' ); ?>
			</button>
		</p>
	</form>

	<!-- Information Box -->
	<div class="card">
		<h2 class="ffc-icon-info"><?php esc_html_e( 'How Geolocation Works', 'ffcertificate' ); ?></h2>
		<ul>
			<li>
				<strong><?php esc_html_e( 'GPS (Browser):', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'Uses HTML5 Geolocation API. Requires HTTPS and user permission. Accuracy: 10-50 meters.', 'ffcertificate' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'IP Geolocation:', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'Detects location by IP address on server. No user permission needed. Accuracy: 1-50 km.', 'ffcertificate' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Form Configuration:', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'Each form can be configured individually with allowed areas, dates, and display options.', 'ffcertificate' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Privacy:', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'GPS coordinates are processed client-side only. IP geolocation results are cached temporarily.', 'ffcertificate' ); ?>
			</li>
		</ul>
	</div>
</div>