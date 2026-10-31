<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for CsvDownloadLogExportSource: the per-phase authorization gate (nonce /
 * form / edit-cap / audit-cap → wp_die with the right HTTP code) and the
 * decrypting row generator (timestamp render, CPF decrypt, non-array skip, and
 * the encryption-off advisory row). The streaming lifecycle it plugs into is
 * tested via SyncCsvExport / CsvStreamer. Split from the former inline
 * PublicCsvDownload::handle_export_log_request (issue #772).
 *
 * Capabilities, Encryption, CsvDownloadAuditLog and ActivityLog are the real
 * classes: the log entries carry ciphertexts the real cipher wrote, and a
 * clear export is read off the activity log's buffer (#2121).
 *
 * @covers \FreeFormCertificate\Frontend\Csv\CsvDownloadLogExportSource
 */
class CsvDownloadLogExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $died = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The PII export rule (#1863): the exporter holds the PII cap unless a
		// test says otherwise, and a masked cell asks is_email().
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		class_exists( '\\FreeFormCertificate\\Frontend\\Csv\\CsvDownloadLogExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_date' )->justReturn( '2023-11-14 22:13:20' );
		Functions\when( 'wp_die' )->alias(
			function ( $msg = '', $code = null ) {
				$this->died = array( 'msg' => $msg, 'code' => $code );
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$_GET = array( '_wpnonce' => 'n' );

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback
		);
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
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
		// Edits the form, holds neither the audit capability nor manage_options.
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_post' === $cap );

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
		// The IP is read through the audit log's decryptor, never off the entry
		// (#1574): a plaintext `ip` beside the ciphertext must not win.
		$log = array(
			array(
				'ts'            => 1700000000,
				'ip_encrypted'  => Encryption::encrypt( '1.2.3.4' ),
				'ip'            => '9.9.9.9',
				'mode'          => 'audit',
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ),
				'result'        => 'audit_pass',
			),
			'not-an-array',
		);
		Functions\when( 'get_post_meta' )->justReturn( $log );

		$rows = iterator_to_array( ( new CsvDownloadLogExportSource( 42 ) )->rows(), false );

		// Encryption configured → no advisory row; one data row (non-array skipped).
		$this->assertCount( 1, $rows );
		$cpf = Identifiers::CPF_A;
		$formatted = substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
		$this->assertSame( array( '2023-11-14 22:13:20', '1.2.3.4', 'audit', $formatted, 'audit_pass' ), $rows[0] );
		// A clear export is a bulk reveal, recorded once (#1863).
		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'pii_exported', $entries[0]['action'] );
		$this->assertSame( 'info', $entries[0]['level'] );
		$this->assertSame( 1, $entries[0]['user_id'] );
		$this->assertSame(
			array( 'export' => 'csv_download_log', 'rows' => 1 ),
			ActivityLogBuffer::contexts()[0]['context']
		);
	}

	public function test_rows_mask_the_cpf_and_ip_without_the_pii_cap_and_record_nothing(): void {
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn(
			array(
				array(
					'ts'            => 1700000000,
					'ip_encrypted'  => Encryption::encrypt( '1.2.3.4' ),
					'mode'          => 'audit',
					'cpf_encrypted' => Encryption::encrypt( '51817842080' ),
					'result'        => 'audit_pass',
				),
			)
		);

		$rows = iterator_to_array( ( new CsvDownloadLogExportSource( 42 ) )->rows(), false );

		$this->assertSame( array( '2023-11-14 22:13:20', '1.2.***.***', 'audit', '518.***.***-80', 'audit_pass' ), $rows[0] );
		$this->assertSame( array(), ActivityLogBuffer::entries(), 'A masked export disclosed nothing.' );
	}

	/**
	 * Whether the cipher is configured is fixed by the wp-config constants the
	 * bootstrap defines for the whole process; only an alias mock can answer
	 * "not configured", so this test has a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
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
