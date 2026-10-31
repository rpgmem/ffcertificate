<?php
/**
 * Blocked Date Repository
 *
 * Data access layer for blocked dates (holidays, maintenance, etc).
 * Follows Repository pattern for separation of concerns.
 *
 * @package FreeFormCertificate\Repositories
 * @since 4.1.0
 * @version 4.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The sniff only recognises the global $wpdb->prepare(); this class binds wpdb as a property, so every $this->wpdb->prepare() call reads as unprepared SQL. Table names go through %i and every value through a placeholder; {$days}/{$days_old} are int-typed parameters.
/**
 * Database repository for blocked date records.
 */
class BlockedDateRepository extends AbstractRepository {

	/**
	 * Get table name
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return $this->wpdb->prefix . 'ffc_self_scheduling_blocked_dates';
	}

	/**
	 * Get cache group
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_self_scheduling_blocked_dates';
	}

	/**
	 * Find blocks for a calendar
	 *
	 * @param int      $calendar_id Calendar ID.
	 * @param int|null $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCalendar( int $calendar_id, ?int $limit = null, int $offset = 0 ): array {
		return $this->findAll(
			array( 'calendar_id' => $calendar_id ),
			'start_date',
			'ASC',
			$limit,
			$offset
		);
	}

	/**
	 * Get all global blocks (applies to all calendars)
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public function getGlobalBlocks(): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE calendar_id IS NULL ORDER BY start_date ASC', $this->table ),
			ARRAY_A
		);
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Check if date is blocked for calendar
	 *
	 * @param int         $calendar_id Calendar ID.
	 * @param string      $date Date in Y-m-d format.
	 * @param string|null $time     Optional time to check for partial blocks.
	 * @param string|null $end_time End of the slot starting at $time; with it a
	 *                              time-range block closes any slot it overlaps.
	 * @return bool
	 */
	public function isDateBlocked( int $calendar_id, string $date, ?string $time = null, ?string $end_time = null ): bool {
		return self::blocksMatch( $this->getBlocksForDate( $calendar_id, $date ), $date, $time, $end_time );
	}

