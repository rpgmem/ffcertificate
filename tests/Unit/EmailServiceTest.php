<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailService;

/**
 * @covers \FreeFormCertificate\Core\EmailService
 */
class EmailServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailService' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_send_passes_all_args_to_wp_mail_and_returns_result(): void {
		$captured = null;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array(), $attachments = array() ) use ( &$captured ) {
				$captured = compact( 'to', 'subject', 'body', 'headers', 'attachments' );
				return true;
			}
		);

		$result = EmailService::send( 'a@b.c', 'Subj', '<p>Hi</p>', array( 'Content-Type: text/html' ), array( '/tmp/x.pdf' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'a@b.c', $captured['to'] );
		$this->assertSame( 'Subj', $captured['subject'] );
		$this->assertSame( '<p>Hi</p>', $captured['body'] );
		$this->assertSame( array( 'Content-Type: text/html' ), $captured['headers'] );
		$this->assertSame( array( '/tmp/x.pdf' ), $captured['attachments'] );
	}

	public function test_send_defaults_headers_and_attachments_to_empty(): void {
		$captured = null;
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body, $headers = array(), $attachments = array() ) use ( &$captured ) {
				$captured = compact( 'headers', 'attachments' );
				return true;
			}
		);

		EmailService::send( 'a@b.c', 'S', 'B' );

		$this->assertSame( array(), $captured['headers'] );
		$this->assertSame( array(), $captured['attachments'] );
	}

	public function test_send_returns_false_when_wp_mail_fails(): void {
		Functions\when( 'wp_mail' )->justReturn( false );

		$this->assertFalse( EmailService::send( 'a@b.c', 'S', 'B' ) );
	}
}
