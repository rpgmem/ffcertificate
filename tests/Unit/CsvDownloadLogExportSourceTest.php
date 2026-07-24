<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource;

/**
 * Tests for CsvDownloadLogExportSource: the per-phase authorization gate (nonce /
 * form / edit-cap / audit-cap → wp_die with the right HTTP code) and the
 * decrypting row generator (timestamp render, CPF decrypt, non-array skip, and
 * the encryption-off advisory row). The streaming lifecycle it plugs into is
 * tested via SyncCsvExport / CsvStreamer. Split from the former inline
 * PublicCsvDownload::handle_export_log_request (issue #772).
 *
 * @covers \FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsvDownloadLogExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $died = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Frontend\\Csv\\CsvDownloadLogExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_date' )->justReturn( '2023-11-14 22:13:20' );
		Functions\when( 'wp_die' )->alias(
			function ( $msg = '', $code = null ) {
				$this->died = array( 'msg' => $msg, 'code' => $code );
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$_GET = array( '_wpnonce' => 'n' );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// authorize()
	// ==================================================================

	public function test_authorize_rejects_bad_nonce_403(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		try {
			( new CsvDownloadLogExportSource( 42 ) )->authorize();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $this->died['code'] );
		}
	}

	public function test_authorize_rejects_missing_form_404(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		try {
			( new CsvDownloadLogExportSource( 42 ) )->authorize();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 404, $this->died['code'] );
		}
	}

	public function test_authorize_rejects_without_edit_cap_403(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		Functions\when( 'current_user_can' )->justReturn( false );

		try {
			( new CsvDownloadLogExportSource( 42 ) )->authorize();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $this->died['code'] );
		}
	}

	public function test_authorize_rejects_without_audit_cap_403(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_post' === $cap );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		try {
			( new CsvDownloadLogExportSource( 42 ) )->authorize();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $this->died['code'] );
		}
	}

	public function test_authorize_passes_and_calls_nocache(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		$called = false;
		Functions\when( 'nocache_headers' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);

		( new CsvDownloadLogExportSource( 42 ) )->authorize();
		$this->assertTrue( $called, 'nocache_headers called' );
	}

	// ==================================================================
	// filename() / header()
	// ==================================================================

	public function test_filename_and_header_shape(): void {
		$source = new CsvDownloadLogExportSource( 7 );
		$this->assertStringContainsString( 'ffc-csv-download-log-7-', $source->filename() );
		$this->assertStringEndsWith( '.csv', $source->filename() );
		$this->assertSame( array( 'timestamp', 'ip', 'mode', 'cpf', 'result' ), $source->header() );
	}

	// ==================================================================
	// rows()
	// ==================================================================

	public function test_rows_decrypts_cpf_and_skips_non_array(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'is_configured' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog' )
			->shouldReceive( 'decrypt_log_entry_cpf' )->andReturn( '52998224725' );

		$log = array(
			array( 'ts' => 1700000000, 'ip' => '1.2.3.4', 'mode' => 'audit', 'cpf_encrypted' => 'e', 'result' => 'audit_pass' ),
			'not-an-array',
		);
		Functions\when( 'get_post_meta' )->justReturn( $log );

		$rows = iterator_to_array( ( new CsvDownloadLogExportSource( 42 ) )->rows(), false );

		// Encryption configured → no advisory row; one data row (non-array skipped).
		$this->assertCount( 1, $rows );
		$this->assertSame( array( '2023-11-14 22:13:20', '1.2.3.4', 'audit', '52998224725', 'audit_pass' ), $rows[0] );
	}

	public function test_rows_emits_advisory_when_encryption_off(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'is_configured' )->andReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog' )
			->shouldReceive( 'decrypt_log_entry_cpf' )->andReturn( '' );

		Functions\when( 'get_post_meta' )->justReturn( array() );

		$rows = iterator_to_array( ( new CsvDownloadLogExportSource( 42 ) )->rows(), false );

		$this->assertCount( 1, $rows, 'only the advisory row (empty log)' );
		$this->assertStringStartsWith( '# Encryption is not configured', $rows[0][0] );
	}
}
