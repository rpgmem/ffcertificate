<?php
/**
 * RestController (Coordinator)
 *
 * Thin coordinator that initialises all REST sub-controllers:
 *
 *   FormRestController       – /forms, /forms/{id}, /forms/{id}/submit
 *   SubmissionRestController – /submissions, /submissions/{id}, /verify
 *   UserDataRestController   – /user/* (coordinator → 6 sub-controllers)
 *   CalendarRestController   – /calendars, /calendars/{id}, /calendars/{id}/slots
 *   AppointmentRestController – /calendars/{id}/appointments, /appointments/{id}
 *   CertificatesCalendarRestController – /certificates/calendar
 *   OperatorCertificatesRestController – /operator/certificates, /operator/certificates/{id}/pdf
 *
 * Namespace: /wp-json/ffc/v1/
 *
 * @package FreeFormCertificate\API
 * @since 3.0.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 4.6.1 - Refactored into coordinator + sub-controllers
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\FormRepository;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for plugin endpoints.
 */
class RestController {

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace = 'ffc/v1';

	/**
	 * Form repository.
	 *
	 * @var FormRepository|null
	 */
	private ?FormRepository $form_repository = null;

	/**
	 * Submission repository.
	 *
	 * @var SubmissionRepository|null
	 */
	private ?SubmissionRepository $submission_repository = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize repositories.
		$this->form_repository       = new FormRepository();
		$this->submission_repository = new SubmissionRepository();

		// Register REST routes.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Suppress PHP notices/warnings in REST API responses to prevent JSON corruption.
		add_action( 'rest_api_init', array( $this, 'suppress_rest_api_notices' ) );

		// Send a refused request's wait as a Retry-After header.
		add_filter( 'rest_post_dispatch', array( $this, 'add_rate_limit_headers' ), 10, 3 );
	}

	/**
	 * Send a refused request's wait as a real `Retry-After` header (#2342).
	 *
	 * WordPress turns a `WP_Error` into a response whose `data` lands in the
	 * JSON body, so the `headers` a guard put there never reached the client
	 * as headers. A 429 from an FFC route now carries the wait it computed.
	 *
	 * The `X-RateLimit-*` pair this filter used to add reported the web form's
	 * per-address pool on every route, although no `ffc/v1` route counts
	 * against it: reads have their own pools, and the authenticated routes
	 * count the account. A header that names the wrong pool is worse than
	 * none, so it is gone.
	 *
	 * @since 5.2.0
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_REST_Server   $server   The REST server.
	 * @param \WP_REST_Request  $request  The request object.
	 * @return \WP_REST_Response
	 */
	public function add_rate_limit_headers( $response, $server, $request ): \WP_REST_Response {
		if ( strpos( $request->get_route(), '/ffc/v1/' ) === false || 429 !== $response->get_status() ) {
			return $response;
		}

		$body = $response->get_data();
		$data = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		$wait = 0;
		if ( isset( $data['headers']['Retry-After'] ) && is_numeric( $data['headers']['Retry-After'] ) ) {
			$wait = (int) $data['headers']['Retry-After'];
		} elseif ( isset( $data['wait_seconds'] ) && is_numeric( $data['wait_seconds'] ) ) {
			$wait = (int) $data['wait_seconds'];
		}

		if ( $wait > 0 ) {
			$response->header( 'Retry-After', (string) $wait );
		}

		return $response;
	}

	/**
	 * Buffer REST API output to prevent stray PHP notices from corrupting JSON.
	 *
	 * Uses output buffering only — warnings are still logged via WP_DEBUG_LOG
	 * but never leak into REST responses.
	 */
	public function suppress_rest_api_notices(): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( ! ob_get_level() ) {
				ob_start();
			}

			add_filter(
				'rest_pre_serve_request',
				function ( $served, $result, $request, $server ) {
					if ( ob_get_level() ) {
						ob_clean();
					}
					return $served;
				},
				10,
				4
			);
		}
	}

	/**
	 * Register all REST routes via sub-controllers
	 */
	public function register_routes(): void {
		$form_controller = new FormRestController( $this->namespace, $this->form_repository );
		$form_controller->register_routes();

		$submission_controller = new SubmissionRestController( $this->namespace, $this->submission_repository );
		$submission_controller->register_routes();

		$user_data_controller = new UserDataRestController( $this->namespace );
		$user_data_controller->register_routes();

		$calendar_controller = new CalendarRestController( $this->namespace );
		$calendar_controller->register_routes();

		$appointment_controller = new AppointmentRestController( $this->namespace );
		$appointment_controller->register_routes();

		$certificates_calendar_controller = new CertificatesCalendarRestController( $this->namespace );
		$certificates_calendar_controller->register_routes();

		// Authenticated operator app surface (#858): issuance + PDF.
		$operator_controller = new OperatorCertificatesRestController( $this->namespace, $this->form_repository );
		$operator_controller->register_routes();
	}
}
