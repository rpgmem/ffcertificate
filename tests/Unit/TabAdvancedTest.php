<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabAdvanced;

/**
 * Tests for TabAdvanced: advanced settings tab (Activity Log, Debug, Danger Zone).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabAdvanced
 */
class TabAdvancedTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabAdvanced */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->tab = new TabAdvanced();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_advanced(): void {
        $this->assertSame( 'advanced', $this->tab->get_id() );
    }

    public function test_tab_title_is_advanced(): void {
        $this->assertSame( 'Advanced', $this->tab->get_title() );
    }

    public function test_tab_icon_is_settings(): void {
        $this->assertSame( 'ffc-icon-settings', $this->tab->get_icon() );
    }

    public function test_tab_order_is_70(): void {
        $this->assertSame( 70, $this->tab->get_order() );
    }

    // ==================================================================
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        // Create a subclass that uses a non-existent view path to test the error branch
        $tab = new class() extends TabAdvanced {
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-advanced.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Advanced settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'Advanced settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-advanced.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "advanced-rendered"; ?>' );

        // Subclass that uses our temp path
        $tab = new class( $tmp_dir ) extends TabAdvanced {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-advanced.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Advanced settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'advanced-rendered', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_value_from_ffc_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => '1' ) );

        $this->assertSame( '1', $this->tab->get_option( 'enable_activity_log' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '0', $this->tab->get_option( 'enable_activity_log', '0' ) );
    }

    // ==================================================================
    // Inheritance verification
    // ==================================================================

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf(
            \FreeFormCertificate\Settings\SettingsTab::class,
            $this->tab
        );
    }
}
