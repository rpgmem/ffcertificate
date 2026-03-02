<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\CustomFieldRepository;

/**
 * Tests for CustomFieldRepository: table names, CRUD, caching, user data,
 * audience hierarchy traversal, and counting.
 *
 * @covers \FreeFormCertificate\Reregistration\CustomFieldRepository
 */
class CustomFieldRepositoryTest extends TestCase {

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
        Functions\when('sanitize_key')->alias(function($key) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        });
        Functions\when('absint')->alias(function($val) { return abs(intval($val)); });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('sanitize_title')->alias(function($title) {
            return strtolower(preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '-', strtolower($title))));
        });

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        })->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Helper: create a field object
    // ==================================================================

    /**
     * Build a mock field definition object.
     *
     * @param array $overrides Properties to override.
     * @return object
     */
    private function make_field(array $overrides = []): object {
        $defaults = [
            'id'               => 1,
            'audience_id'      => 10,
            'field_key'        => 'test_field',
            'field_label'      => 'Test Field',
            'field_type'       => 'text',
            'field_options'    => null,
            'validation_rules' => null,
            'sort_order'       => 0,
            'is_required'      => 0,
            'is_active'        => 1,
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Build a mock audience object.
     *
     * @param int         $id        Audience ID.
     * @param string      $name      Audience name.
     * @param int|null    $parent_id Parent audience ID, or null for root.
     * @return object
     */
    private function make_audience(int $id, string $name, ?int $parent_id = null): object {
        return (object) [
            'id'        => $id,
            'name'      => $name,
            'parent_id' => $parent_id,
            'status'    => 'active',
        ];
    }

    // ==================================================================
    // get_table_name()
    // ==================================================================

    public function test_get_table_name_returns_correct_name(): void {
        $this->assertSame('wp_ffc_custom_fields', CustomFieldRepository::get_table_name());
    }

    public function test_get_table_name_uses_wpdb_prefix(): void {
        global $wpdb;
        $wpdb->prefix = 'test_';

        $this->assertSame('test_ffc_custom_fields', CustomFieldRepository::get_table_name());

        // Restore
        $wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_field_on_cache_miss(): void {
        $field = $this->make_field(['id' => 5]);

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($field);

        $result = CustomFieldRepository::get_by_id(5);

        $this->assertNotNull($result);
        $this->assertEquals(5, $result->id);
        $this->assertSame('test_field', $result->field_key);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = CustomFieldRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached_field = $this->make_field(['id' => 7]);

        Functions\when('wp_cache_get')->alias(function($key) use ($cached_field) {
            return $key === 'id_7' ? $cached_field : false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = CustomFieldRepository::get_by_id(7);

        $this->assertNotNull($result);
        $this->assertEquals(7, $result->id);
    }

    // ==================================================================
    // get_by_audience()
    // ==================================================================

    public function test_get_by_audience_returns_active_fields_by_default(): void {
        $fields = [
            $this->make_field(['id' => 1, 'audience_id' => 10, 'is_active' => 1]),
            $this->make_field(['id' => 2, 'audience_id' => 10, 'is_active' => 1]),
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($fields);

        $result = CustomFieldRepository::get_by_audience(10);

        $this->assertCount(2, $result);
    }

    public function test_get_by_audience_returns_all_fields_when_active_only_false(): void {
        $fields = [
            $this->make_field(['id' => 1, 'is_active' => 1]),
            $this->make_field(['id' => 2, 'is_active' => 0]),
        ];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($fields);

        $result = CustomFieldRepository::get_by_audience(10, false);

        $this->assertCount(2, $result);
    }

    public function test_get_by_audience_returns_empty_array_when_no_fields(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = CustomFieldRepository::get_by_audience(10);

        $this->assertSame([], $result);
    }

    public function test_get_by_audience_active_only_includes_active_clause(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_query) {
            $captured_query = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        CustomFieldRepository::get_by_audience(10, true);

        $this->assertStringContainsString('is_active = 1', $captured_query);
    }

    public function test_get_by_audience_active_only_false_excludes_active_clause(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_query) {
            $captured_query = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        CustomFieldRepository::get_by_audience(10, false);

        $this->assertStringNotContainsString('is_active = 1', $captured_query);
    }

    // ==================================================================
    // get_by_audience_with_parents()
    // ==================================================================

    public function test_get_by_audience_with_parents_returns_empty_for_invalid_audience(): void {
        // AudienceRepository::get_by_id will also use global $wpdb, and
        // we rely on cache returning false + wpdb returning null for the audience lookup.
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->andReturn(null);

        $result = CustomFieldRepository::get_by_audience_with_parents(999);

        $this->assertSame([], $result);
    }

    public function test_get_by_audience_with_parents_single_level(): void {
        // Root audience with no parent
        $audience = $this->make_audience(10, 'Root Audience', null);
        $fields = [
            $this->make_field(['id' => 1, 'audience_id' => 10]),
            $this->make_field(['id' => 2, 'audience_id' => 10]),
        ];

        // First get_row call: AudienceRepository::get_by_id(10) returns the audience
        $this->wpdb->shouldReceive('get_row')
            ->andReturn($audience);

        // get_results for get_by_audience(10)
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($fields);

        $result = CustomFieldRepository::get_by_audience_with_parents(10);

        $this->assertCount(2, $result);
        // Each field should have source_audience_id and source_audience_name
        $this->assertEquals(10, $result[0]->source_audience_id);
        $this->assertSame('Root Audience', $result[0]->source_audience_name);
        $this->assertEquals(10, $result[1]->source_audience_id);
    }

    public function test_get_by_audience_with_parents_walks_hierarchy(): void {
        // Hierarchy: grandparent(1) -> parent(5) -> child(10)
        $grandparent = $this->make_audience(1, 'Grandparent', null);
        $parent      = $this->make_audience(5, 'Parent', 1);
        $child       = $this->make_audience(10, 'Child', 5);

        $grandparent_fields = [
            $this->make_field(['id' => 100, 'audience_id' => 1, 'field_key' => 'gp_field']),
        ];
        $parent_fields = [
            $this->make_field(['id' => 200, 'audience_id' => 5, 'field_key' => 'parent_field']),
        ];
        $child_fields = [
            $this->make_field(['id' => 300, 'audience_id' => 10, 'field_key' => 'child_field']),
        ];

        // AudienceRepository::get_by_id calls: child(10), then parent(5), then grandparent(1)
        $this->wpdb->shouldReceive('get_row')
            ->andReturn($child, $parent, $grandparent);

        // get_by_audience calls: grandparent(1), parent(5), child(10) - reversed order
        $this->wpdb->shouldReceive('get_results')
            ->andReturn($grandparent_fields, $parent_fields, $child_fields);

        $result = CustomFieldRepository::get_by_audience_with_parents(10);

        // Should have 3 fields total, grandparent first, then parent, then child
        $this->assertCount(3, $result);

        // First field from grandparent
        $this->assertEquals(1, $result[0]->source_audience_id);
        $this->assertSame('Grandparent', $result[0]->source_audience_name);
        $this->assertSame('gp_field', $result[0]->field_key);

        // Second field from parent
        $this->assertEquals(5, $result[1]->source_audience_id);
        $this->assertSame('Parent', $result[1]->source_audience_name);
        $this->assertSame('parent_field', $result[1]->field_key);

        // Third field from child
        $this->assertEquals(10, $result[2]->source_audience_id);
        $this->assertSame('Child', $result[2]->source_audience_name);
        $this->assertSame('child_field', $result[2]->field_key);
    }

    public function test_get_by_audience_with_parents_adds_source_properties(): void {
        $audience = $this->make_audience(10, 'My Audience', null);
        $field = $this->make_field(['id' => 1, 'audience_id' => 10]);

        $this->wpdb->shouldReceive('get_row')->andReturn($audience);
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([$field]);

        $result = CustomFieldRepository::get_by_audience_with_parents(10);

        $this->assertCount(1, $result);
        $this->assertObjectHasProperty('source_audience_id', $result[0]);
        $this->assertObjectHasProperty('source_audience_name', $result[0]);
        $this->assertEquals(10, $result[0]->source_audience_id);
        $this->assertSame('My Audience', $result[0]->source_audience_name);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_field_and_returns_id(): void {
        $this->wpdb->insert_id = 42;

        // ensure_unique_key: no existing field with this key
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);

        $result = CustomFieldRepository::create([
            'audience_id'   => 10,
            'field_key'     => 'my_field',
            'field_label'   => 'My Field',
            'field_type'    => 'text',
            'is_required'   => 1,
            'is_active'     => 1,
        ]);

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_on_insert_failure(): void {
        // ensure_unique_key: no conflict
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = CustomFieldRepository::create([
            'audience_id' => 10,
            'field_key'   => 'fail_field',
            'field_label' => 'Fail',
        ]);

        $this->assertFalse($result);
    }

    public function test_create_uses_defaults_for_missing_fields(): void {
        $this->wpdb->insert_id = 1;

        // ensure_unique_key check
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $captured_data = null;
        $this->wpdb->shouldReceive('insert')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::create([
            'audience_id' => 5,
            'field_key'   => 'test',
            'field_label' => 'Test',
        ]);

        $this->assertNotNull($captured_data);
        $this->assertSame(5, $captured_data['audience_id']);
        $this->assertSame('text', $captured_data['field_type']); // default
        $this->assertSame(0, $captured_data['sort_order']); // default
        $this->assertSame(0, $captured_data['is_required']); // default
        $this->assertSame(1, $captured_data['is_active']); // default
    }

    public function test_create_falls_back_to_text_for_invalid_field_type(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $captured_data = null;
        $this->wpdb->shouldReceive('insert')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::create([
            'audience_id' => 5,
            'field_key'   => 'test',
            'field_label' => 'Test',
            'field_type'  => 'invalid_type',
        ]);

        $this->assertSame('text', $captured_data['field_type']);
    }

    public function test_create_encodes_array_field_options_as_json(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $captured_data = null;
        $this->wpdb->shouldReceive('insert')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $options = ['choices' => ['A', 'B', 'C']];
        CustomFieldRepository::create([
            'audience_id'   => 5,
            'field_key'     => 'select_field',
            'field_label'   => 'Select',
            'field_type'    => 'select',
            'field_options' => $options,
        ]);

        $this->assertSame(json_encode($options), $captured_data['field_options']);
    }

    public function test_create_keeps_string_field_options_as_is(): void {
        $this->wpdb->insert_id = 1;

        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $captured_data = null;
        $this->wpdb->shouldReceive('insert')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $options_json = '{"choices":["X","Y"]}';
        CustomFieldRepository::create([
            'audience_id'   => 5,
            'field_key'     => 'select_field',
            'field_label'   => 'Select',
            'field_type'    => 'select',
            'field_options' => $options_json,
        ]);

        $this->assertSame($options_json, $captured_data['field_options']);
    }

    public function test_create_auto_generates_field_key_from_label_when_empty(): void {
        $this->wpdb->insert_id = 1;

        // ensure_unique_key check
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(0);

        $captured_data = null;
        $this->wpdb->shouldReceive('insert')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::create([
            'audience_id' => 5,
            'field_label' => 'My Test Label',
        ]);

        // field_key should be auto-generated (sanitize_key applied to generated key)
        $this->assertNotEmpty($captured_data['field_key']);
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = CustomFieldRepository::update(5, [
            'field_label' => 'Updated Label',
        ]);

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = CustomFieldRepository::update(5, [
            'field_label' => 'Updated Label',
        ]);

        $this->assertFalse($result);
    }

    public function test_update_returns_false_when_data_is_empty(): void {
        // No wpdb calls should happen
        $this->wpdb->shouldNotReceive('update');

        $result = CustomFieldRepository::update(5, []);

        $this->assertFalse($result);
    }

    public function test_update_strips_id_and_created_at(): void {
        // Passing only 'id' and 'created_at' should result in empty update data
        $this->wpdb->shouldNotReceive('update');

        $result = CustomFieldRepository::update(5, [
            'id'         => 99,
            'created_at' => '2025-01-01',
        ]);

        $this->assertFalse($result);
    }

    public function test_update_clears_cache_after_success(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        // Verify cache is cleared by checking wp_cache_delete is called
        // (it's already stubbed to return true, but we can verify the call happens)
        Functions\when('wp_cache_delete')->alias(function($key, $group) {
            // Ensure it is called with the right key
            $this->assertSame("id_5", $key);
            $this->assertSame('ffc_custom_fields', $group);
            return true;
        });

        CustomFieldRepository::update(5, ['field_label' => 'Updated']);
    }

    public function test_update_ignores_unknown_fields(): void {
        // Only known fields should be passed to wpdb->update
        $captured_data = null;
        $this->wpdb->shouldReceive('update')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::update(5, [
            'field_label'   => 'Valid',
            'unknown_field' => 'should be ignored',
        ]);

        $this->assertArrayHasKey('field_label', $captured_data);
        $this->assertArrayNotHasKey('unknown_field', $captured_data);
    }

    public function test_update_falls_back_to_text_for_invalid_field_type(): void {
        $captured_data = null;
        $this->wpdb->shouldReceive('update')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::update(5, [
            'field_type' => 'bogus_type',
        ]);

        $this->assertSame('text', $captured_data['field_type']);
    }

    public function test_update_encodes_array_options_as_json(): void {
        $captured_data = null;
        $this->wpdb->shouldReceive('update')->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $rules = ['min_length' => 5, 'max_length' => 100];
        CustomFieldRepository::update(5, [
            'validation_rules' => $rules,
        ]);

        $this->assertSame(json_encode($rules), $captured_data['validation_rules']);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('delete')->once()
            ->with('wp_ffc_custom_fields', ['id' => 5], ['%d'])
            ->andReturn(1);

        $result = CustomFieldRepository::delete(5);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(false);

        $result = CustomFieldRepository::delete(5);

        $this->assertFalse($result);
    }

    public function test_delete_clears_cache(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        $cache_deleted = false;
        Functions\when('wp_cache_delete')->alias(function($key, $group) use (&$cache_deleted) {
            if ($key === 'id_5' && $group === 'ffc_custom_fields') {
                $cache_deleted = true;
            }
            return true;
        });

        CustomFieldRepository::delete(5);

        $this->assertTrue($cache_deleted);
    }

    // ==================================================================
    // count_by_audience()
    // ==================================================================

    public function test_count_by_audience_returns_count_active_only(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = CustomFieldRepository::count_by_audience(10);

        $this->assertSame(5, $result);
    }

    public function test_count_by_audience_returns_count_all(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('8');

        $result = CustomFieldRepository::count_by_audience(10, false);

        $this->assertSame(8, $result);
    }

    public function test_count_by_audience_returns_zero_when_no_fields(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $result = CustomFieldRepository::count_by_audience(10);

        $this->assertSame(0, $result);
    }

    public function test_count_by_audience_active_only_includes_active_clause(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_query) {
            $captured_query = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        CustomFieldRepository::count_by_audience(10, true);

        $this->assertStringContainsString('is_active = 1', $captured_query);
    }

    public function test_count_by_audience_not_active_only_excludes_active_clause(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function() use (&$captured_query) {
            $captured_query = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        CustomFieldRepository::count_by_audience(10, false);

        $this->assertStringNotContainsString('is_active = 1', $captured_query);
    }

    // ==================================================================
    // get_user_data()
    // ==================================================================

    public function test_get_user_data_returns_array_from_meta(): void {
        $meta_data = ['field_1' => 'value1', 'field_2' => 'value2'];

        Functions\when('get_user_meta')->justReturn($meta_data);

        $result = CustomFieldRepository::get_user_data(42);

        $this->assertSame($meta_data, $result);
    }

    public function test_get_user_data_returns_empty_array_when_no_meta(): void {
        Functions\when('get_user_meta')->justReturn('');

        $result = CustomFieldRepository::get_user_data(42);

        $this->assertSame([], $result);
    }

    public function test_get_user_data_returns_empty_array_when_meta_is_not_array(): void {
        Functions\when('get_user_meta')->justReturn('not_an_array');

        $result = CustomFieldRepository::get_user_data(42);

        $this->assertSame([], $result);
    }

    public function test_get_user_data_returns_empty_array_when_meta_is_null(): void {
        Functions\when('get_user_meta')->justReturn(null);

        $result = CustomFieldRepository::get_user_data(42);

        $this->assertSame([], $result);
    }

    // ==================================================================
    // save_user_data()
    // ==================================================================

    public function test_save_user_data_merges_with_existing_data(): void {
        $existing = ['field_1' => 'old_value'];

        Functions\when('get_user_meta')->justReturn($existing);

        $captured_args = null;
        Functions\when('update_user_meta')->alias(function() use (&$captured_args) {
            $captured_args = func_get_args();
            return true;
        });

        CustomFieldRepository::save_user_data(42, ['field_2' => 'new_value']);

        $this->assertNotNull($captured_args);
        $this->assertSame(42, $captured_args[0]); // user_id
        $this->assertSame('ffc_custom_fields_data', $captured_args[1]); // meta key
        // Merged data
        $this->assertSame(['field_1' => 'old_value', 'field_2' => 'new_value'], $captured_args[2]);
    }

    public function test_save_user_data_overwrites_existing_keys(): void {
        $existing = ['field_1' => 'old_value'];

        Functions\when('get_user_meta')->justReturn($existing);

        $captured_args = null;
        Functions\when('update_user_meta')->alias(function() use (&$captured_args) {
            $captured_args = func_get_args();
            return true;
        });

        CustomFieldRepository::save_user_data(42, ['field_1' => 'updated_value']);

        $this->assertSame('updated_value', $captured_args[2]['field_1']);
    }

    public function test_save_user_data_returns_true_on_success(): void {
        Functions\when('get_user_meta')->justReturn([]);
        Functions\when('update_user_meta')->justReturn(true);

        $result = CustomFieldRepository::save_user_data(42, ['field_1' => 'val']);

        $this->assertTrue($result);
    }

    public function test_save_user_data_returns_false_on_failure(): void {
        Functions\when('get_user_meta')->justReturn([]);
        Functions\when('update_user_meta')->justReturn(false);

        $result = CustomFieldRepository::save_user_data(42, ['field_1' => 'val']);

        $this->assertFalse($result);
    }

    // ==================================================================
    // get_all_for_user()
    // ==================================================================

    public function test_get_all_for_user_returns_empty_when_no_audiences(): void {
        // AudienceRepository::get_user_audiences returns empty
        $this->wpdb->shouldReceive('get_results')->andReturn([]);

        $result = CustomFieldRepository::get_all_for_user(42);

        $this->assertSame([], $result);
    }

    public function test_get_all_for_user_returns_fields_from_user_audiences(): void {
        // User belongs to audience 10, which is a root audience
        $audience = $this->make_audience(10, 'Audience A', null);
        $field = $this->make_field(['id' => 1, 'audience_id' => 10]);

        // get_user_audiences: returns audience list via get_results
        $this->wpdb->shouldReceive('get_results')
            ->andReturn(
                [$audience],  // get_user_audiences
                [$field]      // get_by_audience (inside get_by_audience_with_parents)
            );

        // AudienceRepository::get_by_id(10) via get_row
        $this->wpdb->shouldReceive('get_row')->andReturn($audience);

        $result = CustomFieldRepository::get_all_for_user(42);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]->id);
    }

    public function test_get_all_for_user_deduplicates_fields_from_sibling_audiences(): void {
        // User belongs to two sibling audiences (20, 30) sharing parent (10)
        $parent_audience  = $this->make_audience(10, 'Parent', null);
        $sibling_a        = $this->make_audience(20, 'Sibling A', 10);
        $sibling_b        = $this->make_audience(30, 'Sibling B', 10);

        $parent_field = $this->make_field(['id' => 100, 'audience_id' => 10]);
        $sibling_a_field = $this->make_field(['id' => 200, 'audience_id' => 20]);
        $sibling_b_field = $this->make_field(['id' => 300, 'audience_id' => 30]);

        // get_user_audiences returns both siblings
        $this->wpdb->shouldReceive('get_results')
            ->andReturn(
                [$sibling_a, $sibling_b],   // get_user_audiences
                [$parent_field],            // get_by_audience(10) for sibling_a chain
                [$sibling_a_field],         // get_by_audience(20) for sibling_a chain
                [$parent_field],            // get_by_audience(10) for sibling_b chain
                [$sibling_b_field]          // get_by_audience(30) for sibling_b chain
            );

        // get_by_id calls: sibling_a(20)->parent(10), sibling_b(30)->parent(10)
        $this->wpdb->shouldReceive('get_row')
            ->andReturn(
                $sibling_a,        // get_by_id(20)
                $parent_audience,  // get_by_id(10) - parent of sibling_a
                $sibling_b,        // get_by_id(30)
                $parent_audience   // get_by_id(10) - parent of sibling_b
            );

        $result = CustomFieldRepository::get_all_for_user(42);

        // Field 100 (parent) should only appear once, plus 200 and 300
        $ids = array_map(function($f) { return (int) $f->id; }, $result);
        $this->assertCount(3, $result);
        $this->assertContains(100, $ids);
        $this->assertContains(200, $ids);
        $this->assertContains(300, $ids);
        // Ensure no duplicates
        $this->assertCount(3, array_unique($ids));
    }

    // ==================================================================
    // deactivate() / reactivate()
    // ==================================================================

    public function test_deactivate_sets_is_active_to_zero(): void {
        $captured_data = null;
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::deactivate(5);

        $this->assertSame(0, $captured_data['is_active']);
    }

    public function test_reactivate_sets_is_active_to_one(): void {
        $captured_data = null;
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        CustomFieldRepository::reactivate(5);

        $this->assertSame(1, $captured_data['is_active']);
    }

    // ==================================================================
    // reorder()
    // ==================================================================

    public function test_reorder_updates_sort_order_for_each_field(): void {
        $call_count = 0;
        $this->wpdb->shouldReceive('update')
            ->andReturnUsing(function($table, $data, $where) use (&$call_count) {
                $call_count++;
                return 1;
            });

        $result = CustomFieldRepository::reorder([10, 20, 30]);

        $this->assertTrue($result);
        $this->assertSame(3, $call_count);
    }

    // ==================================================================
    // Constants
    // ==================================================================

    public function test_field_types_constant_contains_expected_types(): void {
        $this->assertContains('text', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('number', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('date', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('select', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('dependent_select', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('checkbox', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('textarea', CustomFieldRepository::FIELD_TYPES);
        $this->assertContains('working_hours', CustomFieldRepository::FIELD_TYPES);
    }

    public function test_validation_formats_constant_contains_expected_formats(): void {
        $this->assertContains('cpf', CustomFieldRepository::VALIDATION_FORMATS);
        $this->assertContains('email', CustomFieldRepository::VALIDATION_FORMATS);
        $this->assertContains('phone', CustomFieldRepository::VALIDATION_FORMATS);
        $this->assertContains('custom_regex', CustomFieldRepository::VALIDATION_FORMATS);
    }

    public function test_user_meta_key_constant(): void {
        $this->assertSame('ffc_custom_fields_data', CustomFieldRepository::USER_META_KEY);
    }

    // ==================================================================
    // get_user_field_value() / set_user_field_value()
    // ==================================================================

    public function test_get_user_field_value_returns_value_when_exists(): void {
        Functions\when('get_user_meta')->justReturn(['field_5' => 'hello']);

        $result = CustomFieldRepository::get_user_field_value(42, 5);

        $this->assertSame('hello', $result);
    }

    public function test_get_user_field_value_returns_null_when_not_set(): void {
        Functions\when('get_user_meta')->justReturn(['field_1' => 'something']);

        $result = CustomFieldRepository::get_user_field_value(42, 999);

        $this->assertNull($result);
    }

    public function test_set_user_field_value_saves_correct_key(): void {
        Functions\when('get_user_meta')->justReturn([]);

        $captured_data = null;
        Functions\when('update_user_meta')->alias(function() use (&$captured_data) {
            $captured_data = func_get_args();
            return true;
        });

        CustomFieldRepository::set_user_field_value(42, 7, 'my_value');

        $this->assertArrayHasKey('field_7', $captured_data[2]);
        $this->assertSame('my_value', $captured_data[2]['field_7']);
    }

    // ==================================================================
    // get_field_choices() / get_validation_rules()
    // ==================================================================

    public function test_get_field_choices_parses_json_string(): void {
        $field = $this->make_field([
            'field_options' => '{"choices":["Red","Green","Blue"]}',
        ]);

        $choices = CustomFieldRepository::get_field_choices($field);

        $this->assertSame(['Red', 'Green', 'Blue'], $choices);
    }

    public function test_get_field_choices_handles_array_options(): void {
        $field = $this->make_field([
            'field_options' => ['choices' => ['A', 'B']],
        ]);

        $choices = CustomFieldRepository::get_field_choices($field);

        $this->assertSame(['A', 'B'], $choices);
    }

    public function test_get_field_choices_returns_empty_when_no_choices(): void {
        $field = $this->make_field(['field_options' => null]);

        $choices = CustomFieldRepository::get_field_choices($field);

        $this->assertSame([], $choices);
    }

    public function test_get_validation_rules_parses_json_string(): void {
        $field = $this->make_field([
            'validation_rules' => '{"min_length":3,"max_length":50}',
        ]);

        $rules = CustomFieldRepository::get_validation_rules($field);

        $this->assertSame(3, $rules['min_length']);
        $this->assertSame(50, $rules['max_length']);
    }

    public function test_get_validation_rules_returns_empty_when_null(): void {
        $field = $this->make_field(['validation_rules' => null]);

        $rules = CustomFieldRepository::get_validation_rules($field);

        $this->assertSame([], $rules);
    }

    public function test_get_validation_rules_handles_array_rules(): void {
        $field = $this->make_field([
            'validation_rules' => ['format' => 'email'],
        ]);

        $rules = CustomFieldRepository::get_validation_rules($field);

        $this->assertSame('email', $rules['format']);
    }

    // ==================================================================
    // get_dependent_choices()
    // ==================================================================

    public function test_get_dependent_choices_returns_groups(): void {
        $field = $this->make_field([
            'field_options' => '{"groups":{"Dept A":["Team 1","Team 2"],"Dept B":["Team 3"]}}',
        ]);

        $groups = CustomFieldRepository::get_dependent_choices($field);

        $this->assertArrayHasKey('Dept A', $groups);
        $this->assertSame(['Team 1', 'Team 2'], $groups['Dept A']);
        $this->assertSame(['Team 3'], $groups['Dept B']);
    }

    public function test_get_dependent_choices_returns_empty_when_no_groups(): void {
        $field = $this->make_field(['field_options' => '{}']);

        $groups = CustomFieldRepository::get_dependent_choices($field);

        $this->assertSame([], $groups);
    }
}
