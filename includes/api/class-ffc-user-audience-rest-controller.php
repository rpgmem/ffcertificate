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
	 */
	private string $namespace;

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
	 * @param \WP_REST_Request $request
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

			if ( ! $this->user_has_capability( 'ffc_view_audience_bookings', $user_id, $ctx['is_view_as'] ) ) {
				return new \WP_Error(
					'capability_denied',
					__( 'You do not have permission to view audience bookings', 'ffcertificate' ),
					array( 'status' => 403 )
				);
			}

			global $wpdb;

			$date_format = get_option( 'date_format', 'F j, Y' );

			$bookings_table          = $wpdb->prefix . 'ffc_audience_bookings';
			$users_table             = $wpdb->prefix . 'ffc_audience_booking_users';
			$booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
			$members_table           = $wpdb->prefix . 'ffc_audience_members';
			$audience_names_table    = $wpdb->prefix . 'ffc_audiences';
			$environments_table      = $wpdb->prefix . 'ffc_audience_environments';
			$schedules_table         = $wpdb->prefix . 'ffc_audience_schedules';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$bookings = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT b.*, e.name as environment_name, s.name as schedule_name
                 FROM %i b
                 LEFT JOIN %i bu ON b.id = bu.booking_id
                 LEFT JOIN %i ba ON b.id = ba.booking_id
                 LEFT JOIN %i am ON ba.audience_id = am.audience_id
                 LEFT JOIN %i e ON b.environment_id = e.id
                 LEFT JOIN %i s ON e.schedule_id = s.id
                 WHERE (bu.user_id = %d OR am.user_id = %d)
                 ORDER BY b.booking_date DESC, b.start_time DESC',
					$bookings_table,
					$users_table,
					$booking_audiences_table,
					$members_table,
					$environments_table,
					$schedules_table,
					$user_id,
					$user_id
				),
				ARRAY_A
			);

			if ( ! is_array( $bookings ) ) {
				$bookings = array();
			}

			// Batch load audiences for all bookings to avoid N+1 queries.
			$audiences_map = array();
			$booking_ids   = array_filter(
				array_map(
					function ( $b ) {
						return (int) ( $b['id'] ?? 0 );
					},
					$bookings
				)
			);

			if ( ! empty( $booking_ids ) ) {
				$safe_ids = array_map( 'absint', $booking_ids );
				$id_list  = implode( ',', $safe_ids );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ID list built from absint() values.
				$all_audiences = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ba.booking_id, a.name, a.color
                     FROM %i ba
                     INNER JOIN %i a ON ba.audience_id = a.id
                     WHERE ba.booking_id IN ({$id_list})",
						$booking_audiences_table,
						$audience_names_table
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( is_array( $all_audiences ) ) {
					foreach ( $all_audiences as $aud ) {
						$audiences_map[ (int) $aud['booking_id'] ][] = array(
							'name'  => $aud['name'],
							'color' => $aud['color'] ?? '#2271b1',
						);
					}
				}
			}

			$bookings_formatted = array();

			foreach ( $bookings as $booking ) {
				$date_formatted = '';
				if ( ! empty( $booking['booking_date'] ) ) {
					$timestamp      = strtotime( $booking['booking_date'] );
					$date_formatted = ( false !== $timestamp ) ? date_i18n( $date_format, $timestamp ) : $booking['booking_date'];
				}

				$time_formatted = '';
				if ( ! empty( $booking['start_time'] ) ) {
					$time_timestamp = strtotime( $booking['start_time'] );
					$time_formatted = ( false !== $time_timestamp ) ? date_i18n( 'H:i', $time_timestamp ) : $booking['start_time'];
				}

				$end_time_formatted = '';
				if ( ! empty( $booking['end_time'] ) ) {
					$end_timestamp      = strtotime( $booking['end_time'] );
					$end_time_formatted = ( false !== $end_timestamp ) ? date_i18n( 'H:i', $end_timestamp ) : '';
				}

				$status_labels = array(
					'active'    => __( 'Confirmed', 'ffcertificate' ),
					'cancelled' => __( 'Cancelled', 'ffcertificate' ),
				);

				$status  = $booking['status'] ?? 'active';
				$is_past = strtotime( $booking['booking_date'] ) < strtotime( 'today' );

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
					'audiences'        => $audiences_map[ (int) $booking['id'] ] ?? array(),
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
				\FreeFormCertificate\Core\Utils::debug_log(
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
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_joinable_groups( $request ) {
		try {
			global $wpdb;
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			if ( ! $user_id ) {
				return new \WP_Error( 'not_logged_in', __( 'You must be logged in', 'ffcertificate' ), array( 'status' => 401 ) );
			}

			$audiences_table = $wpdb->prefix . 'ffc_audiences';
			$members_table   = $wpdb->prefix . 'ffc_audience_members';

			// Check tables and columns exist.
			if ( ! self::table_exists( $audiences_table ) || ! self::column_exists( $audiences_table, 'allow_self_join' ) ) {
				return rest_ensure_response(
					array(
						'groups'       => array(),
						'joined_count' => 0,
						'max_groups'   => self::MAX_SELF_JOIN_GROUPS,
					)
				);
			}

			// Fetch all joinable audiences at once (with membership status).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all = $wpdb->get_results(
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

			if ( empty( $all ) ) {
				return rest_ensure_response(
					array(
						'parents'      => array(),
						'joined_count' => 0,
						'max_groups'   => self::MAX_SELF_JOIN_GROUPS,
					)
				);
			}

			// Build tree in PHP.
			$by_id        = array();
			$joined_count = 0;
			foreach ( $all as &$row ) {
				$row['id']           = (int) $row['id'];
				$row['parent_id']    = $row['parent_id'] ? (int) $row['parent_id'] : null;
				$row['is_member']    = (bool) (int) $row['is_member'];
				$row['children']     = array();
				$by_id[ $row['id'] ] = &$row;
			}
			unset( $row );

			$roots = array();
			foreach ( $by_id as &$item ) {
				if ( $item['parent_id'] && isset( $by_id[ $item['parent_id'] ] ) ) {
					$by_id[ $item['parent_id'] ]['children'][] = &$item;
				} else {
					$roots[] = &$item;
				}
			}
			unset( $item );

			// Count joined leaf audiences and strip parent_id from output.
			$result = array();
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
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function join_audience_group( $request ) {
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

			// Verify group is a child, active, and allows self-join (only children can be joined).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$group = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM %i WHERE id = %d AND status = 'active' AND allow_self_join = 1 AND parent_id IS NOT NULL",
					$audiences_table,
					$group_id
				)
			);

			if ( ! $group ) {
				return new \WP_Error( 'invalid_group', __( 'Group not found or does not allow self-join', 'ffcertificate' ), array( 'status' => 404 ) );
			}

			// Check already member.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$already = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND user_id = %d',
					$members_table,
					$group_id,
					$user_id
				)
			);

			if ( $already ) {
				return new \WP_Error( 'already_member', __( 'You are already a member of this group', 'ffcertificate' ), array( 'status' => 409 ) );
			}

			// Count current self-join memberships (only children count).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i m
                 INNER JOIN %i a ON a.id = m.audience_id
                 WHERE m.user_id = %d AND a.allow_self_join = 1 AND a.parent_id IS NOT NULL',
					$members_table,
					$audiences_table,
					$user_id
				)
			);

			if ( $current_count >= self::MAX_SELF_JOIN_GROUPS ) {
				return new \WP_Error(
					'max_groups_reached',
					/* translators: %d: maximum number of groups */
					sprintf( __( 'You can join a maximum of %d groups. Leave one first.', 'ffcertificate' ), self::MAX_SELF_JOIN_GROUPS ),
					array( 'status' => 422 )
				);
			}

			// Join the group.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$members_table,
				array(
					'audience_id' => $group_id,
					'user_id'     => $user_id,
				),
				array( '%d', '%d' )
			);

			// Grant audience capabilities if needed.
			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				\FreeFormCertificate\UserDashboard\UserManager::grant_audience_capabilities( $user_id );
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
	 * @param \WP_REST_Request $request
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
	 * @param \WP_REST_Request $request
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
