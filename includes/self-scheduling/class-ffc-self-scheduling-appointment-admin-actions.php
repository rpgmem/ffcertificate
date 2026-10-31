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

		$this->appointments->begin_transaction();
		try {
			// The pending row is one of the active rows the count reads, so
			// the slot holds it while the active rows number at most
			// $capacity, which is what a limit of $capacity + 1 asks.
			if ( $capacity <= 0 || ! $this->appointments->isSlotAvailable( (int) $appointment['calendar_id'], $date, $start, $capacity + 1, true ) ) {
				$this->appointments->rollback();
				return new \WP_Error( 'slot_full', __( 'The slot is already full, so the appointment cannot be confirmed.', 'ffcertificate' ) );
			}
			$changed = $this->appointments->confirmPending( $appointment_id, $actor );
			$this->appointments->commit();
		} catch ( \Exception $e ) {
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
