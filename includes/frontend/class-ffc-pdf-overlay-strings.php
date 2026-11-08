<?php
/**
 * PdfOverlayStrings
 *
 * The texts of the PDF overlay that `assets/js/ffc-pdf-generator.js` reads
 * from `ffc_ajax.strings` (#2526).
 *
 * The certificate form localized them with its own `ffc_ajax`; the personal
 * calendar, the appointment receipt and the audience check-in localized
 * `ffc_ajax` with only the AJAX URL and the nonce, so the overlay fell back to
 * its English literals there. Every page that prints `ffc_ajax` now carries
 * them, from this one list.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One list of the PDF overlay's texts.
 */
final class PdfOverlayStrings {

	/**
	 * The overlay's texts, keyed as the script reads them.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'generatingPdf'          => __( 'Generating PDF...', 'ffcertificate' ),
			'pleaseWait'             => __( 'Please wait, this may take a few seconds...', 'ffcertificate' ),
			'pdfGeneratingTab'       => __( 'Generating your certificate…', 'ffcertificate' ),
			'pdfGeneratingTabHint'   => __( 'Please do not close this tab. The PDF will appear automatically in a few seconds.', 'ffcertificate' ),
			'pdfManualOpenIOS'       => __( 'Tap to open the PDF', 'ffcertificate' ),
			'pdfManualHintIOS'       => __( 'Pop-ups are blocked in this browser. Tap the button below to open the PDF — then use the share icon to save or print.', 'ffcertificate' ),
			'pdfOpenedAndroidTab'    => __( 'PDF opened in a new tab. Use the menu to save or share.', 'ffcertificate' ),
			'pdfOpenedIOS'           => __( 'PDF opened in a new tab. Tap the share icon to save or print.', 'ffcertificate' ),
			'pdfSavedAndroid'        => __( 'PDF saved! Check your Downloads folder.', 'ffcertificate' ),
			'pdfDownloaded'          => __( 'PDF downloaded successfully.', 'ffcertificate' ),
			'pdfBlankWarning'        => __( 'Warning: the generated PDF appears to be blank. Please try again.', 'ffcertificate' ),
			'pdfDesktopFallbackHint' => __( "Didn't download? Click here to open the PDF in a new tab.", 'ffcertificate' ),
			'pdfErrorTitle'          => __( "Couldn't generate the certificate", 'ffcertificate' ),
			'pdfErrorRetry'          => __( 'Try again', 'ffcertificate' ),
			'pdfErrorClose'          => __( 'Close', 'ffcertificate' ),
			'pdfErrorGeneric'        => __( 'Something went wrong while building the PDF. Try again — if it keeps failing, use a different browser or contact the organizer.', 'ffcertificate' ),
			'pdfErrorBlank'          => __( 'The certificate came out blank — usually a slow-loading image. Try again. If it persists, try a different browser or contact the organizer.', 'ffcertificate' ),
			'pdfErrorCors'           => __( 'A certificate image failed to load (cross-origin restriction). This is a configuration problem on the server, not on your device. Please contact the organizer with your authentication code.', 'ffcertificate' ),
			'pdfErrorHtml2canvas'    => __( "We couldn't render the certificate. This usually clears up on a retry; if not, try a different browser.", 'ffcertificate' ),
			'pdfErrorLibrariesBody'  => __( 'A required script blocked or timed out. Reload the page (Ctrl+R / Cmd+R) and try again. If the issue persists, try a different browser.', 'ffcertificate' ),
			'pdfDontCloseTab'        => __( 'Please do not close this tab until the PDF appears.', 'ffcertificate' ),
			'pdfLibrariesFailed'     => __( 'PDF libraries failed to load. Please refresh the page.', 'ffcertificate' ),
			'pdfGenerationError'     => __( 'Error generating PDF (html2canvas). Please try again.', 'ffcertificate' ),
			'pdfContainerNotFound'   => __( 'Error: PDF container not found', 'ffcertificate' ),
			'errorGeneratingPdf'     => __( 'Error generating PDF', 'ffcertificate' ),
			'html2canvasFailed'      => __( 'Error: html2canvas failed', 'ffcertificate' ),
		);
	}
}
