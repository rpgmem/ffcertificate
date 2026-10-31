<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController;

/**
 * Tests for the recruitment adjutancies REST controller — route registration,
 * the permission gates and every endpoint's success and failure paths.
 *
 * The collaborators are the real ones: RecruitmentAdjutancyReader / Writer,
 * RecruitmentReasonReader / Writer, RecruitmentNoticeReader,
 * RecruitmentNoticeAdjutancyRepository, RecruitmentClassificationRepository,
 * RecruitmentDeleteService, RecruitmentActivityLogger and
 * RecruitmentErrorMessages. They run over a `$wpdb` double that answers rows
 * by table and id, counts references from seeded maps, records every insert,
 * update and delete, and fails the test on any query it was not taught.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutanciesRestController
 */
class RecruitmentAdjutanciesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const T_ADJUTANCY      = 'wp_ffc_recruitment_adjutancy';
	private const T_REASON         = 'wp_ffc_recruitment_reason';
	private const T_NOTICE         = 'wp_ffc_recruitment_notice';
	private const T_JUNCTION       = 'wp_ffc_recruitment_notice_adjutancy';
	private const T_CLASSIFICATION = 'wp_ffc_recruitment_classification';

	private RecruitmentAdjutanciesRestController $controller;

	/** @var array<int, array{namespace: string, route: string, args: array}> */
	private array $registered_routes = array();

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var \Mockery\MockInterface The `$wpdb` double. */
	private $wpdb;

	/** @var array{0: string, 1: array<int, mixed>} SQL and bound arguments of the last prepare(). */
	private array $prepared = array( '', array() );

	/** @var array<string, array<int, object>> Rows the database holds, by table then id. */
	private array $rows = array();

	/** @var array<int, int> Classification rows referencing each reason via preview_reason_id. */
	private array $reason_refs = array();

	/** @var array<int, int> Classification rows referencing each adjutancy. */
	private array $adjutancy_classifications = array();

	/** @var list<array{0: int, 1: int}> Attached (notice_id, adjutancy_id) pairs. */
	private array $junction = array();

	/** @var int|false What `$wpdb->insert()` answers. */
	private $insert_result = 1;

	/** @var int The id `$wpdb->insert_id` takes after a successful insert. */
	private int $next_insert_id = 0;

	/** @var int|false What `$wpdb->update()` answers. */
	private $update_result = 1;

	/** @var int|false What `$wpdb->delete()` answers. */
	private $delete_result = 1;

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as [table, data]. */
	private array $inserts = array();

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every update, as [table, data, where]. */
	private array $updates = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every delete, as [table, where]. */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// An empty ffc_settings turns the activity log off, so the delete
		// service's audit row stops at its first gate without a write.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		$this->registered_routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		$this->rows                      = array();
		$this->reason_refs               = array();
		$this->adjutancy_classifications = array();
		$this->junction                  = array();
		$this->insert_result             = 1;
		$this->next_insert_id            = 0;
		$this->update_result             = 1;
		$this->delete_result             = 1;
		$this->inserts                   = array();
		$this->updates                   = array();
		$this->deletes                   = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->wpdb        = $this->make_wpdb();
		$GLOBALS['wpdb']   = $this->wpdb;

		$this->controller = new RecruitmentAdjutanciesRestController();
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
	 * A `$wpdb` double over the seeded tables. Every read binds the table
	 * first, then the ids; a query shape it does not know fails the test.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_wpdb() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared = array( (string) $sql, $args );
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				$table              = (string) $args[0];
				if ( 'SELECT * FROM %i WHERE id = %d' === $sql
					&& in_array( $table, array( self::T_ADJUTANCY, self::T_REASON, self::T_NOTICE ), true ) ) {
					return $this->rows[ $table ][ (int) $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql . ' on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				$table              = (string) $args[0];
				if ( 0 === strpos( $sql, 'SELECT * FROM %i ORDER BY' )
					&& in_array( $table, array( self::T_ADJUTANCY, self::T_REASON ), true ) ) {
					return array_values( $this->rows[ $table ] ?? array() );
				}
				$this->fail( 'Unexpected get_results: ' . $sql . ' on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				$table              = (string) $args[0];
				if ( self::T_CLASSIFICATION === $table && 'SELECT COUNT(*) FROM %i WHERE preview_reason_id = %d' === $sql ) {
					return (string) ( $this->reason_refs[ (int) $args[1] ] ?? 0 );
				}
				if ( self::T_CLASSIFICATION === $table && 'SELECT COUNT(*) FROM %i WHERE adjutancy_id = %d' === $sql ) {
					return (string) ( $this->adjutancy_classifications[ (int) $args[1] ] ?? 0 );
				}
				if ( self::T_JUNCTION === $table && 'SELECT COUNT(*) FROM %i WHERE notice_id = %d AND adjutancy_id = %d' === $sql ) {
					return in_array( array( (int) $args[1], (int) $args[2] ), $this->junction, true ) ? '1' : '0';
				}
				$this->fail( 'Unexpected get_var: ' . $sql . ' on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				$table              = (string) $args[0];
				if ( self::T_JUNCTION === $table && 'SELECT notice_id FROM %i WHERE adjutancy_id = %d' === $sql ) {
					$ids = array();
					foreach ( $this->junction as $pair ) {
						if ( (int) $args[1] === $pair[1] ) {
							$ids[] = (string) $pair[0];
						}
					}
					return $ids;
				}
				$this->fail( 'Unexpected get_col: ' . $sql . ' on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->inserts[] = array( $table, $data );
				if ( false !== $this->insert_result && $this->insert_result > 0 ) {
					$this->wpdb->insert_id = $this->next_insert_id;
				}
				return $this->insert_result;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return $this->update_result;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( $table, $where );
				return $this->delete_result;
			}
		);
		$wpdb->shouldNotReceive( 'query' );

		return $wpdb;
	}

	/** Store a row in a table, keyed by its id. */
	private function seed( string $table, object $row ): object {
		$this->rows[ $table ][ (int) $row->id ] = $row;
		return $row;
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

	public function test_register_routes_registers_five_route_groups(): void {
		$this->controller->register_routes();

		// Five register_rest_route calls — adjutancies collection + item,
		// reasons collection + item, plus the notice-attach junction.
		$this->assertCount( 5, $this->registered_routes );
	}

	public function test_register_routes_includes_adjutancies_and_reasons_endpoints(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/recruitment/adjutancies', $routes );
		$this->assertContains( '/recruitment/adjutancies/(?P<id>\d+)', $routes );
		$this->assertContains( '/recruitment/reasons', $routes );
		$this->assertContains( '/recruitment/reasons/(?P<id>\d+)', $routes );
	}

	public function test_register_routes_includes_notice_adjutancies_junction(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		// The attach/detach junction routes notices→adjutancies under
		// /recruitment/notices/{id}/adjutancies/{adjutancy_id}.
		$found = false;
		foreach ( $routes as $r ) {
			if ( str_contains( $r, '/notices/' ) && str_contains( $r, '/adjutancies/' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'expected a notices→adjutancies junction route' );
	}

	// ------------------------------------------------------------------
	// list_adjutancies() + list_reasons()
	// ------------------------------------------------------------------

	public function test_list_adjutancies_returns_200_with_repository_payload(): void {
		$row = $this->seed( self::T_ADJUTANCY, (object) array( 'id' => 1, 'slug' => 'a' ) );

		$response = $this->controller->list_adjutancies();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $row ), $response->get_data() );
	}

	public function test_list_reasons_returns_200_with_repository_payload(): void {
		$row = $this->seed( self::T_REASON, (object) array( 'id' => 1, 'slug' => 'denied' ) );

		$response = $this->controller->list_reasons();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $row ), $response->get_data() );
	}

	// ------------------------------------------------------------------
	// permission_callback gates (allow + deny)
	// ------------------------------------------------------------------

	public function test_check_admin_cap_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_admin_cap() );
	}

	public function test_check_admin_cap_allows_with_manage_cap(): void {
		Functions\when( 'current_user_can' )->alias(
			fn( $cap ) => 'ffc_manage_recruitment' === $cap
		);
		$this->assertTrue( $this->controller->check_admin_cap() );
	}

	public function test_check_can_delete_recruitment_allow_and_deny(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_can_delete_recruitment() );

		Functions\when( 'current_user_can' )->alias(
			fn( $cap ) => 'ffc_delete_recruitment' === $cap
		);
		$this->assertTrue( $this->controller->check_can_delete_recruitment() );
	}

	public function test_check_can_manage_reasons_allow_and_deny(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_can_manage_reasons() );

		Functions\when( 'current_user_can' )->alias(
			fn( $cap ) => 'ffc_manage_recruitment_reasons' === $cap
		);
		$this->assertTrue( $this->controller->check_can_manage_reasons() );
	}

	// ------------------------------------------------------------------
	// create_adjutancy()
	// ------------------------------------------------------------------

	public function test_create_adjutancy_returns_409_on_duplicate_slug(): void {
		// The UNIQUE slug constraint refuses the insert.
		$this->insert_result = false;

		$result = $this->controller->create_adjutancy(
			$this->make_request( array( 'slug' => 'dup', 'name' => 'Dup', 'color' => '' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_adjutancy_create_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( self::T_ADJUTANCY, $this->inserts[0][0] );
		$this->assertSame( 'dup', $this->inserts[0][1]['slug'] );
	}

	public function test_create_adjutancy_returns_201_on_success(): void {
		$this->next_insert_id = 5;
		$row                  = $this->seed( self::T_ADJUTANCY, (object) array( 'id' => 5, 'slug' => 'new', 'name' => 'New' ) );

		$response = $this->controller->create_adjutancy(
			$this->make_request( array( 'slug' => 'new', 'name' => 'New', 'color' => '#fff' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $row, $response->get_data() );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( self::T_ADJUTANCY, $this->inserts[0][0] );
		$this->assertSame( 'new', $this->inserts[0][1]['slug'] );
		$this->assertSame( 'New', $this->inserts[0][1]['name'] );
		$this->assertSame( '#fff', $this->inserts[0][1]['color'] );
	}

	// ------------------------------------------------------------------
	// update_adjutancy()
	// ------------------------------------------------------------------

	public function test_update_adjutancy_returns_400_on_failure(): void {
		$this->update_result = false;

		$result = $this->controller->update_adjutancy(
			$this->make_request( array( 'id' => 3, 'name' => 'X' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_adjutancy_update_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 3 ), $this->updates[0][2] );
	}

	public function test_update_adjutancy_returns_200_on_success(): void {
		$row = $this->seed( self::T_ADJUTANCY, (object) array( 'id' => 3, 'name' => 'Renamed' ) );

		$response = $this->controller->update_adjutancy(
			$this->make_request( array( 'id' => 3, 'name' => 'Renamed', 'ignored' => 'x' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $row, $response->get_data() );
		$this->assertCount( 1, $this->updates );
		list( $table, $data, $where ) = $this->updates[0];
		$this->assertSame( self::T_ADJUTANCY, $table );
		$this->assertSame( array( 'id' => 3 ), $where );
		$this->assertSame( 'Renamed', $data['name'] );
		$this->assertArrayNotHasKey( 'ignored', $data, 'A key outside slug/name/color never reaches the writer.' );
	}

	// ------------------------------------------------------------------
	// delete_adjutancy()
	// ------------------------------------------------------------------

	public function test_delete_adjutancy_returns_409_envelope_when_blocked(): void {
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 8, 'slug' => 'busy' ) );
		$this->adjutancy_classifications[8] = 4;

		$result = $this->controller->delete_adjutancy( $this->make_request( array( 'id' => 8 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_adjutancy_in_use', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		// The real service reports both reference counts.
		$this->assertSame(
			array(
				'notice_adjutancies' => 0,
				'classifications'    => 4,
			),
			$data['blocked_by']
		);
		$this->assertSame( array(), $this->deletes, 'A blocked adjutancy is never deleted.' );
	}

	public function test_delete_adjutancy_returns_200_on_success(): void {
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 8, 'slug' => 'free' ) );

		$response = $this->controller->delete_adjutancy( $this->make_request( array( 'id' => 8 ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'success' => true,
				'errors'  => array(),
			),
			$response->get_data()
		);
		$this->assertSame( array( array( self::T_ADJUTANCY, array( 'id' => 8 ) ) ), $this->deletes );
	}

	// ------------------------------------------------------------------
	// create_reason()
	// ------------------------------------------------------------------

	public function test_create_reason_returns_409_on_duplicate_slug(): void {
		$this->insert_result = false;

		$result = $this->controller->create_reason(
			$this->make_request( array( 'slug' => 'dup', 'label' => 'Dup' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_reason_create_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( self::T_REASON, $this->inserts[0][0] );
	}

	public function test_create_reason_returns_201_and_filters_applies_to(): void {
		$this->next_insert_id = 9;
		$row                  = $this->seed( self::T_REASON, (object) array( 'id' => 9, 'slug' => 'denied' ) );

		$response = $this->controller->create_reason(
			$this->make_request(
				array(
					'slug'       => 'denied',
					'label'      => 'Denied',
					'color'      => '#000',
					// Mixed array: non-string entries must be filtered out.
					'applies_to' => array( 'denied', 123, 'granted' ),
				)
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $row, $response->get_data() );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'denied,granted', $this->inserts[0][1]['applies_to'] );
	}

	public function test_create_reason_handles_non_array_applies_to(): void {
		$this->insert_result = false;

		$this->controller->create_reason(
			$this->make_request( array( 'slug' => 's', 'label' => 'L', 'applies_to' => 'not-array' ) )
		);

		$this->assertCount( 1, $this->inserts );
		// An empty selection is stored as '' ("applies to every status").
		$this->assertSame( '', $this->inserts[0][1]['applies_to'] );
	}

	// ------------------------------------------------------------------
	// update_reason()
	// ------------------------------------------------------------------

	public function test_update_reason_returns_400_on_failure(): void {
		$this->update_result = false;

		$result = $this->controller->update_reason(
			$this->make_request( array( 'id' => 4, 'label' => 'X' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_reason_update_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 4 ), $this->updates[0][2] );
	}

	public function test_update_reason_returns_200_on_success(): void {
		$row = $this->seed( self::T_REASON, (object) array( 'id' => 4, 'label' => 'Renamed' ) );

		$response = $this->controller->update_reason(
			$this->make_request( array( 'id' => 4, 'label' => 'Renamed' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $row, $response->get_data() );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( self::T_REASON, $this->updates[0][0] );
		$this->assertSame( 'Renamed', $this->updates[0][1]['label'] );
		$this->assertSame( array( 'id' => 4 ), $this->updates[0][2] );
	}

	// ------------------------------------------------------------------
	// delete_reason()
	// ------------------------------------------------------------------

	public function test_delete_reason_returns_409_when_in_use(): void {
		$this->reason_refs[6] = 3;

		$result = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_reason_in_use', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 3, $data['reference_count'] );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_delete_reason_returns_400_when_writer_fails(): void {
		$this->delete_result = false;

		$result = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_reason_delete_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array( array( self::T_REASON, array( 'id' => 6 ) ) ), $this->deletes );
	}

	public function test_delete_reason_returns_200_on_success(): void {
		$response = $this->controller->delete_reason( $this->make_request( array( 'id' => 6 ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );
		$this->assertSame( array( array( self::T_REASON, array( 'id' => 6 ) ) ), $this->deletes );
	}

	// ------------------------------------------------------------------
	// attach_notice_adjutancy()
	// ------------------------------------------------------------------

	public function test_attach_returns_404_when_notice_missing(): void {
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 2 ) );

		$result = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_attach_returns_404_when_adjutancy_missing(): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => 'draft' ) );

		$result = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_adjutancy_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->inserts );
	}

	public function test_attach_returns_200_created_false_when_already_attached(): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => 'draft' ) );
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 2 ) );
		$this->junction[] = array( 1, 2 );

		$response = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['created'] );
		$this->assertSame( array(), $this->inserts, 'An attached pair is not inserted again.' );
	}

	public function test_attach_returns_500_when_attach_fails(): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => 'draft' ) );
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 2 ) );
		$this->insert_result = false;

		$result = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_adjutancy_attach_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame(
			array( array( self::T_JUNCTION, array( 'notice_id' => 1, 'adjutancy_id' => 2 ) ) ),
			$this->inserts
		);
	}

	public function test_attach_returns_201_created_true_on_success(): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => 'draft' ) );
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 2 ) );

		$response = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['created'] );
		$this->assertSame( 1, $data['notice_id'] );
		$this->assertSame( 2, $data['adjutancy_id'] );
		$this->assertSame(
			array( array( self::T_JUNCTION, array( 'notice_id' => 1, 'adjutancy_id' => 2 ) ) ),
			$this->inserts
		);
	}

	// ------------------------------------------------------------------
	// detach_notice_adjutancy()
	// ------------------------------------------------------------------

	public function test_detach_returns_200_with_attached_false(): void {
		$response = $this->controller->detach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['attached'] );
		$this->assertSame( 1, $data['notice_id'] );
		$this->assertSame( 2, $data['adjutancy_id'] );
		$this->assertSame(
			array( array( self::T_JUNCTION, array( 'notice_id' => 1, 'adjutancy_id' => 2 ) ) ),
			$this->deletes
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function locked_statuses(): array {
		return array(
			'definitive' => array( 'definitive' ),
			'closed'     => array( 'closed' ),
		);
	}

	/**
	 * @dataProvider locked_statuses
	 */
	public function test_attach_is_refused_once_the_notice_is_past_preliminary( string $status ): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => $status ) );
		$this->seed( self::T_ADJUTANCY, (object) array( 'id' => 2 ) );

		$result = $this->controller->attach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_adjutancies_locked', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->inserts );
	}

	/**
	 * @dataProvider locked_statuses
	 */
	public function test_detach_is_refused_once_the_notice_is_past_preliminary( string $status ): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => $status ) );

		$result = $this->controller->detach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_notice_adjutancies_locked', $result->get_error_code() );
		$this->assertSame( array(), $this->deletes, 'Nothing is detached from a published notice.' );
	}

	public function test_a_preliminary_notice_still_takes_a_detach(): void {
		$this->seed( self::T_NOTICE, (object) array( 'id' => 1, 'status' => 'preliminary' ) );

		$response = $this->controller->detach_notice_adjutancy(
			$this->make_request( array( 'id' => 1, 'adjutancy_id' => 2 ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertCount( 1, $this->deletes );
	}
}
