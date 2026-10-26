<?php
/**
 * FormLayoutPreview
 *
 * Builds the preview of a form's certificate layout in the form editor (#1958
 * follow-up). The preview goes through the same renderer as the PDF, so the
 * QR code, the validation link, the background image and the background colour
 * are the ones the certificate will print, not a client-side approximation.
 *
 * @package FreeFormCertificate\Generators
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a layout with sample data, inside the page the PDF prints on.
 */
final class FormLayoutPreview {

	/** Width of the A4 landscape page the certificate prints on, in CSS pixels. */
	public const PAGE_WIDTH = 1123;

	/** Height of the A4 landscape page the certificate prints on, in CSS pixels. */
	public const PAGE_HEIGHT = 794;

	/**
	 * Page size of the certificate for one orientation, in CSS pixels.
	 *
	 * @param bool $portrait True for portrait; landscape otherwise, which is the
	 *                       generator's default when no orientation is set.
	 * @return array{width: int, height: int}
	 */
	public static function page_size( bool $portrait = false ): array {
		return $portrait
			? array(
				'width'  => self::PAGE_HEIGHT,
				'height' => self::PAGE_WIDTH,
			)
			: array(
				'width'  => self::PAGE_WIDTH,
				'height' => self::PAGE_HEIGHT,
			);
	}

	/**
	 * Fills the sample values a form's own fields do not provide: a field's label
	 * stands in for its value, the way the public preview always showed it.
	 *
	 * @param array<string, string>                          $samples Sample values, already complete for the system placeholders.
	 * @param array<int, array{name: string, label: string}> $fields  The form's fields.
	 * @return array<string, string>
	 */
	public static function with_field_labels( array $samples, array $fields ): array {
		foreach ( $fields as $field ) {
			if ( '' !== $field['name'] && empty( $samples[ $field['name'] ] ) ) {
				$samples[ $field['name'] ] = $field['label'];
			}
		}

		return $samples;
	}

	/**
	 * Renders a layout with the given sample values and wraps it in the page.
	 *
	 * @param string                $layout   Layout HTML, already sanitised by the caller.
	 * @param string                $title    Form title shown as the sample form title.
	 * @param string                $bg_image Background image URL, or '' for none.
	 * @param string                $bg_color Background colour as `#rrggbb`.
	 * @param array<string, string> $samples  Placeholder → sample value.
	 * @param bool                  $portrait Whether the certificate is portrait.
	 * @return string The page markup.
	 */
	public static function body( string $layout, string $title, string $bg_image, string $bg_color, array $samples, bool $portrait = false ): string {
		$renderer = new PdfHtmlRenderer();
		$rendered = $renderer->generate_html(
			$samples,
			$title,
			array( 'pdf_layout' => $layout )
		);

		return self::wrap( $rendered, $bg_image, $bg_color, $portrait );
	}

	/**
	 * Places the page colour and the background image behind the body, the way
	 * the PDF generator does: the colour fills the page, the image covers it
	 * with `object-fit: cover`, and the body sits on top of both.
	 *
	 * @param string $body     Rendered body fragment.
	 * @param string $bg_image Background image URL, or '' for none.
	 * @param string $bg_color Background colour as `#rrggbb`, or '' for none.
	 * @param bool   $portrait Whether the certificate is portrait.
	 * @return string The page markup.
	 */
	public static function wrap( string $body, string $bg_image, string $bg_color, bool $portrait = false ): string {
		$size  = self::page_size( $portrait );
		$style = 'position:relative;width:' . $size['width'] . 'px;height:' . $size['height'] . 'px;';

		if ( '' !== $bg_color ) {
			$style .= 'background-color:' . $bg_color . ';';
		}

		$image = '';

		if ( '' !== $bg_image ) {
			$image = '<img src="' . esc_url( $bg_image ) . '" alt="" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;">';
		}

		return '<div style="' . esc_attr( $style ) . '">' . $image . $body . '</div>';
	}
}
