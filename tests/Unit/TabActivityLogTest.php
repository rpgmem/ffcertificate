<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabActivityLog;

/**
 * Tests for TabActivityLog: the Activity Log Settings tab (#802 Phase B).
 *
 * Covers the tab metadata, the self-gating caps (view = ffc_view_activity_log,
 * manage = ffc_export_activity_log) and that render() delegates to the
 * AdminActivityLogPage surface.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabActivityLog
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabActivityLogTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabActivityLog */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_kses_post' )->returnArg();
        class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabActivityLog' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );

        $this->tab = new TabActivityLog();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_tab_id_is_activity_log(): void {
        $this->assertSame( 'activity_log', $this->tab->get_id() );
    }

    public function test_tab_title_is_activity_log(): void {
        $this->assertSame( 'Activity Log', $this->tab->get_title() );
    }

    public function test_tab_icon_is_clipboard(): void {
        $this->assertSame( 'ffc-icon-clipboard', $this->tab->get_icon() );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    public function test_view_cap_is_activity_log_view(): void {
        $this->assertSame( 'ffc_view_activity_log', $this->tab->get_view_cap() );
    }

    public function test_manage_cap_is_activity_log_export(): void {
        // Off the settings manage cap so the read-only fieldset lock never
        // disables the log's export/filter controls for a legitimate viewer.
        $this->assertSame( 'ffc_export_activity_log', $this->tab->get_manage_cap() );
    }

    public function test_render_delegates_to_activity_log_page(): void {
        // With the log disabled, render_page() emits the disabled notice — a
        // cheap way to prove the tab delegates to AdminActivityLogPage.
        Mockery::mock( 'alias:\FreeFormCertificate\Settings\SettingsReader' )
            ->shouldReceive( 'activity_log_enabled' )->andReturn( false );

        ob_start();
        $this->tab->render();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString( 'Activity Log is currently disabled.', $output );
    }
}
