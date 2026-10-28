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
 * Reregistration invitations go out with the daily run, in batches (#1762),
 * instead of the moment a campaign is activated.
 *
 * The shape is the reminder batch's ({@see ReregistrationReminderBatchTest}),
 * and so is the property that matters most: the keyset cursor advances per
 * row SEEN, so a row whose send fails cannot stall the queue.
 *
 * The repository, the reader, the writer, the date formatter, the mailer and
 * the password invite are the real classes, over a `$wpdb` double that serves
 * the staged campaign and submissions (honouring the keyset cursor and the
 * page size in the query) and records each `invited_at` stamp; the email goes
 * out through the plugin's whole path to a stubbed `wp_mail()`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 */
class ReregistrationInvitationBatchTest extends TestCase {

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
	 * How many `invited_at` stamps were written (one `UPDATE` each).
	 *
	 * @var int
	 */
	private int $stamps = 0;

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
	 * Campaign ids the daily sweep's `get_col` returns.
	 *
	 * @var list<string>
	 */
	private array $sweep_ids = array();

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
		$this->stamps         = 0;
		$this->campaign       = null;
		$this->rows           = array();
		$this->sweep_ids      = array();
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
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = (\d+) AND \( invited_at IS NULL/', $sql, $m ) ) {
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

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( str_contains( $sql, 'email_invitation_enabled = 1' ) ) {
					return $this->sweep_ids;
				}
				throw new \LogicException( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/^UPDATE wp_ffc_reregistration_submissions SET invited_at = \d+ WHERE id IN \(\d+\)$/', $sql ) ) {
					++$this->stamps;
					return 1;
				}
				throw new \LogicException( 'Unexpected query: ' . $sql );
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
	 * Stages the campaign and the submissions awaiting an invitation.
	 *
	 * @param int       $rows      How many rows await.
	 * @param list<int> $failing   `user_id`s whose `get_userdata` fails.
	 * @return void
	 */
	private function stage( int $rows, array $failing = array() ): void {
		$this->campaign = (object) array(
			'id'                       => 7,
			'title'                    => 'Census',
			'email_invitation_enabled' => 1,
			'start_date'               => '2026-01-01',
			'end_date'                 => '2099-12-31',
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
	// The batch
	// ==================================================================

	public function test_a_short_page_sends_and_stamps_each_row_and_does_not_reschedule(): void {
		$this->stage( 3 );

		ReregistrationEmailHandler::send_invitation_batch( 7, 0 );

		$this->assertSame( 3, $this->stamps, 'Each mailed row is stamped as it goes, not at the end.' );
		$this->assertSame( array( 'u1001@example.com', 'u1002@example.com', 'u1003@example.com' ), array_column( $this->mails, 0 ) );
		// The password invite is issued per user, so each body carries its
		// own recipient's key.
		$this->assertStringContainsString( 'ffc_key=key1001', $this->mails[0][1] );
		$this->assertStringContainsString( 'ffc_key=key1003', $this->mails[2][1] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_full_page_reschedules_with_the_cursor(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		$this->stage( $size );

		ReregistrationEmailHandler::send_invitation_batch( 7, 0 );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( ReregistrationEmailHandler::INVITATION_BATCH_HOOK, $this->scheduled[0][1] );
		$this->assertSame( array( 7, $size ), $this->scheduled[0][2] );
		$this->assertGreaterThanOrEqual( time() + ReregistrationEmailHandler::REMINDER_BATCH_DELAY - 1, $this->scheduled[0][0] );
	}

	/**
	 * A row whose user was deleted is never stamped, so a cursor that stopped
	 * on the last SUCCESSFUL send would read it again on every batch.
	 */
	public function test_the_cursor_advances_past_a_row_that_cannot_be_sent(): void {
		$size = ReregistrationEmailHandler::REMINDER_BATCH_SIZE;
		$this->stage( $size, array( 1000 + $size ) );

		ReregistrationEmailHandler::send_invitation_batch( 7, 0 );

		$this->assertSame( $size - 1, $this->stamps );
		$this->assertSame( array( 7, $size ), $this->scheduled[0][2] );
	}

	public function test_an_already_queued_batch_is_not_queued_twice(): void {
		$this->next_scheduled = time() + 30;
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE );

		ReregistrationEmailHandler::send_invitation_batch( 7, 0 );

		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_batch_asks_the_reader_for_one_page_after_the_cursor(): void {
		$this->stage( 1 );

		ReregistrationEmailHandler::send_invitation_batch( 7, 120 );

		$sql = $this->query_with( 'invited_at IS NULL' );
		$this->assertStringEndsWith( ' AND id > 120 ORDER BY id ASC LIMIT ' . ReregistrationEmailHandler::REMINDER_BATCH_SIZE, $sql );
	}

	/**
	 * The manual button still sends to everybody owed one, in one request:
	 * no page, no continuation.
	 */
	public function test_the_manual_send_reads_every_row_and_schedules_nothing(): void {
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE + 5 );

		$sent = ReregistrationEmailHandler::send_invitations( 7 );

		$sql = $this->query_with( 'invited_at IS NULL' );
		$this->assertSame( ReregistrationEmailHandler::REMINDER_BATCH_SIZE + 5, $sent );
		$this->assertNotSame( '', $sql );
		$this->assertStringNotContainsString( 'LIMIT', $sql );
		$this->assertSame( array(), $this->scheduled );
	}

	// ==================================================================
	// The daily sweep
	// ==================================================================

	public function test_the_daily_sweep_starts_a_batch_for_every_active_campaign_that_invites(): void {
		$this->sweep_ids = array( '7', '7' );
		$this->stage( 2 );

		ReregistrationEmailHandler::run_pending_invitations();

		$sweep = $this->query_with( 'email_invitation_enabled = 1' );
		$this->assertStringContainsString( "status = 'active'", $sweep );
		$this->assertCount( 1, array_filter( $this->queries, static fn ( $q ) => str_contains( $q, 'email_invitation_enabled = 1' ) ), 'One sweep per run.' );
		$this->assertSame( 4, $this->stamps, 'Two campaigns of two rows each.' );
	}

	public function test_the_daily_sweep_does_nothing_without_a_campaign(): void {
		$this->sweep_ids = array();

		ReregistrationEmailHandler::run_pending_invitations();

		$this->assertCount( 1, $this->queries, 'Only the sweep runs: no campaign is read.' );
		$this->assertSame( 0, $this->stamps );
	}

	public function test_the_daily_sweep_is_silent_when_emails_are_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default_value = false ) => 'ffc_settings' === $key ? array( 'disable_all_emails' => 1 ) : $default_value
		);

		ReregistrationEmailHandler::run_pending_invitations();

		$this->assertSame( array(), $this->queries, 'The kill-switch comes before the sweep.' );
		$this->assertSame( 0, $this->stamps );
	}
}
