<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceEnvironmentRepository;

/**
 * Tests for AudienceEnvironmentRepository: table names, CRUD operations,
 * working hours, holidays, caching, and open/closed status.
 *
 * @covers \FreeFormCertificate\Audience\AudienceEnvironmentRepository
 */
class AudienceEnvironmentRepositoryTest extends TestCase {

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
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('sanitize_hex_color')->alias(function($color) {
            return $color;
        });
        Functions\when('absint')->alias(function($val) {
            return abs(intval($val));
        });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('sanitize_sql_orderby')->alias(function($orderby) {
            // Simplified: return the value if it looks safe, false otherwise
            if (preg_match('/^[a-zA-Z_]+\s+(ASC|DESC)$/i', $orderby)) {
                return $orderby;
            }
            return false;
        });
        Functions\when('wp_json_encode')->alias('json_encode');

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

    public function test_get_table_name_returns_environments_table(): void {
        $this->assertSame('wp_ffc_audience_environments', AudienceEnvironmentRepository::get_table_name());
    }

    public function test_get_holidays_table_name_returns_holidays_table(): void {
        $this->assertSame('wp_ffc_audience_holidays', AudienceEnvironmentRepository::get_holidays_table_name());
    }

    public function test_table_names_use_wpdb_prefix(): void {
        $this->wpdb->prefix = 'custom_';

        $this->assertSame('custom_ffc_audience_environments', AudienceEnvironmentRepository::get_table_name());
        $this->assertSame('custom_ffc_audience_holidays', AudienceEnvironmentRepository::get_holidays_table_name());

        // Restore
        $this->wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // cache_group()
    // ==================================================================

    public function test_cache_group_returns_expected_string(): void {
        // cache_group is protected, but we can verify it indirectly via get_by_id cache behavior.
        // When we set up a cache hit with the correct group, get_by_id should return it.
        $cached = (object) ['id' => 1, 'name' => 'Cached Env'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            return $key === 'id_1' && $group === 'ffc_audience_environments' ? $cached : false;
        });

        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceEnvironmentRepository::get_by_id(1);

        $this->assertSame('Cached Env', $result->name);
    }

    // ==================================================================
    // get_all()
    // ==================================================================

    public function test_get_all_returns_all_environments_with_no_filters(): void {
        $rows = [
            (object) ['id' => 1, 'name' => 'Room A', 'schedule_id' => 10, 'status' => 'active'],
            (object) ['id' => 2, 'name' => 'Room B', 'schedule_id' => 10, 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audience_environments ORDER BY name ASC');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceEnvironmentRepository::get_all();

        $this->assertCount(2, $result);
        $this->assertSame('Room A', $result[0]->name);
    }

    public function test_get_all_filters_by_schedule_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all(['schedule_id' => 5]);

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
    }

    public function test_get_all_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all(['status' => 'active']);

        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_get_all_combines_schedule_id_and_status_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all(['schedule_id' => 3, 'status' => 'active']);

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_get_all_applies_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all(['limit' => 10, 'offset' => 5]);

        $this->assertStringContainsString('LIMIT 10 OFFSET 5', $captured_sql);
    }

