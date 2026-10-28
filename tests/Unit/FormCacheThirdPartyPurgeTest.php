<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Debug;
use FreeFormCertificate\Submissions\FormCache;
use PHPUnit\Framework\TestCase;

/**
 * FormCache against the third-party page caches it purges.
 *
 * Each integration is detected with `class_exists()` / `function_exists()` /
 * `defined()`, so a test has to DECLARE the integration to reach its branch —
 * and a declared class, function or constant outlives the test in the process.
 * A test that declares one therefore runs in its own process (method-level
 * isolation, with the reason on the method), so the doubles it declares never
 * change which branch a later test elsewhere takes; the same isolation lets
 * `WP_DEBUG` be true here while `FormCacheTest` pins it false. A test that
 * declares nothing runs in the shared process.
 *
 * @covers \FreeFormCertificate\Submissions\FormCache
 */
class FormCacheThirdPartyPurgeTest extends TestCase {

	/**
	 * File the debug breadcrumbs land in.
	 */
	private string $log_file = '';

	/**
	 * The `error_log` ini value before the test, restored in tearDown.
	 *
	 * @var string|false
	 */
	private $saved_error_log = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['ffc_test_purges'] = array();
		$this->log_file             = (string) tempnam( sys_get_temp_dir(), 'ffc-log' );
		$this->saved_error_log      = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Captures Debug::log() output for assertion; restored in tearDown.

