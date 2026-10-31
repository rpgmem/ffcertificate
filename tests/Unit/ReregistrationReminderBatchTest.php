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
 * WHAT RUNS FOR REAL
 *
 * The repository, the reader, the writer, the date formatter, the mailer and
 * the password invite, over a `$wpdb` double that serves the staged campaign
 * and submissions (honouring the keyset cursor and the page size in the
 * query) and records each `reminder_sent_at` stamp. The email goes out
 * through the plugin's whole path to a stubbed `wp_mail()`; that it reaches
 * an inbox is not proved here.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 */
class ReregistrationReminderBatchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Captured `wp_schedule_single_event` calls.
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

	/**
	 * Submission ids stamped `reminder_sent_at`, in order.
	 *
	 * @var list<int>
	 */
	private array $reminded = array();

	/**
	 * The staged campaign, or null before `stage()`.
	 *
	 * @var object|null
	 */
	private ?object $campaign = null;

	/**
	 * The staged submission rows, in id order.
	 *
	 * @var list<object>
	 */
	private array $rows = array();

	/**
	 * Every query the double answered, after interpolation.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Every `wp_mail()` call, as `[ to, body ]`.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $mails = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The campaign window is read on the site's clock (#2242); pin it
		// inside the fixtures' campaigns.
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? '2026-06-15' : ( 'mysql' === $type ? '2026-06-15 12:00:00' : 0 );
			}
		);


		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationEmailHandler' );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// The password invite is the real class: a key per user, appended to
		// the dashboard URL.
		Functions\when( 'get_password_reset_key' )->alias( static fn ( $user ) => 'key' . $user->ID );
		// `issue_for()` refuses staff accounts (#2246); these recipients are not.
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args, $url ) => $url . '?' . http_build_query( (array) $args )
		);

		$this->mails = array();
		$mails       = &$this->mails;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body ) use ( &$mails ) {
				$mails[] = array( (string) $to, (string) $body );
				return true;
			}
		);

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

		$this->scheduled      = array();
		$this->next_scheduled = false;
		$this->reminded       = array();
		$this->campaign       = null;
		$this->rows           = array();
		$this->queries        = array();
		$GLOBALS['wpdb']      = $this->database();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database answering from the staged campaign and rows. A query it does
	 * not recognise throws, so an unexpected read fails the test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->users      = 'wp_users';
		$wpdb->last_error = '';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace_callback(
						'/%([ids])/',
						static fn ( $m ) => 's' === $m[1] ? "'" . $v . "'" : (string) $v,
						(string) $sql,
						1
					);
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/FROM wp_ffc_reregistrations WHERE id = (\d+)/', $sql, $m ) ) {
					return ( null !== $this->campaign && (int) $this->campaign->id === (int) $m[1] ) ? $this->campaign : null;
				}
				throw new \LogicException( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = (\d+) AND status IN \(\'pending\',\'in_progress\',\'rejected\'\) AND \( reminder_sent_at IS NULL/', $sql, $m ) ) {
					$after = preg_match( '/AND id > (\d+)/', $sql, $c ) ? (int) $c[1] : 0;
					$rows  = array_values(
						array_filter(
							$this->rows,
							static fn ( $s ) => (int) $s->reregistration_id === (int) $m[1] && (int) $s->id > $after
						)
					);
					return preg_match( '/LIMIT (\d+)/', $sql, $l ) ? array_slice( $rows, 0, (int) $l[1] ) : $rows;
				}
				throw new \LogicException( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				if ( 'wp_ffc_reregistration_submissions' !== $table || array( 'reminder_sent_at' ) !== array_keys( $data ) ) {
					throw new \LogicException( 'Unexpected update of ' . $table );
				}
				$this->reminded[] = (int) $where['id'];
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * The first query the double saw that contains a fragment, or ''.
	 *
	 * @param string $fragment Text to look for.
	 * @return string
	 */
	private function query_with( string $fragment ): string {
		foreach ( $this->queries as $query ) {
			if ( str_contains( $query, $fragment ) ) {
				return $query;
			}
		}
		return '';
	}

	/**
	 * Stages the campaign and the submissions awaiting a reminder.
	 *
	 * @param int       $rows      How many rows await.
	 * @param list<int> $failing   `user_id`s whose `get_userdata` fails.
	 * @return void
	 */
	private function stage( int $rows, array $failing = array() ): void {
		$this->campaign = (object) array(
			'id'                     => 7,
			'title'                  => 'Campaign',
			'email_reminder_enabled' => 1,
			'start_date'             => '2026-01-01',
			'end_date'               => '2099-12-31',
		);

		for ( $i = 1; $i <= $rows; $i++ ) {
			$this->rows[] = (object) array(
				'id'                => $i,
				'reregistration_id' => 7,
				'user_id'           => 1000 + $i,
				'status'            => 'pending',
			);
		}

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $failing ) {
				if ( in_array( (int) $id, $failing, true ) ) {
					// A submission whose user was deleted: #822's accepted
					// orphan. It is the case that jams the queue without the
					// cursor.
					return false;
				}
				$user               = new \WP_User( (int) $id );
				$user->user_login   = 'login' . $id;
				$user->display_name = 'U' . $id;
				$user->user_email   = 'u' . $id . '@example.com';
				return $user;
			}
		);
	}

	// ==================================================================
	// Termination
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

		$this->assertCount( ReregistrationEmailHandler::REMINDER_BATCH_SIZE - 1, $this->mails );
		$this->assertSame( range( 1, ReregistrationEmailHandler::REMINDER_BATCH_SIZE - 1 ), $this->reminded, 'Each reminded row is stamped as it goes.' );
		$this->assertStringEndsWith( 'ORDER BY id ASC LIMIT ' . ReregistrationEmailHandler::REMINDER_BATCH_SIZE, $this->query_with( 'reminder_sent_at IS NULL' ), 'The batch reads one page.' );
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
	// Progress — the central property
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

		$this->assertSame( range( 1, $size - 1 ), $this->reminded, 'The row whose user was deleted is never stamped.' );
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
