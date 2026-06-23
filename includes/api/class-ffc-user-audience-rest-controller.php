<?php
/**
 * User Audience REST Controller
 *
 * Handles:
 *   GET  /user/audience-bookings    – Current user's audience bookings
 *   GET  /user/joinable-groups      – Groups that allow self-join
 *   POST /user/audience-group/join  – Join a self-joinable group
 *   POST /user/audience-group/leave – Leave a self-joinable group
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user audience endpoints.
 */
class UserAudienceRestController {

	use UserContextTrait;
	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Maximum number of self-join groups a user can belong to
	 */
	private const MAX_SELF_JOIN_GROUPS = 2;

	/**
	 * API namespace
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param string $namespace Namespace.
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
					'environment_id'   => (int) ( $booking['environment_id'] ?? 0 ),
					'environment_name' => $booking['environment_name'] ?? __( 'Unknown', 'ffcertificate' ),
					'schedule_name'    => $booking['schedule_name'] ?? '',
					'booking_date'     => $date_formatted,
					'booking_date_raw' => $booking['booking_date'] ?? '',
					'start_time'       => $time_formatted,
					'end_time'         => $end_time_formatted,
					'description'      => $booking['description'] ?? '',
					'status'           => $status,
					'status_label'     => $status_labels[ $status ] ?? $status,
					'is_past'          => $is_past,
					'audiences'        => $booking['audiences'] ?? array(),
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
						'groups'       => array(),
						'joined_count' => 0,
						'max_groups'   => self::MAX_SELF_JOIN_GROUPS,
					)
				);
			}

			$all = \FreeFormCertificate\Audience\AudienceQueryService::find_user_joinable_audiences( $user_id );

			if ( empty( $all ) ) {
				return rest_ensure_response(
					array(
						'parents'      => array(),
						'joined_count' => 0,
						'max_groups'   => self::MAX_SELF_JOIN_GROUPS,
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

			$joined_count = 0;
			$result       = array();
			foreach ( $roots as $root ) {
				$node = $this->build_joinable_node( $root, $joined_count );
				if ( $node ) {
					$result[] = $node;
				}
			}

			return rest_ensure_response(
				array(
					'parents'      => $result,
					'joined_count' => $joined_count,
					'max_groups'   => self::MAX_SELF_JOIN_GROUPS,
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'joinable_groups_error', __( 'Error loading joinable groups', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Build a joinable node recursively, counting joined members.
	 *
	 * @param array<string, mixed> $node  Audience row with 'children' array.
	 * @param int                  $count Reference counter for joined leaf audiences.
	 * @return array<string, mixed>|null  Cleaned node or null if branch is empty.
	 */
	private function build_joinable_node( array $node, int &$count ): ?array {
		$children = array();
		foreach ( $node['children'] as $child ) {
			$built = $this->build_joinable_node( $child, $count );
			if ( $built ) {
				$children[] = $built;
			}
		}

		// Leaf node: include if joinable.
		if ( empty( $children ) && empty( $node['children'] ) ) {
			if ( $node['is_member'] ) {
				++$count;
			}
			return array(
				'id'        => $node['id'],
				'name'      => $node['name'],
				'color'     => $node['color'],
				'is_member' => $node['is_member'],
			);
		}

		// Branch node: only include if it has joinable descendants.
		if ( ! empty( $children ) ) {
			$out = array(
				'id'       => $node['id'],
				'name'     => $node['name'],
				'color'    => $node['color'],
				'children' => $children,
			);
			return $out;
		}

		return null;
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
			$group_id = absint( $request->get_param( 'group_id' ) );

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! $group_id ) {
				return new \WP_Error( 'missing_group', __( 'Group ID is required', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			// Verify group is a child, active, and self-joinable.
			$group = \FreeFormCertificate\Audience\AudienceReader::get_by_id( $group_id );
			if ( ! $group
				|| 'active' !== (string) ( $group->status ?? '' )
				|| 1 !== (int) ( $group->allow_self_join ?? 0 )
				|| null === ( $group->parent_id ?? null )
			) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or does not allow self-join', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			if ( \FreeFormCertificate\Audience\AudienceReader::is_member( $group_id, $user_id ) ) {
				return new \WP_Error( 'already_member', __( 'You are already a member of this group', 'ffcertificate' ), array( 'status' => 409 ) );
			}

			$current_count = \FreeFormCertificate\Audience\AudienceQueryService::count_user_self_join_memberships( $user_id );

			if ( $current_count >= self::MAX_SELF_JOIN_GROUPS ) {
				return new \WP_Error(
					'max_groups_reached',
					/* translators: %d: maximum number of groups */
					sprintf( __( 'You can join a maximum of %d groups. Leave one first.', 'ffcertificate' ), self::MAX_SELF_JOIN_GROUPS ),
					array( 'status' => 422 )
				);
			}

			// Join the group.
			\FreeFormCertificate\Audience\AudienceWriter::add_member( $group_id, $user_id );

			// Grant audience capabilities if needed.
			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				\FreeFormCertificate\UserDashboard\CapabilityManager::grant_audience_capabilities( $user_id );
			}

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
			$group_id = absint( $request->get_param( 'group_id' ) );

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			if ( ! $group_id ) {
				return new \WP_Error( 'missing_group', __( 'Group ID is required', 'ffcertificate' ), array( 'status' => 400 ) );
			}

			$audiences_table = $wpdb->prefix . 'ffc_audiences';
			$members_table   = $wpdb->prefix . 'ffc_audience_members';

			// Verify group is a self-joinable child (can only leave children).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$group = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, name FROM %i WHERE id = %d AND allow_self_join = 1 AND parent_id IS NOT NULL',
					$audiences_table,
					$group_id
				)
			);

			if ( ! $group ) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or cannot be left by user', 'ffcertificate' ), array( 'status' => 404 ) );
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Leave all self-joinable audience groups at once.
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

			// Delete all memberships where the audience allows self-join.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE m FROM %i m INNER JOIN %i a ON a.id = m.audience_id WHERE m.user_id = %d AND a.allow_self_join = 1',
					$members_table,
					$audiences_table,
					$user_id
				)
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'removed' => (int) $deleted,
					'message' => __( 'You left all groups.', 'ffcertificate' ),
				)
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'leave_all_error', __( 'Error leaving groups', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}
}
