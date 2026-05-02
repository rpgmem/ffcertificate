<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPromotionService;

/**
 * Tests for RecruitmentPromotionService — covers the snapshot-mode
 * promotion path: validate notice state, copy preview rows to definitive,
 * flip status to active, all atomic.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPromotionService
 */
class RecruitmentPromotionServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->insert_id = 0;
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'add_action' )->justReturn( true );

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

	private function notice_stub( string $status ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-2026-01',
			'name'                  => 'Test',
			'status'                => $status,
			'opened_at'             => null,
			'closed_at'             => null,
			'was_reopened'          => '0',
			'public_columns_config' => '{}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	public function test_snapshot_rejects_unknown_notice(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentPromotionService::snapshot_to_definitive( 999 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['copied'] );
		$this->assertSame( array( 'recruitment_notice_not_found' ), $result['errors'] );
	}

	public function test_snapshot_rejects_when_notice_not_in_preliminary(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$result = RecruitmentPromotionService::snapshot_to_definitive( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_promotion_requires_preliminary_state' ), $result['errors'] );
	}

	public function test_snapshot_rejects_when_no_preview_rows_to_copy(): void {
		// First get_row: notice (preliminary).
		// get_results for preview rows returns empty.
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'preliminary' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$result = RecruitmentPromotionService::snapshot_to_definitive( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_promotion_no_preview_rows' ), $result['errors'] );
	}

	public function test_snapshot_copies_preview_rows_and_flips_status(): void {
		$preview_rows = array(
			(object) array(
				'id'           => '1',
				'candidate_id' => '10',
				'adjutancy_id' => '2',
				'notice_id'    => '5',
				'list_type'    => 'preview',
				'rank'         => '1',
				'score'        => '90.0000',
				'status'       => 'empty',
				'created_at'   => '2026-05-01 10:00:00',
				'updated_at'   => '2026-05-01 10:00:00',
			),
			(object) array(
				'id'           => '2',
				'candidate_id' => '11',
				'adjutancy_id' => '2',
				'notice_id'    => '5',
				'list_type'    => 'preview',
				'rank'         => '2',
				'score'        => '85.0000',
				'status'       => 'empty',
				'created_at'   => '2026-05-01 10:00:00',
				'updated_at'   => '2026-05-01 10:00:00',
			),
		);

		// 1st get_row: snapshot_to_definitive() loads notice (preliminary).
		// 2nd get_row: state-machine transition_to() reloads the notice.
		// 3rd get_row: side-effects re-read after status change.
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 3 )
			->andReturn(
				$this->notice_stub( 'preliminary' ),
				$this->notice_stub( 'preliminary' ),
				$this->notice_stub( 'definitive' )
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $preview_rows );

		// query() calls in order:
		//  1. START TRANSACTION
		//  2. delete_all_for_notice_list (definitive)  → returns null/int; we accept any
		//  3. set_status (preliminary→active)          → 1 affected
		//  4. mark_opened                              → 1 affected
		//  5. COMMIT
		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1;
				}
			);

		// Two insert calls expected (one per preview row); $wpdb->insert_id
		// drives the new classification ID returned to the importer pipeline.
		$inserts = 0;
		$this->wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				function () use ( &$inserts ) {
					++$inserts;
					$GLOBALS['wpdb']->insert_id = 100 + $inserts;
					return 1;
				}
			);

		// Delete is called via wpdb->delete inside delete_all_for_notice_list().
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 0 );

		$result = RecruitmentPromotionService::snapshot_to_definitive( 5 );

		$this->assertTrue( $result['success'], 'Snapshot expected to succeed; errors=' . implode( ',', $result['errors'] ) );
		$this->assertSame( 2, $result['copied'] );
		$this->assertSame( 2, $inserts, 'One INSERT per preview row copied' );

		// Sanity: the transaction envelope is present.
		$this->assertContains( 'START TRANSACTION', $query_log );
		$this->assertContains( 'COMMIT', $query_log );
	}

	public function test_snapshot_rolls_back_when_state_machine_transition_loses_race(): void {
		$preview_rows = array(
			(object) array(
				'id'           => '1',
				'candidate_id' => '10',
				'adjutancy_id' => '2',
				'notice_id'    => '5',
				'list_type'    => 'preview',
				'rank'         => '1',
				'score'        => '90.0000',
				'status'       => 'empty',
				'created_at'   => '2026-05-01 10:00:00',
				'updated_at'   => '2026-05-01 10:00:00',
			),
		);

		$this->wpdb->shouldReceive( 'get_row' )
			->times( 2 )
			->andReturn(
				$this->notice_stub( 'preliminary' ),
				$this->notice_stub( 'preliminary' )
			);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $preview_rows );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					// Simulate the conditional UPDATE losing the race
					// (`set_status` returns 0 affected rows).
					if ( false !== stripos( $sql, 'UPDATE' ) && false !== stripos( $sql, 'status' ) ) {
						return 0;
					}
					return 1;
				}
			);

		$this->wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				function () {
					$GLOBALS['wpdb']->insert_id = 200;
					return 1;
				}
			);
		$this->wpdb->shouldReceive( 'delete' )->andReturn( 0 );

		$result = RecruitmentPromotionService::snapshot_to_definitive( 5 );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_transition_race_lost', $result['errors'] );
		$this->assertContains( 'ROLLBACK', $query_log, 'Transaction must be rolled back when the state-machine flip fails' );
		$this->assertNotContains( 'COMMIT', $query_log );
	}
}
