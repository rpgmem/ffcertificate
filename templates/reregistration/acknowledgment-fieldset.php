<?php
/**
 * Template: Reregistration frontend acknowledgment fieldset.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_acknowledgment_fieldset() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $index.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

use FreeFormCertificate\Reregistration\ReregistrationFieldOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<fieldset class="ffc-rereg-fieldset">
			<legend><?php echo esc_html( sprintf( '%d. %s', $index, __( 'Acknowledgment', 'ffcertificate' ) ) ); ?></legend>
			<div class="ffc-rereg-termo-text">
				<?php echo wp_kses_post( ReregistrationFieldOptions::get_default_termo_ciencia_html() ); ?>
			</div>
		</fieldset>
