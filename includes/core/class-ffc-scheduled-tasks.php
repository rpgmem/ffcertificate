<?php
/**
 * The plugin's scheduled tasks: what they are, when they last ran, and the
 * server line that keeps them running.
 *
 * @package FreeFormCertificate\Core
 * @since   6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One register of every WP-Cron hook the plugin schedules (#1538).
 *
 * WHY A REGISTER, AND WHY IT DOES NOT SCHEDULE ANYTHING
 *
 * The hook names are written out by hand in the Loader, the Activator, the
 * Deactivator and `uninstall.php`, and nothing compared them: measured when
 * this was written, one recurring hook was missing from `uninstall.php` and a
 * single-event hook was cleared nowhere. Moving the scheduling here would be
 * churn across four files, and `uninstall.php` is read as TEXT by the
 * fresh-install manifest, so it has to keep its literals anyway. The register
 * is instead what the Scheduled tasks screen reads and what
 * `ScheduledTasksRegistryTest` compares those four places against.
 *
 * The names are literals rather than the owning classes' constants on purpose:
 * referencing `AppointmentReminderScanner::CRON_HOOK` from Core would add a
 * Core > SelfScheduling edge to the module-boundary baseline, and two more like
 * it. The same test asserts each literal still equals its constant, so a
 * rename cannot pass unnoticed.
 *
 * THE HEARTBEAT
 *
 * WordPress records when an event is due, never when it last ran. `init()`
 * adds one listener per recurring hook, at priority 999 so it runs after the
 * real work, that stores the time in one option. A callback that dies leaves
 * the previous time in place -- which is the signal the screen exists to show.
 * No existing callback is touched, and a manual action that calls a callback
 * directly (the Cloudflare "refresh now" button) does not count as a run,
 * because it does not fire the hook.
 */
final class ScheduledTasks {

	/**
	 * Option holding `hook => unix time of the last run` (Category A).
	 * Listed in `uninstall.php`.
	 */
	public const HEARTBEAT_OPTION = 'ffc_cron_heartbeats';

	/**
	 * Recurrence of a hook that is scheduled once per piece of work.
	 */
	public const SINGLE = 'single';

	/**
	 * Server crontab frequencies offered, in minutes.
	 */
	public const CRONTAB_MINUTES = array( 5, 10, 15, 30 );

	/**
	 * Server crontab methods offered.
	 */
	public const CRONTAB_METHODS = array( 'wp_cli', 'wget', 'curl' );

	/**
	 * Every hook the plugin schedules.
	 *
	 * Shape per entry: `recurrence` ('daily' | 'hourly' | self::SINGLE) and
	 * `module` (a `SettingsReader::MODULE_SLUGS` slug whose toggle silences the
	 * callback, or null when the hook runs regardless).
	 *
	 * @var array<string, array{recurrence: string, module: string|null}>
	 */
	private const TASKS = array(
		'ffcertificate_daily_cleanup_hook'            => array(
			'recurrence' => 'daily',
			'module'     => null,
		),
		'ffc_daily_expired_tickets_cleanup'           => array(
			'recurrence' => 'daily',
			'module'     => 'certificates',
		),
		'ffcertificate_reregistration_expire_hook'    => array(
			'recurrence' => 'daily',
			'module'     => 'reregistration',
		),
		'ffcertificate_self_scheduling_reminder_scan' => array(
			'recurrence' => 'hourly',
			'module'     => 'self_scheduling',
		),
		'ffc_cloudflare_cidr_refresh'                 => array(
			'recurrence' => 'daily',
			'module'     => null,
		),
		'ffc_process_submission_async'                => array(
			'recurrence' => self::SINGLE,
			'module'     => null,
		),
		'ffc_reregistration_reminder_batch'           => array(
			'recurrence' => self::SINGLE,
			'module'     => 'reregistration',
		),
		'ffc_date_messages_daily'                     => array(
			'recurrence' => 'daily',
			'module'     => 'date_messages',
		),
		'ffc_date_messages_batch'                     => array(
			'recurrence' => self::SINGLE,
			'module'     => 'date_messages',
		),
		'ffc_date_messages_digest'                    => array(
			'recurrence' => self::SINGLE,
			'module'     => 'date_messages',
		),
	);

