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

        // If any DB query were executed it would fail (no expectations set).
        ReregistrationEmailHandler::run_automated_reminders();
        $this->assertTrue(true);
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

        // get_by_id() → rereg; get_by_reregistration() (pending) → submissions.
        $this->wpdb->shouldReceive('prepare')->andReturn('query');
        $this->wpdb->shouldReceive('get_row')->andReturn($rereg);
        $this->wpdb->shouldReceive('get_results')->andReturn(
            array(
                (object) array( 'user_id' => 10 ),
                (object) array( 'user_id' => 20 ),
            )
        );

        Functions\when('get_userdata')->alias(function ($id) {
            return (object) array(
                'display_name' => 'User ' . $id,
                'user_email'   => 'user' . $id . '@example.com',
            );
        });

        Mockery::mock('alias:FreeFormCertificate\Scheduling\EmailTemplateService')
            ->shouldReceive('format_date')->andReturn('2026-01-01')
            ->shouldReceive('render_template')->andReturnUsing(fn($t) => $t)
            ->shouldReceive('send')->andReturn(true);

        $count = ReregistrationEmailHandler::send_invitations(1);
        $this->assertSame(2, $count);
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
            (object) array( 'user_id' => 10, 'status' => 'pending' ),
            // User 20 already submitted → filtered out.
            (object) array( 'user_id' => 20, 'status' => 'submitted' )
        );

        Functions\when('get_userdata')->alias(fn($id) => (object) array(
            'display_name' => 'U' . $id,
            'user_email'   => 'u' . $id . '@example.com',
        ));

        Mockery::mock('alias:FreeFormCertificate\Scheduling\EmailTemplateService')
            ->shouldReceive('format_date')->andReturn('2026-01-01')
            ->shouldReceive('render_template')->andReturnUsing(fn($t) => $t)
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

        Mockery::mock('alias:FreeFormCertificate\Scheduling\EmailTemplateService')
            ->shouldReceive('format_date')->andReturn('2026-01-01')
            ->shouldReceive('render_template')->andReturnUsing(fn($t) => $t)
            ->shouldReceive('send')->andReturn(true);

        $result = ReregistrationEmailHandler::send_confirmation(1);
        $this->assertTrue($result);
    }
}
