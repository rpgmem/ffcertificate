<?php
/**
 * Audience Presence REST Controller
 *
 * Enrolment, renewal and the revocation list of presence devices (#2741).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ffc/v1/presence/*`.
 *
 * - `POST enrol` and `POST renew` are the app's: a logged-in participant,
 *   the REST nonce, and the user always taken from the session, never from
 *   the request.
 * - `GET revocations` is a terminal's: the presence public keys and the key
 *   ids revoked while an attestation could still carry them. It carries no
 *   personal data. Until terminals pair (#2744) it answers holders of the
 *   check-in capability only.
 */
class AudiencePresenceRestController {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'ffc/v1';

	/**
	 * Route base.
	 */
	public const REST_BASE = 'presence';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$proof = array(
			'timestamp' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'proof'     => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( self::class, 'sanitize_b64' ),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/enrol',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enrol' ),
				'permission_callback' => array( $this, 'check_app_permission' ),
				'args'                => array_merge(
					array(
						'device_id'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_b64' ),
						),
						'public_key' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_b64' ),
						),
						'signals'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
					$proof
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/renew',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'renew' ),
				'permission_callback' => array( $this, 'check_app_permission' ),
				'args'                => array_merge(
					array(
						'key_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_b64' ),
						),
					),
					$proof
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/revocations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'revocations' ),
				'permission_callback' => array( $this, 'check_terminal_permission' ),
			)
		);
	}

	/**
	 * Who may enrol or renew: a logged-in participant of audience calendars,
	 * carrying the REST nonce.
	 *
	 * WordPress already drops a cookie request without the nonce to an
	 * anonymous one; checking it here as well keeps the route safe should a
	 * second authentication path ever let a cookie through without it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_app_permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}
		return Capabilities::current_user_can_admin_or( 'ffc_view_own_audience_bookings' );
	}

	/**
	 * Who may read the revocation list, until terminals pair (#2744).
	 *
	 * @return bool
	 */
	public function check_terminal_permission(): bool {
		return is_user_logged_in() && Capabilities::current_user_can_admin_or( 'ffc_checkin_audiences' );
	}

	/**
	 * Activate this device.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enrol( \WP_REST_Request $request ) {
		$result = AudiencePresenceEnrolment::enrol(
			get_current_user_id(),
			(string) $request->get_param( 'device_id' ),
			(string) $request->get_param( 'public_key' ),
			(int) $request->get_param( 'timestamp' ),
			(string) $request->get_param( 'proof' ),
			(string) $request->get_param( 'signals' ),
			time()
		);
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Renew this device's attestation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function renew( \WP_REST_Request $request ) {
		$result = AudiencePresenceEnrolment::renew(
			get_current_user_id(),
			(string) $request->get_param( 'key_id' ),
			(int) $request->get_param( 'timestamp' ),
			(string) $request->get_param( 'proof' ),
			time()
		);
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * What a terminal needs to verify a presence code offline.
	 *
	 * @return \WP_REST_Response
	 */
	public function revocations(): \WP_REST_Response {
		$now = time();
		return new \WP_REST_Response(
			array(
				'keys'      => AudiencePresenceKeys::public_keys( $now ),
				'revoked'   => AudiencePresenceReader::revoked_key_ids_since( $now - AudiencePresenceAttestation::TTL ),
				'generated' => $now,
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * Keep base64url characters only.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_b64( $value ): string {
		return is_string( $value ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value ) : '';
	}
}
