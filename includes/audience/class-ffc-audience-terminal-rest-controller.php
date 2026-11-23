<?php
/**
 * Audience Terminal REST Controller
 *
 * The routes that pair, list and end check-in terminals, and that hand an
 * operator their unlock code (#2744).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\ClientIpResolver;
use FreeFormCertificate\Core\IpAddress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ffc/v1/terminal/*`.
 *
 * - `POST pairings` — an operator generates a pairing code.
 * - `POST pair` — a terminal trades a code for its token. It runs without a
 *   login on purpose: the terminal never holds a person's account. What
 *   guards it is the code (single use, five minutes) and the lockout.
 * - `GET terminals` — the operator's terminals; an administrator sees all.
 * - `DELETE terminals/{id}` — end one.
 * - `POST operator-code` — an operator's unlock code for a paired terminal.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 */
class AudienceTerminalRestController {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'ffc/v1';

	/**
	 * Route base.
	 */
	public const REST_BASE = 'terminal';

	/**
	 * The header a terminal sends its token in.
	 */
	public const TOKEN_HEADER = 'X-FFC-Terminal';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/pairings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_pairing' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'bookings' => array(
						'type'              => 'array',
						'required'          => true,
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( self::class, 'sanitize_ids' ),
					),
					'label'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/pair',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pair' ),
				'permission_callback' => array( $this, 'check_pair_permission' ),
				'args'                => array(
					'code'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_code' ),
					),
					'device' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( AudiencePresenceRestController::class, 'sanitize_b64' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/terminals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_terminals' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/terminals/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/operator-code',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'operator_code' ),
				'permission_callback' => array( $this, 'check_operator_permission' ),
			)
		);
	}

	/**
	 * Who may pair, list and end terminals: a logged-in holder of
	 * {@see AudienceTerminalPairing::CAP}, carrying the REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_manage_permission( \WP_REST_Request $request ): bool {
		return self::has_nonce( $request ) && Capabilities::current_user_can_admin_or( AudienceTerminalPairing::CAP );
	}

	/**
	 * Who may ask for an operator code: whoever runs a check-in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_operator_permission( \WP_REST_Request $request ): bool {
		return self::has_nonce( $request ) && Capabilities::current_user_can_admin_or( 'ffc_checkin_audiences' );
	}

	/**
	 * Pairing is open to an anonymous caller: the terminal has no account.
	 * The code and {@see AudienceTerminalLockout} are the guard, both checked
	 * in {@see self::pair()}.
	 *
	 * @return bool
	 */
	public function check_pair_permission(): bool {
		return true;
	}

	/**
	 * Generate a pairing code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_pairing( \WP_REST_Request $request ) {
		$bookings = self::sanitize_ids( $request->get_param( 'bookings' ) );
		$label    = $request->get_param( 'label' );
		$result   = AudienceTerminalPairing::create_code( get_current_user_id(), $bookings, is_string( $label ) ? $label : '', time() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 201, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * Trade a code for a terminal token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pair( \WP_REST_Request $request ) {
		$now    = time();
		$code   = $request->get_param( 'code' );
		$device = $request->get_param( 'device' );
		$result = AudienceTerminalPairing::pair(
			is_string( $code ) ? $code : '',
			is_string( $device ) ? $device : '',
			IpAddress::fingerprint( ClientIpResolver::resolve() ),
			$now
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['retry_after'] ) && is_int( $data['retry_after'] ) ) {
				$response = new \WP_REST_Response(
					array(
						'code'        => $result->get_error_code(),
						'message'     => $result->get_error_message(),
						'retry_after' => $data['retry_after'],
					),
					429
				);
				$response->header( 'Retry-After', (string) $data['retry_after'] );
				return $response;
			}
			return $result;
		}
		$result['keys'] = AudiencePresenceKeys::public_keys( $now );
		return new \WP_REST_Response( $result, 201, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * The operator's active terminals; an administrator sees every one.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_terminals(): \WP_REST_Response {
		$now   = time();
		$all   = current_user_can( 'manage_options' );
		$rows  = AudienceTerminalReader::active_terminals( $now, $all ? null : get_current_user_id() );
		$items = array_map( array( self::class, 'present' ), $rows );
		return new \WP_REST_Response(
			array(
				'terminals' => $items,
				'all'       => $all,
				'limits'    => AudienceTerminalPairing::limits(),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * End a terminal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( \WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = AudienceTerminalPairing::revoke( $id, get_current_user_id(), current_user_can( 'manage_options' ), time() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( array( 'revoked' => $id ), 200 );
	}

	/**
	 * An operator's unlock code.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function operator_code() {
		$code = AudienceOperatorCode::issue( get_current_user_id(), time() );
		if ( null === $code ) {
			return new \WP_Error( 'ffc_operator_code', __( 'The site could not sign an operator code. Try again.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( $code, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * The terminal a request's token belongs to.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return TerminalRow|null
	 */
	public static function terminal_of( \WP_REST_Request $request ): ?array {
		$token = (string) $request->get_header( self::TOKEN_HEADER );
		return '' === $token ? null : AudienceTerminalPairing::authenticate( $token, time() );
	}

	/**
	 * What the list shows of a terminal: never its token hash or device hash.
	 *
	 * @param array $terminal Terminal row.
	 * @phpstan-param TerminalRow $terminal
	 * @return array{id: int, label: string, bookings: list<int>, paired_by: int|null, paired_at: int, expires_at: int, last_seen_at: int|null}
	 */
	public static function present( array $terminal ): array {
		return array(
			'id'           => $terminal['id'],
			'label'        => $terminal['label'],
			'bookings'     => $terminal['bookings'],
			'paired_by'    => $terminal['paired_by'],
			'paired_at'    => $terminal['paired_at'],
			'expires_at'   => $terminal['expires_at'],
			'last_seen_at' => $terminal['last_seen_at'],
		);
	}

	/**
	 * Positive integer ids from a list.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int>
	 */
	public static function sanitize_ids( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Keep the characters a pairing code is made of.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_code( $value ): string {
		return is_string( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $value ), 0, 32 ) : '';
	}

	/**
	 * Whether a logged-in caller sent the REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function has_nonce( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
