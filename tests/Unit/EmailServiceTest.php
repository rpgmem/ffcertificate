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
		// On failure EmailService calls Debug::log_email → Debug::is_enabled →
		// get_option('ffc_settings'); stub it so the debug read no-ops.
		Functions\when( 'get_option' )->justReturn( array() );
		// Chrome derives a text/plain alternative for HTML sends (#673): stub
		// the WP glue it touches so plain sends stay behaviour-identical.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $s ) {
				return trim( (string) strip_tags( (string) $s ) );
			}
		);
		// apply_filters( $tag, $value, ... ) → return the value unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
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

	public function test_send_short_circuits_when_emails_globally_disabled(): void {
		// Master kill-switch is enforced here at the chokepoint (#662 P1):
		// wp_mail must never run when disable_all_emails is on.
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => '1' ) );
		$called = false;
		Functions\when( 'wp_mail' )->alias(
			function () use ( &$called ) {
				$called = true;
				return true;
			}
		);

		$result = EmailService::send( 'a@b.c', 'S', 'B' );

		$this->assertFalse( $result );
		$this->assertFalse( $called, 'wp_mail must not run when emails are globally disabled' );
	}

	// ==================================================================
	// multipart text/plain alternative (#673)
	// ==================================================================

	/**
	 * Drive an HTML send and run the captured phpmailer_init callback the way
	 * WordPress would, returning whatever AltBody it set (null if none).
	 *
	 * @param string             $body    HTML body.
	 * @param array<int, string> $headers Mail headers.
	 * @return string|null
	 */
	private function alt_body_for_send( string $body, array $headers ): ?string {
		$callback = null;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $cb ) use ( &$callback ) {
				if ( 'phpmailer_init' === $hook ) {
					$callback = $cb;
				}
				return true;
			}
		);
		Functions\when( 'remove_action' )->justReturn( true );

		$alt = null;
		Functions\when( 'wp_mail' )->alias(
			function () use ( &$callback, &$alt ) {
				if ( null !== $callback ) {
					$phpmailer          = new \stdClass();
					$phpmailer->AltBody = '';
					\call_user_func( $callback, $phpmailer );
					$alt = '' === $phpmailer->AltBody ? null : $phpmailer->AltBody;
				}
				return true;
			}
		);

		EmailService::send( 'a@b.c', 'Subj', $body, $headers );
		return $alt;
	}

	public function test_html_to_plain_text_strips_tags_keeps_links_and_collapses_blanks(): void {
		$html  = '<h2>Hello</h2><p>Visit <a href="https://x.test/go">the site</a> now.</p>';
		$html .= '<div>Line A</div><div>Line B</div>';
		$plain = EmailService::html_to_plain_text( $html );

		$this->assertStringContainsString( 'Hello', $plain );
		$this->assertStringContainsString( 'the site (https://x.test/go)', $plain );
		$this->assertStringContainsString( 'Line A', $plain );
		$this->assertStringContainsString( 'Line B', $plain );
		$this->assertStringNotContainsString( '<', $plain );
		$this->assertStringNotContainsString( "\n\n\n", $plain, 'blank runs should be collapsed' );
	}

	public function test_send_sets_alt_body_for_html_email(): void {
		$alt = $this->alt_body_for_send(
			'<p>Hello <a href="https://x.test">link</a></p>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		$this->assertIsString( $alt );
		$this->assertStringContainsString( 'Hello', $alt );
		$this->assertStringContainsString( 'https://x.test', $alt );
		$this->assertStringNotContainsString( '<p>', $alt );
	}

	public function test_send_skips_alt_body_for_non_html_email(): void {
		$alt = $this->alt_body_for_send( 'plain body', array() );
		$this->assertNull( $alt, 'non-HTML sends must not get an AltBody' );
	}

	public function test_send_skips_alt_body_when_filter_suppresses_it(): void {
		Functions\when( 'apply_filters' )->justReturn( '' );
		$alt = $this->alt_body_for_send(
			'<p>Body</p>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		$this->assertNull( $alt, 'an empty filtered plain text must suppress the alternative' );
	}
}
