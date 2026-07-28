<?php
/**
 * Base class for FFCertificate integration (wiring) tests.
 *
 * Unlike the unit suite — which overload/alias-mocks a class's collaborators to
 * assert a single unit in isolation — the integration suite boots the REAL
 * plugin object graph and only stubs the WordPress boundary (the global `wp_*`
 * functions and `$wpdb`). The goal is a wiring smoke: prove the composition root
 * and the module loaders newing-up real classes together compose without a
 * fatal, and that the bootstrap lands the hooks / crons / roles it promises.
 *
 * The WordPress boundary is stubbed with permissive, side-effect-free defaults
 * so any collaborator constructor reachable from the bootstrap can run. Hook
 * registrations (`add_action` / `add_filter` / `add_shortcode`), option
 * writes and cron scheduling are RECORDED into arrays a test can assert on,
 * rather than dropped — that recording is what turns "it didn't fatal" into a
 * positive wiring assertion.
 *
 * @package FreeFormCertificate\Tests\Integration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real graph over a recorded WordPress boundary.
 */
abstract class IntegrationTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Recorded `add_action()` calls.
	 *
	 * @var array<int, array{hook: string, callback: mixed, priority: int, args: int}>
	 */
	protected array $actions = array();

	/**
	 * Recorded `add_filter()` calls.
	 *
	 * @var array<int, array{hook: string, callback: mixed, priority: int, args: int}>
	 */
	protected array $filters = array();

	/**
	 * Recorded `add_shortcode()` tags.
	 *
	 * @var array<int, string>
	 */
	protected array $shortcodes = array();

	/**
	 * Recorded `wp_schedule_event()` hook names.
	 *
	 * @var array<int, string>
	 */
	protected array $scheduled = array();

	/**
	 * Recorded `register_rest_route()` route definitions (namespace + route).
	 *
	 * @var array<int, string>
	 */
	protected array $rest_routes = array();

	/**
	 * Backing store for the option get/update stubs. Seed entries in a test's
	 * own setUp (before booting) to steer module toggles or migration flags.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wpdb'] = new \wpdb();

		$this->actions     = array();
		$this->filters     = array();
		$this->shortcodes  = array();
		$this->scheduled   = array();
		$this->rest_routes = array();
		$this->options     = array();

		$this->install_wp_boundary();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Install permissive, recording stubs for the WordPress functions the
	 * bootstrap graph reaches. Passthrough/no-op defaults everywhere except the
	 * four "wiring outputs" we assert on (hooks, shortcodes, cron, REST).
	 */
	protected function install_wp_boundary(): void {
		// --- Hook + shortcode registration: recorded. -------------------------
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null, $priority = 10, $args = 1 ) {
				$this->actions[] = array(
					'hook'     => (string) $hook,
					'callback' => $callback,
					'priority' => (int) $priority,
					'args'     => (int) $args,
				);
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback = null, $priority = 10, $args = 1 ) {
				$this->filters[] = array(
					'hook'     => (string) $hook,
					'callback' => $callback,
					'priority' => (int) $priority,
					'args'     => (int) $args,
				);
				return true;
			}
		);
		Functions\when( 'add_shortcode' )->alias(
			function ( $tag, $callback = null ) {
				$this->shortcodes[] = (string) $tag;
				return true;
			}
		);

		// --- Cron scheduling: recorded; nothing is ever "already scheduled". --
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $timestamp, $recurrence, $hook ) {
				$this->scheduled[] = (string) $hook;
				return true;
			}
		);
		Functions\when( 'wp_get_schedules' )->justReturn( array() );

		// --- REST route registration: recorded. -------------------------------
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route = '', $args = array() ) {
				$this->rest_routes[] = trim( (string) $namespace, '/' ) . '/' . ltrim( (string) $route, '/' );
				return true;
			}
		);

		// --- Options: backed by $this->options. -------------------------------
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);

		// --- Registration APIs: harmless no-ops. ------------------------------
		Functions\when( 'register_activation_hook' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );
		Functions\when( 'register_post_type' )->justReturn( null );
		Functions\when( 'register_taxonomy' )->justReturn( null );
		Functions\when( 'register_setting' )->justReturn( true );
		Functions\when( 'register_meta' )->justReturn( true );

		// --- Assets: no-ops (the unit LoaderTest already asserts their args). --
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_style_is' )->justReturn( false );

		// --- URL / path helpers: deterministic strings. -----------------------
		Functions\when( 'admin_url' )->alias(
			static fn( $path = '' ) => 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.test/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'site_url' )->alias(
			static fn( $path = '' ) => 'https://example.test/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'rest_url' )->alias(
			static fn( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'plugins_url' )->alias(
			static fn( $path = '', $plugin = '' ) => 'https://example.test/wp-content/plugins/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'plugin_dir_url' )->justReturn( 'https://example.test/wp-content/plugins/ffcertificate/' );
		Functions\when( 'plugin_dir_path' )->justReturn( FFC_PLUGIN_DIR );

		// --- Context flags: frontend, single-site, logged-out by default. -----
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// --- Roles / users: empty catalog + no-op mutators. The cold-boot path
		// runs the one-shot capability/role migrations, so their WP calls
		// (add_role / get_users / get_userdata) must resolve to safe no-ops.
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'wp_roles' )->justReturn( null );
		Functions\when( 'add_role' )->justReturn( null );
		Functions\when( 'remove_role' )->justReturn( null );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'xxxxxxxxxxxxxxxx' );
		Functions\when( 'current_time' )->alias(
			static fn( $type = 'timestamp' ) => ( 'timestamp' === $type ? 1_600_000_000 : '2020-09-13 12:26:40' )
		);
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );

		// --- Sanitizers / escapers / i18n: identity-ish passthroughs. ---------
		Functions\stubs(
			array(
				'__',
				'_e',
				'_x',
				'esc_html__',
				'esc_html_e',
				'esc_attr__',
				'esc_attr_e',
				'esc_html',
				'esc_attr',
				'esc_url',
				'esc_url_raw',
				'esc_textarea',
				'sanitize_text_field',
				'sanitize_key',
				'sanitize_title',
				'wp_unslash',
				'wp_kses_post',
				'trailingslashit',
				'untrailingslashit',
				'wp_normalize_path',
			)
		);
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $number ) => ( (int) $number === 1 ? $single : $plural )
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

		// --- Transients / object cache: cold cache. ---------------------------
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->justReturn( true );

		// --- Misc. ------------------------------------------------------------
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( 'FFC Test Site' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Seed the one-shot migration + cap-grant completion flags so the bootstrap
	 * takes its steady-state path (the branch that runs on every request after
	 * the first) instead of the cold-install path. The one-shot capability/role
	 * migrations need a live WP_Roles object and are exercised by their own unit
	 * tests (CapabilityMigratorTest / RoleRegistrarTest); a bootstrap *wiring*
	 * smoke should not re-run them. Call from a test's setUp() after
	 * parent::setUp().
	 */
	protected function seed_completed_migration_flags(): void {
		foreach (
			array(
				'ffc_false_caps_stripped_v1',
				'ffc_taxonomy_caps_renamed_v1',
				'ffc_delete_caps_granted_v1',
				'ffc_settings_split_caps_v1',
				'ffc_activity_log_export_cap_v1',
				'ffc_url_shortener_export_cap_v1',
				'ffc_export_caps_granted_v1',
				'ffc_import_caps_granted_v1',
				'ffc_reasons_caps_wired_v1',
				'ffc_admin_role_assigned_v1',
				'ffc_rbac_caps_renamed_v1',
				'ffc_rbac_roles_renamed_v1',
			) as $flag
		) {
			$this->options[ $flag ] = '1';
		}
		// ensure_admin_capabilities() fast-returns when its version key equals FFC_VERSION.
		$this->options['ffc_admin_caps_version_v6'] = FFC_VERSION;
	}

	/**
	 * True if any recorded action targets $hook. When $callback_method is
	 * given, additionally require the callback to be an object/class method of
	 * that name (matching the `[ $obj, 'method' ]` / `[ 'Class', 'method' ]`
	 * shape the bootstrap uses).
	 */
	protected function assertActionRegistered( string $hook, ?string $callback_method = null, string $message = '' ): void {
		$hits = array_filter(
			$this->actions,
			static function ( $entry ) use ( $hook, $callback_method ) {
				if ( $entry['hook'] !== $hook ) {
					return false;
				}
				if ( null === $callback_method ) {
					return true;
				}
				return is_array( $entry['callback'] )
					&& isset( $entry['callback'][1] )
					&& $entry['callback'][1] === $callback_method;
			}
		);

		$this->assertNotEmpty(
			$hits,
			'' !== $message ? $message : "Expected an add_action() for '{$hook}'" . ( null !== $callback_method ? "::{$callback_method}" : '' ) . '.'
		);
	}
}
