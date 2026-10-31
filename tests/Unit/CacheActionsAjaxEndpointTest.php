<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CacheActionsAjaxEndpoint;

/**
 * Tests for the cache-actions AJAX endpoint introduced in 6.5.5. The "Warm"
 * action left with the form-settings cache in 6.43.0 (#2241); "Clear" now
 * purges the page caches of every published form and nothing else.
 *
 * Capabilities and FormCache are the real classes: the capability check reads
 * `current_user_can()` and the purge reads the published forms through
 * `get_posts()` (#2127).
 *
 * @covers \FreeFormCertificate\Admin\CacheActionsAjaxEndpoint
 */
class CacheActionsAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** How many times the object cache was flushed. */
	private int $flushes = 0;

	/**
	 * Arguments of each `ffc_form_cache_purged` action.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $purged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( function ( $singular, $plural, $count ) {
			return $count === 1 ? $singular : $plural;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->flushes = 0;
		$this->purged  = array();
		// The retired form-settings cache is never flushed again (#2241).
		Functions\when( 'wp_cache_flush_group' )->alias(
			function (): bool {
				++$this->flushes;
				return true;
			}
		);
		Functions\when( 'wp_cache_flush' )->alias(
			function (): bool {
				++$this->flushes;
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				if ( 'ffc_form_cache_purged' === $hook ) {
					$this->purged[] = $args;
				}
			}
		);

		// wp_send_json_* throw so we can assert which branch fired.
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
			throw new \RuntimeException( 'json_error: ' . $msg );
		} );
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			// Encode count + message so tests can fish them out.
			$payload = wp_json_encode( $data );
			throw new \RuntimeException( 'json_success: ' . $payload );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The published forms `get_posts()` answers.
	 *
	 * @param list<int> $ids Form ids.
	 */
	private function published_forms( array $ids ): void {
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $ids ): array {
				return 'ffc_form' === $args['post_type'] && 'publish' === $args['post_status'] ? $ids : array();
			}
		);
	}

	// ==================================================================
	// Capability gate
	// ==================================================================

	public function test_clear_rejects_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		try {
			CacheActionsAjaxEndpoint::handle_clear();
			$this->fail( 'Expected wp_send_json_error to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'do not have permission', $e->getMessage() );
		}
		$this->assertSame( array(), $this->purged, 'Nothing is purged without the capability.' );
	}

	// ==================================================================
	// Happy path
	// ==================================================================

	public function test_clear_purges_the_page_caches_of_every_published_form(): void {
		$this->published_forms( array( 3, 7 ) );

		try {
			CacheActionsAjaxEndpoint::handle_clear();
			$this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'json_success', $e->getMessage() );
			$this->assertStringContainsString( 'Page caches purged.', $e->getMessage() );
		}
		$this->assertSame(
			array( array( 3, 'manual_clear_all' ), array( 7, 'manual_clear_all' ) ),
			$this->purged
		);
		$this->assertSame( 0, $this->flushes, 'The object cache is not touched.' );
	}

	public function test_the_warm_action_is_gone(): void {
		$this->assertFalse( defined( CacheActionsAjaxEndpoint::class . '::ACTION_WARM' ) );
		$this->assertFalse( method_exists( CacheActionsAjaxEndpoint::class, 'handle_warm' ) );
	}
}
