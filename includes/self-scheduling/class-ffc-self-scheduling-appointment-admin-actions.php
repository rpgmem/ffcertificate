<?php
/**
 * Appointment Admin Actions
 *
 * Confirm and Cancel from the appointments list (#2244). The view used to run
 * both itself: Confirm wrote `confirmed` over whatever status the row carried,
 * with no capacity check and no regard for the calendar's approval-notice
 * switch, and Cancel e-mailed nobody. Both now go through here, write only
 * over the status they read, and notify through the same switches as the
 * booking path (`AppointmentHandler::schedule_email_notifications()`).
 *
 * The caller checks the capability and the nonce; this class decides what
 * the row allows.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Confirm and Cancel of a personal-calendar appointment.
 */
class AppointmentAdminActions {

	/**
	 * Appointment repository.
	 *
	 * @var AppointmentRepository
	 */
	private $appointments;

	/**
	 * Calendar repository.
	 *
	 * @var CalendarRepository
	 */
	private $calendars;

	/**
	 * Notifier (the booking path's e-mail switches).
	 *
	 * @var AppointmentHandler|null
	 */
	private $notifier;

	/**
	 * Constructor.
	 *
	 * @param AppointmentRepository|null $appointments Appointment repository.
	 * @param CalendarRepository|null    $calendars    Calendar repository.
	 * @param AppointmentHandler|null    $notifier     Notifier; built on first use when null.
	 */
	public function __construct( $appointments = null, $calendars = null, $notifier = null ) {
		$this->appointments = $appointments ?? new AppointmentRepository();
		$this->calendars    = $calendars ?? new CalendarRepository();
		$this->notifier     = $notifier;
	}

	/**
	 * Confirm a pending appointment.
	 *
	 * Only `pending` can be confirmed: a waitlisted row leaves the queue by
	 * promotion, and a cancelled or finished one is not reopened. The slot is
	 * recounted under lock, so a slot that filled (a bypass booking, an
	 * edited capacity) is not overbooked by the approval.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @param int $actor          User confirming.
	 * @return true|\WP_Error
	 */
	public function confirm( int $appointment_id, int $actor ) {
		$appointment = $this->appointments->findById( $appointment_id );
		if ( ! is_array( $appointment ) ) {
			return new \WP_Error( 'not_found', __( 'Appointment not found.', 'ffcertificate' ) );
		}
		if ( 'pending' !== (string) ( $appointment['status'] ?? '' ) ) {
			return new \WP_Error( 'not_pending', __( 'Only a pending appointment can be confirmed.', 'ffcertificate' ) );
		}

		$calendar = $this->calendars->findById( (int) $appointment['calendar_id'] );
		if ( ! is_array( $calendar ) ) {
			return new \WP_Error( 'calendar_not_found', __( 'Calendar not found.', 'ffcertificate' ) );
		}

		$date     = (string) $appointment['appointment_date'];
		$start    = (string) $appointment['start_time'];
		$capacity = WaitlistPromoter::slot_capacity( $calendar, $date, $start );

		// Without a transaction the recount below locks nothing, so the slot
		// could fill between the count and the confirmation.
		if ( ! $this->appointments->begin_transaction() ) {
			return new \WP_Error( 'confirmation_failed', __( 'Failed to confirm appointment.', 'ffcertificate' ) );
		}
		try {
			// The pending row is one of the active rows the count reads, so
			// the slot holds it while the active rows number at most
			// $capacity, which is what a limit of $capacity + 1 asks.
			// A session counts its own places (#2405).
			$session = \FreeFormCertificate\SelfScheduling\CalendarSessions::scope_of_appointment( $calendar, $appointment );
			if ( $capacity <= 0 || ! $this->appointments->isSlotAvailable( (int) $appointment['calendar_id'], $date, $start, $capacity + 1, true, $session ) ) {
				$this->appointments->rollback();
				return new \WP_Error( 'slot_full', __( 'The slot is already full, so the appointment cannot be confirmed.', 'ffcertificate' ) );
			}
			$changed = $this->appointments->confirmPending( $appointment_id, $actor );
			$this->appointments->commit();
		} catch ( \Throwable $e ) {
			$this->appointments->rollback();
			return new \WP_Error( 'confirmation_failed', __( 'Failed to confirm appointment.', 'ffcertificate' ) );
		}

		if ( false === $changed ) {
			return new \WP_Error( 'confirmation_failed', __( 'Failed to confirm appointment.', 'ffcertificate' ) );
		}
		if ( 0 === $changed ) {
			return new \WP_Error( 'status_changed', __( 'This appointment changed meanwhile. Reload the page and try again.', 'ffcertificate' ) );
		}

		$appointment['status']      = 'confirmed';
		$appointment['approved_by'] = $actor;
		$this->notifier()->schedule_email_notifications( $appointment, $calendar, 'confirmed' );

		return true;
	}

