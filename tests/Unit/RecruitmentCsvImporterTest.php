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
			'status' => 'active',
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
}
