<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertificatesDashboard;

/**
 * Tests for CertificatesDashboard menu registration, ordering and capability.
 */
class CertificatesDashboardTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_constants_match_planned_values(): void {
        $this->assertSame( 'ffc-certificates-dashboard', CertificatesDashboard::MENU_SLUG );
        $this->assertSame( 'edit.php?post_type=ffc_form', CertificatesDashboard::PARENT );
        $this->assertSame( 'ffc_view_certificates', CertificatesDashboard::CAPABILITY );
    }

    public function test_init_registers_admin_menu_hooks(): void {
        $dashboard = new CertificatesDashboard();
        $dashboard->init();

        $this->assertNotFalse(
            has_action( 'admin_menu', 'FreeFormCertificate\Admin\CertificatesDashboard->register_menu()' ),
            'init() should register register_menu on admin_menu'
        );
        $this->assertNotFalse(
            has_action( 'admin_menu', 'FreeFormCertificate\Admin\CertificatesDashboard->reorder_menu()' ),
            'init() should register reorder_menu on admin_menu'
        );
    }

    public function test_register_menu_calls_add_submenu_page_with_correct_args(): void {
        $captured = array();
        Functions\when( 'add_submenu_page' )->alias( function () use ( &$captured ) {
            $captured[] = func_get_args();
        } );

        ( new CertificatesDashboard() )->register_menu();

        $this->assertCount( 1, $captured );
        // add_submenu_page args: parent_slug, page_title, menu_title, capability, menu_slug, callback.
        $this->assertSame( 'edit.php?post_type=ffc_form', $captured[0][0] );
        $this->assertSame( 'ffc_view_certificates', $captured[0][3] );
        $this->assertSame( 'ffc-certificates-dashboard', $captured[0][4] );
    }

    public function test_reorder_menu_does_nothing_when_parent_missing(): void {
        global $submenu;
        $submenu = array();

        ( new CertificatesDashboard() )->reorder_menu();

        $this->assertArrayNotHasKey( 'edit.php?post_type=ffc_form', $submenu );
    }

    public function test_reorder_menu_does_nothing_when_dashboard_not_registered(): void {
        global $submenu;
        $submenu = array(
            'edit.php?post_type=ffc_form' => array(
                array( 'All Certificates', 'edit_posts', 'edit.php?post_type=ffc_form' ),
                array( 'Add New', 'edit_posts', 'post-new.php?post_type=ffc_form' ),
                array( 'Submissions', 'manage_options', 'ffc-submissions' ),
            ),
        );

        ( new CertificatesDashboard() )->reorder_menu();

        $slugs = array_column( $submenu['edit.php?post_type=ffc_form'], 2 );
        $this->assertSame(
            array( 'edit.php?post_type=ffc_form', 'post-new.php?post_type=ffc_form', 'ffc-submissions' ),
            $slugs,
            'Submenu should be untouched when dashboard slug is absent'
        );
    }

    public function test_reorder_menu_moves_dashboard_to_first_position(): void {
        global $submenu;
        $submenu = array(
            'edit.php?post_type=ffc_form' => array(
                array( 'All Certificates', 'edit_posts', 'edit.php?post_type=ffc_form' ),
                array( 'Add New', 'edit_posts', 'post-new.php?post_type=ffc_form' ),
                array( 'Submissions', 'manage_options', 'ffc-submissions' ),
                array( 'Activity Log', 'ffc_view_activity_log', 'ffc-activity-log' ),
                array( 'Dashboard', 'ffc_view_certificates', 'ffc-certificates-dashboard' ),
            ),
        );

        ( new CertificatesDashboard() )->reorder_menu();

        $slugs = array_column( $submenu['edit.php?post_type=ffc_form'], 2 );
        $this->assertSame( 'ffc-certificates-dashboard', $slugs[0], 'Dashboard must be first' );
        $this->assertSame(
            array(
                'ffc-certificates-dashboard',
                'edit.php?post_type=ffc_form',
                'post-new.php?post_type=ffc_form',
                'ffc-submissions',
                'ffc-activity-log',
            ),
            $slugs,
            'Other items keep their relative order'
        );
    }

    public function test_render_page_dies_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $called = false;
        Functions\when( 'wp_die' )->alias( function () use ( &$called ) {
            $called = true;
            throw new \RuntimeException( 'wp_die_called' );
        } );

        try {
            ( new CertificatesDashboard() )->render_page();
            $this->fail( 'render_page should call wp_die when capability is missing' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'wp_die_called', $e->getMessage() );
            $this->assertTrue( $called );
        }
    }

    public function test_render_page_emits_container_with_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        ob_start();
        ( new CertificatesDashboard() )->render_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString( 'class="ffc-certificates-dashboard"', $output );
        $this->assertStringContainsString( 'id="ffc-certificates-calendar"', $output );
        $this->assertStringContainsString( 'id="ffc-certificates-day-list"', $output );
    }
}
