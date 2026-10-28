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
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationInvitationBatchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

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
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function () {
				++$this->stamps;
				return 1;
			}
		)->byDefault();
		$this->wpdb = $wpdb;

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_date' )->andReturn( '2026-01-01' );
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\SchedulingMailer' )
			->shouldReceive( 'send' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\PasswordInvite' )
			->shouldReceive( 'issue_for' )->andReturn( 'https://example.com/set-password' );
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
			'id'                       => 7,
			'title'                    => 'Census',
			'email_invitation_enabled' => 1,
			'start_date'               => '2026-01-01',
			'end_date'                 => '2099-12-31',
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
	// The batch
	// ==================================================================

	public function test_a_short_page_sends_and_stamps_each_row_and_does_not_reschedule(): void {
		$this->stage( 3 );

		ReregistrationEmailHandler::send_invitation_batch( 7, 0 );

		$this->assertSame( 3, $this->stamps, 'Each mailed row is stamped as it goes, not at the end.' );
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
		$values = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$values ) {
				if ( false !== strpos( (string) $sql, 'invited_at IS NULL' ) ) {
					$values = $args[0];
				}
				return 'query';
			}
		);
		$this->stage( 1 );

		ReregistrationEmailHandler::send_invitation_batch( 7, 120 );

		$this->assertSame( array( 120, ReregistrationEmailHandler::REMINDER_BATCH_SIZE ), array_slice( $values, -2 ) );
	}

	/**
	 * The manual button still sends to everybody owed one, in one request:
	 * no page, no continuation.
	 */
	public function test_the_manual_send_reads_every_row_and_schedules_nothing(): void {
		$sql = '';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query ) use ( &$sql ) {
				if ( false !== strpos( (string) $query, 'invited_at IS NULL' ) ) {
					$sql = (string) $query;
				}
				return 'query';
			}
		);
		$this->stage( ReregistrationEmailHandler::REMINDER_BATCH_SIZE + 5 );

		$sent = ReregistrationEmailHandler::send_invitations( 7 );

		$this->assertSame( ReregistrationEmailHandler::REMINDER_BATCH_SIZE + 5, $sent );
		$this->assertStringNotContainsString( 'LIMIT', $sql );
		$this->assertSame( array(), $this->scheduled );
	}

	// ==================================================================
	// The daily sweep
	// ==================================================================

	public function test_the_daily_sweep_starts_a_batch_for_every_active_campaign_that_invites(): void {
		$sweep = '';
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $query ) use ( &$sweep ) {
				if ( false !== strpos( (string) $query, 'email_invitation_enabled = 1' ) ) {
					$sweep = (string) $query;
				}
				return 'query';
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '7', '7' ) );
		$this->stage( 2 );

		ReregistrationEmailHandler::run_pending_invitations();

		$this->assertStringContainsString( "status = 'active'", $sweep );
		$this->assertSame( 4, $this->stamps, 'Two campaigns of two rows each.' );
	}

	public function test_the_daily_sweep_does_nothing_without_a_campaign(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldNotReceive( 'get_row' );

		ReregistrationEmailHandler::run_pending_invitations();

		$this->assertSame( 0, $this->stamps );
	}

	public function test_the_daily_sweep_is_silent_when_emails_are_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default_value = false ) => 'ffc_settings' === $key ? array( 'disable_all_emails' => 1 ) : $default_value
		);
		$this->wpdb->shouldNotReceive( 'get_col' );

		ReregistrationEmailHandler::run_pending_invitations();

		$this->assertSame( 0, $this->stamps );
	}
}
