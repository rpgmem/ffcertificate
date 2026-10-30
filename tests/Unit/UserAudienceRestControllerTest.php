<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserAudienceRestController;
use FreeFormCertificate\Audience\AudienceFormInvite;

/**
 * Tests for UserAudienceRestController: route registration, permission checks,
 * and every endpoint over the real audience classes.
 *
 * AudienceQueryService, AudienceReader, AudienceWriter, AudienceTreeRules,
 * AudienceSelfJoinLimit, AudiencePostEventPending (with AudiencePostEventForm,
 * AudienceFormInvite and the invite reader and mailer), AudienceBookingRules,
 * BadgeHtml and DateFormatter run for real. The `$wpdb` double below keeps
 * the audiences, memberships and bookings a test stores, answers each query
 * by its SQL text and the values bound to it, and records every membership
 * written or deleted. The controller catches every exception, so a query
 * the double does not know is recorded and fails the test in
 * assertPostConditions() instead of passing as an error response.
 *
 * @covers \FreeFormCertificate\API\UserAudienceRestController
 */
class UserAudienceRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var array<int, array<string, mixed>> Audience rows by id. */
	private array $audiences = array();

	/** @var list<array{id: int, audience_id: int, user_id: int}> Membership rows, oldest first. */
	private array $memberships = array();

	/** @var int The id the next membership row receives. */
	private int $next_membership_id = 1;

	/** @var list<array<string, mixed>> Audience booking rows. */
	private array $bookings = array();

	/** @var array<int, list<int>> Participants by booking id. */
	private array $participants = array();

	/** @var list<array{booking_id: int, name: string, color: string}> The audiences each booking names. */
	private array $booking_audiences = array();

	/** @var bool Whether the audiences table and its allow_self_join column exist. */
	private bool $schema = true;

	/** @var array<string, mixed> Stored options, read through get_option(). */
	private array $options = array();

	/** @var string|null A query fragment the double throws on, to drive the error paths. */
	private ?string $throw_on = null;

	/** @var list<string> Memberships, as "audience:user", whose delete fails. */
	private array $failing_deletes = array();

	/** @var list<array<string, int>> Every membership insert, as its data. */
	private array $inserts = array();

	/** @var list<array<string, int>> Every membership delete, as its where clause. */
	private array $deletes = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var list<string> Queries the double did not recognise. */
	private array $unexpected = array();

	/** @var Mockery\MockInterface Mock $wpdb */
	private $wpdb;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];

		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		// sprintf is a PHP internal — no need to stub it
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// AudienceWriter::add_member() grants the audience capability to the
		// user it reads here; no account answers, so the grant does nothing.
		Functions\when( 'get_userdata' )->justReturn( false );
		// Joining counts the group's upcoming bookings from today (#2243).
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);

		$this->audiences          = array();
		$this->memberships        = array();
		$this->next_membership_id = 1;
		$this->bookings           = array();
		$this->participants       = array();
		$this->booking_audiences  = array();
		$this->schema             = true;
		// DateFormatter reads the plugin settings: none stored, so the default formats.
		$this->options            = array( 'ffc_settings' => array() );
		$this->throw_on           = null;
		$this->failing_deletes    = array();
		$this->inserts            = array();
		$this->deletes            = array();
		$this->prepared           = array();
		$this->unexpected         = array();

		$this->install_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received a query it does not know.' );
	}

	/**
	 * The `$wpdb` double over the arrays above.
	 */
	private function install_wpdb(): void {
		$this->wpdb            = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix    = 'wp_';
		$this->wpdb->insert_id = 0;

		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $sql ) => $this->read_var( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $sql ) => $this->read_row( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $sql ) => $this->read_results( (string) $sql ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $sql ) => $this->read_col( (string) $sql ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing( fn( $sql ) => $this->run_query( (string) $sql ) );
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				if ( 'wp_ffc_audience_members' !== $table ) {
					return $this->unknown( 'insert', (string) $table );
				}
				$this->inserts[]       = $data;
				$id                    = $this->next_membership_id++;
				$this->memberships[]   = array(
					'id'          => $id,
					'audience_id' => (int) $data['audience_id'],
					'user_id'     => (int) $data['user_id'],
				);
				$this->wpdb->insert_id = $id;
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				if ( 'wp_ffc_audience_members' !== $table ) {
					return $this->unknown( 'delete', (string) $table );
				}
				$this->deletes[] = $where;
				if ( in_array( $where['audience_id'] . ':' . $where['user_id'], $this->failing_deletes, true ) ) {
					return false;
				}
				$before            = count( $this->memberships );
				$this->memberships = array_values(
					array_filter(
						$this->memberships,
						static fn( array $m ): bool => ! ( $m['audience_id'] === (int) $where['audience_id'] && $m['user_id'] === (int) $where['user_id'] )
					)
				);
				return $before - count( $this->memberships );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb;
	}

	/**
	 * Record a query the double does not know and stop the code that ran it.
	 *
	 * @return never
	 */
	private function unknown( string $method, string $sql ) {
		$this->unexpected[] = $method . '(): ' . $sql;
		throw new \LogicException( 'Unexpected ' . $method . '(): ' . $sql );
	}

	/**
	 * The prepare() argument list behind a prepared query string, after
	 * throwing when the test asked for this query to fail.
	 *
	 * @return array<int, mixed>
	 */
	private function bound( string $sql ): array {
		if ( null !== $this->throw_on && false !== strpos( $sql, $this->throw_on ) ) {
			throw new \RuntimeException( 'boom' );
		}
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * Every prepare() call whose SQL contains $needle.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function preparedWith( string $needle ): array {
		return array_values( array_filter( $this->prepared, static fn( array $call ): bool => false !== strpos( (string) $call[0], $needle ) ) );
	}

	/**
	 * Whether a user holds a membership of an audience.
	 */
	private function holds( int $audience_id, int $user_id ): bool {
		foreach ( $this->memberships as $membership ) {
			if ( $membership['audience_id'] === $audience_id && $membership['user_id'] === $user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A user's memberships of self-join audiences.
	 *
	 * @return list<array{id: int, audience_id: int, user_id: int}>
	 */
	private function self_join_memberships( int $user_id ): array {
		return array_values(
			array_filter(
				$this->memberships,
				fn( array $m ): bool => $m['user_id'] === $user_id && ! empty( $this->audiences[ $m['audience_id'] ]['allow_self_join'] )
			)
		);
	}

	private function read_var( string $sql ) {
		$bound = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SHOW TABLES LIKE %s' ) ) {
			return $this->schema ? $bound[1] : null;
		}
		if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND user_id = %d' ) ) {
			// AudienceReader::is_member().
			return $this->holds( (int) $bound[2], (int) $bound[3] ) ? '1' : '0';
		}
		if ( false !== strpos( $sql, 'WHERE m.user_id = %d AND a.allow_self_join = 1' ) && false !== strpos( $sql, 'COUNT(*)' ) ) {
			// AudienceQueryService::count_user_self_join_memberships().
			return (string) count( $this->self_join_memberships( (int) $bound[3] ) );
		}
		if ( false !== strpos( $sql, 'INNER JOIN %i ba ON b.id = ba.booking_id AND ba.audience_id = %d' ) ) {
			// AudienceQueryService::count_upcoming_for_audience() and
			// count_upcoming_lost_on_leave() (#2243).
			$group = (int) $bound[3];
			$today = (string) $bound[4];
			$lost  = false !== strpos( $sql, 'NOT EXISTS' );
			$user  = $lost ? (int) $bound[6] : 0;
			$count = 0;
			foreach ( $this->bookings as $booking ) {
				$id      = (int) $booking['id'];
				$groups  = array_map( static fn( array $a ): int => (int) $a['audience_id'], array_filter( $this->booking_audiences, static fn( array $a ): bool => (int) $a['booking_id'] === $id ) );
				$last    = (string) ( $booking['end_date'] ?? '' );
				$last    = '' !== $last ? $last : (string) $booking['booking_date'];
				$reaches = 'active' === ( $booking['status'] ?? 'active' ) && $last >= $today && in_array( $group, $groups, true );
				if ( $reaches && $lost ) {
					$named   = in_array( $user, $this->participants[ $id ] ?? array(), true );
					$through = array_filter( $groups, fn( int $other ): bool => $other !== $group && $this->holds( $other, $user ) );
					$reaches = ! $named && empty( $through );
				}
				$count += $reaches ? 1 : 0;
			}
			return (string) $count;
		}
		return $this->unknown( 'get_var', $sql );
	}

	private function read_row( string $sql ) {
		$bound = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SELECT * FROM %i WHERE id = %d' ) && 'wp_ffc_audiences' === $bound[1] ) {
			// AudienceReader::get_by_id().
			$row = $this->audiences[ (int) $bound[2] ] ?? null;
			return null === $row ? null : (object) $row;
		}
		if ( false !== strpos( $sql, 'SELECT id, name FROM %i WHERE id = %d AND allow_self_join = 1' ) ) {
			$row = $this->audiences[ (int) $bound[2] ] ?? null;
			return null !== $row && ! empty( $row['allow_self_join'] )
				? (object) array(
					'id'   => $row['id'],
					'name' => $row['name'],
				)
				: null;
		}
		return $this->unknown( 'get_row', $sql );
	}

	private function read_results( string $sql ) {
		$bound = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SHOW COLUMNS FROM %i LIKE %s' ) ) {
			return $this->schema ? array( (object) array( 'Field' => 'allow_self_join' ) ) : array();
		}
		if ( false !== strpos( $sql, 'SELECT a.id, a.name, a.color, a.parent_id, a.allow_self_join' ) ) {
			// AudienceQueryService::find_user_joinable_audiences(): active audiences by name.
			$rows = array();
			foreach ( $this->audiences as $audience ) {
				if ( 'active' !== $audience['status'] ) {
					continue;
				}
				$rows[] = array(
					'id'              => (string) $audience['id'],
					'name'            => $audience['name'],
					'color'           => $audience['color'],
					'parent_id'       => null === $audience['parent_id'] ? null : (string) $audience['parent_id'],
					'allow_self_join' => (string) $audience['allow_self_join'],
					'is_member'       => $this->holds( $audience['id'], (int) $bound[3] ) ? '1' : '0',
				);
			}
			usort( $rows, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
			return $rows;
		}
		if ( false !== strpos( $sql, 'SELECT * FROM %i WHERE parent_id = %d' ) && 'wp_ffc_audiences' === $bound[1] ) {
			// AudienceReader::get_children().
			$rows = array();
			foreach ( $this->audiences as $audience ) {
				if ( $audience['parent_id'] === (int) $bound[2] ) {
					$rows[] = (object) $audience;
				}
			}
			return $rows;
		}
		if ( false !== strpos( $sql, 'SELECT a.id, a.name, a.allow_self_join, m.id AS membership_id' ) ) {
			// AudienceTreeRules::memberships(): the user's memberships among the tree's ids, by name.
			$user = (int) $bound[3];
			$ids  = array_map( 'intval', array_slice( $bound, 4 ) );
			$rows = array();
			foreach ( $this->memberships as $membership ) {
				if ( $membership['user_id'] === $user && in_array( $membership['audience_id'], $ids, true ) ) {
					$audience = $this->audiences[ $membership['audience_id'] ];
					$rows[]   = array(
						'id'              => (string) $audience['id'],
						'name'            => $audience['name'],
						'allow_self_join' => (string) $audience['allow_self_join'],
						'membership_id'   => (string) $membership['id'],
					);
				}
			}
			usort( $rows, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
			return $rows;
		}
		if ( false !== strpos( $sql, 'SELECT DISTINCT b.*, e.name as environment_name, s.name as schedule_name' ) ) {
			// AudienceQueryService::find_user_bookings(): the bookings the user takes part in.
			$user = (int) $bound[7];
			return array_values(
				array_filter(
					$this->bookings,
					fn( array $b ): bool => in_array( $user, $this->participants[ (int) $b['id'] ] ?? array(), true )
				)
			);
		}
		if ( false !== strpos( $sql, 'WHERE ba.booking_id IN (' ) ) {
			// The batch load of every listed booking's audiences.
			preg_match( '/IN \(([\d,]+)\)/', $sql, $match );
			$ids = array_map( 'intval', explode( ',', $match[1] ) );
			return array_values(
				array_filter( $this->booking_audiences, static fn( array $a ): bool => in_array( $a['booking_id'], $ids, true ) )
			);
		}
		if ( false !== strpos( $sql, 'WHERE ba.booking_id = %d' ) ) {
			// AudienceBookingReader::get_booking_audiences(): participants are named directly.
			return array();
		}
		if ( false !== strpos( $sql, 'WHERE audience_booking_id = %d' ) ) {
			// AudienceFormInviteReader::answers(): nobody answered yet.
			return array();
		}
		return $this->unknown( 'get_results', $sql );
	}

	private function read_col( string $sql ) {
		$bound = $this->bound( $sql );
		if ( false !== strpos( $sql, 'SELECT m.audience_id FROM %i m' ) ) {
			// AudienceTreeRules::groups_kept_on_leave_all(): the user's self-join groups.
			return array_map( static fn( array $m ): string => (string) $m['audience_id'], $this->self_join_memberships( (int) $bound[3] ) );
		}
		if ( false !== strpos( $sql, 'SELECT user_id FROM %i WHERE booking_id = %d' ) ) {
			return array_map( 'strval', $this->participants[ (int) $bound[2] ] ?? array() );
		}
		return $this->unknown( 'get_col', $sql );
	}

	private function run_query( string $sql ) {
		$bound = $this->bound( $sql );
		if ( false !== strpos( $sql, 'DELETE m FROM %i m INNER JOIN %i a' ) ) {
			// Every self-join membership of the user, except the kept groups.
			$user   = (int) $bound[3];
			$kept   = array_map( 'intval', array_slice( $bound, 4 ) );
			$before = count( $this->memberships );
			$this->memberships = array_values(
				array_filter(
					$this->memberships,
					fn( array $m ): bool => ! ( $m['user_id'] === $user && ! empty( $this->audiences[ $m['audience_id'] ]['allow_self_join'] ) && ! in_array( $m['audience_id'], $kept, true ) )
				)
			);
			return $before - count( $this->memberships );
		}
		return $this->unknown( 'query', $sql );
	}

	/**
	 * Store an audience.
	 *
	 * @param array<string, mixed> $extra Other columns (members_only, require_membership, color, status).
	 */
	private function audience( int $id, string $name, ?int $parent_id, bool $self_join, array $extra = array() ): void {
		$this->audiences[ $id ] = array_merge(
			array(
				'id'                 => $id,
				'name'               => $name,
				'color'              => '',
				'parent_id'          => $parent_id,
				'allow_self_join'    => $self_join ? 1 : 0,
				'status'             => 'active',
				'members_only'       => 0,
				'require_membership' => 0,
			),
			$extra
		);
	}

	/**
	 * Store a membership; memberships stored later have higher ids.
	 */
	private function member( int $audience_id, int $user_id ): void {
		$this->memberships[] = array(
			'id'          => $this->next_membership_id++,
			'audience_id' => $audience_id,
			'user_id'     => $user_id,
		);
	}

	/**
	 * Store a booking user 5 takes part in.
	 *
	 * @param array<string, mixed> $row Booking row.
	 * @param list<int>            $participants Users booked on it.
	 */
	private function stored_booking( array $row, array $participants = array( 5 ) ): void {
		$this->bookings[]                           = $row;
		$this->participants[ (int) $row['id'] ] = $participants;
	}

	/**
	 * Store a booking that names audiences, and users directly.
	 *
	 * @param list<int> $audience_ids Audiences the booking names.
	 * @param list<int> $users        Users named directly.
	 */
	private function group_booking( int $id, string $date, array $audience_ids, ?string $end_date = null, string $status = 'active', array $users = array() ): void {
		$this->stored_booking(
			array(
				'id'           => $id,
				'booking_date' => $date,
				'end_date'     => $end_date,
				'status'       => $status,
			),
			$users
		);
		foreach ( $audience_ids as $audience_id ) {
			$this->booking_audiences[] = array(
				'booking_id'  => $id,
				'audience_id' => $audience_id,
			);
		}
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [] ): object {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		return $request;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_seven_endpoints(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 7, $this->registered_routes );
		$this->assertContains( '/user/audience-group/leave-impact', array_column( $this->registered_routes, 'route' ) );
		$this->assertContains( '/user/audience-group/switch', array_column( $this->registered_routes, 'route' ) );
	}

	public function test_audience_bookings_route_registered(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/audience-bookings', $paths );
	}

	public function test_joinable_groups_route_registered(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/joinable-groups', $paths );
	}

	public function test_leave_all_route_registered(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/audience-group/leave-all', $paths );
	}

	public function test_all_routes_require_authentication(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		foreach ( $this->registered_routes as $route ) {
			$this->assertSame(
				'is_user_logged_in',
				$route['args']['permission_callback'],
				"Route {$route['route']} should require authentication"
			);
		}
	}

	// ------------------------------------------------------------------
	// get_user_audience_bookings()
	// ------------------------------------------------------------------

	public function test_get_user_audience_bookings_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_audience_bookings( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_get_user_audience_bookings_returns_error_when_capability_denied(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_audience_bookings( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'capability_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->preparedWith( 'environment_name' ), 'No booking is read.' );
	}

	public function test_get_user_audience_bookings_returns_empty_when_no_bookings(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_audience_bookings( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertEmpty( $result['bookings'] );
		$this->assertSame( array( 5, 5 ), array_slice( $this->preparedWith( 'environment_name' )[0], 7, 2 ), 'The bookings of user 5 are read.' );
	}

	public function test_get_user_audience_bookings_renders_wall_clock_without_tz_shift(): void {
		// Regression: booking_date/start_time/end_time are wall-clock (Category B)
		// values. On a UTC-3 site they must render literally — a 05/06 09:00–17:00
		// booking must NOT shift to 04/06 06:00–14:00. Drives a real booking row
		// through the controller with a timezone-honouring wp_date stub.
		$prev_tz = date_default_timezone_get(); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_get
		date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( 'wp_timezone' )->alias( static function () {
			return new \DateTimeZone( '-03:00' );
		} );
		Functions\when( 'wp_date' )->alias( static function ( $format, $ts = null, $tz = null ) {
			$ts = null === $ts ? time() : (int) $ts;
			$dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone(
				$tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' )
			);
			return $dt->format( $format );
		} );

		// The service loads each booking's audiences in one batch; this one has none.
		$this->stored_booking(
			array(
				'id'               => 1,
				'booking_date'     => '2026-06-05',
				'start_time'       => '09:00:00',
				'end_time'         => '17:00:00',
				'status'           => 'active',
				'environment_id'   => 2,
				'environment_name' => 'Env',
				'schedule_name'    => 'Sched',
				'description'      => '',
			)
		);

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$result  = $ctrl->get_user_audience_bookings( $this->make_request() );

		date_default_timezone_set( $prev_tz ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['bookings'] );
		$row = $result['bookings'][0];
		$this->assertSame( '05/06/2026', $row['booking_date'], 'date must not shift back a day' );
		$this->assertSame( '09:00', $row['start_time'], 'start time must not shift' );
		$this->assertSame( '17:00', $row['end_time'], 'end time must not shift' );
		$this->assertSame( '2026-06-05', $row['booking_date_raw'] );
		$this->assertTrue( $row['is_past'], '2026-06-05 is before site-local today 2026-06-06' );
		$this->assertSame( array(), $row['audiences'], 'An empty batch load yields an empty list, never an absent key (#1060).' );
	}

	public function test_get_user_audience_bookings_maps_rows_and_audiences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2000-01-01' );
		// DateFormatter helpers return '' → controller falls back to raw
		// value (exercises lines 145 / 153).
		Functions\when( 'wp_date' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->stored_booking(
			array(
				'id'               => 7,
				'booking_date'     => '2099-12-31',
				'start_time'       => '08:00:00',
				'end_time'         => '',
				'status'           => 'cancelled',
				'environment_id'   => 3,
				'environment_name' => 'Room',
				'schedule_name'    => 'Sched',
				'description'      => 'desc',
				'cancellation_reason' => 'Room flooded',
			)
		);
		$this->booking_audiences = array(
			array(
				'booking_id' => 7,
				'name'       => 'Group',
				'color'      => '#fafafa',
			),
			array(
				'booking_id' => 7,
				'name'       => 'Other',
				'color'      => 'red;background:url(x)',
			),
		);

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_user_audience_bookings( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$row = $result['bookings'][0];
		$this->assertSame( 7, $row['id'] );
		// Fallback to raw when formatter returns ''.
		$this->assertSame( '2099-12-31', $row['booking_date'] );
		$this->assertSame( '08:00:00', $row['start_time'] );
		$this->assertSame( 'cancelled', $row['status'] );
		// The canceller's reason reaches the participant (#2243).
		$this->assertSame( 'Room flooded', $row['cancellation_reason'] );
		$this->assertFalse( $row['is_past'], 'future date is not past' );
		// Each audience carries the text colour computed over its own colour
		// (#1826); a value that is not a hex colour never reaches the payload.
		// The batch load reads the name and colour of each audience, no id.
		$this->assertSame(
			array(
				array(
					'name'       => 'Group',
					'color'      => '#fafafa',
					'text_color' => '#000000',
				),
				array(
					'name'       => 'Other',
					'color'      => '#e9ecef',
					'text_color' => '#000000',
				),
			),
			$row['audiences']
		);
	}

	public function test_a_range_running_today_is_upcoming_and_exports_every_booked_day(): void {
		// A range that started yesterday was listed as past, without its export
		// button, and its calendar file held only the first day (#1946).
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( 'wp_date' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->stored_booking(
			array(
				'id'               => 9,
				'booking_date'     => '2026-06-05',
				'end_date'         => '2026-06-08',
				'time_mode'        => 'span',
				'skipped_dates'    => '["2026-06-08"]',
				'start_time'       => '14:00:00',
				'end_time'         => '10:00:00',
				'status'           => 'active',
				'environment_id'   => 2,
				'environment_name' => 'Env',
				'schedule_name'    => 'Sched',
				'description'      => 'Course',
			)
		);

		$row = ( new UserAudienceRestController( 'ffc/v1' ) )->get_user_audience_bookings( $this->make_request() )['bookings'][0];

		$this->assertFalse( $row['is_past'], 'The range runs until 2026-06-08, after today.' );
		$this->assertSame( '2026-06-08', $row['last_date_raw'] );
		$this->assertSame( array( 'date' => '2026-06-05', 'end_date' => '2026-06-07' ), $row['ics'], 'The span ends on its last booked day, not on the skipped one.' );
		$this->assertSame( 'From 14:00 on the first day to 10:00 on the last day', $row['time_label'] );
	}

	/**
	 * A booking row the controller can format.
	 *
	 * @param int                  $id    Booking ID.
	 * @param array<string, mixed> $extra Other columns.
	 * @return array<string, mixed>
	 */
	private function plain_booking( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'               => $id,
				'booking_date'     => '2026-06-01',
				'start_time'       => '08:00:00',
				'end_time'         => '10:00:00',
				'status'           => 'active',
				'environment_id'   => 3,
				'environment_name' => 'Room',
				'schedule_name'    => 'Sched',
				'description'      => 'desc',
			),
			$extra
		);
	}

	/**
	 * A booking that ended two days ago with its own post-event form (form 50,
	 * open for seven days), and the published page that embeds the form.
	 *
	 * @param int $id Booking ID.
	 * @return array<string, mixed>
	 */
	private function booking_with_an_open_form( int $id ): array {
		$form              = new \WP_Post();
		$form->ID          = 50;
		$form->post_type   = 'ffc_form';
		$form->post_status = 'publish';
		Functions\when( 'get_post' )->alias( static fn( $post_id ) => 50 === (int) $post_id ? $form : null );
		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Functions\when( 'get_posts' )->justReturn( array( 300 ) );
		Functions\when( 'get_post_field' )->justReturn( '[ffc_form id="50"]' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.org/form/' );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );

		// Two days back, so the window is open whichever second the test runs at.
		return $this->plain_booking(
			$id,
			array(
				'booking_date'           => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
				'post_event_custom'      => 1,
				'post_event_form_id'     => 50,
				'post_event_window_days' => 7,
			)
		);
	}

	public function test_get_user_audience_bookings_carries_the_post_event_form_waiting_and_counts_it(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		// Booking 7 waits for user 5's answer; booking 8 has no form.
		$waiting = $this->booking_with_an_open_form( 7 );
		$this->stored_booking( $waiting );
		$this->stored_booking( $this->plain_booking( 8 ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_user_audience_bookings( $this->make_request() );

		$closes = ( new \DateTimeImmutable( $waiting['booking_date'] . ' 10:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp() + 7 * DAY_IN_SECONDS;
		$this->assertSame(
			array(
				'form_title'   => 'Evaluation',
				'url'          => 'https://example.org/form/?ffc_invite=' . AudienceFormInvite::code( 7, 5, 50 ),
				'closes_at'    => $closes,
				'closes_label' => gmdate( 'd/m/Y H:i', $closes ),
			),
			$result['bookings'][0]['post_event']
		);
		$this->assertNull( $result['bookings'][1]['post_event'] );
		$this->assertSame( 1, $result['pending_post_event'] );
		$this->assertSame( 'You have 1 certificate form to fill in. Find it under Actions.', $result['pending_label'] );
	}

	public function test_viewing_as_a_user_asks_for_the_post_event_form_without_its_link(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		$this->stored_booking( $this->booking_with_an_open_form( 7 ), array( 9 ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_user_audience_bookings( $this->make_request( array( 'viewAsUserId' => '9' ) ) );

		$this->assertSame( array( 9, 9 ), array_slice( $this->preparedWith( 'environment_name' )[0], 7, 2 ), 'The viewed user\'s bookings are read.' );
		$this->assertSame( 'Evaluation', $result['bookings'][0]['post_event']['form_title'], 'The form waiting for user 9 is shown.' );
		$this->assertSame( '', $result['bookings'][0]['post_event']['url'], 'Someone viewing as the user never gets the user\'s own link.' );
		$this->assertSame( 1, $result['pending_post_event'] );
	}

	public function test_get_user_audience_bookings_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->throw_on = 'SELECT DISTINCT b.*, e.name as environment_name';

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_user_audience_bookings( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'get_audience_bookings_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_joinable_groups()
	// ------------------------------------------------------------------

	public function test_get_joinable_groups_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_joinable_groups( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_get_joinable_groups_returns_empty_when_tables_not_exist(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// table_exists returns false (SHOW TABLES LIKE returns null)
		$this->schema = false;
		$this->audience( 1, 'Solo', null, true );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_joinable_groups( $request );

		$this->assertIsArray( $result );
		// Empty-schema guard now returns the same `parents` key as the other paths.
		$this->assertArrayHasKey( 'parents', $result );
		$this->assertEmpty( $result['parents'] );
		$this->assertSame( 2, $result['max_groups'] );
		$this->assertSame( array(), $this->preparedWith( 'a.allow_self_join,' ), 'The audiences are never read.' );
	}

	public function test_get_joinable_groups_returns_empty_parents_when_no_audiences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Schema guard passes: table + column exist. No active audience is
		// stored, so the service returns an empty list → early empty parents return.
		$this->audience( 1, 'Archived', null, true, array( 'status' => 'inactive' ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'parents', $result );
		$this->assertEmpty( $result['parents'] );
		$this->assertSame( 0, $result['joined_count'] );
		$this->assertSame( 2, $result['max_groups'] );
	}

	public function test_get_joinable_groups_builds_tree_per_node_button_and_header_rules(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Per-node model (#792): the service returns EVERY active audience
		// with its own `allow_self_join` flag; the controller applies the
		// appears/button/header rules.
		// Non-joinable parent — kept only as a header for its joinable child.
		$this->audience( 1, 'Parent', null, false, array( 'color' => '#000' ) );
		// Joinable child (button + member).
		$this->audience( 2, 'Child A', 1, true, array( 'color' => '#111' ) );
		// Non-joinable leaf child → dropped (no button, no children).
		$this->audience( 3, 'Child B', 1, false, array( 'color' => '#222' ) );
		// Standalone joinable top-level group (button + member).
		$this->audience( 4, 'Solo', null, true, array( 'color' => '#333' ) );
		// Non-joinable top-level leaf → dropped entirely.
		$this->audience( 5, 'Hidden', null, false, array( 'color' => '#444' ) );
		$this->member( 2, 5 );
		$this->member( 4, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'parents', $result );
		// Two button-bearing memberships (Child A + Solo); Child B / Hidden carry no button.
		$this->assertSame( 2, $result['joined_count'] );

		// Two top-level nodes appear: the header Parent and the button Solo.
		// Hidden (no button, no children) is filtered out.
		$this->assertCount( 2, $result['parents'] );

		$branch = null;
		$solo   = null;
		foreach ( $result['parents'] as $node ) {
			if ( 1 === $node['id'] ) { $branch = $node; }
			if ( 4 === $node['id'] ) { $solo = $node; }
		}

		// Parent is header-only: appears (for its child) but has no button.
		$this->assertNotNull( $branch );
		$this->assertArrayNotHasKey( 'joinable', $branch );
		$this->assertArrayHasKey( 'children', $branch );
		// Only the joinable Child A survives; Child B is dropped.
		$this->assertCount( 1, $branch['children'] );
		$this->assertSame( 2, $branch['children'][0]['id'] );
		$this->assertTrue( $branch['children'][0]['joinable'] );
		$this->assertTrue( $branch['children'][0]['is_member'] );

		// Solo is a button node with no children.
		$this->assertNotNull( $solo );
		$this->assertTrue( $solo['joinable'] );
		$this->assertTrue( $solo['is_member'] );
		$this->assertArrayNotHasKey( 'children', $solo );
	}

	public function test_get_joinable_groups_node_can_be_both_button_and_parent(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// A joinable parent that ALSO has a joinable child: it must expose
		// BOTH its own button (joinable + is_member) AND the child list.
		$this->audience( 1, 'Both', null, true, array( 'color' => '#000' ) );
		$this->audience( 2, 'Kid', 1, true, array( 'color' => '#111' ) );
		$this->member( 2, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertCount( 1, $result['parents'] );
		$both = $result['parents'][0];
		$this->assertSame( 1, $both['id'] );
		// Own button.
		$this->assertTrue( $both['joinable'] );
		$this->assertFalse( $both['is_member'] );
		// And the nested joinable child.
		$this->assertArrayHasKey( 'children', $both );
		$this->assertCount( 1, $both['children'] );
		$this->assertSame( 2, $both['children'][0]['id'] );
		// Only the child membership counts (parent is not a member).
		$this->assertSame( 1, $result['joined_count'] );
	}

	public function test_get_joinable_groups_descendants_only_parent_is_a_header_over_buttons(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// The "descendants only" shape the split self-join produces (#1896):
		// the parent's own flag off, its children and grandchild on.
		$this->audience( 1, 'Parent', null, false, array( 'color' => '#000' ) );
		$this->audience( 2, 'Child A', 1, true, array( 'color' => '#111' ) );
		$this->audience( 3, 'Child B', 1, true, array( 'color' => '#222' ) );
		$this->audience( 4, 'Grandchild', 2, true, array( 'color' => '#333' ) );
		$this->member( 4, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertCount( 1, $result['parents'] );
		$parent = $result['parents'][0];
		$this->assertSame( 1, $parent['id'] );
		$this->assertArrayNotHasKey( 'joinable', $parent, 'the parent is a header without a button' );

		$children = array_column( $parent['children'], null, 'id' );
		$this->assertSame( array( 2, 3 ), array_keys( $children ) );
		$this->assertTrue( $children[2]['joinable'] );
		$this->assertTrue( $children[3]['joinable'] );
		$this->assertSame( 4, $children[2]['children'][0]['id'] );
		$this->assertTrue( $children[2]['children'][0]['joinable'] );
		$this->assertSame( 1, $result['joined_count'] );
	}

	public function test_get_joinable_groups_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->throw_on = 'SELECT a.id, a.name, a.color, a.parent_id';

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'joinable_groups_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// join_audience_group()
	// ------------------------------------------------------------------

	public function test_join_audience_group_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->join_audience_group( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_join_audience_group_returns_error_when_missing_group_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'group_id' => 0 ) );
		$result  = $ctrl->join_audience_group( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_group', $result->get_error_code() );
	}

	public function test_join_audience_group_returns_error_when_group_invalid(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// No audience 10 is stored → invalid_group.
		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_join_audience_group_returns_error_when_group_not_self_joinable(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Active child but allow_self_join = 0.
		$this->audience( 1, 'Staff', null, false );
		$this->audience( 10, 'G', 1, false );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_join_audience_group_returns_error_when_already_member(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 1, 'Staff', null, false );
		$this->audience( 10, 'G', 1, true );
		$this->member( 10, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'already_member', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_join_audience_group_returns_error_when_max_reached(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 1, 'Staff', null, false );
		$this->audience( 10, 'G', 1, true );
		// Two self-join groups held already: the default limit.
		$this->audience( 30, 'Chess', null, true );
		$this->audience( 31, 'Choir', null, true );
		$this->member( 30, 5 );
		$this->member( 31, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'max_groups_reached', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	/**
	 * Store the self-join limit (#1916); every other option keeps the value
	 * setUp() gives it.
	 *
	 * @param mixed $limit Raw stored value.
	 */
	private function store_self_join_limit( $limit ): void {
		$this->options['ffc_aud_max_self_join_groups'] = $limit;
	}

	public function test_get_joinable_groups_reports_the_configured_limit(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->store_self_join_limit( '5' );
		$this->audience( 1, 'Solo', null, true, array( 'color' => '#333' ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_joinable_groups( $this->make_request() );

		$this->assertSame( 5, $result['max_groups'] );
	}

	/**
	 * @return iterable<string, array{0: mixed, 1: int}>
	 */
	public static function stored_limits(): iterable {
		yield 'in range' => array( '4', 4 );
		yield 'above the ceiling' => array( '99', 20 );
		yield 'below the floor' => array( '0', 1 );
		yield 'negative' => array( -3, 1 );
		yield 'not a number' => array( 'abc', 2 );
	}

	/**
	 * The join is refused exactly at the clamped limit, and allowed one below.
	 *
	 * @dataProvider stored_limits
	 *
	 * @param mixed $stored   Raw stored value.
	 * @param int   $expected The limit it reads as.
	 */
	public function test_join_audience_group_enforces_the_configured_limit( $stored, int $expected ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->store_self_join_limit( $stored );
		$this->audience( 1, 'Staff', null, false );
		$this->audience( 10, 'G', 1, true );
		// Exactly as many self-join groups held as the limit allows.
		for ( $i = 0; $i < $expected; $i++ ) {
			$this->audience( 100 + $i, 'Group ' . $i, null, true );
			$this->member( 100 + $i, 5 );
		}
		$ctrl = new UserAudienceRestController( 'ffc/v1' );

		$refused = $ctrl->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'max_groups_reached', $refused->get_error_code() );
		$this->assertStringContainsString( 'maximum of ' . $expected . ' groups', $refused->get_error_message() );
		$this->assertSame( array(), $this->inserts );

		// One group fewer held: the join goes through.
		array_shift( $this->memberships );
		$allowed = $ctrl->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );
		$this->assertIsArray( $allowed );
		$this->assertTrue( $allowed['success'] );
		$this->assertSame( array( array( 'audience_id' => 10, 'user_id' => 5 ) ), $this->inserts );
	}

	public function test_join_audience_group_succeeds(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 1, 'Staff', null, false );
		$this->audience( 10, 'My Group', 1, true );
		$this->audience( 30, 'Chess', null, true );
		$this->member( 30, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'My Group', $result['message'] );
		$this->assertSame( array( array( 'audience_id' => 10, 'user_id' => 5 ) ), $this->inserts );
	}

	public function test_joining_says_how_many_of_the_groups_bookings_joined_the_list(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		$this->audience( 10, 'My Group', null, true );
		$this->group_booking( 1, '2026-06-10', array( 10 ) );
		$this->group_booking( 2, '2026-06-01', array( 10 ), '2026-06-08' ); // A range still running.
		$this->group_booking( 3, '2026-06-01', array( 10 ) );                // Past.
		$this->group_booking( 4, '2026-06-12', array( 10 ), null, 'cancelled' );
		$this->group_booking( 5, '2026-06-12', array( 11 ) );                // Another group.

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertSame( 2, $result['upcoming'] );
		$this->assertSame( 'You joined "My Group"! Its 2 upcoming bookings are now in your list.', $result['message'] );
	}

	public function test_joining_a_group_with_no_upcoming_booking_says_only_that_they_joined(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );

		$this->audience( 10, 'My Group', null, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertSame( 0, $result['upcoming'] );
		$this->assertSame( 'You joined "My Group"!', $result['message'] );
	}

	public function test_the_leave_impact_counts_only_bookings_reached_through_that_group_alone(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		$this->audience( 10, 'My Group', null, true );
		$this->audience( 20, 'Other', null, true );
		$this->member( 10, 5 );
		$this->member( 20, 5 );
		$this->group_booking( 1, '2026-06-10', array( 10 ) );              // Lost.
		$this->group_booking( 2, '2026-06-10', array( 10, 20 ) );          // Kept: also through Other.
		$this->group_booking( 3, '2026-06-10', array( 10 ), null, 'active', array( 5 ) ); // Kept: named directly.
		$this->group_booking( 4, '2026-06-01', array( 10 ) );              // Past.

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_leave_impact( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertSame( 1, $result['bookings'] );
		$this->assertSame( 'You will no longer see 1 upcoming booking of this group, nor be expected at it.', $result['message'] );
		$this->assertSame( array(), $this->deletes, 'Asking changes nothing.' );
	}

	public function test_the_leave_impact_is_empty_when_nothing_is_lost(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->get_leave_impact( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertSame( array( 'bookings' => 0, 'message' => '' ), $result );
	}

	public function test_the_leave_impact_needs_a_signed_in_user_and_a_group(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$this->assertSame( 'not_logged_in', $ctrl->get_leave_impact( $this->make_request( array( 'group_id' => 10 ) ) )->get_error_code() );

		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->assertSame( 'missing_group', $ctrl->get_leave_impact( $this->make_request() )->get_error_code() );
	}

	public function test_join_audience_group_succeeds_for_a_top_level_self_join_group(): void {
		// Regression: a standalone top-level self-join audience (parent_id NULL)
		// is offered by the joinable-groups list (a "root that is itself a leaf")
		// and must be joinable — join() no longer requires a parent.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 10, 'Solo', null, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Solo', $result['message'] );
		$this->assertSame( array( array( 'audience_id' => 10, 'user_id' => 5 ) ), $this->inserts );
	}

	public function test_join_audience_group_succeeds_for_a_parent_that_also_has_children(): void {
		// Joinability is per-node: a parent whose own allow_self_join is on is
		// joinable in its own right, even though it has children (the children
		// are joinable separately). Having children does NOT block the parent.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 10, 'Parent', null, true );
		$this->audience( 11, 'Child', 10, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array( array( 'audience_id' => 10, 'user_id' => 5 ) ), $this->inserts );
	}

	public function test_join_audience_group_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->throw_on = 'SELECT * FROM %i WHERE id = %d';

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'join_group_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// leave_audience_group()
	// ------------------------------------------------------------------

	public function test_leave_audience_group_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->leave_audience_group( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_leave_audience_group_returns_error_when_missing_group_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'group_id' => 0 ) );
		$result  = $ctrl->leave_audience_group( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_group', $result->get_error_code() );
	}

	public function test_leave_audience_group_returns_error_when_group_invalid(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Audience 10 does not allow self-join, so the lookup finds nothing → invalid_group.
		$this->audience( 10, 'G', null, false );
		$this->member( 10, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_leave_audience_group_returns_error_when_not_member(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// The delete removes no row → not_member.
		$this->audience( 10, 'G', null, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_member', $result->get_error_code() );
	}

	public function test_leave_audience_group_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->throw_on = 'SELECT id, name FROM %i';

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'leave_group_error', $result->get_error_code() );
	}

	public function test_leave_audience_group_succeeds(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->audience( 10, 'My Group', null, true );
		$this->member( 10, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'My Group', $result['message'] );
		$this->assertSame( array( array( 'audience_id' => 10, 'user_id' => 5 ) ), $this->deletes );
		$this->assertFalse( $this->holds( 10, 5 ) );
	}

	public function test_leaving_clears_the_members_cached_audiences(): void {
		// Through AudienceWriter::remove_member(), so the calendars a member may
		// book update at once (#2243). A second when() replaces setUp()'s stub.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$cleared = array();
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key ) use ( &$cleared ): bool {
				$cleared[] = $key;
				return true;
			}
		);

		$this->audience( 10, 'My Group', null, true );
		$this->member( 10, 5 );

		( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertContains( 'user_aud_5_0', $cleared );
		$this->assertContains( 'user_aud_5_1', $cleared );
	}

	public function test_leaving_a_group_the_user_is_not_in_deletes_nothing(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->audience( 10, 'My Group', null, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertSame( 'not_member', $result->get_error_code() );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_leaving_all_clears_the_members_cached_audiences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$cleared = array();
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key ) use ( &$cleared ): bool {
				$cleared[] = $key;
				return true;
			}
		);
		$this->audience( 10, 'My Group', null, true );
		$this->member( 10, 5 );

		( new UserAudienceRestController( 'ffc/v1' ) )->leave_all_audience_groups( $this->make_request() );

		$this->assertContains( 'user_aud_5_0', $cleared );
		$this->assertContains( 'user_aud_5_1', $cleared );
	}

	// ------------------------------------------------------------------
	// leave_all_audience_groups()
	// ------------------------------------------------------------------

	public function test_leave_all_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_all_audience_groups( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_leave_all_succeeds_and_reports_removed_count(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Three self-join groups go; the group an administrator put the user in stays.
		foreach ( array( 21, 22, 23 ) as $id ) {
			$this->audience( $id, 'Open ' . $id, null, true );
			$this->member( $id, 5 );
		}
		$this->audience( 30, 'Assigned', null, false );
		$this->member( 30, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_all_audience_groups( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['removed'] );
		$this->assertTrue( $this->holds( 30, 5 ) );
	}

	public function test_leave_all_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->throw_on = 'DELETE m FROM';

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_all_audience_groups( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'leave_all_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Members-only trees, keep-at-least-one and switch (#1918)
	// ------------------------------------------------------------------

	/**
	 * One tree: 1 (top-level, no button) → 2, 3 (self-join).
	 *
	 * @param bool $members_only       The tree takes only its members.
	 * @param bool $require_membership The tree keeps its members in one group.
	 */
	private function staff_tree( bool $members_only = false, bool $require_membership = false ): void {
		$this->audience(
			1,
			'Staff',
			null,
			false,
			array(
				'members_only'       => $members_only ? 1 : 0,
				'require_membership' => $require_membership ? 1 : 0,
			)
		);
		$this->audience( 2, 'Morning', 1, true );
		$this->audience( 3, 'Evening', 1, true );
	}

	/**
	 * The schema guard passes and user 5 asks for the joinable groups of the
	 * Staff tree.
	 *
	 * @param bool $member_of_2 Whether user 5 holds Morning.
	 */
	private function one_tree( bool $member_of_2, bool $members_only = false, bool $require_membership = false ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->staff_tree( $members_only, $require_membership );
		if ( $member_of_2 ) {
			$this->member( 2, 5 );
		}
	}

	public function test_a_members_only_tree_is_hidden_from_a_non_member(): void {
		$this->one_tree( false, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$this->assertSame( array(), $result['parents'] );
	}

	public function test_a_members_only_tree_shows_to_an_admin_added_member(): void {
		$this->one_tree( false, true );
		// A group without self-join: an administrator put the user there.
		$this->member( 1, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$this->assertCount( 1, $result['parents'] );
		$this->assertSame( 1, $result['parents'][0]['children'][0]['tree_id'] );
		$this->assertSame(
			array( array( 'id' => 1, 'members_only' => true, 'require_membership' => false, 'total' => 1, 'held' => array() ) ),
			$result['trees']
		);
	}

	public function test_the_last_group_of_a_keep_tree_is_locked_in_the_payload(): void {
		$this->one_tree( true, true, true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$children = array_column( $result['parents'][0]['children'], null, 'id' );
		$this->assertTrue( $children[2]['leave_locked'] );
		$this->assertArrayNotHasKey( 'leave_locked', $children[3] );
		$this->assertSame( array( array( 'id' => 2, 'name' => 'Morning' ) ), $result['trees'][0]['held'] );
		$this->assertSame(
			array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 2, 'group_name' => 'Morning' ) ),
			$result['leave_all_keeps']
		);
	}

	public function test_no_group_is_locked_while_another_of_the_tree_is_held(): void {
		$this->one_tree( true, true, true );
		$this->member( 1, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$children = array_column( $result['parents'][0]['children'], null, 'id' );
		$this->assertArrayNotHasKey( 'leave_locked', $children[2] );
	}

	public function test_join_into_a_members_only_tree_by_a_non_member_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->staff_tree( true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->join_audience_group( $this->make_request( array( 'group_id' => 3 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'members_only', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_leaving_the_last_group_of_a_keep_tree_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->staff_tree( true, true );
		$this->member( 2, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_audience_group( $this->make_request( array( 'group_id' => 2 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'keep_one_group', $result->get_error_code() );
		$this->assertSame( array(), $this->deletes );
		$this->assertTrue( $this->holds( 2, 5 ) );
	}

	public function test_leaving_is_allowed_while_another_group_of_the_tree_is_held(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->staff_tree( true, true );
		$this->member( 2, 5 );
		$this->member( 3, 5 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_audience_group( $this->make_request( array( 'group_id' => 2 ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( array( 'audience_id' => 2, 'user_id' => 5 ) ), $this->deletes );
	}

	/**
	 * Runs leave-all as user 5, and returns the response and the arguments
	 * bound to the DELETE.
	 *
	 * @return array{0: mixed, 1: array<int, mixed>, 2: string}
	 */
	private function leave_all(): array {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_all_audience_groups( $this->make_request() );

		$delete = $this->preparedWith( 'DELETE m FROM' );
		$this->assertCount( 1, $delete, 'One DELETE runs.' );
		return array( $result, array_slice( $delete[0], 1 ), (string) $delete[0][0] );
	}

	/**
	 * The Staff tree keeps its members, and user 5 joined Evening before
	 * Morning; three groups of an open tree are held as well.
	 */
	private function a_kept_staff_tree_and_an_open_one(): void {
		$this->staff_tree( true, true );
		$this->member( 3, 5 );
		$this->member( 2, 5 );
		foreach ( array( 21, 22, 23 ) as $id ) {
			$this->audience( $id, 'Open ' . $id, null, true );
			$this->member( $id, 5 );
		}
	}

	/** Mixed trees: one kept tree holds back its first group, the rest goes. */
	public function test_leave_all_keeps_the_first_group_of_a_kept_tree(): void {
		$this->a_kept_staff_tree_and_an_open_one();
		$kept = array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 3, 'group_name' => 'Evening' ) );

		list( $result, $bound, $sql ) = $this->leave_all();

		$this->assertStringContainsString( 'NOT IN (%d)', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5, 3 ), $bound );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 4, $result['removed'] );
		$this->assertSame( $kept, $result['kept'] );
		$this->assertTrue( $this->holds( 3, 5 ) );
	}

	/** Several kept trees: one group held back in each, all excluded at once. */
	public function test_leave_all_keeps_one_group_in_each_kept_tree(): void {
		$this->a_kept_staff_tree_and_an_open_one();
		$this->audience( 9, 'Clubs', null, false, array( 'members_only' => 1, 'require_membership' => 1 ) );
		$this->audience( 10, 'Chess', 9, true );
		$this->member( 10, 5 );
		$kept = array(
			array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 3, 'group_name' => 'Evening' ),
			array( 'tree_id' => 9, 'tree_name' => 'Clubs', 'group_id' => 10, 'group_name' => 'Chess' ),
		);

		list( $result, $bound, $sql ) = $this->leave_all();

		$this->assertStringContainsString( 'NOT IN (%d,%d)', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5, 3, 10 ), $bound );
		$this->assertSame( $kept, $result['kept'] );
	}

	public function test_leave_all_without_a_kept_tree_leaves_everything(): void {
		$this->staff_tree();
		$this->member( 2, 5 );
		$this->member( 3, 5 );

		list( $result, $bound, $sql ) = $this->leave_all();

		$this->assertStringNotContainsString( 'NOT IN', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5 ), $bound );
		$this->assertSame( array(), $result['kept'] );
		$this->assertSame( 'You left all groups.', $result['message'] );
		$this->assertSame( 2, $result['removed'] );
	}

	/** Groups 2, 3 and 6 share top-level 1; group 8 belongs to top-level 7. User 5 holds 2. */
	private function switch_setup( bool $holds_2 = true ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->staff_tree();
		$this->audience( 6, 'Late', 1, true );
		$this->audience( 7, 'Elsewhere', null, false );
		$this->audience( 8, 'Other', 7, true );
		if ( $holds_2 ) {
			$this->member( 2, 5 );
		}
	}

	private function switch_request( int $from, int $to ) {
		return ( new UserAudienceRestController( 'ffc/v1' ) )
			->switch_audience_group( $this->make_request( array( 'from_group_id' => $from, 'group_id' => $to ) ) );
	}

	public function test_switch_joins_the_target_and_leaves_the_current_group(): void {
		$this->switch_setup();

		$result = $this->switch_request( 2, 3 );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Evening', $result['message'] );
		$this->assertSame( array( array( 'audience_id' => 3, 'user_id' => 5 ) ), $this->inserts );
		$this->assertSame( array( array( 'audience_id' => 2, 'user_id' => 5 ) ), $this->deletes );
		$this->assertTrue( $this->holds( 3, 5 ) );
		$this->assertFalse( $this->holds( 2, 5 ) );
	}

	public function test_switch_across_trees_is_refused_even_at_the_limit(): void {
		$this->switch_setup();
		// A second self-join group held: the user is at the default limit.
		$this->audience( 30, 'Chess', null, true );
		$this->member( 30, 5 );

		$result = $this->switch_request( 2, 8 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'different_tree', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_switch_to_a_group_without_self_join_is_refused(): void {
		$this->switch_setup();
		$this->audience( 4, 'Closed', 1, false );

		$result = $this->switch_request( 2, 4 );

		$this->assertSame( 'invalid_group', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_switch_into_a_tree_the_user_may_not_see_is_refused(): void {
		// A members-only Staff tree of which user 5 holds no group.
		$this->switch_setup( false );
		$this->audiences[1]['members_only'] = 1;

		$result = $this->switch_request( 2, 3 );

		$this->assertSame( 'members_only', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_switch_from_a_group_the_user_does_not_hold_is_refused(): void {
		$this->switch_setup();

		$result = $this->switch_request( 6, 3 );

		$this->assertSame( 'not_member', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_switch_needs_two_different_groups(): void {
		$this->switch_setup();

		$this->assertSame( 'missing_group', $this->switch_request( 3, 3 )->get_error_code() );
		$this->assertSame( 'missing_group', $this->switch_request( 0, 3 )->get_error_code() );
	}

	public function test_a_failed_leave_rolls_the_join_back(): void {
		$this->switch_setup();
		$this->failing_deletes = array( '2:5' );

		$this->assertSame( 'switch_group_error', $this->switch_request( 2, 3 )->get_error_code() );
		$this->assertSame( array( array( 'audience_id' => 3, 'user_id' => 5 ) ), $this->inserts );
		$this->assertSame(
			array( array( 'audience_id' => 2, 'user_id' => 5 ), array( 'audience_id' => 3, 'user_id' => 5 ) ),
			$this->deletes,
			'The leave is tried, then the join is undone.'
		);
		$this->assertTrue( $this->holds( 2, 5 ), 'The user stays where they were.' );
		$this->assertFalse( $this->holds( 3, 5 ) );
	}
}
