<?php
/**
 * Manual QR code generator — AJAX endpoints.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\QrLogo;
use FreeFormCertificate\Generators\QrPayload;
use FreeFormCertificate\Generators\QrSvgRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a manual QR code, and shortens its address on request (#1563).
 *
 * The generator is stateless: drawing writes nothing, so it can follow every
 * keystroke. Shortening is the one write, and it is a separate action fired
 * only by an explicit click -- drawing on each change must never mint a
 * short URL per keystroke.
 */
class QrGeneratorAjaxEndpoint {

	public const ACTION_GENERATE = 'ffc_qr_generate';
	public const ACTION_SHORTEN  = 'ffc_qr_shorten';

	/** Capability for both actions: the generator lives in the shortener's menu. */
	public const CAP = 'ffc_manage_url_shortener';

	/**
	 * Service used to shorten.
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
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_' . self::ACTION_GENERATE, array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_' . self::ACTION_SHORTEN, array( $this, 'handle_shorten' ) );
	}

	/**
	 * Draw the code for the posted content and design.
	 */
	public function handle_generate(): void {
		$this->guard( self::ACTION_GENERATE );

		$type    = sanitize_key( RequestInput::get_post_string( 'type', 'url' ) );
		$payload = QrPayload::build( $type, self::fields() );
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'message' => $payload->get_error_message() ), 400 );
		}

		$input         = RequestInput::get_post_array( 'design' );
		$logo_id       = RequestInput::get_post_int( 'logo_id', 0 );
		$input['logo'] = $logo_id > 0 && current_user_can( 'read_post', $logo_id ) ? QrLogo::data_uri( $logo_id ) : '';
		$design        = new QrDesign( $input );

		$ecc    = strtoupper( RequestInput::get_post_string( 'error_level', 'M' ) );
		$ecc    = $design->error_level( in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ? $ecc : 'M' );
		$margin = max( 0, min( 10, RequestInput::get_post_int( 'margin', 2 ) ) );

		$matrix = QrSvgRenderer::matrix( $payload, $ecc );
		if ( array() === $matrix ) {
			wp_send_json_error(
				array(
					'message' => __( 'This content is too long for a QR code at this error correction level. Shorten it, or lower the level.', 'ffcertificate' ),
					'usage'   => QrPayload::usage( $payload, $ecc, 0 ),
				),
				400
			);
		}

		$drawn = QrSvgRenderer::render_matrix_sized( $matrix, $design, $margin, 1000 );

		wp_send_json_success(
			array(
				'svg'     => $drawn['svg'],
				'width'   => $drawn['width'],
				'height'  => $drawn['height'],
				'payload' => $payload,
				'usage'   => QrPayload::usage( $payload, $ecc, count( $matrix ) ),
				'checks'  => $design->scan_checks(),
			)
		);
	}

	/**
	 * Create a short URL for the posted address and return it.
	 */
	public function handle_shorten(): void {
		$this->guard( self::ACTION_SHORTEN );

		$url = QrPayload::build( 'url', array( 'url' => RequestInput::get_post_string( 'url' ) ) );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ), 400 );
		}

		$result = $this->service->create_short_url( $url, RequestInput::get_post_string( 'title' ) );
		$record = $result['data'] ?? null;
		if ( empty( $result['success'] ) || ! is_array( $record ) || ! isset( $record['short_code'] ) ) {
			$error = (string) ( $result['error'] ?? '' );
			wp_send_json_error( array( 'message' => '' !== $error ? $error : __( 'Could not create the short URL.', 'ffcertificate' ) ) );
		}

		wp_send_json_success(
			array(
				'short_url' => $this->service->get_short_url( (string) $record['short_code'] ),
			)
		);
	}

	/**
	 * Nonce and capability, shared by both actions.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		check_ajax_referer( $action, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * The content fields, unslashed but otherwise as typed.
	 *
	 * They are not run through `sanitize_text_field()`: that collapses the
	 * newlines a message needs and strips characters a Wi-Fi password may
	 * contain. The values are only ever encoded into the QR code -- the
	 * payload goes back to the browser as JSON and is shown with `.text()` --
	 * so only invalid UTF-8 and NUL bytes are removed. Each builder
	 * validates its own fields.
	 *
	 * @return array<string, string>
	 */
	private static function fields(): array {
		$fields = array();
		foreach ( RequestInput::get_post_raw_array( 'fields' ) as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$fields[ sanitize_key( $key ) ] = str_replace( "\0", '', wp_check_invalid_utf8( (string) $value ) );
			}
		}
		return $fields;
	}
}
