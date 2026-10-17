<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\AppointmentRestController;

/**
 * Tests for AppointmentRestController: route registration, create_appointment,
 * get_appointment, cancel_appointment, and check_appointment_access endpoints.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var \Mockery\MockInterface Mock for CalendarRepository (overload) */
	private $calendar_repo_mock;

	/** @var \Mockery\MockInterface Mock for AppointmentRepository (overload) */
	private $appointment_repo_mock;

	/** @var \Mockery\MockInterface Mock for AppointmentHandler (overload) */
	private $appointment_handler_mock;

	/** @var \Mockery\MockInterface Mock for RateLimiter (alias) */
	private $rate_limiter_mock;

	/** @var \Mockery\MockInterface Mock for RequestInput (alias) */
	private $request_input_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];

		// Capture register_rest_route calls
		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Overload mocks for classes instantiated with `new` inside the controller
		$this->calendar_repo_mock = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\CalendarRepository' );
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( null )->byDefault();
		$this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( false )->byDefault();

		$this->appointment_repo_mock = Mockery::mock( 'overload:\FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->appointment_repo_mock->shouldReceive( 'findById' )->andReturn( null )->byDefault();

		$this->appointment_handler_mock = Mockery::mock( 'overload:\FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andReturn( array( 'appointment_id' => 1, 'requires_approval' => false ) )->byDefault();
		$this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )->andReturn( true )->byDefault();

		// Alias mocks for static-only classes
		$utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		$this->request_input_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$this->request_input_mock->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault()
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) )
			->shouldReceive( 'text_array' )->andReturnUsing( static fn( $raw ) => is_array( $raw ) ? array_map( static fn( $v ) => is_scalar( $v ) ? strip_tags( (string) $v ) : '', $raw ) : array() )->byDefault();
		$utils_mock->shouldReceive( 'debug_log' )->byDefault();

		$encryption_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' );
		$encryption_mock->shouldReceive( 'decrypt_field' )->andReturnUsing( function( $row, $field ) {
			return $row[ $field ] ?? '';
		})->byDefault();

		// RateLimiter alias
		$this->rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
		$this->rate_limiter_mock->shouldReceive( 'record_attempt' )->byDefault();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [], array $json_params = [] ): object {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		$req->shouldReceive( 'get_json_params' )->andReturn( $json_params );
		return $req;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_three_endpoints(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 3, $this->registered_routes );
	}

	public function test_create_appointment_route_requires_a_capability(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$route = $this->registered_routes[0];
		$this->assertStringContainsString( 'appointments', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_create_permission' ), $route['args']['permission_callback'] );
	}

	public function test_anonymous_caller_is_refused_by_the_create_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );
	}

	public function test_create_permission_admits_the_appointments_manager_only(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_appointments' === $cap );
		$this->assertTrue( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );

		// The self-scoped booking cap is not enough to book in another's name.
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_book_own_appointments' === $cap );
		$this->assertFalse( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );
	}

	public function test_create_appointment_records_the_rate_limit_attempt(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$this->rate_limiter_mock->shouldReceive( 'record_attempt' )->once()->with( 'ip', '127.0.0.1' );

		( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);
	}

	public function test_create_appointment_sanitizes_custom_data_like_the_booking_form(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$captured = null;
		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andReturnUsing(
			function ( $data ) use ( &$captured ) {
				$captured = $data;
				return array( 'appointment_id' => 9, 'requires_approval' => false );
			}
		);
		$raw = array( 'school' => '<script>x</script>EMEF', 'nested' => array( 'a' => 'b' ) );
		$this->request_input_mock->shouldReceive( 'text_array' )->once()->with( $raw )->andReturn( array( 'school' => 'EMEF', 'nested' => '' ) );

		$booking                = $this->valid_booking();
		$booking['custom_data'] = $raw;
		( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $booking )
		);

		$this->assertSame( array( 'school' => 'EMEF', 'nested' => '' ), $captured['custom_data'] );
	}

	public function test_get_and_cancel_appointment_routes_require_access_check(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		// GET /appointments/{id}
		$route_get = $this->registered_routes[1];
		$this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_get['args']['permission_callback'] );

		// DELETE /appointments/{id}
		$route_delete = $this->registered_routes[2];
		$this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_delete['args']['permission_callback'] );
	}

	// ------------------------------------------------------------------
	// create_appointment()
	// ------------------------------------------------------------------

	public function test_create_appointment_returns_error_when_params_empty(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '1' ), [] );
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_data', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_when_required_fields_missing(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '1' ),
			array( 'date' => '2026-04-01', 'time' => '09:00' )
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_field', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_for_invalid_email(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '1' ),
			array(
				'date'  => '2026-04-01',
				'time'  => '09:00',
				'name'  => 'John Doe',
				'email' => 'not-an-email',
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_when_calendar_not_found(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( null );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '1' ),
			array(
				'date'  => '2026-04-01',
				'time'  => '09:00',
				'name'  => 'John Doe',
				'email' => 'john@example.com',
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_not_found', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_for_private_scheduling_anon(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( array(
			'id' => 1,
			'scheduling_visibility' => 'private',
		));

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '1' ),
			array(
				'date'  => '2026-04-01',
				'time'  => '09:00',
				'name'  => 'John Doe',
				'email' => 'john@example.com',
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'scheduling_private', $result->get_error_code() );
	}

	public function test_create_appointment_success_returns_data(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->with( '1' )->andReturn( array(
			'id' => 1,
			'scheduling_visibility' => 'public',
		));

		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )
			->once()
			->andReturn( array(
				'appointment_id'    => 42,
				'requires_approval' => true,
			));

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '1' ),
			array(
				'date'  => '2026-04-01',
				'time'  => '09:00',
				'name'  => 'John Doe',
				'email' => 'john@example.com',
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $result['appointment_id'] );
		$this->assertTrue( $result['requires_approval'] );
	}

	// ------------------------------------------------------------------
	// get_appointment()
	// ------------------------------------------------------------------

	public function test_get_appointment_returns_error_when_not_found(): void {
		$this->appointment_repo_mock->shouldReceive( 'findById' )->with( '99' )->andReturn( null );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '99' ) );
		$result = $ctrl->get_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'appointment_not_found', $result->get_error_code() );
	}

	public function test_get_appointment_returns_formatted_data_on_success(): void {
		$appointment_data = array(
			'id'               => 10,
			'calendar_id'      => 1,
			'appointment_date' => '2026-04-01',
			'start_time'       => '09:00',
			'end_time'         => '09:30',
			'status'           => 'confirmed',
			'name'             => 'Jane Doe',
			'email'            => 'jane@example.com',
			'phone'            => '11999998888',
			'user_notes'       => 'Test note',
			'created_at'       => '2026-03-01 10:00:00',
		);

		$this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( $appointment_data );
		$this->calendar_repo_mock->shouldReceive( 'findById' )->with( 1 )->andReturn( array(
			'id'    => 1,
			'title' => 'Test Calendar',
		));

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '10' ) );
		$result = $ctrl->get_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( 1, $result['calendar_id'] );
		$this->assertSame( 'Test Calendar', $result['calendar_title'] );
		$this->assertSame( 'confirmed', $result['status'] );
		// A caller who is not the booker gets the address masked (#1863).
		$this->assertSame( 'j***@example.com', $result['email'] );
		$this->assertSame( '*********88', $result['phone'] );
	}

	/**
	 * A booking owned by user 42.
	 *
	 * @return array<string, mixed>
	 */
	private function owned_appointment(): array {
		return array(
			'id'               => 10,
			'calendar_id'      => 1,
			'user_id'          => 42,
			'appointment_date' => '2026-04-01',
			'start_time'       => '09:00',
			'end_time'         => '09:30',
			'status'           => 'confirmed',
			'name'             => 'Jane Doe',
			'email'            => 'jane@example.com',
			'phone'            => '11999998888',
			'created_at'       => '2026-03-01 10:00:00',
		);
	}

	public function test_get_appointment_gives_the_booker_their_own_email_and_phone(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'user_can' )->justReturn( false );
		$this->appointment_repo_mock->shouldReceive( 'findById' )->andReturn( $this->owned_appointment() );
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'title' => 'Cal' ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->get_appointment( $this->make_request( array( 'id' => '10' ) ) );

		$this->assertSame( 'jane@example.com', $result['email'] );
		$this->assertSame( '11999998888', $result['phone'] );
	}

	public function test_get_appointment_reveals_to_a_pii_holder_only_on_request_and_audits(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );
		$this->appointment_repo_mock->shouldReceive( 'findById' )->andReturn( $this->owned_appointment() );
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'title' => 'Cal' ) );
		Mockery::getConfiguration()->setConstantsMap(
			array( 'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_INFO' => 'info' ) )
		);
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )->once()
			->with( 'appointment_pii_revealed', 'info', array( 'field_key' => 'rest_api' ), 5, 10 );
		$ctrl = new AppointmentRestController( 'ffc/v1' );

		$masked = $ctrl->get_appointment( $this->make_request( array( 'id' => '10' ) ) );
		$this->assertSame( 'j***@example.com', $masked['email'] );
		$this->assertSame( '*********88', $masked['phone'] );

		$clear = $ctrl->get_appointment( $this->make_request( array( 'id' => '10', 'reveal' => true ) ) );
		$this->assertSame( 'jane@example.com', $clear['email'] );
		$this->assertSame( '11999998888', $clear['phone'] );
	}

	// ------------------------------------------------------------------
	// cancel_appointment()
	// ------------------------------------------------------------------

	public function test_cancel_appointment_propagates_wp_error(): void {
		$wp_error = new \WP_Error( 'cannot_cancel', 'Cannot cancel', array( 'status' => 400 ) );

		$this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )
			->with( '5', '', '' )
			->once()
			->andReturn( $wp_error );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '5' ), [] );
		$result = $ctrl->cancel_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cannot_cancel', $result->get_error_code() );
	}

	public function test_cancel_appointment_returns_success(): void {
		$this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )
			->with( '5', '', 'Scheduling conflict' )
			->once()
			->andReturn( true );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => '5' ),
			array( 'reason' => 'Scheduling conflict' )
		);
		$result = $ctrl->cancel_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ------------------------------------------------------------------
	// check_appointment_access()
	// ------------------------------------------------------------------

	public function test_check_appointment_access_bypass_returns_true(): void {
		$this->calendar_repo_mock->shouldReceive( 'userHasSchedulingBypass' )->andReturn( true );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '1' ) );

		$this->assertTrue( $ctrl->check_appointment_access( $request ) );
	}

	public function test_check_appointment_access_not_logged_in_returns_false(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '1' ) );

		$this->assertFalse( $ctrl->check_appointment_access( $request ) );
	}

	public function test_check_appointment_access_own_appointment_returns_true(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( array(
			'id'      => 10,
			'user_id' => 42,
		));

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '10' ) );

		$this->assertTrue( $ctrl->check_appointment_access( $request ) );
	}

	public function test_check_appointment_access_other_appointment_returns_false(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$this->appointment_repo_mock->shouldReceive( 'findById' )->with( '10' )->andReturn( array(
			'id'      => 10,
			'user_id' => 99,
		));

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => '10' ) );

		$this->assertFalse( $ctrl->check_appointment_access( $request ) );
	}

	// ------------------------------------------------------------------
	// create_appointment — rate limit / logged-in / error / catch (#910)
	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function valid_booking(): array {
		return array( 'date' => '2026-05-20', 'time' => '10:00', 'name' => 'Ana', 'email' => 'ana@example.com', 'consent' => 1 );
	}

	public function test_create_appointment_rate_limited(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => false ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_create_appointment_attaches_user_id_when_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$captured = null;
		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andReturnUsing(
			function ( $data ) use ( &$captured ) {
				$captured = $data;
				return array( 'appointment_id' => 9, 'requires_approval' => false );
			}
		);

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 5, $captured['user_id'] );
	}

	public function test_create_appointment_bubbles_handler_wp_error(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andReturn(
			new \WP_Error( 'slot_full', 'full' )
		);

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'slot_full', $result->get_error_code() );
	}

	public function test_create_appointment_wraps_exception(): void {
		$this->calendar_repo_mock->shouldReceive( 'findById' )->andReturn( array( 'scheduling_visibility' => 'public' ) );
		$this->appointment_handler_mock->shouldReceive( 'process_appointment' )->andThrow( new \RuntimeException( 'boom' ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )->shouldReceive( 'log_rest_api' )->byDefault();

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_appointment_wraps_exception(): void {
		$this->appointment_repo_mock->shouldReceive( 'findById' )->andThrow( new \RuntimeException( 'boom' ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )->shouldReceive( 'log_rest_api' )->byDefault();

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->get_appointment(
			$this->make_request( array( 'id' => '5' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_cancel_appointment_wraps_exception(): void {
		$this->appointment_handler_mock->shouldReceive( 'cancel_appointment' )->andThrow( new \RuntimeException( 'boom' ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )->shouldReceive( 'log_rest_api' )->byDefault();

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->cancel_appointment(
			$this->make_request( array( 'id' => '5' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_check_appointment_access_false_when_appointment_missing(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->appointment_repo_mock->shouldReceive( 'findById' )->andReturn( null );

		$this->assertFalse(
			( new AppointmentRestController( 'ffc/v1' ) )->check_appointment_access( $this->make_request( array( 'id' => '5' ) ) )
		);
	}
}
