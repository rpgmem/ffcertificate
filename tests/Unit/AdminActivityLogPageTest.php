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
 * Tests for AdminActivityLogPage: constructor source registration,
 * register_menu, enqueue_scripts, render_page (enabled + disabled),
 * build_query_args, render_rows_html, render_pagination_html, plus the static
 * label/badge/summary helpers. The CSV export now runs through the batched
 * engine (issue #772); its per-source behavior lives in
 * ActivityLogExportSourceTest.
 *
 * Process isolation is required because several tests use Mockery `alias:`
 * mocks for the static core helpers (ActivityLogQuery, Capabilities,
 * RequestInput, DateFormatter, SettingsReader) — alias mocks would otherwise
 * leak across the suite.
 *
 * @covers \FreeFormCertificate\Admin\AdminActivityLogPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminActivityLogPageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists('\\FreeFormCertificate\\Admin\\AdminActivityLogPage');

        // Common WP function stubs
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(static fn($v) => (int) $v);
        Functions\when('sanitize_key')->alias(static fn($v) => strtolower((string) $v));
        Functions\when('admin_url')->returnArg();
    }

    protected function tearDown(): void {
        $_GET  = array();
        $_POST = array();
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
        Functions\when('add_action')->justReturn(true);

        $page = new AdminActivityLogPage();
        $page->register_menu();

        $this->assertSame('edit.php?post_type=ffc_form', $captured_args[0]);
        $this->assertSame('Activity Log', $captured_args[1]);
        $this->assertSame('Activity Log', $captured_args[2]);
        $this->assertSame('ffc_view_activity_log', $captured_args[3]);
        $this->assertSame('ffc-activity-log', $captured_args[4]);
        $this->assertIsCallable($captured_args[5]);
    }

    public function test_register_menu_callback_points_to_render_page(): void {
        $captured_callback = null;
        Functions\when('add_submenu_page')->alias(function () use (&$captured_callback) {
            $args = func_get_args();
            $captured_callback = $args[5];
        });
        Functions\when('add_action')->justReturn(true);

        $page = new AdminActivityLogPage();
        $page->register_menu();

        $this->assertIsArray($captured_callback);
        $this->assertSame($page, $captured_callback[0]);
        $this->assertSame('render_page', $captured_callback[1]);
    }

    public function test_register_menu_registers_enqueue_hook_only(): void {
        Functions\when('add_submenu_page')->justReturn('hook');
        $hooks = [];
        Functions\when('add_action')->alias(function ($hook, $cb) use (&$hooks) {
            $hooks[$hook] = $cb;
        });

        $page = new AdminActivityLogPage();
        $page->register_menu();

        // The synchronous admin_init handle_csv_export handler was removed
        // when the export moved onto the batched dispatcher (#772).
        $this->assertArrayNotHasKey('admin_init', $hooks);
        $this->assertArrayHasKey('admin_enqueue_scripts', $hooks);
        $this->assertSame([$page, 'enqueue_scripts'], $hooks['admin_enqueue_scripts']);
    }

    // ==================================================================
    // __construct() — batched-export source registration (#772)
    // ==================================================================

    public function test_construct_registers_activity_log_export_source(): void {
        new AdminActivityLogPage();

        $this->assertTrue(
            \FreeFormCertificate\Core\SourceRegistry::has(
                \FreeFormCertificate\Admin\ActivityLogExportSource::TYPE
            )
        );
        $this->assertSame(
            'activity_log',
            \FreeFormCertificate\Admin\ActivityLogExportSource::TYPE
        );
    }

    // ==================================================================
    // enqueue_scripts()
    // ==================================================================

    public function test_enqueue_scripts_returns_early_on_wrong_hook(): void {
        $called = false;
        Functions\when('wp_enqueue_script')->alias(function () use (&$called) {
            $called = true;
        });

        $page = new AdminActivityLogPage();
        $page->enqueue_scripts('some_other_hook');

        $this->assertFalse($called);
    }

    public function test_enqueue_scripts_enqueues_and_localizes_on_correct_hook(): void {
        if (!defined('FFC_PLUGIN_URL')) {
            define('FFC_PLUGIN_URL', 'http://example.test/wp-content/plugins/ffcertificate/');
        }
        if (!defined('FFC_VERSION')) {
            define('FFC_VERSION', '9.9.9');
        }

        Mockery::mock('alias:\FreeFormCertificate\Core\AssetHelper')
            ->shouldReceive('asset_suffix')->andReturn('.min');

        $scripts = [];
        Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$scripts) {
            $scripts[] = $handle;
        });
        Functions\when('wp_create_nonce')->justReturn('nonce123');
        $localized = null;
        Functions\when('wp_localize_script')->alias(function ($handle, $var, $data) use (&$localized) {
            if ('ffcActivityLog' === $var) {
                $localized = $data;
            }
        });

        $page = new AdminActivityLogPage();
        $page->enqueue_scripts('ffc_form_page_ffc-activity-log');

        $this->assertContains('ffc-core', $scripts);
        $this->assertContains('ffc-batched-export', $scripts);
        $this->assertContains('ffc-admin-activity-log', $scripts);
        $this->assertNotNull($localized);
        $this->assertSame('nonce123', $localized['nonce']);
        // Batched export (#772) needs the AJAX url + a dedicated job nonce.
        $this->assertArrayHasKey('ajaxUrl', $localized);
        $this->assertSame('nonce123', $localized['exportNonce']);
        $this->assertArrayHasKey('strings', $localized);
        $this->assertArrayHasKey('noLogs', $localized['strings']);
        $this->assertArrayHasKey('exportProgress', $localized['strings']);
    }

    // ==================================================================
    // render_page() - disabled state
    // ==================================================================

    public function test_render_page_shows_disabled_notice_when_activity_log_disabled(): void {
        Mockery::mock('alias:\FreeFormCertificate\Settings\SettingsReader')
            ->shouldReceive('activity_log_enabled')->andReturn(false);

        Functions\when('esc_html_e')->alias(function ($text) {
            echo $text;
        });

        $page = new AdminActivityLogPage();

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Activity Log is currently disabled.', $output);
    }

    // ==================================================================
    // render_page() - enabled state (includes the view file)
    // ==================================================================

    public function test_render_page_includes_view_when_enabled(): void {
        if (!defined('FFC_PLUGIN_DIR')) {
            define('FFC_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        Mockery::mock('alias:\FreeFormCertificate\Settings\SettingsReader')
            ->shouldReceive('activity_log_enabled')->andReturn(true);

        Mockery::mock('alias:\FreeFormCertificate\Core\RequestInput')
            ->shouldReceive('get_get_string')->andReturn('');

        $query = Mockery::mock('alias:\FreeFormCertificate\Core\ActivityLogQuery');
        $query->shouldReceive('get_activities')->andReturn([]);
        $query->shouldReceive('count_activities')->andReturn(0);
        $query->shouldReceive('distinct_actions')->andReturn(['submission_created']);
        $query->shouldReceive('get_stats')->andReturn([
            'total'     => 0,
            'by_level'  => [],
            'by_action' => [],
        ]);

        // View-file helpers.
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
        Functions\when('esc_attr_e')->alias(function ($t) { echo $t; });
        Functions\when('selected')->justReturn('');
        Functions\when('wp_nonce_url')->returnArg();
        Functions\when('add_query_arg')->justReturn('http://example.test/export');
        Functions\when('number_format_i18n')->alias(static fn($n) => (string) $n);
        Functions\when('checked')->justReturn('');

        $page = new AdminActivityLogPage();

        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        // The view renders the empty-state message and the wrap heading.
        $this->assertStringContainsString('No activity logs found.', $output);
        $this->assertStringContainsString('Activity Log', $output);
    }

    // Note: the "view file not found" fallback in render_page() is
    // unreachable in the test environment because tests/bootstrap.php defines
    // FFC_PLUGIN_DIR at the real plugin root (so the view file always exists).
    // It is left untested deliberately rather than via a process-local
    // constant hack that bootstrap already pre-empts.

    // ==================================================================
    // build_query_args()
    // ==================================================================

    public function test_build_query_args_defaults_to_page_one(): void {
        $args = AdminActivityLogPage::build_query_args([]);
        $this->assertSame(50, $args['limit']);
        $this->assertSame(0, $args['offset']);
        $this->assertSame('created_at', $args['orderby']);
        $this->assertSame('DESC', $args['order']);
        $this->assertArrayNotHasKey('level', $args);
        $this->assertArrayNotHasKey('action', $args);
        $this->assertArrayNotHasKey('search', $args);
    }

    public function test_build_query_args_computes_offset_from_paged(): void {
        $args = AdminActivityLogPage::build_query_args(['paged' => 3], 50);
        $this->assertSame(100, $args['offset']);
    }

    public function test_build_query_args_includes_filters(): void {
        Functions\when('sanitize_text_field')->returnArg();

        $args = AdminActivityLogPage::build_query_args([
            'level'      => 'error',
            'log_action' => 'submission_created',
            'search'     => 'foo',
        ]);

        $this->assertSame('error', $args['level']);
        $this->assertSame('submission_created', $args['action']);
        $this->assertSame('foo', $args['search']);
    }

    public function test_build_query_args_clamps_paged_to_minimum_one(): void {
        $args = AdminActivityLogPage::build_query_args(['paged' => 0], 50);
        $this->assertSame(0, $args['offset']);
    }

    // ==================================================================
    // render_rows_html()
    // ==================================================================

    public function test_render_rows_html_returns_empty_for_no_logs(): void {
        $this->assertSame('', AdminActivityLogPage::render_rows_html([]));
    }

    public function test_render_rows_html_renders_row_with_known_user(): void {
        Mockery::mock('alias:\FreeFormCertificate\Core\DateFormatter')
            ->shouldReceive('format_date')->andReturn('01/01/2026')
            ->shouldReceive('format_time')->andReturn('00:00');

        $u = new \stdClass();
        $u->display_name = 'Jane Doe';
        $u->user_login   = 'jane';
        Functions\when('get_userdata')->justReturn($u);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });

        $html = AdminActivityLogPage::render_rows_html([
            [
                'created_at' => '2026-01-01 00:00:00',
                'level'      => 'info',
                'action'     => 'submission_created',
                'user_id'    => 7,
                'user_ip'    => '203.0.113.5',
                'context'    => ['form_id' => 42],
            ],
        ]);

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('jane', $html);
        $this->assertStringContainsString('203.0.113.5', $html);
        $this->assertStringContainsString('Submission Created', $html);
        $this->assertStringContainsString('<details>', $html);
    }

    public function test_render_rows_html_renders_deleted_user_and_anonymous(): void {
        Mockery::mock('alias:\FreeFormCertificate\Core\DateFormatter')
            ->shouldReceive('format_date')->andReturn('01/01/2026')
            ->shouldReceive('format_time')->andReturn('00:00');

        Functions\when('get_userdata')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });

        $html = AdminActivityLogPage::render_rows_html([
            [
                'created_at' => '2026-01-01 00:00:00',
                'level'      => 'info',
                'action'     => 'data_accessed',
                'user_id'    => 99, // userdata false -> "deleted"
                'user_ip'    => '',
                'context'    => '',
            ],
            [
                'created_at' => '2026-01-02 00:00:00',
                'level'      => 'warning',
                'action'     => 'access_denied',
                'user_id'    => 0, // anonymous
                'user_ip'    => '',
                'context'    => '',
            ],
        ]);

        $this->assertStringContainsString('deleted', $html);
        $this->assertStringContainsString('System / Anonymous', $html);
        // Empty context renders the em-dash branch.
        $this->assertStringContainsString('—', $html);
    }

    public function test_render_rows_html_renders_schedule_summary_above_dump(): void {
        Mockery::mock('alias:\FreeFormCertificate\Core\DateFormatter')
            ->shouldReceive('format_date')->andReturn('01/01/2026')
            ->shouldReceive('format_time')->andReturn('00:00')
            ->shouldReceive('format_schedule')->andReturn('8h to 18h');

        Functions\when('get_userdata')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn($v) => json_encode($v));
        Functions\when('esc_html_e')->alias(function ($t) { echo $t; });

        $html = AdminActivityLogPage::render_rows_html([
            [
                'created_at' => '2026-01-01 00:00:00',
                'level'      => 'info',
                'action'     => 'schedule_override_created',
                'user_id'    => 0,
                'user_ip'    => '',
                'context'    => [
                    'submission_id'        => 555,
                    'operator_cpf_masked'  => '123.***.***-45',
                    'participant_cpf_hash' => str_repeat('a', 64),
                ],
            ],
        ]);

        $this->assertStringContainsString('ffc-log-summary-dl', $html);
        $this->assertStringContainsString('123.***.***-45', $html);
    }

    // ==================================================================
    // render_pagination_html()
    // ==================================================================

    public function test_render_pagination_html_empty_for_single_page(): void {
        $this->assertSame('', AdminActivityLogPage::render_pagination_html(10, 1, 50));
    }

    public function test_render_pagination_html_renders_for_multiple_pages(): void {
        Functions\when('_n')->alias(static fn($s, $p, $c) => 1 === (int) $c ? $s : $p);
        Functions\when('number_format_i18n')->alias(static fn($n) => (string) $n);
        Functions\when('add_query_arg')->justReturn('http://example.test/?paged=%#%');
        Functions\when('paginate_links')->justReturn('<a>2</a>');

        $html = AdminActivityLogPage::render_pagination_html(120, 1, 50);

        $this->assertStringContainsString('tablenav', $html);
        $this->assertStringContainsString('120 logs', $html);
        $this->assertStringContainsString('<a>2</a>', $html);
    }

    public function test_render_pagination_html_uses_base_url_when_supplied(): void {
        Functions\when('_n')->alias(static fn($s, $p, $c) => 1 === (int) $c ? $s : $p);
        Functions\when('number_format_i18n')->alias(static fn($n) => (string) $n);
        $captured_base = null;
        Functions\when('add_query_arg')->alias(function ($key, $val, $base = null) use (&$captured_base) {
            $captured_base = $base;
            return 'url';
        });
        Functions\when('paginate_links')->justReturn('links');

        AdminActivityLogPage::render_pagination_html(120, 2, 50, 'http://example.test/base');

        $this->assertSame('http://example.test/base', $captured_base);
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
    // Schedule exception labels + summary renderer (#366 Sprint 9)
    // ==================================================================

    public function test_get_action_label_for_schedule_override_created(): void {
        $this->assertSame(
            'Schedule Override Created',
            AdminActivityLogPage::get_action_label( 'schedule_override_created' )
        );
    }

    public function test_get_action_label_for_operator_ip_bypass(): void {
        $this->assertSame(
            'Operator IP Bypass',
            AdminActivityLogPage::get_action_label( 'operator_ip_bypass' )
        );
    }

    public function test_render_schedule_exception_summary_returns_null_for_unrelated_action(): void {
        $this->assertNull(
            AdminActivityLogPage::render_schedule_exception_summary(
                'submission_created',
                array( 'form_id' => 42 )
            )
        );
    }

    public function test_render_schedule_exception_summary_for_override_action_lists_facts(): void {
        Mockery::mock('alias:\FreeFormCertificate\Core\DateFormatter')
            ->shouldReceive('format_schedule')
            ->andReturnUsing(function ($start, $end) {
                if ('08:00' === $start && '18:00' === $end) {
                    return '8h to 18h';
                }
                return '8h to 17h30';
            });

        $html = AdminActivityLogPage::render_schedule_exception_summary(
            'schedule_override_created',
            array(
                'form_id'               => 42,
                'submission_id'         => 555,
                'schedule_start_before' => '08:00',
                'schedule_end_before'   => '18:00',
                'schedule_start_after'  => '08:00',
                'schedule_end_after'    => '17:30',
                'operator_cpf_masked'   => '123.***.***-45',
                'participant_cpf_hash'  => str_repeat( 'a', 64 ),
            )
        );

        $this->assertNotNull( $html );
        $this->assertStringContainsString( 'Before', $html );
        $this->assertStringContainsString( 'After', $html );
        $this->assertStringContainsString( 'Operator (masked)', $html );
        $this->assertStringContainsString( '123.***.***-45', $html );
        $this->assertStringContainsString( 'aaaaaaaaaaaa…', $html );
        $this->assertStringContainsString( '555', $html );
        $this->assertStringContainsString( '8h to 18h', $html );
        $this->assertStringContainsString( '8h to 17h30', $html );
    }

    public function test_render_schedule_exception_summary_for_ip_bypass_action(): void {
        $html = AdminActivityLogPage::render_schedule_exception_summary(
            'operator_ip_bypass',
            array(
                'bypassed_ip'         => '203.0.113.5',
                'operator_cpf_masked' => '123.***.***-45',
                'submission_id'       => 555,
            )
        );

        $this->assertNotNull( $html );
        $this->assertStringContainsString( '203.0.113.5', $html );
        $this->assertStringContainsString( '123.***.***-45', $html );
        $this->assertStringContainsString( '555', $html );
    }

    public function test_render_schedule_exception_summary_skips_empty_fields(): void {
        $html = AdminActivityLogPage::render_schedule_exception_summary(
            'operator_ip_bypass',
            array(
                'bypassed_ip'         => '203.0.113.5',
                'operator_cpf_masked' => '',
                'submission_id'       => 555,
            )
        );

        $this->assertNotNull( $html );
        $this->assertStringNotContainsString( 'Operator (masked)', $html );
        $this->assertStringContainsString( '203.0.113.5', $html );
    }

    // ==================================================================
    // Pre-flight blocked: action label, reason label, summary renderer
    // ==================================================================

    public function test_get_action_label_for_preflight_blocked(): void {
        $this->assertSame(
            'Pre-flight Banner Shown',
            AdminActivityLogPage::get_action_label( 'preflight_blocked' )
        );
    }

    public function test_get_preflight_reason_label_maps_known_codes(): void {
        $this->assertStringContainsString(
            'pre-explainer',
            AdminActivityLogPage::get_preflight_reason_label( 'gps_prompt' )
        );
        $this->assertStringContainsString(
            'denied',
            AdminActivityLogPage::get_preflight_reason_label( 'gps_denied' )
        );
        $this->assertStringContainsString(
            'Cookie wall',
            AdminActivityLogPage::get_preflight_reason_label( 'cookies' )
        );
    }

    public function test_get_preflight_reason_label_falls_back_to_raw_code(): void {
        $this->assertSame(
            'unknown_reason',
            AdminActivityLogPage::get_preflight_reason_label( 'unknown_reason' )
        );
    }

    public function test_render_preflight_blocked_summary_returns_null_for_unrelated_action(): void {
        $this->assertNull(
            AdminActivityLogPage::render_preflight_blocked_summary(
                'submission_created',
                array( 'reason' => 'gps_prompt' )
            )
        );
    }

    public function test_render_preflight_blocked_summary_surfaces_labelled_reason(): void {
        $html = AdminActivityLogPage::render_preflight_blocked_summary(
            'preflight_blocked',
            array(
                'form_id' => 42,
                'reason'  => 'gps_prompt',
                'ip_hash' => str_repeat( 'b', 12 ),
            )
        );

        $this->assertNotNull( $html );
        $this->assertStringContainsString( 'pre-explainer', $html );
        $this->assertStringNotContainsString( 'gps_prompt', $html );
        $this->assertStringContainsString( '42', $html );
        $this->assertStringContainsString( 'bbbbbbbbbbbb', $html );
    }
}
