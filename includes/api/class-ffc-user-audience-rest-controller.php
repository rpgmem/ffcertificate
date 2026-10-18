<?php
/**
 * User Audience REST Controller
 *
 * Handles:
 *   GET  /user/audience-bookings    – Current user's audience bookings
 *   GET  /user/joinable-groups      – Groups that allow self-join
 *   POST /user/audience-group/join  – Join a self-joinable group
 *   POST /user/audience-group/leave – Leave a self-joinable group
 *   POST /user/audience-group/leave-all – Leave every self-joinable group
 *   POST /user/audience-group/switch – Leave one group and join another of the same tree
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

use FreeFormCertificate\Audience\AudienceTreeRules;
use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * REST API controller for user audience endpoints.
 */
class UserAudienceRestController {

	use UserContextTrait;
	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace Namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/audience-bookings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_audience_bookings' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/joinable-groups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_joinable_groups' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/audience-group/join',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'join_audience_group' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/audience-group/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'leave_audience_group' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/audience-group/leave-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'leave_all_audience_groups' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			$this->namespace,
			'/user/audience-group/switch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'switch_audience_group' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * GET /user/audience-bookings
	 *
	 * @since 4.5.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_audience_bookings( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error(
					'not_logged_in',
					__( 'You must be logged in to view bookings', 'ffcertificate' ),
					array( 'status' => 401 )
				);
			}

			if ( ! $this->user_has_capability( 'ffc_view_own_audience_bookings', $user_id, $ctx['is_view_as'] ) ) {
				return new \WP_Error(
					'capability_denied',
					__( 'You do not have permission to view audience bookings', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			$bookings = \FreeFormCertificate\Audience\AudienceQueryService::find_user_bookings( $user_id );

			$bookings_formatted = array();

			foreach ( $bookings as $booking ) {
				$date_formatted = '';
				if ( ! empty( $booking['booking_date'] ) ) {
					// booking_date is a wall-clock DATE (Category B) — render literally, no TZ shift.
					$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $booking['booking_date'] );
					if ( '' === $date_formatted ) {
						$date_formatted = (string) $booking['booking_date'];
					}
				}

				$time_formatted = '';
				if ( ! empty( $booking['start_time'] ) ) {
					$time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $booking['start_time'] );
					if ( '' === $time_formatted ) {
						$time_formatted = (string) $booking['start_time'];
					}
				}

				$end_time_formatted = '';
				if ( ! empty( $booking['end_time'] ) ) {
					$end_time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $booking['end_time'] );
				}

				$status_labels = array(
					'active'    => __( 'Confirmed', 'ffcertificate' ),
					'cancelled' => __( 'Cancelled', 'ffcertificate' ),
				);

				$status  = $booking['status'] ?? 'active';
				$is_past = (string) $booking['booking_date'] < current_time( 'Y-m-d' );

				$bookings_formatted[] = array(
					'id'               => (int) $booking['id'],
					'environment_id'   => (int) $booking['environment_id'],
					'environment_name' => $booking['environment_name'] ?? __( 'Unknown', 'ffcertificate' ),
					'schedule_name'    => $booking['schedule_name'] ?? '',
					'booking_date'     => $date_formatted,
					'booking_date_raw' => $booking['booking_date'],
					'start_time'       => $time_formatted,
					'end_time'         => $end_time_formatted,
					'description'      => $booking['description'],
					'status'           => $status,
					'status_label'     => $status_labels[ $status ] ?? $status,
					'is_past'          => $is_past,
					// The text colour over each audience's own colour is computed here,
					// not left to the script (#1826).
					'audiences'        => \FreeFormCertificate\Core\BadgeHtml::with_row_colors( $booking['audiences'] ),
				);
			}

