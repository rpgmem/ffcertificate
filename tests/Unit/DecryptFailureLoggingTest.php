<?php
/**
 * Tests for the decrypt_failure audit entry emitted by Encryption::decrypt.
 *
 * Centralizing the log there replaces a handful of silent-null fallbacks
 * across the codebase (`decrypt(...) ?? ''`, swallowed try/catch, etc.).
 * This file pins:
 *   - the log fires when decrypt returns null from a non-empty input,
 *   - it stays silent for empty input and for successful round-trips,
 *   - the payload is metadata-only (so it cannot leak plaintext or
 *     recurse back into Encryption via the ActivityLog sensitivity gate),
 *   - the write is disabled when the activity log is disabled.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * @coversNothing
 */
class DecryptFailureLoggingTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->resetActivityLogState();

        global $wpdb;
        $wpdb             = Mockery::mock( 'wpdb' );
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb       = $wpdb;

        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'current_time' )->justReturn( '2026-04-23 00:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
    }

    protected function tearDown(): void {
        $this->resetActivityLogState();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Enable the activity log via ffc_settings. Must run inside the test,
     * not setUp, because Brain\Monkey scopes stubs per test.
     */
    private function enableActivityLog(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'ffc_settings' === $key ) {
                return array( 'enable_activity_log' => 1 );
            }
            return $default;
        } );
        Functions\when( 'FreeFormCertificate\\Core\\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
    }

    /**
     * Disable the activity log (default state). Log calls must no-op.
     */
    private function disableActivityLog(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\\Core\\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
    }

    private function resetActivityLogState(): void {
        $ref = new \ReflectionClass( ActivityLog::class );

        $buffer = $ref->getProperty( 'write_buffer' );
        $buffer->setAccessible( true );
        $buffer->setValue( array() );

        $shutdown = $ref->getProperty( 'shutdown_registered' );
        $shutdown->setAccessible( true );
        $shutdown->setValue( false );

        $disabled = $ref->getProperty( 'logging_disabled' );
        $disabled->setAccessible( true );
        $disabled->setValue( false );

        $columns = $ref->getProperty( 'table_columns_cache' );
        $columns->setAccessible( true );
        $columns->setValue( null );
    }

    private function getWriteBuffer(): array {
        $ref = new \ReflectionClass( ActivityLog::class );
        $p   = $ref->getProperty( 'write_buffer' );
        $p->setAccessible( true );
        return $p->getValue();
    }

    // ==================================================================
    // Failure path
    // ==================================================================

    public function test_decrypt_failure_is_logged_when_activity_log_enabled(): void {
        $this->enableActivityLog();

        $result = Encryption::decrypt( '!!!invalid-base64!!!' );
        $this->assertNull( $result, 'Decrypt should still return null on failure.' );

        $buffer = $this->getWriteBuffer();
        $this->assertCount( 1, $buffer, 'Exactly one audit entry should be buffered.' );
        $this->assertSame( 'decrypt_failure', $buffer[0]['action'] );
        $this->assertSame( ActivityLog::LEVEL_WARNING, $buffer[0]['level'] );
    }

    public function test_decrypt_failure_log_context_is_metadata_only(): void {
        $this->enableActivityLog();

        Encryption::decrypt( '!!!invalid-base64!!!' );
        $buffer   = $this->getWriteBuffer();
        $context  = json_decode( $buffer[0]['context'], true );

        $this->assertIsArray( $context );
        $this->assertSame( strlen( '!!!invalid-base64!!!' ), $context['ciphertext_length'] );
        $this->assertFalse( $context['v2_prefix'] );
        // No key in context should map to something SensitiveFieldRegistry considers sensitive
        // — otherwise the log write would re-enter Encryption::encrypt on the gate path.
        $this->assertFalse(
            SensitiveFieldRegistry::contains_sensitive( $context ),
            'decrypt_failure context must not contain any sensitive keys.'
        );
    }

    public function test_decrypt_failure_log_flags_v2_prefix_when_present(): void {
        $this->enableActivityLog();

        // Well-formed v2: prefix but gibberish after — decoder should reject, still log.
        Encryption::decrypt( 'v2:not-valid-base64!!!' );
        $buffer  = $this->getWriteBuffer();
        $this->assertCount( 1, $buffer );
        $context = json_decode( $buffer[0]['context'], true );
        $this->assertTrue( $context['v2_prefix'] );
    }

    // ==================================================================
    // No-op paths
    // ==================================================================

    public function test_decrypt_empty_input_does_not_log(): void {
        $this->enableActivityLog();

        Encryption::decrypt( '' );
        $this->assertSame( array(), $this->getWriteBuffer() );
    }

    public function test_successful_decrypt_does_not_log(): void {
        $this->enableActivityLog();

        $ct = Encryption::encrypt( 'hello' );
        $this->assertNotNull( $ct );

        $pt = Encryption::decrypt( $ct );
        $this->assertSame( 'hello', $pt );
        $this->assertSame( array(), $this->getWriteBuffer() );
    }

    public function test_decrypt_failure_is_silent_when_activity_log_disabled(): void {
        $this->disableActivityLog();

        $result = Encryption::decrypt( '!!!invalid-base64!!!' );
        $this->assertNull( $result );

        // No buffer entry because ActivityLog::is_enabled() returned false.
        $this->assertSame( array(), $this->getWriteBuffer() );
    }
}
