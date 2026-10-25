<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceBookingRules;
use FreeFormCertificate\Core\ConfirmCountdown;
use FreeFormCertificate\Core\OverrideWarning;
use PHPUnit\Framework\TestCase;

/**
 * Every confirmation countdown reads one value (#2078): the booking
 * cancellation waited 5 seconds while the override warning and the
 * recruitment promotion waited 15, each written as its own literal.
 *
 * @covers \FreeFormCertificate\Core\ConfirmCountdown
 */
class ConfirmCountdownTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_countdown_is_fifteen_seconds(): void {
		$this->assertSame( 15, ConfirmCountdown::SECONDS );
	}

	public function test_the_dialogs_read_the_one_value(): void {
		$this->assertSame( ConfirmCountdown::SECONDS, OverrideWarning::strings()['seconds'] );
		$this->assertSame( ConfirmCountdown::SECONDS, AudienceBookingRules::cancel_strings()['seconds'] );
	}

	public function test_no_countdown_is_written_as_a_literal(): void {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$read  = array();
		$found = array();

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$read[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = (string) php_strip_whitespace( $file->getPathname() );
			if ( preg_match_all( "/'(?:seconds|countdown)'\\s*=>\\s*\\d+/", $code, $m ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . implode( ', ', $m[0] );
			}
		}

		// The deepest site the countdown lives in: the scan must reach it.
		$this->assertContains( 'recruitment/class-ffc-recruitment-notice-edit-page-renderer.php', $read, 'The scan did not reach the recruitment renderer.' );
		$this->assertSame( array(), $found, 'A countdown should read ConfirmCountdown::SECONDS.' );
	}

	public function test_the_script_falls_back_to_the_same_value(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/ffc-core.js' );

		$this->assertSame( 1, preg_match( '/seconds = (\d+);/', $script, $m ), 'ffc-core.js should keep one countdown fallback.' );
		$this->assertSame( (string) ConfirmCountdown::SECONDS, $m[1] );
	}
}
