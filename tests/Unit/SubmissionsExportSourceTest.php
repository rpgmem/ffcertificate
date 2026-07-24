<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SubmissionsExportSource;

/**
 * Tests for SubmissionsExportSource: the fixed + dynamic column layout, per-row
 * formatting (PII decryption, split CPF/RF), the dynamic-key scan, the row
 * count, and the per-phase authorization gates. The job lifecycle it plugs into
 * is tested separately in BatchedCsvExportTest. Split from the former
 * CsvExporter monolith (issue #772).
 *
 * @covers \FreeFormCertificate\Admin\SubmissionsExportSource
 */
class SubmissionsExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SubmissionsExportSource */
	private $source;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Admin\SubmissionsExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Test Form' );
		Functions\when( 'get_userdata' )->alias(
			static function () {
				$user               = new \stdClass();
				$user->display_name = 'Admin User';
				return $user;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

		$ref          = new \ReflectionClass( SubmissionsExportSource::class );
		$this->source = $ref->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke a private/protected method on the source. */
	private function invoke( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( SubmissionsExportSource::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->source, $args );
	}

	/** Inject a mock repository into the private `repository` property. */
	private function set_repository( $repo ): void {
		$prop = new \ReflectionProperty( SubmissionsExportSource::class, 'repository' );
		$prop->setAccessible( true );
		$prop->setValue( $this->source, $repo );
	}

	private function sample_row(): array {
		return array(
			'id'                => 1,
			'form_id'           => 42,
			'user_id'           => 10,
			// `submission_date` is unix UTC int since 6.6.0; 1736937000 = 2025-01-15 10:30:00 UTC.
			'submission_date'   => 1736937000,
			'email'             => 'test@example.com',
			'email_encrypted'   => '',
			'user_ip'           => '192.168.1.1',
			'user_ip_encrypted' => '',
			'cpf'               => '123.456.789-00',
			'cpf_encrypted'     => '',
			'rf'                => '',
			'rf_encrypted'      => '',
			'cpf_rf'            => '',
			'cpf_rf_encrypted'  => '',
			'auth_code'         => 'ABC123',
			'magic_token'       => 'abc123def456',
			'consent_given'     => 1,
			'consent_date'      => 1736937000,
			'consent_text'      => 'I agree',
			'status'            => 'publish',
			'data'              => '{"field_name":"John","field_city":"SP"}',
			'data_encrypted'    => '',
			'edited_at'         => '',
			'edited_by'         => '',
		);
	}

	// ==================================================================
	// get_fixed_headers()  — 15 fixed, 18 with edit columns
	// ==================================================================

	public function test_fixed_headers_without_edit_columns_returns_15(): void {
		$this->assertCount( 15, $this->invoke( 'get_fixed_headers', array( false ) ) );
	}

	public function test_fixed_headers_with_edit_columns_returns_18(): void {
		$this->assertCount( 18, $this->invoke( 'get_fixed_headers', array( true ) ) );
	}

	public function test_fixed_headers_contains_expected_strings(): void {
		$headers = $this->invoke( 'get_fixed_headers', array( false ) );
		foreach ( array( 'ID', 'Form', 'E-mail', 'User IP', 'CPF', 'RF', 'Auth Code', 'Token', 'Consent Given', 'Status' ) as $h ) {
			$this->assertContains( $h, $headers );
		}
	}

	public function test_fixed_headers_edit_columns_at_end(): void {
		$headers = $this->invoke( 'get_fixed_headers', array( true ) );
		$this->assertSame( 'Was Edited', $headers[15] );
		$this->assertSame( 'Edit Date', $headers[16] );
		$this->assertSame( 'Edited By', $headers[17] );
	}

	// ==================================================================
	// format_csv_row()
	// ==================================================================

	public function test_format_csv_row_basic_returns_correct_count(): void {
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array( 'field_name', 'field_city' ), false ) );
		$this->assertCount( 17, $result ); // 15 fixed + 2 dynamic.
	}

	public function test_format_csv_row_fixed_columns_values(): void {
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array(), false ) );
		$this->assertSame( 1, $result[0] );
		$this->assertSame( 'Test Form', $result[1] );
		$this->assertSame( 10, $result[2] );
		$this->assertSame( '15/01/2025 10:30', $result[3] );
		$this->assertSame( 'test@example.com', $result[4] );
		$this->assertSame( '192.168.1.1', $result[5] );
		$this->assertSame( '123.456.789-00', $result[6] );
		$this->assertSame( '', $result[7] );
		$this->assertSame( 'ABC123', $result[8] );
		$this->assertSame( 'abc123def456', $result[9] );
		$this->assertSame( 'Yes', $result[10] );
		$this->assertSame( 'publish', $result[14] );
	}

	public function test_format_csv_row_consent_no(): void {
		$row                  = $this->sample_row();
		$row['consent_given'] = 0;
		$result               = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
		$this->assertSame( 'No', $result[10] );
	}

	public function test_format_csv_row_deleted_form_title(): void {
		Functions\when( 'get_the_title' )->justReturn( '' );
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array(), false ) );
		$this->assertSame( '(Deleted)', $result[1] );
	}

	public function test_format_csv_row_with_edit_columns(): void {
		$row              = $this->sample_row();
		$row['edited_at'] = 1738400400; // 2025-02-01 09:00:00 UTC.
		$row['edited_by'] = 5;
		$result           = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
		$this->assertCount( 18, $result );
		$this->assertSame( 'Yes', $result[15] );
		$this->assertSame( '01/02/2025 09:00', $result[16] );
		$this->assertSame( 'Admin User', $result[17] );
	}

	public function test_format_csv_row_not_edited_empty_edit_columns(): void {
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array(), true ) );
		$this->assertSame( '', $result[15] );
		$this->assertSame( '', $result[16] );
		$this->assertSame( '', $result[17] );
	}

	public function test_format_csv_row_dynamic_columns_values(): void {
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array( 'field_name', 'field_city' ), false ) );
		$this->assertSame( 'John', $result[15] );
		$this->assertSame( 'SP', $result[16] );
	}

	public function test_format_csv_row_missing_dynamic_key_returns_empty(): void {
		$result = $this->invoke( 'format_csv_row', array( $this->sample_row(), array( 'field_name', 'nonexistent_field' ), false ) );
		$this->assertSame( 'John', $result[15] );
		$this->assertSame( '', $result[16] );
	}

	public function test_format_csv_row_rf_only(): void {
		$row        = $this->sample_row();
		$row['cpf'] = '';
		$row['rf']  = '1234567';
		$result     = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
		$this->assertSame( '', $result[6] );
		$this->assertSame( '1234567', $result[7] );
	}

	// ==================================================================
	// CsvExportTrait helpers (used by the source)
	// ==================================================================

	public function test_trait_build_dynamic_headers_snake_case(): void {
		$headers = $this->invoke( 'build_dynamic_headers', array( array( 'first_name', 'last_name' ) ) );
		$this->assertSame( 'First Name', $headers[0] );
		$this->assertSame( 'Last Name', $headers[1] );
	}

	public function test_trait_decode_json_field_plain_text(): void {
		$row    = array( 'data' => '{"name":"Alice"}', 'data_encrypted' => '' );
		$result = $this->invoke( 'decode_json_field', array( $row, 'data', 'data_encrypted' ) );
		$this->assertSame( array( 'name' => 'Alice' ), $result );
	}

	public function test_trait_decode_json_field_invalid_json_returns_empty_array(): void {
		$row    = array( 'data' => 'not json', 'data_encrypted' => '' );
		$this->assertSame( array(), $this->invoke( 'decode_json_field', array( $row, 'data', 'data_encrypted' ) ) );
	}

	public function test_trait_extract_dynamic_keys_unique_across_rows(): void {
		$rows = array(
			array( 'data' => '{"name":"A","email":"a@b.com"}', 'data_encrypted' => '' ),
			array( 'data' => '{"name":"B","phone":"123"}', 'data_encrypted' => '' ),
		);
		$keys = $this->invoke( 'extract_dynamic_keys', array( $rows, 'data', 'data_encrypted' ) );
		$this->assertCount( 3, $keys );
		$this->assertContains( 'phone', $keys );
	}

	// ==================================================================
	// scan_dynamic_keys()
	// ==================================================================

	public function test_scan_dynamic_keys_merges_unique_keys_across_batches(): void {
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'getExportKeysBatch' )->twice()->andReturn(
			array(
				array( 'id' => 10, 'data' => '{"name":"A","city":"SP"}' ),
				array( 'id' => 20, 'data' => '{"name":"B","age":"30"}' ),
			),
			array()
		);
		$this->set_repository( $repo );

		$keys = $this->invoke( 'scan_dynamic_keys', array( array( 1 ), 'publish' ) );
		sort( $keys );
		$this->assertSame( array( 'age', 'city', 'name' ), $keys );
	}

	public function test_scan_dynamic_keys_returns_empty_when_no_rows(): void {
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'getExportKeysBatch' )->once()->andReturn( array() );
		$this->set_repository( $repo );

		$this->assertSame( array(), $this->invoke( 'scan_dynamic_keys', array( null, 'publish' ) ) );
	}

	// ==================================================================
	// count()
	// ==================================================================

	public function test_count_delegates_to_repository(): void {
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'countForExport' )->once()->with( array( 7 ), 'trash' )->andReturn( 42 );
		$this->set_repository( $repo );

		$this->assertSame( 42, $this->source->count( array( 'form_ids' => array( 7 ), 'status' => 'trash' ) ) );
	}

	// ==================================================================
	// authorize_start() / authorize_batch() / authorize_download()
	// ==================================================================

	private function stub_terminators(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	public function test_authorize_start_rejects_without_capability(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'user_id' => 99 ) );
	}

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 1 ) );
	}

	public function test_authorize_download_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 99 ) );
	}
}
