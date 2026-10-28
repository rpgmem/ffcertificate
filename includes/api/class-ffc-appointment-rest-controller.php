<?php
/**
 * Appointment REST Controller
 *
 * Handles appointment-related REST API endpoints:
 *   POST   /calendars/{id}/appointments – Create appointment (authenticated, `ffc_manage_appointments`)
 *   GET    /appointments/{id}           – Get appointment details
 *   DELETE /appointments/{id}           – Cancel appointment
 *
 * @package FreeFormCertificate\API
 * @since 4.6.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for appointment endpoints.
 */
class AppointmentRestController {

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor
	 *
	 * @param non-falsy-string $namespace API namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		// POST /calendars/{id}/appointments - Create appointment.
		//
		// Authenticated (#1861). The public booking form does not use this
		// route: the [ffc_self_scheduling] shortcode posts to the
		// `ffc_book_appointment` AJAX action, which carries the nonce and
		// the captcha. This route books in the name and email the caller
		// sends, which is an operator's act on the appointments domain, so
		// it needs `ffc_manage_appointments` -- not the self-scoped
		// `ffc_book_own_appointments`, which would let any end user book in
		// someone else's name.
		register_rest_route(
			$this->namespace,
			'/calendars/(?P<id>\d+)/appointments',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_appointment' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'args'                => array(
					'cpf_rf' => array(
						'type'        => 'string',
						'description' => 'The booker\'s CPF (11 digits) or RF (7 digits), required in the JSON body like date, time, name and email.',
					),
					'id'     => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /appointments/{id} - Get appointment details.
		register_rest_route(
			$this->namespace,
			'/appointments/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_appointment' ),
				'permission_callback' => array( $this, 'check_appointment_access' ),
				'args'                => array(
					'id'     => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
					'reveal' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'description'       => 'Return the email and phone in clear, for a caller the PII policy lets reveal; the read is recorded in the activity log.',
					),
				),
			)
		);

		// DELETE /appointments/{id} - Cancel appointment.
		register_rest_route(
			$this->namespace,
			'/appointments/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'cancel_appointment' ),
				'permission_callback' => array( $this, 'check_appointment_access' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * POST /calendars/{id}/appointments
	 * Create a new appointment
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_appointment( $request ) {
		try {
			$calendar_id = absint( $request->get_param( 'id' ) );
			$params      = $request->get_json_params();

			if ( empty( $params ) ) {
				return new \WP_Error(
					'no_data',
					__( 'No data provided in request body', 'ffcertificate' ),
					array( 'status' => 400 )
				);
			}

			// `cpf_rf` is required by the validator as it is on the booking form; a
			// body without it is refused here with the field's name (#2155).
			$required_fields = array( 'date', 'time', 'name', 'email', 'cpf_rf' );
			foreach ( $required_fields as $field ) {
				if ( empty( $params[ $field ] ) ) {
					return new \WP_Error(
						'missing_field',
						/* translators: %s: field name */
						sprintf( __( 'Missing required field: %s', 'ffcertificate' ), $field ),
						array( 'status' => 400 )
					);
				}
			}

			if ( ! is_email( $params['email'] ) ) {
				return new \WP_Error(
					'invalid_email',
					__( 'Invalid email address', 'ffcertificate' ),
					array( 'status' => 400 )
				);
			}

			// Check scheduling visibility.
			$calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
			$calendar            = $calendar_repository->findById( $calendar_id );

			if ( ! $calendar ) {
				return new \WP_Error(
					'calendar_not_found',
					__( 'Calendar not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			$has_bypass            = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();
			$scheduling_visibility = $calendar['scheduling_visibility'] ?? 'public';

			if ( 'private' === $scheduling_visibility && ! is_user_logged_in() && ! $has_bypass ) {
				return new \WP_Error(
					'scheduling_private',
					__( 'This calendar requires authentication to book.', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			// Rate limiting (prevent automated booking abuse).
			if ( class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
				$ip         = \FreeFormCertificate\Core\RequestInput::get_user_ip();
				$rate_check = \FreeFormCertificate\Security\RateLimiter::check_ip_limit( $ip );

				if ( ! $rate_check['allowed'] ) {
					return new \WP_Error(
						'rate_limit_exceeded',
						__( 'Too many requests. Please try again later.', 'ffcertificate' ),
						array( 'status' => 429 )
					);
				}

				// A check that never records never trips (#1861).
				\FreeFormCertificate\Security\RateLimiter::record_attempt( 'ip', $ip );
			}

			$appointment_data = array(
				'calendar_id'      => $calendar_id,
				'appointment_date' => sanitize_text_field( $params['date'] ),
				'start_time'       => sanitize_text_field( $params['time'] ),
				'name'             => sanitize_text_field( $params['name'] ),
				'email'            => \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( $params['email'] ) ),
				// Sanitised as the booking form's AJAX handler does; the handler
				// normalises it to digits before it validates and stores it (#2155).
				'cpf_rf'           => sanitize_text_field( (string) $params['cpf_rf'] ),
				'phone'            => isset( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '',
				'user_notes'       => isset( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : '',
				// Sanitised as the booking form's AJAX handler does (#1861).
				'custom_data'      => \FreeFormCertificate\Core\RequestInput::text_array( $params['custom_data'] ?? array() ),
				'consent_given'    => isset( $params['consent'] ) ? 1 : 0,
				'consent_text'     => isset( $params['consent_text'] ) ? sanitize_textarea_field( $params['consent_text'] ) : '',
				'user_ip'          => \FreeFormCertificate\Core\RequestInput::get_user_ip(),
				'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				// A bypass holder's explicit confirmation of a booking outside
				// the calendar's rules; without it the answer is a 409 (#1859).
				'confirm_override' => ! empty( $params['confirm_override'] ),
			);

			if ( is_user_logged_in() ) {
				$appointment_data['user_id'] = get_current_user_id();
			}

			if ( ! class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentHandler' ) ) {
				return new \WP_Error(
					'handler_not_found',
					__( 'Appointment handler not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
			$result              = $appointment_handler->process_appointment( $appointment_data );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response(
				array(
					'success'           => true,
					'message'           => __( 'Appointment booked successfully!', 'ffcertificate' ),
					'appointment_id'    => $result['appointment_id'],
					'requires_approval' => $result['requires_approval'],
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'create_appointment', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /appointments/{id}
	 * Get appointment details
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_appointment( $request ) {
		try {
			$appointment_id = absint( $request->get_param( 'id' ) );

			if ( ! class_exists( '\FreeFormCertificate\Repositories\AppointmentRepository' ) ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Appointment repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
			$appointment            = $appointment_repository->findById( $appointment_id );

			if ( ! $appointment ) {
				return new \WP_Error(
					'appointment_not_found',
					__( 'Appointment not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			$calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
			$calendar            = $calendar_repository->findById( absint( $appointment['calendar_id'] ) );

			$email_display = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );
			$phone_display = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'phone' );

			// #1863 — the booker sees their own email and phone; anyone else
			// reaching this route (a holder of the scheduling bypass) gets them
			// masked, unless the policy lets them reveal and the request asks
			// with `reveal=1`, which is audited like the admin Reveal button.
			$owner = (int) ( $appointment['user_id'] ?? 0 );
			$tier  = \FreeFormCertificate\Core\PiiAccessPolicy::resolve( 'ffc_view_appointments_pii', $owner > 0 ? $owner : null );
			if ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_REVEAL === $tier && $request->get_param( 'reveal' ) ) {
				\FreeFormCertificate\Core\ActivityLog::log(
					'appointment_pii_revealed',
					\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
					array( 'field_key' => 'rest_api' ),
					get_current_user_id(),
					(int) $appointment['id']
				);
			} elseif ( \FreeFormCertificate\Core\PiiAccessPolicy::TIER_UNMASKED !== $tier ) {
				$email_display = \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $email_display );
				$phone_display = \FreeFormCertificate\Core\DocumentFormatter::mask_phone( (string) $phone_display );
			}

			return rest_ensure_response(
				array(
					'id'               => (int) $appointment['id'],
					'calendar_id'      => (int) $appointment['calendar_id'],
					'calendar_title'   => $calendar['title'] ?? '',
					'appointment_date' => $appointment['appointment_date'],
					'start_time'       => $appointment['start_time'],
					'end_time'         => $appointment['end_time'],
					'status'           => $appointment['status'],
					'name'             => $appointment['name'],
					'email'            => $email_display,
					'phone'            => $phone_display,
					'user_notes'       => $appointment['user_notes'] ?? '',
					'created_at'       => $appointment['created_at'],
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_appointment', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * DELETE /appointments/{id}
	 * Cancel an appointment
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_appointment( $request ) {
		try {
			$appointment_id = absint( $request->get_param( 'id' ) );
			$params         = $request->get_json_params();
			$reason         = isset( $params['reason'] ) ? sanitize_textarea_field( $params['reason'] ) : '';

			if ( ! class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentHandler' ) ) {
				return new \WP_Error(
					'handler_not_found',
					__( 'Appointment handler not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
			$result              = $appointment_handler->cancel_appointment( $appointment_id, '', $reason );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Appointment cancelled successfully', 'ffcertificate' ),
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'cancel_appointment', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Permission callback for `POST /calendars/{id}/appointments` (#1861).
	 *
	 * @since 6.40.0
	 * @return bool
	 */
	public function check_create_permission(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' );
	}

	/**
	 * Check appointment access permission
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public function check_appointment_access( $request ): bool {
		$appointment_id = absint( $request->get_param( 'id' ) );

		if ( \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass() ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! class_exists( '\FreeFormCertificate\Repositories\AppointmentRepository' ) ) {
			return false;
		}

		$appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
		$appointment            = $appointment_repository->findById( $appointment_id );

		if ( ! $appointment ) {
			return false;
		}

		return get_current_user_id() === (int) $appointment['user_id'];
	}

	/**
	 * Log REST API error without exposing details to clients.
	 *
	 * @since 4.6.6
	 * @param string     $context Action that caused the error.
	 * @param \Exception $e       The exception.
	 */
	private function log_rest_error( string $context, \Exception $e ): void {
		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			\FreeFormCertificate\Core\Debug::log_rest_api(
				"REST API error: {$context}",
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
		}
	}
}
