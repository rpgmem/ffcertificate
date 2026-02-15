<?php
declare(strict_types=1);

/**
 * Reregistration Repository
 *
 * Handles database operations for reregistration campaigns.
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ReregistrationRepository {

    /**
     * Valid statuses for a reregistration campaign.
     */
    public const STATUSES = array('draft', 'active', 'expired', 'closed');

    /**
     * Get table name.
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_reregistrations';
    }

    /**
     * Get a reregistration by ID.
     *
     * @param int $id Reregistration ID.
     * @return object|null
     */
    public static function get_by_id(int $id): ?object {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Get all reregistrations with optional filters.
     *
     * @param array $filters {
     *     Optional. Query filters.
     *     @type int    $audience_id Filter by audience.
     *     @type string $status      Filter by status.
     *     @type string $search      Search in title.
     *     @type string $orderby     Column to order by. Default 'created_at'.
     *     @type string $order       ASC or DESC. Default 'DESC'.
     *     @type int    $limit       Max results. Default 0 (no limit).
     *     @type int    $offset      Offset. Default 0.
     * }
     * @return array<object>
     */
    public static function get_all(array $filters = array()): array {
        global $wpdb;
        $table = self::get_table_name();
        $audiences_table = AudienceRepository::get_table_name();

        $defaults = array(
            'audience_id' => null,
            'status'      => null,
            'search'      => null,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
            'limit'       => 0,
            'offset'      => 0,
        );
        $filters = wp_parse_args($filters, $defaults);

        $where = array();
        $values = array();

        if ($filters['audience_id'] !== null) {
            $where[] = 'r.audience_id = %d';
            $values[] = (int) $filters['audience_id'];
        }

        if ($filters['status'] !== null) {
            $where[] = 'r.status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'r.title LIKE %s';
            $values[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $allowed_orderby = array('title', 'start_date', 'end_date', 'status', 'created_at');
        $orderby = in_array($filters['orderby'], $allowed_orderby, true) ? $filters['orderby'] : 'created_at';
        $order = strtoupper($filters['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit_clause = $filters['limit'] > 0 ? sprintf('LIMIT %d OFFSET %d', $filters['limit'], $filters['offset']) : '';

        $sql = "SELECT r.*, a.name AS audience_name, a.color AS audience_color
                FROM {$table} r
                LEFT JOIN {$audiences_table} a ON r.audience_id = a.id
                {$where_clause}
                ORDER BY r.{$orderby} {$order}
                {$limit_clause}";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $values);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * Count reregistrations with filters.
     *
     * @param array $filters Same filters as get_all.
     * @return int
     */
    public static function count(array $filters = array()): int {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $values = array();

        if (!empty($filters['audience_id'])) {
            $where[] = 'audience_id = %d';
            $values[] = (int) $filters['audience_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) FROM {$table} {$where_clause}";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare($sql, $values);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Create a reregistration campaign.
     *
     * @param array $data Campaign data.
     * @return int|false Reregistration ID or false on failure.
     */
    public static function create(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'title'                      => '',
            'audience_id'                => 0,
            'start_date'                 => '',
            'end_date'                   => '',
            'auto_approve'               => 0,
            'email_invitation_enabled'   => 0,
            'email_reminder_enabled'     => 0,
            'email_confirmation_enabled' => 0,
            'reminder_days'              => 7,
            'status'                     => 'draft',
            'created_by'                 => get_current_user_id(),
        );
        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            array(
                'title'                      => sanitize_text_field($data['title']),
                'audience_id'                => (int) $data['audience_id'],
                'start_date'                 => $data['start_date'],
                'end_date'                   => $data['end_date'],
                'auto_approve'               => (int) $data['auto_approve'],
                'email_invitation_enabled'   => (int) $data['email_invitation_enabled'],
                'email_reminder_enabled'     => (int) $data['email_reminder_enabled'],
                'email_confirmation_enabled' => (int) $data['email_confirmation_enabled'],
                'reminder_days'              => (int) $data['reminder_days'],
                'status'                     => $data['status'],
                'created_by'                 => (int) $data['created_by'],
            ),
            array('%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a reregistration campaign.
     *
     * @param int   $id   Reregistration ID.
     * @param array $data Update data.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = self::get_table_name();

        unset($data['id'], $data['created_by'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        $update_data = array();
        $format = array();

        $field_formats = array(
            'title'                      => '%s',
            'audience_id'                => '%d',
            'start_date'                 => '%s',
            'end_date'                   => '%s',
            'auto_approve'               => '%d',
            'email_invitation_enabled'   => '%d',
            'email_reminder_enabled'     => '%d',
            'email_confirmation_enabled' => '%d',
            'reminder_days'              => '%d',
            'status'                     => '%s',
        );

        foreach ($data as $key => $value) {
            if (!isset($field_formats[$key])) {
                continue;
            }
            if ($key === 'title') {
                $value = sanitize_text_field($value);
            }
            $update_data[$key] = $value;
            $format[] = $field_formats[$key];
        }

        if (empty($update_data)) {
            return false;
        }

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
     * Delete a reregistration campaign and its submissions.
     *
     * @param int $id Reregistration ID.
     * @return bool
     */
    public static function delete(int $id): bool {
        global $wpdb;
        $table = self::get_table_name();
        $subs_table = ReregistrationSubmissionRepository::get_table_name();

        // Delete submissions first
        $wpdb->delete($subs_table, array('reregistration_id' => $id), array('%d'));

        // Delete the campaign
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Get active reregistrations for a specific audience (including parent cascading).
     *
     * @param int $audience_id Audience ID.
     * @return array<object>
     */
    public static function get_active_for_audience(int $audience_id): array {
        $audience = AudienceRepository::get_by_id($audience_id);
        if (!$audience) {
            return array();
        }

        // Collect this audience + parents
        $audience_ids = array($audience_id);
        $current = $audience;
        while (!empty($current->parent_id)) {
            $audience_ids[] = (int) $current->parent_id;
            $current = AudienceRepository::get_by_id((int) $current->parent_id);
            if (!$current) {
                break;
            }
        }

        global $wpdb;
        $table = self::get_table_name();

        $placeholders = implode(',', array_fill(0, count($audience_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE audience_id IN ({$placeholders})
                AND status = 'active'
                ORDER BY start_date ASC",
                $audience_ids
            )
        );
    }

    /**
     * Get active reregistrations for a user based on their audience memberships.
     *
     * @param int $user_id User ID.
     * @return array<object>
     */
    public static function get_active_for_user(int $user_id): array {
        $audiences = AudienceRepository::get_user_audiences($user_id);
        if (empty($audiences)) {
            return array();
        }

        $all_regs = array();
        $seen_ids = array();

        foreach ($audiences as $audience) {
            $regs = self::get_active_for_audience((int) $audience->id);
            foreach ($regs as $reg) {
                if (!isset($seen_ids[(int) $reg->id])) {
                    $seen_ids[(int) $reg->id] = true;
                    $all_regs[] = $reg;
                }
            }
        }

        return $all_regs;
    }

    /**
     * Expire overdue reregistrations.
     *
     * Changes status from 'active' to 'expired' for campaigns past end_date.
     * Also updates pending/in_progress submissions to 'expired'.
     *
     * @return int Number of campaigns expired.
     */
    public static function expire_overdue(): int {
        global $wpdb;
        $table = self::get_table_name();
        $subs_table = ReregistrationSubmissionRepository::get_table_name();

        // Find active reregistrations past end date
        $overdue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status = 'active' AND end_date < %s",
                current_time('mysql')
            )
        );

        if (empty($overdue)) {
            return 0;
        }

        $count = 0;
        foreach ($overdue as $row) {
            // Expire the campaign
            $wpdb->update(
                $table,
                array('status' => 'expired'),
                array('id' => (int) $row->id),
                array('%s'),
                array('%d')
            );

            // Expire pending/in_progress submissions
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$subs_table} SET status = 'expired', updated_at = %s
                    WHERE reregistration_id = %d AND status IN ('pending', 'in_progress')",
                    current_time('mysql'),
                    (int) $row->id
                )
            );

            $count++;
        }

        return $count;
    }

    /**
     * Get audiences affected by a reregistration (target + all children).
     *
     * @param int $audience_id Root audience ID.
     * @return array<int> Audience IDs.
     */
    public static function get_affected_audience_ids(int $audience_id): array {
        $ids = array($audience_id);
        $children = AudienceRepository::get_children($audience_id);
        foreach ($children as $child) {
            $ids[] = (int) $child->id;
            // Recurse for deeper hierarchy
            $grandchildren = AudienceRepository::get_children((int) $child->id);
            foreach ($grandchildren as $gc) {
                $ids[] = (int) $gc->id;
            }
        }
        return array_unique($ids);
    }

    /**
     * Get all members affected by a reregistration (audience + children).
     *
     * @param int $audience_id Root audience ID.
     * @return array<int> User IDs.
     */
    public static function get_affected_user_ids(int $audience_id): array {
        $audience_ids = self::get_affected_audience_ids($audience_id);
        $user_ids = array();

        foreach ($audience_ids as $aud_id) {
            $members = AudienceRepository::get_members($aud_id);
            $user_ids = array_merge($user_ids, $members);
        }

        return array_unique($user_ids);
    }
}
