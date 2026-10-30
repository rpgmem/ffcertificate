<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\SelfScheduling\AppointmentCloseout;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The old-appointment close-out (#2285), against the real repository and
 * writer over a `$wpdb` double that records every statement.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentCloseout
 * @covers \FreeFormCertificate\Repositories\AppointmentWriter::completeConfirmedUpTo
 * @covers \FreeFormCertificate\Repositories\AppointmentWriter::expirePendingUpTo
 * @covers \FreeFormCertificate\Repositories\AppointmentReader::countDueForCloseout
 */
class AppointmentCloseoutTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The setting get_option() answers. */
	private $days = 7;

	/** @var list<int|false> What each UPDATE answers, in order. */
	private array $updates = array();

	/** @var list<string> Every UPDATE the double ran. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->days    = 7;
		$this->updates = array();
		$this->ran     = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => AppointmentCloseout::OPTION === $key ? $this->days : $default
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'current_time' )->justReturn( '2026-05-10 10:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_supports' )->justReturn( false );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->prefix      = 'wp_';
		$wpdb->last_error  = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$i = 0;
				return preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$i, $args ) {
						$value = $args[ $i++ ];
						return '%i' === $m[0] ? '`' . $value . '`' : ( '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'" );
					},
					(string) preg_replace( '/\s+/', ' ', $sql )
				);
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				return array() === $this->updates ? 0 : array_shift( $this->updates );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_days_clamps_what_is_stored(): void {
		foreach ( array( array( 7, 7 ), array( '30', 30 ), array( -3, 0 ), array( 9999, 365 ), array( 'x', 7 ), array( 0, 0 ) ) as $case ) {
			$this->days = $case[0];
			$this->assertSame( $case[1], AppointmentCloseout::days(), var_export( $case[0], true ) );
		}
	}

	public function test_cutoff_is_today_minus_days_in_the_site_timezone(): void {
		$today = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Sao_Paulo' ) ) );
		$this->assertSame( $today->format( 'Y-m-d' ), AppointmentCloseout::cutoff( 0 ) );
		$this->assertSame( $today->modify( '-7 days' )->format( 'Y-m-d' ), AppointmentCloseout::cutoff( 7 ) );
	}

	public function test_run_completes_confirmed_then_cancels_pending_in_batches_until_one_falls_short(): void {
		// Confirmed: a full batch, then a short one. Pending: one short batch.
		$this->updates = array( 500, 12, 3 );

		$result = AppointmentCloseout::run();

		$this->assertSame( array( 'completed' => 512, 'cancelled' => 3, 'failed' => false ), $result );
		$this->assertCount( 3, $this->ran );
		$cutoff = AppointmentCloseout::cutoff( 7 );
		foreach ( array( 0, 1 ) as $i ) {
			$this->assertStringContainsString( "SET status = 'completed'", $this->ran[ $i ] );
			$this->assertStringContainsString( "WHERE status = 'confirmed' AND appointment_date <= '{$cutoff}'", $this->ran[ $i ] );
			$this->assertStringContainsString( 'LIMIT 500', $this->ran[ $i ] );
		}
		$this->assertStringContainsString( "SET status = 'cancelled'", $this->ran[2] );
		$this->assertStringContainsString( 'cancelled_by = NULL', $this->ran[2] );
		$this->assertStringContainsString( "cancellation_reason = 'Closed automatically: the date passed without approval.'", $this->ran[2] );
		$this->assertStringContainsString( "WHERE status = 'pending' AND appointment_date <= '{$cutoff}'", $this->ran[2] );
	}

	public function test_run_does_nothing_when_the_setting_is_zero(): void {
		$this->days = 0;

		$this->assertSame( array( 'completed' => 0, 'cancelled' => 0, 'failed' => false ), AppointmentCloseout::run( 1 ) );
		$this->assertSame( array(), $this->ran );
	}

	public function test_a_database_error_stops_the_loop_and_is_reported(): void {
		// The confirmed loop fails on its second batch; the pending one still runs once.
		$this->updates = array( 500, false, false );

		$result = AppointmentCloseout::run();

		$this->assertTrue( $result['failed'] );
		$this->assertSame( 500, $result['completed'] );
		$this->assertSame( 0, $result['cancelled'] );
		$this->assertCount( 3, $this->ran, 'Each loop stops at its first error.' );
	}

	public function test_due_counts_by_status_and_is_zero_when_off(): void {
		$GLOBALS['wpdb']->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'status' => 'confirmed', 'total' => '4' ),
				array( 'status' => 'pending', 'total' => '2' ),
			)
		);
		$this->assertSame( array( 'confirmed' => 4, 'pending' => 2 ), AppointmentCloseout::due() );

		$this->days = 0;
		$this->assertSame( array( 'confirmed' => 0, 'pending' => 0 ), AppointmentCloseout::due() );
	}
}
