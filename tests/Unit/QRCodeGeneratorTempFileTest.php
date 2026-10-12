<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Debug;
use FreeFormCertificate\Generators\QRCodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * QRCodeGenerator::generate() through `wp_tempnam()`.
 *
 * The generator prefers `wp_tempnam()` when the function exists. Stubbing it
 * makes Patchwork DEFINE it for the rest of the process, after which every
 * other test that reaches generate() without a stub fails with "not defined
 * nor mocked" — the function_exists() trap described in CLAUDE.md. So these
 * tests run in their own processes.
 *
 * @covers \FreeFormCertificate\Generators\QRCodeGenerator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class QRCodeGeneratorTempFileTest extends TestCase {

	/**
	 * File the debug breadcrumbs land in.
	 */
	private string $log_file = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'ffc-log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Child process only: captures Debug::log() output for assertion.

		// QR Code debug area on; no other setting.
		Functions\when( 'get_option' )->justReturn( array( Debug::AREA_QRCODE => 1 ) );
		Functions\when( 'esc_attr__' )->returnArg();
	}

	protected function tearDown(): void {
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * What Debug::log() wrote during the test.
	 */
	private function log(): string {
		return (string) file_get_contents( $this->log_file );
	}

	public function test_generate_uses_wp_tempnam_and_deletes_the_file(): void {
		$temp = (string) tempnam( sys_get_temp_dir(), 'ffc_qr_' );
		Functions\when( 'wp_tempnam' )->justReturn( $temp );
		$deleted = array();
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) use ( &$deleted ) {
				$deleted[] = $file;
				unlink( $file );
			}
		);

		$base64 = ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' );

		$this->assertStringStartsWith( "\x89PNG", (string) base64_decode( $base64, true ) );
		$this->assertSame( array( $temp ), $deleted );
		$this->assertStringContainsString( 'QR Code generated successfully', $this->log() );
	}

	public function test_generate_gives_up_when_no_temp_file_can_be_created(): void {
		Functions\when( 'wp_tempnam' )->justReturn( false );

		$this->assertSame( '', ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' ) );
		$this->assertStringContainsString( 'QR Code: temp file creation failed', $this->log() );
	}

	public function test_generate_returns_nothing_when_the_image_was_not_written(): void {
		// A path that always reads back empty: the library writes into the void.
		Functions\when( 'wp_tempnam' )->justReturn( '/dev/null' );
		$deleted = array();
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) use ( &$deleted ) {
				$deleted[] = $file; // Recorded only: never unlink /dev/null.
			}
		);

		$this->assertSame( '', ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' ) );
		$this->assertSame( array( '/dev/null' ), $deleted, 'the empty file is cleaned up' );
		$this->assertStringContainsString( 'QR Code: temp file empty or missing after generation', $this->log() );
	}

	public function test_generate_contains_an_exception_inside_the_library_path(): void {
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				throw new \RuntimeException( 'disk full' );
			}
		);

		$this->assertSame( '', ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' ) );
		$log = $this->log();
		$this->assertStringContainsString( 'QR Code generation exception', $log );
		$this->assertStringContainsString( 'disk full', $log );
	}

	public function test_generate_contains_a_fatal_error_inside_the_library_path(): void {
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				throw new \Error( 'temp dir vanished' );
			}
		);

		$this->assertSame( '', ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' ) );
		$log = $this->log();
		$this->assertStringContainsString( 'QR Code generation fatal error', $log );
		$this->assertStringContainsString( 'temp dir vanished', $log );
	}
}
