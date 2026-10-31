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
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
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

		// Interpolate the table, so the double can tell a notice read from a
		// classification read and from the call lookup (#2245).
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				static function ( $query, ...$args ) {
					foreach ( $args as $arg ) {
						$query = (string) preg_replace( '/%[ids]/', (string) $arg, (string) $query, 1 );
					}
					return $query;
				}
			)
			->byDefault();
	}

	/**
	 * Answer reads by table: the classification, its notice, and no active call.
	 *
	 * @param object|null $classification Classification row, or null.
	 * @param object|null $notice         Notice row; a definitive one when null.
	 */
	private function rows( ?object $classification, ?object $notice = null, ?object $active_call = null ): void {
		$notice = $notice ?? $this->notice_stub( '0' );
		$this->wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			static function ( string $sql ) use ( $classification, $notice, $active_call ) {
				if ( 1 === preg_match( '/ffc_recruitment_notice\b/', $sql ) ) {
					return $notice;
				}
				if ( false !== strpos( $sql, 'ffc_recruitment_call' ) ) {
					return $active_call;
				}
				return $classification;
			}
		);
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
		$this->rows( null );

		$result = RecruitmentClassificationStateMachine::transition_to( 999, 'called' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_classification_not_found' ), $result['errors'] );
	}

	public function test_same_state_transition_is_idempotent_success(): void {
		$this->rows( $this->classification_stub( 'empty' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertTrue( $result['success'] );
	}

	public function test_invalid_transition_is_rejected(): void {
		// empty → hired is not allowed (must go through called first).
		$this->rows( $this->classification_stub( 'empty' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'recruitment_invalid_transition', $result['errors'][0] );
	}

	public function test_hired_is_terminal(): void {
		$this->rows( $this->classification_stub( 'hired' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'attempt to undo' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_terminal_hired' ), $result['errors'] );
	}

	public function test_called_to_withdrew_is_allowed(): void {
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'withdrew' );

		$this->assertTrue( $result['success'] );
	}

	public function test_accepted_to_withdrew_is_allowed(): void {
		$this->rows( $this->classification_stub( 'accepted' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'withdrew' );

		$this->assertTrue( $result['success'] );
	}

	public function test_withdrew_is_terminal(): void {
		$this->rows( $this->classification_stub( 'withdrew' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'attempt to undo' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_terminal_withdrew' ), $result['errors'] );
	}

	public function test_called_to_empty_requires_reason(): void {
		$this->rows( $this->classification_stub( 'called' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_called_to_empty_succeeds_with_reason(): void {
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Admin cancelled call' );

		$this->assertTrue( $result['success'] );
	}

	public function test_called_to_accepted_succeeds_without_reason(): void {
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'accepted' );

		$this->assertTrue( $result['success'] );
	}

	public function test_called_to_hired_succeeds(): void {
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertTrue( $result['success'] );
	}

	public function test_accepted_to_hired_succeeds(): void {
		$this->rows( $this->classification_stub( 'accepted' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertTrue( $result['success'] );
	}

	public function test_accepted_to_empty_requires_reason(): void {
		$this->rows( $this->classification_stub( 'accepted' ) );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_not_shown_to_empty_succeeds_when_notice_not_reopened(): void {
		$this->rows( $this->classification_stub( 'not_shown' ), $this->notice_stub( '0' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Reopen' );

		$this->assertTrue( $result['success'] );
	}

	public function test_not_shown_to_empty_blocked_when_notice_was_reopened(): void {
		$this->rows( $this->classification_stub( 'not_shown' ), $this->notice_stub( '1' ) );
		// query() must NOT be called — the reopen-freeze gate fires first.
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'empty', 'Reopen' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_reopen_freeze_active' ), $result['errors'] );
	}

	public function test_set_status_lost_race_surfaces_error(): void {
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'accepted' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_locked' ), $result['errors'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function not_live(): array {
		return array(
			'preview list'        => array( 'preview', 'definitive' ),
			'draft notice'        => array( 'definitive', 'draft' ),
			'preliminary notice'  => array( 'definitive', 'preliminary' ),
			'closed notice'       => array( 'definitive', 'closed' ),
		);
	}

	/**
	 * @dataProvider not_live
	 */
	public function test_outcomes_change_only_on_the_definitive_list_of_a_definitive_notice( string $list, string $status ): void {
		$classification            = $this->classification_stub( 'called' );
		$classification->list_type = $list;
		$notice                    = $this->notice_stub( '0' );
		$notice->status            = $status;
		$this->rows( $classification, $notice );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::transition_to( 10, 'hired' );

		$this->assertFalse( $result['success'] );
		$this->assertSame(
			array( 'preview' === $list ? 'recruitment_call_requires_definitive_list' : 'recruitment_call_requires_definitive_notice' ),
			$result['errors']
		);
	}

	public function test_override_ends_the_active_call(): void {
		$this->rows( $this->classification_stub( 'hired' ), null, (object) array( 'id' => '77', 'classification_id' => '10', 'cancelled_at' => null ) );
		$queries = array();
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Hired by mistake' );

		$this->assertTrue( $result['success'] );
		$cancels = array_values( array_filter( $queries, static fn( string $q ): bool => false !== strpos( $q, 'SET cancellation_reason' ) ) );
		$this->assertCount( 1, $cancels, 'The call that led to the decision is cancelled with it.' );
		$this->assertStringContainsString( 'WHERE id = 77', $cancels[0] );
	}

	// -- admin_override_to_empty (#Item 8) -----------------------------------

	public function test_override_hired_to_empty_succeeds(): void {
		$this->rows( $this->classification_stub( 'hired' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Hired by mistake' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_override_withdrew_to_empty_succeeds(): void {
		$this->rows( $this->classification_stub( 'withdrew' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Logged the wrong candidate' );

		$this->assertTrue( $result['success'] );
	}

	/**
	 * The override deliberately bypasses the reopen-freeze: a `not_shown`
	 * row on a reopened notice is unreachable via `transition_to` (see
	 * test_not_shown_to_empty_blocked_when_notice_was_reopened) but the
	 * admin override resets it, on a notice that was reopened.
	 */
	public function test_override_not_shown_to_empty_bypasses_reopen_freeze(): void {
		$this->rows( $this->classification_stub( 'not_shown' ), $this->notice_stub( '1' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Candidate did show — operator error' );

		$this->assertTrue( $result['success'] );
	}

	public function test_override_requires_reason(): void {
		// Reason is gated before any DB read — no get_row, no query.
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, '   ' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_transition_reason_required' ), $result['errors'] );
	}

	public function test_override_unknown_classification_fails(): void {
		$this->rows( null );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 999, 'Undo' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_classification_not_found' ), $result['errors'] );
	}

	public function test_override_idempotent_when_already_empty(): void {
		$this->rows( $this->classification_stub( 'empty' ) );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Already waiting' );

		$this->assertTrue( $result['success'] );
	}

	public function test_override_rejects_non_overridable_status(): void {
		// `called` is reversible via the normal cancel flow — the override
		// refuses it so it can't double as a generic "set any status" backdoor.
		$this->rows( $this->classification_stub( 'called' ) );
		$this->wpdb->shouldNotReceive( 'query' );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Trying to shortcut' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'recruitment_override_not_overridable', $result['errors'][0] );
	}

	public function test_override_lost_race_surfaces_error(): void {
		$this->rows( $this->classification_stub( 'hired' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$result = RecruitmentClassificationStateMachine::admin_override_to_empty( 10, 'Undo hire' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array( 'recruitment_state_locked' ), $result['errors'] );
	}
}
