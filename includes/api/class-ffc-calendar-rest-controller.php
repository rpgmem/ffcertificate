<?php
/**
 * Calendar REST Controller
 *
 * Handles calendar-related REST API endpoints:
 *   GET /calendars            – List active calendars
 *   GET /calendars/{id}       – Get calendar details
 *   GET /calendars/{id}/slots – Get available time slots
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
 * REST API controller for calendar endpoints.
 */
class CalendarRestController {

	use ReadRateLimitGuardTrait;


	/**
	 * API namespace
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Constructor
	 *
	 * @param string $namespace API namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		// GET /calendars, GET /calendars/{id}, GET /calendars/{id}/slots
		//
		// All three are public-by-design — the [ffc_self_scheduling]
		// shortcode hits them anonymously to render the public booking
		// UI. They cannot move behind a permission gate. Defence is the
		// IP-keyed rate-limit guard called at the top of every handler
		// (see CalendarRestController::rate_limit_guard()), which trips
		// when the IP pool is exhausted by abusive submit/verify hits
		// from the same address. See issue #139 (S3).

		register_rest_route(
			$this->namespace,
			'/calendars',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_calendars' ),
				// phpcs:ignore -- public-by-design: see register_routes() block comment above and #139.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/calendars/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_calendar' ),
				// phpcs:ignore -- public-by-design: see register_routes() block comment above and #139.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/calendars/(?P<id>\d+)/slots',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_calendar_slots' ),
				// phpcs:ignore -- public-by-design: see register_routes() block comment above and #139.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'   => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'date' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return (bool) strtotime( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Per-endpoint rate-limit guard.
	 *
	 * The Calendar GET routes are public-by-design — the
	 * `[ffc_self_scheduling]` shortcode hits them anonymously to build
	 * the booking UI. Issue #259 replaced the previous shared-IP-pool
	 * circuit breaker with a dedicated per-read pool (per-endpoint
	 * thresholds in `settings['read']['endpoints']`) so a scraper that
	 * never submits forms doesn't bypass the protection.
	 *
	 * Logic lives in {@see ReadRateLimitGuardTrait::guard_read()} —
	 * the three calendar endpoints just pass their endpoint key.
	 *
	 * @since 6.4.1
	 * @param string $endpoint_key Endpoint identifier.
	 * @return \WP_Error|null `WP_Error` (status 429) when blocked, null when allowed.
	 */
	private function rate_limit_guard( string $endpoint_key ) {
		if ( ! class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			return null;
		}
		return $this->guard_read( $endpoint_key );
	}

	/**
	 * GET /calendars
	 * List all active calendars
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_calendars( $request ) {
		$blocked = $this->rate_limit_guard( 'calendar_list' );
		if ( $blocked instanceof \WP_Error ) {
			return $blocked;
		}

		try {
			if ( ! class_exists( '\FreeFormCertificate\Repositories\CalendarRepository' ) ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Calendar repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
			$has_bypass          = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();

			// Non-authenticated users only see public calendars.
			if ( ! is_user_logged_in() && ! $has_bypass ) {
				$calendars = $calendar_repository->getPublicActiveCalendars();
			} else {
				$calendars = $calendar_repository->findAll(
					array( 'status' => 'active' ),
					'title',
					'ASC'
				);
			}

			$calendars_formatted = array();
			foreach ( $calendars as $calendar ) {
				$calendars_formatted[] = array(
					'id'                    => (int) $calendar['id'],
					'title'                 => $calendar['title'],
					'description'           => $calendar['description'] ?? '',
					'requires_approval'     => (bool) $calendar['requires_approval'],
					'visibility'            => $calendar['visibility'] ?? 'public',
					'scheduling_visibility' => $calendar['scheduling_visibility'] ?? 'public',
					'allow_cancellation'    => (bool) $calendar['allow_cancellation'],
					'slot_duration'         => (int) $calendar['slot_duration'],
					'advance_booking_min'   => (int) $calendar['advance_booking_min'],
					'advance_booking_max'   => (int) $calendar['advance_booking_max'],
				);
			}

			return rest_ensure_response(
				array(
					'calendars' => $calendars_formatted,
					'total'     => count( $calendars_formatted ),
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_calendars', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /calendars/{id}
	 * Get calendar details
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_calendar( $request ) {
		$blocked = $this->rate_limit_guard( 'calendar_detail' );
		if ( $blocked instanceof \WP_Error ) {
			return $blocked;
		}

		try {
			$calendar_id = $request->get_param( 'id' );

			if ( ! class_exists( '\FreeFormCertificate\Repositories\CalendarRepository' ) ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Calendar repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
			$calendar            = $calendar_repository->getWithWorkingHours( $calendar_id );

			if ( ! $calendar ) {
				return new \WP_Error(
					'calendar_not_found',
					__( 'Calendar not found', 'ffcertificate' ),
					array( 'status' => 404 )
				);
			}

			if ( 'active' !== $calendar['status'] ) {
				return new \WP_Error(
					'calendar_inactive',
					__( 'Calendar is not active', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			// Check visibility for non-authenticated users.
			$visibility = $calendar['visibility'] ?? 'public';
			$has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();

			if ( 'private' === $visibility && ! is_user_logged_in() && ! $has_bypass ) {
				return new \WP_Error(
					'calendar_private',
					__( 'This calendar requires authentication.', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			return rest_ensure_response(
				array(
					'id'                        => (int) $calendar['id'],
					'title'                     => $calendar['title'],
					'description'               => $calendar['description'] ?? '',
					'requires_approval'         => (bool) $calendar['requires_approval'],
					'visibility'                => $visibility,
					'scheduling_visibility'     => $calendar['scheduling_visibility'] ?? 'public',
					'allow_cancellation'        => (bool) $calendar['allow_cancellation'],
					'cancellation_min_hours'    => (int) $calendar['cancellation_min_hours'],
					'slot_duration'             => (int) $calendar['slot_duration'],
					'slot_interval'             => (int) $calendar['slot_interval'],
					'max_appointments_per_slot' => (int) $calendar['max_appointments_per_slot'],
					'advance_booking_min'       => (int) $calendar['advance_booking_min'],
					'advance_booking_max'       => (int) $calendar['advance_booking_max'],
					'working_hours'             => $calendar['working_hours'] ?? array(),
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_calendar', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /calendars/{id}/slots
	 * Get available time slots for a date
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_calendar_slots( $request ) {
		$blocked = $this->rate_limit_guard( 'calendar_slots' );
		if ( $blocked instanceof \WP_Error ) {
			return $blocked;
		}

		try {
			$calendar_id = $request->get_param( 'id' );
			$date        = $request->get_param( 'date' );

			if ( ! class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentHandler' ) ) {
				return new \WP_Error(
					'handler_not_found',
					__( 'Appointment handler not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
			$slots               = $appointment_handler->get_available_slots( $calendar_id, $date );

			if ( is_wp_error( $slots ) ) {
				return $slots;
			}

			return rest_ensure_response(
				array(
					'slots'       => $slots,
					'date'        => $date,
					'calendar_id' => $calendar_id,
				)
			);

		} catch ( \Exception $e ) {
			$this->log_rest_error( 'get_calendar_slots', $e );
			return new \WP_Error(
				'ffc_internal_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
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
