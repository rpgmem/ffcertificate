<?php
declare(strict_types=1);

/**
 * Audience Environment Repository
 *
 * Handles database operations for audience environments (rooms/locations).
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

class AudienceEnvironmentRepository {

    /**
     * Get table name
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_environments';
    }

    /**
     * Get holidays table name
     *
     * @return string
     */
    public static function get_holidays_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_holidays';
    }

    /**
     * Get all environments
     *
     * @param array $args Query arguments
     * @return array<object>
     */
    public static function get_all(array $args = array()): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'schedule_id' => null,
            'status' => null,
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $values = array();

        if ($args['schedule_id']) {
            $where[] = 'schedule_id = %d';
            $values[] = $args['schedule_id'];
        }

        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'name ASC';
        $limit_clause = $args['limit'] > 0 ? sprintf('LIMIT %d OFFSET %d', $args['limit'], $args['offset']) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM {$table} {$where_clause} ORDER BY {$orderby} {$limit_clause}";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $values);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * Get environment by ID
     *
     * @param int $id Environment ID
     * @return object|null
     */
    public static function get_by_id(int $id): ?object {
        global $wpdb;
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Get environments by schedule ID
     *
     * @param int $schedule_id Schedule ID
     * @param string|null $status Optional status filter
     * @return array<object>
     */
    public static function get_by_schedule(int $schedule_id, ?string $status = null): array {
        return self::get_all(array(
            'schedule_id' => $schedule_id,
            'status' => $status,
        ));
    }

    /**
     * Create an environment
     *
     * @param array $data Environment data
     * @return int|false Environment ID or false on failure
     */
    public static function create(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'schedule_id' => 0,
            'name' => '',
            'description' => null,
            'working_hours' => null,
            'status' => 'active',
        );
        $data = wp_parse_args($data, $defaults);

        // Encode working hours if it's an array
        $working_hours = $data['working_hours'];
        if (is_array($working_hours)) {
            $working_hours = wp_json_encode($working_hours);
        }

        $result = $wpdb->insert(
            $table,
            array(
                'schedule_id' => $data['schedule_id'],
                'name' => $data['name'],
                'description' => $data['description'],
                'working_hours' => $working_hours,
                'status' => $data['status'],
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update an environment
     *
     * @param int $id Environment ID
     * @param array $data Update data
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = self::get_table_name();

        // Remove fields that shouldn't be updated
        unset($data['id'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        // Encode working hours if it's an array
        if (isset($data['working_hours']) && is_array($data['working_hours'])) {
            $data['working_hours'] = wp_json_encode($data['working_hours']);
        }

        // Build update data and format arrays
        $update_data = array();
        $format = array();

        $field_formats = array(
            'schedule_id' => '%d',
            'name' => '%s',
            'description' => '%s',
            'working_hours' => '%s',
            'status' => '%s',
        );

        foreach ($data as $key => $value) {
            if (isset($field_formats[$key])) {
                $update_data[$key] = $value;
                $format[] = $field_formats[$key];
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $id),
            $format,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Delete an environment
     *
     * @param int $id Environment ID
     * @return bool
     */
    public static function delete(int $id): bool {
        global $wpdb;
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Get working hours for an environment
     *
     * @param int $id Environment ID
     * @return array|null Decoded working hours or null
     */
    public static function get_working_hours(int $id): ?array {
        $env = self::get_by_id($id);
        if (!$env || !$env->working_hours) {
            return null;
        }

        $hours = json_decode($env->working_hours, true);
        return is_array($hours) ? $hours : null;
    }

    /**
     * Check if environment is open on a specific day/time
     *
     * @param int $id Environment ID
     * @param string $date Date (Y-m-d)
     * @param string|null $time Optional time (H:i)
     * @return bool
     */
    public static function is_open(int $id, string $date, ?string $time = null): bool {
        // Check global holidays first
        if (\FreeFormCertificate\Scheduling\DateBlockingService::is_global_holiday($date)) {
            return false;
        }

        // Check schedule-specific holiday
        if (self::is_holiday($id, $date)) {
            return false;
        }

        $env = self::get_by_id($id);
        if (!$env || $env->status !== 'active') {
            return false;
        }

        $working_hours = self::get_working_hours($id);
        if (!$working_hours) {
            return true; // No working hours defined = always open
        }

        // Delegate to shared service
        if ($time) {
            return \FreeFormCertificate\Scheduling\WorkingHoursService::is_within_working_hours($date, $time, $working_hours);
        }

        return \FreeFormCertificate\Scheduling\WorkingHoursService::is_working_day($date, $working_hours);
    }

    /**
     * Add a holiday to an environment's schedule
     *
     * @param int $schedule_id Schedule ID
     * @param string $date Date (Y-m-d)
     * @param string|null $description Optional description
     * @return int|false Holiday ID or false on failure
     */
    public static function add_holiday(int $schedule_id, string $date, ?string $description = null) {
        global $wpdb;
        $table = self::get_holidays_table_name();

        $result = $wpdb->insert(
            $table,
            array(
                'schedule_id' => $schedule_id,
                'holiday_date' => $date,
                'description' => $description,
                'created_by' => get_current_user_id(),
            ),
            array('%d', '%s', '%s', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Remove a holiday
     *
     * @param int $holiday_id Holiday ID
     * @return bool
     */
    public static function remove_holiday(int $holiday_id): bool {
        global $wpdb;
        $table = self::get_holidays_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, array('id' => $holiday_id), array('%d'));

        return $result !== false;
    }

    /**
     * Get holidays for a schedule
     *
     * @param int $schedule_id Schedule ID
     * @param string|null $start_date Optional start date filter
     * @param string|null $end_date Optional end date filter
     * @return array<object>
     */
    public static function get_holidays(int $schedule_id, ?string $start_date = null, ?string $end_date = null): array {
        global $wpdb;
        $table = self::get_holidays_table_name();

        $where = array('schedule_id = %d');
        $values = array($schedule_id);

        if ($start_date) {
            $where[] = 'holiday_date >= %s';
            $values[] = $start_date;
        }

        if ($end_date) {
            $where[] = 'holiday_date <= %s';
            $values[] = $end_date;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause built from trusted conditions above.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$where_clause} ORDER BY holiday_date ASC",
                $values
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Check if a date is a holiday for the environment's schedule
     *
     * @param int $environment_id Environment ID
     * @param string $date Date (Y-m-d)
     * @return bool
     */
    public static function is_holiday(int $environment_id, string $date): bool {
        $env = self::get_by_id($environment_id);
        if (!$env) {
            return false;
        }

        global $wpdb;
        $table = self::get_holidays_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE schedule_id = %d AND holiday_date = %s",
                $env->schedule_id,
                $date
            )
        );

        return (int) $count > 0;
    }

    /**
     * Count environments
     *
     * @param array $args Query arguments (schedule_id, status)
     * @return int
     */
    public static function count(array $args = array()): int {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $values = array();

        if (isset($args['schedule_id'])) {
            $where[] = 'schedule_id = %d';
            $values[] = $args['schedule_id'];
        }

        if (isset($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM {$table} {$where_clause}";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $values);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }
}
