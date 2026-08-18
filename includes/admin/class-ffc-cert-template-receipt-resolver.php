<?php
/**
 * CertTemplateReceiptResolver
 *
 * Bridges the template pool (#865) and the self-scheduling appointment receipt
 * (#945): when a comprovante PDF is generated, this listener supplies the
 * admin-selected pool template for the calendar's scheduling mode (Regular or
 * Custom), chosen globally in Self-scheduling settings. It hooks the renderer's
 * `ffcertificate_appointment_receipt_template_html` filter, so the Generators
 * layer stays decoupled from the pool (no cross-module reference) and simply
 * falls back to the shipped default file when nothing is configured.
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
 * Resolves the per-mode appointment-receipt template from the pool.
 */
class CertTemplateReceiptResolver {

	/**
	 * Dedicated option storing the per-mode selection: an array
	 * `['regular' => int, 'custom' => int]` of pool template ids (0 = shipped
	 * default). Kept out of the shared `ffc_settings` blob so the standalone
	 * settings page can full-rebuild it without a merge/clobber dance — matching
	 * the geolocation / rate-limit "own option" precedent.
	 */
	public const OPTION = 'ffc_scheduling_receipt_templates';

	/**
	 * Register the receipt-template filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ffcertificate_appointment_receipt_template_html', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * The selected pool template id for a scheduling mode, or 0 when none.
	 *
	 * @param string $schedule_type 'regular' | 'custom'.
	 * @return int
	 */
	public static function selected_id( string $schedule_type ): int {
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			return 0;
		}
		$key = 'custom' === $schedule_type ? 'custom' : 'regular';
		return (int) ( $opt[ $key ] ?? 0 );
	}

	/**
	 * Supply the selected pool template's HTML for the given scheduling mode.
	 *
	 * @param mixed  $html          Current value (empty string unless another
	 *                              listener already supplied HTML).
	 * @param string $schedule_type Calendar scheduling mode ('regular'|'custom').
	 * @return string The pool template HTML, or the incoming value unchanged when
	 *                nothing is configured / the id is not an appointment-receipt
	 *                template (⇒ the renderer falls back to the shipped file).
	 */
	public function resolve( $html, string $schedule_type = 'regular' ): string {
		$current = is_string( $html ) ? $html : '';
		if ( '' !== $current ) {
			return $current; // Respect a template another listener already chose.
		}

		$id = self::selected_id( $schedule_type );
		if ( $id <= 0 ) {
			return $current;
		}

		// Only honour a template that is actually an appointment-receipt kind —
		// guards against a stale id pointing at a deleted/re-typed template.
		if ( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT !== CertTemplateReader::get_kind( $id ) ) {
			return $current;
		}

		$pool_html = CertTemplateReader::get_html( $id );
		return '' !== $pool_html ? $pool_html : $current;
	}
}
