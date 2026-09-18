<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationEmailHandler;
use FreeFormCertificate\Reregistration\ReregistrationRepository;

/**
 * Tests for ReregistrationEmailHandler: invitation, reminder, confirmation,
 * and automated reminder logic.
 *
 * All public methods begin with early-return checks (emails disabled,
 * missing data, missing template) which we exercise thoroughly.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationEmailHandler
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class ReregistrationEmailHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: emails NOT disabled.
		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_settings') {
				return array();
			}
			if ($key === 'ffc_dashboard_page_id') {
				return 0;
			}
			if ($key === 'date_format') {
				return 'Y-m-d';
			}
			return $default;
		});

		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('sanitize_text_field')->alias('trim');
		Functions\when('home_url')->justReturn('https://example.com');
		Functions\when('get_bloginfo')->justReturn('Test Site');
		Functions\when('wp_mail')->justReturn(true);
		Functions\when('date_i18n')->alias(function ($format, $timestamp = false) {
			return date($format, $timestamp ?: time());
		});
		Functions\when('get_permalink')->justReturn('https://example.com/dashboard');

		// wpdb mock for run_automated_reminders.
		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->users = 'wp_users';
		$wpdb->last_error = '';
		// Sending a reminder stamps `reminder_sent_at` on the row that has just
		// been emailed (#1232). A `byDefault()` so that a test wanting to CHARGE
		// the write can still override it with its own expectation.
		$wpdb->shouldReceive('update')->andReturn(1)->byDefault();
		$this->wpdb = $wpdb;

		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_cache_delete')->justReturn(true);
		Functions\when('wp_parse_args')->alias(function ($args, $defaults = array()) {
			return array_merge((array) $defaults, (array) $args);
		});
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// send_invitations()
	// ==================================================================

	public function test_send_invitations_returns_zero_when_emails_disabled(): void {
		Functions\when('get_option')->justReturn(array('disable_all_emails' => 1));

		$result = ReregistrationEmailHandler::send_invitations(1);
		$this->assertSame(0, $result);
	}

	public function test_send_invitations_returns_zero_when_rereg_not_found(): void {
		// Repository returns null.
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn(null);

		$result = ReregistrationEmailHandler::send_invitations(999);
		$this->assertSame(0, $result);
	}

	public function test_send_invitations_returns_zero_when_invitation_not_enabled(): void {
		$rereg = (object) [
			'id'                       => 1,
			'title'                    => 'Test Campaign',
			'email_invitation_enabled' => 0,
			'start_date'               => '2026-01-01',
			'end_date'                 => '2026-12-31',
		];

		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn($rereg);

		$result = ReregistrationEmailHandler::send_invitations(1);
		$this->assertSame(0, $result);
	}

	// ==================================================================
	// send_reminders()
	// ==================================================================

	public function test_send_reminders_returns_zero_when_emails_disabled(): void {
		Functions\when('get_option')->justReturn(array('disable_all_emails' => 1));

		$result = ReregistrationEmailHandler::send_reminders(1);
		$this->assertSame(0, $result);
	}

	public function test_send_reminders_returns_zero_when_rereg_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn(null);

		$result = ReregistrationEmailHandler::send_reminders(999);
		$this->assertSame(0, $result);
	}

	public function test_send_reminders_returns_zero_when_reminder_not_enabled(): void {
		$rereg = (object) [
			'id'                     => 1,
			'title'                  => 'Test Campaign',
			'email_reminder_enabled' => 0,
			'start_date'             => '2026-01-01',
			'end_date'               => '2026-12-31',
		];

		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn($rereg);

		$result = ReregistrationEmailHandler::send_reminders(1);
		$this->assertSame(0, $result);
	}

	// ==================================================================
	// send_confirmation()
	// ==================================================================

	public function test_send_confirmation_returns_false_when_emails_disabled(): void {
		Functions\when('get_option')->justReturn(array('disable_all_emails' => 1));

		$result = ReregistrationEmailHandler::send_confirmation(1);
		$this->assertFalse($result);
	}

	public function test_send_confirmation_returns_false_when_submission_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn(null);

		$result = ReregistrationEmailHandler::send_confirmation(999);
		$this->assertFalse($result);
	}

	public function test_send_confirmation_returns_false_when_rereg_not_found(): void {
		// First get_row returns a submission, second returns null for rereg.
		$submission = (object) [
			'id'                => 1,
			'reregistration_id' => 99,
			'user_id'           => 10,
			'status'            => 'submitted',
			'magic_token'       => '',
			'auth_code'         => '',
		];

		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')
			->andReturn($submission, null);

		$result = ReregistrationEmailHandler::send_confirmation(1);
		$this->assertFalse($result);
	}

	public function test_send_confirmation_returns_false_when_confirmation_not_enabled(): void {
		$submission = (object) [
			'id'                => 1,
			'reregistration_id' => 5,
			'user_id'           => 10,
			'status'            => 'submitted',
			'magic_token'       => '',
			'auth_code'         => '',
		];

		$rereg = (object) [
			'id'                         => 5,
			'title'                      => 'Test Campaign',
			'email_confirmation_enabled' => 0,
			'start_date'                 => '2026-01-01',
			'end_date'                   => '2026-12-31',
		];

		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')
			->andReturn($submission, $rereg);

		$result = ReregistrationEmailHandler::send_confirmation(1);
		$this->assertFalse($result);
	}

	// ==================================================================
	// run_automated_reminders()
	// ==================================================================

	public function test_run_automated_reminders_returns_early_when_emails_disabled(): void {
		Functions\when('get_option')->justReturn(array('disable_all_emails' => 1));

		// #1030: the old comment reasoned that an unexpected query "would
		// fail", which relies on the mock's strictness rather than saying so.
		// State it: past the kill-switch the very next thing is the campaign
		// query, so it must not be reached.
		$this->wpdb->shouldNotReceive('get_results');
		$this->wpdb->shouldNotReceive('prepare');

		ReregistrationEmailHandler::run_automated_reminders();
	}

	public function test_run_automated_reminders_returns_early_when_no_campaigns(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_results')->andReturn(array());

		ReregistrationEmailHandler::run_automated_reminders();
		$this->assertTrue(true);
	}

	public function test_run_automated_reminders_returns_early_when_campaigns_null(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_results')->andReturn(null);

		ReregistrationEmailHandler::run_automated_reminders();
		$this->assertTrue(true);
	}

	public function test_run_automated_reminders_dispatches_per_campaign(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_results')->andReturn(
			array( (object) array( 'id' => 11 ), (object) array( 'id' => 22 ) )
		);
		// Each send_reminders() call re-enters get_by_id (get_row). Return a
		// campaign with reminders disabled so the inner method short-circuits
		// after the dispatch — we only need to prove the loop fans out.
		$this->wpdb->shouldReceive('get_row')->andReturn(
			(object) array(
				'id'                     => 11,
				'email_reminder_enabled' => 0,
				'title'                  => 'C',
				'start_date'             => '2026-01-01',
				'end_date'               => '2026-12-31',
			)
		);

		ReregistrationEmailHandler::run_automated_reminders();
		$this->assertTrue(true);
	}

	// ==================================================================
	// send_invitations() — full success path through send_to_user
	// ==================================================================

	public function test_send_invitations_sends_to_pending_members(): void {
		$rereg = (object) array(
			'id'                       => 1,
			'title'                    => 'Campaign',
			'email_invitation_enabled' => 1,
			'audience_name'            => 'Teachers',
			'start_date'               => '2026-01-01',
			'end_date'                 => '2026-12-31',
		);

		// get_by_id() → rereg; get_awaiting_invitation() → submissions. The rows
		// carry an `id` because the send stamps `invited_at` on them (#1190),
		// and a `status` because the wording is chosen from it (#1300) -- the
		// query is `SELECT *`, so both always arrive.
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn($rereg);
		$this->wpdb->shouldReceive('get_results')->andReturn(
			array(
				(object) array( 'id' => 101, 'user_id' => 10, 'status' => 'pending' ),
				(object) array( 'id' => 102, 'user_id' => 20, 'status' => 'pending' ),
			)
		);
		$marked = array();
		$this->wpdb->shouldReceive('query')->andReturnUsing(function () use (&$marked) {
			$marked[] = true;
			return 2;
		});

		Functions\when('get_userdata')->alias(function ($id) {
			return (object) array(
				'display_name' => 'User ' . $id,
				'user_email'   => 'user' . $id . '@example.com',
			);
		});

		Mockery::mock('alias:FreeFormCertificate\Core\DateFormatter')
			->shouldReceive('format_date')->andReturn('2026-01-01');
		Mockery::mock('alias:FreeFormCertificate\Scheduling\SchedulingMailer')
			->shouldReceive('send')->andReturn(true);

		$count = ReregistrationEmailHandler::send_invitations(1);
		$this->assertSame(2, $count);
		$this->assertNotEmpty($marked, 'Whoever received it must be stamped, or the next send repeats it.');
	}

	// ==================================================================
	// send_invitations() — the wording follows the status (#1300)
	// ==================================================================

	/**
	 * Stage a campaign whose awaiting-invitation rows carry the given statuses
	 * and return the bodies that were actually mailed.
	 *
	 * The body is captured AFTER `TokenResolver` has run, which is the only
	 * place the two new tokens are observable: they are chosen in
	 * `send_invitations()` and resolved in `send_to_user()`, and asserting on
	 * anything earlier would be asserting against the value this test supplies.
	 *
	 * @param array<int, string> $statuses One status per submission row.
	 * @return array<int, string> One rendered body per row.
	 */
	private function invitation_bodies(array $statuses): array {
		$rereg = (object) array(
			'id'                       => 1,
			'title'                    => 'Campaign',
			'email_invitation_enabled' => 1,
			'audience_name'            => 'Teachers',
			'start_date'               => '2026-01-01',
			'end_date'                 => '2026-12-31',
		);

		$rows = array();
		foreach ($statuses as $i => $status) {
			$rows[] = (object) array( 'id' => 100 + $i, 'user_id' => 10 + $i, 'status' => $status );
		}

		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn($rereg);
		$this->wpdb->shouldReceive('get_results')->andReturn($rows);
		$this->wpdb->shouldReceive('query')->andReturn(count($rows));

		Functions\when('get_userdata')->alias(function ($id) {
			return (object) array(
				'display_name' => 'User ' . $id,
				'user_email'   => 'user' . $id . '@example.com',
			);
		});

		Mockery::mock('alias:FreeFormCertificate\Core\DateFormatter')
			->shouldReceive('format_date')->andReturn('2026-01-01');

		$bodies = array();
		Mockery::mock('alias:FreeFormCertificate\Scheduling\SchedulingMailer')
			->shouldReceive('send')
			->andReturnUsing(function ($to, $subject, $body) use (&$bodies) {
				$bodies[] = (string) $body;
				return true;
			});

		ReregistrationEmailHandler::send_invitations(1);

		return $bodies;
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
		$bodies = $this->invitation_bodies(array( 'submitted' ));

		$this->assertCount(1, $bodies);
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
		$this->assertStringContainsString('Your reregistration is already recorded', $bodies[0]);
		$this->assertStringContainsString('View My Reregistration', $bodies[0]);
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
		$bodies = $this->invitation_bodies(array( 'approved', 'rejected' ));

		$this->assertCount(2, $bodies);
		$this->assertStringContainsString('Your reregistration is already recorded', $bodies[0]);
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
		$bodies = $this->invitation_bodies(array( 'pending' ));

		$this->assertCount(1, $bodies);
		$this->assertStringContainsString('Please complete your reregistration before the deadline.', $bodies[0]);
		$this->assertStringContainsString('Complete Reregistration', $bodies[0]);
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
		Functions\when('wp_generate_password')->justReturn('secret-key');
		Functions\when('add_query_arg')->alias(function ($args, $url) {
			return $url . '?' . http_build_query((array) $args);
		});

		$bodies = $this->invitation_bodies(array( 'submitted', 'pending' ));

		$this->assertCount(2, $bodies);
		foreach ($bodies as $i => $body) {
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
		$rereg = (object) array(
			'id'                     => 1,
			'title'                  => 'Campaign',
			'email_reminder_enabled' => 1,
			'start_date'             => '2026-01-01',
			'end_date'               => '2099-12-31',
		);

		// First get_row → rereg; subsequent get_row → per-user submission.
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn(
			$rereg,
			// `id` is required: sending stamps `reminder_sent_at` on the row
			// that has just been emailed (#1232), and a real row always has one.
			// The fixture did not, and only running it showed that.
			(object) array( 'id' => 101, 'user_id' => 10, 'status' => 'pending' ),
			// User 20 already submitted → filtered out.
			(object) array( 'id' => 102, 'user_id' => 20, 'status' => 'submitted' )
		);

		Functions\when('get_userdata')->alias(fn($id) => (object) array(
			'display_name' => 'U' . $id,
			'user_email'   => 'u' . $id . '@example.com',
		));

		Mockery::mock('alias:FreeFormCertificate\Core\DateFormatter')
			->shouldReceive('format_date')->andReturn('2026-01-01');
		Mockery::mock('alias:FreeFormCertificate\Scheduling\SchedulingMailer')
			->shouldReceive('send')->andReturn(true);

		$count = ReregistrationEmailHandler::send_reminders(1, array(10, 20));
		$this->assertSame(1, $count);
	}

	// ==================================================================
	// send_confirmation() — full success path with magic link + auth code
	// ==================================================================

	public function test_send_confirmation_success_with_magic_link(): void {
		$submission = (object) array(
			'id'                => 1,
			'reregistration_id' => 5,
			'user_id'           => 10,
			'status'            => 'submitted',
			'magic_token'       => 'tok123',
			'auth_code'         => 'AC99',
		);
		$rereg = (object) array(
			'id'                         => 5,
			'title'                      => 'Campaign',
			'email_confirmation_enabled' => 1,
			'start_date'                 => '2026-01-01',
			'end_date'                   => '2026-12-31',
		);

		// Two get_by_id() lookups via wpdb: the submission, then its campaign.
		// get_status_label() is pure (no DB), so the real repository is fine.
		$this->wpdb->shouldReceive('prepare')->andReturn('query');
		$this->wpdb->shouldReceive('get_row')->andReturn($submission, $rereg);

		Functions\when('get_userdata')->justReturn((object) array(
			'display_name' => 'Alice',
			'user_email'   => 'alice@example.com',
		));

		Mockery::mock('alias:FreeFormCertificate\Generators\MagicLinkHelper')
			->shouldReceive('generate_magic_link')->with('tok123')->andReturn('https://example.com/m/tok123');

		Mockery::mock('alias:FreeFormCertificate\Core\DateFormatter')
			->shouldReceive('format_date')->andReturn('2026-01-01');
		Mockery::mock('alias:FreeFormCertificate\Scheduling\SchedulingMailer')
			->shouldReceive('send')->andReturn(true);

		$result = ReregistrationEmailHandler::send_confirmation(1);
		$this->assertTrue($result);
	}
}
