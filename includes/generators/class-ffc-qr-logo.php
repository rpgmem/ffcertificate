<?php
/**
 * QR code logo source.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a Media Library image into the data URI a QR SVG embeds (#1563).
 *
 * Embedded, never linked: an SVG drawn through `<img>` -- the certificate,
 * the previews, the browser rasterising a PNG -- is not allowed to load any
 * external resource, so a logo referenced by URL would simply vanish.
 * Only raster images are accepted. An SVG logo would be markup inside our
 * markup, and the size cap keeps one oversized upload from bloating every
 * certificate it is drawn on.
 */
final class QrLogo {

	/** Largest file embedded, in bytes. */
	public const MAX_BYTES = 524288;

	/** Accepted MIME types. */
	public const MIMES = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );

	/**
	 * Data URIs already built in this request, by attachment id.
	 *
	 * @var array<int, string>
	 */
	private static array $memo = array();

	/**
	 * The attachment as a `data:` URI, or '' when it cannot be used.
	 *
	 * @param int $attachment_id Attachment post id; 0 means no logo.
	 * @return string
	 */
	public static function data_uri( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		if ( isset( self::$memo[ $attachment_id ] ) ) {
			return self::$memo[ $attachment_id ];
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		$path = (string) get_attached_file( $attachment_id );
		$uri  = '';

		if ( in_array( $mime, self::MIMES, true ) && '' !== $path && is_readable( $path ) ) {
			$bytes = filesize( $path );
			if ( false !== $bytes && $bytes > 0 && $bytes <= self::MAX_BYTES ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local Media Library file, never a URL.
				$data = file_get_contents( $path );
				if ( false !== $data ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: encoding image bytes for a data URI.
					$uri = 'data:' . $mime . ';base64,' . base64_encode( $data );
				}
			}
		}

		self::$memo[ $attachment_id ] = $uri;
		return $uri;
	}

	/**
	 * Forget the memo (tests, and a logo replaced mid-request).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = array();
	}
}
