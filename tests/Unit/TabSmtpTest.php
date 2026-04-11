<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabSMTP;

/**
 * Tests for TabSMTP: SMTP settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabSMTP
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabSmtpTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabSMTP */
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

        $this->tab = new TabSMTP();
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_smtp(): void {
        $this->assertSame( 'smtp', $this->tab->get_id() );
    }

    public function test_tab_title_is_smtp(): void {
        $this->assertSame( 'SMTP', $this->tab->get_title() );
    }

    public function test_tab_icon_is_email(): void {
        $this->assertSame( 'ffc-icon-email', $this->tab->get_icon() );
    }

    public function test_tab_order_is_20(): void {
        $this->assertSame( 20, $this->tab->get_order() );
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
    // enqueue_scripts() — wrong hook
    // ==================================================================

    public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
        // wp_enqueue_script should never be called for a non-matching hook
        Functions\expect( 'wp_enqueue_script' )->never();

        $this->tab->enqueue_scripts( 'edit.php' );
    }

    // ==================================================================
    // enqueue_scripts() — correct hook but different tab
    // ==================================================================

    public function test_enqueue_scripts_returns_early_when_tab_is_not_smtp(): void {
        $_GET['tab'] = 'general';
        Functions\when( 'wp_unslash' )->returnArg();

        Functions\expect( 'wp_enqueue_script' )->never();

        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
    }

    // ==================================================================
    // enqueue_scripts() — correct hook and correct tab
    // ==================================================================

    public function test_enqueue_scripts_enqueues_script_when_tab_is_smtp(): void {
        $_GET['tab'] = 'smtp';
        Functions\when( 'wp_unslash' )->returnArg();

        // Mock Utils::asset_suffix()
        $utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utils_mock->shouldReceive( 'asset_suffix' )
            ->once()
            ->andReturn( '.min' );

        Functions\expect( 'wp_enqueue_script' )
            ->once()
            ->with(
                'ffc-smtp-settings',
                Mockery::type( 'string' ),
                array( 'jquery' ),
                Mockery::type( 'string' ),
                true
            );

        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
    }

    // ==================================================================
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        $tab = new class() extends TabSMTP {
            protected function init(): void {
                $this->tab_id = 'smtp';
                $this->tab_title = 'SMTP';
                $this->tab_icon = 'ffc-icon-email';
                $this->tab_order = 20;
            }
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-smtp.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'SMTP settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_smtp_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-smtp.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "smtp-rendered"; ?>' );

        $tab = new class( $tmp_dir ) extends TabSMTP {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            protected function init(): void {
                $this->tab_id = 'smtp';
                $this->tab_title = 'SMTP';
                $this->tab_icon = 'ffc-icon-email';
                $this->tab_order = 20;
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-smtp.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'smtp-rendered', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_value_from_ffc_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'smtp_host' => 'mail.example.com' ) );

        $this->assertSame( 'mail.example.com', $this->tab->get_option( 'smtp_host' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'wp', $this->tab->get_option( 'smtp_mode', 'wp' ) );
    }
}
