<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentAjaxHandler;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;

/**
 * Tests for AppointmentAjaxHandler: AJAX endpoints for booking,
 * available slots, cancellation, and monthly booking counts.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentAjaxHandler
 */
class AppointmentAjaxHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private AppointmentAjaxHandler $ajax_handler;

    /** @var \Mockery\MockInterface&AppointmentHandler */
    private $handler;

    /** @var array<int, array{type: string, data: mixed}> Captured JSON responses in order */
    private array $json_responses = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();

        // WordPress stubs
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 0 );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        // wp_hash / wp_rand stubs (used by SecurityService captcha)
        Functions\when( 'wp_hash' )->alias( function ( $data ) {
            return md5( $data . 'test-salt' );
        } );
        Functions\when( 'FreeFormCertificate\Core\wp_rand' )->alias( function ( $min = 0, $max = 0 ) {
            return rand( $min, $max );
        } );
        Functions\when( 'wp_rand' )->alias( function ( $min = 0, $max = 0 ) {
            return rand( $min, $max );
        } );

        // Capture JSON responses and throw to halt execution (simulates wp_die)
        $this->json_responses = array();

        $responses = &$this->json_responses;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'success', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_success' );
        } );

        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'error', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_error' );
        } );

        // Mock $wpdb for repo constructors
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        // Create a mock AppointmentHandler
        $this->handler = Mockery::mock( AppointmentHandler::class );
        $this->handler->shouldReceive( 'get_appointment_repository' )->andReturn(
            Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' )
        )->byDefault();
        $this->handler->shouldReceive( 'get_calendar_repository' )->andReturn(
            Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' )
        )->byDefault();
        $this->handler->shouldReceive( 'get_blocked_date_repository' )->andReturn(
            Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' )
        )->byDefault();

        $this->ajax_handler = new AppointmentAjaxHandler( $this->handler );
    }

    protected function tearDown(): void {
        unset(
            $_POST['nonce'],
            $_POST['calendar_id'],
            $_POST['date'],
            $_POST['time'],
            $_POST['name'],
            $_POST['email'],
            $_POST['cpf_rf'],
            $_POST['notes'],
            $_POST['consent'],
            $_POST['consent_text'],
            $_POST['custom_data'],
            $_POST['appointment_id'],
            $_POST['token'],
            $_POST['reason'],
            $_POST['year'],
            $_POST['month'],
            $_POST['ffc_honeypot_trap'],
            $_POST['ffc_captcha_ans'],
            $_POST['ffc_captcha_hash'],
            $_SERVER['HTTP_USER_AGENT']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Get the first JSON response of a given type.
     */
    private function firstResponse( string $type = 'error' ): ?array {
        foreach ( $this->json_responses as $resp ) {
            if ( $resp['type'] === $type ) {
                return $resp['data'];
            }
        }
        return null;
    }

    /**
     * Generate a valid captcha hash for a given answer using the same
     * logic as SecurityService (wp_hash is stubbed as md5 + salt).
     */
    private function makeCaptchaHash( int $answer ): string {
        return md5( $answer . 'ffc_math_salt' . 'test-salt' );
    }

    /**
     * Call an AJAX method, catching the RuntimeException thrown by JSON response mocks.
     */
    private function callAjax( string $method ): void {
        try {
            $this->ajax_handler->$method();
        } catch ( \RuntimeException $e ) {
            // Expected: wp_send_json_success/error halts execution
        }
    }

    // ==================================================================
    // ajax_get_available_slots()
    // ==================================================================

    public function test_get_available_slots_missing_params_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // calendar_id and date are missing

        $this->callAjax( 'ajax_get_available_slots' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertStringContainsString( 'Invalid parameters', $error['message'] );
    }

    public function test_get_available_slots_wp_error_sends_error(): void {
        $_POST['nonce'] = 'valid';
        $_POST['calendar_id'] = '1';
        $_POST['date'] = '2026-03-15';

        $this->handler->shouldReceive( 'get_available_slots' )
            ->with( 1, '2026-03-15' )
            ->andReturn( new \WP_Error( 'calendar_inactive', 'Calendar is inactive' ) );

        $this->callAjax( 'ajax_get_available_slots' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertSame( 'Calendar is inactive', $error['message'] );
    }

    public function test_get_available_slots_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['calendar_id'] = '1';
        $_POST['date'] = '2026-03-15';

        $slots = array(
            array( 'time' => '09:00:00', 'available' => 2 ),
            array( 'time' => '09:30:00', 'available' => 1 ),
        );

        $this->handler->shouldReceive( 'get_available_slots' )
            ->with( 1, '2026-03-15' )
            ->andReturn( $slots );

        $this->callAjax( 'ajax_get_available_slots' );

        $success = $this->firstResponse( 'success' );
        $this->assertNotNull( $success );
        $this->assertSame( $slots, $success['slots'] );
        $this->assertSame( '2026-03-15', $success['date'] );
    }

    // ==================================================================
    // ajax_cancel_appointment()
    // ==================================================================

    public function test_cancel_appointment_missing_id_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // appointment_id missing

        $this->callAjax( 'ajax_cancel_appointment' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
    }

    public function test_cancel_appointment_wp_error_sends_error(): void {
        $_POST['nonce'] = 'valid';
        $_POST['appointment_id'] = '42';
        $_POST['token'] = 'tok123';
        $_POST['reason'] = 'Changed plans';

        $this->handler->shouldReceive( 'cancel_appointment' )
            ->with( 42, 'tok123', 'Changed plans' )
            ->andReturn( new \WP_Error( 'not_found', 'Appointment not found' ) );

        $this->callAjax( 'ajax_cancel_appointment' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertSame( 'not_found', $error['code'] );
    }

    public function test_cancel_appointment_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['appointment_id'] = '42';
        $_POST['token'] = 'tok123';

        $this->handler->shouldReceive( 'cancel_appointment' )
            ->with( 42, 'tok123', '' )
            ->andReturn( true );

        $this->callAjax( 'ajax_cancel_appointment' );

        $success = $this->firstResponse( 'success' );
        $this->assertNotNull( $success );
        $this->assertStringContainsString( 'cancelled successfully', $success['message'] );
    }

    // ==================================================================
    // ajax_book_appointment()
    // ==================================================================

    public function test_book_appointment_missing_fields_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // Provide valid captcha so security check passes
        $_POST['ffc_captcha_ans'] = '7';
        $_POST['ffc_captcha_hash'] = $this->makeCaptchaHash( 7 );
        // calendar_id, date, time missing

        $this->callAjax( 'ajax_book_appointment' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertStringContainsString( 'Missing required fields', $error['message'] );
    }

    public function test_book_appointment_security_failure_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // Trigger honeypot
        $_POST['ffc_honeypot_trap'] = 'bot-value';
        $_POST['ffc_captcha_ans'] = '7';
        $_POST['ffc_captcha_hash'] = $this->makeCaptchaHash( 7 );
        $_POST['calendar_id'] = '1';
        $_POST['date'] = '2026-03-15';
        $_POST['time'] = '09:00';

        $this->callAjax( 'ajax_book_appointment' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertStringContainsString( 'Honeypot', $error['message'] );
    }

    public function test_book_appointment_wp_error_from_handler(): void {
        $_POST['nonce'] = 'valid';
        $_POST['calendar_id'] = '1';
        $_POST['date'] = '2026-03-15';
        $_POST['time'] = '09:00';
        $_POST['name'] = 'John';
        $_POST['email'] = 'john@example.com';
        $_POST['cpf_rf'] = '12345678901';
        $_POST['consent'] = '1';
        $_POST['ffc_captcha_ans'] = '7';
        $_POST['ffc_captcha_hash'] = $this->makeCaptchaHash( 7 );
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';

        $this->handler->shouldReceive( 'process_appointment' )
            ->once()
            ->andReturn( new \WP_Error( 'slot_full', 'Slot is full' ) );

        $this->callAjax( 'ajax_book_appointment' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
        $this->assertSame( 'slot_full', $error['code'] );
        $this->assertSame( 'Slot is full', $error['message'] );
    }

    // ==================================================================
    // ajax_get_month_bookings()
    // ==================================================================

    public function test_get_month_bookings_missing_calendar_sends_error(): void {
        $_POST['nonce'] = 'valid';
        // calendar_id missing

        $this->callAjax( 'ajax_get_month_bookings' );

        $error = $this->firstResponse( 'error' );
        $this->assertNotNull( $error );
    }

    public function test_get_month_bookings_success(): void {
        $_POST['nonce'] = 'valid';
        $_POST['calendar_id'] = '1';
        $_POST['year'] = '2026';
        $_POST['month'] = '3';

        $appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
        $appointmentRepo->shouldReceive( 'getBookingCountsByDateRange' )
            ->with( 1, '2026-03-01', Mockery::any() )
            ->andReturn( array( '2026-03-15' => 3 ) );

        $blockedRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
        $blockedRepo->shouldReceive( 'getBlockedDatesInRange' )
            ->andReturn( array() );

        $this->handler->shouldReceive( 'get_appointment_repository' )->andReturn( $appointmentRepo );
        $this->handler->shouldReceive( 'get_blocked_date_repository' )->andReturn( $blockedRepo );

        // DateBlockingService::get_global_holidays calls get_option('ffc_global_holidays')
        // which is already stubbed to return array()

        $this->callAjax( 'ajax_get_month_bookings' );

        $success = $this->firstResponse( 'success' );
        $this->assertNotNull( $success );
        $this->assertArrayHasKey( 'counts', $success );
        $this->assertArrayHasKey( 'holidays', $success );
    }
}
