<?php
declare(strict_types=1);

/**
 * URL Shortener QR Handler
 *
 * Generates QR Codes for short URLs and handles download requests (PNG/SVG).
 * Reuses the existing QRCodeGenerator for PNG output.
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;
use FreeFormCertificate\Generators\QRCodeGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlShortenerQrHandler {

	use AjaxTrait;

	/** @var UrlShortenerService */
	private UrlShortenerService $service;

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
	 * When a short_code is provided the result is stored in the
	 * `qr_cache` column of ffc_short_urls so subsequent calls
	 * skip the CPU-intensive phpqrcode + GD pipeline entirely.
	 *
	 * @param string $url        The URL to encode.
	 * @param int    $size       Image size in pixels.
	 * @param string $short_code Optional short code for cache lookup.
	 * @return string Base64-encoded PNG data.
	 */
	public function generate_qr_base64( string $url, int $size = 200, string $short_code = '' ): string {
		// Try cache first.
		if ( '' !== $short_code ) {
			$cached = $this->get_qr_cache( $short_code );
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
		if ( '' !== $short_code && '' !== $base64 ) {
			$this->set_qr_cache( $short_code, $base64 );
		}

		return $base64;
	}

	/**
	 * Retrieve cached QR code from the ffc_short_urls table.
	 *
	 * @param string $short_code Short code.
	 * @return string Base64 data or empty string.
	 */
	private function get_qr_cache( string $short_code ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_short_urls';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT qr_cache FROM %i WHERE short_code = %s', $table, $short_code )
		);

		return is_string( $value ) && '' !== $value ? $value : '';
	}

	/**
	 * Store QR code cache in the ffc_short_urls table.
	 *
	 * @param string $short_code Short code.
	 * @param string $base64     Base64-encoded PNG.
	 */
	private function set_qr_cache( string $short_code, string $base64 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_short_urls';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'qr_cache' => $base64 ),
			array( 'short_code' => $short_code ),
			array( '%s' ),
			array( '%s' )
		);
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

		if ( ! is_array( $matrix ) || empty( $matrix ) ) {
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method (ajax_download_qr/ajax_generate_qr).
		$code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
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
	 * AJAX: Download QR Code as PNG.
	 */
	public function handle_download_png(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission();

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
		$this->check_ajax_permission();

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
