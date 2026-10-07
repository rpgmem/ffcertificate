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
use FreeFormCertificate\Generators\QrCapacity;
use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\QrLogo;
use FreeFormCertificate\Generators\QrPayload;
use FreeFormCertificate\Generators\QrSvgRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws a manual QR code, and creates its short URL when one is wanted
 * (#1563, #1586).
 *
 * Drawing writes nothing, so it can follow every keystroke. With the short
 * URL switch on, the preview carries a fixed example short URL of the real
 * length and reports the short URLs that already send to the same place;
 * the short URL itself is created by a separate action the browser fires
 * only on a download or a print -- drawing on each change must never mint a
 * short URL per keystroke. Remembering the design (#1568) is the other
 * write: it runs on a download or a reset, and stores the design only,
 * never content.
 */
class QrGeneratorAjaxEndpoint {

	public const ACTION_GENERATE = 'ffc_qr_generate';
	public const ACTION_SHORTEN  = 'ffc_qr_shorten';
	public const ACTION_REMEMBER = 'ffc_qr_remember';

	/** Content types whose address can be replaced by a short URL (#1586). */
	public const SHORTENABLE = array( 'url', 'social' );

	/** Capability for every action: the generator lives in the shortener's menu. */
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
		add_action( 'wp_ajax_' . self::ACTION_REMEMBER, array( $this, 'handle_remember' ) );
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

		$short = null;
		if ( in_array( $type, self::SHORTENABLE, true ) && '1' === RequestInput::get_post_string( 'short' ) ) {
			$short   = $this->short_state( $payload, RequestInput::get_post_string( 'short_code' ) );
			$payload = $short['drawn'];
			unset( $short['drawn'] );
		}

		$input         = RequestInput::get_post_array( 'design' );
		$logo_id       = RequestInput::get_post_int( 'logo_id', 0 );
		$input['logo'] = $logo_id > 0 && current_user_can( 'read_post', $logo_id ) ? QrLogo::data_uri( $logo_id ) : '';
		$design        = new QrDesign( $input );

		$ecc    = strtoupper( RequestInput::get_post_string( 'error_level', 'M' ) );
		$ecc    = $design->error_level( in_array( $ecc, array( 'L', 'M', 'Q', 'H' ), true ) ? $ecc : 'M' );
		$margin = max( 0, min( 10, RequestInput::get_post_int( 'margin', 2 ) ) );

		$logo   = '' !== $input['logo'];
		$matrix = QrSvgRenderer::matrix( $payload, $ecc );
		if ( array() === $matrix ) {
			$usage = QrCapacity::measure( $payload, $ecc, 0 ) + array( 'forced' => $logo );
			wp_send_json_error(
				array(
					'message' => $logo
						/* translators: %d: approximate number of characters to remove. */
						? sprintf( __( 'This content is about %d characters too long. The logo requires error correction H, which holds the least; remove the logo or shorten the content.', 'ffcertificate' ), max( 1, $usage['over'] ) )
						/* translators: 1: approximate number of characters to remove, 2: error correction level (L, M, Q or H). */
						: sprintf( __( 'This content is about %1$d characters too long for error correction %2$s. Shorten it, or lower the level.', 'ffcertificate' ), max( 1, $usage['over'] ), $usage['level'] ),
					'usage'   => $usage,
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
				'usage'   => QrCapacity::measure( $payload, $ecc, count( $matrix ) ) + array( 'forced' => $logo ),
				'checks'  => $design->scan_checks(),
				'short'   => $short,
			)
		);
	}

	/**
	 * Create the short URL for the posted content and return it (#1586).
	 *
	 * Fired by the browser on a download or a print with the switch on. It
	 * refuses a missing title, an address that is already one of this site's
	 * short URLs, and -- unless the operator acknowledged it -- a destination
	 * that already has one, answering 409 with that list.
	 */
	public function handle_shorten(): void {
		$this->guard( self::ACTION_SHORTEN );

		$type = sanitize_key( RequestInput::get_post_string( 'type', 'url' ) );
		if ( ! in_array( $type, self::SHORTENABLE, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This content type cannot have a short URL.', 'ffcertificate' ) ), 400 );
		}

		$destination = QrPayload::build( $type, self::fields() );
		if ( is_wp_error( $destination ) ) {
			wp_send_json_error( array( 'message' => $destination->get_error_message() ), 400 );
		}

		$title = trim( sanitize_text_field( RequestInput::get_post_string( 'title' ) ) );
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Enter a title for the short URL.', 'ffcertificate' ) ), 400 );
		}

		if ( ! self::redirectable( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Only http and https addresses can have a short URL.', 'ffcertificate' ) ), 400 );
		}

		if ( '' !== $this->service->code_from_short_url( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'This address is already a short URL of this site; it cannot be shortened again.', 'ffcertificate' ) ), 400 );
		}

		$duplicates = $this->duplicates( $destination );
		if ( array() !== $duplicates && '1' !== RequestInput::get_post_string( 'acknowledge' ) ) {
			wp_send_json_error(
				array(
					'message'    => __( 'A short URL already sends to this address. Use it, or confirm that you want another one.', 'ffcertificate' ),
					'duplicates' => $duplicates,
				),
				409
			);
		}

		$result = $this->service->create_short_url( $destination, $title );
		$record = $result['data'] ?? null;
		if ( empty( $result['success'] ) || ! is_array( $record ) || ! isset( $record['short_code'] ) ) {
			$error = (string) ( $result['error'] ?? '' );
			wp_send_json_error( array( 'message' => '' !== $error ? $error : __( 'Could not create the short URL.', 'ffcertificate' ) ) );
		}

		$code = (string) $record['short_code'];
		wp_send_json_success(
			array(
				'short_code' => $code,
				'short_url'  => $this->service->get_short_url( $code ),
			)
		);
	}

	/**
	 * What the preview draws with the switch on, and what it reports.
	 *
	 * - An address that is not http(s) (ftp, sftp, ssh...) is drawn as is
	 *   and flagged `direct`: a short URL is an HTTP redirect, which a
	 *   browser does not reliably follow into another scheme (#1596).
	 * - An address that is already one of this site's short URLs is drawn
	 *   as is and flagged `circular`: shortening it again would only chain
	 *   two redirects.
	 * - A code the browser holds (created on an earlier download, or picked
	 *   with "Use this") is drawn when it still sends to this destination.
	 * - Otherwise the fixed example short URL is drawn, with the short URLs
	 *   that already send to the destination.
	 *
	 * @param string $destination Address the short URL sends to.
	 * @param string $code        Code the browser holds, or ''.
	 * @return array{drawn: string, circular: bool, direct: bool, code: string, url: string, example: bool, duplicates: array<int, array<string, mixed>>}
	 */
	private function short_state( string $destination, string $code ): array {
		if ( ! self::redirectable( $destination ) ) {
			return array(
				'drawn'      => $destination,
				'circular'   => false,
				'direct'     => true,
				'code'       => '',
				'url'        => '',
				'example'    => false,
				'duplicates' => array(),
			);
		}

		if ( '' !== $this->service->code_from_short_url( $destination ) ) {
			return array(
				'drawn'      => $destination,
				'circular'   => true,
				'direct'     => false,
				'code'       => '',
				'url'        => '',
				'example'    => false,
				'duplicates' => array(),
			);
		}

		$code   = sanitize_text_field( $code );
		$record = '' !== $code ? $this->service->get_repository()->findByShortCode( $code ) : null;
		if ( is_array( $record ) && 'trashed' !== ( $record['status'] ?? '' ) && esc_url_raw( $destination ) === (string) ( $record['target_url'] ?? '' ) ) {
			$url = $this->service->get_short_url( $code );
			return array(
				'drawn'      => $url,
				'circular'   => false,
				'direct'     => false,
				'code'       => $code,
				'url'        => $url,
				'example'    => false,
				'duplicates' => array(),
			);
		}

		return array(
			'drawn'      => $this->service->get_example_short_url(),
			'circular'   => false,
			'direct'     => false,
			'code'       => '',
			'url'        => '',
			'example'    => true,
			'duplicates' => $this->duplicates( $destination ),
		);
	}

	/**
	 * Whether a short URL can redirect to the address: http(s) only.
	 *
	 * @param string $destination Address.
	 * @return bool
	 */
	private static function redirectable( string $destination ): bool {
		$scheme = wp_parse_url( $destination, PHP_URL_SCHEME );
		return is_string( $scheme ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true );
	}

	/**
	 * Short URLs that already send to the destination, as the alert lists them.
	 *
	 * @param string $destination Address.
	 * @return array<int, array{code: string, url: string, title: string, created: string, clicks: int, active: bool}>
	 */
	private function duplicates( string $destination ): array {
		$rows   = $this->service->get_repository()->findByTargetUrl( esc_url_raw( $destination ) );
		$format = get_option( 'date_format' );
		$format = is_string( $format ) && '' !== $format ? $format : 'Y-m-d';
		$list   = array();
		foreach ( $rows as $row ) {
			$code = (string) ( $row['short_code'] ?? '' );
			// `created_at` is written with current_time( 'mysql' ): already the
			// site's wall clock, so it is formatted without a timezone shift.
			$list[] = array(
				'code'    => $code,
				'url'     => $this->service->get_short_url( $code ),
				'title'   => (string) ( $row['title'] ?? '' ),
				'created' => (string) mysql2date( $format, (string) ( $row['created_at'] ?? '' ) ),
				'clicks'  => (int) ( $row['click_count'] ?? 0 ),
				'active'  => 'active' === ( $row['status'] ?? '' ),
			);
		}
		return $list;
	}

	/**
	 * Remember the posted design for the current user, or forget it on a
	 * reset; answers with the state the generator now opens with.
	 */
	public function handle_remember(): void {
		$this->guard( self::ACTION_REMEMBER );
		$user_id = get_current_user_id();

		if ( '1' === RequestInput::get_post_string( 'reset' ) ) {
			QrGeneratorDesignMemory::forget( $user_id );
			$state   = QrGeneratorDesignMemory::global_state();
			$logo_id = (int) $state['qr_design_logo_id'];
			wp_send_json_success(
				array(
					'state'      => $state,
					// The form shows the logo by its thumbnail, which the state does not carry.
					'logo_thumb' => $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
				)
			);
		}

		$design  = RequestInput::get_post_array( 'design' );
		$logo_id = RequestInput::get_post_int( 'logo_id', 0 );
		QrGeneratorDesignMemory::remember(
			$user_id,
			array(
				'qr_design_dots'            => $design['dots'] ?? '',
				'qr_design_eye_frame'       => $design['eye_frame'] ?? '',
				'qr_design_eye_ball'        => $design['eye_ball'] ?? '',
				'qr_design_color'           => $design['color'] ?? '',
				'qr_design_background'      => $design['background'] ?? '',
				'qr_design_eye_frame_color' => $design['eye_frame_color'] ?? '',
				'qr_design_eye_ball_color'  => $design['eye_ball_color'] ?? '',
				'qr_design_gradient'        => $design['gradient'] ?? '',
				'qr_design_color_end'       => $design['color_end'] ?? '',
				'qr_design_frame'           => $design['frame'] ?? '',
				'qr_design_frame_text'      => $design['frame_text'] ?? '',
				'qr_design_frame_color'     => $design['frame_color'] ?? '',
				'qr_design_frame_icon'      => $design['frame_icon'] ?? '',
				'qr_design_transparent'     => $design['transparent'] ?? '',
				// A logo the user cannot read is not remembered: the page would
				// otherwise embed it on the next visit.
				'qr_design_logo_id'         => $logo_id > 0 && current_user_can( 'read_post', $logo_id ) ? $logo_id : 0,
				'margin'                    => RequestInput::get_post_int( 'margin', 2 ),
				'error_level'               => RequestInput::get_post_string( 'error_level', 'M' ),
			)
		);

		wp_send_json_success( array( 'state' => QrGeneratorDesignMemory::state( $user_id ) ) );
	}

	/**
	 * Nonce and capability, shared by every action.
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
