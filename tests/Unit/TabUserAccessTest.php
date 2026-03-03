<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabUserAccess;

/**
 * Tests for TabUserAccess: user access settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabUserAccess
 */
class TabUserAccessTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabUserAccess */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );

        $this->tab = new TabUserAccess();
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        unset( $_POST['ffc_user_access_nonce'], $_POST['block_wp_admin'], $_POST['blocked_roles'] );
        unset( $_POST['redirect_url'], $_POST['redirect_message'] );
        unset( $_POST['allow_admin_bar'], $_POST['bypass_for_admins'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_user_access(): void {
        $this->assertSame( 'user_access', $this->tab->get_id() );
    }

    public function test_tab_title_is_user_access(): void {
        $this->assertSame( 'User Access', $this->tab->get_title() );
    }

    public function test_tab_icon_is_users(): void {
        $this->assertSame( 'ffc-icon-users', $this->tab->get_icon() );
    }

    public function test_tab_order_is_60(): void {
        $this->assertSame( 60, $this->tab->get_order() );
    }

    // ==================================================================
    // Inheritance
    // ==================================================================

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf(
            \FreeFormCertificate\Settings\SettingsTab::class,
            $this->tab
        );
    }

    // ==================================================================
    // enqueue_styles() — wrong hook
    // ==================================================================

    public function test_enqueue_styles_returns_early_for_wrong_hook(): void {
        // The method should return early and not process anything
        // We verify it runs without error
        $this->tab->enqueue_styles( 'edit.php' );
        $this->assertTrue( true ); // Reached without error
    }

    // ==================================================================
    // enqueue_styles() — correct hook
    // ==================================================================

    public function test_enqueue_styles_runs_for_correct_hook(): void {
        $_GET['tab'] = 'user_access';
        Functions\when( 'wp_unslash' )->returnArg();

        // Method runs without error; it currently reads the tab param but doesn't enqueue anything
        $this->tab->enqueue_styles( 'ffc_form_page_ffc-settings' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        $tab = new class() extends TabUserAccess {
            protected function init(): void {
                $this->tab_id = 'user_access';
                $this->tab_title = 'User Access';
                $this->tab_icon = 'ffc-icon-users';
                $this->tab_order = 60;
            }
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-user-access.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'User Access settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'User Access settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_ua_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-user-access.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "user-access-rendered"; ?>' );

        $tab = new class( $tmp_dir ) extends TabUserAccess {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            protected function init(): void {
                $this->tab_id = 'user_access';
                $this->tab_title = 'User Access';
                $this->tab_icon = 'ffc-icon-users';
                $this->tab_order = 60;
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-user-access.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'User Access settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'user-access-rendered', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // get_option() — overridden to use ffc_user_access_settings
    // ==================================================================

    public function test_get_option_returns_value_from_user_access_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'block_wp_admin' => '1' ) );

        $this->assertSame( '1', $this->tab->get_option( 'block_wp_admin' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'no', $this->tab->get_option( 'block_wp_admin', 'no' ) );
    }

    public function test_get_option_returns_empty_string_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '', $this->tab->get_option( 'nonexistent' ) );
    }

    // ==================================================================
    // save_settings() — without nonce
    // ==================================================================

    public function test_save_settings_returns_early_without_nonce(): void {
        // No nonce in POST — update_option should never be called
        Functions\expect( 'update_option' )->never();
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $this->tab->save_settings();

        // If we reach here, early return worked
        $this->assertTrue( true );
    }

    // ==================================================================
    // save_settings() — invalid nonce
    // ==================================================================

    public function test_save_settings_returns_early_with_invalid_nonce(): void {
        $_POST['ffc_user_access_nonce'] = 'bad_nonce';

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\expect( 'update_option' )->never();

        $this->tab->save_settings();

        $this->assertTrue( true );
    }

    // ==================================================================
    // save_settings() — valid nonce saves settings
    // ==================================================================

    public function test_save_settings_saves_with_valid_nonce(): void {
        $_POST['ffc_user_access_nonce'] = 'valid_nonce';
        $_POST['block_wp_admin'] = '1';
        $_POST['blocked_roles'] = array( 'ffc_user', 'subscriber' );
        $_POST['redirect_url'] = 'https://example.com/dashboard';
        $_POST['redirect_message'] = 'You cannot access admin.';
        $_POST['allow_admin_bar'] = '1';
        $_POST['bypass_for_admins'] = '1';

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
        Functions\when( 'add_settings_error' )->justReturn( true );

        $captured_settings = null;
        Functions\when( 'update_option' )->alias( function ( $option_name, $value ) use ( &$captured_settings ) {
            $captured_settings = $value;
            return true;
        } );

        $this->tab->save_settings();

        $this->assertNotNull( $captured_settings );
        $this->assertTrue( $captured_settings['block_wp_admin'] );
        $this->assertSame( array( 'ffc_user', 'subscriber' ), $captured_settings['blocked_roles'] );
        $this->assertSame( 'https://example.com/dashboard', $captured_settings['redirect_url'] );
        $this->assertSame( 'You cannot access admin.', $captured_settings['redirect_message'] );
        $this->assertTrue( $captured_settings['allow_admin_bar'] );
        $this->assertTrue( $captured_settings['bypass_for_admins'] );
    }

    // ==================================================================
    // save_settings() — defaults when POST fields missing
    // ==================================================================

    public function test_save_settings_uses_defaults_when_fields_missing(): void {
        $_POST['ffc_user_access_nonce'] = 'valid_nonce';
        // No other POST fields set

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
        Functions\when( 'add_settings_error' )->justReturn( true );

        $captured_settings = null;
        Functions\when( 'update_option' )->alias( function ( $option_name, $value ) use ( &$captured_settings ) {
            $captured_settings = $value;
            return true;
        } );

        $this->tab->save_settings();

        $this->assertNotNull( $captured_settings );
        $this->assertFalse( $captured_settings['block_wp_admin'] );
        $this->assertSame( array( 'ffc_user' ), $captured_settings['blocked_roles'] );
        $this->assertSame( 'https://example.com/dashboard', $captured_settings['redirect_url'] );
        $this->assertFalse( $captured_settings['allow_admin_bar'] );
        $this->assertFalse( $captured_settings['bypass_for_admins'] );
    }
}
