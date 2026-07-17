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
	public const CRON_HOOK = 'ffcertificate_self_scheduling_reminder_scan';

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

				$calendar = array(
					'id'           => $calendar_id,
					'title'        => (string) ( $appointment['calendar_title'] ?? '' ),
					'email_config' => (string) ( $appointment['email_config'] ?? '' ),
				);

				do_action( 'ffcertificate_self_scheduling_appointment_reminder_email', $appointment, $calendar );
				$repo->markReminderSent( (int) ( $appointment['id'] ?? 0 ) );
			}
		}
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
