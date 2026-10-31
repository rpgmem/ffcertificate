<?php
/**
 * User Appointments REST Controller
 *
 * Handles:
 *   GET /user/appointments – Current user's self-scheduling appointments
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user appointments endpoints.
 */
class UserAppointmentsRestController {

	use UserContextTrait;

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace Namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/appointments',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_appointments' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/**
	 * GET /user/appointments
	 *
	 * @since 4.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_appointments( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error(
					'not_logged_in',
					__( 'You must be logged in to view appointments', 'ffcertificate' ),
					array( 'status' => 401 )
				);
			}

			if ( ! $this->user_has_capability( 'ffc_view_own_appointments', $user_id, $ctx['is_view_as'] ) ) {
				return new \WP_Error(
					'capability_denied',
					__( 'You do not have permission to view appointments', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			if ( ! class_exists( '\FreeFormCertificate\Repositories\AppointmentRepository' ) ) {
				return new \WP_Error(
					'repository_not_found',
					__( 'Appointment repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			if ( ! class_exists( '\FreeFormCertificate\Repositories\CalendarRepository' ) ) {
				return new \WP_Error(
					'calendar_repository_not_found',
					__( 'Calendar repository not available', 'ffcertificate' ),
					array( 'status' => 500 )
				);
			}

			$appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
			$calendar_repository    = new \FreeFormCertificate\Repositories\CalendarRepository();

			$appointments = $appointment_repository->findByUserId( $user_id );

			// Batch load all calendars to avoid N+1 queries.
			$calendar_ids  = array_unique(
				array_filter(
					array_map(
						function ( $apt ) {
							return (int) ( $apt['calendar_id'] ?? 0 );
						},
						$appointments
					)
				)
			);
			$calendars_map = ! empty( $calendar_ids ) ? $calendar_repository->findByIds( $calendar_ids ) : array();

			$appointments_formatted = array();
			$booking_pages          = array();
			// Upcoming and past split on the site's today, not the browser's
			// UTC date (#2246).
			$site_today = current_time( 'Y-m-d' );

			foreach ( $appointments as $appointment ) {
				if ( empty( $appointment['id'] ) ) {
					continue;
				}

				$calendar_title = __( 'Unknown Calendar', 'ffcertificate' );
				$calendar       = null;
				if ( ! empty( $appointment['calendar_id'] ) ) {
					$calendar = $calendars_map[ (int) $appointment['calendar_id'] ] ?? null;
					if ( $calendar && isset( $calendar['title'] ) ) {
						$calendar_title = $calendar['title'];
					}
				}

				// The date is Category B wall-clock too (#2246): through the
				// timestamp path a UTC-3 site rendered 20/05 as 19/05.
				$date_formatted = '';
				if ( ! empty( $appointment['appointment_date'] ) ) {
					$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $appointment['appointment_date'] );
					if ( '' === $date_formatted ) {
						$date_formatted = (string) $appointment['appointment_date'];
					}
				}

				// Appointment times are Category-B wall-clock (TIME columns, no TZ
				// semantics) — render them with the wall-clock formatter. The old
				// strtotime()+format_time() path re-interpreted the literal time as
				// a UTC instant and then applied the site TZ, shifting it by the
				// offset (e.g. 13:00 → 10:00 in UTC-3). See CLAUDE.md Category B.
				$time_formatted = ! empty( $appointment['start_time'] )
					? \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $appointment['start_time'] )
					: '';

				$email_display = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );
				$phone_display = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'phone' );
				// "View as user" is not the owner reading their own bookings
				// (#1863): the email and phone arrive masked; the
				// administrator reveals them on the appointments screen.
				if ( $ctx['is_view_as'] ) {
					$email_display = \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $email_display );
					$phone_display = \FreeFormCertificate\Core\DocumentFormatter::mask_phone( (string) $phone_display );
				}

				$end_time_formatted = ! empty( $appointment['end_time'] )
					? \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $appointment['end_time'] )
					: '';

				$status_labels = array(
					'pending'   => __( 'Pending', 'ffcertificate' ),
					'confirmed' => __( 'Confirmed', 'ffcertificate' ),
					'cancelled' => __( 'Cancelled', 'ffcertificate' ),
					'completed' => __( 'Completed', 'ffcertificate' ),
					'no_show'   => __( 'No Show', 'ffcertificate' ),
					'waitlist'  => __( 'Waitlisted', 'ffcertificate' ),
				);

				$status = $appointment['status'] ?? 'pending';

				// "View as user" gets no receipt link (#2244): the link carries
				// the person's token, which opens their receipt unmasked and
				// cancels as a guest.
				$receipt_url = '';
				if ( 'cancelled' !== $status && ! $ctx['is_view_as'] ) {
					$confirmation_token = $appointment['confirmation_token'] ?? '';
					if ( ! empty( $confirmation_token ) && class_exists( '\\FreeFormCertificate\\Generators\\MagicLinkHelper' ) ) {
						$receipt_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $confirmation_token );
					} elseif ( class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler' ) ) {
						$receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
							(int) $appointment['id'],
							$confirmation_token
						);
					}
				}

				// The same rules the cancel handler applies (#2244): an open
				// appointment that has not started, cancellable by its calendar's
				// rules, or by anyone the calendar's bypass switch lets through.
				// When it is not, the row says why instead of only hiding Cancel.
				$can_cancel  = false;
				$cancel_note = '';
				if ( in_array( $status, \FreeFormCertificate\SelfScheduling\AppointmentHandler::CANCELLABLE_STATUSES, true ) ) {
					$appointment_time = ( new \DateTimeImmutable( $appointment['appointment_date'] . ' ' . ( $appointment['start_time'] ?? '23:59:59' ), wp_timezone() ) )->getTimestamp();
					$now              = time();
					$calendar_post_id = $calendar && ! empty( $calendar['post_id'] ) ? (int) $calendar['post_id'] : null;
					$min_hours        = $calendar && ! empty( $calendar['cancellation_min_hours'] ) ? (int) $calendar['cancellation_min_hours'] : 0;

					if ( $appointment_time > $now ) {
						if ( \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass( null, $calendar_post_id ) ) {
							$can_cancel = true;
						} elseif ( ! $calendar || empty( $calendar['allow_cancellation'] ) ) {
							$cancel_note = __( 'This calendar does not take cancellations online. Contact the organiser to cancel.', 'ffcertificate' );
						} elseif ( $min_hours > 0 && $now > $appointment_time - $min_hours * 3600 ) {
							$cancel_note = sprintf(
								/* translators: %d: hours before the appointment */
								_n( 'Cancelling closed %d hour before the appointment.', 'Cancelling closed %d hours before the appointment.', $min_hours, 'ffcertificate' ),
								$min_hours
							);
						} else {
							$can_cancel = true;
						}
					}
				}

				// Reschedule is cancel-then-book (#2244): offered where cancelling
				// is, and only when the calendar is published on a page the
				// person can return to. One lookup per calendar.
				$reschedule_url = '';
				// A waitlisted person holds no time to move, so leaving is all.
				if ( $can_cancel && 'waitlist' !== $status && ! $ctx['is_view_as'] && $calendar && ! empty( $calendar['post_id'] ) ) {
					$calendar_post = (int) $calendar['post_id'];
					if ( ! isset( $booking_pages[ $calendar_post ] ) ) {
						$booking_pages[ $calendar_post ] = \FreeFormCertificate\Core\ShortcodePage::url( 'ffc_self_scheduling', $calendar_post );
					}
					$reschedule_url = $booking_pages[ $calendar_post ];
				}
				$reschedule_note = '' !== $reschedule_url ? self::interval_note( $appointment, $appointments, (array) $calendar ) : '';

				$appointments_formatted[] = array(
					'id'                   => (int) $appointment['id'],
					'calendar_id'          => (int) ( $appointment['calendar_id'] ?? 0 ),
					'calendar_title'       => $calendar_title,
					'appointment_date'     => $date_formatted,
					'appointment_date_raw' => $appointment['appointment_date'] ?? '',
					'start_time'           => $time_formatted,
					'start_time_raw'       => $appointment['start_time'] ?? '',
					'end_time'             => $end_time_formatted,
					'end_time_raw'         => $appointment['end_time'] ?? '',
					'status'               => $status,
					'status_label'         => $status_labels[ $status ] ?? $status,
					'is_past'              => ( (string) ( $appointment['appointment_date'] ?? '' ) < $site_today ) || in_array( $status, array( 'completed', 'no_show' ), true ),
					'name'                 => $appointment['name'] ?? '',
					'email'                => $email_display,
					'phone'                => $phone_display,
					'user_notes'           => $appointment['user_notes'] ?? '',
					'created_at'           => $appointment['created_at'] ?? '',
					'can_cancel'           => $can_cancel,
					'cancel_note'          => $cancel_note,
					'receipt_url'          => $receipt_url,
					'reschedule_url'       => $reschedule_url,
					'reschedule_note'      => $reschedule_note,
				);
			}

			return rest_ensure_response(
				array(
					'appointments' => $appointments_formatted,
					'total'        => count( $appointments_formatted ),
				)
			);

		} catch ( \Exception $e ) {
			if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_rest_api(
					'get_user_appointments error',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
						'trace'   => $e->getTraceAsString(),
					)
				);
			}

			return new \WP_Error(
				'get_appointments_error',
				__( 'An unexpected error occurred.', 'ffcertificate' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * What the interval rule will hold a rescheduled booking to (#2244).
	 *
	 * Once this appointment is cancelled, the validator measures the new time
	 * from the person's last other non-cancelled appointment on the same
	 * calendar (the highest id). Saying so before the cancellation keeps the
	 * person from releasing a slot they then cannot replace.
	 *
	 * @param array<string, mixed>             $appointment  The row being rescheduled.
	 * @param array<int, array<string, mixed>> $appointments Every row of the person.
	 * @param array<string, mixed>             $calendar     Its calendar row.
	 * @return string '' when the rule does not apply.
	 */
	private static function interval_note( array $appointment, array $appointments, array $calendar ): string {
		$hours = isset( $calendar['minimum_interval_between_bookings'] ) && is_numeric( $calendar['minimum_interval_between_bookings'] ) ? (int) $calendar['minimum_interval_between_bookings'] : 0;
		if ( $hours <= 0 ) {
			return '';
		}

		$last = null;
		foreach ( $appointments as $other ) {
			if ( (int) ( $other['id'] ?? 0 ) === (int) ( $appointment['id'] ?? 0 )
				|| 'cancelled' === ( $other['status'] ?? '' )
				|| (int) ( $other['calendar_id'] ?? 0 ) !== (int) ( $appointment['calendar_id'] ?? 0 ) ) {
				continue;
			}
			if ( null === $last || (int) ( $other['id'] ?? 0 ) > (int) ( $last['id'] ?? 0 ) ) {
				$last = $other;
			}
		}
		if ( null === $last ) {
			return '';
		}

		$when = ( new \DateTimeImmutable( \FreeFormCertificate\Core\ArrayValue::string( $last, 'appointment_date' ) . ' ' . \FreeFormCertificate\Core\ArrayValue::string( $last, 'start_time' ), wp_timezone() ) )->getTimestamp();

		return sprintf(
			/* translators: 1: date and time of the person's other appointment, 2: minimum hours between appointments */
			__( 'You also have an appointment on this calendar on %1$s. The new time must be at least %2$d hours before or after it.', 'ffcertificate' ),
			\FreeFormCertificate\Core\DateFormatter::format_datetime( $when ),
			$hours
		);
	}
}
