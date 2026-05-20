<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\VerificationHandler;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * 6.6.3 — pins the stale-nonce auto-recovery contract on the
 * verification AJAX endpoint. Same shape as form-processor: when
 * wp_verify_nonce() rejects, the response MUST include
 * `refresh_nonce: true` + `new_nonce: '<hash>'` so the client
 * (FFC.request in ffc-core.js) can transparently retry once.
 *
 * Without this, cached pages / iOS Safari sessions / failed
 * dynamic-fragments would all leave the certificate-verification
 * shortcode stuck at "Security check failed" — same symptom that
 * the form submission flow hit, same root causes.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class VerificationHandlerNonceRecoveryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var VerificationHandler */
    private $handler;

    /** @var array<string, mixed>|null Captured payload from wp_send_json_error. */
    private $captured_error = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // The nonce path under test fails.
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        // Server-issued replacement nonce — must be non-empty.
        Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce-verify-xyz' );

        // Capture wp_send_json_error payload and throw so the handler short-circuits.
        $captured = &$this->captured_error;
        Functions\when( 'wp_send_json_error' )->alias( static function ( $payload ) use ( &$captured ) {
            $captured = $payload;
            throw new \RuntimeException( 'wp_send_json_error called' );
        } );

        $mock_handler  = Mockery::mock( SubmissionHandler::class );
        $this->handler = new VerificationHandler( $mock_handler );

        $_POST = array(
            'action' => 'ffc_verify_certificate',
            'nonce'  => 'stale-or-mismatched',
        );
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_nonce_failure_returns_refresh_nonce_with_fresh_value(): void {
        $this->expectException( \RuntimeException::class );

        try {
            $this->handler->handle_verification_ajax();
        } finally {
            $this->assertIsArray( $this->captured_error, 'wp_send_json_error must be called' );
            $this->assertArrayHasKey( 'message', $this->captured_error );
            $this->assertArrayHasKey( 'refresh_nonce', $this->captured_error );
            $this->assertArrayHasKey( 'new_nonce', $this->captured_error );
            $this->assertTrue( $this->captured_error['refresh_nonce'] );
            $this->assertSame( 'fresh-nonce-verify-xyz', $this->captured_error['new_nonce'] );
            $this->assertNotSame( '', $this->captured_error['new_nonce'] );
        }
    }
}
