<?php
/**
 * AudienceTreeRules
 *
 * The two tree-access settings of a top-level audience (#1918), and the
 * questions the dashboard's Join groups panel and its REST routes ask of
 * them: may this user see the tree, may they leave this group, and which
 * group "Leave all groups" keeps in a tree that must keep them.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$placeholders} is %d repeated to match count() of the bound id array; $wpdb->prepare() takes them as a single array argument, which the sniff counts as one replacement.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Members-only and keep-at-least-one, read on the top-level audience.
 *
 * Both flags live on the top-level row and cover every child and grandchild:
 * a group created under the tree belongs to it by definition, so nothing is
 * copied down. A membership counts however it was made — by an
 * administrator, an import or self-join.
 */
class AudienceTreeRules {

	/**
	 * The top-level audience a group belongs to (itself when it has no parent).
	 *
	 * @param int $audience_id Any audience in the tree.
	 * @return int
	 */
	public static function root_id( int $audience_id ): int {
		$chain = AudienceReader::get_ancestor_ids( $audience_id );
		return (int) end( $chain );
	}

	/**
	 * The two flags of a top-level audience. "Keep at least one group" reads
	 * off while "Visible to members only" is off, whatever is stored.
	 *
	 * @param int $root_id Top-level audience ID.
	 * @return array{members_only: bool, require_membership: bool}
	 */
	public static function access( int $root_id ): array {
		$root         = AudienceReader::get_by_id( $root_id );
		$members_only = null !== $root && ! empty( $root->members_only );
		return array(
			'members_only'       => $members_only,
			'require_membership' => $members_only && ! empty( $root->require_membership ),
		);
	}

	/**
	 * The groups of the tree the user belongs to, active or not.
	 *
	 * @param int $root_id Top-level audience ID.
	 * @param int $user_id User ID.
	 * @return list<array{id: int, name: string, self_join: bool, membership_id: int}>
	 */
	public static function memberships( int $root_id, int $user_id ): array {
		if ( $root_id <= 0 || $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$ids          = array_merge( array( $root_id ), AudienceReader::get_descendant_ids( $root_id ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is %d repeated to match count( $ids ); the tables go through %i and the ids are bound as a single array.
			"SELECT a.id, a.name, a.allow_self_join, m.id AS membership_id FROM %i m INNER JOIN %i a ON a.id = m.audience_id WHERE m.user_id = %d AND a.id IN ({$placeholders}) ORDER BY a.name ASC",
			array_merge( array( $wpdb->prefix . 'ffc_audience_members', $wpdb->prefix . 'ffc_audiences', $user_id ), $ids )
		);
		if ( ! is_string( $sql ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above; the sniff cannot follow a prepared string across an assignment.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'id'            => \FreeFormCertificate\Core\ArrayValue::int( $row, 'id' ),
				'name'          => \FreeFormCertificate\Core\ArrayValue::string( $row, 'name' ),
				'self_join'     => 1 === \FreeFormCertificate\Core\ArrayValue::int( $row, 'allow_self_join' ),
				'membership_id' => \FreeFormCertificate\Core\ArrayValue::int( $row, 'membership_id' ),
			);
		}
		return $out;
	}

	/**
	 * Whether the user sees the tree in the Join groups panel and may join
	 * one of its groups.
	 *
	 * @param int $root_id Top-level audience ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_see( int $root_id, int $user_id ): bool {
		if ( ! self::access( $root_id )['members_only'] ) {
			return true;
		}
		return array() !== self::memberships( $root_id, $user_id );
	}

	/**
	 * Whether leaving this group would take the user's last group of a tree
	 * that must keep them.
	 *
	 * @param int $group_id Group the user would leave.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	public static function is_last_kept_group( int $group_id, int $user_id ): bool {
		$root_id = self::root_id( $group_id );
		if ( ! self::access( $root_id )['require_membership'] ) {
			return false;
		}
		$ids = array_column( self::memberships( $root_id, $user_id ), 'id' );
		return array( $group_id ) === $ids;
	}

	/**
	 * The group "Leave all groups" keeps in each tree that must keep the
	 * user (#1918): a tree where every group the user holds is a self-join
	 * one, which is what that action removes, keeps the group the user joined
	 * first (the earliest membership row). A tree where the user also holds a
	 * group that does not allow self-join keeps that one anyway, so nothing
	 * is kept on purpose there.
	 *
	 * @param int $user_id User ID.
	 * @return list<array{tree_id: int, tree_name: string, group_id: int, group_name: string}>
	 */
	public static function groups_kept_on_leave_all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$group_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT m.audience_id FROM %i m INNER JOIN %i a ON a.id = m.audience_id WHERE m.user_id = %d AND a.allow_self_join = 1',
				$wpdb->prefix . 'ffc_audience_members',
				$wpdb->prefix . 'ffc_audiences',
				$user_id
			)
		);
		if ( ! is_array( $group_ids ) ) {
			return array();
		}

		$roots = array();
		foreach ( $group_ids as $group_id ) {
			$roots[ self::root_id( is_numeric( $group_id ) ? (int) $group_id : 0 ) ] = true;
		}

		$kept = array();
		foreach ( array_keys( $roots ) as $root_id ) {
			if ( ! self::access( $root_id )['require_membership'] ) {
				continue;
			}
			$held  = self::memberships( $root_id, $user_id );
			$first = null;
			foreach ( $held as $membership ) {
				if ( ! $membership['self_join'] ) {
					$first = null;
					break;
				}
				if ( null === $first || $membership['membership_id'] < $first['membership_id'] ) {
					$first = $membership;
				}
			}
			if ( null === $first ) {
				continue;
			}
			$root   = AudienceReader::get_by_id( $root_id );
			$kept[] = array(
				'tree_id'    => $root_id,
				'tree_name'  => null !== $root ? (string) $root->name : '',
				'group_id'   => $first['id'],
				'group_name' => $first['name'],
			);
		}
		return $kept;
	}
}
