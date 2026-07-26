<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\CsvParser;

/**
 * #563 Sprint 6 (A3, PR 6a) — unit tests for the pure CsvParser layer
 * extracted from RecruitmentCsvImporter.
 *
 * Pins every public method directly: the header contract, the two leaf
 * helpers (`parse_pcd_flag()` / `build_row()`), plus `parse()` (delimiter/BOM
 * auto-detection, missing-header + empty-input rejection, empty-row skipping,
 * 1-based `_line` numbering) and `normalise_id()` (digit stripping, left-pad,
 * too-long flagging). `parse()`/`normalise_id()` are also exercised via
 * RecruitmentCsvImporterTest, but pcov does not attribute that cross-class run
 * back to CsvParser — the direct tests here credit the coverage and pin the
 * leaf behaviour explicitly.
 *
 * @covers \FreeFormCertificate\Recruitment\CsvParser
 */
class CsvParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// pcov only attributes coverage to a class loaded before the test
		// method runs; preload CsvParser (and the Csv reader it delegates to)
		// so parse()/normalise_id() coverage is credited to this file.
		class_exists( '\\FreeFormCertificate\\Recruitment\\CsvParser' );
		class_exists( '\\FreeFormCertificate\\Core\\Csv' );
	}

	public function test_header_constants_are_the_documented_contract(): void {
		$this->assertSame(
			array( 'name', 'cpf', 'rf', 'email', 'adjutancy', 'rank', 'score', 'pcd' ),
			CsvParser::REQUIRED_HEADERS
		);
		$this->assertSame(
			array( 'phone', 'time_points', 'hab_emebs' ),
			CsvParser::OPTIONAL_HEADERS
		);
	}

	/**
	 * @dataProvider provide_pcd_truthy
	 *
	 * @param mixed $value
	 */
	public function test_parse_pcd_flag_truthy_values( $value ): void {
		$this->assertTrue( CsvParser::parse_pcd_flag( $value ) );
	}

	/**
	 * @return array<int, array{0: mixed}>
	 */
	public static function provide_pcd_truthy(): array {
		return array(
			array( true ),
			array( '1' ),
			array( 'true' ),
			array( 'TRUE' ),
			array( ' Sim ' ),
			array( 'yes' ),
		);
	}

	/**
	 * @dataProvider provide_pcd_falsy
	 *
	 * @param mixed $value
	 */
	public function test_parse_pcd_flag_falsy_values( $value ): void {
		$this->assertFalse( CsvParser::parse_pcd_flag( $value ) );
	}

	/**
	 * @return array<int, array{0: mixed}>
	 */
	public static function provide_pcd_falsy(): array {
		return array(
			array( false ),
			array( '0' ),
			array( '' ),
			array( 'no' ),
			array( 'nao' ),
			array( 'false' ),
			array( 'whatever' ),
		);
	}

	public function test_build_row_maps_named_columns_by_index(): void {
		// Header order intentionally shuffled vs. REQUIRED_HEADERS order.
		$index_map = array(
			'cpf'       => 0,
			'name'      => 1,
			'rf'        => 2,
			'email'     => 3,
			'adjutancy' => 4,
			'rank'      => 5,
			'score'     => 6,
			'pcd'       => 7,
		);
		$cells = array( '12345678909', 'Alice', '0001234', 'a@b.co', 'mat', '1', '9.5', 'sim' );
		$row   = CsvParser::build_row( $cells, $index_map );

		$this->assertSame( 'Alice', $row['name'] );
		$this->assertSame( '12345678909', $row['cpf'] );
		$this->assertSame( '9.5', $row['score'] );
		$this->assertSame( 'sim', $row['pcd'] );
	}

	public function test_build_row_fills_missing_optional_columns_with_empty_string(): void {
		// Only the required columns are present in the map.
		$index_map = array(
			'name'      => 0,
			'cpf'       => 1,
			'rf'        => 2,
			'email'     => 3,
			'adjutancy' => 4,
			'rank'      => 5,
			'score'     => 6,
			'pcd'       => 7,
		);
		$cells = array( 'Bob', '12345678909', '0001234', 'b@c.co', 'mat', '2', '8.0', 'no' );
		$row   = CsvParser::build_row( $cells, $index_map );

		// Optional headers default to '' so downstream code is uniform.
		$this->assertSame( '', $row['phone'] );
		$this->assertSame( '', $row['time_points'] );
		$this->assertSame( '', $row['hab_emebs'] );
	}

	// ==================================================================
	// parse()
	// ==================================================================

	public function test_parse_rejects_empty_content(): void {
		$result = CsvParser::parse( "   \n  " );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( array( 'recruitment_csv_empty' ), $result['errors'] );
	}

	public function test_parse_rejects_missing_required_headers(): void {
		// 'rf', 'adjutancy', 'score', 'pcd' absent from the header line.
		$content = "name,cpf,email,rank\nAlice,123,a@b.co,1\n";
		$result  = CsvParser::parse( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $result['rows'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringStartsWith( 'recruitment_csv_missing_headers:', $result['errors'][0] );
		$this->assertStringContainsString( 'rf', $result['errors'][0] );
		$this->assertStringContainsString( 'adjutancy', $result['errors'][0] );
	}

	public function test_parse_maps_rows_and_assigns_1_based_line_numbers(): void {
		$content = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678909,0001234,a@b.co,mat,1,9.5,sim\n"
			. "Bob,98765432100,0007654,b@c.co,adm,2,8.0,no\n";
		$result = CsvParser::parse( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 2, $result['rows'] );

		// Header is logical row 1, so the first data row is _line 2.
		$this->assertSame( 2, $result['rows'][0]['_line'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
		$this->assertSame( 'sim', $result['rows'][0]['pcd'] );
		$this->assertSame( 3, $result['rows'][1]['_line'] );
		$this->assertSame( 'Bob', $result['rows'][1]['name'] );

		// Optional columns absent from the file are still present as ''.
		$this->assertSame( '', $result['rows'][0]['phone'] );
	}

	public function test_parse_skips_all_whitespace_rows(): void {
		$content = "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678909,0001234,a@b.co,mat,1,9.5,sim\n"
			. ",,,,,,,\n"
			. "Bob,98765432100,0007654,b@c.co,adm,2,8.0,no\n";
		$result = CsvParser::parse( $content );

		$this->assertTrue( $result['ok'] );
		// The blank middle row is dropped, but line numbers still advance
		// (Bob is _line 4, not 3).
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
		$this->assertSame( 'Bob', $result['rows'][1]['name'] );
		$this->assertSame( 4, $result['rows'][1]['_line'] );
	}

	public function test_parse_auto_detects_semicolon_delimiter(): void {
		$content = "name;cpf;rf;email;adjutancy;rank;score;pcd\n"
			. "Alice;12345678909;0001234;a@b.co;mat;1;9,5;sim\n";
		$result = CsvParser::parse( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
		$this->assertSame( 'a@b.co', $result['rows'][0]['email'] );
	}

	public function test_parse_strips_utf8_bom_from_first_header(): void {
		$bom     = "\xEF\xBB\xBF";
		$content = $bom . "name,cpf,rf,email,adjutancy,rank,score,pcd\n"
			. "Alice,12345678909,0001234,a@b.co,mat,1,9.5,sim\n";
		$result = CsvParser::parse( $content );

		// A leaked BOM would make the first header 'ï»¿name' and fail the
		// required-header check; a clean parse proves the BOM was stripped.
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['rows'][0]['name'] );
	}

	// ==================================================================
	// normalise_id()
	// ==================================================================

	public function test_normalise_id_strips_non_digits(): void {
		$out = CsvParser::normalise_id( '123.456.789-09', 11 );
		$this->assertSame( '12345678909', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_id_left_pads_short_values(): void {
		// Excel/Sheets drop leading zeros; a 4-digit RF pads to width 7.
		$out = CsvParser::normalise_id( '1234', 7 );
		$this->assertSame( '0001234', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_id_flags_too_long_without_truncating(): void {
		$out = CsvParser::normalise_id( '123456789012', 11 );
		$this->assertTrue( $out['too_long'] );
		$this->assertSame( '123456789012', $out['value'] );
	}

	public function test_normalise_id_returns_empty_for_no_digits(): void {
		$out = CsvParser::normalise_id( 'abc-.', 11 );
		$this->assertSame( '', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}

	public function test_normalise_id_leaves_exact_width_untouched(): void {
		$out = CsvParser::normalise_id( '12345678909', 11 );
		$this->assertSame( '12345678909', $out['value'] );
		$this->assertFalse( $out['too_long'] );
	}
}
