<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceScheduleRepository;

/**
 * Tests for AudienceScheduleRepository: table names, CRUD operations,
 * permissions management, user access checks, and caching.
 *
 * @covers \FreeFormCertificate\Audience\AudienceScheduleRepository
 */
class AudienceScheduleRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('wp_parse_args')->alias(function($args, $defaults = array()) {
            return array_merge($defaults, $args);
        });
        Functions\when('sanitize_sql_orderby')->alias(function($orderby) {
            if (preg_match('/^[a-zA-Z_]+\s+(ASC|DESC)$/i', $orderby)) {
                return $orderby;
            }
            return false;
        });
        Functions\when('get_current_user_id')->justReturn(1);

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        })->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Table names
    // ==================================================================

    public function test_get_table_name_returns_schedules_table(): void {
        $this->assertSame('wp_ffc_audience_schedules', AudienceScheduleRepository::get_table_name());
    }

    public function test_get_permissions_table_name_returns_permissions_table(): void {
        $this->assertSame('wp_ffc_audience_schedule_permissions', AudienceScheduleRepository::get_permissions_table_name());
    }

    public function test_table_names_use_wpdb_prefix(): void {
        $this->wpdb->prefix = 'custom_';

        $this->assertSame('custom_ffc_audience_schedules', AudienceScheduleRepository::get_table_name());
        $this->assertSame('custom_ffc_audience_schedule_permissions', AudienceScheduleRepository::get_permissions_table_name());

        // Restore
        $this->wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // cache_group()
    // ==================================================================

    public function test_cache_group_returns_correct_value(): void {
        // Verify indirectly via cache interactions: get_by_id triggers cache_get with group
        $schedule = (object) ['id' => 1, 'name' => 'Test Schedule'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($schedule) {
            if ($key === 'id_1' && $group === 'ffc_audience_schedules') {
                return $schedule;
            }
            return false;
        });

        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceScheduleRepository::get_by_id(1);

        $this->assertSame('Test Schedule', $result->name);
    }

    // ==================================================================
    // get_all()
    // ==================================================================

    public function test_get_all_returns_all_schedules_with_no_filters(): void {
        $rows = [
            (object) ['id' => 1, 'name' => 'Schedule A', 'status' => 'active', 'visibility' => 'public'],
            (object) ['id' => 2, 'name' => 'Schedule B', 'status' => 'active', 'visibility' => 'private'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audience_schedules ORDER BY name ASC');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceScheduleRepository::get_all();

        $this->assertCount(2, $result);
        $this->assertSame('Schedule A', $result[0]->name);
    }

    public function test_get_all_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['status' => 'active']);

        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_get_all_filters_by_visibility(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['visibility' => 'public']);

        $this->assertStringContainsString('visibility = %s', $captured_sql);
    }

    public function test_get_all_combines_status_and_visibility_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['status' => 'active', 'visibility' => 'public']);

        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('visibility = %s', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_get_all_applies_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['limit' => 10, 'offset' => 5]);

        $this->assertStringContainsString('LIMIT 10 OFFSET 5', $captured_sql);
    }

    public function test_get_all_no_limit_clause_when_limit_is_zero(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['limit' => 0]);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_get_all_applies_custom_orderby(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all(['orderby' => 'name', 'order' => 'DESC']);

        $this->assertStringContainsString('name DESC', $captured_sql);
    }

    public function test_get_all_no_where_clause_when_no_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_all();

        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_schedule_on_cache_miss(): void {
        $schedule = (object) ['id' => 1, 'name' => 'Test Schedule', 'status' => 'active'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audience_schedules WHERE id = 1');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($schedule);

        $result = AudienceScheduleRepository::get_by_id(1);

        $this->assertSame('Test Schedule', $result->name);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached = (object) ['id' => 1, 'name' => 'Cached Schedule'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            return $key === 'id_1' && $group === 'ffc_audience_schedules' ? $cached : false;
        });

        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceScheduleRepository::get_by_id(1);

        $this->assertSame('Cached Schedule', $result->name);
    }

    public function test_get_by_id_caches_result_on_miss(): void {
        $schedule = (object) ['id' => 5, 'name' => 'Schedule Five'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($schedule);

        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use ($schedule) {
            if ($key === 'id_5' && $group === 'ffc_audience_schedules') {
                \PHPUnit\Framework\TestCase::assertSame($schedule, $value);
            }
            return true;
        });

        AudienceScheduleRepository::get_by_id(5);
    }

    public function test_get_by_id_does_not_cache_null_result(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $cache_set_called_for_null = false;
        Functions\when('wp_cache_set')->alias(function($key, $value) use (&$cache_set_called_for_null) {
            if ($value === null) {
                $cache_set_called_for_null = true;
            }
            return true;
        });

        AudienceScheduleRepository::get_by_id(999);

        $this->assertFalse($cache_set_called_for_null, 'wp_cache_set should not be called with null value');
    }

    // ==================================================================
    // get_by_user_access()
    // ==================================================================

    public function test_get_by_user_access_returns_accessible_schedules(): void {
        $rows = [
            (object) ['id' => 1, 'name' => 'Public Schedule', 'visibility' => 'public', 'status' => 'active'],
            (object) ['id' => 2, 'name' => 'Permitted Schedule', 'visibility' => 'private', 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceScheduleRepository::get_by_user_access(42);

        $this->assertCount(2, $result);
        $this->assertSame('Public Schedule', $result[0]->name);
    }

    public function test_get_by_user_access_returns_empty_when_no_access(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceScheduleRepository::get_by_user_access(99);

        $this->assertEmpty($result);
    }

    public function test_get_by_user_access_sql_includes_public_and_permission_check(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceScheduleRepository::get_by_user_access(42);

        $this->assertStringContainsString("visibility = 'public'", $captured_sql);
        $this->assertStringContainsString('p.user_id IS NOT NULL', $captured_sql);
        $this->assertStringContainsString("s.status = 'active'", $captured_sql);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_returns_insert_id_on_success(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 42;

        $result = AudienceScheduleRepository::create(['name' => 'New Schedule']);

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceScheduleRepository::create(['name' => 'Failing Schedule']);

        $this->assertFalse($result);
    }

    public function test_create_uses_default_values(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data, $format) {
            \PHPUnit\Framework\TestCase::assertSame('wp_ffc_audience_schedules', $table);
            \PHPUnit\Framework\TestCase::assertSame('private', $data['visibility']);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['notify_on_booking']);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['notify_on_cancellation']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['include_ics']);
            \PHPUnit\Framework\TestCase::assertSame('active', $data['status']);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['created_by']); // get_current_user_id returns 1
            \PHPUnit\Framework\TestCase::assertNull($data['description']);
            \PHPUnit\Framework\TestCase::assertNull($data['environment_label']);
            \PHPUnit\Framework\TestCase::assertNull($data['future_days_limit']);
            \PHPUnit\Framework\TestCase::assertNull($data['email_template_booking']);
            \PHPUnit\Framework\TestCase::assertNull($data['email_template_cancellation']);
            return 1;
        });
        $this->wpdb->insert_id = 1;

        AudienceScheduleRepository::create(['name' => 'Test']);
    }

    public function test_create_overrides_defaults_with_provided_data(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data, $format) {
            \PHPUnit\Framework\TestCase::assertSame('Public Schedule', $data['name']);
            \PHPUnit\Framework\TestCase::assertSame('public', $data['visibility']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['notify_on_booking']);
            \PHPUnit\Framework\TestCase::assertSame('My description', $data['description']);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['include_ics']);
            return 1;
        });
        $this->wpdb->insert_id = 10;

        $result = AudienceScheduleRepository::create([
            'name' => 'Public Schedule',
            'visibility' => 'public',
            'notify_on_booking' => 0,
            'description' => 'My description',
            'include_ics' => 1,
        ]);

        $this->assertSame(10, $result);
    }

    public function test_create_passes_correct_format_array(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data, $format) {
            \PHPUnit\Framework\TestCase::assertSame(
                array('%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d'),
                $format
            );
            return 1;
        });
        $this->wpdb->insert_id = 1;

        AudienceScheduleRepository::create(['name' => 'Test']);
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = AudienceScheduleRepository::update(1, ['name' => 'Updated Schedule']);

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = AudienceScheduleRepository::update(1, ['name' => 'Failing Update']);

        $this->assertFalse($result);
    }

    public function test_update_returns_false_when_data_is_empty(): void {
        $result = AudienceScheduleRepository::update(1, []);

        $this->assertFalse($result);
    }

    public function test_update_strips_protected_fields(): void {
        // id, created_by, created_at should be stripped, leaving empty data => false
        $result = AudienceScheduleRepository::update(1, [
            'id' => 999,
            'created_by' => 5,
            'created_at' => '2024-01-01',
        ]);

        $this->assertFalse($result);
    }

    public function test_update_handles_known_fields_only(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturnUsing(function($table, $data, $where, $format, $where_format) {
            \PHPUnit\Framework\TestCase::assertSame('wp_ffc_audience_schedules', $table);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('name', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('visibility', $data);
            // unknown_field should not be in update data
            \PHPUnit\Framework\TestCase::assertArrayNotHasKey('unknown_field', $data);
            \PHPUnit\Framework\TestCase::assertSame(array('id' => 1), $where);
            return 1;
        });

        AudienceScheduleRepository::update(1, [
            'name' => 'Updated',
            'visibility' => 'public',
            'unknown_field' => 'should be ignored',
        ]);
    }

    public function test_update_supports_all_valid_fields(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturnUsing(function($table, $data, $where, $format) {
            \PHPUnit\Framework\TestCase::assertArrayHasKey('name', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('description', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('environment_label', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('visibility', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('future_days_limit', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('notify_on_booking', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('notify_on_cancellation', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('email_template_booking', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('email_template_cancellation', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('include_ics', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('show_event_list', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('event_list_position', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('audience_badge_format', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('booking_label_singular', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('booking_label_plural', $data);
            \PHPUnit\Framework\TestCase::assertArrayHasKey('status', $data);
            return 1;
        });

        AudienceScheduleRepository::update(1, [
            'name' => 'N',
            'description' => 'D',
            'environment_label' => 'E',
            'visibility' => 'public',
            'future_days_limit' => 30,
            'notify_on_booking' => 1,
            'notify_on_cancellation' => 0,
            'email_template_booking' => 'tpl1',
            'email_template_cancellation' => 'tpl2',
            'include_ics' => 1,
            'show_event_list' => 1,
            'event_list_position' => 'top',
            'audience_badge_format' => 'badge',
            'booking_label_singular' => 'Booking',
            'booking_label_plural' => 'Bookings',
            'status' => 'inactive',
        ]);
    }

    public function test_update_invalidates_cache(): void {
        $cache_deleted = false;
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted) {
            if ($key === 'id_7' && $group === 'ffc_audience_schedules') {
                $cache_deleted = true;
            }
            return true;
        });

        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        AudienceScheduleRepository::update(7, ['name' => 'Updated']);

        $this->assertTrue($cache_deleted, 'Cache should be invalidated after update');
    }

    public function test_update_returns_true_when_zero_rows_affected(): void {
        // wpdb->update returns 0 (not false) when no rows changed
        $this->wpdb->shouldReceive('update')->once()->andReturn(0);

        $result = AudienceScheduleRepository::update(1, ['name' => 'Same Name']);

        $this->assertTrue($result);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('delete')->once()
            ->with('wp_ffc_audience_schedules', array('id' => 1), array('%d'))
            ->andReturn(1);

        $result = AudienceScheduleRepository::delete(1);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(false);

        $result = AudienceScheduleRepository::delete(999);

        $this->assertFalse($result);
    }

    public function test_delete_invalidates_cache(): void {
        $cache_deleted = false;
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted) {
            if ($key === 'id_3' && $group === 'ffc_audience_schedules') {
                $cache_deleted = true;
            }
            return true;
        });

        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        AudienceScheduleRepository::delete(3);

        $this->assertTrue($cache_deleted, 'Cache should be invalidated after delete');
    }

    // ==================================================================
    // get_user_permissions()
    // ==================================================================

    public function test_get_user_permissions_returns_permission_object(): void {
        $perms = (object) [
            'id' => 1,
            'schedule_id' => 10,
            'user_id' => 5,
            'can_book' => 1,
            'can_cancel_others' => 0,
            'can_override_conflicts' => 0,
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($perms);

        $result = AudienceScheduleRepository::get_user_permissions(10, 5);

        $this->assertNotNull($result);
        $this->assertSame(1, $result->can_book);
        $this->assertSame(0, $result->can_cancel_others);
    }

    public function test_get_user_permissions_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::get_user_permissions(10, 99);

        $this->assertNull($result);
    }

    // ==================================================================
    // get_all_permissions()
    // ==================================================================

    public function test_get_all_permissions_returns_permissions_for_schedule(): void {
        $rows = [
            (object) ['id' => 1, 'schedule_id' => 10, 'user_id' => 5, 'can_book' => 1],
            (object) ['id' => 2, 'schedule_id' => 10, 'user_id' => 6, 'can_book' => 1],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceScheduleRepository::get_all_permissions(10);

        $this->assertCount(2, $result);
    }

    public function test_get_all_permissions_returns_empty_when_none_exist(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceScheduleRepository::get_all_permissions(10);

        $this->assertEmpty($result);
    }

    // ==================================================================
    // set_user_permissions() - upsert pattern
    // ==================================================================

    public function test_set_user_permissions_inserts_when_no_existing_permission(): void {
        // get_user_permissions returns null (no existing)
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        // Should insert
        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data, $format) {
            \PHPUnit\Framework\TestCase::assertSame('wp_ffc_audience_schedule_permissions', $table);
            \PHPUnit\Framework\TestCase::assertSame(10, $data['schedule_id']);
            \PHPUnit\Framework\TestCase::assertSame(5, $data['user_id']);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['can_book']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['can_cancel_others']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['can_override_conflicts']);
            return 1;
        });

        $result = AudienceScheduleRepository::set_user_permissions(10, 5, []);

        $this->assertTrue($result);
    }

    public function test_set_user_permissions_updates_when_existing_permission(): void {
        $existing = (object) [
            'id' => 42,
            'schedule_id' => 10,
            'user_id' => 5,
            'can_book' => 1,
            'can_cancel_others' => 0,
            'can_override_conflicts' => 0,
        ];

        // get_user_permissions returns existing
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($existing);

        // Should update
        $this->wpdb->shouldReceive('update')->once()->andReturnUsing(function($table, $data, $where, $format, $where_format) {
            \PHPUnit\Framework\TestCase::assertSame('wp_ffc_audience_schedule_permissions', $table);
            \PHPUnit\Framework\TestCase::assertSame(1, $data['can_cancel_others']);
            \PHPUnit\Framework\TestCase::assertSame(array('id' => 42), $where);
            return 1;
        });

        $result = AudienceScheduleRepository::set_user_permissions(10, 5, ['can_cancel_others' => 1]);

        $this->assertTrue($result);
    }

    public function test_set_user_permissions_uses_defaults_for_missing_flags(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->wpdb->shouldReceive('insert')->once()->andReturnUsing(function($table, $data) {
            // Defaults: can_book=1, can_cancel_others=0, can_override_conflicts=0
            \PHPUnit\Framework\TestCase::assertSame(1, $data['can_book']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['can_cancel_others']);
            \PHPUnit\Framework\TestCase::assertSame(0, $data['can_override_conflicts']);
            return 1;
        });

        AudienceScheduleRepository::set_user_permissions(10, 5, []);
    }

    public function test_set_user_permissions_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceScheduleRepository::set_user_permissions(10, 5, []);

        $this->assertFalse($result);
    }

    public function test_set_user_permissions_returns_false_on_update_failure(): void {
        $existing = (object) ['id' => 42, 'schedule_id' => 10, 'user_id' => 5];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($existing);
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = AudienceScheduleRepository::set_user_permissions(10, 5, ['can_book' => 0]);

        $this->assertFalse($result);
    }

    // ==================================================================
    // remove_user_permissions()
    // ==================================================================

    public function test_remove_user_permissions_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('delete')->once()
            ->with(
                'wp_ffc_audience_schedule_permissions',
                array('schedule_id' => 10, 'user_id' => 5),
                array('%d', '%d')
            )
            ->andReturn(1);

        $result = AudienceScheduleRepository::remove_user_permissions(10, 5);

        $this->assertTrue($result);
    }

    public function test_remove_user_permissions_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(false);

        $result = AudienceScheduleRepository::remove_user_permissions(10, 5);

        $this->assertFalse($result);
    }

    // ==================================================================
    // user_can_book()
    // ==================================================================

    public function test_user_can_book_returns_true_for_admin(): void {
        Functions\when('user_can')->alias(function($user_id, $cap) {
            return $cap === 'manage_options';
        });

        $result = AudienceScheduleRepository::user_can_book(10, 1);

        $this->assertTrue($result);
    }

    public function test_user_can_book_returns_false_when_schedule_not_found(): void {
        Functions\when('user_can')->justReturn(false);

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::user_can_book(10, 5);

        $this->assertFalse($result);
    }

    public function test_user_can_book_returns_false_when_schedule_inactive(): void {
        Functions\when('user_can')->justReturn(false);

        $schedule = (object) ['id' => 10, 'status' => 'inactive'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($schedule);

        $result = AudienceScheduleRepository::user_can_book(10, 5);

        $this->assertFalse($result);
    }

    public function test_user_can_book_returns_true_when_user_has_permission(): void {
        Functions\when('user_can')->justReturn(false);

        $schedule = (object) ['id' => 10, 'status' => 'active'];
        $perms = (object) ['can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0];

        // First get_row call is for get_by_id, second for get_user_permissions
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->twice()->andReturn($schedule, $perms);

        $result = AudienceScheduleRepository::user_can_book(10, 5);

        $this->assertTrue($result);
    }

    public function test_user_can_book_returns_false_when_user_has_no_permission(): void {
        Functions\when('user_can')->justReturn(false);

        $schedule = (object) ['id' => 10, 'status' => 'active'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->twice()->andReturn($schedule, null);

        $result = AudienceScheduleRepository::user_can_book(10, 5);

        $this->assertFalse($result);
    }

    public function test_user_can_book_returns_false_when_can_book_is_zero(): void {
        Functions\when('user_can')->justReturn(false);

        $schedule = (object) ['id' => 10, 'status' => 'active'];
        $perms = (object) ['can_book' => 0, 'can_cancel_others' => 0, 'can_override_conflicts' => 0];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->twice()->andReturn($schedule, $perms);

        $result = AudienceScheduleRepository::user_can_book(10, 5);

        $this->assertFalse($result);
    }

    // ==================================================================
    // user_can_cancel_others()
    // ==================================================================

    public function test_user_can_cancel_others_returns_true_for_admin(): void {
        Functions\when('user_can')->alias(function($user_id, $cap) {
            return $cap === 'manage_options';
        });

        $result = AudienceScheduleRepository::user_can_cancel_others(10, 1);

        $this->assertTrue($result);
    }

    public function test_user_can_cancel_others_returns_true_when_permitted(): void {
        Functions\when('user_can')->justReturn(false);

        $perms = (object) ['can_book' => 1, 'can_cancel_others' => 1, 'can_override_conflicts' => 0];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($perms);

        $result = AudienceScheduleRepository::user_can_cancel_others(10, 5);

        $this->assertTrue($result);
    }

    public function test_user_can_cancel_others_returns_false_when_no_permission(): void {
        Functions\when('user_can')->justReturn(false);

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::user_can_cancel_others(10, 5);

        $this->assertFalse($result);
    }

    public function test_user_can_cancel_others_returns_false_when_flag_is_zero(): void {
        Functions\when('user_can')->justReturn(false);

        $perms = (object) ['can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($perms);

        $result = AudienceScheduleRepository::user_can_cancel_others(10, 5);

        $this->assertFalse($result);
    }

    // ==================================================================
    // user_can_override_conflicts()
    // ==================================================================

    public function test_user_can_override_conflicts_returns_true_for_admin(): void {
        Functions\when('user_can')->alias(function($user_id, $cap) {
            return $cap === 'manage_options';
        });

        $result = AudienceScheduleRepository::user_can_override_conflicts(10, 1);

        $this->assertTrue($result);
    }

    public function test_user_can_override_conflicts_returns_true_when_permitted(): void {
        Functions\when('user_can')->justReturn(false);

        $perms = (object) ['can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 1];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($perms);

        $result = AudienceScheduleRepository::user_can_override_conflicts(10, 5);

        $this->assertTrue($result);
    }

    public function test_user_can_override_conflicts_returns_false_when_no_permission(): void {
        Functions\when('user_can')->justReturn(false);

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::user_can_override_conflicts(10, 5);

        $this->assertFalse($result);
    }

    public function test_user_can_override_conflicts_returns_false_when_flag_is_zero(): void {
        Functions\when('user_can')->justReturn(false);

        $perms = (object) ['can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($perms);

        $result = AudienceScheduleRepository::user_can_override_conflicts(10, 5);

        $this->assertFalse($result);
    }

    // ==================================================================
    // get_environment_label()
    // ==================================================================

    public function test_get_environment_label_returns_custom_label_from_object(): void {
        $schedule = (object) ['id' => 1, 'environment_label' => 'Rooms'];

        $result = AudienceScheduleRepository::get_environment_label($schedule);

        $this->assertSame('Rooms', $result);
    }

    public function test_get_environment_label_returns_default_plural_when_no_custom(): void {
        $schedule = (object) ['id' => 1, 'environment_label' => null];

        $result = AudienceScheduleRepository::get_environment_label($schedule, false);

        $this->assertSame('Environments', $result);
    }

    public function test_get_environment_label_returns_default_singular_when_no_custom(): void {
        $schedule = (object) ['id' => 1, 'environment_label' => null];

        $result = AudienceScheduleRepository::get_environment_label($schedule, true);

        $this->assertSame('Environment', $result);
    }

    public function test_get_environment_label_looks_up_by_int_id(): void {
        $schedule = (object) ['id' => 5, 'environment_label' => 'Labs'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($schedule);

        $result = AudienceScheduleRepository::get_environment_label(5);

        $this->assertSame('Labs', $result);
    }

    public function test_get_environment_label_returns_default_for_null(): void {
        $result = AudienceScheduleRepository::get_environment_label(null, false);

        $this->assertSame('Environments', $result);
    }

    public function test_get_environment_label_returns_singular_default_for_null(): void {
        $result = AudienceScheduleRepository::get_environment_label(null, true);

        $this->assertSame('Environment', $result);
    }

    public function test_get_environment_label_returns_default_when_custom_label_empty_string(): void {
        $schedule = (object) ['id' => 1, 'environment_label' => ''];

        $result = AudienceScheduleRepository::get_environment_label($schedule, false);

        $this->assertSame('Environments', $result);
    }

    public function test_get_environment_label_returns_default_when_id_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceScheduleRepository::get_environment_label(999, false);

        $this->assertSame('Environments', $result);
    }

    // ==================================================================
    // count()
    // ==================================================================

    public function test_count_returns_total_count_with_no_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = AudienceScheduleRepository::count();

        $this->assertSame(5, $result);
        $this->assertStringContainsString('SELECT COUNT(*)', $captured_sql);
        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }

    public function test_count_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

        $result = AudienceScheduleRepository::count(['status' => 'active']);

        $this->assertSame(3, $result);
        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_count_filters_by_visibility(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('2');

        $result = AudienceScheduleRepository::count(['visibility' => 'public']);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('visibility = %s', $captured_sql);
    }

    public function test_count_combines_status_and_visibility_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $result = AudienceScheduleRepository::count(['status' => 'active', 'visibility' => 'public']);

        $this->assertSame(1, $result);
        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('visibility = %s', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_count_returns_zero_when_no_results(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $result = AudienceScheduleRepository::count();

        $this->assertSame(0, $result);
    }

    public function test_count_casts_result_to_int(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('42');

        $result = AudienceScheduleRepository::count();

        $this->assertIsInt($result);
        $this->assertSame(42, $result);
    }
}
