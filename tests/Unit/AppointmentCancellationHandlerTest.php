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

	// ---- handle_cancellation_request routing -------------------------
	//
	// The render_*() methods all funnel into render_page(), which calls
	// Utils::asset_suffix() and then exit(). We alias-mock Utils::asset_suffix
	// to throw a sentinel so the routing + render entry are exercised without
	// the process-ending exit(), then assert the correct branch was taken via
	// the captured page title / message routed through __().

	/**
	 * Capture which message render_page() received by intercepting __().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_request_routes_invalid_link_for_missing_id(): void {
		// Truthy-but-non-numeric query var → passes the `! get_query_var` guard
		// yet absint()s to 0, triggering the invalid_link branch.
		Functions\when( 'get_query_var' )->alias(
			static function ( $var ) {
				return AppointmentCancellationHandler::QUERY_VAR === $var ? 'abc' : '';
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$captured = $this->stub_render_capture();

		$handler = $this->make_handler();
		try {
			$handler->handle_cancellation_request();
			$this->fail( 'Expected render to short-circuit via Utils::asset_suffix.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'Invalid cancellation link.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_request_routes_already_cancelled(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var ) {
				if ( AppointmentCancellationHandler::QUERY_VAR === $var ) {
					return '42';
				}
				return 'tok';
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findById' )->with( 42 )->andReturn(
			array( 'confirmation_token' => 'tok', 'status' => 'cancelled' )
		);

		$captured = $this->stub_render_capture();

		$handler = $this->make_handler();
		try {
			$handler->handle_cancellation_request();
			$this->fail( 'Expected render to short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'Already cancelled' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_handle_request_renders_confirm_form_with_calendar(): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var ) {
				if ( AppointmentCancellationHandler::QUERY_VAR === $var ) {
					return '42';
				}
				return 'tok';
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findById' )->with( 42 )->andReturn(
			array(
				'confirmation_token' => 'tok',
				'status'             => 'confirmed',
				'calendar_id'        => 9,
				'appointment_date'   => '2026-05-20',
				'start_time'         => '09:00:00',
			)
		);

		$cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$cal_repo->shouldReceive( 'findById' )->with( 9 )->andReturn( array( 'title' => 'Clinic' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_wallclock_date' )->andReturn( '20/05/2026' )
			->shouldReceive( 'format_wallclock_time' )->andReturn( '09:00' );

		$captured = $this->stub_render_capture();

		$handler = $this->make_handler();
		try {
			$handler->handle_cancellation_request();
			$this->fail( 'Expected render to short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		// Confirm form heading routed through esc_html_e / __.
		$this->assert_captured_contains( $captured, 'Cancel appointment' );
	}

	// ---- process_cancellation ----------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_process_cancellation_renders_error_on_wp_error(): void {
		$error = Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'too late' );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$appt_handler = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$appt_handler->shouldReceive( 'cancel_appointment' )->with( 42, 'tok', '' )->andReturn( $error );

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$handler = new AppointmentCancellationHandler( $appt_handler );

		$captured = $this->stub_render_capture();

		try {
			$this->invoke_process_cancellation( $handler, 42, 'tok' );
			$this->fail( 'Expected render to short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'Could not cancel' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_process_cancellation_renders_success(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$_POST['ffc_cancel_reason'] = 'changed plans';

		$appt_handler = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$appt_handler->shouldReceive( 'cancel_appointment' )->with( 42, 'tok', 'changed plans' )->andReturn( true );

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$handler = new AppointmentCancellationHandler( $appt_handler );

		$captured = $this->stub_render_capture();

		try {
			$this->invoke_process_cancellation( $handler, 42, 'tok' );
			$this->fail( 'Expected render to short-circuit.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'Appointment cancelled' );

		unset( $_POST['ffc_cancel_reason'] );
	}

	/**
	 * Wire up the render-path WP function stubs and capture every string that
	 * reaches __()/esc_html_e(); make Utils::asset_suffix() throw so render_page
	 * stops before exit().
	 *
	 * @return array{strings: list<string>}
	 */
	private function stub_render_capture(): \ArrayObject {
		// An ArrayObject so the closures and the returned handle share one
		// instance — a plain array would be copied on return and the test would
		// never observe the captured strings.
		$captured = new \ArrayObject();

		// Capture every label that reaches the render layer. render_notice()
		// passes title+body through esc_html(); render_confirm_form() emits its
		// headings via esc_html_e(). Neither is stubbed in setUp(), so these are
		// the first (and only) definitions in the test process.
		Functions\when( 'esc_html' )->alias(
			static function ( $text ) use ( $captured ) {
				$captured->append( $text );
				return $text;
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) use ( $captured ) {
				$captured->append( $text );
			}
		);
		Functions\when( 'get_language_attributes' )->justReturn( 'lang="en"' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'status_header' )->justReturn( null );

		// render_page() reads Utils::asset_suffix() before emitting the page
		// body; throw there to exercise the full routing + render entry without
		// the process-ending exit() or any echoed HTML.
		Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
			->shouldReceive( 'asset_suffix' )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'render-page' );
				}
			);

		return $captured;
	}

	/**
	 * @param \ArrayObject<int, string> $captured Captured render-layer strings.
	 */
	private function assert_captured_contains( \ArrayObject $captured, string $needle ): void {
		$this->assertContains( $needle, $captured->getArrayCopy() );
	}

	/**
	 * Invoke the private process_cancellation().
	 */
	private function invoke_process_cancellation( AppointmentCancellationHandler $handler, int $id, string $token ): void {
		$ref = new \ReflectionMethod( AppointmentCancellationHandler::class, 'process_cancellation' );
		$ref->setAccessible( true );
		$ref->invoke( $handler, $id, $token );
	}
}
