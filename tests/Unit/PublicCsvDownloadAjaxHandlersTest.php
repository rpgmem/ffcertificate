<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\PublicCsvDownload;

/**
 * Coverage for the AJAX handlers + the admin-post audit-log export on
 * {@see PublicCsvDownload}. These paths were uncovered by the in-process
 * PublicCsvDownloadTest because they need alias/overload mocks for the
 * action services (EarlyOpenAction, ExtendEndAction, ScheduleExceptionAction),
 * the validator/builder collaborators constructed in __construct(), and
 * namespaced terminator stubs (wp_send_json_*, wp_die) — all of which leak
 * across tests unless the process is isolated.
 *
 * Strategy:
 *  - PublicCsvDownload::__construct() news up CsvDownloadValidator,
 *    CsvDownloadFormInfoBuilder and CsvDownloadFlash, so those three are
 *    overload-mocked before instantiation.
 *  - wp_send_json_error / wp_send_json_success / wp_die are stubbed (as the
 *    namespaced Frontend\* resolutions) to throw, since they terminate the
 *    request in production; tests expectException to prove which branch ran.
 *  - The action services + Geofence + CertificatePreviewSamples + RateLimiter
 *    + SecurityService are alias-mocked.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Frontend\PublicCsvDownload
 */
class PublicCsvDownloadAjaxHandlersTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> json payload captured before the terminator throws */
    private array $captured = array();

    /** @var \Mockery\MockInterface validator overload mock (reconfigurable per test) */
    private $validator;

    /** @var \Mockery\MockInterface rate-limiter alias mock */
    private $rate_limiter;

    /** @var \Mockery\MockInterface security-service alias mock */
    private $security;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Frontend\\PublicCsvDownload' );

        $this->captured = array();

        // --- Generic global + namespaced WP stubs ----------------------
        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\esc_html__' )->returnArg();
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );

        // RequestInput::get_post_string / get_get_string read $_POST/$_GET
        // directly through unqualified core helpers; supply REMOTE_ADDR for
        // the audit-meta building paths.
        $_SERVER['REMOTE_ADDR']    = '203.0.113.5';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';

        // Terminators throw so the branch under test halts (as in prod).
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            function ( $payload = null, $code = null ) {
                $this->captured = array( 'kind' => 'error', 'payload' => $payload, 'code' => $code );
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_success' )->alias(
            function ( $payload = null ) {
                $this->captured = array( 'kind' => 'success', 'payload' => $payload );
                throw new \RuntimeException( 'json_success' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_die' )->alias(
            function ( $msg = '', $code = null ) {
                $this->captured = array( 'kind' => 'die', 'msg' => $msg, 'code' => $code );
                throw new \RuntimeException( 'wp_die' );
            }
        );

        // The three collaborators constructed in PublicCsvDownload::__construct().
        // Validator: default to "all gates pass" (returns null). Tests
        // reconfigure via $this->validator (alias/overload can't be re-mocked,
        // so we override expectations on the stored instance instead).
        $this->validator = Mockery::mock( 'overload:FreeFormCertificate\Frontend\CsvDownloadValidator' );
        $this->validator->shouldReceive( 'validate_cpf_requirement' )->andReturn( null )->byDefault();
        $this->validator->shouldReceive( 'validate_hash_only' )->andReturn( null )->byDefault();
        $this->validator->shouldReceive( 'validate_form_access' )->andReturn( null )->byDefault();
        $this->validator->shouldReceive( 'record_download_log_entry' )->andReturnNull()->byDefault();

        $builder = Mockery::mock( 'overload:FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder' );
        $builder->shouldReceive( 'build_form_info' )->andReturn( array( 'form_title' => 'X' ) )->byDefault();

        Mockery::mock( 'overload:FreeFormCertificate\Frontend\Csv\CsvDownloadFlash' )
            ->shouldReceive( 'fail_redirect' )->andReturnNull()->byDefault()
            ->shouldReceive( 'get_flash_message' )->andReturn( null )->byDefault();

        // RateLimiter — allowed by default (the class_exists() gate fires).
        $this->rate_limiter = Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' );
        $this->rate_limiter->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();

        // SecurityService — honeypot/captcha passes by default.
        $this->security = Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' );
        $this->security->shouldReceive( 'validate_security_fields' )->andReturn( true )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        $_SERVER = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_security( $result = true ): void {
        $this->security->shouldReceive( 'validate_security_fields' )->andReturn( $result );
    }

    private function valid_post( int $form_id = 42 ): void {
        $_POST = array(
            '_ffc_pcd_nonce' => 'n',
            'form_id'        => (string) $form_id,
            'hash'           => 'validhash',
            'cpf'            => '',
        );
    }

    // ==================================================================
    //  ajax_info()
    // ==================================================================

    public function test_ajax_info_rejects_bad_nonce(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        $this->valid_post();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_info();
    }

    public function test_ajax_info_rejects_when_rate_limited(): void {
        $this->rate_limiter->shouldReceive( 'check_ip_limit' )
            ->andReturn( array( 'allowed' => false, 'message' => 'slow down' ) );
        $this->valid_post();

        try {
            ( new PublicCsvDownload() )->ajax_info();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_error', $e->getMessage() );
            $this->assertSame( 'slow down', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_info_rejects_failed_captcha_and_logs(): void {
        $this->stub_security( 'captcha wrong' );
        $this->valid_post();

        try {
            ( new PublicCsvDownload() )->ajax_info();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_error', $e->getMessage() );
            $this->assertSame( 'captcha wrong', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_info_rejects_missing_form_id(): void {
        $this->stub_security( true );
        $_POST = array( '_ffc_pcd_nonce' => 'n', 'form_id' => '0', 'hash' => '' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_info();
    }

    public function test_ajax_info_rejects_when_hash_validation_fails(): void {
        $this->stub_security( true );
        $this->valid_post();
        // Override the default (null) so hash-only validation fails.
        $this->validator->shouldReceive( 'validate_hash_only' )->andReturn( 'bad hash' );

        try {
            ( new PublicCsvDownload() )->ajax_info();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'bad hash', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_info_rejects_when_cpf_gate_fails(): void {
        $this->stub_security( true );
        $this->valid_post();
        $this->validator->shouldReceive( 'validate_cpf_requirement' )->andReturn( 'cpf required' );

        try {
            ( new PublicCsvDownload() )->ajax_info();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'cpf required', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_info_happy_path_returns_form_info(): void {
        $this->stub_security( true );
        $this->valid_post();

        try {
            ( new PublicCsvDownload() )->ajax_info();
            $this->fail( 'expected json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
            $this->assertSame( 'X', $this->captured['payload']['form_title'] );
        }
    }

    // ==================================================================
    //  ajax_cert_preview()
    // ==================================================================

    public function test_ajax_cert_preview_rejects_bad_nonce(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        $this->valid_post();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_cert_preview();
    }

    public function test_ajax_cert_preview_rejects_when_hash_invalid(): void {
        $this->valid_post();
        $this->validator->shouldReceive( 'validate_hash_only' )->andReturn( 'nope' );

        $this->expectException( \RuntimeException::class );
        ( new PublicCsvDownload() )->ajax_cert_preview();
    }

    public function test_ajax_cert_preview_rejects_when_form_already_started(): void {
        $this->valid_post();
        // Hash ok (default validator returns null); Geofence reports the
        // form already started (start in the past) → preview unavailable.
        Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
            ->shouldReceive( 'get_form_start_timestamp' )->andReturn( time() - 100 );

        try {
            ( new PublicCsvDownload() )->ajax_cert_preview();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_error', $e->getMessage() );
        }
    }

    public function test_ajax_cert_preview_happy_path_returns_template(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
            ->shouldReceive( 'get_form_start_timestamp' )->andReturn( time() + 3600 );
        Mockery::mock( 'alias:FreeFormCertificate\Core\CertificatePreviewSamples' )
            ->shouldReceive( 'get_map' )->andReturn( array( '{{name}}' => 'Sample' ) );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            if ( '_ffc_form_config' === $key ) {
                return array( 'pdf_layout' => '<p>{{name}}</p>', 'bg_image' => 'bg.png' );
            }
            if ( '_ffc_form_fields' === $key ) {
                return array(
                    array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
                    array( 'name' => 'note', 'type' => 'info' ), // skipped (info type).
                    array( 'name' => '', 'type' => 'text' ),     // skipped (empty name).
                );
            }
            return '';
        } );

        try {
            ( new PublicCsvDownload() )->ajax_cert_preview();
            $this->fail( 'expected json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
            $p = $this->captured['payload'];
            $this->assertCount( 1, $p['fields'], 'only the named, non-info field survives' );
            $this->assertSame( 'name', $p['fields'][0]['name'] );
            $this->assertSame( array( '{{name}}' => 'Sample' ), $p['previewSamples'] );
        }
    }

    // ==================================================================
    //  ajax_open_early()
    // ==================================================================

    public function test_ajax_open_early_rejects_bad_nonce(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        $this->valid_post();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_open_early();
    }

    public function test_ajax_open_early_rejects_when_cpf_gate_fails(): void {
        $this->valid_post();
        $this->validator->shouldReceive( 'validate_cpf_requirement' )->andReturn( 'cpf bad' );

        try {
            ( new PublicCsvDownload() )->ajax_open_early();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'cpf bad', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_open_early_maps_reason_to_message_on_failure(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\EarlyOpenAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'already_started' ) );

        try {
            ( new PublicCsvDownload() )->ajax_open_early();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'already_started', $this->captured['payload']['reason'] );
            $this->assertStringContainsString( 'already started', $this->captured['payload']['message'] );
            $this->assertSame( 409, $this->captured['code'] );
        }
    }

    public function test_ajax_open_early_unknown_reason_falls_back_to_generic(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\EarlyOpenAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'mystery' ) );

        try {
            ( new PublicCsvDownload() )->ajax_open_early();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unable to open', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_open_early_happy_path(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\EarlyOpenAction' )
            ->shouldReceive( 'execute' )->andReturn(
                array( 'ok' => true, 'new_start_iso' => '2026-01-01T00:00:00Z', 'original_start_iso' => '2026-01-02T00:00:00Z' )
            );

        try {
            ( new PublicCsvDownload() )->ajax_open_early();
            $this->fail( 'expected json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
            $this->assertSame( '2026-01-01T00:00:00Z', $this->captured['payload']['new_start_iso'] );
        }
    }

    // ==================================================================
    //  ajax_extend_end()
    // ==================================================================

    public function test_ajax_extend_end_rejects_bad_nonce(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        $this->valid_post();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_extend_end();
    }

    public function test_ajax_extend_end_rejects_when_cpf_gate_fails(): void {
        $this->valid_post();
        $this->validator->shouldReceive( 'validate_cpf_requirement' )->andReturn( 'cpf bad' );

        $this->expectException( \RuntimeException::class );
        ( new PublicCsvDownload() )->ajax_extend_end();
    }

    public function test_ajax_extend_end_maps_reason_on_failure(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ExtendEndAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'already_postponed' ) );

        try {
            ( new PublicCsvDownload() )->ajax_extend_end();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'already_postponed', $this->captured['payload']['reason'] );
            $this->assertStringContainsString( 'already been postponed', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_extend_end_unknown_reason_generic(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ExtendEndAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'mystery' ) );

        try {
            ( new PublicCsvDownload() )->ajax_extend_end();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unable to postpone', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_extend_end_happy_path(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ExtendEndAction' )
            ->shouldReceive( 'execute' )->andReturn(
                array( 'ok' => true, 'new_end_iso' => '2026-01-01T20:00:00Z', 'original_end_iso' => '2026-01-01T18:00:00Z' )
            );

        try {
            ( new PublicCsvDownload() )->ajax_extend_end();
            $this->fail( 'expected json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
            $this->assertSame( '2026-01-01T20:00:00Z', $this->captured['payload']['new_end_iso'] );
        }
    }

    // ==================================================================
    //  ajax_schedule_exception()
    // ==================================================================

    public function test_ajax_schedule_exception_rejects_bad_nonce(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        $this->valid_post();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        ( new PublicCsvDownload() )->ajax_schedule_exception();
    }

    public function test_ajax_schedule_exception_rejects_when_cpf_gate_fails(): void {
        $this->valid_post();
        $this->validator->shouldReceive( 'validate_cpf_requirement' )->andReturn( 'cpf bad' );

        $this->expectException( \RuntimeException::class );
        ( new PublicCsvDownload() )->ajax_schedule_exception();
    }

    public function test_ajax_schedule_exception_maps_reason_on_failure(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ScheduleExceptionAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'no_change' ) );

        try {
            ( new PublicCsvDownload() )->ajax_schedule_exception();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'no_change', $this->captured['payload']['reason'] );
            $this->assertStringContainsString( 'identical to the baseline', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_schedule_exception_unknown_reason_generic(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ScheduleExceptionAction' )
            ->shouldReceive( 'execute' )->andReturn( array( 'ok' => false, 'reason' => 'mystery' ) );

        try {
            ( new PublicCsvDownload() )->ajax_schedule_exception();
            $this->fail( 'expected json_error' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Unable to create', $this->captured['payload']['message'] );
        }
    }

    public function test_ajax_schedule_exception_happy_path(): void {
        $this->valid_post();
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\ScheduleExceptionAction' )
            ->shouldReceive( 'execute' )->andReturn(
                array( 'ok' => true, 'token' => 'tok123', 'form_url' => 'https://example.com/form' )
            );

        try {
            ( new PublicCsvDownload() )->ajax_schedule_exception();
            $this->fail( 'expected json_success' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
            $this->assertSame( 'tok123', $this->captured['payload']['token'] );
            $this->assertSame( 'https://example.com/form', $this->captured['payload']['form_url'] );
        }
    }

    // ==================================================================
    //  handle_export_log_request()
    // ==================================================================

    public function test_export_log_rejects_bad_nonce(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        $_GET = array( 'form_id' => '42', '_wpnonce' => 'x' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        ( new PublicCsvDownload() )->handle_export_log_request();
    }

    public function test_export_log_rejects_when_form_not_found(): void {
        Functions\when( 'get_post_type' )->justReturn( 'post' );
        Functions\when( 'FreeFormCertificate\Frontend\get_post_type' )->justReturn( 'post' );
        $_GET = array( 'form_id' => '42', '_wpnonce' => 'x' );

        try {
            ( new PublicCsvDownload() )->handle_export_log_request();
            $this->fail( 'expected wp_die' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 404, $this->captured['code'] );
        }
    }

    public function test_export_log_rejects_when_no_edit_cap(): void {
        $_GET = array( 'form_id' => '42', '_wpnonce' => 'x' );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Frontend\current_user_can' )->justReturn( false );

        try {
            ( new PublicCsvDownload() )->handle_export_log_request();
            $this->fail( 'expected wp_die' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 403, $this->captured['code'] );
        }
    }

    public function test_export_log_rejects_when_no_audit_cap(): void {
        $_GET = array( 'form_id' => '42', '_wpnonce' => 'x' );
        // edit_post passes, but the manage_settings audit cap fails.
        Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_post' === $cap );
        Functions\when( 'FreeFormCertificate\Frontend\current_user_can' )->alias( fn( $cap ) => 'edit_post' === $cap );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

        try {
            ( new PublicCsvDownload() )->handle_export_log_request();
            $this->fail( 'expected wp_die' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 403, $this->captured['code'] );
        }
    }

    public function test_export_log_streams_csv_happy_path(): void {
        $_GET = array( 'form_id' => '42', '_wpnonce' => 'x' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\current_user_can' )->justReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'is_configured' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Frontend\Csv\CsvDownloadAuditLog' )
            ->shouldReceive( 'decrypt_log_entry_cpf' )->andReturn( '52998224725' );

        $log = array(
            array( 'ts' => 1700000000, 'ip' => '1.2.3.4', 'mode' => 'audit', 'cpf_encrypted' => 'e', 'result' => 'audit_pass' ),
            'not-an-array', // skipped.
        );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $log ) {
            return '_ffc_csv_public_download_log' === $key ? $log : '';
        } );
        Functions\when( 'wp_date' )->justReturn( '2023-11-14 22:13:20' );
        Functions\when( 'FreeFormCertificate\Frontend\nocache_headers' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\header' )->justReturn( null );

        // Csv::writer returns a writer that records rows. The real method
        // ends in exit; we throw a sentinel on close() so the test can
        // observe the rows that were written before the process would die.
        $rows   = array();
        $writer = Mockery::mock( 'FfcCsvWriterStub' );
        $writer->shouldReceive( 'row' )->andReturnUsing( function ( $r ) use ( &$rows ) { $rows[] = $r; } );
        $writer->shouldReceive( 'close' )->andReturnUsing( function () {
            throw new \RuntimeException( 'closed' );
        } );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Csv' )
            ->shouldReceive( 'writer' )->andReturn( $writer );

        ob_start();
        try {
            ( new PublicCsvDownload() )->handle_export_log_request();
            ob_end_clean();
            $this->fail( 'expected the writer close sentinel' );
        } catch ( \RuntimeException $e ) {
            ob_end_clean();
            $this->assertSame( 'closed', $e->getMessage() );
        }

        // Header row + one data row (the non-array entry is skipped).
        $this->assertContains( array( 'timestamp', 'ip', 'mode', 'cpf', 'result' ), $rows );
        $data = array_values( array_filter( $rows, fn( $r ) => isset( $r[4] ) && 'audit_pass' === $r[4] ) );
        $this->assertNotEmpty( $data );
        $this->assertSame( '52998224725', $data[0][3] );
    }
}
