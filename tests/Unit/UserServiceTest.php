<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Services\UserService;

/**
 * Tests for UserService: full profile retrieval, capabilities,
 * user statistics, personal data export, and FFC data presence check.
 *
 * Alias mocks for UserManager and Utils are created in setUp to prevent
 * autoloading of the real classes (same pattern as DataSanitizerTest).
 *
 * @covers \FreeFormCertificate\Services\UserService
 */
class UserServiceTest extends TestCase {

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

        // Alias mocks: created before autoloading to intercept class_exists
        $userManagerMock = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
        $userManagerMock->shouldReceive('get_profile')->andReturn([])->byDefault();
        $userManagerMock->shouldReceive('get_all_capabilities')->andReturn([])->byDefault();

        $utilsMock = Mockery::mock('alias:\FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('get_submissions_table')
            ->andReturn('wp_ffc_submissions')->byDefault();
        $utilsMock->shouldReceive('debug_log')->byDefault();

        // Default WP stubs
        Functions\when('__')->returnArg();
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('user_can')->justReturn(false);

        // Default wpdb stubs
        $this->wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
        $this->wpdb->shouldReceive('get_var')->andReturn(null)->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeWpUser(array $overrides = []): \WP_User {
        $user = new \WP_User();
        $user->display_name = $overrides['display_name'] ?? 'Test User';
        $user->user_email = $overrides['user_email'] ?? 'test@example.com';
        $user->user_registered = $overrides['user_registered'] ?? '2024-01-01 00:00:00';
        $user->roles = $overrides['roles'] ?? ['ffc_user'];
        return $user;
    }

    // ==================================================================
    // get_full_profile — user not found
    // ==================================================================

    public function test_get_full_profile_returns_null_when_user_not_found(): void {
        $result = UserService::get_full_profile(999);

        $this->assertNull($result);
    }

    // ==================================================================
    // get_full_profile — basic profile (UserManager returns empty)
    // ==================================================================

    public function test_get_full_profile_returns_core_data(): void {
        $user = $this->makeWpUser([
            'display_name' => 'John Doe',
            'user_email' => 'john@example.com',
            'user_registered' => '2024-01-15 10:30:00',
            'roles' => ['ffc_user'],
        ]);
        Functions\when('get_userdata')->justReturn($user);

        $result = UserService::get_full_profile(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['user_id']);
        $this->assertSame('John Doe', $result['display_name']);
        $this->assertSame('john@example.com', $result['email']);
        $this->assertSame('2024-01-15 10:30:00', $result['member_since']);
        $this->assertSame(['ffc_user'], $result['roles']);
        $this->assertArrayHasKey('capabilities', $result);
    }

    // ==================================================================
    // get_full_profile — with FFC profile data from UserManager
    // ==================================================================

    public function test_get_full_profile_merges_ffc_profile_data(): void {
        $user = $this->makeWpUser(['display_name' => 'Maria Silva']);
        Functions\when('get_userdata')->justReturn($user);

        $userManagerMock = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
        $userManagerMock->shouldReceive('get_profile')
            ->with(42)
            ->andReturn([
                'phone' => '+55 11 99999-0000',
                'department' => 'Engineering',
                'organization' => 'Acme Corp',
                'notes' => 'VIP user',
            ]);
        $userManagerMock->shouldReceive('get_all_capabilities')->andReturn([]);

        $result = UserService::get_full_profile(42);

        $this->assertSame('+55 11 99999-0000', $result['phone']);
        $this->assertSame('Engineering', $result['department']);
        $this->assertSame('Acme Corp', $result['organization']);
        $this->assertSame('VIP user', $result['notes']);
    }

    public function test_get_full_profile_defaults_missing_ffc_fields_to_empty_string(): void {
        $user = $this->makeWpUser();
        Functions\when('get_userdata')->justReturn($user);

        $userManagerMock = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
        $userManagerMock->shouldReceive('get_profile')
            ->with(10)
            ->andReturn([]); // no phone, department, etc.
        $userManagerMock->shouldReceive('get_all_capabilities')->andReturn([]);

        $result = UserService::get_full_profile(10);

        $this->assertSame('', $result['phone']);
        $this->assertSame('', $result['department']);
        $this->assertSame('', $result['organization']);
        $this->assertSame('', $result['notes']);
    }

    // ==================================================================
    // get_user_capabilities
    // ==================================================================

    public function test_get_user_capabilities_returns_cap_status_map(): void {
        $userManagerMock = Mockery::mock('alias:\FreeFormCertificate\UserDashboard\UserManager');
        $userManagerMock->shouldReceive('get_all_capabilities')
            ->andReturn(['ffc_view_certificates', 'ffc_download_pdf', 'ffc_manage_forms']);
        $userManagerMock->shouldReceive('get_profile')->andReturn([]);

        Functions\when('user_can')->alias(function ($uid, $cap) {
            return in_array($cap, ['ffc_view_certificates', 'ffc_download_pdf'], true);
        });

        $result = UserService::get_user_capabilities(42);

        $this->assertCount(3, $result);
        $this->assertTrue($result['ffc_view_certificates']);
        $this->assertTrue($result['ffc_download_pdf']);
        $this->assertFalse($result['ffc_manage_forms']);
    }

    public function test_get_user_capabilities_returns_empty_when_no_caps_defined(): void {
        // Default: get_all_capabilities returns [] (from setUp)
        $result = UserService::get_user_capabilities(42);

        $this->assertSame([], $result);
    }

    // ==================================================================
    // get_user_statistics
    // ==================================================================

    public function test_get_user_statistics_returns_certificate_count(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                '5',  // certificates count
                null, // appointments table doesn't exist
                null  // audience_members table doesn't exist
            );

        $result = UserService::get_user_statistics(42);

        $this->assertSame(5, $result['certificates']);
    }

