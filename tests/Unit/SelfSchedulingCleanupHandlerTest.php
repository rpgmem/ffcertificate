<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCleanupHandler;

/**
 * Tests for SelfSchedulingCleanupHandler: nonce verification, permission checks,
 * parameter validation, cleanup actions (all/old/future/cancelled), and metabox rendering.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingCleanupHandler
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingCleanupHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SelfSchedulingCleanupHandler */
	private SelfSchedulingCleanupHandler $handler;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var array<int, array<string, mixed>> Captured wp_send_json_error calls */
	private array $json_errors = array();

	/** @var array<int, array<string, mixed>> Captured wp_send_json_success calls */
	private array $json_successes = array();

	/** @var bool Controls wp_verify_nonce return value */
	private bool $nonce_valid = true;

	/** @var array<int, array<int, mixed>> Captured do_action calls */
	private array $actions = array();

	/** @var bool Controls current_user_can return value */
	private bool $user_can_manage = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Translation stubs
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

		// WordPress stubs
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => '1' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_key' )->returnArg();
		$actions = &$this->actions;
		$this->actions = array();
		Functions\when( 'do_action' )->alias( function ( ...$args ) use ( &$actions ) {
			$actions[] = $args;
		} );
		$this->reset_activity_log();
		Functions\when( 'absint' )->alias( function ( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-03-03' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->user_can_manage = true;
		$user_can = &$this->user_can_manage;
		Functions\when( 'current_user_can' )->alias( function () use ( &$user_can ) {
			return $user_can;
		} );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// Namespaced stubs for Repositories namespace
		Functions\when( 'FreeFormCertificate\Repositories\wp_cache_get' )->justReturn( false );
		Functions\when( 'FreeFormCertificate\Repositories\wp_cache_set' )->justReturn( true );
		Functions\when( 'FreeFormCertificate\Repositories\wp_cache_delete' )->justReturn( true );
		Functions\when( 'FreeFormCertificate\Repositories\current_user_can' )->justReturn( false );
		Functions\when( 'FreeFormCertificate\Repositories\user_can' )->justReturn( false );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		// wp_verify_nonce — use a reference so individual tests can toggle it
		$this->nonce_valid = true;
		$nonce_valid = &$this->nonce_valid;
		Functions\when( 'wp_verify_nonce' )->alias( function () use ( &$nonce_valid ) {
			return $nonce_valid;
		} );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		// JSON response stubs — capture calls and throw to halt execution
		$errors = &$this->json_errors;
		$successes = &$this->json_successes;
		$this->json_errors = array();
		$this->json_successes = array();

		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$errors ) {
			$errors[] = $data;
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$successes ) {
			$successes[] = $data;
			throw new \RuntimeException( 'wp_send_json_success' );
		} );

		// Mock $wpdb
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 0 )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$this->wpdb = $wpdb;

		$this->handler = new SelfSchedulingCleanupHandler();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		// Reset — not `unset()` — the superglobal: fully removing $_POST leaves
		// it *undefined* for the next test in the shard, so any neighbour that
		// reads `$_POST` (e.g. TabRateLimitTest) errors with "Undefined global
		// variable". Shard packing is test-count-weighted, so which neighbour
		// lands here shifts as tests are added. Reset to an empty array instead.
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Empty the activity log's write buffer, which is static.
	 */
	private function reset_activity_log(): void {
		$ref = new \ReflectionClass( \FreeFormCertificate\Core\ActivityLog::class );
		foreach ( array( 'write_buffer' => array(), 'shutdown_registered' => true ) as $name => $value ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	/**
	 * Rows the cleanup left in the activity log's write buffer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function logged(): array {
		$ref  = new \ReflectionClass( \FreeFormCertificate\Core\ActivityLog::class );
		$prop = $ref->getProperty( 'write_buffer' );
		$prop->setAccessible( true );
		return (array) $prop->getValue();
	}

	/**
	 * Hold exactly these capabilities.
	 *
	 * @param list<string> $caps Capabilities.
	 */
	private function holdCaps( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	/**
	 * Run the AJAX handler, swallowing the exception the JSON stubs throw.
	 */
	private function runCleanup(): void {
		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected: wp_send_json_* halts.
		}
	}

	// ------------------------------------------------------------------
	// Helper: set up $_POST for cleanup AJAX
	// ------------------------------------------------------------------

	private function setPostData( array $overrides = [] ): void {
		$_POST = array_merge( array(
			'nonce'          => 'valid_nonce',
			'calendar_id'    => '5',
			'cleanup_action' => 'all',
		), $overrides );
	}

	/**
	 * Make CalendarRepository::findById return the given calendar
	 * by making wpdb->get_row return the calendar array.
	 */
	private function stubCalendarFound( array $calendar ): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( $calendar );
	}

	// ==================================================================
	// Constructor: registers AJAX hook
	// ==================================================================

	public function test_constructor_calls_add_action(): void {
		// Simply verify the handler is an instance and that add_action was called
		// (add_action is stubbed to return true — constructor completed)
		$this->assertInstanceOf( SelfSchedulingCleanupHandler::class, $this->handler );
	}

	// ==================================================================
	// handle_cleanup_appointments() — Nonce failure
	// ==================================================================

	public function test_cleanup_fails_when_nonce_invalid(): void {
		$_POST = array( 'nonce' => 'bad_nonce' );
		$this->nonce_valid = false;

		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected: wp_send_json_error thrown
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'Security check failed', $this->json_errors[0]['message'] );
	}

	// ==================================================================
	// handle_cleanup_appointments() — Permission denied
	// ==================================================================

	public function test_cleanup_fails_when_permission_denied(): void {
		$this->setPostData();
		$this->user_can_manage = false;

		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'permission', $this->json_errors[0]['message'] );
	}

	// ==================================================================
	// handle_cleanup_appointments() — Missing parameters
	// ==================================================================

	public function test_cleanup_fails_when_calendar_id_zero(): void {
		$this->setPostData( array( 'calendar_id' => '0' ) );

		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'Invalid parameters', $this->json_errors[0]['message'] );
	}

	public function test_cleanup_fails_when_cleanup_action_empty(): void {
		$this->setPostData( array( 'cleanup_action' => '' ) );

		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'Invalid parameters', $this->json_errors[0]['message'] );
	}

	// ==================================================================
	// handle_cleanup_appointments() — Calendar not found
	// ==================================================================

	public function test_cleanup_fails_when_calendar_not_found(): void {
		$this->setPostData();

		// wpdb->get_row returns null (default) => CalendarRepository::findById returns null
		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'Calendar not found', $this->json_errors[0]['message'] );
	}

	// ==================================================================
	// handle_cleanup_appointments() — the purge capability (#1872)
	// ==================================================================

	/**
	 * Only the purge cap (or manage_options) runs the cleanup: a Bookings
	 * Manager holds `ffc_delete_appointments` and is still refused.
	 *
	 * @return array<string, array{0: list<string>}>
	 */
	public function refused_tiers(): array {
		return array(
			'no capability'    => array( array() ),
			'bookings manager' => array( array( 'ffc_view_appointments', 'ffc_manage_appointments', 'ffc_export_appointments', 'ffc_delete_appointments', 'ffc_view_appointments_pii' ) ),
		);
	}

	/**
	 * @dataProvider refused_tiers
	 *
	 * @param list<string> $caps Capabilities held.
	 */
	public function test_cleanup_refuses_without_the_purge_cap( array $caps ): void {
		$this->holdCaps( $caps );
		$this->setPostData();
		$this->wpdb->shouldReceive( 'query' )->never();
		$this->wpdb->shouldReceive( 'update' )->never();

		$this->runCleanup();

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'permission', $this->json_errors[0]['message'] );
		$this->assertSame( array(), $this->logged() );
	}

	public function test_the_purge_cap_alone_is_enough(): void {
		$this->holdCaps( array( 'ffc_purge_appointments' ) );
		$this->setPostData( array( 'cleanup_action' => 'cancelled' ) );
		$this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '9' ) );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->runCleanup();

		$this->assertSame( array(), $this->json_errors );
		$this->assertSame( 1, $this->json_successes[0]['deleted'] );
	}

	// ==================================================================
	// handle_cleanup_appointments() — the three deleting actions
	// ==================================================================

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function deleting_actions(): array {
		return array(
			'all'       => array( 'all', 'Successfully deleted %d appointment(s).' ),
			'old'       => array( 'old', 'Successfully deleted %d past appointment(s).' ),
			'cancelled' => array( 'cancelled', 'Successfully deleted %d cancelled appointment(s).' ),
		);
	}

	/**
	 * Each deleting action reads the ids its criterion reaches, deletes
	 * exactly those, and writes one activity-log row naming them.
	 *
	 * @dataProvider deleting_actions
	 *
	 * @param string $action  Cleanup action.
	 * @param string $message Expected message template.
	 */
	public function test_deleting_action_deletes_the_ids_it_read_and_logs_them( string $action, string $message ): void {
		$this->setPostData( array( 'cleanup_action' => $action, 'expected' => '3' ) );
		$this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '11', '12', '13' ) );

		$prepared = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ) use ( &$prepared ): string {
				$prepared[] = array( $sql, $args );
				return 'Q';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'Q' )->andReturn( 3 );

		$this->runCleanup();

		$this->assertSame( array(), $this->json_errors );
		$this->assertSame( 3, $this->json_successes[0]['deleted'] );
		$this->assertSame( sprintf( $message, 3 ), $this->json_successes[0]['message'] );

		$delete = array_values( array_filter( $prepared, static fn( array $p ): bool => 0 === strpos( $p[0], 'DELETE' ) ) );
		$this->assertCount( 1, $delete );
		$this->assertStringContainsString( 'id IN (%d,%d,%d)', $delete[0][0] );
		$this->assertSame( array( 'wp_ffc_self_scheduling_appointments', 11, 12, 13 ), $delete[0][1][0] );

		$log = $this->logged();
		$this->assertCount( 1, $log, 'One activity-log row per run.' );
		$this->assertSame( 'appointment_bulk_purged', $log[0]['action'] );
		$this->assertSame( 'warning', $log[0]['level'] );
		$this->assertSame( 1, $log[0]['user_id'] );
		$context = json_decode( (string) $log[0]['context'], true );
		$this->assertSame(
			array(
				'calendar_id'     => 5,
				'calendar_title'  => 'Test Calendar',
				'criterion'       => $action,
				'count'           => 3,
				'appointment_ids' => array( 11, 12, 13 ),
			),
			$context
		);
	}

	public function test_cleanup_refuses_when_the_confirmed_count_moved(): void {
		$this->setPostData( array( 'cleanup_action' => 'all', 'expected' => '48' ) );
		$this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '1', '2' ) );
		$this->wpdb->shouldReceive( 'query' )->never();

		$this->runCleanup();

		$this->assertStringContainsString( 'changed since this page was loaded', $this->json_errors[0]['message'] );
		$this->assertSame( array(), $this->json_successes );
		$this->assertSame( array(), $this->logged() );
	}

	// ==================================================================
	// handle_cleanup_appointments() — "future" cancels and notifies
	// ==================================================================

	/**
	 * Two future appointments in a calendar with the given e-mail toggle.
	 *
	 * @param int $send Whether the calendar sends the cancellation e-mail.
	 */
	private function stubFutureCalendar( int $send ): void {
		$this->stubCalendarFound(
			array(
				'id'           => 5,
				'title'        => 'Test Calendar',
				'email_config' => json_encode( array( 'send_cancellation_notification' => $send ) ),
			)
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array( 'id' => 21, 'status' => 'confirmed', 'appointment_date' => '2026-03-10', 'calendar_id' => 5 ),
				array( 'id' => 22, 'status' => 'waitlist', 'appointment_date' => '2026-03-11', 'calendar_id' => 5 ),
			)
		);
	}

	public function test_future_cancels_sends_the_email_and_never_deletes(): void {
		$this->setPostData( array( 'cleanup_action' => 'future', 'expected' => '2' ) );
		$this->stubFutureCalendar( 1 );

		$updates = array();
		$this->wpdb->shouldReceive( 'update' )->twice()->andReturnUsing(
			static function ( $table, array $data, array $where ) use ( &$updates ): int {
				$updates[ $where['id'] ] = $data;
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'query' )->never();
		$this->wpdb->shouldReceive( 'delete' )->never();

		$this->runCleanup();

		$this->assertSame( array(), $this->json_errors );
		$this->assertSame( 2, $this->json_successes[0]['cancelled'] );
		$this->assertArrayNotHasKey( 'deleted', $this->json_successes[0] );
		$this->assertStringContainsString( 'cancellation e-mail', $this->json_successes[0]['message'] );

		$this->assertSame( array( 21, 22 ), array_keys( $updates ) );
		foreach ( $updates as $data ) {
			$this->assertSame( 'cancelled', $data['status'] );
			$this->assertSame( 1, $data['cancelled_by'] );
			$this->assertSame( 'Cancelled by the calendar administrator.', $data['cancellation_reason'] );
		}

		$emails = array_values( array_filter( $this->actions, static fn( array $a ): bool => 'ffcertificate_self_scheduling_appointment_cancelled_email' === $a[0] ) );
		$this->assertCount( 2, $emails, 'Each person booked gets the calendar\'s cancellation e-mail.' );
		$this->assertSame( 21, $emails[0][1]['id'] );
		$this->assertSame( 'cancelled', $emails[0][1]['status'] );
		$this->assertSame( 'Cancelled by the calendar administrator.', $emails[0][1]['cancellation_reason'] );
		$this->assertSame( 5, $emails[0][2]['id'] );

		$hooks = array_column( $this->actions, 0 );
		$this->assertNotContains( 'ffcertificate_appointment_cancelled', $hooks, 'The per-appointment hook would promote a waitlist this run cancels.' );

		$log = $this->logged();
		$this->assertCount( 1, $log );
		$this->assertSame( 'appointment_bulk_cancelled', $log[0]['action'] );
		$context = json_decode( (string) $log[0]['context'], true );
		$this->assertSame( 'future', $context['criterion'] );
		$this->assertSame( 2, $context['count'] );
		$this->assertSame( array( 21, 22 ), $context['appointment_ids'] );
	}

	public function test_future_sends_no_email_when_the_calendar_turns_it_off(): void {
		$this->setPostData( array( 'cleanup_action' => 'future' ) );
		$this->stubFutureCalendar( 0 );
		$this->wpdb->shouldReceive( 'update' )->twice()->andReturn( 1 );
		$this->wpdb->shouldReceive( 'query' )->never();

		$this->runCleanup();

		$this->assertSame( 2, $this->json_successes[0]['cancelled'] );
		$this->assertStringContainsString( 'nobody was notified', $this->json_successes[0]['message'] );
		$this->assertNotContains( 'ffcertificate_self_scheduling_appointment_cancelled_email', array_column( $this->actions, 0 ) );
	}

	public function test_future_skips_a_row_whose_cancel_failed(): void {
		$this->setPostData( array( 'cleanup_action' => 'future' ) );
		$this->stubFutureCalendar( 1 );
		$this->wpdb->shouldReceive( 'update' )->andReturn( false, 1 );

		$this->runCleanup();

		$this->assertSame( 1, $this->json_successes[0]['cancelled'] );
		$this->assertCount( 1, array_filter( $this->actions, static fn( array $a ): bool => 'ffcertificate_self_scheduling_appointment_cancelled_email' === $a[0] ) );
		$context = json_decode( (string) $this->logged()[0]['context'], true );
		$this->assertSame( array( 22 ), $context['appointment_ids'] );
	}

	public function test_sends_cancellation_email_reads_the_toggle(): void {
		$this->assertTrue( SelfSchedulingCleanupHandler::sends_cancellation_email( array( 'email_config' => '{"send_cancellation_notification":1}' ) ) );
		$this->assertTrue( SelfSchedulingCleanupHandler::sends_cancellation_email( array( 'email_config' => array( 'send_cancellation_notification' => 1 ) ) ) );
		$this->assertFalse( SelfSchedulingCleanupHandler::sends_cancellation_email( array( 'email_config' => '{"send_cancellation_notification":0}' ) ) );
		$this->assertFalse( SelfSchedulingCleanupHandler::sends_cancellation_email( array( 'email_config' => 'not json' ) ) );
		$this->assertFalse( SelfSchedulingCleanupHandler::sends_cancellation_email( array() ) );
	}

	// ==================================================================
	// handle_cleanup_appointments() — Invalid action
	// ==================================================================

	public function test_cleanup_fails_with_invalid_action(): void {
		$this->setPostData( array( 'cleanup_action' => 'bogus' ) );
		$this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

		try {
			$this->handler->handle_cleanup_appointments();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertNotEmpty( $this->json_errors );
		$this->assertStringContainsString( 'Invalid cleanup action', $this->json_errors[0]['message'] );
	}

	// ==================================================================
	// render_cleanup_metabox() — Calendar not found
	// ==================================================================

	public function test_render_cleanup_metabox_shows_message_when_calendar_not_found(): void {
		// wpdb->get_row returns null by default => CalendarRepository::findByPostId returns null
		$post = (object) array( 'ID' => 42 );

		ob_start();
		$this->handler->render_cleanup_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Calendar not found in database', $output );
	}

	// ==================================================================
	// render_cleanup_metabox() — Renders stats table
	// ==================================================================

	public function test_render_cleanup_metabox_renders_stats_when_calendar_found(): void {
		// findByPostId needs get_row to return a calendar row
		$this->wpdb->shouldReceive( 'get_row' )
			->andReturn( array( 'id' => 10, 'title' => 'Test Calendar' ) );

		// count() calls from AppointmentRepository use get_var
		$this->wpdb->shouldReceive( 'get_var' )
			->andReturn( 15, 5, 7, 3 );

		$post = (object) array( 'ID' => 42 );

		ob_start();
		$this->handler->render_cleanup_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ffc-cleanup-appointments', $output );
		$this->assertStringContainsString( 'Total:', $output );
		$this->assertStringContainsString( 'Past:', $output );
		$this->assertStringContainsString( 'Future:', $output );
		$this->assertStringContainsString( 'Cancelled:', $output );
	}

	/**
	 * The actions need the cap handle_cleanup_appointments() checks; without
	 * it the box keeps the counts and offers nothing to click (#1769). Since
	 * #1872 that cap is `ffc_purge_appointments`, so a Bookings Manager, who
	 * holds `ffc_delete_appointments`, sees the counts only.
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function cleanup_tiers(): array {
		return array(
			'appointments view-only tier' => array( array( 'ffc_view_appointments', 'ffc_view_calendars' ), false ),
			'appointments manage tier'    => array( array( 'ffc_view_appointments', 'ffc_manage_appointments', 'ffc_manage_calendars' ), false ),
			'bookings manager tier'       => array( array( 'ffc_view_appointments', 'ffc_manage_appointments', 'ffc_delete_appointments' ), false ),
			'bookings administrator tier' => array( array( 'ffc_view_appointments', 'ffc_manage_appointments', 'ffc_delete_appointments', 'ffc_purge_appointments' ), true ),
		);
	}

	/**
	 * @dataProvider cleanup_tiers
	 *
	 * @param list<string> $caps        Capabilities held.
	 * @param bool         $sees_purge  Whether the danger zone renders.
	 */
	public function test_render_cleanup_metabox_offers_the_danger_zone_only_with_the_purge_cap( array $caps, bool $sees_purge ): void {
		$this->holdCaps( $caps );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 10, 'title' => 'Test Calendar' ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 15, 5, 7, 3 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '1', '2' ) );

		ob_start();
		$this->handler->render_cleanup_metabox( (object) array( 'ID' => 42 ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Total:', $output, 'The counts render for every tier.' );
		$this->assertStringContainsString( '15', $output );
		$this->assertSame( $sees_purge ? 4 : 0, substr_count( $output, 'ffc-cleanup-btn ' ) );
		$this->assertSame( $sees_purge, str_contains( $output, 'ffc-cleanup-danger' ) );
		$this->assertSame( $sees_purge, str_contains( $output, 'ffc-notice-danger' ) );
		$this->assertSame( ! $sees_purge, str_contains( $output, 'Permanently delete appointments from this calendar.' ), 'Without the cap the box is today\'s, unchanged.' );
		$this->assertStringNotContainsString( 'No appointments to clean up.', $output );
	}

	public function test_render_danger_zone_carries_the_counts_and_the_icons(): void {
		$this->holdCaps( array( 'ffc_purge_appointments' ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'id'           => 10,
				'title'        => 'Test Calendar',
				'email_config' => '{"send_cancellation_notification":1}',
			)
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 15, 5, 7, 3 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '1', '2' ) );

		ob_start();
		$this->handler->render_cleanup_metabox( (object) array( 'ID' => 42 ) );
		$output = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/data-action="cancelled"\s+data-count="3"/', $output );
		$this->assertMatchesRegularExpression( '/data-action="old"\s+data-count="5"/', $output );
		$this->assertMatchesRegularExpression( '/data-action="future"\s+data-count="2"\s+data-notify="1"/', $output, 'Future counts the bookings it can still cancel.' );
		$this->assertMatchesRegularExpression( '/data-action="all"\s+data-count="15"/', $output );
		$this->assertStringContainsString( 'Cancel future and notify (2)', $output );
		$this->assertStringContainsString( 'ffc-cleanup-btn-caution', $output );
		$this->assertStringContainsString( 'ffc-cleanup-btn-solid', $output );
		$this->assertSame( 3, substr_count( $output, 'ffc-icon-delete' ) );
		$this->assertStringContainsString( 'ffc-icon-cross', $output );
		$this->assertStringContainsString( 'data-calendar-title="Test Calendar"', $output );
	}

	public function test_render_future_button_does_not_promise_a_notice_the_calendar_will_not_send(): void {
		$this->holdCaps( array( 'ffc_purge_appointments' ) );
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( array( 'id' => 10, 'title' => 'Test Calendar' ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( 15, 5, 7, 3 );
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '1' ) );

		ob_start();
		$this->handler->render_cleanup_metabox( (object) array( 'ID' => 42 ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cancel future (1)', $output );
		$this->assertStringContainsString( 'data-notify="0"', $output );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function danger_box_tiers(): array {
		return array(
			'bookings manager'       => array( array( 'ffc_delete_appointments' ), false ),
			'bookings administrator' => array( array( 'ffc_purge_appointments' ), true ),
			'site administrator'     => array( array( 'manage_options' ), true ),
		);
	}

	/**
	 * @dataProvider danger_box_tiers
	 *
	 * @param list<string> $caps   Capabilities held.
	 * @param bool         $marked Whether the postbox gets the danger class.
	 */
	public function test_postbox_is_marked_as_a_danger_zone_only_for_the_purge_cap( array $caps, bool $marked ): void {
		$this->holdCaps( $caps );

		$classes = $this->handler->add_danger_box_class( array( 'closed' ) );

		$this->assertSame( $marked ? array( 'closed', 'ffc-cleanup-danger-box' ) : array( 'closed' ), $classes );
	}
}
