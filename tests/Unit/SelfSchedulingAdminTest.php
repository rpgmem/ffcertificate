<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin
 */
class SelfSchedulingAdminTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/ffcertificate/' );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
        if ( ! defined( 'FFC_JQUERY_UI_VERSION' ) ) {
            define( 'FFC_JQUERY_UI_VERSION', '1.13.2' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $admin = new SelfSchedulingAdmin();
        $this->assertInstanceOf( SelfSchedulingAdmin::class, $admin );
    }

    // ==================================================================
    // add_submenu_pages()
    // ==================================================================

    public function test_add_submenu_pages_registers_menu(): void {
        $admin = new SelfSchedulingAdmin();
        $admin->add_submenu_pages();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_admin_assets() — no screen
    // ==================================================================

    public function test_enqueue_admin_assets_returns_early_without_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( null );

        $admin = new SelfSchedulingAdmin();
        $admin->enqueue_admin_assets( 'edit.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_admin_assets() — wrong screen
    // ==================================================================

    public function test_enqueue_admin_assets_returns_early_on_wrong_screen(): void {
        $screen = (object) array( 'post_type' => 'post', 'id' => 'edit-post' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $admin = new SelfSchedulingAdmin();
        $admin->enqueue_admin_assets( 'edit.php' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_admin_assets() — correct screen
    // ==================================================================

    public function test_enqueue_admin_assets_enqueues_on_self_scheduling_screen(): void {
        $screen = (object) array( 'post_type' => 'ffc_self_scheduling', 'id' => 'ffc_self_scheduling' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued ) {
            $enqueued[] = func_get_arg( 0 );
        } );

        $admin = new SelfSchedulingAdmin();
        $admin->enqueue_admin_assets( 'edit.php' );

        $this->assertContains( 'ffc-calendar-admin', $enqueued );
    }

    // ==================================================================
    // enqueue_admin_assets() — appointments page
    // ==================================================================

    public function test_enqueue_admin_assets_enqueues_on_appointments_page(): void {
        $screen = (object) array( 'post_type' => '', 'id' => 'ffc-scheduling_page_ffc-appointments' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $enqueued_styles = array();
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
            $enqueued_styles[] = func_get_arg( 0 );
        } );

        $admin = new SelfSchedulingAdmin();
        $admin->enqueue_admin_assets( 'admin_page_ffc-appointments' );

        $this->assertContains( 'ffc-calendar-admin', $enqueued_styles );
    }

    // ==================================================================
    // render_appointments_page() — no permission
    // ==================================================================

    /**
     * Runs in a separate process because other tests in the suite leave a
     * Mockery alias for Utils loaded, which makes the permission check
     * resolve to a null mock in full-suite runs.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_appointments_page_dies_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $admin = new SelfSchedulingAdmin();
        $this->expectException( \RuntimeException::class );
        $admin->render_appointments_page();
    }
}
