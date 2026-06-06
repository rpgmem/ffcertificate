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

    // ==================================================================
    // handle_receipt_request() — appointment not found
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_receipt_request_dies_when_not_found(): void {
        $this->stub_query_var( '7', '' );
        Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );

        Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' )
            ->shouldReceive( 'findById' )->with( 7 )->andReturn( null );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        $handler = new AppointmentReceiptHandler();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Appointment not found.' );
        $handler->handle_receipt_request();
    }

    // ==================================================================
    // handle_receipt_request() — no access (wrong token, not owner/admin)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_receipt_request_dies_without_access(): void {
        $this->stub_query_var( '7', 'wrong-token' );
        Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' )
            ->shouldReceive( 'findById' )->with( 7 )->andReturn(
                array( 'id' => 7, 'confirmation_token' => 'real-token', 'status' => 'confirmed', 'user_id' => 5 )
            );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        $handler = new AppointmentReceiptHandler();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        $handler->handle_receipt_request();
    }

    // ==================================================================
    // handle_receipt_request() — pending appointment blocked for non-admin
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_receipt_request_blocks_pending_for_non_admin(): void {
        $this->stub_query_var( '7', 'tok' );
        Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );

        Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' )
            ->shouldReceive( 'findById' )->with( 7 )->andReturn(
                array( 'id' => 7, 'confirmation_token' => 'tok', 'status' => 'pending', 'user_id' => 5 )
            );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        $handler = new AppointmentReceiptHandler();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'awaiting admin approval' );
        $handler->handle_receipt_request();
    }

    // ==================================================================
    // handle_receipt_request() — success renders + uses deleted-calendar
    //                            placeholder, decryption + date formatting
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_receipt_request_renders_receipt_for_admin(): void {
        $this->stub_query_var( '7', '' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'language_attributes' )->justReturn( null );
        Functions\when( 'bloginfo' )->justReturn( null );
        Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_print_scripts' )->justReturn( true );

        // Calendar deleted (calendar_id empty) → placeholder branch.
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' )
            ->shouldReceive( 'findById' )->with( 7 )->andReturn(
                array(
                    'id'              => 7,
                    'status'          => 'confirmed',
                    'user_id'         => 1,
                    'name'            => 'Alice',
                    'email_encrypted' => 'ENC_EMAIL',
                    'phone_encrypted' => 'ENC_PHONE',
                    'appointment_date' => '2026-05-20',
                    'start_time'      => '09:00:00',
                    'end_time'        => '10:00:00',
                    'created_at'      => '2026-05-01 12:00:00',
                    'validation_code' => 'AC1234567890',
                    'user_notes'      => "line1\nline2",
                    'calendar_id'     => 0,
                )
            );
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
        Mockery::mock( 'alias:FreeFormCertificate\Generators\PdfGenerator' )
            ->shouldReceive( 'generate_appointment_pdf_data' )->andReturn( 'PDFDATA' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'decrypt' )->with( 'ENC_EMAIL' )->andReturn( 'alice@example.com' )
            ->shouldReceive( 'decrypt' )->with( 'ENC_PHONE' )->andReturn( '+5511999990000' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
            ->shouldReceive( 'format_wallclock_date' )->andReturn( '20/05/2026' )
            ->shouldReceive( 'format_wallclock_time' )->andReturn( '09:00' )
            ->shouldReceive( 'format_datetime' )->andReturn( '01/05/2026 12:00' );
        // DocumentFormatter is pure (no DB) and exposes PREFIX_APPOINTMENT as a
        // real const the handler references — use the real class.

        // wp_localize_script runs near the end of display_receipt(), after all
        // the data prep + markup; throw there to stop before the caller's exit.
        Functions\when( 'wp_localize_script' )->alias(
            static function () {
                throw new \RuntimeException( 'localized' );
            }
        );

        $handler = new AppointmentReceiptHandler();

        ob_start();
        try {
            $handler->handle_receipt_request();
            ob_end_clean();
            $this->fail( 'Expected display_receipt to short-circuit at wp_localize_script.' );
        } catch ( \RuntimeException $e ) {
            ob_end_clean();
            $this->assertSame( 'localized', $e->getMessage() );
        }
    }

    /**
     * Stub get_query_var to return the receipt id + token.
     */
    private function stub_query_var( string $id, string $token ): void {
        Functions\when( 'get_query_var' )->alias(
            static function ( $var ) use ( $id, $token ) {
                if ( 'ffc_appointment_receipt' === $var ) {
                    return $id;
                }
                if ( 'token' === $var ) {
                    return $token;
                }
                return '';
            }
        );
    }
}
