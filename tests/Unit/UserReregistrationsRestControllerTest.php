<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserReregistrationsRestController;
use FreeFormCertificate\Core\DocumentFormatter;

/**
 * Tests for UserReregistrationsRestController: route registration,
 * authentication checks, empty/formatted submission responses.
 *
 * ReregistrationSubmissionReader, ReregistrationSubmissionWriter,
 * MagicLinkHelper and DateFormatter are the real classes: the `$wpdb` double
 * answers the user's submissions query with the rows a test sets and records
 * every update, and the options they read are stubbed globally.
 */
class UserReregistrationsRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var list<object>|\Exception What the submissions query answers, or throws. */
	private $submissions = array();

	/** @var list<array<int, mixed>> The arguments of every `$wpdb->update()` call. */
	private array $updates = array();

	/** @var list<array<int, mixed>> The bound arguments of every submissions query. */
	private array $queried = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];
		$this->submissions       = array();
		$this->updates           = array();
		$this->queried           = array();

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
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			if ( 'ffc_settings' === $key ) {
				// What DateFormatter reads.
				return array(
					'date_format' => 'F j, Y',
					'time_format' => 'H:i',
				);
			}
			// Everything else, the verification page id included, is unset.
			return $default;
		});
		Functions\when( 'wp_date' )->alias( function( $format, $timestamp = null, $tz = null ) {
			return date( $format, $timestamp ?? time() );
		});
		Functions\when( 'wp_timezone' )->alias( function() {
			return new \DateTimeZone( 'UTC' );
		});
		Functions\when( 'home_url' )->alias( function( $path = '' ) {
			return 'https://example.com' . $path;
		});
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $sql, ...$args ) {
			return array(
				'sql'  => $sql,
				'args' => $args,
			);
		});
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $q ) {
			if ( false === strpos( $q['sql'], 'WHERE s.user_id = %d' ) ) {
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
			$this->queried[] = $q['args'];
			if ( $this->submissions instanceof \Exception ) {
				throw $this->submissions;
			}
			return $this->submissions;
		});
		$wpdb->shouldReceive( 'update' )->andReturnUsing( function( ...$args ) {
			$this->updates[] = $args;
			return 1;
		});

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
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

	/**
	 * Helper: build a mock submission object.
	 */
	private function make_submission( array $overrides = [] ): object {
		$defaults = array(
			'id'                     => 1,
			'reregistration_id'      => 10,
			'reregistration_title'   => 'Annual Renewal',
			'status'                 => 'pending',
			'reregistration_status'  => 'active',
			'start_date'             => '2026-01-01',
			'end_date'               => '2026-12-31',
			'submitted_at'           => null,
			'auth_code'              => '',
		);

		return (object) array_merge( $defaults, $overrides );
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_one_endpoint(): void {
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 1, $this->registered_routes );
	}

	public function test_reregistrations_route_registered(): void {
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame( '/user/reregistrations', $this->registered_routes[0]['route'] );
	}

	public function test_reregistrations_route_requires_authentication(): void {
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame(
			'is_user_logged_in',
			$this->registered_routes[0]['args']['permission_callback']
		);
	}

	// ------------------------------------------------------------------
	// get_user_reregistrations() — not logged in
	// ------------------------------------------------------------------

	public function test_get_user_reregistrations_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_user_reregistrations() — empty submissions
	// ------------------------------------------------------------------

	public function test_get_user_reregistrations_returns_empty_when_no_submissions(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->submissions = array();

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$this->assertSame( array( array( 'wp_ffc_reregistration_submissions', 'wp_ffc_reregistrations', 5 ) ), $this->queried, 'The submissions are read for the current user.' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertEmpty( $result['reregistrations'] );
	}

	// ------------------------------------------------------------------
	// get_user_reregistrations() — formatted submissions
	// ------------------------------------------------------------------

	public function test_get_user_reregistrations_returns_formatted_submissions(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$sub = $this->make_submission( array(
			'id'                    => 42,
			'reregistration_id'     => 10,
			'reregistration_title'  => 'Annual Renewal',
			'status'                => 'submitted',
			'reregistration_status' => 'active',
			'start_date'            => '2026-01-01',
			'end_date'              => '2026-12-31',
			'submitted_at'          => '2026-03-01 14:30:00',
			'auth_code'             => 'ABCD1234EFGH',
			'magic_token'           => 'magic-token-42',
		));

		$this->submissions = array( $sub );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$this->assertSame( 5, $this->queried[0][2], 'The submissions are read for the current user.' );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );

		$formatted = $result['reregistrations'][0];
		$this->assertSame( 42, $formatted['submission_id'] );
		$this->assertSame( 10, $formatted['reregistration_id'] );
		$this->assertSame( 'Annual Renewal', $formatted['title'] );
		$this->assertSame( 'submitted', $formatted['status'] );
		$this->assertSame( 'Submitted — Pending Review', $formatted['status_label'] );
		$this->assertTrue( $formatted['can_download'] );
		$this->assertFalse( $formatted['can_submit'] );
		$this->assertTrue( $formatted['is_active'] );
		$this->assertNotEmpty( $formatted['auth_code'] );
		$this->assertStringContainsString( 'R-', $formatted['auth_code'] );
		$this->assertSame( DocumentFormatter::format_auth_code( 'ABCD1234EFGH', DocumentFormatter::PREFIX_REREGISTRATION ), $formatted['auth_code'] );
		$this->assertSame( 'January 1, 2026', $formatted['start_date_formatted'] );
		$this->assertSame( 'https://example.com/valid/#token=magic-token-42', $formatted['magic_link'], 'The link is built from the stored token, on the fallback verification page.' );
		$this->assertSame( array(), $this->updates, 'A stored token is reused, never rewritten.' );
	}

	public function test_get_user_reregistrations_pending_submission_can_submit(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$sub = $this->make_submission( array(
			'status'                => 'pending',
			'reregistration_status' => 'active',
			'auth_code'             => '',
		));

		$this->submissions = array( $sub );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$formatted = $result['reregistrations'][0];
		$this->assertTrue( $formatted['can_submit'] );
		$this->assertFalse( $formatted['can_download'] );
		$this->assertSame( '', $formatted['magic_link'] );
		$this->assertSame( '', $formatted['auth_code'] );
	}

	public function test_get_user_reregistrations_expired_submission_with_auth_code_can_download(): void {
		// 6.7.2 — When a submission carries an `auth_code` it MUST be
		// downloadable, even if the parent campaign expired and the
		// submission status flipped to 'expired'. Pre-6.7.2 the
		// controller hard-coded `status IN ('submitted', 'approved')`
		// which excluded this perfectly valid post-approval state.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$sub = $this->make_submission( array(
			'id'                    => 77,
			'reregistration_id'     => 12,
			'reregistration_title'  => 'Atualização Anual',
			'status'                => 'expired',
			'reregistration_status' => 'expired',
			'start_date'            => '2026-02-15',
			'end_date'              => '2026-03-27',
			'submitted_at'          => null,
			'auth_code'             => 'ZSKDNN5895DU',
		));

		// No token stored yet: the writer generates one and saves it on the row.
		$this->submissions = array( $sub );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$formatted = $result['reregistrations'][0];
		$this->assertTrue( $formatted['can_download'], 'auth_code present → download MUST be available regardless of expired status' );
		$this->assertCount( 1, $this->updates, 'The missing token is generated and saved once.' );
		$this->assertSame( 'wp_ffc_reregistration_submissions', $this->updates[0][0] );
		$this->assertSame( array( 'id' => 77 ), $this->updates[0][2] );
		$token = $this->updates[0][1]['magic_token'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertSame( 'https://example.com/valid/#token=' . $token, $formatted['magic_link'] );
		$this->assertFalse( $formatted['can_submit'], 'expired submissions cannot be re-submitted' );
	}

	public function test_get_user_reregistrations_rejected_submission_no_download(): void {
		// Rejected submissions never had an auth_code generated, so the
		// auth_code-based gate naturally returns can_download=false.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$sub = $this->make_submission( array(
			'status'                => 'rejected',
			'reregistration_status' => 'active',
			'auth_code'             => '',
		));

		$this->submissions = array( $sub );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$formatted = $result['reregistrations'][0];
		$this->assertFalse( $formatted['can_download'] );
		$this->assertSame( '', $formatted['magic_link'] );
	}

	public function test_get_user_reregistrations_exception_returns_wp_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->submissions = new \Exception( 'Database failure' );

		$ctrl    = new UserReregistrationsRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_reregistrations( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'reregistrations_error', $result->get_error_code() );
	}
}
