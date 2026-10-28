<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CacheActionsAjaxEndpoint;

/**
 * Tests for the cache-actions AJAX endpoints (Warm + Clear) introduced in 6.5.5.
 *
 * Capabilities and FormCache are the real classes: the capability check reads
 * `current_user_can()`, warming reads the published forms through
 * `get_posts()` and clearing flushes the object cache (#2127).
 *
 * @covers \FreeFormCertificate\Admin\CacheActionsAjaxEndpoint
 */
class CacheActionsAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** How many times the object cache was flushed. */
	private int $flushes = 0;

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
		// A warm form reads as already cached, so warming counts it.
		Functions\when( 'wp_cache_get' )->justReturn( array( 'cached' ) );
		$this->flushes = 0;
		Functions\when( 'wp_cache_flush' )->alias(
			function (): bool {
				++$this->flushes;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );

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

	public function test_warm_rejects_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'do not have permission' );
		CacheActionsAjaxEndpoint::handle_warm();
	}

	public function test_clear_rejects_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		try {
			CacheActionsAjaxEndpoint::handle_clear();
			$this->fail( 'Expected wp_send_json_error to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'do not have permission', $e->getMessage() );
		}
		$this->assertSame( 0, $this->flushes, 'Nothing is flushed without the capability.' );
	}

	// ==================================================================
	// Happy path
	// ==================================================================

	public function test_warm_returns_count_from_form_cache(): void {
		$this->published_forms( array( 1, 2, 3, 4, 5, 6, 7 ) );

		try {
			CacheActionsAjaxEndpoint::handle_warm();
			$this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'json_success', $e->getMessage() );
			$this->assertStringContainsString( '"count":7', $e->getMessage() );
			$this->assertStringContainsString( 'Cache warmed for 7 forms', $e->getMessage() );
		}
	}

	public function test_warm_singular_message_when_only_one_form(): void {
		$this->published_forms( array( 1 ) );

		try {
			CacheActionsAjaxEndpoint::handle_warm();
			$this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '"count":1', $e->getMessage() );
			$this->assertStringContainsString( 'Cache warmed for 1 form.', $e->getMessage() );
		}
	}

	public function test_clear_invokes_form_cache_clear_all(): void {
		$this->published_forms( array() );

		try {
			CacheActionsAjaxEndpoint::handle_clear();
			$this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'json_success', $e->getMessage() );
			$this->assertStringContainsString( 'Cache cleared.', $e->getMessage() );
		}
		$this->assertSame( 1, $this->flushes );
	}
}
