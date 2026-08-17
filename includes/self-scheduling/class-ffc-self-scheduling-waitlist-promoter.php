<?php
/**
 * WaitlistPromoter — FIFO promotion when a booked spot frees up (#941 phase 2).
 *
 * When an appointment is cancelled (self-service or admin, the latter being how
 * an approval is "rejected" in this module), a spot may open on its slot. This
 * listener promotes the oldest waiting entry for that exact (calendar, date,
 * start) into the active pool — `confirmed` when the calendar auto-confirms, or
 * `pending` when it requires manual approval — and emails the promoted user.
 *
 * The promotion is transactional (`FOR UPDATE`) so a concurrent booking or a
 * second cancellation can't double-fill the freed spot.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Promotes waitlisted appointments when a spot frees up.
 */
class WaitlistPromoter {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Appointment repository.
	 *
	 * @var \FreeFormCertificate\Repositories\AppointmentRepository
	 */
	private $appointment_repository;

	/**
	 * Calendar repository.
	 *
	 * @var \FreeFormCertificate\Repositories\CalendarRepository
	 */
	private $calendar_repository;

	/**
	 * Constructor.
	 *
	 * @param \FreeFormCertificate\Repositories\AppointmentRepository|null $appointment_repository Appointment repository.
	 * @param \FreeFormCertificate\Repositories\CalendarRepository|null    $calendar_repository Calendar repository.
	 */
	public function __construct( $appointment_repository = null, $calendar_repository = null ) {
		$this->appointment_repository = $appointment_repository ? $appointment_repository : new \FreeFormCertificate\Repositories\AppointmentRepository();
		$this->calendar_repository    = $calendar_repository ? $calendar_repository : new \FreeFormCertificate\Repositories\CalendarRepository();
	}

	/**
	 * Register the cancellation listener.
	 *
	 * Every cancel path fires `ffcertificate_appointment_cancelled`, so a single
	 * hook here covers self-service, REST and admin cancellations.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'ffcertificate_appointment_cancelled', array( $this, 'on_appointment_cancelled' ), 10, 2 );
	}

	/**
	 * Handle a cancellation: promote the next waitlisted entry if a spot opened.
	 *
	 * @param int                        $appointment_id Cancelled appointment ID (unused; the row carries the slot).
	 * @param array<string, mixed>|mixed $appointment    The appointment row as it was before cancellation.
	 * @return void
	 */
	public function on_appointment_cancelled( $appointment_id, $appointment ): void {
		unset( $appointment_id );

		if ( ! is_array( $appointment ) ) {
			return;
		}

		// Only a freed *active* spot triggers promotion. Someone leaving the
		// queue (a cancelled `waitlist` row) opens nothing.
		$prev_status = (string) ( $appointment['status'] ?? '' );
		if ( ! in_array( $prev_status, array( 'pending', 'confirmed' ), true ) ) {
			return;
		}

		if ( empty( $appointment['calendar_id'] ) || empty( $appointment['appointment_date'] ) || empty( $appointment['start_time'] ) ) {
			return;
		}

		$this->promote(
			(int) $appointment['calendar_id'],
			(string) $appointment['appointment_date'],
			(string) $appointment['start_time']
		);
	}

	/**
	 * Promote the oldest waitlisted entry for a slot, if there is now room.
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Date (Y-m-d).
	 * @param string $start_time  Slot start time (H:i:s).
	 * @return int|null Promoted appointment ID, or null when nothing was promoted.
	 */
	public function promote( int $calendar_id, string $date, string $start_time ): ?int {
		$calendar = $this->calendar_repository->findById( $calendar_id );
		if ( ! is_array( $calendar ) || empty( $calendar['waitlist_enabled'] ) ) {
			return null;
		}

		$capacity = $this->resolve_capacity( $calendar, $date, $start_time );
		if ( $capacity <= 0 ) {
			return null;
		}

		$promoted_id = null;

		$this->appointment_repository->begin_transaction();
		try {
			// Recount under lock; only promote if the freed spot is genuinely open.
			if ( $this->appointment_repository->isSlotAvailable( $calendar_id, $date, $start_time, $capacity, true ) ) {
				$next = $this->appointment_repository->findOldestWaitlisted( $calendar_id, $date, $start_time, true );
				if ( is_array( $next ) && ! empty( $next['id'] ) ) {
					$requires_approval = ! empty( $calendar['requires_approval'] );
					$this->appointment_repository->promoteFromWaitlist( (int) $next['id'], $requires_approval );
					$promoted_id = (int) $next['id'];
				}
			}
			$this->appointment_repository->commit();
		} catch ( \Exception $e ) {
			$this->appointment_repository->rollback();
			return null;
		}

		if ( null !== $promoted_id ) {
			$this->notify_promoted( $promoted_id, $calendar );
		}

		return $promoted_id;
	}

	/**
	 * Resolve the slot capacity for the freed slot per scheduling mode.
	 *
	 * @param array<string, mixed> $calendar   Calendar row.
	 * @param string               $date       Date (Y-m-d).
	 * @param string               $start_time Slot start time (H:i:s).
	 * @return int Capacity, or 0 when the slot no longer exists (custom block removed).
	 */
	private function resolve_capacity( array $calendar, string $date, string $start_time ): int {
		if ( 'custom' === ( $calendar['schedule_type'] ?? 'regular' ) ) {
			$block = CustomSlots::find( $calendar['custom_slots'] ?? '', $date, $start_time );
			return null === $block ? 0 : (int) $block['capacity'];
		}

		return (int) ( $calendar['max_appointments_per_slot'] ?? 1 );
	}

	/**
	 * Email the promoted user (reuses the booking-confirmation toggle).
	 *
	 * @param int                  $appointment_id Promoted appointment ID.
	 * @param array<string, mixed> $calendar       Calendar row (carries email_config).
	 * @return void
	 */
	private function notify_promoted( int $appointment_id, array $calendar ): void {
		if ( self::ffc_emails_disabled() ) {
			return;
		}

		$email_config = json_decode( (string) ( $calendar['email_config'] ?? '' ), true );
		if ( ! is_array( $email_config ) || empty( $email_config['send_user_confirmation'] ) ) {
			return;
		}

		$appointment = $this->appointment_repository->findById( $appointment_id );
		if ( is_array( $appointment ) ) {
			do_action( 'ffcertificate_self_scheduling_appointment_promoted_email', $appointment, $calendar );
		}
	}
}
