<?php
/**
 * Form Editor Device Limit Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Device Limit Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorDeviceLimitMetabox {

	/**
	 * Render Device Fingerprint Limit metabox.
	 *
	 * Composes the master toggle + sub-options. Kept as a single entry
	 * point for any external consumer that hooks `render_box_device_limit`
	 * directly. The form-editor restriction metabox calls
	 * {@see render_master_toggle()} and {@see render_sub_options()}
	 * separately so the master toggle can be inlined in the Form
	 * Restrictions list while sub-options stay in their own collapse
	 * wrapper below.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render( WP_Post $post ): void {
		$this->render_master_toggle( $post );
		$this->render_sub_options( $post );
	}

	/**
	 * Render the master toggle as one row inside the Form Restrictions
	 * list — same `<div class="ffc-restriction-label">` shape used by
	 * the password / allowlist / denylist / ticket toggles.
	 *
	 * When the global Device Fingerprint subsystem (Settings → Rate
	 * Limit → Device Fingerprint) is OFF, the toggle is rendered
	 * `disabled` and the hint text picks up an inline note explaining
	 * how to turn it on. The hidden `ffc_device_limit[present]` carrier
	 * is also emitted here so the save handler can detect that the
	 * device-limit POST namespace was rendered for this form regardless
	 * of toggle state.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_master_toggle( WP_Post $post ): void {
		$enabled       = (string) get_post_meta( $post->ID, '_ffc_device_limit_enabled', true );
		$global_active = $this->is_global_subsystem_active();
		?>
		<input type="hidden" name="ffc_device_limit[present]" value="1">
		<div class="ffc-restriction-label">
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'     => 'ffc_device_limit[enabled]',
					'id'       => 'ffc_device_limit_enabled',
					'checked'  => '1' === (string) $enabled,
					'disabled' => ! $global_active,
					'label'    => __( 'Device Fingerprint Limit', 'ffcertificate' ),
					'data'     => array( 'ffc-autosave-form-key' => 'device_limit_enabled' ),
				)
			);
			?>
			<span class="description">
				— <?php esc_html_e( 'Limit submissions per physical device (cookie + browser fingerprint).', 'ffcertificate' ); ?>
				<?php if ( ! $global_active ) : ?>
					<br>
					<em class="ffc-warning-text">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: link to global rate-limit settings */
								__( 'Disabled globally — enable in %s before configuring per-form overrides.', 'ffcertificate' ),
								'<a href="' . esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=rate_limit' ) ) . '">' . esc_html__( 'Settings → Rate Limit → Device Fingerprint', 'ffcertificate' ) . '</a>'
							),
							array( 'a' => array( 'href' => array() ) )
						);
						?>
					</em>
				<?php elseif ( '1' !== $enabled ) : ?>
					<br>
					<em class="ffc-device-nudge">
						<?php esc_html_e( 'Available for this form but currently off. Leave it off if your audience submits from shared devices (labs, kiosks); turn it on if each person submits from their own device and you want to prevent repeat issuances.', 'ffcertificate' ); ?>
					</em>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the per-form sub-options (max submissions / threshold /
	 * message), wrapped in `.ffc-collapsed-target` so the unified JS
	 * initializer collapses them when the master toggle is off.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_sub_options( WP_Post $post ): void {
		$enabled         = (string) get_post_meta( $post->ID, '_ffc_device_limit_enabled', true );
		$max             = (string) get_post_meta( $post->ID, '_ffc_device_limit_max', true );
		$threshold       = (string) get_post_meta( $post->ID, '_ffc_device_match_threshold', true );
		$strong_min      = (string) get_post_meta( $post->ID, '_ffc_device_strong_min', true );
		$message         = (string) get_post_meta( $post->ID, '_ffc_device_limit_message', true );
		$global_active   = $this->is_global_subsystem_active();
		$global_defaults = $this->get_global_device_defaults();
		$sub_disabled    = ( ! $global_active ) || ( '1' !== $enabled );
		?>
		<div class="ffc-collapsed-target<?php echo $sub_disabled ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_device_limit_enabled"
			aria-hidden="<?php echo $sub_disabled ? 'true' : 'false'; ?>">
		<table class="form-table ffc-device-limit-table">
			<tr class="ffc-device-limit-sub">
				<th scope="row">
					<label for="ffc_device_limit_max"><?php esc_html_e( 'Max submissions per device', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number"
						name="ffc_device_limit[max]"
						id="ffc_device_limit_max"
						min="1"
						max="100"
						value="<?php echo esc_attr( $max ); ?>"
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>">
					<?php
					$this->render_inherit_hint(
						/* translators: %s: highlighted current global default — Max submissions per device. */
						esc_html__( 'Leave empty to inherit the global default — currently %s (Settings → Rate Limit → Device Fingerprint).', 'ffcertificate' ),
						(string) $global_defaults['max']
					);
					?>
				</td>
			</tr>

			<tr class="ffc-device-limit-sub">
				<th scope="row">
					<label for="ffc_device_limit_threshold"><?php esc_html_e( 'Match threshold (3-12)', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number"
						name="ffc_device_limit[threshold]"
						id="ffc_device_limit_threshold"
						min="3"
						max="12"
						value="<?php echo esc_attr( $threshold ); ?>"
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>">
					<?php
					$this->render_inherit_hint(
						/* translators: %s: highlighted current global default — match threshold. */
						esc_html__( 'Lower = more aggressive. Leave empty to inherit the global default — currently %s.', 'ffcertificate' ),
						(string) $global_defaults['threshold']
					);
					?>
				</td>
			</tr>

			<tr class="ffc-device-limit-sub">
				<th scope="row">
					<label for="ffc_device_strong_min"><?php esc_html_e( 'Minimum strong signals (0-6)', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number"
						name="ffc_device_limit[strong_min]"
						id="ffc_device_strong_min"
						min="0"
						max="6"
						value="<?php echo esc_attr( $strong_min ); ?>"
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>">
					<?php
					$this->render_inherit_hint(
						/* translators: %s: highlighted current global default — minimum strong signals. */
						esc_html__( 'How many STRONG signals (canvas, WebGL, audio, fonts, plugins, permissions) must match in addition to the threshold. Higher = fewer false positives but easier to evade; 0 disables the strong tier. Leave empty to inherit the global default — currently %s.', 'ffcertificate' ),
						(string) $global_defaults['strong_min']
					);
					?>
				</td>
			</tr>

			<tr class="ffc-device-limit-sub">
				<th scope="row">
					<label for="ffc_device_limit_message"><?php esc_html_e( 'Block message', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<textarea name="ffc_device_limit[message]"
						id="ffc_device_limit_message"
						rows="2"
						class="large-text"
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>"><?php echo esc_textarea( $message ); ?></textarea>
					<?php
					$ffc_global_msg = '' !== $global_defaults['message']
						? $global_defaults['message']
						: __( '(built-in default)', 'ffcertificate' );
					$this->render_inherit_hint(
						/* translators: %s: highlighted current global default — the block message shown when the device limit is reached. */
						esc_html__( 'Leave empty to inherit the global block message — currently %s.', 'ffcertificate' ),
						(string) $ffc_global_msg
					);
					?>
				</td>
			</tr>
		</table>
		</div><!-- /.ffc-collapsed-target -->
		<?php
	}

	/**
	 * Is the global Device Fingerprint subsystem turned on in
	 * Settings → Rate Limit?
	 */
	private function is_global_subsystem_active(): bool {
		if ( ! class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			return false;
		}
		$rl_settings = \FreeFormCertificate\Security\RateLimiter::get_settings();
		return ! empty( $rl_settings['device']['enabled'] );
	}

	/**
	 * The current global Device Fingerprint defaults (max submissions per
	 * device + match threshold), surfaced in the per-form field descriptions
	 * so the operator sees what an empty field will inherit. Falls back to the
	 * shipped defaults (2 / 7) when the subsystem class isn't available.
	 *
	 * @return array{max: int, threshold: int, strong_min: int, message: string}
	 */
	private function get_global_device_defaults(): array {
		$max    = 2;
		$thr    = 7;
		$strong = 2;
		$msg    = '';
		if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$device = \FreeFormCertificate\Security\RateLimiter::get_settings()['device'] ?? array();
			if ( isset( $device['max_per_form'] ) ) {
				$max = max( 1, (int) $device['max_per_form'] );
			}
			if ( isset( $device['match_threshold'] ) ) {
				$thr = max( 3, min( 12, (int) $device['match_threshold'] ) );
			}
			if ( isset( $device['match_strong_min'] ) ) {
				$strong = max( 0, min( 6, (int) $device['match_strong_min'] ) );
			}
			if ( isset( $device['message'] ) && is_string( $device['message'] ) ) {
				$msg = trim( $device['message'] );
			}
		}
		return array(
			'max'        => $max,
			'threshold'  => $thr,
			'strong_min' => $strong,
			'message'    => $msg,
		);
	}

	/**
	 * Render a field description that shows the value an empty field inherits
	 * from the global Device Fingerprint settings, with the value wrapped in a
	 * subtly-highlighted `.ffc-global-default` span.
	 *
	 * @param string $template Translated sprintf template with a single `%s`
	 *                         placeholder for the highlighted value.
	 * @param string $value    Already-plain (un-escaped) global value to show.
	 */
	private function render_inherit_hint( string $template, string $value ): void {
		echo '<p class="description">';
		echo wp_kses(
			sprintf( $template, '<span class="ffc-global-default">' . esc_html( $value ) . '</span>' ),
			array( 'span' => array( 'class' => array() ) )
		);
		echo '</p>';
	}
}
