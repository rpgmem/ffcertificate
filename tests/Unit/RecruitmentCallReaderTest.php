<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCallReader;

/**
 * Tests for the call reader — every SELECT / lookup / count query for
 * `ffc_recruitment_call` rows. Drives the global `$wpdb` mock to return
 * fixtures and exercises happy-path, empty and not-found branches, plus the
 * object-cache hit/miss paths of {@see RecruitmentCallReader::get_by_id()}.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallReader
 */
class RecruitmentCallReaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov coverage-attribution: preload the class so its lines attribute here.
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentCallReader' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// prepare() returns the SQL literal unchanged.
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
	private function row( int $id, int $classification_id = 1 ): \stdClass {
		$o                    = new \stdClass();
		$o->id                = (string) $id;
		$o->classification_id = (string) $classification_id;
		$o->called_at         = '2026-07-01 10:00:00';
		$o->cancelled_at      = null;
		return $o;
	}

	public function test_get_table_name(): void {
		$this->assertSame( 'wp_ffc_recruitment_call', RecruitmentCallReader::get_table_name() );
	}

	// ------------------------------------------------------------------
	// get_by_id — cache miss / hit
	// ------------------------------------------------------------------

	public function test_get_by_id_returns_row_on_cache_miss(): void {
		$row = $this->row( 5 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCallReader::get_by_id( 5 ) );
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCallReader::get_by_id( 999 ) );
	}

	public function test_get_by_id_returns_cached_value_without_query(): void {
		$cached = $this->row( 7 );
		Functions\when( 'wp_cache_get' )->justReturn( $cached );
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assertSame( $cached, RecruitmentCallReader::get_by_id( 7 ) );
	}

	// ------------------------------------------------------------------
	// get_active_for_classification
	// ------------------------------------------------------------------

	public function test_get_active_returns_row_when_present(): void {
		$row = $this->row( 3, 42 );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, RecruitmentCallReader::get_active_for_classification( 42 ) );
	}

	public function test_get_active_returns_null_when_none(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( RecruitmentCallReader::get_active_for_classification( 42 ) );
	}

	// ------------------------------------------------------------------
	// get_history_for_classification
	// ------------------------------------------------------------------

	public function test_get_history_returns_rows(): void {
		$rows = array( $this->row( 2, 9 ), $this->row( 1, 9 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, RecruitmentCallReader::get_history_for_classification( 9 ) );
	}

	public function test_get_history_returns_empty_on_non_array(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), RecruitmentCallReader::get_history_for_classification( 9 ) );
	}

	// ------------------------------------------------------------------
	// get_history_for_classifications (batch IN())
	// ------------------------------------------------------------------

	public function test_get_history_batch_returns_empty_for_empty_input(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), RecruitmentCallReader::get_history_for_classifications( array() ) );
	}

	public function test_get_history_batch_returns_rows(): void {
		$rows = array( $this->row( 4, 1 ), $this->row( 5, 2 ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$out = RecruitmentCallReader::get_history_for_classifications( array( 1, 2 ) );
		$this->assertSame( $rows, $out );
	}

	public function test_get_history_batch_returns_empty_when_prepare_fails(): void {
		// A non-string prepare() result short-circuits before the query.
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( null );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), RecruitmentCallReader::get_history_for_classifications( array( 1 ) ) );
	}

	public function test_get_history_batch_returns_empty_on_non_array_result(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( false );

		$this->assertSame( array(), RecruitmentCallReader::get_history_for_classifications( array( 1 ) ) );
	}

	// ------------------------------------------------------------------
	// count_for_classification
	// ------------------------------------------------------------------

	public function test_count_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->assertSame( 3, RecruitmentCallReader::count_for_classification( 42 ) );
	}

	public function test_count_returns_zero_when_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->assertSame( 0, RecruitmentCallReader::count_for_classification( 42 ) );
	}
}