	/**
	 * How long past its interval a recurring task may go unrun before the
	 * screen calls it late: the interval plus a margin for WP-Cron's
	 * traffic-driven drift.
	 *
	 * @var array<string, int>
	 */
	private const LATE_AFTER = array(
		'daily'  => 26 * HOUR_IN_SECONDS,
		'hourly' => 2 * HOUR_IN_SECONDS,
	);

	/**
	 * Every registered hook with its metadata.
	 *
	 * @return array<string, array{recurrence: string, module: string|null}>
	 */
	public static function all(): array {
		return self::TASKS;
	}

	/**
	 * Human label of a hook. Kept out of the constant so the register itself
	 * never calls `__()`; this runs only when a screen renders.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	public static function label( string $hook ): string {
		switch ( $hook ) {
			case 'ffcertificate_daily_cleanup_hook':
				return __( 'Daily cleanup (submissions, export jobs, expired sessions, activity log)', 'ffcertificate' );
			case 'ffc_daily_expired_tickets_cleanup':
				return __( 'Expired ticket cleanup', 'ffcertificate' );
			case 'ffcertificate_reregistration_expire_hook':
				return __( 'Reregistration expiry and automated reminders', 'ffcertificate' );
			case 'ffcertificate_self_scheduling_reminder_scan':
				return __( 'Appointment reminder scan', 'ffcertificate' );
			case 'ffc_cloudflare_cidr_refresh':
				return __( 'Cloudflare IP range refresh', 'ffcertificate' );
			case 'ffc_process_submission_async':
				return __( 'Submission processing (one per submission)', 'ffcertificate' );
			case 'ffc_reregistration_reminder_batch':
				return __( 'Reregistration reminder batch (one per batch)', 'ffcertificate' );
			case 'ffc_date_messages_daily':
				return __( 'Date messages (birthdays and other dates)', 'ffcertificate' );
			case 'ffc_date_messages_batch':
				return __( 'Date messages batch (one per batch)', 'ffcertificate' );
			case 'ffc_date_messages_digest':
				return __( 'Date messages manager summary (one per run)', 'ffcertificate' );
		}
		return $hook;
	}

	/**
	 * Attach the heartbeat listener to every recurring hook. Called on every
	 * request -- never behind `is_admin()`, which is false under WP-Cron.
	 *
	 * @return void
	 */
	public static function init(): void {
		foreach ( self::TASKS as $hook => $task ) {
			if ( self::SINGLE === $task['recurrence'] ) {
				continue;
			}
			add_action(
				$hook,
				static function () use ( $hook ): void {
					self::touch( $hook );
				},
				999
			);
		}
	}

