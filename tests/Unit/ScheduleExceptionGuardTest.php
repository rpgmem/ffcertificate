<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\ScheduleExceptionSession;

/**
 * Tests for ScheduleExceptionGuard — pipeline stage 1 that resolves the hidden
 * schedule-exception token into the submission context.
 *
 * Pins the "live token" contract of live_exception_payload() (signature +
 * positive form binding + form-id match + unconsumed jti) and that apply()
 * only touches the context when a token is actually posted. The tokens are
 * signed by the real ScheduleExceptionSession and the consumed ledger is a
 * stubbed option (#2125).
 *
 * @covers \FreeFormCertificate\Frontend\Submission\ScheduleExceptionGuard
 */
class ScheduleExceptionGuardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** The consumed-ledger option names that read as spent. */
	private array $spent = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Frontend\\Submission\\ScheduleExceptionGuard' );

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->spent = array();
		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = false ) => in_array( $name, $this->spent, true ) ? '1' : $fallback
		);
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
	 * A token the real session signed, live for an hour.
	 *
	 * @param array<string, mixed> $claims    form_id and jti.
	 * @param bool                 $jti_spent Whether the ledger holds its jti.
	 * @return array{0: string, 1: array<string, mixed>} The token and the payload it verifies to.
	 */
	private function token( array $claims, bool $jti_spent = false ): array {
		$payload = $claims + array(
			'v'   => 1,
			'exp' => time() + HOUR_IN_SECONDS,
		);
		if ( $jti_spent ) {
			$this->spent[] = 'ffc_sched_exc_used_' . $claims['jti'];
		}
		return array( ScheduleExceptionSession::sign_token( $payload ), $payload );
	}

	// ==================================================================
	// live_exception_payload()
	// ==================================================================

	public function test_null_when_token_unverified(): void {
		list( $token ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1' ) );

		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( $token . 'x', 5 ), 'A tampered signature.' );
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( 'tok', 5 ), 'Not a token.' );
	}

	public function test_null_when_form_id_not_positive(): void {
		list( $token ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1' ) );
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( $token, 0 ) );
	}

	public function test_null_when_form_id_mismatch(): void {
		list( $token ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1' ) );
		// Token is scoped to form 5 but was posted from form 9.
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( $token, 9 ) );
	}

	public function test_null_when_jti_missing(): void {
		list( $token ) = $this->token( array( 'form_id' => 5, 'jti' => '' ) );
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( $token, 5 ) );
	}

	public function test_null_when_jti_already_consumed(): void {
		list( $token ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1' ), true );
		$this->assertNull( ScheduleExceptionGuard::live_exception_payload( $token, 5 ) );
	}

	public function test_returns_payload_when_live(): void {
		list( $token, $payload ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1', 'extra' => 'kept' ) );

		$this->assertSame( $payload, ScheduleExceptionGuard::live_exception_payload( $token, 5 ) );
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
		list( $token, $payload ) = $this->token( array( 'form_id' => 5, 'jti' => 'j1' ) );

		$_POST['ffc_schedule_exception_token'] = $token;
		$_POST['form_id']                      = '5';

		$ctx = new SubmissionContext();
		( new ScheduleExceptionGuard() )->apply( $ctx );

		$this->assertTrue( $ctx->has_exception );
		$this->assertSame( $payload, $ctx->schedule_exception_payload );
	}

	public function test_apply_marks_no_exception_for_a_dead_token(): void {
		$_POST['ffc_schedule_exception_token'] = 'tok';
		$_POST['form_id']                      = '5';

		$ctx = new SubmissionContext();
		( new ScheduleExceptionGuard() )->apply( $ctx );

		$this->assertFalse( $ctx->has_exception );
		$this->assertNull( $ctx->schedule_exception_payload );
	}
}
