<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;

/**
 * Comprehensive tests for ActivityLog: constants, static state management,
 * log buffering, flush behaviour, table creation, convenience wrappers,
 * column caching, enable/disable toggling, and delegated query methods.
 *
 * @covers \FreeFormCertificate\Core\ActivityLog
 */
class ActivityLogTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset ALL static state via reflection.
        $this->resetStaticState();

        // Set up global $wpdb mock.
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        // Default WP function stubs used by most tests.
        Functions\when('absint')->alias(function ($val) {
            return abs((int) $val);
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_time')->justReturn('2026-03-01 10:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('add_action')->justReturn(true);
    }

    protected function tearDown(): void {
        // Always reset static state after test to avoid leaking to next test.
        $this->resetStaticState();

        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset all private/protected static properties on ActivityLog to their defaults.
     */
    private function resetStaticState(): void {
        $ref = new \ReflectionClass(ActivityLog::class);

        $buffer = $ref->getProperty('write_buffer');
        $buffer->setAccessible(true);
        $buffer->setValue([]);

        $shutdown = $ref->getProperty('shutdown_registered');
        $shutdown->setAccessible(true);
        $shutdown->setValue(false);

        $disabled = $ref->getProperty('logging_disabled');
        $disabled->setAccessible(true);
        $disabled->setValue(false);

        $colCache = $ref->getProperty('table_columns_cache');
        $colCache->setAccessible(true);
        $colCache->setValue(null);
    }

    /**
     * Helper: read $write_buffer via reflection.
     *
     * @return array
     */
    private function getWriteBuffer(): array {
        $ref = new \ReflectionClass(ActivityLog::class);
        $prop = $ref->getProperty('write_buffer');
        $prop->setAccessible(true);
        return $prop->getValue();
    }

    /**
     * Helper: set $write_buffer via reflection.
     *
     * @param array $entries
     */
    private function setWriteBuffer(array $entries): void {
        $ref = new \ReflectionClass(ActivityLog::class);
        $prop = $ref->getProperty('write_buffer');
        $prop->setAccessible(true);
        $prop->setValue($entries);
    }

    /**
     * Helper: read $shutdown_registered via reflection.
     */
    private function getShutdownRegistered(): bool {
        $ref = new \ReflectionClass(ActivityLog::class);
        $prop = $ref->getProperty('shutdown_registered');
        $prop->setAccessible(true);
        return $prop->getValue();
    }

    /**
     * Helper: configure get_option to enable activity log.
     */
    private function enableActivityLog(): void {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_settings') {
                return ['enable_activity_log' => 1];
            }
            return $default;
        });
    }

    /**
     * Helper: configure get_option to disable activity log.
     */
    private function disableActivityLog(): void {
        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'ffc_settings') {
                return ['enable_activity_log' => 0];
            }
            return $default;
        });
    }

    // ==================================================================
    // Constants
    // ==================================================================

    public function test_level_constants_are_defined(): void {
        $this->assertSame('info', ActivityLog::LEVEL_INFO);
        $this->assertSame('warning', ActivityLog::LEVEL_WARNING);
        $this->assertSame('error', ActivityLog::LEVEL_ERROR);
        $this->assertSame('debug', ActivityLog::LEVEL_DEBUG);
    }

    public function test_buffer_threshold_constant(): void {
        $ref = new \ReflectionClass(ActivityLog::class);
        $constants = $ref->getConstants();
        $this->assertArrayHasKey('BUFFER_THRESHOLD', $constants);
        $this->assertSame(20, $constants['BUFFER_THRESHOLD']);
    }

    // ==================================================================
    // is_enabled()
    // ==================================================================

    public function test_is_enabled_returns_true_when_setting_is_one(): void {
        $this->enableActivityLog();
        $this->assertTrue(ActivityLog::is_enabled());
    }

    public function test_is_enabled_returns_false_when_setting_is_zero(): void {
        $this->disableActivityLog();
        $this->assertFalse(ActivityLog::is_enabled());
    }

    public function test_is_enabled_returns_false_when_setting_missing(): void {
        Functions\when('get_option')->justReturn([]);
        $this->assertFalse(ActivityLog::is_enabled());
    }

    public function test_is_enabled_returns_false_when_option_returns_empty_array(): void {
        Functions\when('get_option')->justReturn(array());
        $this->assertFalse(ActivityLog::is_enabled());
    }

    // ==================================================================
    // log() — basic behaviour
    // ==================================================================

    public function test_log_returns_false_when_disabled(): void {
        $this->disableActivityLog();

        $result = ActivityLog::log('test_action');
        $this->assertFalse($result);
    }

    public function test_log_returns_false_when_logging_temporarily_disabled(): void {
        $this->enableActivityLog();

        ActivityLog::disable_logging();
        $result = ActivityLog::log('test_action');
        $this->assertFalse($result);
    }

    public function test_log_returns_true_when_enabled(): void {
        $this->enableActivityLog();

        // Utils::get_user_ip() will be called - it reads $_SERVER, returns '0.0.0.0' when empty.
        $result = ActivityLog::log('some_action', ActivityLog::LEVEL_INFO, [], 5, 10);
        $this->assertTrue($result);
    }

    public function test_log_adds_entry_to_buffer(): void {
        $this->enableActivityLog();

        ActivityLog::log('buffered_action', ActivityLog::LEVEL_WARNING, ['key' => 'val'], 7, 42);

        $buffer = $this->getWriteBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame('buffered_action', $buffer[0]['action']);
        $this->assertSame('warning', $buffer[0]['level']);
        $this->assertSame(7, $buffer[0]['user_id']);
        $this->assertSame(42, $buffer[0]['submission_id']);
        $this->assertSame('2026-03-01 10:00:00', $buffer[0]['created_at']);
    }

    public function test_log_registers_shutdown_hook_once(): void {
        $this->enableActivityLog();

        $addActionCalls = 0;
        Functions\when('add_action')->alias(function () use (&$addActionCalls) {
            $addActionCalls++;
            return true;
        });

        ActivityLog::log('first_action');
        // Second call should NOT register shutdown again.
        ActivityLog::log('second_action');

        $this->assertTrue($this->getShutdownRegistered());
        // add_action should have been called exactly once (for shutdown hook).
        $this->assertSame(1, $addActionCalls);
    }

    public function test_log_normalizes_invalid_level_to_info(): void {
        $this->enableActivityLog();

        ActivityLog::log('action_with_bad_level', 'invalid_level', [], 0, 0);

        $buffer = $this->getWriteBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame('info', $buffer[0]['level']);
    }

    public function test_log_accepts_all_valid_levels(): void {
        $this->enableActivityLog();

        $validLevels = [
            ActivityLog::LEVEL_INFO,
            ActivityLog::LEVEL_WARNING,
            ActivityLog::LEVEL_ERROR,
            ActivityLog::LEVEL_DEBUG,
        ];

        foreach ($validLevels as $level) {
            ActivityLog::log('test', $level);
        }

        $buffer = $this->getWriteBuffer();
        $this->assertCount(4, $buffer);

        foreach ($validLevels as $i => $level) {
            $this->assertSame($level, $buffer[$i]['level']);
        }
    }

    public function test_log_encodes_context_as_json(): void {
        $this->enableActivityLog();

        $context = ['form_id' => 5, 'status' => 'published'];
        ActivityLog::log('test_action', ActivityLog::LEVEL_INFO, $context);

        $buffer = $this->getWriteBuffer();
        $this->assertSame(json_encode($context), $buffer[0]['context']);
    }

    public function test_log_context_encrypted_is_set_for_sensitive_actions_when_encryption_available(): void {
        $this->enableActivityLog();

        // The Encryption class is autoloaded and configured (via bootstrap constants),
        // so sensitive actions like 'submission_created' get their context encrypted.
        ActivityLog::log('submission_created', ActivityLog::LEVEL_INFO, ['sensitive' => 'data']);

        $buffer = $this->getWriteBuffer();
        // context_encrypted should be a non-null string (encrypted blob).
        $this->assertNotNull($buffer[0]['context_encrypted']);
        $this->assertIsString($buffer[0]['context_encrypted']);
        $this->assertNotEmpty($buffer[0]['context_encrypted']);
    }

    public function test_log_context_encrypted_is_null_for_non_sensitive_actions(): void {
        $this->enableActivityLog();

        // Non-sensitive actions should NOT get encrypted context.
        ActivityLog::log('settings_changed', ActivityLog::LEVEL_INFO, ['key' => 'value']);

        $buffer = $this->getWriteBuffer();
        $this->assertNull($buffer[0]['context_encrypted']);
    }

    public function test_log_captures_user_ip(): void {
        $this->enableActivityLog();

        // Utils::get_user_ip() reads $_SERVER. With no SERVER vars set it returns '0.0.0.0'.
        ActivityLog::log('test');

        $buffer = $this->getWriteBuffer();
        $this->assertArrayHasKey('user_ip', $buffer[0]);
        // The IP should be a string (either a real IP or 0.0.0.0).
        $this->assertIsString($buffer[0]['user_ip']);
    }

    // ==================================================================
    // log() — auto-flush at BUFFER_THRESHOLD
    // ==================================================================

    public function test_log_auto_flushes_at_buffer_threshold(): void {
        $this->enableActivityLog();

        // Set up wpdb for flush_buffer() which will be triggered.
        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_col')->andReturn([
            'id', 'action', 'level', 'context', 'user_id', 'user_ip',
            'created_at', 'submission_id', 'context_encrypted',
        ]);
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        // Log exactly BUFFER_THRESHOLD entries (20).
        for ($i = 0; $i < 20; $i++) {
            ActivityLog::log("action_{$i}");
        }

        // Buffer should be empty after auto-flush.
        $buffer = $this->getWriteBuffer();
        $this->assertEmpty($buffer);
    }

    // ==================================================================
    // flush_buffer()
    // ==================================================================

    public function test_flush_buffer_returns_zero_when_buffer_empty(): void {
        $this->assertSame(0, ActivityLog::flush_buffer());
    }

    public function test_flush_buffer_discards_buffer_when_disabled(): void {
        $this->disableActivityLog();

        $this->setWriteBuffer([
            ['action' => 'test', 'level' => 'info'],
        ]);

        $count = ActivityLog::flush_buffer();

        $this->assertSame(0, $count);
        $this->assertEmpty($this->getWriteBuffer());
    }

    public function test_flush_buffer_inserts_entries_and_returns_count(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'action_one',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '127.0.0.1',
                'submission_id'     => 10,
                'created_at'        => '2026-03-01 10:00:00',
            ],
            [
                'action'            => 'action_two',
                'level'             => 'warning',
                'context'           => '{"key":"val"}',
                'context_encrypted' => null,
                'user_id'           => 2,
                'user_ip'           => '10.0.0.1',
                'submission_id'     => 20,
                'created_at'        => '2026-03-01 10:01:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        // get_table_columns_cached uses $wpdb->get_col via $wpdb->prepare.
        $this->wpdb->shouldReceive('prepare')
            ->andReturn('DESCRIBE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn([
                'id', 'action', 'level', 'context', 'user_id', 'user_ip',
                'created_at', 'submission_id', 'context_encrypted',
            ]);

        // Expect two insert calls (one per entry).
        $this->wpdb->shouldReceive('insert')
            ->twice()
            ->andReturn(1);

        $count = ActivityLog::flush_buffer();

        $this->assertSame(2, $count);
        $this->assertEmpty($this->getWriteBuffer());
    }

    public function test_flush_buffer_omits_submission_id_when_column_missing(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'test',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 5,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        // Columns WITHOUT submission_id or context_encrypted.
        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['id', 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at']);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_activity_log',
                Mockery::on(function ($data) {
                    return !array_key_exists('submission_id', $data)
                        && !array_key_exists('context_encrypted', $data);
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $count = ActivityLog::flush_buffer();
        $this->assertSame(1, $count);
    }

    public function test_flush_buffer_includes_submission_id_when_column_exists(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'test',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 42,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn([
                'id', 'action', 'level', 'context', 'user_id', 'user_ip',
                'created_at', 'submission_id',
            ]);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_activity_log',
                Mockery::on(function ($data) {
                    return array_key_exists('submission_id', $data)
                        && $data['submission_id'] === 42
                        && !array_key_exists('context_encrypted', $data);
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        $count = ActivityLog::flush_buffer();
        $this->assertSame(1, $count);
    }

    // ==================================================================
    // create_table()
    // ==================================================================

    public function test_create_table_returns_true_when_table_already_exists(): void {
        // get_charset_collate is called before table_exists check.
        $this->wpdb->shouldReceive('get_charset_collate')
            ->andReturn('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->wpdb->shouldReceive('prepare')
            ->with('SHOW TABLES LIKE %s', 'wp_ffc_activity_log')
            ->andReturn('SHOW TABLES LIKE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_var')
            ->andReturn('wp_ffc_activity_log');

        $result = ActivityLog::create_table();
        $this->assertTrue($result);
    }

    public function test_create_table_calls_dbdelta_when_table_missing(): void {
        $this->wpdb->shouldReceive('prepare')
            ->with('SHOW TABLES LIKE %s', 'wp_ffc_activity_log')
            ->andReturn('SHOW TABLES LIKE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(null);
        $this->wpdb->shouldReceive('get_charset_collate')
            ->andReturn('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        // dbDelta requires wp-admin/includes/upgrade.php. Mock it.
        Functions\when('dbDelta')->justReturn([]);

        $result = ActivityLog::create_table();
        $this->assertTrue($result);
    }

    // ==================================================================
    // disable_logging() / enable_logging()
    // ==================================================================

    public function test_disable_logging_prevents_log_from_buffering(): void {
        $this->enableActivityLog();

        ActivityLog::disable_logging();

        $result = ActivityLog::log('should_not_buffer');
        $this->assertFalse($result);
        $this->assertEmpty($this->getWriteBuffer());
    }

    public function test_enable_logging_restores_logging(): void {
        $this->enableActivityLog();

        ActivityLog::disable_logging();
        ActivityLog::enable_logging();

        $result = ActivityLog::log('should_buffer', ActivityLog::LEVEL_INFO, [], 0, 0);
        $this->assertTrue($result);
        $this->assertCount(1, $this->getWriteBuffer());
    }

    public function test_disable_then_enable_round_trip(): void {
        $this->enableActivityLog();

        ActivityLog::disable_logging();
        $this->assertFalse(ActivityLog::log('blocked'));
        $this->assertEmpty($this->getWriteBuffer());

        ActivityLog::enable_logging();
        $this->assertTrue(ActivityLog::log('allowed'));
        $this->assertCount(1, $this->getWriteBuffer());
    }

    // ==================================================================
    // get_table_columns_cached()
    // ==================================================================

    public function test_get_table_columns_cached_queries_on_first_call(): void {
        $expected = ['id', 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at'];

        $this->wpdb->shouldReceive('prepare')
            ->with('DESCRIBE %i', 'wp_ffc_activity_log')
            ->once()
            ->andReturn('DESCRIBE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn($expected);

        $result = ActivityLog::get_table_columns_cached('wp_ffc_activity_log');
        $this->assertSame($expected, $result);
    }

    public function test_get_table_columns_cached_returns_cache_on_second_call(): void {
        $expected = ['id', 'action', 'level'];

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('DESCRIBE wp_ffc_activity_log');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn($expected);

        // First call populates cache.
        ActivityLog::get_table_columns_cached('wp_ffc_activity_log');
        // Second call should use cache (no additional DB query).
        $result = ActivityLog::get_table_columns_cached('wp_ffc_activity_log');

        $this->assertSame($expected, $result);
    }

    // ==================================================================
    // clear_column_cache()
    // ==================================================================

    public function test_clear_column_cache_forces_re_query(): void {
        $columns_v1 = ['id', 'action'];
        $columns_v2 = ['id', 'action', 'submission_id'];

        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->twice()
            ->andReturn($columns_v1, $columns_v2);

        $result1 = ActivityLog::get_table_columns_cached('wp_ffc_activity_log');
        $this->assertSame($columns_v1, $result1);

        ActivityLog::clear_column_cache();

        $result2 = ActivityLog::get_table_columns_cached('wp_ffc_activity_log');
        $this->assertSame($columns_v2, $result2);
    }

    // ==================================================================
    // Convenience wrappers — log_submission_created
    // ==================================================================

    public function test_log_submission_created_calls_log_with_correct_params(): void {
        $this->enableActivityLog();

        $data = ['form_id' => 3, 'encrypted' => false];
        $result = ActivityLog::log_submission_created(99, $data);

        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame('submission_created', $buffer[0]['action']);
        $this->assertSame('info', $buffer[0]['level']);
        $this->assertSame(1, $buffer[0]['user_id']); // get_current_user_id returns 1
        $this->assertSame(99, $buffer[0]['submission_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_submission_updated
    // ==================================================================

    public function test_log_submission_updated_uses_correct_action_and_user(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_submission_updated(50, 7);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('submission_updated', $buffer[0]['action']);
        $this->assertSame('info', $buffer[0]['level']);
        $this->assertSame(7, $buffer[0]['user_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_submission_deleted
    // ==================================================================

    public function test_log_submission_deleted_uses_warning_level(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_submission_deleted(33, 5);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('submission_deleted', $buffer[0]['action']);
        $this->assertSame('warning', $buffer[0]['level']);
        $this->assertSame(5, $buffer[0]['user_id']);
    }

    public function test_log_submission_deleted_defaults_user_to_zero(): void {
        $this->enableActivityLog();

        ActivityLog::log_submission_deleted(33);

        $buffer = $this->getWriteBuffer();
        $this->assertSame(0, $buffer[0]['user_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_submission_trashed
    // ==================================================================

    public function test_log_submission_trashed_logs_with_current_user(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_submission_trashed(77);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('submission_trashed', $buffer[0]['action']);
        $this->assertSame('info', $buffer[0]['level']);
        $this->assertSame(1, $buffer[0]['user_id']); // get_current_user_id returns 1
        $this->assertSame(77, $buffer[0]['submission_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_submission_restored
    // ==================================================================

    public function test_log_submission_restored_logs_with_current_user(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_submission_restored(88);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('submission_restored', $buffer[0]['action']);
        $this->assertSame(88, $buffer[0]['submission_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_data_accessed (LGPD)
    // ==================================================================

    public function test_log_data_accessed_logs_info_level(): void {
        $this->enableActivityLog();

        $context = ['method' => 'admin_view'];
        $result = ActivityLog::log_data_accessed(55, $context);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('data_accessed', $buffer[0]['action']);
        $this->assertSame('info', $buffer[0]['level']);
        $this->assertSame(55, $buffer[0]['submission_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_access_denied
    // ==================================================================

    public function test_log_access_denied_logs_warning_level(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_access_denied('unauthorized', 'user@example.com');
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('access_denied', $buffer[0]['action']);
        $this->assertSame('warning', $buffer[0]['level']);
    }

    // ==================================================================
    // Convenience wrappers — log_settings_changed
    // ==================================================================

    public function test_log_settings_changed_logs_correct_action(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_settings_changed('general_tab', 3);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('settings_changed', $buffer[0]['action']);
        $this->assertSame(3, $buffer[0]['user_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_password_changed
    // ==================================================================

    public function test_log_password_changed_logs_for_given_user(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_password_changed(12);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('password_changed', $buffer[0]['action']);
        $this->assertSame(12, $buffer[0]['user_id']);
    }

    // ==================================================================
    // Convenience wrappers — log_profile_updated
    // ==================================================================

    public function test_log_profile_updated_includes_fields_in_context(): void {
        $this->enableActivityLog();

        $fields = ['display_name', 'email'];
        $result = ActivityLog::log_profile_updated(8, $fields);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('profile_updated', $buffer[0]['action']);
        $this->assertSame(8, $buffer[0]['user_id']);

        $decoded = json_decode($buffer[0]['context'], true);
        $this->assertSame($fields, $decoded['fields']);
    }

    // ==================================================================
    // Convenience wrappers — log_capabilities_granted
    // ==================================================================

    public function test_log_capabilities_granted_logs_context_and_caps(): void {
        $this->enableActivityLog();

        $caps = ['ffc_manage_certificates', 'ffc_view_submissions'];
        $result = ActivityLog::log_capabilities_granted(15, 'certificate', $caps);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('capabilities_granted', $buffer[0]['action']);
        // user_id comes from get_current_user_id() which returns 1
        $this->assertSame(1, $buffer[0]['user_id']);

        $decoded = json_decode($buffer[0]['context'], true);
        $this->assertSame('certificate', $decoded['context']);
        $this->assertSame($caps, $decoded['capabilities']);
    }

    // ==================================================================
    // Convenience wrappers — log_privacy_request
    // ==================================================================

    public function test_log_privacy_request_logs_type(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_privacy_request(20, 'export_personal_data');
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('privacy_request_created', $buffer[0]['action']);
        $this->assertSame(20, $buffer[0]['user_id']);

        $decoded = json_decode($buffer[0]['context'], true);
        $this->assertSame('export_personal_data', $decoded['type']);
    }

    // ==================================================================
    // Delegation methods exist (verify signatures)
    // ==================================================================

    public function test_get_activities_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'get_activities'));

        $ref = new \ReflectionMethod(ActivityLog::class, 'get_activities');
        $this->assertTrue($ref->isStatic());
        $this->assertTrue($ref->isPublic());
    }

    public function test_count_activities_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'count_activities'));

        $ref = new \ReflectionMethod(ActivityLog::class, 'count_activities');
        $this->assertTrue($ref->isStatic());
    }

    public function test_cleanup_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'cleanup'));

        $ref = new \ReflectionMethod(ActivityLog::class, 'cleanup');
        $this->assertTrue($ref->isStatic());
        $params = $ref->getParameters();
        $this->assertSame('days', $params[0]->getName());
        $this->assertSame(90, $params[0]->getDefaultValue());
    }

    public function test_run_cleanup_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'run_cleanup'));
    }

    public function test_get_stats_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'get_stats'));

        $ref = new \ReflectionMethod(ActivityLog::class, 'get_stats');
        $params = $ref->getParameters();
        $this->assertSame('days', $params[0]->getName());
        $this->assertSame(30, $params[0]->getDefaultValue());
    }

    public function test_get_submission_logs_method_exists(): void {
        $this->assertTrue(method_exists(ActivityLog::class, 'get_submission_logs'));

        $ref = new \ReflectionMethod(ActivityLog::class, 'get_submission_logs');
        $params = $ref->getParameters();
        $this->assertSame('submission_id', $params[0]->getName());
        $this->assertSame('limit', $params[1]->getName());
        $this->assertSame(100, $params[1]->getDefaultValue());
    }

    // ==================================================================
    // Static state isolation between tests
    // ==================================================================

    public function test_static_state_is_clean_after_setup(): void {
        // This verifies that setUp properly resets all static state.
        $this->assertEmpty($this->getWriteBuffer());
        $this->assertFalse($this->getShutdownRegistered());

        // $logging_disabled should be false.
        $ref = new \ReflectionClass(ActivityLog::class);
        $disabled = $ref->getProperty('logging_disabled');
        $disabled->setAccessible(true);
        $this->assertFalse($disabled->getValue());

        // $table_columns_cache should be null.
        $cache = $ref->getProperty('table_columns_cache');
        $cache->setAccessible(true);
        $this->assertNull($cache->getValue());
    }

    // ==================================================================
    // Multiple log calls accumulate in buffer
    // ==================================================================

    public function test_multiple_logs_accumulate_in_buffer(): void {
        $this->enableActivityLog();

        ActivityLog::log('action_1', ActivityLog::LEVEL_INFO);
        ActivityLog::log('action_2', ActivityLog::LEVEL_WARNING);
        ActivityLog::log('action_3', ActivityLog::LEVEL_ERROR);

        $buffer = $this->getWriteBuffer();
        $this->assertCount(3, $buffer);
        $this->assertSame('action_1', $buffer[0]['action']);
        $this->assertSame('action_2', $buffer[1]['action']);
        $this->assertSame('action_3', $buffer[2]['action']);
    }

    // ==================================================================
    // flush_buffer clears buffer after successful write
    // ==================================================================

    public function test_flush_buffer_clears_buffer_after_write(): void {
        $this->enableActivityLog();

        $this->setWriteBuffer([
            [
                'action'            => 'a',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 0,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 0,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ]);

        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')->andReturn([
            'id', 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at',
        ]);
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        ActivityLog::flush_buffer();

        $this->assertEmpty($this->getWriteBuffer());
    }

    // ==================================================================
    // Convenience wrappers return false when logging disabled
    // ==================================================================

    public function test_convenience_wrappers_return_false_when_disabled(): void {
        $this->disableActivityLog();

        $this->assertFalse(ActivityLog::log_submission_created(1));
        $this->assertFalse(ActivityLog::log_submission_updated(1, 1));
        $this->assertFalse(ActivityLog::log_submission_deleted(1));
        $this->assertFalse(ActivityLog::log_submission_trashed(1));
        $this->assertFalse(ActivityLog::log_submission_restored(1));
        $this->assertFalse(ActivityLog::log_data_accessed(1));
        $this->assertFalse(ActivityLog::log_access_denied('reason', 'id'));
        $this->assertFalse(ActivityLog::log_settings_changed('key', 1));
        $this->assertFalse(ActivityLog::log_password_changed(1));
        $this->assertFalse(ActivityLog::log_profile_updated(1));
        $this->assertFalse(ActivityLog::log_capabilities_granted(1, 'ctx'));
        $this->assertFalse(ActivityLog::log_privacy_request(1, 'export'));
    }

    // ==================================================================
    // log() with empty context
    // ==================================================================

    public function test_log_with_empty_context_encodes_as_empty_array(): void {
        $this->enableActivityLog();

        ActivityLog::log('empty_ctx', ActivityLog::LEVEL_DEBUG, []);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('[]', $buffer[0]['context']);
    }

    // ==================================================================
    // flush_buffer() format arrays
    // ==================================================================

    public function test_flush_buffer_uses_correct_format_for_base_columns(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'test',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 0,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        // Only base columns (no submission_id, no context_encrypted).
        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->andReturn(['id', 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at']);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_activity_log',
                Mockery::type('array'),
                // Base format: 6 string/int placeholders.
                ['%s', '%s', '%s', '%d', '%s', '%s']
            )
            ->andReturn(1);

        ActivityLog::flush_buffer();
    }

    public function test_flush_buffer_format_includes_submission_and_encrypted(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'test',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => 'encrypted_blob',
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 5,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        // All columns present.
        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->andReturn([
                'id', 'action', 'level', 'context', 'user_id', 'user_ip',
                'created_at', 'submission_id', 'context_encrypted',
            ]);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_activity_log',
                Mockery::type('array'),
                // Base 6 + submission_id (%d) + context_encrypted (%s).
                ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s']
            )
            ->andReturn(1);

        ActivityLog::flush_buffer();
    }

    // ==================================================================
    // DatabaseHelperTrait integration via create_table / table_exists
    // ==================================================================

    public function test_class_uses_database_helper_trait(): void {
        $ref = new \ReflectionClass(ActivityLog::class);
        $traits = $ref->getTraitNames();
        $this->assertContains(
            'FreeFormCertificate\Core\DatabaseHelperTrait',
            $traits
        );
    }

    // ==================================================================
    // Edge cases
    // ==================================================================

    public function test_log_with_zero_user_and_submission(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log('anonymous_action', ActivityLog::LEVEL_INFO, [], 0, 0);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame(0, $buffer[0]['user_id']);
        $this->assertSame(0, $buffer[0]['submission_id']);
    }

    public function test_log_sanitizes_action_and_level(): void {
        $this->enableActivityLog();

        // sanitize_text_field is mocked to returnArg, sanitize_key to returnArg,
        // but we confirm they are actually used by verifying data flows through.
        ActivityLog::log('my_action', ActivityLog::LEVEL_ERROR, [], 0, 0);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('my_action', $buffer[0]['action']);
        $this->assertSame('error', $buffer[0]['level']);
    }

    public function test_flush_buffer_with_null_context_encrypted_uses_empty_string(): void {
        $this->enableActivityLog();

        $entries = [
            [
                'action'            => 'test',
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 0,
                'created_at'        => '2026-03-01 10:00:00',
            ],
        ];

        $this->setWriteBuffer($entries);

        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->andReturn([
                'id', 'action', 'level', 'context', 'user_id', 'user_ip',
                'created_at', 'context_encrypted',
            ]);

        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_ffc_activity_log',
                Mockery::on(function ($data) {
                    // null coalesces to '' in the flush code.
                    return $data['context_encrypted'] === '';
                }),
                Mockery::type('array')
            )
            ->andReturn(1);

        ActivityLog::flush_buffer();
    }

    // ==================================================================
    // log_submission_created with empty data array
    // ==================================================================

    public function test_log_submission_created_with_empty_data(): void {
        $this->enableActivityLog();

        $result = ActivityLog::log_submission_created(1, []);
        $this->assertTrue($result);

        $buffer = $this->getWriteBuffer();
        $this->assertSame('[]', $buffer[0]['context']);
    }

    // ==================================================================
    // Multiple flushes re-query columns only once (cache)
    // ==================================================================

    public function test_flush_uses_cached_columns_across_calls(): void {
        $this->enableActivityLog();

        $makeEntry = function (string $action): array {
            return [
                'action'            => $action,
                'level'             => 'info',
                'context'           => '{}',
                'context_encrypted' => null,
                'user_id'           => 1,
                'user_ip'           => '0.0.0.0',
                'submission_id'     => 0,
                'created_at'        => '2026-03-01 10:00:00',
            ];
        };

        // get_col should only be called once (cached after first flush).
        $this->wpdb->shouldReceive('prepare')->andReturn('DESCRIBE ...');
        $this->wpdb->shouldReceive('get_col')
            ->once()
            ->andReturn(['id', 'action', 'level', 'context', 'user_id', 'user_ip', 'created_at']);
        $this->wpdb->shouldReceive('insert')->andReturn(1);

        // First flush.
        $this->setWriteBuffer([$makeEntry('first')]);
        ActivityLog::flush_buffer();

        // Second flush reuses cached columns.
        $this->setWriteBuffer([$makeEntry('second')]);
        ActivityLog::flush_buffer();
    }
}