	/**
	 * The calendar-specific and global blocks that reach a date, in one query,
	 * so a caller checking every slot of the day reads them once (#2244).
	 *
	 * A full-day or time-range block with no end date is one day, the day it
	 * starts (#2244): the month view always read it so, while this query read
	 * it as never ending. Only a recurring block runs on without an end.
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $date        Date in Y-m-d format.
	 * @return array<int, array<string, mixed>>
	 */
	public function getBlocksForDate( int $calendar_id, string $date ): array {
		$blocks_raw = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i
                WHERE (calendar_id = %d OR calendar_id IS NULL)
                AND start_date <= %s
                AND (end_date >= %s OR (end_date IS NULL AND (start_date = %s OR block_type = %s)))',
				$this->table,
				$calendar_id,
				$date,
				$date,
				$date,
				'recurring'
			),
			ARRAY_A
		);
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>> $blocks
		 */
		$blocks = is_array( $blocks_raw ) ? $blocks_raw : array();
		return $blocks;
	}

	/**
	 * Whether any of the blocks read by getBlocksForDate() closes the date, or
	 * the time on it.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks for the date.
	 * @param string                           $date   Date in Y-m-d format.
	 * @param string|null                      $time     Optional time to check for partial blocks.
	 * @param string|null                      $end_time End of the slot starting at $time. With it a
	 *                                                   time-range block closes every slot it overlaps
	 *                                                   (#2244); without it, only a slot starting inside it.
	 * @return bool
	 */
	public static function blocksMatch( array $blocks, string $date, ?string $time = null, ?string $end_time = null ): bool {
		foreach ( $blocks as $block ) {
			// Full day block.
			if ( 'full_day' === $block['block_type'] ) {
				return true;
			}

			// Time range block - only if time is provided.
			if ( 'time_range' === $block['block_type'] && null !== $time ) {
				if ( self::overlaps( $block, $time, $end_time ) ) {
					return true;
				}
			}

			// Recurring block.
			if ( 'recurring' === $block['block_type'] && ! empty( $block['recurring_pattern'] ) ) {
				// The column is JSON text this plugin wrote, but the row is a
				// generic `array<string, mixed>` read, so the value is `mixed`.
				// ArrayValue::string() refuses a non-scalar instead of letting
				// `(string) array()` reach the parser as the word "Array";
				// json_decode() of a non-JSON string is null, which
				// matchesRecurringPattern() already accepts.
				$pattern = json_decode( ArrayValue::string( $block, 'recurring_pattern' ), true );
				if ( self::matchesRecurringPattern( $date, $time, is_array( $pattern ) ? $pattern : array() ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a time-range block reaches a slot (#2244).
	 *
	 * Times compare as `H:i:s` text, which orders them correctly.
	 *
	 * @param array<string, mixed> $block    Time-range block.
	 * @param string               $start    Slot start.
	 * @param string|null          $end_time Slot end; null tests the start alone.
	 * @return bool
	 */
	private static function overlaps( array $block, string $start, ?string $end_time ): bool {
		$block_start = (string) ( $block['start_time'] ?? '' );
		$block_end   = (string) ( $block['end_time'] ?? '' );
		if ( null === $end_time || '' === $end_time ) {
			return $start >= $block_start && $start < $block_end;
		}
		return $start < $block_end && $end_time > $block_start;
	}

	/**
	 * Whether the calendar already has a block covering the one described,
	 * so adding it would change nothing (#2244): the same time range on the
	 * date, or a full-day block over every day of it.
	 *
	 * @param int         $calendar_id Calendar ID.
	 * @param string      $type        full_day | time_range.
	 * @param string      $start_date  First date.
	 * @param string      $end_date    Last date (the first for a time range).
	 * @param string|null $start_time  Time-range start.
	 * @param string|null $end_time    Time-range end.
	 * @return bool
	 */
	public function isCovered( int $calendar_id, string $type, string $start_date, string $end_date, ?string $start_time = null, ?string $end_time = null ): bool {
		foreach ( $this->findByCalendar( $calendar_id ) as $block ) {
			$from = (string) ( $block['start_date'] ?? '' );
			$to   = (string) ( $block['end_date'] ?? '' );
			$to   = '' === $to ? $from : $to;
			if ( 'full_day' === ( $block['block_type'] ?? '' ) && $from <= $start_date && $to >= $end_date ) {
				return true;
			}
			if ( 'time_range' === $type && 'time_range' === ( $block['block_type'] ?? '' ) && $from === $start_date
				&& (string) ( $block['start_time'] ?? '' ) <= (string) $start_time && (string) ( $block['end_time'] ?? '' ) >= (string) $end_time ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete one of a calendar's blocks; a global block or another calendar's
	 * is left alone (#2244).
	 *
	 * @param int $calendar_id Calendar ID.
	 * @param int $block_id    Block ID.
	 * @return bool True when a row was deleted.
	 */
	public function deleteForCalendar( int $calendar_id, int $block_id ): bool {
		$deleted = $this->wpdb->delete(
			$this->table,
			array(
				'id'          => $block_id,
				'calendar_id' => $calendar_id,
			),
			array( '%d', '%d' )
		);
		if ( $deleted ) {
			$this->clear_cache();
		}
		return (bool) $deleted;
	}

	/**
	 * Get blocked dates for a date range
	 *
	 * @param int    $calendar_id Calendar ID.
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array<int, array<string, mixed>>
	 */
	public function getBlockedDatesInRange( int $calendar_id, string $start_date, string $end_date ): array {
		$sql = $this->wpdb->prepare(
			'SELECT * FROM %i
             WHERE (calendar_id = %d OR calendar_id IS NULL)
             AND (
                 (start_date BETWEEN %s AND %s)
                 OR (end_date BETWEEN %s AND %s)
                 OR (start_date <= %s AND (end_date >= %s OR end_date IS NULL))
             )
             ORDER BY start_date ASC',
			$this->table,
			$calendar_id,
			$start_date,
			$end_date,
			$start_date,
			$end_date,
			$start_date,
			$end_date
		);

		$results = $this->wpdb->get_results( $sql, ARRAY_A );
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Create full day block
	 *
	 * @param int|null    $calendar_id NULL for global block.
	 * @param string      $start_date Start date.
	 * @param string|null $end_date For multi-day blocks.
	 * @param string|null $reason Reason.
	 * @return int|false
	 */
	public function createFullDayBlock( ?int $calendar_id, string $start_date, ?string $end_date = null, ?string $reason = null ) {
		return $this->insert(
			array(
				'calendar_id' => $calendar_id,
				'block_type'  => 'full_day',
				'start_date'  => $start_date,
				'end_date'    => $end_date,
				'reason'      => $reason,
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			)
		);
	}

	/**
	 * Create time range block
	 *
	 * @param int|null    $calendar_id Calendar ID.
	 * @param string      $date Date.
	 * @param string      $start_time Start time.
	 * @param string      $end_time End time.
	 * @param string|null $reason Reason.
	 * @return int|false
	 */
	public function createTimeRangeBlock( ?int $calendar_id, string $date, string $start_time, string $end_time, ?string $reason = null ) {
		return $this->insert(
			array(
				'calendar_id' => $calendar_id,
				'block_type'  => 'time_range',
				'start_date'  => $date,
				'start_time'  => $start_time,
				'end_time'    => $end_time,
				'reason'      => $reason,
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => get_current_user_id(),
			)
		);
	}

	/**
	 * Create recurring block
	 *
	 * Example pattern: {type: 'weekly', days: [0,6]} = weekends
	 *
	 * @param int|null             $calendar_id Calendar ID.
	 * @param string               $start_date Start date.
	 * @param array<string, mixed> $pattern Pattern.
	 * @param string|null          $reason Reason.
	 * @return int|false
	 */
	public function createRecurringBlock( ?int $calendar_id, string $start_date, array $pattern, ?string $reason = null ) {
		return $this->insert(
			array(
				'calendar_id'       => $calendar_id,
				'block_type'        => 'recurring',
				'start_date'        => $start_date,
				'recurring_pattern' => wp_json_encode( $pattern ),
				'reason'            => $reason,
				'created_at'        => current_time( 'mysql' ),
				'created_by'        => get_current_user_id(),
			)
		);
	}

	/**
	 * Delete expired blocks
	 *
	 * Cleanup blocks that have ended.
	 *
	 * @param int $days_old Number of days past end date.
	 * @return int|false Number of deleted rows
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public function deleteExpiredBlocks( int $days_old = 30 ) {
		_deprecated_function( __METHOD__, '6.43.0' );

		$cutoff_ts   = strtotime( "-{$days_old} days" );
		$cutoff_date = gmdate( 'Y-m-d', $cutoff_ts ? $cutoff_ts : time() );

		$sql = $this->wpdb->prepare(
			"DELETE FROM %i
             WHERE block_type != 'recurring'
             AND end_date IS NOT NULL
             AND end_date < %s",
			$this->table,
			$cutoff_date
		);

		if ( ! is_string( $sql ) ) {
			return false;
		}

		$result = $this->wpdb->query( $sql );
		return false === $result ? false : (int) $result;
	}

	/**
	 * Check if date/time matches recurring pattern
	 *
	 * @param string               $date Date.
	 * @param string|null          $time Time.
	 * @param array<string, mixed> $pattern Pattern.
	 * @return bool
	 */
	private static function matchesRecurringPattern( string $date, ?string $time, array $pattern ): bool {
		if ( empty( $pattern['type'] ) ) {
			return false;
		}

		$date_ts     = strtotime( $date );
		$timestamp   = $date_ts ? $date_ts : time();
		$day_of_week = \FreeFormCertificate\Core\Utils::get_day_of_week_number( $timestamp );

		switch ( $pattern['type'] ) {
			case 'weekly':
				// Check if day of week is in blocked days.
				if ( ! empty( $pattern['days'] ) && is_array( $pattern['days'] ) ) {
					return in_array( $day_of_week, $pattern['days'], true );
				}
				break;

			case 'monthly':
				// Block specific day of month (e.g., 1st, 15th).
				if ( ! empty( $pattern['days'] ) && is_array( $pattern['days'] ) ) {
					$day_of_month = (int) gmdate( 'j', $timestamp );
					return in_array( $day_of_month, $pattern['days'], true );
				}
				break;

			case 'yearly':
				// Block specific dates annually (e.g., holidays).
				if ( ! empty( $pattern['dates'] ) && is_array( $pattern['dates'] ) ) {
					$month_day = gmdate( 'm-d', $timestamp );
					return in_array( $month_day, $pattern['dates'], true );
				}
				break;
		}

		return false;
	}

	/**
	 * Get upcoming blocks for a calendar
	 *
	 * @param int $calendar_id Calendar ID.
	 * @param int $days Number of days to look ahead.
	 * @return array<int, array<string, mixed>>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public function getUpcomingBlocks( int $calendar_id, int $days = 30 ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		$start_date = gmdate( 'Y-m-d' );
		$end_ts     = strtotime( "+{$days} days" );
		$end_date   = gmdate( 'Y-m-d', $end_ts ? $end_ts : time() );

		return $this->getBlockedDatesInRange( $calendar_id, $start_date, $end_date );
	}
}
