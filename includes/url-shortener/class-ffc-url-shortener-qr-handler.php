<?php
/**
 * URL Shortener QR Handler
 *
 * Generates QR Codes for short URLs and handles download requests (PNG/SVG).
 * Reuses the existing QRCodeGenerator for PNG output.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;
use FreeFormCertificate\Generators\QRCodeGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for url shortener qr operations.
 */
class UrlShortenerQrHandler {

	use AjaxTrait;

	/**
	 * The ONLY size the QR cache serves.
	 *
	 * The cache exists because of a single caller: the post editor's metabox,
	 * which redraws the same QR every time the screen opens. REST and download
	 * are occasional operator actions, over one URL at a time -- there is no
	 * repetition to amortise, and caching the 901 sizes REST accepts (100 to
	 * 1000) would trade a CPU cost nobody measured for a real storage one.
	 *
	 * @var int
	 */
	public const CACHE_SIZE = 200;

	/**
	 * Versao do envelope gravado na coluna `qr_cache`.
	 *
	 * @var int
	 */
	private const CACHE_FORMAT = 1;

	/*
	 * TWO QR CACHES SHARING ONE POPULAR NAME -- do not confuse them (#1233).
	 *
	 * 1. THIS ONE: `ffc_short_urls.qr_cache`, the short URL's QR. No toggle --
	 *    it writes whenever it receives a `short_code` at the canonical size.
	 *    There is no admin button that clears it: it self-corrects, because an
	 *    envelope that does not match the requested size is discarded on read.
	 *
	 * 2. `ffc_submissions.qr_code_cache`, the certificate's QR, in
	 *    {@see \FreeFormCertificate\Generators\QRCodeGenerator}. Indexed by
	 *    `submission_id`, governed by the `qr_cache_enabled` toggle
	 *    (Settings -> Cache), which is OFF by decision.
	 *
	 * That tab's "Clear All QR Code Cache" button calls
	 * `SubmissionRepository::clearQrCodeCache()` and reaches only (2).
	 */

	/**
	 * Description.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerService $service Service.
	 */
	public function __construct( UrlShortenerService $service ) {
		$this->service = $service;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_ffc_download_qr_png', array( $this, 'handle_download_png' ) );
		add_action( 'wp_ajax_ffc_download_qr_svg', array( $this, 'handle_download_svg' ) );
	}

	/**
	 * Generate a QR Code as base64 PNG, with database caching.
	 *
	 * The cache serves {@see self::CACHE_SIZE} EXCLUSIVELY: any other size goes
	 * straight past it, on read and on write. Until #1233 the key was the
	 * `short_code` alone, ignoring the requested size -- so a REST call with
	 * `size=1000` read the 200px PNG the metabox had written, and, with an
	 * empty cache, wrote 1000px there for the metabox to render inside a 200px
	 * space. Silent content corruption between callers.
	 *
	 * **The order of the fix mattered.** The obvious reading of the defect was
	 * "two callers miss the cache, just pass the `short_code`" -- and it was the
	 * opposite: `handle_download_png()` was the one site that could not be
	 * poisoned, precisely because it omitted the code. Wiring the callers before
	 * fixing the key would have turned the only healthy site into the third sick
	 * one. With the size gate this stops depending on who passes what: a caller
	 * asking for 400 cannot reach the cache even if it tries.
	 *
	 * @param string $url        The URL to encode.
	 * @param int    $size       Image size in pixels.
	 * @param string $short_code Optional short code for cache lookup.
	 * @return string Base64-encoded PNG data.
	 */
	public function generate_qr_base64( string $url, int $size = self::CACHE_SIZE, string $short_code = '' ): string {
		$cacheable = '' !== $short_code && self::CACHE_SIZE === $size;

		if ( $cacheable ) {
			$cached = $this->get_qr_cache( $short_code, $size );
			if ( '' !== $cached ) {
				return $cached;
			}
		}

		$generator = new QRCodeGenerator();

		$base64 = $generator->generate(
			$url,
			array(
				'size'        => $size,
				'margin'      => 2,
				'error_level' => 'M',
			)
		);

		// Persist to cache.
		if ( $cacheable && '' !== $base64 ) {
			$this->set_qr_cache( $short_code, $size, $base64 );
		}

		return $base64;
	}

