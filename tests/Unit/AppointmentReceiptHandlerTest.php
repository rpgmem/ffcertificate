<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\Identifiers;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;

/**
 * Tests for AppointmentReceiptHandler: the query-var guard, the access and
 * pending-status gates, and the rendered receipt.
 *
 * Everything below the handler is real: AppointmentRepository and
 * CalendarRepository over a `$wpdb` double that answers the appointment row by
 * its id and records any other query, AssetHelper, AssetRegistry, PdfGenerator,
 * Encryption (configured by the bootstrap's key constants, so the receipt
 * decrypts ciphertexts the real cipher wrote), DateFormatter, DocumentFormatter
 * and BrowserGate. `wp_die()` throws, so a gate's message is what the test
 * reads back.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler
 */
class AppointmentReceiptHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> Appointment rows by id. */
	private array $appointments = array();

	/** @var list<int> Every appointment id the repository read from `$wpdb`. */
	private array $appointment_reads = array();

	/** @var list<string> Every query the double did not expect. */
	private array $unexpected = array();

	/** @var array{0: string, 1: array<int, mixed>} SQL and bound arguments of the last prepare(). */
	private array $prepared = array( '', array() );

	/** @var array<string, array<string, mixed>> Localized script data by object name. */
	private array $localized = array();

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->appointments      = array();
		$this->appointment_reads = array();
		$this->unexpected        = array();
		$this->localized         = array();

		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_query_arg' )->justReturn( '/?ffc_appointment_receipt=1' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// The receipt page localizes the request helper's URL and nonce for
		// the pre-flight telemetry (#2040).
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

		// The repositories' object cache always misses, so every read reaches
		// the double.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Also decides Debug::is_enabled() and every settings read below the
		// handler, so no branch depends on test order.
		Functions\when( 'get_option' )->justReturn( array() );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * No test may reach a query the double does not answer. Recorded rather
	 * than failed on the spot, because the receipt wraps the PDF generator in
	 * a catch that would swallow the failure.
	 */
	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'Unexpected queries reached $wpdb.' );
		parent::assertPostConditions();
	}

	/**
	 * A `$wpdb` double answering the appointment read by id.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_wpdb() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared = array( (string) $sql, $args );
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function () {
				list( $sql, $args ) = $this->prepared;
				if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_self_scheduling_appointments' === $args[0] ) {
					$this->appointment_reads[] = (int) $args[1];
					return $this->appointments[ (int) $args[1] ] ?? null;
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);
		foreach ( array( 'get_results', 'get_col', 'get_var', 'insert', 'update', 'delete', 'query' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->unexpected[] = $method . ': ' . $this->prepared[0];
					return null;
				}
			);
		}

		return $wpdb;
	}

	/**
	 * Run the request until `wp_die()` stops it, and return the message.
	 */
	private function die_message(): string {
		Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( (string) $msg ) );

		try {
			( new AppointmentReceiptHandler() )->handle_receipt_request();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'Expected wp_die().' );
	}

	// ==================================================================
	// add_query_vars()
	// ==================================================================

	public function test_add_query_vars_appends_receipt_vars(): void {
		$handler = new AppointmentReceiptHandler();
		$vars = array( 'existing_var' );
		$result = $handler->add_query_vars( $vars );

		$this->assertContains( 'ffc_appointment_receipt', $result );
		$this->assertContains( 'token', $result );
		$this->assertContains( 'existing_var', $result );
		$this->assertCount( 3, $result );
	}

	// ==================================================================
	// handle_receipt_request() — no query var
	// ==================================================================

	public function test_handle_receipt_request_returns_early_without_query_var(): void {
		// #1030: past the guard the method reads the query var twice more and
		// then wp_die()s on the empty id, so one read and no wp_die is what
		// shows it returned at the guard.
		Functions\expect( 'get_query_var' )->once()->andReturn( '' );
		Functions\expect( 'wp_die' )->never();

		$handler = new AppointmentReceiptHandler();
		$handler->handle_receipt_request();
		$this->assertSame( array(), $this->appointment_reads );
	}

	// ==================================================================
	// handle_receipt_request() — invalid ID
	// ==================================================================

	public function test_handle_receipt_request_dies_for_invalid_id(): void {
		Functions\when( 'get_query_var' )->alias( function ( $var ) {
			if ( $var === 'ffc_appointment_receipt' ) return 'abc';
			return '';
		} );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$handler = new AppointmentReceiptHandler();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid appointment receipt request.' );
		$handler->handle_receipt_request();
	}

	// ==================================================================
	// get_receipt_url() — without token
	// ==================================================================

	public function test_get_receipt_url_returns_url_without_token(): void {
		$url = AppointmentReceiptHandler::get_receipt_url( 42 );
		$this->assertIsString( $url );
	}

	// ==================================================================
	// get_receipt_url() — with token
	// ==================================================================

	public function test_get_receipt_url_returns_url_with_token(): void {
		$url = AppointmentReceiptHandler::get_receipt_url( 42, 'abc123' );
		$this->assertIsString( $url );
	}

	// ==================================================================
	// handle_receipt_request() — appointment not found
	// ==================================================================

	public function test_handle_receipt_request_dies_when_not_found(): void {
		$this->stub_query_var( '7', '' );

		$this->assertSame( 'Appointment not found.', $this->die_message() );
		$this->assertSame( array( 7 ), $this->appointment_reads );
	}

	// ==================================================================
	// handle_receipt_request() — no access (wrong token, not owner/admin)
	// ==================================================================

	public function test_handle_receipt_request_dies_without_access(): void {
		$this->stub_query_var( '7', 'wrong-token' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->appointments[7] = array( 'id' => '7', 'confirmation_token' => 'real-token', 'status' => 'confirmed', 'user_id' => '5' );

		$this->assertStringContainsString( 'do not have permission', $this->die_message() );
		$this->assertSame( array( 7 ), $this->appointment_reads );
	}

	/**
	 * The owner, signed in and without a token, passes the access check and
	 * reaches the pending-status gate. `user_id` arrives as the string `$wpdb`
	 * returns, which the strict comparison never matched (#2160).
	 */
	public function test_handle_receipt_request_lets_the_signed_in_owner_through(): void {
		$this->stub_query_var( '7', '' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->appointments[7] = array( 'id' => '7', 'confirmation_token' => 'tok', 'status' => 'pending', 'user_id' => '5' );

		$this->assertStringContainsString( 'awaiting admin approval', $this->die_message() );
		$this->assertSame( array( 7 ), $this->appointment_reads );
	}

	// ==================================================================
	// handle_receipt_request() — pending appointment blocked for non-admin
	// ==================================================================

	public function test_handle_receipt_request_blocks_pending_for_non_admin(): void {
		$this->stub_query_var( '7', 'tok' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->appointments[7] = array( 'id' => '7', 'confirmation_token' => 'tok', 'status' => 'pending', 'user_id' => '5' );

		$this->assertStringContainsString( 'awaiting admin approval', $this->die_message() );
		$this->assertSame( array( 7 ), $this->appointment_reads );
	}

	// ==================================================================
	// handle_receipt_request() — success renders + uses deleted-calendar
	//                            placeholder, decryption + date formatting
	// ==================================================================

	/**
	 * Stub what rendering a receipt reads.
	 */
	private function stub_render(): void {
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'language_attributes' )->justReturn( null );
		Functions\when( 'bloginfo' )->justReturn( null );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_print_scripts' )->justReturn( true );
		// What DateFormatter reads: the plugin's own formats, rendered by a
		// wp_date() that applies the timezone it is given.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $fallback = false ) => 'ffc_settings' === $key
				? array(
					'date_format' => 'd/m/Y',
					'time_format' => 'H:i',
				)
				: $fallback
		);
		// The PDF renderer reads the shipped receipt template off disk, after
		// checking its path sits inside the plugin or a theme.
		Functions\when( 'wp_normalize_path' )->alias( static fn( $path ) => str_replace( '\\', '/', (string) $path ) );
		Functions\when( 'get_template_directory' )->justReturn( '/themes/parent' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/themes/child' );
		// The placeholder substitution filters each value and absolutises URLs.
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) );
		// The PDF filename's translated prefix.
		Functions\when( '_x' )->returnArg();
		// The template's QR code points at the verification page.
		Functions\when( 'site_url' )->alias( static fn( $path = '' ) => 'https://example.com/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);

	}

	/**
	 * Store appointment 7 (calendar deleted, owner user 1), render its
	 * receipt and return the page.
	 *
	 * @return string
	 */
	private function render_receipt(): string {
		// Calendar deleted (calendar_id empty) → placeholder branch, and no
		// calendar read reaches the double.
		$this->appointments[7] = array(
			'id'               => '7',
			'status'           => 'confirmed',
			'user_id'          => '1',
			'confirmation_token' => str_repeat( 'a', 64 ),
			'cpf_encrypted'    => Encryption::encrypt( Identifiers::CPF_A ),
			'name'             => 'Alice',
			'email_encrypted'  => Encryption::encrypt( 'alice@example.com' ),
			'phone_encrypted'  => Encryption::encrypt( '+5511999990000' ),
			'appointment_date' => '2026-05-20',
			'start_time'       => '09:00:00',
			'end_time'         => '10:00:00',
			'created_at'       => '2026-05-01 12:00:00',
			'validation_code'  => 'AC1234567890',
			'user_notes'       => "line1\nline2",
			'calendar_id'      => '0',
		);

		// wp_localize_script runs near the end of display_receipt(), after all
		// the data prep + markup; throw there to stop before the caller's exit.
		// The request helper's `ffc_ajax` is localized earlier, with the
		// scripts (#2040); only the receipt's own data stops the run.
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data = array() ) {
				$this->localized[ $name ] = $data;
				if ( 'ffcReceiptData' === $name ) {
					throw new \RuntimeException( 'localized' );
				}
				return true;
			}
		);

		$handler = new AppointmentReceiptHandler();

		ob_start();
		try {
			$handler->handle_receipt_request();
			ob_end_clean();
			$this->fail( 'Expected display_receipt to short-circuit at wp_localize_script.' );
		} catch ( \RuntimeException $e ) {
			$html = (string) ob_get_clean();
			$this->assertSame( 'localized', $e->getMessage() );
		}

		return $html;
	}

	public function test_the_owner_reads_the_receipt_as_it_is(): void {
		$this->stub_query_var( '7', '' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->stub_render();

		$html = $this->render_receipt();

		$this->assertSame( array( 7 ), $this->appointment_reads );
		// The real cipher decrypted both stored ciphertexts.
		$this->assertStringContainsString( 'alice@example.com', $html );
		$this->assertStringContainsString( '+5511999990000', $html );
		$this->assertStringContainsString( '(Calendar Deleted)', $html );
		$this->assertStringContainsString( 'line1<br />', $html );
		// The wall-clock date and slot render in the configured formats.
		$this->assertStringContainsString( '20/05/2026', $html );
		$this->assertStringContainsString( '09:00 - 10:00', $html );
		$this->assertStringContainsString( '01/05/2026 12:00', $html );

		$receipt = $this->localized['ffcReceiptData'];
		$this->assertSame( '7', $receipt['appointmentId'] );
		$this->assertNotSame( '', $receipt['validationCode'] );
		// The server-side PDF data was built, not dropped by the generator's
		// catch-all fallback.
		$this->assertIsArray( $receipt['pdfData'] );
		$this->assertSame( 'appointment_receipt', $receipt['pdfData']['type'] );
		$this->assertSame( '(Calendar Deleted)', $receipt['pdfData']['form_title'] );
		// The owner's PDF carries the token its QR code links with.
		$this->assertStringContainsString( str_repeat( 'a', 64 ), (string) $receipt['pdfData']['html'] );
		$this->assertStringContainsString( DocumentFormatter::format_cpf( Identifiers::CPF_A ), (string) $receipt['pdfData']['html'] );
	}

	public function test_the_token_holder_reads_the_receipt_as_it_is(): void {
		$this->stub_query_var( '7', str_repeat( 'a', 64 ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->stub_render();

		$html = $this->render_receipt();

		$this->assertStringContainsString( 'alice@example.com', $html );
		$this->assertStringContainsString( '+5511999990000', $html );
	}

	/**
	 * Staff read every receipt masked, manage_options included (#2244): it
	 * used to print the e-mail, phone and CPF and, through the QR code, the
	 * token that cancels the appointment as a guest.
	 */
	public function test_staff_read_the_receipt_masked_and_without_the_token(): void {
		$this->stub_query_var( '7', '' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		$this->stub_render();

		$html = $this->render_receipt();
		$pdf  = (string) $this->localized['ffcReceiptData']['pdfData']['html'];

		foreach ( array( 'alice@example.com', '+5511999990000' ) as $clear ) {
			$this->assertStringNotContainsString( $clear, $html );
			$this->assertStringNotContainsString( $clear, $pdf );
		}
		$this->assertStringContainsString( DocumentFormatter::mask_email( 'alice@example.com' ), $html );
		$this->assertStringContainsString( DocumentFormatter::mask_phone( '+5511999990000' ), $html );
		$this->assertStringNotContainsString( DocumentFormatter::format_cpf( Identifiers::CPF_A ), $pdf );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), $pdf );
	}

	public function test_a_signed_in_user_who_is_neither_owner_nor_staff_is_refused(): void {
		$this->stub_query_var( '7', '' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		$this->appointments[7] = array( 'id' => '7', 'confirmation_token' => 'tok', 'status' => 'confirmed', 'user_id' => '5' );

		$this->assertStringContainsString( 'do not have permission', $this->die_message() );
	}

	/**
	 * Stub get_query_var to return the receipt id + token.
	 */
	private function stub_query_var( string $id, string $token ): void {
		Functions\when( 'get_query_var' )->alias(
			static function ( $var ) use ( $id, $token ) {
				if ( 'ffc_appointment_receipt' === $var ) {
					return $id;
				}
				if ( 'token' === $var ) {
					return $token;
				}
				return '';
			}
		);
	}
}