		// Form-processor debug area on, so a swallowed failure leaves a breadcrumb.
		Functions\when( 'get_option' )->justReturn( array( Debug::AREA_FORM_PROCESSOR => 1 ) );
	}

	protected function tearDown(): void {
		if ( false !== $this->saved_error_log ) {
			ini_set( 'error_log', $this->saved_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value setUp replaced.
		}
		unset( $GLOBALS['ffc_test_purges'], $GLOBALS['ffc_test_purge_throw'] );
		if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * What Debug::log() wrote during the test.
	 */
	private function log(): string {
		return (string) file_get_contents( $this->log_file );
	}

	/**
	 * Declare W3 Total Cache and LiteSpeed doubles that record or throw.
	 *
	 * @param bool $throw Whether every call throws.
	 */
	private function declare_cache_classes( bool $throw ): void {
		$GLOBALS['ffc_test_purge_throw'] = $throw;
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a third-party class inside this test's own process.
		eval(
			'namespace W3TC; class Dispatcher {
				public static $component;
				public static function component( $name ) {
					if ( ! empty( $GLOBALS["ffc_test_purge_throw"] ) ) { throw new \RuntimeException( "w3tc down" ); }
					$GLOBALS["ffc_test_purges"][] = "w3tc:component:" . $name;
					return self::$component;
				}
			}'
		);
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a third-party class inside this test's own process.
		eval(
			'namespace LiteSpeed; class Purge {
				public static function purge_all( $reason ) {
					if ( ! empty( $GLOBALS["ffc_test_purge_throw"] ) ) { throw new \RuntimeException( "litespeed down" ); }
					$GLOBALS["ffc_test_purges"][] = "litespeed:all:" . $reason;
				}
				public static function add( $tag ) {
					if ( ! empty( $GLOBALS["ffc_test_purge_throw"] ) ) { throw new \RuntimeException( "litespeed down" ); }
					$GLOBALS["ffc_test_purges"][] = "litespeed:add:" . $tag;
				}
			}'
		);
	}

	/**
	 * Declare the WP Super Cache / WP Rocket / W3TC function doubles.
	 *
	 * @param bool $throw Whether every call throws.
	 */
	private function declare_cache_functions( bool $throw ): void {
		$GLOBALS['ffc_test_purge_throw'] = $throw;
		$body = static fn( string $tag ): string => 'if ( ! empty( $GLOBALS["ffc_test_purge_throw"] ) ) { throw new \RuntimeException( "' . $tag . ' down" ); } $GLOBALS["ffc_test_purges"][] = "' . $tag . ':" . implode( ",", func_get_args() );';
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares third-party functions inside this test's own process.
		eval(
			'function wp_cache_clear_cache() { ' . $body( 'wpsc_clear' ) . ' }
			function wpsc_delete_post_cache( $id ) { ' . $body( 'wpsc_post' ) . ' }
			function rocket_clean_domain() { ' . $body( 'rocket_domain' ) . ' }
			function rocket_clean_post( $id ) { ' . $body( 'rocket_post' ) . ' }
			function w3tc_flush_posts() { ' . $body( 'w3tc_posts' ) . ' }'
		);
	}

	/**
	 * Declares the W3TC / LiteSpeed classes and the WP Super Cache / WP Rocket / W3TC functions with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_all_pages_flushes_every_detected_cache(): void {
		$this->declare_cache_classes( false );
		$this->declare_cache_functions( false );
		\W3TC\Dispatcher::$component = new class() {
			public function flush_pgcache() {
				$GLOBALS['ffc_test_purges'][] = 'w3tc:flush_pgcache';
			}
		};
		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		FormCache::purge_all_pages( 42, 'early_open' );

		$this->assertSame(
			array( 'w3tc:component:CacheFlush', 'w3tc:flush_pgcache', 'litespeed:all:FFC early_open', 'wpsc_clear:', 'rocket_domain:' ),
			$GLOBALS['ffc_test_purges']
		);
		$this->assertSame( array( array( 'ffc_form_cache_purged', 42, 'early_open:all' ) ), $actions );
		$this->assertSame( '', $this->log(), 'nothing failed, so nothing is logged' );
	}

	/**
	 * Declares the W3TC / LiteSpeed classes with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_all_pages_falls_back_to_flush_all_and_tolerates_a_missing_component(): void {
		$this->declare_cache_classes( false );
		Functions\when( 'do_action' )->justReturn( null );

		\W3TC\Dispatcher::$component = new class() {
			public function flush_all() {
				$GLOBALS['ffc_test_purges'][] = 'w3tc:flush_all';
			}
		};
		FormCache::purge_all_pages( 1 );
		$this->assertContains( 'w3tc:flush_all', $GLOBALS['ffc_test_purges'] );

		// A component with neither method, and no component at all, are skipped.
		$GLOBALS['ffc_test_purges']  = array();
		\W3TC\Dispatcher::$component = new \stdClass();
		FormCache::purge_all_pages( 1 );
		\W3TC\Dispatcher::$component = null;
		FormCache::purge_all_pages( 1 );

		$this->assertSame(
			array( 'w3tc:component:CacheFlush', 'litespeed:all:FFC ', 'w3tc:component:CacheFlush', 'litespeed:all:FFC ' ),
			$GLOBALS['ffc_test_purges']
		);
	}

	/**
	 * Declares throwing W3TC / LiteSpeed classes and cache functions with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_all_pages_survives_every_integration_throwing_and_logs_each(): void {
		$this->declare_cache_classes( true );
		$this->declare_cache_functions( true );
		$fired = false;
		Functions\when( 'do_action' )->alias(
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		FormCache::purge_all_pages( 42, 'geofence_changed' );

		$this->assertTrue( $fired, 'the action still fires after every integration threw' );
		$log = $this->log();
		$this->assertSame( 4, substr_count( $log, 'External cache purge failed' ) );
		foreach ( array( 'w3tc down', 'litespeed down', 'wpsc_clear down', 'rocket_domain down' ) as $error ) {
			$this->assertStringContainsString( $error, $log );
		}
	}

	/**
	 * Declares the W3TC / LiteSpeed classes and the cache functions with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_external_caches_purges_the_form_in_every_detected_cache(): void {
		$this->declare_cache_classes( false );
		$this->declare_cache_functions( false );
		\W3TC\Dispatcher::$component = new class() {
			public function flush_post( $id ) {
				$GLOBALS['ffc_test_purges'][] = 'w3tc:flush_post:' . $id;
			}
		};
		Functions\when( 'do_action' )->justReturn( null );

		FormCache::purge_external_caches( 5, 'early_open' );

		$this->assertSame(
			array( 'w3tc:component:CacheFlush', 'w3tc:flush_post:5', 'litespeed:add:P_5', 'wpsc_post:5', 'rocket_post:5' ),
			$GLOBALS['ffc_test_purges']
		);
	}

	/**
	 * Declares throwing W3TC / LiteSpeed classes and cache functions with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_external_caches_logs_each_failure_with_the_form_id(): void {
		$this->declare_cache_classes( true );
		$this->declare_cache_functions( true );
		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		FormCache::purge_external_caches( 5, 'geofence_changed' );

		$this->assertSame( array( array( 'ffc_form_cache_purged', 5, 'geofence_changed' ) ), $actions );
		$log = $this->log();
		$this->assertSame( 4, substr_count( $log, 'External cache purge failed' ) );
		foreach ( array( 'w3tc down', 'litespeed down', 'wpsc_post down', 'rocket_post down' ) as $error ) {
			$this->assertStringContainsString( $error, $log );
		}
	}

	/**
	 * Defines LSCWP_V and declares the cache functions with eval.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_page_cache_purges_the_embedding_pages(): void {
		define( 'LSCWP_V', '6.0' );
		$this->declare_cache_functions( false );
		$queried = null;
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$queried ) {
				$queried = $args;
				return array( 10, 11 );
			}
		);
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 10 === $id ? 'https://example.org/page-10/' : false );
		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		FormCache::purge_page_cache( 7, 'ffc_self_scheduling' );

		$this->assertSame( '[ffc_self_scheduling id="7"', $queried['s'] );
		$this->assertSame( array( array( 'litespeed_purge_url', 'https://example.org/page-10/' ) ), $actions, 'a page without a permalink is skipped' );
		$this->assertSame( array( 'rocket_post:10', 'rocket_post:11', 'w3tc_posts:', 'wpsc_clear:' ), $GLOBALS['ffc_test_purges'] );
	}

	/**
	 * Defines LSCWP_V, which no later test may inherit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_purge_page_cache_purges_all_of_litespeed_when_no_page_embeds_the_form(): void {
		define( 'LSCWP_V', '6.0' );
		Functions\when( 'get_posts' )->justReturn( array() );
		$actions = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$actions ) {
				$actions[] = $args;
			}
		);

		FormCache::purge_page_cache( 7, 'ffc_form' );

		$this->assertSame( array( array( 'litespeed_purge_all' ) ), $actions );
	}

	/**
	 * Defines WP_DEBUG as true, while FormCacheTest pins it false in the shared process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_form_config_logs_the_miss_and_the_hit_under_wp_debug(): void {
		define( 'WP_DEBUG', true );
		$cached = false;
		Functions\when( 'wp_cache_get' )->alias(
			static function () use ( &$cached ) {
				return $cached;
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key, $value ) use ( &$cached ) {
				$cached = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( array( 'title' => 'Form' ) );

		$this->assertSame( array( 'title' => 'Form' ), FormCache::get_form_config( 3 ) );
		$this->assertSame( array( 'title' => 'Form' ), FormCache::get_form_config( 3 ) );

		$log = $this->log();
		$this->assertStringContainsString( 'Form config cache MISS', $log );
		$this->assertStringContainsString( 'Form config cache HIT', $log );
	}

	public function test_the_scheduled_warming_warms_every_form_when_the_toggle_is_on(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_enabled'   => 1,
				'cache_auto_warm' => 1,
			)
		);
		$queried = null;
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$queried ) {
				$queried = $args;
				return array( 4 );
			}
		);
		$warmed = array();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key ) use ( &$warmed ) {
				$warmed[] = $key;
				return true;
			}
		);

		FormCache::run_scheduled_warming();

		$this->assertSame( 'ffc_form', $queried['post_type'] );
		$this->assertSame( array( 'complete_4' ), $warmed );
	}
}
