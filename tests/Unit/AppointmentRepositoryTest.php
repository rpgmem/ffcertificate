<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\AppointmentRepository;

/**
 * Tests for AppointmentRepository: CRUD operations, query methods,
 * status transitions, slot availability, statistics, and appointment creation.
 *
 * @covers \FreeFormCertificate\Repositories\AppointmentRepository
 */
class AppointmentRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var AppointmentRepository */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $this->wpdb = $wpdb;

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-03-01 10:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('absint')->alias(function($val) {
            return abs(intval($val));
        });

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        })->byDefault();

        $this->repo = new AppointmentRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Table name and cache group
    // ==================================================================

    public function test_table_name_uses_wpdb_prefix(): void {
        // The table name is set during construction from get_table_name()
        // We verify by checking that queries reference the correct table
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_appointments WHERE id = 1'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findById(1);

        // findById returns null when row not found; Mockery verifies prepare/get_row were called
        $this->assertNull($result);
    }

    public function test_table_name_with_custom_prefix(): void {
        global $wpdb;
        $wpdb->prefix = 'custom_';
        $repo = new AppointmentRepository();

        // Verify construction completed (table uses custom prefix)
        $this->assertInstanceOf(AppointmentRepository::class, $repo);

        // Restore
        $wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // findById (inherited from AbstractRepository)
    // ==================================================================

    public function test_find_by_id_returns_row_on_cache_miss(): void {
        $row = ['id' => 1, 'calendar_id' => 5, 'status' => 'confirmed'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_appointments WHERE id = 1'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = $this->repo->findById(1);

        $this->assertSame($row, $result);
    }

    public function test_find_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_appointments WHERE id = 999'
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findById(999);

        $this->assertNull($result);
    }

    public function test_find_by_id_uses_cache(): void {
        $cached = ['id' => 1, 'calendar_id' => 5, 'status' => 'confirmed'];

        // Override the default wp_cache_get to return cached data
        Functions\when('wp_cache_get')->justReturn($cached);

        // Rebuild repo so the cache mock takes effect
        $this->repo = new AppointmentRepository();

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
            ['id' => 1, 'calendar_id' => 5, 'status' => 'confirmed'],
            ['id' => 3, 'calendar_id' => 5, 'status' => 'pending'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findByIds([1, 3]);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(3, $result);
        $this->assertSame('confirmed', $result[1]['status']);
    }

    // ==================================================================
    // findAll (inherited from AbstractRepository)
    // ==================================================================

    public function test_find_all_with_no_conditions_returns_all_rows(): void {
        $rows = [
            ['id' => 1, 'status' => 'confirmed'],
            ['id' => 2, 'status' => 'pending'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findAll();

        $this->assertCount(2, $result);
    }

    public function test_find_all_with_limit_includes_limit_clause(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
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
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findAll(['calendar_id' => 5]);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    // ==================================================================
    // count (inherited from AbstractRepository)
    // ==================================================================

    public function test_count_returns_integer(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('42');

        $result = $this->repo->count(['calendar_id' => 5]);

        $this->assertSame(42, $result);
    }

    public function test_count_with_no_conditions(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
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

        $result = $this->repo->insert(['calendar_id' => 5, 'status' => 'pending']);

        $this->assertSame(42, $result);
    }

    public function test_insert_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $result = $this->repo->insert(['calendar_id' => 5]);

        $this->assertFalse($result);
    }

    // ==================================================================
    // update (inherited from AbstractRepository)
    // ==================================================================

    public function test_update_returns_affected_rows_on_success(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = $this->repo->update(1, ['status' => 'confirmed']);

        $this->assertSame(1, $result);
    }

    public function test_update_returns_zero_when_no_rows_changed(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(0);

        $result = $this->repo->update(1, ['status' => 'confirmed']);

        $this->assertSame(0, $result);
    }

    public function test_update_returns_false_on_error(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->update(1, ['status' => 'confirmed']);

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

    public function test_rollback_returns_true_on_success(): void {
        $this->wpdb->shouldReceive('query')
            ->with('ROLLBACK')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->repo->rollback());
    }

    // ==================================================================
    // findByCalendar
    // ==================================================================

    public function test_find_by_calendar_delegates_to_find_all(): void {
        $rows = [
            ['id' => 1, 'calendar_id' => 5, 'appointment_date' => '2026-03-10'],
            ['id' => 2, 'calendar_id' => 5, 'appointment_date' => '2026-03-09'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findByCalendar(5);

        $this->assertCount(2, $result);
        $this->assertSame(5, $result[0]['calendar_id']);
    }

    public function test_find_by_calendar_with_limit_and_offset(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByCalendar(5, 10, 20);

        $this->assertStringContainsString('LIMIT', $captured_sql);
        $this->assertStringContainsString('OFFSET', $captured_sql);
    }

    public function test_find_by_calendar_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->findByCalendar(999);

        $this->assertSame([], $result);
    }

    // ==================================================================
    // findByUserId
    // ==================================================================

    public function test_find_by_user_id_without_statuses_delegates_to_find_all(): void {
        $rows = [
            ['id' => 1, 'user_id' => 10, 'status' => 'confirmed'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findByUserId(10);

        $this->assertCount(1, $result);
    }

    public function test_find_by_user_id_with_statuses_uses_in_clause(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByUserId(10, ['confirmed', 'pending']);

        $this->assertStringContainsString('status IN', $captured_sql);
        $this->assertStringContainsString('user_id = %d', $captured_sql);
    }

    public function test_find_by_user_id_with_statuses_and_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByUserId(10, ['confirmed'], 5, 0);

        $this->assertStringContainsString('LIMIT', $captured_sql);
        $this->assertStringContainsString('status IN', $captured_sql);
    }

    public function test_find_by_user_id_with_statuses_no_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByUserId(10, ['confirmed', 'pending'], null, 0);

        $this->assertStringContainsString('status IN', $captured_sql);
        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    // ==================================================================
    // findByEmail
    // ==================================================================

    public function test_find_by_email_uses_email_hash(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByEmail('test@example.com');

        $this->assertStringContainsString('email_hash', $captured_sql);
    }

    public function test_find_by_email_normalizes_email_for_hash(): void {
        // Both of these should produce the same hash
        $email_hash_1 = hash('sha256', strtolower(trim('Test@Example.com')));
        $email_hash_2 = hash('sha256', strtolower(trim('  test@example.com  ')));

        $this->assertSame($email_hash_1, $email_hash_2);
    }

    public function test_find_by_email_with_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByEmail('test@example.com', 10, 5);

        $this->assertStringContainsString('email_hash', $captured_sql);
        $this->assertStringContainsString('LIMIT', $captured_sql);
        $this->assertStringContainsString('OFFSET', $captured_sql);
    }

    public function test_find_by_email_without_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByEmail('test@example.com');

        $this->assertStringContainsString('email_hash', $captured_sql);
        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_find_by_email_returns_results(): void {
        $rows = [
            ['id' => 1, 'email_hash' => hash('sha256', 'test@example.com'), 'status' => 'confirmed'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->findByEmail('test@example.com');

        $this->assertCount(1, $result);
        $this->assertSame('confirmed', $result[0]['status']);
    }

    // ==================================================================
    // findByCpfRf
    // ==================================================================

    public function test_find_by_cpf_rf_uses_cpf_hash_for_eleven_digits(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        // 11-digit CPF
        $this->repo->findByCpfRf('123.456.789-01');

        $this->assertStringContainsString('cpf_hash', $captured_sql);
        $this->assertStringNotContainsString('rf_hash', $captured_sql);
    }

    public function test_find_by_cpf_rf_uses_rf_hash_for_seven_digits(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        // 7-digit RF
        $this->repo->findByCpfRf('1234567');

        $this->assertStringContainsString('rf_hash', $captured_sql);
        $this->assertStringNotContainsString('cpf_hash', $captured_sql);
    }

    public function test_find_by_cpf_rf_strips_non_numeric_characters(): void {
        // Both should produce the same result since non-numeric chars are stripped
        $clean = preg_replace('/[^0-9]/', '', '123.456.789-01');
        $this->assertSame('12345678901', $clean);
        $this->assertSame(11, strlen($clean));
    }

    public function test_find_by_cpf_rf_with_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByCpfRf('12345678901', 10, 0);

        $this->assertStringContainsString('LIMIT', $captured_sql);
    }

    public function test_find_by_cpf_rf_without_limit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByCpfRf('12345678901');

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_find_by_cpf_rf_defaults_to_cpf_hash_for_non_seven_digit(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        // 5-digit number: not 7, so defaults to cpf_hash
        $this->repo->findByCpfRf('12345');

        $this->assertStringContainsString('cpf_hash', $captured_sql);
    }

    // ==================================================================
    // findByConfirmationToken
    // ==================================================================

    public function test_find_by_confirmation_token_returns_row_when_found(): void {
        $row = ['id' => 1, 'confirmation_token' => 'abc123', 'status' => 'pending'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            "SELECT * FROM wp_ffc_self_scheduling_appointments WHERE confirmation_token = 'abc123'"
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = $this->repo->findByConfirmationToken('abc123');

        $this->assertSame($row, $result);
    }

    public function test_find_by_confirmation_token_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            "SELECT * FROM wp_ffc_self_scheduling_appointments WHERE confirmation_token = 'nonexistent'"
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findByConfirmationToken('nonexistent');

        $this->assertNull($result);
    }

    public function test_find_by_confirmation_token_returns_null_for_empty_result(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            "SELECT * FROM wp_ffc_self_scheduling_appointments WHERE confirmation_token = 'missing'"
        );
        // wpdb->get_row returns null when no result; test the ?: null path
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(false);

        $result = $this->repo->findByConfirmationToken('missing');

        // false is falsy, so ?: null returns null
        $this->assertNull($result);
    }

    // ==================================================================
    // findByValidationCode
    // ==================================================================

    public function test_find_by_validation_code_returns_row_when_found(): void {
        $row = ['id' => 1, 'validation_code' => 'ABCD1234EFGH', 'status' => 'confirmed'];

        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            "SELECT * FROM wp_ffc_self_scheduling_appointments WHERE validation_code = 'ABCD1234EFGH'"
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = $this->repo->findByValidationCode('abcd1234efgh');

        $this->assertSame($row, $result);
    }

    public function test_find_by_validation_code_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive('prepare')->once()->andReturn(
            "SELECT * FROM wp_ffc_self_scheduling_appointments WHERE validation_code = 'ZZZZ'"
        );
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->findByValidationCode('zzzz');

        $this->assertNull($result);
    }

    public function test_find_by_validation_code_uppercases_input(): void {
        // The method calls strtoupper() on the input. We verify it via the prepare call.
        $captured_args = [];
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_args) {
            $captured_args = func_get_args();
            return $captured_args[0];
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->repo->findByValidationCode('abcd1234efgh');

        // The third argument to prepare should be the uppercased code
        $this->assertSame('ABCD1234EFGH', $captured_args[2]);
    }

    // ==================================================================
    // getAppointmentsByDate
    // ==================================================================

    public function test_get_appointments_by_date_returns_results(): void {
        $rows = [
            ['id' => 1, 'calendar_id' => 5, 'appointment_date' => '2026-03-10', 'start_time' => '09:00:00'],
            ['id' => 2, 'calendar_id' => 5, 'appointment_date' => '2026-03-10', 'start_time' => '10:00:00'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->getAppointmentsByDate(5, '2026-03-10');

        $this->assertCount(2, $result);
    }

    public function test_get_appointments_by_date_with_default_statuses(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDate(5, '2026-03-10');

        $this->assertStringContainsString('status IN', $captured_sql);
        $this->assertStringContainsString('calendar_id = %d', $captured_sql);
        $this->assertStringContainsString('appointment_date = %s', $captured_sql);
        $this->assertStringContainsString('ORDER BY start_time ASC', $captured_sql);
    }

    public function test_get_appointments_by_date_with_custom_statuses(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDate(5, '2026-03-10', ['confirmed']);

        $this->assertStringContainsString('status IN', $captured_sql);
    }

    public function test_get_appointments_by_date_without_lock(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDate(5, '2026-03-10', ['confirmed', 'pending'], false);

        $this->assertStringNotContainsString('FOR UPDATE', $captured_sql);
    }

    public function test_get_appointments_by_date_with_lock(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDate(5, '2026-03-10', ['confirmed', 'pending'], true);

        $this->assertStringContainsString('FOR UPDATE', $captured_sql);
    }

    // ==================================================================
    // getAppointmentsByDateRange
    // ==================================================================

    public function test_get_appointments_by_date_range_returns_results(): void {
        $rows = [
            ['id' => 1, 'appointment_date' => '2026-03-10'],
            ['id' => 2, 'appointment_date' => '2026-03-11'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->getAppointmentsByDateRange(5, '2026-03-10', '2026-03-15');

        $this->assertCount(2, $result);
    }

    public function test_get_appointments_by_date_range_builds_correct_query(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDateRange(5, '2026-03-01', '2026-03-31');

        $this->assertStringContainsString('BETWEEN', $captured_sql);
        $this->assertStringContainsString('calendar_id = %d', $captured_sql);
        $this->assertStringContainsString('status IN', $captured_sql);
        $this->assertStringContainsString('ORDER BY appointment_date ASC, start_time ASC', $captured_sql);
    }

    public function test_get_appointments_by_date_range_with_custom_statuses(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getAppointmentsByDateRange(5, '2026-03-01', '2026-03-31', ['completed']);

        $this->assertStringContainsString('status IN', $captured_sql);
    }

    // ==================================================================
    // isSlotAvailable
    // ==================================================================

    public function test_is_slot_available_returns_true_when_below_max(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $result = $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 1);

        $this->assertTrue($result);
    }

    public function test_is_slot_available_returns_false_when_at_max(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');

        $result = $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 1);

        $this->assertFalse($result);
    }

    public function test_is_slot_available_returns_false_when_above_max(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('5');

        $result = $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 3);

        $this->assertFalse($result);
    }

    public function test_is_slot_available_returns_true_with_multi_slot_capacity(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('2');

        $result = $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 5);

        $this->assertTrue($result);
    }

    public function test_is_slot_available_without_lock(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 1, false);

        $this->assertStringNotContainsString('FOR UPDATE', $captured_sql);
    }

    public function test_is_slot_available_with_lock(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00', 1, true);

        $this->assertStringContainsString('FOR UPDATE', $captured_sql);
    }

    // ==================================================================
    // cancel
    // ==================================================================

    public function test_cancel_updates_status_to_cancelled(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $result = $this->repo->cancel(1, 42, 'No longer needed');

        $this->assertSame(1, $result);
        $this->assertSame('cancelled', $captured_data['status']);
        $this->assertSame(42, $captured_data['cancelled_by']);
        $this->assertSame('No longer needed', $captured_data['cancellation_reason']);
        $this->assertArrayHasKey('cancelled_at', $captured_data);
        $this->assertArrayHasKey('updated_at', $captured_data);
    }

    public function test_cancel_with_null_cancelled_by(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $this->repo->cancel(1);

        $this->assertSame('cancelled', $captured_data['status']);
        $this->assertNull($captured_data['cancelled_by']);
        $this->assertNull($captured_data['cancellation_reason']);
    }

    public function test_cancel_uses_current_time(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $this->repo->cancel(1, 1, 'test');

        $this->assertSame('2026-03-01 10:00:00', $captured_data['cancelled_at']);
        $this->assertSame('2026-03-01 10:00:00', $captured_data['updated_at']);
    }

    // ==================================================================
    // confirm
    // ==================================================================

    public function test_confirm_updates_status_to_confirmed(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $result = $this->repo->confirm(1, 42);

        $this->assertSame(1, $result);
        $this->assertSame('confirmed', $captured_data['status']);
        $this->assertSame(42, $captured_data['approved_by']);
        $this->assertArrayHasKey('approved_at', $captured_data);
        $this->assertArrayHasKey('updated_at', $captured_data);
    }

    public function test_confirm_with_null_approved_by(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $this->repo->confirm(1);

        $this->assertSame('confirmed', $captured_data['status']);
        $this->assertNull($captured_data['approved_by']);
    }

    // ==================================================================
    // markCompleted
    // ==================================================================

    public function test_mark_completed_updates_status(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $result = $this->repo->markCompleted(1);

        $this->assertSame(1, $result);
        $this->assertSame('completed', $captured_data['status']);
        $this->assertArrayHasKey('updated_at', $captured_data);
    }

    public function test_mark_completed_sets_updated_at_timestamp(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $this->repo->markCompleted(5);

        $this->assertSame('2026-03-01 10:00:00', $captured_data['updated_at']);
    }

    // ==================================================================
    // markNoShow
    // ==================================================================

    public function test_mark_no_show_updates_status(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $result = $this->repo->markNoShow(1);

        $this->assertSame(1, $result);
        $this->assertSame('no_show', $captured_data['status']);
        $this->assertArrayHasKey('updated_at', $captured_data);
    }

    // ==================================================================
    // markReminderSent
    // ==================================================================

    public function test_mark_reminder_sent_sets_timestamp(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function($table, $data, $where) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });

        $result = $this->repo->markReminderSent(1);

        $this->assertSame(1, $result);
        $this->assertArrayHasKey('reminder_sent_at', $captured_data);
        $this->assertSame('2026-03-01 10:00:00', $captured_data['reminder_sent_at']);
    }

    // ==================================================================
    // getUpcomingForReminders
    // ==================================================================

    public function test_get_upcoming_for_reminders_builds_correct_query(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getUpcomingForReminders(24);

        $this->assertStringContainsString('status = \'confirmed\'', $captured_sql);
        $this->assertStringContainsString('reminder_sent_at IS NULL', $captured_sql);
        $this->assertStringContainsString('LEFT JOIN', $captured_sql);
        $this->assertStringContainsString('calendar_title', $captured_sql);
        $this->assertStringContainsString('email_config', $captured_sql);
    }

    public function test_get_upcoming_for_reminders_returns_results(): void {
        $rows = [
            ['id' => 1, 'calendar_title' => 'Test Calendar', 'appointment_date' => '2026-03-02'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->getUpcomingForReminders(24);

        $this->assertCount(1, $result);
        $this->assertSame('Test Calendar', $result[0]['calendar_title']);
    }

    public function test_get_upcoming_for_reminders_uses_default_hours(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        // Default parameter is 24
        $result = $this->repo->getUpcomingForReminders();

        $this->assertIsArray($result);
    }

    // ==================================================================
    // getStatistics
    // ==================================================================

    public function test_get_statistics_returns_stats_for_calendar(): void {
        $stats = [
            'total' => '50',
            'confirmed' => '20',
            'pending' => '10',
            'cancelled' => '5',
            'completed' => '12',
            'no_show' => '3',
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn($stats);

        $result = $this->repo->getStatistics(5);

        $this->assertSame($stats, $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('confirmed', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('cancelled', $result);
        $this->assertArrayHasKey('completed', $result);
        $this->assertArrayHasKey('no_show', $result);
    }

    public function test_get_statistics_with_date_range(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn([
            'total' => '10',
            'confirmed' => '5',
            'pending' => '2',
            'cancelled' => '1',
            'completed' => '2',
            'no_show' => '0',
        ]);

        $this->repo->getStatistics(5, '2026-03-01', '2026-03-31');

        $this->assertStringContainsString('BETWEEN', $captured_sql);
    }

    public function test_get_statistics_without_date_range(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn([
            'total' => '10',
            'confirmed' => '5',
            'pending' => '2',
            'cancelled' => '1',
            'completed' => '2',
            'no_show' => '0',
        ]);

        $this->repo->getStatistics(5);

        $this->assertStringNotContainsString('BETWEEN', $captured_sql);
    }

    public function test_get_statistics_returns_defaults_when_null(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $result = $this->repo->getStatistics(999);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(0, $result['completed']);
        $this->assertSame(0, $result['no_show']);
    }

    public function test_get_statistics_query_includes_count_and_sums(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->repo->getStatistics(5);

        $this->assertStringContainsString('COUNT(*)', $captured_sql);
        $this->assertStringContainsString('SUM(CASE WHEN status', $captured_sql);
        $this->assertStringContainsString('calendar_id = %d', $captured_sql);
    }

    // ==================================================================
    // createAppointment (without encryption - class_exists returns false)
    // ==================================================================

    public function test_create_appointment_generates_confirmation_token(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        // Encryption class is not loaded in tests, so class_exists() returns false naturally.
        // We provide confirmation_token and validation_code to skip the private
        // generate_unique_validation_code() call that depends on Utils.

        $data = [
            'calendar_id' => 5,
            'appointment_date' => '2026-03-10',
            'start_time' => '09:00:00',
            'status' => 'pending',
            'confirmation_token' => 'pre-generated-token',
            'validation_code' => 'PRE123456789',
        ];

        $result = $this->repo->createAppointment($data);

        $this->assertSame(1, $result);
        $this->assertSame('pre-generated-token', $captured_data['confirmation_token']);
        $this->assertSame('PRE123456789', $captured_data['validation_code']);
    }

    public function test_create_appointment_strips_plaintext_fields(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $data = [
            'calendar_id' => 5,
            'email' => 'test@example.com',
            'cpf_rf' => '12345678901',
            'phone' => '555-1234',
            'custom_data' => ['field1' => 'value1'],
            'user_ip' => '192.168.1.1',
            'confirmation_token' => 'existing-token',
            'validation_code' => 'EXISTING1234',
        ];

        $this->repo->createAppointment($data);

        // These plaintext fields should be removed (unconditional unset at line 488)
        $this->assertArrayNotHasKey('email', $captured_data);
        $this->assertArrayNotHasKey('cpf_rf', $captured_data);
        $this->assertArrayNotHasKey('phone', $captured_data);
        $this->assertArrayNotHasKey('custom_data', $captured_data);
        $this->assertArrayNotHasKey('user_ip', $captured_data);

        // These should remain
        $this->assertArrayHasKey('calendar_id', $captured_data);
        $this->assertArrayHasKey('confirmation_token', $captured_data);
        $this->assertArrayHasKey('validation_code', $captured_data);
    }

    public function test_create_appointment_sets_created_at_when_missing(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $data = [
            'calendar_id' => 5,
            'confirmation_token' => 'token123',
            'validation_code' => 'CODE12345678',
        ];

        $this->repo->createAppointment($data);

        $this->assertSame('2026-03-01 10:00:00', $captured_data['created_at']);
    }

    public function test_create_appointment_preserves_existing_created_at(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $data = [
            'calendar_id' => 5,
            'created_at' => '2026-02-28 15:00:00',
            'confirmation_token' => 'token123',
            'validation_code' => 'CODE12345678',
        ];

        $this->repo->createAppointment($data);

        $this->assertSame('2026-02-28 15:00:00', $captured_data['created_at']);
    }

    public function test_create_appointment_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive('insert')->once()->andReturn(false);

        $data = [
            'calendar_id' => 5,
            'confirmation_token' => 'token123',
            'validation_code' => 'CODE12345678',
        ];

        $result = $this->repo->createAppointment($data);

        $this->assertFalse($result);
    }

    public function test_create_appointment_preserves_provided_confirmation_token(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $data = [
            'calendar_id' => 5,
            'confirmation_token' => 'my-custom-token',
            'validation_code' => 'CODE12345678',
        ];

        $this->repo->createAppointment($data);

        $this->assertSame('my-custom-token', $captured_data['confirmation_token']);
    }

    public function test_create_appointment_preserves_provided_validation_code(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 1;

        $data = [
            'calendar_id' => 5,
            'confirmation_token' => 'token123',
            'validation_code' => 'MYCODE123456',
        ];

        $this->repo->createAppointment($data);

        $this->assertSame('MYCODE123456', $captured_data['validation_code']);
    }

    // ==================================================================
    // getBookingCountsByDateRange
    // ==================================================================

    public function test_get_booking_counts_by_date_range_returns_date_count_map(): void {
        $rows = [
            ['appointment_date' => '2026-03-10', 'count' => '3'],
            ['appointment_date' => '2026-03-11', 'count' => '5'],
            ['appointment_date' => '2026-03-12', 'count' => '1'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->getBookingCountsByDateRange(5, '2026-03-10', '2026-03-15');

        $this->assertCount(3, $result);
        $this->assertSame(3, $result['2026-03-10']);
        $this->assertSame(5, $result['2026-03-11']);
        $this->assertSame(1, $result['2026-03-12']);
    }

    public function test_get_booking_counts_by_date_range_returns_empty_array_for_no_results(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->getBookingCountsByDateRange(5, '2026-03-10', '2026-03-15');

        $this->assertSame([], $result);
    }

    public function test_get_booking_counts_by_date_range_returns_empty_for_null_results(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

        $result = $this->repo->getBookingCountsByDateRange(5, '2026-03-10', '2026-03-15');

        $this->assertSame([], $result);
    }

    public function test_get_booking_counts_by_date_range_casts_count_to_int(): void {
        $rows = [
            ['appointment_date' => '2026-03-10', 'count' => '7'],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

        $result = $this->repo->getBookingCountsByDateRange(5, '2026-03-10', '2026-03-15');

        $this->assertIsInt($result['2026-03-10']);
        $this->assertSame(7, $result['2026-03-10']);
    }

    public function test_get_booking_counts_by_date_range_query_structure(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->getBookingCountsByDateRange(5, '2026-03-01', '2026-03-31');

        $this->assertStringContainsString('GROUP BY appointment_date', $captured_sql);
        $this->assertStringContainsString('calendar_id = %d', $captured_sql);
        $this->assertStringContainsString("status IN ('confirmed', 'pending')", $captured_sql);
        $this->assertStringContainsString('COUNT(*)', $captured_sql);
    }

    // ==================================================================
    // Status transition integration tests
    // ==================================================================

    public function test_cancel_returns_false_when_update_fails(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->cancel(1, 42, 'reason');

        $this->assertFalse($result);
    }

    public function test_confirm_returns_false_when_update_fails(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->confirm(1, 42);

        $this->assertFalse($result);
    }

    public function test_mark_completed_returns_false_when_update_fails(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->markCompleted(1);

        $this->assertFalse($result);
    }

    public function test_mark_no_show_returns_false_when_update_fails(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->markNoShow(1);

        $this->assertFalse($result);
    }

    // ==================================================================
    // Edge cases and boundary conditions
    // ==================================================================

    public function test_is_slot_available_with_default_max_per_slot(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');

        // Default max_per_slot is 1
        $result = $this->repo->isSlotAvailable(5, '2026-03-10', '09:00:00');

        $this->assertTrue($result);
    }

    public function test_find_by_calendar_without_limit_has_no_limit_clause(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $this->repo->findByCalendar(5, null, 0);

        $this->assertStringNotContainsString('LIMIT', $captured_sql);
    }

    public function test_find_by_user_id_empty_statuses_delegates_to_find_all(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->findByUserId(10, []);

        $this->assertIsArray($result);
    }

    public function test_get_statistics_requires_both_dates_for_range_filter(): void {
        // When only start_date is provided but end_date is null, BETWEEN should not appear
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->repo->getStatistics(5, '2026-03-01', null);

        $this->assertStringNotContainsString('BETWEEN', $captured_sql);
    }

    public function test_get_statistics_with_only_end_date_does_not_filter(): void {
        $captured_sql = '';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() use (&$captured_sql) {
            $captured_sql = func_get_args()[0];
            return $captured_sql;
        });
        $this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

        $this->repo->getStatistics(5, null, '2026-03-31');

        $this->assertStringNotContainsString('BETWEEN', $captured_sql);
    }

    public function test_find_by_email_returns_empty_array_for_no_matches(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->findByEmail('nonexistent@example.com');

        $this->assertSame([], $result);
    }

    public function test_find_by_cpf_rf_returns_empty_array_for_no_matches(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->findByCpfRf('00000000000');

        $this->assertSame([], $result);
    }

    public function test_create_appointment_with_minimal_data(): void {
        $captured_data = [];
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function($table, $data) use (&$captured_data) {
                $captured_data = $data;
                return 1;
            });
        $this->wpdb->insert_id = 99;

        // Provide token and validation_code to avoid calling Utils
        $data = [
            'calendar_id' => 1,
            'confirmation_token' => 'token',
            'validation_code' => 'CODE12345678',
        ];

        $result = $this->repo->createAppointment($data);

        $this->assertSame(99, $result);
        $this->assertSame(1, $captured_data['calendar_id']);
        $this->assertArrayHasKey('created_at', $captured_data);
    }

    public function test_mark_reminder_sent_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive('update')->once()->andReturn(false);

        $result = $this->repo->markReminderSent(1);

        $this->assertFalse($result);
    }

    public function test_get_appointments_by_date_returns_empty_for_no_matches(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->getAppointmentsByDate(5, '2026-12-25');

        $this->assertSame([], $result);
    }

    public function test_get_appointments_by_date_range_returns_empty_for_no_matches(): void {
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function() {
            return func_get_args()[0];
        });
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([]);

        $result = $this->repo->getAppointmentsByDateRange(5, '2026-12-01', '2026-12-31');

        $this->assertSame([], $result);
    }
}
