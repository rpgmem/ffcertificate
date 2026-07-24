<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\Admin;

/**
 * @covers \FreeFormCertificate\Admin\Admin
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminClassTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** Sentinel thrown in place of exit() after a redirect. */
    private const REDIRECTED = 'FFC_TEST_REDIRECTED';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Admin\\Admin' );

        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->alias(
            function ( $single, $plural, $number ) {
                return 1 === (int) $number ? $single : $plural;
            }
        );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/edit.php' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'remove_query_arg' )->justReturn( '/' );
        Functions\when( 'add_query_arg' )->justReturn( '/?msg=test' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_the_title' )->justReturn( 'Target Form' );
        Functions\when( 'current_user_can' )->justReturn( true );

        // exit() cannot be intercepted; halt the flow at the redirect instead.
        Functions\when( 'wp_safe_redirect' )->alias(
            function () {
                throw new \RuntimeException( self::REDIRECTED );
            }
        );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
    }

    protected function tearDown(): void {
        unset(
            $_GET['page'], $_GET['action'], $_GET['action2'], $_GET['submission_id'],
            $_GET['submission'], $_GET['_wpnonce'], $_GET['ffc_migration'], $_GET['post_type'],
            $_GET['status'], $_GET['filter_form_id'], $_GET['move_to_form_id'],
            $_GET['msg'], $_GET['moved'], $_GET['conflicts'], $_GET['to_form'],
            $_GET['conflict_id'], $_GET['migrated'], $_GET['migration_name'], $_GET['error_msg']
        );
        unset( $_POST['ffc_action'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array{0: Admin, 1: \Mockery\MockInterface}
     */
    private function makeAdmin(): array {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'register_ajax_hooks' )->byDefault();
        $handler->shouldReceive( 'trash_submission' )->byDefault();
        $handler->shouldReceive( 'restore_submission' )->byDefault();
        $handler->shouldReceive( 'delete_submission' )->byDefault();
        $handler->shouldReceive( 'bulk_trash_submissions' )->byDefault();
        $handler->shouldReceive( 'bulk_restore_submissions' )->byDefault();
        $handler->shouldReceive( 'bulk_delete_submissions' )->byDefault();
        $exporter = Mockery::mock( 'FreeFormCertificate\Admin\CsvExporter' );
        $exporter->shouldReceive( 'register_source' )->byDefault();
        return array( new Admin( $handler, $exporter ), $handler );
    }

    /** Invoke a private/protected method. */
    private function call( Admin $admin, string $method, array $args = array() ) {
        $ref = new \ReflectionMethod( Admin::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $admin, $args );
    }

    /** Replace a private property with a test double. */
    private function inject( Admin $admin, string $prop, $value ): void {
        $ref = new \ReflectionProperty( Admin::class, $prop );
        $ref->setAccessible( true );
        $ref->setValue( $admin, $value );
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        list( $admin ) = $this->makeAdmin();
        $this->assertInstanceOf( Admin::class, $admin );
    }

    public function test_constructor_registers_admin_menu_hook(): void {
        $hooks = array();
        Functions\when( 'add_action' )->alias(
            function ( $hook ) use ( &$hooks ) {
                $hooks[] = $hook;
                return true;
            }
        );
        $this->makeAdmin();
        $this->assertContains( 'admin_menu', $hooks );
        $this->assertContains( 'admin_head', $hooks );
        $this->assertContains( 'admin_init', $hooks );
    }

    // ==================================================================
    // register_admin_menu()
    // ==================================================================

    public function test_register_admin_menu_registers_submissions_submenu(): void {
        list( $admin ) = $this->makeAdmin();

        $captured = array();
        Functions\when( 'add_submenu_page' )->alias(
            function ( $parent, $page_title, $menu_title, $cap, $slug ) use ( &$captured ) {
                $captured[] = array( $parent, $cap, $slug );
                return 'hook';
            }
        );

        $activity = Mockery::mock();
        $activity->shouldReceive( 'register_menu' )->once();
        $this->inject( $admin, 'activity_log_page', $activity );

        $admin->register_admin_menu();

        $this->assertSame( 'edit.php?post_type=ffc_form', $captured[0][0] );
        $this->assertSame( 'ffc_view_certificates', $captured[0][1] );
        $this->assertSame( 'ffc-submissions', $captured[0][2] );
    }

    // ==================================================================
    // configure_tinymce_placeholders()
    // ==================================================================

    public function test_configure_tinymce_placeholders_sets_noneditable(): void {
        list( $admin ) = $this->makeAdmin();
        $result = $admin->configure_tinymce_placeholders( array() );

        $this->assertSame( '/{{[^}]+}}/g', $result['noneditable_regexp'] );
        $this->assertSame( 'ffc-placeholder', $result['noneditable_class'] );
        $this->assertSame( 'raw', $result['entity_encoding'] );
        $this->assertContains( '/{{[^}]+}}/g', $result['protect'] );
    }

    public function test_configure_tinymce_preserves_existing_extended_elements(): void {
        list( $admin ) = $this->makeAdmin();
        $result = $admin->configure_tinymce_placeholders( array( 'extended_valid_elements' => 'div[class]' ) );
        $this->assertSame( 'div[class]', $result['extended_valid_elements'] );
    }

    public function test_configure_tinymce_appends_to_existing_protect_array(): void {
        list( $admin ) = $this->makeAdmin();
        $result = $admin->configure_tinymce_placeholders( array( 'protect' => array( '/foo/' ) ) );
        $this->assertContains( '/foo/', $result['protect'] );
        $this->assertContains( '/{{[^}]+}}/g', $result['protect'] );
    }

    public function test_configure_tinymce_leaves_non_array_protect_untouched(): void {
        list( $admin ) = $this->makeAdmin();
        $result = $admin->configure_tinymce_placeholders( array( 'protect' => 'scalar' ) );
        $this->assertSame( 'scalar', $result['protect'] );
    }

    // ==================================================================
    // maybe_register_tinymce_placeholder_filter()
    // ==================================================================

    public function test_maybe_register_tinymce_filter_skips_other_post_types(): void {
        $screen = new \stdClass();
        $screen->post_type = 'post';
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        list( $admin ) = $this->makeAdmin();
        $added = false;
        Functions\when( 'add_filter' )->alias( function () use ( &$added ) { $added = true; return true; } );

        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertFalse( $added );
    }

    public function test_maybe_register_tinymce_filter_registers_on_ffc_form_screen(): void {
        $screen = new \stdClass();
        $screen->post_type = 'ffc_form';
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        list( $admin ) = $this->makeAdmin();
        $captured = null;
        Functions\when( 'add_filter' )->alias( function ( $hook ) use ( &$captured ) { $captured = $hook; return true; } );

        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertSame( 'tiny_mce_before_init', $captured );
    }

    public function test_maybe_register_tinymce_filter_handles_null_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( null );
        list( $admin ) = $this->makeAdmin();
        $added = false;
        Functions\when( 'add_filter' )->alias( function () use ( &$added ) { $added = true; return true; } );
        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertFalse( $added );
    }

    public function test_maybe_register_tinymce_filter_handles_missing_function(): void {
        // get_current_screen left undefined -> function_exists() guard returns.
        list( $admin ) = $this->makeAdmin();
        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertTrue( true );
    }

    // ==================================================================
    // display_submissions_page() dispatch
    // ==================================================================

    public function test_display_submissions_page_dispatches_to_edit_render(): void {
        list( $admin ) = $this->makeAdmin();
        $_GET['action'] = 'edit';
        $_GET['submission_id'] = '42';

        $edit = Mockery::mock();
        $edit->shouldReceive( 'render' )->once()->with( 42 );
        $this->inject( $admin, 'edit_page', $edit );

        $admin->display_submissions_page();
        $this->assertTrue( true );
    }

    public function test_display_submissions_page_dispatches_to_list_render(): void {
        list( $admin ) = $this->makeAdmin();
        // No action -> default 'list' -> render_list_page news up SubmissionsList.
        $list = Mockery::mock( 'overload:FreeFormCertificate\Admin\SubmissionsList' );
        $list->shouldReceive( 'prepare_items' )->andReturnNull();
        $list->shouldReceive( 'views' )->andReturnNull();
        $list->shouldReceive( 'search_box' )->andReturnNull();
        $list->shouldReceive( 'display' )->andReturnNull();

        ob_start();
        $admin->display_submissions_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'wp-heading-inline', $html );
        $this->assertStringContainsString( 'ffc-csv-export-btn', $html );
    }

    // ==================================================================
    // render_list_page() filter/button branches (via display)
    // ==================================================================

    public function test_render_list_page_export_all_button_default(): void {
        list( $admin ) = $this->makeAdmin();
        $list = Mockery::mock( 'overload:FreeFormCertificate\Admin\SubmissionsList' );
        $list->shouldReceive( 'prepare_items' )->andReturnNull();
        $list->shouldReceive( 'views' )->andReturnNull();
        $list->shouldReceive( 'search_box' )->andReturnNull();
        $list->shouldReceive( 'display' )->andReturnNull();

        ob_start();
        $admin->display_submissions_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Export All CSV', $html );
        $this->assertStringContainsString( 'class="button"', $html );
    }

    public function test_render_list_page_export_filtered_button_with_filters(): void {
        list( $admin ) = $this->makeAdmin();
        $_GET['status'] = 'trash';
        $_GET['filter_form_id'] = array( '7', '9' );

        $list = Mockery::mock( 'overload:FreeFormCertificate\Admin\SubmissionsList' );
        $list->shouldReceive( 'prepare_items' )->andReturnNull();
        $list->shouldReceive( 'views' )->andReturnNull();
        $list->shouldReceive( 'search_box' )->andReturnNull();
        $list->shouldReceive( 'display' )->andReturnNull();

        ob_start();
        $admin->display_submissions_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Export Filtered CSV', $html );
        $this->assertStringContainsString( 'button-primary', $html );
        $this->assertStringContainsString( 'data-status="trash"', $html );
    }

    public function test_render_list_page_handles_scalar_filter_form_id(): void {
        list( $admin ) = $this->makeAdmin();
        $_GET['filter_form_id'] = '5';

        $list = Mockery::mock( 'overload:FreeFormCertificate\Admin\SubmissionsList' );
        $list->shouldReceive( 'prepare_items' )->andReturnNull();
        $list->shouldReceive( 'views' )->andReturnNull();
        $list->shouldReceive( 'search_box' )->andReturnNull();
        $list->shouldReceive( 'display' )->andReturnNull();

        ob_start();
        $admin->display_submissions_page();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Export Filtered CSV', $html );
        $this->assertStringContainsString( '[5]', $html );
    }

    // ==================================================================
    // render_edit_page()
    // ==================================================================

    public function test_render_edit_page_delegates_with_submission_id(): void {
        list( $admin ) = $this->makeAdmin();
        $_GET['submission_id'] = '99';
        $edit = Mockery::mock();
        $edit->shouldReceive( 'render' )->once()->with( 99 );
        $this->inject( $admin, 'edit_page', $edit );
        $this->call( $admin, 'render_edit_page' );
        $this->assertTrue( true );
    }

    public function test_render_edit_page_defaults_id_to_zero(): void {
        list( $admin ) = $this->makeAdmin();
        $edit = Mockery::mock();
        $edit->shouldReceive( 'render' )->once()->with( 0 );
        $this->inject( $admin, 'edit_page', $edit );
        $this->call( $admin, 'render_edit_page' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_submission_edit_save()
    // ==================================================================

    public function test_handle_submission_edit_save_delegates(): void {
        list( $admin ) = $this->makeAdmin();
        $edit = Mockery::mock();
        $edit->shouldReceive( 'handle_save' )->once();
        $this->inject( $admin, 'edit_page', $edit );
        $admin->handle_submission_edit_save();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_submission_actions()
    // ==================================================================

    public function test_handle_submission_actions_returns_early_on_wrong_page(): void {
        $_GET['page'] = 'other-page';
        list( $admin ) = $this->makeAdmin();
        $admin->handle_submission_actions();
        $this->assertTrue( true );
    }

    public function test_handle_submission_actions_returns_when_lacking_manage_cap(): void {
        $_GET['page'] = 'ffc-submissions';
        Functions\when( 'current_user_can' )->justReturn( false );
        list( $admin ) = $this->makeAdmin();
        $admin->handle_submission_actions();
        $this->assertTrue( true );
    }

    public function test_handle_submission_actions_trash(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['submission_id'] = '12';
        $_GET['action'] = 'trash';
        $_GET['_wpnonce'] = 'n';
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'trash_submission' )->once()->with( 12 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( self::REDIRECTED );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_restore(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['submission_id'] = '13';
        $_GET['action'] = 'restore';
        $_GET['_wpnonce'] = 'n';
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'restore_submission' )->once()->with( 13 );
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_delete_with_cap(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['submission_id'] = '14';
        $_GET['action'] = 'delete';
        $_GET['_wpnonce'] = 'n';
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'delete_submission' )->once()->with( 14 );
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_delete_without_delete_cap_dies(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['submission_id'] = '15';
        $_GET['action'] = 'delete';
        $_GET['_wpnonce'] = 'n';
        // manage cap = true, but delete cap = false. Distinguish by the slug.
        Functions\when( 'current_user_can' )->alias(
            function ( $cap ) {
                if ( 'manage_options' === $cap ) {
                    return false;
                }
                return 'ffc_delete_certificates' !== $cap;
            }
        );
        Functions\when( 'wp_die' )->alias( function ( $m ) { throw new \DomainException( (string) $m ); } );
        list( $admin ) = $this->makeAdmin();
        $this->expectException( \DomainException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_skips_invalid_nonce(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['submission_id'] = '16';
        $_GET['action'] = 'trash';
        $_GET['_wpnonce'] = 'bad';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldNotReceive( 'trash_submission' );
        // No redirect because nonce failed -> no exception expected.
        $admin->handle_submission_actions();
        $this->assertTrue( true );
    }

    public function test_handle_submission_actions_bulk_trash(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['action'] = 'bulk_trash';
        $_GET['submission'] = array( '1', '2', '3' );
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'bulk_trash_submissions' )->once()->with( array( 1, 2, 3 ) );
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_bulk_restore(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['action'] = 'bulk_restore';
        $_GET['submission'] = array( '4' );
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'bulk_restore_submissions' )->once();
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_bulk_delete_with_cap(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['action'] = 'bulk_delete';
        $_GET['submission'] = array( '5' );
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'bulk_delete_submissions' )->once();
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_bulk_action2_fallback(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['action'] = '-1';
        $_GET['action2'] = 'bulk_trash';
        $_GET['submission'] = array( '6' );
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'bulk_trash_submissions' )->once();
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    public function test_handle_submission_actions_bulk_move_to_form(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['action'] = 'move_to_form';
        $_GET['submission'] = array( '8' );
        $_GET['filter_form_id'] = array( '3' );
        $_GET['move_to_form_id'] = '4';
        list( $admin, $handler ) = $this->makeAdmin();
        $handler->shouldReceive( 'move_submissions_between_forms' )
            ->once()
            ->with( 3, 4, array( 8 ) )
            ->andReturn( array( 'moved' => array( 8 ), 'conflicts' => array() ) );
        $this->expectException( \RuntimeException::class );
        $admin->handle_submission_actions();
    }

    // ==================================================================
    // handle_bulk_move_to_form() validation branches
    // ==================================================================

    public function test_bulk_move_invalid_when_forms_equal(): void {
        $_GET['filter_form_id'] = array( '3' );
        $_GET['move_to_form_id'] = '3';
        list( $admin ) = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $this->call( $admin, 'handle_bulk_move_to_form', array( array( 1 ) ) );
    }

    public function test_bulk_move_invalid_target_when_not_ffc_form(): void {
        $_GET['filter_form_id'] = '3';
        $_GET['move_to_form_id'] = '4';
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        list( $admin ) = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $this->call( $admin, 'handle_bulk_move_to_form', array( array( 1 ) ) );
    }

    // ==================================================================
    // redirect_with_msg() / redirect_with_extra_args()
    // ==================================================================

    public function test_redirect_with_msg_preserves_page_and_post_type(): void {
        $_GET['page'] = 'ffc-submissions';
        $_GET['post_type'] = 'ffc_form';
        list( $admin ) = $this->makeAdmin();
        $captured = null;
        Functions\when( 'add_query_arg' )->alias(
            function ( $args ) use ( &$captured ) { $captured = $args; return '/x'; }
        );
        try {
            $this->call( $admin, 'redirect_with_msg', array( 'trash' ) );
            $this->fail( 'Expected redirect.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'trash', $captured['msg'] );
            $this->assertSame( 'ffc-submissions', $captured['page'] );
            $this->assertSame( 'ffc_form', $captured['post_type'] );
        }
    }

    public function test_redirect_with_extra_args_includes_counts(): void {
        $_GET['page'] = 'ffc-submissions';
        list( $admin ) = $this->makeAdmin();
        $captured = null;
        Functions\when( 'add_query_arg' )->alias(
            function ( $args ) use ( &$captured ) { $captured = $args; return '/x'; }
        );
        try {
            $this->call(
                $admin,
                'redirect_with_extra_args',
                array( array( 'msg' => 'move_done', 'moved' => 2 ) )
            );
            $this->fail( 'Expected redirect.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'move_done', $captured['msg'] );
            $this->assertSame( 2, $captured['moved'] );
            $this->assertSame( 'ffc-submissions', $captured['page'] );
        }
    }

    // ==================================================================
    // display_admin_notices() (private) — message catalog
    // ==================================================================

    /**
     * @dataProvider notice_msg_provider
     */
    public function test_display_admin_notices_renders_text( string $msg, string $needle ): void {
        $_GET['msg'] = $msg;
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'display_admin_notices' );
        $html = ob_get_clean();
        $this->assertStringContainsString( $needle, $html );
    }

    public function notice_msg_provider(): array {
        return array(
            'trash'   => array( 'trash', 'trash' ),
            'restore' => array( 'restore', 'restored' ),
            'delete'  => array( 'delete', 'deleted' ),
            'bulk'    => array( 'bulk_done', 'Bulk action' ),
            'updated' => array( 'updated', 'updated successfully' ),
            'inv'     => array( 'move_invalid', 'Move failed' ),
            'invt'    => array( 'move_invalid_target', 'does not exist' ),
        );
    }

    public function test_display_admin_notices_returns_without_msg(): void {
        unset( $_GET['msg'] );
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'display_admin_notices' );
        $html = ob_get_clean();
        $this->assertSame( '', $html );
    }

    public function test_display_admin_notices_migration_success(): void {
        $_GET['msg'] = 'migration_success';
        $_GET['migrated'] = '42';
        $_GET['migration_name'] = 'CPF';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'display_admin_notices' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'migrated', $html );
    }

    public function test_display_admin_notices_migration_error(): void {
        $_GET['msg'] = 'migration_error';
        $_GET['error_msg'] = 'boom';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'display_admin_notices' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'error', $html );
        $this->assertStringContainsString( 'boom', $html );
    }

    public function test_display_admin_notices_move_done_delegates(): void {
        $_GET['msg'] = 'move_done';
        $_GET['moved'] = '3';
        $_GET['conflicts'] = '0';
        $_GET['to_form'] = '4';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'display_admin_notices' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'moved', $html );
    }

    // ==================================================================
    // render_move_done_notice() (private)
    // ==================================================================

    public function test_render_move_done_notice_moved_only(): void {
        $_GET['moved'] = '2';
        $_GET['conflicts'] = '0';
        $_GET['to_form'] = '4';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'render_move_done_notice' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'moved', $html );
        $this->assertStringContainsString( 'Target Form', $html );
    }

    public function test_render_move_done_notice_with_conflicts(): void {
        $_GET['moved'] = '1';
        $_GET['conflicts'] = '2';
        $_GET['to_form'] = '4';
        $_GET['conflict_id'] = '10,11';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'render_move_done_notice' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'Conflict IDs', $html );
        $this->assertStringContainsString( '10, 11', $html );
    }

    public function test_render_move_done_notice_nothing_matched(): void {
        $_GET['moved'] = '0';
        $_GET['conflicts'] = '0';
        $_GET['to_form'] = '4';
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'render_move_done_notice' );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'No submissions matched', $html );
    }

    public function test_render_move_done_notice_falls_back_to_form_id_title(): void {
        $_GET['moved'] = '1';
        $_GET['conflicts'] = '0';
        $_GET['to_form'] = '7';
        Functions\when( 'get_the_title' )->justReturn( '' );
        list( $admin ) = $this->makeAdmin();
        ob_start();
        $this->call( $admin, 'render_move_done_notice' );
        $html = ob_get_clean();
        $this->assertStringContainsString( '7', $html );
    }

    // ==================================================================
    // handle_migration_action()
    // ==================================================================

    public function test_handle_migration_action_returns_early_without_param(): void {
        unset( $_GET['ffc_migration'] );
        list( $admin ) = $this->makeAdmin();
        $admin->handle_migration_action();
        $this->assertTrue( true );
    }

    public function test_handle_migration_action_dies_without_permission(): void {
        $_GET['ffc_migration'] = 'test_migration';
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) { throw new \RuntimeException( (string) $msg ); } );
        list( $admin ) = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $admin->handle_migration_action();
    }

    public function test_handle_migration_action_dies_on_invalid_key(): void {
        $_GET['ffc_migration'] = 'nope';
        Functions\when( 'wp_die' )->alias( function ( $msg ) { throw new \DomainException( (string) $msg ); } );

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'get_migration' )->andReturn( null );

        list( $admin ) = $this->makeAdmin();
        $this->expectException( \DomainException::class );
        $admin->handle_migration_action();
    }

    public function test_handle_migration_action_success_redirects(): void {
        $_GET['ffc_migration'] = 'cpf';

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'get_migration' )->andReturn( array( 'name' => 'CPF Migration' ) );
        $mgr->shouldReceive( 'run_migration' )->andReturn( array( 'migrated' => 5 ) );

        list( $admin ) = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( self::REDIRECTED );
        $admin->handle_migration_action();
    }

    public function test_handle_migration_action_error_redirects(): void {
        $_GET['ffc_migration'] = 'cpf';
        Functions\when( 'is_wp_error' )->justReturn( true );

        $err = Mockery::mock();
        $err->shouldReceive( 'get_error_message' )->andReturn( 'failed' );

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'get_migration' )->andReturn( array( 'name' => 'CPF Migration' ) );
        $mgr->shouldReceive( 'run_migration' )->andReturn( $err );

        list( $admin ) = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $admin->handle_migration_action();
    }
}
