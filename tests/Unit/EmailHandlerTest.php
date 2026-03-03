<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\EmailHandler;

/**
 * Tests for EmailHandler: SMTP configuration, user email sending, and admin notifications.
 *
 * Note: send_wp_user_notification() context logic is covered by EmailHandlerContextTest.
 *
 * @covers \FreeFormCertificate\Integrations\EmailHandler
 */
class EmailHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array{to: string, subject: string, body: string}> Captured emails */
    private array $sent_emails = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wpautop' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn();
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'site_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'untrailingslashit' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );

        // Capture wp_mail calls
        $this->sent_emails = array();
        $emails = &$this->sent_emails;
        Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) use ( &$emails ) {
            $emails[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_hooks(): void {
        // add_action is stubbed in setUp — verify constructor completes
        $handler = new EmailHandler();
        $this->assertInstanceOf( EmailHandler::class, $handler );
    }

    // ==================================================================
    // configure_custom_smtp() — custom mode
    // ==================================================================

    public function test_configure_custom_smtp_sets_phpmailer_when_custom_mode(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'smtp_mode'       => 'custom',
            'smtp_host'       => 'smtp.example.com',
            'smtp_port'       => '465',
            'smtp_user'       => 'user@example.com',
            'smtp_pass'       => 'secret',
            'smtp_secure'     => 'ssl',
            'smtp_from_email' => 'noreply@example.com',
            'smtp_from_name'  => 'My App',
        ) );

        $phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
        $phpmailer->shouldReceive( 'isSMTP' )->once();

        // PHPMailer uses magic properties
        $phpmailer->Host       = '';
        $phpmailer->SMTPAuth   = false;
        $phpmailer->Port       = 0;
        $phpmailer->Username   = '';
        $phpmailer->Password   = '';
        $phpmailer->SMTPSecure = '';
        $phpmailer->From       = '';
        $phpmailer->FromName   = '';

        $handler = new EmailHandler();
        $handler->configure_custom_smtp( $phpmailer );

        $this->assertSame( 'smtp.example.com', $phpmailer->Host );
        $this->assertTrue( $phpmailer->SMTPAuth );
        $this->assertSame( 465, $phpmailer->Port );
        $this->assertSame( 'user@example.com', $phpmailer->Username );
        $this->assertSame( 'secret', $phpmailer->Password );
        $this->assertSame( 'ssl', $phpmailer->SMTPSecure );
        $this->assertSame( 'noreply@example.com', $phpmailer->From );
        $this->assertSame( 'My App', $phpmailer->FromName );
    }

    // ==================================================================
    // configure_custom_smtp() — non-custom mode (no-op)
    // ==================================================================

    public function test_configure_custom_smtp_does_nothing_when_not_custom(): void {
        Functions\when( 'get_option' )->justReturn( array( 'smtp_mode' => 'default' ) );

        $phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
        $phpmailer->shouldNotReceive( 'isSMTP' );
        $phpmailer->Host = '';

        $handler = new EmailHandler();
        $handler->configure_custom_smtp( $phpmailer );

        $this->assertSame( '', $phpmailer->Host );
    }

    // ==================================================================
    // configure_custom_smtp() — no settings at all
    // ==================================================================

    public function test_configure_custom_smtp_skips_when_no_smtp_mode(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
        $phpmailer->shouldNotReceive( 'isSMTP' );

        $handler = new EmailHandler();
        $handler->configure_custom_smtp( $phpmailer );
    }

    // ==================================================================
    // async_process_submission() — sends user email when enabled
    // ==================================================================

    public function test_async_sends_user_email_when_enabled(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'admin_email' ) {
                return 'admin@example.com';
            }
            return $default;
        } );
        Functions\when( 'is_email' )->justReturn( true );

        // DocumentFormatter is autoloaded (has real PREFIX_CERTIFICATE constant)
        // Utils and MagicLinkHelper need alias mocks
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'format_auth_code' )->andReturn( 'CERT-ABC123' );
        $utilsMock->shouldReceive( 'format_document' )->andReturnArg( 0 );
        $utilsMock->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() );
        $utilsMock->shouldReceive( 'debug_log' )->andReturn();

        $magicMock = Mockery::mock( 'alias:\FreeFormCertificate\Generators\MagicLinkHelper' );
        $magicMock->shouldReceive( 'generate_magic_link' )
            ->with( 'tok123' )
            ->andReturn( 'https://example.com/magic/tok123' );

        $handler = new EmailHandler();
        $handler->async_process_submission(
            1,
            10,
            'Test Certificate',
            array( 'auth_code' => 'ABC123', 'name' => 'John' ),
            'user@example.com',
            array(),
            array( 'send_user_email' => 1, 'email_admin' => 'admin@example.com' ),
            'tok123'
        );

        // At least 1 email sent (user email)
        $this->assertGreaterThanOrEqual( 1, count( $this->sent_emails ) );
        $this->assertSame( 'user@example.com', $this->sent_emails[0]['to'] );
    }

    // ==================================================================
    // async_process_submission() — skips user email when disabled
    // ==================================================================

    public function test_async_skips_user_email_when_disabled(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'admin_email' ) {
                return 'admin@example.com';
            }
            return $default;
        } );
        Functions\when( 'is_email' )->justReturn( true );

        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'format_auth_code' )->andReturn( '' );
        $utilsMock->shouldReceive( 'format_document' )->andReturnArg( 0 );
        $utilsMock->shouldReceive( 'get_allowed_html_tags' )->andReturn( array() );
        $utilsMock->shouldReceive( 'debug_log' )->andReturn();

        $handler = new EmailHandler();
        $handler->async_process_submission(
            1,
            10,
            'Test Certificate',
            array( 'name' => 'John' ),
            'user@example.com',
            array(),
            array( 'send_user_email' => 0, 'email_admin' => 'admin@example.com' ),
            ''
        );

        // Only admin notification sent (no user email)
        $this->assertCount( 1, $this->sent_emails );
        $this->assertSame( 'admin@example.com', $this->sent_emails[0]['to'] );
    }

    // ==================================================================
    // async_process_submission() — global email disable blocks all
    // ==================================================================

    public function test_async_sends_nothing_when_emails_globally_disabled(): void {
        Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

        $handler = new EmailHandler();
        $handler->async_process_submission(
            1,
            10,
            'Test',
            array(),
            'user@example.com',
            array(),
            array( 'send_user_email' => 1, 'email_admin' => 'admin@example.com' ),
            'tok'
        );

        $this->assertCount( 0, $this->sent_emails );
    }

    // ==================================================================
    // configure_custom_smtp() — from_email without from_name uses bloginfo
    // ==================================================================

    public function test_configure_custom_smtp_uses_bloginfo_for_from_name(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'smtp_mode'       => 'custom',
            'smtp_host'       => 'smtp.test.com',
            'smtp_from_email' => 'noreply@test.com',
            // smtp_from_name intentionally missing
        ) );

        $phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
        $phpmailer->shouldReceive( 'isSMTP' )->once();
        $phpmailer->Host       = '';
        $phpmailer->SMTPAuth   = false;
        $phpmailer->Port       = 0;
        $phpmailer->Username   = '';
        $phpmailer->Password   = '';
        $phpmailer->SMTPSecure = '';
        $phpmailer->From       = '';
        $phpmailer->FromName   = '';

        $handler = new EmailHandler();
        $handler->configure_custom_smtp( $phpmailer );

        $this->assertSame( 'noreply@test.com', $phpmailer->From );
        $this->assertSame( 'Test Site', $phpmailer->FromName );
    }

    // ==================================================================
    // configure_custom_smtp() — defaults for missing fields
    // ==================================================================

    public function test_configure_custom_smtp_uses_defaults_for_missing_fields(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'smtp_mode' => 'custom',
            // All other fields intentionally missing
        ) );

        $phpmailer = Mockery::mock( 'PHPMailer\PHPMailer\PHPMailer' );
        $phpmailer->shouldReceive( 'isSMTP' )->once();
        $phpmailer->Host       = '';
        $phpmailer->SMTPAuth   = false;
        $phpmailer->Port       = 0;
        $phpmailer->Username   = '';
        $phpmailer->Password   = '';
        $phpmailer->SMTPSecure = '';
        $phpmailer->From       = '';
        $phpmailer->FromName   = '';

        $handler = new EmailHandler();
        $handler->configure_custom_smtp( $phpmailer );

        $this->assertSame( '', $phpmailer->Host );
        $this->assertTrue( $phpmailer->SMTPAuth );
        $this->assertSame( 587, $phpmailer->Port );
        $this->assertSame( '', $phpmailer->Username );
        $this->assertSame( '', $phpmailer->Password );
        $this->assertSame( 'tls', $phpmailer->SMTPSecure );
    }
}
