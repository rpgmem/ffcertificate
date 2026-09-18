<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationEmailHandler;

/**
 * The reregistration reminder goes out in BATCHES, and the batch advances
 * (#1232 step 2).
 *
 * WHY BATCH
 *
 * `run_automated_reminders()` runs on wp-cron, that is, inside a visitor's
 * request. The per-item stamp delivered in step 1 already made the send
 * resumable BETWEEN daily runs, but the reach stayed bounded by (how much fits
 * in one run) x `reminder_days` -- on a campaign of thousands, the deadline
 * expires before everybody has been reminded.
 *
 * THE PROPERTY THIS FILE EXISTS TO PIN
 *
 * It is not "the batch holds 50 rows": it is that the batch **PROGRESSES**. The
 * keyset cursor advances per row SEEN, not per successful send, and that is
 * what separates a loop that terminates from one that does not.
 *
 * The case is not hypothetical and is documented in the code: `user_id` in
 * `ffc_reregistration_submissions` is `NOT NULL` and an ACCEPTED ORPHAN (#822),
 * so deleting the account in WordPress leaves the submission pointing at a user
 * that does not exist. `send_to_user()` returns `false` at `get_userdata()`,
 * `mark_reminded()` never runs, and `reminder_sent_at` stays NULL forever. A
 * loop driven only by `reminder_sent_at IS NULL` would fetch that row again on
 * every batch and reschedule itself every 60 seconds, without end.
 *
 * WHAT THIS FILE DOES NOT PROVE
 *
 * That the email arrives. `SchedulingMailer` is a double; what is observed here
 * is the scheduling and the cursor.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class ReregistrationReminderBatchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Chamadas capturadas de `wp_schedule_single_event`.
	 *
	 * @var list<array{0: int, 1: string, 2: array<int, mixed>}>
	 */
	private array $scheduled = array();

	/**
	 * What `wp_next_scheduled` answers.
	 *
	 * @var int|false
	 */
	private $next_scheduled = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationEmailHandler' );

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) {
				if ( 'ffc_settings' === $key ) {
					return array();
				}
				if ( 'ffc_dashboard_page_id' === $key ) {
					return 0;
				}
				return $default_value;
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Functions\when( 'wp_next_scheduled' )->alias(
			function () {
				return $this->next_scheduled;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->scheduled[] = array( (int) $timestamp, (string) $hook, (array) $args );
				return true;
			}
		);

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->users      = 'wp_users';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'query' )->byDefault();
		$wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();
		$this->wpdb = $wpdb;

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_date' )->andReturn( '2026-01-01' );
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
			->shouldReceive( 'send' )->andReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stages the campaign and the page of submissions the reader returns.
	 *
	 * @param int       $rows      How many rows the page brings.
	 * @param list<int> $failing   `user_id`s whose `get_userdata` fails.
	 * @return void
	 */
	private function stage( int $rows, array $failing = array() ): void {
		$rereg = (object) array(
			'id'                     => 7,
			'title'                  => 'Campanha',
			'email_reminder_enabled' => 1,
			'start_date'             => '2026-01-01',
			'end_date'               => '2099-12-31',
		);

		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $rereg );

		$page = array();
		for ( $i = 1; $i <= $rows; $i++ ) {
			$page[] = (object) array(
				'id'      => $i,
				'user_id' => 1000 + $i,
				'status'  => 'pending',
			);
		}
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( $page );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $failing ) {
				if ( in_array( (int) $id, $failing, true ) ) {
					// A submission whose user was deleted: #822's accepted
					// orphan. It is the case that jams the queue without the
					// cursor.
					return false;
				}
				return (object) array(
					'display_name' => 'U' . $id,
					'user_email'   => 'u' . $id . '@example.com',
				);
			}
		);
	}

	// ==================================================================
	// Encerramento
	// ==================================================================

	/**
	 * A page smaller than the batch ends the queue: nothing is rescheduled.
	 *
	 * It is what keeps a small campaign identical to the behaviour before
	 * batching -- it finishes in a single run, queueing nothing.
	 */
	public function test_a_short_page_does_not_reschedule(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE - 1 );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertSame( array(), $this->scheduled, 'An incomplete page means an empty queue; rescheduling there produces a useless run forever.' );
	}

	/**
	 * A full page reschedules, with the campaign and the cursor in the payload.
	 *
	 * The pair of the assertion above: without it, a driver that never
	 * reschedules would pass both tests and the batching would not exist.
	 */
	public function test_a_full_page_reschedules_with_the_cursor(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		$this->stage( $size );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( ReregistrationEmailHandler::REMINDER_BATCH_HOOK, $this->scheduled[0][1] );
		$this->assertSame( array( 7, $size ), $this->scheduled[0][2] );
	}

	// ==================================================================
	// Progresso — a propriedade central
	// ==================================================================

	/**
	 * The cursor advances past a row that CANNOT be sent.
	 *
	 * This is the assertion the file exists to hold up. The page's last row has a
	 * deleted user, so it never receives a stamp. If the cursor were "the last
	 * successfully sent", the next batch would start before it, fetch it again,
	 * see a full page again and reschedule -- every 60 seconds, forever.
	 */
	public function test_the_cursor_advances_past_a_row_that_cannot_be_sent(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		// The LAST row of the page is the one that fails: that is where the
		// difference between "row seen" and "successful send" becomes visible.
		$this->stage( $size, array( 1000 + $size ) );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame(
			array( 7, $size ),
			$this->scheduled[0][2],
			'The cursor stopped on a row that will never be stamped — the next batch fetches it again and the cycle never ends.'
		);
	}

	/**
	 * The payload carries scalars only.
	 *
	 * The `cron` option is autoloaded and unserialised on EVERY request to the
	 * site, so a fat payload costs everywhere, all the time -- not only here. Two
	 * integers is what the design asks for.
	 */
	public function test_the_payload_carries_only_scalars(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		foreach ( $this->scheduled[0][2] as $arg ) {
			$this->assertIsInt( $arg );
		}
	}

	/**
	 * An identical batch already queued is not queued again.
	 *
	 * Two visitors can trigger wp-cron almost together; without the guard, the
	 * same campaign would enter the queue twice and each participant would get
	 * two emails -- exactly the duplication step 1 fixed, reintroduced by step 2.
	 */
	public function test_an_already_queued_batch_is_not_queued_twice(): void {
		$this->next_scheduled = time() + 30;
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertSame( array(), $this->scheduled );
	}

	/**
	 * The delay between batches is the declared one.
	 *
	 * It freezes the value against an accidental change, and the constant's
	 * docblock holds the caveat the number cannot express: with WP-Cron it is a
	 * floor, not a promise.
	 */
	public function test_the_next_batch_is_scheduled_after_the_declared_delay(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		$before = time();
		ReregistrationEmailHandler::send_reminder_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertGreaterThanOrEqual(
			$before + ReregistrationEmailHandler::REMINDER_BATCH_DELAY,
			$this->scheduled[0][0]
		);
	}
}
