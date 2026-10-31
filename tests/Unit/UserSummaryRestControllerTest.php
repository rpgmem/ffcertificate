<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserSummaryRestController;

/**
 * Tests for UserSummaryRestController: route registration, summary data,
 * capability gating, and exception handling.
 *
 * Runs the real AudienceQueryService, ReregistrationFrontend (through
 * ReregistrationRepository and AudienceReader), AppointmentRepository,
 * CalendarRepository and DateFormatter over a `$wpdb` double. Every
 * `prepare()` is recorded and answered with a token, so `get_var()` /
 * `get_row()` / `get_results()` dispatch on the SQL and arguments behind it.
 */
class UserSummaryRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var Mockery\MockInterface The `$wpdb` double. */
	private $wpdb;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = [];

	/**
	 * Tables that `SHOW TABLES LIKE` reports as present.
	 *
	 * @var list<string>
	 */
	private array $tables = [];

	/** What the certificate COUNT(*) on the submissions table answers. */
	private ?string $certificate_count = '0';

	/** What the audience booking COUNT(DISTINCT) answers. */
	private ?string $booking_count = '0';

	/** The next upcoming appointment row, or null for none. */
	private ?array $appointment_row = null;

	/** The calendar row findById() reads, or null for none. */
	private ?array $calendar_row = null;

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];
		$this->prepared          = [];
		$this->tables            = [];
		$this->certificate_count = '0';
		$this->booking_count     = '0';
		$this->appointment_row   = null;
		$this->calendar_row      = null;

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
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
			return date( $format, $timestamp ?: time() );
		});
		Functions\when( 'wp_date' )->alias( function( $format, $timestamp = null, $tz = null ) {
			return date( $format, $timestamp ?? time() );
		});
		Functions\when( 'wp_timezone' )->alias( function() {
			return new \DateTimeZone( 'UTC' );
		});
		Functions\when( 'current_time' )->justReturn( '2026-08-09' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		$this->wpdb         = Mockery::mock( 'wpdb' );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
					return in_array( $args[0], $this->tables, true ) ? $args[0] : null;
				}
				if ( false !== strpos( $sql, 'COUNT(DISTINCT b.id)' ) ) {
					return $this->booking_count;
				}
				if ( false !== strpos( $sql, 'COUNT(*)' ) && 'wp_ffc_submissions' === ( $args[0] ?? null ) ) {
					return $this->certificate_count;
				}
				return null;
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'appointment_date >= %s' ) ) {
					return $this->appointment_row;
				}
				if ( false !== strpos( $sql, 'WHERE id = %d' ) && 'wp_ffc_self_scheduling_calendars' === ( $args[0] ?? null ) ) {
					return $this->calendar_row;
				}
				return null;
			}
		);
		// The user's audiences (and anything else listed) are empty, so the
		// real ReregistrationFrontend finds no active campaign.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		return $this->prepared[ $index ] ?? array( (string) $query, array() );
	}

	/**
	 * Prepared statements whose SQL matches a pattern.
	 *
	 * @param string $pattern Regular expression.
	 * @return list<array{0: string, 1: array<int, mixed>}>
	 */
	private function prepared_matching( string $pattern ): array {
		return array_values(
			array_filter(
				$this->prepared,
				static fn( array $p ) => 1 === preg_match( $pattern, $p[0] )
			)
		);
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

	public function test_register_routes_creates_one_endpoint(): void {
		$ctrl = new UserSummaryRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 1, $this->registered_routes );
	}

	public function test_summary_route_registered(): void {
		$ctrl = new UserSummaryRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame( '/user/summary', $this->registered_routes[0]['route'] );
	}

	public function test_summary_route_requires_authentication(): void {
		$ctrl = new UserSummaryRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame(
			'rest_permission',
			$this->registered_routes[0]['args']['permission_callback'][1]
		);
	}

	// ------------------------------------------------------------------
	// get_user_summary() — no capabilities
	// ------------------------------------------------------------------

	public function test_get_user_summary_returns_all_zeros_when_no_capabilities(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_certificates'] );
		$this->assertNull( $result['next_appointment'] );
		$this->assertSame( 0, $result['upcoming_group_events'] );
		$this->assertSame( 0, $result['pending_reregistrations'] );
		// The reregistration count asked for the user's audiences, found none.
		$audience_reads = $this->prepared_matching( '/m\.user_id = %d/' );
		$this->assertSame( 5, $audience_reads[0][1][2] ?? null );
	}

	public function test_get_user_summary_returns_zeros_even_when_user_id_is_zero(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		// The method does NOT return WP_Error for user_id=0; it proceeds with defaults
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_certificates'] );
		$this->assertNull( $result['next_appointment'] );
	}

	// ------------------------------------------------------------------
	// get_user_summary() — exception path
	// ------------------------------------------------------------------

	public function test_get_user_summary_returns_defaults_on_exception(): void {
		// Make get_current_user_id throw to trigger the catch block
		Functions\when( 'get_current_user_id' )->alias( function() {
			throw new \Exception( 'Simulated failure' );
		});

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		// On exception, returns defaults (NOT WP_Error)
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_certificates'] );
		$this->assertNull( $result['next_appointment'] );
		$this->assertSame( 0, $result['upcoming_group_events'] );
		$this->assertSame( 0, $result['pending_reregistrations'] );
	}

	// ------------------------------------------------------------------
	// get_user_summary() — certificates count with manage_options
	// ------------------------------------------------------------------

	/**
	 * #1744 — the count matches the list: no trash, no unpassed attempt.
	 */
	public function test_get_user_summary_counts_only_certificates(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		$this->certificate_count = '2';

		$result = ( new UserSummaryRestController( 'ffc/v1' ) )->get_user_summary( $this->make_request() );

		$counts = $this->prepared_matching( '/status NOT IN \(%s, %s, %s, %s\)/' );
		$this->assertCount( 1, $counts, 'The certificate count is prepared exactly once.' );
		$this->assertSame(
			array( 'wp_ffc_submissions', 5, 'trash', 'quiz_in_progress', 'quiz_failed', 'quiz_pending' ),
			$counts[0][1]
		);
		$this->assertSame( 2, $result['total_certificates'] );
	}

	public function test_get_user_summary_counts_certificates_when_manage_options(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			// manage_options grants view_own_certificates via user_has_capability
			return $cap === 'manage_options';
		});

		// The DB query for certificates count.
		$this->certificate_count = '7';

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['total_certificates'] );
	}

	// ------------------------------------------------------------------
	// get_user_summary() — next appointment when capability granted
	// ------------------------------------------------------------------

	public function test_get_user_summary_includes_next_appointment_when_capability_granted(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			return $cap === 'manage_options';
		});

		// The next-appointment lookup goes through
		// AppointmentRepository::findNextUpcomingForUser() and the
		// calendar title is fetched separately via CalendarRepository
		// (issue #340 centralization): one row each.
		$this->appointment_row = array(
			'id'               => 1,
			'appointment_date' => '2026-06-15',
			'start_time'       => '10:30:00',
			'calendar_id'      => 42,
		);
		$this->calendar_row    = array(
			'id'    => 42,
			'title' => 'Main Calendar',
		);

		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( $key === 'ffc_settings' ) {
				return array( 'date_format' => 'Y-m-d' );
			}
			return $default;
		});

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		$this->assertIsArray( $result );
		$this->assertNotNull( $result['next_appointment'] );
		$this->assertSame( 'Main Calendar', $result['next_appointment']['title'] );
		$this->assertSame( '10:30', $result['next_appointment']['time'] );
		$this->assertSame( '2026-06-15', $result['next_appointment']['date'], 'The wall-clock date renders as stored (#2246).' );
		$next_reads = $this->prepared_matching( '/appointment_date >= %s/' );
		$this->assertSame( '2026-08-09', end( $next_reads[0][1] ), 'The lower bound is the site\'s today.' );
		$calendar_reads = $this->prepared_matching( '/WHERE id = %d/' );
		$this->assertSame( array( 'wp_ffc_self_scheduling_calendars', 42 ), $calendar_reads[0][1] ?? null );
	}

	public function test_get_user_summary_next_appointment_null_when_no_upcoming(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			return $cap === 'manage_options';
		});

		$this->appointment_row = null;

		$ctrl    = new UserSummaryRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_summary( $request );

		$this->assertIsArray( $result );
		$this->assertNull( $result['next_appointment'] );
	}

	public function test_get_user_summary_counts_group_events_when_capability_granted(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function ( $cap ) {
			return 'ffc_view_own_audience_bookings' === $cap;
		} );
		// table_exists(ffc_audience_bookings) → true.
		$this->tables        = array( 'wp_ffc_audience_bookings' );
		$this->booking_count = '3';

		$result = ( new UserSummaryRestController( 'ffc/v1' ) )->get_user_summary( $this->make_request() );

		$this->assertSame( 3, $result['upcoming_group_events'] );
		$counts = $this->prepared_matching( '/COUNT\(DISTINCT b\.id\)/' );
		$this->assertCount( 1, $counts );
		$this->assertSame( array( 5, 5, '2026-08-09', 'cancelled' ), array_slice( $counts[0][1], 4 ) );
	}
}
