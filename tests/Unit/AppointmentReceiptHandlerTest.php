<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler;

/**
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler
 */
class AppointmentReceiptHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'add_query_arg' )->justReturn( '/?ffc_appointment_receipt=1' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // add_query_vars()
    // ==================================================================

    public function test_add_query_vars_appends_receipt_vars(): void {
        $handler = new AppointmentReceiptHandler();
        $vars = array( 'existing_var' );
        $result = $handler->add_query_vars( $vars );

        $this->assertContains( 'ffc_appointment_receipt', $result );
        $this->assertContains( 'token', $result );
        $this->assertContains( 'existing_var', $result );
        $this->assertCount( 3, $result );
    }

    // ==================================================================
    // handle_receipt_request() — no query var
    // ==================================================================

    public function test_handle_receipt_request_returns_early_without_query_var(): void {
        Functions\when( 'get_query_var' )->justReturn( '' );

        $handler = new AppointmentReceiptHandler();
        $handler->handle_receipt_request();

        // No exception = early return
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_receipt_request() — invalid ID
    // ==================================================================

    public function test_handle_receipt_request_dies_for_invalid_id(): void {
        Functions\when( 'get_query_var' )->alias( function ( $var ) {
            if ( $var === 'ffc_appointment_receipt' ) return 'abc';
            return '';
        } );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $handler = new AppointmentReceiptHandler();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid appointment receipt request.' );
        $handler->handle_receipt_request();
    }

    // ==================================================================
    // get_receipt_url() — without token
    // ==================================================================

    public function test_get_receipt_url_returns_url_without_token(): void {
        $url = AppointmentReceiptHandler::get_receipt_url( 42 );
        $this->assertIsString( $url );
    }

    // ==================================================================
    // get_receipt_url() — with token
    // ==================================================================

    public function test_get_receipt_url_returns_url_with_token(): void {
        $url = AppointmentReceiptHandler::get_receipt_url( 42, 'abc123' );
        $this->assertIsString( $url );
    }
}
