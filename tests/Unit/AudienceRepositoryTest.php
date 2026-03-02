<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceRepository;

/**
 * Tests for AudienceRepository: table names, CRUD operations,
 * hierarchical queries, membership management, and caching.
 *
 * @covers \FreeFormCertificate\Audience\AudienceRepository
 */
class AudienceRepositoryTest extends TestCase {

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

    public function test_get_table_name_returns_audiences_table(): void {
        $this->assertSame('wp_ffc_audiences', AudienceRepository::get_table_name());
    }

    public function test_get_members_table_name_returns_audience_members_table(): void {
        $this->assertSame('wp_ffc_audience_members', AudienceRepository::get_members_table_name());
    }

    public function test_table_names_use_wpdb_prefix(): void {
        $this->wpdb->prefix = 'custom_';

        $this->assertSame('custom_ffc_audiences', AudienceRepository::get_table_name());
        $this->assertSame('custom_ffc_audience_members', AudienceRepository::get_members_table_name());

        // Restore
        $this->wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // get_all()
    // ==================================================================

    public function test_get_all_returns_all_audiences_with_no_filters(): void {
        $rows = [
            (object) ['id' => 1, 'name' => 'Audience A', 'parent_id' => null, 'status' => 'active'],
            (object) ['id' => 2, 'name' => 'Audience B', 'parent_id' => null, 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audiences ORDER BY name ASC');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = AudienceRepository::get_all();

        $this->assertCount(2, $result);
        $this->assertSame('Audience A', $result[0]->name);
    }

    public function test_get_all_filters_by_parent_id_zero_for_parents(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['parent_id' => 0]);

        $this->assertStringContainsString('parent_id IS NULL', $captured_sql);
    }

    public function test_get_all_filters_by_specific_parent_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['parent_id' => 5]);

        $this->assertStringContainsString('parent_id = %d', $captured_sql);
    }

