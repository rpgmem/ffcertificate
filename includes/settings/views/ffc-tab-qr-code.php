<?php
/**
 * QR Code Settings Tab View (#1563)
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.34.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_get_option = \Closure::fromCallable( array( $settings, 'get_option' ) );

// What the shared design partial needs; the generator starts from the same.
$ffc_qr_design    = \FreeFormCertificate\Generators\QrDesign::from_settings();
$ffc_qr_state     = \FreeFormCertificate\Generators\QrDesign::form_state();
$ffc_qr_logo_id   = $ffc_qr_state['logo_id'];
$ffc_qr_gradient  = $ffc_qr_state['gradient'];
$ffc_qr_color_end = $ffc_qr_state['color_end'];
$ffc_qr_name      = static fn( string $key ): string => 'ffc_settings[' . $key . ']';
?>

<div class="ffc-settings-wrap">

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="qr_code">

<!-- QR Code Defaults Card -->
<div class="card">
	<h2 class="ffc-icon-qr"><?php esc_html_e( 'QR Code Defaults', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Default settings for QR Code generation in certificates.', 'ffcertificate' ); ?>
	</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="qr_default_size"><?php esc_html_e( 'Default QR Code Size', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="ffc_settings[qr_default_size]" id="qr_default_size" value="<?php echo esc_attr( $ffcertificate_get_option( 'qr_default_size', 200 ) ); ?>" min="100" max="500" step="10" class="small-text" data-ffc-autosave-key="qr_default_size" required> px
						<p class="description">
							<?php esc_html_e( 'Default size when {{qr_code}} placeholder is used without size parameter. Range: 100-500px.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_default_margin"><?php esc_html_e( 'Default QR Code Margin', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="number" name="ffc_settings[qr_default_margin]" id="qr_default_margin" value="<?php echo esc_attr( $ffcertificate_get_option( 'qr_default_margin', 2 ) ); ?>" min="0" max="10" step="1" class="small-text" data-ffc-autosave-key="qr_default_margin" required>
						<p class="description">
							<?php esc_html_e( 'White space around QR Code in modules. 0 = no margin, higher values = more white space.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_default_error_level"><?php esc_html_e( 'Default Error Correction Level', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						// Read once, outside the options: passing each option's
						// own value as the fallback made every `selected()` fire
						// when the key was absent, and the browser honours the
						// last — so a fresh install showed "H" while the
						// generator used the declared 'M' (#1076).
						$ffcertificate_qr_level = $ffcertificate_get_option( 'qr_default_error_level', 'M' );
						?>
						<select name="ffc_settings[qr_default_error_level]" id="qr_default_error_level" class="regular-text" data-ffc-autosave-key="qr_default_error_level">
							<option value="L" <?php selected( 'L', $ffcertificate_qr_level ); ?>>
								L - <?php esc_html_e( 'Low (7% correction)', 'ffcertificate' ); ?>
							</option>
							<option value="M" <?php selected( 'M', $ffcertificate_qr_level ); ?>>
								M - <?php esc_html_e( 'Medium (15% correction) - Recommended', 'ffcertificate' ); ?>
							</option>
							<option value="Q" <?php selected( 'Q', $ffcertificate_qr_level ); ?>>
								Q - <?php esc_html_e( 'Quartile (25% correction)', 'ffcertificate' ); ?>
							</option>
							<option value="H" <?php selected( 'H', $ffcertificate_qr_level ); ?>>
								H - <?php esc_html_e( 'High (30% correction)', 'ffcertificate' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Higher levels allow more damage to QR Code but create denser patterns.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
</div>

<!-- QR Code Design Card -->
<div class="card" id="ffc-qr-design">
	<h2 class="ffc-icon-palette"><?php esc_html_e( 'QR Code Design', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Shapes and colours of the QR codes the plugin draws. Applies only where it is switched on below; elsewhere the plain black-and-white code is kept.', 'ffcertificate' ); ?>
	</p>

	<div class="ffc-qr-design-layout">
		<div class="ffc-qr-sections">
			<?php require FFC_PLUGIN_DIR . 'templates/admin/qr/design-fields.php'; ?>
		</div>

		<div class="ffc-qr-design-preview">
			<div id="ffc-qr-design-preview" class="ffc-qr-design-preview__image" aria-hidden="true"></div>
			<p id="ffc-qr-design-checks" class="ffc-qr-design-preview__checks" role="status" aria-live="polite"></p>
		</div>
	</div>
</div>

<!-- Where the design applies -->
<div class="card">
	<h2 class="ffc-icon-layout"><?php esc_html_e( 'Apply the design to', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'A designed code is drawn as SVG. Certificates embed it directly; short URL PNG downloads are rasterised by your browser so they match the preview.', 'ffcertificate' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_settings[qr_design_on_certificate]',
							'id'      => 'qr_design_on_certificate',
							'checked' => \FreeFormCertificate\Settings\SettingsReader::get_bool( 'qr_design_on_certificate' ),
							'label'   => __( 'Draw the {{qr_code}} placeholder with this design', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-key' => 'qr_design_on_certificate' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Short URLs', 'ffcertificate' ); ?></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_settings[qr_design_on_short_urls]',
							'id'      => 'qr_design_on_short_urls',
							'checked' => \FreeFormCertificate\Settings\SettingsReader::get_bool( 'qr_design_on_short_urls' ),
							'label'   => __( 'Draw the short URL QR codes (preview and downloads) with this design', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-key' => 'qr_design_on_short_urls' ),
						)
					);
					?>
				</td>
			</tr>
		</tbody>
	</table>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->
