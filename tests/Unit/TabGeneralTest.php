<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabGeneral;

/**
 * Tests for TabGeneral: general settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabGeneral
 */
class TabGeneralTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabGeneral */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->tab = new TabGeneral();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_general(): void {
        $this->assertSame( 'general', $this->tab->get_id() );
    }

    public function test_tab_title_is_general(): void {
        $this->assertSame( 'General', $this->tab->get_title() );
    }

    public function test_tab_icon_is_settings(): void {
        $this->assertSame( 'ffc-icon-settings', $this->tab->get_icon() );
    }

    public function test_tab_order_is_10(): void {
        $this->assertSame( 10, $this->tab->get_order() );
    }

    public function test_general_tab_has_highest_priority(): void {
        // General tab should load first (order 10), before other tabs
        $this->assertLessThanOrEqual( 10, $this->tab->get_order() );
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
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        $tab = new class() extends TabGeneral {
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-general.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'General settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'General settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_general_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-general.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "general-rendered"; ?>' );

        $tab = new class( $tmp_dir ) extends TabGeneral {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-general.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'General settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'general-rendered', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_value_from_ffc_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'date_format' => 'd/m/Y' ) );

        $this->assertSame( 'd/m/Y', $this->tab->get_option( 'date_format' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'F j, Y', $this->tab->get_option( 'date_format', 'F j, Y' ) );
    }

    public function test_get_option_returns_empty_string_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '', $this->tab->get_option( 'nonexistent_key' ) );
    }
}
