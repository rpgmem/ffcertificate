<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdmin
 */
class ReregistrationAdminTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array{type: string, data: mixed}> */
    private array $json_responses = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_menu_page' )->justReturn( 'hook' );
        Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . $path;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
        if ( ! defined( 'FFC_HTML2CANVAS_VERSION' ) ) {
            define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );
        }
        if ( ! defined( 'FFC_JSPDF_VERSION' ) ) {
            define( 'FFC_JSPDF_VERSION', '2.5.1' );
        }

        $this->json_responses = array();
        $responses = &$this->json_responses;

        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'success', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_success' );
        } );
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'error', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_error' );
        } );
    }

    protected function tearDown(): void {
        unset( $_GET['page'], $_GET['view'], $_GET['id'], $_GET['message'], $_GET['action'], $_GET['_wpnonce'] );
        unset( $_POST['ffc_action'], $_POST['audience_ids'], $_POST['reregistration_id'], $_POST['rereg_status'], $_POST['rereg_audience_ids'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constants
    // ==================================================================

    public function test_menu_slug_constant(): void {
        $this->assertSame( 'ffc-reregistration', ReregistrationAdmin::MENU_SLUG );
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_hooks(): void {
        $admin = new ReregistrationAdmin();
        $admin->init();
        $this->assertInstanceOf( ReregistrationAdmin::class, $admin );
    }

    // ==================================================================
    // add_menu()
    // ==================================================================

    public function test_add_menu_registers_menu_pages(): void {
        $admin = new ReregistrationAdmin();
        $admin->add_menu();
        // If we got here without error, the menus were registered
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — wrong page
    // ==================================================================

    public function test_enqueue_assets_returns_early_on_wrong_hook(): void {
        $enqueued = array();
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) {
            $enqueued[] = func_get_arg( 0 );
        } );

        $admin = new ReregistrationAdmin();
        $admin->enqueue_assets( 'edit.php' );

        $this->assertEmpty( $enqueued );
    }

    // ==================================================================
    // enqueue_assets() — correct page
    // ==================================================================

    public function test_enqueue_assets_enqueues_on_correct_hook(): void {
        $enqueued_styles = array();
        $enqueued_scripts = array();
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
            $enqueued_styles[] = func_get_arg( 0 );
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
            $enqueued_scripts[] = func_get_arg( 0 );
        } );

        $admin = new ReregistrationAdmin();
        $admin->enqueue_assets( 'toplevel_page_ffc-reregistration' );

        $this->assertContains( 'ffc-reregistration-admin', $enqueued_styles );
        $this->assertContains( 'ffc-reregistration-admin', $enqueued_scripts );
    }

    // ==================================================================
    // enqueue_assets() — submissions view enqueues PDF libs
    // ==================================================================

    public function test_enqueue_assets_loads_pdf_libs_on_submissions_view(): void {
        $_GET['view'] = 'submissions';

        $enqueued_scripts = array();
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
            $enqueued_scripts[] = func_get_arg( 0 );
        } );

        $admin = new ReregistrationAdmin();
        $admin->enqueue_assets( 'toplevel_page_ffc-reregistration' );

        $this->assertContains( 'html2canvas', $enqueued_scripts );
        $this->assertContains( 'jspdf', $enqueued_scripts );
        $this->assertContains( 'ffc-pdf-generator', $enqueued_scripts );
    }

    // ==================================================================
    // render_page() — permission denied
    // ==================================================================

    /**
     * Runs in a separate process because other tests in the suite leak a
     * Mockery alias for current_user_can through Brain/Monkey's global state,
     * causing the local `when()` stub to be ignored here.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_page_dies_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $admin = new ReregistrationAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Permission denied.' );
        $admin->render_page();
    }

    // ==================================================================
    // handle_actions() — returns early without capability
    // ==================================================================

    public function test_handle_actions_returns_early_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $admin = new ReregistrationAdmin();
        $admin->handle_actions();
        $this->assertTrue( true ); // No error means early return
    }

    // ==================================================================
    // handle_actions() — returns early on wrong page
    // ==================================================================

    public function test_handle_actions_returns_early_on_wrong_page(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page'] = 'other-page';

        $admin = new ReregistrationAdmin();
        $admin->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — shows message from query string
    // ==================================================================

    public function test_handle_actions_shows_message_from_query_string(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $_GET['page'] = 'ffc-reregistration';
        $_GET['message'] = 'created';

        $settings_errors = array();
        Functions\when( 'add_settings_error' )->alias( function ( $setting, $code, $message, $type ) use ( &$settings_errors ) {
            $settings_errors[] = array( 'setting' => $setting, 'type' => $type, 'message' => $message );
        } );

        $admin = new ReregistrationAdmin();
        $admin->handle_actions();

        $this->assertCount( 1, $settings_errors );
        $this->assertSame( 'success', $settings_errors[0]['type'] );
    }

    // ==================================================================
    // ajax_generate_ficha() — permission denied
    // ==================================================================

    public function test_ajax_generate_ficha_returns_error_without_capability(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $admin = new ReregistrationAdmin();
        try {
            $admin->ajax_generate_ficha();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
        $this->assertStringContainsString( 'Permission denied', $this->json_responses[0]['data']['message'] );
    }

    // ==================================================================
    // ajax_generate_ficha() — missing submission ID
    // ==================================================================

    public function test_ajax_generate_ficha_returns_error_for_missing_id(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $_POST['submission_id'] = 0;

        $admin = new ReregistrationAdmin();
        try {
            $admin->ajax_generate_ficha();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
        $this->assertStringContainsString( 'Invalid submission', $this->json_responses[0]['data']['message'] );
    }

    // ==================================================================
    // ajax_count_members() — permission denied
    // ==================================================================

    public function test_ajax_count_members_returns_error_without_capability(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $admin = new ReregistrationAdmin();
        try {
            $admin->ajax_count_members();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'error', $this->json_responses[0]['type'] );
    }

    // ==================================================================
    // ajax_count_members() — empty audience IDs
    // ==================================================================

    public function test_ajax_count_members_returns_zero_for_empty_audiences(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $_POST['audience_ids'] = array();

        $admin = new ReregistrationAdmin();
        try {
            $admin->ajax_count_members();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertSame( 'success', $this->json_responses[0]['type'] );
        $this->assertSame( 0, $this->json_responses[0]['data']['count'] );
    }

    // ==================================================================
    // handle_save() / handle_delete()
    // ==================================================================

    private function invoke_private( ReregistrationAdmin $admin, string $method, array $args = array() ) {
        $ref = new \ReflectionMethod( ReregistrationAdmin::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $admin, $args );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_save_creates_active_campaign_and_redirects(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        $_POST['ffc_action']         = 'save_reregistration';
        $_POST['reregistration_id']  = 0;
        $_POST['rereg_status']       = 'active';
        $_POST['rereg_audience_ids'] = array( '3', '4' );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_post_string' )->andReturnUsing(
                function ( $key, $default = '' ) {
                    $map = array(
                        'rereg_title'      => 'My Campaign',
                        'rereg_start_date' => '2026-01-01',
                        'rereg_end_date'   => '2026-12-31',
                        'rereg_status'     => 'active',
                    );
                    return $map[ $key ] ?? $default;
                }
            );

        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
            ->shouldReceive( 'create' )->once()->andReturn( 55 )
            ->shouldReceive( 'set_audience_ids' )->once()->with( 55, array( 3, 4 ) );

        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository' )
            ->shouldReceive( 'create_for_audience_members' )->once()->with( 55, array( 3, 4 ) );

        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )
            ->shouldReceive( 'send_invitations' )->once()->with( 55 )->andReturn( 0 );

        $admin = new ReregistrationAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->invoke_private( $admin, 'handle_save' );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_save_updates_existing_draft_campaign(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        $_POST['ffc_action']        = 'save_reregistration';
        $_POST['reregistration_id'] = 9;

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_post_string' )->andReturnUsing(
                function ( $key, $default = '' ) {
                    $map = array(
                        'rereg_title'      => 'Updated',
                        'rereg_start_date' => '2026-01-01',
                        'rereg_end_date'   => '2026-12-31',
                        'rereg_status'     => 'draft',
                    );
                    return $map[ $key ] ?? $default;
                }
            );

        // Existing campaign already draft → no membership/invitation side effects.
        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
            ->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'draft' ) )
            ->shouldReceive( 'update' )->once()->with( 9, Mockery::type( 'array' ) )
            ->shouldReceive( 'set_audience_ids' )->once()->with( 9, array() );

        $admin = new ReregistrationAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->invoke_private( $admin, 'handle_save' );
    }

    public function test_handle_save_returns_early_without_action(): void {
        unset( $_POST['ffc_action'] );
        $admin = new ReregistrationAdmin();
        $this->invoke_private( $admin, 'handle_save' );
        $this->assertTrue( true );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_save_returns_early_on_bad_nonce(): void {
        $_POST['ffc_action'] = 'save_reregistration';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_post_string' )->andReturn( '' );

        $admin = new ReregistrationAdmin();
        $this->invoke_private( $admin, 'handle_save' );
        $this->assertTrue( true );
    }

    public function test_handle_delete_returns_early_without_action(): void {
        unset( $_GET['action'] );
        $admin = new ReregistrationAdmin();
        $this->invoke_private( $admin, 'handle_delete' );
        $this->assertTrue( true );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_delete_dies_without_delete_cap(): void {
        $_GET['action'] = 'delete';
        $_GET['id']     = '5';
        Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )->with( 'ffc_delete_reregistration' )->andReturn( false );

        $admin = new ReregistrationAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'permission to delete' );
        $this->invoke_private( $admin, 'handle_delete' );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_delete_deletes_and_redirects(): void {
        $_GET['action']   = 'delete';
        $_GET['id']       = '5';
        $_GET['_wpnonce'] = 'good';
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'good' );

        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
            ->shouldReceive( 'delete' )->once()->with( 5 );

        $admin = new ReregistrationAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $this->invoke_private( $admin, 'handle_delete' );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_delete_returns_early_on_bad_nonce(): void {
        $_GET['action']   = 'delete';
        $_GET['id']       = '5';
        $_GET['_wpnonce'] = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'bad' );

        Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
            ->shouldReceive( 'delete' )->never();

        $admin = new ReregistrationAdmin();
        $this->invoke_private( $admin, 'handle_delete' );
        $this->assertTrue( true );
    }
}
