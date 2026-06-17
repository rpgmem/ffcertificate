<?php
/**
 * Tab rate limit
 *
 * @package FreeFormCertificate\Settings\Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file
$ffcertificate_s     = $settings;
$ffcertificate_stats = \FreeFormCertificate\Security\RateLimiter::get_stats();
?>
<div class="ffc-rate-limit-wrap">
<form method="post">
<?php wp_nonce_field( 'ffc_rate_limit_nonce' ); ?>

<div class="card">
	<h2 class="ffc-icon-globe"><?php esc_html_e( 'IP Rate Limit', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'ip_enabled',
				'id'      => 'ip_enabled',
				'checked' => (bool) $ffcertificate_s['ip']['enabled'],
				'label'   => __( 'Enable', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'ip_enabled',
					'ffc-section-master' => 'rl-ip',
				),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-ip"><tbody>
		<tr><th><?php esc_html_e( 'Max per hour', 'ffcertificate' ); ?></th><td><input type="number" name="ip_max_per_hour" value="<?php echo esc_attr( $ffcertificate_s['ip']['max_per_hour'] ); ?>" min="1" max="1000" data-ffc-autosave-key="ip_max_per_hour"></td></tr>
		<tr><th><?php esc_html_e( 'Max per day', 'ffcertificate' ); ?></th><td><input type="number" name="ip_max_per_day" value="<?php echo esc_attr( $ffcertificate_s['ip']['max_per_day'] ); ?>" min="1" max="10000" data-ffc-autosave-key="ip_max_per_day"></td></tr>
		<tr><th><?php esc_html_e( 'Cooldown (sec)', 'ffcertificate' ); ?></th><td><input type="number" name="ip_cooldown_seconds" value="<?php echo esc_attr( $ffcertificate_s['ip']['cooldown_seconds'] ); ?>" min="1" max="3600" data-ffc-autosave-key="ip_cooldown_seconds"></td></tr>
		<tr><th><?php esc_html_e( 'Apply to', 'ffcertificate' ); ?></th><td><select name="ip_apply_to" data-ffc-autosave-key="ip_apply_to"><option value="all"><?php esc_html_e( 'All forms', 'ffcertificate' ); ?></option></select></td></tr>
		<tr><th><?php esc_html_e( 'Message', 'ffcertificate' ); ?></th><td><textarea name="ip_message" rows="3" class="large-text" data-ffc-autosave-key="ip_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( $ffcertificate_s['ip']['message'] ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-email"><?php esc_html_e( 'Email Rate Limit', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'email_enabled',
				'id'      => 'email_enabled',
				'checked' => (bool) $ffcertificate_s['email']['enabled'],
				'label'   => __( 'Enable', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'email_enabled',
					'ffc-section-master' => 'rl-email',
				),
			)
		);
		?>
	</p>
	<p data-ffc-section="rl-email">
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'email_check_database',
				'id'      => 'email_check_database',
				'checked' => (bool) $ffcertificate_s['email']['check_database'],
				'label'   => __( 'Check database', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'email_check_database' ),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-email"><tbody>
		<tr><th><?php esc_html_e( 'Max per day', 'ffcertificate' ); ?></th><td><input type="number" name="email_max_per_day" value="<?php echo esc_attr( $ffcertificate_s['email']['max_per_day'] ); ?>" min="1" data-ffc-autosave-key="email_max_per_day"></td></tr>
		<tr><th><?php esc_html_e( 'Max per week', 'ffcertificate' ); ?></th><td><input type="number" name="email_max_per_week" value="<?php echo esc_attr( $ffcertificate_s['email']['max_per_week'] ); ?>" min="1" data-ffc-autosave-key="email_max_per_week"></td></tr>
		<tr><th><?php esc_html_e( 'Max per month', 'ffcertificate' ); ?></th><td><input type="number" name="email_max_per_month" value="<?php echo esc_attr( $ffcertificate_s['email']['max_per_month'] ); ?>" min="1" data-ffc-autosave-key="email_max_per_month"></td></tr>
		<tr><th><?php esc_html_e( 'Message', 'ffcertificate' ); ?></th><td><textarea name="email_message" rows="3" class="large-text" data-ffc-autosave-key="email_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( $ffcertificate_s['email']['message'] ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-id"><?php esc_html_e( 'Tax ID (CPF) Rate Limit', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'cpf_enabled',
				'id'      => 'cpf_enabled',
				'checked' => (bool) $ffcertificate_s['cpf']['enabled'],
				'label'   => __( 'Enable', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'cpf_enabled',
					'ffc-section-master' => 'rl-cpf',
				),
			)
		);
		?>
	</p>
	<p data-ffc-section="rl-cpf">
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'cpf_check_database',
				'id'      => 'cpf_check_database',
				'checked' => (bool) $ffcertificate_s['cpf']['check_database'],
				'label'   => __( 'Check database', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'cpf_check_database' ),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-cpf"><tbody>
		<tr><th><?php esc_html_e( 'Max per month', 'ffcertificate' ); ?></th><td><input type="number" name="cpf_max_per_month" value="<?php echo esc_attr( $ffcertificate_s['cpf']['max_per_month'] ); ?>" min="1" data-ffc-autosave-key="cpf_max_per_month"></td></tr>
		<tr><th><?php esc_html_e( 'Max per year', 'ffcertificate' ); ?></th><td><input type="number" name="cpf_max_per_year" value="<?php echo esc_attr( $ffcertificate_s['cpf']['max_per_year'] ); ?>" min="1" data-ffc-autosave-key="cpf_max_per_year"></td></tr>
		<tr>
			<th><?php esc_html_e( 'Block after', 'ffcertificate' ); ?></th>
			<td>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %1$s: attempts input field, %2$s: hours input field */
						__( '%1$s attempts in %2$s hour(s)', 'ffcertificate' ),
						'<input type="number" name="cpf_block_threshold" value="' . esc_attr( $ffcertificate_s['cpf']['block_threshold'] ) . '" min="1" data-ffc-autosave-key="cpf_block_threshold">',
						'<input type="number" name="cpf_block_hours" value="' . esc_attr( $ffcertificate_s['cpf']['block_hours'] ) . '" min="1" data-ffc-autosave-key="cpf_block_hours">'
					),
					array(
						'input' => array(
							'type'                  => true,
							'name'                  => true,
							'value'                 => true,
							'min'                   => true,
							'data-ffc-autosave-key' => true,
						),
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Block duration', 'ffcertificate' ); ?></th>
			<td>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %1$s: duration input field */
						__( '%1$s hours', 'ffcertificate' ),
						'<input type="number" name="cpf_block_duration" value="' . esc_attr( $ffcertificate_s['cpf']['block_duration'] ) . '" min="1" data-ffc-autosave-key="cpf_block_duration">'
					),
					array(
						'input' => array(
							'type'                  => true,
							'name'                  => true,
							'value'                 => true,
							'min'                   => true,
							'data-ffc-autosave-key' => true,
						),
					)
				);
				?>
			</td>
		</tr>
		<tr><th><?php esc_html_e( 'Message', 'ffcertificate' ); ?></th><td><textarea name="cpf_message" rows="3" class="large-text" data-ffc-autosave-key="cpf_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( $ffcertificate_s['cpf']['message'] ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-shield"><?php esc_html_e( 'Global Rate Limit', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'global_enabled',
				'id'      => 'global_enabled',
				'checked' => (bool) $ffcertificate_s['global']['enabled'],
				'label'   => __( 'Enable', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'global_enabled',
					'ffc-section-master' => 'rl-global',
				),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-global"><tbody>
		<tr><th><?php esc_html_e( 'Max per minute', 'ffcertificate' ); ?></th><td><input type="number" name="global_max_per_minute" value="<?php echo esc_attr( $ffcertificate_s['global']['max_per_minute'] ); ?>" min="1" data-ffc-autosave-key="global_max_per_minute"></td></tr>
		<tr><th><?php esc_html_e( 'Max per hour', 'ffcertificate' ); ?></th><td><input type="number" name="global_max_per_hour" value="<?php echo esc_attr( $ffcertificate_s['global']['max_per_hour'] ); ?>" min="1" data-ffc-autosave-key="global_max_per_hour"></td></tr>
		<tr><th><?php esc_html_e( 'Message', 'ffcertificate' ); ?></th><td><textarea name="global_message" rows="3" class="large-text" data-ffc-autosave-key="global_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( $ffcertificate_s['global']['message'] ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-shield"><?php esc_html_e( 'Read Endpoints (Public GET)', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Per-endpoint rate limit for public GET endpoints (e.g. the Calendar shortcode\'s /slots lookup). Prevents scraping while leaving submission limits independent.', 'ffcertificate' ); ?>
	</p>
	<table class="form-table" role="presentation"><tbody>
		<tr><th><?php esc_html_e( 'Respect IP whitelist', 'ffcertificate' ); ?></th><td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'    => 'read_respect_whitelist',
					'id'      => 'read_respect_whitelist',
					'checked' => (bool) ( $ffcertificate_s['read']['respect_whitelist'] ?? true ),
					'label'   => __( 'IPs listed in the global Whitelist below bypass the read limit', 'ffcertificate' ),
					'data'    => array( 'ffc-autosave-key' => 'read_respect_whitelist' ),
				)
			);
			?>
		</td></tr>
		<tr><th><?php esc_html_e( 'Bypass for logged-in users', 'ffcertificate' ); ?></th><td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'    => 'read_bypass_logged_in',
					'id'      => 'read_bypass_logged_in',
					'checked' => (bool) ( $ffcertificate_s['read']['bypass_logged_in'] ?? true ),
					'label'   => __( 'Any logged-in WordPress user bypasses the read limit (staff / kiosks signed in to WP)', 'ffcertificate' ),
					'data'    => array( 'ffc-autosave-key' => 'read_bypass_logged_in' ),
				)
			);
			?>
		</td></tr>
		<tr><th><?php esc_html_e( 'Block message', 'ffcertificate' ); ?></th><td>
			<textarea name="read_message" rows="2" class="large-text" data-ffc-autosave-key="read_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( (string) ( $ffcertificate_s['read']['message'] ?? '' ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Shown to blocked callers in the 429 response. {time} is replaced with the wait window.', 'ffcertificate' ); ?></p>
		</td></tr>
	</tbody></table>

	<?php
	$ffc_read_endpoints = array(
		'calendar_slots'  => __( 'Calendar — Slots (GET /calendars/{id}/slots)', 'ffcertificate' ),
		'calendar_list'   => __( 'Calendar — List (GET /calendars)', 'ffcertificate' ),
		'calendar_detail' => __( 'Calendar — Detail (GET /calendars/{id})', 'ffcertificate' ),
	);
	foreach ( $ffc_read_endpoints as $ffc_ep_key => $ffc_ep_label ) :
		$ffc_ep = $ffcertificate_s['read']['endpoints'][ $ffc_ep_key ] ?? array(
			'enabled'        => false,
			'max_per_minute' => 0,
			'max_per_hour'   => 0,
		);
		?>
		<h3 style="margin-top:1.5em;"><?php echo esc_html( $ffc_ep_label ); ?></h3>
		<table class="form-table" role="presentation"><tbody>
			<tr><th><?php esc_html_e( 'Enable', 'ffcertificate' ); ?></th><td>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'read_endpoint_' . $ffc_ep_key . '_enabled',
						'id'      => 'read_endpoint_' . $ffc_ep_key . '_enabled',
						'checked' => (bool) $ffc_ep['enabled'],
						'label'   => __( 'Enforce limit on this endpoint', 'ffcertificate' ),
						'data'    => array( 'ffc-autosave-key' => 'read_endpoint_' . $ffc_ep_key . '_enabled' ),
					)
				);
				?>
			</td></tr>
			<tr><th><?php esc_html_e( 'Max per minute', 'ffcertificate' ); ?></th><td><input type="number" name="read_endpoint_<?php echo esc_attr( $ffc_ep_key ); ?>_max_per_minute" value="<?php echo esc_attr( (string) (int) $ffc_ep['max_per_minute'] ); ?>" min="0" data-ffc-autosave-key="read_endpoint_<?php echo esc_attr( $ffc_ep_key ); ?>_max_per_minute"></td></tr>
			<tr><th><?php esc_html_e( 'Max per hour', 'ffcertificate' ); ?></th><td><input type="number" name="read_endpoint_<?php echo esc_attr( $ffc_ep_key ); ?>_max_per_hour" value="<?php echo esc_attr( (string) (int) $ffc_ep['max_per_hour'] ); ?>" min="0" data-ffc-autosave-key="read_endpoint_<?php echo esc_attr( $ffc_ep_key ); ?>_max_per_hour"></td></tr>
		</tbody></table>
	<?php endforeach; ?>
