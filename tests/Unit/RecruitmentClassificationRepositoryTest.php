<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentClassificationRepository;

/**
 * Tests for RecruitmentClassificationRepository — covers the atomic
 * state-transition primitive used by the state machine (sprint 5) and the
 * convocation hot-path lookup `find_lowest_rank_empty()` used by the
 * out-of-order check (sprint 6).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationRepository
 */
class RecruitmentClassificationRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
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
		$this->assertSame( 'wp_ffc_recruitment_classification', RecruitmentClassificationRepository::get_table_name() );
	}

	public function test_set_status_returns_one_when_expected_current_matches(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$affected = RecruitmentClassificationRepository::set_status( 1, 'empty', 'called' );
		$this->assertSame( 1, $affected, 'Atomic transition succeeds when status was empty as expected' );
	}

	public function test_set_status_returns_zero_when_race_lost(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$affected = RecruitmentClassificationRepository::set_status( 1, 'empty', 'called' );
		$this->assertSame( 0, $affected, 'A concurrent writer claiming the row first leaves us with 0 affected rows' );
	}

	public function test_find_lowest_rank_empty_returns_typed_row(): void {
		$row = (object) array(
			'id'   => '5',
			'rank' => '1',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$result = RecruitmentClassificationRepository::find_lowest_rank_empty( 1, 2, 'definitive' );
		$this->assertSame( $row, $result );
	}

	public function test_find_lowest_rank_empty_returns_null_when_all_called(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentClassificationRepository::find_lowest_rank_empty( 1, 2, 'definitive' );
		$this->assertNull( $result, 'When no empty row remains, the in-order check returns null' );
	}

	public function test_create_inserts_with_default_empty_status(): void {
		$captured = array();
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured ) {
					$captured = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 99;

		$id = RecruitmentClassificationRepository::create(
			array(
				'candidate_id' => 1,
				'adjutancy_id' => 2,
				'notice_id'    => 3,
				'list_type'    => 'preview',
				'rank'         => 5,
				'score'        => '87.5',
			)
		);

		$this->assertSame( 99, $id );
		$this->assertSame( 'empty', $captured['status'], 'Default status on creation is empty' );
		$this->assertSame( '87.5', $captured['score'] );
		$this->assertSame( 5, $captured['rank'] );
	}

	public function test_count_for_candidate_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$this->assertSame( 3, RecruitmentClassificationRepository::count_for_candidate( 7 ) );
	}

	public function test_count_for_adjutancy_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '12' );

		$this->assertSame( 12, RecruitmentClassificationRepository::count_for_adjutancy( 4 ) );
	}

	public function test_delete_all_for_notice_list_returns_count(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with(
				'wp_ffc_recruitment_classification',
				array( 'notice_id' => 5, 'list_type' => 'preview' ),
				array( '%d', '%s' )
			)
			->andReturn( 17 );

		$this->assertSame( 17, RecruitmentClassificationRepository::delete_all_for_notice_list( 5, 'preview' ) );
	}

	public function test_count_calls_for_notice_runs_cross_table_count(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

		$this->assertSame( 4, RecruitmentClassificationRepository::count_calls_for_notice( 8 ) );
	}

	public function test_get_for_notice_filters_by_list_type_and_adjutancy(): void {
		$rows = array(
			(object) array( 'id' => '1', 'rank' => '1' ),
			(object) array( 'id' => '2', 'rank' => '2' ),
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$result = RecruitmentClassificationRepository::get_for_notice( 1, 'definitive', 2 );
		$this->assertCount( 2, $result );
	}
}
