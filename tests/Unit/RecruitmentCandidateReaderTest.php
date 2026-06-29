<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateReader;

/**
 * Tests for the candidate reader — every SELECT / lookup / count query for
 * `ffc_recruitment_candidate` rows. Drives the global `$wpdb` mock to return
 * fixtures and exercises both happy-path and empty/not-found branches, plus
 * the object-cache hit/miss paths of {@see RecruitmentCandidateReader::get_by_id()}.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateReader
 */
class RecruitmentCandidateReaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov coverage-attribution: preload the class so its lines attribute here.
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// prepare() returns the SQL literal unchanged; esc_like() is identity.
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function () {
					return func_get_args()[0];
				}
			)
			->byDefault();
		$this->wpdb->shouldReceive( 'esc_like' )
			->andReturnUsing(
				static function ( $v ) {
					return $v;
				}
			)
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return \stdClass */
	private function row( int $id, string $name = 'Alice' ): \stdClass {
		$o             = new \stdClass();
		$o->id         = (string) $id;
		$o->name       = $name;
		$o->cpf_hash   = 'cpfhash' . $id;
		$o->rf_hash    = 'rfhash' . $id;
		$o->email_hash = 'emailhash' . $id;
		$o->user_id    = '0';
		return $o;
	}

	// ------------------------------------------------------------------
	// get_table_name
	// ------------------------------------------------------------------

	public function test_get_table_name(): void {
		$this->assertSame( 'wp_ffc_recruitment_candidate', RecruitmentCandidateReader::get_table_name() );
	}

	// ------------------------------------------------------------------
	// get_by_id — cache miss (hit DB), found + not found, and cache hit
	// ------------------------------------------------------------------

	public function test_get_by_id_returns_row_on_cache_miss(): void {
		$row = $this->row( 5 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = RecruitmentCandidateReader::get_by_id( 5 );
		$this->assertSame( $row, $result );
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCandidateReader::get_by_id( 999 ) );
	}

	public function test_get_by_id_returns_cached_value_without_query(): void {
		$cached = $this->row( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( $cached );
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assertSame( $cached, RecruitmentCandidateReader::get_by_id( 7 ) );
	}

	// ------------------------------------------------------------------
	// get_by_ids
	// ------------------------------------------------------------------

	public function test_get_by_ids_returns_empty_for_empty_input(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );
		$this->assertSame( array(), RecruitmentCandidateReader::get_by_ids( array() ) );
	}

	public function test_get_by_ids_filters_invalid_then_returns_empty(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );
		// All non-positive after intval -> nothing to query.
		$this->assertSame( array(), RecruitmentCandidateReader::get_by_ids( array( 0, -3, 'abc' ) ) );
	}

	public function test_get_by_ids_returns_keyed_map(): void {
		$r1 = $this->row( 1 );
		$r2 = $this->row( 2 );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $r1, $r2 ) );

		$out = RecruitmentCandidateReader::get_by_ids( array( 1, 2, 2, 1 ) );
		$this->assertSame( array( 1 => $r1, 2 => $r2 ), $out );
	}

	public function test_get_by_ids_skips_rows_with_invalid_id(): void {
		$bad      = new \stdClass();
		$bad->id  = '0';
		$good     = $this->row( 3 );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array( $bad, $good ) );

		$out = RecruitmentCandidateReader::get_by_ids( array( 3 ) );
		$this->assertSame( array( 3 => $good ), $out );
	}

	public function test_get_by_ids_returns_empty_when_non_array_result(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );
		$this->assertSame( array(), RecruitmentCandidateReader::get_by_ids( array( 1 ) ) );
	}

	// ------------------------------------------------------------------
	// get_by_cpf_hash / get_by_rf_hash / get_by_email_hash
	// ------------------------------------------------------------------

	public function test_get_by_cpf_hash_found_and_not_found(): void {
		$row = $this->row( 8 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );
		$this->assertSame( $row, RecruitmentCandidateReader::get_by_cpf_hash( 'cpfhash8' ) );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->assertNull( RecruitmentCandidateReader::get_by_cpf_hash( 'missing' ) );
	}

	public function test_get_by_rf_hash_found_and_not_found(): void {
		$row = $this->row( 9 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );
		$this->assertSame( $row, RecruitmentCandidateReader::get_by_rf_hash( 'rfhash9' ) );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->assertNull( RecruitmentCandidateReader::get_by_rf_hash( 'missing' ) );
	}

	public function test_get_by_email_hash_found_and_not_found(): void {
		$row = $this->row( 10 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );
		$this->assertSame( $row, RecruitmentCandidateReader::get_by_email_hash( 'emailhash10' ) );

		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->assertNull( RecruitmentCandidateReader::get_by_email_hash( 'missing' ) );
	}

	// ------------------------------------------------------------------
	// get_by_user_id
	// ------------------------------------------------------------------

	public function test_get_by_user_id_returns_rows(): void {
		$rows = array( $this->row( 1 ), $this->row( 2 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->assertSame( $rows, RecruitmentCandidateReader::get_by_user_id( 42 ) );
	}

	public function test_get_by_user_id_returns_empty_when_none(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );
		$this->assertSame( array(), RecruitmentCandidateReader::get_by_user_id( 42 ) );
	}

	// ------------------------------------------------------------------
	// get_paginated (with and without name filter)
	// ------------------------------------------------------------------

	public function test_get_paginated_without_name_search(): void {
		$rows = array( $this->row( 1 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->assertSame( $rows, RecruitmentCandidateReader::get_paginated( '', 20, 0 ) );
	}

	public function test_get_paginated_with_name_search(): void {
		$rows = array( $this->row( 1, 'Bob' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->assertSame( $rows, RecruitmentCandidateReader::get_paginated( 'Bob', 50, 10 ) );
	}

	public function test_get_paginated_clamps_limit_and_offset(): void {
		// limit > 200 clamps to 200, negative offset clamps to 0; null result -> [].
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );
		$this->assertSame( array(), RecruitmentCandidateReader::get_paginated( '', 9999, -5 ) );
	}

	// ------------------------------------------------------------------
	// get_paginated_for_adjutancy / count_paginated_for_adjutancy
	// ------------------------------------------------------------------

	public function test_get_paginated_for_adjutancy_without_name(): void {
		$rows = array( $this->row( 1 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->assertSame( $rows, RecruitmentCandidateReader::get_paginated_for_adjutancy( '', 3, 20, 0 ) );
	}

	public function test_get_paginated_for_adjutancy_with_name_and_empty_result(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );
		$this->assertSame( array(), RecruitmentCandidateReader::get_paginated_for_adjutancy( 'Carol', 3, 20, 0 ) );
	}

	public function test_count_paginated_for_adjutancy_without_name(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );
		$this->assertSame( 4, RecruitmentCandidateReader::count_paginated_for_adjutancy( '', 3 ) );
	}

	public function test_count_paginated_for_adjutancy_with_name_returns_zero_when_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->assertSame( 0, RecruitmentCandidateReader::count_paginated_for_adjutancy( 'Dave', 3 ) );
	}

	// ------------------------------------------------------------------
	// count_paginated (with and without name)
	// ------------------------------------------------------------------

	public function test_count_paginated_without_name(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '12' );
		$this->assertSame( 12, RecruitmentCandidateReader::count_paginated( '' ) );
	}

	public function test_count_paginated_with_name(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );
		$this->assertSame( 3, RecruitmentCandidateReader::count_paginated( 'Eve' ) );
	}

	public function test_count_paginated_returns_zero_when_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->assertSame( 0, RecruitmentCandidateReader::count_paginated( '' ) );
	}

	// ------------------------------------------------------------------
	// get_ids_by_email_hash
	// ------------------------------------------------------------------

	public function test_get_ids_by_email_hash_returns_int_list(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '1', '2', '3' ) );
		$this->assertSame( array( 1, 2, 3 ), RecruitmentCandidateReader::get_ids_by_email_hash( 'emailhash' ) );
	}

	public function test_get_ids_by_email_hash_empty(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->assertSame( array(), RecruitmentCandidateReader::get_ids_by_email_hash( 'missing' ) );
	}

	// ------------------------------------------------------------------
	// get_paginated_filtered — all filter combinations
	// ------------------------------------------------------------------

	public function test_get_paginated_filtered_empty_id_constraint_short_circuits(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );
		$this->assertSame(
			array(),
			RecruitmentCandidateReader::get_paginated_filtered( '', array(), 0, '', 20, 0 )
		);
	}

	public function test_get_paginated_filtered_no_filters(): void {
		$rows = array( $this->row( 1 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$this->assertSame(
			$rows,
			RecruitmentCandidateReader::get_paginated_filtered( '', null, 0, '', 20, 0 )
		);
	}

	public function test_get_paginated_filtered_all_filters(): void {
		$rows = array( $this->row( 2 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );
		$result = RecruitmentCandidateReader::get_paginated_filtered(
			'Frank',
			array( 1, 2, 2 ),
			5,
			'called',
			50,
			10
		);
		$this->assertSame( $rows, $result );
	}

	public function test_get_paginated_filtered_returns_empty_when_null(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );
		$this->assertSame(
			array(),
			RecruitmentCandidateReader::get_paginated_filtered( '', null, 3, '', 20, 0 )
		);
	}

	// ------------------------------------------------------------------
	// count_paginated_filtered — all filter combinations
	// ------------------------------------------------------------------

	public function test_count_paginated_filtered_empty_id_constraint_short_circuits(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );
		$this->assertSame(
			0,
			RecruitmentCandidateReader::count_paginated_filtered( '', array(), 0, '' )
		);
	}

	public function test_count_paginated_filtered_no_filters(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '7' );
		$this->assertSame(
			7,
			RecruitmentCandidateReader::count_paginated_filtered( '', null, 0, '' )
		);
	}

	public function test_count_paginated_filtered_all_filters(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );
		$result = RecruitmentCandidateReader::count_paginated_filtered(
			'Grace',
			array( 1, 2 ),
			5,
			'hired'
		);
		$this->assertSame( 2, $result );
	}

	public function test_count_paginated_filtered_returns_zero_when_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$this->assertSame(
			0,
			RecruitmentCandidateReader::count_paginated_filtered( '', null, 0, 'accepted' )
		);
	}
}
