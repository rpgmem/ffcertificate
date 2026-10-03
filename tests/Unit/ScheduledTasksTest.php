<?php
/**
 * Tests for ScheduledTasks -- the register of the plugin's WP-Cron hooks, its
 * heartbeat, and the server crontab line (#1538).
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ScheduledTasks;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FreeFormCertificate\Core\ScheduledTasks
 */
class ScheduledTasksTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The heartbeat option as stored.
	 *
	 * @var mixed
	 */
	private $stored = array();

	/**
	 * Options written: name => [ value, autoload ].
	 *
	 * @var array<string, array{0: mixed, 1: mixed}>
	 */
	private array $writes = array();

	/**
	 * The chosen-times option as stored.
	 *
	 * @var mixed
	 */
	private $times = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->stored = array();
		$this->writes = array();
		$this->times  = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( ScheduledTasks::TIMES_OPTION === $name ) {
					return $this->times;
				}
				return ScheduledTasks::HEARTBEAT_OPTION === $name ? $this->stored : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->writes[ $name ] = array( $value, $autoload );
				if ( ScheduledTasks::HEARTBEAT_OPTION === $name ) {
					$this->stored = $value;
				}
				if ( ScheduledTasks::TIMES_OPTION === $name ) {
					$this->times = $value;
				}
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// The register against the four places that name the hooks
	// ------------------------------------------------------------------

	/**
	 * Every registered hook is cleared in BOTH cron blocks of uninstall.php --
	 * the data-kept early return and the full purge. This is what found the
	 * two gaps the register was introduced with.
	 */
	public function test_every_registered_hook_is_cleared_by_both_uninstall_paths(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$split = strpos( $source, 'if ( ! $ffcertificate_purge ) {' );
		$this->assertNotFalse( $split, 'Could not find the data-kept block -- the check did not run.' );
		$end_kept = strpos( $source, 'return;', (int) $split );
		$this->assertNotFalse( $end_kept );

		$kept_block  = substr( $source, (int) $split, (int) $end_kept - (int) $split );
		$purge_block = substr( $source, (int) $end_kept );

		$hooks = array_keys( ScheduledTasks::all() );
		$this->assertNotSame( array(), $hooks, 'The register is empty -- the check did not run.' );

		foreach ( $hooks as $hook ) {
			$call = "wp_clear_scheduled_hook( '{$hook}' )";
			$this->assertStringContainsString( $call, $kept_block, "{$hook} is not cleared when data is kept." );
			$this->assertStringContainsString( $call, $purge_block, "{$hook} is not cleared on a full purge." );
		}
	}

	/**
	 * Deactivation clears every registered hook, directly or through the
	 * owning class's `unschedule()`.
	 */
	public function test_deactivation_clears_every_registered_hook(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ffc-deactivator.php' );
		$start  = strpos( $source, 'public static function deactivate()' );
		$this->assertNotFalse( $start );
		$body = substr( $source, (int) $start, (int) strpos( $source, 'flush_rewrite_rules();', (int) $start ) - (int) $start );

		$via_class = array(
			'ffc_daily_expired_tickets_cleanup' => 'ExpiredTicketsCleanup::unschedule()',
			'ffc_cloudflare_cidr_refresh'       => 'CloudflareCidrRefresh::unschedule()',
			'ffc_date_messages_daily'           => 'DateMessagesCron::unschedule()',
			'ffc_date_messages_batch'           => 'DateMessagesCron::unschedule()',
			'ffc_date_messages_digest'          => 'DateMessagesCron::unschedule()',
			'ffcertificate_warm_cache_hook'     => 'FormCache::unschedule_cache_warming()',
		);

		foreach ( array_keys( ScheduledTasks::all() ) as $hook ) {
			if ( 'ffc_process_submission_async' === $hook ) {
				// A per-submission event left pending at deactivation still has
				// its work to do on reactivation; only uninstall drops it.
				continue;
			}
			$needle = $via_class[ $hook ] ?? "wp_clear_scheduled_hook( '{$hook}' )";
			$this->assertStringContainsString( $needle, $body, "{$hook} survives deactivation." );
		}
	}

	/**
	 * The register spells hooks as literals to keep Core off three module
	 * edges; this keeps each literal equal to the constant its owner uses.
	 */
	public function test_the_literals_equal_the_owning_classes_constants(): void {
		$all = ScheduledTasks::all();

		foreach ( array(
			\FreeFormCertificate\SelfScheduling\AppointmentReminderScanner::CRON_HOOK,
			\FreeFormCertificate\Integrations\CloudflareCidrRefresh::CRON_HOOK,
			\FreeFormCertificate\Admin\ExpiredTicketsCleanup::CRON_HOOK,
			\FreeFormCertificate\Submissions\SubmissionHandler::ASYNC_PIPELINE_HOOK,
			\FreeFormCertificate\Reregistration\ReregistrationEmailHandler::REMINDER_BATCH_HOOK,
			\FreeFormCertificate\DateMessages\DateMessagesCron::CRON_HOOK,
			\FreeFormCertificate\DateMessages\Runner::BATCH_HOOK,
			\FreeFormCertificate\DateMessages\Digest::HOOK,
			\FreeFormCertificate\Submissions\FormCache::WARM_HOOK,
		) as $constant ) {
			$this->assertArrayHasKey( $constant, $all );
		}
	}

	public function test_every_registered_hook_has_its_own_label(): void {
		foreach ( array_keys( ScheduledTasks::all() ) as $hook ) {
			$this->assertNotSame( $hook, ScheduledTasks::label( $hook ), "{$hook} falls back to its raw name." );
		}
	}

	// ------------------------------------------------------------------
	// Heartbeat
	// ------------------------------------------------------------------

	public function test_init_listens_late_on_every_recurring_hook_and_never_on_singles(): void {
		$added = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$added ) {
				$added[ $hook ] = $priority;
				return true;
			}
		);

		ScheduledTasks::init();

		$expected = array();
		foreach ( ScheduledTasks::all() as $hook => $task ) {
			if ( ScheduledTasks::SINGLE !== $task['recurrence'] ) {
				$expected[ $hook ] = 999;
			}
		}
		$this->assertNotSame( array(), $expected );
		$this->assertSame( $expected, $added );
	}

	public function test_touch_records_the_time_in_one_option_that_is_not_autoloaded(): void {
		$this->stored = array( 'ffc_cloudflare_cidr_refresh' => 100 );

		$before = time();
		ScheduledTasks::touch( 'ffcertificate_daily_cleanup_hook' );

		[ $value, $autoload ] = $this->writes[ ScheduledTasks::HEARTBEAT_OPTION ];
		$this->assertFalse( $autoload );
		$this->assertSame( 100, $value['ffc_cloudflare_cidr_refresh'], 'Touching one hook must keep the others.' );
		$this->assertGreaterThanOrEqual( $before, $value['ffcertificate_daily_cleanup_hook'] );
	}

	public function test_heartbeats_drop_what_is_not_a_hook_and_a_time(): void {
		$this->stored = array(
			'ffc_cloudflare_cidr_refresh' => '200',
			'broken'                      => array( 'x' ),
			0                             => 5,
		);

		$this->assertSame( array( 'ffc_cloudflare_cidr_refresh' => 200 ), ScheduledTasks::heartbeats() );

		$this->stored = 'not an array';
		$this->assertSame( array(), ScheduledTasks::heartbeats() );
	}

	// ------------------------------------------------------------------
	// State
	// ------------------------------------------------------------------

	/**
	 * @dataProvider states
	 */
	public function test_state( string $hook, ?int $next, ?int $last, string $expected ): void {
		$this->assertSame( $expected, ScheduledTasks::state( $hook, $next, $last, 1000000 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int|null, 2: int|null, 3: string}>
	 */
	public function states(): array {
		$now = 1000000;
		return array(
			'not scheduled'          => array( 'ffcertificate_daily_cleanup_hook', null, $now - 10, 'not_scheduled' ),
			'never ran'              => array( 'ffcertificate_daily_cleanup_hook', $now + 10, null, 'never_run' ),
			'daily within 26h'       => array( 'ffcertificate_daily_cleanup_hook', $now + 10, $now - 26 * 3600, 'ok' ),
			'daily past 26h'         => array( 'ffcertificate_daily_cleanup_hook', $now + 10, $now - 26 * 3600 - 1, 'late' ),
			'hourly within 2h'       => array( 'ffcertificate_self_scheduling_reminder_scan', $now + 10, $now - 2 * 3600, 'ok' ),
			'hourly past 2h'         => array( 'ffcertificate_self_scheduling_reminder_scan', $now + 10, $now - 2 * 3600 - 1, 'late' ),
		);
	}

	public function test_report_lists_recurring_hooks_with_their_module_state(): void {
		$this->stored = array( 'ffcertificate_daily_cleanup_hook' => 999000 );
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( $hook ) {
				return 'ffc_cloudflare_cidr_refresh' === $hook ? false : 1001000;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( ScheduledTasks::HEARTBEAT_OPTION === $name ) {
					return $this->stored;
				}
				// Every module off, so the column can be read.
				return 'ffc_settings' === $name ? array( 'module_reregistration_enabled' => '0' ) : $default;
			}
		);

		$rows = array();
		foreach ( ScheduledTasks::report( 1000000 ) as $row ) {
			$rows[ $row['hook'] ] = $row;
		}

		$this->assertArrayNotHasKey( 'ffc_process_submission_async', $rows, 'A one-off event has no heartbeat to report.' );
		$this->assertSame( 'ok', $rows['ffcertificate_daily_cleanup_hook']['state'] );
		$this->assertSame( 999000, $rows['ffcertificate_daily_cleanup_hook']['last_run'] );
		$this->assertSame( 'not_scheduled', $rows['ffc_cloudflare_cidr_refresh']['state'] );
		$this->assertTrue( $rows['ffc_cloudflare_cidr_refresh']['module_enabled'], 'A hook with no module is always on.' );
		$this->assertFalse( $rows['ffcertificate_reregistration_expire_hook']['module_enabled'] );
	}

	public function test_pending_singles_counts_queued_events_per_hook(): void {
		$crons = array(
			100 => array(
				'ffc_process_submission_async'     => array( 'a' => array(), 'b' => array() ),
				'ffcertificate_daily_cleanup_hook' => array( 'c' => array() ),
			),
			200 => array(
				'ffc_process_submission_async' => array( 'd' => array() ),
			),
			'version' => 2,
		);

		$this->assertSame(
			array(
				'ffc_process_submission_async'      => 3,
				'ffc_reregistration_reminder_batch' => 0,
				'ffc_date_messages_batch'           => 0,
				'ffc_date_messages_digest'          => 0,
			),
			ScheduledTasks::pending_singles( $crons )
		);
	}

	// ------------------------------------------------------------------
	// Crontab line
	// ------------------------------------------------------------------

	public function test_crontab_lines_for_each_method(): void {
		$this->assertSame(
			"*/15 * * * * cd '/var/www/site' && wp cron event run --due-now --quiet",
			ScheduledTasks::crontab_line( 'wp_cli', 15, '/var/www/site/', 'https://example.org' )
		);
		$this->assertSame(
			"*/5 * * * * wget -q -O - 'https://example.org/wp-cron.php?doing_wp_cron' >/dev/null 2>&1",
			ScheduledTasks::crontab_line( 'wget', 5, '/var/www/site/', 'https://example.org/' )
		);
		$this->assertSame(
			"*/30 * * * * curl -s 'https://example.org/wp-cron.php?doing_wp_cron' >/dev/null 2>&1",
			ScheduledTasks::crontab_line( 'curl', 30, '/var/www/site/', 'https://example.org' )
		);
	}

	/**
	 * A path with a space stays one argument, and an apostrophe inside it
	 * cannot close the quote and run what follows.
	 */
	public function test_a_path_with_a_space_or_a_quote_stays_one_argument(): void {
		$this->assertSame(
			"*/15 * * * * cd '/home/escola sme/it'\\''s; rm -rf x' && wp cron event run --due-now --quiet",
			ScheduledTasks::crontab_line( 'wp_cli', 15, "/home/escola sme/it's; rm -rf x", 'https://example.org' )
		);
	}

	public function test_an_unoffered_method_or_frequency_yields_no_line(): void {
		$this->assertSame( '', ScheduledTasks::crontab_line( 'ssh', 15, '/x', 'https://example.org' ) );
		$this->assertSame( '', ScheduledTasks::crontab_line( 'wp_cli', 7, '/x', 'https://example.org' ) );
	}

	public function test_crontab_lines_offers_every_method_and_frequency(): void {
		$lines = ScheduledTasks::crontab_lines( '/x', 'https://example.org' );

		$this->assertSame( ScheduledTasks::CRONTAB_METHODS, array_keys( $lines ) );
		foreach ( $lines as $by_minutes ) {
			$this->assertSame( ScheduledTasks::CRONTAB_MINUTES, array_keys( $by_minutes ) );
			foreach ( $by_minutes as $line ) {
				$this->assertNotSame( '', $line );
			}
		}
	}
	// ------------------------------------------------------------------
	// Chosen times of day
	// ------------------------------------------------------------------

	public function test_times_keep_only_valid_times_of_daily_tasks(): void {
		$this->times = array(
			'ffcertificate_daily_cleanup_hook'            => '03:30',
			'ffcertificate_self_scheduling_reminder_scan' => '04:00',
			'ffc_cloudflare_cidr_refresh'                 => '24:00',
			'not_ours'                                    => '05:00',
		);

		$this->assertSame( array( 'ffcertificate_daily_cleanup_hook' => '03:30' ), ScheduledTasks::times() );
		$this->assertSame( '03:30', ScheduledTasks::time_for( 'ffcertificate_daily_cleanup_hook' ) );
		$this->assertNull( ScheduledTasks::time_for( 'ffc_cloudflare_cidr_refresh' ) );
	}

	public function test_next_at_is_the_next_occurrence_in_the_site_timezone(): void {
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );
		// 2026-10-03 12:00 in São Paulo (UTC-3) is 15:00 UTC.
		$noon = ( new \DateTimeImmutable( '2026-10-03 12:00', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();

		$this->assertSame( $noon + 2 * HOUR_IN_SECONDS, ScheduledTasks::next_at( '14:00', $noon ) );
		$this->assertSame( $noon + 21 * HOUR_IN_SECONDS, ScheduledTasks::next_at( '09:00', $noon ), 'A time already past today is tomorrow.' );
		$this->assertSame( $noon + DAY_IN_SECONDS, ScheduledTasks::next_at( '12:00', $noon ), 'Exactly now is tomorrow, never now.' );
	}

	public function test_first_run_uses_the_chosen_time_or_the_callers_default(): void {
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->times = array( 'ffcertificate_daily_cleanup_hook' => '03:30' );

		$this->assertSame( 12345, ScheduledTasks::first_run( 'ffc_cloudflare_cidr_refresh', 12345 ) );
		$first = ScheduledTasks::first_run( 'ffcertificate_daily_cleanup_hook', 12345 );
		$this->assertSame( '03:30', gmdate( 'H:i', $first ) );
		$this->assertGreaterThan( time(), $first );
	}

	public function test_save_times_moves_only_changed_scheduled_tasks(): void {
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->times = array(
			'ffcertificate_daily_cleanup_hook' => '03:30',
			'ffc_cloudflare_cidr_refresh'      => '04:00',
		);
		$scheduled   = array( 'ffcertificate_daily_cleanup_hook', 'ffc_cloudflare_cidr_refresh', 'ffc_daily_expired_tickets_cleanup' );
		Functions\when( 'wp_next_scheduled' )->alias( static fn( $hook ) => in_array( $hook, $scheduled, true ) ? time() + 60 : false );
		$cleared = array();
		$events  = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
				return 1;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $ts, $recurrence, $hook ) use ( &$events ) {
				$events[ $hook ] = array( gmdate( 'H:i', $ts ), $recurrence );
				return true;
			}
		);

		$invalid = ScheduledTasks::save_times(
			array(
				'ffcertificate_daily_cleanup_hook'         => '03:30', // unchanged
				'ffc_cloudflare_cidr_refresh'              => '',      // cleared
				'ffc_daily_expired_tickets_cleanup'        => '02:15', // new, scheduled
				'ffcertificate_reregistration_expire_hook' => '05:45', // new, not scheduled
				'ffc_date_messages_daily'                  => '7:00',  // invalid
				'ffcertificate_self_scheduling_reminder_scan' => '01:00', // hourly: ignored
			)
		);

		$this->assertSame( array( 'ffc_date_messages_daily' ), $invalid );
		$this->assertSame(
			array(
				'ffcertificate_daily_cleanup_hook'         => '03:30',
				'ffc_daily_expired_tickets_cleanup'        => '02:15',
				'ffcertificate_reregistration_expire_hook' => '05:45',
			),
			$this->times
		);
		$this->assertFalse( $this->writes[ ScheduledTasks::TIMES_OPTION ][1], 'Not autoloaded.' );
		$this->assertSame( array( 'ffc_daily_expired_tickets_cleanup' ), $cleared, 'Only a changed, scheduled task moves; a cleared one stays where it is.' );
		$this->assertSame( array( 'ffc_daily_expired_tickets_cleanup' => array( '02:15', 'daily' ) ), $events );
	}
}
