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
}
