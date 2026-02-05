<?php
declare(strict_types=1);

/**
 * Blocked Date Repository
 *
 * Data access layer for blocked dates (holidays, maintenance, etc).
 * Follows Repository pattern for separation of concerns.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

class BlockedDateRepository extends AbstractRepository {

    /**
     * Get table name
     *
     * @return string
     */
    protected function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_self_scheduling_blocked_dates';
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
     * @param int $calendar_id
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function findByCalendar(int $calendar_id, ?int $limit = null, int $offset = 0): array {
        return $this->findAll(
            ['calendar_id' => $calendar_id],
            'start_date',
            'ASC',
            $limit,
            $offset
        );
    }

    /**
     * Get all global blocks (applies to all calendars)
     *
     * @return array
     */
    public function getGlobalBlocks(): array {
        $sql = "SELECT * FROM {$this->table} WHERE calendar_id IS NULL ORDER BY start_date ASC";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Check if date is blocked for calendar
     *
     * @param int $calendar_id
     * @param string $date Date in Y-m-d format
     * @param string|null $time Optional time to check for partial blocks
     * @return bool
     */
    public function isDateBlocked(int $calendar_id, string $date, ?string $time = null): bool {
        // Check calendar-specific and global blocks
        $sql = "SELECT * FROM {$this->table}
                WHERE (calendar_id = %d OR calendar_id IS NULL)
                AND start_date <= %s
                AND (end_date IS NULL OR end_date >= %s)";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $blocks = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $calendar_id, $date, $date),
            ARRAY_A
        );

        foreach ($blocks as $block) {
            // Full day block
            if ($block['block_type'] === 'full_day') {
                return true;
            }

            // Time range block - only if time is provided
            if ($block['block_type'] === 'time_range' && $time !== null) {
                if ($time >= $block['start_time'] && $time < $block['end_time']) {
                    return true;
                }
            }

            // Recurring block
            if ($block['block_type'] === 'recurring' && !empty($block['recurring_pattern'])) {
                if ($this->matchesRecurringPattern($date, $time, json_decode($block['recurring_pattern'], true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get blocked dates for a date range
     *
     * @param int $calendar_id
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function getBlockedDatesInRange(int $calendar_id, string $start_date, string $end_date): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE (calendar_id = %d OR calendar_id IS NULL)
             AND (
                 (start_date BETWEEN %s AND %s)
                 OR (end_date BETWEEN %s AND %s)
                 OR (start_date <= %s AND (end_date >= %s OR end_date IS NULL))
             )
             ORDER BY start_date ASC",
            $calendar_id,
            $start_date,
            $end_date,
            $start_date,
            $end_date,
            $start_date,
            $end_date
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Create full day block
     *
     * @param int|null $calendar_id NULL for global block
     * @param string $start_date
     * @param string|null $end_date For multi-day blocks
     * @param string|null $reason
     * @return int|false
     */
    public function createFullDayBlock(?int $calendar_id, string $start_date, ?string $end_date = null, ?string $reason = null) {
        return $this->insert([
            'calendar_id' => $calendar_id,
            'block_type' => 'full_day',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'reason' => $reason,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ]);
    }

    /**
     * Create time range block
     *
     * @param int|null $calendar_id
     * @param string $date
     * @param string $start_time
     * @param string $end_time
     * @param string|null $reason
     * @return int|false
     */
    public function createTimeRangeBlock(?int $calendar_id, string $date, string $start_time, string $end_time, ?string $reason = null) {
        return $this->insert([
            'calendar_id' => $calendar_id,
            'block_type' => 'time_range',
            'start_date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'reason' => $reason,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ]);
    }

    /**
     * Create recurring block
     *
     * Example pattern: {type: 'weekly', days: [0,6]} = weekends
     *
     * @param int|null $calendar_id
     * @param string $start_date
     * @param array $pattern
     * @param string|null $reason
     * @return int|false
     */
    public function createRecurringBlock(?int $calendar_id, string $start_date, array $pattern, ?string $reason = null) {
        return $this->insert([
            'calendar_id' => $calendar_id,
            'block_type' => 'recurring',
            'start_date' => $start_date,
            'recurring_pattern' => json_encode($pattern),
            'reason' => $reason,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ]);
    }

    /**
     * Delete expired blocks
     *
     * Cleanup blocks that have ended.
     *
     * @param int $days_old Number of days past end date
     * @return int|false Number of deleted rows
     */
    public function deleteExpiredBlocks(int $days_old = 30) {
        $cutoff_date = gmdate('Y-m-d', strtotime("-{$days_old} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE block_type != 'recurring'
             AND end_date IS NOT NULL
             AND end_date < %s",
            $cutoff_date
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $this->wpdb->query($sql);
    }

    /**
     * Check if date/time matches recurring pattern
     *
     * @param string $date
     * @param string|null $time
     * @param array $pattern
     * @return bool
     */
    private function matchesRecurringPattern(string $date, ?string $time, array $pattern): bool {
        if (!is_array($pattern) || empty($pattern['type'])) {
            return false;
        }

        $timestamp = strtotime($date);
        $day_of_week = (int)gmdate('w', $timestamp);

        switch ($pattern['type']) {
            case 'weekly':
                // Check if day of week is in blocked days
                if (!empty($pattern['days']) && is_array($pattern['days'])) {
                    return in_array($day_of_week, $pattern['days']);
                }
                break;

            case 'monthly':
                // Block specific day of month (e.g., 1st, 15th)
                if (!empty($pattern['days']) && is_array($pattern['days'])) {
                    $day_of_month = (int)gmdate('j', $timestamp);
                    return in_array($day_of_month, $pattern['days']);
                }
                break;

            case 'yearly':
                // Block specific dates annually (e.g., holidays)
                if (!empty($pattern['dates']) && is_array($pattern['dates'])) {
                    $month_day = gmdate('m-d', $timestamp);
                    return in_array($month_day, $pattern['dates']);
                }
                break;
        }

        return false;
    }

    /**
     * Get upcoming blocks for a calendar
     *
     * @param int $calendar_id
     * @param int $days Number of days to look ahead
     * @return array
     */
    public function getUpcomingBlocks(int $calendar_id, int $days = 30): array {
        $start_date = gmdate('Y-m-d');
        $end_date = gmdate('Y-m-d', strtotime("+{$days} days"));

        return $this->getBlockedDatesInRange($calendar_id, $start_date, $end_date);
    }
}
