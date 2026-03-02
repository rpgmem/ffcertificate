<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceBookingRepository;

/**
 * Tests for AudienceBookingRepository: table names, CRUD operations,
 * booking audience/user management, conflict detection, caching, and count.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingRepository
 */
class AudienceBookingRepositoryTest extends TestCase {

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
        Functions\when('absint')->alias(function($val) {
            return abs(intval($val));
        });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('current_time')->justReturn('2026-03-01 10:00:00');
        Functions\when('sanitize_sql_orderby')->alias(function($orderby) {
            // Simplified: return the value if it looks like a safe orderby clause
            if (preg_match('/^[a-zA-Z_.]+\s+(ASC|DESC)$/i', $orderby)) {
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

    public function test_get_table_name_returns_bookings_table(): void {
        $this->assertSame('wp_ffc_audience_bookings', AudienceBookingRepository::get_table_name());
    }

    public function test_get_booking_audiences_table_name_returns_correct_table(): void {
        $this->assertSame('wp_ffc_audience_booking_audiences', AudienceBookingRepository::get_booking_audiences_table_name());
    }

    public function test_get_booking_users_table_name_returns_correct_table(): void {
        $this->assertSame('wp_ffc_audience_booking_users', AudienceBookingRepository::get_booking_users_table_name());
    }

    public function test_table_names_use_wpdb_prefix(): void {
        $this->wpdb->prefix = 'custom_';

        $this->assertSame('custom_ffc_audience_bookings', AudienceBookingRepository::get_table_name());
        $this->assertSame('custom_ffc_audience_booking_audiences', AudienceBookingRepository::get_booking_audiences_table_name());
        $this->assertSame('custom_ffc_audience_booking_users', AudienceBookingRepository::get_booking_users_table_name());

        // Restore
        $this->wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // get_all()
    // ==================================================================

    public function test_get_all_returns_results_with_no_filters(): void {
        $rows = [
            (object) ['id' => 1, 'description' => 'Booking A', 'status' => 'active'],
            (object) ['id' => 2, 'description' => 'Booking B', 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT b.* FROM ...');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceBookingRepository::get_all();

        $this->assertCount(2, $result);
        $this->assertSame('Booking A', $result[0]->description);
    }

    public function test_get_all_filters_by_environment_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['environment_id' => 5]);

        $this->assertStringContainsString('b.environment_id = %d', $captured_sql);
    }

    public function test_get_all_filters_by_schedule_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['schedule_id' => 3]);

        $this->assertStringContainsString('e.schedule_id = %d', $captured_sql);
    }

    public function test_get_all_filters_by_booking_date(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['booking_date' => '2026-03-15']);

        $this->assertStringContainsString('b.booking_date = %s', $captured_sql);
    }

    public function test_get_all_filters_by_date_range(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all([
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]);

        $this->assertStringContainsString('b.booking_date >= %s', $captured_sql);
        $this->assertStringContainsString('b.booking_date <= %s', $captured_sql);
    }

    public function test_get_all_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['status' => 'active']);

