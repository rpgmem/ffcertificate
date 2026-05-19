<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateHistoryService;

/**
 * Tests for RecruitmentCandidateHistoryService — verifies the candidate
 * matcher predicate (direct candidate_id / classification_id /
 * bulk classification_ids) plus the per-action summary formatter. The
 * end-to-end `get_for_candidate()` path that hits wpdb is exercised
 * separately by the integration suite; here we pin the pure logic.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateHistoryService
 */
class RecruitmentCandidateHistoryServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// matches_candidate()
	// ------------------------------------------------------------------

	public function test_matches_direct_candidate_id(): void {
		$row = array( 'action' => 'recruitment_candidate_fields_edited', 'context' => array( 'candidate_id' => 42 ) );

		$this->assertTrue( RecruitmentCandidateHistoryService::matches_candidate( $row, 42, array() ) );
	}

	public function test_does_not_match_when_candidate_id_differs(): void {
		$row = array( 'action' => 'recruitment_candidate_fields_edited', 'context' => array( 'candidate_id' => 7 ) );

		$this->assertFalse( RecruitmentCandidateHistoryService::matches_candidate( $row, 42, array() ) );
	}

	public function test_matches_when_classification_id_in_set(): void {
		$row = array( 'action' => 'recruitment_classification_status_changed', 'context' => array( 'classification_id' => 100 ) );

		$this->assertTrue( RecruitmentCandidateHistoryService::matches_candidate( $row, 42, array( 100, 101 ) ) );
	}

	public function test_does_not_match_when_classification_id_outside_set(): void {
		$row = array( 'action' => 'recruitment_classification_status_changed', 'context' => array( 'classification_id' => 999 ) );

		$this->assertFalse( RecruitmentCandidateHistoryService::matches_candidate( $row, 42, array( 100, 101 ) ) );
	}

	public function test_matches_when_bulk_classification_ids_intersects(): void {
		$row = array(
			'action'  => 'recruitment_bulk_call_created',
			'context' => array( 'classification_ids' => array( 50, 51, 100 ) ),
		);

		$this->assertTrue( RecruitmentCandidateHistoryService::matches_candidate( $row, 42, array( 100, 101 ) ) );
	}

	public function test_does_not_match_when_context_missing(): void {
		$this->assertFalse( RecruitmentCandidateHistoryService::matches_candidate( array(), 42, array( 100 ) ) );
		$this->assertFalse( RecruitmentCandidateHistoryService::matches_candidate( array( 'context' => 'not-an-array' ), 42, array( 100 ) ) );
	}

	// ------------------------------------------------------------------
	// summarize_event() — every supported action code
	// ------------------------------------------------------------------

	public function test_summarize_candidate_promoted_mentions_user_id(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event( 'recruitment_candidate_promoted', array( 'user_id' => 99 ) );

		$this->assertStringContainsString( '#99', $out );
	}

	public function test_summarize_fields_edited_lists_changed_field_names(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_candidate_fields_edited',
			array( 'changes' => array( 'name' => array( 'old' => 'A', 'new' => 'B' ), 'phone' => array() ) )
		);

		$this->assertStringContainsString( 'name', $out );
		$this->assertStringContainsString( 'phone', $out );
	}

	public function test_summarize_pii_revealed_shows_field_key(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event( 'recruitment_pii_revealed', array( 'field_key' => 'cpf' ) );

		$this->assertStringContainsString( 'cpf', $out );
	}

	public function test_summarize_status_changed_includes_from_to_and_reason(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_classification_status_changed',
			array( 'classification_id' => 17, 'from' => 'empty', 'to' => 'called', 'reason' => 'urgência' )
		);

		$this->assertStringContainsString( '#17', $out );
		$this->assertStringContainsString( 'empty', $out );
		$this->assertStringContainsString( 'called', $out );
		$this->assertStringContainsString( 'urgência', $out );
	}

	public function test_summarize_status_changed_without_reason_omits_clause(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_classification_status_changed',
			array( 'classification_id' => 17, 'from' => 'empty', 'to' => 'called' )
		);

		$this->assertStringNotContainsString( 'reason:', $out );
	}

	public function test_summarize_adjutancy_changed_shows_both_ids(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_classification_adjutancy_changed',
			array( 'classification_id' => 17, 'from' => 3, 'to' => 5 )
		);

		$this->assertStringContainsString( '#3', $out );
		$this->assertStringContainsString( '#5', $out );
	}

	public function test_summarize_call_created_flags_out_of_order(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_call_created',
			array( 'call_id' => 8, 'classification_id' => 17, 'out_of_order' => 1 )
		);

		$this->assertStringContainsString( 'out-of-order', $out );
	}

	public function test_summarize_call_created_in_order_has_no_flag(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_call_created',
			array( 'call_id' => 8, 'classification_id' => 17, 'out_of_order' => 0 )
		);

		$this->assertStringNotContainsString( 'out-of-order', $out );
	}

	public function test_summarize_bulk_call_includes_count(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event( 'recruitment_bulk_call_created', array( 'count' => 12 ) );

		$this->assertStringContainsString( '12', $out );
	}

	public function test_summarize_call_cancelled_includes_reason(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event(
			'recruitment_call_cancelled',
			array( 'call_id' => 8, 'classification_id' => 17, 'reason' => 'duplicado' )
		);

		$this->assertStringContainsString( 'duplicado', $out );
	}

	public function test_summarize_unknown_action_falls_back_to_code(): void {
		$out = RecruitmentCandidateHistoryService::summarize_event( 'recruitment_some_future_event', array() );

		$this->assertSame( 'recruitment_some_future_event', $out );
	}
}
