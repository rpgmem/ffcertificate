<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentErrorMessages;

/**
 * Tests for RecruitmentErrorMessages — covers the code → user-facing
 * message mapping plus the `line=N: ` and `code: suffix` decompositions
 * emitted by the CSV importer + REST controller.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentErrorMessages
 */
class RecruitmentErrorMessagesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_known_code_translates_to_friendly_message(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_notice_has_no_adjutancies' );

		// Friendly message — not the raw code.
		$this->assertNotSame( 'recruitment_notice_has_no_adjutancies', $msg );
		$this->assertStringContainsStringIgnoringCase( 'adjutancies', $msg );
	}

	public function test_unknown_code_passes_through_verbatim(): void {
		$this->assertSame( 'recruitment_brand_new_unmapped_code', RecruitmentErrorMessages::translate( 'recruitment_brand_new_unmapped_code' ) );
	}

	public function test_line_prefix_is_preserved_and_message_translated(): void {
		$msg = RecruitmentErrorMessages::translate( 'line=42: recruitment_csv_missing_score' );

		$this->assertStringContainsString( '42', $msg );
		$this->assertStringContainsStringIgnoringCase( 'score', $msg );
		$this->assertStringNotContainsString( 'recruitment_csv_missing_score', $msg );
	}

	public function test_suffix_is_appended_in_parens_for_known_codes(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_csv_adjutancy_not_in_notice: my-slug' );

		$this->assertStringContainsString( '(my-slug)', $msg );
		$this->assertStringNotContainsString( 'recruitment_csv_adjutancy_not_in_notice', $msg );
	}

	public function test_suffix_passes_through_for_unknown_codes(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_unknown_code: some-detail' );
		$this->assertSame( 'recruitment_unknown_code: some-detail', $msg );
	}

	public function test_translate_all_preserves_order(): void {
		$out = RecruitmentErrorMessages::translate_all(
			array(
				'recruitment_csv_empty',
				'recruitment_brand_new',
				'recruitment_notice_not_found',
			)
		);

		$this->assertCount( 3, $out );
		$this->assertNotSame( 'recruitment_csv_empty', $out[0] );
		$this->assertSame( 'recruitment_brand_new', $out[1] );
		$this->assertNotSame( 'recruitment_notice_not_found', $out[2] );
	}

	public function test_invalid_transition_names_both_statuses_in_words(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_invalid_transition: called->empty' );

		$this->assertStringContainsString( '"Called"', $msg );
		$this->assertStringContainsString( '"Waiting"', $msg );
		$this->assertStringNotContainsString( 'called->empty', $msg );
	}

	public function test_invalid_transition_with_unknown_status_keeps_the_code_word(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_invalid_transition: called->bogus' );

		$this->assertStringContainsString( '"bogus"', $msg );
	}

	public function test_invalid_transition_with_unparsable_suffix_falls_back_to_parens(): void {
		$msg = RecruitmentErrorMessages::translate( 'recruitment_invalid_transition: whatever' );

		$this->assertStringContainsString( 'not allowed', $msg );
		$this->assertStringContainsString( '(whatever)', $msg );
	}

	public function test_race_codes_say_reload_rather_than_blame_the_state(): void {
		$this->assertStringContainsString( 'Reload', RecruitmentErrorMessages::translate( 'recruitment_state_locked' ) );
		$this->assertStringContainsString( 'no longer waiting', RecruitmentErrorMessages::translate( 'recruitment_classification_not_empty' ) );
	}

	public function test_call_schedule_and_bulk_codes_have_messages(): void {
		foreach ( array( 'recruitment_call_invalid_date', 'recruitment_call_invalid_time', 'recruitment_bulk_call_failed_at_classification' ) as $code ) {
			$this->assertNotSame( $code, RecruitmentErrorMessages::translate( $code ), $code );
		}
	}
}