			return rest_ensure_response(
				array(
					'bookings' => $bookings_formatted,
					'total'    => count( $bookings_formatted ),
				)
			);

		} catch ( \Exception $e ) {
			if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_rest_api(
					'get_user_audience_bookings error',
					array(
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
						'trace'   => $e->getTraceAsString(),
					)
				);
			}

			return new \WP_Error(
				'get_audience_bookings_error',
				/* translators: %s: error message */
				sprintf( __( 'Error loading audience bookings: %s', 'ffcertificate' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * GET /user/joinable-groups
	 *
	 * Lists audience groups that allow self-join, with the user's current membership status.
	 *
	 * @since 4.9.9
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_joinable_groups( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			global $wpdb;
			$audiences_table = $wpdb->prefix . 'ffc_audiences';
			// Schema guard for fresh / partially-upgraded installs — kept
			// in the controller (service trusts the schema). Service
			// degrades to `[]` gracefully if the column is missing anyway,
			// but the early-return here also short-circuits the response
			// assembly below.
			if ( ! self::table_exists( $audiences_table ) || ! self::column_exists( $audiences_table, 'allow_self_join' ) ) {
				return rest_ensure_response(
					array(
						'parents'         => array(),
						'joined_count'    => 0,
						'max_groups'      => \FreeFormCertificate\Audience\AudienceSelfJoinLimit::get(),
						'trees'           => array(),
						'leave_all_keeps' => array(),
					)
				);
			}

			$all = \FreeFormCertificate\Audience\AudienceQueryService::find_user_joinable_audiences( $user_id );

			if ( empty( $all ) ) {
				return rest_ensure_response(
					array(
						'parents'         => array(),
						'joined_count'    => 0,
						'max_groups'      => \FreeFormCertificate\Audience\AudienceSelfJoinLimit::get(),
						'trees'           => array(),
						'leave_all_keeps' => array(),
					)
				);
			}

			// Build tree in PHP (presentation concern — stays in the
			// controller). The service hands us a flat list with parent_id
			// + is_member already resolved.
			$by_id = array();
			foreach ( $all as $row ) {
				$row['children']     = array();
				$by_id[ $row['id'] ] = $row;
			}

			$roots = array();
			foreach ( $by_id as $id => $item ) {
				if ( null !== $item['parent_id'] && isset( $by_id[ $item['parent_id'] ] ) ) {
					$by_id[ $item['parent_id'] ]['children'][] = &$by_id[ $id ];
				}
			}
			foreach ( $by_id as $id => $item ) {
				if ( null === $item['parent_id'] || ! isset( $by_id[ $item['parent_id'] ] ) ) {
					$roots[] = &$by_id[ $id ];
				}
			}

			// Each root shown belongs to a tree whose top-level audience holds
			// the members-only and keep-at-least-one settings (#1918). A shown
			// root is not always that audience: a group whose parent is
			// inactive is listed at the top.
			$joined_count = 0;
			$result       = array();
			$trees        = array();
			foreach ( $roots as $root ) {
				$tree_id = AudienceTreeRules::root_id( (int) $root['id'] );
				if ( ! isset( $trees[ $tree_id ] ) ) {
					$trees[ $tree_id ] = $this->describe_tree( $tree_id, $user_id );
				}
				$tree = $trees[ $tree_id ];
				if ( $tree['members_only'] && 0 === $tree['total'] ) {
					continue;
				}

				// The one group the user may not leave: their last of a tree
				// that must keep them.
				$locked_id = ( $tree['require_membership'] && 1 === $tree['total'] ) ? $tree['only_id'] : 0;

				$node = $this->build_joinable_node( $root, $joined_count, $tree_id, $locked_id );
				if ( $node ) {
					$result[] = $node;
				}
			}

			$tree_list = array();
			foreach ( $trees as $tree_id => $tree ) {
				unset( $tree['only_id'] );
				$tree_list[] = array( 'id' => $tree_id ) + $tree;
			}

			return rest_ensure_response(
				array(
					'parents'         => $result,
					'joined_count'    => $joined_count,
					'max_groups'      => \FreeFormCertificate\Audience\AudienceSelfJoinLimit::get(),
					'trees'           => $tree_list,
					'leave_all_keeps' => AudienceTreeRules::groups_kept_on_leave_all( $user_id ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'joinable_groups_error', __( 'Error loading joinable groups', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Read an integer route parameter.
	 *
	 * `WP_REST_Request::get_param()` returns mixed — the route's registered
	 * type is a runtime validation, not something the analyser can see — and
	 * `absint()` on an array is a TypeError rather than a rejected request.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                 $key     Parameter name.
	 * @return int
	 */
	private static function param_int( $request, string $key ): int {
		$value = $request->get_param( $key );

		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * What the panel needs about one tree (#1918): its settings, how many of
	 * its groups the user holds, and the self-join ones among them — the
	 * groups a switch may replace.
	 *
	 * @param int $tree_id Top-level audience ID.
	 * @param int $user_id User ID.
	 * @return array{members_only: bool, require_membership: bool, total: int, held: list<array{id: int, name: string}>, only_id: int}
	 */
	private function describe_tree( int $tree_id, int $user_id ): array {
		$access      = AudienceTreeRules::access( $tree_id );
		$memberships = AudienceTreeRules::memberships( $tree_id, $user_id );
		$held        = array();
		foreach ( $memberships as $membership ) {
			if ( $membership['self_join'] ) {
				$held[] = array(
					'id'   => $membership['id'],
					'name' => $membership['name'],
				);
			}
		}
		return array(
			'members_only'       => $access['members_only'],
			'require_membership' => $access['require_membership'],
			'total'              => count( $memberships ),
			'held'               => $held,
			'only_id'            => 1 === count( $memberships ) ? $memberships[0]['id'] : 0,
		);
	}

	/**
	 * Recursively assemble a joinable-tree node from a raw audience row.
	 *
	 * Per-node model (#792 / CLAUDE.md self-join rules):
	 *   - A node has a **button** ⟺ its own `allow_self_join` is on.
	 *   - A node **appears** ⟺ it has a button OR has an appearing
	 *     descendant (a child that itself appears).
	 *   - A node is **header-only** when it appears without a button
	 *     (a non-self-join parent kept visible for its joinable children).
	 *
	 * So a node may carry BOTH a `joinable` button (its own membership)
	 * AND a `children` list (its appearing descendants). The `$count`
	 * reference tallies the user's memberships in button-bearing nodes —
	 * the on-screen "joined N of max" counter.
	 *
	 * The parameter is `array<array-key, mixed>` rather than
	 * `array<string, mixed>` because the recursive call feeds it an element of
	 * the node's own `children`, whose key type nothing guarantees. Every read
	 * below goes through `ArrayValue`, so a node missing a key yields a
	 * default instead of a warning.
	 *
	 * @param array<array-key, mixed> $node      Audience row with 'children' array.
	 * @param int                     $count     Reference counter for joined self-join nodes.
	 * @param int                     $tree_id   Top-level audience of the node's tree.
	 * @param int                     $locked_id The group the user may not leave, or 0.
	 * @return array<string, mixed>|null         Assembled node, or null when it does not appear.
	 */
	private function build_joinable_node( array $node, int &$count, int $tree_id = 0, int $locked_id = 0 ): ?array {
		$children = array();
		foreach ( ArrayValue::array( $node, 'children' ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$built = $this->build_joinable_node( $child, $count, $tree_id, $locked_id );
			if ( $built ) {
				$children[] = $built;
			}
		}

		$has_button = ! empty( $node['allow_self_join'] );

		// Does not appear: no button of its own and no appearing descendant.
		if ( ! $has_button && empty( $children ) ) {
			return null;
		}

		$out = array(
			'id'    => $node['id'],
			'name'  => $node['name'],
			'color' => $node['color'],
		);

		if ( $has_button ) {
			$out['joinable']  = true;
			$out['is_member'] = (bool) $node['is_member'];
			$out['tree_id']   = $tree_id;
			if ( $node['is_member'] ) {
				++$count;
				if ( \FreeFormCertificate\Core\ArrayValue::int( $node, 'id' ) === $locked_id ) {
					$out['leave_locked'] = true;
				}
			}
		}

		if ( ! empty( $children ) ) {
			$out['children'] = $children;
		}

		return $out;
	}

	/**
	 * POST /user/audience-group/join
	 *
	 * Join a self-joinable audience group. Max 2 self-join groups per user.
	 *
	 * @since 4.9.9
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function join_audience_group( $request ) {
		try {
			$ctx      = $this->resolve_user_context( $request );
			$user_id  = $ctx['user_id'];
			$group_id = self::param_int( $request, 'group_id' );

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! $group_id ) {
				return new \WP_Error( 'missing_group', __( 'Group ID is required', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			// Verify group is active and self-joinable. Joinability is per-node:
			// a group is directly joinable when its OWN allow_self_join is on —
			// whether it is a child, a top-level group with no children, or a
			// parent that also has children (the parent is joinable in its own
			// right; its children are joinable separately). The previous
			// `parent_id NOT NULL` check wrongly rejected top-level groups (the
			// reported bug).
			$group = \FreeFormCertificate\Audience\AudienceReader::get_by_id( $group_id );
			if ( ! $group
				|| 'active' !== (string) ( $group->status ?? '' )
				|| 1 !== (int) ( $group->allow_self_join ?? 0 )
			) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or does not allow self-join', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			// A members-only tree takes no one who does not already belong to
			// one of its groups (#1918); the panel hides it, and this refuses
			// a request made without the panel.
			if ( ! AudienceTreeRules::can_see( AudienceTreeRules::root_id( $group_id ), $user_id ) ) {
				return new \WP_Error( 'members_only', __( 'This group is open only to members of its audience.', 'ffcertificate' ), array( 'status' => 403 ) );
			}

			if ( \FreeFormCertificate\Audience\AudienceReader::is_member( $group_id, $user_id ) ) {
				return new \WP_Error( 'already_member', __( 'You are already a member of this group', 'ffcertificate' ), array( 'status' => 409 ) );
			}

			$current_count = \FreeFormCertificate\Audience\AudienceQueryService::count_user_self_join_memberships( $user_id );

			$max_groups = \FreeFormCertificate\Audience\AudienceSelfJoinLimit::get();

			if ( $current_count >= $max_groups ) {
				return new \WP_Error(
					'max_groups_reached',
					/* translators: %d: maximum number of groups */
					sprintf( __( 'You can join a maximum of %d groups. Leave one first.', 'ffcertificate' ), $max_groups ),
					array( 'status' => 422 )
				);
			}

			// Join the group. The audience capability comes with the
			// membership — `add_member()` grants it, so every entry point gets
			// it and not just this one (#1302).
			\FreeFormCertificate\Audience\AudienceWriter::add_member( $group_id, $user_id );

			return rest_ensure_response(
				array(
					'success' => true,
					/* translators: %s: group name */
					'message' => sprintf( __( 'You joined "%s"!', 'ffcertificate' ), $group->name ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'join_group_error', __( 'Error joining group', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /user/audience-group/leave
	 *
	 * Leave a self-joinable audience group.
	 *
	 * @since 4.9.9
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function leave_audience_group( $request ) {
		try {
			global $wpdb;
			$ctx      = $this->resolve_user_context( $request );
			$user_id  = $ctx['user_id'];
			$group_id = self::param_int( $request, 'group_id' );

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! $group_id ) {
				return new \WP_Error( 'missing_group', __( 'Group ID is required', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$audiences_table = $wpdb->prefix . 'ffc_audiences';
			$members_table   = $wpdb->prefix . 'ffc_audience_members';

			// Verify group is self-joinable (top-level or child — mirrors join()).
			$group = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, name FROM %i WHERE id = %d AND allow_self_join = 1',
					$audiences_table,
					$group_id
				)
			);

			if ( ! $group ) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or cannot be left by user', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			if ( AudienceTreeRules::is_last_kept_group( $group_id, $user_id ) ) {
				return new \WP_Error( 'keep_one_group', __( 'This audience keeps its members in at least one group. Join or switch to another of its groups before leaving this one.', 'ffcertificate' ), array( 'status' => 409 ) );
			}

			$deleted = $wpdb->delete(
				$members_table,
				array(
					'audience_id' => $group_id,
					'user_id'     => $user_id,
				),
				array( '%d', '%d' )
			);

			if ( ! $deleted ) {
				return new \WP_Error( 'not_member', __( 'You are not a member of this group', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					/* translators: %s: group name */
					'message' => sprintf( __( 'You left "%s".', 'ffcertificate' ), $group->name ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'leave_group_error', __( 'Error leaving group', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /user/audience-group/leave-all
	 *
	 * Leave all self-joinable audience groups at once, except one group in
	 * each tree that keeps its members in at least one (#1918): the one the
	 * user joined first.
	 *
	 * @since 5.1.0
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function leave_all_audience_groups( $request ) {
		try {
			global $wpdb;
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			$audiences_table = $wpdb->prefix . 'ffc_audiences';
			$members_table   = $wpdb->prefix . 'ffc_audience_members';

			// A tree that must keep the user keeps the group they joined
			// first; every other self-join membership goes (#1918).
			$kept     = AudienceTreeRules::groups_kept_on_leave_all( $user_id );
			$kept_ids = array_map( 'intval', array_column( $kept, 'group_id' ) );
			$args     = array( $members_table, $audiences_table, $user_id );
			$exclude  = '';
			if ( array() !== $kept_ids ) {
				$exclude = ' AND m.audience_id NOT IN (' . implode( ',', array_fill( 0, count( $kept_ids ), '%d' ) ) . ')';
				$args    = array_merge( $args, $kept_ids );
			}

			// Delete the memberships where the audience allows self-join.
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacements are one array: two tables, the user and one id per %d in $exclude.
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $exclude is ' AND m.audience_id NOT IN (%d,…)' with one %d per kept id, bound in $args.
				"DELETE m FROM %i m INNER JOIN %i a ON a.id = m.audience_id WHERE m.user_id = %d AND a.allow_self_join = 1{$exclude}",
				$args
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above.
			$deleted = is_string( $sql ) ? $wpdb->query( $sql ) : 0;

			// `kept` names, per tree, the group left in place, so the
			// dashboard can say what stayed and why.
			return rest_ensure_response(
				array(
					'success' => true,
					'removed' => (int) $deleted,
					'kept'    => $kept,
					'message' => array() === $kept
						? __( 'You left all groups.', 'ffcertificate' )
						: __( 'You left every group you could. Some audiences keep their members in at least one group.', 'ffcertificate' ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'leave_all_error', __( 'Error leaving groups', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * POST /user/audience-group/switch
	 *
	 * Leave one self-join group and join another of the same tree in one
	 * request (#1918): what a user at the group limit, or holding the last
	 * group of a tree that must keep them, does instead of Leave then Join.
	 * The count of self-join groups does not change, and both groups must
	 * share a top-level audience, so it cannot move a user past the limit
	 * from one tree into another.
	 *
	 * @since 6.41.0
	 * @param \WP_REST_Request $request REST request (`from_group_id`, `group_id`).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function switch_audience_group( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];
			$from_id = self::param_int( $request, 'from_group_id' );
			$to_id   = self::param_int( $request, 'group_id' );

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! $from_id || ! $to_id || $from_id === $to_id ) {
				return new \WP_Error( 'missing_group', __( 'Choose the group to leave and the group to join.', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$target = \FreeFormCertificate\Audience\AudienceReader::get_by_id( $to_id );
			if ( ! $target
				|| 'active' !== (string) ( $target->status ?? '' )
				|| 1 !== (int) ( $target->allow_self_join ?? 0 )
			) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or does not allow self-join', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			$current = \FreeFormCertificate\Audience\AudienceReader::get_by_id( $from_id );
			if ( ! $current || 1 !== (int) ( $current->allow_self_join ?? 0 ) ) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or cannot be left by user', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			$tree_id = AudienceTreeRules::root_id( $to_id );
			if ( AudienceTreeRules::root_id( $from_id ) !== $tree_id ) {
				return new \WP_Error( 'different_tree', __( 'You can only switch between groups of the same audience.', 'ffcertificate' ), array( 'status' => 422 ) );
			}

			if ( ! AudienceTreeRules::can_see( $tree_id, $user_id ) ) {
				return new \WP_Error( 'members_only', __( 'This group is open only to members of its audience.', 'ffcertificate' ), array( 'status' => 403 ) );
			}

			if ( ! \FreeFormCertificate\Audience\AudienceReader::is_member( $from_id, $user_id ) ) {
				return new \WP_Error( 'not_member', __( 'You are not a member of this group', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			if ( \FreeFormCertificate\Audience\AudienceReader::is_member( $to_id, $user_id ) ) {
				return new \WP_Error( 'already_member', __( 'You are already a member of this group', 'ffcertificate' ), array( 'status' => 409 ) );
			}

			// Join first, so a failure leaves the user where they were rather
			// than in neither group.
			if ( ! \FreeFormCertificate\Audience\AudienceWriter::add_member( $to_id, $user_id ) ) {
				return new \WP_Error( 'switch_group_error', __( 'Error switching group', 'ffcertificate' ), array( 'status' => 500 ) );
			}
			if ( ! \FreeFormCertificate\Audience\AudienceWriter::remove_member( $from_id, $user_id ) ) {
				\FreeFormCertificate\Audience\AudienceWriter::remove_member( $to_id, $user_id );
				return new \WP_Error( 'switch_group_error', __( 'Error switching group', 'ffcertificate' ), array( 'status' => 500 ) );
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: group left, 2: group joined */
						__( 'You left "%1$s" and joined "%2$s".', 'ffcertificate' ),
						$current->name,
						$target->name
					),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'switch_group_error', __( 'Error switching group', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}
}