    public function test_get_all_no_limit_clause_when_limit_is_zero(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all(['limit' => 0]);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_get_all_no_where_clause_when_no_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_all();

        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_environment_on_cache_miss(): void {
        $env = (object) ['id' => 1, 'name' => 'Test Room', 'status' => 'active'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audience_environments WHERE id = 1');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($env);

        $result = AudienceEnvironmentRepository::get_by_id(1);

        $this->assertSame('Test Room', $result->name);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceEnvironmentRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached = (object) ['id' => 1, 'name' => 'Cached Environment'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            return $key === 'id_1' && $group === 'ffc_audience_environments' ? $cached : false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceEnvironmentRepository::get_by_id(1);

        $this->assertSame('Cached Environment', $result->name);
    }

    public function test_get_by_id_caches_result_on_miss(): void {
        $env = (object) ['id' => 5, 'name' => 'Room Five'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($env);

        // Verify cache_set is called with the right key
        $cache_set_called_with_key = null;
        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use (&$cache_set_called_with_key) {
            if ($group === 'ffc_audience_environments') {
                $cache_set_called_with_key = $key;
            }
            return true;
        });

        AudienceEnvironmentRepository::get_by_id(5);

        $this->assertSame('id_5', $cache_set_called_with_key);
    }

    public function test_get_by_id_does_not_cache_null_result(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $cache_set_called = false;
        Functions\when('wp_cache_set')->alias(function() use (&$cache_set_called) {
            $cache_set_called = true;
            return true;
        });

        AudienceEnvironmentRepository::get_by_id(999);

        $this->assertFalse($cache_set_called);
    }

    // ==================================================================
    // get_by_schedule()
    // ==================================================================

    public function test_get_by_schedule_delegates_to_get_all_with_schedule_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_by_schedule(42);

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
    }

    public function test_get_by_schedule_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_by_schedule(42, 'active');

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_get_by_schedule_returns_array(): void {
        $rows = [
            (object) ['id' => 1, 'name' => 'Room A', 'schedule_id' => 10],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceEnvironmentRepository::get_by_schedule(10);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_environment_and_returns_id(): void {
        $this->wpdb->insert_id = 42;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    return $data['name'] === 'New Room'
                        && $data['schedule_id'] === 10
                        && $data['status'] === 'active';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::create([
            'name' => 'New Room',
            'schedule_id' => 10,
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceEnvironmentRepository::create(['name' => 'Fail Room']);

        $this->assertFalse($result);
    }

    public function test_create_uses_defaults(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    return $data['schedule_id'] === 0
                        && $data['name'] === ''
                        && $data['color'] === '#3788d8'
                        && $data['description'] === null
                        && $data['working_hours'] === null
                        && $data['status'] === 'active';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::create([]);
    }

    public function test_create_encodes_working_hours_array_to_json(): void {
        $this->wpdb->insert_id = 1;

        $working_hours = [
            ['day' => 'monday', 'start' => '09:00', 'end' => '17:00'],
        ];

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    // working_hours should be a JSON string, not an array
                    return is_string($data['working_hours'])
                        && str_contains($data['working_hours'], 'monday');
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::create([
            'name' => 'Room with Hours',
            'working_hours' => $working_hours,
        ]);
    }

    public function test_create_passes_string_working_hours_unchanged(): void {
        $this->wpdb->insert_id = 1;
        $json = '{"day":"monday"}';

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) use ($json) {
                    return $data['working_hours'] === $json;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::create([
            'name' => 'Room with String Hours',
            'working_hours' => $json,
        ]);
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_modifies_environment_and_returns_true(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    return $data['name'] === 'Updated Name';
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::update(1, ['name' => 'Updated Name']);

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = AudienceEnvironmentRepository::update(1, ['name' => 'Fail']);

        $this->assertFalse($result);
    }

    public function test_update_returns_false_when_data_is_empty_after_filtering(): void {
        // Only id and created_at are present -- all get unset
        $result = AudienceEnvironmentRepository::update(1, [
            'id' => 99,
            'created_at' => '2024-01-01',
        ]);

        $this->assertFalse($result);
    }

    public function test_update_strips_protected_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    return !isset($data['id'])
                        && !isset($data['created_at'])
                        && isset($data['name']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::update(1, [
            'id' => 99,
            'created_at' => '2024-01-01',
            'name' => 'Valid Update',
        ]);
    }

    public function test_update_clears_cache(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $cache_deleted_key = null;
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted_key) {
            $cache_deleted_key = $key;
            return true;
        });

        AudienceEnvironmentRepository::update(7, ['name' => 'Cached Update']);

        $this->assertSame('id_7', $cache_deleted_key);
    }

    public function test_update_encodes_working_hours_array_to_json(): void {
        $working_hours = [
            ['day' => 'tuesday', 'start' => '08:00', 'end' => '16:00'],
        ];

        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    return is_string($data['working_hours'])
                        && str_contains($data['working_hours'], 'tuesday');
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::update(1, ['working_hours' => $working_hours]);
    }

    public function test_update_only_includes_known_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_environments',
                Mockery::on(function($data) {
                    // Unknown field 'bogus_field' should not be in the update data
                    return !isset($data['bogus_field'])
                        && isset($data['name']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceEnvironmentRepository::update(1, ['name' => 'Good', 'bogus_field' => 'ignored']);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_removes_environment_and_returns_true(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_environments', ['id' => 5], ['%d'])
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::delete(5);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_environments', ['id' => 5], ['%d'])
            ->andReturn(false);

        $result = AudienceEnvironmentRepository::delete(5);

        $this->assertFalse($result);
    }

    public function test_delete_clears_cache(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        $cache_deleted_key = null;
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted_key) {
            $cache_deleted_key = $key;
            return true;
        });

        AudienceEnvironmentRepository::delete(8);

        $this->assertSame('id_8', $cache_deleted_key);
    }

    // ==================================================================
    // get_working_hours()
    // ==================================================================

    public function test_get_working_hours_returns_decoded_array(): void {
        $working_hours_data = [
            ['day' => 'monday', 'start' => '09:00', 'end' => '17:00'],
            ['day' => 'tuesday', 'start' => '09:00', 'end' => '17:00'],
        ];
        $env = (object) [
            'id' => 1,
            'name' => 'Room',
            'working_hours' => json_encode($working_hours_data),
            'status' => 'active',
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($env);

        $result = AudienceEnvironmentRepository::get_working_hours(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('monday', $result[0]['day']);
    }

    public function test_get_working_hours_returns_null_when_env_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceEnvironmentRepository::get_working_hours(999);

        $this->assertNull($result);
    }

    public function test_get_working_hours_returns_null_when_working_hours_is_null(): void {
        $env = (object) [
            'id' => 1,
            'name' => 'Room',
            'working_hours' => null,
            'status' => 'active',
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($env);

        $result = AudienceEnvironmentRepository::get_working_hours(1);

        $this->assertNull($result);
    }

    public function test_get_working_hours_returns_null_when_json_is_invalid(): void {
        $env = (object) [
            'id' => 1,
            'name' => 'Room',
            'working_hours' => 'not-valid-json{{{',
            'status' => 'active',
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($env);

        $result = AudienceEnvironmentRepository::get_working_hours(1);

        $this->assertNull($result);
    }

    // ==================================================================
    // add_holiday()
    // ==================================================================

    public function test_add_holiday_inserts_and_returns_id(): void {
        $this->wpdb->insert_id = 55;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_holidays',
                Mockery::on(function($data) {
                    return $data['schedule_id'] === 10
                        && $data['holiday_date'] === '2025-12-25'
                        && $data['description'] === 'Christmas'
                        && $data['created_by'] === 1;
                }),
                ['%d', '%s', '%s', '%d']
            )
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::add_holiday(10, '2025-12-25', 'Christmas');

        $this->assertSame(55, $result);
    }

    public function test_add_holiday_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceEnvironmentRepository::add_holiday(10, '2025-12-25');

        $this->assertFalse($result);
    }

    public function test_add_holiday_with_null_description(): void {
        $this->wpdb->insert_id = 56;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_holidays',
                Mockery::on(function($data) {
                    return $data['description'] === null;
                }),
                ['%d', '%s', '%s', '%d']
            )
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::add_holiday(10, '2025-01-01');

        $this->assertSame(56, $result);
    }

    // ==================================================================
    // remove_holiday()
    // ==================================================================

    public function test_remove_holiday_deletes_and_returns_true(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_holidays', ['id' => 55], ['%d'])
            ->andReturn(1);

        $result = AudienceEnvironmentRepository::remove_holiday(55);

        $this->assertTrue($result);
    }

    public function test_remove_holiday_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_holidays', ['id' => 55], ['%d'])
            ->andReturn(false);

        $result = AudienceEnvironmentRepository::remove_holiday(55);

        $this->assertFalse($result);
    }

    // ==================================================================
    // get_holidays()
    // ==================================================================

    public function test_get_holidays_returns_holidays_for_schedule(): void {
        $holidays = [
            (object) ['id' => 1, 'schedule_id' => 10, 'holiday_date' => '2025-12-25', 'description' => 'Christmas'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($holidays);

        $result = AudienceEnvironmentRepository::get_holidays(10);

        $this->assertCount(1, $result);
        $this->assertSame('Christmas', $result[0]->description);
    }

    public function test_get_holidays_filters_by_start_date(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_holidays(10, '2025-01-01');

        $this->assertStringContainsString('holiday_date >= %s', $captured_sql);
    }

    public function test_get_holidays_filters_by_end_date(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_holidays(10, null, '2025-12-31');

        $this->assertStringContainsString('holiday_date <= %s', $captured_sql);
    }

    public function test_get_holidays_filters_by_date_range(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceEnvironmentRepository::get_holidays(10, '2025-01-01', '2025-12-31');

        $this->assertStringContainsString('holiday_date >= %s', $captured_sql);
        $this->assertStringContainsString('holiday_date <= %s', $captured_sql);
    }

    // ==================================================================
    // is_holiday()
    // ==================================================================

    public function test_is_holiday_returns_false_when_env_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceEnvironmentRepository::is_holiday(999, '2025-12-25');

        $this->assertFalse($result);
    }

    public function test_is_holiday_returns_cached_result_on_cache_hit(): void {
        $env = (object) ['id' => 1, 'schedule_id' => 10, 'status' => 'active'];

        // First wp_cache_get call: cache miss for get_by_id (via StaticRepositoryTrait)
        // Second wp_cache_get call: cache hit for is_holiday
        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($env) {
            if ($key === 'id_1' && $group === 'ffc_audience_environments') {
                return $env;
            }
            if ($key === 'ffcertificate_holiday_1_2025-12-25' && $group === 'ffcertificate') {
                return 1; // cached as "is a holiday"
            }
            return false;
        });

        // wpdb should NOT be called for the holiday query since cache hit
        $this->wpdb->shouldNotReceive('get_var');

        $result = AudienceEnvironmentRepository::is_holiday(1, '2025-12-25');

        $this->assertTrue($result);
    }

    public function test_is_holiday_queries_db_on_cache_miss_and_returns_true(): void {
        $env = (object) ['id' => 1, 'schedule_id' => 10, 'status' => 'active'];

        // Cache miss for both get_by_id and is_holiday
        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($env) {
            if ($key === 'id_1' && $group === 'ffc_audience_environments') {
                return $env;
            }
            return false;
        });

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*)...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $result = AudienceEnvironmentRepository::is_holiday(1, '2025-12-25');

        $this->assertTrue($result);
    }

    public function test_is_holiday_queries_db_on_cache_miss_and_returns_false(): void {
        $env = (object) ['id' => 1, 'schedule_id' => 10, 'status' => 'active'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($env) {
            if ($key === 'id_1' && $group === 'ffc_audience_environments') {
                return $env;
            }
            return false;
        });

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*)...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $result = AudienceEnvironmentRepository::is_holiday(1, '2025-12-25');

        $this->assertFalse($result);
    }

    public function test_is_holiday_caches_result_on_miss(): void {
        $env = (object) ['id' => 1, 'schedule_id' => 10, 'status' => 'active'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($env) {
            if ($key === 'id_1' && $group === 'ffc_audience_environments') {
                return $env;
            }
            return false;
        });

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*)...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $cache_set_key = null;
        $cache_set_value = null;
        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use (&$cache_set_key, &$cache_set_value) {
            if ($group === 'ffcertificate') {
                $cache_set_key = $key;
                $cache_set_value = $value;
            }
            return true;
        });

        AudienceEnvironmentRepository::is_holiday(1, '2025-12-25');

        $this->assertSame('ffcertificate_holiday_1_2025-12-25', $cache_set_key);
        $this->assertTrue((bool) $cache_set_value);
    }

