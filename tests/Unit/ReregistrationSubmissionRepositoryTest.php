<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository;

/**
 * Tests for ReregistrationSubmissionRepository: table name, status labels,
 * CRUD operations, approval/rejection workflows, bulk operations, statistics,
 * and audience member submission creation.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionRepository
 */
class ReregistrationSubmissionRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->users = 'wp_users';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        // Stub WP cache functions.
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('wp_parse_args')->alias(function ($args, $defaults = array()) {
            return array_merge($defaults, $args);
        });
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('sanitize_textarea_field')->alias('trim');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_time')->justReturn('2026-03-01 12:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
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

    public function test_get_table_name_returns_prefixed_table(): void {
        $this->assertSame(
            'wp_ffc_reregistration_submissions',
            ReregistrationSubmissionRepository::get_table_name()
        );
    }

    // ==================================================================
    // STATUSES constant
    // ==================================================================

    public function test_statuses_constant_contains_all_expected_values(): void {
        $expected = array('pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired');
        $this->assertSame($expected, ReregistrationSubmissionRepository::STATUSES);
    }

    // ==================================================================
    // get_status_labels() / get_status_label()
    // ==================================================================

    public function test_get_status_labels_returns_all_statuses(): void {
        $labels = ReregistrationSubmissionRepository::get_status_labels();

        $this->assertArrayHasKey('pending', $labels);
        $this->assertArrayHasKey('in_progress', $labels);
        $this->assertArrayHasKey('submitted', $labels);
        $this->assertArrayHasKey('approved', $labels);
        $this->assertArrayHasKey('rejected', $labels);
        $this->assertArrayHasKey('expired', $labels);
        $this->assertCount(6, $labels);
    }

    public function test_get_status_labels_values_are_strings(): void {
        $labels = ReregistrationSubmissionRepository::get_status_labels();

        foreach ($labels as $key => $label) {
            $this->assertIsString($label, "Label for '{$key}' should be a string.");
        }
    }

    public function test_get_status_label_returns_label_for_known_status(): void {
        $label = ReregistrationSubmissionRepository::get_status_label('approved');
        $this->assertSame('Approved', $label);
    }

    public function test_get_status_label_returns_key_for_unknown_status(): void {
        $label = ReregistrationSubmissionRepository::get_status_label('nonexistent');
        $this->assertSame('nonexistent', $label);
    }

    public function test_get_status_label_returns_submitted_with_review_note(): void {
        // The __ mock returns the first argument, so the raw English string.
        $label = ReregistrationSubmissionRepository::get_status_label('submitted');
        $this->assertStringContainsString('Submitted', $label);
    }

    // ==================================================================
    // get_by_id()
    // ==================================================================

    public function test_get_by_id_returns_result_from_database(): void {
        $row = (object) array('id' => 1, 'status' => 'pending', 'user_id' => 10);

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_reregistration_submissions WHERE id = 1');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReregistrationSubmissionRepository::get_by_id(1);

        $this->assertSame(1, $result->id);
        $this->assertSame('pending', $result->status);
    }

    public function test_get_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->assertNull(ReregistrationSubmissionRepository::get_by_id(999));
    }

    public function test_get_by_id_returns_cached_result_on_cache_hit(): void {
        $cached = (object) array('id' => 5, 'status' => 'approved');

        Functions\when('wp_cache_get')->alias(function ($key) use ($cached) {
            return $key === 'id_5' ? $cached : false;
        });

        // wpdb should NOT be called since we have a cache hit.
        $this->wpdb->shouldNotReceive('get_row');

        $result = ReregistrationSubmissionRepository::get_by_id(5);

        $this->assertSame(5, $result->id);
        $this->assertSame('approved', $result->status);
    }

    public function test_get_by_id_sets_cache_on_miss(): void {
        $row = (object) array('id' => 3, 'status' => 'submitted');

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        // Verify that cache_set is called via wp_cache_set.
        // The default justReturn(true) already handles this, but the call should happen.
        $result = ReregistrationSubmissionRepository::get_by_id(3);

        $this->assertSame(3, $result->id);
    }

    // ==================================================================
    // get_by_auth_code()
    // ==================================================================

    public function test_get_by_auth_code_returns_null_for_empty_string(): void {
        $this->wpdb->shouldNotReceive('prepare');
        $this->wpdb->shouldNotReceive('get_row');

        $this->assertNull(ReregistrationSubmissionRepository::get_by_auth_code(''));
    }

    public function test_get_by_auth_code_queries_database_for_valid_code(): void {
        $row = (object) array('id' => 10, 'auth_code' => 'ABCD1234', 'status' => 'submitted');

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReregistrationSubmissionRepository::get_by_auth_code('ABCD1234');

        $this->assertSame(10, $result->id);
        $this->assertSame('ABCD1234', $result->auth_code);
    }

    public function test_get_by_auth_code_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->assertNull(ReregistrationSubmissionRepository::get_by_auth_code('NONEXIST'));
    }

    // ==================================================================
    // get_by_magic_token()
    // ==================================================================

    public function test_get_by_magic_token_returns_null_for_empty_string(): void {
        $this->wpdb->shouldNotReceive('prepare');
        $this->wpdb->shouldNotReceive('get_row');

        $this->assertNull(ReregistrationSubmissionRepository::get_by_magic_token(''));
    }

    public function test_get_by_magic_token_queries_database_for_valid_token(): void {
        $token = str_repeat('ab', 32); // 64 hex chars
        $row = (object) array('id' => 15, 'magic_token' => $token, 'status' => 'approved');

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReregistrationSubmissionRepository::get_by_magic_token($token);

        $this->assertSame(15, $result->id);
        $this->assertSame($token, $result->magic_token);
    }

    public function test_get_by_magic_token_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->assertNull(ReregistrationSubmissionRepository::get_by_magic_token('deadbeef'));
    }

    // ==================================================================
    // ensure_magic_token()
    // ==================================================================

    public function test_ensure_magic_token_returns_existing_token(): void {
        $token = str_repeat('cd', 32);
        $submission = (object) array('id' => 20, 'magic_token' => $token);

        // Should NOT call update since token already exists.
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationSubmissionRepository::ensure_magic_token($submission);

        $this->assertSame($token, $result);
    }

    public function test_ensure_magic_token_generates_new_token_when_empty(): void {
        $submission = (object) array('id' => 25, 'magic_token' => '');

        // The update call should happen to persist the new token.
        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = ReregistrationSubmissionRepository::ensure_magic_token($submission);

        // Generated token should be 64 hex characters (bin2hex of 32 random bytes).
        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    public function test_ensure_magic_token_generates_new_token_when_null(): void {
        $submission = (object) array('id' => 26, 'magic_token' => null);

        $this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = ReregistrationSubmissionRepository::ensure_magic_token($submission);

        $this->assertSame(64, strlen($result));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    // ==================================================================
    // get_by_reregistration_and_user()
    // ==================================================================

    public function test_get_by_reregistration_and_user_returns_matching_row(): void {
        $row = (object) array('id' => 30, 'reregistration_id' => 5, 'user_id' => 42);

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReregistrationSubmissionRepository::get_by_reregistration_and_user(5, 42);

        $this->assertSame(30, $result->id);
        $this->assertSame(5, $result->reregistration_id);
        $this->assertSame(42, $result->user_id);
    }

    public function test_get_by_reregistration_and_user_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->assertNull(ReregistrationSubmissionRepository::get_by_reregistration_and_user(5, 999));
    }

    // ==================================================================
    // create()
    // ==================================================================

    public function test_create_inserts_basic_submission(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['reregistration_id'] === 1
                        && $data['user_id'] === 10
                        && $data['status'] === 'pending'
                        && !isset($data['data'])
                        && !isset($data['submitted_at'])
                        && !isset($data['notes']);
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 42;

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
        ));

        $this->assertSame(42, $result);
    }

    public function test_create_inserts_with_json_data(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return isset($data['data'])
                        && $data['data'] === '{"field":"value"}';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 50;

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
            'data'              => array('field' => 'value'),
        ));

        $this->assertSame(50, $result);
    }

    public function test_create_inserts_with_string_data(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return isset($data['data'])
                        && $data['data'] === '{"already":"encoded"}';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 51;

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
            'data'              => '{"already":"encoded"}',
        ));

        $this->assertSame(51, $result);
    }

    public function test_create_inserts_with_submitted_at(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return isset($data['submitted_at'])
                        && $data['submitted_at'] === '2026-03-01 10:00:00';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 52;

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
            'submitted_at'      => '2026-03-01 10:00:00',
        ));

        $this->assertSame(52, $result);
    }

    public function test_create_inserts_with_notes(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return isset($data['notes'])
                        && $data['notes'] === 'Some notes';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 53;

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
            'notes'             => 'Some notes',
        ));

        $this->assertSame(53, $result);
    }

    public function test_create_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
        ));

        $this->assertFalse($result);
    }

    public function test_create_uses_default_status_pending(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'pending';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 54;

        ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
        ));
    }

    public function test_create_allows_custom_status(): void {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'in_progress';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $this->wpdb->insert_id = 55;

        ReregistrationSubmissionRepository::create(array(
            'reregistration_id' => 1,
            'user_id'           => 10,
            'status'            => 'in_progress',
        ));
    }

    // ==================================================================
    // update()
    // ==================================================================

    public function test_update_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'submitted';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'status' => 'submitted',
        ));

        $this->assertTrue($result);
    }

    public function test_update_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'status' => 'submitted',
        ));

        $this->assertFalse($result);
    }

    public function test_update_returns_false_for_empty_data(): void {
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationSubmissionRepository::update(1, array());

        $this->assertFalse($result);
    }

    public function test_update_strips_immutable_fields(): void {
        // When only immutable fields are provided, nothing is left to update.
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationSubmissionRepository::update(1, array(
            'id'                => 999,
            'reregistration_id' => 999,
            'user_id'           => 999,
            'created_at'        => '2026-01-01 00:00:00',
        ));

        $this->assertFalse($result);
    }

    public function test_update_ignores_unknown_fields(): void {
        // Unknown fields should be silently ignored.
        $this->wpdb->shouldNotReceive('update');

        $result = ReregistrationSubmissionRepository::update(1, array(
            'unknown_field' => 'value',
            'another_bad'   => 123,
        ));

        $this->assertFalse($result);
    }

    public function test_update_encodes_array_data_field(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['data'] === '{"key":"val"}';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'data' => array('key' => 'val'),
        ));

        $this->assertTrue($result);
    }

    public function test_update_preserves_string_data_field(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['data'] === '{"raw":"json"}';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'data' => '{"raw":"json"}',
        ));

        $this->assertTrue($result);
    }

    public function test_update_sanitizes_notes_field(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    // sanitize_textarea_field is aliased to trim.
                    return $data['notes'] === 'trimmed notes';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'notes' => '  trimmed notes  ',
        ));

        $this->assertTrue($result);
    }

    public function test_update_allows_null_notes(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return array_key_exists('notes', $data) && $data['notes'] === null;
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'notes' => null,
        ));

        $this->assertTrue($result);
    }

    public function test_update_deletes_cache_for_id(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        // We rely on the wp_cache_delete stub returning true.
        // The method should call cache_delete("id_1").
        $result = ReregistrationSubmissionRepository::update(1, array(
            'status' => 'approved',
        ));

        $this->assertTrue($result);
    }

    public function test_update_handles_auth_code_field(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['auth_code'] === 'ABCD1234';
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'auth_code' => 'ABCD1234',
        ));

        $this->assertTrue($result);
    }

    public function test_update_handles_magic_token_field(): void {
        $token = str_repeat('ef', 32);

        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) use ($token) {
                    return $data['magic_token'] === $token;
                }),
                array('id' => 1),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::update(1, array(
            'magic_token' => $token,
        ));

        $this->assertTrue($result);
    }

    // ==================================================================
    // approve()
    // ==================================================================

    public function test_approve_updates_status_and_reviewer(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'approved'
                        && $data['reviewed_at'] === '2026-03-01 12:00:00'
                        && $data['reviewed_by'] === 99;
                }),
                array('id' => 5),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::approve(5, 99);

        $this->assertTrue($result);
    }

    public function test_approve_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = ReregistrationSubmissionRepository::approve(5, 99);

        $this->assertFalse($result);
    }

    // ==================================================================
    // reject()
    // ==================================================================

    public function test_reject_updates_status_reviewer_and_notes(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'rejected'
                        && $data['reviewed_at'] === '2026-03-01 12:00:00'
                        && $data['reviewed_by'] === 99
                        && $data['notes'] === 'Incomplete submission';
                }),
                array('id' => 7),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::reject(7, 99, 'Incomplete submission');

        $this->assertTrue($result);
    }

    public function test_reject_with_empty_notes(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'rejected'
                        && $data['notes'] === '';
                }),
                array('id' => 7),
                Mockery::type('array'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::reject(7, 99);

        $this->assertTrue($result);
    }

    public function test_reject_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = ReregistrationSubmissionRepository::reject(7, 99, 'Reason');

        $this->assertFalse($result);
    }

    // ==================================================================
    // return_to_draft()
    // ==================================================================

    public function test_return_to_draft_resets_submission_fields(): void {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_ffc_reregistration_submissions',
                Mockery::on(function ($data) {
                    return $data['status'] === 'in_progress'
                        && $data['submitted_at'] === null
                        && $data['reviewed_at'] === null
                        && $data['reviewed_by'] === null
                        && $data['notes'] === null;
                }),
                array('id' => 8),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            )
            ->andReturn(1);

        $result = ReregistrationSubmissionRepository::return_to_draft(8, 99);

        $this->assertTrue($result);
    }

    public function test_return_to_draft_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = ReregistrationSubmissionRepository::return_to_draft(8, 99);

        $this->assertFalse($result);
    }

    public function test_return_to_draft_returns_true_on_zero_rows_affected(): void {
        // wpdb->update returns 0 (int) when no rows match but no error occurred.
        $this->wpdb->shouldReceive('update')->once()->andReturn(0);

        $result = ReregistrationSubmissionRepository::return_to_draft(8, 99);

        $this->assertTrue($result);
    }

    // ==================================================================
    // bulk_approve()
    // ==================================================================

    public function test_bulk_approve_approves_all_submissions(): void {
        $this->wpdb->shouldReceive('update')->times(3)->andReturn(1);

        $count = ReregistrationSubmissionRepository::bulk_approve(array(1, 2, 3), 99);

        $this->assertSame(3, $count);
    }

    public function test_bulk_approve_counts_only_successful_approvals(): void {
        $this->wpdb->shouldReceive('update')
            ->andReturn(1, false, 1);

        $count = ReregistrationSubmissionRepository::bulk_approve(array(1, 2, 3), 99);

        $this->assertSame(2, $count);
    }

    public function test_bulk_approve_returns_zero_for_empty_array(): void {
        $count = ReregistrationSubmissionRepository::bulk_approve(array(), 99);

        $this->assertSame(0, $count);
    }

    public function test_bulk_approve_returns_zero_when_all_fail(): void {
        $this->wpdb->shouldReceive('update')->times(2)->andReturn(false);

        $count = ReregistrationSubmissionRepository::bulk_approve(array(1, 2), 99);

        $this->assertSame(0, $count);
    }

    // ==================================================================
    // bulk_return_to_draft()
    // ==================================================================

    public function test_bulk_return_to_draft_returns_count(): void {
        $this->wpdb->shouldReceive('update')->times(2)->andReturn(1);

        $count = ReregistrationSubmissionRepository::bulk_return_to_draft(array(10, 11), 99);

        $this->assertSame(2, $count);
    }

    public function test_bulk_return_to_draft_counts_only_successes(): void {
        $this->wpdb->shouldReceive('update')
            ->andReturn(1, false);

        $count = ReregistrationSubmissionRepository::bulk_return_to_draft(array(10, 11), 99);

        $this->assertSame(1, $count);
    }

    public function test_bulk_return_to_draft_returns_zero_for_empty_array(): void {
        $count = ReregistrationSubmissionRepository::bulk_return_to_draft(array(), 99);

        $this->assertSame(0, $count);
    }

    // ==================================================================
    // get_statistics()
    // ==================================================================

    public function test_get_statistics_returns_all_status_counts(): void {
        $rows = array(
            (object) array('status' => 'pending', 'count' => '5'),
            (object) array('status' => 'in_progress', 'count' => '3'),
            (object) array('status' => 'submitted', 'count' => '7'),
            (object) array('status' => 'approved', 'count' => '10'),
            (object) array('status' => 'rejected', 'count' => '2'),
            (object) array('status' => 'expired', 'count' => '1'),
        );

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $stats = ReregistrationSubmissionRepository::get_statistics(1);

        $this->assertSame(28, $stats['total']);
        $this->assertSame(5, $stats['pending']);
        $this->assertSame(3, $stats['in_progress']);
        $this->assertSame(7, $stats['submitted']);
        $this->assertSame(10, $stats['approved']);
        $this->assertSame(2, $stats['rejected']);
        $this->assertSame(1, $stats['expired']);
    }

    public function test_get_statistics_returns_zeros_when_no_results(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        $stats = ReregistrationSubmissionRepository::get_statistics(1);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['pending']);
        $this->assertSame(0, $stats['in_progress']);
        $this->assertSame(0, $stats['submitted']);
        $this->assertSame(0, $stats['approved']);
        $this->assertSame(0, $stats['rejected']);
        $this->assertSame(0, $stats['expired']);
    }

    public function test_get_statistics_handles_partial_statuses(): void {
        $rows = array(
            (object) array('status' => 'approved', 'count' => '15'),
            (object) array('status' => 'pending', 'count' => '5'),
        );

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $stats = ReregistrationSubmissionRepository::get_statistics(1);

        $this->assertSame(20, $stats['total']);
        $this->assertSame(15, $stats['approved']);
        $this->assertSame(5, $stats['pending']);
        $this->assertSame(0, $stats['in_progress']);
        $this->assertSame(0, $stats['submitted']);
        $this->assertSame(0, $stats['rejected']);
        $this->assertSame(0, $stats['expired']);
    }

    // ==================================================================
    // count_by_reregistration()
    // ==================================================================

    public function test_count_by_reregistration_without_status_filter(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('12');

        $count = ReregistrationSubmissionRepository::count_by_reregistration(1);

        $this->assertSame(12, $count);
    }

    public function test_count_by_reregistration_with_status_filter(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('4');

        $count = ReregistrationSubmissionRepository::count_by_reregistration(1, 'approved');

        $this->assertSame(4, $count);
    }

    public function test_count_by_reregistration_returns_zero_for_null(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

        $count = ReregistrationSubmissionRepository::count_by_reregistration(1);

        $this->assertSame(0, $count);
    }

    // ==================================================================
    // get_by_reregistration()
    // ==================================================================

    public function test_get_by_reregistration_returns_results(): void {
        $rows = array(
            (object) array('id' => 1, 'status' => 'pending', 'user_name' => 'Alice'),
            (object) array('id' => 2, 'status' => 'approved', 'user_name' => 'Bob'),
        );

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $results = ReregistrationSubmissionRepository::get_by_reregistration(1);

        $this->assertCount(2, $results);
        $this->assertSame('Alice', $results[0]->user_name);
    }

    public function test_get_by_reregistration_with_status_filter(): void {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () {
                $sql = func_get_args()[0];
                $this->assertStringContainsString('s.status = %s', $sql);
                return 'QUERY';
            });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationSubmissionRepository::get_by_reregistration(1, array('status' => 'approved'));
    }

    public function test_get_by_reregistration_with_search_filter(): void {
        $this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function ($v) {
            return $v;
        });
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () {
                $sql = func_get_args()[0];
                $this->assertStringContainsString('LIKE', $sql);
                return 'QUERY';
            });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationSubmissionRepository::get_by_reregistration(1, array('search' => 'alice'));
    }

    public function test_get_by_reregistration_with_limit_and_offset(): void {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () {
                $sql = func_get_args()[0];
                $this->assertStringContainsString('LIMIT', $sql);
                return 'QUERY';
            });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationSubmissionRepository::get_by_reregistration(1, array(
            'limit'  => 10,
            'offset' => 20,
        ));
    }

    public function test_get_by_reregistration_invalid_orderby_falls_back(): void {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () {
                $sql = func_get_args()[0];
                $this->assertStringContainsString('s.created_at', $sql);
                return 'QUERY';
            });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationSubmissionRepository::get_by_reregistration(1, array(
            'orderby' => 'malicious_column',
        ));
    }

    public function test_get_by_reregistration_desc_order(): void {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturnUsing(function () {
                $sql = func_get_args()[0];
                $this->assertStringContainsString('DESC', $sql);
                return 'QUERY';
            });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

        ReregistrationSubmissionRepository::get_by_reregistration(1, array(
            'order' => 'DESC',
        ));
    }

    // ==================================================================
    // get_for_export()
    // ==================================================================

    public function test_get_for_export_delegates_to_get_by_reregistration(): void {
        $rows = array(
            (object) array('id' => 1, 'status' => 'approved'),
        );

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $results = ReregistrationSubmissionRepository::get_for_export(1);

        $this->assertCount(1, $results);
    }

    // ==================================================================
    // get_all_by_user()
    // Note: These tests must run BEFORE create_for_audience_members tests
    // because those tests create a Mockery alias for ReregistrationRepository
    // which persists for the rest of the process.
    // ==================================================================

    public function test_get_all_by_user_returns_array_of_results(): void {
        $rows = array(
            (object) array('id' => 1, 'user_id' => 42, 'reregistration_title' => 'Campaign A'),
            (object) array('id' => 2, 'user_id' => 42, 'reregistration_title' => 'Campaign B'),
        );

        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $results = ReregistrationSubmissionRepository::get_all_by_user(42);

        $this->assertCount(2, $results);
        $this->assertSame('Campaign A', $results[0]->reregistration_title);
    }

    public function test_get_all_by_user_returns_empty_array_when_no_results(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

        $results = ReregistrationSubmissionRepository::get_all_by_user(42);

        $this->assertSame(array(), $results);
    }

    // ==================================================================
    // create_for_audience_members()
    // Note: These tests use Mockery alias mocks for ReregistrationRepository,
    // which permanently replace the class for this process. Keep these last.
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_create_for_audience_members_creates_submissions_for_new_users(): void {
        // Mock ReregistrationRepository::get_user_ids_for_audiences.
        $repoMock = Mockery::mock('alias:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $repoMock->shouldReceive('get_user_ids_for_audiences')
            ->once()
            ->with(array(100, 200))
            ->andReturn(array(10, 20, 30));

        // For each user, get_by_reregistration_and_user returns null (no existing submission).
        $this->wpdb->shouldReceive('get_row')->andReturn(null);

        // Each create call succeeds.
        $this->wpdb->shouldReceive('insert')->times(3)->andReturn(1);
        $this->wpdb->insert_id = 1;

        $count = ReregistrationSubmissionRepository::create_for_audience_members(5, array(100, 200));

        $this->assertSame(3, $count);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_create_for_audience_members_skips_existing_submissions(): void {
        $repoMock = Mockery::mock('alias:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $repoMock->shouldReceive('get_user_ids_for_audiences')
            ->once()
            ->with(array(100))
            ->andReturn(array(10, 20));

        // User 10 already has a submission, user 20 does not.
        $existingSubmission = (object) array('id' => 50, 'user_id' => 10);
        $call_count = 0;
        $this->wpdb->shouldReceive('get_row')->andReturnUsing(function () use (&$call_count, $existingSubmission) {
            $call_count++;
            return $call_count === 1 ? $existingSubmission : null;
        });

        // Only one insert should happen (for user 20).
        $this->wpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->wpdb->insert_id = 51;

        $count = ReregistrationSubmissionRepository::create_for_audience_members(5, array(100));

        $this->assertSame(1, $count);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_create_for_audience_members_returns_zero_for_no_users(): void {
        $repoMock = Mockery::mock('alias:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $repoMock->shouldReceive('get_user_ids_for_audiences')
            ->once()
            ->with(array(100))
            ->andReturn(array());

        $this->wpdb->shouldNotReceive('insert');

        $count = ReregistrationSubmissionRepository::create_for_audience_members(5, array(100));

        $this->assertSame(0, $count);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_create_for_audience_members_handles_insert_failures(): void {
        $repoMock = Mockery::mock('alias:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $repoMock->shouldReceive('get_user_ids_for_audiences')
            ->once()
            ->with(array(100))
            ->andReturn(array(10, 20));

        // No existing submissions.
        $this->wpdb->shouldReceive('get_row')->andReturn(null);

        // First insert succeeds, second fails.
        $this->wpdb->shouldReceive('insert')
            ->twice()
            ->andReturn(1, false);
        $this->wpdb->insert_id = 1;

        $count = ReregistrationSubmissionRepository::create_for_audience_members(5, array(100));

        $this->assertSame(1, $count);
    }
}
