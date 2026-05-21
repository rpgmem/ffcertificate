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
 * 6.6.4 Sprint 4 — LGPD + email presence checks are now run BEFORE
 * the per-field validation loop. The motivation:
 *
 *   - LGPD + email-presence are O(1) string compares; the field loop
 *     runs CPF/RF regex + checksum. Cheap → expensive ordering.
 *   - The pre-flight returns ALL missing-presence errors in a single
 *     `errors` array so the user sees "missing email AND missing
 *     LGPD" in one go instead of one-at-a-time round-trips.
 *
 * This test pins both contracts.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class FormProcessorPreflightOrderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormProcessor */
    private $processor;

    /** @var array<string, mixed>|null */
    private $captured_error = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Reach the preflight gate: rate-limit allows, nonce valid,
        // captcha valid, form_id valid, form config exists with an
        // email-typed field.
        $rate_limiter_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
        $rate_limiter_mock->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) )->byDefault();

        Functions\when( 'wp_verify_nonce' )->justReturn( true );

        $security_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
        $security_mock->shouldReceive( 'validate_security_fields' )->andReturn( true )->byDefault();

        Functions\when( 'get_post_meta' )->alias( static function ( $post_id, $key ) {
            if ( '_ffc_form_fields' === $key ) {
                return array(
                    array( 'name' => 'email', 'type' => 'email' ),
                    array( 'name' => 'cpf_rf', 'type' => 'text' ),
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
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_missing_lgpd_alone_returns_lgpd_error(): void {
        $_POST = array(
            'action'    => 'ffc_submit_form',
            'nonce'     => 'ok',
            'form_id'   => 1,
            'email'     => 'user@example.org',
            'cpf_rf'    => '52998224725', // valid CPF — would pass field loop.
            // ffc_lgpd_consent intentionally missing.
        );

        $this->expectException( \RuntimeException::class );
        try {
            $this->processor->handle_submission_ajax();
        } finally {
            $this->assertIsArray( $this->captured_error );
            $this->assertArrayHasKey( 'errors', $this->captured_error );
            $this->assertCount( 1, $this->captured_error['errors'] );
            $this->assertStringContainsString( 'Privacy Policy', $this->captured_error['errors'][0] );
        }
    }

    public function test_missing_email_alone_returns_email_error(): void {
        $_POST = array(
            'action'           => 'ffc_submit_form',
            'nonce'            => 'ok',
            'form_id'          => 1,
            'ffc_lgpd_consent' => '1',
            'cpf_rf'           => '52998224725',
            // email field intentionally missing.
        );

        $this->expectException( \RuntimeException::class );
        try {
            $this->processor->handle_submission_ajax();
        } finally {
            $this->assertIsArray( $this->captured_error );
            $this->assertArrayHasKey( 'errors', $this->captured_error );
            $this->assertCount( 1, $this->captured_error['errors'] );
            $this->assertStringContainsString( 'Email', $this->captured_error['errors'][0] );
        }
    }

    public function test_missing_both_returns_combined_errors_array(): void {
        $_POST = array(
            'action'  => 'ffc_submit_form',
            'nonce'   => 'ok',
            'form_id' => 1,
            'cpf_rf'  => '52998224725',
            // email + LGPD both missing.
        );

        $this->expectException( \RuntimeException::class );
        try {
            $this->processor->handle_submission_ajax();
        } finally {
            $this->assertIsArray( $this->captured_error );
            $this->assertArrayHasKey( 'errors', $this->captured_error );
            $this->assertCount( 2, $this->captured_error['errors'] );
            // First error is LGPD (it's checked first), second is email.
            $this->assertStringContainsString( 'Privacy Policy', $this->captured_error['errors'][0] );
            $this->assertStringContainsString( 'Email', $this->captured_error['errors'][1] );
            // Legacy `message` field is the first error (backward compat).
            $this->assertSame( $this->captured_error['errors'][0], $this->captured_error['message'] );
        }
    }

    public function test_preflight_passes_when_lgpd_and_email_are_filled(): void {
        // Both presence checks satisfied → preflight does NOT call
        // wp_send_json_error, which proves it's purely additive and
        // doesn't change the happy-path behaviour. The request will
        // continue into the field-validation loop downstream; we don't
        // mock that here, so we assert ONLY that the preflight is
        // silent (captured_error stays null until something downstream
        // fires it). A null captured_error after a try/catch confirms
        // the preflight branch didn't fire.
        $_POST = array(
            'action'           => 'ffc_submit_form',
            'nonce'            => 'ok',
            'form_id'          => 1,
            'ffc_lgpd_consent' => '1',
            'email'            => 'user@example.org',
            'cpf_rf'           => '52998224725',
        );

        try {
            $this->processor->handle_submission_ajax();
        } catch ( \Throwable $e ) {
            // Downstream may throw (no full mock chain). All we care
            // about is that the captured payload doesn't carry the
            // preflight `errors` array — which would mean the
            // preflight fired incorrectly.
        }
        if ( null === $this->captured_error ) {
            // No wp_send_json_error fired at all → preflight passed and
            // downstream didn't reach a json_error either. Pass.
            $this->assertNull( $this->captured_error );
            return;
        }
        // Some downstream check fired wp_send_json_error. As long as it
        // doesn't have our `errors` array, it wasn't the preflight.
        $this->assertArrayNotHasKey(
            'errors',
            $this->captured_error,
            'preflight `errors` array should not be present on a non-preflight failure'
        );
    }
}
