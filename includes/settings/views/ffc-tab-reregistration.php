<?php
/**
 * Reregistration Settings Tab View
 *
 * Nested editor for the Divisão → Setor map (division => list of sectors).
 * Renders the current map server-side; `ffc-divisao-setor-editor.js`
 * enhances it (add/remove rows) and keeps the hidden JSON input in sync.
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.7.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_ds_map = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_divisao_setor_map();
if ( ! is_array( $ffc_ds_map ) ) {
	$ffc_ds_map = array();
}

$ffc_ds_json = wp_json_encode( $ffc_ds_map );
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-list"><?php esc_html_e( 'Division → Department Map', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Defines the options for the "Division / Department" dependent dropdown in the reregistration form. Each division holds a list of departments; selecting a division filters the department options. Saving rewrites the dropdown for every audience.', 'ffcertificate' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
		<input type="hidden" name="_ffc_tab" value="reregistration">
		<input type="hidden" id="ffc_ds_map_json" name="ffc_settings[divisao_setor_map_json]"
				value="<?php echo esc_attr( false !== $ffc_ds_json ? $ffc_ds_json : '{}' ); ?>">

		<div class="ffc-ds-editor" data-target="ffc_ds_map_json">
			<div class="ffc-ds-divisions">
				<?php foreach ( $ffc_ds_map as $ffc_ds_division => $ffc_ds_sectors ) : ?>
					<div class="ffc-ds-division">
						<div class="ffc-ds-division-head">
							<input type="text" class="ffc-ds-division-name regular-text"
									value="<?php echo esc_attr( (string) $ffc_ds_division ); ?>"
									placeholder="<?php esc_attr_e( 'Division name', 'ffcertificate' ); ?>">
							<button type="button" class="button button-link-delete ffc-ds-division-remove">
								<?php esc_html_e( 'Remove division', 'ffcertificate' ); ?>
							</button>
						</div>
						<div class="ffc-ds-sectors">
							<?php foreach ( (array) $ffc_ds_sectors as $ffc_ds_sector ) : ?>
								<div class="ffc-ds-sector">
									<input type="text" class="ffc-ds-sector-name regular-text"
											value="<?php echo esc_attr( (string) $ffc_ds_sector ); ?>"
											placeholder="<?php esc_attr_e( 'Department name', 'ffcertificate' ); ?>">
									<button type="button" class="button-link ffc-ds-sector-remove" aria-label="<?php esc_attr_e( 'Remove department', 'ffcertificate' ); ?>">&times;</button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button button-small ffc-ds-sector-add">
							<?php esc_html_e( '+ Add Department', 'ffcertificate' ); ?>
						</button>
					</div>
				<?php endforeach; ?>
			</div>

			<p>
				<button type="button" class="button ffc-ds-division-add">
					<?php esc_html_e( '+ Add Division', 'ffcertificate' ); ?>
				</button>
			</p>
		</div>

		<?php submit_button(); ?>
	</form>
</div>

</div><!-- .ffc-settings-wrap -->
