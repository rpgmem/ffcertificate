<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\Tests\Support\CallLog;
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
 * The routing tests run the real AppointmentRepository, CalendarRepository,
 * DateFormatter and AssetHelper over a `$wpdb` double that answers findById()
 * from the rows a test stores; render_page() is stopped before its exit() by a
 * throwing get_language_attributes() stub.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentCancellationHandler
 */
class AppointmentCancellationHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, array<string, mixed>>> Rows findById() answers, by table then id. */
	private array $rows = array();

	/** @var list<string> Every table findById() read from, in order. */
	private array $reads = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->rows        = array();
		$this->reads       = array();
		$this->post_backup = $_POST ?? array();

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
		// What SettingsReader (dark mode) and DateFormatter (formats) read.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return array(
						'date_format' => 'd/m/Y',
						'time_format' => 'H:i',
					);
				}
				return $default;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT * FROM %i WHERE id = %d' !== $q['sql'] ) {
					$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
				}
				list( $table, $id ) = $q['args'];
				$this->reads[]      = $table;
				return $this->rows[ $table ][ $id ] ?? null;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
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
		// #1030: the guard reads the query var once and returns; past it the
		// method reads it twice more (the id and the token). Exactly one read
		// is therefore the proof that it stopped at the guard.
		Functions\expect( 'get_query_var' )->once()->andReturn( '' );

		$handler = $this->make_handler();
		$handler->handle_cancellation_request();
	}

	// ---- handle_cancellation_request routing -------------------------
	//
	// The render_*() methods all funnel into render_page(), which prints the
	// document and then exit()s. get_language_attributes() is stubbed to throw
	// a sentinel, so the routing + render entry are exercised without the
	// process-ending exit(), then the branch taken is asserted via the captured
	// page title / message.

	/**
	 * Capture which message render_page() received by intercepting esc_html().
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
			$this->fail( 'Expected render to short-circuit via get_language_attributes().' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'Invalid cancellation link.' );
		$this->assertSame( array(), $this->reads, 'An id of 0 must never reach the repository.' );
	}

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

		$this->rows['wp_ffc_self_scheduling_appointments'][42] = array(
			'confirmation_token' => 'tok',
			'status'             => 'cancelled',
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
		$this->assertSame( array( 'wp_ffc_self_scheduling_appointments' ), $this->reads );
	}

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

		$this->rows['wp_ffc_self_scheduling_appointments'][42] = array(
			'confirmation_token' => 'tok',
			'status'             => 'confirmed',
			'calendar_id'        => 9,
			'appointment_date'   => '2026-05-20',
			'start_time'         => '09:00:00',
		);
		$this->rows['wp_ffc_self_scheduling_calendars'][9]     = array( 'title' => 'Clinic' );

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
		// The calendar row and the real DateFormatter reached the summary.
		$this->assert_captured_contains( $captured, 'Clinic' );
		$this->assert_captured_contains( $captured, '20/05/2026 · 09:00' );
		$this->assertSame(
			array( 'wp_ffc_self_scheduling_appointments', 'wp_ffc_self_scheduling_calendars' ),
			$this->reads
		);
	}

	// ---- process_cancellation ----------------------------------------

	public function test_process_cancellation_renders_error_on_wp_error(): void {
		$error = new \WP_Error( 'ffc_cancel_deadline', 'too late' );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
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
		$this->assert_captured_contains( $captured, 'too late' );
	}

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
	}

	/**
	 * "Cancel and book a new time" (#2244): the reason is filled in when the
	 * person typed none, and the browser goes to the calendar's page.
	 */
	public function test_process_cancellation_redirects_to_the_booking_page_on_reschedule(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$_POST['ffc_cancel_reason'] = '';
		$redirected = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirected ) {
				$redirected[] = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		$appt_handler = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$appt_handler->shouldReceive( 'cancel_appointment' )->once()->with( 42, 'tok', 'Rescheduled by the participant' )->andReturn( true );

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$handler = new AppointmentCancellationHandler( $appt_handler );

		try {
			$this->invoke_process_cancellation( $handler, 42, 'tok', 'https://example.com/book/' );
			$this->fail( 'Expected the redirect to end the request.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertSame( array( 'https://example.com/book/' ), $redirected );
	}

	/**
	 * A refused cancellation does not redirect: the person stays to read why
	 * and still holds the booking.
	 */
	public function test_process_cancellation_does_not_redirect_when_refused(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$log = CallLog::record( 'wp_safe_redirect', null );

		$appt_handler = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentHandler' );
		$appt_handler->shouldReceive( 'cancel_appointment' )->andReturn( new \WP_Error( 'ffc_cancel_deadline', 'too late' ) );

		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		$handler  = new AppointmentCancellationHandler( $appt_handler );
		$captured = $this->stub_render_capture();

		try {
			$this->invoke_process_cancellation( $handler, 42, 'tok', 'https://example.com/book/' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'render-page', $e->getMessage() );
		}

		$this->assert_captured_contains( $captured, 'too late' );
		$this->assertSame( 0, $log->count() );
	}

	/**
	 * The second button appears only when the calendar is published on a
	 * page; the plain confirm is always there.
	 */
	public function test_confirm_form_offers_rebooking_only_when_the_calendar_is_on_a_page(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( '[ffc_self_scheduling id="31"]' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/book/' );
		$appointment = array(
			'appointment_date' => '2026-05-20',
			'start_time'       => '09:00:00',
		);

		foreach ( array( 'published' => array( 70 ), 'unpublished' => array() ) as $case => $pages ) {
			Functions\when( 'get_posts' )->justReturn( $pages );
			$captured = $this->stub_render_capture();
			$handler  = $this->make_handler();
			$ref      = new \ReflectionMethod( AppointmentCancellationHandler::class, 'render_confirm_form' );
			$ref->setAccessible( true );
			ob_start();
			try {
				$ref->invoke( $handler, $appointment, array( 'title' => 'Clinic', 'post_id' => 31 ) );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'render-page', $e->getMessage() );
			}
			ob_end_clean();

			$labels = $captured->getArrayCopy();
			$this->assertContains( 'Confirm cancellation', $labels, $case );
			if ( 'published' === $case ) {
				$this->assertContains( 'Cancel and book a new time', $labels );
			} else {
				$this->assertNotContains( 'Cancel and book a new time', $labels );
			}
		}
	}

	// ---- the document head --------------------------------------------

	/**
	 * The palette has to reach this page, and it is the one page nothing enqueues.
	 *
	 * This handler builds its own document and links its stylesheet directly, so
	 * it never went through `wp_enqueue_style()` and never picked up the
	 * `ffc-common` dependency every enqueued sheet declares. That sheet reads
	 * eleven `var(--ffc-*)`, and an undeclared custom property does NOT fall back
	 * to a literal — it invalidates the whole declaration. The card therefore had
	 * no ground, no text colour and an unpainted confirm button, in both themes.
	 *
	 * `AdminStylesheetTokensTest`'s dependency direction cannot see this page by
	 * construction: it reads `wp_enqueue_style()` calls, and there is none here.
	 *
	 * @return void
	 */
	public function test_the_palette_loads_before_the_page_own_stylesheet(): void {
		$sheets = $this->call_private_static( 'stylesheets', array( '.min' ) );

		$this->assertCount( 2, $sheets );
		$this->assertStringContainsString( 'assets/css/ffc-common.min.css', $sheets[0] );
		$this->assertStringContainsString( 'assets/css/ffc-appointment-cancellation.min.css', $sheets[1] );
	}

	/**
	 * `on` renders dark. `auto` cannot: this page carries no JavaScript, and the
	 * OS preference is only readable there — a stated limit, not an oversight.
	 *
	 * @return void
	 */
	public function test_the_root_class_honours_the_mode_a_server_can_know(): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => 'on' ) );
		$this->assertSame( ' class="ffc-dark-mode"', $this->call_private_static( 'root_class' ) );
	}

	/**
	 * @dataProvider provider_light_modes
	 * @param string $mode Stored `dark_mode` value that must render light.
	 * @return void
	 */
	public function test_the_root_class_is_empty_for_every_other_mode( string $mode ): void {
		Functions\when( 'get_option' )->justReturn( array( 'dark_mode' => $mode ) );
		$this->assertSame( '', $this->call_private_static( 'root_class' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function provider_light_modes(): array {
		return array(
			'desligado'  => array( 'off' ),
			'automatico' => array( 'auto' ),
			'lixo'       => array( 'nonsense' ),
		);
	}

	/**
	 * Call one of the two extracted head helpers.
	 *
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args   Arguments.
	 * @return mixed
	 */
	private function call_private_static( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( AppointmentCancellationHandler::class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Wire up the render-path WP function stubs and capture every string that
	 * reaches esc_html()/esc_html_e(); make get_language_attributes() throw so
	 * render_page() stops before it prints the document and exit()s.
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
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'status_header' )->justReturn( null );

		// render_page() calls get_language_attributes() in the first echo, after
		// the real AssetHelper::asset_suffix(); throw there to exercise the full
		// routing + render entry without the process-ending exit() or any
		// echoed HTML.
		Functions\when( 'get_language_attributes' )->alias(
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
	private function invoke_process_cancellation( AppointmentCancellationHandler $handler, int $id, string $token, string $next = '' ): void {
		$ref = new \ReflectionMethod( AppointmentCancellationHandler::class, 'process_cancellation' );
		$ref->setAccessible( true );
		$ref->invoke( $handler, $id, $token, $next );
	}
}
