<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminEnvironment;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminEnvironmentTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// handle_actions() acts only on its own screen (#1771).
		$_GET['page'] = 'ffc-scheduling-environments';

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
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'submit_button' )->justReturn( '' );

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
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminEnvironment::class, $page );
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
			'ffc_action'   => 'save_environment',
			'ffc_environment_nonce' => 'n',
		);

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — with message
	// ==================================================================

	public function test_handle_actions_shows_feedback_message(): void {
		$_GET['message'] = 'created';
		$_GET['page'] = 'ffc-scheduling-environments';

		// #1030: the name claims a message is shown and nothing checked it —
		// a handler that rendered no feedback at all would have passed.
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertNotEmpty( $notices, 'handle_actions() surfaced no feedback message' );
	}

	// ==================================================================
	// render_view() — default list
	// ==================================================================

	public function test_render_view_renders_list_by_default(): void {
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-environments-table', $output );
		// The hosting page prints the wrap (#1761), never the view.
		$this->assertStringNotContainsString( 'class="wrap', $output );
	}

	// ==================================================================
	// render_list() — with environments + calendars (data-prep / usort)
	// ==================================================================

	public function test_render_list_sorts_by_calendar_then_name(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Zeta', 'schedule_id' => 2, 'status' => 'active', 'color' => '#fff', 'description' => 'd' ),
				(object) array( 'id' => 2, 'name' => 'Alpha', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ),
			)
		);

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Cal One' ),
				(object) array( 'id' => 2, 'name' => 'Cal Two' ),
			)
		);
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = ob_get_clean();

		// Alpha (Cal One) sorts before Zeta (Cal Two).
		$this->assertLessThan( strpos( $output, 'Zeta' ), strpos( $output, 'Alpha' ) );
		$this->assertStringContainsString( 'Cal One', $output );
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
		Functions\when( 'esc_attr__' )->returnArg();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Active Room', 'schedule_id' => 1, 'status' => 'active', 'color' => '#fff', 'description' => '' ),
				(object) array( 'id' => 2, 'name' => 'Inactive Room', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ),
			)
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array( (object) array( 'id' => 1, 'name' => 'Cal One' ) ) );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Active Room', $output, 'The rows render for every tier.' );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Edit</a>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Deactivate' ) );
		$this->assertSame( $can_delete, str_contains( $output, 'Delete' ) );
	}

	public function test_render_list_filters_by_schedule(): void {
		$_GET['schedule_id'] = '3';
		Functions\when( 'esc_html__' )->returnArg();

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_all' )->with( array( 'schedule_id' => 3 ) )->once()->andReturn( array() );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array() );
		$schedRepo->shouldReceive( 'get_environment_label' )->with( 3 )->andReturn( 'Rooms' );
		// The view switch names the views with the default label (#1761).
		$schedRepo->shouldReceive( 'get_environment_label' )->withNoArgs()->andReturn( 'Environments' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Rooms', $output );
		unset( $_GET['schedule_id'] );
	}

	// ==================================================================
	// render_form()
	// ==================================================================

	public function test_render_form_new_shows_create_title(): void {
		$_GET['action'] = 'new';
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environment' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 1, 'name' => 'Cal' ) )
		);

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cal', $output );
		$this->assertStringContainsString( 'environment_name', $output );
		unset( $_GET['action'] );
	}

	public function test_render_form_edit_loads_existing_with_working_hours(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 7 )->andReturn(
			(object) array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => 'desc',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => json_encode( array( 'mon' => array( 'closed' => true, 'start' => '09:00', 'end' => '17:00' ) ) ),
			)
		);

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 2, 'name' => 'Cal' ) )
		);

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'My Room', $output );
		$this->assertStringContainsString( '#abcdef', $output );

		// #1627: the form sits in one box headed by the calendar's own label.
		// The `.ffc-boxed` wrap is the hosting calendars page's (#1761).
		$this->assertMatchesRegularExpression( '/<div class="card">\s*<h2 class="ffc-icon-building">Room<\/h2>/', $output );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_edit_missing_environment_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Environment not found' );
		// The view may echo markup before it detects the missing record and
		// calls wp_die(); capture and discard that output so the markup
		// doesn't leak into PHPUnit's stdout (the assertion is on the wp_die
		// exception, not the buffer).
		ob_start();
		try {
			$page->render_view();
		} finally {
			ob_end_clean();
			unset( $_GET['action'], $_GET['id'] );
		}
	}

	// ==================================================================
	// handle_actions() — save (create + update)
	// ==================================================================

	public function test_handle_actions_creates_environment_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '0',
			'environment_schedule'  => '3',
			'environment_name'      => 'New Env',
			'environment_color'     => '#112233',
			'environment_status'    => 'active',
			'working_hours'         => array(
				'mon' => array( 'start' => '08:00', 'end' => '12:00' ),
				'tue' => array( 'closed' => '1' ),
			),
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'create' )->once()->with(
			Mockery::on(
				static function ( $data ) {
					return 3 === $data['schedule_id']
						&& 'New Env' === $data['name']
						&& true === $data['working_hours']['tue']['closed']
						&& '08:00' === $data['working_hours']['mon']['start'];
				}
			)
		)->andReturn( 42 );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=created' );
		$page->handle_actions();
	}

	public function test_handle_actions_updates_environment(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Edited',
			'environment_color'     => '#222222',
			'environment_status'    => 'inactive',
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'update' )->once()->with( 5, Mockery::type( 'array' ) )->andReturn( true );

		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environment' );

		Functions\when( 'add_settings_error' )->justReturn( true );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	public function test_render_form_keeps_an_inactive_calendar_selected(): void {
		// The select listed active calendars only, so an environment on a
		// deactivated calendar showed "Select a calendar" and could not be
		// saved past the required check (#1771).
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 7 )->andReturn(
			(object) array(
				'id'            => 7,
				'schedule_id'   => 4,
				'name'          => 'Old Room',
				'description'   => '',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 2, 'name' => 'Live Cal', 'status' => 'active' ) )
		);
		$schedRepo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn(
			(object) array( 'id' => 4, 'name' => 'Archived Cal', 'status' => 'inactive' )
		);

		ob_start();
		try {
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		} finally {
			$output = (string) ob_get_clean();
			unset( $_GET['action'], $_GET['id'] );
		}

		$this->assertMatchesRegularExpression( '#<option value="4"\s+selected="selected">\s*Archived Cal \(inactive\)\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="2"\s*>\s*Live Cal\s*</option>#', $output );
	}

	/**
	 * Render the environment form for the given query, with `selected()`
	 * printing its attribute and two active calendars listed.
	 *
	 * @param array<string, string> $get      Query arguments.
	 * @param object|null           $existing The calendar get_by_id() answers, or null for none.
	 */
	private function render_form_for( array $get, ?object $existing ): string {
		$_GET = array_merge( $_GET, $get );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturnUsing(
			static fn( $id = null, $singular = false ) => ( $id ? 'Room' : 'Environment' ) . ( $singular ? '' : 's' )
		);
		$schedRepo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 2, 'name' => 'Cal Two', 'status' => 'active' ),
				(object) array( 'id' => 3, 'name' => 'Cal Three', 'status' => 'active' ),
			)
		);
		$schedRepo->shouldReceive( 'get_by_id' )->andReturn( $existing );

		ob_start();
		try {
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		} finally {
			$output = (string) ob_get_clean();
			unset( $_GET['schedule_id'] );
		}
		return $output;
	}

	public function test_render_form_new_preselects_the_requested_calendar(): void {
		// The calendar editor's Environments tab opens Add New with its
		// calendar (#1796).
		$output = $this->render_form_for(
			array( 'action' => 'new', 'schedule_id' => '3' ),
			(object) array( 'id' => 3, 'name' => 'Cal Three', 'status' => 'active' )
		);

		$this->assertMatchesRegularExpression( '#<option value="3"\s+selected="selected">\s*Cal Three\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="2"\s*>\s*Cal Two\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value=""\s*>\s*Select a calendar\s*</option>#', $output );
		$this->assertStringContainsString( 'name="environment_id" value="0"', $output, 'the form still creates' );
		$this->assertStringContainsString( '<h1>Add New Room</h1>', $output, 'the title takes the calendar\'s label' );
	}

	public function test_render_form_new_ignores_an_unknown_calendar(): void {
		$output = $this->render_form_for( array( 'action' => 'new', 'schedule_id' => '99' ), null );

		$this->assertStringNotContainsString( 'selected="selected"', $output );
		$this->assertMatchesRegularExpression( '#<option value=""\s*>\s*Select a calendar\s*</option>#', $output );
		$this->assertStringNotContainsString( 'value="99"', $output );
		$this->assertStringContainsString( '<h1>Add New Environment</h1>', $output );
	}

	public function test_render_form_new_ignores_a_value_that_is_not_an_id(): void {
		$output = $this->render_form_for( array( 'action' => 'new', 'schedule_id' => 'abc' ), (object) array( 'id' => 3, 'name' => 'Cal Three', 'status' => 'active' ) );

		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	public function test_render_form_edit_keeps_the_saved_calendar_over_the_query(): void {
		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 7 )->andReturn(
			(object) array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => '',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);

		$output = $this->render_form_for(
			array( 'action' => 'edit', 'id' => '7', 'schedule_id' => '3' ),
			(object) array( 'id' => 3, 'name' => 'Cal Three', 'status' => 'active' )
		);

		$this->assertMatchesRegularExpression( '#<option value="2"\s+selected="selected">\s*Cal Two\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="3"\s*>\s*Cal Three\s*</option>#', $output );
	}

	public function test_calendar_tab_add_new_carries_the_calendar(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'get_all' )->with( array( 'schedule_id' => 4 ) )->andReturn( array() );
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4, 'name' => 'Cal Four' ) );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Rooms' );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_calendar_tab( 4 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments&action=new&schedule_id=4" class="button"', $output );
	}

	/**
	 * The list's own Add New carries the calendar filter it applies, and
	 * nothing when no filter is active (#1801).
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function list_add_new_filters(): array {
		return array(
			'filtered'   => array( array( 'schedule_id' => '4' ), '&action=new&schedule_id=4' ),
			'unfiltered' => array( array(), '&action=new' ),
			'not an id'  => array( array( 'schedule_id' => 'abc' ), '&action=new' ),
		);
	}

	/**
	 * @dataProvider list_add_new_filters
	 *
	 * @param array<string, string> $get      Query arguments of the list.
	 * @param string                $expected Query the Add New link ends with.
	 */
	public function test_list_add_new_carries_the_active_filter( array $get, string $expected ): void {
		$_GET = array_merge( $_GET, $get );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'get_all' )->andReturn( array() );
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array() );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Rooms' );

		try {
			ob_start();
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
			$output = (string) ob_get_clean();
		} finally {
			unset( $_GET['schedule_id'] );
		}

		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments' . $expected . '" class="page-title-action"', $output );
	}

	/**
	 * Run a save of environment 5 with the given working hours and return
	 * what reached the repository.
	 *
	 * @param array<string, array<string, string>> $posted Posted `working_hours`.
	 * @param string                               $stored Stored `working_hours` JSON.
	 * @return array<string, mixed>
	 */
	private function save_working_hours( array $posted, string $stored ): array {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Room',
			'working_hours'         => $posted,
		);

		$saved   = array();
		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'working_hours' => $stored ) );
		$envRepo->shouldReceive( 'update' )->once()->andReturnUsing(
			static function ( $id, $data ) use ( &$saved ) {
				$saved = $data['working_hours'];
				return true;
			}
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environment' );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$_POST = array();
		return $saved;
	}

	public function test_save_keeps_the_stored_times_of_a_closed_day(): void {
		// A closed day's time inputs are disabled, so only `closed` is posted;
		// the save used to reset the day to 08:00-18:00 (#1771).
		$saved = $this->save_working_hours(
			array(
				'mon' => array( 'closed' => '1' ),
				'tue' => array( 'start' => '10:00', 'end' => '16:00' ),
			),
			(string) json_encode(
				array(
					'mon' => array( 'closed' => false, 'start' => '07:30', 'end' => '12:15' ),
					'tue' => array( 'closed' => false, 'start' => '09:00', 'end' => '17:00' ),
				)
			)
		);

		$this->assertSame(
			array(
				'mon' => array( 'closed' => true, 'start' => '07:30', 'end' => '12:15' ),
				'tue' => array( 'closed' => false, 'start' => '10:00', 'end' => '16:00' ),
			),
			$saved
		);
	}

	public function test_save_stores_the_default_for_an_empty_time(): void {
		// The field says "Leave times empty to use default (08:00 - 18:00)",
		// and an empty string is what was stored (#1771).
		$saved = $this->save_working_hours(
			array(
				'wed' => array( 'start' => '', 'end' => '' ),
				'thu' => array( 'closed' => '1' ),
			),
			''
		);

		$this->assertSame(
			array(
				'wed' => array( 'closed' => false, 'start' => '08:00', 'end' => '18:00' ),
				'thu' => array( 'closed' => true, 'start' => '08:00', 'end' => '18:00' ),
			),
			$saved
		);
	}

	public function test_handle_actions_does_not_save_on_another_screen(): void {
		// The coordinator runs this handler on every ffc-scheduling* screen;
		// the save branch was the one that did not check which (#1771).
		$_GET['page'] = 'ffc-scheduling-settings';
		$nonce        = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$_POST        = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldNotReceive( 'update' );
		$envRepo->shouldNotReceive( 'create' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( 0, $nonce->count() );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_environment', 'ffc_environment_nonce' => 'bad' );

		// No repo expectations — must return before any persistence.
		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldNotReceive( 'create' );
		$envRepo->shouldNotReceive( 'update' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertTrue( true );
	}

	// ==================================================================
	// handle_actions() — deactivate
	// ==================================================================

	public function test_handle_actions_deactivates_environment(): void {
		$_GET = array(
			'action'   => 'deactivate',
			'id'       => '8',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'update' )->once()->with( 8, array( 'status' => 'inactive' ) )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deactivated' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — delete
	// ==================================================================

	public function test_handle_actions_deletes_inactive_environment(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '9',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'inactive' ) );
		$envRepo->shouldReceive( 'delete' )->once()->with( 9 )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'message=deleted' );
		$page->handle_actions();
	}

	public function test_handle_actions_delete_skips_active_environment(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '9',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'active' ) );
		$envRepo->shouldNotReceive( 'delete' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions(); // no redirect — active item can't be deleted.
		$this->assertTrue( true );
	}

	public function test_handle_actions_delete_denied_without_cap(): void {
		$_GET = array(
			'action' => 'delete',
			'id'     => '9',
			'page'   => 'ffc-scheduling-environments',
		);
		// Top gate admin_or('ffc_manage_audiences') passes via the granular cap;
		// not a site admin and the delete cap is denied -> wp_die.
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'permission to delete' );
		$page->handle_actions();
	}

	// ==================================================================
	// #1761 — the screen is a view of Audience Calendars
	// ==================================================================

	/**
	 * Where an environment request can arrive: the view on the calendars
	 * page, and the previous slug (still registered, hidden).
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function locations(): array {
		return array(
			'Environments view' => array( array( 'page' => 'ffc-scheduling-calendars', 'view' => 'environments' ) ),
			'previous slug'     => array( array( 'page' => 'ffc-scheduling-environments' ) ),
		);
	}

	public function test_view_url_names_the_calendars_page_and_the_view(): void {
		Functions\when( 'admin_url' )->returnArg();
		$this->assertSame( 'admin.php?page=ffc-scheduling-calendars&view=environments', AudienceAdminEnvironment::view_url( 'ffc-scheduling' ) );
		$this->assertSame( 'admin.php?page=ffc-scheduling-calendars&view=environments&action=new', AudienceAdminEnvironment::view_url( 'ffc-scheduling', '&action=new' ) );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_is_own_request_at_either_location( array $location ): void {
		$_GET = $location;
		$this->assertTrue( ( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->is_own_request() );
	}

	public function test_is_not_own_request_on_the_calendars_view_or_a_sibling(): void {
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$_GET = array( 'page' => 'ffc-scheduling-calendars' );
		$this->assertFalse( $page->is_own_request(), 'the Calendars view' );
		$_GET = array(
			'page' => 'ffc-scheduling-audiences',
			'view' => 'environments',
		);
		$this->assertFalse( $page->is_own_request(), 'a sibling page carrying the view' );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_deactivate_works_from_either_location_and_lands_on_the_view( array $location ): void {
		$_GET = $location + array(
			'action'   => 'deactivate',
			'id'       => '8',
			'_wpnonce' => 'n',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'deactivate_environment_8' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'update' )->once()->with( 8, array( 'status' => 'inactive' ) )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect:admin.php?page=ffc-scheduling-calendars&view=environments&message=deactivated' );
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_delete_works_from_either_location_and_lands_on_the_view( array $location ): void {
		$_GET = $location + array(
			'action'   => 'delete',
			'id'       => '9',
			'_wpnonce' => 'n',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'delete_environment_9' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'inactive' ) );
		$envRepo->shouldReceive( 'delete' )->once()->with( 9 )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect:admin.php?page=ffc-scheduling-calendars&view=environments&message=deleted' );
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_create_works_from_either_location_and_opens_the_new_record_in_the_view( array $location ): void {
		$_GET  = $location + array( 'action' => 'new' );
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '0',
			'environment_schedule'  => '3',
			'environment_name'      => 'New Env',
			'environment_status'    => 'active',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'save_environment' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'create' )->once()->with( Mockery::on( static fn( $data ) => 3 === $data['schedule_id'] && 'New Env' === $data['name'] ) )->andReturn( 42 );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirect:admin.php?page=ffc-scheduling-calendars&view=environments&action=edit&id=42&message=created' );
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_update_works_from_either_location( array $location ): void {
		$_GET  = $location + array(
			'action' => 'edit',
			'id'     => '5',
		);
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Edited',
			'environment_status'    => 'inactive',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'save_environment' )->andReturn( 1 );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'update' )->once()->with( 5, Mockery::on( static fn( $data ) => 'Edited' === $data['name'] && 'inactive' === $data['status'] ) )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( array( 'Room updated successfully.' ), $notices );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_redirect_feedback_shows_at_either_location( array $location ): void {
		$_GET    = $location + array( 'message' => 'deleted' );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' )->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( array( 'Room deleted successfully.' ), $notices );
	}

	public function test_actions_are_left_alone_on_the_calendars_view(): void {
		// The calendars list shares the page; its `action=deactivate` and
		// `message` belong to AudienceAdminCalendar.
		$_GET = array(
			'page'     => 'ffc-scheduling-calendars',
			'action'   => 'deactivate',
			'id'       => '8',
			'_wpnonce' => 'n',
			'message'  => 'deleted',
		);
		$nonces  = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$notices = \FreeFormCertificate\Tests\Support\CallLog::record( 'add_settings_error', true );
		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldNotReceive( 'update' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( 0, $nonces->count(), 'no environment action was attempted' );
		$this->assertSame( 0, $notices->count(), 'no environment notice was raised' );
	}

	public function test_the_list_links_and_filter_point_at_the_view(): void {
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		$buttons = array();
		Functions\when( 'submit_button' )->alias(
			static function ( $text, $type, $name ) use ( &$buttons ) {
				$buttons[] = array( $text, $type, $name );
			}
		);
		$_GET = array(
			'page'        => 'ffc-scheduling-calendars',
			'view'        => 'environments',
			'schedule_id' => '1',
		);

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )->shouldReceive( 'get_all' )->with( array( 'schedule_id' => 1 ) )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Active Room', 'schedule_id' => 1, 'status' => 'active', 'color' => '#fff', 'description' => '' ),
				(object) array( 'id' => 2, 'name' => 'Inactive Room', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ),
			)
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array( (object) array( 'id' => 1, 'name' => 'Cal One' ), (object) array( 'id' => 2, 'name' => 'Cal Two' ) ) );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		$view = 'admin.php?page=ffc-scheduling-calendars&view=environments';
		$this->assertStringContainsString( 'href="' . $view . '&action=new&schedule_id=1" class="page-title-action"', $output, 'the filtered list\'s Add New carries the calendar (#1801)' );
		$this->assertSame( 2, substr_count( $output, 'href="' . $view . '&action=edit&id=1"' ), 'the name link and the Edit action' );
		$this->assertStringContainsString( 'href="' . $view . '&action=deactivate&id=1&_wpnonce=deactivate_environment_1"', $output );
		$this->assertStringContainsString( 'href="' . $view . '&action=delete&id=2&_wpnonce=delete_environment_2"', $output );
		$this->assertStringNotContainsString( 'ffc-scheduling-environments', $output, 'no link is left on the previous slug' );

		// The filter: a GET form that stays on the view, the calendar select and its Filter button.
		$this->assertMatchesRegularExpression( '/<div class="tablenav top">\s*<form method="get">\s*<input type="hidden" name="page" value="ffc-scheduling-calendars">\s*<input type="hidden" name="view" value="environments">\s*<select name="schedule_id">/', $output );
		$this->assertStringContainsString( '<option value="">All Calendars</option>', $output );
		$this->assertSame( array( array( 'Filter', 'secondary', 'filter' ) ), $buttons );

		// The same columns as before the move.
		preg_match_all( '/<th scope="col" class="(column-[a-z]+)">/', $output, $columns );
		$this->assertSame( array( 'column-color', 'column-name', 'column-calendar', 'column-status', 'column-actions' ), $columns[1] );

		// The view switch, with Environments current.
		$this->assertMatchesRegularExpression( '/<ul class="subsubsub">.*href="admin\.php\?page=ffc-scheduling-calendars">Calendars<\/a>.*href="' . preg_quote( $view, '/' ) . '" class="current" aria-current="page">Environments<\/a>/s', $output );
	}

	/**
	 * Edit-screen tiers (#1777): view-only renders read-only, manage edits.
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function edit_screen_tiers(): array {
		return array(
			'view-only tier' => array( array( 'ffc_view_audiences' ), false ),
			'manage tier'    => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true ),
		);
	}

	/**
	 * Record each submit button as a marker carrying its label.
	 */
	private function capture_submit_buttons(): void {
		Functions\when( 'submit_button' )->alias(
			static function ( $text = null ) {
				echo '[submit:' . $text . ']';
			}
		);
	}

	/**
	 * @dataProvider edit_screen_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether the screen is editable.
	 */
	public function test_edit_form_renders_read_only_without_manage( array $caps, bool $can_manage ): void {
		$this->grant( $caps );
		$this->capture_submit_buttons();
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$envRepo->shouldReceive( 'get_by_id' )->with( 7 )->andReturn(
			(object) array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => 'desc',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);
		$schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$schedRepo->shouldReceive( 'get_environment_label' )->andReturn( 'Room' );
		$schedRepo->shouldReceive( 'get_all' )->andReturn( array( (object) array( 'id' => 2, 'name' => 'Cal', 'status' => 'active' ) ) );
		$schedRepo->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 2, 'name' => 'Cal', 'status' => 'active' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'My Room', $output, 'every tier sees the record' );
		$this->assertSame( ! $can_manage, str_contains( $output, 'Read-only — you can view this page' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '<fieldset disabled class="ffc-readonly-lock">' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '</fieldset>' ) );
		$this->assertSame( $can_manage, str_contains( $output, '[submit:Update Room]' ) );
		if ( ! $can_manage ) {
			$open  = (int) strpos( $output, '<fieldset disabled' );
			$close = (int) strpos( $output, '</fieldset>' );
			foreach ( array( 'name="environment_schedule"', 'name="environment_name"', 'name="working_hours[sun][closed]"', 'name="environment_status"' ) as $field ) {
				$at = (int) strpos( $output, $field );
				$this->assertTrue( $at > $open && $at < $close, $field . ' sits inside the lock' );
			}
		}
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}
}