	/**
	 * Cancel (or, on a pending row, reject) an appointment.
	 *
	 * Fires `ffcertificate_appointment_cancelled` with the row as it was, so
	 * the waitlist promoter and the activity log see the freed slot, and sends
	 * the cancellation e-mail carrying the reason when the calendar sends one.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param int    $actor          User cancelling.
	 * @param string $reason         Reason shown to the person.
	 * @return true|\WP_Error
	 */
	public function cancel( int $appointment_id, int $actor, string $reason ) {
		$appointment = $this->appointments->findById( $appointment_id );
		if ( ! is_array( $appointment ) ) {
			return new \WP_Error( 'not_found', __( 'Appointment not found.', 'ffcertificate' ) );
		}

		$status = (string) ( $appointment['status'] ?? '' );
		if ( 'cancelled' === $status ) {
			return new \WP_Error( 'already_cancelled', __( 'This appointment is already cancelled.', 'ffcertificate' ) );
		}
		if ( ! in_array( $status, AppointmentHandler::CANCELLABLE_STATUSES, true ) ) {
			return new \WP_Error( 'not_cancellable', __( 'This appointment has already taken place and can no longer be cancelled.', 'ffcertificate' ) );
		}

		$changed = $this->appointments->cancelFrom( $appointment_id, $status, $actor, $reason );
		if ( false === $changed ) {
			return new \WP_Error( 'cancellation_failed', __( 'Failed to cancel appointment.', 'ffcertificate' ) );
		}
		if ( 0 === $changed ) {
			return new \WP_Error( 'status_changed', __( 'This appointment changed meanwhile. Reload the page and try again.', 'ffcertificate' ) );
		}

		/** This action is documented in includes/self-scheduling/class-ffc-self-scheduling-appointment-handler.php */
		do_action( 'ffcertificate_appointment_cancelled', $appointment_id, $appointment, $reason, $actor );

		$calendar = $this->calendars->findById( (int) $appointment['calendar_id'] );
		if ( is_array( $calendar ) ) {
			$cancelled                        = $appointment;
			$cancelled['status']              = 'cancelled';
			$cancelled['cancellation_reason'] = $reason;
			$cancelled['cancelled_by']        = $actor;
			$this->notifier()->schedule_email_notifications( $cancelled, $calendar, 'cancelled' );
		}

		return true;
	}

	/**
	 * Whether an attendance mark may be written on an appointment now.
	 *
	 * Both marks need a confirmed appointment. Attended is marked from the
	 * appointment's date on, since a person may arrive early; a no-show only
	 * once the slot has started, since before that nobody has failed to come
	 * (#2689). Dates and times are wall-clock, read in the site time zone.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @param string               $mark        `attended` or `no_show`.
	 * @param int                  $now         Unix time.
	 * @return bool
	 */
	public static function can_mark( array $appointment, string $mark, int $now ): bool {
		$date = \FreeFormCertificate\Core\ArrayValue::string( $appointment, 'appointment_date' );
		if ( 'confirmed' !== ( $appointment['status'] ?? '' ) || '' === $date ) {
			return false;
		}
		if ( 'no_show' === $mark ) {
			return self::has_started( $appointment, $now );
		}
		return 'attended' === $mark && $date <= (string) wp_date( 'Y-m-d', $now );
	}

	/**
	 * Whether an appointment's slot has started, comparing its wall-clock
	 * date and start time with the site's clock.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @param int                  $now         Unix time.
	 * @return bool
	 */
	private static function has_started( array $appointment, int $now ): bool {
		$date  = \FreeFormCertificate\Core\ArrayValue::string( $appointment, 'appointment_date' );
		$today = (string) wp_date( 'Y-m-d', $now );
		if ( $date !== $today ) {
			return $date < $today;
		}
		$start = substr( \FreeFormCertificate\Core\ArrayValue::string( $appointment, 'start_time' ), 0, 5 );
		return ( '' === $start ? '00:00' : $start ) <= (string) wp_date( 'H:i', $now );
	}

