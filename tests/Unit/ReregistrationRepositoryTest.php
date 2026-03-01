<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationRepository;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository;
use FreeFormCertificate\Audience\AudienceRepository;

/**
 * Tests for ReregistrationRepository: table names, CRUD, audience junction,
 * filtering, counting, expiration, affected users, and status labels.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationRepository
 */
class ReregistrationRepositoryTest extends TestCase {

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
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('current_time')->justReturn('2026-03-01 12:00:00');

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        })->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_table_name()
    // ==================================================================

    public function test_get_table_name_returns_correct_table(): void {
        $this->assertSame('wp_ffc_reregistrations', ReregistrationRepository::get_table_name());
    }

    // ==================================================================
    // get_audiences_table_name()
    // ==================================================================

    public function test_get_audiences_table_name_returns_correct_junction_table(): void {
        $this->assertSame('wp_ffc_reregistration_audiences', ReregistrationRepository::get_audiences_table_name());
    }

    // ==================================================================
    // get_status_labels()
    // ==================================================================

    public function test_get_status_labels_returns_all_statuses(): void {
        $labels = ReregistrationRepository::get_status_labels();

        $this->assertArrayHasKey('draft', $labels);
        $this->assertArrayHasKey('active', $labels);
        $this->assertArrayHasKey('expired', $labels);
        $this->assertArrayHasKey('closed', $labels);
        $this->assertCount(4, $labels);
    }

    public function test_get_status_labels_returns_translated_labels(): void {
        $labels = ReregistrationRepository::get_status_labels();

        $this->assertSame('Draft', $labels['draft']);
        $this->assertSame('Active', $labels['active']);
        $this->assertSame('Expired', $labels['expired']);
        $this->assertSame('Closed', $labels['closed']);
    }

    // ==================================================================
    // get_status_label()
    // ==================================================================

    public function test_get_status_label_returns_label_for_known_status(): void {
        $this->assertSame('Active', ReregistrationRepository::get_status_label('active'));
    }

    public function test_get_status_label_returns_key_for_unknown_status(): void {
        $this->assertSame('unknown', ReregistrationRepository::get_status_label('unknown'));
    }

    public function test_get_status_label_returns_draft_label(): void {
        $this->assertSame('Draft', ReregistrationRepository::get_status_label('draft'));
    }

    public function test_get_status_label_returns_expired_label(): void {
        $this->assertSame('Expired', ReregistrationRepository::get_status_label('expired'));
    }

    public function test_get_status_label_returns_closed_label(): void {
        $this->assertSame('Closed', ReregistrationRepository::get_status_label('closed'));
    }

    // ==================================================================
    // get_audience_ids()
    // ==================================================================

    public function test_get_audience_ids_returns_integer_array(): void {
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(array('1', '2', '3'));

        $result = ReregistrationRepository::get_audience_ids(10);

        $this->assertSame(array(1, 2, 3), $result);
    }

    public function test_get_audience_ids_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(array());

        $result = ReregistrationRepository::get_audience_ids(10);

        $this->assertSame(array(), $result);
    }

    public function test_get_audience_ids_calls_prepare_with_correct_sql(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(array());

        ReregistrationRepository::get_audience_ids(10);

        $this->assertStringContainsString('SELECT audience_id FROM %i WHERE reregistration_id = %d', $captured_sql);
    }

    // ==================================================================
    // set_audience_ids()
    // ==================================================================

    public function test_set_audience_ids_deletes_existing_then_inserts_new(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_audiences', array('reregistration_id' => 5), array('%d'))
            ->andReturn(1);

        $this->wpdb->shouldReceive('insert')
            ->times(3)
            ->andReturn(true);

        ReregistrationRepository::set_audience_ids(5, array(10, 20, 30));
    }

    public function test_set_audience_ids_filters_out_zero_and_duplicates(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        // 0 is filtered out, duplicates removed: should only insert for 10 and 20
        $this->wpdb->shouldReceive('insert')
            ->times(2)
            ->andReturn(true);

        ReregistrationRepository::set_audience_ids(5, array(10, 20, 0, 10));
    }

    public function test_set_audience_ids_with_empty_array_only_deletes(): void {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_audiences', array('reregistration_id' => 5), array('%d'))
            ->andReturn(0);

        $this->wpdb->shouldNotReceive('insert');

        ReregistrationRepository::set_audience_ids(5, array());
    }

    public function test_set_audience_ids_inserts_correct_data(): void {
        $this->wpdb->shouldReceive('delete')->once()->andReturn(1);

        $inserted_rows = array();
        $this->wpdb->shouldReceive('insert')
            ->andReturnUsing(function($table, $data) use (&$inserted_rows) {
                $inserted_rows[] = $data;
                return true;
            });

        ReregistrationRepository::set_audience_ids(5, array(10, 20));

        $this->assertCount(2, $inserted_rows);
        $this->assertSame(5, $inserted_rows[0]['reregistration_id']);
        $this->assertSame(10, $inserted_rows[0]['audience_id']);
        $this->assertSame(5, $inserted_rows[1]['reregistration_id']);
        $this->assertSame(20, $inserted_rows[1]['audience_id']);
    }

    // ==================================================================
    // get_audiences()
    // ==================================================================

    public function test_get_audiences_returns_audience_objects(): void {
        $audiences = array(
            (object) array('id' => 1, 'name' => 'Group A', 'color' => '#ff0000'),
            (object) array('id' => 2, 'name' => 'Group B', 'color' => '#00ff00'),
        );

        $this->wpdb->shouldReceive('get_results')->once()->andReturn($audiences);

        $result = ReregistrationRepository::get_audiences(10);

        $this->assertCount(2, $result);
        $this->assertSame('Group A', $result[0]->name);
        $this->assertSame('#00ff00', $result[1]->color);
    }

    public function test_get_audiences_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        $result = ReregistrationRepository::get_audiences(10);

        $this->assertSame(array(), $result);
    }

    public function test_get_audiences_queries_with_join(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_audiences(10);

        $this->assertStringContainsString('SELECT a.id, a.name, a.color', $captured_sql);
        $this->assertStringContainsString('JOIN %i a ON ra.audience_id = a.id', $captured_sql);
        $this->assertStringContainsString('ORDER BY a.name ASC', $captured_sql);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_row_from_database(): void {
        $row = (object) array('id' => 1, 'title' => 'Test Campaign', 'status' => 'active');

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReregistrationRepository::get_by_id(1);

        $this->assertSame('Test Campaign', $result->title);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = ReregistrationRepository::get_by_id(999);

        $this->assertNull($result);
    }

    public function test_get_by_id_returns_cached_result(): void {
        $cached = (object) array('id' => 1, 'title' => 'Cached Campaign');

        Functions\when('wp_cache_get')->alias(function($key) use ($cached) {
            return $key === 'id_1' ? $cached : false;
        });

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive('get_row');

        $result = ReregistrationRepository::get_by_id(1);

        $this->assertSame('Cached Campaign', $result->title);
    }

    public function test_get_by_id_does_not_cache_null_result(): void {
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        // Track cache_set calls
        $cache_set_called = false;
        Functions\when('wp_cache_set')->alias(function() use (&$cache_set_called) {
            $cache_set_called = true;
            return true;
        });

        $result = ReregistrationRepository::get_by_id(999);

        $this->assertNull($result);
        $this->assertFalse($cache_set_called);
    }

    public function test_get_by_id_caches_found_result(): void {
        $row = (object) array('id' => 5, 'title' => 'New Campaign');

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $cache_set_called = false;
        $cache_set_key = null;
        Functions\when('wp_cache_set')->alias(function($key) use (&$cache_set_called, &$cache_set_key) {
            $cache_set_called = true;
            $cache_set_key = $key;
            return true;
        });

        ReregistrationRepository::get_by_id(5);

        $this->assertTrue($cache_set_called);
        $this->assertSame('id_5', $cache_set_key);
    }

    // ==================================================================
    // get_all()
    // ==================================================================

    public function test_get_all_returns_all_campaigns(): void {
        $rows = array(
            (object) array('id' => 1, 'title' => 'Campaign 1'),
            (object) array('id' => 2, 'title' => 'Campaign 2'),
        );

        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = ReregistrationRepository::get_all();

        $this->assertCount(2, $result);
        $this->assertSame('Campaign 1', $result[0]->title);
    }

    public function test_get_all_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('status' => 'active'));

        $this->assertStringContainsString('r.status = %s', $captured_sql);
    }

    public function test_get_all_with_audience_id_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('audience_id' => 5));

        $this->assertStringContainsString('JOIN %i ra_filter ON r.id = ra_filter.reregistration_id', $captured_sql);
    }

    public function test_get_all_with_search_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function($v) {
            return $v;
        });
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('search' => 'test'));

        $this->assertStringContainsString('r.title LIKE %s', $captured_sql);
    }

    public function test_get_all_with_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('limit' => 10, 'offset' => 20));

        $this->assertStringContainsString('LIMIT 10 OFFSET 20', $captured_sql);
    }

    public function test_get_all_invalid_orderby_falls_back_to_created_at(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('orderby' => 'malicious_column'));

        $this->assertStringContainsString('ORDER BY r.created_at', $captured_sql);
    }

    public function test_get_all_valid_orderby_is_used(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('orderby' => 'title', 'order' => 'ASC'));

        $this->assertStringContainsString('ORDER BY r.title ASC', $captured_sql);
    }

    public function test_get_all_order_defaults_to_desc(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all();

        $this->assertStringContainsString('DESC', $captured_sql);
    }

    public function test_get_all_no_limit_by_default(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all();

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_get_all_combined_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function($v) {
            return $v;
        });
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array(
            'audience_id' => 3,
            'status'      => 'active',
            'search'      => 'test',
        ));

        $this->assertStringContainsString('JOIN %i ra_filter', $captured_sql);
        $this->assertStringContainsString('r.status = %s', $captured_sql);
        $this->assertStringContainsString('r.title LIKE %s', $captured_sql);
    }

    public function test_get_all_uses_distinct_select(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all();

        $this->assertStringContainsString('SELECT DISTINCT r.*', $captured_sql);
    }

    public function test_get_all_with_start_date_orderby(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('orderby' => 'start_date'));

        $this->assertStringContainsString('ORDER BY r.start_date', $captured_sql);
    }

    public function test_get_all_with_end_date_orderby(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('orderby' => 'end_date'));

        $this->assertStringContainsString('ORDER BY r.end_date', $captured_sql);
    }

    public function test_get_all_with_status_orderby(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_all(array('orderby' => 'status'));

        $this->assertStringContainsString('ORDER BY r.status', $captured_sql);
    }

    // ==================================================================
    // count()
    // ==================================================================

    public function test_count_returns_integer(): void {
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = ReregistrationRepository::count();

        $this->assertSame(5, $result);
    }

    public function test_count_with_status_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('3');

        $result = ReregistrationRepository::count(array('status' => 'active'));

        $this->assertSame(3, $result);
        $this->assertStringContainsString('r.status = %s', $captured_sql);
    }

    public function test_count_with_audience_id_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('2');

        $result = ReregistrationRepository::count(array('audience_id' => 7));

        $this->assertSame(2, $result);
        $this->assertStringContainsString('JOIN %i ra_filter', $captured_sql);
    }

    public function test_count_without_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('10');

        $result = ReregistrationRepository::count();

        $this->assertSame(10, $result);
        $this->assertStringContainsString('COUNT(DISTINCT r.id)', $captured_sql);
        $this->assertStringNotContainsString('WHERE', $captured_sql);
    }

    public function test_count_returns_zero_when_null(): void {
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $result = ReregistrationRepository::count();

        $this->assertSame(0, $result);
    }

    public function test_count_with_combined_filters(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $result = ReregistrationRepository::count(array(
            'audience_id' => 3,
            'status'      => 'active',
        ));

        $this->assertSame(1, $result);
        $this->assertStringContainsString('JOIN %i ra_filter', $captured_sql);
        $this->assertStringContainsString('r.status = %s', $captured_sql);
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_row_and_returns_insert_id(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    return $data['title'] === 'Test Campaign'
                        && $data['status'] === 'draft'
                        && $data['created_by'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(true);

        $this->wpdb->insert_id = 42;

        $result = ReregistrationRepository::create(array('title' => 'Test Campaign'));

        $this->assertSame(42, $result);
    }

    public function test_create_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = ReregistrationRepository::create(array('title' => 'Failed'));

        $this->assertFalse($result);
    }

    public function test_create_uses_default_values(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    return $data['auto_approve'] === 0
                        && $data['email_invitation_enabled'] === 0
                        && $data['email_reminder_enabled'] === 0
                        && $data['email_confirmation_enabled'] === 0
                        && $data['reminder_days'] === 7
                        && $data['status'] === 'draft'
                        && $data['created_by'] === 1;
                }),
                Mockery::type('array')
            )
            ->andReturn(true);

        $this->wpdb->insert_id = 1;

        ReregistrationRepository::create(array());
    }

    public function test_create_sanitizes_title(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    // sanitize_text_field is aliased to trim
                    return $data['title'] === 'Trimmed Title';
                }),
                Mockery::type('array')
            )
            ->andReturn(true);

        $this->wpdb->insert_id = 1;

        ReregistrationRepository::create(array('title' => '  Trimmed Title  '));
    }

    public function test_create_with_all_fields(): void {
        $data = array(
            'title'                      => 'Full Campaign',
            'start_date'                 => '2026-01-01',
            'end_date'                   => '2026-12-31',
            'auto_approve'               => 1,
            'email_invitation_enabled'   => 1,
            'email_reminder_enabled'     => 1,
            'email_confirmation_enabled' => 1,
            'reminder_days'              => 14,
            'status'                     => 'active',
            'created_by'                 => 5,
        );

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($insert_data) {
                    return $insert_data['title'] === 'Full Campaign'
                        && $insert_data['start_date'] === '2026-01-01'
                        && $insert_data['end_date'] === '2026-12-31'
                        && $insert_data['auto_approve'] === 1
                        && $insert_data['email_invitation_enabled'] === 1
                        && $insert_data['email_reminder_enabled'] === 1
                        && $insert_data['email_confirmation_enabled'] === 1
                        && $insert_data['reminder_days'] === 14
                        && $insert_data['status'] === 'active'
                        && $insert_data['created_by'] === 5;
                }),
                Mockery::type('array')
            )
            ->andReturn(true);

        $this->wpdb->insert_id = 99;

        $result = ReregistrationRepository::create($data);

        $this->assertSame(99, $result);
    }

    public function test_create_with_correct_format_specifiers(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::type('array'),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d')
            )
            ->andReturn(true);

        $this->wpdb->insert_id = 1;

        ReregistrationRepository::create(array('title' => 'Test'));
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    return $data['title'] === 'Updated Title';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationRepository::update(1, array('title' => 'Updated Title'));

        $this->assertTrue($result);
    }

    public function test_update_returns_true_when_zero_rows_affected(): void {
        // update returns 0 (no rows changed, but no error) => not false => true
        $this->wpdb->shouldReceive('update')->once()->andReturn(0);

        $result = ReregistrationRepository::update(1, array('title' => 'Same Title'));

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = ReregistrationRepository::update(1, array('title' => 'Test'));

        $this->assertFalse($result);
    }

    public function test_update_returns_false_for_empty_data(): void {
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationRepository::update(1, array());

        $this->assertFalse($result);
    }

    public function test_update_strips_protected_fields(): void {
        // id, created_by, created_at should be removed before update
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationRepository::update(1, array(
            'id'         => 999,
            'created_by' => 999,
            'created_at' => '2026-01-01',
        ));

        $this->assertFalse($result);
    }

    public function test_update_ignores_unknown_fields(): void {
        // Fields not in $field_formats should be skipped
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationRepository::update(1, array('unknown_field' => 'value'));

        $this->assertFalse($result);
    }

    public function test_update_clears_cache(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $cache_deleted_key = null;
        Functions\when('wp_cache_delete')->alias(function($key) use (&$cache_deleted_key) {
            $cache_deleted_key = $key;
            return true;
        });

        ReregistrationRepository::update(5, array('status' => 'expired'));

        $this->assertSame('id_5', $cache_deleted_key);
    }

    public function test_update_sanitizes_title(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    return $data['title'] === 'Sanitized';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        ReregistrationRepository::update(1, array('title' => '  Sanitized  '));
    }

    public function test_update_uses_correct_format_for_string_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::type('array'),
                array('id' => 1),
                array('%s'),
                array('%d')
            )
            ->andReturn(1);

        ReregistrationRepository::update(1, array('status' => 'active'));
    }

    public function test_update_uses_correct_format_for_integer_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::type('array'),
                array('id' => 1),
                array('%d'),
                array('%d')
            )
            ->andReturn(1);

        ReregistrationRepository::update(1, array('auto_approve' => 1));
    }

    public function test_update_with_mixed_protected_and_valid_fields(): void {
        // Only valid fields should be passed through
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistrations',
                Mockery::on(function($data) {
                    return count($data) === 1 && $data['title'] === 'Valid';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationRepository::update(1, array(
            'id'         => 999,
            'created_by' => 999,
            'title'      => 'Valid',
        ));

        $this->assertTrue($result);
    }

    // ==================================================================
    // delete()
    // ==================================================================

    public function test_delete_removes_submissions_audience_links_and_campaign(): void {
        // Should delete submissions first
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_submissions', array('reregistration_id' => 10), array('%d'))
            ->andReturn(5);

        // Then audience links
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_audiences', array('reregistration_id' => 10), array('%d'))
            ->andReturn(3);

        // Then the campaign itself
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistrations', array('id' => 10), array('%d'))
            ->andReturn(1);

        $result = ReregistrationRepository::delete(10);

        $this->assertTrue($result);
    }

    public function test_delete_returns_false_on_campaign_delete_failure(): void {
        // Submissions delete
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_submissions', Mockery::any(), Mockery::any())
            ->andReturn(0);

        // Audience links delete
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistration_audiences', Mockery::any(), Mockery::any())
            ->andReturn(0);

        // Campaign delete fails
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_ffc_reregistrations', array('id' => 10), array('%d'))
            ->andReturn(false);

        $result = ReregistrationRepository::delete(10);

        $this->assertFalse($result);
    }

    public function test_delete_clears_cache(): void {
        $this->wpdb->shouldReceive('delete')->times(3)->andReturn(1);

        $cache_deleted_key = null;
        Functions\when('wp_cache_delete')->alias(function($key) use (&$cache_deleted_key) {
            $cache_deleted_key = $key;
            return true;
        });

        ReregistrationRepository::delete(7);

        $this->assertSame('id_7', $cache_deleted_key);
    }

    public function test_delete_calls_delete_in_correct_order(): void {
        $delete_order = array();
        $this->wpdb->shouldReceive('delete')
            ->times(3)
            ->andReturnUsing(function($table) use (&$delete_order) {
                $delete_order[] = $table;
                return 1;
            });

        ReregistrationRepository::delete(1);

        $this->assertSame(
            array(
                'wp_ffc_reregistration_submissions',
                'wp_ffc_reregistration_audiences',
                'wp_ffc_reregistrations',
            ),
            $delete_order
        );
    }

    // ==================================================================
    // get_active_for_audience()
    // ==================================================================

    public function test_get_active_for_audience_returns_active_campaigns(): void {
        $audience = (object) array('id' => 5, 'parent_id' => null);
        $campaigns = array(
            (object) array('id' => 1, 'title' => 'Active Campaign', 'status' => 'active'),
        );

        // Mock AudienceRepository - since the class is already loaded, we need
        // to use a partial mock approach. We mock the wpdb calls that
        // AudienceRepository::get_by_id will make internally.
        // However, get_by_id uses cache first, so we provide the result via cache.
        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($audience) {
            if ($key === 'id_5' && $group === 'ffc_audiences') {
                return $audience;
            }
            return false;
        });

        $this->wpdb->shouldReceive('get_results')->once()->andReturn($campaigns);

        $result = ReregistrationRepository::get_active_for_audience(5);

        $this->assertCount(1, $result);
        $this->assertSame('Active Campaign', $result[0]->title);
    }

    public function test_get_active_for_audience_returns_empty_when_audience_not_found(): void {
        // AudienceRepository::get_by_id will check cache (returns false), then query DB
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = ReregistrationRepository::get_active_for_audience(999);

        $this->assertSame(array(), $result);
    }

    public function test_get_active_for_audience_includes_parent_audiences(): void {
        $child = (object) array('id' => 5, 'parent_id' => 2);
        $parent = (object) array('id' => 2, 'parent_id' => null);

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($child, $parent) {
            if ($key === 'id_5' && $group === 'ffc_audiences') {
                return $child;
            }
            if ($key === 'id_2' && $group === 'ffc_audiences') {
                return $parent;
            }
            return false;
        });

        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_active_for_audience(5);

        $this->assertStringContainsString("status = 'active'", $captured_sql);
        $this->assertStringContainsString('IN', $captured_sql);
    }

    public function test_get_active_for_audience_sql_includes_join(): void {
        $audience = (object) array('id' => 3, 'parent_id' => null);

        Functions\when('wp_cache_get')->alias(function($key, $group = '') use ($audience) {
            if ($key === 'id_3' && $group === 'ffc_audiences') {
                return $audience;
            }
            return false;
        });

        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return 'QUERY';
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::get_active_for_audience(3);

        $this->assertStringContainsString('JOIN %i ra ON r.id = ra.reregistration_id', $captured_sql);
        $this->assertStringContainsString('ORDER BY r.start_date ASC', $captured_sql);
    }

    // ==================================================================
    // expire_overdue()
    // ==================================================================

    public function test_expire_overdue_expires_campaigns_past_end_date(): void {
        $overdue = array(
            (object) array('id' => 1),
            (object) array('id' => 2),
        );

        $this->wpdb->shouldReceive('get_results')->once()->andReturn($overdue);

        // Each campaign gets expired
        $this->wpdb->shouldReceive('update')
            ->twice()
            ->with(
                'wp_ffc_reregistrations',
                array('status' => 'expired'),
                Mockery::type('array'),
                array('%s'),
                array('%d')
            )
            ->andReturn(1);

        // Each campaign's submissions get expired
        $this->wpdb->shouldReceive('query')->twice()->andReturn(1);

        ReregistrationRepository::expire_overdue();
    }

    public function test_expire_overdue_does_nothing_when_no_overdue(): void {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        // No update or query calls should happen
        $this->wpdb->shouldNotReceive('update');
        $this->wpdb->shouldNotReceive('query');

        ReregistrationRepository::expire_overdue();
    }

    public function test_expire_overdue_does_nothing_when_null_result(): void {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

        $this->wpdb->shouldNotReceive('update');
        $this->wpdb->shouldNotReceive('query');

        ReregistrationRepository::expire_overdue();
    }

    public function test_expire_overdue_uses_current_time(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $args = func_get_args();
            if (strpos($args[0], 'end_date') !== false) {
                $captured_sql = $args[0];
            }
            return $args[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::expire_overdue();

        $this->assertStringContainsString('end_date < %s', $captured_sql);
    }

    public function test_expire_overdue_updates_submissions_status(): void {
        $overdue = array(
            (object) array('id' => 1),
        );

        $this->wpdb->shouldReceive('get_results')->once()->andReturn($overdue);
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $captured_sub_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sub_sql) {
            $args = func_get_args();
            if (strpos($args[0], 'pending') !== false) {
                $captured_sub_sql = $args[0];
            }
            return $args[0];
        });
        $this->wpdb->shouldReceive('query')->once()->andReturn(1);

        ReregistrationRepository::expire_overdue();

        $this->assertStringContainsString("SET status = 'expired'", $captured_sub_sql);
        $this->assertStringContainsString("IN ('pending', 'in_progress')", $captured_sub_sql);
    }

    public function test_expire_overdue_queries_only_active_campaigns(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $args = func_get_args();
            if (strpos($args[0], 'end_date') !== false) {
                $captured_sql = $args[0];
            }
            return $args[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationRepository::expire_overdue();

        $this->assertStringContainsString("status = 'active'", $captured_sql);
    }

    // ==================================================================
    // get_affected_user_ids_for_reregistration()
    // ==================================================================

    public function test_get_affected_user_ids_for_reregistration_returns_user_ids(): void {
        // get_audience_ids returns audience IDs
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(array('10', '20'));

        // AudienceRepository::get_members will query via wpdb
        // We need to handle the prepare calls for get_members
        $this->wpdb->shouldReceive('get_col')
            ->andReturn(array('100', '200'), array('200', '300'));

        $result = ReregistrationRepository::get_affected_user_ids_for_reregistration(1);

        // Should contain unique user IDs from both audiences
        $this->assertNotEmpty($result);
    }

    public function test_get_affected_user_ids_for_reregistration_empty_audiences(): void {
        $this->wpdb->shouldReceive('get_col')->once()->andReturn(array());

        $result = ReregistrationRepository::get_affected_user_ids_for_reregistration(1);

        $this->assertSame(array(), $result);
    }

    // ==================================================================
    // get_user_ids_for_audiences()
    // ==================================================================

    public function test_get_user_ids_for_audiences_returns_user_ids(): void {
        // AudienceRepository::get_members calls wpdb->get_col via prepare
        // For each audience_id, get_members is called
        $call_count = 0;
        $this->wpdb->shouldReceive('get_col')
            ->andReturnUsing(function() use (&$call_count) {
                $call_count++;
                if ($call_count === 1) {
                    return array('10', '20');
                }
                return array('20', '30');
            });

        $result = ReregistrationRepository::get_user_ids_for_audiences(array(1, 2));

        // Should have unique IDs: 10, 20, 30
        $this->assertCount(3, $result);
    }

    public function test_get_user_ids_for_audiences_empty_input(): void {
        $result = ReregistrationRepository::get_user_ids_for_audiences(array());

        $this->assertSame(array(), $result);
    }

    public function test_get_user_ids_for_audiences_single_audience(): void {
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(array('100', '200', '300'));

        $result = ReregistrationRepository::get_user_ids_for_audiences(array(5));

        $this->assertCount(3, $result);
    }

    // ==================================================================
    // STATUSES constant
    // ==================================================================

    public function test_statuses_constant_contains_expected_values(): void {
        $this->assertSame(
            array('draft', 'active', 'expired', 'closed'),
            ReregistrationRepository::STATUSES
        );
    }

    public function test_statuses_constant_has_four_entries(): void {
        $this->assertCount(4, ReregistrationRepository::STATUSES);
    }
}
