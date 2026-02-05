<?php
declare(strict_types=1);

/**
 * Audience Repository
 *
 * Handles database operations for audience groups (públicos-alvo).
 * Supports 2-level hierarchy (parent/child).
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceRepository {

    /**
     * Get audiences table name
     *
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audiences';
    }

    /**
     * Get members table name
     *
     * @return string
     */
    public static function get_members_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ffc_audience_members';
    }

    /**
     * Get all audiences
     *
     * @param array $args Query arguments
     * @return array<object>
     */
    public static function get_all(array $args = array()): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'parent_id' => null, // null = all, 0 = only parents, >0 = children of specific parent
            'status' => null,
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array();
        $values = array();

        if ($args['parent_id'] !== null) {
            if ($args['parent_id'] === 0) {
                $where[] = 'parent_id IS NULL';
            } else {
                $where[] = 'parent_id = %d';
                $values[] = $args['parent_id'];
            }
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
     * Get audience by ID
     *
     * @param int $id Audience ID
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
     * Get parent audiences (top-level groups)
     *
     * @param string|null $status Optional status filter
     * @return array<object>
     */
    public static function get_parents(?string $status = null): array {
        return self::get_all(array(
            'parent_id' => 0,
            'status' => $status,
        ));
    }

    /**
     * Get children of a parent audience
     *
     * @param int $parent_id Parent audience ID
     * @param string|null $status Optional status filter
     * @return array<object>
     */
    public static function get_children(int $parent_id, ?string $status = null): array {
        return self::get_all(array(
            'parent_id' => $parent_id,
            'status' => $status,
        ));
    }

    /**
     * Get audiences with their children (hierarchical)
     *
     * @param string|null $status Optional status filter
     * @return array<object> Parents with 'children' property
     */
    public static function get_hierarchical(?string $status = null): array {
        $parents = self::get_parents($status);

        foreach ($parents as $parent) {
            $parent->children = self::get_children($parent->id, $status);
        }

        return $parents;
    }

    /**
     * Create an audience
     *
     * @param array $data Audience data
     * @return int|false Audience ID or false on failure
     */
    public static function create(array $data) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'name' => '',
            'color' => '#3788d8',
            'parent_id' => null,
            'status' => 'active',
            'created_by' => get_current_user_id(),
        );
        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            array(
                'name' => $data['name'],
                'color' => $data['color'],
                'parent_id' => $data['parent_id'],
                'status' => $data['status'],
                'created_by' => $data['created_by'],
            ),
            array('%s', '%s', '%d', '%s', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update an audience
     *
     * @param int $id Audience ID
     * @param array $data Update data
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
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
            'color' => '%s',
            'parent_id' => '%d',
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
     * Delete an audience
     *
     * Note: This also deletes all child audiences and member associations.
     *
     * @param int $id Audience ID
     * @return bool
     */
    public static function delete(int $id): bool {
        global $wpdb;
        $table = self::get_table_name();
        $members_table = self::get_members_table_name();

        // Delete children first
        $children = self::get_children($id);
        foreach ($children as $child) {
            self::delete($child->id);
        }

        // Delete member associations
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($members_table, array('audience_id' => $id), array('%d'));

        // Delete the audience
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $result !== false;
    }

    /**
     * Add a member to an audience
     *
     * @param int $audience_id Audience ID
     * @param int $user_id User ID
     * @return int|false Member ID or false on failure
     */
    public static function add_member(int $audience_id, int $user_id) {
        global $wpdb;
        $table = self::get_members_table_name();

        // Check if already a member
        if (self::is_member($audience_id, $user_id)) {
            return false;
        }

        $result = $wpdb->insert(
            $table,
            array(
                'audience_id' => $audience_id,
                'user_id' => $user_id,
            ),
            array('%d', '%d')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Remove a member from an audience
     *
     * @param int $audience_id Audience ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function remove_member(int $audience_id, int $user_id): bool {
        global $wpdb;
        $table = self::get_members_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            array('audience_id' => $audience_id, 'user_id' => $user_id),
            array('%d', '%d')
        );

        return $result !== false;
    }

    /**
     * Check if a user is a member of an audience
     *
     * @param int $audience_id Audience ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function is_member(int $audience_id, int $user_id): bool {
        global $wpdb;
        $table = self::get_members_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE audience_id = %d AND user_id = %d",
                $audience_id,
                $user_id
            )
        );

        return (int) $count > 0;
    }

    /**
     * Get members of an audience
     *
     * @param int $audience_id Audience ID
     * @param bool $include_children Whether to include members of child audiences
     * @return array<int> User IDs
     */
    public static function get_members(int $audience_id, bool $include_children = false): array {
        global $wpdb;
        $table = self::get_members_table_name();

        $audience_ids = array($audience_id);

        // Include children if requested
        if ($include_children) {
            $children = self::get_children($audience_id);
            foreach ($children as $child) {
                $audience_ids[] = $child->id;
            }
        }

        $placeholders = implode(',', array_fill(0, count($audience_ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$table} WHERE audience_id IN ({$placeholders})",
                $audience_ids
            )
        );

        return array_map('intval', $results);
    }

    /**
     * Get audiences a user belongs to
     *
     * @param int $user_id User ID
     * @param bool $include_parents Whether to include parent audiences (when user is in child)
     * @return array<object>
     */
    public static function get_user_audiences(int $user_id, bool $include_parents = false): array {
        global $wpdb;
        $table = self::get_table_name();
        $members_table = self::get_members_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $audiences = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.* FROM {$table} a
                INNER JOIN {$members_table} m ON a.id = m.audience_id
                WHERE m.user_id = %d AND a.status = 'active'
                ORDER BY a.name ASC",
                $user_id
            )
        );

        // Include parent audiences if requested
        if ($include_parents && !empty($audiences)) {
            $parent_ids = array();
            foreach ($audiences as $audience) {
                if ($audience->parent_id) {
                    $parent_ids[] = $audience->parent_id;
                }
            }

            if (!empty($parent_ids)) {
                $parent_ids = array_unique($parent_ids);
                $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $parents = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE id IN ({$placeholders}) AND status = 'active'",
                        $parent_ids
                    )
                );

                // Merge and remove duplicates
                $existing_ids = array_column($audiences, 'id');
                foreach ($parents as $parent) {
                    if (!in_array($parent->id, $existing_ids, true)) {
                        $audiences[] = $parent;
                    }
                }

                // Sort by name
                usort($audiences, function($a, $b) {
                    return strcmp($a->name, $b->name);
                });
            }
        }

        return $audiences;
    }

    /**
     * Get member count for an audience
     *
     * @param int $audience_id Audience ID
     * @param bool $include_children Whether to include members of child audiences
     * @return int
     */
    public static function get_member_count(int $audience_id, bool $include_children = false): int {
        return count(self::get_members($audience_id, $include_children));
    }

    /**
     * Bulk add members to an audience
     *
     * @param int $audience_id Audience ID
     * @param array<int> $user_ids User IDs
     * @return int Number of members added
     */
    public static function bulk_add_members(int $audience_id, array $user_ids): int {
        $added = 0;
        foreach ($user_ids as $user_id) {
            if (self::add_member($audience_id, (int) $user_id)) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * Bulk remove members from an audience
     *
     * @param int $audience_id Audience ID
     * @param array<int> $user_ids User IDs
     * @return int Number of members removed
     */
    public static function bulk_remove_members(int $audience_id, array $user_ids): int {
        $removed = 0;
        foreach ($user_ids as $user_id) {
            if (self::remove_member($audience_id, (int) $user_id)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Replace all members of an audience
     *
     * @param int $audience_id Audience ID
     * @param array<int> $user_ids User IDs
     * @return bool
     */
    public static function set_members(int $audience_id, array $user_ids): bool {
        global $wpdb;
        $table = self::get_members_table_name();

        // Remove all existing members
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete($table, array('audience_id' => $audience_id), array('%d'));

        // Add new members
        foreach ($user_ids as $user_id) {
            self::add_member($audience_id, (int) $user_id);
        }

        return true;
    }

    /**
     * Count audiences
     *
     * @param array $args Query arguments (parent_id, status)
     * @return int
     */
    public static function count(array $args = array()): int {
        global $wpdb;
        $table = self::get_table_name();

        $where = array();
        $values = array();

        if (isset($args['parent_id'])) {
            if ($args['parent_id'] === 0 || $args['parent_id'] === null) {
                $where[] = 'parent_id IS NULL';
            } else {
                $where[] = 'parent_id = %d';
                $values[] = $args['parent_id'];
            }
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

    /**
     * Search audiences by name
     *
     * @param string $search Search term
     * @param int $limit Max results
     * @return array<object>
     */
    public static function search(string $search, int $limit = 10): array {
        global $wpdb;
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE name LIKE %s AND status = 'active'
                ORDER BY name ASC
                LIMIT %d",
                '%' . $wpdb->esc_like($search) . '%',
                $limit
            )
        );
    }
}
