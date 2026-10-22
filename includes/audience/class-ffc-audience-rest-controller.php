<?php
/**
 * Audience REST Controller
 *
 * Handles REST API endpoints for audience booking system.
 *
 * Endpoints:
 * - GET  /ffc/v1/audience/bookings       - Get bookings for date range
 * - POST /ffc/v1/audience/bookings       - Create a new booking
 * - DELETE /ffc/v1/audience/bookings/{id} - Cancel a booking
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for audience endpoints.
 *
 * @phpstan-import-type EnvironmentRow from AudienceEnvironmentRepository
 */
class AudienceRestController {

	/**
	 * Namespace for REST routes
	 */
	private const NAMESPACE = 'ffc/v1';

	/**
	 * Resource base
	 */
	private const REST_BASE = 'audience';

	/**
	 * Register REST routes
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Get bookings.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/bookings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_bookings' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'schedule_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'environment_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'start_date'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_date'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Create booking.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/bookings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'environment_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'booking_date'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'start_time'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_time'         => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_type'     => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'audience', 'individual' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'audience_ids'     => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					'user_ids'         => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					// A bypass holder's explicit "I agree" to a booking that
					// breaks a rule or overrides a conflict (#1859).
					'confirm_override' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		// Cancel booking.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/bookings/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => array( $this, 'check_cancel_permission' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'reason' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Check conflicts.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/conflicts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_conflicts' ),
				'permission_callback' => array( $this, 'check_conflicts_permission' ),
				'args'                => array(
					'environment_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'booking_date'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'start_time'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'end_time'       => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'audience_ids'   => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					'user_ids'       => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Check read permission
	 *
	 * Allows non-authenticated users to read bookings for public schedules.
	 *
	 * @since 4.7.0
	 * @return bool
	 */
	public function check_read_permission(): bool {
		// Logged-in users can always read.
		if ( is_user_logged_in() ) {
			return true;
		}

		// Non-logged-in users can read public schedules (read-only calendar view).
		return true;
	}

	/**
	 * Permission callback for the conflict probe (#1861).
	 *
	 * Only the booking form calls it, and only a logged-in user can book;
	 * an anonymous caller has nothing to check a conflict for.
	 *
	 * @since 6.40.0
	 * @return bool
	 */
	public function check_conflicts_permission(): bool {
		return is_user_logged_in();
	}

