<?php
declare(strict_types=1);

/**
 * Reregistration Submission Repository
 *
 * Handles database operations for individual user responses to reregistration campaigns.
 *
 * @since 4.11.0
 * @package FreeFormCertificate\Reregistration
 */

namespace FreeFormCertificate\Reregistration;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ReregistrationSubmissionRepository {
    use \FreeFormCertificate\Core\StaticRepositoryTrait;

    /**
     * Cache group for reregistration submission queries.
     *
     * @return string
     */
    protected static function cache_group(): string {
        return 'ffc_rereg_submissions';
    }

    /**
     * Valid submission statuses.
     */
    public const STATUSES = array('pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired');

    /**
     * Get human-readable status labels.
     *
     * @return array<string, string> Status key => translated label.
     */
    public static function get_status_labels(): array {
        return array(
            'pending'     => __('Pending', 'ffcertificate'),
            'in_progress' => __('In Progress', 'ffcertificate'),
            'submitted'   => __('Submitted — Pending Review', 'ffcertificate'),
            'approved'    => __('Approved', 'ffcertificate'),
            'rejected'    => __('Rejected', 'ffcertificate'),
            'expired'     => __('Expired', 'ffcertificate'),
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
        return self::db()->prefix . 'ffc_reregistration_submissions';
    }

    /**
     * Get a submission by ID.
     *
     * @param int $id Submission ID.
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
     * Get a submission by its auth_code.
     *
     * @since 4.12.0
     * @param string $auth_code Cleaned auth code (uppercase, no hyphens).
     * @return object|null
     */
    public static function get_by_auth_code(string $auth_code): ?object {
        if (empty($auth_code)) {
            return null;
        }

        $wpdb = self::db();
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE auth_code = %s AND status IN ('submitted', 'approved')", $table, $auth_code)
        );
    }

