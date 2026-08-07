<?php
/**
 * URL Shortener QR REST Controller
 *
 * Exposes a short URL's QR Code over the REST API for external reuse
 * (e.g. an N8N automation): `GET /ffc/v1/short-urls/{code}/qr`.
 *
 * Feature-owned controller (registered by {@see UrlShortenerLoader}, like the
 * Audience/Recruitment controllers) so it stays inside the UrlShortener module
 * and adds no `API → UrlShortener` boundary edge. Reuses the exact generation +
 * `qr_cache` pipeline of {@see UrlShortenerQrHandler} — no duplication.
 *
 * Auth: cap-gated (`ffc_view_url_shortener`, the read tier), so an Application
 * Password authenticates the call (Basic Auth) — consistent with the operator
 * routes (#858). Never public.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for short-URL QR codes.
 */
class UrlShortenerQrRestController {

	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'ffc/v1';

	/**
	 * Resource base.
	 */
	private const REST_BASE = 'short-urls';

	/**
	 * Service.
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
	 * Register the QR route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<code>[A-Za-z0-9]+)/qr',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_qr' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'code'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'format' => array(
						'type'              => 'string',
						'default'           => 'png',
						'enum'              => array( 'png', 'svg' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'size'   => array(
						'type'              => 'integer',
						'default'           => 300,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission gate: the url-shortener read tier (admin OR the view cap),
	 * satisfiable by an Application Password.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return Capabilities::current_user_can_admin_or( 'ffc_view_url_shortener' );
	}

	/**
	 * Return the QR code of a short URL as base64 (PNG or SVG).
	 *
	 * The QR always encodes the *short* URL (so scans hit the redirect and are
	 * click-counted), matching the admin download.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_qr( \WP_REST_Request $request ) {
		$code   = (string) $request->get_param( 'code' );
		$record = $this->service->get_repository()->findByShortCode( $code );

		if ( ! $record ) {
			return new \WP_Error(
				'ffc_short_url_not_found',
				__( 'Short URL not found.', 'ffcertificate' ),
				array( 'status' => 404 )
			);
		}

		$format    = 'svg' === $request->get_param( 'format' ) ? 'svg' : 'png';
		$size      = max( 100, min( 1000, (int) $request->get_param( 'size' ) ) );
		$short_url = $this->service->get_short_url( $code );
		$qr        = new UrlShortenerQrHandler( $this->service );

		if ( 'svg' === $format ) {
			$svg = $qr->generate_svg( $short_url, $size );
			if ( '' === $svg ) {
				return $this->generation_failed();
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding the SVG for a JSON transport payload, not obfuscation.
			return $this->qr_response( $code, $short_url, 'svg', base64_encode( $svg ), 'image/svg+xml' );
		}

		$base64 = $qr->generate_qr_base64( $short_url, $size, $code );
		if ( '' === $base64 ) {
			return $this->generation_failed();
		}
		return $this->qr_response( $code, $short_url, 'png', $base64, 'image/png' );
	}

	/**
	 * Build the success payload.
	 *
	 * @param string $code      Short code.
	 * @param string $short_url Full short URL.
	 * @param string $format    'png' | 'svg'.
	 * @param string $base64    Base64-encoded image.
	 * @param string $mime      MIME type.
	 * @return \WP_REST_Response
	 */
	private function qr_response( string $code, string $short_url, string $format, string $base64, string $mime ) {
		return rest_ensure_response(
			array(
				'code'        => $code,
				'short_url'   => $short_url,
				'format'      => $format,
				'mime'        => $mime,
				'data_base64' => $base64,
			)
		);
	}

	/**
	 * Standard 500 for a failed generation.
	 *
	 * @return \WP_Error
	 */
	private function generation_failed(): \WP_Error {
		return new \WP_Error(
			'ffc_qr_generation_failed',
			__( 'QR generation failed.', 'ffcertificate' ),
			array( 'status' => 500 )
		);
	}
}
