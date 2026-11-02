<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RetrySchedule;
use PHPUnit\Framework\TestCase;

/**
 * The retry schedule shared by the release check and the Cloudflare probe (#2342).
 *
 * @covers \FreeFormCertificate\Core\RetrySchedule
 */
final class RetryScheduleTest extends TestCase {

	private const NOW   = 1_800_000_000;
	private const CYCLE = 43200;

	public function test_each_failure_waits_longer_and_the_fifth_waits_out_the_cycle(): void {
		$state = array( 'payload' => 'kept' );
		$now   = self::NOW;
		$waits = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$state   = RetrySchedule::after_failure( $state, $now, self::CYCLE );
			$waits[] = $state['retry_at'] - $now;
			$now     = $state['retry_at'];
		}

		$this->assertSame( array( 600, 1800, 3600, 7200 ), array_slice( $waits, 0, 4 ) );
		$this->assertSame( self::NOW + self::CYCLE, $state['retry_at'], 'The fifth failure waits until one cycle after the first.' );
		$this->assertSame( 0, $state['failures'], 'The count starts over after the cycle.' );
		$this->assertSame( 0, $state['first_failure_at'] );
		$this->assertSame( 'kept', $state['payload'], 'Keys it does not own are left alone.' );
	}

	public function test_a_new_cycle_starts_from_the_first_delay(): void {
		$state = array(
			'failures'         => 0,
			'first_failure_at' => 0,
			'retry_at'         => self::NOW,
		);

		$state = RetrySchedule::after_failure( $state, self::NOW, self::CYCLE );

		$this->assertSame( 1, $state['failures'] );
		$this->assertSame( self::NOW, $state['first_failure_at'] );
		$this->assertSame( self::NOW + 600, $state['retry_at'] );
	}

	public function test_waiting_reads_the_retry_time(): void {
		$this->assertFalse( RetrySchedule::waiting( array(), self::NOW ) );
		$this->assertTrue( RetrySchedule::waiting( array( 'retry_at' => self::NOW + 1 ), self::NOW ) );
		$this->assertFalse( RetrySchedule::waiting( array( 'retry_at' => self::NOW ), self::NOW ) );
	}
}
