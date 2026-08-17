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
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
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
        Functions\when( 'wp_date' )->alias( function( $format, $ts = null, $tz = null ) { return gmdate( $format, $ts ?? time() ); } );

        // ------------------------------------------------------------------
        // Namespaced stubs: FreeFormCertificate\SelfScheduling\*
        // ------------------------------------------------------------------
        Functions\when( '__' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'wp_timezone' )->alias( function() { return new \DateTimeZone( 'UTC' ); } );

        // Repositories namespace stubs
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        // Scheduling namespace
        Functions\when( 'get_option' )->justReturn( array() );

        // Core namespace stubs
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Generators namespace stubs (for MagicLinkHelper)
        Functions\when( 'home_url' )->alias( function( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'trailingslashit' )->alias( function( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'wp_parse_url' )->alias( function( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();

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

    public function test_available_slots_custom_returns_blocks_with_per_block_capacity(): void {
        $calendar = $this->makeCalendar( array(
            'schedule_type' => 'custom',
            'custom_slots'  => array(
                array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 40, 'label' => 'Manha' ),
                array( 'date' => '2026-09-03', 'start' => '14:00', 'end' => '18:00', 'capacity' => 40, 'label' => '' ),
                array( 'date' => '2026-09-04', 'start' => '08:00', 'end' => '13:00', 'capacity' => 20, 'label' => '' ),
            ),
        ) );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $calendar );
        // One booking already sits in the 08:00 block on 2026-09-03.
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array(
            array( 'start_time' => '08:00:00' ),
        ) );
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false );

        $result = $this->handler->get_available_slots( 1, '2026-09-03' );

        // Only the two 2026-09-03 blocks; the block IS the slot.
        $this->assertCount( 2, $result );
        $this->assertSame( '08:00:00', $result[0]['time'] );
        $this->assertSame( '13:00:00', $result[0]['end'] );
        $this->assertSame( 40, $result[0]['total'] );
        $this->assertSame( 39, $result[0]['available'] ); // 40 capacity − 1 booking
        $this->assertStringContainsString( 'Manha', $result[0]['display'] );
        // Second block untouched.
        $this->assertSame( '14:00:00', $result[1]['time'] );
        $this->assertSame( 40, $result[1]['available'] );
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
        Functions\when( 'current_user_can' )->justReturn( true );

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

    public function test_available_slots_returns_empty_when_date_blocked(): void {
        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $this->makeCalendar() );
        // Not a global holiday (real DateBlockingService + empty options) → the
        // per-calendar blocked-date check short-circuits with no slots.
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->with( 1, '2026-03-02' )->andReturn( true );

        $result = $this->handler->get_available_slots( 1, '2026-03-02' );

        $this->assertSame( array(), $result );
    }

    public function test_available_slots_admin_bypass_generates_default_slots(): void {
        // Bypass holder (manage_options) → a day with no configured working hours
        // still yields the default 09:00–18:00 window.
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn(
            $this->makeCalendar( array(
                'slot_duration'             => 60,
                'slot_interval'             => 0,
                'max_appointments_per_slot' => 1,
                'working_hours'             => array(),
            ) )
        );
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array() );

        $result = $this->handler->get_available_slots( 1, '2026-03-02' );

        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result );
        $this->assertSame( '09:00:00', $result[0]['time'] );
    }

    // ==================================================================
    // process_appointment() — booking exception + user linking entry
    // ==================================================================

    public function test_process_booking_exception_rolls_back(): void {
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        // Validation throws inside the try → catch converts it to a booking_error.
        $this->validator->shouldReceive( 'validate' )->andThrow( new \RuntimeException( 'boom' ) );
        $this->appointmentRepo->shouldReceive( 'rollback' )->once();

        $result = $this->handler->process_appointment( array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'booking_error', $result->get_error_code() );
    }

    public function test_process_user_linking_early_returns_without_email(): void {
        // cpf_rf present + no logged-in user → create_or_link_user() is invoked,
        // but with no email it early-returns and booking proceeds unchanged.
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->once()->andReturn( 200 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 200 )->andReturn( array(
            'id' => 200, 'confirmation_token' => 'tok200',
        ) );

        $result = $this->handler->process_appointment( array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
            'cpf_rf'           => '12345678901',
            'user_id'          => 0,
        ) );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
    }

    // ==================================================================
    // schedule_email_notifications() — event fan-out + short-circuits
    // ==================================================================

    public function test_schedule_emails_fires_created_notifications(): void {
        $fired = array();
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$fired ) {
            $fired[] = $hook;
        } );

        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array(
                'email_config' => wp_json_encode( array(
                    'send_user_confirmation'  => 1,
                    'send_admin_notification' => 1,
                ) ),
            ) )
        );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->andReturn( 201 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 201 )->andReturn( array(
            'id' => 201, 'confirmation_token' => 'tok201',
        ) );

        $this->handler->process_appointment( array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
        ) );

        $this->assertContains( 'ffcertificate_self_scheduling_appointment_created_email', $fired );
        $this->assertContains( 'ffcertificate_self_scheduling_appointment_admin_notification', $fired );
    }

    public function test_schedule_emails_skipped_when_config_not_array(): void {
        // email_config decodes to a non-array (JSON null) → notifications skipped.
        $fired = array();
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$fired ) {
            $fired[] = $hook;
        } );

        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'email_config' => 'null' ) )
        );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->andReturn( 202 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 202 )->andReturn( array(
            'id' => 202, 'confirmation_token' => 'tok202',
        ) );

        $this->handler->process_appointment( array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
        ) );

        $this->assertNotContains( 'ffcertificate_self_scheduling_appointment_created_email', $fired );
    }

    public function test_schedule_emails_skipped_when_globally_disabled(): void {
        // Global kill-switch on → schedule_email_notifications() returns before the switch.
        // Scope the override to ffc_settings so other get_option consumers (e.g. the
        // receipt magic-link helper) keep their empty-array default.
        Functions\when( 'get_option' )->alias( function ( $key ) {
            return 'ffc_settings' === $key ? array( 'disable_all_emails' => 1 ) : array();
        } );
        $fired = array();
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$fired ) {
            $fired[] = $hook;
        } );

        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array(
                'email_config' => wp_json_encode( array( 'send_user_confirmation' => 1 ) ),
            ) )
        );
        $this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
        $this->validator->shouldReceive( 'validate' )->andReturn( true );
        $this->appointmentRepo->shouldReceive( 'createAppointment' )->andReturn( 203 );
        $this->appointmentRepo->shouldReceive( 'commit' )->once();
        $this->appointmentRepo->shouldReceive( 'findById' )->with( 203 )->andReturn( array(
            'id' => 203, 'confirmation_token' => 'tok203',
        ) );

        $this->handler->process_appointment( array(
            'calendar_id'      => 1,
            'appointment_date' => '2026-03-01',
            'start_time'       => '09:00',
            'consent_given'    => '1',
        ) );

        $this->assertNotContains( 'ffcertificate_self_scheduling_appointment_created_email', $fired );
    }

    public function test_schedule_emails_fires_cancelled_notification(): void {
        $fired = array();
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$fired ) {
            $fired[] = $hook;
        } );

        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => 'valid',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array(
                'email_config' => wp_json_encode( array( 'send_cancellation_notification' => 1 ) ),
            ) )
        );
        $this->appointmentRepo->shouldReceive( 'cancel' )->andReturn( true );

        $this->handler->cancel_appointment( 1, 'valid' );

        $this->assertContains( 'ffcertificate_self_scheduling_appointment_cancelled_email', $fired );
    }

    // ==================================================================
    // cancel_appointment() — authorization + policy error branches
    // ==================================================================

    public function test_cancel_calendar_not_found(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 9, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => '',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->with( 9 )->andReturn( null );

        $result = $this->handler->cancel_appointment( 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'calendar_not_found', $result->get_error_code() );
    }

    public function test_cancel_logged_in_owner_without_capability_denied(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'current_user_can' )->justReturn( false ); // no bypass, no own-cancel cap.

        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 5, 'confirmation_token' => '',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );

        $result = $this->handler->cancel_appointment( 1 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'capability_denied', $result->get_error_code() );
    }

    public function test_cancel_logged_in_owner_with_capability_succeeds(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        // Holds only the own-cancel cap; bypass caps (manage_options / ffc_bypass_appointments) denied.
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return 'ffc_cancel_own_appointments' === $cap;
        } );

        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 5, 'confirmation_token' => '',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'cancel' )
            ->with( 1, 5, 'Owner cancel' )
            ->andReturn( true );

        $result = $this->handler->cancel_appointment( 1, '', 'Owner cancel' );

        $this->assertTrue( $result );
    }

    public function test_cancel_disabled_by_calendar(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => 'valid',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'allow_cancellation' => 0 ) )
        );

        $result = $this->handler->cancel_appointment( 1, 'valid' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'cancellation_disabled', $result->get_error_code() );
    }

    public function test_cancel_deadline_passed(): void {
        // Appointment in the past + a min-hours window → the deadline has elapsed.
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => 'valid',
            'appointment_date' => '2020-01-01', 'start_time' => '09:00:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn(
            $this->makeCalendar( array( 'cancellation_min_hours' => 24 ) )
        );

        $result = $this->handler->cancel_appointment( 1, 'valid' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'deadline_passed', $result->get_error_code() );
    }

    public function test_cancel_repository_failure(): void {
        $this->appointmentRepo->shouldReceive( 'findById' )->andReturn( array(
            'id' => 1, 'calendar_id' => 1, 'status' => 'confirmed',
            'user_id' => 0, 'confirmation_token' => 'valid',
            'appointment_date' => '2026-03-01', 'start_time' => '09:00',
        ) );
        $this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
        $this->appointmentRepo->shouldReceive( 'cancel' )->andReturn( false );

        $result = $this->handler->cancel_appointment( 1, 'valid' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'cancellation_failed', $result->get_error_code() );
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
