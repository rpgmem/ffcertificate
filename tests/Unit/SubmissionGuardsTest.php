<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Frontend\Submission\IpRateLimitGuard;
use FreeFormCertificate\Frontend\Submission\NonceGuard;
use FreeFormCertificate\Frontend\Submission\SecurityFieldsGuard;
use FreeFormCertificate\Frontend\Submission\FormConfigResolver;
use FreeFormCertificate\Frontend\Submission\PreflightGuard;
use FreeFormCertificate\Frontend\Submission\FieldSanitizer;
use FreeFormCertificate\Frontend\Submission\DeviceSignalsResolver;
use FreeFormCertificate\Frontend\Submission\GeofenceGuard;
use FreeFormCertificate\Frontend\Submission\RateLimitGuard;
use FreeFormCertificate\Frontend\Submission\AccessRestrictionGuard;
use FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard;

/**
 * #563 Sprint 1 — unit tests for the extracted submission entry guards.
 *
 * Each guard is exercised in isolation against a SubmissionContext: this is
 * the testability win the pipeline split delivers (the legacy monolith could
 * only be driven end-to-end). Static collaborators are alias-mocked, so each
 * test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubmissionGuardsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function ctx(): SubmissionContext {
		return new SubmissionContext();
	}

	// ===================== ScheduleExceptionGuard =====================

	public function test_schedule_exception_guard_is_noop_without_token(): void {
		$_POST = array();
		$ctx   = $this->ctx();
		( new ScheduleExceptionGuard() )->apply( $ctx );
		$this->assertFalse( $ctx->has_exception );
		$this->assertNull( $ctx->schedule_exception_payload );
	}

	// ===================== IpRateLimitGuard =====================

	public function test_ip_guard_throws_when_over_limit(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_ip_limit' )->andReturn(
			array( 'allowed' => false, 'message' => 'slow down', 'wait_seconds' => 30 )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();

		try {
			( new IpRateLimitGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['rate_limit'] );
			$this->assertSame( 30, $payload['wait_seconds'] );
		}
	}

	public function test_ip_guard_passes_when_allowed(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
		$ctx = $this->ctx();
		( new IpRateLimitGuard() )->apply( $ctx );
		$this->assertFalse( $ctx->has_exception );
	}

	public function test_ip_guard_skipped_on_exception_path(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldNotReceive( 'check_ip_limit' );
		$ctx                = $this->ctx();
		$ctx->has_exception = true;
		( new IpRateLimitGuard() )->apply( $ctx );
		$this->assertTrue( $ctx->has_exception );
	}

	// ===================== NonceGuard =====================

	public function test_nonce_guard_throws_with_fresh_nonce_when_invalid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );
		$_POST = array( 'nonce' => 'stale' );

		try {
			( new NonceGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['refresh_nonce'] );
			$this->assertSame( 'fresh-nonce', $payload['new_nonce'] );
		}
	}

	public function test_nonce_guard_passes_when_valid(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array( 'nonce' => 'ok' );
		( new NonceGuard() )->apply( $this->ctx() );
		$this->assertTrue( true );
	}

	// ===================== SecurityFieldsGuard =====================

	public function test_security_guard_throws_with_new_captcha_on_failure(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_form' )->andReturnNull()->byDefault();
		$svc = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
		$svc->shouldReceive( 'validate_security_fields' )->andReturn( 'Wrong answer.' );
		$svc->shouldReceive( 'generate_simple_captcha' )->andReturn(
			array( 'label' => '2 + 2', 'hash' => 'h' )
		);
		$_POST = array();

		try {
			( new SecurityFieldsGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['refresh_captcha'] );
			$this->assertSame( '2 + 2', $payload['new_label'] );
		}
	}

	public function test_security_guard_passes_when_valid(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_form' )->andReturnNull()->byDefault();
		$svc = Mockery::mock( 'alias:\FreeFormCertificate\Core\SecurityService' );
		$svc->shouldReceive( 'validate_security_fields' )->andReturn( true );
		$_POST = array();
		( new SecurityFieldsGuard() )->apply( $this->ctx() );
		$this->assertTrue( true );
	}

	// ===================== FormConfigResolver =====================

	public function test_form_config_resolver_rejects_missing_form_id(): void {
		$_POST = array();
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'Invalid Form ID', $e->get_payload()['message'] );
		}
	}

	public function test_form_config_resolver_rejects_absent_fields(): void {
		$_POST = array( 'form_id' => 7 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key ? array() : ''
		);
		try {
			( new FormConfigResolver() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'not available', $e->get_payload()['message'] );
		}
	}

	public function test_form_config_resolver_populates_context(): void {
		$_POST = array( 'form_id' => 7 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_ffc_form_config' === $key
				? array( 'quiz_enabled' => '0' )
				: array( array( 'name' => 'email', 'type' => 'email' ) )
		);
		$ctx = $this->ctx();
		( new FormConfigResolver() )->apply( $ctx );
		$this->assertSame( 7, $ctx->form_id );
		$this->assertSame( '0', $ctx->form_config['quiz_enabled'] );
		$this->assertCount( 1, $ctx->fields_config );
	}

	// ===================== PreflightGuard =====================

	public function test_preflight_collects_both_lgpd_and_email_errors(): void {
		$_POST           = array(); // no consent, no email value.
		$ctx             = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new PreflightGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$errors = $e->get_payload()['errors'];
			$this->assertCount( 2, $errors );
		}
	}

	public function test_preflight_passes_when_consent_and_email_present(): void {
		$_POST              = array( 'ffc_lgpd_consent' => '1', 'email' => 'a@b.co' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		( new PreflightGuard() )->apply( $ctx );
		$this->assertTrue( true );
	}

	// ===================== FieldSanitizer =====================

	public function test_field_sanitizer_rejects_bad_cpf_length(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'recursive_sanitize' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$_POST              = array( 'cpf_rf' => '123' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'cpf_rf', 'type' => 'text' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( '7 or 11 digits', $e->get_payload()['message'] );
		}
	}

	public function test_field_sanitizer_rejects_when_email_empty(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'recursive_sanitize' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		Functions\when( 'sanitize_email' )->justReturn( '' );
		$_POST              = array( 'email' => 'not-an-email' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		try {
			( new FieldSanitizer() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertStringContainsString( 'Email address is required', $e->get_payload()['message'] );
		}
	}

	public function test_field_sanitizer_populates_context_on_happy_path(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'recursive_sanitize' )->andReturnUsing( static fn( $v ) => $v )->byDefault();
		$_POST              = array( 'email' => 'USER@EX.CO', 'ffc_ticket' => 'abc' );
		$ctx                = $this->ctx();
		$ctx->fields_config = array( array( 'name' => 'email', 'type' => 'email' ) );
		( new FieldSanitizer() )->apply( $ctx );
		$this->assertSame( 'user@ex.co', $ctx->user_email );
		$this->assertSame( '1', $ctx->submission_data['ffc_lgpd_consent'] );
		$this->assertSame( 'ABC', $ctx->val_ticket );
	}

	// ===================== DeviceSignalsResolver =====================

	public function test_device_resolver_null_when_globally_disabled(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'get_settings' )->andReturn( array( 'device' => array( 'enabled' => false ) ) );
		Functions\when( 'get_post_meta' )->justReturn( '0' );
		$ctx = $this->ctx();
		( new DeviceSignalsResolver() )->apply( $ctx );
		$this->assertNull( $ctx->device_signals );
		$this->assertFalse( $ctx->skip_device );
	}

	public function test_device_resolver_sets_skip_on_manager_bypass(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'get_settings' )->andReturn( array( 'device' => array( 'enabled' => true ) ) );
		$rl->shouldReceive( 'should_bypass_for_manager' )->andReturn( true );
		$rl->shouldReceive( 'log_attempt' )->andReturnNull();
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$ctx = $this->ctx();
		( new DeviceSignalsResolver() )->apply( $ctx );
		$this->assertTrue( $ctx->skip_device );
	}

	// ===================== GeofenceGuard =====================

	public function test_geofence_guard_throws_when_blocked(): void {
		$gf = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
		$gf->shouldReceive( 'get_form_config' )->andReturn( array( 'geo_enabled' => false ) );
		$gf->shouldReceive( 'can_access_form' )->andReturn(
			array( 'allowed' => false, 'message' => 'outside', 'reason' => 'geo_outside_radius' )
		);
		try {
			( new GeofenceGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['geofence_blocked'] );
			$this->assertSame( 'geo_outside_radius', $payload['reason'] );
		}
	}

	public function test_geofence_guard_passes_when_allowed(): void {
		$gf = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
		$gf->shouldReceive( 'get_form_config' )->andReturn( array() );
		$gf->shouldReceive( 'can_access_form' )->andReturn( array( 'allowed' => true ) );
		( new GeofenceGuard() )->apply( $this->ctx() );
		$this->assertTrue( true );
	}

	// ===================== RateLimitGuard =====================

	public function test_rate_limit_guard_throws_when_blocked(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->andReturn(
			array( 'allowed' => false, 'message' => 'limited', 'wait_seconds' => 12 )
		);
		$rl->shouldNotReceive( 'record_attempt' );
		$ctx              = $this->ctx();
		$ctx->user_email  = 'a@b.co';
		try {
			( new RateLimitGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertTrue( $e->get_payload()['rate_limit'] );
		}
	}

	public function test_rate_limit_guard_records_when_allowed(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->atLeast()->once();
		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';
		( new RateLimitGuard() )->apply( $ctx );
		$this->assertTrue( true );
	}

	// ===================== AccessRestrictionGuard =====================

	public function test_access_restriction_guard_throws_when_denied(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\AccessRestrictionChecker' )
			->shouldReceive( 'check' )->andReturn( array( 'allowed' => false, 'message' => 'nope' ) );
		try {
			( new AccessRestrictionGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$this->assertSame( 'nope', $e->get_payload()['message'] );
		}
	}

	public function test_access_restriction_guard_stores_result_when_allowed(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\AccessRestrictionChecker' )
			->shouldReceive( 'check' )->andReturn( array( 'allowed' => true, 'is_ticket' => true ) );
		$ctx = $this->ctx();
		( new AccessRestrictionGuard() )->apply( $ctx );
		$this->assertTrue( $ctx->restriction_result['is_ticket'] );
	}
}
