<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Privacy\PrivacyHandler;

/**
 * Tests for PrivacyHandler: exporter/eraser registration,
 * personal data export from multiple tables, and data erasure.
 *
 * @covers \FreeFormCertificate\Privacy\PrivacyHandler
 */
class PrivacyHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        Functions\when('__')->returnArg();
        Functions\when('sanitize_title')->alias(function ($title) {
            return strtolower(str_replace(' ', '-', $title));
        });

        // The prepare mock returns the raw SQL by default.
        // For SHOW TABLES queries, it embeds the table name so table_exists()
        // can match it via the get_var mock.
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            $args = func_get_args();
            $sql = $args[0];
            if (strpos($sql, 'SHOW TABLES LIKE') !== false && isset($args[1])) {
                return 'SHOW TABLES LIKE ' . $args[1];
            }
            return $sql;
        })->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Create a WP_User stub.
     */
    private function make_user(int $id = 42, string $email = 'user@example.com', string $display_name = 'Test User'): \WP_User {
        $user = new \WP_User($id);
        $user->user_email = $email;
        $user->display_name = $display_name;
        $user->user_registered = '2024-01-01 00:00:00';
        return $user;
    }

    /**
     * Set up mocks needed when export_profile finds a valid user.
     *
     * Since UserManager class exists in the autoloader, class_exists() returns
     * true and UserManager::get_profile() is called. We make its table_exists
     * check return false so it falls through to get_userdata().
     */
    private function mock_profile_dependencies(\WP_User $user): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null)
            ->byDefault();

        Functions\when('get_userdata')->justReturn($user);
    }

    /**
     * Configure get_var mock so that table_exists() returns true for all tables.
     *
     * The prepare mock embeds the table name in the SQL for SHOW TABLES queries.
     * This mock extracts and returns that name so it matches the === check.
     */
    private function mock_all_tables_exist(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturnUsing(function ($sql) {
                if (is_string($sql) && strpos($sql, 'SHOW TABLES LIKE ') === 0) {
                    return str_replace('SHOW TABLES LIKE ', '', $sql);
                }
                return null;
            });
    }

    /**
     * Configure get_var mock so that table_exists() returns false for all tables.
     */
    private function mock_no_tables_exist(): void {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null);
    }

    /**
     * Set up common mocks needed by erase_personal_data tests.
     * ActivityLog::log() calls get_option; we return empty to short-circuit.
     */
    private function mock_erase_dependencies(): void {
        Functions\when('get_option')->justReturn(array());
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_exporter_and_eraser_filters(): void {
        Functions\expect('add_filter')
            ->once()
            ->with('wp_privacy_personal_data_exporters', [PrivacyHandler::class, 'register_exporters']);

        Functions\expect('add_filter')
            ->once()
            ->with('wp_privacy_personal_data_erasers', [PrivacyHandler::class, 'register_erasers']);

        PrivacyHandler::init();
    }

    // ==================================================================
    // register_exporters()
    // ==================================================================

    public function test_register_exporters_adds_six_entries(): void {
        $result = PrivacyHandler::register_exporters(array());

        $this->assertCount(6, $result);
        $this->assertArrayHasKey('ffcertificate-profile', $result);
        $this->assertArrayHasKey('ffcertificate-certificates', $result);
        $this->assertArrayHasKey('ffcertificate-appointments', $result);
        $this->assertArrayHasKey('ffcertificate-audience-groups', $result);
        $this->assertArrayHasKey('ffcertificate-audience-bookings', $result);
        $this->assertArrayHasKey('ffcertificate-usermeta', $result);
    }

    public function test_register_exporters_preserves_existing_entries(): void {
        $existing = array(
            'other-plugin' => array(
                'exporter_friendly_name' => 'Other Plugin',
                'callback' => 'other_callback',
            ),
        );

        $result = PrivacyHandler::register_exporters($existing);

        $this->assertCount(7, $result);
        $this->assertArrayHasKey('other-plugin', $result);
    }

    public function test_register_exporters_each_entry_has_required_keys(): void {
        $result = PrivacyHandler::register_exporters(array());

        foreach ($result as $key => $exporter) {
            $this->assertArrayHasKey('exporter_friendly_name', $exporter, "Missing exporter_friendly_name for $key");
            $this->assertArrayHasKey('callback', $exporter, "Missing callback for $key");
            $this->assertIsCallable($exporter['callback'], "Callback not callable for $key");
        }
    }

    // ==================================================================
    // register_erasers()
    // ==================================================================

    public function test_register_erasers_adds_one_entry(): void {
        $result = PrivacyHandler::register_erasers(array());

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('ffcertificate', $result);
    }

    public function test_register_erasers_preserves_existing_entries(): void {
        $existing = array(
            'other-eraser' => array(
                'eraser_friendly_name' => 'Other Eraser',
                'callback' => 'other_erase_callback',
            ),
        );

        $result = PrivacyHandler::register_erasers($existing);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('other-eraser', $result);
        $this->assertArrayHasKey('ffcertificate', $result);
    }

    public function test_register_erasers_entry_has_required_keys(): void {
        $result = PrivacyHandler::register_erasers(array());

        $eraser = $result['ffcertificate'];
        $this->assertArrayHasKey('eraser_friendly_name', $eraser);
        $this->assertArrayHasKey('callback', $eraser);
        $this->assertIsCallable($eraser['callback']);
    }

    // ==================================================================
    // export_profile()
    // ==================================================================

    public function test_export_profile_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_profile('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_profile_returns_empty_on_page_greater_than_one(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $result = PrivacyHandler::export_profile('user@example.com', 2);

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_profile_returns_user_data(): void {
        $user = $this->make_user(42, 'user@example.com', 'Jane Doe');
        Functions\when('get_user_by')->justReturn($user);
        Functions\when('get_user_meta')->justReturn('');
        $this->mock_profile_dependencies($user);

        $result = PrivacyHandler::export_profile('user@example.com');

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertSame('ffc-profile', $item['group_id']);
        $this->assertSame('ffc-profile-42', $item['item_id']);

        $names = array_column($item['data'], 'name');
        $this->assertContains('Display Name', $names);
        $this->assertContains('Email', $names);
    }

    public function test_export_profile_includes_registration_date_when_present(): void {
        $user = $this->make_user(42, 'user@example.com', 'Jane Doe');
        Functions\when('get_user_by')->justReturn($user);
        Functions\when('get_user_meta')->justReturn('2024-01-15');
        $this->mock_profile_dependencies($user);

        $result = PrivacyHandler::export_profile('user@example.com');

        $item = $result['data'][0];
        $names = array_column($item['data'], 'name');
        $values = array_column($item['data'], 'value');

        $this->assertContains('Member Since', $names);
        $idx = array_search('Member Since', $names);
        $this->assertSame('2024-01-15', $values[$idx]);
    }

    // ==================================================================
    // export_certificates()
    // ==================================================================

    public function test_export_certificates_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_certificates('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_certificates_returns_empty_when_no_submissions(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(array());

        $result = PrivacyHandler::export_certificates('user@example.com');

        $this->assertSame(array(), $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_export_certificates_exports_submission_data(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $submissions = array(
            array(
                'id' => 101,
                'form_id' => 5,
                'submission_date' => '2024-06-01',
                'auth_code' => 'ABCD1234EFGH',
                'consent_given' => 1,
                'email_encrypted' => '',
                'form_title' => 'Contact Form',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($submissions);

        $result = PrivacyHandler::export_certificates('user@example.com');

        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['done']);

        $item = $result['data'][0];
        $this->assertSame('ffc-certificates', $item['group_id']);
        $this->assertSame('ffc-cert-101', $item['item_id']);

        // Verify 12-char auth code is formatted as XXXX-XXXX-XXXX
        $auth_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Auth Code') {
                $auth_data = $d;
                break;
            }
        }
        $this->assertNotNull($auth_data);
        $this->assertSame('ABCD-1234-EFGH', $auth_data['value']);
    }

    public function test_export_certificates_short_auth_code_not_formatted(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $submissions = array(
            array(
                'id' => 102,
                'form_id' => 1,
                'submission_date' => '2024-06-01',
                'auth_code' => 'SHORT',
                'consent_given' => 0,
                'email_encrypted' => '',
                'form_title' => 'Form',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($submissions);

        $result = PrivacyHandler::export_certificates('user@example.com');

        $item = $result['data'][0];
        $auth_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Auth Code') {
                $auth_data = $d;
                break;
            }
        }
        $this->assertNotNull($auth_data);
        $this->assertSame('SHORT', $auth_data['value']);
    }

    public function test_export_certificates_consent_given_shows_yes(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $submissions = array(
            array(
                'id' => 103,
                'form_id' => 1,
                'submission_date' => '2024-06-01',
                'auth_code' => '',
                'consent_given' => 1,
                'email_encrypted' => '',
                'form_title' => 'Form',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($submissions);

        $result = PrivacyHandler::export_certificates('user@example.com');

        $item = $result['data'][0];
        $consent_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Consent Given') {
                $consent_data = $d;
                break;
            }
        }
        $this->assertNotNull($consent_data);
        $this->assertSame('Yes', $consent_data['value']);
    }

    public function test_export_certificates_consent_not_given_shows_no(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $submissions = array(
            array(
                'id' => 104,
                'form_id' => 1,
                'submission_date' => '2024-06-01',
                'auth_code' => '',
                'consent_given' => 0,
                'email_encrypted' => '',
                'form_title' => 'Form',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($submissions);

        $result = PrivacyHandler::export_certificates('user@example.com');

        $item = $result['data'][0];
        $consent_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Consent Given') {
                $consent_data = $d;
                break;
            }
        }
        $this->assertNotNull($consent_data);
        $this->assertSame('No', $consent_data['value']);
    }

    public function test_export_certificates_pagination_done_false_when_full_page(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $submissions = array();
        for ($i = 0; $i < 50; $i++) {
            $submissions[] = array(
                'id' => $i + 1,
                'form_id' => 1,
                'submission_date' => '2024-01-01',
                'auth_code' => 'ABC',
                'consent_given' => 0,
                'email_encrypted' => '',
                'form_title' => 'Form',
            );
        }

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($submissions);

        $result = PrivacyHandler::export_certificates('user@example.com');

        $this->assertFalse($result['done']);
        $this->assertCount(50, $result['data']);
    }

    // ==================================================================
    // export_appointments()
    // ==================================================================

    public function test_export_appointments_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_appointments('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_appointments_returns_empty_when_table_missing(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_no_tables_exist();

        $result = PrivacyHandler::export_appointments('user@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_appointments_returns_empty_when_no_appointments(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(array());

        $result = PrivacyHandler::export_appointments('user@example.com');

        $this->assertSame(array(), $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_export_appointments_exports_appointment_data(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $appointments = array(
            array(
                'id' => 201,
                'appointment_date' => '2024-07-15',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'status' => 'confirmed',
                'name' => 'Jane Doe',
                'email_encrypted' => '',
                'phone_encrypted' => '',
                'user_notes' => 'Follow-up visit',
                'calendar_title' => 'Main Calendar',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($appointments);

        $result = PrivacyHandler::export_appointments('user@example.com');

        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['done']);

        $item = $result['data'][0];
        $this->assertSame('ffc-appointments', $item['group_id']);
        $this->assertSame('ffc-appt-201', $item['item_id']);

        $names = array_column($item['data'], 'name');
        $this->assertContains('Calendar', $names);
        $this->assertContains('Date', $names);
        $this->assertContains('Time', $names);
        $this->assertContains('Status', $names);
        $this->assertContains('Name', $names);
        $this->assertContains('Notes', $names);
    }

    public function test_export_appointments_pagination_done_false_when_full_page(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $appointments = array();
        for ($i = 0; $i < 50; $i++) {
            $appointments[] = array(
                'id' => $i + 1,
                'appointment_date' => '2024-01-01',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'status' => 'confirmed',
                'name' => 'User',
                'email_encrypted' => '',
                'phone_encrypted' => '',
                'user_notes' => '',
                'calendar_title' => 'Cal',
            );
        }

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($appointments);

        $result = PrivacyHandler::export_appointments('user@example.com');

        $this->assertFalse($result['done']);
        $this->assertCount(50, $result['data']);
    }

    // ==================================================================
    // export_audience_groups()
    // ==================================================================

    public function test_export_audience_groups_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_audience_groups('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_audience_groups_returns_empty_when_table_missing(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_no_tables_exist();

        $result = PrivacyHandler::export_audience_groups('user@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_audience_groups_returns_empty_on_page_greater_than_one(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $result = PrivacyHandler::export_audience_groups('user@example.com', 2);

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_audience_groups_exports_group_data(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $groups = array(
            array(
                'audience_name' => 'Engineering Team',
                'color' => '#ff0000',
                'joined_date' => '2024-03-10',
            ),
            array(
                'audience_name' => 'Marketing',
                'color' => '#00ff00',
                'joined_date' => '2024-04-20',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($groups);

        $result = PrivacyHandler::export_audience_groups('user@example.com');

        $this->assertCount(2, $result['data']);
        $this->assertTrue($result['done']);

        $item = $result['data'][0];
        $this->assertSame('ffc-audience-groups', $item['group_id']);
        $this->assertSame('ffc-group-engineering-team', $item['item_id']);

        $names = array_column($item['data'], 'name');
        $this->assertContains('Audience Name', $names);
        $this->assertContains('Joined Date', $names);
    }

    public function test_export_audience_groups_empty_results(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(array());

        $result = PrivacyHandler::export_audience_groups('user@example.com');

        $this->assertSame(array(), $result['data']);
        $this->assertTrue($result['done']);
    }

    // ==================================================================
    // export_audience_bookings()
    // ==================================================================

    public function test_export_audience_bookings_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_audience_bookings('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_audience_bookings_returns_empty_when_table_missing(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_no_tables_exist();

        $result = PrivacyHandler::export_audience_bookings('user@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_audience_bookings_returns_empty_when_no_bookings(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(array());

        $result = PrivacyHandler::export_audience_bookings('user@example.com');

        $this->assertSame(array(), $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_export_audience_bookings_exports_booking_data(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $bookings = array(
            array(
                'id' => 301,
                'booking_date' => '2024-08-01',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'description' => 'Team standup',
                'status' => 'confirmed',
                'is_all_day' => 0,
                'environment_name' => 'Room A',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        $result = PrivacyHandler::export_audience_bookings('user@example.com');

        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['done']);

        $item = $result['data'][0];
        $this->assertSame('ffc-audience-bookings', $item['group_id']);
        $this->assertSame('ffc-booking-301', $item['item_id']);

        $time_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Time') {
                $time_data = $d;
                break;
            }
        }
        $this->assertNotNull($time_data);
        $this->assertSame('09:00 - 10:00', $time_data['value']);
    }

    public function test_export_audience_bookings_shows_all_day_label(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $bookings = array(
            array(
                'id' => 302,
                'booking_date' => '2024-08-02',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'description' => 'Conference',
                'status' => 'confirmed',
                'is_all_day' => 1,
                'environment_name' => 'Hall B',
            ),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        $result = PrivacyHandler::export_audience_bookings('user@example.com');

        $item = $result['data'][0];
        $time_data = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'Time') {
                $time_data = $d;
                break;
            }
        }
        $this->assertNotNull($time_data);
        $this->assertSame('All Day', $time_data['value']);
    }

    public function test_export_audience_bookings_pagination_done_false_when_full_page(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_all_tables_exist();

        $bookings = array();
        for ($i = 0; $i < 50; $i++) {
            $bookings[] = array(
                'id' => $i + 1,
                'booking_date' => '2024-01-01',
                'start_time' => '09:00',
                'end_time' => '10:00',
                'description' => '',
                'status' => 'confirmed',
                'is_all_day' => 0,
                'environment_name' => 'Room',
            );
        }

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        $result = PrivacyHandler::export_audience_bookings('user@example.com');

        $this->assertFalse($result['done']);
    }

    // ==================================================================
    // export_usermeta()
    // ==================================================================

    public function test_export_usermeta_returns_empty_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::export_usermeta('nobody@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_usermeta_returns_empty_on_page_greater_than_one(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $result = PrivacyHandler::export_usermeta('user@example.com', 2);

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_usermeta_returns_empty_when_no_ffc_meta(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(array());

        $result = PrivacyHandler::export_usermeta('user@example.com');

        $this->assertSame(array('data' => array(), 'done' => true), $result);
    }

    public function test_export_usermeta_exports_meta_data(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $meta_rows = array(
            array('meta_key' => 'ffc_theme_preference', 'meta_value' => 'dark'),
            array('meta_key' => 'ffc_notification_enabled', 'meta_value' => '1'),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($meta_rows);

        $result = PrivacyHandler::export_usermeta('user@example.com');

        $this->assertCount(1, $result['data']);
        $this->assertTrue($result['done']);

        $item = $result['data'][0];
        $this->assertSame('ffc-usermeta', $item['group_id']);
        $this->assertSame('ffc-usermeta-42', $item['item_id']);
        $this->assertCount(2, $item['data']);
        $this->assertSame('ffc_theme_preference', $item['data'][0]['name']);
        $this->assertSame('dark', $item['data'][0]['value']);
    }

    public function test_export_usermeta_redacts_sensitive_keys(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);

        $meta_rows = array(
            array('meta_key' => 'ffc_cpf_rf_hash', 'meta_value' => 'abc123hash'),
            array('meta_key' => 'ffc_some_setting', 'meta_value' => 'visible'),
        );

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($meta_rows);

        $result = PrivacyHandler::export_usermeta('user@example.com');

        $item = $result['data'][0];

        $hash_entry = null;
        $setting_entry = null;
        foreach ($item['data'] as $d) {
            if ($d['name'] === 'ffc_cpf_rf_hash') {
                $hash_entry = $d;
            }
            if ($d['name'] === 'ffc_some_setting') {
                $setting_entry = $d;
            }
        }

        $this->assertNotNull($hash_entry);
        $this->assertSame('[hash]', $hash_entry['value']);

        $this->assertNotNull($setting_entry);
        $this->assertSame('visible', $setting_entry['value']);
    }

    // ==================================================================
    // erase_personal_data()
    // ==================================================================

    public function test_erase_returns_no_action_for_nonexistent_user(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::erase_personal_data('nobody@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame(array(), $result['messages']);
        $this->assertTrue($result['done']);
    }

    public function test_erase_anonymizes_submissions_no_other_tables(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_no_tables_exist();

        // submissions UPDATE returns 3, usermeta DELETE returns 0
        $query_call = 0;
        $this->wpdb->shouldReceive('query')
            ->andReturnUsing(function () use (&$query_call) {
                $query_call++;
                if ($query_call === 1) {
                    return 3; // submissions anonymize
                }
                return 0; // usermeta delete
            });

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertNotEmpty($result['messages']);
    }

    public function test_erase_with_all_tables_existing(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_all_tables_exist();

        // wpdb->query calls: submissions, appointments, activity log, usermeta
        $query_call = 0;
        $this->wpdb->shouldReceive('query')
            ->andReturnUsing(function () use (&$query_call) {
                $query_call++;
                switch ($query_call) {
                    case 1: return 2;  // submissions anonymize
                    case 2: return 1;  // appointments anonymize
                    case 3: return 0;  // activity log anonymize
                    case 4: return 5;  // usermeta delete
                    default: return 0;
                }
            });

        // wpdb->delete calls for audience members, booking users, permissions, profiles
        $this->wpdb->shouldReceive('delete')
            ->andReturn(1);

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertNotEmpty($result['messages']);
    }

    public function test_erase_with_zero_affected_rows(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_no_tables_exist();

        $this->wpdb->shouldReceive('query')
            ->andReturn(0);

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame(array(), $result['messages']);
        $this->assertTrue($result['done']);
    }

    public function test_erase_deletes_audience_members_and_reports_message(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_all_tables_exist();

        // submissions=0, appointments=0, activity_log=0, usermeta=0
        $this->wpdb->shouldReceive('query')
            ->andReturn(0);

        // wpdb->delete: audience members=3, booking users=0, permissions=0, profiles=0
        $delete_call = 0;
        $this->wpdb->shouldReceive('delete')
            ->andReturnUsing(function () use (&$delete_call) {
                $delete_call++;
                if ($delete_call === 1) {
                    return 3; // audience members
                }
                return 0;
            });

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);

        $found_membership_msg = false;
        foreach ($result['messages'] as $msg) {
            if (strpos($msg, '3') !== false && strpos($msg, 'audience') !== false) {
                $found_membership_msg = true;
                break;
            }
        }
        $this->assertTrue($found_membership_msg, 'Expected message about audience memberships removed');
    }

    public function test_erase_deletes_user_profile_and_reports_message(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_all_tables_exist();

        // submissions=0, appointments=0, activity_log=0, usermeta=0
        $this->wpdb->shouldReceive('query')
            ->andReturn(0);

        // wpdb->delete: members=0, booking_users=0, permissions=0, profiles=1
        $delete_call = 0;
        $this->wpdb->shouldReceive('delete')
            ->andReturnUsing(function () use (&$delete_call) {
                $delete_call++;
                if ($delete_call === 4) {
                    return 1; // user profile deleted
                }
                return 0;
            });

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);

        $found_profile_msg = false;
        foreach ($result['messages'] as $msg) {
            if (strpos($msg, 'profile') !== false || strpos($msg, 'User profile') !== false) {
                $found_profile_msg = true;
                break;
            }
        }
        $this->assertTrue($found_profile_msg, 'Expected message about user profile deleted');
    }

    public function test_erase_deletes_usermeta_and_reports_message(): void {
        $user = $this->make_user();
        Functions\when('get_user_by')->justReturn($user);
        $this->mock_erase_dependencies();
        $this->mock_no_tables_exist();

        // submissions=0, usermeta=7
        $query_call = 0;
        $this->wpdb->shouldReceive('query')
            ->andReturnUsing(function () use (&$query_call) {
                $query_call++;
                if ($query_call === 1) {
                    return 0; // submissions
                }
                return 7; // usermeta delete
            });

        $result = PrivacyHandler::erase_personal_data('user@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertTrue($result['done']);

        $found_settings_msg = false;
        foreach ($result['messages'] as $msg) {
            if (strpos($msg, '7') !== false && strpos($msg, 'settings') !== false) {
                $found_settings_msg = true;
                break;
            }
        }
        $this->assertTrue($found_settings_msg, 'Expected message about user settings removed');
    }

    // ==================================================================
    // Return structure validation
    // ==================================================================

    public function test_export_methods_return_correct_structure(): void {
        Functions\when('get_user_by')->justReturn(false);

        $export_methods = [
            'export_profile',
            'export_certificates',
            'export_appointments',
            'export_audience_groups',
            'export_audience_bookings',
            'export_usermeta',
        ];

        foreach ($export_methods as $method) {
            $result = PrivacyHandler::$method('nobody@example.com');

            $this->assertArrayHasKey('data', $result, "Missing 'data' key in $method return");
            $this->assertArrayHasKey('done', $result, "Missing 'done' key in $method return");
            $this->assertIsArray($result['data'], "'data' should be array in $method");
            $this->assertIsBool($result['done'], "'done' should be bool in $method");
        }
    }

    public function test_erase_returns_correct_structure(): void {
        Functions\when('get_user_by')->justReturn(false);

        $result = PrivacyHandler::erase_personal_data('nobody@example.com');

        $this->assertArrayHasKey('items_removed', $result);
        $this->assertArrayHasKey('items_retained', $result);
        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('done', $result);
        $this->assertIsArray($result['messages']);
    }
}
