<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesCron;
use FreeFormCertificate\DateMessages\Runner;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The daily send runs at a local time of day (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesCron
 */
class DateMessagesCronTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Stored settings.
	 *
	 * @var mixed
	 */
	private $settings = array();

	/**
	 * The central chosen-times option (Settings → Scheduled Tasks).
	 *
	 * @var array<string, string>
	 */
	private array $times = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->settings = array();
		$this->times    = array();
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( \FreeFormCertificate\Core\ScheduledTasks::TIMES_OPTION === $name ) {
					return $this->times;
				}
				return DateMessagesCron::SETTINGS_OPTION === $name ? $this->settings : $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider send_times
	 *
	 * @param mixed  $stored   Stored settings.
	 * @param string $expected Resolved time.
	 */
	public function test_send_time_falls_back_on_anything_that_is_not_a_time( $stored, string $expected ): void {
		$this->settings = $stored;
		$this->assertSame( $expected, DateMessagesCron::send_time() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function send_times(): array {
		return array(
			'nothing stored' => array( array(), '08:00' ),
			'valid'          => array( array( 'send_time' => '06:30' ), '06:30' ),
			'hour 24'        => array( array( 'send_time' => '24:00' ), '08:00' ),
			'not a time'     => array( array( 'send_time' => 'morning' ), '08:00' ),
			'not an array'   => array( 'x', '08:00' ),
		);
	}

	public function test_the_time_chosen_on_scheduled_tasks_wins_over_the_module_option(): void {
		$this->settings = array( 'send_time' => '06:30' );
		$this->times    = array( DateMessagesCron::CRON_HOOK => '21:15' );

		$this->assertSame( '21:15', DateMessagesCron::send_time() );
	}

	public function test_next_run_is_today_when_the_local_time_is_still_ahead(): void {
		$this->settings = array( 'send_time' => '08:00' );
		// 2026-10-03 07:00 in São Paulo (UTC-3) is 10:00 UTC.
		$now = ( new \DateTimeImmutable( '2026-10-03 10:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$next = ( new \DateTimeImmutable( '@' . DateMessagesCron::next_run( $now ) ) )->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) );

		$this->assertSame( '2026-10-03 08:00', $next->format( 'Y-m-d H:i' ) );
	}

	public function test_next_run_moves_to_tomorrow_once_the_local_time_has_passed(): void {
		$this->settings = array( 'send_time' => '08:00' );
		$now            = ( new \DateTimeImmutable( '2026-10-03 11:00:01', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$next = ( new \DateTimeImmutable( '@' . DateMessagesCron::next_run( $now ) ) )->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) );

		$this->assertSame( '2026-10-04 08:00', $next->format( 'Y-m-d H:i' ) );
	}

	public function test_schedule_only_adds_a_missing_event(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\expect( 'wp_schedule_event' )->never();
		DateMessagesCron::schedule();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'daily', DateMessagesCron::CRON_HOOK );
		DateMessagesCron::schedule();
	}

	public function test_unschedule_clears_the_daily_event_and_every_pending_batch(): void {
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
			}
		);

		DateMessagesCron::unschedule();

		$this->assertSame( array( DateMessagesCron::CRON_HOOK, Runner::BATCH_HOOK, \FreeFormCertificate\DateMessages\Digest::HOOK ), $cleared );
	}

	public function test_init_attaches_the_daily_job_and_the_batch_continuation(): void {
		$added = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$added ) {
				$added[ $hook ] = $args;
				return true;
			}
		);

		DateMessagesCron::init();

		$this->assertSame(
			array(
				DateMessagesCron::CRON_HOOK                         => 1,
				\FreeFormCertificate\DateMessages\Digest::HOOK => 1,
				Runner::BATCH_HOOK                                  => 3,
			),
			$added
		);
	}
}