    /**
     * Get a submission by its magic_token.
     *
     * @since 4.12.0
     * @param string $token Magic token (64 hex chars).
     * @return object|null
     */
    public static function get_by_magic_token(string $token): ?object {
        if (empty($token)) {
            return null;
        }

        $wpdb = self::db();
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE magic_token = %s AND status IN ('submitted', 'approved')", $table, $token)
        );
    }

    /**
     * Ensure a submission has a magic_token, generating one if missing.
     *
     * @param object $submission Submission row object.
     * @return string The magic_token (existing or newly generated).
     */
    public static function ensure_magic_token(object $submission): string {
        if (!empty($submission->magic_token)) {
            return $submission->magic_token;
        }

        $token = bin2hex(random_bytes(32));
        self::update((int) $submission->id, array('magic_token' => $token));

        return $token;
    }

    /**
     * Get submission for a specific reregistration and user.
     *
     * @param int $reregistration_id Reregistration ID.
     * @param int $user_id           User ID.
     * @return object|null
     */
    public static function get_by_reregistration_and_user(int $reregistration_id, int $user_id): ?object {
        $wpdb = self::db();
        $table = self::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE reregistration_id = %d AND user_id = %d",
                $table,
                $reregistration_id,
                $user_id
            )
        );
    }

    /**
     * Get all submissions for a user across all reregistrations.
     *
     * Joins with reregistrations table to include title and dates.
     *
     * @since 4.12.0
     * @param int $user_id User ID.
     * @return array<object>
     */
    public static function get_all_by_user(int $user_id): array {
        $wpdb = self::db();
        $table = self::get_table_name();
        $rereg_table = ReregistrationRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, r.title AS reregistration_title, r.start_date, r.end_date, r.status AS reregistration_status
                 FROM %i s
                 INNER JOIN %i r ON s.reregistration_id = r.id
                 WHERE s.user_id = %d
                 ORDER BY r.start_date DESC, s.created_at DESC",
                $table,
                $rereg_table,
                $user_id
            )
        );

        return is_array($results) ? $results : array();
    }

    /**
     * Get submissions for a reregistration with optional filters.
     *
     * @param int   $reregistration_id Reregistration ID.
     * @param array $filters {
     *     Optional. Query filters.
     *     @type string $status  Filter by status.
     *     @type string $search  Search in user display_name or email.
     *     @type string $orderby Column to order by. Default 'created_at'.
     *     @type string $order   ASC or DESC. Default 'ASC'.
     *     @type int    $limit   Max results. Default 0.
     *     @type int    $offset  Offset. Default 0.
     * }
     * @param array<string, mixed> $filters
     * @return array<object>
     */
    public static function get_by_reregistration(int $reregistration_id, array $filters = array()): array {
        $wpdb = self::db();
        $table = self::get_table_name();

        $defaults = array(
            'status'  => null,
            'search'  => null,
            'orderby' => 'created_at',
            'order'   => 'ASC',
            'limit'   => 0,
            'offset'  => 0,
        );
        $filters = wp_parse_args($filters, $defaults);

        $where = array('s.reregistration_id = %d');
        $values = array($reregistration_id);

        if ($filters['status'] !== null) {
            $where[] = 's.status = %s';
            $values[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        $allowed_orderby = array('created_at', 'submitted_at', 'reviewed_at', 'status');
        $orderby = in_array($filters['orderby'], $allowed_orderby, true) ? 's.' . $filters['orderby'] : 's.created_at';
        $order = strtoupper($filters['order']) === 'DESC' ? 'DESC' : 'ASC';
        $limit_clause = $filters['limit'] > 0 ? sprintf('LIMIT %d OFFSET %d', $filters['limit'], $filters['offset']) : '';

        $sql = "SELECT s.*, u.display_name AS user_name, u.user_email AS user_email
                FROM %i s
                LEFT JOIN %i u ON s.user_id = u.ID
                {$where_clause}
                ORDER BY {$orderby} {$order}
                {$limit_clause}";

        $prepare_values = array_merge(array($table, $wpdb->users), $values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $prepare_values));
    }

    /**
     * Create a submission record.
     *
     * @param array<string, mixed> $data Submission data.
     * @return int|false Submission ID or false.
     */
    public static function create(array $data) {
        $wpdb = self::db();
        $table = self::get_table_name();

        $defaults = array(
            'reregistration_id' => 0,
            'user_id'           => 0,
            'data'              => null,
            'status'            => 'pending',
            'submitted_at'      => null,
            'reviewed_at'       => null,
            'reviewed_by'       => null,
            'notes'             => null,
        );
        $data = wp_parse_args($data, $defaults);

        $insert_data = array(
            'reregistration_id' => (int) $data['reregistration_id'],
            'user_id'           => (int) $data['user_id'],
            'status'            => $data['status'],
        );
        $insert_format = array('%d', '%d', '%s');

        if ($data['data'] !== null) {
            $insert_data['data'] = is_string($data['data']) ? $data['data'] : wp_json_encode($data['data']);
            $insert_format[] = '%s';
        }

        if ($data['submitted_at'] !== null) {
            $insert_data['submitted_at'] = $data['submitted_at'];
            $insert_format[] = '%s';
        }

        if ($data['notes'] !== null) {
            $insert_data['notes'] = sanitize_textarea_field($data['notes']);
            $insert_format[] = '%s';
        }

        $result = $wpdb->insert($table, $insert_data, $insert_format);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a submission.
     *
     * @param int   $id   Submission ID.
     * @param array<string, mixed> $data Update data.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        $wpdb = self::db();
        $table = self::get_table_name();

        unset($data['id'], $data['reregistration_id'], $data['user_id'], $data['created_at']);

        if (empty($data)) {
            return false;
        }

        $update_data = array();
        $format = array();

        $field_formats = array(
            'data'         => '%s',
            'status'       => '%s',
            'submitted_at' => '%s',
            'reviewed_at'  => '%s',
            'reviewed_by'  => '%d',
            'notes'        => '%s',
            'auth_code'    => '%s',
            'magic_token'  => '%s',
        );

        foreach ($data as $key => $value) {
            if (!isset($field_formats[$key])) {
                continue;
            }

            if ($key === 'data' && !is_string($value)) {
                $value = wp_json_encode($value);
            }

            if ($key === 'notes' && $value !== null) {
                $value = sanitize_textarea_field($value);
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
     * Approve a submission.
     *
     * @param int $id          Submission ID.
     * @param int $reviewer_id Reviewer user ID.
     * @return bool
     */
    public static function approve(int $id, int $reviewer_id): bool {
        $result = self::update($id, array(
            'status'      => 'approved',
            'reviewed_at' => current_time('mysql'),
            'reviewed_by' => $reviewer_id,
        ));

        static::cache_delete("id_{$id}");

        return $result;
    }

    /**
     * Reject a submission.
     *
     * @param int    $id          Submission ID.
     * @param int    $reviewer_id Reviewer user ID.
     * @param string $notes       Rejection reason.
     * @return bool
     */
    public static function reject(int $id, int $reviewer_id, string $notes = ''): bool {
        $result = self::update($id, array(
            'status'      => 'rejected',
            'reviewed_at' => current_time('mysql'),
            'reviewed_by' => $reviewer_id,
            'notes'       => $notes,
        ));

        static::cache_delete("id_{$id}");

        return $result;
    }

    /**
     * Return a submission to draft (in_progress) so the user can revise it.
     *
     * Clears the review metadata and resets submitted_at so the user
     * sees it as an editable draft again.
     *
     * @param int $id          Submission ID.
     * @param int $reviewer_id Admin user ID performing the action.
     * @return bool
     */
    public static function return_to_draft(int $id, int $reviewer_id): bool {
        $wpdb = self::db();
        $table = self::get_table_name();

        $result = $wpdb->update(
            $table,
            array(
                'status'       => 'in_progress',
                'submitted_at' => null,
                'reviewed_at'  => null,
                'reviewed_by'  => null,
                'notes'        => null,
            ),
            array('id' => $id),
            array('%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        static::cache_delete("id_{$id}");

        return $result !== false;
    }

    /**
     * Bulk return multiple submissions to draft.
     *
     * @param array<int> $ids         Submission IDs.
     * @param int        $reviewer_id Admin user ID performing the action.
     * @return int Number of submissions returned to draft.
     */
    public static function bulk_return_to_draft(array $ids, int $reviewer_id): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::return_to_draft((int) $id, $reviewer_id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk approve multiple submissions.
     *
     * @param array<int> $ids         Submission IDs.
     * @param int        $reviewer_id Reviewer user ID.
     * @return int Number of approved submissions.
     */
    public static function bulk_approve(array $ids, int $reviewer_id): int {
        $count = 0;
        foreach ($ids as $id) {
            if (self::approve((int) $id, $reviewer_id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get statistics for a reregistration.
     *
     * @param int $reregistration_id Reregistration ID.
     * @return array<string, int> Counts keyed by status.
     */
    public static function get_statistics(int $reregistration_id): array {
        $wpdb = self::db();
        $table = self::get_table_name();

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM %i
                WHERE reregistration_id = %d GROUP BY status",
                $table,
                $reregistration_id
            )
        );

        $stats = array(
            'total'       => 0,
            'pending'     => 0,
            'in_progress' => 0,
            'submitted'   => 0,
            'approved'    => 0,
            'rejected'    => 0,
            'expired'     => 0,
        );

        foreach ($results as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        return $stats;
    }

    /**
     * Get submissions for CSV export.
     *
     * @param int   $reregistration_id Reregistration ID.
     * @param array<string, mixed> $filters           Optional filters (status, search).
     * @return array<object>
     */
    public static function get_for_export(int $reregistration_id, array $filters = array()): array {
        $filters['limit'] = 0;
        $filters['offset'] = 0;
        return self::get_by_reregistration($reregistration_id, $filters);
    }

    /**
     * Create pending submissions for all affected users of a reregistration.
     *
     * Skips users who already have a submission for this reregistration.
     *
     * @param int       $reregistration_id Reregistration ID.
     * @param array<int> $audience_ids      Audience IDs.
     * @return int Number of submissions created.
     */
    public static function create_for_audience_members(int $reregistration_id, array $audience_ids): int {
        $user_ids = ReregistrationRepository::get_user_ids_for_audiences($audience_ids);
        $created = 0;

        foreach ($user_ids as $user_id) {
            // Check if submission already exists
            $existing = self::get_by_reregistration_and_user($reregistration_id, $user_id);
            if ($existing) {
                continue;
            }

            $result = self::create(array(
                'reregistration_id' => $reregistration_id,
                'user_id'           => $user_id,
                'status'            => 'pending',
            ));

            if ($result) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Count submissions for a reregistration.
     *
     * @param int         $reregistration_id Reregistration ID.
     * @param string|null $status            Optional status filter.
     * @return int
     */
    public static function count_by_reregistration(int $reregistration_id, ?string $status = null): int {
        $wpdb = self::db();
        $table = self::get_table_name();

        $where = 'WHERE reregistration_id = %d';
        $values = array($reregistration_id);

        if ($status !== null) {
            $where .= ' AND status = %s';
            $values[] = $status;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i {$where}", array_merge(array($table), $values))
        );
    }
}
