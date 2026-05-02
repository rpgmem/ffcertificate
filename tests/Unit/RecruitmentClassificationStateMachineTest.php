<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine;

/**
 * Tests for RecruitmentClassificationStateMachine — covers every legal
 * transition, every blocked transition, reason gating, the `hired`
 * terminal state, and the §5.1 reopen-freeze rule
 * (`not_shown → empty` blocked when `notice.was_reopened = 1`).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationStateMachine
 */
class RecruitmentClassificationStateMachineTest extends TestCase {

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
	 * Build a classification row stub.
	 *
	 * @param string $status Current classification status.
	 * @return object
	 */
	private function classification_stub( string $status ): object {
		return (object) array(
			'id'           => '10',
			'candidate_id' => '1',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'list_type'    => 'definitive',
			'rank'         => '1',
			'score'        => '90.0000',
			'status'       => $status,
			'created_at'   => '2026-05-01 10:00:00',
			'updated_at'   => '2026-05-01 10:00:00',
		);
	}

	/**
	 * Build a notice row stub for the reopen-freeze gate.
	 *
	 * @param string $was_reopened String "0" or "1" matching numeric-string PHPDoc.
	 * @return object
	 */
	private function notice_stub( string $was_reopened ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-2026-01',
			'name'                  => 'Test',
			'status'                => 'definitive',
			'opened_at'             => '2026-04-01 09:00:00',
			'closed_at'             => null,
			'was_reopened'          => $was_reopened,
			'public_columns_config' => '{}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	public function test_transition_returns_failure_when_classification_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentClassificationStateMachine::transition_to( 999, 'called' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_classification_not_found' ), $result['errors'] );
	}

	public function test_same_state_transition_is_idempotent_success(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'empty' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertTrue( $result['success'] );
	}

	public function test_invalid_transition_is_rejected(): void {
		// empty → hired is not allowed (must go through called first).
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'empty' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'recruitment_invalid_transition', $result['errors'][0] );
	}

	public function test_hired_is_terminal(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'hired' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'attempt to undo' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_terminal_hired' ), $result['errors'] );
	}

	public function test_called_to_empty_requires_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'called' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_called_to_empty_succeeds_with_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Admin cancelled call' );

		$this->assertTrue( $result['success'] );
	}

	public function test_called_to_accepted_succeeds_without_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'accepted' );

		$this->assertTrue( $result['success'] );
	}

	public function test_called_to_hired_succeeds(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertTrue( $result['success'] );
	}

	public function test_accepted_to_hired_succeeds(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'accepted' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertTrue( $result['success'] );
	}

	public function test_accepted_to_empty_requires_reason(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'accepted' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_not_shown_to_empty_succeeds_when_notice_not_reopened(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->classification_stub( 'not_shown' ),
				$this->notice_stub( '0' )
			);
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Reopen' );

		$this->assertTrue( $result['success'] );
	}

	public function test_not_shown_to_empty_blocked_when_notice_was_reopened(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->classification_stub( 'not_shown' ),
				$this->notice_stub( '1' )
			);
		// query() must NOT be called — the reopen-freeze gate fires first.
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Reopen' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_reopen_freeze_active' ), $result['errors'] );
	}

	public function test_set_status_lost_race_surfaces_error(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'accepted' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_locked' ), $result['errors'] );
	}
}
