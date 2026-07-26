<?php
/**
 * Modules Settings Tab View
 *
 * Per-module enable/disable switches. Each toggle writes its
 * `ffc_settings` slot (SettingsReader::module_option_key()) through the
 * incremental settings-autosave endpoint, and gates the matching bootstrap
 * in Loader::init_plugin(). Every module defaults to ON.
 *
 * @package FFC
 * @since 6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FreeFormCertificate\Settings\SettingsReader;
use FreeFormCertificate\Admin\AdminUI;

// Human-facing label + one-line description per module slug. Keys must match
// SettingsReader::MODULE_SLUGS (the source of truth for order + gating).
$ffc_module_meta = array(
	'certificates'    => array(
		'label' => __( 'Certificates', 'ffcertificate' ),
		'desc'  => __( 'The certificate form post type and public form rendering. Disabling hides the certificate admin and stops public submissions.', 'ffcertificate' ),
		'note'  => __( 'While disabled, the daily expired-ticket cleanup is paused — unredeemed ticket codes of ended forms are no longer purged until the module is re-enabled.', 'ffcertificate' ),
	),
	'audiences'       => array(
		'label' => __( 'Audiences / Scheduling', 'ffcertificate' ),
		'desc'  => __( 'Audience groups and the scheduling admin.', 'ffcertificate' ),
	),
	'self_scheduling' => array(
		'label' => __( 'Self-Scheduling', 'ffcertificate' ),
		'desc'  => __( 'Appointment self-booking and its admin screens.', 'ffcertificate' ),
	),
	'reregistration'  => array(
		'label' => __( 'Reregistration', 'ffcertificate' ),
		'desc'  => __( 'The reregistration campaign flow and its admin.', 'ffcertificate' ),
	),
	'url_shortener'   => array(
		'label' => __( 'URL Shortener', 'ffcertificate' ),
		'desc'  => __( 'Built-in short URLs, redirects and QR codes.', 'ffcertificate' ),
	),
	'recruitment'     => array(
		'label' => __( 'Recruitment', 'ffcertificate' ),
		'desc'  => __( 'Recruitment calls, candidates and the public queue.', 'ffcertificate' ),
	),
);
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-package"><?php esc_html_e( 'Modules', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Enable or disable individual plugin modules. Changes save instantly and take effect on the next page load. Disabling a module hides its screens and stops its runtime, but never deletes its data — re-enable at any time.', 'ffcertificate' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
		<?php foreach ( SettingsReader::MODULE_SLUGS as $ffc_module_slug ) : ?>
			<?php
			$ffc_meta = $ffc_module_meta[ $ffc_module_slug ] ?? array(
				'label' => $ffc_module_slug,
				'desc'  => '',
			);
			$ffc_key  = SettingsReader::module_option_key( $ffc_module_slug );
			/* translators: %s: module name */
			$ffc_confirm = sprintf( __( 'Disable the “%s” module? Its screens are hidden and its runtime stops. No data is deleted — you can re-enable it at any time.', 'ffcertificate' ), $ffc_meta['label'] );
			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_html( $ffc_meta['label'] ); ?></label>
				</th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'name'    => 'ffc_settings[' . $ffc_key . ']',
							'id'      => $ffc_key,
							'checked' => SettingsReader::module_enabled( $ffc_module_slug ),
							'label'   => __( 'Enabled', 'ffcertificate' ),
							'data'    => array(
								'ffc-autosave-key' => $ffc_key,
								'ffc-confirm-off'  => $ffc_confirm,
							),
						)
					);
					?>
					<?php if ( '' !== $ffc_meta['desc'] ) : ?>
						<p class="description"><?php echo esc_html( $ffc_meta['desc'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $ffc_meta['note'] ) ) : ?>
						<p class="description ffc-module-note">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php echo esc_html( $ffc_meta['note'] ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>

</div>
