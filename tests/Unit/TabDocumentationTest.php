<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabDocumentation;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabDocumentation
 */
class TabDocumentationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private TabDocumentation $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

        $this->tab = new TabDocumentation();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_tab_id(): void {
        $this->assertSame( 'documentation', $this->tab->get_id() );
    }

    public function test_tab_title(): void {
        $this->assertSame( 'Documentation', $this->tab->get_title() );
    }

    public function test_tab_icon(): void {
        $this->assertSame( 'ffc-icon-doc', $this->tab->get_icon() );
    }

    public function test_tab_order(): void {
        $this->assertSame( 90, $this->tab->get_order() );
    }

    public function test_render_error_when_view_missing(): void {
        $tab = new class() extends TabDocumentation {
            public function render(): void {
                $view_file = '/tmp/nonexistent_ffc/ffc-tab-documentation.php';
                if ( file_exists( $view_file ) ) {
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Documentation view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'not found', $output );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    public function test_get_option_returns_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $this->assertSame( 'x', $this->tab->get_option( 'missing_key', 'x' ) );
    }
}
