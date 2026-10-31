<?php
/**
 * ClosedDayAppointments
 *
 * The personal-calendar appointments a global holiday takes away (#2244):
 * the open ones on the date, across every calendar, and their cancellation.
 * The Scheduling settings ask about them with the audience bookings of the
 * same day, through AudienceClosedDay's confirmation.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Repositories\AppointmentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and cancels the open appointments of a closed day.
 */
class ClosedDayAppointments {

	/**
	 * Appointments repository.
	 *
	 * @var AppointmentRepository
	 */
	private $appointments;

	/**
	 * Admin cancel, which notifies and frees the slot.
	 *
	 * @var AppointmentAdminActions|null
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param AppointmentRepository|null   $appointments Repository; a new one when null.
	 * @param AppointmentAdminActions|null $actions      Cancel; built on first use when null.
	 */
	public function __construct( $appointments = null, $actions = null ) {
		$this->appointments = $appointments ?? new AppointmentRepository();
		$this->actions      = $actions;
	}

	/**
	 * The pending, confirmed and waitlisted appointments on a date, on every
	 * calendar, each with its `calendar_title`.
	 *
	 * @param string $date Date (Y-m-d).
	 * @return array<int, array<string, mixed>>
	 */
	public function on( string $date ): array {
		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $date ) ) {
			return array();
		}
		return $this->appointments->getAllOnDate( $date, AppointmentHandler::CANCELLABLE_STATUSES );
	}

	/**
	 * Cancel the appointments of a closed day, each with the closure as its
	 * reason. The calendar's cancellation e-mail goes out where the calendar
	 * sends one; a row another request already changed is skipped.
	 *
	 * @param array<int, array<string, mixed>> $appointments The appointments on the date.
	 * @param string                           $reason       The reason each one carries.
	 * @return int How many were cancelled.
	 */
	public function cancel( array $appointments, string $reason ): int {
		$actions   = $this->actions ?? new AppointmentAdminActions( $this->appointments );
		$cancelled = 0;
		foreach ( $appointments as $appointment ) {
			if ( true === $actions->cancel( (int) $appointment['id'], get_current_user_id(), $reason ) ) {
				++$cancelled;
			}
		}
		return $cancelled;
	}
}
