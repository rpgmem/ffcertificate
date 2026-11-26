<?php
/**
 * Template: one audience's custom fields in the dashboard's Edit Profile form
 * (#2850).
 *
 * Editable fields are drawn by the reregistration form's own renderer. A
 * sensitive value is drawn masked and disabled, so it is neither shown nor
 * posted until revealed; a read-only field shows its value and why.
 *
 * Expected in scope: $section (ProfileFieldSection), $by_id (field rows by
 * id), $stored (stored values by field id).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.52.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<fieldset class="ffc-profile-cf-section" data-audience-id="<?php echo esc_attr( (string) $section['audience_id'] ); ?>">
	<legend>
		<span class="ffc-color-dot" style="--ffc-color: <?php echo esc_attr( $section['color'] ); ?>;" aria-hidden="true"></span>
		<?php echo esc_html( $section['name'] ); ?>
	</legend>
	<?php foreach ( $section['fields'] as $view ) : ?>
		<?php
		$field = $by_id[ $view['id'] ] ?? null;
		if ( null === $field ) {
			continue;
		}
		$control_id = 'ffc_rereg_field_' . $view['id'];
		?>
		<?php if ( '' !== $view['read_only'] ) : ?>
			<div class="ffc-rereg-field ffc-profile-cf-readonly" data-field-id="<?php echo esc_attr( (string) $view['id'] ); ?>">
				<span class="ffc-profile-cf-label"><?php echo esc_html( $view['label'] ); ?></span>
				<div class="ffc-field-value"><?php echo esc_html( '' === $view['display'] ? '-' : $view['display'] ); ?></div>
				<p class="ffc-field-hint">
					<?php
					echo esc_html(
						\FreeFormCertificate\Reregistration\ProfileCustomFields::READ_ONLY_IDENTITY === $view['read_only']
							? __( 'Already on your profile. Contact the administrator to change it.', 'ffcertificate' )
							: __( 'Edited with the profile fields above.', 'ffcertificate' )
					);
					?>
				</p>
			</div>
		<?php elseif ( $view['masked'] ) : ?>
			<div class="ffc-rereg-field ffc-profile-cf-masked" data-field-id="<?php echo esc_attr( (string) $view['id'] ); ?>">
				<label for="<?php echo esc_attr( $control_id ); ?>"><?php echo esc_html( $view['label'] ); ?></label>
				<input type="text" id="<?php echo esc_attr( $control_id ); ?>" value="<?php echo esc_attr( $view['display'] ); ?>" disabled>
				<div class="ffc-field-foot">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'    => __( 'Reveal', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'eye',
							'class'    => 'ffc-profile-cf-reveal-btn',
							'attrs'    => array( 'data-field' => 'cf_' . $view['id'] ),
						)
					);
					?>
					<span class="ffc-field-hint"><?php esc_html_e( 'Reveal the value to edit it.', 'ffcertificate' ); ?></span>
				</div>
			</div>
		<?php else : ?>
			<?php \FreeFormCertificate\Reregistration\ReregistrationFormRenderer::render_field( $field, $stored[ $view['id'] ] ?? '' ); ?>
		<?php endif; ?>
	<?php endforeach; ?>
</fieldset>
