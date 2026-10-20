<?php
/**
 * FormLayoutPreview
 *
 * Builds the preview of a form's certificate layout in the form editor (#1958
 * follow-up). The preview goes through the same renderer as the PDF, so the
 * QR code, the validation link, the background image and the background colour
 * are the ones the certificate will print, not a client-side approximation.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\CertificatePreviewSamples;
use FreeFormCertificate\Generators\PdfHtmlRenderer;

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
	 * Renders a layout with the sample participant and wraps it in the page.
	 *
	 * @param string $layout   Layout HTML, already sanitised by the caller.
	 * @param string $title    Form title shown as the sample form title.
	 * @param string $bg_image Background image URL, or '' for none.
	 * @param string $bg_color Background colour as `#rrggbb`.
	 * @return string The page body fragment.
	 */
	public static function body( string $layout, string $title, string $bg_image, string $bg_color ): string {
		$renderer = new PdfHtmlRenderer();
		$rendered = $renderer->generate_html(
			CertificatePreviewSamples::get_map(),
			$title,
			array( 'pdf_layout' => $layout )
		);

		return self::wrap( $rendered, $bg_image, $bg_color );
	}

	/**
	 * Places the page colour and the background image behind the body, the way
	 * the PDF generator does: the colour fills the page, the image covers it
	 * with `object-fit: cover`, and the body sits on top of both.
	 *
	 * @param string $body     Rendered body fragment.
	 * @param string $bg_image Background image URL, or '' for none.
	 * @param string $bg_color Background colour as `#rrggbb`, or '' for none.
	 * @return string The page markup.
	 */
	public static function wrap( string $body, string $bg_image, string $bg_color ): string {
		$style = 'position:relative;width:' . self::PAGE_WIDTH . 'px;height:' . self::PAGE_HEIGHT . 'px;';

		if ( '' !== $bg_color ) {
			$style .= 'background-color:' . $bg_color . ';';
		}

		$image = '';

		if ( '' !== $bg_image ) {
			$image = '<img src="' . esc_url( $bg_image ) . '" alt="" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;">';
		}

		return '<div style="' . esc_attr( $style ) . '">' . $image . $body . '</div>';
	}

	/**
	 * The complete document the preview frame loads. It carries no margin, so
	 * the page fills the frame that the editor scales to fit.
	 *
	 * @param string $title Title of the document.
	 * @param string $body  Page markup from {@see self::body()}.
	 * @return string
	 */
	public static function document( string $title, string $body ): string {
		return '<!DOCTYPE html><html><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>html,body{margin:0;padding:0;overflow:hidden;}</style>'
			. '</head><body>' . $body . '</body></html>';
	}
}
