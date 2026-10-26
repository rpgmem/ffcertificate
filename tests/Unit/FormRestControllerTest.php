<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\FormRestController;
use FreeFormCertificate\Repositories\FormRepository;

/**
 * Tests for FormRestController: route registration, get_forms,
 * get_form, and submit_form endpoints.
 * @covers \FreeFormCertificate\API\FormRestController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var \Mockery\MockInterface&FormRepository Mock for FormRepository (injected) */
	private $form_repo_mock;

	/** @var \Mockery\MockInterface Mock for SubmissionHandler (overload) */
	private $submission_handler_mock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\API\\FormRestController' );

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
		Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/form/1' );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'home_url' )->alias( function( $path = '' ) { return 'https://example.com' . $path; } );

		// rest_ensure_response now wraps the array in a fake response so
		// get_forms() can set pagination headers. Existing tests that
		// assert on the array shape read via $response->get_data().
		Functions\when( 'rest_ensure_response' )->alias( function ( $data ) {
			return new \FreeFormCertificate\Tests\Unit\FakeRestResponse( $data );
		} );

		// get_forms() builds the Link header via these helpers — stub
		// them with predictable behaviour for the assertions below.
		Functions\when( 'rest_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' );
		} );
		Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
			$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
			return $url . $sep . http_build_query( $args );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();

		// Injected FormRepository mock
		$this->form_repo_mock = Mockery::mock( FormRepository::class );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( [] )->byDefault();
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 0 )->byDefault();
		$this->form_repo_mock->shouldReceive( 'getConfig' )->andReturn( array() )->byDefault();
		$this->form_repo_mock->shouldReceive( 'getFields' )->andReturn( array() )->byDefault();
		$this->form_repo_mock->shouldReceive( 'getBackground' )->andReturn( '' )->byDefault();

		// Overload mock for SubmissionHandler (instantiated with new)
		$this->submission_handler_mock = Mockery::mock( 'overload:\FreeFormCertificate\Submissions\SubmissionHandler' );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->andReturn( 1 )->byDefault();

		// Alias mocks for static-only classes
		$utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' )->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault()
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$utils_mock->shouldReceive( 'debug_log' )->byDefault();

		// DocumentFormatter is loaded for real (its PREFIX_* constants are needed
		// by the controller). validate_cpf/rf and format_auth_code are pure.
		$data_sanitizer_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' );
		$data_sanitizer_mock->shouldReceive( 'recursive_sanitize' )->andReturnUsing( function( $data ) { return $data; } )->byDefault();
		$data_sanitizer_mock->shouldReceive( 'normalize_cpf_rf' )->andReturnUsing( function( $value ) {
			return preg_replace( '/[^0-9]/', '', (string) $value ) ?? '';
		} )->byDefault();
		$data_sanitizer_mock->shouldReceive( 'normalize_email' )->andReturnUsing( static fn( $v ) => strtolower( trim( (string) $v ) ) )->byDefault();
		$data_sanitizer_mock->shouldReceive( 'classify_cpf_rf' )->andReturnUsing( static fn( $v ) => 7 === strlen( (string) preg_replace( '/\D/', '', (string) $v ) ) ? 'rf' : 'cpf' )->byDefault();

		$this->geofence_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
		Mockery::mock( 'alias:\FreeFormCertificate\Security\GpsReading' )->shouldReceive( 'from_request' )->andReturn( array( 'reading' => null, 'status' => '' ) )->byDefault();
		$this->geofence_mock->shouldReceive( 'get_form_config' )->andReturn( null )->byDefault();
		$this->geofence_mock->shouldReceive( 'check_submission' )->andReturn( array( 'allowed' => true ) )->byDefault();

		$this->rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
		$this->rate_limiter_mock->shouldReceive( 'check_email_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
		$this->rate_limiter_mock->shouldReceive( 'check_cpf_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
	}

	/** @var \Mockery\MockInterface Geofence alias */
	private $geofence_mock;

	/** @var \Mockery\MockInterface RateLimiter alias */
	private $rate_limiter_mock;

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

	/**
	 * Helper: create a mock WP_Post object.
	 */
	private function make_post( int $id, string $type = 'ffc_form', string $status = 'publish' ): object {
		$post = new \stdClass();
		$post->ID            = $id;
		$post->post_type     = $type;
		$post->post_status   = $status;
		$post->post_title    = 'Test Form';
		$post->post_date     = '2026-01-01 00:00:00';
		$post->post_modified = '2026-01-15 12:00:00';
		return $post;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_four_endpoints(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		$this->assertCount( 4, $this->registered_routes );
	}

	public function test_forms_list_route_requires_capability_callback(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		$route = $this->registered_routes[0];
		$this->assertSame( '/forms', $route['route'] );
		// 6.4.1: switched from `__return_true` to a method callback
		// that delegates to current_user_can('ffc_view_forms_api').
		$this->assertIsArray( $route['args']['permission_callback'] );
		$this->assertSame( 'permission_read_forms_api', $route['args']['permission_callback'][1] );
	}

	public function test_form_single_route_requires_capability_callback(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		$route = $this->registered_routes[1];
		$this->assertStringContainsString( 'forms', $route['route'] );
		$this->assertIsArray( $route['args']['permission_callback'] );
		$this->assertSame( 'permission_read_forms_api', $route['args']['permission_callback'][1] );
	}

	public function test_form_schema_route_is_public(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		$route = $this->registered_routes[2];
		$this->assertStringContainsString( 'schema', $route['route'] );
		$this->assertSame( '__return_true', $route['args']['permission_callback'] );
	}

	public function test_form_submit_route_requires_the_submit_capability(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		$route = $this->registered_routes[3];
		$this->assertStringContainsString( 'submit', $route['route'] );
		$this->assertSame( array( $ctrl, 'permission_submit_forms_api' ), $route['args']['permission_callback'] );
	}

	public function test_anonymous_caller_is_refused_by_the_submit_permission(): void {
		// An anonymous request holds no capability at all.
		Functions\expect( 'current_user_can' )->once()->with( 'ffc_submit_forms_api' )->andReturn( false );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertFalse( $ctrl->permission_submit_forms_api() );
	}

	public function test_caller_holding_the_submit_capability_is_admitted(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'ffc_submit_forms_api' )->andReturn( true );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertTrue( $ctrl->permission_submit_forms_api() );
	}

	// ------------------------------------------------------------------
	// get_forms()
	// ------------------------------------------------------------------

	public function test_get_forms_returns_error_when_repository_null(): void {
		$ctrl = new FormRestController( 'ffc/v1', null );
		$request = $this->make_request();
		$result = $ctrl->get_forms( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'repository_not_found', $result->get_error_code() );
	}

	public function test_get_forms_returns_forms_list_on_success(): void {
		$post1 = $this->make_post( 1 );
		$post1->post_title = 'Form A';
		$post2 = $this->make_post( 2 );
		$post2->post_title = 'Form B';

		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 2 );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->once()->andReturn( array( $post1, $post2 ) );
		$this->form_repo_mock->shouldReceive( 'getConfig' )->with( 1 )->andReturn( array( 'theme' => 'default' ) );
		$this->form_repo_mock->shouldReceive( 'getConfig' )->with( 2 )->andReturn( array( 'theme' => 'dark' ) );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10 ) );
		$result = $ctrl->get_forms( $request );

		$this->assertInstanceOf( FakeRestResponse::class, $result );
		$data = $result->get_data();
		$this->assertCount( 2, $data );
		$this->assertSame( 1, $data[0]['id'] );
		$this->assertSame( 'Form A', $data[0]['title'] );
		$this->assertSame( 2, $data[1]['id'] );
	}

	// ------------------------------------------------------------------
	// get_form()
	// ------------------------------------------------------------------

	public function test_get_form_returns_error_when_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 99 ) );
		$result = $ctrl->get_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	public function test_get_form_returns_error_when_wrong_post_type(): void {
		$post = $this->make_post( 5, 'post' );
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 5 ) );
		$result = $ctrl->get_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	public function test_get_form_returns_error_when_not_published(): void {
		$post = $this->make_post( 5, 'ffc_form', 'draft' );
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 5 ) );
		$result = $ctrl->get_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_published', $result->get_error_code() );
	}

	public function test_get_form_returns_trimmed_payload_on_success(): void {
		$post = $this->make_post( 3 );
		$post->post_title = 'My Form';
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 3 ) );
		$result = $ctrl->get_form( $request );

		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 3, $data['id'] );
		$this->assertSame( 'My Form', $data['title'] );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'date', $data );
		$this->assertArrayHasKey( 'modified', $data );
		$this->assertArrayHasKey( 'link', $data );

		// 6.4.1: config / fields / background dropped from the payload
		// — they previously leaked the `_ffc_form_config` blob (allowed/
		// denied user lists, validation/generated codes, geofence). See
		// issue #139. Integrators that need form structure use the
		// public `/forms/{id}/schema` endpoint instead.
		$this->assertArrayNotHasKey( 'config', $data );
		$this->assertArrayNotHasKey( 'fields', $data );
		$this->assertArrayNotHasKey( 'background', $data );
	}

	public function test_get_forms_list_payload_does_not_include_config_blob(): void {
		$post1 = $this->make_post( 1 );
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 1 );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( array( $post1 ) );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10 ) );
		$result = $ctrl->get_forms( $request );

		$data = $result->get_data();
		$this->assertCount( 1, $data );
		// Trimmed: id, title, status, date, modified, link only.
		$this->assertArrayNotHasKey( 'config', $data[0] );
	}

	// ------------------------------------------------------------------
	// get_form_schema()
	// ------------------------------------------------------------------

	public function test_get_form_schema_returns_error_when_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 99 ) );
		$result = $ctrl->get_form_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	public function test_get_form_schema_returns_error_when_not_published(): void {
		$post = $this->make_post( 5, 'ffc_form', 'draft' );
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 5 ) );
		$result = $ctrl->get_form_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_published', $result->get_error_code() );
	}

	public function test_get_form_schema_returns_error_when_repository_null(): void {
		$post = $this->make_post( 5 );
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', null );
		$request = $this->make_request( array( 'id' => 5 ) );
		$result = $ctrl->get_form_schema( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'repository_not_found', $result->get_error_code() );
	}

	public function test_get_form_schema_trims_fields_to_public_keys(): void {
		$post = $this->make_post( 7 );
		$post->post_title = 'Schema Form';
		Functions\when( 'get_post' )->justReturn( $post );

		$this->form_repo_mock->shouldReceive( 'getFields' )->with( 7 )->andReturn( array(
			array(
				'name'             => 'email',
				'label'            => 'E-mail',
				'type'             => 'email',
				'required'         => true,
				'options'          => array(),
				'internal_secret'  => 'should-not-leak',
			),
			array(
				'name'     => 'city',
				'label'    => 'City',
				'type'     => 'select',
				'required' => false,
				'options'  => array( 'SP', 'RJ' ),
			),
		));

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 7 ) );
		$result = $ctrl->get_form_schema( $request );

		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 7, $data['id'] );
		$this->assertSame( 'Schema Form', $data['title'] );
		$this->assertArrayHasKey( 'fields', $data );
		$this->assertCount( 2, $data['fields'] );

		$this->assertSame(
			array( 'name', 'label', 'type', 'required', 'options' ),
			array_keys( $data['fields'][0] )
		);
		$this->assertSame( 'email', $data['fields'][0]['name'] );
		$this->assertTrue( $data['fields'][0]['required'] );
		$this->assertSame( array( 'SP', 'RJ' ), $data['fields'][1]['options'] );

		// Background and config must NOT be part of the trimmed schema.
		$this->assertArrayNotHasKey( 'background', $data );
		$this->assertArrayNotHasKey( 'config', $data );
	}

	public function test_get_form_schema_normalises_missing_field_keys(): void {
		$post = $this->make_post( 8 );
		Functions\when( 'get_post' )->justReturn( $post );

		$this->form_repo_mock->shouldReceive( 'getFields' )->with( 8 )->andReturn( array(
			array( 'name' => 'only_name' ),
			'not-an-array',
		));

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 8 ) );
		$result = $ctrl->get_form_schema( $request );

		$data = $result->get_data();
		$this->assertCount( 1, $data['fields'] );
		$this->assertSame( 'only_name', $data['fields'][0]['name'] );
		$this->assertSame( '', $data['fields'][0]['label'] );
		$this->assertSame( 'text', $data['fields'][0]['type'] );
		$this->assertFalse( $data['fields'][0]['required'] );
		$this->assertSame( array(), $data['fields'][0]['options'] );
	}

	// ------------------------------------------------------------------
	// submit_form()
	// ------------------------------------------------------------------

	public function test_submit_form_returns_error_when_params_empty(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'id' => 1 ), [] );
		$result = $ctrl->submit_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_data', $result->get_error_code() );
	}

	public function test_submit_form_returns_error_when_form_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request(
			array( 'id' => 99 ),
			array( 'full_name' => 'John' )
		);
		$result = $ctrl->submit_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_found', $result->get_error_code() );
	}

	public function test_submit_form_returns_error_when_form_not_published(): void {
		$post = $this->make_post( 1, 'ffc_form', 'draft' );
		Functions\when( 'get_post' )->justReturn( $post );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array( 'full_name' => 'John' )
		);
		$result = $ctrl->submit_form( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'form_not_published', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// GET /forms pagination (#260)
	// ------------------------------------------------------------------

	/**
	 * Build $count fake posts with stable, sortable titles.
	 *
	 * @param int $count
	 * @return array<int, object>
	 */
	private function make_posts( int $count ): array {
		$posts = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$p             = $this->make_post( $i );
			$p->post_title = sprintf( 'Form %02d', $i );
			$posts[]       = $p;
		}
		return $posts;
	}

	public function test_get_forms_default_per_page_is_10(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock
			->shouldReceive( 'findPublished' )
			->once()
			->with( 10, 0 )
			->andReturn( $this->make_posts( 10 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request(); // page + per_page both null
		// sanitize callbacks fire only when WP REST dispatches; emulate by
		// making `get_param` return the registered defaults.
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertInstanceOf( FakeRestResponse::class, $result );
		$this->assertCount( 10, $result->get_data() );
		$this->assertSame( '25', $result->headers['X-WP-Total'] );
		$this->assertSame( '3', $result->headers['X-WP-TotalPages'] );
	}

	public function test_get_forms_page_2_returns_offset_slice(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock
			->shouldReceive( 'findPublished' )
			->once()
			->with( 10, 10 )
			->andReturn( $this->make_posts( 10 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 2, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertCount( 10, $result->get_data() );
		$this->assertSame( '25', $result->headers['X-WP-Total'] );
		$this->assertSame( '3', $result->headers['X-WP-TotalPages'] );
	}

	public function test_get_forms_overflow_page_returns_empty_array_with_headers(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		// findPublished must NOT be called for an overflow page.
		$this->form_repo_mock
			->shouldReceive( 'findPublished' )
			->never();

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 99999, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertSame( array(), $result->get_data() );
		$this->assertSame( '25', $result->headers['X-WP-Total'] );
		$this->assertSame( '3', $result->headers['X-WP-TotalPages'] );
	}

	public function test_get_forms_with_zero_total_returns_empty_array(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 0 );
		$this->form_repo_mock
			->shouldReceive( 'findPublished' )
			->once()
			->with( 10, 0 )
			->andReturn( array() );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertSame( array(), $result->get_data() );
		$this->assertSame( '0', $result->headers['X-WP-Total'] );
		$this->assertSame( '0', $result->headers['X-WP-TotalPages'] );
		$this->assertArrayNotHasKey( 'Link', $result->headers );
	}

	public function test_get_forms_link_header_first_page_has_next_and_last_only(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( $this->make_posts( 10 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertArrayHasKey( 'Link', $result->headers );
		$link = $result->headers['Link'];
		$this->assertStringNotContainsString( 'rel="first"', $link );
		$this->assertStringNotContainsString( 'rel="prev"', $link );
		$this->assertStringContainsString( 'rel="next"', $link );
		$this->assertStringContainsString( 'rel="last"', $link );
		$this->assertStringContainsString( 'page=2', $link );
		$this->assertStringContainsString( 'page=3', $link );
	}

	public function test_get_forms_link_header_middle_page_has_all_four_rels(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( $this->make_posts( 10 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 2, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$link = $result->headers['Link'];
		$this->assertStringContainsString( 'rel="first"', $link );
		$this->assertStringContainsString( 'rel="prev"', $link );
		$this->assertStringContainsString( 'rel="next"', $link );
		$this->assertStringContainsString( 'rel="last"', $link );
	}

	public function test_get_forms_link_header_last_page_has_first_and_prev_only(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock->shouldReceive( 'findPublished' )->andReturn( $this->make_posts( 5 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 3, 'per_page' => 10 ) );

		$result = $ctrl->get_forms( $request );

		$link = $result->headers['Link'];
		$this->assertStringContainsString( 'rel="first"', $link );
		$this->assertStringContainsString( 'rel="prev"', $link );
		$this->assertStringNotContainsString( 'rel="next"', $link );
		$this->assertStringNotContainsString( 'rel="last"', $link );
	}

	public function test_get_forms_ignores_legacy_limit_query_arg(): void {
		// Pre-#260, ?limit=N drove the result-set size. Now the controller
		// reads `page` + `per_page` only — `limit` is silently ignored.
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andReturn( 25 );
		$this->form_repo_mock
			->shouldReceive( 'findPublished' )
			->once()
			->with( 10, 0 )  // ← per_page, not 50
			->andReturn( $this->make_posts( 10 ) );

		$ctrl    = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$request = $this->make_request( array( 'page' => 1, 'per_page' => 10, 'limit' => 50 ) );

		$result = $ctrl->get_forms( $request );

		$this->assertCount( 10, $result->get_data() );
	}

	// ------------------------------------------------------------------
	// sanitize_per_page() + sanitize_page()
	// ------------------------------------------------------------------

	public function test_sanitize_per_page_clamps_above_max(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertSame( 100, $ctrl->sanitize_per_page( 101 ) );
		$this->assertSame( 100, $ctrl->sanitize_per_page( 9999 ) );
	}

	public function test_sanitize_per_page_returns_default_for_zero_or_garbage(): void {
		// absint() strips the sign, so negative numbers come through as
		// their absolute value (which is then clamped). Only 0 / non-numeric
		// garbage fall back to the default.
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertSame( 10, $ctrl->sanitize_per_page( 0 ) );
		$this->assertSame( 10, $ctrl->sanitize_per_page( 'banana' ) );
		$this->assertSame( 10, $ctrl->sanitize_per_page( null ) );
	}

	public function test_sanitize_per_page_passes_through_valid_values(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertSame( 1, $ctrl->sanitize_per_page( 1 ) );
		$this->assertSame( 25, $ctrl->sanitize_per_page( 25 ) );
		$this->assertSame( 100, $ctrl->sanitize_per_page( 100 ) );
	}

	public function test_sanitize_page_returns_1_for_zero_or_garbage(): void {
		// absint() strips the sign on negatives — same caveat as
		// sanitize_per_page. Only zero / non-numeric garbage fall back.
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertSame( 1, $ctrl->sanitize_page( 0 ) );
		$this->assertSame( 1, $ctrl->sanitize_page( 'foo' ) );
		$this->assertSame( 1, $ctrl->sanitize_page( null ) );
	}

	public function test_register_routes_no_longer_registers_limit_arg(): void {
		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->register_routes();

		// First registered route is `/forms` per
		// {@see self::test_register_routes_creates_four_endpoints}.
		$forms_route = $this->registered_routes[0];
		$this->assertSame( '/forms', $forms_route['route'] );

		$args = $forms_route['args']['args'];
		$this->assertArrayHasKey( 'page', $args );
		$this->assertArrayHasKey( 'per_page', $args );
		$this->assertArrayNotHasKey( 'limit', $args );
	}

	// ------------------------------------------------------------------
	// permission_read_forms_api()
	// ------------------------------------------------------------------

	public function test_permission_read_forms_api_delegates_to_current_user_can(): void {
		Functions\when( 'current_user_can' )->alias( function ( $cap ) {
			return 'ffc_view_forms_api' === $cap;
		} );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$this->assertTrue( $ctrl->permission_read_forms_api() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $ctrl->permission_read_forms_api() );
	}

	// ------------------------------------------------------------------
	// Exception → 500 catch branches
	// ------------------------------------------------------------------

	public function test_get_forms_returns_500_on_exception(): void {
		$this->form_repo_mock->shouldReceive( 'countPublished' )->andThrow( new \Exception( 'db down' ) );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->get_forms( $this->make_request( array( 'page' => 1, 'per_page' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_form_returns_500_on_exception(): void {
		Functions\when( 'get_post' )->alias( function () { throw new \Exception( 'boom' ); } );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->get_form( $this->make_request( array( 'id' => 1 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_form_schema_returns_500_on_exception(): void {
		Functions\when( 'get_post' )->alias( function () { throw new \Exception( 'boom' ); } );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->get_form_schema( $this->make_request( array( 'id' => 1 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_form_schema: non-array getFields fallback
	// ------------------------------------------------------------------

	public function test_get_form_schema_handles_non_array_fields(): void {
		$post = $this->make_post( 9 );
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

		$this->form_repo_mock->shouldReceive( 'getFields' )->with( 9 )->andReturn( 'not-an-array' );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->get_form_schema( $this->make_request( array( 'id' => 9 ) ) );

		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( array(), $data['fields'] );
	}

	// ------------------------------------------------------------------
	// submit_form(): the web form's stages over the JSON body (#1861)
	// ------------------------------------------------------------------

	/**
	 * Serve a form's config and fields the way FormConfigResolver reads them,
	 * and stub what the real stages call.
	 *
	 * @param array<string, mixed>             $config Form config.
	 * @param array<int, array<string, mixed>> $fields Form fields.
	 */
	private function serve_form( array $config, array $fields ): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 1 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $config, $fields ) {
				if ( '_ffc_form_config' === $key ) {
					return $config;
				}
				return '_ffc_form_fields' === $key ? $fields : '';
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'current_time' )->justReturn( '2026-10-17 10:00:00' );

		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\ReprintDetector' )
			->shouldReceive( 'detect' )
			->andReturn( array( 'is_reprint' => false, 'id' => 0, 'date' => '', 'data' => array() ) )
			->byDefault();

		$this->rate_limiter_mock->shouldReceive( 'check_all' )->andReturn( array( 'allowed' => true ) )->byDefault();
		$this->rate_limiter_mock->shouldReceive( 'record_attempt' )->byDefault();
	}

	/** Fields of a plain certificate form: a name and an email. */
	private function plain_fields(): array {
		return array(
			array( 'type' => 'text', 'name' => 'course', 'label' => 'Course' ),
			array( 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => '1' ),
		);
	}

	public function test_submit_form_stores_only_the_defined_fields_and_never_the_client_auth_code(): void {
		$fields   = $this->plain_fields();
		$fields[] = array( 'type' => 'hidden', 'name' => 'track', 'default_value' => 'official' );
		$this->serve_form( array(), $fields );

		$stored = null;
		$this->submission_handler_mock->shouldReceive( 'process_submission' )
			->once()
			->andReturnUsing(
				function ( $form_id, $title, $data ) use ( &$stored ) {
					$stored = $data;
					return 42;
				}
			);

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'course'           => 'Safety',
					'email'            => 'Jane@Example.com',
					'track'            => 'forged',
					'not_a_field'      => 'injected',
					'auth_code'        => 'CHOSENBYCLNT',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertIsArray( $stored );
		$this->assertSame( 'Safety', $stored['course'] );
		$this->assertSame( 'official', $stored['track'], 'A hidden field keeps the form\'s value.' );
		$this->assertArrayNotHasKey( 'not_a_field', $stored, 'A key the form does not define is not stored.' );
		$this->assertArrayNotHasKey( 'auth_code', $stored, 'The caller never chooses the authentication code.' );

		$data = $result->get_data();
		$this->assertSame( 42, $data['submission_id'] );
		$this->assertStringNotContainsString( 'CHOS', $data['auth_code'] );
	}

	public function test_submit_form_requires_the_consent_the_web_form_requires(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->never();

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form( $this->make_request( array( 'id' => 1 ), array( 'email' => 'jane@example.com' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_submit_form_applies_the_password_restriction_from_the_body(): void {
		$this->serve_form(
			array(
				'restrictions'    => array( 'password' => '1' ),
				'validation_code' => 'open-sesame',
			),
			$this->plain_fields()
		);
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->never();

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
					'ffc_password'     => 'guess',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertSame( 'Incorrect password.', $result->get_error_message() );
	}

	public function test_submit_form_passes_the_password_restriction_with_the_right_password(): void {
		$this->serve_form(
			array(
				'restrictions'    => array( 'password' => '1' ),
				'validation_code' => 'open-sesame',
			),
			$this->plain_fields()
		);
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->once()->andReturn( 7 );

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
					'ffc_password'     => 'open-sesame',
				)
			)
		);

		$this->assertSame( 7, $result->get_data()['submission_id'] );
	}

	public function test_submit_form_applies_the_denylist(): void {
		$fields   = $this->plain_fields();
		$fields[] = array( 'type' => 'text', 'name' => 'cpf_rf', 'label' => 'CPF' );
		$this->serve_form(
			array(
				'restrictions'      => array( 'denylist' => '1' ),
				'denied_users_list' => '51817842080',
			),
			$fields
		);
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->never();

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'cpf_rf'           => '518.178.420-80',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
	}

	public function test_submit_form_scores_the_quiz_and_issues_nothing_below_the_pass_mark(): void {
		$fields   = $this->plain_fields();
		$fields[] = array(
			'type'    => 'radio',
			'name'    => 'q1',
			'options' => 'A,B,C',
			'points'  => '10,0,0',
		);
		$this->serve_form(
			array(
				'quiz_enabled'       => '1',
				'quiz_passing_score' => 70,
				'quiz_max_attempts'  => 0,
			),
			$fields
		);
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Encryption' )->shouldReceive( 'is_configured' )->andReturn( false );

		$repo = Mockery::mock();
		$repo->shouldReceive( 'updateStatus' )->once()->with( 55, 'quiz_in_progress' );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->once()->andReturn( 55 );
		$this->submission_handler_mock->shouldReceive( 'get_repository' )->andReturn( $repo );

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'q1'               => 'B',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['quiz']['passed'] );
	}

	public function test_submit_form_records_the_rate_limit_attempts(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->once()->andReturn( 9 );
		$this->rate_limiter_mock->shouldReceive( 'record_attempt' )->once()->with( 'ip', '127.0.0.1', 1 );
		$this->rate_limiter_mock->shouldReceive( 'record_attempt' )->once()->with( 'email', 'jane@example.com', 1 );

		$ctrl = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
				)
			)
		);
	}

	public function test_submit_form_answers_429_when_rate_limited(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => false, 'message' => 'Slow down' ) );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )->never();

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_submit_form_answers_403_when_the_geofence_blocks(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->geofence_mock->shouldReceive( 'check_submission' )->andReturn(
			array( 'allowed' => false, 'message' => 'Outside window', 'reason' => 'datetime' )
		);

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'Outside window', $result->get_error_message() );
	}

	public function test_submit_form_hides_the_raw_repository_error(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->submission_handler_mock->shouldReceive( 'process_submission' )
			->andReturn( new \WP_Error( 'db_error', 'Duplicate entry for key' ) );

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'detail', $result->get_error_data() );
	}

	public function test_submit_form_returns_500_on_exception(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 1 ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		$this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andThrow( new \Exception( 'boom' ) );

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form( $this->make_request( array( 'id' => 1 ), array( 'email' => 'jane@example.com' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}
}

/**
 * Lightweight stand-in for `WP_REST_Response`. The controller's
 * `get_forms()` calls `->header()` to set pagination metadata; tests
 * read `->headers` and `->get_data()` to assert behaviour.
 */
class FakeRestResponse {

	/** @var mixed */
	public $data;

	/** @var array<string, string> */
	public array $headers = array();

	/**
	 * @param mixed $data
	 */
	public function __construct( $data ) {
		$this->data = $data;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	/**
	 * @return mixed
	 */
	public function get_data() {
		return $this->data;
	}
}