    // ==================================================================
    // count()
    // ==================================================================

    public function test_count_returns_count_with_no_filters(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*)...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = AudienceEnvironmentRepository::count();

        $this->assertSame(5, $result);
    }

    public function test_count_filters_by_schedule_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

        AudienceEnvironmentRepository::count(['schedule_id' => 10]);

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
    }

    public function test_count_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('2');

        AudienceEnvironmentRepository::count(['status' => 'active']);

        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_count_combines_schedule_id_and_status_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        AudienceEnvironmentRepository::count(['schedule_id' => 10, 'status' => 'active']);

        $this->assertStringContainsString('schedule_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_count_returns_cached_result_on_cache_hit(): void {
        Functions\when('wp_cache_get')->alias(function($key, $group = '') {
            if ($group === 'ffcertificate') {
                return 42;
            }
            return false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_var');

        $result = AudienceEnvironmentRepository::count();

        $this->assertSame(42, $result);
    }

    public function test_count_caches_result_on_cache_miss(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*)...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('7');

        $cache_set_value = null;
        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use (&$cache_set_value) {
            if ($group === 'ffcertificate') {
                $cache_set_value = $value;
            }
            return true;
        });

        $result = AudienceEnvironmentRepository::count();

        $this->assertSame(7, $result);
        $this->assertSame(7, $cache_set_value);
    }

    public function test_count_no_where_clause_when_no_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        AudienceEnvironmentRepository::count();

        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }
}
