<?php
/**
 * Template: Reregistration frontend dependent-select cascade.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_dependent_select_field() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $field_id, $field_name, $groups, $parent_label, $child_label, $parent, $child.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<input type="hidden" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>"
				value="
				<?php
				$dep_json = wp_json_encode(
					array(
						'parent' => $parent,
						'child'  => $child,
					)
				);
				echo esc_attr( $dep_json ? $dep_json : '' );
				?>
						">
		<div class="ffc-dependent-select" data-target="<?php echo esc_attr( $field_id ); ?>">
			<div class="ffc-rereg-row ffc-rereg-row-2">
				<div class="ffc-rereg-field">
					<label><?php echo esc_html( (string) $parent_label ); ?></label>
					<select class="ffc-dep-parent">
						<option value=""><?php esc_html_e( 'Select', 'ffcertificate' ); ?></option>
						<?php foreach ( array_keys( $groups ) as $group ) : ?>
							<option value="<?php echo esc_attr( (string) $group ); ?>" <?php selected( $parent, (string) $group ); ?>><?php echo esc_html( (string) $group ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ffc-rereg-field">
					<label><?php echo esc_html( (string) $child_label ); ?></label>
					<select class="ffc-dep-child">
						<option value=""><?php esc_html_e( 'Select', 'ffcertificate' ); ?></option>
						<?php
						if ( '' !== $parent && isset( $groups[ $parent ] ) ) {
							foreach ( $groups[ $parent ] as $child_opt ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( (string) $child_opt ),
									selected( $child, (string) $child_opt, false ),
									esc_html( (string) $child_opt )
								);
							}
						}
						?>
					</select>
				</div>
			</div>
			<script type="application/json" class="ffc-dep-groups"><?php echo wp_json_encode( $groups ); ?></script>
		</div>
