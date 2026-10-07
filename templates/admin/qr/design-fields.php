<?php
/**
 * Template: the QR design fields (#1563), shared by Settings → QR Code and
 * the manual generator so the two cannot drift.
 *
 * Emits collapsible `<details>` sections (#1570); the caller wraps them in a
 * `.ffc-qr-sections` container. Every control carries
 * `data-ffc-qr-design="<key>"`, which is what `ffc-qr-design.js` collects;
 * only the `name` differs between the two screens. Shapes are picked from
 * tiles: a radio group whose thumbnails `QrSvgRenderer::swatch()` draws, so a
 * tile shows exactly what the code will look like.
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

use FreeFormCertificate\Core\Icons;
use FreeFormCertificate\Generators\QrSvgRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_qr_shape_labels = array(
	'dots'       => array(
		'square'  => __( 'Square', 'ffcertificate' ),
		'rounded' => __( 'Rounded', 'ffcertificate' ),
		'dots'    => __( 'Dots', 'ffcertificate' ),
		'fluid'   => __( 'Fluid', 'ffcertificate' ),
		'diamond' => __( 'Diamond', 'ffcertificate' ),
		'star'    => __( 'Star', 'ffcertificate' ),
		'cross'   => __( 'Cross', 'ffcertificate' ),
		'heart'   => __( 'Heart', 'ffcertificate' ),
		'x'       => __( 'X', 'ffcertificate' ),
	),
	'eye_frame'  => array(
		'square'  => __( 'Square', 'ffcertificate' ),
		'rounded' => __( 'Rounded', 'ffcertificate' ),
		'circle'  => __( 'Circle', 'ffcertificate' ),
		'leaf'    => __( 'Leaf', 'ffcertificate' ),
		'dotted'  => __( 'Dotted', 'ffcertificate' ),
		'corner'  => __( 'One rounded corner', 'ffcertificate' ),
		'cut'     => __( 'Cut corners', 'ffcertificate' ),
	),
	'eye_ball'   => array(
		'square'  => __( 'Square', 'ffcertificate' ),
		'rounded' => __( 'Rounded', 'ffcertificate' ),
		'circle'  => __( 'Circle', 'ffcertificate' ),
		'diamond' => __( 'Diamond', 'ffcertificate' ),
		'star'    => __( 'Star', 'ffcertificate' ),
		'cross'   => __( 'Cross', 'ffcertificate' ),
		'flower'  => __( 'Flower', 'ffcertificate' ),
	),
	'frame'      => array(
		'none'     => __( 'None', 'ffcertificate' ),
		'banner'   => __( 'Banner below', 'ffcertificate' ),
		'badge'    => __( 'Badge with caption above', 'ffcertificate' ),
		'bubble'   => __( 'Speech bubble above', 'ffcertificate' ),
		'pill'     => __( 'Pill with icon below', 'ffcertificate' ),
		'speech'   => __( 'Outline with pointer and icon', 'ffcertificate' ),
		'circle'   => __( 'Circle with curved caption', 'ffcertificate' ),
		'brackets' => __( 'Corner brackets', 'ffcertificate' ),
		'double'   => __( 'Bands above and below', 'ffcertificate' ),
	),
	'frame_icon' => array(
		'scan'  => __( 'Scan', 'ffcertificate' ),
		'none'  => __( 'None', 'ffcertificate' ),
		'globe' => __( 'Globe', 'ffcertificate' ),
		'url'   => __( 'Link', 'ffcertificate' ),
		'wifi'  => __( 'Wi-Fi', 'ffcertificate' ),
		'phone' => __( 'Phone', 'ffcertificate' ),
	),
);

/**
 * One tile picker: a fieldset of visually hidden radios, each showing the
 * renderer's thumbnail of its value.
 *
 * @param string $ffc_key     Settings key, e.g. qr_design_dots.
 * @param string $ffc_kind    Swatch kind (dots, eye_frame, eye_ball, frame) or frame_icon.
 * @param string $ffc_legend  Visible legend.
 * @param string $ffc_current Selected value.
 */
