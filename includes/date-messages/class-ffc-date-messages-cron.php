<?php
/**
 * Date-message daily cron.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The daily event that sends date messages, at a local time of day (#1538).
 *
 * WordPress schedules a daily event at a timestamp and repeats it every 24
 * hours, so the time of day is decided when the event is first scheduled. It
 * is computed in the site timezone (Settings → General), not the server's.
 * Changing the configured time therefore means rescheduling, which
 * `reschedule()` does; the send-time setting arrives with the admin screen.
 */
final class DateMessagesCron {

	/**
	 * The daily hook. Listed in `ScheduledTasks`.
	 */
	public const CRON_HOOK = 'ffc_date_messages_daily';

	/**
	 * Option holding the module's settings (`send_time` as `HH:MM`). Listed
	 * in `uninstall.php`.
	 */
	public const SETTINGS_OPTION = 'ffc_date_messages_settings';

	/**
	 * Send time when none is configured.
	 */
	public const DEFAULT_SEND_TIME = '08:00';

	/**
	 * Attach the callbacks. Called under the module toggle, on every request:
	 * WP-Cron never runs with `is_admin()` true.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( Runner::class, 'run_daily' ) );
		// One accepted argument: the event carries the run id.
		add_action( Digest::HOOK, array( Digest::class, 'send' ), 10, 1 );
		Runner::init();
	}

	/**
	 * Schedule the daily event when it is missing.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( self::next_run( time() ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Move the daily event to the configured time.
	 *
	 * @return void
	 */
	public static function reschedule(): void {
		self::unschedule();
		self::schedule();
	}

	/**
	 * Remove the daily event, every pending batch and every pending digest.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Runner::BATCH_HOOK );
		wp_clear_scheduled_hook( Digest::HOOK );
	}

	/**
	 * Configured send time, `HH:MM`.
	 *
	 * @return string
	 */
	public static function send_time(): string {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$time     = is_array( $settings ) && is_string( $settings['send_time'] ?? null ) ? $settings['send_time'] : '';
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DEFAULT_SEND_TIME;
	}

	/**
	 * The next moment, after `$now`, at the send time in the site timezone.
	 *
	 * @param int $now Unix time.
	 * @return int Unix time.
	 */
	public static function next_run( int $now ): int {
		[ $hour, $minute ] = array_map( 'intval', explode( ':', self::send_time() ) );

		$at = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( $hour, $minute );
		if ( $at->getTimestamp() <= $now ) {
			$at = $at->modify( '+1 day' );
		}
		return $at->getTimestamp();
	}
}
