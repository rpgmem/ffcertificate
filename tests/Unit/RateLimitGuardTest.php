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
use FreeFormCertificate\Frontend\Submission\RateLimitGuard;

/**
 * Dedicated coverage for RateLimitGuard — the consolidated submission
 * rate-limit gate. Exercises the reprint-detection skip-device path, the
 * CPF normalize/record branch, and the deferred device-signal persistence
 * hook (add_action), beyond the deny/allow basics in SubmissionGuardsTest.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\RateLimitGuard
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RateLimitGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Frontend\Submission\RateLimitGuard' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// RequestInput::get_user_ip() resolves these in the Core namespace.
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function ctx(): SubmissionContext {
		return new SubmissionContext();
	}

	public function test_throws_when_check_all_denies(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn(
			array( 'allowed' => false, 'message' => 'too many', 'wait_seconds' => 45 )
		);
		$rl->shouldNotReceive( 'record_attempt' );

		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';

		try {
			( new RateLimitGuard() )->apply( $ctx );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertTrue( $payload['rate_limit'] );
			$this->assertSame( 'too many', $payload['message'] );
			$this->assertSame( 45, $payload['wait_seconds'] );
		}
	}

	public function test_denied_uses_default_message_when_absent(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn( array( 'allowed' => false ) );

		try {
			( new RateLimitGuard() )->apply( $this->ctx() );
			$this->fail( 'expected SubmissionRejected' );
		} catch ( SubmissionRejected $e ) {
			$payload = $e->get_payload();
			$this->assertSame( 'Rate limit exceeded.', $payload['message'] );
			$this->assertSame( 0, $payload['wait_seconds'] );
		}
	}

	public function test_records_ip_and_email_when_allowed_no_cpf(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->once()->with( 'ip', Mockery::any(), 0 );
		$rl->shouldReceive( 'record_attempt' )->once()->with( 'email', 'a@b.co', 0 );
		// CPF record must NOT fire when val_cpf empty.
		$rl->shouldNotReceive( 'record_attempt' )->with( 'cpf', Mockery::any(), Mockery::any() );

		$ctx             = $this->ctx();
		$ctx->user_email = 'a@b.co';
		// device_signals null → no add_action branch.
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertNull( $ctx->device_signals );
	}

	public function test_records_cpf_attempt_with_normalized_value(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->with( 'ip', Mockery::any(), 0 )->once();
		$rl->shouldReceive( 'record_attempt' )->with( 'email', 'c@d.co', 0 )->once();
		$rl->shouldReceive( 'record_attempt' )->with( 'cpf', '12345678901', 0 )->once();

		Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->once()->with( '123.456.789-01' )->andReturn( '12345678901' );

		// ReprintDetector is consulted because val_cpf is set and skip_device false.
		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\ReprintDetector' )
			->shouldReceive( 'detect' )->once()->andReturn( array( 'is_reprint' => false ) );

		$ctx             = $this->ctx();
		$ctx->user_email = 'c@d.co';
		$ctx->val_cpf    = '123.456.789-01';
		// device_signals null → no deferred hook.
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertFalse( $ctx->skip_device );
	}

	public function test_reprint_detection_sets_skip_device(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()
			->with( Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), true )
			->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->andReturnNull();

		Mockery::mock( 'alias:\FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->andReturn( '11111111111' );

		Mockery::mock( 'alias:\FreeFormCertificate\Frontend\ReprintDetector' )
			->shouldReceive( 'detect' )->once()->andReturn( array( 'is_reprint' => true ) );

		$ctx             = $this->ctx();
		$ctx->user_email = 'r@e.co';
		$ctx->val_cpf    = '11111111111';

		( new RateLimitGuard() )->apply( $ctx );

		$this->assertTrue( $ctx->skip_device, 'reprint must whitelist the per-device gate' );
	}

	public function test_registers_deferred_device_signal_hook_when_signals_present(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->andReturnNull();

		// The deferred callback persists signals once the row exists; assert it
		// runs and forwards the right args (and the form-id guard short-circuits).
		$rl->shouldReceive( 'record_device_signals' )->once()
			->with( 99, 7, array( 'fp' => 'abc' ) );

		$captured = null;
		Functions\expect( 'add_action' )->once()->andReturnUsing(
			function ( $hook, $cb ) use ( &$captured ) {
				$this->assertSame( 'ffcertificate_after_submission_save', $hook );
				$captured = $cb;
				return true;
			}
		);

		$ctx                 = $this->ctx();
		$ctx->user_email     = 'd@e.co';
		$ctx->form_id        = 7;
		$ctx->device_signals = array( 'fp' => 'abc' );
		// val_cpf empty → ReprintDetector not consulted.
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertIsCallable( $captured );
		// Mismatched form id → early return, no record_device_signals.
		$captured( 99, 8 );
		// Matching form id → record_device_signals fires.
		$captured( 99, 7 );
	}

	public function test_deferred_hook_skipped_when_skip_device(): void {
		$rl = Mockery::mock( 'alias:\FreeFormCertificate\Security\RateLimiter' );
		$rl->shouldReceive( 'check_all' )->once()->andReturn( array( 'allowed' => true ) );
		$rl->shouldReceive( 'record_attempt' )->andReturnNull();
		Functions\expect( 'add_action' )->never();

		$ctx                 = $this->ctx();
		$ctx->user_email     = 'd@e.co';
		$ctx->skip_device    = true;
		$ctx->device_signals = array( 'fp' => 'abc' );
		( new RateLimitGuard() )->apply( $ctx );

		$this->assertTrue( $ctx->skip_device );
	}
}