$ffc_qr_tiles = static function ( string $ffc_key, string $ffc_kind, string $ffc_legend, string $ffc_current ) use ( $ffc_qr_name, $ffc_qr_shape_labels ): void {
	?>
	<fieldset class="ffc-qr-tiles" id="<?php echo esc_attr( $ffc_key ); ?>">
		<legend class="ffc-qr-tiles__legend"><?php echo esc_html( $ffc_legend ); ?></legend>
		<?php foreach ( $ffc_qr_shape_labels[ $ffc_kind ] as $ffc_value => $ffc_label ) : ?>
			<label class="ffc-qr-tile" title="<?php echo esc_attr( $ffc_label ); ?>">
				<input type="radio" class="ffc-qr-tile__input" name="<?php echo esc_attr( $ffc_qr_name( $ffc_key ) ); ?>" value="<?php echo esc_attr( $ffc_value ); ?>" <?php checked( $ffc_value, $ffc_current ); ?> data-ffc-qr-design="<?php echo esc_attr( $ffc_key ); ?>">
				<span class="ffc-qr-tile__face">
					<?php
					// A frame icon shows the icon itself: the FRAME_ICONS names are
					// Icons names, 'none' being the empty-set symbol.
					echo 'frame_icon' === $ffc_kind
						? Icons::svg( $ffc_value, 28 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup.
						: QrSvgRenderer::swatch( $ffc_kind, $ffc_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by the renderer from allowlisted shape names and constant colours; wp_kses would lowercase viewBox and break it.
					?>
					<span class="screen-reader-text"><?php echo esc_html( $ffc_label ); ?></span>
				</span>
			</label>
		<?php endforeach; ?>
	</fieldset>
	<?php
};

/**
 * One colour field: the native picker, named and collected, beside a hex
 * text box that `ffc-qr-design.js` keeps in step with it. The text box has
 * no name, so only the picker's value is ever posted.
 *
 * @param string $ffc_key   Settings key.
 * @param string $ffc_label Visible label.
 * @param string $ffc_value Current colour.
 */
$ffc_qr_color = static function ( string $ffc_key, string $ffc_label, string $ffc_value ) use ( $ffc_qr_name ): void {
	?>
	<div class="ffc-qr-field">
		<label class="ffc-qr-field__label" for="<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_html( $ffc_label ); ?></label>
		<span class="ffc-qr-color">
			<input type="color" class="ffc-qr-color__swatch" name="<?php echo esc_attr( $ffc_qr_name( $ffc_key ) ); ?>" id="<?php echo esc_attr( $ffc_key ); ?>" value="<?php echo esc_attr( $ffc_value ); ?>" data-ffc-qr-design="<?php echo esc_attr( $ffc_key ); ?>">
			<input type="text" class="ffc-qr-color__hex" value="<?php echo esc_attr( $ffc_value ); ?>" maxlength="7" spellcheck="false" aria-label="<?php echo esc_attr( $ffc_label ); ?> (hex)" data-ffc-qr-hex-for="<?php echo esc_attr( $ffc_key ); ?>">
		</span>
	</div>
	<?php
};

/**
 * Open one collapsible section.
 *
 * @param string $ffc_icon  Icon name.
 * @param string $ffc_title Title.
 * @param string $ffc_hint  One-line description.
 * @param bool   $ffc_open  Whether it starts open.
 */
$ffc_qr_section = static function ( string $ffc_icon, string $ffc_title, string $ffc_hint, bool $ffc_open = false ): void {
	?>
	<details class="ffc-qr-section" <?php echo $ffc_open ? 'open' : ''; ?>>
		<summary class="ffc-qr-section__summary">
			<span class="ffc-qr-section__icon"><?php echo Icons::svg( $ffc_icon, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?></span>
			<span class="ffc-qr-section__text">
				<span class="ffc-qr-section__title"><?php echo esc_html( $ffc_title ); ?></span>
				<span class="ffc-qr-section__hint"><?php echo esc_html( $ffc_hint ); ?></span>
			</span>
		</summary>
		<div class="ffc-qr-section__body">
	<?php
};

// The thumbnail shows the logo that is set, by its attachment id.
$ffc_qr_logo_thumb = $ffc_qr_logo_id > 0 ? (string) wp_get_attachment_image_url( $ffc_qr_logo_id, 'thumbnail' ) : '';

$ffc_qr_section( 'pattern', __( 'Pattern', 'ffcertificate' ), __( 'The shape of the modules that carry the data.', 'ffcertificate' ), true );
$ffc_qr_tiles( 'qr_design_dots', 'dots', __( 'Modules', 'ffcertificate' ), $ffc_qr_design->dots );
?>
		</div>
	</details>

<?php
$ffc_qr_section( 'eyes', __( 'Corners', 'ffcertificate' ), __( 'The three corner markers a reader locks onto first.', 'ffcertificate' ) );
$ffc_qr_tiles( 'qr_design_eye_frame', 'eye_frame', __( 'Corner frame', 'ffcertificate' ), $ffc_qr_design->eye_frame );
$ffc_qr_tiles( 'qr_design_eye_ball', 'eye_ball', __( 'Corner centre', 'ffcertificate' ), $ffc_qr_design->eye_ball );
?>
			<div class="ffc-qr-fields">
				<?php
				$ffc_qr_color( 'qr_design_eye_frame_color', __( 'Corner frame colour', 'ffcertificate' ), $ffc_qr_design->eye_frame_color );
				$ffc_qr_color( 'qr_design_eye_ball_color', __( 'Corner centre colour', 'ffcertificate' ), $ffc_qr_design->eye_ball_color );
				?>
			</div>
		</div>
	</details>

<?php $ffc_qr_section( 'colors', __( 'Colours', 'ffcertificate' ), __( 'Module and background colours, and an optional gradient.', 'ffcertificate' ) ); ?>
			<div class="ffc-qr-fields">
				<?php
				$ffc_qr_color( 'qr_design_color', __( 'Module colour', 'ffcertificate' ), $ffc_qr_design->color );
				$ffc_qr_color( 'qr_design_background', __( 'Background colour', 'ffcertificate' ), $ffc_qr_design->background );
				?>
				<div class="ffc-qr-field">
					<span class="ffc-qr-field__label"><?php esc_html_e( 'Transparent background', 'ffcertificate' ); ?></span>
					<label class="ffc-qr-field__check">
						<input type="checkbox" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_transparent' ) ); ?>" id="qr_design_transparent" value="1" <?php checked( $ffc_qr_design->transparent ); ?> data-ffc-qr-design="qr_design_transparent">
						<?php esc_html_e( 'Draw no background, for placing the code on a coloured surface', 'ffcertificate' ); ?>
					</label>
				</div>
			</div>
			<div class="ffc-qr-fields">
				<div class="ffc-qr-field">
					<label class="ffc-qr-field__check">
						<input type="checkbox" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_gradient' ) ); ?>" id="qr_design_gradient" value="1" <?php checked( $ffc_qr_gradient ); ?> data-ffc-qr-design="qr_design_gradient">
						<?php esc_html_e( 'Blend the modules diagonally into a second colour', 'ffcertificate' ); ?>
					</label>
				</div>
				<?php $ffc_qr_color( 'qr_design_color_end', __( 'Gradient end colour', 'ffcertificate' ), $ffc_qr_color_end ); ?>
			</div>
		</div>
	</details>

<?php $ffc_qr_section( 'logo', __( 'Logo', 'ffcertificate' ), __( 'An image in the centre of the code.', 'ffcertificate' ) ); ?>
			<div class="ffc-qr-field">
				<img id="ffc-qr-logo-thumb" class="ffc-qr-logo-thumb" src="<?php echo esc_url( $ffc_qr_logo_thumb ); ?>" alt="" <?php echo '' === $ffc_qr_logo_thumb ? 'hidden' : ''; ?>>
				<input type="hidden" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_logo_id' ) ); ?>" id="qr_design_logo_id" value="<?php echo esc_attr( (string) $ffc_qr_logo_id ); ?>" data-ffc-qr-design="qr_design_logo_id">
				<p>
					<button type="button" class="button ffc-media-select" data-ffc-media-target="#qr_design_logo_id" data-ffc-media-value="id" data-ffc-media-thumb="#ffc-qr-logo-thumb"><?php esc_html_e( 'Select image', 'ffcertificate' ); ?></button>
					<button type="button" class="button-link ffc-media-clear" data-ffc-media-target="#qr_design_logo_id" data-ffc-media-value="id" data-ffc-media-thumb="#ffc-qr-logo-thumb"><?php esc_html_e( 'Clear', 'ffcertificate' ); ?></button>
				</p>
				<p class="description">
					<?php esc_html_e( 'PNG, JPEG, WebP or GIF up to 512 KB, drawn in the centre. A logo raises error correction to H so the covered modules can be rebuilt.', 'ffcertificate' ); ?>
				</p>
			</div>
		</div>
	</details>

<?php
$ffc_qr_section( 'frame', __( 'Frame', 'ffcertificate' ), __( 'A printed frame with a short call to action.', 'ffcertificate' ) );
$ffc_qr_tiles( 'qr_design_frame', 'frame', __( 'Frame', 'ffcertificate' ), $ffc_qr_design->frame );
$ffc_qr_tiles( 'qr_design_frame_icon', 'frame_icon', __( 'Icon (pill and outline frames)', 'ffcertificate' ), $ffc_qr_design->frame_icon );
?>
			<div class="ffc-qr-fields">
				<div class="ffc-qr-field ffc-qr-field--wide">
					<label class="ffc-qr-field__label" for="qr_design_frame_text"><?php esc_html_e( 'Frame caption', 'ffcertificate' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $ffc_qr_name( 'qr_design_frame_text' ) ); ?>" id="qr_design_frame_text" value="<?php echo esc_attr( $ffc_qr_design->frame_text ); ?>" maxlength="<?php echo esc_attr( (string) \FreeFormCertificate\Generators\QrDesign::FRAME_TEXT_MAX ); ?>" class="regular-text" data-ffc-qr-design="qr_design_frame_text">
					<p class="description">
						<?php esc_html_e( 'Up to 24 characters, e.g. "Scan to verify". The caption colour is picked to stay readable on the frame.', 'ffcertificate' ); ?>
					</p>
				</div>
				<?php $ffc_qr_color( 'qr_design_frame_color', __( 'Frame colour', 'ffcertificate' ), $ffc_qr_design->frame_color ); ?>
			</div>
		</div>
	</details>
