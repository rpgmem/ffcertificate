<?php
/**
 * Template: the QR design fields (#1563), shared by Settings → QR Code and
 * the manual generator so the two cannot drift.
 *
 * Emits `<tr>` rows for a `form-table`. Every control carries
 * `data-ffc-qr-design="<key>"`, which is what `ffc-qr-design.js` collects;
 * only the `name` differs between the two screens.
 *
 * @var \FreeFormCertificate\Generators\QrDesign $ffc_qr_design    Values to show.
 * @var callable(string): string                  $ffc_qr_name      Field name for a key.
 * @var int                                       $ffc_qr_logo_id   Logo attachment id, 0 for none.
 * @var bool                                      $ffc_qr_gradient  Whether the gradient is on.
 * @var string                                    $ffc_qr_color_end Gradient end colour, kept while off.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_qr_shapes = array(
	'qr_design_dots'      => array(
		'label'   => __( 'Modules', 'ffcertificate' ),
		'value'   => $ffc_qr_design->dots,
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
		'value'   => $ffc_qr_design->eye_frame,
		'options' => array(
			'square'  => __( 'Square', 'ffcertificate' ),
			'rounded' => __( 'Rounded', 'ffcertificate' ),
			'circle'  => __( 'Circle', 'ffcertificate' ),
			'leaf'    => __( 'Leaf', 'ffcertificate' ),
		),
	),
	'qr_design_eye_ball'  => array(
		'label'   => __( 'Corner centre', 'ffcertificate' ),
		'value'   => $ffc_qr_design->eye_ball,
		'options' => array(
			'square'  => __( 'Square', 'ffcertificate' ),
			'rounded' => __( 'Rounded', 'ffcertificate' ),
			'circle'  => __( 'Circle', 'ffcertificate' ),
			'diamond' => __( 'Diamond', 'ffcertificate' ),
		),
	),
);

$ffc_qr_colors = array(
	'qr_design_color'           => array( __( 'Module colour', 'ffcertificate' ), $ffc_qr_design->color ),
	'qr_design_background'      => array( __( 'Background colour', 'ffcertificate' ), $ffc_qr_design->background ),
	'qr_design_eye_frame_color' => array( __( 'Corner frame colour', 'ffcertificate' ), $ffc_qr_design->eye_frame_color ),
	'qr_design_eye_ball_color'  => array( __( 'Corner centre colour', 'ffcertificate' ), $ffc_qr_design->eye_ball_color ),
);

// The thumbnail shows the logo that is set, by its attachment id.
$ffc_qr_logo_thumb = $ffc_qr_logo_id > 0 ? (string) wp_get_attachment_image_url( $ffc_qr_logo_id, 'thumbnail' ) : '';

$ffc_qr_frames = array(
	'none'   => __( 'None', 'ffcertificate' ),
	'banner' => __( 'Banner below', 'ffcertificate' ),
	'badge'  => __( 'Badge with caption above', 'ffcertificate' ),
	'bubble' => __( 'Speech bubble above', 'ffcertificate' ),
);
?>
				<?php foreach ( $ffc_qr_shapes as $ffc_key => $ffc_shape ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_html( $ffc_shape['label'] ); ?></label>
					</th>
					<td>
						<select name="<?php echo esc_attr( $ffc_qr_name( $ffc_key ) ); ?>" id="<?php echo esc_attr( $ffc_key ); ?>" data-ffc-qr-design="<?php echo esc_attr( $ffc_key ); ?>">
							<?php foreach ( $ffc_shape['options'] as $ffc_value => $ffc_label ) : ?>
								<option value="<?php echo esc_attr( $ffc_value ); ?>" <?php selected( $ffc_value, $ffc_shape['value'] ); ?>><?php echo esc_html( $ffc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>

				<?php foreach ( $ffc_qr_colors as $ffc_key => $ffc_color ) : ?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_html( $ffc_color[0] ); ?></label>
					</th>
					<td>
						<input type="color" name="<?php echo esc_attr( $ffc_qr_name( $ffc_key ) ); ?>" id="<?php echo esc_attr( $ffc_key ); ?>" value="<?php echo esc_attr( $ffc_color[1] ); ?>" data-ffc-qr-design="<?php echo esc_attr( $ffc_key ); ?>">
					</td>
				</tr>
				<?php endforeach; ?>

				<tr>
					<th scope="row">
						<label for="qr_design_gradient"><?php esc_html_e( 'Gradient', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_gradient' ) ); ?>" id="qr_design_gradient" value="1" <?php checked( $ffc_qr_gradient ); ?> data-ffc-qr-design="qr_design_gradient">
							<?php esc_html_e( 'Blend the modules diagonally into a second colour', 'ffcertificate' ); ?>
						</label>
						<br>
						<input type="color" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_color_end' ) ); ?>" id="qr_design_color_end" value="<?php echo esc_attr( $ffc_qr_color_end ); ?>" aria-label="<?php esc_attr_e( 'Gradient end colour', 'ffcertificate' ); ?>" data-ffc-qr-design="qr_design_color_end">
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_design_logo_id"><?php esc_html_e( 'Logo', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<img id="ffc-qr-logo-thumb" class="ffc-qr-logo-thumb" src="<?php echo esc_url( $ffc_qr_logo_thumb ); ?>" alt="" <?php echo '' === $ffc_qr_logo_thumb ? 'hidden' : ''; ?>>
						<input type="hidden" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_logo_id' ) ); ?>" id="qr_design_logo_id" value="<?php echo esc_attr( (string) $ffc_qr_logo_id ); ?>" data-ffc-qr-design="qr_design_logo_id">
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
						<select name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_frame' ) ); ?>" id="qr_design_frame" data-ffc-qr-design="qr_design_frame">
							<?php foreach ( $ffc_qr_frames as $ffc_value => $ffc_label ) : ?>
								<option value="<?php echo esc_attr( $ffc_value ); ?>" <?php selected( $ffc_value, $ffc_qr_design->frame ); ?>><?php echo esc_html( $ffc_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="qr_design_frame_text"><?php esc_html_e( 'Frame caption', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="text" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_frame_text' ) ); ?>" id="qr_design_frame_text" value="<?php echo esc_attr( $ffc_qr_design->frame_text ); ?>" maxlength="<?php echo esc_attr( (string) \FreeFormCertificate\Generators\QrDesign::FRAME_TEXT_MAX ); ?>" class="regular-text" data-ffc-qr-design="qr_design_frame_text">
						<input type="color" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_frame_color' ) ); ?>" id="qr_design_frame_color" value="<?php echo esc_attr( $ffc_qr_design->frame_color ); ?>" aria-label="<?php esc_attr_e( 'Frame colour', 'ffcertificate' ); ?>" data-ffc-qr-design="qr_design_frame_color">
						<p class="description">
							<?php esc_html_e( 'Up to 24 characters, e.g. "Scan to verify". The caption colour is picked to stay readable on the frame.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
