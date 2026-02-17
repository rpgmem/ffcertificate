<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentValidator;

/**
 * Tests for AppointmentValidator: booking validation, interval checking, working hours.
 */
class AppointmentValidatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var AppointmentValidator */
    private $validator;

    /** @var \Mockery\MockInterface */
    private $appointmentRepo;

    /** @var \Mockery\MockInterface */
    private $blockedDateRepo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            // No bypass, but has booking permission
            if ( $cap === 'manage_options' ) return false;
            if ( $cap === 'ffc_scheduling_bypass' ) return false;
            if ( $cap === 'ffc_book_appointments' ) return true;
            return false;
        } );
        Functions\when( 'user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );
        Functions\when( 'current_time' )->justReturn( gmdate( 'Y-m-d H:i:s' ) );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_global_holidays' ) return array();
            if ( $key === 'date_format' ) return 'Y-m-d';
            if ( $key === 'time_format' ) return 'H:i';
            return $default;
        } );
        Functions\when( 'date_i18n' )->alias( function ( $format, $ts = false ) {
            return gmdate( 'Y-m-d H:i', $ts ?: time() );
        } );

        $this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
        $this->blockedDateRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );

        // Default repo behavior: everything available
        $this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( true )->byDefault();
        $this->blockedDateRepo->shouldReceive( 'isDateBlocked' )->andReturn( false )->byDefault();
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )->andReturn( array() )->byDefault();

        $this->validator = new AppointmentValidator( $this->appointmentRepo, $this->blockedDateRepo );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Default valid booking data (far future, 7-digit RF).
     */
    private function valid_data(): array {
        return array(
            'appointment_date' => '2030-01-15',
            'start_time'       => '10:00',
            'calendar_id'      => 1,
            'email'            => 'test@example.com',
            'cpf_rf'           => '1234567',
            'user_id'          => 1,
        );
    }

    /**
     * Default permissive calendar config.
     */
    private function permissive_calendar(): array {
        return array(
            'advance_booking_min'               => 0,
            'advance_booking_max'               => 0,
            'max_appointments_per_slot'         => 10,
            'slots_per_day'                     => 0,
            'minimum_interval_between_bookings' => 0,
            'working_hours'                     => array(),
            'scheduling_visibility'             => 'public',
            'restrict_booking_to_hours'         => false,
        );
    }

    // ==================================================================
    // validate() — field and format validation
    // ==================================================================

    public function test_validate_valid_data_returns_true(): void {
        $result = $this->validator->validate( $this->valid_data(), $this->permissive_calendar() );
        $this->assertTrue( $result );
    }

    public function test_validate_missing_date_returns_error(): void {
        $data = $this->valid_data();
        $data['appointment_date'] = '';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_fields', $result->get_error_code() );
    }

    public function test_validate_missing_time_returns_error(): void {
        $data = $this->valid_data();
        $data['start_time'] = '';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'missing_fields', $result->get_error_code() );
    }

    public function test_validate_invalid_date_format(): void {
        $data = $this->valid_data();
        $data['appointment_date'] = '15/01/2030';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_date', $result->get_error_code() );
    }

    public function test_validate_invalid_time_format(): void {
        $data = $this->valid_data();
        $data['start_time'] = '25:00';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_time', $result->get_error_code() );
    }

    public function test_validate_time_with_seconds_accepted(): void {
        $data = $this->valid_data();
        $data['start_time'] = '10:30:45';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertTrue( $result );
    }

    public function test_validate_date_impossible_day_rejected(): void {
        $data = $this->valid_data();
        $data['appointment_date'] = '2030-02-30';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_date', $result->get_error_code() );
    }

    // ==================================================================
    // validate() — CPF/RF validation
    // ==================================================================

    public function test_validate_missing_cpf_rf_returns_error(): void {
        $data = $this->valid_data();
        $data['cpf_rf'] = '';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'cpf_rf_required', $result->get_error_code() );
    }

    public function test_validate_rf_7_digits_accepted(): void {
        $data = $this->valid_data();
        $data['cpf_rf'] = '1234567';
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertTrue( $result );
    }

    public function test_validate_cpf_rf_wrong_length_returns_error(): void {
        $data = $this->valid_data();
        $data['cpf_rf'] = '12345'; // 5 digits
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_cpf_rf', $result->get_error_code() );
    }

    public function test_validate_cpf_rf_formatted_strips_non_digits(): void {
        $data = $this->valid_data();
        $data['cpf_rf'] = '123.456.7'; // 7 digits after stripping
        $result = $this->validator->validate( $data, $this->permissive_calendar() );
        $this->assertTrue( $result );
    }

    // ==================================================================
    // validate() — slot availability (never bypassed)
    // ==================================================================

    public function test_validate_slot_full_returns_error(): void {
        $this->appointmentRepo->shouldReceive( 'isSlotAvailable' )
            ->once()
            ->andReturn( false );

        $result = $this->validator->validate( $this->valid_data(), $this->permissive_calendar() );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'slot_full', $result->get_error_code() );
    }

    public function test_validate_daily_limit_reached_returns_error(): void {
        $calendar = $this->permissive_calendar();
        $calendar['slots_per_day'] = 5;

        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )
            ->andReturn( array_fill( 0, 5, array() ) );

        $result = $this->validator->validate( $this->valid_data(), $calendar );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'daily_limit', $result->get_error_code() );
    }

    // ==================================================================
    // validate() — scheduling visibility
    // ==================================================================

    public function test_validate_private_calendar_logged_out_returns_error(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $calendar = $this->permissive_calendar();
        $calendar['scheduling_visibility'] = 'private';

        // Email required check will trigger first for logged-out users
        $data = $this->valid_data();
        $data['email'] = 'test@example.com'; // provide email to skip that check

        $result = $this->validator->validate( $data, $calendar );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'login_required', $result->get_error_code() );
    }

    // ==================================================================
    // check_booking_interval() — user identifier resolution
    // ==================================================================

    public function test_interval_user_id_uses_findByUserId(): void {
        $this->appointmentRepo->shouldReceive( 'findByUserId' )
            ->with( 42 )
            ->once()
            ->andReturn( array() );

        $result = $this->validator->check_booking_interval( 42, 1, 24 );
        $this->assertTrue( $result );
    }

    public function test_interval_email_uses_findByEmail(): void {
        $this->appointmentRepo->shouldReceive( 'findByEmail' )
            ->with( 'test@example.com' )
            ->once()
            ->andReturn( array() );

        $result = $this->validator->check_booking_interval( 'test@example.com', 1, 24 );
        $this->assertTrue( $result );
    }

    public function test_interval_cpf_uses_findByCpfRf(): void {
        $this->appointmentRepo->shouldReceive( 'findByCpfRf' )
            ->with( '12345678901' )
            ->once()
            ->andReturn( array() );

        $result = $this->validator->check_booking_interval( '12345678901', 1, 24 );
        $this->assertTrue( $result );
    }

    public function test_interval_skips_cancelled_appointments(): void {
        $now = time();
        $upcoming = gmdate( 'Y-m-d', $now + 3600 );
        $upcoming_time = gmdate( 'H:i', $now + 3600 );

        $this->appointmentRepo->shouldReceive( 'findByUserId' )
            ->andReturn( array(
                array(
                    'appointment_date' => $upcoming,
                    'start_time'       => $upcoming_time,
                    'status'           => 'cancelled',
                    'calendar_id'      => 1,
                ),
            ) );

        $result = $this->validator->check_booking_interval( 1, 1, 24 );
        $this->assertTrue( $result );
    }

    public function test_interval_skips_different_calendar(): void {
        $now = time();
        $upcoming = gmdate( 'Y-m-d', $now + 3600 );
        $upcoming_time = gmdate( 'H:i', $now + 3600 );

        $this->appointmentRepo->shouldReceive( 'findByUserId' )
            ->andReturn( array(
                array(
                    'appointment_date' => $upcoming,
                    'start_time'       => $upcoming_time,
                    'status'           => 'confirmed',
                    'calendar_id'      => 99, // different
                ),
            ) );

        $result = $this->validator->check_booking_interval( 1, 1, 24 );
        $this->assertTrue( $result );
    }

    public function test_interval_returns_error_for_upcoming_appointment(): void {
        $now = time();
        $upcoming = gmdate( 'Y-m-d', $now + 3600 );
        $upcoming_time = gmdate( 'H:i', $now + 3600 );

        $this->appointmentRepo->shouldReceive( 'findByUserId' )
            ->andReturn( array(
                array(
                    'appointment_date' => $upcoming,
                    'start_time'       => $upcoming_time,
                    'status'           => 'confirmed',
                    'calendar_id'      => 1,
                ),
            ) );

        $result = $this->validator->check_booking_interval( 1, 1, 24 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'booking_too_soon', $result->get_error_code() );
    }

    public function test_interval_no_recent_returns_true(): void {
        $this->appointmentRepo->shouldReceive( 'findByUserId' )
            ->andReturn( array() );

        $result = $this->validator->check_booking_interval( 1, 1, 24 );
        $this->assertTrue( $result );
    }

    // ==================================================================
    // is_within_working_hours() — delegation
    // ==================================================================

    public function test_within_working_hours_delegates_to_service(): void {
        $calendar = array( 'working_hours' => array() );
        // Empty working hours means no restrictions → true
        $this->assertTrue( $this->validator->is_within_working_hours( '2030-01-15', '10:00', $calendar ) );
    }

    public function test_within_working_hours_respects_config(): void {
        $calendar = array(
            'working_hours' => array(
                'wed' => array( 'start' => '09:00', 'end' => '12:00', 'closed' => false ),
            ),
        );
        // 2030-01-15 is a Tuesday — no 'tue' entry in keyed format → true (unknown day)
        $this->assertTrue( $this->validator->is_within_working_hours( '2030-01-15', '10:00', $calendar ) );
    }

    // ==================================================================
    // get_daily_appointment_count()
    // ==================================================================

    public function test_daily_count_returns_count_from_repo(): void {
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )
            ->with( 1, '2030-01-15', array( 'confirmed', 'pending' ), false )
            ->once()
            ->andReturn( array( array(), array(), array() ) );

        $this->assertSame( 3, $this->validator->get_daily_appointment_count( 1, '2030-01-15' ) );
    }

    public function test_daily_count_empty_returns_zero(): void {
        $this->appointmentRepo->shouldReceive( 'getAppointmentsByDate' )
            ->andReturn( array() );

        $this->assertSame( 0, $this->validator->get_daily_appointment_count( 1, '2030-01-15' ) );
    }
}
