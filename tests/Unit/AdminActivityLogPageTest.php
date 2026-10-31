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
 * enqueue_scripts, render_page (enabled + disabled), build_query_args,
 * render_rows_html, render_pagination_html, plus the static label/badge/summary
 * helpers. The page has no standalone menu since #804 (it renders as the
 * Activity Log Settings tab). The CSV export now runs through the batched
 * engine (issue #772); its per-source behavior lives in
 * ActivityLogExportSourceTest.
 *
 * Every test runs in the shared process against the real core helpers
 * (AssetHelper, AssetRegistry, SettingsReader, RequestInput, ActivityLogQuery,
 * DateFormatter): settings come from a `get_option` stub, and the log table
 * from a `$wpdb` double that answers each query by its SQL text.
 *
 * @covers \FreeFormCertificate\Admin\AdminActivityLogPage
 */
class AdminActivityLogPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The superglobal as an earlier test left it. */
	private array $get_backup = array();

	/** @var array<string, mixed> The superglobal as an earlier test left it. */
	private array $post_backup = array();

	/** @var list<array{0: string, 1: string}> Every read the `$wpdb` double answered, as array( method, SQL ). */
	private array $ran = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->get_backup  = $_GET ?? array();
		$this->post_backup = $_POST ?? array();
		$this->ran         = array();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		class_exists('\\FreeFormCertificate\\Admin\\AdminActivityLogPage');

		// The address reveal (#2109): a user who may not reveal, unless a
		// test says otherwise.
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('user_can')->justReturn(false);

		// Common WP function stubs
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_url')->returnArg();
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('absint')->alias(static fn($v) => (int) $v);
		Functions\when('sanitize_key')->alias(static fn($v) => strtolower((string) $v));
		Functions\when('wp_unslash')->returnArg();
		Functions\when('admin_url')->returnArg();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET  = $this->get_backup;
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The plugin settings the real SettingsReader reads; any other option
	 * read fails the test.
	 *
	 * @param array<string, mixed> $settings The `ffc_settings` option.
	 */
	private function stub_settings( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) use ( $settings ) {
				if ( 'ffc_settings' === $key ) {
					return $settings;
				}
				$this->fail( 'Unexpected get_option(): ' . $key );
			}
		);
	}

	/**
	 * What the real DateFormatter needs to render a row: default settings,
	 * a UTC site timezone and a `wp_date` that formats in it.
	 */
	private function stub_date_formatting(): void {
		$this->stub_settings( array() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( (string) $format )
		);
	}

	/**
	 * A `$wpdb` double for an empty activity log holding one distinct action:
	 * the page query, the count, the action list and the 30-day stats. Any
	 * other query fails the test.
	 */
	private function install_empty_log(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( ...$args ) => (string) $args[0]
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_results', (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT * FROM %i WHERE 1=1' ) ) {
					return array();
				}
				if ( false !== strpos( (string) $sql, 'GROUP BY' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_var', (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT COUNT(*) FROM %i WHERE' ) ) {
					return '0';
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = array( 'get_col', (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT DISTINCT action FROM %i' ) ) {
					return array( 'submission_created' );
				}
				$this->fail( 'Unexpected get_col(): ' . $sql );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
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

	public function test_enqueue_scripts_returns_early_on_settings_hook_but_other_tab(): void {
		$_GET['tab'] = 'general';
		$called      = false;
		Functions\when('wp_enqueue_script')->alias(function () use (&$called) {
			$called = true;
		});

		$page = new AdminActivityLogPage();
		$page->enqueue_scripts('toplevel_page_ffc-settings');

		$this->assertFalse($called);
	}

	public function test_enqueue_scripts_enqueues_and_localizes_on_correct_hook(): void {
		$scripts = [];
		Functions\when('wp_enqueue_script')->alias(function ($handle) use (&$scripts) {
			$scripts[] = $handle;
		});
		// The page also enqueues the token palette (#1126 B) and the shared
		// progress-overlay stylesheet (#786).
		$styles = [];
		Functions\when('wp_enqueue_style')->alias(function ($handle) use (&$styles) {
			$styles[] = $handle;
		});
		Functions\when('wp_create_nonce')->justReturn('nonce123');
		$localized = null;
		Functions\when('wp_localize_script')->alias(function ($handle, $var, $data) use (&$localized) {
			if ('ffcActivityLog' === $var) {
				$localized = $data;
			}
		});

		$_GET['tab'] = 'activity_log';
		$page        = new AdminActivityLogPage();
		$page->enqueue_scripts('toplevel_page_ffc-settings');

		$this->assertContains('ffc-core', $scripts);
		$this->assertContains('ffc-batched-export', $scripts);
		$this->assertContains('ffc-admin-activity-log', $scripts);
		$this->assertContains('ffc-common', $styles, 'enqueue_common_style() puts the token palette on the screen.');
		$this->assertContains('ffc-progress-overlay', $styles);
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
		$this->stub_settings(array('enable_activity_log' => 0));
		// No `$wpdb` at all: the disabled page must not query the log.
		unset($GLOBALS['wpdb']);

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
		$this->stub_settings(array('enable_activity_log' => 1));
		$this->install_empty_log();
		// No filter in the request; nothing cached, so every read reaches the table.
		$_GET = array();
		Functions\when('wp_parse_args')->alias(static fn($args, $defaults) => array_merge($defaults, $args));
		Functions\when('get_transient')->justReturn(false);
		$cached = array();
		Functions\when('set_transient')->alias(function ($key) use (&$cached) {
			$cached[] = $key;
			return true;
		});

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
		// The distinct action reaches the filter, labelled.
		$this->assertStringContainsString('value="submission_created"', $output);
		$this->assertStringContainsString('Submission Created', $output);
		// The page, the count, the action list and the stats each read the table.
		$reads = array_map(static fn($r) => $r[0], $this->ran);
		$this->assertContains('get_col', $reads);
		$this->assertGreaterThanOrEqual(2, count(array_keys($reads, 'get_var', true)), 'The count and the stats total.');
		$this->assertContains('ffc_activity_stats_30', $cached);
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
		$this->stub_date_formatting();

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
		$this->assertStringContainsString('203.0.***.***', $html);
		$this->assertStringNotContainsString('203.0.113.5', $html, 'the address is masked (#2109)');
		$this->assertStringNotContainsString('ffc-reveal-pii', $html, 'no Reveal without the cap');
		$this->assertStringContainsString('Submission Created', $html);
		$this->assertStringContainsString('<details>', $html);
	}

	public function test_a_holder_of_the_reveal_cap_gets_a_reveal_and_details_stay_masked(): void {
		$this->stub_date_formatting();
		Functions\when('get_userdata')->justReturn(false);
		Functions\when('wp_json_encode')->alias(static fn($v, $f = 0) => json_encode($v, $f));
		Functions\when('esc_html_e')->alias(function ($t) { echo $t; });
		Functions\when('wp_create_nonce')->justReturn('nonce-123');
		Functions\when('user_can')->alias(static fn($uid, $cap) => 'ffc_view_activity_log_pii' === $cap);

		$html = AdminActivityLogPage::render_rows_html([
			[
				'id'         => 77,
				'created_at' => '2026-01-01 00:00:00',
				'level'      => 'info',
				'action'     => 'access_denied',
				'user_id'    => 0,
				'user_ip'    => '2804:14c:1:2::9',
				'context'    => ['identifier' => '198.51.100.7', 'nested' => ['ip' => '::ffff:192.0.2.1'], 'form_id' => 42],
			],
		]);

		$this->assertStringContainsString('2804:14c:1:2:****:****:****:****', $html);
		$this->assertStringContainsString('ffc-reveal-pii', $html);
		$this->assertStringContainsString('data-type="activity_log"', $html);
		$this->assertStringContainsString('data-submission-id="77"', $html);
		$this->assertStringContainsString('198.51.***.***', $html);
		$this->assertStringContainsString('192.0.***.***', $html);
		$this->assertStringNotContainsString('198.51.100.7', $html, 'an address in the details is masked whatever its key');
		$this->assertStringNotContainsString('192.0.2.1', $html);
		$this->assertStringContainsString('42', $html);
	}

	public function test_render_rows_html_renders_deleted_user_and_anonymous(): void {
		$this->stub_date_formatting();

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
		$this->stub_date_formatting();

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
		// The real DateFormatter::format_schedule() renders both ranges.
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
		$this->assertStringContainsString( '203.0.***.***', $html );
		$this->assertStringNotContainsString( '203.0.113.5', $html );
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
		$this->assertStringContainsString( '203.0.***.***', $html );
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

	public function test_render_preflight_blocked_summary_names_a_typed_row(): void {
		$html = AdminActivityLogPage::render_preflight_blocked_summary(
			'preflight_blocked',
			array(
				'type'    => 'csv_download',
				'id'      => 15,
				'reason'  => 'secure',
				'ip_hash' => '',
			)
		);

		$this->assertStringContainsString( 'CSV download page ID', (string) $html );
		$this->assertStringContainsString( '15', (string) $html );
		$this->assertStringNotContainsString( 'Form ID', (string) $html );
	}
}
