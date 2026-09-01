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
	 * @var non-falsy-string
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
	 * @param non-falsy-string $namespace API namespace.
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
}
