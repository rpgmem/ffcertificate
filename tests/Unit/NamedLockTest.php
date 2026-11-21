<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\NamedLock;

/**
 * The MySQL named lock around a check-then-write (#2695).
 *
 * @covers \FreeFormCertificate\Core\NamedLock
 */
class NamedLockTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Every lock query and every write, in order. */
	private array $events = array();

	/** @var mixed The `$wpdb` before the test. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $id, $group ) {
				$this->events[] = "cache_delete {$group} {$id}";
				return true;
			}
		);
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` whose GET_LOCK answers `$answer`.
	 *
	 * @param string|null $answer GET_LOCK's answer.
	 */
	private function server( ?string $answer ): void {
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => vsprintf( str_replace( '%s', "'%s'", (string) $sql ), $args ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) use ( $answer ) {
				$this->events[] = (string) $sql;
				return 0 === strpos( (string) $sql, 'SELECT GET_LOCK' ) ? $answer : '1';
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_acquire_reports_held_timed_out_and_unsupported(): void {
		$this->server( '1' );
		$this->assertTrue( NamedLock::acquire( 'a', 3 ) );
		$this->server( '0' );
		$this->assertFalse( NamedLock::acquire( 'a', 3 ) );
		$this->server( null );
		$this->assertNull( NamedLock::acquire( 'a', 3 ) );
		$this->assertSame( "SELECT GET_LOCK('a', 3)", $this->events[0] );
	}

	public function test_acquire_without_a_database_is_unsupported(): void {
		unset( $GLOBALS['wpdb'] );
		$this->assertNull( NamedLock::acquire( 'a', 3 ) );
		NamedLock::release( 'a' );
		$this->assertSame( array(), $this->events );
	}

	public function test_the_form_config_write_runs_between_take_and_release_on_fresh_meta(): void {
		$this->server( '1' );

		$result = NamedLock::with_form_config(
			9,
			function () {
				$this->events[] = 'write';
				return 'done';
			}
		);

		$this->assertSame( 'done', $result );
		$this->assertSame(
			array( "SELECT GET_LOCK('ffc_form_config_9', 5)", 'cache_delete post_meta 9', 'write', "SELECT RELEASE_LOCK('ffc_form_config_9')" ),
			$this->events
		);
	}

	public function test_a_write_that_throws_still_releases(): void {
		$this->server( '1' );

		try {
			NamedLock::with_form_config(
				9,
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( "SELECT RELEASE_LOCK('ffc_form_config_9')", end( $this->events ) );
	}

	public function test_a_lock_not_granted_still_writes_and_releases_nothing(): void {
		$this->server( '0' );

		NamedLock::with_form_config(
			9,
			function () {
				$this->events[] = 'write';
			}
		);

		$this->assertSame( array( "SELECT GET_LOCK('ffc_form_config_9', 5)", 'cache_delete post_meta 9', 'write' ), $this->events );
	}
}