	/**
	 * Record that a hook ran now.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public static function touch( string $hook ): void {
		$beats          = self::heartbeats();
		$beats[ $hook ] = time();
		update_option( self::HEARTBEAT_OPTION, $beats, false );
	}

	/**
	 * Last-run times keyed by hook.
	 *
	 * @return array<string, int>
	 */
	public static function heartbeats(): array {
		$stored = get_option( self::HEARTBEAT_OPTION, array() );
		$out    = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $hook => $ts ) {
				if ( is_string( $hook ) && is_numeric( $ts ) ) {
					$out[ $hook ] = (int) $ts;
				}
			}
		}
		return $out;
	}

	/**
	 * State of one recurring hook at a given moment.
	 *
	 * - `not_scheduled` -- WordPress holds no event for it;
	 * - `never_run`     -- scheduled, but no heartbeat recorded yet (normal
	 *                      right after an update, until its first run);
	 * - `late`          -- the last run is older than the interval allows;
	 * - `ok`.
	 *
	 * @param string   $hook      Hook name.
	 * @param int|null $next_run  Next scheduled time, or null.
	 * @param int|null $last_run  Last recorded run, or null.
	 * @param int      $now       Current time.
	 * @return string
	 */
	public static function state( string $hook, ?int $next_run, ?int $last_run, int $now ): string {
		if ( null === $next_run ) {
			return 'not_scheduled';
		}
		if ( null === $last_run ) {
			return 'never_run';
		}
		$recurrence = self::TASKS[ $hook ]['recurrence'] ?? 'daily';
		$limit      = self::LATE_AFTER[ $recurrence ] ?? self::LATE_AFTER['daily'];
		return ( $now - $last_run ) > $limit ? 'late' : 'ok';
	}

	/**
	 * Rows for the screen: one per recurring hook.
	 *
	 * @param int $now Current time.
	 * @return array<int, array{hook: string, label: string, recurrence: string, module: string|null, module_enabled: bool, next_run: int|null, last_run: int|null, state: string}>
	 */
	public static function report( int $now ): array {
		$beats = self::heartbeats();
		$rows  = array();
		foreach ( self::TASKS as $hook => $task ) {
			if ( self::SINGLE === $task['recurrence'] ) {
				continue;
			}
			$next     = wp_next_scheduled( $hook );
			$next_run = false === $next ? null : (int) $next;
			$last_run = $beats[ $hook ] ?? null;

			$rows[] = array(
				'hook'           => $hook,
				'label'          => self::label( $hook ),
				'recurrence'     => $task['recurrence'],
				'module'         => $task['module'],
				'module_enabled' => null === $task['module'] || SettingsReader::module_enabled( $task['module'] ),
				'next_run'       => $next_run,
				'last_run'       => $last_run,
				'state'          => self::state( $hook, $next_run, $last_run, $now ),
			);
		}
		return $rows;
	}

	/**
	 * Pending single events per registered single-event hook.
	 *
	 * @param array<mixed> $crons The cron array (`_get_cron_array()`).
	 * @return array<string, int>
	 */
	public static function pending_singles( array $crons ): array {
		$counts = array();
		foreach ( self::TASKS as $hook => $task ) {
			if ( self::SINGLE === $task['recurrence'] ) {
				$counts[ $hook ] = 0;
			}
		}
		foreach ( $crons as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}
			foreach ( $events as $hook => $instances ) {
				if ( isset( $counts[ $hook ] ) && is_array( $instances ) ) {
					$counts[ $hook ] += count( $instances );
				}
			}
		}
		return $counts;
	}

	/**
	 * The server crontab line that drives WP-Cron.
	 *
	 * The line only winds WP-Cron up; when each task runs is what WordPress
	 * scheduled. Paths and URLs are single-quoted with any quote inside
	 * escaped, so a path with a space or an apostrophe stays one argument.
	 *
	 * @param string $method   One of CRONTAB_METHODS.
	 * @param int    $minutes  One of CRONTAB_MINUTES.
	 * @param string $abspath  WordPress root directory.
	 * @param string $site_url Site URL.
	 * @return string Empty when the method or frequency is not offered.
	 */
	public static function crontab_line( string $method, int $minutes, string $abspath, string $site_url ): string {
		if ( ! in_array( $minutes, self::CRONTAB_MINUTES, true ) ) {
			return '';
		}

		$schedule = '*/' . $minutes . ' * * * *';
		$cron_url = self::shell_quote( rtrim( $site_url, '/' ) . '/wp-cron.php?doing_wp_cron' );

		switch ( $method ) {
			case 'wp_cli':
				return $schedule . ' cd ' . self::shell_quote( rtrim( $abspath, '/' ) ) . ' && wp cron event run --due-now --quiet';
			case 'wget':
				return $schedule . ' wget -q -O - ' . $cron_url . ' >/dev/null 2>&1';
			case 'curl':
				return $schedule . ' curl -s ' . $cron_url . ' >/dev/null 2>&1';
		}

		return '';
	}

	/**
	 * Every crontab line, keyed `method` => `minutes` => line.
	 *
	 * @param string $abspath  WordPress root directory.
	 * @param string $site_url Site URL.
	 * @return array<string, array<int, string>>
	 */
	public static function crontab_lines( string $abspath, string $site_url ): array {
		$out = array();
		foreach ( self::CRONTAB_METHODS as $method ) {
			foreach ( self::CRONTAB_MINUTES as $minutes ) {
				$out[ $method ][ $minutes ] = self::crontab_line( $method, $minutes, $abspath, $site_url );
			}
		}
		return $out;
	}

	/**
	 * POSIX single-quote a shell argument.
	 *
	 * @param string $value Raw argument.
	 * @return string
	 */
	private static function shell_quote( string $value ): string {
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}
}
