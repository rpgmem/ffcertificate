<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserAudienceRestController;

/**
 * Tests for UserAudienceRestController: route registration, permission checks,
 * and endpoint callback error paths.
 * @covers \FreeFormCertificate\API\UserAudienceRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserAudienceRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var object Mock $wpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\API\\UserAudienceRestController' );

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
		Functions\when( 'get_option' )->justReturn( 'F j, Y' );
		Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
			return date( $format, $timestamp ?: time() );
		});

		// Alias mocks for static-only dependencies
		$utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		$utils_mock->shouldReceive( 'debug_log' )->byDefault();

		$user_manager_mock = Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\CapabilityManager' );
		$user_manager_mock->shouldReceive( 'grant_audience_capabilities' )->byDefault();

		// UserManager alias so the class_exists() guard in join_audience_group()
		// resolves true and the CapabilityManager grant branch is exercised.
		Mockery::mock( 'alias:\FreeFormCertificate\UserDashboard\UserManager' );

		// Audience data-access dependencies (static). Overridable per-test.
		$this->query_service = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceQueryService' );
		$this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array() )->byDefault();
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( array() )->byDefault();
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 0 )->byDefault();

		$this->reader = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceReader' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
		$this->reader->shouldReceive( 'is_member' )->andReturn( false )->byDefault();

		$this->writer = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceWriter' );
		$this->writer->shouldReceive( 'add_member' )->andReturn( 1 )->byDefault();
		$this->writer->shouldReceive( 'remove_member' )->andReturn( true )->byDefault();

		// Tree access (#1918): open trees by default, each group its own root.
		$this->tree = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceTreeRules' );
		$this->tree->shouldReceive( 'root_id' )->andReturnUsing( static fn( int $id ): int => $id )->byDefault();
		$this->tree->shouldReceive( 'access' )->andReturn( array( 'members_only' => false, 'require_membership' => false ) )->byDefault();
		$this->tree->shouldReceive( 'memberships' )->andReturn( array() )->byDefault();
		$this->tree->shouldReceive( 'can_see' )->andReturn( true )->byDefault();
		$this->tree->shouldReceive( 'is_last_kept_group' )->andReturn( false )->byDefault();
		$this->tree->shouldReceive( 'groups_kept_on_leave_all' )->andReturn( array() )->byDefault();

		// Global $wpdb mock
		$this->wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( '' )->byDefault();
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function( $v ) { return (string) $v; } )->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$this->wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$this->wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/** @var \Mockery\MockInterface AudienceQueryService alias */
	private $query_service;

	/** @var \Mockery\MockInterface AudienceReader alias */
	private $reader;

	/** @var \Mockery\MockInterface AudienceWriter alias */
	private $writer;

	/** @var \Mockery\MockInterface AudienceTreeRules alias */
	private $tree;

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
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

	public function test_register_routes_creates_six_endpoints(): void {
		$ctrl = new UserAudienceRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 6, $this->registered_routes );
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
	}

	public function test_get_user_audience_bookings_returns_empty_when_no_bookings(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_audience_bookings( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertEmpty( $result['bookings'] );
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
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return 'ffc_settings' === $key ? array() : ( false !== $default ? $default : '' );
		} );
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

		$booking_row = array(
			'id'               => 1,
			'booking_date'     => '2026-06-05',
			'start_time'       => '09:00:00',
			'end_time'         => '17:00:00',
			'status'           => 'active',
			'environment_id'   => 2,
			'environment_name' => 'Env',
			'schedule_name'    => 'Sched',
			'description'      => '',
			// The service always sets this key — an empty batch-load yields an
			// empty list, never an absent key — so the fixture has to as well,
			// or it tests a row shape production never produces (#1060).
			'audiences'        => array(),
		);
		// Service returns the single booking row (audience batch-load empty).
		$this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array( $booking_row ) );

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
	}

	public function test_get_user_audience_bookings_maps_rows_and_audiences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2000-01-01' );
		// DateFormatter helpers return '' → controller falls back to raw
		// value (exercises lines 145 / 153).
		Functions\when( 'wp_date' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$booking = array(
			'id'               => 7,
			'booking_date'     => '2099-12-31',
			'start_time'       => '08:00:00',
			'end_time'         => '',
			'status'           => 'cancelled',
			'environment_id'   => 3,
			'environment_name' => 'Room',
			'schedule_name'    => 'Sched',
			'description'      => 'desc',
			'audiences'        => array(
				array(
					'id'    => 1,
					'name'  => 'Group',
					'color' => '#fafafa',
				),
				array(
					'id'    => 2,
					'name'  => 'Other',
					'color' => 'red;background:url(x)',
				),
			),
		);
		$this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array( $booking ) );

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
		$this->assertFalse( $row['is_past'], 'future date is not past' );
		// Each audience carries the text colour computed over its own colour
		// (#1826); a value that is not a hex colour never reaches the payload.
		$this->assertSame(
			array(
				array(
					'id'         => 1,
					'name'       => 'Group',
					'color'      => '#fafafa',
					'text_color' => '#000000',
				),
				array(
					'id'         => 2,
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

		$this->query_service->shouldReceive( 'find_user_bookings' )->andReturn(
			array(
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
					'audiences'        => array(),
				),
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
	 * @param int $id Booking ID.
	 * @return array<string, mixed>
	 */
	private function plain_booking( int $id ): array {
		return array(
			'id'               => $id,
			'booking_date'     => '2026-06-01',
			'start_time'       => '08:00:00',
			'end_time'         => '10:00:00',
			'status'           => 'active',
			'environment_id'   => 3,
			'environment_name' => 'Room',
			'schedule_name'    => 'Sched',
			'description'      => 'desc',
			'audiences'        => array(),
		);
	}

	public function test_get_user_audience_bookings_carries_the_post_event_form_waiting_and_counts_it(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-06-06' );
		Functions\when( 'wp_date' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		$waiting = array(
			'form_title'   => 'Evaluation',
			'url'          => 'https://example.org/form/?ffc_invite=abc',
			'closes_at'    => 1800000000,
			'closes_label' => '2027-01-15 12:00',
		);
		$this->query_service->shouldReceive( 'find_user_bookings' )->andReturn( array( $this->plain_booking( 7 ), $this->plain_booking( 8 ) ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudiencePostEventPending' )
			->shouldReceive( 'for_user' )
			->twice()
			->with( Mockery::type( 'object' ), 5, Mockery::type( 'int' ), true )
			->andReturnUsing( static fn( object $b ) => 7 === (int) $b->id ? $waiting : null );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_user_audience_bookings( $this->make_request() );

		$this->assertSame( $waiting, $result['bookings'][0]['post_event'] );
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
		Functions\when( 'wp_date' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$this->query_service->shouldReceive( 'find_user_bookings' )->with( 9 )->andReturn( array( $this->plain_booking( 7 ) ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudiencePostEventPending' )
			->shouldReceive( 'for_user' )->once()
			->with( Mockery::type( 'object' ), 9, Mockery::type( 'int' ), false )
			->andReturn( null );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_user_audience_bookings( $this->make_request( array( 'viewAsUserId' => '9' ) ) );

		$this->assertSame( 0, $result['pending_post_event'] );
		$this->assertSame( '', $result['pending_label'] );
	}

	public function test_get_user_audience_bookings_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->query_service->shouldReceive( 'find_user_bookings' )
			->andThrow( new \Exception( 'boom' ) );

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
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

		$ctrl    = new UserAudienceRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_joinable_groups( $request );

		$this->assertIsArray( $result );
		// Empty-schema guard now returns the same `parents` key as the other paths.
		$this->assertArrayHasKey( 'parents', $result );
		$this->assertEmpty( $result['parents'] );
		$this->assertSame( 2, $result['max_groups'] );
	}

	public function test_get_joinable_groups_returns_empty_parents_when_no_audiences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Schema guard passes: table + column exist.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

		// Service returns empty flat list → early empty parents return.
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( array() );

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

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

		// Per-node model (#792): the service now returns EVERY active
		// audience with its own `allow_self_join` flag; the controller
		// applies the appears/button/header rules.
		$flat = array(
			// Non-joinable parent — kept only as a header for its joinable child.
			array( 'id' => 1, 'parent_id' => null, 'name' => 'Parent', 'color' => '#000', 'allow_self_join' => false, 'is_member' => false ),
			// Joinable child (button + member).
			array( 'id' => 2, 'parent_id' => 1, 'name' => 'Child A', 'color' => '#111', 'allow_self_join' => true, 'is_member' => true ),
			// Non-joinable leaf child → dropped (no button, no children).
			array( 'id' => 3, 'parent_id' => 1, 'name' => 'Child B', 'color' => '#222', 'allow_self_join' => false, 'is_member' => false ),
			// Standalone joinable top-level group (button + member).
			array( 'id' => 4, 'parent_id' => null, 'name' => 'Solo', 'color' => '#333', 'allow_self_join' => true, 'is_member' => true ),
			// Non-joinable top-level leaf → dropped entirely.
			array( 'id' => 5, 'parent_id' => null, 'name' => 'Hidden', 'color' => '#444', 'allow_self_join' => false, 'is_member' => false ),
		);
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( $flat );

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

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

		// A joinable parent that ALSO has a joinable child: it must expose
		// BOTH its own button (joinable + is_member) AND the child list.
		$flat = array(
			array( 'id' => 1, 'parent_id' => null, 'name' => 'Both', 'color' => '#000', 'allow_self_join' => true, 'is_member' => false ),
			array( 'id' => 2, 'parent_id' => 1, 'name' => 'Kid', 'color' => '#111', 'allow_self_join' => true, 'is_member' => true ),
		);
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( $flat );

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

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );

		// The "descendants only" shape the split self-join produces (#1896):
		// the parent's own flag off, its children and grandchild on.
		$flat = array(
			array( 'id' => 1, 'parent_id' => null, 'name' => 'Parent', 'color' => '#000', 'allow_self_join' => false, 'is_member' => false ),
			array( 'id' => 2, 'parent_id' => 1, 'name' => 'Child A', 'color' => '#111', 'allow_self_join' => true, 'is_member' => false ),
			array( 'id' => 3, 'parent_id' => 1, 'name' => 'Child B', 'color' => '#222', 'allow_self_join' => true, 'is_member' => false ),
			array( 'id' => 4, 'parent_id' => 2, 'name' => 'Grandchild', 'color' => '#333', 'allow_self_join' => true, 'is_member' => true ),
		);
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn( $flat );

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

		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )
			->andThrow( new \Exception( 'boom' ) );

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

		// get_by_id returns null → invalid_group.
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( null );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
	}

	public function test_join_audience_group_returns_error_when_group_not_self_joinable(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Active child but allow_self_join = 0.
		$group = (object) array( 'status' => 'active', 'allow_self_join' => 0, 'parent_id' => 1, 'name' => 'G' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
	}

	public function test_join_audience_group_returns_error_when_already_member(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'G' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( true );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'already_member', $result->get_error_code() );
	}

	public function test_join_audience_group_returns_error_when_max_reached(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'G' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( false );
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 2 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'max_groups_reached', $result->get_error_code() );
	}

	/**
	 * Stub the stored self-join limit (#1916); every other option keeps the
	 * value setUp() gives it.
	 *
	 * @param mixed $limit Raw stored value.
	 */
	private function store_self_join_limit( $limit ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_aud_max_self_join_groups' === $key ? $limit : 'F j, Y'
		);
	}

	public function test_get_joinable_groups_reports_the_configured_limit(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->store_self_join_limit( '5' );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn(
			array(
				array( 'id' => 1, 'parent_id' => null, 'name' => 'Solo', 'color' => '#333', 'allow_self_join' => true, 'is_member' => false ),
			)
		);

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
		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'G' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( false );
		$count = $expected;
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturnUsing(
			static function () use ( &$count ) {
				return $count;
			}
		);
		$ctrl = new UserAudienceRestController( 'ffc/v1' );

		$refused = $ctrl->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'max_groups_reached', $refused->get_error_code() );
		$this->assertStringContainsString( 'maximum of ' . $expected . ' groups', $refused->get_error_message() );

		$count   = $expected - 1;
		$allowed = $ctrl->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );
		$this->assertIsArray( $allowed );
		$this->assertTrue( $allowed['success'] );
	}

	public function test_join_audience_group_succeeds(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => 1, 'name' => 'My Group' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( false );
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 1 );
		$this->writer->shouldReceive( 'add_member' )->once()->with( 10, 5 )->andReturn( 1 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'My Group', $result['message'] );
	}

	public function test_join_audience_group_succeeds_for_a_top_level_self_join_group(): void {
		// Regression: a standalone top-level self-join audience (parent_id NULL)
		// is offered by the joinable-groups list (a "root that is itself a leaf")
		// and must be joinable — join() no longer requires a parent.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => null, 'name' => 'Solo' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( false );
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 0 );
		$this->writer->shouldReceive( 'add_member' )->once()->with( 10, 5 )->andReturn( 1 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Solo', $result['message'] );
	}

	public function test_join_audience_group_succeeds_for_a_parent_that_also_has_children(): void {
		// Joinability is per-node: a parent whose own allow_self_join is on is
		// joinable in its own right, even though it has children (the children
		// are joinable separately). Having children does NOT block the parent.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$group = (object) array( 'status' => 'active', 'allow_self_join' => 1, 'parent_id' => null, 'name' => 'Parent' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( $group );
		$this->reader->shouldReceive( 'is_member' )->andReturn( false );
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 0 );
		$this->writer->shouldReceive( 'add_member' )->once()->with( 10, 5 )->andReturn( 1 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->join_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	public function test_join_audience_group_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->reader->shouldReceive( 'get_by_id' )->andThrow( new \Exception( 'boom' ) );

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

		// get_row returns null → invalid_group.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( null );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_group', $result->get_error_code() );
	}

	public function test_leave_audience_group_returns_error_when_not_member(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 10, 'name' => 'G' ) );
		// delete returns 0 rows → not_member.
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 0 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_member', $result->get_error_code() );
	}

	public function test_leave_audience_group_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->wpdb->shouldReceive( 'get_row' )->andThrow( new \Exception( 'boom' ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'leave_group_error', $result->get_error_code() );
	}

	public function test_leave_audience_group_succeeds(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 10, 'name' => 'My Group' ) );
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 1 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_audience_group( $this->make_request( array( 'group_id' => 10 ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'My Group', $result['message'] );
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

		$this->wpdb->shouldReceive( 'query' )->andReturn( 3 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_all_audience_groups( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['removed'] );
	}

	public function test_leave_all_returns_500_on_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->wpdb->shouldReceive( 'query' )->andThrow( new \Exception( 'boom' ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )
			->leave_all_audience_groups( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'leave_all_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Members-only trees, keep-at-least-one and switch (#1918)
	// ------------------------------------------------------------------

	/**
	 * The schema guard passes and the service answers with one tree:
	 * 1 (top-level, no button) → 2, 3 (self-join).
	 */
	private function one_tree( bool $member_of_2 ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 'wp_ffc_audiences' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array( (object) array( 'Field' => 'allow_self_join' ) ) );
		$this->query_service->shouldReceive( 'find_user_joinable_audiences' )->andReturn(
			array(
				array( 'id' => 1, 'parent_id' => null, 'name' => 'Staff', 'color' => '', 'allow_self_join' => false, 'is_member' => false ),
				array( 'id' => 2, 'parent_id' => 1, 'name' => 'Morning', 'color' => '', 'allow_self_join' => true, 'is_member' => $member_of_2 ),
				array( 'id' => 3, 'parent_id' => 1, 'name' => 'Evening', 'color' => '', 'allow_self_join' => true, 'is_member' => false ),
			)
		);
		$this->tree->shouldReceive( 'root_id' )->andReturn( 1 );
	}

	public function test_a_members_only_tree_is_hidden_from_a_non_member(): void {
		$this->one_tree( false );
		$this->tree->shouldReceive( 'access' )->with( 1 )->andReturn( array( 'members_only' => true, 'require_membership' => false ) );
		$this->tree->shouldReceive( 'memberships' )->with( 1, 5 )->andReturn( array() );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$this->assertSame( array(), $result['parents'] );
	}

	public function test_a_members_only_tree_shows_to_an_admin_added_member(): void {
		$this->one_tree( false );
		$this->tree->shouldReceive( 'access' )->with( 1 )->andReturn( array( 'members_only' => true, 'require_membership' => false ) );
		// A group without self-join: an administrator put the user there.
		$this->tree->shouldReceive( 'memberships' )->with( 1, 5 )->andReturn( array( array( 'id' => 1, 'name' => 'Staff', 'self_join' => false ) ) );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$this->assertCount( 1, $result['parents'] );
		$this->assertSame( 1, $result['parents'][0]['children'][0]['tree_id'] );
		$this->assertSame(
			array( array( 'id' => 1, 'members_only' => true, 'require_membership' => false, 'total' => 1, 'held' => array() ) ),
			$result['trees']
		);
	}

	public function test_the_last_group_of_a_keep_tree_is_locked_in_the_payload(): void {
		$this->one_tree( true );
		$this->tree->shouldReceive( 'access' )->with( 1 )->andReturn( array( 'members_only' => true, 'require_membership' => true ) );
		$this->tree->shouldReceive( 'memberships' )->with( 1, 5 )->andReturn( array( array( 'id' => 2, 'name' => 'Morning', 'self_join' => true ) ) );
		$keeps = array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 2, 'group_name' => 'Morning' ) );
		$this->tree->shouldReceive( 'groups_kept_on_leave_all' )->with( 5 )->andReturn( $keeps );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$children = $result['parents'][0]['children'];
		$this->assertTrue( $children[0]['leave_locked'] );
		$this->assertArrayNotHasKey( 'leave_locked', $children[1] );
		$this->assertSame( array( array( 'id' => 2, 'name' => 'Morning' ) ), $result['trees'][0]['held'] );
		$this->assertSame( $keeps, $result['leave_all_keeps'] );
	}

	public function test_no_group_is_locked_while_another_of_the_tree_is_held(): void {
		$this->one_tree( true );
		$this->tree->shouldReceive( 'access' )->with( 1 )->andReturn( array( 'members_only' => true, 'require_membership' => true ) );
		$this->tree->shouldReceive( 'memberships' )->with( 1, 5 )->andReturn(
			array(
				array( 'id' => 1, 'name' => 'Staff', 'self_join' => false ),
				array( 'id' => 2, 'name' => 'Morning', 'self_join' => true ),
			)
		);

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->get_joinable_groups( $this->make_request() );

		$this->assertArrayNotHasKey( 'leave_locked', $result['parents'][0]['children'][0] );
	}

	private function self_join_group( int $id, string $name ): object {
		return (object) array( 'id' => $id, 'name' => $name, 'status' => 'active', 'allow_self_join' => '1' );
	}

	public function test_join_into_a_members_only_tree_by_a_non_member_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->reader->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $this->self_join_group( 3, 'Evening' ) );
		$this->tree->shouldReceive( 'can_see' )->andReturn( false );
		$this->writer->shouldNotReceive( 'add_member' );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->join_audience_group( $this->make_request( array( 'group_id' => 3 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'members_only', $result->get_error_code() );
	}

	public function test_leaving_the_last_group_of_a_keep_tree_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 2, 'name' => 'Morning' ) );
		$this->tree->shouldReceive( 'is_last_kept_group' )->with( 2, 5 )->andReturn( true );
		$this->wpdb->shouldNotReceive( 'delete' );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_audience_group( $this->make_request( array( 'group_id' => 2 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'keep_one_group', $result->get_error_code() );
	}

	public function test_leaving_is_allowed_while_another_group_of_the_tree_is_held(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( (object) array( 'id' => 2, 'name' => 'Morning' ) );
		$this->tree->shouldReceive( 'is_last_kept_group' )->with( 2, 5 )->andReturn( false );
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_audience_group( $this->make_request( array( 'group_id' => 2 ) ) );

		$this->assertTrue( $result['success'] );
	}

	/**
	 * Runs leave-all with what the rules keep, and returns the response and
	 * the arguments bound to the DELETE.
	 *
	 * @param list<array<string, mixed>> $kept What groups_kept_on_leave_all() answers.
	 * @return array{0: mixed, 1: array<int, mixed>, 2: string}
	 */
	private function leave_all_with( array $kept ): array {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->tree->shouldReceive( 'groups_kept_on_leave_all' )->with( 5 )->andReturn( $kept );

		$bound = array();
		$sql   = '';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $query, $args = array() ) use ( &$bound, &$sql ) {
				$sql   = (string) $query;
				$bound = is_array( $args ) ? $args : array();
				return 'DELETE';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'DELETE' )->andReturn( 4 );

		$result = ( new UserAudienceRestController( 'ffc/v1' ) )->leave_all_audience_groups( $this->make_request() );
		return array( $result, $bound, $sql );
	}

	/** Mixed trees: one kept tree holds back its first group, the rest goes. */
	public function test_leave_all_keeps_the_first_group_of_a_kept_tree(): void {
		$kept = array( array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 3, 'group_name' => 'Evening' ) );

		list( $result, $bound, $sql ) = $this->leave_all_with( $kept );

		$this->assertStringContainsString( 'NOT IN (%d)', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5, 3 ), $bound );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 4, $result['removed'] );
		$this->assertSame( $kept, $result['kept'] );
	}

	/** Several kept trees: one group held back in each, all excluded at once. */
	public function test_leave_all_keeps_one_group_in_each_kept_tree(): void {
		$kept = array(
			array( 'tree_id' => 1, 'tree_name' => 'Staff', 'group_id' => 3, 'group_name' => 'Evening' ),
			array( 'tree_id' => 9, 'tree_name' => 'Clubs', 'group_id' => 10, 'group_name' => 'Chess' ),
		);

		list( $result, $bound, $sql ) = $this->leave_all_with( $kept );

		$this->assertStringContainsString( 'NOT IN (%d,%d)', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5, 3, 10 ), $bound );
		$this->assertSame( $kept, $result['kept'] );
	}

	public function test_leave_all_without_a_kept_tree_leaves_everything(): void {
		list( $result, $bound, $sql ) = $this->leave_all_with( array() );

		$this->assertStringNotContainsString( 'NOT IN', $sql );
		$this->assertSame( array( 'wp_ffc_audience_members', 'wp_ffc_audiences', 5 ), $bound );
		$this->assertSame( array(), $result['kept'] );
		$this->assertSame( 'You left all groups.', $result['message'] );
	}

	/** Groups 2 and 3 share top-level 1; group 8 belongs to top-level 7. */
	private function switch_setup(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->reader->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( $this->self_join_group( 2, 'Morning' ) );
		$this->reader->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $this->self_join_group( 3, 'Evening' ) );
		$this->reader->shouldReceive( 'get_by_id' )->with( 8 )->andReturn( $this->self_join_group( 8, 'Other' ) );
		$this->reader->shouldReceive( 'is_member' )->with( 2, 5 )->andReturn( true );
		$this->reader->shouldReceive( 'is_member' )->with( 3, 5 )->andReturn( false );
		$this->reader->shouldReceive( 'is_member' )->with( 8, 5 )->andReturn( false );
		$this->tree->shouldReceive( 'root_id' )->andReturnUsing( static fn( int $id ): int => 8 === $id ? 7 : 1 );
	}

	private function switch_request( int $from, int $to ) {
		return ( new UserAudienceRestController( 'ffc/v1' ) )
			->switch_audience_group( $this->make_request( array( 'from_group_id' => $from, 'group_id' => $to ) ) );
	}

	public function test_switch_joins_the_target_and_leaves_the_current_group(): void {
		$this->switch_setup();
		$this->writer->shouldReceive( 'add_member' )->once()->with( 3, 5 )->andReturn( 1 );
		$this->writer->shouldReceive( 'remove_member' )->once()->with( 2, 5 )->andReturn( true );

		$result = $this->switch_request( 2, 3 );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Evening', $result['message'] );
	}

	public function test_switch_across_trees_is_refused_even_at_the_limit(): void {
		$this->switch_setup();
		$this->query_service->shouldReceive( 'count_user_self_join_memberships' )->andReturn( 2 );
		$this->writer->shouldNotReceive( 'add_member' );
		$this->writer->shouldNotReceive( 'remove_member' );

		$result = $this->switch_request( 2, 8 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'different_tree', $result->get_error_code() );
	}

	public function test_switch_to_a_group_without_self_join_is_refused(): void {
		$this->switch_setup();
		$this->reader->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4, 'name' => 'Closed', 'status' => 'active', 'allow_self_join' => '0' ) );
		$this->writer->shouldNotReceive( 'add_member' );

		$result = $this->switch_request( 2, 4 );

		$this->assertSame( 'invalid_group', $result->get_error_code() );
	}

	public function test_switch_into_a_tree_the_user_may_not_see_is_refused(): void {
		$this->switch_setup();
		$this->tree->shouldReceive( 'can_see' )->with( 1, 5 )->andReturn( false );
		$this->writer->shouldNotReceive( 'add_member' );

		$result = $this->switch_request( 2, 3 );

		$this->assertSame( 'members_only', $result->get_error_code() );
	}

	public function test_switch_from_a_group_the_user_does_not_hold_is_refused(): void {
		$this->switch_setup();
		$this->reader->shouldReceive( 'get_by_id' )->with( 6 )->andReturn( $this->self_join_group( 6, 'Late' ) );
		$this->reader->shouldReceive( 'is_member' )->with( 6, 5 )->andReturn( false );
		$this->writer->shouldNotReceive( 'add_member' );

		$result = $this->switch_request( 6, 3 );

		$this->assertSame( 'not_member', $result->get_error_code() );
	}

	public function test_switch_needs_two_different_groups(): void {
		$this->switch_setup();

		$this->assertSame( 'missing_group', $this->switch_request( 3, 3 )->get_error_code() );
		$this->assertSame( 'missing_group', $this->switch_request( 0, 3 )->get_error_code() );
	}

	public function test_a_failed_leave_rolls_the_join_back(): void {
		$this->switch_setup();
		$this->writer->shouldReceive( 'add_member' )->once()->with( 3, 5 )->andReturn( 1 );
		$this->writer->shouldReceive( 'remove_member' )->once()->with( 2, 5 )->andReturn( false );
		$this->writer->shouldReceive( 'remove_member' )->once()->with( 3, 5 )->andReturn( true );

		$this->assertSame( 'switch_group_error', $this->switch_request( 2, 3 )->get_error_code() );
	}
}
