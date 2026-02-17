<?php
declare(strict_types=1);

/**
 * Calendar Repository
 *
 * Data access layer for calendar operations.
 * Follows Repository pattern for separation of concerns.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) { exit; }


class CalendarRepository extends AbstractRepository {

    /**
     * Get table name
     *
     * @return string
     */
    protected function get_table_name(): string {
        return $this->wpdb->prefix . 'ffc_self_scheduling_calendars';
    }

    /**
     * Get cache group
     *
     * @return string
     */
    protected function get_cache_group(): string {
        return 'ffc_self_scheduling_calendars';
    }

    /**
     * Find calendar by post ID
     *
     * @param int $post_id WordPress post ID
     * @return array|null
     */
    public function findByPostId(int $post_id): ?array {
        $cache_key = "post_{$post_id}";
        $cached = $this->get_cache($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $this->table, $post_id ),
            ARRAY_A
        );

        if ($result) {
            $this->set_cache($cache_key, $result);
        }

        return $result;
    }

    /**
     * Get active calendars
     *
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function getActiveCalendars(?int $limit = null, int $offset = 0): array {
        return $this->findAll(
            ['status' => 'active'],
            'created_at',
            'DESC',
            $limit,
            $offset
        );
    }

    /**
     * Get calendar with working hours decoded
     *
     * @param int $id
     * @return array|null
     */
    public function getWithWorkingHours(int $id): ?array {
        $calendar = $this->findById($id);

        if ($calendar && !empty($calendar['working_hours'])) {
            $calendar['working_hours'] = json_decode($calendar['working_hours'], true);
        }

        if ($calendar && !empty($calendar['email_config'])) {
            $calendar['email_config'] = json_decode($calendar['email_config'], true);
        }

        return $calendar;
    }

    /**
     * Update working hours
     *
     * @param int $id
     * @param array $working_hours
     * @return int|false
     */
    public function updateWorkingHours(int $id, array $working_hours) {
        return $this->update($id, [
            'working_hours' => json_encode($working_hours),
            'updated_at' => current_time('mysql'),
            'updated_by' => get_current_user_id()
        ]);
    }

    /**
     * Update email configuration
     *
     * @param int $id
     * @param array $email_config
     * @return int|false
     */
    public function updateEmailConfig(int $id, array $email_config) {
        return $this->update($id, [
            'email_config' => json_encode($email_config),
            'updated_at' => current_time('mysql'),
            'updated_by' => get_current_user_id()
        ]);
    }

    /**
     * Update calendar status
     *
     * @param int $id
     * @param string $status (active, inactive, archived)
     * @return int|false
     */
    public function updateStatus(int $id, string $status) {
        return $this->update($id, [
            'status' => $status,
            'updated_at' => current_time('mysql'),
            'updated_by' => get_current_user_id()
        ]);
    }

    /**
     * Get public active calendars
     *
     * Returns active calendars with visibility = 'public'.
     * Used for non-authenticated users.
     *
     * @since 4.7.0
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public function getPublicActiveCalendars(?int $limit = null, int $offset = 0): array {
        return $this->findAll(
            ['status' => 'active', 'visibility' => 'public'],
            'created_at',
            'DESC',
            $limit,
            $offset
        );
    }

    /**
     * Check if user has scheduling bypass capability
     *
     * @since 4.7.0
     * @param int|null $user_id User ID (null for current user)
     * @return bool
     */
    public static function userHasSchedulingBypass(?int $user_id = null): bool {
        if ($user_id === null) {
            return current_user_can('manage_options') || current_user_can('ffc_scheduling_bypass');
        }
        return user_can($user_id, 'manage_options') || user_can($user_id, 'ffc_scheduling_bypass');
    }

    /**
     * Create calendar from post
     *
     * Called when a calendar post is created.
     *
     * @param int $post_id
     * @param array $data
     * @return int|false
     */
    public function createFromPost(int $post_id, array $data = []) {
        $defaults = [
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'description' => '',
            'slot_duration' => 30,
            'slot_interval' => 0,
            'slots_per_day' => 0,
            'working_hours' => json_encode([]),
            'advance_booking_min' => 0,
            'advance_booking_max' => 30,
            'allow_cancellation' => 1,
            'cancellation_min_hours' => 24,
            'requires_approval' => 0,
            'max_appointments_per_slot' => 1,
            'visibility' => 'public',
            'scheduling_visibility' => 'public',
            'restrict_viewing_to_hours' => 0,
            'restrict_booking_to_hours' => 0,
            'email_config' => json_encode([
                'send_user_confirmation' => 0,
                'send_admin_notification' => 0,
                'send_approval_notification' => 0,
                'send_cancellation_notification' => 0,
                'send_reminder' => 0,
                'reminder_hours_before' => 24
            ]),
            'status' => 'active',
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ];

        $data = array_merge($defaults, $data);

        return $this->insert($data);
    }
}