</div>

<div class="card">
	<h2 class="ffc-icon-shield"><?php esc_html_e( 'Device Fingerprint', 'ffcertificate' ); ?></h2>
	<p class="description" data-ffc-section="rl-device"><?php esc_html_e( 'Limit submissions from the same physical device by combining a persistent cookie with multiple browser signals. Two visits count as the same device when (a) their cookie matches, or (b) they match at least the threshold number of signals AND at least the minimum number of STRONG signals. The strong-signal tier prevents false blocks across same-model devices in homogeneous audiences, where weak signals (browser/OS/screen/timezone) are identical between different people.', 'ffcertificate' ); ?></p>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'device_enabled',
				'id'      => 'device_enabled',
				'checked' => (bool) $ffcertificate_s['device']['enabled'],
				'label'   => __( 'Enable device fingerprint limit', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'device_enabled',
					'ffc-section-master' => 'rl-device',
				),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-device"><tbody>
		<tr>
			<th><?php esc_html_e( 'Max submissions per device/form', 'ffcertificate' ); ?></th>
			<td><input type="number" name="device_max_per_form" value="<?php echo esc_attr( $ffcertificate_s['device']['max_per_form'] ); ?>" min="1" max="100" data-ffc-autosave-key="device_max_per_form">
				<p class="description"><?php esc_html_e( 'Per-form override available in the form metabox.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Match threshold (N of 13)', 'ffcertificate' ); ?></th>
			<td><input type="number" name="device_match_threshold" value="<?php echo esc_attr( $ffcertificate_s['device']['match_threshold'] ); ?>" min="3" max="12" data-ffc-autosave-key="device_match_threshold">
				<p class="description"><?php esc_html_e( 'How many non-cookie signals (strong + weak) must match to consider it the same device. Lower = more aggressive (more false positives). Higher = harder to bypass but easier to evade. The default is 7 of 13.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Minimum strong signals (0-6)', 'ffcertificate' ); ?></th>
			<td><input type="number" name="device_match_strong_min" value="<?php echo esc_attr( $ffcertificate_s['device']['match_strong_min'] ?? 2 ); ?>" min="0" max="6" data-ffc-autosave-key="device_match_strong_min">
				<p class="description">
					<?php esc_html_e( 'On top of the threshold above, at least this many STRONG signals (canvas, WebGL, audio, fonts, plugins, permissions) must match before two visits count as the same device. Strong signals rarely coincide between different physical devices, so requiring them is what stops false blocks across same-model devices in a homogeneous audience.', 'ffcertificate' ); ?>
					<br>
					<strong><?php esc_html_e( 'Consequences of changing this:', 'ffcertificate' ); ?></strong>
					<?php esc_html_e( 'Higher = fewer false positives, but easier to evade (a device only needs to differ on a few strong signals to escape). Lower = closer to the old behavior and more aggressive. 0 disables the strong tier entirely (block on the raw threshold alone — the legacy behavior that over-blocked homogeneous audiences). Submissions that cannot emit this many strong signals (e.g. privacy browsers blocking canvas/WebGL) fall back to the cookie only and are never blocked on weak signals alone. Default is 2.', 'ffcertificate' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Signals collected', 'ffcertificate' ); ?></th>
			<td>
				<?php
				$ffcertificate_signals_options = array(
					'cookie'       => __( 'Persistent cookie (UUID in localStorage)', 'ffcertificate' ),
					'ua'           => __( 'User Agent (browser+OS)', 'ffcertificate' ),
					'screen'       => __( 'Screen size + DPR', 'ffcertificate' ),
					'tz'           => __( 'Timezone', 'ffcertificate' ),
					'concurrency'  => __( 'CPU concurrency', 'ffcertificate' ),
					'memory'       => __( 'Device memory', 'ffcertificate' ),
					'canvas'       => __( 'Canvas hash (GPU/font rendering)', 'ffcertificate' ),
					'audio'        => __( 'AudioContext hash', 'ffcertificate' ),
					'webgl'        => __( 'WebGL renderer/vendor', 'ffcertificate' ),
					'fonts'        => __( 'Installed fonts probe', 'ffcertificate' ),
					'plugins'      => __( 'Browser plugins list', 'ffcertificate' ),
					'permissions'  => __( 'Permissions API state (notifications, camera, etc.)', 'ffcertificate' ),
					'mediaqueries' => __( 'Media queries (color scheme, reduced motion, …)', 'ffcertificate' ),
					'math'         => __( 'Math precision probes (CPU/SO-specific IEEE-754 quirks)', 'ffcertificate' ),
				);

				$ffcertificate_strong_keys = \FreeFormCertificate\Security\RateLimitChecker::STRONG_SIGNALS;

				// Classify each signal into one of three visual groups so the
				// admin can see which signals carry real distinguishing power.
				$ffcertificate_signal_groups = array(
					'cookie' => array(),
					'strong' => array(),
					'weak'   => array(),
				);
				foreach ( $ffcertificate_signals_options as $ffcertificate_sig_key => $ffcertificate_sig_label ) {
					if ( 'cookie' === $ffcertificate_sig_key ) {
						$ffcertificate_signal_groups['cookie'][ $ffcertificate_sig_key ] = $ffcertificate_sig_label;
					} elseif ( in_array( $ffcertificate_sig_key, $ffcertificate_strong_keys, true ) ) {
						$ffcertificate_signal_groups['strong'][ $ffcertificate_sig_key ] = $ffcertificate_sig_label;
					} else {
						$ffcertificate_signal_groups['weak'][ $ffcertificate_sig_key ] = $ffcertificate_sig_label;
					}
				}

				// Inline styles keep the badge self-contained (no CSS rebuild).
				$ffcertificate_badge_styles = array(
					'cookie' => 'background:#cfe2ff;color:#084298;',
					'strong' => 'background:#d1e7dd;color:#0f5132;',
					'weak'   => 'background:#e2e3e5;color:#41464b;',
				);
				$ffcertificate_badge_labels = array(
					'cookie' => __( 'Cookie', 'ffcertificate' ),
					'strong' => __( 'Strong', 'ffcertificate' ),
					'weak'   => __( 'Weak', 'ffcertificate' ),
				);

				$ffcertificate_render_signal = static function ( $key, $label, $group ) use ( $ffcertificate_s, $ffcertificate_badge_styles, $ffcertificate_badge_labels ) {
					$checked = in_array( $key, (array) $ffcertificate_s['device']['signals_enabled'], true );
					echo '<p class="ffc-device-signal-row">';
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'device_signals_enabled[]',
							'id'      => 'device_signal_' . $key,
							'value'   => $key,
							'checked' => $checked,
							'label'   => $label,
							'data'    => array(
								'ffc-autosave-key'   => 'device_signals_enabled',
								'ffc-autosave-multi' => '1',
							),
						)
					);
					printf(
						' <span class="ffc-signal-badge ffc-signal-badge--%1$s" style="%2$s display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:600;vertical-align:middle;">%3$s</span>',
						esc_attr( $group ),
						esc_attr( $ffcertificate_badge_styles[ $group ] ),
						esc_html( $ffcertificate_badge_labels[ $group ] )
					);
					echo '</p>';
				};
				?>

				<?php foreach ( $ffcertificate_signal_groups['cookie'] as $ffcertificate_sig_key => $ffcertificate_sig_label ) : ?>
					<?php $ffcertificate_render_signal( $ffcertificate_sig_key, $ffcertificate_sig_label, 'cookie' ); ?>
				<?php endforeach; ?>

				<p class="ffc-signal-group-heading" style="margin-bottom:2px;"><strong><?php esc_html_e( 'Strong signals', 'ffcertificate' ); ?></strong> — <span class="description"><?php esc_html_e( 'high entropy; hard to coincide between different physical devices and resistant to incognito. The strong-signal minimum is counted from these.', 'ffcertificate' ); ?></span></p>
				<?php foreach ( $ffcertificate_signal_groups['strong'] as $ffcertificate_sig_key => $ffcertificate_sig_label ) : ?>
					<?php $ffcertificate_render_signal( $ffcertificate_sig_key, $ffcertificate_sig_label, 'strong' ); ?>
				<?php endforeach; ?>

				<p class="ffc-signal-group-heading" style="margin-bottom:2px;"><strong><?php esc_html_e( 'Weak signals', 'ffcertificate' ); ?></strong> — <span class="description"><?php esc_html_e( 'low entropy; commonly identical across many devices of the same model/OS/browser, so matching these alone does not indicate the same device.', 'ffcertificate' ); ?></span></p>
				<?php foreach ( $ffcertificate_signal_groups['weak'] as $ffcertificate_sig_key => $ffcertificate_sig_label ) : ?>
					<?php $ffcertificate_render_signal( $ffcertificate_sig_key, $ffcertificate_sig_label, 'weak' ); ?>
				<?php endforeach; ?>

				<p class="description"><?php esc_html_e( 'Disabling strong signals lowers entropy and makes the limit easier to bypass. If you disable so many strong signals that fewer than the configured minimum remain, the strong tier can never be satisfied and only the cookie/threshold will apply.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Bypass for managers', 'ffcertificate' ); ?></th>
			<td>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'device_bypass_logged_in_managers',
						'id'      => 'device_bypass_logged_in_managers',
						'checked' => (bool) $ffcertificate_s['device']['bypass_logged_in_managers'],
						'label'   => __( 'Skip device limit when the submitter is logged in as administrator or has the ffc_manage_settings capability', 'ffcertificate' ),
						'data'    => array( 'ffc-autosave-key' => 'device_bypass_logged_in_managers' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Useful for staff registering certificates for legitimate participants from a single device. Bypassed submissions are tagged in the rate-limit log with reason "manager_bypass".', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Bypass cookie hashes', 'ffcertificate' ); ?></th>
			<td><textarea name="device_bypass_whitelist_signals" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $ffcertificate_s['device']['bypass_whitelist_signals'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One SHA-256 hex digest per line. Submissions whose ffc_device_id cookie hash matches one of these are exempt from the device limit.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Block message', 'ffcertificate' ); ?></th>
			<td><textarea name="device_message" rows="3" class="large-text" data-ffc-autosave-key="device_message" data-ffc-autosave-debounce="800"><?php echo esc_textarea( $ffcertificate_s['device']['message'] ); ?></textarea></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Retention', 'ffcertificate' ); ?></th>
			<td><input type="number" name="device_retention_days" value="<?php echo esc_attr( $ffcertificate_s['device']['retention_days'] ); ?>" min="1" max="3650" data-ffc-autosave-key="device_retention_days"> <?php esc_html_e( 'days', 'ffcertificate' ); ?>
				<p class="description"><?php esc_html_e( 'Older signal rows are purged by the daily cleanup cron.', 'ffcertificate' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Log blocks', 'ffcertificate' ); ?></th>
			<td>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'    => 'device_log_blocks',
						'id'      => 'device_log_blocks',
						'checked' => (bool) $ffcertificate_s['device']['log_blocks'],
						'label'   => __( 'Record blocked device-fingerprint attempts in the rate-limit log', 'ffcertificate' ),
						'data'    => array( 'ffc-autosave-key' => 'device_log_blocks' ),
					)
				);
				?>
			</td>
		</tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-checkmark"><?php esc_html_e( 'Whitelist', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'whitelist_enabled',
				'id'      => 'whitelist_enabled',
				'checked' => ! empty( $ffcertificate_s['whitelist']['enabled'] ),
				'label'   => __( 'Show', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'whitelist_enabled',
					'ffc-section-master' => 'rl-whitelist',
				),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-whitelist"><tbody>
		<tr><th><?php esc_html_e( 'IPs', 'ffcertificate' ); ?></th><td><textarea name="whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['whitelist']['ips'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'One per line', 'ffcertificate' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></th><td><textarea name="whitelist_emails" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['whitelist']['emails'] ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Domains', 'ffcertificate' ); ?></th><td><textarea name="whitelist_email_domains" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['whitelist']['email_domains'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Format: *@domain.com', 'ffcertificate' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Tax IDs (CPFs)', 'ffcertificate' ); ?></th><td><textarea name="whitelist_cpfs" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['whitelist']['cpfs'] ) ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-cross"><?php esc_html_e( 'Blacklist', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'blacklist_enabled',
				'id'      => 'blacklist_enabled',
				'checked' => ! empty( $ffcertificate_s['blacklist']['enabled'] ),
				'label'   => __( 'Show', 'ffcertificate' ),
				'data'    => array(
					'ffc-autosave-key'   => 'blacklist_enabled',
					'ffc-section-master' => 'rl-blacklist',
				),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation" data-ffc-section="rl-blacklist"><tbody>
		<tr><th><?php esc_html_e( 'IPs', 'ffcertificate' ); ?></th><td><textarea name="blacklist_ips" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['blacklist']['ips'] ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Emails', 'ffcertificate' ); ?></th><td><textarea name="blacklist_emails" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['blacklist']['emails'] ) ); ?></textarea></td></tr>
		<tr><th><?php esc_html_e( 'Domains', 'ffcertificate' ); ?></th><td><textarea name="blacklist_email_domains" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['blacklist']['email_domains'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Format: *@domain.com', 'ffcertificate' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'Tax IDs (CPFs)', 'ffcertificate' ); ?></th><td><textarea name="blacklist_cpfs" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", $ffcertificate_s['blacklist']['cpfs'] ) ); ?></textarea></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-clipboard"><?php esc_html_e( 'Logs', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'logging_enabled',
				'checked' => (bool) $ffcertificate_s['logging']['enabled'],
				'label'   => __( 'Enable logs', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'logging_enabled' ),
			)
		);
		?>
	</p>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'logging_log_allowed',
				'checked' => (bool) $ffcertificate_s['logging']['log_allowed'],
				'label'   => __( 'Log allowed requests', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'logging_log_allowed' ),
			)
		);
		?>
	</p>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'logging_log_blocked',
				'checked' => (bool) $ffcertificate_s['logging']['log_blocked'],
				'label'   => __( 'Log blocked requests', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'logging_log_blocked' ),
			)
		);
		?>
	</p>
	<table class="form-table" role="presentation"><tbody>
		<tr><th><?php esc_html_e( 'Retention', 'ffcertificate' ); ?></th><td><input type="number" name="logging_retention_days" value="<?php echo esc_attr( $ffcertificate_s['logging']['retention_days'] ); ?>" min="1" data-ffc-autosave-key="logging_retention_days"> <?php esc_html_e( 'days', 'ffcertificate' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Max logs', 'ffcertificate' ); ?></th><td><input type="number" name="logging_max_logs" value="<?php echo esc_attr( $ffcertificate_s['logging']['max_logs'] ); ?>" min="100" data-ffc-autosave-key="logging_max_logs"></td></tr>
	</tbody></table>
</div>

<div class="card">
	<h2 class="ffc-icon-palette"><?php esc_html_e( 'Interface', 'ffcertificate' ); ?></h2>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'ui_show_remaining',
				'checked' => (bool) $ffcertificate_s['ui']['show_remaining'],
				'label'   => __( 'Show remaining attempts', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'ui_show_remaining' ),
			)
		);
		?>
	</p>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'ui_show_wait_time',
				'checked' => (bool) $ffcertificate_s['ui']['show_wait_time'],
				'label'   => __( 'Show wait time', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'ui_show_wait_time' ),
			)
		);
		?>
	</p>
	<p>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_toggle(
			array(
				'name'    => 'ui_countdown_timer',
				'checked' => (bool) $ffcertificate_s['ui']['countdown_timer'],
				'label'   => __( 'Countdown timer', 'ffcertificate' ),
				'data'    => array( 'ffc-autosave-key' => 'ui_countdown_timer' ),
			)
		);
		?>
	</p>
