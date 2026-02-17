<?php
declare(strict_types=1);

/**
 * Audience Schedule Repository
 *
 * Handles database operations for audience schedules (calendars).
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

class AudienceScheduleRepository {
    use \FreeFormCertificate\Core\StaticRepositoryTrait;

    /**
     * Get table name
     *
     * @return string
     */
    public static function get_table_name(): string {
        return self::db()->prefix . 'ffc_audience_schedules';
    }

    /**
     * Get permissions table name
     *
     * @return string
     */
    public static function get_permissions_table_name(): string {
        return self::db()->prefix . 'ffc_audience_schedule_permissions';
    }

    /**
     * Get all schedules
     *
     * @param array $args Query arguments
     * @return array<object>
     */
    public static function get_all(array $args = array()): array {
        $wpdb = self::db();
        $table = self::get_table_name();

        $defaults = array(
            'status' => null,
            'visibility' => null,
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $values = array();

        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ($args['visibility']) {
            $where[] = 'visibility = %s';
            $values[] = $args['visibility'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'name ASC';
        $limit_clause = $args['limit'] > 0 ? sprintf('LIMIT %d OFFSET %d', $args['limit'], $args['offset']) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT * FROM %i {$where_clause} ORDER BY {$orderby} {$limit_clause}";

        $prepare_args = array_merge( array( $table ), $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, $prepare_args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * Get schedule by ID
     *
     * @param int $id Schedule ID
     * @return object|null
     */
    public static function get_by_id(int $id): ?object {
        $wpdb = self::db();
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id)
        );
    }

    /**
     * Get schedules accessible by user
     *
     * Returns schedules where user has permission or schedule is public.
     *
     * @param int $user_id User ID
     * @return array<object>
     */
    public static function get_by_user_access(int $user_id): array {
        $wpdb = self::db();
        $table = self::get_table_name();
        $perms_table = self::get_permissions_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT s.* FROM %i s
                LEFT JOIN %i p ON s.id = p.schedule_id AND p.user_id = %d
                WHERE s.status = 'active'
                AND (s.visibility = 'public' OR p.user_id IS NOT NULL)
                ORDER BY s.name ASC",
                $table,
                $perms_table,
                $user_id
            )
        );
    }

    /**
     * Create a schedule
     *
     * @param array $data Schedule data
     * @return int|false Schedule ID or false on failure
     */
    public static function create(array $data) {
        $wpdb = self::db();
        $table = self::get_table_name();

        $defaults = array(
            'name' => '',
            'description' => null,
            'environment_label' => null,
            'visibility' => 'private',
            'future_days_limit' => null,
            'notify_on_booking' => 1,
            'notify_on_cancellation' => 1,
            'email_template_booking' => null,
            'email_template_cancellation' => null,
            'include_ics' => 0,
            'status' => 'active',
            'created_by' => get_current_user_id(),
        );
        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            array(
                'name' => $data['name'],
                'description' => $data['description'],
                'environment_label' => $data['environment_label'],
                'visibility' => $data['visibility'],
                'future_days_limit' => $data['future_days_limit'],
                'notify_on_booking' => $data['notify_on_booking'],
                'notify_on_cancellation' => $data['notify_on_cancellation'],
                'email_template_booking' => $data['email_template_booking'],
                'email_template_cancellation' => $data['email_template_cancellation'],
                'include_ics' => $data['include_ics'],
                'status' => $data['status'],
                'created_by' => $data['created_by'],
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a schedule
     *
     * @param int $id Schedule ID
     * @param array $data Update data
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        $wpdb = self::db();
        $table = self::get_table_name();

        // Remove fields that shouldn't be updated
        unset($data['id'], $data['created_by'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        // Build update data and format arrays
        $update_data = array();
        $format = array();

        $field_formats = array(
            'name' => '%s',
            'description' => '%s',
            'environment_label' => '%s',
            'visibility' => '%s',
            'future_days_limit' => '%d',
            'notify_on_booking' => '%d',
            'notify_on_cancellation' => '%d',
            'email_template_booking' => '%s',
            'email_template_cancellation' => '%s',
            'include_ics' => '%d',
            'show_event_list' => '%d',
            'event_list_position' => '%s',
            'audience_badge_format' => '%s',
            'booking_label_singular' => '%s',
            'booking_label_plural' => '%s',
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
     * Delete a schedule
     *
     * @param int $id Schedule ID
     * @return bool
     */
    public static function delete(int $id): bool {
        $wpdb = self::db();
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Get user permissions for a schedule
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @return object|null
     */
    public static function get_user_permissions(int $schedule_id, int $user_id): ?object {
        $wpdb = self::db();
        $table = self::get_permissions_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE schedule_id = %d AND user_id = %d",
                $table,
                $schedule_id,
                $user_id
            )
        );
    }

    /**
     * Get all permissions for a schedule
     *
     * @param int $schedule_id Schedule ID
     * @return array<object>
     */
    public static function get_all_permissions(int $schedule_id): array {
        $wpdb = self::db();
        $table = self::get_permissions_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM %i WHERE schedule_id = %d", $table, $schedule_id)
        );
    }

    /**
     * Set user permissions for a schedule
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @param array $permissions Permission flags
     * @return bool
     */
    public static function set_user_permissions(int $schedule_id, int $user_id, array $permissions): bool {
        $wpdb = self::db();
        $table = self::get_permissions_table_name();

        $defaults = array(
            'can_book' => 1,
            'can_cancel_others' => 0,
            'can_override_conflicts' => 0,
        );
        $permissions = wp_parse_args($permissions, $defaults);

        // Check if permission exists
        $existing = self::get_user_permissions($schedule_id, $user_id);

        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table,
                array(
                    'can_book' => $permissions['can_book'],
                    'can_cancel_others' => $permissions['can_cancel_others'],
                    'can_override_conflicts' => $permissions['can_override_conflicts'],
                ),
                array('id' => $existing->id),
                array('%d', '%d', '%d'),
                array('%d')
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $table,
                array(
                    'schedule_id' => $schedule_id,
                    'user_id' => $user_id,
                    'can_book' => $permissions['can_book'],
                    'can_cancel_others' => $permissions['can_cancel_others'],
                    'can_override_conflicts' => $permissions['can_override_conflicts'],
                ),
                array('%d', '%d', '%d', '%d', '%d')
            );
        }

        return $result !== false;
    }

    /**
     * Remove user permissions from a schedule
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function remove_user_permissions(int $schedule_id, int $user_id): bool {
        $wpdb = self::db();
        $table = self::get_permissions_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            array('schedule_id' => $schedule_id, 'user_id' => $user_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Check if user can book on a schedule
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function user_can_book(int $schedule_id, int $user_id): bool {
        // Admins can always book
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $schedule = self::get_by_id($schedule_id);
        if (!$schedule || $schedule->status !== 'active') {
            return false;
        }

        // Check user permissions
        $permissions = self::get_user_permissions($schedule_id, $user_id);

        return $permissions && (bool) $permissions->can_book;
    }

    /**
     * Check if user can cancel others' bookings
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function user_can_cancel_others(int $schedule_id, int $user_id): bool {
        // Admins can always cancel
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $permissions = self::get_user_permissions($schedule_id, $user_id);

        return $permissions && (bool) $permissions->can_cancel_others;
    }

    /**
     * Check if user can override conflicts
     *
     * @param int $schedule_id Schedule ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function user_can_override_conflicts(int $schedule_id, int $user_id): bool {
        // Admins can always override
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $permissions = self::get_user_permissions($schedule_id, $user_id);

        return $permissions && (bool) $permissions->can_override_conflicts;
    }

    /**
     * Get the environment label for a schedule
     *
     * Returns the custom label if set, otherwise the default translatable "Environments".
     *
     * @since 4.7.0
     * @param object|int|null $schedule Schedule object, ID, or null for default
     * @param bool $singular Whether to return singular form
     * @return string
     */
    public static function get_environment_label($schedule = null, bool $singular = false): string {
        if (is_int($schedule)) {
            $schedule = self::get_by_id($schedule);
        }

        $custom_label = $schedule->environment_label ?? null;

        if (!empty($custom_label)) {
            return $custom_label;
        }

        return $singular
            ? __('Environment', 'ffcertificate')
            : __('Environments', 'ffcertificate');
    }

    /**
     * Count schedules
     *
     * @param array $args Query arguments (status, visibility)
     * @return int
     */
    public static function count(array $args = array()): int {
        $wpdb = self::db();
        $table = self::get_table_name();

        $where = array();
        $values = array();

        if (isset($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (isset($args['visibility'])) {
            $where[] = 'visibility = %s';
            $values[] = $args['visibility'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT COUNT(*) FROM %i {$where_clause}";

        $prepare_args = array_merge( array( $table ), $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, $prepare_args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }
}