	/**
	 * Check write permission
	 *
	 * @return bool
	 */
	public function check_write_permission(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Admin or audience-bypass users can always write (#1859).
		if ( AudienceScheduleRepository::user_has_bypass_capability() ) {
			return true;
		}

		// Check if user has booking permission on at least one schedule.
		// Detailed per-schedule permission is verified inside create_booking().
		return current_user_can( 'ffc_view_own_audience_bookings' );
	}

	/**
	 * Check cancel permission
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function check_cancel_permission( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Admin/audience bypass can cancel anything.
		if ( AudienceScheduleRepository::user_has_bypass_capability() ) {
			return true;
		}

		$booking_id = $request->get_param( 'id' );
		$booking    = AudienceBookingReader::get_by_id( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		// Creator can cancel their own booking.
		if ( get_current_user_id() === (int) $booking->created_by ) {
			return true;
		}

		// Check if user has cancel_others permission on this schedule.
		$environment = AudienceEnvironmentRepository::get_by_id( (int) $booking->environment_id );
		if ( $environment ) {
			return AudienceScheduleRepository::user_can_cancel_others( (int) $environment->schedule_id, get_current_user_id() );
		}

		return false;
	}

	/**
	 * Resolve the set of schedule IDs the current caller may read.
	 *
	 * The read endpoints have a public `permission_callback`, so this is the
	 * authorization boundary that keeps private-schedule booking data out of
	 * unauthorized responses — mirroring the visibility gate the shortcode
	 * (`AudienceShortcode::render()`) already enforces:
	 *   - admin / audience-bypass users: unrestricted (returns null).
	 *   - logged-in users: public schedules + any they have permission on.
	 *   - anonymous users: active public schedules only.
	 *
	 * @return int[]|null Allowlist of schedule IDs, or null when unrestricted.
	 */
	private function readable_schedule_ids(): ?array {
		if ( AudienceScheduleRepository::user_has_bypass_capability() ) {
			return null;
		}

		if ( is_user_logged_in() ) {
			$schedules = AudienceScheduleRepository::get_by_user_access( get_current_user_id() );
		} else {
			$schedules = AudienceScheduleRepository::get_all(
				array(
					'status'     => 'active',
					'visibility' => 'public',
				)
			);
		}

		$ids = array();
		foreach ( $schedules as $schedule ) {
			$ids[] = (int) $schedule->id;
		}

		return $ids;
	}

	/**
	 * The active environments the month read describes (#1859).
	 *
	 * One environment when the request names it, the active environments of
	 * the schedule when it names a schedule, and otherwise those of every
	 * active schedule the caller may read. An environment or schedule the
	 * caller may not read yields none.
	 *
	 * @param int $schedule_id    Schedule ID (0 for none).
	 * @param int $environment_id Environment ID (0 for none).
	 * @return list<EnvironmentRow>
	 */
	private function environments_in_scope( int $schedule_id, int $environment_id ): array {
		$readable = $this->readable_schedule_ids();

		if ( $environment_id > 0 ) {
			$environment = AudienceEnvironmentRepository::get_by_id( $environment_id );
			if ( ! $environment || 'active' !== ( $environment->status ?? 'active' ) ) {
				return array();
			}
			if ( null !== $readable && ! in_array( (int) $environment->schedule_id, $readable, true ) ) {
				return array();
			}
			return array( $environment );
		}

		if ( $schedule_id > 0 ) {
			$schedule_ids = array( $schedule_id );
		} elseif ( null !== $readable ) {
			$schedule_ids = $readable;
		} else {
			$schedule_ids = array();
			foreach ( AudienceScheduleRepository::get_all( array( 'status' => 'active' ) ) as $schedule ) {
				$schedule_ids[] = (int) $schedule->id;
			}
		}

		$environments = array();
		foreach ( $schedule_ids as $id ) {
			if ( null !== $readable && ! in_array( (int) $id, $readable, true ) ) {
				continue;
			}
			foreach ( AudienceEnvironmentRepository::get_by_schedule( (int) $id, 'active' ) as $environment ) {
				$environments[] = $environment;
			}
		}

		return $environments;
	}

	/**
	 * Get bookings
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_bookings( \WP_REST_Request $request ): \WP_REST_Response {
		$start_date     = $request->get_param( 'start_date' );
		$end_date       = $request->get_param( 'end_date' );
		$schedule_id    = $request->get_param( 'schedule_id' );
		$environment_id = $request->get_param( 'environment_id' );

		// Build query args.
		$args = array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'status'     => null, // Get all statuses.
		);

		if ( $schedule_id ) {
			$args['schedule_id'] = $schedule_id;
		}

		if ( $environment_id ) {
			$args['environment_id'] = $environment_id;
		}

		// Get bookings.
		$bookings = AudienceBookingReader::get_all( $args );

		// Enforce schedule visibility: drop any booking whose owning schedule
		// the caller may not read. Covers both the scoped request (a private
		// schedule_id/environment_id the caller can't see yields no rows) and
		// the unscoped request (which otherwise returns every schedule's
		// bookings, including private ones). Each row carries `schedule_id`
		// from the reader's INNER JOIN on the environments table.
		$readable_schedule_ids = $this->readable_schedule_ids();
		if ( null !== $readable_schedule_ids ) {
			$bookings = array_values(
				array_filter(
					$bookings,
					static function ( $booking ) use ( $readable_schedule_ids ) {
						return in_array( (int) $booking->schedule_id, $readable_schedule_ids, true );
					}
				)
			);
		}

		// Get user info for each booking.
		$user_id      = get_current_user_id();
		$is_logged_in = is_user_logged_in();
		$has_bypass   = AudienceScheduleRepository::user_has_bypass_capability();

		// Transform bookings for response.
		$bookings_data = array_map(
			function ( $booking ) use ( $user_id, $is_logged_in, $has_bypass ) {
				$audiences = AudienceBookingReader::get_booking_audiences( (int) $booking->id );

				// Non-logged-in users get public data (no personal details like created_by).
				if ( ! $is_logged_in ) {
					return array(
						'id'               => $booking->id,
						'environment_id'   => $booking->environment_id,
						'environment_name' => $booking->environment_name ?? '',
						'booking_date'     => $booking->booking_date,
						'start_time'       => $booking->start_time,
						'end_time'         => $booking->end_time,
						'is_all_day'       => $booking->is_all_day ?? 0,
						'booking_type'     => $booking->booking_type,
						'description'      => $booking->description,
						'status'           => $booking->status,
						'can_cancel'       => false,
						'audiences'        => array_map(
							function ( $a ) {
								return array(
									'id'    => $a->id,
									'name'  => $a->name,
									'color' => $a->color,
								);
							},
							$audiences
						),
					);
				}

				return array(
					'id'               => $booking->id,
					'environment_id'   => $booking->environment_id,
					'environment_name' => $booking->environment_name ?? '',
					'booking_date'     => $booking->booking_date,
					'start_time'       => $booking->start_time,
					'end_time'         => $booking->end_time,
					'is_all_day'       => $booking->is_all_day ?? 0,
					'booking_type'     => $booking->booking_type,
					'description'      => $booking->description,
					'status'           => $booking->status,
					'created_by'       => (int) $booking->created_by,
					'can_cancel'       => $has_bypass || (int) $booking->created_by === $user_id,
					'audiences'        => array_map(
						function ( $a ) {
							return array(
								'id'    => $a->id,
								'name'  => $a->name,
								'color' => $a->color,
							);
						},
						$audiences
					),
				);
			},
			$bookings
		);

		// Get schedule-specific holidays for the date range.
		$holidays = array();
		if ( $schedule_id ) {
			$holidays = AudienceEnvironmentRepository::get_holidays( (int) $schedule_id, $start_date, $end_date );
		}

		// Merge global holidays into the response.
		$global_holidays    = \FreeFormCertificate\Scheduling\DateBlockingService::get_global_holidays( $start_date, $end_date );
		$holidays_formatted = array_map(
			function ( $h ) {
				return array(
					'holiday_date' => $h->holiday_date,
					'description'  => $h->description,
				);
			},
			$holidays
		);

		foreach ( $global_holidays as $gh ) {
			$holidays_formatted[] = array(
				'holiday_date' => $gh['date'],
				'description'  => $gh['description'] ?? __( 'Holiday', 'ffcertificate' ),
			);
		}

		// Which spaces are open on which days (#1859). Without an environment
		// the calendar shows every space in scope, so a weekday renders closed
		// only when every one of them is closed on it, and the client reads the
		// per-space pattern to list the spaces open on a chosen date.
		$scope_environments = $this->environments_in_scope( $schedule_id ? (int) $schedule_id : 0, $environment_id ? (int) $environment_id : 0 );
		$availability       = AudienceBookingRules::availability( $scope_environments );
		$closed_weekdays    = AudienceBookingRules::shared_closed_weekdays( $availability );

		// Each schedule's own closed dates, keyed by schedule, so a holiday of
		// one calendar closes its spaces and no other's.
		$schedule_holidays = array();
		foreach ( array_unique( array_column( $availability, 'schedule_id' ) ) as $scope_schedule_id ) {
			$dates = array();
			foreach ( AudienceEnvironmentRepository::get_holidays( (int) $scope_schedule_id, $start_date, $end_date ) as $h ) {
				$dates[] = $h->holiday_date;
			}
			$schedule_holidays[ (string) $scope_schedule_id ] = $dates;
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'bookings'          => $bookings_data,
				'holidays'          => $holidays_formatted,
				'closed_weekdays'   => $closed_weekdays,
				'environments'      => $availability,
				'schedule_holidays' => $schedule_holidays,
				'global_holidays'   => array_column( $global_holidays, 'date' ),
			),
			200
		);
	}

	/**
	 * Create booking
	 *
	 * Every caller is held to the same rules (#1859): the calendar and the
	 * space must be active; the date must not be in the past or beyond the
	 * advance window, nor a holiday or a day the space is closed; the start
	 * AND the end must fall inside its opening hours; and the slot must be
	 * free. An all-day booking takes the space's opening window for the day.
	 *
	 * A holder of the audience bypass on this calendar may break any of the
	 * rules, and a holder of "Override Conflicts" may book over an occupied
	 * slot — but only by confirming: without `confirm_override` the answer is
	 * a 409 listing every reason and nothing is saved. A confirmed booking is
	 * written to the activity log, and the bookings it overrode are cancelled
	 * and their owners told by e-mail.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function create_booking( \WP_REST_Request $request ): \WP_REST_Response {
		$environment_id     = (int) $request->get_param( 'environment_id' );
		$booking_date       = (string) $request->get_param( 'booking_date' );
		$is_all_day         = ! empty( $request->get_param( 'is_all_day' ) );
		$start_time         = (string) $request->get_param( 'start_time' );
		$end_time           = (string) $request->get_param( 'end_time' );
		$booking_type       = $request->get_param( 'booking_type' );
		$description        = (string) $request->get_param( 'description' );
		$audience_ids_param = $request->get_param( 'audience_ids' );
		$audience_ids       = $audience_ids_param ? $audience_ids_param : array();
		$user_ids_param     = $request->get_param( 'user_ids' );
		$user_ids           = $user_ids_param ? $user_ids_param : array();
		$confirm_override   = ! empty( $request->get_param( 'confirm_override' ) );
		$end_date           = (string) $request->get_param( 'end_date' );
		$time_mode          = (string) $request->get_param( 'time_mode' );
		$time_mode          = '' === $time_mode ? 'daily' : $time_mode;
		$skip_blocked       = ! empty( $request->get_param( 'skip_blocked' ) );

		// Validate environment exists.
		$environment = AudienceEnvironmentRepository::get_by_id( $environment_id );
		if ( ! $environment ) {
			return self::refusal( 400, __( 'Invalid environment.', 'ffcertificate' ) );
		}

		$schedule_id = (int) $environment->schedule_id;
		$user_id     = get_current_user_id();

		// Check user permission on this schedule. Holding the audience bypass
		// capability is a permission to book even where the calendar's toggle
		// keeps the rules on.
		if ( ! AudienceScheduleRepository::user_has_bypass_capability() && ! AudienceScheduleRepository::user_can_book( $schedule_id, $user_id ) ) {
			return self::refusal( 403, __( 'You do not have permission to book on this calendar.', 'ffcertificate' ) );
		}

		// An inactive calendar or space refuses every caller (#1859).
		$schedule = AudienceScheduleRepository::get_by_id( $schedule_id );
		if ( ! $schedule || 'active' !== ( $schedule->status ?? 'active' ) || 'active' !== ( $environment->status ?? 'active' ) ) {
			return self::refusal( 400, __( 'This calendar is not accepting bookings.', 'ffcertificate' ), 'inactive' );
		}

		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $booking_date ) ) {
			return self::refusal( 400, __( 'Invalid date format.', 'ffcertificate' ) );
		}

		// A range covers every day from the start date to the end date, both included.
		$is_range = '' !== $end_date && $end_date !== $booking_date;
		if ( $is_range ) {
			if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $end_date ) || $end_date < $booking_date ) {
				return self::refusal( 400, __( 'The end date must be a valid date on or after the start date.', 'ffcertificate' ) );
			}

			if ( $is_all_day ) {
				return self::refusal( 400, __( 'An all-day booking covers one day.', 'ffcertificate' ) );
			}

			if ( count( AudienceBookingRules::range_days( $booking_date, $end_date ) ) > AudienceBookingRules::MAX_RANGE_DAYS ) {
				/* translators: %d: the most days one booking may cover. */
				return self::refusal( 400, sprintf( __( 'A booking can cover at most %d days.', 'ffcertificate' ), AudienceBookingRules::MAX_RANGE_DAYS ) );
			}
		} else {
			$end_date = $booking_date;
		}

		if ( ! in_array( $time_mode, array( 'daily', 'span' ), true ) ) {
			return self::refusal( 400, __( 'Invalid time mode.', 'ffcertificate' ) );
		}

		// Skipping blocked days only means something for a range.
		$skip_blocked = $is_range && $skip_blocked;

		// All day is the space's opening window for that day.
		if ( $is_all_day ) {
			$window     = AudienceBookingRules::all_day_window( $environment_id, $booking_date );
			$start_time = $window['start'];
			$end_time   = $window['end'];
		}

		// Validate time range. A span runs from one day to another, so its start may come after its end.
		$is_span = $is_range && 'span' === $time_mode;
		if ( ! $is_span && $start_time >= $end_time ) {
			return self::refusal( 400, __( 'End time must be after start time.', 'ffcertificate' ) );
		}

		// Validate description length.
		$desc_length = mb_strlen( $description );
		if ( $desc_length < 15 || $desc_length > 300 ) {
			return self::refusal( 400, __( 'Description must be between 15 and 300 characters.', 'ffcertificate' ) );
		}

		// Validate booking type has required data.
		if ( 'audience' === $booking_type && empty( $audience_ids ) ) {
			return self::refusal( 400, __( 'At least one audience must be selected.', 'ffcertificate' ) );
		}

		if ( 'individual' === $booking_type && empty( $user_ids ) ) {
			return self::refusal( 400, __( 'At least one user must be selected.', 'ffcertificate' ) );
		}

		$has_bypass = AudienceScheduleRepository::user_has_bypass( $schedule_id, $user_id );

		// The days this booking takes, after any skipped blocked days.
		$plan = AudienceBookingRules::occurrences( $environment_id, $booking_date, $end_date, $start_time, $end_time, $time_mode, $skip_blocked );
		if ( empty( $plan['days'] ) ) {
			return self::refusal( 400, __( 'Every day of the range is blocked.', 'ffcertificate' ) );
		}

		// The rules, checked on every day. A caller without the bypass is refused on the first one.
		$reasons = array();
		foreach ( $plan['days'] as $day ) {
			foreach ( AudienceBookingRules::violations( $schedule, $environment, $day['date'], $day['start_time'], $day['end_time'] ) as $reason ) {
				$reasons[ $reason['code'] ] = $reason;
			}
		}
		$reasons = array_values( $reasons );
		if ( ! empty( $reasons ) && ! $has_bypass ) {
			return self::refusal( 400, $reasons[0]['message'], $reasons[0]['code'], $reasons );
		}

		// Time slot conflicts (environment double-booking), checked on every day.
		$conflicts       = array();
		$colliding_dates = array();
		foreach ( $plan['days'] as $day ) {
			$found = AudienceBookingReader::get_conflicts( $environment_id, $day['date'], $day['start_time'], $day['end_time'] );
			if ( ! empty( $found ) ) {
				$colliding_dates[] = $day['date'];
				foreach ( $found as $conflict ) {
					$conflicts[ (int) $conflict->id ] = $conflict;
				}
			}
		}
		$conflicts = array_values( $conflicts );
		if ( ! empty( $conflicts ) ) {
			if ( ! AudienceScheduleRepository::user_can_override_conflicts( $schedule_id, $user_id ) ) {
				$refused                          = self::refusal( 400, __( 'This time slot is already booked for this environment.', 'ffcertificate' ), AudienceBookingRules::CONFLICT );
				$refused->data['colliding_dates'] = $colliding_dates;
				return $refused;
			}
			$reasons[] = AudienceBookingRules::reason( AudienceBookingRules::CONFLICT );
		}

		// Breaking a rule or a conflict needs an explicit confirmation.
		if ( ! empty( $reasons ) && ! $confirm_override ) {
			return new \WP_REST_Response(
				array(
					'success'         => false,
					'code'            => 'override_confirmation_required',
					'message'         => __( 'This booking breaks the calendar rules. Confirm to book anyway.', 'ffcertificate' ),
					'reasons'         => $reasons,
					'colliding_dates' => $colliding_dates,
					'conflicts'       => array_map(
						static function ( $b ) {
							return array(
								'id'          => (int) $b->id,
								'start_time'  => $b->start_time,
								'end_time'    => $b->end_time,
								'description' => $b->description ?? '',
							);
						},
						$conflicts
					),
				),
				409
			);
		}

		$booking_data = array(
			'environment_id' => $environment_id,
			'booking_date'   => $booking_date,
			'start_time'     => $start_time,
			'end_time'       => $end_time,
			'is_all_day'     => $is_all_day ? 1 : 0,
			'booking_type'   => $booking_type,
			'description'    => $description,
			'audience_ids'   => $audience_ids,
			'user_ids'       => $user_ids,
			'end_date'       => $end_date,
			'time_mode'      => $time_mode,
			'skip_blocked'   => $skip_blocked,
		);

		/**
		 * Fires before an audience booking is created.
		 *
		 * @since 4.6.4
		 * @param array $booking_data Booking data to be inserted.
		 */
		do_action( 'ffcertificate_before_audience_booking_create', $booking_data );

		// Create the booking.
		$booking_id = AudienceBookingWriter::create( $booking_data );

		if ( ! $booking_id ) {
			return self::refusal( 500, __( 'Failed to create booking.', 'ffcertificate' ) );
		}

		// The bookings this one overrode give way to it.
		$cancelled_ids = array();
		if ( ! empty( $conflicts ) ) {
			$reason = __( 'Overridden by a priority booking: the space is no longer available at this time.', 'ffcertificate' );
			foreach ( $conflicts as $conflict ) {
				if ( AudienceBookingWriter::cancel( (int) $conflict->id, $reason ) ) {
					$cancelled_ids[] = (int) $conflict->id;

					/**
					 * Fires when a booking is cancelled because a confirmed
					 * override took its slot (#1859).
					 *
					 * @since 6.40.0
					 * @param int    $cancelled_id The booking that gave way.
					 * @param int    $booking_id   The booking that took the slot.
					 * @param string $reason       The cancellation reason.
					 */
					do_action( 'ffcertificate_audience_booking_overridden', (int) $conflict->id, (int) $booking_id, $reason );
				}
			}
		}

		if ( ! empty( $reasons ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'audience_booking_override',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'booking_id'         => (int) $booking_id,
					'schedule_id'        => $schedule_id,
					'environment_id'     => $environment_id,
					'booking_date'       => $booking_date,
					'start_time'         => $start_time,
					'end_time'           => $end_time,
					'reasons'            => array_column( $reasons, 'code' ),
					'cancelled_bookings' => $cancelled_ids,
					'confirmed_at'       => time(),
				),
				$user_id
			);
		}

		// Trigger notification hook.
		do_action( 'ffcertificate_audience_booking_created', $booking_id );

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'booking_id' => $booking_id,
				'message'    => __( 'Booking created successfully.', 'ffcertificate' ),
			),
			201
		);
	}

	/**
	 * A refused booking, in the response shape the calendar reads.
	 *
	 * @param int                                             $status  HTTP status.
	 * @param string                                          $message What the person reads.
	 * @param string                                          $code    Machine-readable reason, '' for none.
	 * @param list<array{code: string, message: string}>|null $reasons Every rule broken, when known.
	 * @return \WP_REST_Response
	 */
	private static function refusal( int $status, string $message, string $code = '', ?array $reasons = null ): \WP_REST_Response {
		$body = array(
			'success' => false,
			'message' => $message,
		);
		if ( '' !== $code ) {
			$body['code'] = $code;
		}
		if ( null !== $reasons ) {
			$body['reasons'] = $reasons;
		}

		return new \WP_REST_Response( $body, $status );
	}

	/**
	 * Cancel booking
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function cancel_booking( \WP_REST_Request $request ): \WP_REST_Response {
		$booking_id = $request->get_param( 'id' );
		$reason     = $request->get_param( 'reason' );

		// Validate reason.
		if ( empty( $reason ) || mb_strlen( $reason ) < 5 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Cancellation reason is required.', 'ffcertificate' ),
				),
				400
			);
		}

		$booking = AudienceBookingReader::get_by_id( $booking_id );
		if ( ! $booking ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Booking not found.', 'ffcertificate' ),
				),
				404
			);
		}

		if ( 'cancelled' === $booking->status ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Booking is already cancelled.', 'ffcertificate' ),
				),
				400
			);
		}

		// Cancel the booking.
		$result = AudienceBookingWriter::cancel( $booking_id, $reason );

		if ( ! $result ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to cancel booking.', 'ffcertificate' ),
				),
				500
			);
		}

		// Trigger notification hook.
		do_action( 'ffcertificate_audience_booking_cancelled', $booking_id, $reason );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Booking cancelled successfully.', 'ffcertificate' ),
			),
			200
		);
	}

	/**
	 * Check conflicts
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function check_conflicts( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$environment_id     = (int) $request->get_param( 'environment_id' );
			$booking_date       = sanitize_text_field( $request->get_param( 'booking_date' ) );
			$start_time         = sanitize_text_field( $request->get_param( 'start_time' ) );
			$end_time           = sanitize_text_field( $request->get_param( 'end_time' ) );
			$audience_ids_param = $request->get_param( 'audience_ids' );
			$audience_ids       = array_map( 'intval', (array) ( $audience_ids_param ? $audience_ids_param : array() ) );
			$user_ids_param     = $request->get_param( 'user_ids' );
			$user_ids           = array_map( 'intval', (array) ( $user_ids_param ? $user_ids_param : array() ) );

			// Validate required parameters.
			if ( ! $environment_id || ! $booking_date || ! $start_time || ! $end_time ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Missing required parameters.', 'ffcertificate' ),
					),
					400
				);
			}

			// The environment must exist and belong to a schedule the caller
			// may read; both refusals read the same, so the probe does not
			// tell an environment that exists from one that does not (#1861).
			$environment           = AudienceEnvironmentRepository::get_by_id( $environment_id );
			$readable_schedule_ids = $this->readable_schedule_ids();
			if ( ! $environment
				|| ( null !== $readable_schedule_ids
					&& ! in_array( (int) $environment->schedule_id, $readable_schedule_ids, true ) ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Environment not found.', 'ffcertificate' ),
					),
					404
				);
			}

			// Whether the caller may book over a hard conflict (#1859): the
			// form then offers the booking, behind the override warning,
			// instead of blocking it.
			$response_data = array(
				'type'              => 'none',
				'bookings'          => array(),
				'affected_users'    => array(),
				'audience_same_day' => array(),
				'can_override'      => AudienceScheduleRepository::user_can_override_conflicts( (int) $environment->schedule_id, get_current_user_id() ),
			);

			// Determine if this schedule is isolated (ignores cross-schedule conflicts).
			$scope_schedule_id = null;
			$schedule          = AudienceScheduleRepository::get_by_id( (int) $environment->schedule_id );
			if ( $schedule && ! empty( $schedule->is_isolated ) ) {
				$scope_schedule_id = (int) $schedule->id;
			}

			$is_hard_conflict = false;

			// 1. Check environment time slot conflicts (hard conflict)
			$env_conflicts = AudienceBookingReader::get_conflicts( $environment_id, $booking_date, $start_time, $end_time );
			if ( ! empty( $env_conflicts ) ) {
				$response_data['type']     = 'environment';
				$response_data['message']  = __( 'Time slot already booked for this environment.', 'ffcertificate' );
				$response_data['bookings'] = array_map(
					function ( $b ) {
						return array(
							'id'         => $b->id,
							'start_time' => $b->start_time,
							'end_time'   => $b->end_time,
						);
					},
					$env_conflicts
				);
				$is_hard_conflict          = true;
			}

			// 2. Check same audience group on same day (soft conflict)
			if ( ! $is_hard_conflict && ! empty( $audience_ids ) ) {
				$same_day = AudienceBookingReader::get_audience_same_day_bookings( $booking_date, $audience_ids, null, $scope_schedule_id, $readable_schedule_ids );
				if ( ! empty( $same_day ) ) {
					$response_data['audience_same_day'] = array_map(
						function ( $b ) {
							return array(
								'id'            => (int) $b->id,
								'start_time'    => $b->start_time,
								'end_time'      => $b->end_time,
								'description'   => $b->description ?? '',
								'audience_name' => $b->audience_name,
							);
						},
						$same_day
					);
				}
			}

			// 3. Check user conflicts — members with overlapping bookings (soft conflict)
			// Only check if no hard conflict detected.
			if ( ! $is_hard_conflict ) {
				$user_conflicts = AudienceBookingReader::get_user_conflicts(
					$booking_date,
					$start_time,
					$end_time,
					$audience_ids,
					$user_ids,
					null,
					$scope_schedule_id,
					$readable_schedule_ids
				);

				if ( ! empty( $user_conflicts['bookings'] ) ) {
					$response_data['type']           = 'user';
					$response_data['bookings']       = array_map(
						function ( $b ) {
							return array(
								'id'          => $b->id,
								'start_time'  => $b->start_time,
								'end_time'    => $b->end_time,
								'description' => $b->description,
							);
						},
						$user_conflicts['bookings']
					);
					$response_data['affected_users'] = $user_conflicts['affected_users'];
				}

				// Audience same-day is also a soft conflict.
				if ( 'none' === $response_data['type'] && ! empty( $response_data['audience_same_day'] ) ) {
					$response_data['type'] = 'audience_same_day';
				}
			}

			return new \WP_REST_Response(
				array(
					'success'   => true,
					'conflicts' => $response_data,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Error checking conflicts.', 'ffcertificate' ),
				),
				500
			);
		}
	}
}
