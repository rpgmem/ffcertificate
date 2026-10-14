<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminCalendar;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminCalendar
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminCalendarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
		Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'date_i18n' )->alias( function ( $f, $t = null ) { return date( $f, $t ?? time() ); } );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_GET['action'], $_GET['id'], $_GET['message'], $_GET['page'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminCalendar::class, $page );
	}

	// ==================================================================
	// handle_actions() — no permission
	// ==================================================================

	public function test_handle_actions_returns_early_without_permission(): void {
		// #1030: this asserted nothing, and with no action queued the method
		// would have done nothing whether or not the guard existed. Queue the
		// page's real save action so the capability is the only thing that can
		// stop it, then assert the nonce — which every acting branch verifies
		// immediately after matching the action — was never checked.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'ffc_action'   => 'save_schedule',
			'ffc_schedule_nonce' => 'n',
		);

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — with message
	// ==================================================================

	public function test_handle_actions_shows_feedback_message(): void {
		$_GET['message'] = 'created';
		$_GET['page'] = 'ffc-scheduling-calendars';

		// #1030: the name claims a message is shown and nothing checked it —
		// a handler that rendered no feedback at all would have passed.
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertNotEmpty( $notices, 'handle_actions() surfaced no feedback message' );
	}

	// ==================================================================
	// render_page() — default list
	// ==================================================================

	public function test_render_page_renders_list_by_default(): void {
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wrap', $output );
		$this->assertStringNotContainsString( 'ffc-boxed', $output, 'the list stays a plain list-table screen' );
	}

	// ==================================================================
	// render_list() — with calendars
	// ==================================================================

	public function test_render_list_shows_calendars(): void {
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Public Cal', 'description' => 'A public one', 'visibility' => 'public', 'status' => 'active' ),
				(object) array( 'id' => 2, 'name' => 'Private Cal', 'description' => '', 'visibility' => 'private', 'status' => 'inactive' ),
			)
		);
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'count' )->andReturn( 2 );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Public Cal', $output );
		$this->assertStringContainsString( 'Private Cal', $output );
		$this->assertStringContainsString( 'Public', $output );
		$this->assertStringContainsString( 'Deactivate', $output ); // active calendar.
		$this->assertStringContainsString( 'Delete', $output );     // inactive calendar.
	}

	/**
	 * Row actions by tier (#1769): Edit / Deactivate need
	 * `ffc_manage_audiences`, Delete needs `ffc_delete_audiences`.
	 *
	 * @return array<string, array{0: list<string>, 1: bool, 2: bool}>
	 */
	public function row_action_tiers(): array {
		return array(
			'view-only tier'       => array( array( 'ffc_view_audiences' ), false, false ),
			'manage tier'          => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true, false ),
			'manage + delete tier' => array( array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences' ), true, true ),
		);
	}

	/**
	 * Grant exactly the given capabilities.
	 *
	 * @param list<string> $caps Capabilities held.
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	/**
	 * @dataProvider row_action_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether Edit / Deactivate render.
	 * @param bool         $can_delete Whether Delete renders.
	 */
	public function test_row_actions_render_only_for_the_cap_their_handler_checks( array $caps, bool $can_manage, bool $can_delete ): void {
		$this->grant( $caps );
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Active Cal', 'description' => '', 'visibility' => 'public', 'status' => 'active' ),
				(object) array( 'id' => 2, 'name' => 'Inactive Cal', 'description' => '', 'visibility' => 'private', 'status' => 'inactive' ),
			)
		);
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'count' )->andReturn( 0 );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Active Cal', $output, 'The rows render for every tier.' );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Edit</a>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Deactivate' ) );
		$this->assertSame( $can_delete, str_contains( $output, 'Delete' ) );
	}

	// ==================================================================
	// render_form() — new + edit (full sections)
	// ==================================================================

	public function test_render_form_new_shows_create_button(): void {
		$_GET['action'] = 'new';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'schedule_name', $output );
		$this->assertStringContainsString( 'Notifications', $output );
	}

	public function test_render_form_edit_with_permissions_and_holidays(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array(
				'id'                     => 5,
				'name'                   => 'Cal Five',
				'description'            => 'desc',
				'environment_label'      => 'Rooms',
				'visibility'             => 'public',
				'future_days_limit'      => 30,
				'notify_on_booking'      => 1,
				'notify_on_cancellation' => 0,
				'include_ics'            => 1,
				'show_event_list'        => 1,
				'event_list_position'    => 'below',
				'audience_badge_format'  => 'parent_name',
				'booking_label_singular' => 'session',
				'booking_label_plural'   => 'sessions',
				'is_isolated'            => 1,
				'status'                 => 'active',
			)
		);
		$schedRepo->shouldReceive( 'get_all_permissions' )->with( 5 )->andReturn(
			array( (object) array( 'user_id' => 7, 'can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 1 ) )
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_holidays' )->with( 5 )->andReturn(
			array( (object) array( 'id' => 3, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) )
		);

		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$df->shouldReceive( 'format_wallclock_date' )->andReturn( '25/12/2026' );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cal Five', $output );
		$this->assertStringContainsString( 'User7', $output );
		$this->assertStringContainsString( 'Christmas', $output );
		$this->assertStringContainsString( '25/12/2026', $output );

		// #1627: a boxed screen, one `.card` per section, each heading with its icon.
		$this->assertStringContainsString( 'ffc-page-scheduling-calendars ffc-boxed', $output );
		foreach ( array( 'ffc-icon-calendar">Calendar', 'ffc-icon-users">User Access &amp; Permissions', 'ffc-icon-lock">Holidays / Closed Dates' ) as $heading ) {
			$this->assertMatchesRegularExpression( '/<div class="card">\s*<h2 class="' . preg_quote( $heading, '/' ) . '/', str_replace( 'User Access & Permissions', 'User Access &amp; Permissions', $output ) );
		}
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
		$this->assertStringNotContainsString( '<hr>', $output, 'boxes replace the separators' );
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_edit_no_permissions_no_holidays(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn(
			(object) array( 'id' => 5, 'name' => 'Empty Cal', 'status' => 'active' )
		);
		$schedRepo->shouldReceive( 'get_all_permissions' )->with( 5 )->andReturn( array() );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_holidays' )->with( 5 )->andReturn( array() );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No users have been granted access yet', $output );
		$this->assertStringContainsString( 'No holidays defined yet', $output );
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_missing_calendar_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Calendar not found' );
		// render_page() echoes the admin page wrapper before it detects the
		// missing record and calls wp_die(); capture and discard that output
		// so the markup doesn't leak into PHPUnit's stdout (the assertion is
		// on the wp_die exception, not the buffer).
		ob_start();
		try {
			$page->render_page();
		} finally {
			ob_end_clean();
			unset( $_GET['action'], $_GET['id'] );
		}
	}

	// ==================================================================
	// handle_actions() — save
	// ==================================================================

	public function test_handle_actions_creates_calendar_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$_POST = array(
			'ffc_action'                => 'save_schedule',
			'ffc_schedule_nonce'        => 'n',
			'schedule_id'               => '0',
			'schedule_name'             => 'New Cal',
			'schedule_visibility'       => 'public',
			'schedule_event_list_position' => 'below',
			'schedule_audience_badge_format' => 'parent_name',
			'schedule_booking_label_singular' => 'evt',
			'schedule_notify_booking'   => '1',
			'schedule_is_isolated'      => '1',
			'schedule_status'           => 'active',
		);

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'create' )->once()->with(
			Mockery::on(
				static function ( $d ) {
					return 'New Cal' === $d['name']
						&& 'public' === $d['visibility']
						&& 'below' === $d['event_list_position']
						&& 'parent_name' === $d['audience_badge_format']
						&& 1 === $d['notify_on_booking']
						&& 0 === $d['notify_on_cancellation']
						&& 1 === $d['is_isolated'];
				}
			)
		)->andReturn( 60 );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=created' );
		$page->handle_actions();
	}

	public function test_handle_actions_updates_calendar(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( true );

		$_POST = array(
			'ffc_action'         => 'save_schedule',
			'ffc_schedule_nonce' => 'n',
			'schedule_id'        => '5',
			'schedule_name'      => 'Edited Cal',
			// omit optional toggles -> defaults exercised.
		);

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'update' )->once()->with(
			5,
			Mockery::on(
				static fn( $d ) => 'Edited Cal' === $d['name']
					&& 'side' === $d['event_list_position']
					&& 'name' === $d['audience_badge_format']
					&& null === $d['booking_label_singular']
			)
		)->andReturn( true );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_schedule', 'ffc_schedule_nonce' => 'bad' );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldNotReceive( 'create' );
		$schedRepo->shouldNotReceive( 'update' );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	// ==================================================================
	// handle_actions() — deactivate / delete
	// ==================================================================

	public function test_handle_actions_deactivates_calendar(): void {
		$_GET = array( 'action' => 'deactivate', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'update' )->once()->with( 5, array( 'status' => 'inactive' ) )->andReturn( true );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deactivated' );
		$page->handle_actions();
	}

	public function test_handle_actions_deletes_inactive_calendar(): void {
		$_GET = array( 'action' => 'delete', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'status' => 'inactive' ) );
		$schedRepo->shouldReceive( 'delete' )->once()->with( 5 )->andReturn( true );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deleted' );
		$page->handle_actions();
	}

	public function test_handle_actions_delete_denied_without_cap(): void {
		$_GET = array( 'action' => 'delete', 'id' => '5' );
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'permission to delete' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — holidays
	// ==================================================================

	public function test_handle_actions_adds_holiday(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$_POST = array(
			'ffc_action'          => 'add_holiday',
			'ffc_holiday_nonce'   => 'n',
			'schedule_id'         => '5',
			'holiday_date'        => '2026-12-25',
			'holiday_description' => 'Xmas',
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'add_holiday' )->once()->with( 5, '2026-12-25', 'Xmas' )->andReturn( true );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	public function test_handle_actions_deletes_holiday_and_redirects(): void {
		$_GET = array( 'delete_holiday' => '3', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'remove_holiday' )->once()->with( 3 )->andReturn( true );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=holiday_deleted' );
		$page->handle_actions();
	}

	// ==================================================================
	// #1761 — Environments as the page's second view
	// ==================================================================

	/**
	 * The page as the coordinator builds it: hosting the Environments view.
	 */
	private function page_with_environments(): AudienceAdminCalendar {
		return new AudienceAdminCalendar( 'ffc-scheduling', new \FreeFormCertificate\Audience\AudienceAdminEnvironment( 'ffc-scheduling' ) );
	}

	public function test_calendars_list_prints_the_view_switch_and_links_each_count(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->justReturn( null );
		$_GET      = array( 'page' => 'ffc-scheduling-calendars' );
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 4, 'name' => 'Rooms Cal', 'description' => '', 'visibility' => 'public', 'status' => 'active' ) )
		);
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Rooms' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'count' )->with( array( 'schedule_id' => 4 ) )->andReturn( 3 );

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ">', $output );
		$this->assertMatchesRegularExpression( '/<ul class="subsubsub">.*href="admin\.php\?page=ffc-scheduling-calendars" class="current" aria-current="page">Calendars<\/a> \|.*href="admin\.php\?page=ffc-scheduling-calendars&view=environments">Rooms<\/a>/s', $output );
		$this->assertMatchesRegularExpression( '/<td class="column-environments">\s*<a href="admin\.php\?page=ffc-scheduling-calendars&view=environments&schedule_id=4">3<\/a>/', $output );
		$this->assertStringNotContainsString( 'ffc-environments-table', $output );
	}

	/**
	 * Requests that show the Environments view: the view itself, and the
	 * previous slug when it is rendered (a POST from a form opened before
	 * the move; a GET is redirected before it renders).
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function environments_requests(): array {
		return array(
			'Environments view' => array( array( 'page' => 'ffc-scheduling-calendars', 'view' => 'environments' ) ),
			'previous slug'     => array( array( 'page' => 'ffc-scheduling-environments' ) ),
		);
	}

	/**
	 * @dataProvider environments_requests
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_environments_list_renders_inside_the_calendars_wrap( array $location ): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		$_GET = $location;
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'get_all' )->andReturn( array() );
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array() );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ">', $output );
		$this->assertStringContainsString( 'ffc-environments-table', $output );
		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments" class="current" aria-current="page">Environments</a>', $output );
		$this->assertSame( 1, substr_count( $output, 'class="wrap' ), 'one wrap: the view prints none of its own' );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}

	public function test_environment_form_is_a_boxed_screen_of_the_calendars_page(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$_GET = array(
			'page'   => 'ffc-scheduling-calendars',
			'view'   => 'environments',
			'action' => 'new',
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array( (object) array( 'id' => 1, 'name' => 'Cal' ) ) );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ffc-boxed">', $output );
		$this->assertStringContainsString( 'name="ffc_action" value="save_environment"', $output );
		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments">&larr;', $output, 'Back goes to the view' );
		$this->assertStringNotContainsString( 'schedule_name', $output, 'the calendar form is not drawn' );
	}

	public function test_handle_actions_leaves_the_environments_view_to_its_handler(): void {
		// `action=deactivate`, `id` and `message` mean an environment here.
		$_GET    = array(
			'page'     => 'ffc-scheduling-calendars',
			'view'     => 'environments',
			'action'   => 'deactivate',
			'id'       => '5',
			'_wpnonce' => 'n',
			'message'  => 'deleted',
		);
		$nonces  = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$notices = \FreeFormCertificate\Tests\Support\CallLog::record( 'add_settings_error', true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )->shouldNotReceive( 'update' );

		$this->page_with_environments()->handle_actions();
		$this->assertSame( 0, $nonces->count(), 'no calendar action was attempted' );
		$this->assertSame( 0, $notices->count(), 'no calendar notice was raised' );
	}
}
