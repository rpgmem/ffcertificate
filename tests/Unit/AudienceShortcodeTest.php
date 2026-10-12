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
		// `AssetHelper::enqueue_dark_mode()` reads the dark-mode setting to decide
		// whether to enqueue the toggle (#1126). Without this stub the enqueue
		// path dies at `get_option()` — and it is not `byDefault()` in the tests
		// that declare their own `get_option`, because there the specific alias
		// already answers.
		Functions\when('get_option')->justReturn(false);
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
		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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

		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_hierarchical')->andReturn(array());

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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
		// #951 a11y: the environment filter <select> carries an accessible name
		// (it has no visible <label>), so screen-reader users know its purpose.
		$this->assertStringContainsString('ffc-environment-select', $result);
		$this->assertStringContainsString('aria-label="Filter by', $result);
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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

		$result = AudienceShortcode::render(array('schedule_id' => 99));

		$this->assertSame('', $result);
	}

	// ==================================================================
	// render() — logged in: full calendar, bypass notice, booking modal
	// ==================================================================

	/**
	 * Stubs shared by every logged-in render that reaches the calendar.
	 *
	 * @param array<string, mixed> $localized Receives the `ffcAudience` payload.
	 */
	private function stubLoggedInCalendarEnvironment( array &$localized ): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('wp_enqueue_style')->justReturn(null);
		Functions\when('wp_enqueue_script')->justReturn(null);
		Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localized) {
			$localized = $data;
			return true;
		});
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('rest_url')->justReturn('https://example.com/wp-json/ffc/v1/audience/');
		Functions\when('wp_create_nonce')->justReturn('nonce123');
		Functions\when('get_locale')->justReturn('en_US');
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('selected')->justReturn('');
		Functions\when('nocache_headers')->justReturn(null);
		Functions\when('do_action')->justReturn(null);
		Functions\when('is_user_logged_in')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(7);
		// The JSON config travels in an attribute, so it must really be escaped.
		Functions\when('esc_attr')->alias(function ($text) {
			return htmlspecialchars((string) $text, ENT_QUOTES);
		});

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		$utilsMock->shouldReceive('enqueue_dark_mode');
	}

	/**
	 * Decodes the `data-config` attribute the calendar carries.
	 *
	 * @return array<string, mixed>
	 */
	private function configOf( string $html ): array {
		$this->assertSame(1, preg_match('/data-config="([^"]*)"/', $html, $m), 'The calendar must carry its data-config.');
		$config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
		$this->assertIsArray($config);
		return $config;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_logged_in_admin_with_bypass_renders_full_calendar(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('user_can')->justReturn(true);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(true);

		$private = (object) array(
			'id' => 5, 'name' => 'Private Rooms', 'status' => 'active', 'visibility' => 'private',
			'show_event_list' => 1, 'event_list_position' => 'below',
			'audience_badge_format' => 'parent_name', 'future_days_limit' => '30',
			'booking_label_singular' => 'reservation', 'booking_label_plural' => 'reservations',
		);
		$public = (object) array(
			'id' => 6, 'name' => 'Open Rooms', 'status' => 'active', 'visibility' => 'public',
			'show_event_list' => 0, 'event_list_position' => '',
			'audience_badge_format' => '', 'booking_label_singular' => '', 'booking_label_plural' => '',
		);

		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_all')->with(array('status' => 'active'))->andReturn(array($private, $public));
		$schedRepoMock->shouldReceive('get_environment_label')->andReturn('Room');
		$schedRepoMock->shouldNotReceive('user_can_book');

		$envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
		$envRepoMock->shouldReceive('get_by_schedule')->with(5, 'active')->andReturn(array(
			(object) array('id' => '11', 'name' => 'Hall', 'color' => null),
		));
		$envRepoMock->shouldReceive('get_by_schedule')->with(6, 'active')->andReturn(array(
			(object) array('id' => '12', 'name' => 'Lab', 'color' => '#ff0000'),
		));

		$child = (object) array('id' => 3, 'name' => 'Class A', 'color' => '#111111', 'parent_id' => 2);
		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_hierarchical')->with('active')->andReturn(array(
			(object) array('id' => 2, 'name' => 'Teachers', 'color' => '#222222', 'children' => array($child)),
		));

		$result = AudienceShortcode::render(array());

		$this->assertStringContainsString('ffc-admin-bypass-notice', $result);
		$this->assertStringContainsString('ffc-schedule-select', $result, 'Two schedules must render the calendar selector.');
		$this->assertStringContainsString('ffc-event-list-panel', $result);
		$this->assertStringContainsString('ffc-event-list-below', $result);
		$this->assertStringContainsString('ffc-booking-modal', $result);
		$this->assertStringContainsString('ffc-new-booking-btn', $result);

		$config = $this->configOf($result);
		$this->assertTrue($config['canBook']);
		$this->assertSame('parent_name', $config['audienceBadgeFormat']);
		$this->assertSame('below', $config['eventListPosition']);
		$this->assertSame(30, $config['schedules'][0]['futureDaysLimit']);
		$this->assertNull($config['schedules'][1]['futureDaysLimit']);
		$this->assertSame('#3788d8', $config['schedules'][0]['environments'][0]['color'], 'A missing colour falls back to the default.');
		$this->assertSame('#ff0000', $config['schedules'][1]['environments'][0]['color']);
		$this->assertSame('Class A', $config['audiences'][0]['children'][0]['name']);
		$this->assertNull($config['audiences'][0]['parent_id']);

		$this->assertSame('reservation', $localized['strings']['booking']);
		$this->assertSame('reservations', $localized['strings']['bookings']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_logged_in_bypass_without_private_schedule_prints_no_notice(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('user_can')->justReturn(true);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(true);

		$public = (object) array('id' => 8, 'name' => 'Open', 'status' => 'active', 'visibility' => 'public');
		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_by_id')->with(8)->andReturn($public);
		$schedRepoMock->shouldReceive('get_environment_label')->andReturn('Room');

		$envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
		$envRepoMock->shouldReceive('get_by_schedule')->andReturn(array());

		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_hierarchical')->andReturn(array());

		$result = AudienceShortcode::render(array('schedule_id' => 8));

		$this->assertStringNotContainsString('ffc-admin-bypass-notice', $result);
		$this->assertStringNotContainsString('ffc-schedule-select', $result, 'One schedule needs no selector.');
		$this->assertStringNotContainsString('ffc-event-list-panel', $result);
		$this->assertSame('booking', $localized['strings']['booking'], 'No custom label keeps the default.');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_logged_in_admin_with_unknown_schedule_shows_no_access(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('user_can')->justReturn(true);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_by_id')->with(404)->andReturn(null);

		$result = AudienceShortcode::render(array('schedule_id' => 404));

		$this->assertStringContainsString('You do not have access to any calendars.', $result);
		$this->assertSame(array(), $localized, 'No script is localized when nothing is accessible.');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_logged_in_member_filters_schedules_and_books_per_schedule(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('user_can')->justReturn(false);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

		$a = (object) array('id' => 1, 'name' => 'A', 'status' => 'active', 'visibility' => 'public');
		$b = (object) array('id' => 2, 'name' => 'B', 'status' => 'active', 'visibility' => 'public');
		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_by_user_access')->with(7)->andReturn(array($a, $b));
		$schedRepoMock->shouldReceive('get_environment_label')->andReturn('Room');
		$schedRepoMock->shouldReceive('user_can_book')->with(2, 7)->once()->andReturn(true);

		$envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
		$envRepoMock->shouldReceive('get_by_schedule')->andReturn(array());

		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_user_audiences')->with(7, true)->andReturn(array(
			(object) array('id' => 9, 'name' => 'Mine', 'color' => '#000000', 'parent_id' => 4),
		));
		$audRepoMock->shouldNotReceive('get_hierarchical');

		$result = AudienceShortcode::render(array('schedule_id' => 2));

		$config = $this->configOf($result);
		$this->assertCount(1, $config['schedules'], 'Only the requested schedule survives the filter.');
		$this->assertSame(2, $config['schedules'][0]['id']);
		$this->assertTrue($config['canBook']);
		$this->assertSame(4, $config['audiences'][0]['parent_id']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_logged_in_member_without_booking_rights_cannot_book(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('user_can')->justReturn(false);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

		$a = (object) array('id' => 1, 'name' => 'A', 'status' => 'active', 'visibility' => 'public');
		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_by_user_access')->with(7)->andReturn(array($a));
		$schedRepoMock->shouldReceive('get_environment_label')->andReturn('Room');
		$schedRepoMock->shouldReceive('user_can_book')->with(1, 7)->andReturn(false);

		$envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
		$envRepoMock->shouldReceive('get_by_schedule')->andReturn(array());

		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_user_audiences')->andReturn(array());

		$result = AudienceShortcode::render(array());

		$this->assertFalse($this->configOf($result)['canBook']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_public_listing_shows_read_only_calendar_with_login_link(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('is_user_logged_in')->justReturn(false);
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn(false);

		$calRepoMock = Mockery::mock('alias:FreeFormCertificate\Repositories\CalendarRepository');
		$calRepoMock->shouldReceive('userHasSchedulingBypass')->andReturn(false);

		$a = (object) array('id' => 1, 'name' => 'A', 'status' => 'active', 'visibility' => 'public', 'show_event_list' => 1);
		$schedRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceScheduleRepository');
		$schedRepoMock->shouldReceive('get_all')->with(array('status' => 'active', 'visibility' => 'public'))->andReturn(array($a));
		$schedRepoMock->shouldReceive('get_environment_label')->andReturn('Room');

		$envRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository');
		$envRepoMock->shouldReceive('get_by_schedule')->andReturn(array());

		$audRepoMock = Mockery::mock('alias:FreeFormCertificate\Audience\AudienceReader');
		$audRepoMock->shouldReceive('get_hierarchical')->andReturn(array());

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ('ffc_aud_scheduling_message' === $key) {
				return 'Go <a href="%login_url%">here</a>';
			}
			return $default;
		});

		$result = AudienceShortcode::render(array());

		$config = $this->configOf($result);
		$this->assertTrue($config['readOnly']);
		$this->assertFalse($config['canBook']);
		$this->assertSame('side', $config['eventListPosition'], 'An enabled list with no position falls back to side.');
		$this->assertSame('Go <a href="https://example.com/login">here</a>', $config['schedulingMessage']);
		$this->assertStringContainsString('ffc-event-list-side', $result);
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

		$utilsMock = Mockery::mock('alias:FreeFormCertificate\Core\AssetHelper');
		$utilsMock->shouldReceive('asset_suffix')->andReturn('.min');
		// O shortcode passou a enfileirar o interruptor do modo escuro (#1126).
		$utilsMock->shouldReceive('enqueue_dark_mode');

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_private_display_mode') return 'show_message';
			if ($key === 'ffc_aud_visibility_message') return 'Please log in.';
			return $default;
		});
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');
	}
}
