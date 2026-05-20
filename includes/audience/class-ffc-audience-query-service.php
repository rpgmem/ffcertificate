<?php
/**
 * AudienceQueryService
 *
 * Cross-table read aggregator for the user-facing audience surface.
 * Concentrates the multi-table JOINs that lived inline in
 * `Api\UserAudienceRestController` + `Api\UserSummaryRestController`
 * (#343 group B).
 *
 * Rationale: each REST endpoint composes data from 4–7 audience tables
 * to build a view-model. Pushing those joins into any single audience
 * repository would violate single-table ownership; this service is the
 * right layer — read-only, scoped to "what does this user see in the
 * audience UI?", easy to test against a mocked wpdb.
 *
 * Design notes (#343 Option C):
 *   - Service returns rich denormalized rows; REST controllers own the
 *     final view-model assembly (badge nesting, parent-child tree, etc.).
 *   - All methods are stateless static — matches `UserService` and
 *     `UserIdentifiersQueryService` already in this codebase.
 *   - No caching at this layer initially; profiling will tell whether
 *     per-request memoization helps. Repository-level caches still
 *     apply where the service delegates back to a repository.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Stateless service. Public API mirrors what REST controllers used to
 * spell inline; the JOIN shape is the same — just centralized.
 */
final class AudienceQueryService {

	/**
	 * Count the user's active self-join audience memberships.
	 *
	 * A "self-join membership" is a row in `ffc_audience_members` whose
	 * audience meets BOTH `allow_self_join = 1` AND `parent_id IS NOT NULL`
	 * (only child audiences are joinable per the §self-join rules; this
	 * matches the gate `UserAudienceRestController::join_audience_group`
	 * uses to enforce the `MAX_SELF_JOIN_GROUPS` cap).
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function count_user_self_join_memberships( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$audiences_table = $wpdb->prefix . 'ffc_audiences';
		$members_table   = $wpdb->prefix . 'ffc_audience_members';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence-style count gating a user-initiated POST; instantaneous decision.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i m
				INNER JOIN %i a ON a.id = m.audience_id
				WHERE m.user_id = %d AND a.allow_self_join = 1 AND a.parent_id IS NOT NULL',
				$members_table,
				$audiences_table,
				$user_id
			)
		);
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * List every self-joinable, active audience with a per-row
	 * `is_member` boolean flag indicating whether the supplied user
	 * already belongs. Returned flat (no parent-child nesting) — the
	 * REST controller assembles the tree because that's a presentation
	 * concern.
	 *
	 * Each row carries: `id` (int), `name` (string), `color` (string),
	 * `parent_id` (?int — null for root audiences), `is_member` (bool).
	 *
	 * Issue #343 group B.
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return list<array{id: int, name: string, color: string, parent_id: ?int, is_member: bool}>
	 */
	public static function find_user_joinable_audiences( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$audiences_table = $wpdb->prefix . 'ffc_audiences';
		$members_table   = $wpdb->prefix . 'ffc_audience_members';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LEFT JOIN per-user; bounded by active+joinable audiences (small set).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.name, a.color, a.parent_id,
					CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END AS is_member
				FROM %i a
				LEFT JOIN %i m ON m.audience_id = a.id AND m.user_id = %d
				WHERE a.allow_self_join = 1 AND a.status = 'active'
				ORDER BY a.name ASC",
				$audiences_table,
				$members_table,
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'        => (int) ( $row['id'] ?? 0 ),
				'name'      => (string) ( $row['name'] ?? '' ),
				'color'     => (string) ( $row['color'] ?? '' ),
				'parent_id' => empty( $row['parent_id'] ) ? null : (int) $row['parent_id'],
				'is_member' => 1 === (int) ( $row['is_member'] ?? 0 ),
			);
		}
		return $out;
	}
}