	/**
	 * Retrieve cached QR code from the ffc_short_urls table.
	 *
	 * Returns '' -- that is, a MISS -- for anything that is not an envelope of
	 * this version declaring exactly the requested size. That covers the upgrade
	 * path with no migration at all: a row written under the old scheme is plain
	 * base64, which never decodes to an ARRAY, so it is discarded and rewritten
	 * in the new format on the first read.
	 *
	 * "Never decodes to an array" is stronger than it looks, because the base64
	 * alphabet produces VALID JSON in some cases: a digits-only payload decodes
	 * to a number, and a four-character one can literally be `true`. What
	 * guarantees the miss in those cases is not the `is_array()` -- it is the
	 * envelope validation just below, which refuses anything without a coherent
	 * `v`, `size` and `png`. Measured: swapping the `is_array()` for a
	 * `null === $payload` keeps all three cases a miss.
	 *
	 * The `is_array()` is here as a TYPE guard, not a content one: without it,
	 * `12345['v']` emits "Trying to access array offset on value of type int" on
	 * every read of an old row. Do not remove it thinking it is redundant --
	 * what it avoids is the warning, not the wrong cache.
	 *
	 * It is also what makes {@see self::CACHE_SIZE} safe to change: the rows
	 * written under the previous value start failing the size check and are
	 * discarded rather than served.
	 *
	 * @param string $short_code Short code.
	 * @param int    $size       The required size, in pixels.
	 * @return string Base64 data or empty string.
	 */
	private function get_qr_cache( string $short_code, int $size ): string {
		$raw = $this->service->get_repository()->findQrCacheByShortCode( $short_code );
		if ( '' === $raw ) {
			return '';
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$version = $payload['v'] ?? null;
		$stored  = $payload['size'] ?? null;
		$png     = $payload['png'] ?? null;

		if ( self::CACHE_FORMAT !== $version || $size !== $stored || ! is_string( $png ) || '' === $png ) {
			return '';
		}

		return $png;
	}

	/**
	 * Store QR code cache in the ffc_short_urls table.
	 *
	 * Stores the size ALONGSIDE the PNG. The alternative -- a `qr_cache_size`
	 * column -- would say the same thing at the cost of a schema change that
	 * crosses `SchemaAgreementTest`, the `dbDelta` idempotence gate and the
	 * `uninstall.php` manifest, with nothing beyond this cache reading the
	 * value.
	 *
	 * @param string $short_code Short code.
	 * @param int    $size       The PNG's size, in pixels.
	 * @param string $base64     Base64-encoded PNG.
	 */
	private function set_qr_cache( string $short_code, int $size, string $base64 ): void {
		$payload = wp_json_encode(
			array(
				'v'    => self::CACHE_FORMAT,
				'size' => $size,
				'png'  => $base64,
			)
		);

		if ( ! is_string( $payload ) ) {
			return;
		}

		$this->service->get_repository()->setQrCacheForShortCode( $short_code, $payload );
	}

	/**
	 * Generate a QR Code as SVG string.
	 *
	 * Builds SVG directly from the phpqrcode raw matrix — no PNG
	 * generation, no GD image loading, no pixel-by-pixel scanning.
	 * This eliminates the two most CPU-intensive steps of the old
	 * implementation.
	 *
	 * @param string $url  The URL to encode.
	 * @param int    $size SVG viewBox size.
	 * @return string SVG markup.
	 */
	public function generate_svg( string $url, int $size = 200 ): string {
		// Ensure phpqrcode is loaded.
		if ( ! class_exists( '\\QRcode' ) ) {
			require_once FFC_PLUGIN_DIR . 'libs/phpqrcode/qrlib.php';
		}

		// Get the raw QR matrix directly (no temp files, no GD).
		$matrix = \QRcode::raw( $url, false, QR_ECLEVEL_M );

		if ( empty( $matrix ) ) {
			return '';
		}

		$margin      = 2;
		$matrix_size = count( $matrix );
		$total       = $matrix_size + $margin * 2;
		$module_size = (int) floor( $size / $total );

		if ( $module_size < 1 ) {
			$module_size = 1;
		}

		$svg_size = $module_size * $total;

		$parts   = array();
		$parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$parts[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svg_size . ' ' . $svg_size . '" width="' . $svg_size . '" height="' . $svg_size . '">';
		$parts[] = '<rect width="100%" height="100%" fill="white"/>';

		for ( $y = 0; $y < $matrix_size; $y++ ) {
			$row = $matrix[ $y ];
			for ( $x = 0; $x < $matrix_size; $x++ ) {
				// Each cell is 0 (white) or non-zero (dark module).
				if ( isset( $row[ $x ] ) && $row[ $x ] ) {
					$px      = ( $x + $margin ) * $module_size;
					$py      = ( $y + $margin ) * $module_size;
					$parts[] = '<rect x="' . $px . '" y="' . $py
							. '" width="' . $module_size . '" height="' . $module_size . '" fill="black"/>';
				}
			}
		}

		$parts[] = '</svg>';

		return implode( "\n", $parts );
	}

	/**
	 * Resolve the QR target URL, filename prefix, and short code.
	 *
	 * Always encodes the short URL so that scans are tracked by the
	 * click counter — regardless of whether the request comes from
	 * the post meta box (post_id) or the admin listing (code).
	 *
	 * @return array{url: string, prefix: string, code: string} Target URL, filename prefix and short code.
	 */
	private function resolve_qr_target(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified by the calling method. Cast to int, which sanitises; unslashing a value that becomes an int is a no-op.
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$record = $this->service->get_repository()->findByPostId( $post_id );
			if ( ! $record ) {
				wp_send_json_error( array( 'message' => __( 'Short URL not found for this post.', 'ffcertificate' ) ) );
			}
			$post = get_post( $post_id );
			$slug = $post ? $post->post_name : (string) $post_id;
			return array(
				'url'    => $this->service->get_short_url( $record['short_code'] ),
				'prefix' => 'qr-' . $slug,
				'code'   => $record['short_code'],
			);
		}

		$code = \FreeFormCertificate\Core\RequestInput::get_post_string( 'code' );
		if ( empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid code.', 'ffcertificate' ) ) );
		}

		$record = $this->service->get_repository()->findByShortCode( $code );
		if ( ! $record ) {
			wp_send_json_error( array( 'message' => __( 'Short URL not found.', 'ffcertificate' ) ) );
		}

		return array(
			'url'    => $this->service->get_short_url( $code ),
			'prefix' => 'qr-' . $code,
			'code'   => $code,
		);
	}

	/**
	 * Gate the QR download on the url-shortener *view* tier (#739 §4.2):
	 * admin, the view cap, OR the manage cap. A `manage` role need not also
	 * carry the `view` cap (canView already includes manage), so accepting
	 * either keeps QR download available across the whole url-shortener ladder
	 * instead of the view-only tier. Dies with a JSON error when neither holds.
	 *
	 * @return void
	 */
	private function check_qr_view_permission(): void {
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_url_shortener' )
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_url_shortener' ) ) {
			return;
		}
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
	}

	/**
	 * AJAX: Download QR Code as PNG.
	 */
	public function handle_download_png(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_qr_view_permission();

		$target = $this->resolve_qr_target();
		$base64 = $this->generate_qr_base64( $target['url'], 400 );

		if ( empty( $base64 ) ) {
			wp_send_json_error( array( 'message' => __( 'QR generation failed.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				'data'     => $base64,
				'filename' => $target['prefix'] . '.png',
				'mime'     => 'image/png',
			)
		);
	}

	/**
	 * AJAX: Download QR Code as SVG.
	 */
	public function handle_download_svg(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_qr_view_permission();

		$target = $this->resolve_qr_target();
		$svg    = $this->generate_svg( $target['url'], 400 );

		if ( empty( $svg ) ) {
			wp_send_json_error( array( 'message' => __( 'SVG generation failed.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: encoding SVG for client-side download payload.
				'data'     => base64_encode( $svg ),
				'filename' => $target['prefix'] . '.svg',
				'mime'     => 'image/svg+xml',
			)
		);
	}
}