        $this->assertStringContainsString('b.status = %s', $captured_sql);
    }

    public function test_get_all_filters_by_booking_type(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['booking_type' => 'audience']);

        $this->assertStringContainsString('b.booking_type = %s', $captured_sql);
    }

    public function test_get_all_filters_by_created_by(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['created_by' => 42]);

        $this->assertStringContainsString('b.created_by = %d', $captured_sql);
    }

    public function test_get_all_applies_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['limit' => 10, 'offset' => 5]);

        $this->assertStringContainsString('LIMIT 10 OFFSET 5', $captured_sql);
    }

    public function test_get_all_no_limit_clause_when_limit_is_zero(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all(['limit' => 0]);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_get_all_combines_multiple_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all([
            'environment_id' => 1,
            'status' => 'active',
            'created_by' => 5,
        ]);

        $this->assertStringContainsString('b.environment_id = %d', $captured_sql);
        $this->assertStringContainsString('b.status = %s', $captured_sql);
        $this->assertStringContainsString('b.created_by = %d', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_get_all_includes_join_to_environments_table(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_all();

        $this->assertStringContainsString('INNER JOIN', $captured_sql);
        $this->assertStringContainsString('b.environment_id = e.id', $captured_sql);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_booking_on_cache_miss(): void {
        $booking = (object) [
            'id' => 1,
            'description' => 'Test Booking',
            'status' => 'active',
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('SELECT b.* ...');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($booking);
        // get_booking_audiences query
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);
        // get_booking_users query
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $result = AudienceBookingRepository::get_by_id(1);

        $this->assertSame('Test Booking', $result->description);
        $this->assertSame([], $result->audiences);
        $this->assertSame([], $result->users);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceBookingRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached = (object) [
            'id' => 1,
            'description' => 'Cached Booking',
            'audiences' => [],
            'users' => [],
        ];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            return $key === 'id_1' && $group === 'ffc_audience_bookings' ? $cached : false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceBookingRepository::get_by_id(1);

        $this->assertSame('Cached Booking', $result->description);
    }

    public function test_get_by_id_caches_result_on_miss(): void {
        $booking = (object) ['id' => 5, 'description' => 'Booking Five'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($booking);
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $cache_set_called_with_key = null;
        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use (&$cache_set_called_with_key) {
            if ($group === 'ffc_audience_bookings') {
                $cache_set_called_with_key = $key;
            }
            return true;
        });

        AudienceBookingRepository::get_by_id(5);

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

        AudienceBookingRepository::get_by_id(999);

        $this->assertFalse($cache_set_called);
    }

    public function test_get_by_id_loads_audiences_and_users(): void {
        $booking = (object) ['id' => 10, 'description' => 'Full Booking'];
        $audiences = [
            (object) ['id' => 1, 'name' => 'Audience A'],
            (object) ['id' => 2, 'name' => 'Audience B'],
        ];
        $user_ids = ['5', '10', '15'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($booking);
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);
        $this->wpdb->shouldReceive('get_col')->once()->andReturn($user_ids);

        $result = AudienceBookingRepository::get_by_id(10);

        $this->assertCount(2, $result->audiences);
        $this->assertSame('Audience A', $result->audiences[0]->name);
        $this->assertSame([5, 10, 15], $result->users);
    }

    // ==================================================================
    // get_by_date()
    // ==================================================================

    public function test_get_by_date_delegates_to_get_all(): void {
        $rows = [
            (object) ['id' => 1, 'booking_date' => '2026-03-15'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceBookingRepository::get_by_date(5, '2026-03-15');

        $this->assertCount(1, $result);
    }

    public function test_get_by_date_passes_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_date(5, '2026-03-15', 'active');

        $this->assertStringContainsString('b.environment_id = %d', $captured_sql);
        $this->assertStringContainsString('b.booking_date = %s', $captured_sql);
        $this->assertStringContainsString('b.status = %s', $captured_sql);
    }

    // ==================================================================
    // get_by_date_range()
    // ==================================================================

    public function test_get_by_date_range_delegates_to_get_all(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_date_range(5, '2026-03-01', '2026-03-31');

        $this->assertStringContainsString('b.environment_id = %d', $captured_sql);
        $this->assertStringContainsString('b.booking_date >= %s', $captured_sql);
        $this->assertStringContainsString('b.booking_date <= %s', $captured_sql);
    }

    public function test_get_by_date_range_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_date_range(5, '2026-03-01', '2026-03-31', 'cancelled');

        $this->assertStringContainsString('b.status = %s', $captured_sql);
    }

    // ==================================================================
    // get_by_creator()
    // ==================================================================

    public function test_get_by_creator_delegates_to_get_all_with_created_by(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_creator(42);

        $this->assertStringContainsString('b.created_by = %d', $captured_sql);
    }

    public function test_get_by_creator_merges_additional_args(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_creator(42, ['status' => 'active']);

        $this->assertStringContainsString('b.created_by = %d', $captured_sql);
        $this->assertStringContainsString('b.status = %s', $captured_sql);
    }

    // ==================================================================
    // get_by_participant()
    // ==================================================================

    public function test_get_by_participant_builds_multi_table_join(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_participant(42);

        $this->assertStringContainsString('SELECT DISTINCT b.*', $captured_sql);
        $this->assertStringContainsString('LEFT JOIN', $captured_sql);
        $this->assertStringContainsString('bu.user_id = %d OR am.user_id = %d', $captured_sql);
    }

    public function test_get_by_participant_applies_date_and_status_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_by_participant(42, [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'status' => 'active',
        ]);

        $this->assertStringContainsString('b.booking_date >= %s', $captured_sql);
        $this->assertStringContainsString('b.booking_date <= %s', $captured_sql);
        $this->assertStringContainsString('b.status = %s', $captured_sql);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_booking_and_returns_id(): void {
        $this->wpdb->insert_id = 42;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return $data['environment_id'] === 5
                        && $data['booking_date'] === '2026-03-15'
                        && $data['start_time'] === '09:00'
                        && $data['end_time'] === '10:00'
                        && $data['description'] === 'Morning meeting'
                        && $data['status'] === 'active'
                        && $data['booking_type'] === 'audience'
                        && $data['created_by'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Morning meeting',
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_when_missing_required_fields(): void {
        // Missing environment_id (defaults to 0, which is falsy)
        $result = AudienceBookingRepository::create([
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Test',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_returns_false_when_missing_booking_date(): void {
        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Test',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_returns_false_when_missing_start_time(): void {
        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'booking_date' => '2026-03-15',
            'end_time' => '10:00',
            'description' => 'Test',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_returns_false_when_missing_end_time(): void {
        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'description' => 'Test',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_returns_false_when_missing_description(): void {
        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceBookingRepository::create([
            'environment_id' => 5,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Test booking',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_uses_defaults(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return $data['is_all_day'] === 0
                        && $data['booking_type'] === 'audience'
                        && $data['status'] === 'active'
                        && $data['created_by'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceBookingRepository::create([
            'environment_id' => 1,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'Defaults test',
        ]);
    }

    public function test_create_adds_audience_associations(): void {
        $this->wpdb->insert_id = 42;

        // Main booking insert
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_bookings', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(1);

        // Audience association inserts
        $this->wpdb->shouldReceive('insert')
            ->with(
                'wp_ffc_audience_booking_audiences',
                ['booking_id' => 42, 'audience_id' => 10],
                ['%d', '%d']
            )
            ->once()
            ->andReturn(1);

        $this->wpdb->shouldReceive('insert')
            ->with(
                'wp_ffc_audience_booking_audiences',
                ['booking_id' => 42, 'audience_id' => 20],
                ['%d', '%d']
            )
            ->once()
            ->andReturn(1);

        $result = AudienceBookingRepository::create([
            'environment_id' => 1,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'With audiences',
            'audience_ids' => [10, 20],
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_adds_user_associations(): void {
        $this->wpdb->insert_id = 42;

        // Main booking insert
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_bookings', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(1);

        // User association inserts
        $this->wpdb->shouldReceive('insert')
            ->with(
                'wp_ffc_audience_booking_users',
                ['booking_id' => 42, 'user_id' => 100],
                ['%d', '%d']
            )
            ->once()
            ->andReturn(1);

        $this->wpdb->shouldReceive('insert')
            ->with(
                'wp_ffc_audience_booking_users',
                ['booking_id' => 42, 'user_id' => 200],
                ['%d', '%d']
            )
            ->once()
            ->andReturn(1);

        $result = AudienceBookingRepository::create([
            'environment_id' => 1,
            'booking_date' => '2026-03-15',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'description' => 'With users',
            'user_ids' => [100, 200],
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_with_is_all_day_flag(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return $data['is_all_day'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceBookingRepository::create([
            'environment_id' => 1,
            'booking_date' => '2026-03-15',
            'start_time' => '00:00',
            'end_time' => '23:59',
            'description' => 'All day event',
            'is_all_day' => 1,
        ]);
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_modifies_booking_and_returns_true(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return $data['description'] === 'Updated description';
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::update(1, ['description' => 'Updated description']);

        $this->assertTrue($result);
    }

    public function test_update_strips_protected_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return !isset($data['id'])
                        && !isset($data['created_by'])
                        && !isset($data['created_at'])
                        && isset($data['description']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceBookingRepository::update(1, [
            'id' => 99,
            'created_by' => 5,
            'created_at' => '2024-01-01',
            'description' => 'Valid Update',
        ]);
    }

    public function test_update_returns_true_when_data_is_empty_after_filtering(): void {
        // With only protected fields, no wpdb->update is called, but update() still returns true
        $result = AudienceBookingRepository::update(1, [
            'id' => 99,
            'created_by' => 5,
            'created_at' => '2024-01-01',
        ]);

        $this->assertTrue($result);
    }

    public function test_update_handles_audience_ids_separately(): void {
        // Main booking update with description
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    // audience_ids should NOT be in the update data
                    return !isset($data['audience_ids']) && isset($data['description']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        // set_booking_audiences: delete existing
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 1], ['%d'])
            ->once()
            ->andReturn(1);

        // set_booking_audiences: add new ones
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_audiences', Mockery::any(), ['%d', '%d'])
            ->twice()
            ->andReturn(1);

        $result = AudienceBookingRepository::update(1, [
            'description' => 'Updated',
            'audience_ids' => [10, 20],
        ]);

        $this->assertTrue($result);
    }

    public function test_update_handles_user_ids_separately(): void {
        // Main booking update with description
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return !isset($data['user_ids']) && isset($data['description']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        // set_booking_users: delete existing
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 1], ['%d'])
            ->once()
            ->andReturn(1);

        // set_booking_users: add new ones
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_users', Mockery::any(), ['%d', '%d'])
            ->times(3)
            ->andReturn(1);

        $result = AudienceBookingRepository::update(1, [
            'description' => 'Updated',
            'user_ids' => [100, 200, 300],
        ]);

        $this->assertTrue($result);
    }

    public function test_update_clears_cache(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $cache_deleted_key = null;
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$cache_deleted_key) {
            if ($group === 'ffc_audience_bookings') {
                $cache_deleted_key = $key;
            }
            return true;
        });

        AudienceBookingRepository::update(7, ['description' => 'Cache test']);

        $this->assertSame('id_7', $cache_deleted_key);
    }

    public function test_update_only_includes_known_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return !isset($data['bogus_field']) && isset($data['description']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceBookingRepository::update(1, ['description' => 'Good', 'bogus_field' => 'ignored']);
    }

    public function test_update_accepts_all_known_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return isset($data['environment_id'])
                        && isset($data['booking_date'])
                        && isset($data['start_time'])
                        && isset($data['end_time'])
                        && isset($data['booking_type'])
                        && isset($data['description'])
                        && isset($data['status'])
                        && isset($data['cancelled_by'])
                        && isset($data['cancelled_at'])
                        && isset($data['cancellation_reason']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceBookingRepository::update(1, [
            'environment_id' => 2,
            'booking_date' => '2026-04-01',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'booking_type' => 'individual',
            'description' => 'Full update',
            'status' => 'cancelled',
            'cancelled_by' => 1,
            'cancelled_at' => '2026-03-01 10:00:00',
            'cancellation_reason' => 'No longer needed',
        ]);
    }

    // ==================================================================
    // cancel()
    // ==================================================================

    public function test_cancel_sets_status_and_cancellation_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audience_bookings',
                Mockery::on(function($data) {
                    return $data['status'] === 'cancelled'
                        && $data['cancelled_by'] === 1
                        && $data['cancelled_at'] === '2026-03-01 10:00:00'
                        && $data['cancellation_reason'] === 'Schedule conflict';
                }),
                ['id' => 5],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::cancel(5, 'Schedule conflict');

        $this->assertTrue($result);
    }

    public function test_cancel_returns_false_when_reason_is_empty(): void {
        $result = AudienceBookingRepository::cancel(5, '');

        $this->assertFalse($result);
    }

    public function test_cancel_clears_cache(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $deleted_keys = [];
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$deleted_keys) {
            if ($group === 'ffc_audience_bookings') {
                $deleted_keys[] = $key;
            }
            return true;
        });

        AudienceBookingRepository::cancel(8, 'Cancelled reason');

        // cancel() calls update() which clears cache, then cancel() clears cache again
        $this->assertContains('id_8', $deleted_keys);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_removes_associations_and_booking(): void {
        // Delete audience associations
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 5], ['%d'])
            ->andReturn(1);

        // Delete user associations
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 5], ['%d'])
            ->andReturn(1);

        // Delete the booking itself
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_bookings', ['id' => 5], ['%d'])
            ->andReturn(1);

        $result = AudienceBookingRepository::delete(5);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_when_booking_delete_fails(): void {
        // Association deletes succeed
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_booking_audiences', Mockery::any(), Mockery::any())
            ->andReturn(1);
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_booking_users', Mockery::any(), Mockery::any())
            ->andReturn(1);

        // Main delete fails
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_bookings', ['id' => 5], ['%d'])
            ->andReturn(false);

        $result = AudienceBookingRepository::delete(5);

        $this->assertFalse($result);
    }

    public function test_delete_clears_cache(): void {
        $this->wpdb->shouldReceive('delete')->andReturn(1);

        $deleted_keys = [];
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$deleted_keys) {
            if ($group === 'ffc_audience_bookings') {
                $deleted_keys[] = $key;
            }
            return true;
        });

        AudienceBookingRepository::delete(8);

        $this->assertContains('id_8', $deleted_keys);
    }

    public function test_delete_associations_called_before_main_delete(): void {
        $delete_order = [];

        $this->wpdb->shouldReceive('delete')->andReturnUsing(function($table) use (&$delete_order) {
            $delete_order[] = $table;
            return 1;
        });

        AudienceBookingRepository::delete(1);

        $this->assertSame([
            'wp_ffc_audience_booking_audiences',
            'wp_ffc_audience_booking_users',
            'wp_ffc_audience_bookings',
        ], $delete_order);
    }

    // ==================================================================
    // add_booking_audience()
    // ==================================================================

    public function test_add_booking_audience_inserts_and_returns_true(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_booking_audiences',
                ['booking_id' => 1, 'audience_id' => 10],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::add_booking_audience(1, 10);

        $this->assertTrue($result);
    }

    public function test_add_booking_audience_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        $result = AudienceBookingRepository::add_booking_audience(1, 10);

        $this->assertFalse($result);
    }

    // ==================================================================
    // remove_booking_audience()
    // ==================================================================

    public function test_remove_booking_audience_deletes_and_returns_true(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_ffc_audience_booking_audiences',
                ['booking_id' => 1, 'audience_id' => 10],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::remove_booking_audience(1, 10);

        $this->assertTrue($result);
    }

    public function test_remove_booking_audience_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->andReturn(false);

        $result = AudienceBookingRepository::remove_booking_audience(1, 10);

        $this->assertFalse($result);
    }

    // ==================================================================
    // get_booking_audiences()
    // ==================================================================

    public function test_get_booking_audiences_returns_audience_objects(): void {
        $audiences = [
            (object) ['id' => 1, 'name' => 'Audience A'],
            (object) ['id' => 2, 'name' => 'Audience B'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

        $result = AudienceBookingRepository::get_booking_audiences(5);

        $this->assertCount(2, $result);
        $this->assertSame('Audience A', $result[0]->name);
        $this->assertSame('Audience B', $result[1]->name);
    }

    public function test_get_booking_audiences_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceBookingRepository::get_booking_audiences(5);

        $this->assertSame([], $result);
    }

    public function test_get_booking_audiences_query_joins_audiences_table(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_booking_audiences(5);

        $this->assertStringContainsString('INNER JOIN', $captured_sql);
        $this->assertStringContainsString('a.id = ba.audience_id', $captured_sql);
        $this->assertStringContainsString('ba.booking_id = %d', $captured_sql);
    }

    // ==================================================================
    // set_booking_audiences()
    // ==================================================================

    public function test_set_booking_audiences_replaces_all(): void {
        // Delete existing
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 1], ['%d'])
            ->andReturn(1);

        // Insert new ones
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 1, 'audience_id' => 10], ['%d', '%d'])
            ->once()
            ->andReturn(1);
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 1, 'audience_id' => 20], ['%d', '%d'])
            ->once()
            ->andReturn(1);

        $result = AudienceBookingRepository::set_booking_audiences(1, [10, 20]);

        $this->assertTrue($result);
    }

    public function test_set_booking_audiences_with_empty_array_clears_all(): void {
        // Delete existing
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_audiences', ['booking_id' => 1], ['%d'])
            ->andReturn(1);

        // No inserts should happen
        $this->wpdb->shouldNotReceive('insert');

        $result = AudienceBookingRepository::set_booking_audiences(1, []);

        $this->assertTrue($result);
    }

    // ==================================================================
    // add_booking_user()
    // ==================================================================

    public function test_add_booking_user_inserts_and_returns_true(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_booking_users',
                ['booking_id' => 1, 'user_id' => 42],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::add_booking_user(1, 42);

        $this->assertTrue($result);
    }

    public function test_add_booking_user_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        $result = AudienceBookingRepository::add_booking_user(1, 42);

        $this->assertFalse($result);
    }

    // ==================================================================
    // remove_booking_user()
    // ==================================================================

    public function test_remove_booking_user_deletes_and_returns_true(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_ffc_audience_booking_users',
                ['booking_id' => 1, 'user_id' => 42],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceBookingRepository::remove_booking_user(1, 42);

        $this->assertTrue($result);
    }

    public function test_remove_booking_user_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->andReturn(false);

        $result = AudienceBookingRepository::remove_booking_user(1, 42);

        $this->assertFalse($result);
    }

    // ==================================================================
    // get_booking_users()
    // ==================================================================

    public function test_get_booking_users_returns_integer_user_ids(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['5', '10', '15']);

        $result = AudienceBookingRepository::get_booking_users(1);

        $this->assertSame([5, 10, 15], $result);
    }

    public function test_get_booking_users_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $result = AudienceBookingRepository::get_booking_users(1);

        $this->assertSame([], $result);
    }

    public function test_get_booking_users_query_selects_user_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        AudienceBookingRepository::get_booking_users(1);

        $this->assertStringContainsString('SELECT user_id FROM', $captured_sql);
        $this->assertStringContainsString('booking_id = %d', $captured_sql);
    }

    // ==================================================================
    // set_booking_users()
    // ==================================================================

    public function test_set_booking_users_replaces_all(): void {
        // Delete existing
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 1], ['%d'])
            ->andReturn(1);

        // Insert new ones
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 1, 'user_id' => 100], ['%d', '%d'])
            ->once()
            ->andReturn(1);
        $this->wpdb->shouldReceive('insert')
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 1, 'user_id' => 200], ['%d', '%d'])
            ->once()
            ->andReturn(1);

        $result = AudienceBookingRepository::set_booking_users(1, [100, 200]);

        $this->assertTrue($result);
    }

    public function test_set_booking_users_with_empty_array_clears_all(): void {
        // Delete existing
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_booking_users', ['booking_id' => 1], ['%d'])
            ->andReturn(1);

        // No inserts should happen
        $this->wpdb->shouldNotReceive('insert');

        $result = AudienceBookingRepository::set_booking_users(1, []);

        $this->assertTrue($result);
    }

    // ==================================================================
    // get_conflicts()
    // ==================================================================

    public function test_get_conflicts_returns_conflicting_bookings(): void {
        $conflicts = [
            (object) ['id' => 2, 'start_time' => '09:00', 'end_time' => '10:30'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($conflicts);

        $result = AudienceBookingRepository::get_conflicts(1, '2026-03-15', '09:30', '10:00');

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]->id);
    }

    public function test_get_conflicts_returns_empty_when_no_conflicts(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceBookingRepository::get_conflicts(1, '2026-03-15', '09:00', '10:00');

        $this->assertSame([], $result);
    }

    public function test_get_conflicts_checks_time_overlap_conditions(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_conflicts(1, '2026-03-15', '09:00', '10:00');

        $this->assertStringContainsString('environment_id = %d', $captured_sql);
        $this->assertStringContainsString('booking_date = %s', $captured_sql);
        $this->assertStringContainsString("status = 'active'", $captured_sql);
        $this->assertStringContainsString('start_time < %s AND end_time > %s', $captured_sql);
        $this->assertStringContainsString('start_time >= %s AND start_time < %s', $captured_sql);
        $this->assertStringContainsString('end_time > %s AND end_time <= %s', $captured_sql);
    }

    public function test_get_conflicts_with_exclude_booking_id(): void {
        $captured_sql = '';
        // Two prepare calls: one for the exclude clause, one for the main query
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $sql = func_get_args()[0];
            $captured_sql .= ' ' . $sql;
            return $sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_conflicts(1, '2026-03-15', '09:00', '10:00', 99);

        $this->assertStringContainsString('id != %d', $captured_sql);
    }

    public function test_get_conflicts_without_exclude_booking_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_conflicts(1, '2026-03-15', '09:00', '10:00');

        $this->assertStringNotContainsString('id !=', $captured_sql);
    }

    // ==================================================================
    // get_audience_same_day_bookings()
    // ==================================================================

    public function test_get_audience_same_day_bookings_returns_matching_bookings(): void {
        $bookings = [
            (object) ['id' => 1, 'start_time' => '09:00', 'audience_name' => 'Group A'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($bookings);

        $result = AudienceBookingRepository::get_audience_same_day_bookings('2026-03-15', [10, 20]);

        $this->assertCount(1, $result);
    }

    public function test_get_audience_same_day_bookings_returns_empty_for_empty_audience_ids(): void {
        $result = AudienceBookingRepository::get_audience_same_day_bookings('2026-03-15', []);

        $this->assertSame([], $result);
    }

    public function test_get_audience_same_day_bookings_query_structure(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_audience_same_day_bookings('2026-03-15', [10, 20]);

        $this->assertStringContainsString('booking_date = %s', $captured_sql);
        $this->assertStringContainsString("status = 'active'", $captured_sql);
        $this->assertStringContainsString('audience_id IN', $captured_sql);
        $this->assertStringContainsString('INNER JOIN', $captured_sql);
    }

    public function test_get_audience_same_day_bookings_with_exclude_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $sql = func_get_args()[0];
            $captured_sql .= ' ' . $sql;
            return $sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceBookingRepository::get_audience_same_day_bookings('2026-03-15', [10], 99);

        $this->assertStringContainsString('b.id != %d', $captured_sql);
    }

    // ==================================================================
    // count()
    // ==================================================================

    public function test_count_returns_total_count_with_no_filters(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT COUNT(*) FROM ...');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('15');

        $result = AudienceBookingRepository::count();

        $this->assertSame(15, $result);
    }

    public function test_count_filters_by_environment_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = AudienceBookingRepository::count(['environment_id' => 3]);

        $this->assertSame(5, $result);
        $this->assertStringContainsString('environment_id = %d', $captured_sql);
    }

    public function test_count_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

        $result = AudienceBookingRepository::count(['status' => 'active']);

        $this->assertSame(3, $result);
        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_count_filters_by_booking_date(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('2');

        $result = AudienceBookingRepository::count(['booking_date' => '2026-03-15']);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('booking_date = %s', $captured_sql);
    }

    public function test_count_filters_by_created_by(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('4');

        $result = AudienceBookingRepository::count(['created_by' => 42]);

        $this->assertSame(4, $result);
        $this->assertStringContainsString('created_by = %d', $captured_sql);
    }

    public function test_count_combines_multiple_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $result = AudienceBookingRepository::count([
            'environment_id' => 1,
            'status' => 'active',
            'booking_date' => '2026-03-15',
            'created_by' => 42,
        ]);

        $this->assertSame(1, $result);
        $this->assertStringContainsString('environment_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('booking_date = %s', $captured_sql);
        $this->assertStringContainsString('created_by = %d', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    public function test_count_returns_zero_when_no_results(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $result = AudienceBookingRepository::count();

        $this->assertSame(0, $result);
    }

    public function test_count_casts_result_to_int(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $result = AudienceBookingRepository::count();

        $this->assertSame(0, $result);
        $this->assertIsInt($result);
    }
}
