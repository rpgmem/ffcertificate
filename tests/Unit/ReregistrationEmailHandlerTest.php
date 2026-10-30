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
 * Tests for ReregistrationEmailHandler: invitation, reminder, confirmation,
 * and automated reminder logic.
 *
 * All public methods begin with early-return checks (emails disabled,
 * missing data, missing template) which we exercise thoroughly.
 *
 * The repository, the reader, the writer, the date formatter, the mailer, the
 * magic-link helper and the document formatter are the real classes. They
 * run over a `$wpdb` double holding the campaigns and submissions each test
 * stages, which answers the reads by the query's table and bound ids and
 * records every write; the email goes out through the plugin's whole path to
 * a stubbed `wp_mail()`, whose calls are what the tests read.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 */
class ReregistrationEmailHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Campaign rows by id.
	 *
	 * @var array<int, object>
	 */
	private array $campaigns = array();

	/**
	 * Submission rows, in id order.
	 *
	 * @var list<object>
	 */
	private array $submissions = array();

	/**
	 * What the due-reminder sweep query returns.
	 *
	 * @var array<int, object>|null
	 */
	private $due_campaigns = array();

	/**
	 * Every query the double answered, after interpolation.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Submission ids stamped `invited_at`, in order.
	 *
	 * @var list<int>
	 */
	private array $invited = array();

	/**
	 * Every `$wpdb->update()`, as `[ table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/**
	 * Every `wp_mail()` call, as `[ to, subject, body ]`.
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
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


		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// Default: emails NOT disabled, activity log off.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) {
				if ( 'ffc_settings' === $key ) {
					return array();
				}
				if ( 'ffc_dashboard_page_id' === $key ) {
					return 0;
				}
				if ( 'date_format' === $key ) {
					return 'Y-m-d';
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
		Functions\when( 'trailingslashit' )->alias( static fn ( $s ) => rtrim( (string) $s, '/' ) . '/' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		$this->mails = array();
		$mails       = &$this->mails;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body ) use ( &$mails ) {
				$mails[] = array( (string) $to, (string) $subject, (string) $body );
				return true;
			}
		);

		$this->campaigns     = array();
		$this->submissions   = array();
		$this->due_campaigns = array();
		$this->queries       = array();
		$this->invited       = array();
		$this->updates       = array();
		$GLOBALS['wpdb']     = $this->database();
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
	 * A database answering from the staged campaigns and submissions. A query
	 * it does not recognise throws, so an unexpected read fails the test.
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
					return $this->campaigns[ (int) $m[1] ] ?? null;
				}
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE id = (\d+)/', $sql, $m ) ) {
					return $this->find_submission( static fn ( $s ) => (int) $s->id === (int) $m[1] );
				}
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = (\d+) AND user_id = (\d+)/', $sql, $m ) ) {
					return $this->find_submission(
						static fn ( $s ) => (int) $s->reregistration_id === (int) $m[1] && (int) $s->user_id === (int) $m[2]
					);
				}
				throw new \LogicException( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( str_contains( $sql, 'DATEDIFF(end_date' ) ) {
					return $this->due_campaigns;
				}
				if ( preg_match( '/FROM wp_ffc_reregistration_submissions WHERE reregistration_id = (\d+)/', $sql, $m ) ) {
					$after = preg_match( '/AND id > (\d+)/', $sql, $c ) ? (int) $c[1] : 0;
					$rows  = array_values(
						array_filter(
							$this->submissions,
							static fn ( $s ) => (int) $s->reregistration_id === (int) $m[1] && (int) $s->id > $after
						)
					);
					return preg_match( '/LIMIT (\d+)/', $sql, $l ) ? array_slice( $rows, 0, (int) $l[1] ) : $rows;
				}
				throw new \LogicException( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/UPDATE wp_ffc_reregistration_submissions SET invited_at = \d+ WHERE id IN \(([\d, ]+)\)/', $sql, $m ) ) {
					$ids           = array_map( 'intval', explode( ',', $m[1] ) );
					$this->invited = array_merge( $this->invited, $ids );
					return count( $ids );
				}
				throw new \LogicException( 'Unexpected query: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * The first staged submission matching a predicate.
	 *
	 * @param callable $match Predicate over a submission row.
	 * @return object|null
	 */
	private function find_submission( callable $match ): ?object {
		foreach ( $this->submissions as $submission ) {
			if ( $match( $submission ) ) {
				return $submission;
			}
		}
		return null;
	}

	/**
	 * Stage a campaign row.
	 *
	 * @param array<string, mixed> $fields Fields over the defaults.
	 * @return void
	 */
	private function campaign( array $fields ): void {
		$row = (object) array_merge(
			array(
				'id'         => 1,
				'title'      => 'Campaign',
				'start_date' => '2026-01-01',
				'end_date'   => '2026-12-31',
			),
			$fields
		);

		$this->campaigns[ (int) $row->id ] = $row;
	}

	/**
	 * Stage a submission row.
	 *
	 * @param int    $id                Submission id.
	 * @param int    $reregistration_id Campaign id.
	 * @param int    $user_id           Owner.
	 * @param string $status            Status.
	 * @param array  $fields            Further columns.
	 * @return void
	 */
	private function submission( int $id, int $reregistration_id, int $user_id, string $status, array $fields = array() ): void {
		$this->submissions[] = (object) array_merge(
			array(
				'id'                => $id,
				'reregistration_id' => $reregistration_id,
				'user_id'           => $user_id,
				'status'            => $status,
				'magic_token'       => '',
				'auth_code'         => '',
			),
			$fields
		);
	}

	/**
	 * `get_userdata()` answering every id with a user whose address names it.
	 *
	 * @param string $prefix Display-name and address prefix.
	 * @return void
	 */
	private function users( string $prefix = 'user' ): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $prefix ) {
				return (object) array(
					'display_name' => $prefix . ' ' . $id,
					'user_email'   => $prefix . $id . '@example.com',
				);
			}
		);
	}

	/**
	 * The recipients `wp_mail()` was called with, in order.
	 *
	 * @return list<string>
	 */
	private function recipients(): array {
		return array_column( $this->mails, 0 );
	}

	/**
	 * Updates written to the submissions table, as `[ id, data ]`.
	 *
	 * @return list<array{0: int, 1: array<string, mixed>}>
	 */
	private function submission_updates(): array {
		$out = array();
		foreach ( $this->updates as $update ) {
			if ( 'wp_ffc_reregistration_submissions' === $update[0] ) {
				$out[] = array( (int) $update[2]['id'], $update[1] );
			}
		}
		return $out;
	}

	// ==================================================================
	// send_invitations()
	// ==================================================================

	public function test_send_invitations_returns_zero_when_emails_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

		$result = ReregistrationEmailHandler::send_invitations( 1 );
		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->queries, 'The kill-switch comes before the campaign read.' );
	}

	public function test_send_invitations_returns_zero_when_rereg_not_found(): void {
		$result = ReregistrationEmailHandler::send_invitations( 999 );

		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_send_invitations_returns_zero_when_invitation_not_enabled(): void {
		$this->campaign(
			array(
				'id'                       => 1,
				'title'                    => 'Test Campaign',
				'email_invitation_enabled' => 0,
			)
		);
		$this->submission( 101, 1, 10, 'pending' );
		$this->users();

		$result = ReregistrationEmailHandler::send_invitations( 1 );

		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->invited );
	}

	// ==================================================================
	// send_reminders()
	// ==================================================================

	public function test_send_reminders_returns_zero_when_emails_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

		$result = ReregistrationEmailHandler::send_reminders( 1 );
		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->queries, 'The kill-switch comes before the campaign read.' );
	}

	public function test_send_reminders_returns_zero_when_rereg_not_found(): void {
		$result = ReregistrationEmailHandler::send_reminders( 999 );

		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_send_reminders_returns_zero_when_reminder_not_enabled(): void {
		$this->campaign(
			array(
				'id'                     => 1,
				'title'                  => 'Test Campaign',
				'email_reminder_enabled' => 0,
			)
		);
		$this->submission( 101, 1, 10, 'pending' );
		$this->users();

		$result = ReregistrationEmailHandler::send_reminders( 1 );

		$this->assertSame( 0, $result );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( array(), $this->updates );
	}

	// ==================================================================
	// send_confirmation()
	// ==================================================================

	public function test_send_confirmation_returns_false_when_emails_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

		$result = ReregistrationEmailHandler::send_confirmation( 1 );
		$this->assertFalse( $result );
		$this->assertSame( array(), $this->queries, 'The kill-switch comes before the submission read.' );
	}

	public function test_send_confirmation_returns_false_when_submission_not_found(): void {
		$result = ReregistrationEmailHandler::send_confirmation( 999 );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_send_confirmation_returns_false_when_rereg_not_found(): void {
		// The submission exists; the campaign it points at does not.
		$this->submission( 1, 99, 10, 'submitted' );
		$this->users();

		$result = ReregistrationEmailHandler::send_confirmation( 1 );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->mails );
	}

	public function test_send_confirmation_returns_false_when_confirmation_not_enabled(): void {
		$this->submission( 1, 5, 10, 'submitted' );
		$this->campaign(
			array(
				'id'                         => 5,
				'title'                      => 'Test Campaign',
				'email_confirmation_enabled' => 0,
			)
		);
		$this->users();

		$result = ReregistrationEmailHandler::send_confirmation( 1 );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->mails );
	}

	// ==================================================================
	// run_automated_reminders()
	// ==================================================================

	public function test_run_automated_reminders_returns_early_when_emails_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

		ReregistrationEmailHandler::run_automated_reminders();

		// #1030: past the kill-switch the very next thing is the campaign
		// query, so it must not be reached.
		$this->assertSame( array(), $this->queries );
	}

	public function test_run_automated_reminders_returns_early_when_no_campaigns(): void {
		$this->due_campaigns = array();

		ReregistrationEmailHandler::run_automated_reminders();

		$this->assertCount( 1, $this->queries, 'Only the sweep runs: no campaign means no per-campaign read.' );
		$this->assertStringContainsString( 'DATEDIFF(end_date', $this->queries[0] );
	}

	/**
	 * The campaign dates are wall-clock values in the site's calendar, so the
	 * sweep compares them with the site's date, never the database server's
	 * `CURDATE()`, whose zone is whatever the host set (#2242).
	 */
	public function test_the_reminder_sweep_counts_days_from_the_site_date(): void {
		$this->due_campaigns = array();

		ReregistrationEmailHandler::run_automated_reminders();

		$this->assertStringNotContainsString( 'CURDATE', $this->queries[0] );
		$this->assertStringContainsString( "DATEDIFF(end_date, '2026-06-15') <= reminder_days", $this->queries[0] );
		$this->assertStringContainsString( "DATEDIFF(end_date, '2026-06-15') >= 0", $this->queries[0] );
	}

	public function test_run_automated_reminders_returns_early_when_campaigns_null(): void {
		$this->due_campaigns = null;

		ReregistrationEmailHandler::run_automated_reminders();

		$this->assertCount( 1, $this->queries, 'A failed sweep is treated as no campaign.' );
		$this->assertStringContainsString( 'DATEDIFF(end_date', $this->queries[0] );
	}

	public function test_run_automated_reminders_dispatches_per_campaign(): void {
		$this->due_campaigns = array( (object) array( 'id' => 11 ), (object) array( 'id' => 22 ) );
		// Reminders disabled on both, so each send short-circuits right after
		// its campaign read -- what is proved is that the loop fans out.
		$this->campaign(
			array(
				'id'                     => 11,
				'email_reminder_enabled' => 0,
			)
		);
		$this->campaign(
			array(
				'id'                     => 22,
				'email_reminder_enabled' => 0,
			)
		);

		ReregistrationEmailHandler::run_automated_reminders();

		$reads = array_values(
			array_filter( $this->queries, static fn ( $q ) => str_contains( $q, 'FROM wp_ffc_reregistrations WHERE id =' ) )
		);
		$this->assertSame(
			array(
				'SELECT * FROM wp_ffc_reregistrations WHERE id = 11',
				'SELECT * FROM wp_ffc_reregistrations WHERE id = 22',
			),
			$reads
		);
		$this->assertSame( array(), $this->mails );
	}

	// ==================================================================
	// send_invitations() — full success path through send_to_user
	// ==================================================================

	public function test_send_invitations_sends_to_pending_members(): void {
		$this->campaign(
			array(
				'id'                       => 1,
				'email_invitation_enabled' => 1,
				'audience_name'            => 'Teachers',
			)
		);
		// The rows carry an `id` because the send stamps `invited_at` on them
		// (#1190), and a `status` because the wording is chosen from it (#1300).
		$this->submission( 101, 1, 10, 'pending' );
		$this->submission( 102, 1, 20, 'pending' );
		$this->users();

		$count = ReregistrationEmailHandler::send_invitations( 1 );

		$this->assertSame( 2, $count );
		$this->assertSame( array( 'user10@example.com', 'user20@example.com' ), $this->recipients() );
		$this->assertSame( array( 101, 102 ), $this->invited, 'Whoever received it must be stamped, or the next send repeats it.' );
	}

	// ==================================================================
	// send_invitations() — the wording follows the status (#1300)
	// ==================================================================

	/**
	 * Stage a campaign whose awaiting-invitation rows carry the given statuses
	 * and return the bodies that were actually mailed.
	 *
	 * The body is read off `wp_mail()`, AFTER `TokenResolver` has run and the
	 * chrome has wrapped it, which is the only place the two status tokens are
	 * observable: they are chosen in `send_invitations()` and resolved in
	 * `send_to_user()`, and asserting on anything earlier would be asserting
	 * against the value this test supplies.
	 *
	 * @param array<int, string> $statuses One status per submission row.
	 * @return array<int, string> One rendered body per row.
	 */
	private function invitation_bodies( array $statuses ): array {
		$this->campaign(
			array(
				'id'                       => 1,
				'email_invitation_enabled' => 1,
				'audience_name'            => 'Teachers',
			)
		);
		foreach ( $statuses as $i => $status ) {
			$this->submission( 100 + $i, 1, 10 + $i, $status );
		}
		$this->users();

		ReregistrationEmailHandler::send_invitations( 1 );

		return array_column( $this->mails, 2 );
	}

	/**
	 * A recorded reregistration is not asked for again.
	 *
	 * `get_awaiting_invitation()` selects on `invited_at IS NULL` WHATEVER the
	 * status, so the campaign reaches people whose reregistration is already
	 * on file — everyone imported by CSV, and anyone who used the dashboard
	 * banner before the operator pressed Send invitations.
	 *
	 * The BUTTON is asserted alongside the sentence deliberately: a line saying
	 * the reregistration is recorded under a button saying "Complete
	 * Reregistration" makes the email contradict itself, which is worse than
	 * the single wrong sentence. They move together or not at all.
	 */
	public function test_send_invitations_does_not_ask_a_recorded_submission_to_complete_anything(): void {
		$bodies = $this->invitation_bodies( array( 'submitted' ) );

		$this->assertCount( 1, $bodies );
		$this->assertStringNotContainsString(
			'Please complete your reregistration before the deadline.',
			$bodies[0],
			'The campaign reaches people whose reregistration is already recorded; telling them to complete it is the defect.'
		);
		$this->assertStringNotContainsString(
			'Complete Reregistration',
			$bodies[0],
			'The button must not contradict the sentence above it.'
		);
		$this->assertStringContainsString( 'Your reregistration is already recorded', $bodies[0] );
		$this->assertStringContainsString( 'View My Reregistration', $bodies[0] );
	}

	/**
	 * `approved` is recorded too, and `rejected` is NOT.
	 *
	 * The tempting shortcut is "the complement of `UNFINISHED_STATUSES`", and
	 * it is wrong: that list carries `expired` and `rejected`, and both of
	 * those people still have something to do — `rejected` is in the
	 * frontend's `SUBMITTABLE_STATUSES`, so the dashboard draws them a form.
	 */
	public function test_send_invitations_treats_approved_as_recorded_and_rejected_as_pending(): void {
		$bodies = $this->invitation_bodies( array( 'approved', 'rejected' ) );

		$this->assertCount( 2, $bodies );
		$this->assertStringContainsString( 'Your reregistration is already recorded', $bodies[0] );
		$this->assertStringContainsString(
			'Please complete your reregistration before the deadline.',
			$bodies[1],
			'A rejected submission can still be resubmitted, so the invitation must keep asking for it.'
		);
	}

	/**
	 * The pending invitation is what it has always been.
	 *
	 * The tokens are an addition, not a rewrite: for the audience that could
	 * already act on this email, both resolve to the exact strings the template
	 * carried as literals before #1300.
	 */
	public function test_send_invitations_leaves_the_pending_wording_unchanged(): void {
		$bodies = $this->invitation_bodies( array( 'pending' ) );

		$this->assertCount( 1, $bodies );
		$this->assertStringContainsString( 'Please complete your reregistration before the deadline.', $bodies[0] );
		$this->assertStringContainsString( 'Complete Reregistration', $bodies[0] );
	}

	/**
	 * Both audiences still get the password link, and no token is left raw.
	 *
	 * The rejected alternative to this whole change was excluding recorded
	 * submissions from the invitation. It would strand a created account
	 * without `{{set_password_url}}` — the one thing it actually needs (#1212)
	 * — so the recorded person must keep receiving the email, and the link in
	 * it must resolve.
	 *
	 * The `{{` assertion is what proves the two new tokens are wired at all: a
	 * token the sender never supplies survives `TokenResolver` verbatim and
	 * would ship to the recipient as literal text.
	 */
	public function test_send_invitations_resolves_every_token_for_both_audiences(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'secret-key' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( (array) $args );
			}
		);

		$bodies = $this->invitation_bodies( array( 'submitted', 'pending' ) );

		$this->assertCount( 2, $bodies );
		foreach ( $bodies as $i => $body ) {
			$this->assertStringNotContainsString(
				'{{',
				$body,
				"Body {$i} shipped an unresolved token, which reaches the recipient as literal text."
			);
		}
	}

	// ==================================================================
	// send_reminders() — explicit user IDs filtered by status
	// ==================================================================

	public function test_send_reminders_with_explicit_user_ids(): void {
		$this->campaign(
			array(
				'id'                     => 1,
				'email_reminder_enabled' => 1,
				'end_date'               => '2099-12-31',
			)
		);
		$this->submission( 101, 1, 10, 'pending' );
		// User 20 already submitted → filtered out.
		$this->submission( 102, 1, 20, 'submitted' );
		$this->users( 'u' );

		$count = ReregistrationEmailHandler::send_reminders( 1, array( 10, 20 ) );

		$this->assertSame( 1, $count );
		$this->assertSame( array( 'u10@example.com' ), $this->recipients() );
		// Sending stamps `reminder_sent_at` on the row just emailed (#1232).
		$updates = $this->submission_updates();
		$this->assertSame( array( 101 ), array_column( $updates, 0 ) );
		$this->assertArrayHasKey( 'reminder_sent_at', $updates[0][1] );
	}

	// ==================================================================
	// send_confirmation() — full success path with magic link + auth code
	// ==================================================================

	public function test_send_confirmation_leads_to_the_dashboard_not_a_public_link(): void {
		// The Record downloads only signed in (#2242): the confirmation's
		// button and `{{magic_link_url}}` both lead to the dashboard.
		$this->submission(
			1,
			5,
			10,
			'approved',
			array(
				'magic_token' => 'tok123',
				'auth_code'   => 'AC99',
			)
		);
		$this->campaign(
			array(
				'id'                         => 5,
				'email_confirmation_enabled' => 1,
			)
		);
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'display_name' => 'Alice',
				'user_email'   => 'alice@example.com',
			)
		);

		$result = ReregistrationEmailHandler::send_confirmation( 1 );

		$this->assertTrue( $result );
		$this->assertSame( array( 'alice@example.com' ), $this->recipients() );
		$body = $this->mails[0][2];
		$this->assertStringNotContainsString( 'tok123', $body, 'No public link to the record is mailed.' );
		$this->assertStringContainsString( 'href="https://example.com/dashboard"', $body );
		$this->assertStringContainsString( 'R-AC99', $body );
	}
}
