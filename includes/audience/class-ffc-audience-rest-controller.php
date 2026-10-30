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

use FreeFormCertificate\Scheduling\ClosedDateKind;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for audience endpoints.
 *
 * @phpstan-import-type EnvironmentRow from AudienceEnvironmentRepository
 * @phpstan-import-type ScheduleRow from AudienceScheduleRepository
 */
class AudienceRestController {

	use \FreeFormCertificate\API\ReadRateLimitGuardTrait;

	/**
	 * Namespace for REST routes
	 */
	private const NAMESPACE = 'ffc/v1';

	/**
	 * Resource base
	 */
	private const REST_BASE = 'audience';

	/**
	 * Most days one `GET /bookings` may cover, both ends included (#2243).
	 * The month view shows up to six weeks, so 62 leaves room for both.
	 */
	public const MAX_READ_SPAN_DAYS = 62;

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
					// Why an override takes days from other bookings, sent to
					// their owners and participants (#2243).
					'override_reason'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					// A weekly series (#2243): the last day it may reach, the
					// dates the booker chose to leave out, and whether this
					// call only lists what each date would meet.
					'repeat_until'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'skip_dates'       => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'preview'          => array(
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
					'scope'  => array(
						'type'    => 'string',
						'enum'    => array( 'this', AudienceBookingSeries::SCOPE_FOLLOWING ),
						'default' => 'this',
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

		$booking_id = $request->get_param( 'id' );
		$booking    = AudienceBookingReader::get_by_id( is_numeric( $booking_id ) ? (int) $booking_id : 0 );

		if ( ! $booking ) {
			return false;
		}

		// Creator can cancel their own booking.
		if ( get_current_user_id() === (int) $booking->created_by ) {
			return true;
		}

		$environment = AudienceEnvironmentRepository::get_by_id( (int) $booking->environment_id );
		if ( ! $environment ) {
			return false;
		}

		// The audience bypass cancels anyone's booking only where the
		// calendar's switch lets it, as it does for creating (#2243); with the
		// switch off its holder needs the cancel-others permission like
		// everyone else, which administrators keep.
		$schedule_id = (int) $environment->schedule_id;
		return AudienceScheduleRepository::user_has_bypass( $schedule_id )
			|| AudienceScheduleRepository::user_can_cancel_others( $schedule_id, get_current_user_id() );
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
		$start_date     = (string) $request->get_param( 'start_date' );
		$end_date       = (string) $request->get_param( 'end_date' );
		$schedule_id    = $request->get_param( 'schedule_id' );
		$environment_id = $request->get_param( 'environment_id' );

		// The month read is the one a script would loop over to copy the
		// calendar, so it takes the read rate limit, for logged-in callers
		// too, counted by account (#2243). The span cap below bounds one
		// request; this bounds how many.
		$blocked = $this->guard_read( 'audience_bookings', true );
		if ( null !== $blocked ) {
			$data = $blocked->get_error_data();
			$wait = is_array( $data ) && is_array( $data['headers'] ?? null ) ? (int) ( $data['headers']['Retry-After'] ?? 60 ) : 60;
			return new \WP_REST_Response(
				self::refusal( 429, $blocked->get_error_message(), 'rate_limit_exceeded' )->get_data(),
				429,
				array( 'Retry-After' => (string) max( 1, $wait ) )
			);
		}

		// The read is public, so its span is bounded (#2243): the month view
		// asks for at most six weeks, and anything wider is refused rather
		// than read.
		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $start_date )
			|| ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $end_date )
			|| $end_date < $start_date ) {
			return self::refusal( 400, __( 'Invalid date range.', 'ffcertificate' ) );
		}
		if ( AudienceBookingRules::range_length( $start_date, $end_date ) > self::MAX_READ_SPAN_DAYS ) {
			/* translators: %d: the most days one calendar read may cover. */
			return self::refusal( 400, sprintf( __( 'A calendar read can cover at most %d days.', 'ffcertificate' ), self::MAX_READ_SPAN_DAYS ) );
		}

		// Build query args. The calendars the caller may read are filtered
		// in the query, so a private calendar's rows are never fetched.
		$args = array(
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'status'       => null, // Get all statuses.
			'schedule_ids' => $this->readable_schedule_ids(),
		);

		if ( $schedule_id ) {
			$args['schedule_id'] = $schedule_id;
		}

		if ( $environment_id ) {
			$args['environment_id'] = $environment_id;
		}

		$bookings = AudienceBookingReader::get_all( $args );

		// Every booking's audiences, in one query.
		$audiences_by_booking = AudienceBookingReader::get_audiences_for_bookings(
			array_map( static fn( $booking ): int => (int) $booking->id, $bookings )
		);

		// Get user info for each booking.
		$user_id      = get_current_user_id();
		$is_logged_in = is_user_logged_in();
		// Whether the caller cancels others' bookings, per calendar: the
		// bypass counts only where the calendar's switch allows it (#2243).
		$cancels_others = array();
		$has_bypass     = static function ( $booking ) use ( &$cancels_others, $user_id ): bool {
			$schedule_id = (int) ( $booking->schedule_id ?? 0 );
			if ( ! isset( $cancels_others[ $schedule_id ] ) ) {
				$cancels_others[ $schedule_id ] = $user_id > 0 && AudienceScheduleRepository::user_has_bypass( $schedule_id );
			}
			return $cancels_others[ $schedule_id ];
		};

		// Transform bookings for response.
		$bookings_data = array_map(
			function ( $booking ) use ( $user_id, $is_logged_in, $has_bypass, $audiences_by_booking ) {
				$audiences = $audiences_by_booking[ (int) $booking->id ] ?? array();

				// Non-logged-in users get public data (no personal details like created_by).
				if ( ! $is_logged_in ) {
					return array(
						'id'               => $booking->id,
						'environment_id'   => $booking->environment_id,
						'environment_name' => $booking->environment_name ?? '',
						'booking_date'     => $booking->booking_date,
						'end_date'         => $booking->end_date ?? null,
						'time_mode'        => $booking->time_mode ?? null,
						'days'             => AudienceBookingRules::booked_days( (string) $booking->booking_date, $booking->end_date ?? null, $booking->skipped_dates ?? null ),
						'day_times'        => (object) AudienceBookingRules::day_times_map( $booking ),
						'date_label'       => AudienceBookingRules::date_label( (string) $booking->booking_date, $booking->end_date ?? null, static fn( string $date ): string => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date ) ),
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
					'end_date'         => $booking->end_date ?? null,
					'time_mode'        => $booking->time_mode ?? null,
					'days'             => AudienceBookingRules::booked_days( (string) $booking->booking_date, $booking->end_date ?? null, $booking->skipped_dates ?? null ),
					'day_times'        => (object) AudienceBookingRules::day_times_map( $booking ),
					'date_label'       => AudienceBookingRules::date_label( (string) $booking->booking_date, $booking->end_date ?? null, static fn( string $date ): string => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date ) ),
					'start_time'       => $booking->start_time,
					'end_time'         => $booking->end_time,
					'is_all_day'       => $booking->is_all_day ?? 0,
					'booking_type'     => $booking->booking_type,
					'description'      => $booking->description,
					'status'           => $booking->status,
					'created_by'       => (int) $booking->created_by,
					'series_id'        => (int) ( $booking->series_id ?? 0 ),
					'can_cancel'       => $has_bypass( $booking ) || (int) $booking->created_by === $user_id,
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
					'kind'         => ClosedDateKind::normalize( $h->kind ?? null ),
				);
			},
			$holidays
		);

		// What each closed date is (#2202), for the cell's label. A date
		// closed as a holiday anywhere in scope reads as a holiday.
		$holiday_kinds = array();
		$note_kind     = static function ( string $date, $kind ) use ( &$holiday_kinds ): void {
			$kind = ClosedDateKind::normalize( $kind );
			if ( ! isset( $holiday_kinds[ $date ] ) || ClosedDateKind::HOLIDAY === $kind ) {
				$holiday_kinds[ $date ] = $kind;
			}
		};

		foreach ( $global_holidays as $gh ) {
			$holidays_formatted[] = array(
				'holiday_date' => $gh['date'],
				'description'  => $gh['description'] ?? __( 'Holiday', 'ffcertificate' ),
				'kind'         => ClosedDateKind::normalize( $gh['kind'] ?? null ),
			);
			$note_kind( (string) $gh['date'], $gh['kind'] ?? null );
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
				$note_kind( (string) $h->holiday_date, $h->kind ?? null );
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
				'holiday_kinds'     => (object) $holiday_kinds,
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
		$override_reason    = trim( (string) $request->get_param( 'override_reason' ) );
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

			if ( AudienceBookingRules::range_length( $booking_date, $end_date ) > AudienceBookingRules::max_range_days() ) {
				/* translators: %d: the most consecutive days one booking may cover. */
				return self::refusal( 400, sprintf( __( 'A booking can cover at most %d consecutive days.', 'ffcertificate' ), AudienceBookingRules::max_range_days() ) );
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

		// Only the participants the caller may book for (#2243).
		$audience_ids = self::positive_ids( $audience_ids );
		$user_ids     = self::positive_ids( $user_ids );
		$refused      = self::participant_refusal( (string) $booking_type, $audience_ids, $user_ids, $user_id );
		if ( null !== $refused ) {
			return $refused;
		}

		// A weekly series repeats this one day (#2243).
		$repeat_until = (string) $request->get_param( 'repeat_until' );
		if ( '' !== $repeat_until ) {
			if ( $is_range ) {
				return self::refusal( 400, __( 'A weekly series repeats one day. Choose one day, not several.', 'ffcertificate' ) );
			}
			return $this->create_series(
				$request,
				$schedule,
				$environment,
				array(
					'environment_id' => $environment_id,
					'booking_date'   => $booking_date,
					'start_time'     => $start_time,
					'end_time'       => $end_time,
					'is_all_day'     => $is_all_day ? 1 : 0,
					'booking_type'   => $booking_type,
					'description'    => $description,
					'audience_ids'   => $audience_ids,
					'user_ids'       => $user_ids,
				),
				$repeat_until
			);
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
			// Overriding takes only the days that clash (#2243): the other
			// booking keeps its other days, attendance and invitations.
			$conflict_reason            = AudienceBookingRules::reason( AudienceBookingRules::CONFLICT );
			$conflict_reason['message'] = __( 'This time slot is already booked for this environment. Confirming cancels that booking on the days that clash; its other days stay booked.', 'ffcertificate' );
			$reasons[]                  = $conflict_reason;
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
					'reason_required' => empty( $conflicts ) ? null : array(
						'min' => AudienceBookingRules::OVERRIDE_REASON_MIN,
						'max' => AudienceBookingRules::OVERRIDE_REASON_MAX,
					),
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

		// The people who lose days are told why, in the operator's words (#2243).
		if ( ! empty( $conflicts ) ) {
			$reason_length = mb_strlen( $override_reason );
			if ( $reason_length < AudienceBookingRules::OVERRIDE_REASON_MIN || $reason_length > AudienceBookingRules::OVERRIDE_REASON_MAX ) {
				return self::refusal(
					400,
					/* translators: 1: fewest characters, 2: most characters. */
					sprintf( __( 'Write why the other booking gives way, between %1$d and %2$d characters. It is sent to the people who lose those days.', 'ffcertificate' ), AudienceBookingRules::OVERRIDE_REASON_MIN, AudienceBookingRules::OVERRIDE_REASON_MAX ),
					'override_reason_required'
				);
			}
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

		// The bookings this one overrides give way to it on the days that
		// clash. The writer takes those days in the transaction that inserts
		// this one: doing it after the insert left the insert to find them
		// still active and refuse.
		if ( ! empty( $conflicts ) ) {
			$booking_data['override_ids']  = array_map( static fn( $conflict ): int => (int) $conflict->id, $conflicts );
			$booking_data['override_note'] = $override_reason;
		}

		/**
		 * Fires before an audience booking is created.
		 *
		 * @since 4.6.4
		 * @param array $booking_data Booking data to be inserted.
		 */
		do_action( 'ffcertificate_before_audience_booking_create', $booking_data );

		// Create the booking.
		$overridden = array();
		$booking_id = AudienceBookingWriter::create( $booking_data, $overridden );

		if ( ! $booking_id ) {
			return self::refusal( 500, __( 'Failed to create booking.', 'ffcertificate' ) );
		}

		$cancelled_ids = array();
		$trimmed       = array();
		foreach ( $overridden as $overridden_id => $lost_days ) {
			if ( empty( $lost_days ) ) {
				$cancelled_ids[] = (int) $overridden_id;
			} else {
				$trimmed[ (int) $overridden_id ] = $lost_days;
			}

			/**
			 * Fires when a booking gives way to a confirmed override (#1859):
			 * cancelled whole, or only on the days that clashed (#2243).
			 *
			 * @since 6.40.0
			 * @param int      $overridden_id The booking that gave way.
			 * @param int      $booking_id    The booking that took the slot.
			 * @param string   $reason        The operator's reason.
			 * @param string[] $lost_days     The days it lost (Y-m-d); empty when it was cancelled whole.
			 */
			do_action( 'ffcertificate_audience_booking_overridden', (int) $overridden_id, (int) $booking_id, $override_reason, $lost_days );
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
					'trimmed_bookings'   => $trimmed,
					'override_reason'    => $override_reason,
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
	 * Create a weekly series, or list what each of its dates would meet
	 * (#2243).
	 *
	 * Every occurrence is an ordinary booking: the calendar's rules and the
	 * room's conflicts are checked on each date, the way a single booking is
	 * checked. A date that meets any of them is listed with the reasons and
	 * left out, never overridden; the override stays a decision about one
	 * booking. The same call serves the preview and the creation, so what
	 * the booker was shown is what is booked: a date that went wrong between
	 * the two answers again with the fresh list instead of being booked.
	 *
	 * The occurrences are tied by `series_id`. Each fires the usual
	 * `ffcertificate_audience_booking_created`, and the e-mail is sent once,
	 * for the first, listing every date.
	 *
	 * @param \WP_REST_Request    $request      Request.
	 * @param object              $schedule     The calendar.
	 * @param object              $environment  The space.
	 * @param array<string,mixed> $booking_data The fields every occurrence shares, with the first date.
	 * @param string              $repeat_until Last day the series may reach (Y-m-d).
	 * @phpstan-param ScheduleRow $schedule
	 * @phpstan-param EnvironmentRow $environment
	 * @return \WP_REST_Response
	 */
	private function create_series( \WP_REST_Request $request, object $schedule, object $environment, array $booking_data, string $repeat_until ): \WP_REST_Response {
		$first_date = (string) $booking_data['booking_date'];

		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $repeat_until ) || $repeat_until <= $first_date ) {
			return self::refusal( 400, __( 'Repeat until must be a valid date after the first one.', 'ffcertificate' ) );
		}
		if ( AudienceBookingRules::weekly_count( $first_date, $repeat_until ) > AudienceBookingRules::SERIES_MAX_WEEKS ) {
			/* translators: %d: the most weeks a series may repeat. */
			return self::refusal( 400, sprintf( __( 'A weekly series can repeat for at most %d weeks.', 'ffcertificate' ), AudienceBookingRules::SERIES_MAX_WEEKS ), 'series_too_long' );
		}

		$environment_id = (int) $environment->id;
		$is_all_day     = ! empty( $booking_data['is_all_day'] );
		$skip_dates     = array_values( array_filter( array_map( 'strval', (array) $request->get_param( 'skip_dates' ) ), array( \FreeFormCertificate\Core\DateFormatter::class, 'is_valid_date' ) ) );

		$occurrences = array();
		foreach ( AudienceBookingRules::weekly_dates( $first_date, $repeat_until ) as $date ) {
			$start_time = (string) $booking_data['start_time'];
			$end_time   = (string) $booking_data['end_time'];
			if ( $is_all_day ) {
				$window     = AudienceBookingRules::all_day_window( $environment_id, $date );
				$start_time = $window['start'];
				$end_time   = $window['end'];
			}

			$problems = AudienceBookingRules::violations( $schedule, $environment, $date, $start_time, $end_time );
			if ( ! empty( AudienceBookingReader::get_conflicts( $environment_id, $date, $start_time, $end_time ) ) ) {
				$problems[] = AudienceBookingRules::reason( AudienceBookingRules::CONFLICT );
			}

			$occurrences[] = array(
				'date'       => $date,
				'start_time' => $start_time,
				'end_time'   => $end_time,
				'problems'   => $problems,
				'skipped'    => in_array( $date, $skip_dates, true ),
			);
		}

		$listed   = array_map(
			static function ( array $occurrence ): array {
				return array(
					'date'     => $occurrence['date'],
					'label'    => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $occurrence['date'] ),
					'problems' => $occurrence['problems'],
				);
			},
			$occurrences
		);
		$bookable = array_values( array_filter( $occurrences, static fn( array $o ): bool => empty( $o['problems'] ) ) );

		if ( ! empty( $request->get_param( 'preview' ) ) ) {
			return new \WP_REST_Response(
				array(
					'success'     => true,
					'preview'     => true,
					'occurrences' => $listed,
					'bookable'    => count( $bookable ),
				),
				200
			);
		}

		// A date with a problem the booker did not agree to leave out:
		// something changed since the preview.
		foreach ( $occurrences as $occurrence ) {
			if ( ! empty( $occurrence['problems'] ) && ! $occurrence['skipped'] ) {
				return new \WP_REST_Response(
					array(
						'success'     => false,
						'code'        => 'series_changed',
						'message'     => __( 'Some dates changed since the check. Review the list and confirm again.', 'ffcertificate' ),
						'occurrences' => $listed,
						'bookable'    => count( $bookable ),
					),
					409
				);
			}
		}

		$to_book = array_values( array_filter( $bookable, static fn( array $o ): bool => ! $o['skipped'] ) );
		if ( empty( $to_book ) ) {
			return self::refusal( 400, __( 'No date of the series can be booked.', 'ffcertificate' ), 'series_empty' );
		}

		$created = array();
		$failed  = array();
		foreach ( $to_book as $occurrence ) {
			$data = array_merge(
				$booking_data,
				array(
					'booking_date' => $occurrence['date'],
					'start_time'   => $occurrence['start_time'],
					'end_time'     => $occurrence['end_time'],
					'end_date'     => $occurrence['date'],
					'time_mode'    => 'daily',
					'skip_blocked' => false,
				)
			);

			/** This action is documented in create_booking(). */
			do_action( 'ffcertificate_before_audience_booking_create', $data );

			$booking_id = AudienceBookingWriter::create( $data );
			if ( $booking_id ) {
				$created[] = (int) $booking_id;
			} else {
				$failed[] = $occurrence['date'];
			}
		}

		if ( empty( $created ) ) {
			return self::refusal( 500, __( 'Failed to create booking.', 'ffcertificate' ) );
		}

		if ( count( $created ) > 1 ) {
			AudienceBookingWriter::assign_series( $created );
		}

		foreach ( $created as $booking_id ) {
			/** This action is documented in create_booking(). */
			do_action( 'ffcertificate_audience_booking_created', $booking_id );
		}

		$message = sprintf(
			/* translators: %d: how many weekly bookings were created. */
			_n( '%d weekly booking created.', '%d weekly bookings created.', count( $created ), 'ffcertificate' ),
			count( $created )
		);
		if ( ! empty( $failed ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the dates that could not be booked, comma-separated. */
				__( 'These dates could not be booked: %s.', 'ffcertificate' ),
				implode( ', ', array_map( array( \FreeFormCertificate\Core\DateFormatter::class, 'format_wallclock_date' ), $failed ) )
			);
		}

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'booking_id'   => $created[0],
				'booking_ids'  => $created,
				'failed_dates' => $failed,
				'message'      => $message,
			),
			201
		);
	}

	/**
	 * The distinct positive integers of a request's id list.
	 *
	 * @param mixed $ids The raw parameter.
	 * @return list<int>
	 */
	private static function positive_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[ (int) $id ] = (int) $id;
			}
		}

		return array_values( $out );
	}

	/**
	 * Why the caller may not book these participants, or null when it may (#2243).
	 *
	 * The calendar only offers a booker their own audiences, but the route
	 * took any ids, so a caller who could book anywhere could reach every
	 * member of any audience: the e-mail, the dashboard, the check-in and the
	 * post-event invite. A WordPress administrator or a holder of the
	 * audience bypass may book any audience; everyone else books the
	 * audiences they belong to, or one of those audiences' ancestors, as the
	 * calendar offers them. An individual booking names people directly, so
	 * it takes the right to manage audiences. Every id must exist.
	 *
	 * @param string     $booking_type `audience` or `individual`.
	 * @param array<int> $audience_ids Requested audiences.
	 * @param array<int> $user_ids     Requested people.
	 * @param int        $user_id      The caller.
	 * @return \WP_REST_Response|null
	 */
	private static function participant_refusal( string $booking_type, array $audience_ids, array $user_ids, int $user_id ): ?\WP_REST_Response {
		$privileged = AudienceScheduleRepository::user_has_bypass_capability();

		if ( 'individual' === $booking_type && ! AudienceBookingRules::can_book_individuals() ) {
			return self::refusal( 403, __( 'You do not have permission to book for individual users.', 'ffcertificate' ) );
		}

		foreach ( $user_ids as $id ) {
			if ( ! get_userdata( $id ) ) {
				return self::refusal( 400, __( 'One of the selected users does not exist.', 'ffcertificate' ) );
			}
		}

		$own_ids = null;
		foreach ( $audience_ids as $id ) {
			if ( null === AudienceReader::get_by_id( $id ) ) {
				return self::refusal( 400, __( 'One of the selected audiences does not exist.', 'ffcertificate' ) );
			}
			if ( $privileged ) {
				continue;
			}
			if ( null === $own_ids ) {
				$own_ids = array_map( static fn( $audience ): int => (int) $audience->id, AudienceReader::get_user_audiences( $user_id, true ) );
			}
			if ( ! in_array( $id, $own_ids, true ) ) {
				return self::refusal( 403, __( 'You can only book for audiences you belong to.', 'ffcertificate' ) );
			}
		}

		return null;
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

		// A date of a weekly series may take every later date with it, and
		// the series is told in one e-mail (#2243).
		if ( AudienceBookingSeries::SCOPE_FOLLOWING === $request->get_param( 'scope' ) && (int) ( $booking->series_id ?? 0 ) > 0 ) {
			$cancelled = AudienceBookingSeries::cancel_following( $booking, $reason );
			if ( array() === $cancelled ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Booking is already cancelled.', 'ffcertificate' ),
					),
					400
				);
			}

			return new \WP_REST_Response(
				array(
					'success'   => true,
					'cancelled' => $cancelled,
					'message'   => sprintf(
						/* translators: %d: how many dates of the series were cancelled. */
						_n( '%d date of the series cancelled.', '%d dates of the series cancelled.', count( $cancelled ), 'ffcertificate' ),
						count( $cancelled )
					),
				),
				200
			);
		}

		// Cancel the booking. A request that lost the race to another one
		// changes nothing and is told so, without a second e-mail (#2243).
		$result = AudienceBookingWriter::cancel( $booking_id, $reason );

		if ( ! $result && AudienceBookingReader::is_cancelled( (int) $booking_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Booking is already cancelled.', 'ffcertificate' ),
				),
				400
			);
		}

		if ( ! $result ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to cancel booking.', 'ffcertificate' ),
				),
				500
			);
		}

		/**
		 * Fires after an audience booking is cancelled.
		 *
		 * @param int                  $booking_id The cancelled booking.
		 * @param string               $reason     The cancellation reason.
		 * @param array<string, mixed> $context    Empty for one booking; for dates of a weekly
		 *                                         series cancelled together, `series_lead` (the
		 *                                         first id), `series_ids` and `series_dates`.
		 */
		do_action( 'ffcertificate_audience_booking_cancelled', $booking_id, $reason, array() );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Booking cancelled successfully.', 'ffcertificate' ),
			),
			200
		);
	}

	/**
	 * The last day the conflict check covers: the range's end date when the
	 * request names a valid range the create endpoint would accept, the
	 * start date otherwise. A malformed or oversized range is checked as
	 * its first day, and the create endpoint refuses it with the reason.
	 *
	 * @param \WP_REST_Request $request      Request object.
	 * @param string           $booking_date Start date (Y-m-d).
	 * @return string
	 */
	private function conflict_end_date( \WP_REST_Request $request, string $booking_date ): string {
		$end_date = sanitize_text_field( (string) $request->get_param( 'end_date' ) );

		if ( '' === $end_date
			|| ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $booking_date )
			|| ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $end_date )
			|| $end_date <= $booking_date
			|| AudienceBookingRules::range_length( $booking_date, $end_date ) > AudienceBookingRules::max_range_days() ) {
			return $booking_date;
		}

		return $end_date;
	}

	/**
	 * Display names of the people a booking would clash for (#2243): the
	 * first ten in name order, and how many more there are. One query.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return array{names: list<string>, more: int}
	 */
	private static function affected_names( array $user_ids ): array {
		$limit = 10;
		if ( empty( $user_ids ) ) {
			return array(
				'names' => array(),
				'more'  => 0,
			);
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'display_name',
				'number'  => $limit,
				'fields'  => array( 'display_name' ),
			)
		);
		$names = array();
		foreach ( $users as $user ) {
			$names[] = (string) $user->display_name;
		}

		return array(
			'names' => $names,
			'more'  => max( 0, count( $user_ids ) - count( $names ) ),
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

			if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $booking_date ) ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Invalid date format.', 'ffcertificate' ),
					),
					400
				);
			}

			// A range longer than the cap is refused here, with the message the
			// create endpoint gives, so the check does not pass a booking the
			// next step would refuse (#2243).
			$end_date = sanitize_text_field( (string) $request->get_param( 'end_date' ) );
			if ( '' !== $end_date
				&& \FreeFormCertificate\Core\DateFormatter::is_valid_date( $end_date )
				&& $end_date > $booking_date
				&& AudienceBookingRules::range_length( $booking_date, $end_date ) > AudienceBookingRules::max_range_days() ) {
				return self::refusal(
					400,
					/* translators: %d: the most consecutive days one booking may cover. */
					sprintf( __( 'A booking can cover at most %d consecutive days.', 'ffcertificate' ), AudienceBookingRules::max_range_days() ),
					'range_too_long'
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

			// The days the booking would take: one for a single day, every
			// booked day of a range with the hours each one takes. The checks
			// below run on each of them, as the create endpoint does.
			$plan = AudienceBookingRules::occurrences(
				$environment_id,
				$booking_date,
				$this->conflict_end_date( $request, $booking_date ),
				$start_time,
				$end_time,
				'span' === $request->get_param( 'time_mode' ) ? 'span' : 'daily',
				! empty( $request->get_param( 'skip_blocked' ) )
			);

			// 1. Check environment time slot conflicts (hard conflict)
			$env_conflicts = array();
			foreach ( $plan['days'] as $day ) {
				foreach ( AudienceBookingReader::get_conflicts( $environment_id, $day['date'], $day['start_time'], $day['end_time'] ) as $conflict ) {
					$env_conflicts[ (int) $conflict->id ] = $conflict;
				}
			}
			$env_conflicts = array_values( $env_conflicts );
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

			$env_ids = array_map( static fn( $conflict ): int => (int) $conflict->id, $env_conflicts );

			// 2. Check same audience group on same day (soft conflict). Run
			// with a room clash too, so a booker who overrides it learns
			// everything in one round (#2243).
			if ( ! empty( $audience_ids ) ) {
				$same_day = array();
				foreach ( $plan['days'] as $day ) {
					foreach ( AudienceBookingReader::get_audience_same_day_bookings( $day['date'], $audience_ids, null, $scope_schedule_id, $readable_schedule_ids ) as $found ) {
						if ( in_array( (int) $found->id, $env_ids, true ) ) {
							continue;
						}
						$same_day[ (int) $found->id . '-' . (string) ( $found->audience_name ?? '' ) ] = $found;
					}
				}
				$same_day = array_values( $same_day );
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

			// 3. Check user conflicts — members with overlapping bookings (soft
			// conflict), with or without a room clash (#2243).
			$user_conflicts = array(
				'bookings'       => array(),
				'affected_users' => array(),
			);
			foreach ( $plan['days'] as $day ) {
				$found = AudienceBookingReader::get_user_conflicts(
					$day['date'],
					$day['start_time'],
					$day['end_time'],
					$audience_ids,
					$user_ids,
					null,
					$scope_schedule_id,
					$readable_schedule_ids
				);
				foreach ( $found['bookings'] as $booking ) {
					// A booking already reported as the room's clash is not
					// reported a second time as the people's.
					if ( ! in_array( (int) $booking->id, $env_ids, true ) ) {
						$user_conflicts['bookings'][ (int) $booking->id ] = $booking;
					}
				}
				$user_conflicts['affected_users'] = array_merge( $user_conflicts['affected_users'], $found['affected_users'] );
			}
			$user_conflicts['bookings']       = array_values( $user_conflicts['bookings'] );
			$user_conflicts['affected_users'] = array_values( array_unique( $user_conflicts['affected_users'] ) );

			if ( ! empty( $user_conflicts['bookings'] ) ) {
				// The people who clash, by name (#2243), so the booker knows
				// whom to ask rather than only how many.
				$names                           = self::affected_names( $user_conflicts['affected_users'] );
				$response_data['affected_names'] = $names['names'];
				$response_data['affected_more']  = $names['more'];
				$response_data['affected_users'] = $user_conflicts['affected_users'];
				$user_bookings                   = array_map(
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
				if ( $is_hard_conflict ) {
					$response_data['user_bookings'] = $user_bookings;
				} else {
					$response_data['type']     = 'user';
					$response_data['bookings'] = $user_bookings;
				}
			}

			// Audience same-day is also a soft conflict.
			if ( 'none' === $response_data['type'] && ! empty( $response_data['audience_same_day'] ) ) {
				$response_data['type'] = 'audience_same_day';
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
