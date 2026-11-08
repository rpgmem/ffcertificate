<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\FormCache;

/**
 * Tests for FormCache: caching layer for form configurations.
 *
 * Covers cache get/set patterns (hit/miss), expiration settings,
 * cache clearing, warming, statistics, hook registration, and
 * save/delete callbacks.
 *
 * @covers \FreeFormCertificate\Submissions\FormCache
 */
class FormCacheTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The form background and the receipt background are cleaned on read (#2344).
		Functions\when( 'esc_url_raw' )->returnArg();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', false );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_expiration()
	// ==================================================================

	// ==================================================================
	// get_form_config()
	// ==================================================================

	// ==================================================================
	// get_form_fields()
	// ==================================================================

	// ==================================================================
	// get_form_background()
	// ==================================================================

	// ==================================================================
	// get_form_complete()
	// ==================================================================

	// ==================================================================
	// get_form_post()
	// ==================================================================

	// ==================================================================
	// clear_form_cache()
	// ==================================================================

	// ==================================================================
	// clear_all_cache()
	// ==================================================================

	// ==================================================================
	// warm_cache()
	// ==================================================================

	// ==================================================================
	// warm_all_forms()
	// ==================================================================

	// ==================================================================
	// get_stats()
	// ==================================================================

	// ==================================================================
	// check_form_cache_status()
	// ==================================================================

	// ==================================================================
	// get_cache_key()
	// ==================================================================

	// ==================================================================
	// register_hooks()
	// ==================================================================

	public function test_register_hooks_only_wires_the_page_cache_purge_on_save(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);

		FormCache::register_hooks();

		// No invalidation on delete and no warming listener: the cache they
		// served is retired (#2241).
		$this->assertSame( array( 'save_post_ffc_form' ), $hooks );
	}

	// ==================================================================
	// retire_warming_schedule() (#2241)
	// ==================================================================

	public function test_retire_clears_the_event_an_install_still_carries(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1709308800 );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'ffcertificate_warm_cache_hook' );

		FormCache::retire_warming_schedule();
	}

	public function test_retire_does_nothing_once_the_event_is_gone(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		FormCache::retire_warming_schedule();
	}

	// ==================================================================
	// on_form_saved()
	// ==================================================================

	public function test_on_form_saved_skips_revisions(): void {
		$post = Mockery::mock( \WP_Post::class );
		$post->post_status = 'publish';
		$post->post_type   = 'ffc_form';

		Functions\expect( 'wp_is_post_revision' )
			->once()
			->with( 100 )
			->andReturn( true );

		Functions\expect( 'wp_is_post_autosave' )->never();
		Functions\expect( 'wp_cache_delete' )->never();

		FormCache::on_form_saved( 100, $post, true );
	}

	public function test_on_form_saved_skips_autosaves(): void {
		$post = Mockery::mock( \WP_Post::class );
		$post->post_status = 'publish';
		$post->post_type   = 'ffc_form';

		Functions\expect( 'wp_is_post_revision' )
			->once()
			->with( 101 )
			->andReturn( false );

		Functions\expect( 'wp_is_post_autosave' )
			->once()
			->with( 101 )
			->andReturn( true );

		Functions\expect( 'wp_cache_delete' )->never();

		FormCache::on_form_saved( 101, $post, false );
	}

	/**
	 * Saving a form, published or not, purges the pages that embed it and
	 * no longer touches the retired object cache (#2241).
	 *
	 * @dataProvider statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_on_form_saved_purges_pages_and_leaves_the_object_cache( string $status ): void {
		$post              = Mockery::mock( \WP_Post::class );
		$post->post_status = $status;
		$post->post_type   = 'ffc_form';

		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\expect( 'wp_cache_delete' )->never();
		Functions\expect( 'wp_cache_get' )->never();
		Functions\expect( 'wp_cache_set' )->never();

		$searches = array();
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$searches ): array {
				$searches[] = $args['s'];
				return array();
			}
		);

		FormCache::on_form_saved( 200, $post, true );

		$this->assertSame( array( '[ffc_form id="200"' ), $searches );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function statuses(): array {
		return array(
			'published' => array( 'publish' ),
			'draft'     => array( 'draft' ),
		);
	}

	// ==================================================================
	// on_form_deleted()
	// ==================================================================

	// ==================================================================
	// schedule_cache_warming()
	// ==================================================================

	// ==================================================================
	// unschedule_cache_warming()
	// ==================================================================

	public function test_unschedule_cache_warming_clears_the_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( 'ffcertificate_warm_cache_hook' )
			->andReturn( 1 );

		FormCache::unschedule_cache_warming();
	}

	// ==================================================================
	// sync_warming_schedule() / run_scheduled_warming() (#1541)
	// ==================================================================

	/**
	 * @param array<string, mixed> $settings Stored ffc_settings.
	 */
	private function settings( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? $settings : $default
		);
	}

	/**
	 * The warming retired with the cache (#2241): a toggle an install still
	 * stores schedules nothing.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public function toggles(): array {
		return array(
			'defaults (toggle off)' => array( array(), false ),
			'toggle on, cache on'   => array( array( 'cache_auto_warm' => 1 ), false ),
			'toggle on, cache off'  => array( array( 'cache_auto_warm' => 1, 'cache_enabled' => 0 ), false ),
		);
	}

	// ==================================================================
	// CACHE_GROUP constant
	// ==================================================================

	// ==================================================================
	// purge_external_caches() — third-party cache plugin integration
	// ==================================================================

	public function test_purge_external_caches_skips_when_form_id_is_zero(): void {
		$fired = false;
		Functions\when( 'do_action' )->alias( function () use ( &$fired ) {
			$fired = true;
		} );

		FormCache::purge_external_caches( 0 );

		$this->assertFalse( $fired, 'Hook should not fire for non-positive form_id' );
	}

	public function test_purge_external_caches_fires_action_hook_with_form_id_and_reason(): void {
		$captured = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, $form_id = null, $reason = null ) use ( &$captured ) {
				if ( 'ffc_form_cache_purged' === $hook ) {
					$captured = array( $form_id, $reason );
				}
			}
		);

		FormCache::purge_external_caches( 42, 'early_open' );

		$this->assertSame( array( 42, 'early_open' ), $captured );
	}

	public function test_purge_external_caches_fires_with_empty_reason_by_default(): void {
		$captured = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, $form_id = null, $reason = null ) use ( &$captured ) {
				if ( 'ffc_form_cache_purged' === $hook ) {
					$captured = array( $form_id, $reason );
				}
			}
		);

		FormCache::purge_external_caches( 7 );

		$this->assertSame( array( 7, '' ), $captured );
	}

	/**
	 * Declare the WP Super Cache double once, for every test that reaches it.
	 *
	 * A function cannot be undeclared, so whichever test runs first decides the
	 * body for the rest of the process: one shared body that both records the
	 * call and throws on request keeps the two tests independent of their order.
	 */
	private static function declare_wp_super_cache(): void {
		if ( ! function_exists( 'wpsc_delete_post_cache' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a third-party function the class under test probes for.
			eval( 'function wpsc_delete_post_cache( $id ) { if ( ! empty( $GLOBALS["__wpsc_should_throw"] ) ) { throw new \RuntimeException( "boom" ); } $GLOBALS["__wpsc_called_with"] = $id; }' );
		}
	}

	public function test_purge_external_caches_calls_wp_super_cache_when_available(): void {
		$GLOBALS['__wpsc_called_with'] = null;
		$GLOBALS['__wpsc_should_throw'] = false;
		self::declare_wp_super_cache();

		Functions\when( 'do_action' )->justReturn( true );

		FormCache::purge_external_caches( 99, 'test' );

		$this->assertSame( 99, $GLOBALS['__wpsc_called_with'] );
		unset( $GLOBALS['__wpsc_called_with'], $GLOBALS['__wpsc_should_throw'] );
	}

	public function test_purge_external_caches_calls_wp_rocket_when_available(): void {
		$GLOBALS['__rocket_called_with'] = null;
		if ( ! function_exists( 'rocket_clean_post' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- defining a missing global stub for the test.
			eval( 'function rocket_clean_post( $id ) { $GLOBALS["__rocket_called_with"] = $id; }' );
		}

		Functions\when( 'do_action' )->justReturn( true );

		FormCache::purge_external_caches( 123, 'manual_clear_all' );

		$this->assertSame( 123, $GLOBALS['__rocket_called_with'] );
		unset( $GLOBALS['__rocket_called_with'] );
	}

	public function test_purge_external_caches_swallows_third_party_exceptions(): void {
		$GLOBALS['__wpsc_should_throw'] = true;
		self::declare_wp_super_cache();
		$hook_fired = false;
		Functions\when( 'do_action' )->alias( function () use ( &$hook_fired ) {
			$hook_fired = true;
		} );
		// The best-effort catch now leaves a gated debug breadcrumb (Debug::log_form → get_option).
		Functions\when( 'get_option' )->justReturn( array() );

		// Must NOT throw — the host action keeps going.
		FormCache::purge_external_caches( 50 );

		$this->assertTrue( $hook_fired, 'Hook should still fire after third-party throws' );
		unset( $GLOBALS['__wpsc_should_throw'] );
	}

	// ==================================================================
	// purge_external_caches_for_all_forms()
	// ==================================================================

	public function test_purge_external_caches_for_all_forms_iterates_published_forms(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11, 22, 33 ) );
		// Defensive: if a per-form purge hits a best-effort catch, the breadcrumb reads get_option.
		Functions\when( 'get_option' )->justReturn( array() );

		$hook_calls = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, $form_id = null, $reason = null ) use ( &$hook_calls ) {
				if ( 'ffc_form_cache_purged' === $hook ) {
					$hook_calls[] = array( $form_id, $reason );
				}
			}
		);

		$count = FormCache::purge_external_caches_for_all_forms( 'manual_clear_all' );

		$this->assertSame( 3, $count );
		$this->assertSame(
			array(
				array( 11, 'manual_clear_all' ),
				array( 22, 'manual_clear_all' ),
				array( 33, 'manual_clear_all' ),
			),
			$hook_calls
		);
	}

	public function test_purge_external_caches_for_all_forms_handles_empty_list(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( true );

		$count = FormCache::purge_external_caches_for_all_forms();

		$this->assertSame( 0, $count );
	}

	// ==================================================================
	// purge_all_pages() — aggressive site-wide page-cache flush
	// ==================================================================

	public function test_purge_all_pages_fires_action_hook_with_all_suffix(): void {
		$captured = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, $form_id = null, $reason = null ) use ( &$captured ) {
				if ( 'ffc_form_cache_purged' === $hook ) {
					$captured = array( $form_id, $reason );
				}
			}
		);

		FormCache::purge_all_pages( 42, 'early_open' );

		// ':all' suffix lets subscribers distinguish the per-post hook
		// (`early_open`) from the aggressive sweep (`early_open:all`).
		$this->assertSame( array( 42, 'early_open:all' ), $captured );
	}

	public function test_purge_all_pages_calls_wp_super_cache_clear(): void {
		$GLOBALS['__wpsc_cleared'] = false;
		if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub for the test.
			eval( 'function wp_cache_clear_cache() { $GLOBALS["__wpsc_cleared"] = true; }' );
		}
		Functions\when( 'do_action' )->justReturn( true );

		FormCache::purge_all_pages( 7, 'geofence_changed' );

		$this->assertTrue( $GLOBALS['__wpsc_cleared'] );
		unset( $GLOBALS['__wpsc_cleared'] );
	}

	public function test_purge_all_pages_calls_wp_rocket_clean_domain(): void {
		$GLOBALS['__rocket_cleaned'] = false;
		if ( ! function_exists( 'rocket_clean_domain' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub for the test.
			eval( 'function rocket_clean_domain() { $GLOBALS["__rocket_cleaned"] = true; }' );
		}
		Functions\when( 'do_action' )->justReturn( true );

		FormCache::purge_all_pages( 99 );

		$this->assertTrue( $GLOBALS['__rocket_cleaned'] );
		unset( $GLOBALS['__rocket_cleaned'] );
	}

}
