<?php
/**
 * Appointment Validator
 *
 * Validates appointment booking data against calendar rules:
 * required fields, date/time format, advance booking window,
 * blocked dates, working hours, slot availability, user permissions.
 *
 * Extracted from AppointmentHandler (M7 refactoring).
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.6.8
 * @version 4.6.10 - Added lock-aware validation for concurrent booking safety
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator for appointment input.
 */
class AppointmentValidator {

	/**
	 * Appointment repository.
	 *
	 * @var \FreeFormCertificate\Repositories\AppointmentRepository
	 */
	private $appointment_repository;
	/**
	 * Blocked date repository.
	 *
	 * @var \FreeFormCertificate\Repositories\BlockedDateRepository
	 */
	private $blocked_date_repository;

	/**
	 * Whether the last validate() diverted a full-slot booking to the waitlist.
	 *
	 * Set during the capacity step (#941 phase 2) when the slot is full but the
	 * calendar offers a waitlist with room. The caller reads it after a
	 * successful validate() to store the appointment as `waitlist`.
	 *
	 * @var bool
	 */
	private $waitlist_requested = false;

	/**
	 * Constructor
	 *
	 * @param \FreeFormCertificate\Repositories\AppointmentRepository $appointment_repository Appointment repository.
	 * @param \FreeFormCertificate\Repositories\BlockedDateRepository $blocked_date_repository Blocked date repository.
	 */
	public function __construct(
		\FreeFormCertificate\Repositories\AppointmentRepository $appointment_repository,
		\FreeFormCertificate\Repositories\BlockedDateRepository $blocked_date_repository
	) {
		$this->appointment_repository  = $appointment_repository;
		$this->blocked_date_repository = $blocked_date_repository;
	}

	/**
	 * Validate appointment booking
	 *
	 * @param array<string, mixed> $data Appointment data.
	 * @param array<string, mixed> $calendar Calendar configuration.
	 * @param bool                 $use_lock Use FOR UPDATE locks on capacity queries (requires active transaction).
	 * @return true|\WP_Error
	 */
	public function validate( array $data, array $calendar, bool $use_lock = false ) {
		$calendar_post_id         = isset( $calendar['post_id'] ) ? (int) $calendar['post_id'] : null;
		$has_bypass               = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass( null, $calendar_post_id );
		$is_custom                = 'custom' === ( $calendar['schedule_type'] ?? 'regular' );
		$this->waitlist_requested = false;

		// 1. Validate required fields.
		if ( empty( $data['appointment_date'] ) || empty( $data['start_time'] ) ) {
			return new \WP_Error( 'missing_fields', __( 'Date and time are required.', 'ffcertificate' ) );
		}

		// 2. Validate date format.
		$date_obj = \DateTime::createFromFormat( 'Y-m-d', $data['appointment_date'] );
		if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $data['appointment_date'] ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid date format.', 'ffcertificate' ) );
		}

