<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateRepository;

/**
 * Tests for RecruitmentCandidateRepository — covers CRUD primitives plus the
 * promotion link setter and the hash-based lookups (cpf, rf, email) used by
 * the CSV importer for cross-CSV / cross-notice candidate reuse.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateRepository
 */
class RecruitmentCandidateRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';
		$this->wpdb       = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function () {
					return func_get_args()[0];
				}
			)
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_table_name(): void {
		$this->assertSame( 'wp_ffc_recruitment_candidate', RecruitmentCandidateRepository::get_table_name() );
	}

	public function test_get_by_cpf_hash_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCandidateRepository::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_get_by_cpf_hash_returns_row_on_match(): void {
		$row = (object) array( 'id' => '1', 'cpf_hash' => 'abcd1234' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCandidateRepository::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_create_rejects_when_required_fields_missing(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( RecruitmentCandidateRepository::create( array( 'name' => 'Alice' ) ) );
		$this->assertFalse( RecruitmentCandidateRepository::create( array( 'pcd_hash' => 'h' ) ) );
		$this->assertFalse( RecruitmentCandidateRepository::create( array() ) );
	}

	public function test_create_includes_only_supplied_optional_columns(): void {
		$captured_data   = array();
		$captured_format = array();
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $format ) use ( &$captured_data, &$captured_format ) {
					$captured_data   = $data;
					$captured_format = $format;
					return 1;
				}
			);
		$this->wpdb->insert_id = 50;

		$id = RecruitmentCandidateRepository::create(
			array(
				'name'     => 'Alice',
				'pcd_hash' => 'pcd_hash_value',
				'cpf_hash' => 'cpf_hash_value',
			)
		);

		$this->assertSame( 50, $id );
		$this->assertArrayHasKey( 'name', $captured_data );
		$this->assertArrayHasKey( 'pcd_hash', $captured_data );
		$this->assertArrayHasKey( 'cpf_hash', $captured_data );
		$this->assertArrayNotHasKey( 'rf_hash', $captured_data, 'rf_hash should not be in INSERT when not supplied' );
		$this->assertArrayNotHasKey( 'email_hash', $captured_data );
		$this->assertCount( count( $captured_data ), $captured_format, 'format array must match data array length' );
	}

	public function test_create_returns_false_on_insert_failure(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$result = RecruitmentCandidateRepository::create(
			array(
				'name'     => 'Alice',
				'pcd_hash' => 'h',
			)
		);
		$this->assertFalse( $result );
	}

	public function test_update_does_not_allow_user_id_or_pcd_hash(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		RecruitmentCandidateRepository::update(
			3,
			array(
				'name'     => 'New Name',
				'phone'    => '555-0100',
				'user_id'  => 99,
				'pcd_hash' => 'tampered',
			)
		);

		$this->assertArrayHasKey( 'name', $captured );
		$this->assertArrayHasKey( 'phone', $captured );
		$this->assertArrayNotHasKey( 'user_id', $captured, 'user_id is set via set_user_id, not update' );
		$this->assertArrayNotHasKey( 'pcd_hash', $captured, 'PCD value is CSV-only per §12' );
	}

	public function test_set_user_id_writes_link(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		$this->assertTrue( RecruitmentCandidateRepository::set_user_id( 5, 100 ) );
		$this->assertSame( 100, $captured['user_id'] );
	}

	public function test_set_user_id_can_clear_link(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( $table, $data, $where ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);

		$this->assertTrue( RecruitmentCandidateRepository::set_user_id( 5, null ) );
		$this->assertNull( $captured['user_id'] );
	}

	public function test_get_by_user_id_returns_array_for_each_match(): void {
		$rows = array(
			(object) array( 'id' => '1', 'user_id' => '100' ),
			(object) array( 'id' => '2', 'user_id' => '100' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$result = RecruitmentCandidateRepository::get_by_user_id( 100 );
		$this->assertCount( 2, $result );
	}

	public function test_delete_returns_true_on_success(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$this->assertTrue( RecruitmentCandidateRepository::delete( 5 ) );
	}

	// ------------------------------------------------------------------
	// get_ids_by_email_hash() — issue #331 search frontend
	// ------------------------------------------------------------------

	public function test_get_ids_by_email_hash_returns_all_matches(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '1', '7', '12' ) );

		$ids = RecruitmentCandidateRepository::get_ids_by_email_hash( 'fakehash' );

		$this->assertSame( array( 1, 7, 12 ), $ids );
	}

	public function test_get_ids_by_email_hash_returns_empty_when_no_match(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$ids = RecruitmentCandidateRepository::get_ids_by_email_hash( 'fakehash' );

		$this->assertSame( array(), $ids );
	}

	// ------------------------------------------------------------------
	// get_paginated_filtered() / count_paginated_filtered() — #331
	// ------------------------------------------------------------------

	public function test_get_paginated_filtered_short_circuits_on_empty_id_constraint(): void {
		// id_constraint=[] means "at least one filter matched zero rows".
		// The query should never run.
		$this->wpdb->shouldNotReceive( 'get_results' );

		$rows = RecruitmentCandidateRepository::get_paginated_filtered( '', array(), 0, '', 20, 0 );

		$this->assertSame( array(), $rows );
	}

	public function test_count_paginated_filtered_short_circuits_on_empty_id_constraint(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$total = RecruitmentCandidateRepository::count_paginated_filtered( '', array(), 0, '' );

		$this->assertSame( 0, $total );
	}

	public function test_get_paginated_filtered_executes_query_when_unconstrained(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array( (object) array( 'id' => '5', 'name' => 'Alice' ) ) );

		$rows = RecruitmentCandidateRepository::get_paginated_filtered( '', null, 0, '', 20, 0 );

		$this->assertCount( 1, $rows );
		$this->assertSame( '5', $rows[0]->id );
	}

	public function test_get_paginated_filtered_joins_when_status_filter_present(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();

		// Capture the SQL passed to prepare() so we can assert the JOIN
		// + WHERE clauses fire as expected.
		$captured_sql = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql ) use ( &$captured_sql ) {
					if ( null === $captured_sql ) {
						$captured_sql = $sql;
					}
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		RecruitmentCandidateRepository::get_paginated_filtered( '', null, 0, 'called', 20, 0 );

		$this->assertNotNull( $captured_sql );
		$this->assertStringContainsString( 'INNER JOIN', (string) $captured_sql );
		$this->assertStringContainsString( "cls.list_type = 'definitive'", (string) $captured_sql );
		$this->assertStringContainsString( 'cls.status = %s', (string) $captured_sql );
	}

	public function test_count_paginated_filtered_returns_int_total(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );

		$total = RecruitmentCandidateRepository::count_paginated_filtered( 'Alice', null, 0, '' );

		$this->assertSame( 42, $total );
	}
}
