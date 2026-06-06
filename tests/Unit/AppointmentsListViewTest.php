<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for includes/self-scheduling/views/appointments-list.php.
 *
 * The view is a procedural admin template: a permission gate, the
 * confirm/cancel mutation handlers (redirect + exit), the single-appointment
 * detail branch (data prep + render), and the default list branch. We include
 * the file under different $_GET states with the WP surface stubbed, asserting
 * the branch-selecting logic — pure markup rows are incidental.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentsListViewTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private const VIEW = '/includes/self-scheduling/views/appointments-list.php';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
        Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=ffc-appointments' );
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        unset( $_GET['appointment'], $_GET['ffc_action'], $_GET['reason'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function include_view(): void {
        include FFC_PLUGIN_DIR . self::VIEW;
    }

    // ==================================================================
    // Permission gate
    // ==================================================================

    public function test_view_dies_without_permission_on_specific_appointment(): void {
        $_GET['appointment'] = '5';
        Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturn( '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        $this->include_view();
    }

    // ==================================================================
    // Confirm mutation
    // ==================================================================

    public function test_view_confirm_success_redirects(): void {
        $_GET['appointment'] = '5';
        $_GET['ffc_action']  = 'confirm';
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'confirm' : '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'confirm' )->with( 5, 1 )->andReturn( true );
        $appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn(
            array( 'id' => 5, 'calendar_id' => 7 )
        );

        $cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $cal_repo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Cal' ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->include_view();
    }

    public function test_view_confirm_failure_redirects(): void {
        $_GET['appointment'] = '5';
        $_GET['ffc_action']  = 'confirm';
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'confirm' : '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'confirm' )->with( 5, 1 )->andReturn( false );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->include_view();
    }

    // ==================================================================
    // Cancel mutation
    // ==================================================================

    public function test_view_cancel_success_redirects(): void {
        $_GET['appointment'] = '5';
        $_GET['ffc_action']  = 'cancel';
        $_GET['reason']      = 'Admin closed slot';
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'cancel' : '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'cancel' )->with( 5, 1, 'Admin closed slot' )->andReturn( true );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->include_view();
    }

    // ==================================================================
    // Detail view
    // ==================================================================

    public function test_view_renders_appointment_detail(): void {
        $_GET['appointment'] = '5';
        Functions\when( 'get_user_by' )->justReturn( false );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturn( '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt = array(
            'id'               => 5,
            'status'           => 'confirmed',
            'calendar_id'      => 7,
            'appointment_date' => '2026-05-20',
            'start_time'       => '09:00:00',
            'end_time'         => '10:00:00',
            'name'             => 'Alice',
            'created_at'       => '2026-05-01',
            'custom_data'      => '{"field_a":"v","field_b":["x","y"]}',
        );
        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn( $appt );

        $cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $cal_repo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Clinic' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'decrypt_appointment' )->andReturnUsing( fn( $a ) => $a );

        ob_start();
        $this->include_view();
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Appointment Details', $out );
        $this->assertStringContainsString( 'Clinic', $out );
        $this->assertStringContainsString( 'Alice', $out );
    }

    public function test_view_detail_not_found_shows_notice(): void {
        $_GET['appointment'] = '5';

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturn( '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn( null );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        ob_start();
        $this->include_view();
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Appointment not found.', $out );
    }

    // ==================================================================
    // List view (default, no specific appointment)
    // ==================================================================

    public function test_view_renders_list_table_by_default(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'get_get_string' )->andReturn( '' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'findAll' )->andReturn( array() );
        $appt_repo->shouldReceive( 'count' )->andReturn( 0 );

        $cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn( array() );

        ob_start();
        $this->include_view();
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Appointments', $out );
        $this->assertStringContainsString( 'Export CSV', $out );
    }
}
