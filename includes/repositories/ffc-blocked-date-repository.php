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

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 */
	public function getGlobalBlocks(): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * @param string|null $time Optional time to check for partial blocks.
	 * @return bool
	 */
	public function isDateBlocked( int $calendar_id, string $date, ?string $time = null ): bool {
		// Check calendar-specific and global blocks.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blocks_raw = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i
                WHERE (calendar_id = %d OR calendar_id IS NULL)
                AND start_date <= %s
                AND (end_date IS NULL OR end_date >= %s)',
				$this->table,
				$calendar_id,
				$date,
				$date
			),
			ARRAY_A
		);
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>> $blocks
		 */
		$blocks = is_array( $blocks_raw ) ? $blocks_raw : array();

		foreach ( $blocks as $block ) {
			// Full day block.
			if ( 'full_day' === $block['block_type'] ) {
				return true;
			}

			// Time range block - only if time is provided.
			if ( 'time_range' === $block['block_type'] && null !== $time ) {
				if ( $time >= $block['start_time'] && $time < $block['end_time'] ) {
					return true;
				}
			}

			// Recurring block.
			if ( 'recurring' === $block['block_type'] && ! empty( $block['recurring_pattern'] ) ) {
				if ( $this->matchesRecurringPattern( $date, $time, json_decode( $block['recurring_pattern'], true ) ) ) {
					return true;
				}
			}
		}

		return false;
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 */
	public function deleteExpiredBlocks( int $days_old = 30 ) {
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	private function matchesRecurringPattern( string $date, ?string $time, array $pattern ): bool {
		if ( empty( $pattern['type'] ) ) {
			return false;
		}

		$date_ts     = strtotime( $date );
		$timestamp   = $date_ts ? $date_ts : time();
		$day_of_week = (int) gmdate( 'w', $timestamp );

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
	 */
	public function getUpcomingBlocks( int $calendar_id, int $days = 30 ): array {
		$start_date = gmdate( 'Y-m-d' );
		$end_ts     = strtotime( "+{$days} days" );
		$end_date   = gmdate( 'Y-m-d', $end_ts ? $end_ts : time() );

		return $this->getBlockedDatesInRange( $calendar_id, $start_date, $end_date );
	}
}
