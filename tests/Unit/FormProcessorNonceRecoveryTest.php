<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\FormProcessor;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * 6.6.3 — pins the stale-nonce auto-recovery contract on the server.
 * When the AJAX form-submission nonce check fails, the response MUST
 * include `refresh_nonce: true` + `new_nonce: '<hash>'` so the client
 * (FFC.request in ffc-core.js) can transparently retry once with the
 * fresh nonce. Without this, iOS Safari + cached-HTML hosts would
 * keep surfacing "Security check failed" even after the user reloads.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class FormProcessorNonceRecoveryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormProcessor */
    private $processor;

    /** @var array<string, mixed>|null Captured payload from wp_send_json_error. */
    private $captured_error = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Pretend the request is well-formed enough to reach the nonce gate.
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        // Rate-limit gate: pass through.
        Functions\when( 'apply_filters' )->returnArg();

        // Nonce: we ARE the nonce-failure path under test.
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        // Server-issued replacement nonce — must be a non-empty string.
        Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce-abc123' );

        // Capture the wp_send_json_error payload and throw so the handler
        // function short-circuits (mirrors WP's wp_die() behaviour).
        $captured = &$this->captured_error;
        Functions\when( 'wp_send_json_error' )->alias( static function ( $payload ) use ( &$captured ) {
            $captured = $payload;
            throw new \RuntimeException( 'wp_send_json_error called' );
        } );

        // Rate-limit gate would otherwise hit the real RateLimiter::check_ip_limit
        // (a static method that probes wpdb/transients we haven't stubbed).
        // Alias-mock it to "allowed: true" so the handler reaches the nonce check.
        $rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();

        $mock_handler    = Mockery::mock( SubmissionHandler::class );
        $this->processor = new FormProcessor( $mock_handler );

        $_POST = array(
            'action'   => 'ffc_submit_form',
            'nonce'    => 'stale-or-mismatched',
            'form_id'  => 1,
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
            $this->processor->handle_submission_ajax();
        } finally {
            // RuntimeException propagates, but we still need to assert
            // the captured payload — do it inside finally so the
            // expectException contract is satisfied AND we can check.
            $this->assertIsArray( $this->captured_error, 'wp_send_json_error must be called' );
            $this->assertArrayHasKey( 'message', $this->captured_error );
            $this->assertArrayHasKey( 'refresh_nonce', $this->captured_error );
            $this->assertArrayHasKey( 'new_nonce', $this->captured_error );
            $this->assertTrue( $this->captured_error['refresh_nonce'] );
            $this->assertSame( 'fresh-nonce-abc123', $this->captured_error['new_nonce'] );
            $this->assertNotSame( '', $this->captured_error['new_nonce'] );
        }
    }
}
