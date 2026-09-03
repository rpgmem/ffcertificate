<?php
/**
 * Template: Reregistration frontend group fieldset.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_group_fieldset() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $index, $label, $fields, $values.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<fieldset class="ffc-rereg-fieldset">
			<legend><?php echo esc_html( sprintf( '%d. %s', $index, $label ) ); ?></legend>
			<?php
			foreach ( $fields as $field ) {
				$key   = (string) $field->field_key;
				$value = $values[ $key ] ?? '';
				self::render_field( $field, $value );
			}
			?>
		</fieldset>
