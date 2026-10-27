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
 * makes Patchwork DEFINE it for the rest of the process, so every test that
 * reaches generate() stubs it too and chooses the branch rather than
 * inheriting it -- the function_exists() rule in CLAUDE.md. That is what lets
 * this class run in the shared process (#2091); only the fallback, which
 * needs the function never to have been defined, takes a process of its own.
 *
 * @covers \FreeFormCertificate\Generators\QRCodeGenerator
 */
class QRCodeGeneratorTempFileTest extends TestCase {

	/**
	 * File the debug breadcrumbs land in.
	 */
	private string $log_file = '';

	/**
	 * The error_log setting before the test pointed it at $log_file.
	 */
	private string $previous_error_log = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'ffc-log' );
		$this->previous_error_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Captures Debug::log() output for assertion; tearDown() restores it.

		// QR Code debug area on; no other setting.
		Functions\when( 'get_option' )->justReturn( array( Debug::AREA_QRCODE => 1 ) );
		Functions\when( 'esc_attr__' )->returnArg();
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores what setUp() changed.
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

	/**
	 * Its own process: the fallback runs only where wp_tempnam() was never
	 * defined, and any earlier stub of it in a shared process defines it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generate_falls_back_to_tempnam_without_wp_tempnam(): void {
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				unlink( $file );
			}
		);

		$this->assertFalse( function_exists( 'wp_tempnam' ) );
		$base64 = ( new QRCodeGenerator() )->generate( 'https://example.org/valid/' );

		$this->assertStringStartsWith( "\x89PNG", (string) base64_decode( $base64, true ) );
	}
}
