<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeReader;

/**
 * Tests for the notice reader — every SELECT / lookup query for
 * `ffc_recruitment_notice` rows. Drives the global `$wpdb` mock and exercises
 * the object-cache hit/miss of {@see RecruitmentNoticeReader::get_by_id()}, the
 * case-normalising {@see ::get_by_code()}, and both branches of
 * {@see ::get_all()} (status filter vs. unfiltered).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeReader
 */
class RecruitmentNoticeReaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentNoticeReader' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// prepare() returns the SQL literal unchanged; capture args so the
		// case-normalisation in get_by_code() can be asserted.
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function () {
					return func_get_args()[0];
				}
			)
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return \stdClass */
	private function row( int $id, string $code = 'EDITAL-2026-01', string $status = 'active' ): \stdClass {
		$o         = new \stdClass();
		$o->id     = (string) $id;
		$o->code   = $code;
		$o->status = $status;
		return $o;
	}

	public function test_get_table_name(): void {
		$this->assertSame( 'wp_ffc_recruitment_notice', RecruitmentNoticeReader::get_table_name() );
	}

	// ------------------------------------------------------------------
	// get_by_id — cache miss / hit
	// ------------------------------------------------------------------

	public function test_get_by_id_returns_row_on_cache_miss(): void {
		$row = $this->row( 5 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentNoticeReader::get_by_id( 5 ) );
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentNoticeReader::get_by_id( 999 ) );
	}

	public function test_get_by_id_returns_cached_value_without_query(): void {
		$cached = $this->row( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( $cached );
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assertSame( $cached, RecruitmentNoticeReader::get_by_id( 7 ) );
	}

	// ------------------------------------------------------------------
	// get_by_code — uppercases the input before lookup
	// ------------------------------------------------------------------

	public function test_get_by_code_uppercases_and_returns_row(): void {
		$row = $this->row( 3, 'EDITAL-2026-01' );
		$captured = null;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$captured ) {
				$captured = $args;
				return $sql;
			}
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentNoticeReader::get_by_code( 'edital-2026-01' ) );
		// The code is uppercased before it reaches the query.
		$this->assertContains( 'EDITAL-2026-01', $captured );
	}

	public function test_get_by_code_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentNoticeReader::get_by_code( 'nope' ) );
	}

	// ------------------------------------------------------------------
	// get_all — filtered by status vs. unfiltered
	// ------------------------------------------------------------------

	public function test_get_all_filtered_by_status(): void {
		$rows = array( $this->row( 1, 'A', 'active' ), $this->row( 2, 'B', 'active' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, RecruitmentNoticeReader::get_all( 'active' ) );
	}

	public function test_get_all_unfiltered(): void {
		$rows = array( $this->row( 1, 'A', 'draft' ), $this->row( 2, 'B', 'closed' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, RecruitmentNoticeReader::get_all() );
	}

	public function test_get_all_returns_empty_on_non_array(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), RecruitmentNoticeReader::get_all() );
	}
}
