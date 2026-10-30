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

	/** @var array<string, object|null> What `get_row()` answers, by table. */
	private array $rows = array();

	/** @var list<object> The campaign's field rows. */
	private array $fields = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The campaign window is read on the site's clock (#2242); pin it
		// inside the fixtures' campaigns.
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? '2026-06-15' : ( 'mysql' === $type ? '2026-06-15 12:00:00' : 0 );
			}
		);


		$this->registered_routes = [];
		$this->rows              = array();
		$this->fields            = array();
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
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( function( $q ) {
			return $this->rows[ (string) $q['args'][0] ] ?? null;
		});
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( '5' ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $q ) {
			if ( false !== strpos( $q['sql'], 'FROM %i WHERE audience_id = %d' ) ) {
				return $this->fields;
			}
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

	public function test_register_routes_creates_the_list_answers_and_record_endpoints(): void {
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 3, $this->registered_routes );
		$this->assertSame( '/user/reregistrations/(?P<id>\\d+)/answers', $this->registered_routes[1]['route'] );
		$this->assertSame( '/user/reregistrations/(?P<id>\\d+)/record', $this->registered_routes[2]['route'] );
		$this->assertSame( 'is_user_logged_in', $this->registered_routes[2]['args']['permission_callback'], 'The Record is a signed-in download (#2242).' );
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
		$this->assertFalse( $formatted['can_download'], 'A submission awaiting review has no Record yet (#2242).' );
		$this->assertSame( '', $formatted['auth_code'], 'A code kept on a row returned to draft stays hidden until approval.' );
		$this->assertFalse( $formatted['can_submit'] );
		$this->assertTrue( $formatted['is_active'] );
		$this->assertSame( 'January 1, 2026', $formatted['start_date_formatted'] );
		$this->assertArrayNotHasKey( 'magic_link', $formatted, 'The Record is never offered through a public link.' );
		$this->assertSame( array(), $this->updates, 'Listing writes nothing.' );
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
		$this->assertSame( '', $formatted['auth_code'] );
	}

	public function test_an_approved_submission_downloads_its_record_after_the_campaign_ends(): void {
		// The daily run expires only rows that never submitted; an approved
		// row stays approved, so its Record stays downloadable (#2242).
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$sub = $this->make_submission( array(
			'id'                    => 77,
			'status'                => 'approved',
			'reregistration_status' => 'expired',
			'start_date'            => '2026-02-15',
			'end_date'              => '2026-03-27',
			'submitted_at'          => 1761000000,
			'auth_code'             => 'ZSKDNN5895DU',
		));
		$this->submissions = array( $sub );

		$formatted = ( new UserReregistrationsRestController( 'ffc/v1' ) )->get_user_reregistrations( $this->make_request() )['reregistrations'][0];

		$this->assertTrue( $formatted['can_download'] );
		$this->assertSame( DocumentFormatter::format_auth_code( 'ZSKDNN5895DU', DocumentFormatter::PREFIX_REREGISTRATION ), $formatted['auth_code'] );
		$this->assertFalse( $formatted['can_submit'] );
		$this->assertSame( array(), $this->updates, 'No public token is minted for the list.' );
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

	// ------------------------------------------------------------------
	// GET /user/reregistrations/{id}/answers
	// ------------------------------------------------------------------

	/**
	 * Answer a campaign with one masked and one plain field, and a submitted
	 * submission owned by user 7.
	 */
	private function given_answers( array $overrides = array() ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$this->rows['wp_ffc_reregistration_submissions'] = (object) array_merge(
			array(
				'id'                => 3,
				'reregistration_id' => 10,
				'user_id'           => 7,
				'status'            => 'approved',
				'submitted_at'      => 1761000000,
				'data'              => json_encode( array( 'fields' => array( 'rf' => '5181780', 'vinculo' => '2' ) ) ),
			),
			$overrides
		);
		$this->rows['wp_ffc_reregistrations']            = (object) array(
			'id'    => 10,
			'title' => 'Annual',
		);
		$this->rows['wp_ffc_audiences']                  = (object) array(
			'id'        => 5,
			'name'      => 'Staff',
			'parent_id' => null,
		);
		$this->fields = array(
			(object) array( 'id' => 1, 'audience_id' => 5, 'field_key' => 'rf', 'field_label' => 'RF', 'field_type' => 'text', 'field_mask' => 'rf', 'is_sensitive' => 0 ),
			(object) array( 'id' => 2, 'audience_id' => 5, 'field_key' => 'vinculo', 'field_label' => 'Bond', 'field_type' => 'text', 'field_mask' => 'number', 'is_sensitive' => 0 ),
		);
	}

	public function test_answers_arrive_masked_for_the_owner_who_may_reveal_them(): void {
		$this->given_answers();
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_submission_answers( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertSame( array( 'rf' => '518.***-0', 'vinculo' => '2' ), array_column( $result['rows'], 'value', 'key' ) );
		$this->assertTrue( $result['masked'] );
		$this->assertTrue( $result['can_reveal'] );
	}

	public function test_the_owner_reveals_their_answers(): void {
		$this->given_answers();
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_submission_answers( $this->make_request( array( 'id' => 3, 'reveal' => true ) ) );

		$this->assertSame( '518.178-0', array_column( $result['rows'], 'value', 'key' )['rf'] );
		$this->assertFalse( $result['masked'] );
		$this->assertFalse( $result['can_reveal'], 'Nothing is left to reveal.' );
	}

	public function test_another_persons_submission_answers_not_found(): void {
		$this->given_answers( array( 'user_id' => 8 ) );
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_submission_answers( $this->make_request( array( 'id' => 3, 'reveal' => true ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_submission_not_found', $result->get_error_code() );
	}

	public function test_a_submission_never_sent_has_no_answers(): void {
		$this->given_answers( array( 'submitted_at' => null ) );
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_submission_answers( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_submission_not_found', $result->get_error_code() );
	}

	public function test_a_logged_out_request_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_submission_answers( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_the_list_offers_answers_only_for_a_sent_submission(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->submissions = array(
			$this->make_submission( array( 'id' => 1, 'submitted_at' => 1761000000 ) ),
			$this->make_submission( array( 'id' => 2 ) ),
		);
		$ctrl = new UserReregistrationsRestController( 'ffc/v1' );

		$result = $ctrl->get_user_reregistrations( $this->make_request() );

		$this->assertSame( array( true, false ), array_column( $result['reregistrations'], 'can_view_answers' ) );
	}

	// ------------------------------------------------------------------
	// GET /user/reregistrations/{id}/record (#2242)
	// ------------------------------------------------------------------

	public function test_the_record_is_refused_for_a_submission_not_approved(): void {
		foreach ( array( 'submitted', 'rejected', 'in_progress' ) as $status ) {
			$this->given_answers( array( 'status' => $status ) );

			$result = ( new UserReregistrationsRestController( 'ffc/v1' ) )->get_submission_record( $this->make_request( array( 'id' => 3 ) ) );

			$this->assertInstanceOf( \WP_Error::class, $result, $status );
			$this->assertSame( 'ffc_submission_not_found', $result->get_error_code(), $status );
		}
	}

	public function test_the_record_is_refused_for_another_persons_submission(): void {
		$this->given_answers( array( 'user_id' => 8 ) );

		$result = ( new UserReregistrationsRestController( 'ffc/v1' ) )->get_submission_record( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertSame( 'ffc_submission_not_found', $result->get_error_code() );
	}

	public function test_the_record_is_refused_when_logged_out(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$result = ( new UserReregistrationsRestController( 'ffc/v1' ) )->get_submission_record( $this->make_request( array( 'id' => 3 ) ) );

		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}
}
