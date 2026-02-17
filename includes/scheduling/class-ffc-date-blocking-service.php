<?php
declare(strict_types=1);

/**
 * Date Blocking Service
 *
 * Shared service for checking if dates are blocked/holidays across both
 * self-scheduling and audience scheduling systems.
 *
 * Provides a unified API while delegating to system-specific repositories.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Scheduling
 */

namespace FreeFormCertificate\Scheduling;

if (!defined('ABSPATH')) {
    exit;
}

class DateBlockingService {

    /**
     * Check if a date is a global holiday (applies to all calendars/schedules).
     *
     * Global holidays are stored in wp_options as ffc_global_holidays.
     *
     * @param string $date Date (Y-m-d)
     * @return bool
     */
    public static function is_global_holiday(string $date): bool {
        $holidays = get_option('ffc_global_holidays', array());

        if (!is_array($holidays) || empty($holidays)) {
            return false;
        }

        foreach ($holidays as $holiday) {
            if (isset($holiday['date']) && $holiday['date'] === $date) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all global holidays, optionally filtered by date range.
     *
     * @param string|null $start_date Optional start date filter (Y-m-d)
     * @param string|null $end_date Optional end date filter (Y-m-d)
     * @return array<int, array<string, mixed>>
     */
    public static function get_global_holidays(?string $start_date = null, ?string $end_date = null): array {
        $holidays = get_option('ffc_global_holidays', array());

        if (!is_array($holidays)) {
            return array();
        }

        if ($start_date || $end_date) {
            $holidays = array_filter($holidays, function ($h) use ($start_date, $end_date) {
                if (!isset($h['date'])) {
                    return false;
                }
                if ($start_date && $h['date'] < $start_date) {
                    return false;
                }
                if ($end_date && $h['date'] > $end_date) {
                    return false;
                }
                return true;
            });
        }

        return array_values($holidays);
    }

    /**
     * Check if a date is blocked for self-scheduling.
     *
     * Delegates to BlockedDateRepository if available.
     *
     * @param int $calendar_id Calendar ID
     * @param string $date Date (Y-m-d)
     * @param string|null $time Optional time (H:i:s)
     * @return bool
     */
    public static function is_self_scheduling_blocked(int $calendar_id, string $date, ?string $time = null): bool {
        if (!class_exists('\FreeFormCertificate\Repositories\BlockedDateRepository')) {
            return false;
        }

        $repo = new \FreeFormCertificate\Repositories\BlockedDateRepository();
        return $repo->isDateBlocked($calendar_id, $date, $time);
    }

    /**
     * Check if a date is a holiday for an audience schedule.
     *
     * Delegates to AudienceEnvironmentRepository if available.
     *
     * @param int $environment_id Environment ID
     * @param string $date Date (Y-m-d)
     * @return bool
     */
    public static function is_audience_holiday(int $environment_id, string $date): bool {
        if (!class_exists('\FreeFormCertificate\Audience\AudienceEnvironmentRepository')) {
            return false;
        }

        return \FreeFormCertificate\Audience\AudienceEnvironmentRepository::is_holiday($environment_id, $date);
    }

    /**
     * Check if a date is available for scheduling, combining working hours and blocking.
     *
     * Checks in order: global holidays → working hours → system-specific blocks.
     *
     * @param string $date Date (Y-m-d)
     * @param string|null $time Optional time (H:i or H:i:s)
     * @param string|array<string, mixed> $working_hours Working hours config
     * @param int|null $calendar_id Self-scheduling calendar ID (null to skip check)
     * @param int|null $environment_id Audience environment ID (null to skip check)
     * @return bool
     */
    public static function is_date_available(
        string $date,
        ?string $time,
        $working_hours,
        ?int $calendar_id = null,
        ?int $environment_id = null
    ): bool {
        // Check global holidays first (applies to all systems)
        if (self::is_global_holiday($date)) {
            return false;
        }

        // Check working hours
        if ($time !== null) {
            if (!WorkingHoursService::is_within_working_hours($date, $time, $working_hours)) {
                return false;
            }
        } else {
            if (!WorkingHoursService::is_working_day($date, $working_hours)) {
                return false;
            }
        }

        // Check self-scheduling blocked dates
        if ($calendar_id !== null && self::is_self_scheduling_blocked($calendar_id, $date, $time)) {
            return false;
        }

        // Check audience holidays
        if ($environment_id !== null && self::is_audience_holiday($environment_id, $date)) {
            return false;
        }

        return true;
    }
}
