<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\EmailHandler;

/**
 * Tests for EmailHandler::send_wp_user_notification() context logic.
 */
class EmailHandlerContextTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Namespaced stub: prevent "is not defined" error when Sprint 27 tests run first.
        // Core\Debug calls get_option from the Core namespace.
        Functions\when( 'FreeFormCertificate\Core\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Global disable prevents all contexts from sending.
     */
    public function test_global_disable_prevents_all_emails(): void {
        Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => 1 ) );

        $handler = new EmailHandler();
        $this->assertFalse( $handler->send_wp_user_notification( 1, 'submission' ) );
        $this->assertFalse( $handler->send_wp_user_notification( 1, 'appointment' ) );
        $this->assertFalse( $handler->send_wp_user_notification( 1, 'csv_import' ) );
        $this->assertFalse( $handler->send_wp_user_notification( 1, 'migration' ) );
    }

    /**
     * Submission context defaults to enabled.
     */
    public function test_submission_enabled_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_new_user_notification' )->once()->with( 1, null, 'user' );

        $handler = new EmailHandler();
        $result = $handler->send_wp_user_notification( 1, 'submission' );

        $this->assertTrue( $result );
    }

    /**
     * Appointment context defaults to enabled.
     */
    public function test_appointment_enabled_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_new_user_notification' )->once();

        $handler = new EmailHandler();
        $result = $handler->send_wp_user_notification( 1, 'appointment' );

        $this->assertTrue( $result );
    }

    /**
     * CSV import context defaults to disabled.
     */
    public function test_csv_import_disabled_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $handler = new EmailHandler();
        $result = $handler->send_wp_user_notification( 1, 'csv_import' );

        $this->assertFalse( $result );
    }

    /**
     * Migration context defaults to disabled.
     */
    public function test_migration_disabled_by_default(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $handler = new EmailHandler();
        $result = $handler->send_wp_user_notification( 1, 'migration' );

        $this->assertFalse( $result );
    }

    /**
     * Explicit setting overrides default.
     */
    public function test_explicit_setting_overrides_default(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'send_wp_user_email_submission' => '0',
            'send_wp_user_email_csv_import' => '1',
        ) );
        Functions\when( 'absint' )->alias( 'intval' );
        Functions\when( 'wp_new_user_notification' )->justReturn();

        $handler = new EmailHandler();

        // Submission forced off.
        $this->assertFalse( $handler->send_wp_user_notification( 1, 'submission' ) );

        // CSV import forced on.
        $this->assertTrue( $handler->send_wp_user_notification( 1, 'csv_import' ) );
    }
}
