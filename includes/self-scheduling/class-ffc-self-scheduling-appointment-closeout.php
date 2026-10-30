<?php
/**
 * Appointment Close-out
 *
 * Closes personal-calendar appointments whose date passed a configured number
 * of days ago and that nobody closed by hand: a confirmed one is marked
 * completed (presumed attended), a pending one is cancelled, since nobody
 * approved it before its date. Runs daily from WP-Cron and on demand from the
 * Appointments list; both go through run(), and neither sends an e-mail.
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
 * The old-appointment close-out.
 */
final class AppointmentCloseout {

	/**
	 * Recurring cron hook that runs the close-out once a day.
	 */
	public const CRON_HOOK = 'ffcertificate_self_scheduling_closeout';

	/**
	 * Option holding the number of days after the appointment date; 0 turns
	 * the close-out off. Listed in `uninstall.php`.
	 */
	public const OPTION = 'ffc_ss_closeout_days';

	/**
	 * Days used when the option was never saved.
	 */
	public const DEFAULT_DAYS = 7;

	/**
	 * Highest accepted number of days.
	 */
	public const MAX_DAYS = 365;

	/**
	 * Rows per UPDATE, so one run never holds a long lock on a shared host.
	 */
	private const BATCH = 500;

	/**
	 * The configured number of days, clamped on read.
	 *
	 * @return int 0 when the close-out is off.
	 */
	public static function days(): int {
		return self::clamp( get_option( self::OPTION, self::DEFAULT_DAYS ) );
	}

	/**
	 * Clamp a stored or submitted value to 0..MAX_DAYS.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_DAYS;
		}
		return max( 0, min( self::MAX_DAYS, (int) $value ) );
	}

	/**
	 * The last appointment date a close-out with $days reaches: today minus
	 * $days in the site timezone, because the appointment date is wall-clock.
	 *
	 * @param int $days Days after the appointment date.
	 * @return string `Y-m-d`.
	 */
	public static function cutoff( int $days ): string {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )
			->modify( '-' . max( 0, $days ) . ' days' )
			->format( 'Y-m-d' );
	}

	/**
	 * What a run would change now.
	 *
	 * @return array{confirmed: int, pending: int} Both zero when the close-out is off.
	 */
	public static function due(): array {
		$days = self::days();
		if ( $days <= 0 ) {
			return array(
				'confirmed' => 0,
				'pending'   => 0,
			);
		}
		return ( new AppointmentRepository() )->countDueForCloseout( self::cutoff( $days ) );
	}

	/**
	 * Close every due appointment.
	 *
	 * Each batch counts the rows it CHANGED, and the loop stops on a batch
	 * that changed fewer than it asked for, so it ends whatever the predicate
	 * drifts into (CLAUDE.md "Batched migrations — what makes a batch stop").
	 *
	 * @param int $user_id Operator who asked, 0 for the scheduled run.
	 * @return array{completed: int, cancelled: int, failed: bool}
	 */
	public static function run( int $user_id = 0 ): array {
		$result = array(
			'completed' => 0,
			'cancelled' => 0,
			'failed'    => false,
		);
		$days   = self::days();
		if ( $days <= 0 ) {
			return $result;
		}

		$repo   = new AppointmentRepository();
		$cutoff = self::cutoff( $days );
		$reason = __( 'Closed automatically: the date passed without approval.', 'ffcertificate' );

		do {
			$rows = $repo->completeConfirmedUpTo( $cutoff, self::BATCH );
			if ( false === $rows ) {
				$result['failed'] = true;
				break;
			}
			$result['completed'] += $rows;
		} while ( self::BATCH === $rows );

		do {
			$rows = $repo->expirePendingUpTo( $cutoff, $reason, self::BATCH );
			if ( false === $rows ) {
				$result['failed'] = true;
				break;
			}
			$result['cancelled'] += $rows;
		} while ( self::BATCH === $rows );

		if ( $result['completed'] + $result['cancelled'] > 0 && class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'appointment_bulk_closed',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'cutoff'    => $cutoff,
					'completed' => $result['completed'],
					'cancelled' => $result['cancelled'],
					'trigger'   => $user_id > 0 ? 'manual' : 'cron',
				),
				$user_id
			);
		}

		return $result;
	}

	/**
	 * Cron callback.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::run();
	}
}
