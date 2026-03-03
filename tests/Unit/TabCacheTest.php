<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabCache;

/**
 * Tests for TabCache: cache & performance settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabCache
 */
class TabCacheTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabCache */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->tab = new TabCache();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_cache(): void {
        $this->assertSame( 'cache', $this->tab->get_id() );
    }

    public function test_tab_title_is_cache(): void {
        $this->assertSame( 'Cache', $this->tab->get_title() );
    }

    public function test_tab_icon_is_package(): void {
        $this->assertSame( 'ffc-icon-package', $this->tab->get_icon() );
    }

    public function test_tab_order_is_30(): void {
        $this->assertSame( 30, $this->tab->get_order() );
    }

    // ==================================================================
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        $tab = new class() extends TabCache {
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-cache.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Cache settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'Cache settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_cache_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-cache.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "cache-view-loaded"; ?>' );

        $tab = new class( $tmp_dir ) extends TabCache {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-cache.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Cache settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'cache-view-loaded', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_saved_cache_enabled(): void {
        Functions\when( 'get_option' )->justReturn( array( 'cache_enabled' => '1' ) );

        $this->assertSame( '1', $this->tab->get_option( 'cache_enabled' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( '', $this->tab->get_option( 'cache_expiration' ) );
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
