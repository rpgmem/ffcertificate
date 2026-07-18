<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Stub exposing EmailHelperTrait's protected static methods for testing.
 */
class EmailHelperTraitStub {
    use \FreeFormCertificate\Core\EmailHelperTrait;

    public static function pub_emails_disabled(): bool {
        return self::ffc_emails_disabled();
    }

    public static function pub_send_mail( string $to, string $subject, string $body, array $attachments = array() ): bool {
        return self::ffc_send_mail( $to, $subject, $body, $attachments );
    }

    public static function pub_parse_admin_emails( string $emails, string $fallback = '' ): array {
        return self::ffc_parse_admin_emails( $emails, $fallback );
    }

    public static function pub_render_email_partial( string $template, array $args = array() ): string {
        return self::ffc_render_email_partial( $template, $args );
    }

    public static function pub_email_document( string $content ): string {
        return self::ffc_email_document( $content );
    }

    public static function pub_admin_notification_table( array $details ): string {
        return self::ffc_admin_notification_table( $details );
    }
}

/**
 * Tests for EmailHelperTrait: email disable check, admin email parsing,
 * send wrapper, HTML header/footer, notification table.
 */
class EmailHelperTraitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'wp_date' )->justReturn( '2026' );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( 'wp_mail' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) return array();
            if ( $key === 'admin_email' ) return 'admin@example.com';
            return $default;
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
        } );

    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // ffc_emails_disabled()
    // ==================================================================

    public function test_emails_not_disabled_by_default(): void {
        $this->assertFalse( EmailHelperTraitStub::pub_emails_disabled() );
    }

    public function test_emails_disabled_when_setting_true(): void {
        Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );
        $this->assertTrue( EmailHelperTraitStub::pub_emails_disabled() );
    }

    public function test_emails_not_disabled_when_setting_empty(): void {
        Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => '' ) );
        $this->assertFalse( EmailHelperTraitStub::pub_emails_disabled() );
    }

    // ==================================================================
    // ffc_parse_admin_emails()
    // ==================================================================

    public function test_parse_single_email(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( 'test@example.com' );
        $this->assertSame( array( 'test@example.com' ), $result );
    }

    public function test_parse_multiple_emails(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( 'a@test.com, b@test.com, c@test.com' );
        $this->assertCount( 3, $result );
        $this->assertContains( 'a@test.com', $result );
        $this->assertContains( 'b@test.com', $result );
        $this->assertContains( 'c@test.com', $result );
    }

    public function test_parse_filters_invalid_emails(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( 'good@test.com, not-an-email, other@test.com' );
        $this->assertCount( 2, $result );
        $this->assertNotContains( 'not-an-email', $result );
    }

    public function test_parse_empty_string_uses_admin_email(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( '' );
        $this->assertSame( array( 'admin@example.com' ), $result );
    }

    public function test_parse_empty_string_uses_custom_fallback(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( '', 'fallback@test.com' );
        $this->assertSame( array( 'fallback@test.com' ), $result );
    }

    public function test_parse_trims_whitespace(): void {
        $result = EmailHelperTraitStub::pub_parse_admin_emails( '  a@test.com ,  b@test.com  ' );
        $this->assertContains( 'a@test.com', $result );
        $this->assertContains( 'b@test.com', $result );
    }

    // ==================================================================
    // ffc_send_mail()
    // ==================================================================

    public function test_send_mail_returns_true_on_success(): void {
        $this->assertTrue( EmailHelperTraitStub::pub_send_mail( 'to@test.com', 'Subject', '<p>Body</p>' ) );
    }

    public function test_send_mail_returns_false_on_failure(): void {
        Functions\when( 'wp_mail' )->justReturn( false );
        $this->assertFalse( EmailHelperTraitStub::pub_send_mail( 'to@test.com', 'Subject', 'Body' ) );
    }

    // ==================================================================
    // ffc_render_email_partial() / ffc_email_document()
    // ==================================================================

    public function test_render_email_partial_returns_empty_for_missing_template(): void {
        $html = EmailHelperTraitStub::pub_render_email_partial( 'does-not-exist-xyz' );
        $this->assertSame( '', $html );
    }

    public function test_render_email_partial_renders_layout_with_content(): void {
        $html = EmailHelperTraitStub::pub_render_email_partial( 'layout', array( 'content' => '<span>MIOLO</span>' ) );
        $this->assertStringContainsString( '<span>MIOLO</span>', $html );
        // The configurable chrome is table-based (Gmail/Outlook safe).
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringContainsString( 'font-family', $html );
    }

    public function test_email_document_wraps_content_in_chrome(): void {
        $html = EmailHelperTraitStub::pub_email_document( '<p>INNER</p>' );
        $this->assertStringContainsString( '<p>INNER</p>', $html );
        // Footer chrome carries the site name.
        $this->assertStringContainsString( 'Test Site', $html );
    }

    // ==================================================================
    // ffc_admin_notification_table()
    // ==================================================================

    public function test_notification_table_has_table_tags(): void {
        $html = EmailHelperTraitStub::pub_admin_notification_table( array( 'Name' => 'João' ) );
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringContainsString( '</table>', $html );
    }

    public function test_notification_table_contains_label_and_value(): void {
        $html = EmailHelperTraitStub::pub_admin_notification_table( array(
            'Name'  => 'Maria',
            'Email' => 'maria@test.com',
        ) );
        $this->assertStringContainsString( 'Name', $html );
        $this->assertStringContainsString( 'Maria', $html );
        $this->assertStringContainsString( 'Email', $html );
        $this->assertStringContainsString( 'maria@test.com', $html );
    }

    public function test_notification_table_row_count(): void {
        $html = EmailHelperTraitStub::pub_admin_notification_table( array(
            'A' => '1',
            'B' => '2',
            'C' => '3',
        ) );
        $this->assertSame( 3, substr_count( $html, '<tr>' ) );
    }

    public function test_notification_table_empty_details(): void {
        $html = EmailHelperTraitStub::pub_admin_notification_table( array() );
        $this->assertStringContainsString( '<table', $html );
        $this->assertStringNotContainsString( '<tr>', $html );
    }
}