    public function test_get_all_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['status' => 'active']);

        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_get_all_applies_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['limit' => 10, 'offset' => 5]);

        $this->assertStringContainsString('LIMIT 10 OFFSET 5', $captured_sql);
    }

    public function test_get_all_no_limit_clause_when_limit_is_zero(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['limit' => 0]);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_get_all_combines_parent_id_and_status_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_all(['parent_id' => 3, 'status' => 'active']);

        $this->assertStringContainsString('parent_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
        $this->assertStringContainsString('AND', $captured_sql);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_audience_on_cache_miss(): void {
        $audience = (object) ['id' => 1, 'name' => 'Test Audience', 'status' => 'active'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_audiences WHERE id = 1');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($audience);

        $result = AudienceRepository::get_by_id(1);

        $this->assertSame('Test Audience', $result->name);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = AudienceRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached = (object) ['id' => 1, 'name' => 'Cached Audience'];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            return $key === 'id_1' && $group === 'ffc_audiences' ? $cached : false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = AudienceRepository::get_by_id(1);

        $this->assertSame('Cached Audience', $result->name);
    }

    public function test_get_by_id_caches_result_on_miss(): void {
        $audience = (object) ['id' => 5, 'name' => 'Audience Five'];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($audience);

        // Verify cache_set is called with the right key
        Functions\when('wp_cache_set')->alias(function($key, $value, $group = '') use ($audience) {
            if ($key === 'id_5' && $group === 'ffc_audiences') {
                // Verify the cached value is the audience object
                \PHPUnit\Framework\TestCase::assertSame($audience, $value);
            }
            return true;
        });

        AudienceRepository::get_by_id(5);
    }

    public function test_get_by_id_does_not_cache_null_result(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $cache_set_called = false;
        Functions\when('wp_cache_set')->alias(function() use (&$cache_set_called) {
            $cache_set_called = true;
            return true;
        });

        AudienceRepository::get_by_id(999);

        $this->assertFalse($cache_set_called);
    }

    // ==================================================================
    // get_parents()
    // ==================================================================

    public function test_get_parents_returns_top_level_audiences(): void {
        $parents = [
            (object) ['id' => 1, 'name' => 'Parent A', 'parent_id' => null],
            (object) ['id' => 2, 'name' => 'Parent B', 'parent_id' => null],
        ];

        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($parents);

        $result = AudienceRepository::get_parents();

        $this->assertCount(2, $result);
        $this->assertStringContainsString('parent_id IS NULL', $captured_sql);
    }

    public function test_get_parents_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_parents('active');

        $this->assertStringContainsString('parent_id IS NULL', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    // ==================================================================
    // get_children()
    // ==================================================================

    public function test_get_children_returns_children_of_parent(): void {
        $children = [
            (object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1],
            (object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1],
        ];

        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($children);

        $result = AudienceRepository::get_children(1);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('parent_id = %d', $captured_sql);
    }

    public function test_get_children_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::get_children(1, 'active');

        $this->assertStringContainsString('parent_id = %d', $captured_sql);
        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    // ==================================================================
    // get_hierarchical()
    // ==================================================================

    public function test_get_hierarchical_returns_parents_with_children(): void {
        $parent1 = (object) ['id' => 1, 'name' => 'Parent A', 'parent_id' => null];
        $parent2 = (object) ['id' => 2, 'name' => 'Parent B', 'parent_id' => null];
        $child1 = (object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1];
        $child2 = (object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1];

        // First call: get_parents -> get_all with parent_id=0
        // Second call: get_children(1) -> get_all with parent_id=1
        // Third call: get_children(2) -> get_all with parent_id=2
        $call_count = 0;
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$call_count, $parent1, $parent2, $child1, $child2) {
            $call_count++;
            switch ($call_count) {
                case 1: return [$parent1, $parent2]; // parents
                case 2: return [$child1, $child2];   // children of parent 1
                case 3: return [];                    // children of parent 2
                default: return [];
            }
        });

        $result = AudienceRepository::get_hierarchical();

        $this->assertCount(2, $result);
        $this->assertObjectHasProperty('children', $result[0]);
        $this->assertCount(2, $result[0]->children);
        $this->assertObjectHasProperty('children', $result[1]);
        $this->assertCount(0, $result[1]->children);
    }

    public function test_get_hierarchical_returns_empty_array_when_no_parents(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceRepository::get_hierarchical();

        $this->assertSame([], $result);
    }

    public function test_get_hierarchical_passes_status_filter(): void {
        $captured_sqls = [];
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sqls) {
            $sql = func_get_args()[0];
            $captured_sqls[] = $sql;
            return $sql;
        });
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        AudienceRepository::get_hierarchical('active');

        $this->assertNotEmpty($captured_sqls);
        $this->assertStringContainsString('status = %s', $captured_sqls[0]);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_audience_and_returns_id(): void {
        $this->wpdb->insert_id = 42;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audiences',
                Mockery::on(function($data) {
                    return $data['name'] === 'New Audience'
                        && $data['color'] === '#ff0000'
                        && $data['status'] === 'active';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $result = AudienceRepository::create([
            'name' => 'New Audience',
            'color' => '#ff0000',
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceRepository::create(['name' => 'Fail Audience']);

        $this->assertFalse($result);
    }

    public function test_create_uses_defaults(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audiences',
                Mockery::on(function($data) {
                    return $data['color'] === '#3788d8'
                        && $data['status'] === 'active'
                        && $data['created_by'] === 1
                        && $data['parent_id'] === null;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceRepository::create(['name' => 'Default Test']);
    }

    public function test_create_includes_allow_self_join(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audiences',
                Mockery::on(function($data) {
                    return isset($data['allow_self_join']) && $data['allow_self_join'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        AudienceRepository::create(['name' => 'Self Join', 'allow_self_join' => 1]);
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_modifies_audience_and_returns_true(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audiences',
                Mockery::on(function($data) {
                    return $data['name'] === 'Updated Name';
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        $result = AudienceRepository::update(1, ['name' => 'Updated Name']);

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = AudienceRepository::update(1, ['name' => 'Fail']);

        $this->assertFalse($result);
    }

    public function test_update_returns_false_when_data_is_empty_after_filtering(): void {
        // Only id, created_by, created_at are present -- all get unset
        $result = AudienceRepository::update(1, [
            'id' => 99,
            'created_by' => 5,
            'created_at' => '2024-01-01',
        ]);

        $this->assertFalse($result);
    }

    public function test_update_strips_protected_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audiences',
                Mockery::on(function($data) {
                    return !isset($data['id'])
                        && !isset($data['created_by'])
                        && !isset($data['created_at'])
                        && isset($data['name']);
                }),
                ['id' => 1],
                Mockery::type('array'),
                ['%d']
            )
            ->andReturn(1);

        AudienceRepository::update(1, [
            'id' => 99,
            'created_by' => 5,
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

        AudienceRepository::update(7, ['name' => 'Cached Update']);

        $this->assertSame('id_7', $cache_deleted_key);
    }

    public function test_update_only_includes_known_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_audiences',
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

        AudienceRepository::update(1, ['name' => 'Good', 'bogus_field' => 'ignored']);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_removes_audience_members_and_children(): void {
        // No children
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]); // get_children returns empty

        // Delete member associations
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audience_members', ['audience_id' => 5], ['%d'])
            ->andReturn(1);

        // Delete the audience itself
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_audiences', ['id' => 5], ['%d'])
            ->andReturn(1);

        $result = AudienceRepository::delete(5);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]); // no children

        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_members', Mockery::any(), Mockery::any())
            ->andReturn(1);

        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audiences', ['id' => 5], ['%d'])
            ->andReturn(false);

        $result = AudienceRepository::delete(5);

        $this->assertFalse($result);
    }

    public function test_delete_recursively_deletes_children(): void {
        $child = (object) ['id' => 10, 'name' => 'Child', 'parent_id' => 5];

        $call_count = 0;
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$call_count, $child) {
            $call_count++;
            // First call: children of parent 5 (returns one child)
            // Second call: children of child 10 (returns empty)
            return $call_count === 1 ? [$child] : [];
        });

        // Expect deletes for child 10 first (members + audience), then parent 5
        $delete_calls = [];
        $this->wpdb->shouldReceive('delete')->andReturnUsing(function($table, $where) use (&$delete_calls) {
            $delete_calls[] = ['table' => $table, 'where' => $where];
            return 1;
        });

        AudienceRepository::delete(5);

        // Should have 4 delete calls:
        // 1. child members, 2. child audience, 3. parent members, 4. parent audience
        $this->assertCount(4, $delete_calls);
        $this->assertSame(['audience_id' => 10], $delete_calls[0]['where']);
        $this->assertSame(['id' => 10], $delete_calls[1]['where']);
        $this->assertSame(['audience_id' => 5], $delete_calls[2]['where']);
        $this->assertSame(['id' => 5], $delete_calls[3]['where']);
    }

    public function test_delete_clears_cache(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);
        $this->wpdb->shouldReceive('delete')->andReturn(1);

        $deleted_keys = [];
        Functions\when('wp_cache_delete')->alias(function($key, $group = '') use (&$deleted_keys) {
            $deleted_keys[] = $key;
            return true;
        });

        AudienceRepository::delete(8);

        $this->assertContains('id_8', $deleted_keys);
    }

    // ==================================================================
    // add_member()
    // ==================================================================

    public function test_add_member_inserts_and_returns_id(): void {
        $this->wpdb->insert_id = 99;

        // is_member check returns 0 (not a member)
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_audience_members',
                ['audience_id' => 1, 'user_id' => 42],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceRepository::add_member(1, 42);

        $this->assertSame(99, $result);
    }

    public function test_add_member_returns_false_if_already_member(): void {
        // is_member returns count > 0
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $this->wpdb->shouldNotReceive('insert');

        $result = AudienceRepository::add_member(1, 42);

        $this->assertFalse($result);
    }

    public function test_add_member_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = AudienceRepository::add_member(1, 42);

        $this->assertFalse($result);
    }

    // ==================================================================
    // remove_member()
    // ==================================================================

    public function test_remove_member_deletes_and_returns_true(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_ffc_audience_members',
                ['audience_id' => 1, 'user_id' => 42],
                ['%d', '%d']
            )
            ->andReturn(1);

        $result = AudienceRepository::remove_member(1, 42);

        $this->assertTrue($result);
    }

    public function test_remove_member_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(false);

        $result = AudienceRepository::remove_member(1, 42);

        $this->assertFalse($result);
    }

    public function test_remove_member_returns_true_when_no_rows_affected(): void {
        // delete returns 0 (no rows affected, but no error => not false)
        $this->wpdb->shouldReceive('delete')->once()->andReturn(0);

        $result = AudienceRepository::remove_member(1, 999);

        $this->assertTrue($result);
    }

    // ==================================================================
    // is_member()
    // ==================================================================

    public function test_is_member_returns_true_when_member_exists(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $this->assertTrue(AudienceRepository::is_member(1, 42));
    }

    public function test_is_member_returns_false_when_not_member(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $this->assertFalse(AudienceRepository::is_member(1, 42));
    }

    public function test_is_member_returns_false_when_count_is_null(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $this->assertFalse(AudienceRepository::is_member(1, 42));
    }

    // ==================================================================
    // get_members()
    // ==================================================================

    public function test_get_members_returns_user_ids(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30']);

        $result = AudienceRepository::get_members(1);

        $this->assertSame([10, 20, 30], $result);
    }

    public function test_get_members_returns_empty_array_when_no_members(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $result = AudienceRepository::get_members(1);

        $this->assertSame([], $result);
    }

    public function test_get_members_includes_children_when_requested(): void {
        $child1 = (object) ['id' => 3, 'name' => 'Child A', 'parent_id' => 1];
        $child2 = (object) ['id' => 4, 'name' => 'Child B', 'parent_id' => 1];

        // get_children call (via get_all)
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([$child1, $child2]);

        // get_col for members query (should include audience_ids 1, 3, 4)
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20']);

        $result = AudienceRepository::get_members(1, true);

        $this->assertSame([10, 20], $result);
    }

    public function test_get_members_without_children_queries_single_audience(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $sql = func_get_args()[0];
            if (strpos($sql, 'DISTINCT') !== false) {
                $captured_sql = $sql;
            }
            return $sql;
        });
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        AudienceRepository::get_members(1, false);

        $this->assertStringContainsString('IN (%d)', $captured_sql);
    }

    public function test_get_members_casts_results_to_integers(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['1', '2', '3']);

        $result = AudienceRepository::get_members(1);

        foreach ($result as $id) {
            $this->assertIsInt($id);
        }
    }

    // ==================================================================
    // get_user_audiences()
    // ==================================================================

    public function test_get_user_audiences_returns_audiences_for_user(): void {
        $audiences = [
            (object) ['id' => 1, 'name' => 'Audience A', 'parent_id' => null, 'status' => 'active'],
            (object) ['id' => 3, 'name' => 'Audience C', 'parent_id' => 1, 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

        $result = AudienceRepository::get_user_audiences(42);

        $this->assertCount(2, $result);
        $this->assertSame('Audience A', $result[0]->name);
    }

    public function test_get_user_audiences_returns_empty_array_when_no_memberships(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceRepository::get_user_audiences(42);

        $this->assertSame([], $result);
    }

    public function test_get_user_audiences_includes_parents_when_requested(): void {
        $child_audience = (object) [
            'id' => 3,
            'name' => 'Child Audience',
            'parent_id' => 1,
            'status' => 'active',
        ];
        $parent_audience = (object) [
            'id' => 1,
            'name' => 'Parent Audience',
            'parent_id' => null,
            'status' => 'active',
        ];

        $call_count = 0;
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$call_count, $child_audience, $parent_audience) {
            $call_count++;
            if ($call_count === 1) {
                return [$child_audience]; // user's direct audiences
            }
            return [$parent_audience]; // parent audiences
        });

        $result = AudienceRepository::get_user_audiences(42, true);

        $this->assertCount(2, $result);
        // Should be sorted by name
        $names = array_map(function($a) { return $a->name; }, $result);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function test_get_user_audiences_does_not_duplicate_when_including_parents(): void {
        // User is directly in parent audience and also in child audience
        $parent_audience = (object) [
            'id' => 1,
            'name' => 'Parent',
            'parent_id' => null,
            'status' => 'active',
        ];
        $child_audience = (object) [
            'id' => 3,
            'name' => 'Child',
            'parent_id' => 1,
            'status' => 'active',
        ];

        $call_count = 0;
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->andReturnUsing(function() use (&$call_count, $parent_audience, $child_audience) {
            $call_count++;
            if ($call_count === 1) {
                return [$parent_audience, $child_audience]; // user has both
            }
            return [$parent_audience]; // parent lookup returns same parent
        });

        $result = AudienceRepository::get_user_audiences(42, true);

        // Should not have duplicate parent
        $ids = array_column($result, 'id');
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function test_get_user_audiences_uses_cache(): void {
        $cached = [
            (object) ['id' => 1, 'name' => 'Cached Audience'],
        ];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            if ($key === 'ffcertificate_user_aud_42_0' && $group === 'ffcertificate') {
                return $cached;
            }
            return false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_results');

        $result = AudienceRepository::get_user_audiences(42);

        $this->assertCount(1, $result);
        $this->assertSame('Cached Audience', $result[0]->name);
    }

    public function test_get_user_audiences_caches_with_parents_flag(): void {
        $cached = [
            (object) ['id' => 1, 'name' => 'Cached With Parents'],
        ];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            if ($key === 'ffcertificate_user_aud_42_1' && $group === 'ffcertificate') {
                return $cached;
            }
            return false;
        });

        $this->wpdb->shouldNotReceive('get_results');

        $result = AudienceRepository::get_user_audiences(42, true);

        $this->assertCount(1, $result);
    }

    // ==================================================================
    // get_member_count()
    // ==================================================================

    public function test_get_member_count_returns_count_of_members(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30']);

        $count = AudienceRepository::get_member_count(1);

        $this->assertSame(3, $count);
    }

    public function test_get_member_count_returns_zero_when_no_members(): void {
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_col')->once()->andReturn([]);

        $count = AudienceRepository::get_member_count(1);

        $this->assertSame(0, $count);
    }

    public function test_get_member_count_includes_children_when_requested(): void {
        $child = (object) ['id' => 3, 'name' => 'Child', 'parent_id' => 1];

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        // get_children call
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([$child]);
        // get_col for DISTINCT user_id
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(['10', '20', '30', '40']);

        $count = AudienceRepository::get_member_count(1, true);

        $this->assertSame(4, $count);
    }

    // ==================================================================
    // bulk_add_members()
    // ==================================================================

    public function test_bulk_add_members_adds_all_users(): void {
        $this->wpdb->insert_id = 1;

        // Each add_member call checks is_member first
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->andReturn('0'); // not a member
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        $added = AudienceRepository::bulk_add_members(1, [10, 20, 30]);

        $this->assertSame(3, $added);
    }

    public function test_bulk_add_members_skips_existing_members(): void {
        $this->wpdb->insert_id = 1;

        $get_var_call = 0;
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->andReturnUsing(function() use (&$get_var_call) {
            $get_var_call++;
            // First user is already a member, others are not
            return $get_var_call === 1 ? '1' : '0';
        });
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        $added = AudienceRepository::bulk_add_members(1, [10, 20, 30]);

        $this->assertSame(2, $added);
    }

    public function test_bulk_add_members_returns_zero_for_empty_array(): void {
        $added = AudienceRepository::bulk_add_members(1, []);

        $this->assertSame(0, $added);
    }

    public function test_bulk_add_members_counts_only_successful_inserts(): void {
        $this->wpdb->insert_id = 1;

        $insert_call = 0;
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->andReturn('0'); // not a member
        $this->wpdb->shouldReceive('insert')->andReturnUsing(function() use (&$insert_call) {
            $insert_call++;
            // Second insert fails
            return $insert_call === 2 ? false : 1;
        });

        $added = AudienceRepository::bulk_add_members(1, [10, 20, 30]);

        $this->assertSame(2, $added);
    }

    // ==================================================================
    // cascade_self_join()
    // ==================================================================

    public function test_cascade_self_join_updates_children(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('query')->once()->andReturn(3);

        AudienceRepository::cascade_self_join(1, 1);

        $this->assertStringContainsString('UPDATE', $captured_sql);
        $this->assertStringContainsString('allow_self_join', $captured_sql);
        $this->assertStringContainsString('parent_id', $captured_sql);
    }

    // ==================================================================
    // bulk_remove_members()
    // ==================================================================

    public function test_bulk_remove_members_removes_all_users(): void {
        $this->wpdb->shouldReceive('delete')->andReturn(1);

        $removed = AudienceRepository::bulk_remove_members(1, [10, 20, 30]);

        $this->assertSame(3, $removed);
    }

    public function test_bulk_remove_members_returns_zero_for_empty_array(): void {
        $removed = AudienceRepository::bulk_remove_members(1, []);

        $this->assertSame(0, $removed);
    }

    // ==================================================================
    // set_members()
    // ==================================================================

    public function test_set_members_replaces_all_members(): void {
        $this->wpdb->insert_id = 1;

        // First: delete all existing members
        $this->wpdb->shouldReceive('delete')
            ->with('wp_ffc_audience_members', ['audience_id' => 1], ['%d'])
            ->once()
            ->andReturn(5);

        // Then: add_member for each user (is_member + insert)
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->andReturn('0');
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        $result = AudienceRepository::set_members(1, [10, 20]);

        $this->assertTrue($result);
    }

    // ==================================================================
    // count()
    // ==================================================================

    public function test_count_returns_total_audiences(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('15');

        $result = AudienceRepository::count();

        $this->assertSame(15, $result);
    }

    public function test_count_filters_by_parent_id(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        AudienceRepository::count(['parent_id' => 0]);

        $this->assertStringContainsString('parent_id IS NULL', $captured_sql);
    }

    public function test_count_filters_by_status(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

        AudienceRepository::count(['status' => 'active']);

        $this->assertStringContainsString('status = %s', $captured_sql);
    }

    public function test_count_uses_cache(): void {
        Functions\when('wp_cache_get')->alias(function($key, $group = '') {
            if ($group === 'ffcertificate') {
                return 42;
            }
            return false;
        });

        $this->wpdb->shouldNotReceive('get_var');

        $result = AudienceRepository::count();

        $this->assertSame(42, $result);
    }

    // ==================================================================
    // search()
    // ==================================================================

    public function test_search_returns_matching_audiences(): void {
        $audiences = [
            (object) ['id' => 1, 'name' => 'Test Audience'],
        ];

        $this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function($v) {
            return $v;
        });
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

        $result = AudienceRepository::search('Test');

        $this->assertCount(1, $result);
        $this->assertSame('Test Audience', $result[0]->name);
    }

    public function test_search_returns_empty_array_when_no_matches(): void {
        $this->wpdb->shouldReceive('esc_like')->once()->andReturn('nonexistent');
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = AudienceRepository::search('nonexistent');

        $this->assertSame([], $result);
    }

    public function test_search_uses_cache(): void {
        $cached = [(object) ['id' => 1, 'name' => 'Cached Result']];

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($cached) {
            if ($group === 'ffcertificate') {
                return $cached;
            }
            return false;
        });

        $this->wpdb->shouldNotReceive('get_results');

        $result = AudienceRepository::search('cached');

        $this->assertCount(1, $result);
    }

    public function test_search_respects_limit_parameter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('esc_like')->once()->andReturn('test');
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        AudienceRepository::search('test', 5);

        $this->assertStringContainsString('LIMIT %d', $captured_sql);
    }
}
