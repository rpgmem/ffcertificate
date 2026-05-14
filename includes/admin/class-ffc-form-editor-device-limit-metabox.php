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
	 * Per-form override layer for the global Rate Limit → Device Fingerprint
	 * settings. Empty fields fall through to the global defaults; the
	 * "Enable for this form" checkbox controls whether the limit applies
	 * to this form at all.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render( WP_Post $post ): void {
		$enabled       = (string) get_post_meta( $post->ID, '_ffc_device_limit_enabled', true );
		$max           = (string) get_post_meta( $post->ID, '_ffc_device_limit_max', true );
		$threshold     = (string) get_post_meta( $post->ID, '_ffc_device_match_threshold', true );
		$message       = (string) get_post_meta( $post->ID, '_ffc_device_limit_message', true );
		$global_active = false;
		if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			$rl_settings   = \FreeFormCertificate\Security\RateLimiter::get_settings();
			$global_active = ! empty( $rl_settings['device']['enabled'] );
		}

		// Two independent gates control whether the sub-fields can be edited:
		// 1. The global Device Fingerprint subsystem (Settings → Rate Limit).
		// When that is OFF, every input including the master checkbox is
		// locked — the warning explains how to enable it.
		// 2. The per-form "Enable for this form" checkbox. When that is OFF
		// (but global is ON), only the secondary fields lock; the
		// checkbox itself stays editable so the user can flip it on.
		$sub_disabled = ( ! $global_active ) || ( '1' !== $enabled );

		$table_classes = array( 'form-table', 'ffc-device-limit-table' );
		if ( ! $global_active ) {
			$table_classes[] = 'ffc-device-limit-globally-off';
		} elseif ( '1' !== $enabled ) {
			$table_classes[] = 'ffc-device-limit-disabled';
		}

		// Nonce is emitted by render_box_layout(), which always renders before this metabox.
		?>
		<p class="description">
			<?php esc_html_e( 'Limit how many times the same physical device can submit this form. Combines a persistent cookie with a multi-signal browser fingerprint and the global "N of M" matching rule.', 'ffcertificate' ); ?>
		</p>
		<?php if ( ! $global_active ) : ?>
			<p class="description ffc-warning-text">
				<strong><?php esc_html_e( 'Note:', 'ffcertificate' ); ?></strong>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: link to global rate-limit settings */
						__( 'The Device Fingerprint subsystem is disabled globally. Enable it in %s before configuring per-form overrides.', 'ffcertificate' ),
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=rate_limit' ) ) . '">' . esc_html__( 'Settings → Rate Limit → Device Fingerprint', 'ffcertificate' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		<?php endif; ?>

		<input type="hidden" name="ffc_device_limit[present]" value="1">
		<table class="<?php echo esc_attr( implode( ' ', $table_classes ) ); ?>">
			<tr>
				<th scope="row">
					<label for="ffc_device_limit_enabled"><?php esc_html_e( 'Enable for this form', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'     => 'ffc_device_limit[enabled]',
							'id'       => 'ffc_device_limit_enabled',
							'checked'  => '1' === (string) $enabled,
							'disabled' => ! $global_active,
							'label'    => __( 'Apply the device-fingerprint limit to this form.', 'ffcertificate' ),
						)
					);
					?>
				</td>
			</tr>

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
						placeholder="<?php esc_attr_e( 'Default: 2', 'ffcertificate' ); ?>"<?php disabled( $sub_disabled ); ?>>
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
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>"<?php disabled( $sub_disabled ); ?>>
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
						placeholder="<?php esc_attr_e( 'Inherit from global', 'ffcertificate' ); ?>"<?php disabled( $sub_disabled ); ?>><?php echo esc_textarea( $message ); ?></textarea>
				</td>
			</tr>
		</table>
		<?php
	}
}