		// 3. Validate time format.
		if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $data['start_time'] ) ) {
			return new \WP_Error( 'invalid_time', __( 'Invalid time format.', 'ffcertificate' ) );
		}

		// 4. Check if date is in the past (bypass allowed)
		$now                   = time();
		$tz                    = wp_timezone();
		$appointment_timestamp = ( new \DateTimeImmutable( $data['appointment_date'] . ' ' . $data['start_time'], $tz ) )->getTimestamp();

		if ( $appointment_timestamp < $now && ! $has_bypass ) {
			return new \WP_Error( 'past_date', __( 'Cannot book appointments in the past.', 'ffcertificate' ) );
		}

		// 5. Validate advance booking window (minimum) - bypass skips.
		if ( ! $has_bypass && $calendar['advance_booking_min'] > 0 ) {
			$min_advance = $now + ( $calendar['advance_booking_min'] * 3600 );
			if ( $appointment_timestamp < $min_advance ) {
				return new \WP_Error(
					'too_soon',
					sprintf(
						/* translators: %d: minimum number of hours for advance booking */
						__( 'Appointments must be booked at least %d hours in advance.', 'ffcertificate' ),
						$calendar['advance_booking_min']
					)
				);
			}
		}

		// 6. Validate advance booking window (maximum) - bypass skips. Not applied
		// in custom mode (#941): the bookable dates are explicit by construction.
		if ( ! $has_bypass && ! $is_custom && $calendar['advance_booking_max'] > 0 ) {
			$max_advance = $now + ( $calendar['advance_booking_max'] * 86400 );
			if ( $appointment_timestamp > $max_advance ) {
				return new \WP_Error(
					'too_far',
					sprintf(
						/* translators: %d: maximum number of days for advance booking */
						__( 'Appointments cannot be booked more than %d days in advance.', 'ffcertificate' ),
						$calendar['advance_booking_max']
					)
				);
			}
		}

		// 7. Check global holidays and blocked dates (bypass skips)
		if ( ! $has_bypass ) {
			if ( \FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday( $data['appointment_date'] ) ) {
				return new \WP_Error( 'date_blocked', __( 'This date is a holiday.', 'ffcertificate' ) );
			}
			if ( $this->blocked_date_repository->isDateBlocked( $data['calendar_id'], $data['appointment_date'], $data['start_time'] ) ) {
				return new \WP_Error( 'date_blocked', __( 'This date/time is not available.', 'ffcertificate' ) );
			}
		}

		// 8. Resolve the slot capacity per mode. Custom (#941): the (date, start)
		// must match a defined block, and that block's capacity applies; working
		// hours don't. Regular: the working-hours gate + the calendar-wide cap.
		if ( $is_custom ) {
			$block = \FreeFormCertificate\SelfScheduling\CustomSlots::find(
				$calendar['custom_slots'] ?? '',
				$data['appointment_date'],
				$data['start_time']
			);
			if ( null === $block ) {
				return new \WP_Error( 'invalid_slot', __( 'The selected time block is not available.', 'ffcertificate' ) );
			}
			$slot_capacity = (int) $block['capacity'];
		} else {
			if ( ! $has_bypass && ! $this->is_within_working_hours( $data['appointment_date'], $data['start_time'], $calendar ) ) {
				return new \WP_Error( 'outside_hours', __( 'Selected time is outside working hours.', 'ffcertificate' ) );
			}
			$slot_capacity = (int) $calendar['max_appointments_per_slot'];
		}

		// 9. Check slot/block capacity. Enforced for everyone EXCEPT holders of the
		// dedicated overbook capability (#941) — the one manual override.
		if ( ! current_user_can( 'ffc_bypass_appointment_capacity' ) ) {
			$is_available = $this->appointment_repository->isSlotAvailable(
				$data['calendar_id'],
				$data['appointment_date'],
				$data['start_time'],
				$slot_capacity,
				$use_lock
			);

			if ( ! $is_available ) {
				// Slot full. If the calendar offers a waitlist with room, divert
				// this booking to the queue instead of rejecting it (#941 phase 2).
				if ( $this->can_join_waitlist( $calendar, $data, $use_lock ) ) {
					$this->waitlist_requested = true;
				} else {
					return new \WP_Error( 'slot_full', __( 'This time slot is fully booked.', 'ffcertificate' ) );
				}
			}
		}

		// 10. Check daily limit — bypass skips. Not applied in custom mode (#941):
		// per-block capacity is the control, not a per-date total.
		if ( ! $has_bypass && ! $is_custom && $calendar['slots_per_day'] > 0 ) {
			$daily_count = $this->get_daily_appointment_count( $data['calendar_id'], $data['appointment_date'], $use_lock );
			if ( $daily_count >= $calendar['slots_per_day'] ) {
				return new \WP_Error( 'daily_limit', __( 'Daily booking limit reached for this date.', 'ffcertificate' ) );
			}
		}

		// 11. Check minimum interval between bookings — bypass skips.
		if ( ! $has_bypass && ! empty( $calendar['minimum_interval_between_bookings'] ) && $calendar['minimum_interval_between_bookings'] > 0 ) {
			$user_identifier = null;

			if ( ! empty( $data['user_id'] ) ) {
				$user_identifier = $data['user_id'];
			} elseif ( ! empty( $data['email'] ) ) {
				$user_identifier = $data['email'];
			} elseif ( ! empty( $data['cpf_rf'] ) ) {
				$user_identifier = $data['cpf_rf'];
			}

			if ( $user_identifier ) {
				$interval_hours = (int) $calendar['minimum_interval_between_bookings'];
				$interval_check = $this->check_booking_interval( $user_identifier, $data['calendar_id'], $interval_hours );

				if ( is_wp_error( $interval_check ) ) {
					return $interval_check;
				}
			}
		}

		// 12. Validate user permissions (capability AND calendar config)
		if ( is_user_logged_in() && ! $has_bypass ) {
			if ( ! current_user_can( 'ffc_book_own_appointments' ) ) {
				return new \WP_Error(
					'capability_denied',
					__( 'You do not have permission to book appointments.', 'ffcertificate' )
				);
			}
		}

		// Calendar-specific scheduling visibility check.
		$scheduling_visibility = $calendar['scheduling_visibility'] ?? 'public';
		if ( 'private' === $scheduling_visibility && ! is_user_logged_in() && ! $has_bypass ) {
			return new \WP_Error( 'login_required', __( 'You must be logged in to book this calendar.', 'ffcertificate' ) );
		}

		// Business hours restriction for booking — bypass skips.
		if ( ! $has_bypass && ! empty( $calendar['restrict_booking_to_hours'] ) ) {
			$working_hours = $calendar['working_hours'] ?? array();
			if ( ! empty( $working_hours ) ) {
				$now          = current_time( 'mysql' );
				$now_ts_raw   = strtotime( $now );
				$now_ts       = $now_ts_raw ? $now_ts_raw : time();
				$current_date = gmdate( 'Y-m-d', $now_ts );
				$current_time = gmdate( 'H:i', $now_ts );

				$is_working_day  = \FreeFormCertificate\Scheduling\WorkingHoursService::is_working_day( $current_date, $working_hours );
				$is_within_hours = \FreeFormCertificate\Scheduling\WorkingHoursService::is_within_working_hours( $current_date, $current_time, $working_hours );

				if ( ! $is_working_day || ! $is_within_hours ) {
					return new \WP_Error( 'outside_business_hours', __( 'Booking is available only during business hours.', 'ffcertificate' ) );
				}
			}
		}

		// 13. Validate email (if not logged in)
		if ( ! is_user_logged_in() && empty( $data['email'] ) ) {
			return new \WP_Error( 'email_required', __( 'Email address is required.', 'ffcertificate' ) );
		}

		// 14. Validate CPF/RF.
		if ( empty( $data['cpf_rf'] ) ) {
			return new \WP_Error( 'cpf_rf_required', __( 'CPF/RF is required.', 'ffcertificate' ) );
		}

		$cpf_rf_clean = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( (string) $data['cpf_rf'] );
		if ( strlen( $cpf_rf_clean ) === 7 ) {
			if ( ! preg_match( '/^\d{7}$/', $cpf_rf_clean ) ) {
				return new \WP_Error( 'invalid_rf', __( 'Invalid RF format.', 'ffcertificate' ) );
			}
		} elseif ( strlen( $cpf_rf_clean ) === 11 ) {
			if ( ! \FreeFormCertificate\Core\DocumentFormatter::validate_cpf( $cpf_rf_clean ) ) {
				return new \WP_Error( 'invalid_cpf', __( 'Invalid CPF.', 'ffcertificate' ) );
			}
		} else {
			return new \WP_Error( 'invalid_cpf_rf', __( 'CPF/RF must be 7 digits (RF) or 11 digits (CPF).', 'ffcertificate' ) );
		}

		return true;
	}

	/**
	 * Whether the last validate() diverted the booking to the waitlist (#941 phase 2).
	 *
	 * Only meaningful after validate() returned `true`. When true, the caller
	 * stores the appointment with status `waitlist` instead of pending/confirmed.
	 *
	 * @return bool
	 */
	public function is_waitlist_requested(): bool {
		return $this->waitlist_requested;
	}

	/**
	 * Whether a full slot can accept another waitlist entry (#941 phase 2).
	 *
	 * True when the calendar has the waitlist enabled and the queue for this
	 * exact (date, start) slot is below `waitlist_capacity` (0 = unlimited).
	 *
	 * @param array<string, mixed> $calendar Calendar configuration.
	 * @param array<string, mixed> $data Appointment data.
	 * @param bool                 $use_lock Use FOR UPDATE on the queue count.
	 * @return bool
	 */
	private function can_join_waitlist( array $calendar, array $data, bool $use_lock ): bool {
		if ( empty( $calendar['waitlist_enabled'] ) ) {
			return false;
		}

		$capacity = (int) ( $calendar['waitlist_capacity'] ?? 0 );
		if ( $capacity <= 0 ) {
			return true; // Unlimited queue.
		}

		$waiting = $this->appointment_repository->countWaitlisted(
			(int) $data['calendar_id'],
			(string) $data['appointment_date'],
			(string) $data['start_time'],
			$use_lock
		);

		return $waiting < $capacity;
	}

	/**
	 * Check minimum interval between bookings for a user
	 *
	 * @param mixed $user_identifier User ID, email, or CPF/RF.
	 * @param int   $calendar_id Calendar ID.
	 * @param int   $interval_hours Minimum hours between bookings.
	 * @return true|\WP_Error
	 */
	public function check_booking_interval( $user_identifier, int $calendar_id, int $interval_hours ) {
		$now         = time();
		$cutoff_time = $now + ( $interval_hours * 3600 );

		$recent_appointments = array();

		if ( is_int( $user_identifier ) ) {
			$recent_appointments = $this->appointment_repository->findByUserId( $user_identifier );
		} elseif ( filter_var( $user_identifier, FILTER_VALIDATE_EMAIL ) ) {
				$recent_appointments = $this->appointment_repository->findByEmail( $user_identifier );
		} else {
			$recent_appointments = $this->appointment_repository->findByCpfRf( $user_identifier );
		}

		foreach ( $recent_appointments as $appointment ) {
			if ( 'cancelled' === $appointment['status'] ) {
				continue;
			}

			if ( (int) $appointment['calendar_id'] !== $calendar_id ) {
				continue;
			}

			$apt_timestamp = ( new \DateTimeImmutable( $appointment['appointment_date'] . ' ' . $appointment['start_time'], wp_timezone() ) )->getTimestamp();

			if ( $apt_timestamp >= $now && $apt_timestamp <= $cutoff_time ) {
				$next_available = \FreeFormCertificate\Core\DateFormatter::format_datetime( $apt_timestamp + ( $interval_hours * 3600 ) );

				return new \WP_Error(
					'booking_too_soon',
					sprintf(
						/* translators: %1$d: number of hours, %2$s: next available date/time */
						__( 'You already have an appointment scheduled within the next %1$d hours. You can book again after %2$s.', 'ffcertificate' ),
						$interval_hours,
						$next_available
					)
				);
			}
		}

		return true;
	}

	/**
	 * Check if time is within working hours
	 *
	 * @param string               $date Date.
	 * @param string               $time Time.
	 * @param array<string, mixed> $calendar Calendar.
	 * @return bool
	 */
	public function is_within_working_hours( string $date, string $time, array $calendar ): bool {
		return \FreeFormCertificate\Scheduling\WorkingHoursService::is_within_working_hours(
			$date,
			$time,
			$calendar['working_hours'] ?? ''
		);
	}

	/**
	 * Get daily appointment count
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date Date.
	 * @param bool   $use_lock Use FOR UPDATE lock (requires active transaction).
	 * @return int
	 */
	public function get_daily_appointment_count( int $calendar_id, string $date, bool $use_lock = false ): int {
		$appointments = $this->appointment_repository->getAppointmentsByDate( $calendar_id, $date, array( 'confirmed', 'pending' ), $use_lock );
		return count( $appointments );
	}
}
