<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\SchedulingMailer;

/**
 * Tests for SchedulingMailer: HTML chrome wrapping and send() transport.
 *
 * @covers \FreeFormCertificate\Scheduling\SchedulingMailer
 */
class SchedulingMailerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_mail' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // wrap_html()
    // ==================================================================

    public function test_wrap_html_contains_body_content(): void {
        $html = SchedulingMailer::wrap_html( '<p>Test content</p>' );
        $this->assertStringContainsString( '<p>Test content</p>', $html );
    }

    public function test_wrap_html_has_doctype(): void {
        $html = SchedulingMailer::wrap_html( 'body' );
        $this->assertStringContainsString( '<!DOCTYPE html>', $html );
    }

    public function test_wrap_html_includes_site_name(): void {
        $html = SchedulingMailer::wrap_html( 'body' );
        $this->assertStringContainsString( 'Test Site', $html );
    }

    public function test_wrap_html_has_header_content_footer(): void {
        $html = SchedulingMailer::wrap_html( 'body' );
        $this->assertStringContainsString( "class='header'", $html );
        $this->assertStringContainsString( "class='content'", $html );
        $this->assertStringContainsString( "class='footer'", $html );
    }

    // ==================================================================
    // send()
    // ==================================================================

    public function test_send_wraps_body_by_default(): void {
        $sent_body = null;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
            $sent_body = $body;
            return true;
        } );

        SchedulingMailer::send( 'test@example.com', 'Subject', '<p>Body</p>' );
        $this->assertStringContainsString( '<!DOCTYPE html>', $sent_body );
        $this->assertStringContainsString( '<p>Body</p>', $sent_body );
    }

    public function test_send_skip_wrap_when_false(): void {
        $sent_body = null;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
            $sent_body = $body;
            return true;
        } );

        SchedulingMailer::send( 'test@example.com', 'Subject', '<p>Raw</p>', array(), false );
        $this->assertSame( '<p>Raw</p>', $sent_body );
    }

    public function test_send_returns_wp_mail_result(): void {
        Functions\when( 'wp_mail' )->justReturn( false );
        $this->assertFalse( SchedulingMailer::send( 'test@example.com', 'Subj', 'Body' ) );
    }
}
