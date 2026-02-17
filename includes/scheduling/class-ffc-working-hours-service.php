<?php
declare(strict_types=1);

/**
 * Working Hours Service
 *
 * Shared service for validating and checking working hours across both
 * self-scheduling and audience scheduling systems.
 *
 * Supports two JSON formats:
 * - Self-Scheduling: [{day: 0-6, start: "09:00", end: "17:00"}, ...]
 * - Audience: {mon: {start: "08:00", end: "18:00", closed: false}, ...}
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Scheduling
 */

namespace FreeFormCertificate\Scheduling;

if (!defined('ABSPATH')) {
    exit;
}

class WorkingHoursService {

    /**
     * Day name mapping (index => short name)
     */
    private const DAY_NAMES = array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat');

    /**
     * Check if a given date/time falls within working hours.
     *
     * Accepts both JSON formats used in the codebase.
     *
     * @param string $date  Date string (Y-m-d)
     * @param string $time  Time string (H:i or H:i:s)
     * @param string|array<mixed> $working_hours JSON string or decoded array
     * @return bool True if within working hours (or no restrictions defined)
     */
    public static function is_within_working_hours(string $date, string $time, $working_hours): bool {
        $hours = self::normalize($working_hours);
        if (empty($hours)) {
            return true; // No restrictions
        }

        $day_of_week = (int) gmdate('w', strtotime($date));
        $day_name = self::DAY_NAMES[$day_of_week];

        // Keyed format: {mon: {start, end, closed}, ...}
        if (isset($hours[$day_name])) {
            $day_hours = $hours[$day_name];

            if (!empty($day_hours['closed'])) {
                return false;
            }

            if (!isset($day_hours['start']) || !isset($day_hours['end'])) {
                return true;
            }

            return self::time_in_range($time, $day_hours['start'], $day_hours['end']);
        }

        // Array-of-objects format: [{day: 0-6, start: "09:00", end: "17:00"}, ...]
        if (isset($hours[0]) && isset($hours[0]['day'])) {
            foreach ($hours as $entry) {
                if ((int) $entry['day'] === $day_of_week) {
                    if (isset($entry['start']) && isset($entry['end'])) {
                        if (self::time_in_range($time, $entry['start'], $entry['end'])) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        // Unknown format — treat as no restrictions
        return true;
    }

    /**
     * Check if a day is a working day (not closed).
     *
     * @param string $date  Date string (Y-m-d)
     * @param string|array<mixed> $working_hours JSON string or decoded array
     * @return bool
     */
    public static function is_working_day(string $date, $working_hours): bool {
        $hours = self::normalize($working_hours);
        if (empty($hours)) {
            return true;
        }

        $day_of_week = (int) gmdate('w', strtotime($date));
        $day_name = self::DAY_NAMES[$day_of_week];

        // Keyed format
        if (isset($hours[$day_name])) {
            return empty($hours[$day_name]['closed']);
        }

        // Array-of-objects format — day is working if there's an entry for it
        if (isset($hours[0]) && isset($hours[0]['day'])) {
            foreach ($hours as $entry) {
                if ((int) $entry['day'] === $day_of_week) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Get working hours range for a specific date.
     *
     * @param string $date  Date string (Y-m-d)
     * @param string|array<mixed> $working_hours JSON string or decoded array
     * @return array<int, array<string, string>> Array of time ranges for the day
     */
    public static function get_day_ranges(string $date, $working_hours): array {
        $hours = self::normalize($working_hours);
        if (empty($hours)) {
            return array();
        }

        $day_of_week = (int) gmdate('w', strtotime($date));
        $day_name = self::DAY_NAMES[$day_of_week];
        $ranges = array();

        // Keyed format
        if (isset($hours[$day_name])) {
            $day_hours = $hours[$day_name];
            if (empty($day_hours['closed']) && isset($day_hours['start']) && isset($day_hours['end'])) {
                $ranges[] = array('start' => $day_hours['start'], 'end' => $day_hours['end']);
            }
            return $ranges;
        }

        // Array-of-objects format — may have multiple ranges per day
        if (isset($hours[0]) && isset($hours[0]['day'])) {
            foreach ($hours as $entry) {
                if ((int) $entry['day'] === $day_of_week && isset($entry['start']) && isset($entry['end'])) {
                    $ranges[] = array('start' => $entry['start'], 'end' => $entry['end']);
                }
            }
        }

        return $ranges;
    }

    /**
     * Normalize working hours input to a decoded array.
     *
     * @param string|array<mixed>|null $working_hours
     * @return array<mixed>
     */
    private static function normalize($working_hours): array {
        if (is_string($working_hours)) {
            $decoded = json_decode($working_hours, true);
            return is_array($decoded) ? $decoded : array();
        }

        return is_array($working_hours) ? $working_hours : array();
    }

    /**
     * Check if a time falls within a range (inclusive start, exclusive end).
     *
     * @param string $time  Time to check
     * @param string $start Range start
     * @param string $end   Range end
     * @return bool
     */
    private static function time_in_range(string $time, string $start, string $end): bool {
        $t = strtotime('1970-01-01 ' . $time);
        $s = strtotime('1970-01-01 ' . $start);
        $e = strtotime('1970-01-01 ' . $end);

        return $t >= $s && $t < $e;
    }
}
