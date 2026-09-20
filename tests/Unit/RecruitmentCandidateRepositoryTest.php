<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateReader;
use FreeFormCertificate\Recruitment\RecruitmentCandidateWriter;

/**
 * Tests for the candidate repository read/write split — covers CRUD primitives
 * plus the promotion link setter and the hash-based lookups (cpf, rf, email)
 * used by the CSV importer for cross-CSV / cross-notice candidate reuse.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateReader
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateWriter
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

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so preload the reader/writer here for correct coverage attribution.
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );

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
		$this->assertSame( 'wp_ffc_recruitment_candidate', RecruitmentCandidateReader::get_table_name() );
	}

	public function test_get_by_cpf_hash_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCandidateReader::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_get_by_cpf_hash_returns_row_on_match(): void {
		$row = (object) array( 'id' => '1', 'cpf_hash' => 'abcd1234' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCandidateReader::get_by_cpf_hash( 'abcd1234' ) );
	}

	public function test_create_rejects_when_required_fields_missing(): void {
		$this->wpdb->shouldNotReceive( 'insert' );

		$this->assertFalse( RecruitmentCandidateWriter::create( array( 'name' => 'Alice' ) ) );
		$this->assertFalse( RecruitmentCandidateWriter::create( array( 'pcd_hash' => 'h' ) ) );
		$this->assertFalse( RecruitmentCandidateWriter::create( array() ) );
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

		$id = RecruitmentCandidateWriter::create(
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

		$result = RecruitmentCandidateWriter::create(
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

		RecruitmentCandidateWriter::update(
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

	/**
	 * Every unlinked candidacy carrying the hash is claimed, not one.
	 *
	 * One person applying to two positions is two rows under the same
	 * identifier — legitimate, and the reason `COUNT(DISTINCT)` does not flag
	 * it as a conflict. A `LIMIT 1` here would adopt one candidacy and leave
	 * its sibling orphaned (#1345).
	 */
	public function test_link_orphans_claims_every_matching_row(): void {
		$statements = array();

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '7', '9', '11' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturnUsing(
			function ( $sql ) use ( &$statements ) {
				$statements[] = (string) $sql;
				return 3;
			}
		);

		$claimed = RecruitmentCandidateWriter::link_orphans_by_hash( 'cpfhash', 'rfhash', 100 );

		$this->assertSame( 3, $claimed );
		$this->assertNotEmpty( $statements );
		$this->assertStringNotContainsStringIgnoringCase( 'limit', $statements[0], 'Claiming one row would orphan its sibling candidacy.' );
		$this->assertStringContainsString( 'user_id IS NULL', $statements[0], 'A candidacy already linked to somebody is never taken.' );
	}

	/**
	 * Each claimed row's cached copy is dropped.
	 *
	 * `RecruitmentCandidateReader::get_by_id()` caches under `id_<id>`, so a
	 * bulk `UPDATE` alone leaves a cached row still claiming no user —
	 * invisible without a persistent object cache and permanent with one.
	 * Reading the ids before the write is what makes this possible, and it is
	 * why the method lives here rather than as SQL issued by the caller: the
	 * cache group is this class's own.
	 */
	public function test_link_orphans_invalidates_each_claimed_row(): void {
		$deleted = array();
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '7', '9' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );

		RecruitmentCandidateWriter::link_orphans_by_hash( null, 'rfhash', 100 );

		$this->assertSame( array( 'id_7', 'id_9' ), $deleted );
	}

	/**
	 * Nothing matching means no write at all.
	 */
	public function test_link_orphans_writes_nothing_when_no_row_matches(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, RecruitmentCandidateWriter::link_orphans_by_hash( 'cpfhash', null, 100 ) );
	}

	/**
	 * A call with nothing to match on never reaches the database.
	 *
	 * Both hashes empty would build an empty `WHERE`, and a missing user id
	 * would write a link to nobody — either one claims every orphan in the
	 * table.
	 */
	public function test_link_orphans_refuses_a_call_it_cannot_scope(): void {
		$this->wpdb->shouldNotReceive( 'get_col' );
		$this->wpdb->shouldNotReceive( 'query' );

		$this->assertSame( 0, RecruitmentCandidateWriter::link_orphans_by_hash( null, null, 100 ) );
		$this->assertSame( 0, RecruitmentCandidateWriter::link_orphans_by_hash( '', '', 100 ) );
		$this->assertSame( 0, RecruitmentCandidateWriter::link_orphans_by_hash( 'cpfhash', 'rfhash', 0 ) );
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

		$this->assertTrue( RecruitmentCandidateWriter::set_user_id( 5, 100 ) );
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

		$this->assertTrue( RecruitmentCandidateWriter::set_user_id( 5, null ) );
		$this->assertNull( $captured['user_id'] );
	}

	public function test_get_by_user_id_returns_array_for_each_match(): void {
		$rows = array(
			(object) array( 'id' => '1', 'user_id' => '100' ),
			(object) array( 'id' => '2', 'user_id' => '100' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$result = RecruitmentCandidateReader::get_by_user_id( 100 );
		$this->assertCount( 2, $result );
	}

	public function test_delete_returns_true_on_success(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 1 );

		$this->assertTrue( RecruitmentCandidateWriter::delete( 5 ) );
	}

	// ------------------------------------------------------------------
	// get_ids_by_email_hash() — issue #331 search frontend
	// ------------------------------------------------------------------

	public function test_get_ids_by_email_hash_returns_all_matches(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '1', '7', '12' ) );

		$ids = RecruitmentCandidateReader::get_ids_by_email_hash( 'fakehash' );

		$this->assertSame( array( 1, 7, 12 ), $ids );
	}

	public function test_get_ids_by_email_hash_returns_empty_when_no_match(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$ids = RecruitmentCandidateReader::get_ids_by_email_hash( 'fakehash' );

		$this->assertSame( array(), $ids );
	}

	// ------------------------------------------------------------------
	// get_paginated_filtered() / count_paginated_filtered() — #331
	// ------------------------------------------------------------------

	public function test_get_paginated_filtered_short_circuits_on_empty_id_constraint(): void {
		// id_constraint=[] means "at least one filter matched zero rows".
		// The query should never run.
		$this->wpdb->shouldNotReceive( 'get_results' );

		$rows = RecruitmentCandidateReader::get_paginated_filtered( '', array(), 0, '', 20, 0 );

		$this->assertSame( array(), $rows );
	}

	public function test_count_paginated_filtered_short_circuits_on_empty_id_constraint(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$total = RecruitmentCandidateReader::count_paginated_filtered( '', array(), 0, '' );

		$this->assertSame( 0, $total );
	}

	public function test_get_paginated_filtered_executes_query_when_unconstrained(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( array( (object) array( 'id' => '5', 'name' => 'Alice' ) ) );

		$rows = RecruitmentCandidateReader::get_paginated_filtered( '', null, 0, '', 20, 0 );

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

		RecruitmentCandidateReader::get_paginated_filtered( '', null, 0, 'called', 20, 0 );

		$this->assertNotNull( $captured_sql );
		$this->assertStringContainsString( 'INNER JOIN', (string) $captured_sql );
		$this->assertStringContainsString( "cls.list_type = 'definitive'", (string) $captured_sql );
		$this->assertStringContainsString( 'cls.status = %s', (string) $captured_sql );
	}

	public function test_count_paginated_filtered_returns_int_total(): void {
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );

		$total = RecruitmentCandidateReader::count_paginated_filtered( 'Alice', null, 0, '' );

		$this->assertSame( 42, $total );
	}
}
