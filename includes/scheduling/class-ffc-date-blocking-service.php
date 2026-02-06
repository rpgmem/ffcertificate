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
     * @param string $date Date (Y-m-d)
     * @param string|null $time Optional time (H:i or H:i:s)
     * @param string|array $working_hours Working hours config
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
        // Check working hours first
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
