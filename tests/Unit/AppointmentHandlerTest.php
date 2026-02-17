<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;

/**
 * Tests for AppointmentHandler: appointment booking flow, end-time calculation,
 * status determination, available slots algorithm, and cancellation logic.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentHandler
 */
class AppointmentHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private AppointmentHandler $handler;
    private $calendarRepo;
    private $appointmentRepo;
    private $blockedDateRepo;
    private $validator;
    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( true )->byDefault();

        // ------------------------------------------------------------------
        // Global WP stubs (always register, even if defined from prior test)
        // ------------------------------------------------------------------
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_timezone' )->alias( function() { return new \DateTimeZone( 'UTC' ); } );

        // ------------------------------------------------------------------
        // Namespaced stubs: FreeFormCertificate\SelfScheduling\*
        // ------------------------------------------------------------------
        Functions\when( 'FreeFormCertificate\SelfScheduling\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\SelfScheduling\is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'FreeFormCertificate\SelfScheduling\do_action' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\SelfScheduling\current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'FreeFormCertificate\SelfScheduling\get_current_user_id' )->justReturn( 1 );
        Functions\when( 'FreeFormCertificate\SelfScheduling\is_user_logged_in' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\SelfScheduling\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\SelfScheduling\apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'FreeFormCertificate\SelfScheduling\wp_timezone' )->alias( function() { return new \DateTimeZone( 'UTC' ); } );

        // Repositories namespace stubs
        Functions\when( 'FreeFormCertificate\Repositories\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Repositories\user_can' )->justReturn( false );

        // Scheduling namespace
        Functions\when( 'FreeFormCertificate\Scheduling\get_option' )->justReturn( array() );

        // Core namespace stubs
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();

        // Generators namespace stubs (for MagicLinkHelper)
        Functions\when( 'FreeFormCertificate\Generators\home_url' )->alias( function( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'FreeFormCertificate\Generators\trailingslashit' )->alias( function( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'FreeFormCertificate\Generators\wp_parse_url' )->alias( function( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'FreeFormCertificate\Generators\esc_url' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_attr' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_html' )->returnArg();

        // Create handler — constructor creates real repos using mocked $wpdb
        $this->handler = new AppointmentHandler();

        // Replace dependencies with mocks via Reflection
        $this->ref = new \ReflectionClass( AppointmentHandler::class );

        $this->calendarRepo = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
        $this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
        $this->blockedDateRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
        $this->validator = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentValidator' );

        $this->setPrivate( 'calendar_repository', $this->calendarRepo );
        $this->setPrivate( 'appointment_repository', $this->appointmentRepo );
        $this->setPrivate( 'blocked_date_repository', $this->blockedDateRepo );
        $this->setPrivate( 'validator', $this->validator );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function setPrivate( string $name, $value ): void {
        $prop = $this->ref->getProperty( $name );
        $prop->setAccessible( true );
        $prop->setValue( $this->handler, $value );
    }

    private function makeCalendar( array $overrides = [] ): array {
        return array_merge( array(
            'id'                       => 1,
            'title'                    => 'Test Calendar',
            'status'                   => 'active',
            'slot_duration'            => 30,
            'slot_interval'            => 0,
            'max_appointments_per_slot' => 1,
            'requires_approval'        => 0,
            'allow_cancellation'       => 1,
            'cancellation_min_hours'   => 0,
            'email_config'             => '{}',
            'working_hours'            => array(),
        ), $overrides );
    }

    // ==================================================================
    // process_appointment() — Booking flow
    // ==================================================================

    public function test_process_calendar_not_found(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->with( 1 )->andReturn( null );

        $result = $this->handler->process_appointment( array( 'calendar_id' => 1 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_calendar', $result->get_error_code() );
    }

    public function test_process_calendar_inactive(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'status' => 'inactive' ) )
        );

        $result = $this->handler->process_appointment( array( 'calendar_id' => 1 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_inactive', $result->get_error_code() );
    }

    public function test_process_consent_required(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '',
            'user_ip'          => '127.0.0.1',
        );

        $result = $this->handler->process_appointment( $data );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'consent_required', $result->get_error_code() );
    }

    public function test_process_status_pending_when_requires_approval(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'requires_approval' => 1 ) )
        );

        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->once()
            ->with( Mockery::on( function( $data ) {
                return $data['status'] === 'pending';
            } ) )
            ->andReturn( 100 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 100 )->andReturn( array(
            'id' => 100, 'confirmation_token' => 'tok123',
        ) );

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
            'user_ip'          => '127.0.0.1',
        );

        $result = $this->handler->process_appointment( $data );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertTrue( $result['requires_approval'] );
    }

    public function test_process_status_confirmed_without_approval(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'requires_approval' => 0 ) )
        );

        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->once()
            ->with( Mockery::on( function( $data ) {
                return $data['status'] === 'confirmed'
                    && ! empty( $data['approved_at'] );
            } ) )
            ->andReturn( 101 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 101 )->andReturn( array(
            'id' => 101, 'confirmation_token' => 'tok456',
        ) );

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '10:00',
            'consent_given'    => '1',
            'user_ip'          => '1.2.3.4',
        );

        $result = $this->handler->process_appointment( $data );

        $this->assertIsArray( $result );
        $this->assertFalse( $result['requires_approval'] );
    }

    public function test_process_validation_failure_rolls_back(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn(
            new \WP_Error( 'slot_full', 'Slot is full' )
        );
        $this->appointmentRepo->shouldReceive( 'rollback' )->once();

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
            'user_ip'          => '1.2.3.4',
        );

        $result = $this->handler->process_appointment( $data );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'slot_full', $result->get_error_code() );
    }

    public function test_process_creation_failure_rolls_back(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->andReturn( null );
        $this->appointmentRepo->shouldReceive( 'rollback' )->once();

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
            'user_ip'          => '1.2.3.4',
        );

        $result = $this->handler->process_appointment( $data );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'creation_failed', $result->get_error_code() );
    }

    // ==================================================================
    // get_available_slots() — Slot calculation
    // ==================================================================

    public function test_available_slots_calendar_not_found(): void {
        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( null );

        $result = $this->handler->get_available_slots( 1, '2026-03-01' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_available_slots_inactive_calendar(): void {
        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn(
            $this->makeCalendar( array( 'status' => 'inactive' ) )
        );

        $result = $this->handler->get_available_slots( 1, '2026-03-01' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_available_slots_generates_from_working_hours(): void {
        // Monday 2026-03-02 (day_of_week=1)
        $calendar = $this->makeCalendar( array(
            'slot_duration'            => 30,
            'slot_interval'            => 0,
            'max_appointments_per_slot' => 2,
            'working_hours'            => array(
                array( 'day' => 1, 'start' => '09:00', 'end' => '10:00' ),
            ),
        ) );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $calendar );
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array() );
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false );

        // userHasSchedulingBypass returns false (current_user_can returns false from stub)
        // is_global_holiday returns false (get_option returns empty array)

        $result = $this->handler->get_available_slots( 1, '2026-03-02' );

        // 09:00-10:00 with 30min slots = 2 slots (09:00 and 09:30)
        $this->assertIsArray( $result );
        $this->assertCount( 2, $result );
        $this->assertSame( '09:00:00', $result[0]['time'] );
        $this->assertSame( '09:30:00', $result[1]['time'] );
        $this->assertSame( 2, $result[0]['available'] );
    }

    public function test_available_slots_reduces_by_existing_appointments(): void {
        $calendar = $this->makeCalendar( array(
            'slot_duration'            => 30,
            'slot_interval'            => 0,
            'max_appointments_per_slot' => 2,
            'working_hours'            => array(
                array( 'day' => 1, 'start' => '09:00', 'end' => '10:00' ),
            ),
        ) );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $calendar );
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array(
            array( 'start_time' => '09:00:00' ),
            array( 'start_time' => '09:00:00' ), // 09:00 full
        ) );
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false );

        $result = $this->handler->get_available_slots( 1, '2026-03-02' );

        // 09:00 full (2/2), only 09:30 available
        $this->assertCount( 1, $result );
        $this->assertSame( '09:30:00', $result[0]['time'] );
    }

    public function test_available_slots_no_working_hours_returns_empty(): void {
        // Tuesday 2026-03-03 (day=2) — no hours for day 2
        $calendar = $this->makeCalendar( array(
            'working_hours' => array(
                array( 'day' => 1, 'start' => '09:00', 'end' => '17:00' ),
            ),
        ) );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $calendar );
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false );

        $result = $this->handler->get_available_slots( 1, '2026-03-03' );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    public function test_available_slots_with_interval_between_slots(): void {
        $calendar = $this->makeCalendar( array(
            'slot_duration'            => 30,
            'slot_interval'            => 10,
            'max_appointments_per_slot' => 1,
            'working_hours'            => array(
                array( 'day' => 1, 'start' => '09:00', 'end' => '10:30' ),
            ),
        ) );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $calendar );
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array() );
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false );

        $result = $this->handler->get_available_slots( 1, '2026-03-02' );

        // 30min + 10min interval = 40min per cycle
        // 09:00, 09:40, 10:20 (10:20 < 10:30)
        $this->assertCount( 3, $result );
        $this->assertSame( '09:00', $result[0]['display'] );
        $this->assertSame( '09:40', $result[1]['display'] );
        $this->assertSame( '10:20', $result[2]['display'] );
    }

    // ==================================================================
    // cancel_appointment()
    // ==================================================================

    public function test_cancel_appointment_not_found(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( null );

        $result = $this->handler->cancel_appointment( 999 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_found', $result->get_error_code() );
    }

    public function test_cancel_already_cancelled(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'cancelled',
            'user_id' => 0, 'confirmation_token' => '',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );

        // Bypass check: current_user_can returns false (no bypass)
        // But token matches empty string — unauthorized path
        // Actually, the code checks bypass FIRST (returns false),
        // then logged in (false), then token match. Empty token = no match.
        // So it goes to "unauthorized" before checking "already_cancelled".
        // BUT — already_cancelled check is AFTER permission check.
        // For admin bypass test, we need FreeFormCertificate\Repositories\current_user_can to return true.

        // Let bypass succeed so we reach the status check
        Functions\when( 'FreeFormCertificate\Repositories\current_user_can' )->justReturn( true );

        $result = $this->handler->cancel_appointment( 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'already_cancelled', $result->get_error_code() );
    }

    public function test_cancel_unauthorized_wrong_token(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 99, 'confirmation_token' => 'secret',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );

        $result = $this->handler->cancel_appointment( 1, 'wrong-token' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unauthorized', $result->get_error_code() );
    }

    public function test_cancel_with_valid_token_succeeds(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => 'valid-token-123',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'cancel' )
            ->with( 1, null, 'Changed plans' )
            ->andReturn( true );

        $result = $this->handler->cancel_appointment( 1, 'valid-token-123', 'Changed plans' );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // Repository accessors
    // ==================================================================

    public function test_get_calendar_repository(): void {
        $this->assertSame( $this->calendarRepo, $this->handler->get_calendar_repository() );
    }

    public function test_get_appointment_repository(): void {
        $this->assertSame( $this->appointmentRepo, $this->handler->get_appointment_repository() );
    }

    public function test_get_blocked_date_repository(): void {
        $this->assertSame( $this->blockedDateRepo, $this->handler->get_blocked_date_repository() );
    }
}
