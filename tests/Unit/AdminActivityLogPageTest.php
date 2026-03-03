<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminActivityLogPage;

/**
 * Tests for AdminActivityLogPage: register_menu, get_action_label,
 * get_level_badge, and render_page logic.
 *
 * @covers \FreeFormCertificate\Admin\AdminActivityLogPage
 */
class AdminActivityLogPageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WP function stubs
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->justReturn(1);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // register_menu()
    // ==================================================================

    public function test_register_menu_calls_add_submenu_page(): void {
        $captured_args = [];
        Functions\when('add_submenu_page')->alias(function () use (&$captured_args) {
            $captured_args = func_get_args();
        });

        $page = new AdminActivityLogPage();
        $page->register_menu();

        $this->assertSame('edit.php?post_type=ffc_form', $captured_args[0]);
        $this->assertSame('Activity Log', $captured_args[1]);
        $this->assertSame('Activity Log', $captured_args[2]);
        $this->assertSame('manage_options', $captured_args[3]);
        $this->assertSame('ffc-activity-log', $captured_args[4]);
        $this->assertIsCallable($captured_args[5]);
    }

    public function test_register_menu_callback_points_to_render_page(): void {
        $captured_callback = null;
        Functions\when('add_submenu_page')->alias(function () use (&$captured_callback) {
            $args = func_get_args();
            $captured_callback = $args[5];
        });

        $page = new AdminActivityLogPage();
        $page->register_menu();

        $this->assertIsArray($captured_callback);
        $this->assertSame($page, $captured_callback[0]);
        $this->assertSame('render_page', $captured_callback[1]);
    }

    // ==================================================================
    // get_action_label()
    // ==================================================================

    public function test_get_action_label_returns_known_label(): void {
        $label = AdminActivityLogPage::get_action_label('submission_created');
        $this->assertSame('Submission Created', $label);
    }

    public function test_get_action_label_returns_label_for_submission_deleted(): void {
        $label = AdminActivityLogPage::get_action_label('submission_deleted');
        $this->assertSame('Submission Deleted', $label);
    }

    public function test_get_action_label_returns_label_for_settings_changed(): void {
        $label = AdminActivityLogPage::get_action_label('settings_changed');
        $this->assertSame('Settings Changed', $label);
    }

    public function test_get_action_label_returns_formatted_string_for_unknown(): void {
        $label = AdminActivityLogPage::get_action_label('custom_event_fired');
        $this->assertSame('Custom Event Fired', $label);
    }

    public function test_get_action_label_handles_single_word(): void {
        $label = AdminActivityLogPage::get_action_label('login');
        $this->assertSame('Login', $label);
    }

    // ==================================================================
    // get_level_badge()
    // ==================================================================

    public function test_get_level_badge_returns_info_badge(): void {
        $html = AdminActivityLogPage::get_level_badge('info');
        $this->assertStringContainsString('ffc-badge-info', $html);
        $this->assertStringContainsString('INFO', $html);
    }

    public function test_get_level_badge_returns_warning_badge(): void {
        $html = AdminActivityLogPage::get_level_badge('warning');
        $this->assertStringContainsString('ffc-badge-warning', $html);
        $this->assertStringContainsString('WARNING', $html);
    }

    public function test_get_level_badge_returns_error_badge(): void {
        $html = AdminActivityLogPage::get_level_badge('error');
        $this->assertStringContainsString('ffc-badge-error', $html);
        $this->assertStringContainsString('ERROR', $html);
    }

    public function test_get_level_badge_returns_debug_badge(): void {
        $html = AdminActivityLogPage::get_level_badge('debug');
        $this->assertStringContainsString('ffc-badge-debug', $html);
        $this->assertStringContainsString('DEBUG', $html);
    }

    public function test_get_level_badge_defaults_to_info_for_unknown_level(): void {
        $html = AdminActivityLogPage::get_level_badge('custom');
        $this->assertStringContainsString('ffc-badge-info', $html);
        $this->assertStringContainsString('CUSTOM', $html);
    }

    public function test_get_level_badge_contains_span_element(): void {
        $html = AdminActivityLogPage::get_level_badge('error');
        $this->assertStringStartsWith('<span', $html);
        $this->assertStringEndsWith('</span>', $html);
    }

    // ==================================================================
    // render_page() - disabled state
    // ==================================================================

    public function test_render_page_shows_disabled_notice_when_activity_log_disabled(): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('esc_html_e')->alias(function ($text) {
            echo $text;
        });
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();

        $page = new AdminActivityLogPage();

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Activity Log is currently disabled.', $output);
    }

    public function test_render_page_shows_disabled_notice_when_setting_is_zero(): void {
        Functions\when('get_option')->justReturn(['enable_activity_log' => 0]);
        Functions\when('esc_html_e')->alias(function ($text) {
            echo $text;
        });
        Functions\when('esc_url')->returnArg();
        Functions\when('admin_url')->returnArg();

        $page = new AdminActivityLogPage();

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Activity Log is currently disabled.', $output);
    }
}
