<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\FormRestController;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Repositories\FormRepository;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for FormRestController: route registration, get_forms,
 * get_form, and submit_form endpoints.
 *
 * The injected FormRepository stays a plain instance mock. submit_form()
 * runs the web form's real stages over a `$wpdb` double: RateLimiter (with
 * its transients held in the test), Geofence and GpsReading, ReprintDetector,
 * AccessRestrictionChecker, SubmissionHandler and SubmissionRepository, and
 * Encryption with the bootstrap's key constants. The double answers the
 * rate-limit, auth-code and reprint queries, records every write, and records
 * any other query as unexpected, which assertPostConditions() fails on (the
 * controller catches every exception, a failed assertion included).
 *
 * @covers \FreeFormCertificate\API\FormRestController
 */
class FormRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A public address (the resolver discards loopback and private ones). */
	private const CLIENT_IP = '198.51.100.7';

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var \Mockery\MockInterface&FormRepository Mock for FormRepository (injected) */
	private $form_repo_mock;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/**
	 * Every insert the double ran: [ table, data ].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $inserts = array();

	/**
	 * Every update the double ran: [ table, data, where ].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/** @var list<string> Queries the double did not expect. */
	private array $unexpected = array();

	/** @var int CPF/RF lookups of an earlier submission the controller ran. */
	private int $id_lookups = 0;

	/** @var object|null The earlier submission that lookup finds. */
	private $earlier_row = null;

	/** @var int|false The id the submissions insert answers; false makes it fail. */
	private $submission_id = 1;

	/** @var array<string, mixed> Transients the rate limiter reads, by key. */
	private array $transients = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_SERVER` before this test. */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		$this->registered_routes = [];
		$this->prepared          = array();
		$this->inserts           = array();
		$this->updates           = array();
		$this->unexpected        = array();
		$this->id_lookups        = 0;
		$this->earlier_row       = null;
		$this->submission_id     = 1;
		$this->transients        = array();

		// RateLimitChecker memoises its settings; start every test from the option.
		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		// Capture register_rest_route calls
		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/form/1' );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'home_url' )->alias( function( $path = '' ) { return 'https://example.com' . $path; } );
		// Nothing is configured: every debug area and the activity log are off,
		// and the rate limits run on their defaults.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = array() ) => array_merge( (array) $defaults, (array) $args )
		);
		// An integration's request: the geofence's administrator bypass never applies.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null ) => gmdate( $format, null === $ts ? time() : (int) $ts )
		);

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

		// The caller's address, read by the real client-IP resolver.
		$this->server_backup = $_SERVER;
		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED'], $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'], $_SERVER['HTTP_FORWARDED_FOR'], $_SERVER['HTTP_FORWARDED'] );
		$_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;

		$this->install_wpdb();
	}

	/**
	 * A `$wpdb` double answering the queries the submission stages run.
	 */
	private function install_wpdb(): void {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared[] = array( (string) $sql, $args );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => addcslashes( (string) $v, '_%\\' ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SELECT count FROM %i WHERE type=%s' ) ) {
					// No rate-limit counter stored yet.
					return null;
				}
				if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM %i WHERE email_hash=%s' ) ) {
					return '0';
				}
				if ( false !== strpos( $sql, 'SELECT id FROM %i WHERE auth_code = %s' ) || false !== strpos( $sql, 'SELECT id FROM %i WHERE validation_code = %s' ) ) {
					// The generated code is free.
					return null;
				}
				$this->unexpected[] = 'get_var(): ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SELECT id,count FROM %i WHERE type=%s' ) ) {
					return null;
				}
				if ( preg_match( '/^SELECT \* FROM %i WHERE form_id = %d AND (cpf_hash|rf_hash) = %s ORDER BY id DESC LIMIT 1$/', $sql ) ) {
					++$this->id_lookups;
					// By default no earlier submission of this CPF: not a reprint.
					return $this->earlier_row;
				}
				$this->unexpected[] = 'get_row(): ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) {
				[ $sql ]            = $this->statement( $query );
				$this->unexpected[] = 'get_results(): ' . $sql;
				return array();
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) {
				[ $sql ]            = $this->statement( $query );
				$this->unexpected[] = 'query(): ' . $sql;
				return false;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array( (string) $table, (array) $data );
				if ( 'wp_ffc_submissions' === $table ) {
					if ( false === $this->submission_id ) {
						return false;
					}
					$wpdb->insert_id = $this->submission_id;
					return 1;
				}
				if ( 'wp_ffc_rate_limits' === $table ) {
					return 1;
				}
				$this->unexpected[] = 'insert(): ' . $table;
				return false;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( (string) $table, (array) $data, (array) $where );
				if ( 'wp_ffc_submissions' !== $table ) {
					$this->unexpected[] = 'update(): ' . $table;
				}
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$query = (string) $query;
		if ( 1 === preg_match( '/^Q(\d+)$/', $query, $m ) ) {
			return $this->prepared[ (int) $m[1] ];
		}
		return array( $query, array() );
	}

	/**
	 * The rows written to one table.
	 *
	 * @param string $table Table name, prefixed.
	 * @return list<array<string, mixed>>
	 */
	private function inserted_into( string $table ): array {
		$rows = array();
		foreach ( $this->inserts as [ $name, $data ] ) {
			if ( $table === $name ) {
				$rows[] = $data;
			}
		}
		return $rows;
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a query the test did not expect.' );
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		ActivityLogBuffer::reset();
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
	 * Serve a form's config, fields and geofence the way the stages read
	 * them, and stub what the real stages call.
	 *
	 * @param array<string, mixed>             $config   Form config.
	 * @param array<int, array<string, mixed>> $fields   Form fields.
	 * @param array<string, mixed>|string      $geofence Stored geofence config; '' for none.
	 */
	private function serve_form( array $config, array $fields, $geofence = '' ): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 1 ) );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $config, $fields, $geofence ) {
				if ( '_ffc_form_config' === $key ) {
					return $config;
				}
				if ( '_ffc_geofence_config' === $key ) {
					return $geofence;
				}
				return '_ffc_form_fields' === $key ? $fields : '';
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'current_time' )->justReturn( '2026-10-17 10:00:00' );
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
	}

	/** Fields of a plain certificate form: a name and an email. */
	private function plain_fields(): array {
		return array(
			array( 'type' => 'text', 'name' => 'course', 'label' => 'Course' ),
			array( 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => '1' ),
		);
	}

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/**
	 * The extra data of the one submission row written, decrypted.
	 *
	 * @return array<string, mixed>
	 */
	private function stored_data(): array {
		$rows = $this->inserted_into( 'wp_ffc_submissions' );
		$this->assertCount( 1, $rows, 'Exactly one submission row is written.' );
		$this->assertNull( $rows[0]['data'], 'With encryption configured the plaintext column stays empty.' );
		$json = Encryption::decrypt( (string) $rows[0]['data_encrypted'] );
		$data = json_decode( (string) $json, true );
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_submit_form_stores_only_the_defined_fields_and_never_the_client_auth_code(): void {
		$fields   = $this->plain_fields();
		$fields[] = array( 'type' => 'hidden', 'name' => 'track', 'default_value' => 'official' );
		$this->serve_form( array(), $fields );
		$this->submission_id = 42;

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

		$stored = $this->stored_data();
		$this->assertSame( 'Safety', $stored['course'] );
		$this->assertSame( 'official', $stored['track'], 'A hidden field keeps the form\'s value.' );
		$this->assertArrayNotHasKey( 'not_a_field', $stored, 'A key the form does not define is not stored.' );
		$this->assertArrayNotHasKey( 'auth_code', $stored );
		$row = $this->inserted_into( 'wp_ffc_submissions' )[0];
		$this->assertNotSame( 'CHOSENBYCLNT', $row['auth_code'], 'The caller never chooses the authentication code.' );
		$this->assertSame( 12, strlen( (string) $row['auth_code'] ) );

		$data = $result->get_data();
		$this->assertSame( 42, $data['submission_id'] );
		$this->assertStringNotContainsString( 'CHOS', $data['auth_code'] );
	}

	public function test_submit_form_requires_the_consent_the_web_form_requires(): void {
		$this->serve_form( array(), $this->plain_fields() );

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form( $this->make_request( array( 'id' => 1 ), array( 'email' => 'jane@example.com' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_submissions' ), 'Nothing is stored.' );
	}

	public function test_submit_form_applies_the_password_restriction_from_the_body(): void {
		$this->serve_form(
			array(
				'restrictions'    => array( 'password' => '1' ),
				'validation_code' => 'open-sesame',
			),
			$this->plain_fields()
		);

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
		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_submissions' ), 'Nothing is stored.' );
	}

	public function test_submit_form_passes_the_password_restriction_with_the_right_password(): void {
		$this->serve_form(
			array(
				'restrictions'    => array( 'password' => '1' ),
				'validation_code' => 'open-sesame',
			),
			$this->plain_fields()
		);
		$this->submission_id = 7;

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
		$this->assertCount( 1, $this->inserted_into( 'wp_ffc_submissions' ) );
	}

	public function test_submit_form_applies_the_denylist(): void {
		$fields   = $this->plain_fields();
		$fields[] = array( 'type' => 'text', 'name' => 'cpf_rf', 'label' => 'CPF' );
		$this->serve_form(
			array(
				'restrictions'      => array( 'denylist' => '1' ),
				'denied_users_list' => Identifiers::CPF_A,
			),
			$fields
		);

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'cpf_rf'           => self::masked( Identifiers::CPF_A ),
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_submissions' ), 'Nothing is stored.' );
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
		$this->submission_id = 55;

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
		$this->assertCount( 1, $this->inserted_into( 'wp_ffc_submissions' ) );
		$this->assertSame(
			array( array( 'wp_ffc_submissions', array( 'status' => 'quiz_in_progress' ), array( 'id' => 55 ) ) ),
			$this->updates,
			'The attempt is stored as in progress, not issued.'
		);
	}

	public function test_submit_form_records_the_rate_limit_attempts(): void {
		$this->serve_form( array(), $this->plain_fields() );
		$this->submission_id = 9;

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

		$recorded = array();
		foreach ( $this->inserted_into( 'wp_ffc_rate_limits' ) as $row ) {
			$recorded[] = array( $row['type'], $row['identifier'], $row['form_id'], $row['window_type'] );
		}
		$this->assertSame(
			array(
				array( 'ip', IpAddress::fingerprint( self::CLIENT_IP ), 1, 'day' ),
				array( 'email', hash( 'sha256', 'jane@example.com' ), 1, 'day' ),
				array( 'email', hash( 'sha256', 'jane@example.com' ), 1, 'month' ),
			),
			$recorded,
			'One attempt is recorded for the address and one for the e-mail, against the form.'
		);
	}

	public function test_submit_form_looks_an_earlier_submission_up_once(): void {
		// The rate-limit guard and the persister both ask whether this CPF
		// already has a certificate; they share one lookup (#2241). The
		// certificate is its logged-in owner's, so the reprint is handed over.
		$fields   = $this->plain_fields();
		$fields[] = array( 'type' => 'text', 'name' => 'cpf_rf', 'label' => 'CPF' );
		$this->serve_form( array(), $fields );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		$this->earlier_row = (object) array(
			'id'              => '44',
			'user_id'         => '9',
			'status'          => 'publish',
			'submission_date' => '1750000000',
			'data'            => '{}',
			'auth_code'       => 'ABCD1234EFGH',
		);

		$ctrl   = new FormRestController( 'ffc/v1', $this->form_repo_mock );
		$result = $ctrl->submit_form(
			$this->make_request(
				array( 'id' => 1 ),
				array(
					'email'            => 'jane@example.com',
					'cpf_rf'           => self::masked( Identifiers::CPF_A ),
					'ffc_lgpd_consent' => '1',
				)
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 1, $this->id_lookups );
	}

	public function test_submit_form_answers_429_when_rate_limited(): void {
		$this->serve_form( array(), $this->plain_fields() );
		// The address has already used the hour's default allowance (5).
		$this->transients[ 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::CLIENT_IP ) ) . '_hour' ] = 5;

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
		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_submissions' ), 'Nothing is stored.' );
	}

	public function test_submit_form_answers_403_when_the_geofence_blocks(): void {
		// The form's date window closed long ago.
		$this->serve_form(
			array(),
			$this->plain_fields(),
			array(
				'datetime_enabled' => '1',
				'date_start'       => '2000-01-01',
				'date_end'         => '2000-01-02',
				'msg_datetime'     => 'Outside window',
			)
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
		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_submissions' ), 'Nothing is stored.' );
	}

	public function test_submit_form_hides_the_raw_repository_error(): void {
		$this->serve_form( array(), $this->plain_fields() );
		// The submissions insert fails, so the handler answers a WP_Error.
		$this->submission_id = false;

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

		$this->assertCount( 1, $this->inserted_into( 'wp_ffc_submissions' ), 'The insert was attempted.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'submission_rejected', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'detail', $result->get_error_data() );
	}

	public function test_submit_form_returns_500_on_exception(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 1 ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		// The rate limiter's first read, in the first stage, throws.
		Functions\when( 'get_transient' )->alias(
			static function () {
				throw new \Exception( 'boom' );
			}
		);

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
