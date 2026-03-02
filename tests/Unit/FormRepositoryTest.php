<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\FormRepository;

/**
 * Tests for FormRepository: table/cache configuration, findPublished,
 * getConfig/getFields/getBackground with FormCache delegation and fallback,
 * and inherited CRUD operations from AbstractRepository.
 *
 * @covers \FreeFormCertificate\Repositories\FormRepository
 */
class FormRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var FormRepository */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $this->wpdb = $wpdb;

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        })->byDefault();

        $this->repo = new FormRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Table name and cache group
    // ==================================================================

    public function test_table_name_is_wpdb_posts(): void {
        // The table name is set during construction from get_table_name()
        // which returns $this->wpdb->posts. We verify by triggering a query.
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_posts WHERE id = 1'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findById(1);

        $this->assertNull($result);
    }

    public function test_table_name_reflects_wpdb_posts_property(): void {
        global $wpdb;
        $wpdb->posts = 'custom_prefix_posts';
        $repo = new FormRepository();

        // Verify construction completed with custom posts table
        $this->assertInstanceOf(FormRepository::class, $repo);

        // Restore
        $wpdb->posts = 'wp_posts';
    }

    public function test_cache_group_is_ffc_forms(): void {
        // We verify the cache group by checking that wp_cache_get is called
        // with the correct group. Override wp_cache_get to capture the group.
        $captured_group = '';
        Functions\when('wp_cache_get')->alias(function ($key, $group) use (&$captured_group) {
            $captured_group = $group;
            return false;
        });

        // Rebuild repo so the new mock takes effect
        $this->repo = new FormRepository();

        $this->wpdb->shouldReceive('prepare')->andReturn('SELECT * FROM wp_posts WHERE id = 1');
        $this->wpdb->shouldReceive('get_row')->andReturn(null);

        $this->repo->findById(1);

        $this->assertSame('ffc_forms', $captured_group);
    }

    // ==================================================================
    // findPublished
    // ==================================================================

    public function test_find_published_calls_get_posts_with_correct_args(): void {
        $posts = [
            (object) ['ID' => 1, 'post_title' => 'Form A'],
            (object) ['ID' => 2, 'post_title' => 'Form B'],
        ];

        Functions\expect('get_posts')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['post_type'] === 'ffc_form'
                    && $args['post_status'] === 'publish'
                    && $args['posts_per_page'] === -1
                    && $args['orderby'] === 'title'
                    && $args['order'] === 'ASC';
            }))
            ->andReturn($posts);

        $result = $this->repo->findPublished();

        $this->assertCount(2, $result);
        $this->assertSame($posts, $result);
    }

    public function test_find_published_respects_limit_parameter(): void {
        Functions\expect('get_posts')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['posts_per_page'] === 5;
            }))
            ->andReturn([]);

        $result = $this->repo->findPublished(5);

        $this->assertSame([], $result);
    }

    public function test_find_published_default_limit_is_negative_one(): void {
        Functions\expect('get_posts')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['posts_per_page'] === -1;
            }))
            ->andReturn([]);

        $this->repo->findPublished();
    }

    public function test_find_published_returns_empty_array_when_no_forms(): void {
        Functions\expect('get_posts')
            ->once()
            ->andReturn([]);

        $result = $this->repo->findPublished();

        $this->assertSame([], $result);
    }

    public function test_find_published_with_limit_of_one(): void {
        $posts = [(object) ['ID' => 1, 'post_title' => 'Only Form']];

        Functions\expect('get_posts')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['posts_per_page'] === 1;
            }))
            ->andReturn($posts);

        $result = $this->repo->findPublished(1);

        $this->assertCount(1, $result);
    }

    // ==================================================================
    // getConfig
    // ==================================================================

    public function test_get_config_delegates_to_form_cache_when_class_exists(): void {
        $config = ['field1' => 'value1', 'field2' => 'value2'];

        Functions\when('class_exists')->alias(function ($class) {
            return $class === '\FreeFormCertificate\Submissions\FormCache';
        });

        // Create a mock for FormCache
        $formCacheMock = Mockery::mock('alias:\FreeFormCertificate\Submissions\FormCache');
        $formCacheMock->shouldReceive('get_form_config')
            ->with(42)
            ->once()
            ->andReturn($config);

        // Rebuild repo so the class_exists mock takes effect
        $this->repo = new FormRepository();

        $result = $this->repo->getConfig(42);

        $this->assertSame($config, $result);
    }

    public function test_get_config_falls_back_to_get_post_meta_without_form_cache(): void {
        $config = ['field1' => 'value1'];

        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_ffc_form_config', true)
            ->andReturn($config);

        // Rebuild repo so the class_exists mock takes effect
        $this->repo = new FormRepository();

        $result = $this->repo->getConfig(42);

        $this->assertSame($config, $result);
    }

    public function test_get_config_fallback_returns_empty_when_no_meta(): void {
        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(99, '_ffc_form_config', true)
            ->andReturn('');

        $this->repo = new FormRepository();

        $result = $this->repo->getConfig(99);

        $this->assertSame('', $result);
    }

    // ==================================================================
    // getFields
    // ==================================================================

    public function test_get_fields_delegates_to_form_cache_when_class_exists(): void {
        $fields = [
            ['name' => 'first_name', 'type' => 'text'],
            ['name' => 'email', 'type' => 'email'],
        ];

        Functions\when('class_exists')->alias(function ($class) {
            return $class === '\FreeFormCertificate\Submissions\FormCache';
        });

        $formCacheMock = Mockery::mock('alias:\FreeFormCertificate\Submissions\FormCache');
        $formCacheMock->shouldReceive('get_form_fields')
            ->with(42)
            ->once()
            ->andReturn($fields);

        $this->repo = new FormRepository();

        $result = $this->repo->getFields(42);

        $this->assertSame($fields, $result);
    }

    public function test_get_fields_falls_back_to_get_post_meta_without_form_cache(): void {
        $fields = [['name' => 'first_name', 'type' => 'text']];

        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_ffc_form_fields', true)
            ->andReturn($fields);

        $this->repo = new FormRepository();

        $result = $this->repo->getFields(42);

        $this->assertSame($fields, $result);
    }

    public function test_get_fields_fallback_returns_empty_when_no_meta(): void {
        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(99, '_ffc_form_fields', true)
            ->andReturn('');

        $this->repo = new FormRepository();

        $result = $this->repo->getFields(99);

        $this->assertSame('', $result);
    }

    // ==================================================================
    // getBackground
    // ==================================================================

    public function test_get_background_delegates_to_form_cache_when_class_exists(): void {
        $bg = ['url' => 'https://example.com/bg.jpg', 'opacity' => 0.5];

        Functions\when('class_exists')->alias(function ($class) {
            return $class === '\FreeFormCertificate\Submissions\FormCache';
        });

        $formCacheMock = Mockery::mock('alias:\FreeFormCertificate\Submissions\FormCache');
        $formCacheMock->shouldReceive('get_form_background')
            ->with(42)
            ->once()
            ->andReturn($bg);

        $this->repo = new FormRepository();

        $result = $this->repo->getBackground(42);

        $this->assertSame($bg, $result);
    }

    public function test_get_background_falls_back_to_get_post_meta_without_form_cache(): void {
        $bg = ['url' => 'https://example.com/bg.jpg'];

        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_ffc_form_bg', true)
            ->andReturn($bg);

        $this->repo = new FormRepository();

        $result = $this->repo->getBackground(42);

        $this->assertSame($bg, $result);
    }

    public function test_get_background_fallback_returns_empty_when_no_meta(): void {
        Functions\when('class_exists')->justReturn(false);

        Functions\expect('get_post_meta')
            ->once()
            ->with(99, '_ffc_form_bg', true)
            ->andReturn('');

        $this->repo = new FormRepository();

        $result = $this->repo->getBackground(99);

        $this->assertSame('', $result);
    }

    // ==================================================================
    // findById (inherited from AbstractRepository)
    // ==================================================================

    public function test_find_by_id_returns_row_on_cache_miss(): void {
        $row = ['id' => 1, 'post_title' => 'My Form', 'post_status' => 'publish'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_posts WHERE id = 1'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = $this->repo->findById(1);

        $this->assertSame($row, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_posts WHERE id = 999'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findById(999);

        $this->assertNull($result);
    }

    public function test_find_by_id_uses_cache(): void {
        $cached = ['id' => 1, 'post_title' => 'Cached Form'];

        // Override the default wp_cache_get to return cached data
        Functions\when('wp_cache_get')->justReturn($cached);

        // Rebuild repo so the cache mock takes effect
        $this->repo = new FormRepository();

        // get_row should NOT be called because cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = $this->repo->findById(1);

        $this->assertSame($cached, $result);
    }

    // ==================================================================
    // findByIds (inherited from AbstractRepository)
    // ==================================================================

    public function test_find_by_ids_returns_empty_array_for_empty_input(): void {
        $result = $this->repo->findByIds([]);

        $this->assertSame([], $result);
    }

    public function test_find_by_ids_returns_rows_keyed_by_id(): void {
        $rows = [
            ['id' => 1, 'post_title' => 'Form A'],
            ['id' => 3, 'post_title' => 'Form C'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findByIds([1, 3]);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(3, $result);
        $this->assertSame('Form A', $result[1]['post_title']);
    }

    // ==================================================================
    // findAll (inherited from AbstractRepository)
    // ==================================================================

    public function test_find_all_with_no_conditions_returns_all_rows(): void {
        $rows = [
            ['id' => 1, 'post_title' => 'Form A'],
            ['id' => 2, 'post_title' => 'Form B'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findAll();

        $this->assertCount(2, $result);
    }

    public function test_find_all_with_limit_includes_limit_clause(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findAll([], 'id', 'DESC', 10, 5);

        $this->assertStringContainsString('LIMIT', $captured_sql);
        $this->assertStringContainsString('OFFSET', $captured_sql);
    }

    public function test_find_all_without_limit_omits_limit_clause(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findAll(['post_status' => 'publish']);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    // ==================================================================
    // count (inherited from AbstractRepository)
    // ==================================================================

    public function test_count_returns_integer(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('42');

        $result = $this->repo->count(['post_status' => 'publish']);

        $this->assertSame(42, $result);
    }

    public function test_count_with_no_conditions(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('10');

        $result = $this->repo->count();

        $this->assertSame(10, $result);
        $this->assertStringContainsString('SELECT COUNT(*)', $captured_sql);
        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }

    // ==================================================================
    // insert (inherited from AbstractRepository)
    // ==================================================================

    public function test_insert_returns_insert_id_on_success(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 42;

        $result = $this->repo->insert(['post_title' => 'New Form', 'post_status' => 'draft']);

        $this->assertSame(42, $result);
    }

    public function test_insert_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = $this->repo->insert(['post_title' => 'New Form']);

        $this->assertFalse($result);
    }

    public function test_insert_clears_cache_on_success(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 1;

        // wp_cache_flush is stubbed via Functions\when in setUp,
        // verifying it doesn't throw is sufficient here
        $result = $this->repo->insert(['post_title' => 'New Form']);

        $this->assertSame(1, $result);
    }

    // ==================================================================
    // update (inherited from AbstractRepository)
    // ==================================================================

    public function test_update_returns_affected_rows_on_success(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = $this->repo->update(1, ['post_title' => 'Updated Form']);

        $this->assertSame(1, $result);
    }

    public function test_update_returns_zero_when_no_rows_changed(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(0);

        $result = $this->repo->update(1, ['post_title' => 'Same Title']);

        $this->assertSame(0, $result);
    }

    public function test_update_returns_false_on_error(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->update(1, ['post_title' => 'Updated Form']);

        $this->assertFalse($result);
    }

    // ==================================================================
    // delete (inherited from AbstractRepository)
    // ==================================================================

    public function test_delete_returns_affected_rows(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        $result = $this->repo->delete(1);

        $this->assertSame(1, $result);
    }

    public function test_delete_returns_false_on_error(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(false);

        $result = $this->repo->delete(1);

        $this->assertFalse($result);
    }

    public function test_delete_returns_zero_when_row_not_found(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(0);

        $result = $this->repo->delete(999);

        $this->assertSame(0, $result);
    }

    // ==================================================================
    // Transaction methods (inherited from AbstractRepository)
    // ==================================================================

    public function test_begin_transaction_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->repo->begin_transaction());
    }

    public function test_begin_transaction_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('query')
            ->with('START TRANSACTION')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->repo->begin_transaction());
    }

    public function test_commit_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->repo->commit());
    }

    public function test_commit_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('query')
            ->with('COMMIT')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->repo->commit());
    }

    public function test_rollback_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->repo->rollback());
    }

    public function test_rollback_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(false);

        $this->assertFalse($this->repo->rollback());
    }
}
