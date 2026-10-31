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

		// The pre-#2323 names stay cleared for their deprecation cycle.
		$hooks = array_merge( array_keys( ScheduledTasks::all() ), array_keys( ScheduledTasks::LEGACY_HOOKS ) );
		$this->assertNotSame( array(), $hooks, 'The register is empty -- the check did not run.' );

		foreach ( $hooks as $hook ) {
			// A single event carries its arguments, and wp_clear_scheduled_hook()
			// clears only the events whose arguments match the ones it is given:
			// with none, it left every batch, digest and announcement behind.
			$call = self::clear_call( $hook );
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
			'ffc_date_messages_announce'        => 'DateMessagesCron::unschedule()',
		);

		foreach ( array_keys( ScheduledTasks::all() ) as $hook ) {
			if ( 'ffc_process_submission_async' === $hook ) {
				// A per-submission event left pending at deactivation still has
				// its work to do on reactivation; only uninstall drops it.
				continue;
			}
			$needle = $via_class[ $hook ] ?? self::clear_call( $hook );
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
			\FreeFormCertificate\SelfScheduling\AppointmentCloseout::CRON_HOOK,
			\FreeFormCertificate\Integrations\CloudflareCidrRefresh::CRON_HOOK,
			\FreeFormCertificate\Audience\AudienceFormInviteScanner::CRON_HOOK,
			\FreeFormCertificate\Admin\ExpiredTicketsCleanup::CRON_HOOK,
			\FreeFormCertificate\Submissions\SubmissionHandler::ASYNC_PIPELINE_HOOK,
			\FreeFormCertificate\Reregistration\ReregistrationEmailHandler::REMINDER_BATCH_HOOK,
			\FreeFormCertificate\Reregistration\ReregistrationEmailHandler::INVITATION_BATCH_HOOK,
			\FreeFormCertificate\DateMessages\DateMessagesCron::CRON_HOOK,
			\FreeFormCertificate\DateMessages\Runner::BATCH_HOOK,
			\FreeFormCertificate\DateMessages\Digest::HOOK,
			\FreeFormCertificate\DateMessages\Runner::ANNOUNCE_HOOK,
		) as $constant ) {
			$this->assertArrayHasKey( $constant, $all );
		}
	}

	public function test_the_retired_warming_is_no_longer_listed(): void {
		// The event is still cleared everywhere (#2241); it is not a task.
		$this->assertArrayNotHasKey( \FreeFormCertificate\Submissions\FormCache::WARM_HOOK, ScheduledTasks::all() );
	}

	public function test_the_date_messages_cron_unschedules_every_single_event_whatever_its_arguments(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/date-messages/class-ffc-date-messages-cron.php' );

		foreach ( array( 'Runner::BATCH_HOOK', 'Digest::HOOK', 'Runner::ANNOUNCE_HOOK' ) as $hook ) {
			$this->assertStringContainsString( "wp_unschedule_hook( {$hook} )", $source );
		}
	}

	/**
	 * The call that removes every pending event of a hook: a recurring event
	 * has no arguments, a single one does.
	 *
	 * @param string $hook Registered hook.
	 * @return string
	 */
	private static function clear_call( string $hook ): string {
		// A legacy name was recurring, like the hook it became.
		$hook_now = ScheduledTasks::LEGACY_HOOKS[ $hook ] ?? $hook;
		return ScheduledTasks::SINGLE === ScheduledTasks::all()[ $hook_now ]['recurrence']
			? "wp_unschedule_hook( '{$hook}' )"
			: "wp_clear_scheduled_hook( '{$hook}' )";
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
				if ( 999 === $priority ) {
					$added[ $hook ] = $priority;
				}
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
		ScheduledTasks::touch( 'ffc_daily_cleanup' );

		[ $value, $autoload ] = $this->writes[ ScheduledTasks::HEARTBEAT_OPTION ];
		$this->assertFalse( $autoload );
		$this->assertSame( 100, $value['ffc_cloudflare_cidr_refresh'], 'Touching one hook must keep the others.' );
		$this->assertGreaterThanOrEqual( $before, $value['ffc_daily_cleanup'] );
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
			'not scheduled'          => array( 'ffc_daily_cleanup', null, $now - 10, 'not_scheduled' ),
			'never ran'              => array( 'ffc_daily_cleanup', $now + 10, null, 'never_run' ),
			'daily within 26h'       => array( 'ffc_daily_cleanup', $now + 10, $now - 26 * 3600, 'ok' ),
			'daily past 26h'         => array( 'ffc_daily_cleanup', $now + 10, $now - 26 * 3600 - 1, 'late' ),
			'hourly within 2h'       => array( 'ffc_self_scheduling_reminder_scan', $now + 10, $now - 2 * 3600, 'ok' ),
			'hourly past 2h'         => array( 'ffc_self_scheduling_reminder_scan', $now + 10, $now - 2 * 3600 - 1, 'late' ),
		);
	}

	public function test_report_lists_recurring_hooks_with_their_module_state(): void {
		$this->stored = array( 'ffc_daily_cleanup' => 999000 );
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
		$this->assertSame( 'ok', $rows['ffc_daily_cleanup']['state'] );
		$this->assertSame( 999000, $rows['ffc_daily_cleanup']['last_run'] );
		$this->assertSame( 'not_scheduled', $rows['ffc_cloudflare_cidr_refresh']['state'] );
		$this->assertTrue( $rows['ffc_cloudflare_cidr_refresh']['module_enabled'], 'A hook with no module is always on.' );
		$this->assertFalse( $rows['ffc_reregistration_expire']['module_enabled'] );
	}

	public function test_pending_singles_counts_queued_events_per_hook(): void {
		$crons = array(
			100 => array(
				'ffc_process_submission_async'     => array( 'a' => array(), 'b' => array() ),
				'ffc_daily_cleanup' => array( 'c' => array() ),
			),
			200 => array(
				'ffc_process_submission_async' => array( 'd' => array() ),
			),
			'version' => 2,
		);

		$this->assertSame(
			array(
				'ffc_process_submission_async'        => 3,
				'ffc_reregistration_reminder_batch'   => 0,
				'ffc_reregistration_invitation_batch' => 0,
				'ffc_recruitment_account_link_batch'  => 0,
				'ffc_audience_notification_batch'     => 0,
				'ffc_date_messages_batch'             => 0,
				'ffc_date_messages_digest'            => 0,
				'ffc_date_messages_announce'          => 0,
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
			'ffc_daily_cleanup'            => '03:30',
			'ffc_self_scheduling_reminder_scan' => '04:00',
			'ffc_cloudflare_cidr_refresh'                 => '24:00',
			'not_ours'                                    => '05:00',
		);

		$this->assertSame( array( 'ffc_daily_cleanup' => '03:30' ), ScheduledTasks::times() );
		$this->assertSame( '03:30', ScheduledTasks::time_for( 'ffc_daily_cleanup' ) );
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
		$this->times = array( 'ffc_daily_cleanup' => '03:30' );

		$this->assertSame( 12345, ScheduledTasks::first_run( 'ffc_cloudflare_cidr_refresh', 12345 ) );
		$first = ScheduledTasks::first_run( 'ffc_daily_cleanup', 12345 );
		$this->assertSame( '03:30', gmdate( 'H:i', $first ) );
		$this->assertGreaterThan( time(), $first );
	}

	public function test_save_times_moves_only_changed_scheduled_tasks(): void {
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->times = array(
			'ffc_daily_cleanup' => '03:30',
			'ffc_cloudflare_cidr_refresh'      => '04:00',
		);
		$scheduled   = array( 'ffc_daily_cleanup', 'ffc_cloudflare_cidr_refresh', 'ffc_daily_expired_tickets_cleanup' );
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
				'ffc_daily_cleanup'         => '03:30', // unchanged
				'ffc_cloudflare_cidr_refresh'              => '',      // cleared
				'ffc_daily_expired_tickets_cleanup'        => '02:15', // new, scheduled
				'ffc_reregistration_expire' => '05:45', // new, not scheduled
				'ffc_date_messages_daily'                  => '7:00',  // invalid
				'ffc_self_scheduling_reminder_scan' => '01:00', // hourly: ignored
			)
		);

		$this->assertSame( array( 'ffc_date_messages_daily' ), $invalid );
		$this->assertSame(
			array(
				'ffc_daily_cleanup'         => '03:30',
				'ffc_daily_expired_tickets_cleanup'        => '02:15',
				'ffc_reregistration_expire' => '05:45',
			),
			$this->times
		);
		$this->assertFalse( $this->writes[ ScheduledTasks::TIMES_OPTION ][1], 'Not autoloaded.' );
		$this->assertSame( array( 'ffc_daily_expired_tickets_cleanup' ), $cleared, 'Only a changed, scheduled task moves; a cleared one stays where it is.' );
		$this->assertSame( array( 'ffc_daily_expired_tickets_cleanup' => array( '02:15', 'daily' ) ), $events );
	}

	// ------------------------------------------------------------------
	// Pre-#2323 names
	// ------------------------------------------------------------------

	public function test_every_legacy_name_maps_to_a_registered_recurring_hook(): void {
		$this->assertCount( 6, ScheduledTasks::LEGACY_HOOKS );
		foreach ( ScheduledTasks::LEGACY_HOOKS as $old => $new ) {
			$this->assertStringStartsWith( 'ffcertificate_', $old );
			$this->assertStringStartsWith( 'ffc_', $new );
			$this->assertArrayHasKey( $new, ScheduledTasks::all(), $new );
			$this->assertNotSame( ScheduledTasks::SINGLE, ScheduledTasks::all()[ $new ]['recurrence'], $new );
		}
	}

	/**
	 * An event under an old name moves to the new one at the same time and
	 * with the same recurrence; the stored heartbeat and chosen time follow.
	 */
	public function test_migration_moves_an_old_event_with_its_time_and_keys(): void {
		$events = array(
			'ffcertificate_daily_cleanup_hook'            => array( 1900000000, 'daily' ),
			'ffcertificate_self_scheduling_reminder_scan' => array( 1900000500, 'hourly' ),
		);
		Functions\when( 'wp_next_scheduled' )->alias( static fn( $hook ) => isset( $events[ $hook ] ) ? $events[ $hook ][0] : false );
		Functions\when( 'wp_get_schedule' )->alias( static fn( $hook ) => isset( $events[ $hook ] ) ? $events[ $hook ][1] : false );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$events ) {
				unset( $events[ $hook ] );
				return 1;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $ts, $recurrence, $hook ) use ( &$events ) {
				$events[ $hook ] = array( $ts, $recurrence );
				return true;
			}
		);
		$this->stored = array( 'ffcertificate_daily_cleanup_hook' => 111 );
		$this->times  = array( 'ffcertificate_daily_cleanup_hook' => '03:30' );

		$this->assertSame( 2, ScheduledTasks::migrate_legacy_hooks() );

		$this->assertSame(
			array(
				'ffc_daily_cleanup'                 => array( 1900000000, 'daily' ),
				'ffc_self_scheduling_reminder_scan' => array( 1900000500, 'hourly' ),
			),
			$events
		);
		$this->assertSame( array( 'ffc_daily_cleanup' => 111 ), $this->stored );
		$this->assertSame( array( 'ffc_daily_cleanup' => '03:30' ), $this->times );
	}

	public function test_migration_never_schedules_a_second_copy(): void {
		$events = array(
			'ffcertificate_bulk_issue_purge' => array( 1900000000, 'daily' ),
			'ffc_bulk_issue_purge'           => array( 1900009999, 'daily' ),
		);
		Functions\when( 'wp_next_scheduled' )->alias( static fn( $hook ) => isset( $events[ $hook ] ) ? $events[ $hook ][0] : false );
		Functions\when( 'wp_get_schedule' )->justReturn( 'daily' );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$events ) {
				unset( $events[ $hook ] );
				return 1;
			}
		);
		$log = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_schedule_event', true );
		$this->stored = array(
			'ffcertificate_bulk_issue_purge' => 1,
			'ffc_bulk_issue_purge'           => 2,
		);

		$this->assertSame( 1, ScheduledTasks::migrate_legacy_hooks() );

		$this->assertSame( 0, $log->count() );
		$this->assertSame( array( 'ffc_bulk_issue_purge' => array( 1900009999, 'daily' ) ), $events );
		$this->assertSame( array( 'ffc_bulk_issue_purge' => 2 ), $this->stored, 'The value under the new name wins.' );
	}

	public function test_migration_with_nothing_left_writes_nothing(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$log = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_clear_scheduled_hook', 0 );

		$this->assertSame( 0, ScheduledTasks::migrate_legacy_hooks() );

		$this->assertSame( 0, $log->count() );
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * A callback a site attached to an old name still runs when the new hook
	 * fires, through do_action_deprecated() naming the replacement.
	 */
	public function test_an_old_name_with_a_listener_is_relayed_as_deprecated(): void {
		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$callbacks ) {
				if ( 998 === $priority ) {
					$callbacks[ $hook ] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'has_action' )->alias( static fn( $hook ) => 'ffcertificate_daily_cleanup_hook' === $hook );
		$relayed = array();
		Functions\when( 'do_action_deprecated' )->alias(
			static function ( $hook, $args, $version, $replacement ) use ( &$relayed ) {
				$relayed[] = array( $hook, $args, $version, $replacement );
			}
		);

		ScheduledTasks::init();

		$this->assertSame( array_values( ScheduledTasks::LEGACY_HOOKS ), array_keys( $callbacks ) );
		$callbacks['ffc_daily_cleanup']();
		$callbacks['ffc_bulk_issue_purge']();

		$this->assertSame( array( array( 'ffcertificate_daily_cleanup_hook', array(), '6.43.0', 'ffc_daily_cleanup' ) ), $relayed, 'Only the old name with a listener is relayed.' );
	}
}
