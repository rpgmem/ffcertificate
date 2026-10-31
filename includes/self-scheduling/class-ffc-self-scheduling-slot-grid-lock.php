<?php
/**
 * SlotGridLock
 *
 * The slot grid of a regular calendar is its duration, its interval and its
 * working hours (#2244). Changing any of them moves the grid under the
 * appointments already booked on it: a 09:30 booking falls off a new
 * 45-minute grid and 09:45 becomes bookable on top of it. So a save that
 * would move or remove the slot of an open appointment keeps the grid it
 * had, and the editor lists the appointments that held it.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares two grids against the open appointments, and carries the refusal
 * from the save to the editor.
 */
class SlotGridLock {

	/**
	 * Seconds the refusal waits for the editor that follows the save.
	 */
	private const NOTICE_TTL = 60;

	/**
	 * The appointments whose slot the new grid moves or removes: the ones on
	 * a slot of the old grid that the new grid does not hold with the same
	 * start and end. An appointment that was not on the old grid either (a
	 * bypass booking on a closed day) is not the change's doing and is left
	 * out.
	 *
	 * @param array<string, mixed>             $old          Grid as stored: slot_duration, slot_interval, working_hours.
	 * @param array<string, mixed>             $new          Grid as submitted, same keys.
	 * @param array<int, array<string, mixed>> $appointments Open appointments: appointment_date, start_time, end_time.
	 * @return array<int, array<string, mixed>>
	 */
	public static function moved( array $old, array $new, array $appointments ): array {
		$moved = array();
		foreach ( $appointments as $appointment ) {
			$date  = ArrayValue::string( $appointment, 'appointment_date' );
			$start = ArrayValue::string( $appointment, 'start_time' );
			if ( null === SlotGrid::find( $old, $date, $start, false ) ) {
				continue;
			}
			$slot = SlotGrid::find( $new, $date, $start, false );
			$end  = SlotGrid::normalize( ArrayValue::string( $appointment, 'end_time' ) );
			if ( null === $slot || ( null !== $end && $slot['end'] !== $end ) ) {
				$moved[] = $appointment;
			}
		}
		return $moved;
	}

	/**
	 * Keep the refusal for the editor the save redirects to.
	 *
	 * @param int                              $post_id      Calendar post.
	 * @param array<int, array<string, mixed>> $appointments The appointments that held the grid.
	 * @return void
	 */
	public static function remember( int $post_id, array $appointments ): void {
		$rows = array();
		foreach ( $appointments as $appointment ) {
			$rows[] = array(
				'date'  => ArrayValue::string( $appointment, 'appointment_date' ),
				'start' => substr( ArrayValue::string( $appointment, 'start_time' ), 0, 5 ),
				'end'   => substr( ArrayValue::string( $appointment, 'end_time' ), 0, 5 ),
			);
		}
		set_transient( self::key( $post_id ), $rows, self::NOTICE_TTL );
	}

	/**
	 * Print the refusal once, if the last save of this calendar left one.
	 *
	 * @param int $post_id Calendar post.
	 * @return void
	 */
	public static function render_notice( int $post_id ): void {
		$rows = get_transient( self::key( $post_id ) );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return;
		}
		delete_transient( self::key( $post_id ) );

		$items = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items .= '<li>' . esc_html(
				\FreeFormCertificate\Core\DateFormatter::format_wallclock_date( ArrayValue::string( $row, 'date' ) )
				. ' ' . ArrayValue::string( $row, 'start' ) . '–' . ArrayValue::string( $row, 'end' )
			) . '</li>';
		}

		$count = count( $rows );
		\FreeFormCertificate\Core\AdminNotice::render(
			'error',
			'<p>' . esc_html(
				sprintf(
					/* translators: %d: number of appointments */
					_n(
						'The appointment duration, interval and working hours were not changed: %d open appointment sits on a slot the change would move or remove. Cancel or move it first.',
						'The appointment duration, interval and working hours were not changed: %d open appointments sit on slots the change would move or remove. Cancel or move them first.',
						$count,
						'ffcertificate'
					),
					$count
				)
			) . '</p><ul class="ffc-grid-lock-list">' . $items . '</ul>',
			array(
				'additional_classes' => array( 'inline' ),
				'paragraph_wrap'     => false,
			)
		);
	}

	/**
	 * The transient that carries the refusal, per user and calendar.
	 *
	 * @param int $post_id Calendar post.
	 * @return string
	 */
	private static function key( int $post_id ): string {
		return 'ffc_ss_grid_lock_' . get_current_user_id() . '_' . $post_id;
	}
}
