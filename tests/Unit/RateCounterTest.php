<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Security\RateCounter;
use PHPUnit\Framework\TestCase;

/**
 * The short-window counters live in fixed windows (#2339).
 *
 * @covers \FreeFormCertificate\Security\RateCounter
 */
class RateCounterTest extends TestCase {

	/**
	 * Transients by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_windowed_key_carries_the_window_number(): void {
		$before = (int) floor( time() / 60 );
		$key    = RateCounter::windowed( 'ffc_rate_read_x_minute', 60 );
		$after  = (int) floor( time() / 60 );

		// Read on both sides: the call may cross the minute.
		$this->assertContains( $key, array( 'ffc_rate_read_x_minute_' . $before, 'ffc_rate_read_x_minute_' . $after ) );
	}

	public function test_a_steady_caller_starts_again_in_the_next_window(): void {
		// A key without the window number was re-armed on every hit and its
		// count never fell; with it, the next window is a fresh counter.
		$this_window = 'ffc_rate_ip_x_hour_' . (int) floor( time() / HOUR_IN_SECONDS );
		$this->transients[ $this_window ] = 5;

		$next_window = 'ffc_rate_ip_x_hour_' . ( (int) floor( time() / HOUR_IN_SECONDS ) + 1 );

		$this->assertSame( 0, RateCounter::get( $next_window ) );
		$this->assertSame( 1, RateCounter::increment( $next_window, HOUR_IN_SECONDS ) );
		$this->assertSame( 5, RateCounter::get( $this_window ), 'The previous window keeps its own count.' );
	}

	public function test_a_zero_or_negative_window_is_read_as_one_second(): void {
		$this->assertSame( 1, preg_match( '/^k_\d+$/', RateCounter::windowed( 'k', 0 ) ) );
	}
}
