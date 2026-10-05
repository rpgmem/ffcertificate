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
$ffcertificate_qr_design  = \FreeFormCertificate\Generators\QrDesign::from_settings();

$ffcertificate_qr_shapes = array(
	'qr_design_dots'      => array(
		'label'   => __( 'Modules', 'ffcertificate' ),
		'value'   => $ffcertificate_qr_design->dots,
		'options' => array(
			'square'  => __( 'Square', 'ffcertificate' ),
			'rounded' => __( 'Rounded', 'ffcertificate' ),
			'dots'    => __( 'Dots', 'ffcertificate' ),
			'fluid'   => __( 'Fluid', 'ffcertificate' ),
			'diamond' => __( 'Diamond', 'ffcertificate' ),
		),
	),
	'qr_design_eye_frame' => array(
		'label'   => __( 'Corner frame', 'ffcertificate' ),
		'value'   => $ffcertificate_qr_design->eye_frame,
		'options' => array(
			'square'  => __( 'Square', 'ffcertificate' ),
			'rounded' => __( 'Rounded', 'ffcertificate' ),
			'circle'  => __( 'Circle', 'ffcertificate' ),
			'leaf'    => __( 'Leaf', 'ffcertificate' ),
		),
	),
	'qr_design_eye_ball'  => array(
		'label'   => __( 'Corner centre', 'ffcertificate' ),
		'value'   => $ffcertificate_qr_design->eye_ball,
		'options' => array(
			'square'  => __( 'Square', 'ffcertificate' ),
			'rounded' => __( 'Rounded', 'ffcertificate' ),
			'circle'  => __( 'Circle', 'ffcertificate' ),
			'diamond' => __( 'Diamond', 'ffcertificate' ),
		),
	),
);

$ffcertificate_qr_colors = array(
	'qr_design_color'           => array( __( 'Module colour', 'ffcertificate' ), $ffcertificate_qr_design->color ),
	'qr_design_background'      => array( __( 'Background colour', 'ffcertificate' ), $ffcertificate_qr_design->background ),
	'qr_design_eye_frame_color' => array( __( 'Corner frame colour', 'ffcertificate' ), $ffcertificate_qr_design->eye_frame_color ),
	'qr_design_eye_ball_color'  => array( __( 'Corner centre colour', 'ffcertificate' ), $ffcertificate_qr_design->eye_ball_color ),
);

// The logo is stored as an attachment id; the thumbnail shows what is set.
$ffcertificate_qr_logo_id    = \FreeFormCertificate\Settings\SettingsReader::get_int( 'qr_design_logo_id', 0 );
$ffcertificate_qr_logo_thumb = $ffcertificate_qr_logo_id > 0 ? (string) wp_get_attachment_image_url( $ffcertificate_qr_logo_id, 'thumbnail' ) : '';

$ffcertificate_qr_frames = array(
	'none'   => __( 'None', 'ffcertificate' ),
	'banner' => __( 'Banner below', 'ffcertificate' ),
	'badge'  => __( 'Badge with caption above', 'ffcertificate' ),
	'bubble' => __( 'Speech bubble above', 'ffcertificate' ),
);

// The gradient end is kept while the gradient is off, so switching it back
// on restores the colour instead of resetting it.
$ffcertificate_qr_gradient  = \FreeFormCertificate\Settings\SettingsReader::get_bool( 'qr_design_gradient' );
$ffcertificate_qr_color_end = \FreeFormCertificate\Generators\QrDesign::hex( $ffcertificate_get_option( 'qr_design_color_end', '#2271b1' ), '#2271b1' );
?>

<div class="ffc-settings-wrap">

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="qr_code">

