<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCsvImporter;

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
 */
class RecruitmentCsvImporterTest extends TestCase {

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
	 * Call the private `normalise_id` helper via reflection.
	 *
	 * @param string $raw
	 * @param int    $expected_length
	 * @return array{value: string, too_long: bool}
	 */
	private function normalise( string $raw, int $expected_length ): array {
		$ref = new \ReflectionClass( RecruitmentCsvImporter::class );
		$m   = $ref->getMethod( 'normalise_id' );
		$m->setAccessible( true );
		return $m->invoke( null, $raw, $expected_length );
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

	// ──────────────────────────────────────────────────────────────────────.
	// Batched-import early-exit envelopes.
	//
	// The happy path (parse + validate + tmp file write + transient set)
	// exercises wp_upload_dir(), wp_mkdir_p(), wp_generate_uuid4() and
	// file_put_contents() — all of them straddling the filesystem boundary
	// that Brain\Monkey can't stub cleanly. Integration tests cover the
	// happy path; here we pin the early-rejection envelopes since those
	// are the user-visible "your CSV was rejected because…" messages.
	// ──────────────────────────────────────────────────────────────────────.

	public function test_start_job_rejects_unknown_notice(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		// Mock the repository lookup to return null (notice not found).
		// `get_by_id()` queries via $wpdb; with no shouldReceive setup the
		// partial mock returns null which the repo treats as "not found".
		$out = RecruitmentCsvImporter::start_job( 999_999, 'name,cpf,rf,email,adjutancy,rank,score,pcd', 'preview' );

		$this->assertSame( array( 'ok' => false, 'errors' => array( 'recruitment_notice_not_found' ) ), $out );
	}

	public function test_process_job_batch_returns_not_found_when_transient_missing(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$out = RecruitmentCsvImporter::process_job_batch( 'nonexistent-job-id', 50 );

		$this->assertSame( array( 'ok' => false, 'errors' => array( 'recruitment_import_job_not_found' ) ), $out );
	}

	public function test_commit_job_returns_not_found_when_transient_missing(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$out = RecruitmentCsvImporter::commit_job( 'nonexistent-job-id' );

		$this->assertSame( array( 'ok' => false, 'errors' => array( 'recruitment_import_job_not_found' ) ), $out );
	}

	public function test_commit_job_rejects_when_cursor_below_total(): void {
		// The commit endpoint must not swap when batches haven't finished.
		// Without this guard, an early commit would publish a partial list.
		Functions\when( 'get_transient' )->justReturn(
			array(
				'notice_id' => 1,
				'list_type' => 'preview',
				'file'      => '/tmp/nonexistent.json',
				'total'     => 100,
				'cursor'    => 25, // 75 rows still pending.
				'inserted'  => 25,
				'user_id'   => 1,
				'created'   => time(),
			)
		);

		$out = RecruitmentCsvImporter::commit_job( 'half-done-job' );

		$this->assertSame( array( 'ok' => false, 'errors' => array( 'recruitment_import_job_not_finished' ) ), $out );
	}

	public function test_batch_size_constants_match_documented_defaults(): void {
		// Pins the public batch-size + TTL contract: AJAX callers (the
		// JS-side `BATCH_SIZE_DEFAULT` and the REST controller `args`)
		// reference these constants by name, so any future bump should
		// happen in lockstep with a CHANGELOG note.
		$this->assertSame( 50, RecruitmentCsvImporter::BATCH_SIZE_DEFAULT );
		$this->assertSame( 3600, RecruitmentCsvImporter::JOB_TTL );
	}
}
