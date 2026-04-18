<?php
/**
 * User Data REST Controller (Coordinator)
 *
 * Thin coordinator that initialises all user-facing REST sub-controllers:
 *
 *   UserCertificatesRestController     – GET  /user/certificates
 *   UserProfileRestController          – GET|PUT /user/profile, POST /user/change-password, POST /user/privacy-request
 *   UserAppointmentsRestController     – GET  /user/appointments
 *   UserAudienceRestController         – GET  /user/audience-bookings, GET /user/joinable-groups, POST /user/audience-group/join|leave
 *   UserSummaryRestController          – GET  /user/summary
 *   UserReregistrationsRestController  – GET  /user/reregistrations
 *
 * @package FreeFormCertificate\API
 * @since 4.6.1
 * @version 4.12.7 - Refactored into coordinator + 6 sub-controllers
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user data endpoints.
 */
class UserDataRestController {

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Sub-controller instances (lazy-loaded on register_routes)
	 *
	 * @var array<object>
	 */
	private array $sub_controllers = array();

	/**
	 * Constructor
	 *
	 * @param string $namespace API namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes via sub-controllers
	 */
	public function register_routes(): void {
		$this->sub_controllers = array(
			'certificates'    => new UserCertificatesRestController( $this->namespace ),
			'profile'         => new UserProfileRestController( $this->namespace ),
			'appointments'    => new UserAppointmentsRestController( $this->namespace ),
			'audience'        => new UserAudienceRestController( $this->namespace ),
			'summary'         => new UserSummaryRestController( $this->namespace ),
			'reregistrations' => new UserReregistrationsRestController( $this->namespace ),
		);

		foreach ( $this->sub_controllers as $controller ) {
			$controller->register_routes();
		}
	}

	// ------------------------------------------------------------------.
	// Backward-compatible delegate methods.
	//
	// External code (or tests) may call these directly. Each method.
	// delegates to the appropriate sub-controller.
	// ------------------------------------------------------------------.

	/**
	 * Get user certificates.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_certificates( $request ) {
		return $this->get_sub( 'certificates' )->get_user_certificates( $request );
	}

	/**
	 * Get user profile.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_profile( $request ) {
		return $this->get_sub( 'profile' )->get_user_profile( $request );
	}

	/**
	 * Update user profile.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_user_profile( $request ) {
		return $this->get_sub( 'profile' )->update_user_profile( $request );
	}

	/**
	 * Get user appointments.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_appointments( $request ) {
		return $this->get_sub( 'appointments' )->get_user_appointments( $request );
	}

	/**
	 * Get user audience bookings.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_audience_bookings( $request ) {
		return $this->get_sub( 'audience' )->get_user_audience_bookings( $request );
	}

	/**
	 * Change password.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_password( $request ) {
		return $this->get_sub( 'profile' )->change_password( $request );
	}

	/**
	 * Create privacy request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_privacy_request( $request ) {
		return $this->get_sub( 'profile' )->create_privacy_request( $request );
	}

	/**
	 * Get user summary.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_summary( $request ) {
		return $this->get_sub( 'summary' )->get_user_summary( $request );
	}

	/**
	 * Get joinable groups.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_joinable_groups( $request ) {
		return $this->get_sub( 'audience' )->get_joinable_groups( $request );
	}

	/**
	 * Join audience group.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function join_audience_group( $request ) {
		return $this->get_sub( 'audience' )->join_audience_group( $request );
	}

	/**
	 * Leave audience group.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function leave_audience_group( $request ) {
		return $this->get_sub( 'audience' )->leave_audience_group( $request );
	}

	/**
	 * Get user reregistrations.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_reregistrations( $request ) {
		return $this->get_sub( 'reregistrations' )->get_user_reregistrations( $request );
	}

	/**
	 * Get (or lazy-create) a sub-controller by key.
	 *
	 * @param string $key Sub-controller key.
	 * @return object
	 */
	private function get_sub( string $key ): object {
		if ( empty( $this->sub_controllers[ $key ] ) ) {
			// Lazy-init if register_routes() hasn't been called yet.
			$map                           = array(
				'certificates'    => UserCertificatesRestController::class,
				'profile'         => UserProfileRestController::class,
				'appointments'    => UserAppointmentsRestController::class,
				'audience'        => UserAudienceRestController::class,
				'summary'         => UserSummaryRestController::class,
				'reregistrations' => UserReregistrationsRestController::class,
			);
			$class                         = $map[ $key ];
			$this->sub_controllers[ $key ] = new $class( $this->namespace );
		}
		return $this->sub_controllers[ $key ];
	}
}
