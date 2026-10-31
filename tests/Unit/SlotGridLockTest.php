<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SlotGridLock;

/**
 * A regular calendar's grid cannot move under open appointments (#2244).
 *
 * @covers \FreeFormCertificate\SelfScheduling\SlotGridLock
 */
class SlotGridLockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A grid open 09:00–12:00 every day.
	 *
	 * @param int    $duration Minutes.
	 * @param int    $interval Minutes.
	 * @param string $close    Closing time.
	 * @return array<string, mixed>
	 */
	private static function grid( int $duration, int $interval = 0, string $close = '12:00' ): array {
		$hours = array();
		for ( $day = 0; $day <= 6; $day++ ) {
			$hours[] = array( 'day' => $day, 'start' => '09:00', 'end' => $close );
		}
		return array( 'slot_duration' => $duration, 'slot_interval' => $interval, 'working_hours' => $hours );
	}

	/**
	 * @param string $start Start time.
	 * @param string $end   End time.
	 * @return array<string, mixed>
	 */
	private static function appointment( string $start, string $end ): array {
		return array( 'id' => 1, 'appointment_date' => '2032-05-20', 'start_time' => $start, 'end_time' => $end );
	}

	public function test_a_slot_the_new_duration_drops_is_moved(): void {
		$moved = SlotGridLock::moved( self::grid( 30 ), self::grid( 45 ), array( self::appointment( '09:30:00', '10:00:00' ) ) );

		$this->assertCount( 1, $moved );
	}

	public function test_a_slot_that_keeps_its_start_but_not_its_end_is_moved(): void {
		$moved = SlotGridLock::moved( self::grid( 30 ), self::grid( 60 ), array( self::appointment( '09:00:00', '09:30:00' ) ) );

		$this->assertCount( 1, $moved, 'A 09:00 booking of 30 minutes does not fit a 60-minute grid.' );
	}

	public function test_shorter_hours_remove_the_last_slot(): void {
		$moved = SlotGridLock::moved( self::grid( 30 ), self::grid( 30, 0, '11:00' ), array( self::appointment( '11:30:00', '12:00:00' ), self::appointment( '09:00:00', '09:30:00' ) ) );

		$this->assertSame( array( '11:30:00' ), array_column( $moved, 'start_time' ) );
	}

	public function test_longer_hours_move_nothing(): void {
		$this->assertSame( array(), SlotGridLock::moved( self::grid( 30 ), self::grid( 30, 0, '18:00' ), array( self::appointment( '11:30:00', '12:00:00' ) ) ) );
	}

	public function test_an_appointment_off_the_old_grid_is_not_the_change_s_doing(): void {
		$this->assertSame( array(), SlotGridLock::moved( self::grid( 30 ), self::grid( 45 ), array( self::appointment( '14:00:00', '14:30:00' ) ) ) );
	}

	public function test_the_refusal_is_printed_once_with_each_appointment(): void {
		$store = array();
		Functions\when( 'set_transient' )->alias( static function ( $key, $value ) use ( &$store ) { $store[ $key ] = $value; return true; } );
		Functions\when( 'get_transient' )->alias( static function ( $key ) use ( &$store ) { return $store[ $key ] ?? false; } );
		Functions\when( 'delete_transient' )->alias( static function ( $key ) use ( &$store ) { unset( $store[ $key ] ); return true; } );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, $ts ) );
		Functions\when( 'get_option' )->justReturn( 'd/m/Y' );
		$printed = array();
		Functions\when( 'wp_admin_notice' )->alias( static function ( $message, $args ) use ( &$printed ) { $printed[] = array( $message, $args ); } );

		SlotGridLock::remember( 20, array( self::appointment( '09:30:00', '10:00:00' ) ) );
		$this->assertArrayHasKey( 'ffc_ss_grid_lock_3_20', $store );

		SlotGridLock::render_notice( 20 );
		SlotGridLock::render_notice( 20 );

		$this->assertCount( 1, $printed, 'The refusal is shown once.' );
		$this->assertStringContainsString( '1 open appointment sits on a slot', $printed[0][0] );
		$this->assertStringContainsString( '09:30–10:00', $printed[0][0] );
		$this->assertSame( 'error', $printed[0][1]['type'] );
	}
}
