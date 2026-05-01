<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine;

/**
 * Tests for RecruitmentNoticeStateMachine — covers every legal transition,
 * every blocked transition, reason gating, the active→preliminary
 * zero-calls gate, and the closed→active reopen flag side-effect.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine
 */
class RecruitmentNoticeStateMachineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
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

	/**
	 * Build a notice row stub.
	 *
	 * @param string      $status Current notice status.
	 * @param string|null $closed_at Stored closed_at.
	 * @return object
	 */
	private function notice_stub( string $status, ?string $closed_at = null, ?string $opened_at = null, string $was_reopened = '0' ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-2026-01',
			'name'                  => 'Test',
			'status'                => $status,
			'opened_at'             => $opened_at,
			'closed_at'             => $closed_at,
			'was_reopened'          => $was_reopened,
			'public_columns_config' => '{}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	public function test_transition_returns_failure_when_notice_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentNoticeStateMachine::transition_to( 999, 'preliminary' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_notice_not_found' ), $result['errors'] );
	}

	public function test_same_state_transition_is_idempotent_success(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'draft' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_invalid_transition_is_rejected(): void {
		// draft → active is not allowed (must go through preliminary).
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'active' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'recruitment_invalid_transition', $result['errors'][0] );
		$this->assertStringContainsString( 'draft->active', $result['errors'][0] );
	}

	public function test_draft_to_preliminary_succeeds(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );
		// set_status: returns 1 affected row (race won).
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'preliminary' );

		$this->assertTrue( $result['success'] );
	}

	public function test_set_status_lost_race_surfaces_error(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );
		// 0 affected rows = concurrent writer claimed the row first.
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'preliminary' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_race_lost' ), $result['errors'] );
	}

	public function test_active_to_preliminary_blocked_when_calls_exist(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'active' ) );
		// count_calls_for_notice returns 3 — gate fails.
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'preliminary' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_active_to_preliminary_blocked_by_calls' ), $result['errors'] );
	}

	public function test_active_to_preliminary_succeeds_when_no_calls(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'active' ) );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'preliminary' );

		$this->assertTrue( $result['success'] );
	}

	public function test_active_to_closed_stamps_closed_at(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'active' ) );

		// Two query calls expected: (1) set_status, (2) mark_closed.
		$query_calls = 0;
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function () use ( &$query_calls ) {
					++$query_calls;
					return 1;
				}
			);

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'closed' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $query_calls, 'set_status + mark_closed' );
	}

	public function test_closed_to_active_requires_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'closed' ) );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'active' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_closed_to_active_rejects_blank_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'closed' ) );

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'active', "   \t\n" );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_closed_to_active_flips_was_reopened(): void {
		// First get_row: load original notice (status=closed, closed_at set, was_reopened=0).
		// Second get_row: side-effects re-read after status flip succeeded.
		$pre  = $this->notice_stub( 'closed', '2026-05-01 09:00:00', '2026-04-01 09:00:00', '0' );
		$post = $this->notice_stub( 'active', '2026-05-01 09:00:00', '2026-04-01 09:00:00', '0' );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $pre, $post );

		// Three query calls expected: set_status + mark_reopened.
		// (mark_opened is skipped because opened_at is non-null.)
		$query_calls = 0;
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function () use ( &$query_calls ) {
					++$query_calls;
					return 1;
				}
			);

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'active', 'Vacancy reopened' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $query_calls, 'set_status + mark_reopened (no mark_opened — already opened previously)' );
	}

	public function test_first_preliminary_to_active_stamps_opened_at_only(): void {
		$pre  = $this->notice_stub( 'preliminary', null, null, '0' );
		$post = $this->notice_stub( 'active', null, null, '0' );
		$this->wpdb->shouldReceive( 'get_row' )->twice()->andReturn( $pre, $post );

		$query_calls = 0;
		$this->wpdb->shouldReceive( 'query' )
			->andReturnUsing(
				function () use ( &$query_calls ) {
					++$query_calls;
					return 1;
				}
			);

		$result = RecruitmentNoticeStateMachine::transition_to( 5, 'active' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $query_calls, 'set_status + mark_opened (no mark_reopened — never closed before)' );
	}
}
