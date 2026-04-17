<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceShortcode;

/**
 * Tests for AudienceShortcode: init registration, render with various
 * login/access states, private visibility message, and helper methods.
 *
 * @covers \FreeFormCertificate\Audience\AudienceShortcode
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceShortcodeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_attr_e')->alias(function ($text) { echo $text; });
        Functions\when('esc_html_e')->alias(function ($text) { echo $text; });
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });
        Functions\when('sanitize_text_field')->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_shortcode(): void {
        Functions\expect('add_shortcode')
            ->once()
            ->with('ffc_audience', Mockery::type('array'));

        AudienceShortcode::init();
    }

    // ==================================================================
    // render() — non-array atts normalised
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_normalises_string_atts_to_array(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            if (!is_array($atts)) {
                $atts = array();
            }
            return array_merge($defaults, $atts);
        });
        $this->stubNotLoggedInWithNoPublicSchedules();

        $result = AudienceShortcode::render('');

        $this->assertIsString($result);
    }

    // ==================================================================
    // render() — not logged in, private schedule
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_returns_private_message_for_private_schedule_when_not_logged_in(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        Functions\when('is_user_logged_in')->justReturn(false);

        $schedule = (object) [
            'id' => 1, 'name' => 'Test Calendar', 'status' => 'active', 'visibility' => 'private',
        ];

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(1)->andReturn($schedule);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_aud_private_display_mode') return 'show_message';
            if ($key === 'ffc_aud_visibility_message') return 'Please log in.';
            return $default;
        });
        Functions\when('wp_login_url')->justReturn('https://example.com/login');
        Functions\when('get_permalink')->justReturn('https://example.com/page');

        // Stub Utils for enqueue_styles
        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        $result = AudienceShortcode::render(array('schedule_id' => 1));

        $this->assertStringContainsString('ffc-visibility-restricted', $result);
        $this->assertStringContainsString('ffc-restricted-message', $result);
    }

    // ==================================================================
    // render() — not logged in, display_mode = hide
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_returns_empty_when_private_display_mode_is_hide(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        Functions\when('is_user_logged_in')->justReturn(false);

        $schedule = (object) [
            'id' => 1, 'name' => 'Private Cal', 'status' => 'active', 'visibility' => 'private',
        ];

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(1)->andReturn($schedule);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_aud_private_display_mode') return 'hide';
            return $default;
        });
        Functions\when('wp_login_url')->justReturn('https://example.com/login');
        Functions\when('get_permalink')->justReturn('https://example.com/page');

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        $result = AudienceShortcode::render(array('schedule_id' => 1));

        $this->assertSame('', $result);
    }

    // ==================================================================
    // render() — not logged in, inactive schedule
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_returns_empty_for_inactive_schedule_when_not_logged_in(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        Functions\when('is_user_logged_in')->justReturn(false);

        $schedule = (object) [
            'id' => 5, 'name' => 'Inactive Calendar', 'status' => 'inactive', 'visibility' => 'public',
        ];

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(5)->andReturn($schedule);

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        $result = AudienceShortcode::render(array('schedule_id' => 5));

        $this->assertSame('', $result);
    }

    // ==================================================================
    // render() — logged in, no access
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_returns_no_access_message_when_user_has_no_schedules(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(42);
        Functions\when('user_can')->justReturn(false);
        Functions\when('nocache_headers')->justReturn(null);
        Functions\when('do_action')->justReturn(null);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_user_access')->with(42)->andReturn(array());

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        $result = AudienceShortcode::render(array());

        $this->assertStringContainsString('ffc-audience-notice', $result);
        $this->assertStringContainsString('You do not have access to any calendars.', $result);
    }

    // ==================================================================
    // render() — not logged in, no schedule_id, no public schedules
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_shows_private_message_when_no_public_schedules_found(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });

        $this->stubNotLoggedInWithNoPublicSchedules();

        $result = AudienceShortcode::render(array());

        $this->assertStringContainsString('ffc-visibility-restricted', $result);
    }

    // ==================================================================
    // render() — not logged in, public schedule (read-only calendar)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_shows_readonly_calendar_for_public_schedule(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->justReturn('https://example.com/wp-json/ffc/v1/audience/');
        Functions\when('wp_create_nonce')->justReturn('nonce123');
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('wp_login_url')->justReturn('https://example.com/login');
        Functions\when('get_permalink')->justReturn('https://example.com/page');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('selected')->justReturn('');
        Functions\when('is_user_logged_in')->justReturn(false);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        $schedule = (object) [
            'id' => 2, 'name' => 'Public Calendar', 'status' => 'active', 'visibility' => 'public',
            'show_event_list' => false, 'event_list_position' => 'side',
            'audience_badge_format' => 'name', 'future_days_limit' => null,
            'booking_label_singular' => null, 'booking_label_plural' => null,
        ];

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(2)->andReturn($schedule);
        $schedRepoMock->shouldReceive('get_environment_label')->andReturn('Environment');

        $envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
        $envRepoMock->shouldReceive('get_by_schedule')->andReturn(array());

        $audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceRepository');
        $audRepoMock->shouldReceive('get_hierarchical')->andReturn(array());

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_aud_scheduling_message') return 'Please log in to book.';
            if ($key === 'date_format') return 'Y-m-d';
            if ($key === 'time_format') return 'H:i';
            if ($key === 'start_of_week') return 0;
            if ($key === 'ffc_aud_multiple_audiences_color') return '';
            return $default;
        });

        $result = AudienceShortcode::render(array('schedule_id' => 2));

        $this->assertStringContainsString('ffc-audience-calendar', $result);
        $this->assertStringContainsString('ffc-calendar-grid', $result);
        // Read-only: no booking modal
        $this->assertStringNotContainsString('ffc-booking-modal', $result);
    }

    // ==================================================================
    // render() — show_title_message displays calendar name
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_private_visibility_show_title_message_includes_schedule_name(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('is_user_logged_in')->justReturn(false);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        $schedule = (object) [
            'id' => 3, 'name' => 'VIP Calendar', 'status' => 'active', 'visibility' => 'private',
        ];

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(3)->andReturn($schedule);

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_aud_private_display_mode') return 'show_title_message';
            if ($key === 'ffc_aud_visibility_message') return 'Log in to see this.';
            return $default;
        });
        Functions\when('wp_login_url')->justReturn('https://example.com/login');
        Functions\when('get_permalink')->justReturn('https://example.com/page');

        $result = AudienceShortcode::render(array('schedule_id' => 3));

        $this->assertStringContainsString('ffc-calendar-title', $result);
        $this->assertStringContainsString('VIP Calendar', $result);
    }

    // ==================================================================
    // render() — schedule not found returns empty
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_render_returns_empty_when_schedule_not_found(): void {
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, is_array($atts) ? $atts : array());
        });
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('is_user_logged_in')->justReturn(false);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_by_id')->with(99)->andReturn(null);

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        $result = AudienceShortcode::render(array('schedule_id' => 99));

        $this->assertSame('', $result);
    }

    // ==================================================================
    // Helper: stub for "not logged in, no public schedules"
    // ==================================================================

    private function stubNotLoggedInWithNoPublicSchedules(): void {
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('is_user_logged_in')->justReturn(false);

        $calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
        $calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

        $schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
        $schedRepoMock->shouldReceive('get_all')->andReturn(array());

        $utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('asset_suffix')->andReturn('.min');

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_aud_private_display_mode') return 'show_message';
            if ($key === 'ffc_aud_visibility_message') return 'Please log in.';
            return $default;
        });
        Functions\when('wp_login_url')->justReturn('https://example.com/login');
        Functions\when('get_permalink')->justReturn('https://example.com/page');
    }
}