    public function test_get_user_statistics_counts_appointments_when_table_exists(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                '0',                                    // certificates count
                'wp_ffc_self_scheduling_appointments',  // appointments table exists
                '3',                                    // appointments count
                null                                    // audience_members table doesn't exist
            );

        $result = UserService::get_user_statistics(42);

        $this->assertSame(3, $result['appointments']);
    }

    public function test_get_user_statistics_counts_audience_groups_when_table_exists(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                '0',                           // certificates count
                null,                          // appointments table doesn't exist
                'wp_ffc_audience_members',     // audience_members table exists
                '5'                            // audience_groups count
            );

        $result = UserService::get_user_statistics(42);

        $this->assertSame(5, $result['audience_groups']);
    }

    public function test_get_user_statistics_returns_all_zeros_when_no_data(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                '0',  // certificates
                null, // no appointments table
                null  // no audience table
            );

        $result = UserService::get_user_statistics(42);

        $this->assertSame(0, $result['certificates']);
        $this->assertSame(0, $result['appointments']);
        $this->assertSame(0, $result['audience_groups']);
    }

    // ==================================================================
    // user_has_ffc_data
    // ==================================================================

    public function test_user_has_ffc_data_returns_false_when_all_stats_zero(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('0', null, null);

        $this->assertFalse(UserService::user_has_ffc_data(42));
    }

    public function test_user_has_ffc_data_returns_true_when_certificates_exist(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('3', null, null);

        $this->assertTrue(UserService::user_has_ffc_data(42));
    }

    public function test_user_has_ffc_data_returns_true_when_appointments_exist(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(
                '0',
                'wp_ffc_self_scheduling_appointments',
                '1',
                null
            );

        $this->assertTrue(UserService::user_has_ffc_data(42));
    }

    // ==================================================================
    // export_personal_data
    // ==================================================================

    public function test_export_personal_data_includes_profile_when_user_exists(): void {
        $user = $this->makeWpUser(['display_name' => 'Export User']);
        Functions\when('get_userdata')->justReturn($user);

        $this->wpdb->shouldReceive('get_var')
            ->andReturn('0', null, null);

        $result = UserService::export_personal_data(42);

        $this->assertArrayHasKey('profile', $result);
        $this->assertSame(42, $result['profile']['user_id']);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_export_personal_data_omits_profile_when_user_not_found(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('0', null, null);

        $result = UserService::export_personal_data(999);

        $this->assertArrayNotHasKey('profile', $result);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function test_export_personal_data_always_includes_statistics(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('0', null, null);

        $result = UserService::export_personal_data(1);

        $this->assertArrayHasKey('statistics', $result);
        $this->assertIsArray($result['statistics']);
    }
}
