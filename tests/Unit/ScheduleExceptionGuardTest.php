<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;

/**
 * Tests for ScheduleExceptionGuard — pipeline stage 1 that resolves the hidden
 * schedule-exception token into the submission context.
 *
 * Pins the "live token" contract of live_exception_payload() (signature +
 * positive form binding + form-id match + unconsumed jti) and that apply()
 * only touches the context when a token is actually posted. The token collab
 * ScheduleExceptionSession is alias-mocked, so each test runs in its own
 * process.
 *
 * @covers \FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ScheduleExceptionGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Frontend\\Submission\\ScheduleExceptionGuard' );

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_schedule_exception_token'], $_POST['form_id'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Alias-mock the token session with a fixed verify_token payload and a
	 * jti-consumed answer.
	 *
	 * @param array<string, mixed>|null $verified   verify_token() return.
	 * @param bool                      $jti_spent  is_jti_consumed() return.
	 */
	private function mock_session( ?array $verified, bool $jti_spent = false ): void {
		$mock = Mockery::mock( 'alias:FreeFormCertificate\Frontend\ScheduleExceptionSession' );
		$mock->shouldReceive( 'verify_token' )->andReturn( $verified );
		$mock->shouldReceive( 'is_jti_consumed' )->andReturn( $jti_spent );
	}

	// ==================================================================
	// live_exception_payload()
	// ==================================================================

	public function test_null_when_token_unverified(): void {
		$this->mock_session( null );
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 5 ) );
	}

	public function test_null_when_form_id_not_positive(): void {
		$this->mock_session(
			array(
				'form_id' => 5,
				'jti'     => 'j1',
			)
		);
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 0 ) );
	}

	public function test_null_when_form_id_mismatch(): void {
		$this->mock_session(
			array(
				'form_id' => 5,
				'jti'     => 'j1',
			)
		);
		// Token is scoped to form 5 but was posted from form 9.
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 9 ) );
	}

	public function test_null_when_jti_missing(): void {
		$this->mock_session(
			array(
				'form_id' => 5,
				'jti'     => '',
			)
		);
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 5 ) );
	}

	public function test_null_when_jti_already_consumed(): void {
		$this->mock_session(
			array(
				'form_id' => 5,
				'jti'     => 'j1',
			),
			true
		);
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 5 ) );
	}

	public function test_returns_payload_when_live(): void {
		$payload = array(
			'form_id' => 5,
			'jti'     => 'j1',
			'extra'   => 'kept',
		);
		$this->mock_session( $payload, false );

		$this->assertSame( $payload, ScheduleExceptionGuard::live_exception_payload( 'tok', 5 ) );
	}

	// ==================================================================
	// apply()
	// ==================================================================

	public function test_apply_leaves_context_untouched_without_token(): void {
		$ctx = new SubmissionContext();

		( new ScheduleExceptionGuard() )->apply( $ctx );

		$this->assertFalse( $ctx->has_exception );
		$this->assertNull( $ctx->schedule_exception_payload );
	}

	public function test_apply_populates_context_for_a_live_token(): void {
		$payload = array(
			'form_id' => 5,
			'jti'     => 'j1',
		);
		$this->mock_session( $payload, false );

		$_POST['ffc_schedule_exception_token'] = 'tok';
		$_POST['form_id']                      = '5';

		$ctx = new SubmissionContext();
		( new ScheduleExceptionGuard() )->apply( $ctx );

		$this->assertTrue( $ctx->has_exception );
		$this->assertSame( $payload, $ctx->schedule_exception_payload );
	}

	public function test_apply_marks_no_exception_for_a_dead_token(): void {
		$this->mock_session( null );

		$_POST['ffc_schedule_exception_token'] = 'tok';
		$_POST['form_id']                      = '5';

		$ctx = new SubmissionContext();
		( new ScheduleExceptionGuard() )->apply( $ctx );

		$this->assertFalse( $ctx->has_exception );
		$this->assertNull( $ctx->schedule_exception_payload );
	}
}
