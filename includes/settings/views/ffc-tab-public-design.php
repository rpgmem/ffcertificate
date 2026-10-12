<?php
/**
 * Public Design Settings Tab View
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.37.0
 */

use FreeFormCertificate\Core\PublicDesign;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_design = PublicDesign::from_settings();
$ffc_name   = static fn( string $field ): string => 'ffc_settings[' . PublicDesign::KEYS[ $field ] . ']';
$ffc_colors = array(
	'accent' => __( 'Accent (buttons, links and focus)', 'ffcertificate' ),
	'border' => __( 'Field borders', 'ffcertificate' ),
	'muted'  => __( 'Hints and secondary text', 'ffcertificate' ),
	'error'  => __( 'Errors', 'ffcertificate' ),
);
?>

<div class="ffc-settings-wrap">

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="public_design">

<div class="card">
	<h2 class="ffc-icon-palette"><?php esc_html_e( 'Public Design', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'The look of every screen visitors see: forms, certificate verification, scheduling, audiences, the user dashboard and recruitment. The colours apply to the light theme; in dark mode the plugin keeps its own dark palette, whose contrast is already checked.', 'ffcertificate' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Customise', 'ffcertificate' ); ?></th>
				<td>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => $ffc_name( 'enabled' ),
							'id'      => 'public_design',
							'checked' => $ffc_design->enabled,
							'label'   => __( 'Use the colours, corners, width and font below', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-key' => 'public_design' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Off, the screens keep the plugin\'s own look and the values below are not used. Saving with it off puts them back to their defaults.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<?php foreach ( $ffc_colors as $ffc_field => $ffc_label ) : ?>
			<tr>
				<th scope="row"><label for="public_design_<?php echo esc_attr( $ffc_field ); ?>"><?php echo esc_html( $ffc_label ); ?></label></th>
				<td>
					<input type="color" id="public_design_<?php echo esc_attr( $ffc_field ); ?>" name="<?php echo esc_attr( $ffc_name( $ffc_field ) ); ?>" value="<?php echo esc_attr( $ffc_design->{ $ffc_field } ); ?>">
					<?php
					/* translators: %s: the default colour, #rrggbb. */
					echo ' <code>' . esc_html( sprintf( __( 'Default: %s', 'ffcertificate' ), PublicDesign::DEFAULTS[ $ffc_field ] ) ) . '</code>';
					?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'The text on buttons is chosen for you, black or white, whichever reads best on the accent. A colour that reads poorly on white (below 4.5:1 for text, 3:1 for borders, as WCAG asks) is still saved, with a warning naming it.', 'ffcertificate' ); ?></p>
</div>

<div class="card">
	<h2 class="ffc-icon-layout"><?php esc_html_e( 'Shape and Font', 'ffcertificate' ); ?></h2>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="public_design_radius"><?php esc_html_e( 'Rounded corners', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="public_design_radius" name="<?php echo esc_attr( $ffc_name( 'radius' ) ); ?>" value="<?php echo esc_attr( (string) $ffc_design->radius ); ?>" min="0" max="<?php echo esc_attr( (string) PublicDesign::RADIUS_MAX ); ?>" class="small-text" required> px
					<p class="description">
						<?php
						/* translators: 1: default radius, 2: maximum, in pixels. */
						echo esc_html( sprintf( __( 'Of the fields and buttons; 0 makes them square. Default: %1$d px, up to %2$d.', 'ffcertificate' ), PublicDesign::DEFAULTS['radius'], PublicDesign::RADIUS_MAX ) );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="public_design_width"><?php esc_html_e( 'Form width', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="public_design_width" name="<?php echo esc_attr( $ffc_name( 'width' ) ); ?>" value="<?php echo esc_attr( (string) $ffc_design->width ); ?>" min="<?php echo esc_attr( (string) PublicDesign::WIDTH_MIN ); ?>" max="<?php echo esc_attr( (string) PublicDesign::WIDTH_MAX ); ?>" step="10" class="small-text" required> px
					<p class="description">
						<?php
						/* translators: 1: default width, 2: minimum, 3: maximum, in pixels. */
						echo esc_html( sprintf( __( 'The widest a form or verification card grows; its fields are 150 px narrower. On a narrower screen it fills the space it has. Default: %1$d px, from %2$d to %3$d.', 'ffcertificate' ), PublicDesign::DEFAULTS['width'], PublicDesign::WIDTH_MIN, PublicDesign::WIDTH_MAX ) );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Font', 'ffcertificate' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo esc_attr( $ffc_name( 'font' ) ); ?>" value="theme" <?php checked( $ffc_design->font, 'theme' ); ?>> <?php esc_html_e( 'The site theme\'s', 'ffcertificate' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $ffc_name( 'font' ) ); ?>" value="plugin" <?php checked( $ffc_design->font, 'plugin' ); ?>> <?php esc_html_e( 'The system font, as in the admin screens', 'ffcertificate' ); ?></label>
					</fieldset>
				</td>
			</tr>
		</tbody>
	</table>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->
