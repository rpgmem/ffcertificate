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
        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminEnvironment::class, $page );
    }

    // ==================================================================
    // handle_actions() — no permission
    // ==================================================================

    public function test_handle_actions_returns_early_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — with message
    // ==================================================================

    public function test_handle_actions_shows_feedback_message(): void {
        $_GET['message'] = 'created';
        $_GET['page'] = 'ffc-scheduling-environments';

        Functions\when( 'add_settings_error' )->justReturn( true );

        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_page() — default list
    // ==================================================================

    public function test_render_page_renders_list_by_default(): void {
        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
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
        $page->render_page();
        $output = ob_get_clean();

        // Alpha (Cal One) sorts before Zeta (Cal Two).
        $this->assertLessThan( strpos( $output, 'Zeta' ), strpos( $output, 'Alpha' ) );
        $this->assertStringContainsString( 'Cal One', $output );
    }

    public function test_render_list_filters_by_schedule(): void {
        $_GET['schedule_id'] = '3';
        Functions\when( 'esc_html__' )->returnArg();

        $envRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
        $envRepo->shouldReceive( 'get_all' )->with( array( 'schedule_id' => 3 ) )->once()->andReturn( array() );

        $schedRepo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
        $schedRepo->shouldReceive( 'get_all' )->andReturn( array() );
        $schedRepo->shouldReceive( 'get_environment_label' )->with( 3 )->andReturn( 'Rooms' );

        $page = new AudienceAdminEnvironment( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
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
        $page->render_page();
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
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'My Room', $output );
        $this->assertStringContainsString( '#abcdef', $output );
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
}
