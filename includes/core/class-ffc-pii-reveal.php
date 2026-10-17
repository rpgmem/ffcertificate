<?php
/**
 * PiiReveal
 *
 * The shared Reveal script and the "Reveal this page" button a list screen
 * draws above its masked values (#1863).
 *
 * @package FreeFormCertificate\Core
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen-side half of the reveal flow; the endpoints live in AdminAjax.
 *
 * The page button reveals every masked value of a list page at once.
 * `assets/js/ffc-pii-reveal.js` collects every per-value Reveal button on the
 * screen and sends them to `ffc_reveal_pii_page`, which checks each against
 * the policy and writes one audit row per module naming the records. The
 * button is drawn only for a user the module's policy lets reveal (the
 * `reveal` tier); a masked-tier user, FFC Read-only included, never sees it.
 */
final class PiiReveal {

	/**
	 * The button markup, or '' when the current user may not reveal the
	 * module's personal data.
	 *
	 * @param string $pii_cap The module reveal cap.
	 * @return string Escaped HTML.
	 */
	public static function page_button( string $pii_cap ): string {
		if ( ! PiiAccessPolicy::can_reveal_any( $pii_cap ) ) {
			return '';
		}

		return sprintf(
			'<p><button type="button" class="button ffc-reveal-pii-page ffc-icon-eye" data-nonce="%1$s">%2$s</button> <span class="description">%3$s</span></p>',
			esc_attr( wp_create_nonce( 'ffc_reveal_pii_nonce' ) ),
			esc_html__( 'Reveal this page', 'ffcertificate' ),
			esc_html__( 'Shows every masked value listed here. The reveal is recorded in the activity log.', 'ffcertificate' )
		);
	}

	/**
	 * Enqueue the shared reveal script (`assets/js/ffc-pii-reveal.js`) and
	 * its strings. Every screen that draws a Reveal button calls this, or
	 * the button has no listener. It depends on `ffc-core`, which provides
	 * `FFC.request`.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$s = AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-pii-reveal',
			FFC_PLUGIN_URL . "assets/js/ffc-pii-reveal{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-pii-reveal',
			'ffcPiiReveal',
			array(
				'reveal_error' => __( 'Unable to reveal this value.', 'ffcertificate' ),
			)
		);
	}
}
