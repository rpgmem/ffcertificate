<?php
/**
 * FormBackground
 *
 * The one reader of a form's background image URL. The form editor saves it in
 * `_ffc_form_config['bg_image']`; the top-level `_ffc_form_bg` meta is the
 * legacy location, which nothing writes any more but which older forms still
 * carry. Every consumer — the PDF generator, the form cache, the repository —
 * goes through here, so the two locations cannot disagree about which one a
 * form uses.
 *
 * @package FreeFormCertificate\Core
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the background image URL of a form.
 */
final class FormBackground {

	/**
	 * Background URL for a form: the editor's value first, then the legacy meta.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Image URL, or an empty string when the form has none.
	 */
	public static function url( int $form_id ): string {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );

		if ( is_array( $config ) ) {
			$url = ArrayValue::string( $config, 'bg_image' );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return (string) get_post_meta( $form_id, '_ffc_form_bg', true );
	}
}
