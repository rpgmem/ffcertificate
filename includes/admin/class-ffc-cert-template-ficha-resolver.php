<?php
/**
 * CertTemplateFichaResolver
 *
 * Bridges the template pool (#865) and the reregistration ficha (#951 phase 2):
 * when a ficha PDF is generated, this listener supplies the admin-selected pool
 * template (kind `ficha`), chosen globally in Reregistration settings. It hooks
 * the generator's `ffcertificate_ficha_template_html` filter, so the ficha
 * generator stays decoupled from the pool (no cross-module reference) and simply
 * falls back to the bundled default file when nothing is configured.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the global reregistration-ficha template from the pool.
 */
class CertTemplateFichaResolver {

	/**
	 * Dedicated option storing the selected pool template id (0 = bundled
	 * default). Kept out of the shared `ffc_settings` blob so the standalone
	 * settings tab can rebuild it without a merge/clobber dance — matching the
	 * appointment-receipt selection precedent.
	 */
	public const OPTION = 'ffc_reregistration_ficha_template';

	/**
	 * Register the ficha-template filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ffcertificate_ficha_template_html', array( $this, 'resolve' ) );
	}

	/**
	 * The selected pool template id, or 0 when none.
	 *
	 * @return int
	 */
	public static function selected_id(): int {
		$id = get_option( self::OPTION, 0 );
		return is_scalar( $id ) ? (int) $id : 0;
	}

	/**
	 * Supply the selected pool template's HTML for the ficha.
	 *
	 * @param mixed $html Current value (empty string unless another listener
	 *                    already supplied HTML).
	 * @return string The pool template HTML, or the incoming value unchanged when
	 *                nothing is configured / the id is not a ficha template (⇒ the
	 *                generator falls back to the bundled file).
	 */
	public function resolve( $html ): string {
		$current = is_string( $html ) ? $html : '';
		if ( '' !== $current ) {
			return $current; // Respect a template another listener already chose.
		}

		$id = self::selected_id();
		if ( $id <= 0 ) {
			return $current;
		}

		// Only honour a template that is actually a ficha kind — guards against a
		// stale id pointing at a deleted/re-typed template.
		if ( CertTemplateCpt::KIND_FICHA !== CertTemplateReader::get_kind( $id ) ) {
			return $current;
		}

		$pool_html = CertTemplateReader::get_html( $id );
		return '' !== $pool_html ? $pool_html : $current;
	}
}
