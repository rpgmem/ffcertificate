<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCallService;

/**
 * Tests for RecruitmentCallService — covers single, bulk, out-of-order
 * detection, lost-race semantics, the §7 §3.6 reason invariant, and the
 * append-only cancellation flow (classification rolls back to `empty`
 * AND the call row gets `cancelled_at` stamped, atomic).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallService
 */
class RecruitmentCallServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$this->wpdb      = $wpdb;

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

	private function classification_stub( int $id, string $status, int $rank = 1 ): object {
		return (object) array(
			'id'           => (string) $id,
			'candidate_id' => '100',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'list_type'    => 'definitive',
			'rank'         => (string) $rank,
			'score'        => '90.0000',
			'status'       => $status,
			'created_at'   => '2026-05-01 10:00:00',
			'updated_at'   => '2026-05-01 10:00:00',
		);
	}

	private function call_stub( int $id, int $classification_id, ?string $cancelled_at = null ): object {
		return (object) array(
			'id'                  => (string) $id,
			'classification_id'   => (string) $classification_id,
			'called_at'           => '2026-05-01 10:00:00',
			'date_to_assume'      => '2026-06-01',
			'time_to_assume'      => '08:00:00',
			'out_of_order'        => '0',
			'out_of_order_reason' => null,
			'cancellation_reason' => null,
			'cancelled_at'        => $cancelled_at,
			'cancelled_by'        => null,
			'notes'               => null,
			'created_by'          => '1',
			'created_at'          => '2026-05-01 10:00:00',
			'updated_at'          => '2026-05-01 10:00:00',
		);
	}

	// ==================================================================
	// call_single — single-row convocation
	// ==================================================================

	public function test_call_single_succeeds_in_order(): void {
		$classification = $this->classification_stub( 10, 'empty', 1 );
		// Two get_row calls: load classification + find_lowest_rank_empty.
		// Both return the same row (this IS the lowest empty), so call is in-order.
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $classification, $classification );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1; // status update wins.
				}
			);
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function () {
					$GLOBALS['wpdb']->insert_id = 7777;
					return 1;
				}
			);

		$result = RecruitmentCallService::call_single( 10, '2026-06-01', '08:00', 1 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 7777 ), $result['call_ids'] );
		$this->assertContains( 'START TRANSACTION', $query_log );
		$this->assertContains( 'COMMIT', $query_log );
	}

	public function test_call_single_rejects_when_classification_not_empty(): void {
		$classification = $this->classification_stub( 10, 'called' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $classification );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1;
				}
			);

		$result = RecruitmentCallService::call_single( 10, '2026-06-01', '08:00', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_classification_not_empty', $result['errors'] );
		$this->assertContains( 'ROLLBACK', $query_log );
		$this->assertNotContains( 'COMMIT', $query_log );
	}

	public function test_call_single_out_of_order_requires_reason(): void {
		// Target classification is rank 5; lowest empty is rank 1 (a different row).
		$target = $this->classification_stub( 10, 'empty', 5 );
		$lowest = $this->classification_stub( 8, 'empty', 1 );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $target, $lowest );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1;
				}
			);

		$result = RecruitmentCallService::call_single( 10, '2026-06-01', '08:00', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_out_of_order_requires_reason', $result['errors'] );
		$this->assertContains( 'ROLLBACK', $query_log );
	}

	public function test_call_single_out_of_order_succeeds_with_reason(): void {
		$target = $this->classification_stub( 10, 'empty', 5 );
		$lowest = $this->classification_stub( 8, 'empty', 1 );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $target, $lowest );

		$captured_call = null;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( $table, $data ) use ( &$captured_call ) {
					$captured_call              = $data;
					$GLOBALS['wpdb']->insert_id = 8888;
					return 1;
				}
			);

		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$result = RecruitmentCallService::call_single(
			10,
			'2026-06-01',
			'08:00',
			1,
			'Lower-rank candidate withdrew earlier today',
			null
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 8888 ), $result['call_ids'] );
		$this->assertSame( 1, $captured_call['out_of_order'] );
		$this->assertSame( 'Lower-rank candidate withdrew earlier today', $captured_call['out_of_order_reason'] );
	}

	public function test_call_single_lost_race_rolls_back(): void {
		$classification = $this->classification_stub( 10, 'empty', 1 );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $classification, $classification );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					// Conditional UPDATE empty → called: 0 affected = lost race.
					if ( false !== stripos( $sql, 'UPDATE' ) && false !== stripos( $sql, 'status' ) ) {
						return 0;
					}
					return 1;
				}
			);

		$result = RecruitmentCallService::call_single( 10, '2026-06-01', '08:00', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_state_locked', $result['errors'] );
		$this->assertContains( 'ROLLBACK', $query_log );
	}

	// ==================================================================
	// call_bulk
	// ==================================================================

	public function test_call_bulk_rejects_empty_id_list(): void {
		// Should not even open a transaction.
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentCallService::call_bulk( array(), '2026-06-01', '08:00', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_bulk_call_empty_id_list' ), $result['errors'] );
	}

	public function test_call_bulk_succeeds_for_two_in_order_classifications(): void {
		// Sequence of get_row calls:
		//  1. classification 10 (call_one #1)
		//  2. lowest empty for 10's adjutancy → 10 itself (in-order)
		//  3. classification 11 (call_one #2)
		//  4. lowest empty after #1 was called → 11 (in-order)
		$c10 = $this->classification_stub( 10, 'empty', 1 );
		$c11 = $this->classification_stub( 11, 'empty', 2 );
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 4 )
			->andReturn( $c10, $c10, $c11, $c11 );

		$insert_count = 0;
		$this->wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				function () use ( &$insert_count ) {
					++$insert_count;
					$GLOBALS['wpdb']->insert_id = 9000 + $insert_count;
					return 1;
				}
			);

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1;
				}
			);

		$result = RecruitmentCallService::call_bulk( array( 10, 11 ), '2026-06-01', '08:00', 1 );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['call_ids'] );
		$this->assertContains( 'COMMIT', $query_log );
	}

	public function test_call_bulk_rolls_back_when_one_row_fails(): void {
		// First row succeeds (in-order); second row fails (already called).
		$c10 = $this->classification_stub( 10, 'empty', 1 );
		$c11 = $this->classification_stub( 11, 'called', 2 );
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 3 )
			->andReturn( $c10, $c10, $c11 );

		$this->wpdb->shouldReceive( 'insert' )
			->andReturnUsing(
				function () {
					$GLOBALS['wpdb']->insert_id = 1234;
					return 1;
				}
			);

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1;
				}
			);

		$result = RecruitmentCallService::call_bulk( array( 10, 11 ), '2026-06-01', '08:00', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $result['call_ids'] );
		$this->assertContains( 'ROLLBACK', $query_log );
		$this->assertNotContains( 'COMMIT', $query_log );
		// Bulk wraps the inner error code with a "failed at" marker.
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'recruitment_bulk_call_failed_at_classification: 11', $result['errors'][0] );
	}

	// ==================================================================
	// cancel_call
	// ==================================================================

	public function test_cancel_call_rejects_blank_reason(): void {
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentCallService::cancel_call( 7, '   ', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_cancel_reason_required' ), $result['errors'] );
	}

	public function test_cancel_call_rejects_unknown_call_id(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentCallService::cancel_call( 999, 'Reason', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_call_not_found' ), $result['errors'] );
	}

	public function test_cancel_call_rejects_already_cancelled(): void {
		$call = $this->call_stub( 7, 10, '2026-05-01 09:00:00' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $call );

		$result = RecruitmentCallService::cancel_call( 7, 'Reason', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_call_already_cancelled' ), $result['errors'] );
	}

	public function test_cancel_call_rejects_when_classification_not_in_called_or_accepted(): void {
		$call           = $this->call_stub( 7, 10 );
		$classification = $this->classification_stub( 10, 'hired' );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $call, $classification );

		$result = RecruitmentCallService::cancel_call( 7, 'Reason', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_cancel_only_from_called_or_accepted' ), $result['errors'] );
	}

	public function test_cancel_call_succeeds_from_called(): void {
		$call           = $this->call_stub( 7, 10 );
		$classification = $this->classification_stub( 10, 'called' );
		// 1) cancel_call: load call.
		// 2) cancel_call: load classification.
		// 3) state-machine transition_to: load classification again.
		$this->wpdb->shouldReceive( 'get_row' )->times( 3 )->andReturn( $call, $classification, $classification );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					return 1; // every UPDATE wins.
				}
			);

		$result = RecruitmentCallService::cancel_call( 7, 'Admin reverted', 1 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 7 ), $result['call_ids'] );
		$this->assertContains( 'COMMIT', $query_log );
	}

	public function test_cancel_call_rolls_back_when_state_machine_fails(): void {
		$call           = $this->call_stub( 7, 10 );
		$classification = $this->classification_stub( 10, 'called' );
		$this->wpdb->shouldReceive( 'get_row' )->times( 3 )->andReturn( $call, $classification, $classification );

		$query_log = array();
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function ( $sql ) use ( &$query_log ) {
					$query_log[] = $sql;
					// The state-machine UPDATE loses the race (status changed concurrently).
					if ( false !== stripos( $sql, 'UPDATE' ) && false !== stripos( $sql, 'status' ) ) {
						return 0;
					}
					return 1;
				}
			);

		$result = RecruitmentCallService::cancel_call( 7, 'Admin reverted', 1 );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_state_locked', $result['errors'] );
		$this->assertContains( 'ROLLBACK', $query_log );
		$this->assertNotContains( 'COMMIT', $query_log );
	}
}
