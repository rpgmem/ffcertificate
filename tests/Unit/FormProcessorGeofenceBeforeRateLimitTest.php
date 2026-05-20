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
 * 6.6.4 Sprint 5 — Geofence::can_access_form (read-only) runs BEFORE
 * RateLimiter::check_all + record_attempt (writes). Without this
 * order, a visitor outside the geofence ticked the rate-limit
 * counter on every retry, burning their budget for the next
 * legitimate attempt.
 *
 * Contract pinned here: when geofence rejects, RateLimiter::check_all
 * is NEVER invoked.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class FormProcessorGeofenceBeforeRateLimitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormProcessor */
    private $processor;

    /** @var array<string, mixed>|null */
    private $captured_error = null;

    /** @var \Mockery\MockInterface Alias mock for RateLimiter. */
    private $rate_limiter_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'absint' )->alias( 'intval' );
        Functions\when( 'is_email' )->justReturn( true );
        // recursive_sanitize() calls wp_kses() internally.
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        // RateLimiter: only `check_ip_limit` should be called (the
        // initial IP gate at the top of the handler). check_all must
        // NOT be called when geofence rejects.
        $this->rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $this->rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();
        $this->rate_limiter_mock->shouldReceive( 'get_settings' )->andReturn( array( 'device' => array( 'enabled' => false ) ) )->byDefault();

        Functions\when( 'wp_verify_nonce' )->justReturn( true );

        $security_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
        $security_mock->shouldReceive( 'validate_security_fields' )->andReturn( true )->byDefault();

        // Geofence: rejects.
        $geofence_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
        $geofence_mock->shouldReceive( 'get_form_config' )->andReturn( array( 'geo_enabled' => true, 'geo_ip_enabled' => true ) )->byDefault();
        $geofence_mock->shouldReceive( 'can_access_form' )->andReturn( array(
            'allowed' => false,
            'message' => 'You are outside the allowed area.',
            'reason'  => 'geo_outside_radius',
        ) )->byDefault();

        Functions\when( 'get_post_meta' )->alias( static function ( $post_id, $key ) {
            if ( '_ffc_form_fields' === $key ) {
                return array(
                    array( 'name' => 'email', 'type' => 'email' ),
                );
            }
            if ( '_ffc_form_config' === $key ) {
                return array();
            }
            return '';
        } );
        Functions\when( 'get_post' )->alias( static function () {
            $p = new \stdClass();
            $p->ID = 1;
            $p->post_title = 'Form';
            return $p;
        } );

        $captured = &$this->captured_error;
        Functions\when( 'wp_send_json_error' )->alias( static function ( $payload ) use ( &$captured ) {
            $captured = $payload;
            throw new \RuntimeException( 'wp_send_json_error called' );
        } );

        $mock_handler    = Mockery::mock( SubmissionHandler::class );
        $this->processor = new FormProcessor( $mock_handler );

        $_POST = array(
            'action'           => 'ffc_submit_form',
            'nonce'            => 'ok',
            'form_id'          => 1,
            'ffc_lgpd_consent' => '1',
            'email'            => 'user@example.org',
        );
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_geofence_rejection_returns_geofence_blocked_payload(): void {
        $this->expectException( \RuntimeException::class );
        try {
            $this->processor->handle_submission_ajax();
        } finally {
            $this->assertIsArray( $this->captured_error );
            $this->assertArrayHasKey( 'geofence_blocked', $this->captured_error );
            $this->assertTrue( $this->captured_error['geofence_blocked'] );
            $this->assertSame( 'geo_outside_radius', $this->captured_error['reason'] );
            $this->assertStringContainsString( 'outside the allowed area', $this->captured_error['message'] );
        }
    }

    public function test_geofence_rejection_does_NOT_invoke_rate_limiter_check_all_or_record_attempt(): void {
        // Critical contract: a visitor outside the geofence does NOT
        // tick the rate-limit counter. We assert by NOT setting
        // expectations on check_all / record_attempt: Mockery's
        // strict mode (via alias mock) means any unexpected call
        // would fail. We also explicitly forbid them to be unambiguous.
        $this->rate_limiter_mock->shouldNotReceive( 'check_all' );
        $this->rate_limiter_mock->shouldNotReceive( 'record_attempt' );
        $this->rate_limiter_mock->shouldNotReceive( 'record_device_signals' );

        $this->expectException( \RuntimeException::class );
        try {
            $this->processor->handle_submission_ajax();
        } finally {
            // Mockery verifies on tearDown.
        }
    }
}