<!-- QR Code Defaults Card -->
<div class="card">
	<h2 class="ffc-icon-phone"><?php esc_html_e( 'QR Code Defaults', 'ffcertificate' ); ?></h2>
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
	<h2 class="ffc-icon-phone"><?php esc_html_e( 'QR Code Design', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Shapes and colours of the QR codes the plugin draws. Applies only where it is switched on below; elsewhere the plain black-and-white code is kept.', 'ffcertificate' ); ?>
	</p>

	<div class="ffc-qr-design-layout">
		<table class="form-table" role="presentation">
			<tbody>
				<?php foreach ( $ffcertificate_qr_shapes as $ffcertificate_key => $ffcertificate_shape ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $ffcertificate_key ); ?>"><?php echo esc_html( $ffcertificate_shape['label'] ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[<?php echo esc_attr( $ffcertificate_key ); ?>]" id="<?php echo esc_attr( $ffcertificate_key ); ?>" data-ffc-qr-design="<?php echo esc_attr( $ffcertificate_key ); ?>">
							<?php foreach ( $ffcertificate_shape['options'] as $ffcertificate_value => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_value ); ?>" <?php selected( $ffcertificate_value, $ffcertificate_shape['value'] ); ?>><?php echo esc_html( $ffcertificate_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>

				<?php foreach ( $ffcertificate_qr_colors as $ffcertificate_key => $ffcertificate_color ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $ffcertificate_key ); ?>"><?php echo esc_html( $ffcertificate_color[0] ); ?></label>
					</th>
					<td>
						<input type="color" name="ffc_settings[<?php echo esc_attr( $ffcertificate_key ); ?>]" id="<?php echo esc_attr( $ffcertificate_key ); ?>" value="<?php echo esc_attr( $ffcertificate_color[1] ); ?>" data-ffc-qr-design="<?php echo esc_attr( $ffcertificate_key ); ?>">
					</td>
				</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row">
						<label for="qr_design_gradient"><?php esc_html_e( 'Gradient', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="ffc_settings[qr_design_gradient]" id="qr_design_gradient" value="1" <?php checked( $ffcertificate_qr_gradient ); ?> data-ffc-qr-design="qr_design_gradient">
							<?php esc_html_e( 'Blend the modules diagonally into a second colour', 'ffcertificate' ); ?>
						</label>
						<br>
						<input type="color" name="ffc_settings[qr_design_color_end]" id="qr_design_color_end" value="<?php echo esc_attr( $ffcertificate_qr_color_end ); ?>" aria-label="<?php esc_attr_e( 'Gradient end colour', 'ffcertificate' ); ?>" data-ffc-qr-design="qr_design_color_end">
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_design_logo_id"><?php esc_html_e( 'Logo', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<img id="ffc-qr-logo-thumb" class="ffc-qr-logo-thumb" src="<?php echo esc_url( $ffcertificate_qr_logo_thumb ); ?>" alt="" <?php echo '' === $ffcertificate_qr_logo_thumb ? 'hidden' : ''; ?>>
						<input type="hidden" name="ffc_settings[qr_design_logo_id]" id="qr_design_logo_id" value="<?php echo esc_attr( (string) $ffcertificate_qr_logo_id ); ?>" data-ffc-qr-design="qr_design_logo_id">
						<button type="button" class="button ffc-media-select" data-ffc-media-target="#qr_design_logo_id" data-ffc-media-value="id" data-ffc-media-thumb="#ffc-qr-logo-thumb"><?php esc_html_e( 'Select image', 'ffcertificate' ); ?></button>
						<button type="button" class="button-link ffc-media-clear" data-ffc-media-target="#qr_design_logo_id" data-ffc-media-value="id" data-ffc-media-thumb="#ffc-qr-logo-thumb"><?php esc_html_e( 'Clear', 'ffcertificate' ); ?></button>
						<p class="description">
							<?php esc_html_e( 'PNG, JPEG, WebP or GIF up to 512 KB, drawn in the centre. A logo raises error correction to H so the covered modules can be rebuilt.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_design_frame"><?php esc_html_e( 'Frame', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="ffc_settings[qr_design_frame]" id="qr_design_frame" data-ffc-qr-design="qr_design_frame">
							<?php foreach ( $ffcertificate_qr_frames as $ffcertificate_value => $ffcertificate_label ) : ?>
								<option value="<?php echo esc_attr( $ffcertificate_value ); ?>" <?php selected( $ffcertificate_value, $ffcertificate_qr_design->frame ); ?>><?php echo esc_html( $ffcertificate_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_design_frame_text"><?php esc_html_e( 'Frame caption', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text" name="ffc_settings[qr_design_frame_text]" id="qr_design_frame_text" value="<?php echo esc_attr( $ffcertificate_qr_design->frame_text ); ?>" maxlength="<?php echo esc_attr( (string) \FreeFormCertificate\Generators\QrDesign::FRAME_TEXT_MAX ); ?>" class="regular-text" data-ffc-qr-design="qr_design_frame_text">
						<input type="color" name="ffc_settings[qr_design_frame_color]" id="qr_design_frame_color" value="<?php echo esc_attr( $ffcertificate_qr_design->frame_color ); ?>" aria-label="<?php esc_attr_e( 'Frame colour', 'ffcertificate' ); ?>" data-ffc-qr-design="qr_design_frame_color">
						<p class="description">
							<?php esc_html_e( 'Up to 24 characters, e.g. "Scan to verify". The caption colour is picked to stay readable on the frame.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<div class="ffc-qr-design-preview">
			<div id="ffc-qr-design-preview" class="ffc-qr-design-preview__image" aria-hidden="true"></div>
			<p id="ffc-qr-design-checks" class="ffc-qr-design-preview__checks" role="status" aria-live="polite"></p>
		</div>
	</div>
</div>

<!-- Where the design applies -->
<div class="card">
	<h2 class="ffc-icon-settings"><?php esc_html_e( 'Apply the design to', 'ffcertificate' ); ?></h2>
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
