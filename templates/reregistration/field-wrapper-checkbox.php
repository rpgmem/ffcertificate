<?php
/**
 * Template: Reregistration frontend checkbox field wrapper.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_field() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $field, $field_id, $field_name, $value, $required, $rules.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
			<div class="ffc-rereg-field" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>"
				data-field-key="<?php echo esc_attr( (string) $field->field_key ); ?>">
				<?php self::render_input( $field, $field_id, $field_name, $value, $required, $rules ); ?>
				<span class="ffc-field-error" role="alert"></span>
			</div>
