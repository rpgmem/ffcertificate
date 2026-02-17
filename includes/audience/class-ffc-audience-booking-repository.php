<?php
declare(strict_types=1);

/**
 * Audience Booking Repository
 *
 * Handles database operations for audience bookings.
 * Manages the booking records and N:N relationships with audiences and users.
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

class AudienceBookingRepository {

    /**
     * Get bookings table name
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_bookings';
    }

    /**
     * Get booking audiences table name
     *
     * @return string
     */
    public static function get_booking_audiences_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_booking_audiences';
    }

    /**
     * Get booking users table name
     *
     * @return string
     */
    public static function get_booking_users_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_booking_users';
    }

    /**
     * Get all bookings
     *
     * @param array $args Query arguments
     * @return array<object>
     */
    public static function get_all(array $args = array()): array {
        global $wpdb;
        $table = self::get_table_name();
        $env_table = AudienceEnvironmentRepository::get_table_name();

        $defaults = array(
            'environment_id' => null,
            'schedule_id' => null,
            'booking_date' => null,
            'start_date' => null,
            'end_date' => null,
            'status' => null,
            'booking_type' => null,
            'created_by' => null,
            'orderby' => 'booking_date',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $values = array();

        if ($args['environment_id']) {
            $where[] = 'b.environment_id = %d';
            $values[] = $args['environment_id'];
        }

        if ($args['schedule_id']) {
            $where[] = 'e.schedule_id = %d';
            $values[] = $args['schedule_id'];
        }

        if ($args['booking_date']) {
            $where[] = 'b.booking_date = %s';
            $values[] = $args['booking_date'];
        }

        if ($args['start_date']) {
            $where[] = 'b.booking_date >= %s';
            $values[] = $args['start_date'];
        }

        if ($args['end_date']) {
            $where[] = 'b.booking_date <= %s';
            $values[] = $args['end_date'];
        }

        if ($args['status']) {
            $where[] = 'b.status = %s';
            $values[] = $args['status'];
        }

        if ($args['booking_type']) {
            $where[] = 'b.booking_type = %s';
            $values[] = $args['booking_type'];
        }

        if ($args['created_by']) {
            $where[] = 'b.created_by = %d';
            $values[] = $args['created_by'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderby = sanitize_sql_orderby('b.' . $args['orderby'] . ' ' . $args['order']) ?: 'b.booking_date ASC';
        $limit_clause = $args['limit'] > 0 ? sprintf('LIMIT %d OFFSET %d', $args['limit'], $args['offset']) : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                {$where_clause}
                ORDER BY {$orderby}, b.start_time ASC
                {$limit_clause}";

        $prepare_args = array_merge( array( $table, $env_table ), $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, $prepare_args);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * Get booking by ID
     *
     * @param int $id Booking ID
     * @return object|null
     */
    public static function get_by_id(int $id): ?object {
        global $wpdb;
        $table = self::get_table_name();
        $env_table = AudienceEnvironmentRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                WHERE b.id = %d",
                $table,
                $env_table,
                $id
            )
        );

        if ($booking) {
            // Load related audiences and users
            $booking->audiences = self::get_booking_audiences($id);
            $booking->users = self::get_booking_users($id);
        }

        return $booking;
    }

    /**
     * Get bookings for a specific date and environment
     *
     * @param int $environment_id Environment ID
     * @param string $date Date (Y-m-d)
     * @param string|null $status Optional status filter
     * @return array<object>
     */
    public static function get_by_date(int $environment_id, string $date, ?string $status = null): array {
        return self::get_all(array(
            'environment_id' => $environment_id,
            'booking_date' => $date,
            'status' => $status,
        ));
    }

    /**
     * Get bookings for a date range
     *
     * @param int $environment_id Environment ID
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param string|null $status Optional status filter
     * @return array<object>
     */
    public static function get_by_date_range(int $environment_id, string $start_date, string $end_date, ?string $status = null): array {
        return self::get_all(array(
            'environment_id' => $environment_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status' => $status,
        ));
    }

    /**
     * Get bookings created by a user
     *
     * @param int $user_id User ID
     * @param array $args Additional query arguments
     * @return array<object>
     */
    public static function get_by_creator(int $user_id, array $args = array()): array {
        $args['created_by'] = $user_id;
        return self::get_all($args);
    }

    /**
     * Get bookings for a user (as participant, not creator)
     *
     * @param int $user_id User ID
     * @param array $args Additional query arguments
     * @return array<object>
     */
    public static function get_by_participant(int $user_id, array $args = array()): array {
        global $wpdb;
        $table = self::get_table_name();
        $users_table = self::get_booking_users_table_name();
        $audiences_table = self::get_booking_audiences_table_name();
        $members_table = AudienceRepository::get_members_table_name();
        $env_table = AudienceEnvironmentRepository::get_table_name();

        $defaults = array(
            'start_date' => null,
            'end_date' => null,
            'status' => null,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $values = array($user_id, $user_id);

        if ($args['start_date']) {
            $where[] = 'b.booking_date >= %s';
            $values[] = $args['start_date'];
        }

        if ($args['end_date']) {
            $where[] = 'b.booking_date <= %s';
            $values[] = $args['end_date'];
        }

        if ($args['status']) {
            $where[] = 'b.status = %s';
            $values[] = $args['status'];
        }

        $where_clause = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT b.*, e.name as environment_name, e.schedule_id
                FROM %i b
                INNER JOIN %i e ON b.environment_id = e.id
                LEFT JOIN %i bu ON b.id = bu.booking_id
                LEFT JOIN %i ba ON b.id = ba.booking_id
                LEFT JOIN %i am ON ba.audience_id = am.audience_id
                WHERE (bu.user_id = %d OR am.user_id = %d)
                {$where_clause}
                ORDER BY b.booking_date ASC, b.start_time ASC",
                array_merge( array( $table, $env_table, $users_table, $audiences_table, $members_table ), $values )
            )
        );
    }

    /**
     * Create a booking
     *
     * @param array $data Booking data
     * @return int|false Booking ID or false on failure
     */
    public static function create(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'environment_id' => 0,
            'booking_date' => '',
            'start_time' => '',
            'end_time' => '',
            'is_all_day' => 0,
            'booking_type' => 'audience',
            'description' => '',
            'status' => 'active',
            'created_by' => get_current_user_id(),
        );
        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        if (!$data['environment_id'] || !$data['booking_date'] || !$data['start_time'] || !$data['end_time'] || !$data['description']) {
            return false;
        }

        $result = $wpdb->insert(
            $table,
            array(
                'environment_id' => $data['environment_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'is_all_day' => $data['is_all_day'] ? 1 : 0,
                'booking_type' => $data['booking_type'],
                'description' => $data['description'],
                'status' => $data['status'],
                'created_by' => $data['created_by'],
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d')
        );

        if (!$result) {
            return false;
        }

        $booking_id = $wpdb->insert_id;

        // Add audience associations if provided
        if (isset($data['audience_ids']) && is_array($data['audience_ids'])) {
            foreach ($data['audience_ids'] as $audience_id) {
                self::add_booking_audience($booking_id, (int) $audience_id);
            }
        }

        // Add user associations if provided
        if (isset($data['user_ids']) && is_array($data['user_ids'])) {
            foreach ($data['user_ids'] as $user_id) {
                self::add_booking_user($booking_id, (int) $user_id);
            }
        }

        return $booking_id;
    }

    /**
     * Update a booking
     *
     * @param int $id Booking ID
     * @param array $data Update data
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = self::get_table_name();

        // Remove fields that shouldn't be updated
        unset($data['id'], $data['created_by'], $data['created_at']);

        // Handle audience_ids separately
        $audience_ids = null;
        if (isset($data['audience_ids'])) {
            $audience_ids = $data['audience_ids'];
            unset($data['audience_ids']);
        }

        // Handle user_ids separately
        $user_ids = null;
        if (isset($data['user_ids'])) {
            $user_ids = $data['user_ids'];
            unset($data['user_ids']);
        }

        // Update main booking record
        if (!empty($data)) {
            $update_data = array();
            $format = array();

            $field_formats = array(
                'environment_id' => '%d',
                'booking_date' => '%s',
                'start_time' => '%s',
                'end_time' => '%s',
                'booking_type' => '%s',
                'description' => '%s',
                'status' => '%s',
                'cancelled_by' => '%d',
                'cancelled_at' => '%s',
                'cancellation_reason' => '%s',
            );

            foreach ($data as $key => $value) {
                if (isset($field_formats[$key])) {
                    $update_data[$key] = $value;
                    $format[] = $field_formats[$key];
                }
            }

            if (!empty($update_data)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table,
                    $update_data,
                    array('id' => $id),
                    $format,
                    array('%d')
                );
            }
        }

        // Update audience associations
        if ($audience_ids !== null) {
            self::set_booking_audiences($id, $audience_ids);
        }

        // Update user associations
        if ($user_ids !== null) {
            self::set_booking_users($id, $user_ids);
        }

        return true;
    }

    /**
     * Cancel a booking
     *
     * @param int $id Booking ID
     * @param string $reason Cancellation reason (required)
     * @return bool
     */
    public static function cancel(int $id, string $reason): bool {
        if (empty($reason)) {
            return false;
        }

        return self::update($id, array(
            'status' => 'cancelled',
            'cancelled_by' => get_current_user_id(),
            'cancelled_at' => current_time('mysql'),
            'cancellation_reason' => $reason,
        ));
    }

    /**
     * Delete a booking
     *
     * @param int $id Booking ID
     * @return bool
     */
    public static function delete(int $id): bool {
        global $wpdb;
        $table = self::get_table_name();
        $audiences_table = self::get_booking_audiences_table_name();
        $users_table = self::get_booking_users_table_name();

        // Delete associations first
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($audiences_table, array('booking_id' => $id), array('%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($users_table, array('booking_id' => $id), array('%d'));

        // Delete the booking
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Add an audience to a booking
     *
     * @param int $booking_id Booking ID
     * @param int $audience_id Audience ID
     * @return bool
     */
    public static function add_booking_audience(int $booking_id, int $audience_id): bool {
        global $wpdb;
        $table = self::get_booking_audiences_table_name();

        $result = $wpdb->insert(
            $table,
            array('booking_id' => $booking_id, 'audience_id' => $audience_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Remove an audience from a booking
     *
     * @param int $booking_id Booking ID
     * @param int $audience_id Audience ID
     * @return bool
     */
    public static function remove_booking_audience(int $booking_id, int $audience_id): bool {
        global $wpdb;
        $table = self::get_booking_audiences_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            array('booking_id' => $booking_id, 'audience_id' => $audience_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Get audiences for a booking
     *
     * @param int $booking_id Booking ID
     * @return array<object>
     */
    public static function get_booking_audiences(int $booking_id): array {
        global $wpdb;
        $table = self::get_booking_audiences_table_name();
        $audiences_table = AudienceRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.* FROM %i a
                INNER JOIN %i ba ON a.id = ba.audience_id
                WHERE ba.booking_id = %d
                ORDER BY a.name ASC",
                $audiences_table,
                $table,
                $booking_id
            )
        );
    }

    /**
     * Set audiences for a booking (replace all)
     *
     * @param int $booking_id Booking ID
     * @param array<int> $audience_ids Audience IDs
     * @return bool
     */
    public static function set_booking_audiences(int $booking_id, array $audience_ids): bool {
        global $wpdb;
        $table = self::get_booking_audiences_table_name();

        // Remove all existing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($table, array('booking_id' => $booking_id), array('%d'));

        // Add new ones
        foreach ($audience_ids as $audience_id) {
            self::add_booking_audience($booking_id, (int) $audience_id);
        }

        return true;
    }

    /**
     * Add a user to a booking
     *
     * @param int $booking_id Booking ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function add_booking_user(int $booking_id, int $user_id): bool {
        global $wpdb;
        $table = self::get_booking_users_table_name();

        $result = $wpdb->insert(
            $table,
            array('booking_id' => $booking_id, 'user_id' => $user_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Remove a user from a booking
     *
     * @param int $booking_id Booking ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function remove_booking_user(int $booking_id, int $user_id): bool {
        global $wpdb;
        $table = self::get_booking_users_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            array('booking_id' => $booking_id, 'user_id' => $user_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Get users for a booking
     *
     * @param int $booking_id Booking ID
     * @return array<int> User IDs
     */
    public static function get_booking_users(int $booking_id): array {
        global $wpdb;
        $table = self::get_booking_users_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM %i WHERE booking_id = %d",
                $table,
                $booking_id
            )
        );

        return array_map('intval', $results);
    }

    /**
     * Set users for a booking (replace all)
     *
     * @param int $booking_id Booking ID
     * @param array<int> $user_ids User IDs
     * @return bool
     */
    public static function set_booking_users(int $booking_id, array $user_ids): bool {
        global $wpdb;
        $table = self::get_booking_users_table_name();

        // Remove all existing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($table, array('booking_id' => $booking_id), array('%d'));

        // Add new ones
        foreach ($user_ids as $user_id) {
            self::add_booking_user($booking_id, (int) $user_id);
        }

        return true;
    }

    /**
     * Get all affected users for a booking (from audiences + individual users)
     *
     * @param int $booking_id Booking ID
     * @return array<int> Unique user IDs
     */
    public static function get_all_affected_users(int $booking_id): array {
        $users = array();

        // Get directly added users
        $direct_users = self::get_booking_users($booking_id);
        $users = array_merge($users, $direct_users);

        // Get users from audiences
        $audiences = self::get_booking_audiences($booking_id);
        foreach ($audiences as $audience) {
            $audience_users = AudienceRepository::get_members((int) $audience->id, true);
            $users = array_merge($users, $audience_users);
        }

        // Return unique user IDs
        return array_unique($users);
    }

    /**
     * Check for time conflicts
     *
     * @param int $environment_id Environment ID
     * @param string $date Date (Y-m-d)
     * @param string $start_time Start time (H:i)
     * @param string $end_time End time (H:i)
     * @param int|null $exclude_booking_id Booking ID to exclude (for updates)
     * @return array<object> Conflicting bookings
     */
    public static function get_conflicts(int $environment_id, string $date, string $start_time, string $end_time, ?int $exclude_booking_id = null): array {
        global $wpdb;
        $table = self::get_table_name();

        $exclude_clause = $exclude_booking_id ? $wpdb->prepare("AND id != %d", $exclude_booking_id) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
                WHERE environment_id = %d
                AND booking_date = %s
                AND status = 'active'
                AND (
                    (start_time < %s AND end_time > %s) OR
                    (start_time >= %s AND start_time < %s) OR
                    (end_time > %s AND end_time <= %s)
                )
                {$exclude_clause}
                ORDER BY start_time ASC",
                $table,
                $environment_id,
                $date,
                $end_time, $start_time,
                $start_time, $end_time,
                $start_time, $end_time
            )
        );
    }

    /**
     * Check for user conflicts across all environments
     *
     * Returns bookings that would affect the same users at the same time.
     *
     * @param string $date Date (Y-m-d)
     * @param string $start_time Start time (H:i)
     * @param string $end_time End time (H:i)
     * @param array<int> $audience_ids Audience IDs to check
     * @param array<int> $user_ids Individual user IDs to check
     * @param int|null $exclude_booking_id Booking ID to exclude
     * @return array{bookings: array<object>, affected_users: array<int>}
     */
    public static function get_user_conflicts(
        string $date,
        string $start_time,
        string $end_time,
        array $audience_ids,
        array $user_ids,
        ?int $exclude_booking_id = null
    ): array {
        global $wpdb;
        $table = self::get_table_name();
        $ba_table = self::get_booking_audiences_table_name();
        $bu_table = self::get_booking_users_table_name();
        $members_table = AudienceRepository::get_members_table_name();

        // Get all users that would be affected by this booking
        $all_user_ids = $user_ids;
        foreach ($audience_ids as $audience_id) {
            $audience_users = AudienceRepository::get_members((int) $audience_id, true);
            $all_user_ids = array_merge($all_user_ids, $audience_users);
        }
        $all_user_ids = array_unique($all_user_ids);

        if (empty($all_user_ids)) {
            return array('bookings' => array(), 'affected_users' => array());
        }

        $placeholders = implode(',', array_fill(0, count($all_user_ids), '%d'));
        $exclude_clause = $exclude_booking_id ? $wpdb->prepare("AND b.id != %d", $exclude_booking_id) : '';

        $values = array($date, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time);
        $values = array_merge($values, $all_user_ids, $all_user_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $conflicting_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT b.* FROM %i b
                LEFT JOIN %i ba ON b.id = ba.booking_id
                LEFT JOIN %i am ON ba.audience_id = am.audience_id
                LEFT JOIN %i bu ON b.id = bu.booking_id
                WHERE b.booking_date = %s
                AND b.status = 'active'
                AND (
                    (b.start_time < %s AND b.end_time > %s) OR
                    (b.start_time >= %s AND b.start_time < %s) OR
                    (b.end_time > %s AND b.end_time <= %s)
                )
                AND (am.user_id IN ({$placeholders}) OR bu.user_id IN ({$placeholders}))
                {$exclude_clause}
                ORDER BY b.start_time ASC",
                array_merge( array( $table, $ba_table, $members_table, $bu_table ), $values )
            )
        );

        // Find which specific users have conflicts
        $affected_users = array();
        foreach ($conflicting_bookings as $booking) {
            $booking_users = self::get_all_affected_users((int) $booking->id);
            $conflicting = array_intersect($all_user_ids, $booking_users);
            $affected_users = array_merge($affected_users, $conflicting);
        }
        $affected_users = array_unique($affected_users);

        return array(
            'bookings' => $conflicting_bookings,
            'affected_users' => $affected_users,
        );
    }

    /**
     * Find bookings on the same date that include any of the given audience groups
     *
     * This is a "soft conflict" check — same audience group booked multiple times
     * on the same day (regardless of time overlap).
     *
     * @param string $date Date (Y-m-d)
     * @param array<int> $audience_ids Audience IDs to check
     * @param int|null $exclude_booking_id Booking ID to exclude (for updates)
     * @return array<object> Bookings with matched audience info
     */
    public static function get_audience_same_day_bookings(
        string $date,
        array $audience_ids,
        ?int $exclude_booking_id = null
    ): array {
        global $wpdb;
        $table = self::get_table_name();
        $ba_table = self::get_booking_audiences_table_name();
        $audiences_table = AudienceRepository::get_table_name();

        if (empty($audience_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($audience_ids), '%d'));
        $exclude_clause = $exclude_booking_id ? $wpdb->prepare("AND b.id != %d", $exclude_booking_id) : '';

        $values = array($date);
        $values = array_merge($values, $audience_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.start_time, b.end_time, b.description, a.name AS audience_name, ba.audience_id
                FROM %i b
                INNER JOIN %i ba ON b.id = ba.booking_id
                INNER JOIN %i a ON ba.audience_id = a.id
                WHERE b.booking_date = %s
                AND b.status = 'active'
                AND ba.audience_id IN ({$placeholders})
                {$exclude_clause}
                ORDER BY a.name ASC, b.start_time ASC",
                array_merge( array( $table, $ba_table, $audiences_table ), $values )
            )
        );
    }

    /**
     * Count bookings
     *
     * @param array $args Query arguments
     * @return int
     */
    public static function count(array $args = array()): int {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $values = array();

        if (isset($args['environment_id'])) {
            $where[] = 'environment_id = %d';
            $values[] = $args['environment_id'];
        }

        if (isset($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (isset($args['booking_date'])) {
            $where[] = 'booking_date = %s';
            $values[] = $args['booking_date'];
        }

        if (isset($args['created_by'])) {
            $where[] = 'created_by = %d';
            $values[] = $args['created_by'];
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