</div>

<div class="card">
	<h2 class="ffc-icon-chart"><?php esc_html_e( 'Statistics', 'ffcertificate' ); ?></h2>
	<p><strong><?php esc_html_e( 'Blocked today:', 'ffcertificate' ); ?></strong> <?php echo esc_html( number_format( $ffcertificate_stats['today'] ) ); ?></p>
	<p><strong><?php esc_html_e( 'Blocked (30 days):', 'ffcertificate' ); ?></strong> <?php echo esc_html( number_format( $ffcertificate_stats['month'] ) ); ?></p>
<?php if ( ! empty( $ffcertificate_stats['by_type'] ) ) : ?>
	<h3><?php esc_html_e( 'By type:', 'ffcertificate' ); ?></h3>
	<ul><?php foreach ( $ffcertificate_stats['by_type'] as $ffcertificate_t ) : ?>
		<li><?php echo esc_html( $ffcertificate_t['type'] ); ?>: <?php echo esc_html( number_format( $ffcertificate_t['count'] ) ); ?></li>
	<?php endforeach; ?></ul>
<?php endif; ?>
<?php if ( ! empty( $ffcertificate_stats['top_ips'] ) ) : ?>
	<h3><?php esc_html_e( 'Top blocked IPs:', 'ffcertificate' ); ?></h3>
	<ol><?php foreach ( $ffcertificate_stats['top_ips'] as $ffcertificate_ip ) : ?>
		<li><?php echo esc_html( $ffcertificate_ip['identifier'] ); ?> (<?php echo esc_html( number_format( $ffcertificate_ip['count'] ) ); ?>x)</li>
	<?php endforeach; ?></ol>
<?php endif; ?>
</div>

<p class="submit"><input type="submit" name="ffc_save_rate_limit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'ffcertificate' ); ?>"></p>
</form>
</div>
