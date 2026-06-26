<?php
/**
 * FilenameHelper
 *
 * Filename construction helpers sliced out of the Core\Utils god-utility
 * (#563 Sprint 3, B1 phase 1): the download-safe filename sanitiser, the
 * standardized PDF filename builder, and the CSV export filename builder.
 * Pure string helpers with no plugin state.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filename construction helpers.
 */
class FilenameHelper {

	/**
	 * Sanitize filename for safe download
	 *
	 * @param string $filename Original filename.
	 * @return string Sanitized filename
	 */
	public static function sanitize_filename( string $filename ): string {
		// Remove extension temporarily.
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );
		$name      = pathinfo( $filename, PATHINFO_FILENAME );

		// Remove special characters.
		$name = preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $name ) ?? '';

		// Remove multiple dashes.
		$name = preg_replace( '/-+/', '-', $name ) ?? '';

		// Trim dashes from start/end.
		$name = trim( $name, '-' );

		// Lowercase.
		$name = strtolower( $name );

		// Add extension back if it exists.
		if ( $extension ) {
			return $name . '.' . $extension;
		}

		return $name;
	}

	/**
	 * Build a standardized PDF filename for all plugin-generated PDFs.
	 *
	 * Pattern: `{prefix}_{entity_id}_{code}.pdf` — e.g.
	 * `certificado_666_C-MLQQZ9UX9MWF.pdf`. Replaces three divergent
	 * pre-6.6.11 patterns (kebab-lower-{auth}, `appointment-receipt_{code}`,
	 * `Ficha_{Title}_{Display Name}`) with a single consistent shape.
	 *
	 * The prefix is **translatable by default** via `_x()` with the
	 * `pdf filename prefix` context. Sites that need stable filenames for
	 * external automations (DMS regex matching, etc.) can pin a stable
	 * prefix via the `ffcertificate_pdf_filename` filter:
	 *
	 *     add_filter( 'ffcertificate_pdf_filename', function ( $filename, $type, $entity_id, $code ) {
	 *         $stable = array( 'certificate' => 'certificate', 'appointment_receipt' => 'receipt', 'ficha' => 'record' );
	 *         return sprintf( '%s_%d_%s.pdf', $stable[ $type ] ?? $type, $entity_id, $code );
	 *     }, 10, 4 );
	 *
	 * @since 6.6.11
	 *
	 * @param string $type      Internal type slug — one of 'certificate',
	 *                          'appointment_receipt', 'ficha'. Unknown values
	 *                          fall through to the slug itself as prefix.
	 * @param int    $entity_id Form / calendar / reregistration post ID.
	 * @param string $code      Auth code / validation code. Sanitised to
	 *                          [A-Za-z0-9-] and upper-cased. Empty values
	 *                          skip the trailing `_code` segment so the
	 *                          filename ends `_{entity_id}.pdf`.
	 * @return string Final filename WITH `.pdf` extension.
	 */
	public static function build_pdf_filename( string $type, int $entity_id, string $code = '' ): string {
		// Map type → (filename prefix slug, auth-code virtual prefix letter).
		// The letter mirrors `DocumentFormatter::PREFIX_*` constants so the
		// filename auth code matches what `/valid` and emails render
		// (`C-MLQQ-Z9UX-9MWF` displayed → `C-MLQQZ9UX9MWF` in the filename,
		// dashes stripped for filesystem compactness).
		switch ( $type ) {
			case 'certificate':
				$prefix_raw  = _x( 'certificado', 'pdf filename prefix - certificate', 'ffcertificate' );
				$code_prefix = 'C';
				break;
			case 'appointment_receipt':
				$prefix_raw  = _x( 'recibo', 'pdf filename prefix - appointment receipt', 'ffcertificate' );
				$code_prefix = 'A';
				break;
			case 'ficha':
				$prefix_raw  = _x( 'ficha', 'pdf filename prefix - reregistration record', 'ffcertificate' );
				$code_prefix = 'R';
				break;
			default:
				$prefix_raw  = $type;
				$code_prefix = '';
				break;
		}

		// Filesystem-safe prefix: ASCII alnum + dash, lowercase, no leading/trailing dash.
		$prefix = preg_replace( '/[^a-zA-Z0-9\-_]/', '-', $prefix_raw ) ?? '';
		$prefix = preg_replace( '/-+/', '-', $prefix ) ?? '';
		$prefix = trim( $prefix, '-' );
		$prefix = strtolower( $prefix );
		if ( '' === $prefix ) {
			$prefix = 'document';
		}

		$entity_id = max( 0, $entity_id );

		// Code: strip unsafe chars (keep alnum + dash for ranges like
		// `C-MLQQZ9UX9MWF`), upper-case for visual distinction.
		$safe_code = preg_replace( '/[^A-Za-z0-9\-]/', '', $code ) ?? '';
		$safe_code = strtoupper( $safe_code );

		// Attach the virtual document prefix to the auth code when it is
		// a "real" auth code (raw 12-char from DB OR already display-formatted).
		// Synthetic fallbacks like `S{submission_id}` for draft fichas
		// stay un-prefixed — they already encode their nature in the
		// leading `S` and adding `R-` would imply a verifiable auth
		// code where there is none.
		$is_synthetic = '' !== $safe_code && 'S' === substr( $safe_code, 0, 1 ) && ctype_digit( substr( $safe_code, 1 ) );
		if ( '' !== $code_prefix && '' !== $safe_code && ! $is_synthetic ) {
			// Detect whether the caller already passed a virtual prefix
			// like `C-MLQQ-Z9UX-9MWF` (display shape) so we don't
			// double-prefix to `C-C-MLQQZ9UX9MWF`. Then strip ALL inner
			// dashes from the code body for filesystem compactness, and
			// re-prepend the canonical `{LETTER}-` once.
			$already_prefixed = 0 === strpos( $safe_code, $code_prefix . '-' );
			$body             = $already_prefixed ? substr( $safe_code, 2 ) : $safe_code;
			$compact          = str_replace( '-', '', $body );
			$safe_code        = $code_prefix . '-' . $compact;
		}

		$filename = '' !== $safe_code
			? sprintf( '%s_%d_%s.pdf', $prefix, $entity_id, $safe_code )
			: sprintf( '%s_%d.pdf', $prefix, $entity_id );

		/**
		 * Filters the standardized PDF filename produced by all FFC generators.
		 *
		 * Fires for every PDF the plugin generates (certificate, appointment
		 * receipt, ficha). Per-type filters (`ffcertificate_certificate_filename`,
		 * `ffcertificate_ficha_filename`, `ffcertificate_appointment_receipt_filename`)
		 * fire AFTER this one with their original arg shapes preserved for
		 * back-compat — chain accordingly.
		 *
		 * @since 6.6.11
		 *
		 * @param string $filename  The generated `prefix_id_code.pdf` filename.
		 * @param string $type      Internal type slug (certificate / appointment_receipt / ficha).
		 * @param int    $entity_id Form / calendar / reregistration post ID.
		 * @param string $code      Code component after sanitisation (uppercase, no padding).
		 */
		return (string) apply_filters( 'ffcertificate_pdf_filename', $filename, $type, $entity_id, $safe_code );
	}

	/**
	 * Build a CSV export filename of shape `<prefix>[-<title>]-<YYYY-MM-DD>.csv`.
	 *
	 * `$title` (when provided) is passed through `sanitize_file_name()` so
	 * it's safe to include directly in the filename. Date stamp uses UTC
	 * (`gmdate`) — admins downloading the CSV in different timezones
	 * still get a stable, sortable filename.
	 *
	 * @since 6.6.1
	 * @param string      $prefix Required leading segment (e.g. `submissions`).
	 * @param string|null $title  Optional middle segment (form/calendar title).
	 * @return string Filename including `.csv` extension.
	 */
	public static function get_export_filename( string $prefix, ?string $title = null ): string {
		$parts = array( $prefix );
		if ( null !== $title && '' !== $title ) {
			$parts[] = sanitize_file_name( $title );
		}
		$parts[] = gmdate( 'Y-m-d' );
		return implode( '-', $parts ) . '.csv';
	}
}
