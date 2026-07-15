<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;
use FreeFormCertificate\Recruitment\CsvParser;
use FreeFormCertificate\Recruitment\CsvValidator;

/**
 * Tests for RecruitmentCsvImporter.
 *
 * Two layers of coverage:
 *
 *   - `parse()` is a pure-function CSV reader; tested directly with no
 *     database mocking required. Covers BOM stripping, header validation,
 *     empty-row skipping, and the missing-required-headers error.
 *
 *   - `import_preview()` is exercised at the rejection boundary — valid
 *     parsing followed by a state-machine reject (notice not in `draft`/
 *     `preliminary`) — to verify the early-exit envelope shape without
 *     wiring full transactional DB mocks. The happy path is covered by
 *     integration tests in sprint 13.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCsvImporter
 * @covers \FreeFormCertificate\Recruitment\CsvValidator
 */
class RecruitmentCsvImporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the extracted CsvValidator coverage would attribute to nothing.
		// Preload it here so pcov attributes its lines to this test.
		class_exists( '\\FreeFormCertificate\\Recruitment\\CsvValidator' );

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

	// ==================================================================
	// parse() — pure-function CSV reader
	// ==================================================================

	public function test_parse_rejects_empty_content(): void {
		$result = RecruitmentCsvImporter::parse( '' );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'recruitment_csv_empty', $result['errors'] );
	}

	public function test_parse_rejects_missing_required_headers(): void {
		$csv = "name,cpf\nAlice,12345";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertFalse( $result['ok'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'recruitment_csv_missing_headers', $result['errors'][0] );
	}

	public function test_parse_strips_utf8_bom(): void {
		$bom = "\xEF\xBB\xBF";
		$csv = $bom . "name,cpf,rf,email,adjutancy,rank,score,pcd\nAlice,12345678901,,a@b.com,mat,1,90,Sim";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
	}

	public function test_parse_skips_completely_empty_rows(): void {
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim\n"
			. "\n"
			. "    \n"
			. ",,,,,,,\n"
			. "Bob,98765432100,,b@b.com,mat,2,80,Não\n";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $result['rows'], 'Empty/whitespace-only rows are silently skipped' );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
		$this->assertSame( 'Bob', $result['rows'][1]['name'] );
	}

	public function test_parse_records_line_numbers_for_each_row(): void {
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim\n"
			. "\n"
			. "Bob,98765432100,,b@b.com,mat,2,80,Não\n";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertSame( 2, $result['rows'][0]['_line'], 'Header is line 1; first data row is line 2' );
		$this->assertSame( 4, $result['rows'][1]['_line'], 'Skipped empty line still increments the counter' );
	}

	public function test_parse_handles_optional_phone_column_when_present(): void {
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd,phone\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim,11999998888";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '11999998888', $result['rows'][0]['phone'] );
	}

	public function test_parse_defaults_phone_to_empty_when_column_missing(): void {
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['rows'][0]['phone'] );
	}

	public function test_parse_accepts_semicolon_delimiter(): void {
		// BR/EU spreadsheet exports default to `;` because `,` is the
		// locale decimal separator. The importer must detect and use
		// it transparently.
		$csv = "name;cpf;rf;email;adjutancy;rank;score;pcd\n"
			. "Alice;12345678901;;a@b.com;mat;1;90;Sim";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
		$this->assertSame( '12345678901', $result['rows'][0]['cpf'] );
		$this->assertSame( 'mat', $result['rows'][0]['adjutancy'] );
	}

	public function test_parse_keeps_comma_when_more_commas_than_semicolons(): void {
		// Tie-breaker: `,` wins when counts are equal, and obviously when
		// `,` outnumbers `;`. This pins the default behaviour.
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
	}

	public function test_parse_accepts_crlf_line_endings(): void {
		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\r\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim\r\n";

		$result = RecruitmentCsvImporter::parse( $csv );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['rows'] );
	}

	// ==================================================================
	// import_preview() — early-exit boundary behaviors
	// ==================================================================

	public function test_import_preview_rejects_unknown_notice(): void {
		// Notice repo cache miss, then DB lookup returns null.
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$result = RecruitmentCsvImporter::import_preview( 999, 'unused' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['inserted'] );
		$this->assertContains( 'recruitment_notice_not_found', $result['errors'] );
	}

	public function test_import_preview_rejects_when_notice_is_active(): void {
		$notice = (object) array(
			'id'     => '5',
			'status' => 'definitive',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $notice );

		$result = RecruitmentCsvImporter::import_preview( 5, 'unused' );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $result['errors'] );
	}

	public function test_import_preview_rejects_when_notice_is_closed(): void {
		$notice = (object) array(
			'id'     => '5',
			'status' => 'closed',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $notice );

		$result = RecruitmentCsvImporter::import_preview( 5, 'unused' );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_invalid_state_for_preview_import', $result['errors'] );
	}

	public function test_import_preview_propagates_csv_parse_errors(): void {
		$notice = (object) array(
			'id'     => '5',
			'status' => 'preliminary',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $notice );

		// Empty CSV — parse() returns missing-headers error.
		$result = RecruitmentCsvImporter::import_preview( 5, '' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $result['inserted'] );
		$this->assertContains( 'recruitment_csv_empty', $result['errors'] );
	}

	public function test_import_preview_rejects_csv_with_missing_headers_in_eligible_state(): void {
		$notice = (object) array(
			'id'     => '5',
			'status' => 'draft',
		);
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $notice );

		$bad_csv = "name,cpf\nAlice,12345";

		$result = RecruitmentCsvImporter::import_preview( 5, $bad_csv );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'recruitment_csv_missing_headers', $result['errors'][0] );
	}

	public function test_import_preview_rejects_when_notice_has_no_adjutancies(): void {
		$notice = (object) array(
			'id'     => '5',
			'status' => 'draft',
		);
		// First get_row: notice lookup. Adjutancy lookups return empty.
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $notice );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$csv = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678901,,a@b.com,mat,1,90,Sim";

		$result = RecruitmentCsvImporter::import_preview( 5, $csv );

		$this->assertFalse( $result['success'] );
		$this->assertContains( 'recruitment_notice_has_no_adjutancies', $result['errors'] );
	}

	// ------------------------------------------------------------------
	// normalise_id helper — strip punctuation + left-pad short values (#172)
	// ------------------------------------------------------------------

	/**
	 * Call the `normalise_id` helper.
	 *
	 * Moved to the extracted {@see CsvParser} as a public static in #563
	 * Sprint 6 (PR 6a); the importer now delegates to it.
	 *
	 * @param string $raw
	 * @param int    $expected_length
	 * @return array{value: string, too_long: bool}
	 */
	private function normalise( string $raw, int $expected_length ): array {
		return CsvParser::normalise_id( $raw, $expected_length );
	}

	public function test_normalise_cpf_passes_through_canonical_value(): void {
		$out = $this->normalise( '12345678909', 11 );
		$this->assertSame( '12345678909', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_cpf_strips_dots_and_dash(): void {
		$out = $this->normalise( '123.456.789-09', 11 );
		$this->assertSame( '12345678909', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_cpf_pads_short_value_with_leading_zeros(): void {
		// 10 digits → padded to 11.
		$out = $this->normalise( '1234567890', 11 );
		$this->assertSame( '01234567890', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_cpf_pads_very_short_value(): void {
		$out = $this->normalise( '5', 11 );
		$this->assertSame( '00000000005', $out['value'] );
	}

	public function test_normalise_cpf_rejects_too_long_value(): void {
		$out = $this->normalise( '123456789012', 11 );
		$this->assertTrue( $out['too_long'] );
		// Value still returned so callers can include it in their error.
		$this->assertSame( '123456789012', $out['value'] );
	}

	public function test_normalise_rejects_too_long_after_stripping_punctuation(): void {
		// 12 digits hidden behind formatting.
		$out = $this->normalise( '123.456.789.012', 11 );
		$this->assertTrue( $out['too_long'] );
	}

	public function test_normalise_returns_empty_on_all_punctuation_input(): void {
		$out = $this->normalise( '...---', 11 );
		$this->assertSame( '', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_returns_empty_on_blank_input(): void {
		$out = $this->normalise( '', 11 );
		$this->assertSame( '', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_rf_strips_punctuation(): void {
		$out = $this->normalise( '123.456-7', 7 );
		$this->assertSame( '1234567', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_rf_pads_short_value(): void {
		$out = $this->normalise( '12', 7 );
		$this->assertSame( '0000012', $out['value'] );
	}

	public function test_normalise_rf_rejects_too_long_value(): void {
		$out = $this->normalise( '12345678', 7 );
		$this->assertTrue( $out['too_long'] );
	}

	public function test_normalise_strips_spaces_and_slashes(): void {
		// Pathological formatting still produces clean digits.
		$out = $this->normalise( ' 123  / 456 / 789 - 09 ', 11 );
		$this->assertSame( '12345678909', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_batch_size_constants_match_documented_defaults(): void {
		// Pins the public batch-size contract: AJAX callers (the JS-side
		// `BATCH_SIZE_DEFAULT` and the REST controller `args`) reference
		// this constant by name, so any future bump should happen in
		// lockstep with a CHANGELOG note.
		$this->assertSame( 50, RecruitmentCsvImporter::BATCH_SIZE_DEFAULT );
	}

	// ──────────────────────────────────────────────────────────────────.
	// validate() — physical-candidate duplicate detection.
	//
	// The prior rule "(cpf + adjutancy) duplicate" missed the cases the
	// upsert path later collapsed via rf_hash / email lookup. Those
	// silent reuses then violated the UNIQUE (candidate_id, adjutancy,
	// notice, list_type) constraint at INSERT time. The expanded rule
	// groups rows by ANY shared identifier (cpf / rf / email) before
	// the pair check fires.
	//
	// Note: validate() is private. The tests invoke it via Reflection,
	// matching the pattern the trailing normalise_id tests above use.
	// ──────────────────────────────────────────────────────────────────.

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param array<string, int>         $map  Adjutancy slug → id.
	 * @return list<string>
	 */
	private function validate_rows( array $rows, array $map = array( 'mat' => 1 ) ): array {
		// validate() moved to the extracted CsvValidator (public static) in
		// #563 Sprint 6 (PR 6b); the importer now delegates to it.
		return CsvValidator::validate( $rows, 1, 'preview', $map );
	}

	private function csv_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'_line'     => 2,
				'name'      => 'Alice',
				'cpf'       => '12345678901',
				'rf'        => '',
				'email'     => 'alice@example.test',
				'phone'     => '',
				'adjutancy' => 'mat',
				'rank'      => '1',
				'score'     => '90',
				'pcd'       => 'Não',
			),
			$overrides
		);
	}

	public function test_validate_detects_same_rf_different_cpf_in_same_adjutancy(): void {
		// Both rows have unique CPFs (legal under the old rule), but
		// share the same RF — upsert_candidate would collapse them onto
		// one candidate row and the second classification INSERT would
		// hit the UNIQUE constraint. validate() must now reject it.
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'cpf' => '11111111111', 'rf' => '1234567', 'email' => 'a@b.test' ) ),
			$this->csv_row( array( '_line' => 3, 'cpf' => '22222222222', 'rf' => '1234567', 'email' => 'c@d.test' ) ),
		);

		$errors = $this->validate_rows( $rows );

		$this->assertNotEmpty( $errors );
		$dup_errors = array_filter( $errors, static fn( $e ) => false !== strpos( $e, 'duplicate_candidate_adjutancy' ) );
		$this->assertCount( 1, $dup_errors, 'second row must be flagged as duplicate' );
	}

	public function test_validate_detects_same_email_different_cpf_in_same_adjutancy(): void {
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'cpf' => '11111111111', 'rf' => '', 'email' => 'shared@example.test' ) ),
			$this->csv_row( array( '_line' => 3, 'cpf' => '22222222222', 'rf' => '', 'email' => 'shared@example.test' ) ),
		);

		$errors = $this->validate_rows( $rows );

		$dup_errors = array_filter( $errors, static fn( $e ) => false !== strpos( $e, 'duplicate_candidate_adjutancy' ) );
		$this->assertCount( 1, $dup_errors );
	}

	public function test_validate_detects_rf_only_row_matching_prior_cpf_plus_rf_row(): void {
		// Linha 1 carries both CPF + RF; linha 2 is RF-only (CPF blank).
		// The upsert would find the existing candidate via rf_hash and
		// reuse the id. Must be caught by the pre-pass.
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'cpf' => '11111111111', 'rf' => '1234567' ) ),
			$this->csv_row( array( '_line' => 3, 'cpf' => '', 'rf' => '1234567', 'email' => '' ) ),
		);

		$errors = $this->validate_rows( $rows );

		$dup_errors = array_filter( $errors, static fn( $e ) => false !== strpos( $e, 'duplicate_candidate_adjutancy' ) );
		$this->assertCount( 1, $dup_errors );
	}

	public function test_validate_allows_same_candidate_across_different_adjutancies(): void {
		// Same person classified for two different adjutancies is the
		// legitimate use-case the duplicate rule must NOT break.
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'adjutancy' => 'mat' ) ),
			$this->csv_row( array( '_line' => 3, 'adjutancy' => 'por' ) ),
		);

		$errors = $this->validate_rows( $rows );

		// 'por' isn't in the adjutancy_map our harness provides, so we
		// expect adjutancy_not_in_notice errors — but NOT duplicate.
		$dup_errors = array_filter( $errors, static fn( $e ) => false !== strpos( $e, 'duplicate_candidate_adjutancy' ) );
		$this->assertEmpty( $dup_errors, 'same person in different adjutancies must not flag duplicate' );
	}

	public function test_validate_transitively_coalesces_identities_via_shared_tags(): void {
		// Row 1 carries CPF=A + RF=R1
		// Row 2 carries CPF=B + email=E1   (different person on the
		//   surface — no overlap with row 1)
		// Row 3 carries RF=R1 + email=E1   (bridges 1 and 2 onto the
		//   same physical candidate)
		// All three sit in the same adjutancy → must surface as one
		// duplicate-pair error against row 3 (the bridge row).
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'cpf' => '11111111111', 'rf' => '1111111', 'email' => 'a@b.test' ) ),
			$this->csv_row( array( '_line' => 3, 'cpf' => '22222222222', 'rf' => '', 'email' => 'bridge@b.test' ) ),
			$this->csv_row( array( '_line' => 4, 'cpf' => '', 'rf' => '1111111', 'email' => 'bridge@b.test' ) ),
		);

		$errors = $this->validate_rows( $rows );

		$dup_errors = array_values(
			array_filter( $errors, static fn( $e ) => false !== strpos( $e, 'duplicate_candidate_adjutancy' ) )
		);
		// Once row 3 bridges the two prior identities, both row 2 and
		// row 3 collapse onto the same logical candidate as row 1 —
		// so the per-row loop surfaces TWO duplicate errors (one for
		// row 2 vs the established row 1 pair, one for row 3 vs the
		// same pair). Two errors is the right answer: each subsequent
		// row that hits the same (candidate, adjutancy) deserves its
		// own per-line message so the operator can clean any of them.
		$this->assertCount( 2, $dup_errors );
		$lines = implode( ' | ', $dup_errors );
		// Format is `line=N: …` (see line_error()). Pin the prefixes
		// of both offending rows so we know both made it through.
		$this->assertStringContainsString( 'line=3', $lines );
		$this->assertStringContainsString( 'line=4', $lines );
	}

	// ------------------------------------------------------------------
	// validate() — per-row field-shape rules.
	// ------------------------------------------------------------------

	public function test_validate_rejects_row_with_no_cpf_or_rf(): void {
		$rows   = array( $this->csv_row( array( 'cpf' => '', 'rf' => '' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'recruitment_csv_missing_cpf_or_rf', $errors[0] );
	}

	public function test_validate_rejects_cpf_too_long(): void {
		$rows   = array( $this->csv_row( array( 'cpf' => '123456789012345' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_cpf_too_long', $errors[0] );
	}

	public function test_validate_rejects_rf_too_long(): void {
		$rows   = array( $this->csv_row( array( 'cpf' => '', 'rf' => '123456789' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_rf_too_long', $errors[0] );
	}

	public function test_validate_rejects_missing_score(): void {
		$rows   = array( $this->csv_row( array( 'score' => '' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_missing_score', $errors[0] );
	}

	public function test_validate_rejects_comma_decimal_score(): void {
		$rows   = array( $this->csv_row( array( 'score' => '90,5' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_score_uses_comma_decimal', $errors[0] );
	}

	public function test_validate_rejects_non_numeric_score(): void {
		$rows   = array( $this->csv_row( array( 'score' => 'abc' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_score_invalid_format', $errors[0] );
	}

	public function test_validate_rejects_zero_rank(): void {
		$rows   = array( $this->csv_row( array( 'rank' => '0' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_rank_invalid', $errors[0] );
	}

	public function test_validate_rejects_non_numeric_rank(): void {
		$rows   = array( $this->csv_row( array( 'rank' => 'x' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_rank_invalid', $errors[0] );
	}

	public function test_validate_rejects_comma_decimal_time_points(): void {
		$rows   = array( $this->csv_row( array( 'time_points' => '1,5' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_time_points_uses_comma_decimal', $errors[0] );
	}

	public function test_validate_rejects_invalid_time_points_format(): void {
		$rows   = array( $this->csv_row( array( 'time_points' => 'abc' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_time_points_invalid_format', $errors[0] );
	}

	public function test_validate_accepts_valid_time_points(): void {
		$rows   = array( $this->csv_row( array( 'time_points' => '12.5' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertEmpty( $errors );
	}

	public function test_validate_rejects_missing_adjutancy_slug(): void {
		$rows   = array( $this->csv_row( array( 'adjutancy' => '' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_missing_adjutancy', $errors[0] );
	}

	public function test_validate_rejects_adjutancy_not_in_notice(): void {
		$rows   = array( $this->csv_row( array( 'adjutancy' => 'unknown' ) ) );
		$errors = $this->validate_rows( $rows );

		$this->assertStringContainsString( 'recruitment_csv_adjutancy_not_in_notice: unknown', $errors[0] );
	}

	public function test_validate_detects_candidate_field_divergence_same_cpf(): void {
		// Same CPF in two rows but a different name. The rows sit in
		// DIFFERENT adjutancies (both in the map) so the duplicate-pair
		// check passes and the candidate-field divergence rule fires: the
		// first row is the reference, the second diverges on `name`.
		$rows = array(
			$this->csv_row( array( '_line' => 2, 'cpf' => '12345678901', 'name' => 'Alice', 'adjutancy' => 'mat' ) ),
			$this->csv_row( array( '_line' => 3, 'cpf' => '12345678901', 'name' => 'Alicia', 'adjutancy' => 'por' ) ),
		);

		$errors = $this->validate_rows(
			$rows,
			array(
				'mat' => 1,
				'por' => 2,
			)
		);

		$div = array_filter( $errors, static fn ( $e ) => false !== strpos( $e, 'candidate_field_divergence' ) );
		$this->assertNotEmpty( $div );
		$this->assertStringContainsString( 'field=name', implode( '', $div ) );
	}

	public function test_validate_accepts_clean_single_row(): void {
		$errors = $this->validate_rows( array( $this->csv_row() ) );
		$this->assertEmpty( $errors );
	}
}
