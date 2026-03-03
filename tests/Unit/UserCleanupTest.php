<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserCleanup;

/**
 * Tests for UserCleanup: hook registration, user data anonymization
 * on deletion, and email change handling with hash reindexing.
 *
 * Alias mocks for ActivityLog and Utils are created in setUp to prevent
 * autoloading of the real classes.
 *
 * @covers \FreeFormCertificate\UserDashboard\UserCleanup
 */
class UserCleanupTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        // Alias mocks: prevent autoloading
        $activityLogMock = Mockery::mock('alias:\FreeFormCertificate\Core\ActivityLog');
        $activityLogMock->shouldReceive('log')->byDefault();

        $utilsMock = Mockery::mock('alias:\FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('mask_email')->andReturnUsing(function ($email) {
            return substr($email, 0, 1) . '***@' . explode('@', $email)[1];
        })->byDefault();
        $utilsMock->shouldReceive('debug_log')->byDefault();

        Functions\when('__')->returnArg();
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('current_time')->justReturn('2026-03-02 12:00:00');

        // Default wpdb stubs
        $this->wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — hook registrations
    // ==================================================================

    public function test_init_registers_deleted_user_action(): void {
        Functions\expect('add_action')
            ->with('deleted_user', [UserCleanup::class, 'anonymize_user_data'])
            ->once();

        Functions\expect('add_action')
            ->with('profile_update', [UserCleanup::class, 'handle_email_change'], 10, 2)
            ->once();

        UserCleanup::init();
    }

    // ==================================================================
    // anonymize_user_data — submissions nullified, no optional tables
    // ==================================================================

    public function test_anonymize_nullifies_submissions_user_id(): void {
        $this->wpdb->shouldReceive('query')->once()->andReturn(3);
        $this->wpdb->shouldReceive('get_var')->andReturn(null); // no optional tables

        UserCleanup::anonymize_user_data(42);

        $this->addToAssertionCount(1);
    }

    public function test_anonymize_handles_no_affected_rows_gracefully(): void {
        $this->wpdb->shouldReceive('query')->andReturn(0);
        $this->wpdb->shouldReceive('get_var')->andReturn(null);

        UserCleanup::anonymize_user_data(42);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // anonymize_user_data — with optional tables
    // ==================================================================

    public function test_anonymize_nullifies_appointments_when_table_exists(): void {
        // query: submissions=0, appointments=2, activity_log=0
        $this->wpdb->shouldReceive('query')->andReturn(0, 2, 0);

        // table_exists: appointments yes, activity_log yes, rest no
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                'wp_ffc_self_scheduling_appointments',
                'wp_ffc_activity_log',
                null, null, null, null
            );

        UserCleanup::anonymize_user_data(42);

        $this->addToAssertionCount(1);
    }

    public function test_anonymize_deletes_from_deletion_tables_when_they_exist(): void {
        $this->wpdb->shouldReceive('query')->andReturn(0);

        // All tables exist
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                'wp_ffc_self_scheduling_appointments',
                'wp_ffc_activity_log',
                'wp_ffc_audience_members',
                'wp_ffc_audience_booking_users',
                'wp_ffc_audience_schedule_permissions',
                'wp_ffc_user_profiles'
            );

        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_members', ['user_id' => 42], ['%d'])
            ->once()->andReturn(1);
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_booking_users', ['user_id' => 42], ['%d'])
            ->once()->andReturn(0);
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_schedule_permissions', ['user_id' => 42], ['%d'])
            ->once()->andReturn(2);
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_user_profiles', ['user_id' => 42], ['%d'])
            ->once()->andReturn(1);

        UserCleanup::anonymize_user_data(42);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // anonymize_user_data — logging
    // ==================================================================

    public function test_anonymize_logs_affected_tables(): void {
        $this->wpdb->shouldReceive('query')->andReturn(1); // submissions affected
        $this->wpdb->shouldReceive('get_var')->andReturn(null); // no optional tables

        $activityLogMock = Mockery::mock('alias:\FreeFormCertificate\Core\ActivityLog');
        $activityLogMock->shouldReceive('log')
            ->once()
            ->with(
                'user_data_anonymized',
                Mockery::any(),
                Mockery::on(function ($context) {
                    return $context['anonymized_user_id'] === 42
                        && isset($context['tables_affected'])
                        && $context['tables_affected']['submissions'] === 1;
                })
            );

        UserCleanup::anonymize_user_data(42);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // handle_email_change — early returns
    // ==================================================================

    public function test_handle_email_change_returns_early_when_user_not_found(): void {
        $old_user = new \WP_User();
        $old_user->user_email = 'old@example.com';

        $this->wpdb->shouldNotReceive('query');

        UserCleanup::handle_email_change(42, $old_user);

        $this->addToAssertionCount(1);
    }

    public function test_handle_email_change_returns_early_when_email_unchanged(): void {
        $new_user = new \WP_User();
        $new_user->user_email = 'same@example.com';
        Functions\when('get_userdata')->justReturn($new_user);

        $old_user = new \WP_User();
        $old_user->user_email = 'same@example.com';

        $this->wpdb->shouldNotReceive('query');

        UserCleanup::handle_email_change(42, $old_user);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // handle_email_change — reindexes email_hash
    // ==================================================================

    public function test_handle_email_change_updates_email_hash_on_submissions(): void {
        $new_user = new \WP_User();
        $new_user->user_email = 'new@example.com';
        Functions\when('get_userdata')->justReturn($new_user);

        $old_user = new \WP_User();
        $old_user->user_email = 'old@example.com';

        $this->wpdb->shouldReceive('query')->once()->andReturn(3);
        $this->wpdb->shouldReceive('get_var')->andReturn(null); // no profiles table

        UserCleanup::handle_email_change(42, $old_user);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // handle_email_change — updates profile timestamp
    // ==================================================================

    public function test_handle_email_change_updates_profile_timestamp_when_table_exists(): void {
        $new_user = new \WP_User();
        $new_user->user_email = 'new@example.com';
        Functions\when('get_userdata')->justReturn($new_user);

        $old_user = new \WP_User();
        $old_user->user_email = 'old@example.com';

        $this->wpdb->shouldReceive('query')->andReturn(1);
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('wp_ffc_user_profiles');
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_user_profiles',
                ['updated_at' => '2026-03-02 12:00:00'],
                ['user_id' => 42],
                ['%s'],
                ['%d']
            )
            ->andReturn(1);

        UserCleanup::handle_email_change(42, $old_user);

        $this->addToAssertionCount(1);
    }

    // ==================================================================
    // handle_email_change — logging with masked emails
    // ==================================================================

    public function test_handle_email_change_logs_with_masked_emails(): void {
        $new_user = new \WP_User();
        $new_user->user_email = 'new@example.com';
        Functions\when('get_userdata')->justReturn($new_user);

        $old_user = new \WP_User();
        $old_user->user_email = 'old@example.com';

        $this->wpdb->shouldReceive('query')->andReturn(1);
        $this->wpdb->shouldReceive('get_var')->andReturn(null);

        $activityLogMock = Mockery::mock('alias:\FreeFormCertificate\Core\ActivityLog');
        $activityLogMock->shouldReceive('log')
            ->once()
            ->with(
                'user_email_changed',
                Mockery::any(),
                Mockery::on(function ($context) {
                    return $context['user_id'] === 42
                        && !empty($context['old_email_masked'])
                        && !empty($context['new_email_masked']);
                })
            );

        UserCleanup::handle_email_change(42, $old_user);

        $this->addToAssertionCount(1);
    }
}
