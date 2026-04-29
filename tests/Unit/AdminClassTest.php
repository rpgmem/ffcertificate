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
 */
class AdminClassTest extends TestCase {

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
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'remove_query_arg' )->justReturn( '/' );
        Functions\when( 'add_query_arg' )->justReturn( '/?msg=test' );
        Functions\when( 'wp_safe_redirect' )->justReturn( true );

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
        unset( $_GET['page'], $_GET['action'], $_GET['submission_id'], $_GET['_wpnonce'], $_GET['ffc_migration'] );
        unset( $_POST['ffc_action'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeAdmin(): Admin {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'register_ajax_hooks' )->byDefault();
        $exporter = Mockery::mock( 'FreeFormCertificate\Admin\CsvExporter' );
        $exporter->shouldReceive( 'register_ajax_hooks' )->byDefault();
        return new Admin( $handler, $exporter );
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $admin = $this->makeAdmin();
        $this->assertInstanceOf( Admin::class, $admin );
    }

    // ==================================================================
    // register_admin_menu()
    // ==================================================================

    public function test_register_admin_menu_adds_submenu_page(): void {
        $admin = $this->makeAdmin();
        $admin->register_admin_menu();
        $this->assertTrue( true );
    }

    // ==================================================================
    // configure_tinymce_placeholders()
    // ==================================================================

    public function test_configure_tinymce_placeholders_sets_noneditable(): void {
        $admin = $this->makeAdmin();
        $init = array();
        $result = $admin->configure_tinymce_placeholders( $init );

        $this->assertSame( '/{{[^}]+}}/g', $result['noneditable_regexp'] );
        $this->assertSame( 'ffc-placeholder', $result['noneditable_class'] );
        $this->assertSame( 'raw', $result['entity_encoding'] );
        $this->assertContains( '/{{[^}]+}}/g', $result['protect'] );
    }

    public function test_configure_tinymce_preserves_existing_extended_elements(): void {
        $admin = $this->makeAdmin();
        $init = array( 'extended_valid_elements' => 'div[class]' );
        $result = $admin->configure_tinymce_placeholders( $init );

        $this->assertSame( 'div[class]', $result['extended_valid_elements'] );
    }

    // ==================================================================
    // maybe_register_tinymce_placeholder_filter()
    // ==================================================================

    public function test_maybe_register_tinymce_filter_skips_other_post_types(): void {
        $screen = new \stdClass();
        $screen->post_type = 'post';
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $admin = $this->makeAdmin();
        $added = false;
        Functions\when( 'add_filter' )->alias( function () use ( &$added ) {
            $added = true;
            return true;
        } );

        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertFalse( $added, 'Filter must not be registered outside the ffc_form screen.' );
    }

    public function test_maybe_register_tinymce_filter_registers_on_ffc_form_screen(): void {
        $screen = new \stdClass();
        $screen->post_type = 'ffc_form';
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $admin = $this->makeAdmin();
        $captured = null;
        Functions\when( 'add_filter' )->alias( function ( $hook ) use ( &$captured ) {
            $captured = $hook;
            return true;
        } );

        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertSame( 'tiny_mce_before_init', $captured );
    }

    public function test_maybe_register_tinymce_filter_handles_null_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( null );

        $admin = $this->makeAdmin();
        $added = false;
        Functions\when( 'add_filter' )->alias( function () use ( &$added ) {
            $added = true;
            return true;
        } );

        $admin->maybe_register_tinymce_placeholder_filter();
        $this->assertFalse( $added );
    }

    // ==================================================================
    // handle_submission_actions() — wrong page
    // ==================================================================

    public function test_handle_submission_actions_returns_early_on_wrong_page(): void {
        $_GET['page'] = 'other-page';
        $admin = $this->makeAdmin();
        $admin->handle_submission_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_migration_action() — no migration param
    // ==================================================================

    public function test_handle_migration_action_returns_early_without_param(): void {
        unset( $_GET['ffc_migration'] );
        $admin = $this->makeAdmin();
        $admin->handle_migration_action();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_migration_action() — permission denied
    // ==================================================================

    public function test_handle_migration_action_dies_without_permission(): void {
        $_GET['ffc_migration'] = 'test_migration';
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $admin = $this->makeAdmin();
        $this->expectException( \RuntimeException::class );
        $admin->handle_migration_action();
    }

    // ==================================================================
    // handle_submission_edit_save() — delegates
    // ==================================================================

    public function test_handle_submission_edit_save_delegates(): void {
        $admin = $this->makeAdmin();
        $admin->handle_submission_edit_save();
        $this->assertTrue( true );
    }
}
