<?php
/**
 * Appointment Reminder Scanner
 *
 * Cron driver for the self-scheduling appointment reminder email. The reminder
 * email handler ({@see AppointmentEmailHandler::send_reminder()}) and its whole
 * read/mark pipeline existed but had no trigger — nothing scheduled the scan or
 * fired the reminder hook, so enabling "Send reminder before appointment" did
 * nothing (#650). This scanner is the missing driver: run hourly, it finds
 * confirmed, not-yet-reminded appointments that are due per their calendar's
 * `reminder_hours_before`, fires the reminder hook, and marks them sent.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 6.14.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans for due appointment reminders and dispatches them.
 */
final class AppointmentReminderScanner {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Recurring cron hook that drives the scan.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'ffc_self_scheduling_reminder_scan';

	/**
	 * Cron callback: dispatch reminders for every due appointment.
	 *
	 * @return void
	 */
	public static function run(): void {
		// Skip entirely when emails are globally off — otherwise we would mark
		// appointments reminded without ever delivering the email, losing them.
		if ( self::ffc_emails_disabled() ) {
			return;
		}

		// Build calendar_id => reminder_hours_before for calendars that have
		// reminders enabled, and the distinct set of hour-windows to scan.
		$enabled   = array();
		$hours_set = array();
		foreach ( ( new CalendarRepository() )->getActiveCalendars() as $calendar ) {
			$config = self::decode_email_config( $calendar['email_config'] ?? '' );
			if ( empty( $config['send_reminder'] ) ) {
				continue;
			}
			$hours = isset( $config['reminder_hours_before'] ) ? max( 1, (int) $config['reminder_hours_before'] ) : 24;

			$enabled[ (int) $calendar['id'] ] = $hours;
			$hours_set[ $hours ]              = true;
		}

		if ( empty( $enabled ) ) {
			return;
		}

		$repo = new AppointmentRepository();
		foreach ( array_keys( $hours_set ) as $hours ) {
			foreach ( $repo->getUpcomingForReminders( (int) $hours ) as $appointment ) {
				$calendar_id = (int) ( $appointment['calendar_id'] ?? 0 );

				// Only remind when THIS calendar wants a reminder at THIS window
				// (the scan returns every calendar's appointments at `$hours`).
				if ( ( $enabled[ $calendar_id ] ?? null ) !== $hours ) {
					continue;
				}

				// The person turned reminders off on their dashboard (#1545).
				// Marked as handled all the same, so the next hourly scan does
				// not reconsider it: the decision is made once per appointment.
				$user_id = (int) ( $appointment['user_id'] ?? 0 );
				if ( ! \FreeFormCertificate\UserDashboard\UserManager::wants_notification( $user_id, \FreeFormCertificate\UserDashboard\UserManager::NOTIFY_APPOINTMENT_REMINDER ) ) {
					$repo->markReminderSent( (int) ( $appointment['id'] ?? 0 ) );
					continue;
				}

				// Booked or approved inside the window: the confirmation e-mail
				// went out moments ago, so a reminder now repeats it (#2689).
				if ( self::confirmed_inside_window( $appointment, (int) $hours ) ) {
					$repo->markReminderSent( (int) ( $appointment['id'] ?? 0 ) );
					continue;
				}

				$calendar = array(
					'id'           => $calendar_id,
					'title'        => (string) ( $appointment['calendar_title'] ?? '' ),
					'email_config' => (string) ( $appointment['email_config'] ?? '' ),
				);

				do_action( 'ffcertificate_self_scheduling_appointment_reminder_email', $appointment, $calendar );

				// A send the mailer refused stays unmarked, so the next hourly
				// scan tries again while the slot is still ahead (#2689).
				if ( false === AppointmentEmailHandler::take_reminder_outcome( (int) ( $appointment['id'] ?? 0 ) ) ) {
					continue;
				}
				$repo->markReminderSent( (int) ( $appointment['id'] ?? 0 ) );
			}
		}
	}

	/**
	 * Whether the appointment was booked or approved inside its reminder window.
	 *
	 * The later of `created_at` and `approved_at` (both instants) is when the
	 * person was told the appointment holds. When that falls less than
	 * `$hours` before the slot starts, the reminder would arrive right after
	 * that message.
	 *
	 * @param array<string, mixed> $appointment Appointment row.
	 * @param int                  $hours       Reminder window, in hours.
	 * @return bool
	 */
	private static function confirmed_inside_window( array $appointment, int $hours ): bool {
		$told_at = max( (int) ( $appointment['created_at'] ?? 0 ), (int) ( $appointment['approved_at'] ?? 0 ) );
		if ( $told_at <= 0 ) {
			return false;
		}
		try {
			$start = new \DateTimeImmutable( (string) ( $appointment['appointment_date'] ?? '' ) . ' ' . (string) ( $appointment['start_time'] ?? '' ), wp_timezone() );
		} catch ( \Exception $e ) {
			return false;
		}
		return $told_at > $start->getTimestamp() - $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Decode a calendar's `email_config` (JSON string or already-decoded array).
	 *
	 * @param mixed $raw Raw config.
	 * @return array<string, mixed>
	 */
	private static function decode_email_config( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
