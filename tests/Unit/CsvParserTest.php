<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\CsvParser;

/**
 * #563 Sprint 6 (A3, PR 6a) — unit tests for the pure CsvParser layer
 * extracted from RecruitmentCsvImporter.
 *
 * `parse()` and `normalise_id()` are already exercised end-to-end through
 * RecruitmentCsvImporterTest (the importer delegates to this class); here we
 * pin the two leaf helpers — `parse_pcd_flag()` and `build_row()` — that had
 * no direct coverage before the extraction, plus the header contract.
 *
 * @covers \FreeFormCertificate\Recruitment\CsvParser
 */
class CsvParserTest extends TestCase {

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
}
