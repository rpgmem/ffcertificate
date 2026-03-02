<?php
declare(strict_types=1);

/**
 * Reregistration Repository
 *
 * Handles database operations for reregistration campaigns.
 * Audiences are stored in a junction table (wp_ffc_reregistration_audiences).
 *
 * @since 4.11.0
 * @since 4.13.0 Multi-audience support via junction table.
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

class ReregistrationRepository {
    use \FreeFormCertificate\Core\StaticRepositoryTrait;

    /**
     * Cache group for reregistration queries.
     *
     * @return string
     */
    protected static function cache_group(): string {
        return 'ffc_reregistrations';
    }

    /**
     * Valid statuses for a reregistration campaign.
     */
    public const STATUSES = array('draft', 'active', 'expired', 'closed');

    /**
     * Get human-readable status labels.
     *
     * @return array<string, string> Status key => translated label.
     */
    public static function get_status_labels(): array {
        return array(
            'draft'   => __('Draft', 'ffcertificate'),
            'active'  => __('Active', 'ffcertificate'),
            'expired' => __('Expired', 'ffcertificate'),
            'closed'  => __('Closed', 'ffcertificate'),
        );
    }

    /**
     * Get a single status label.
     *
     * @param string $status Status key.
     * @return string Translated label (falls back to the key).
     */
    public static function get_status_label(string $status): string {
        $labels = self::get_status_labels();
        return $labels[$status] ?? $status;
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public static function get_table_name(): string {
        return self::db()->prefix . 'ffc_reregistrations';
    }

    /**
     * Get junction table name.
     *
     * @return string
     */
    public static function get_audiences_table_name(): string {
        return self::db()->prefix . 'ffc_reregistration_audiences';
    }

    // ─────────────────────────────────────────────
    // AUDIENCE JUNCTION TABLE
    // ─────────────────────────────────────────────

    /**
     * Get audience IDs for a reregistration.
     *
     * @param int $reregistration_id Reregistration ID.
     * @return array<int>
     */
    public static function get_audience_ids(int $reregistration_id): array {
        $wpdb = self::db();
        $table = self::get_audiences_table_name();

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT audience_id FROM %i WHERE reregistration_id = %d",
                $table,
                $reregistration_id
            )
        );

        return array_map('intval', $rows);
    }

    /**
     * Set audience IDs for a reregistration (replaces all existing).
     *
     * @param int       $reregistration_id Reregistration ID.
     * @param array<int> $audience_ids      Audience IDs.
     * @return void
     */
    public static function set_audience_ids(int $reregistration_id, array $audience_ids): void {
        $wpdb = self::db();
        $table = self::get_audiences_table_name();

        $wpdb->delete($table, array('reregistration_id' => $reregistration_id), array('%d'));

        foreach (array_unique(array_filter(array_map('intval', $audience_ids))) as $aud_id) {
            $wpdb->insert(
                $table,
                array(
                    'reregistration_id' => $reregistration_id,
                    'audience_id'       => $aud_id,
                ),
                array('%d', '%d')
            );
        }
    }

    /**
     * Get audience objects for a reregistration (with name and color).
     *
     * @param int $reregistration_id Reregistration ID.
     * @return array<object>
     */
    public static function get_audiences(int $reregistration_id): array {
        $wpdb = self::db();
        $junction = self::get_audiences_table_name();
        $audiences = AudienceRepository::get_table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.id, a.name, a.color
                 FROM %i ra
                 JOIN %i a ON ra.audience_id = a.id
                 WHERE ra.reregistration_id = %d
                 ORDER BY a.name ASC",
                $junction,
                $audiences,
                $reregistration_id
            )
        );
    }

    // ─────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────

    /**
     * Get a reregistration by ID.
     *
     * @param int $id Reregistration ID.
     * @return object|null
     */
    public static function get_by_id(int $id): ?object {
        $cached = static::cache_get("id_{$id}");
        if ($cached !== false) {
            return $cached;
        }

        $wpdb = self::db();
        $table = self::get_table_name();

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", $table, $id)
        );

        if ($result) {
            static::cache_set("id_{$id}", $result);
        }

        return $result;
    }

    /**
     * Get all reregistrations with optional filters.
     *
     * @param array<string, mixed> $filters {
     *     Optional. Query filters.
     *     @type int    $audience_id Filter by audience (campaigns that include this audience).
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
        $wpdb = self::db();
        $table = self::get_table_name();
        $junction = self::get_audiences_table_name();

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

        $joins = '';
        $where = array();
        $values = array($table);

        if ($filters['audience_id'] !== null) {
            $joins = 'JOIN %i ra_filter ON r.id = ra_filter.reregistration_id AND ra_filter.audience_id = %d';
            $values[] = $junction;
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

        $sql = "SELECT DISTINCT r.*
                FROM %i r
                {$joins}
                {$where_clause}
                ORDER BY r.{$orderby} {$order}
                {$limit_clause}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, $values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($sql);
    }

    /**
     * Count reregistrations with filters.
     *
     * @param array<string, mixed> $filters Same filters as get_all.
     * @return int
     */
    public static function count(array $filters = array()): int {
        $wpdb = self::db();
        $table = self::get_table_name();
        $junction = self::get_audiences_table_name();

        $joins = '';
        $where = array();
        $values = array($table);

        if (!empty($filters['audience_id'])) {
            $joins = 'JOIN %i ra_filter ON r.id = ra_filter.reregistration_id AND ra_filter.audience_id = %d';
            $values[] = $junction;
            $values[] = (int) $filters['audience_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = %s';
            $values[] = $filters['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(DISTINCT r.id) FROM %i r {$joins} {$where_clause}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare($sql, $values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Create a reregistration campaign.
     *
     * @param array<string, mixed> $data Campaign data (audience_ids handled separately).
     * @return int|false Reregistration ID or false on failure.
     */
    public static function create(array $data) {
        $wpdb = self::db();
        $table = self::get_table_name();

        $defaults = array(
            'title'                      => '',
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
            array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a reregistration campaign.
     *
     * @param int   $id   Reregistration ID.
     * @param array<string, mixed> $data Update data.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        $wpdb = self::db();
        $table = self::get_table_name();

        unset($data['id'], $data['created_by'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        $update_data = array();
        $format = array();

        $field_formats = array(
            'title'                      => '%s',
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

        static::cache_delete("id_{$id}");

        return $result !== false;
    }

    /**
     * Delete a reregistration campaign, its audience links, and submissions.
     *
     * @param int $id Reregistration ID.
     * @return bool
     */
    public static function delete(int $id): bool {
        $wpdb = self::db();
        $table = self::get_table_name();
        $subs_table = ReregistrationSubmissionRepository::get_table_name();
        $junction = self::get_audiences_table_name();

        // Delete submissions first
        $wpdb->delete($subs_table, array('reregistration_id' => $id), array('%d'));

        // Delete audience links
        $wpdb->delete($junction, array('reregistration_id' => $id), array('%d'));

        // Delete the campaign
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        static::cache_delete("id_{$id}");

        return $result !== false;
    }

    // ─────────────────────────────────────────────
    // ACTIVE LOOKUPS (frontend)
    // ─────────────────────────────────────────────

    /**
     * Get active reregistrations for a specific audience.
     *
     * Finds campaigns whose audience set includes the given audience
     * or any of its parent audiences.
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

        $wpdb = self::db();
        $table = self::get_table_name();
        $junction = self::get_audiences_table_name();

        $placeholders = implode(',', array_fill(0, count($audience_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT r.* FROM %i r
                 JOIN %i ra ON r.id = ra.reregistration_id
                 WHERE ra.audience_id IN ({$placeholders})
                 AND r.status = 'active'
                 ORDER BY r.start_date ASC",
                array_merge(array($table, $junction), $audience_ids)
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

    // ─────────────────────────────────────────────
    // EXPIRATION
    // ─────────────────────────────────────────────

    /**
     * Expire overdue reregistrations.
     *
     * Changes status from 'active' to 'expired' for campaigns past end_date.
     * Also updates pending/in_progress submissions to 'expired'.
     *
     * @return void
     */
    public static function expire_overdue(): void {
        $wpdb = self::db();
        $table = self::get_table_name();
        $subs_table = ReregistrationSubmissionRepository::get_table_name();

        // Find active reregistrations past end date
        $overdue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM %i WHERE status = 'active' AND end_date < %s",
                $table,
                current_time('mysql')
            )
        );

        if (empty($overdue)) {
            return;
        }

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
                    "UPDATE %i SET status = 'expired', updated_at = %s
                    WHERE reregistration_id = %d AND status IN ('pending', 'in_progress')",
                    $subs_table,
                    current_time('mysql'),
                    (int) $row->id
                )
            );
        }
    }

    // ─────────────────────────────────────────────
    // AFFECTED MEMBERS
    // ─────────────────────────────────────────────

    /**
     * Get all user IDs affected by a reregistration's audience set.
     *
     * @param int $reregistration_id Reregistration ID.
     * @return array<int> User IDs.
     */
    public static function get_affected_user_ids_for_reregistration(int $reregistration_id): array {
        $audience_ids = self::get_audience_ids($reregistration_id);
        return self::get_user_ids_for_audiences($audience_ids);
    }

    /**
     * Get all user IDs that belong to the given audience IDs.
     *
     * @param array<int> $audience_ids Audience IDs (individual, no cascading).
     * @return array<int> User IDs.
     */
    public static function get_user_ids_for_audiences(array $audience_ids): array {
        $user_ids = array();

        foreach ($audience_ids as $aud_id) {
            $members = AudienceRepository::get_members((int) $aud_id);
            $user_ids = array_merge($user_ids, $members);
        }

        return array_unique($user_ids);
    }
}
