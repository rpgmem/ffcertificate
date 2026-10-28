<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticesRestController;

/**
 * Tests for the recruitment notices REST controller — route registration
 * plus the three endpoint handlers (list / create / update).
 *
 * The reader, the writer, the state machine and the error-message catalogue
 * are the real classes, over a `$wpdb` double that holds the notice rows a
 * test stores, answers the reads by id and status, and records every insert
 * and update. A query the double does not expect fails the test.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticesRestController
 */
class RecruitmentNoticesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecruitmentNoticesRestController $controller;

	/** @var array<int, array{namespace: string, route: string, args: array}> */
	private array $registered_routes = array();

	/**
	 * Stored notices, by id.
	 *
	 * @var array<int, object>
	 */
	private array $notices = array();

	/**
	 * Rows `get_all()` answers, by the status filter ('' for none).
	 *
	 * @var array<string, list<object>>
	 */
	private array $listed = array();

	/** @var list<string> Every `get_results()` statement, as prepared. */
	private array $list_queries = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as `[ table, data ]`. */
	private array $inserts = array();

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every update, as `[ table, data, where ]`. */
	private array $updates = array();

	/** @var int|false What the next `insert()` returns. */
	private $insert_result = 1;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->registered_routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		$this->notices       = array();
		$this->listed        = array();
		$this->list_queries  = array();
		$this->inserts       = array();
		$this->updates       = array();
		$this->insert_result = 1;
		$GLOBALS['wpdb']     = $this->database();

		$this->controller = new RecruitmentNoticesRestController();
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
	 * A database over `$this->notices`; only the statements the notice
	 * reader and writer issue are answered.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;

		// Interpolates, so the reads below can dispatch on the statement.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_notice WHERE id = (\d+)$/', (string) $sql, $m ) ) {
					return $this->notices[ (int) $m[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->list_queries[] = (string) $sql;
				if ( 'SELECT * FROM wp_ffc_recruitment_notice ORDER BY created_at DESC' === $sql ) {
					return $this->listed[''] ?? array();
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_notice WHERE status = (\w+) ORDER BY created_at DESC$/', (string) $sql, $m ) ) {
					return $this->listed[ $m[1] ] ?? array();
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array( $table, $data );
				if ( false !== $this->insert_result ) {
					$wpdb->insert_id = 5;
					$this->notices[5] = (object) array_merge( array( 'id' => 5 ), $data );
				}
				return $this->insert_result;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		return $wpdb;
	}

	private function make_request( array $params ): \WP_REST_Request {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
		$req->shouldReceive( 'get_params' )->andReturn( $params );
		return $req;
	}

	// ------------------------------------------------------------------
	// register_routes()
	// ------------------------------------------------------------------

	public function test_register_routes_registers_collection_and_item_endpoints(): void {
		$this->controller->register_routes();

		$this->assertCount( 2, $this->registered_routes );
		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/recruitment/notices', $routes );
		$this->assertContains( '/recruitment/notices/(?P<id>\d+)', $routes );
	}

	public function test_register_routes_wires_view_tier_reads_and_admin_writes(): void {
		$this->controller->register_routes();

		$seen = array();
		foreach ( $this->registered_routes as $entry ) {
			$callbacks = $this->collect_permission_callbacks( $entry['args'] );
			foreach ( $callbacks as $cb ) {
				$this->assertSame( $this->controller, $cb[0] );
				// #739 §2.3 — reads accept the view tier; writes stay admin-only.
				$this->assertContains( $cb[1], array( 'check_admin_cap', 'check_can_view_recruitment' ) );
				$seen[ $cb[1] ] = true;
			}
		}
		// The list read was actually rewired to the view tier.
		$this->assertArrayHasKey( 'check_can_view_recruitment', $seen );
		$this->assertArrayHasKey( 'check_admin_cap', $seen );
	}

	/**
	 * @return list<callable>
	 */
	private function collect_permission_callbacks( array $args ): array {
		$out = array();
		if ( isset( $args['permission_callback'] ) ) {
			$out[] = $args['permission_callback'];
			return $out;
		}
		foreach ( $args as $v ) {
			if ( is_array( $v ) ) {
				$out = array_merge( $out, $this->collect_permission_callbacks( $v ) );
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// list_notices()
	// ------------------------------------------------------------------

	public function test_list_notices_returns_200_with_repository_payload(): void {
		$notices          = array(
			(object) array( 'id' => 1, 'code' => 'EDITAL-A' ),
			(object) array( 'id' => 2, 'code' => 'EDITAL-B' ),
		);
		$this->listed[''] = $notices;

		$response = $this->controller->list_notices( $this->make_request( array() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $notices, $response->get_data() );
		$this->assertSame( array( 'SELECT * FROM wp_ffc_recruitment_notice ORDER BY created_at DESC' ), $this->list_queries, 'No status filter reads every notice.' );
	}

	public function test_list_notices_passes_status_filter_through_to_repository(): void {
		$open                 = array( (object) array( 'id' => 3, 'code' => 'EDITAL-C' ) );
		$this->listed['open'] = $open;

		$response = $this->controller->list_notices( $this->make_request( array( 'status' => 'open' ) ) );

		$this->assertSame( array( 'SELECT * FROM wp_ffc_recruitment_notice WHERE status = open ORDER BY created_at DESC' ), $this->list_queries );
		$this->assertSame( $open, $response->get_data() );
	}

	// ------------------------------------------------------------------
	// create_notice()
	// ------------------------------------------------------------------

	public function test_create_notice_returns_201_on_success(): void {
		$response = $this->controller->create_notice(
			$this->make_request( array( 'code' => 'new', 'name' => 'New' ) )
		);

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'wp_ffc_recruitment_notice', $this->inserts[0][0] );
		$this->assertSame( 'NEW', $this->inserts[0][1]['code'], 'The code is stored uppercased.' );
		$this->assertSame( 'New', $this->inserts[0][1]['name'] );
		$this->assertSame( 'draft', $this->inserts[0][1]['status'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $this->notices[5], $response->get_data(), 'The response carries the row read back by the new id.' );
	}

	public function test_create_notice_returns_wp_error_with_409_on_duplicate(): void {
		// A unique-key violation: the insert reports failure.
		$this->insert_result = false;

		$result = $this->controller->create_notice(
			$this->make_request( array( 'code' => 'DUP', 'name' => 'Dup' ) )
		);

		$this->assertCount( 1, $this->inserts );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_create_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// update_notice()
	// ------------------------------------------------------------------

	public function test_update_notice_returns_404_when_notice_missing(): void {
		// No notice 999 is stored; the meta update itself reports success.
		$result = $this->controller->update_notice(
			$this->make_request( array( 'id' => 999, 'name' => 'X' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_update_notice_returns_409_when_state_machine_rejects_transition(): void {
		// draft -> closed is not an allowed transition.
		$this->notices[1] = (object) array(
			'id'     => 1,
			'code'   => 'EDITAL-A',
			'status' => 'draft',
		);

		$result = $this->controller->update_notice(
			$this->make_request( array( 'id' => 1, 'status' => 'closed' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'recruitment_invalid_transition: draft->closed', $result->get_error_code() );
		$this->assertSame( array(), $this->updates, 'A refused transition writes nothing.' );
	}

	public function test_update_notice_passes_meta_fields_through_to_repository(): void {
		$this->notices[7] = (object) array(
			'id'     => 7,
			'status' => 'draft',
		);

		$response = $this->controller->update_notice(
			$this->make_request(
				array(
					'id'                    => 7,
					'name'                  => 'Updated Name',
					'public_columns_config' => 'cfg',
					'ignored_field'         => 'nope',
				)
			)
		);

		$this->assertCount( 1, $this->updates );
		[ $table, $data, $where ] = $this->updates[0];
		$this->assertSame( 'wp_ffc_recruitment_notice', $table );
		$this->assertSame( array( 'id' => 7 ), $where );
		$this->assertSame( 'Updated Name', $data['name'] );
		$this->assertSame( 'cfg', $data['public_columns_config'] );
		$this->assertArrayNotHasKey( 'ignored_field', $data );
		$this->assertSame( 200, $response->get_status() );
	}
}