	/**
	 * Undo an attendance mark, putting the appointment back to confirmed.
	 *
	 * A mark frees the appointment's place, so on a slot that has not started
	 * the place may have been booked since: the slot is recounted under lock,
	 * and a full one refuses the undo rather than overbook it (#2689). Once
	 * the slot has started nobody can book it, so nothing is recounted.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @param int $now            Unix time.
	 * @return true|\WP_Error
	 */
	public function undo_attendance( int $appointment_id, int $now ) {
		$appointment = $this->appointments->findById( $appointment_id );
		$status      = is_array( $appointment ) ? (string) ( $appointment['status'] ?? '' ) : '';
		if ( ! is_array( $appointment ) || ! in_array( $status, \FreeFormCertificate\Repositories\AppointmentWriter::ATTENDANCE_STATUSES, true ) ) {
			return new \WP_Error( 'status_changed', __( 'Failed to update the appointment status. It may have changed meanwhile; reload the list.', 'ffcertificate' ) );
		}

		if ( self::has_started( $appointment, $now ) ) {
			return $this->appointments->revertAttendance( $appointment_id, $status )
				? true
				: new \WP_Error( 'status_changed', __( 'Failed to update the appointment status. It may have changed meanwhile; reload the list.', 'ffcertificate' ) );
		}

		$calendar = $this->calendars->findById( (int) $appointment['calendar_id'] );
		if ( ! is_array( $calendar ) ) {
			return new \WP_Error( 'calendar_not_found', __( 'Calendar not found.', 'ffcertificate' ) );
		}
		$date     = (string) $appointment['appointment_date'];
		$time     = (string) $appointment['start_time'];
		$capacity = WaitlistPromoter::slot_capacity( $calendar, $date, $time );
		if ( ! $this->appointments->begin_transaction() ) {
			return new \WP_Error( 'status_changed', __( 'Failed to update the appointment status. It may have changed meanwhile; reload the list.', 'ffcertificate' ) );
		}
		try {
			// The marked row is not one of the active rows the count reads.
			$session = \FreeFormCertificate\SelfScheduling\CalendarSessions::scope_of_appointment( $calendar, $appointment );
			if ( $capacity <= 0 || ! $this->appointments->isSlotAvailable( (int) $appointment['calendar_id'], $date, $time, $capacity, true, $session ) ) {
				$this->appointments->rollback();
				return new \WP_Error( 'slot_full', __( 'The slot was booked again since the mark, so the mark cannot be undone.', 'ffcertificate' ) );
			}
			$changed = $this->appointments->revertAttendance( $appointment_id, $status );
			$this->appointments->commit();
		} catch ( \Throwable $e ) {
			$this->appointments->rollback();
			$changed = false;
		}

		return $changed
			? true
			: new \WP_Error( 'status_changed', __( 'Failed to update the appointment status. It may have changed meanwhile; reload the list.', 'ffcertificate' ) );
	}

	/**
	 * Mark several appointments as no-show at once (#2244).
	 *
	 * Each is marked under the same rule as the row action
	 * ({@see self::can_mark()}). The write is conditional on the status, so a
	 * row another request changed meanwhile is skipped rather than
	 * overwritten.
	 *
	 * @param array<int, int> $ids Appointment IDs.
	 * @return array{marked: int, skipped: int}
	 */
	public function mark_no_show_many( array $ids ): array {
		$now    = time();
		$result = array(
			'marked'  => 0,
			'skipped' => 0,
		);
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$appointment = $this->appointments->findById( $id );
			if ( ! is_array( $appointment ) || ! self::can_mark( $appointment, 'no_show', $now ) ) {
				++$result['skipped'];
				continue;
			}
			if ( $this->appointments->markNoShow( $id ) ) {
				++$result['marked'];
			} else {
				++$result['skipped'];
			}
		}
		return $result;
	}

	/**
	 * The statuses an appointment may be deleted permanently from (#2340):
	 * the ones that are over. A pending, confirmed or waitlisted one is
	 * cancelled first, which notifies the person.
	 */
	public const DELETABLE_STATUSES = array( 'cancelled', 'completed', 'no_show' );

	/**
	 * Whether an appointment row may be deleted permanently.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @return bool
	 */
	public static function is_deletable( array $appointment ): bool {
		return in_array( (string) ( $appointment['status'] ?? '' ), self::DELETABLE_STATUSES, true );
	}

	/**
	 * Delete appointments permanently (#2340), skipping any that is not over.
	 *
	 * Each run writes one Danger Zone entry naming what it deleted; nothing is
	 * logged when nothing was deleted. The caller checks
	 * `ffc_delete_appointments` and the nonce.
	 *
	 * @param array<int, int|string> $ids   Appointment IDs.
	 * @param int                    $actor User deleting.
	 * @return array{deleted: int, skipped: int}
	 */
	public function delete_permanently( array $ids, int $actor ): array {
		$deletable = array();
		$calendars = array();
		$skipped   = 0;
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$appointment = $this->appointments->findById( $id );
			if ( ! is_array( $appointment ) || ! self::is_deletable( $appointment ) ) {
				++$skipped;
				continue;
			}
			$deletable[] = $id;
			$calendar    = (int) ( $appointment['calendar_id'] ?? 0 );
			if ( $calendar > 0 ) {
				$calendars[ $calendar ] = true;
			}
		}

		$deleted  = array() === $deletable ? 0 : $this->appointments->deleteByIds( $deletable );
		$skipped += count( $deletable ) - $deleted;

		if ( $deleted > 0 ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'appointment_deleted',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'count'           => $deleted,
					'appointment_ids' => $deletable,
					'calendar_ids'    => array_keys( $calendars ),
				),
				$actor
			);
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);
	}

	/**
	 * The notifier, built on first use.
	 *
	 * @return AppointmentHandler
	 */
	private function notifier(): AppointmentHandler {
		if ( null === $this->notifier ) {
			$this->notifier = new AppointmentHandler();
		}
		return $this->notifier;
	}
}
