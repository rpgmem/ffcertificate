<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentCancellationHandler;

/**
 * Tests for the public token-based appointment cancellation handler (#Item9).
 * Focus: the URL contract, the constant-time token check, the nonce gate, and
 * the pure branch decision (classify_request) — the render methods end the
 * request with exit() and are intentionally left as thin output wrappers.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentCancellationHandler
 */
class AppointmentCancellationHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				$glue = false === strpos( (string) $url, '?' ) ? '?' : '&';
				return $url . $glue . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_cancel_confirm'], $_POST['_wpnonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_handler(): AppointmentCancellationHandler {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$appt_handler = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		return new AppointmentCancellationHandler( $appt_handler );
	}

	// ---- get_cancellation_url ----------------------------------------

	public function test_get_cancellation_url_includes_query_var_and_token(): void {
		$url = AppointmentCancellationHandler::get_cancellation_url( 42, 'abc/def' );

		$this->assertStringContainsString( 'ffc_cancel_appointment=42', $url );
		// Token is URL-encoded.
		$this->assertStringContainsString( 'token=abc%2Fdef', $url );
	}

	public function test_get_cancellation_url_omits_token_when_empty(): void {
		$url = AppointmentCancellationHandler::get_cancellation_url( 42, '' );

		$this->assertStringContainsString( 'ffc_cancel_appointment=42', $url );
		$this->assertStringNotContainsString( 'token=', $url );
	}

	// ---- add_query_vars ----------------------------------------------

	public function test_add_query_vars_registers_both_vars(): void {
		$handler = $this->make_handler();
		$vars    = $handler->add_query_vars( array( 'existing' ) );

		$this->assertContains( 'ffc_cancel_appointment', $vars );
		$this->assertContains( 'token', $vars );
		$this->assertContains( 'existing', $vars );
	}

	// ---- token_matches (reflection) ----------------------------------

	private function invoke_token_matches( array $appointment, string $token ): bool {
		$ref = new \ReflectionMethod( AppointmentCancellationHandler::class, 'token_matches' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $appointment, $token );
	}

	public function test_token_matches_true_for_exact_token(): void {
		$this->assertTrue( $this->invoke_token_matches( array( 'confirmation_token' => 'secret-123' ), 'secret-123' ) );
	}

	public function test_token_matches_false_for_wrong_token(): void {
		$this->assertFalse( $this->invoke_token_matches( array( 'confirmation_token' => 'secret-123' ), 'secret-999' ) );
	}

	public function test_token_matches_false_for_empty_inputs(): void {
		$this->assertFalse( $this->invoke_token_matches( array( 'confirmation_token' => 'secret' ), '' ) );
		$this->assertFalse( $this->invoke_token_matches( array( 'confirmation_token' => '' ), 'secret' ) );
		$this->assertFalse( $this->invoke_token_matches( array( 'confirmation_token' => null ), 'secret' ) );
		$this->assertFalse( $this->invoke_token_matches( array(), 'secret' ) );
	}

	// ---- classify_request --------------------------------------------

	public function test_classify_invalid_link_when_no_id(): void {
		$this->assertSame(
			'invalid_link',
			AppointmentCancellationHandler::classify_request( 0, null, 'tok', false, false )
		);
	}

	public function test_classify_invalid_token_when_appointment_missing(): void {
		$this->assertSame(
			'invalid_token',
			AppointmentCancellationHandler::classify_request( 42, null, 'tok', false, false )
		);
	}

	public function test_classify_invalid_token_when_token_mismatches(): void {
		$appointment = array( 'confirmation_token' => 'real', 'status' => 'confirmed' );
		$this->assertSame(
			'invalid_token',
			AppointmentCancellationHandler::classify_request( 42, $appointment, 'wrong', false, false )
		);
	}

	public function test_classify_already_cancelled(): void {
		$appointment = array( 'confirmation_token' => 'tok', 'status' => 'cancelled' );
		$this->assertSame(
			'already_cancelled',
			AppointmentCancellationHandler::classify_request( 42, $appointment, 'tok', false, false )
		);
	}

	public function test_classify_process_on_confirmed_post(): void {
		$appointment = array( 'confirmation_token' => 'tok', 'status' => 'confirmed' );
		$this->assertSame(
			'process',
			AppointmentCancellationHandler::classify_request( 42, $appointment, 'tok', true, true )
		);
	}

	public function test_classify_confirm_on_get_or_unconfirmed_post(): void {
		$appointment = array( 'confirmation_token' => 'tok', 'status' => 'confirmed' );
		// GET (not a post).
		$this->assertSame(
			'confirm',
			AppointmentCancellationHandler::classify_request( 42, $appointment, 'tok', false, false )
		);
		// POST but nonce not confirmed → fall back to the confirm form, not process.
		$this->assertSame(
			'confirm',
			AppointmentCancellationHandler::classify_request( 42, $appointment, 'tok', true, false )
		);
	}

	// ---- confirm_submitted (reflection) ------------------------------

	private function invoke_confirm_submitted( AppointmentCancellationHandler $handler ): bool {
		$ref = new \ReflectionMethod( AppointmentCancellationHandler::class, 'confirm_submitted' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( $handler );
	}

	public function test_confirm_submitted_false_without_post_fields(): void {
		$handler = $this->make_handler();
		$this->assertFalse( $this->invoke_confirm_submitted( $handler ) );
	}

	public function test_confirm_submitted_false_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST['ffc_cancel_confirm'] = '1';
		$_POST['_wpnonce']           = 'bad';

		$handler = $this->make_handler();
		$this->assertFalse( $this->invoke_confirm_submitted( $handler ) );
	}

	public function test_confirm_submitted_true_on_valid_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST['ffc_cancel_confirm'] = '1';
		$_POST['_wpnonce']           = 'good';

		$handler = $this->make_handler();
		$this->assertTrue( $this->invoke_confirm_submitted( $handler ) );
	}

	// ---- handle_cancellation_request no-op ---------------------------

	public function test_handle_request_no_op_when_query_var_absent(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		$handler = $this->make_handler();

		// Should return without rendering / exiting.
		$handler->handle_cancellation_request();
		$this->assertTrue( true );
	}
}
