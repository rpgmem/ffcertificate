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
		$enabled       = (string) get_post_meta( $post->ID, '_ffc_device_limit_enabled', true );
		$max           = (string) get_post_meta( $post->ID, '_ffc_device_limit_max', true );
		$threshold     = (string) get_post_meta( $post->ID, '_ffc_device_match_threshold', true );
		$message       = (string) get_post_meta( $post->ID, '_ffc_device_limit_message', true );
		$global_active = $this->is_global_subsystem_active();
		$sub_disabled  = ( ! $global_active ) || ( '1' !== $enabled );
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
						placeholder="<?php esc_attr_e( 'Default: 2', 'ffcertificate' ); ?>">
					<p class="description"><?php esc_html_e( 'Defaults to 2 when this metabox is enabled. Override here to set a per-form value.', 'ffcertificate' ); ?></p>
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
					<p class="description"><?php esc_html_e( 'Lower = more aggressive. Leave empty to inherit the global default.', 'ffcertificate' ); ?></p>
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
}
