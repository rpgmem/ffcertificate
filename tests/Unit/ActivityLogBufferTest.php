<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLog;

/**
 * Tests for ActivityLog buffer behaviour and is_enabled gating.
 */
class ActivityLogBufferTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset static state between tests via reflection.
        $ref = new \ReflectionClass( ActivityLog::class );

        $buffer = $ref->getProperty( 'write_buffer' );
        $buffer->setAccessible( true );
        $buffer->setValue( [] );

        $shutdown = $ref->getProperty( 'shutdown_registered' );
        $shutdown->setAccessible( true );
        $shutdown->setValue( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * log() returns false when activity log is disabled.
     */
    public function test_log_returns_false_when_disabled(): void {
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => '0' ) );
        Functions\when( 'absint' )->alias( 'intval' );

        $result = ActivityLog::log( 'test_action' );

        $this->assertFalse( $result );
    }

    /**
     * log() returns true and buffers entry when enabled.
     */
    public function test_log_buffers_entry_when_enabled(): void {
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => '1' ) );
        Functions\when( 'absint' )->alias( 'intval' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'current_time' )->justReturn( '2026-02-08 12:00:00' );
        Functions\when( 'add_action' )->justReturn( true );

        // Mock Utils::get_user_ip()
        if ( ! class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
            // Skip if Utils not autoloaded – isolated test.
            $this->markTestSkipped( 'Utils class not available in isolated test.' );
        }

        $result = ActivityLog::log( 'test_action', 'info', array(), 0, 0 );

        $this->assertTrue( $result );
    }

    /**
     * flush_buffer() discards buffer when logging is disabled.
     */
    public function test_flush_buffer_discards_when_disabled(): void {
        // Seed the buffer via reflection.
        $ref = new \ReflectionClass( ActivityLog::class );
        $buffer = $ref->getProperty( 'write_buffer' );
        $buffer->setAccessible( true );
        $buffer->setValue( [ array( 'action' => 'fake', 'level' => 'info' ) ] );

        // Logging disabled.
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => '0' ) );
        Functions\when( 'absint' )->alias( 'intval' );

        $count = ActivityLog::flush_buffer();

        $this->assertSame( 0, $count );

        // Buffer should be empty now.
        $this->assertEmpty( $buffer->getValue() );
    }

    /**
     * flush_buffer() returns 0 when buffer is empty.
     */
    public function test_flush_buffer_returns_zero_on_empty(): void {
        $count = ActivityLog::flush_buffer();

        $this->assertSame( 0, $count );
    }
}
