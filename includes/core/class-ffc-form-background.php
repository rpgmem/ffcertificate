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
	 * Colour a form's page is painted with when no colour is set. White is the
	 * colour a certificate has always printed on; the editor shows it as the
	 * starting value, so an unchanged form keeps its look.
	 */
	public const DEFAULT_COLOR = '#ffffff';

	/**
	 * Reduce a submitted colour to a six-digit hex value, or the default.
	 *
	 * `sanitize_hex_color()` alone would return null for an empty value, and
	 * an empty value is what an unset field posts. Both the save handler and the
	 * reader go through here, so a stored value always reads back the same way.
	 *
	 * @param string $value Raw colour, as submitted or stored.
	 * @return string A `#rrggbb` value, or DEFAULT_COLOR.
	 */
	public static function normalize_color( string $value ): string {
		$color = (string) sanitize_hex_color( $value );

		return '' === $color ? self::DEFAULT_COLOR : $color;
	}

	/**
	 * Page colour for a form: the editor's value, or the default.
	 *
	 * @param int $form_id Form post ID.
	 * @return string A `#rrggbb` value.
	 */
	public static function color( int $form_id ): string {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );

		return is_array( $config )
			? self::normalize_color( ArrayValue::string( $config, 'bg_color' ) )
			: self::DEFAULT_COLOR;
	}

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
