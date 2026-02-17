<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\DateBlockingService;

/**
 * Tests for DateBlockingService: global holidays, date range filtering, availability checks.
 */
class DateBlockingServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_global_holidays' ) {
                return array(
                    array( 'date' => '2030-01-01', 'name' => 'New Year' ),
                    array( 'date' => '2030-04-21', 'name' => 'Tiradentes' ),
                    array( 'date' => '2030-05-01', 'name' => 'Labor Day' ),
                    array( 'date' => '2030-09-07', 'name' => 'Independence Day' ),
                    array( 'date' => '2030-12-25', 'name' => 'Christmas' ),
                );
            }
            return $default;
        } );

        // Namespaced stub: prevent "is not defined" error when Sprint 27 tests run first.
        // Delegates to the global get_option stub so per-test overrides work correctly.
        Functions\when( 'FreeFormCertificate\Scheduling\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // is_global_holiday()
    // ==================================================================

    public function test_is_global_holiday_matching_date(): void {
        $this->assertTrue( DateBlockingService::is_global_holiday( '2030-01-01' ) );
    }

    public function test_is_global_holiday_non_matching_date(): void {
        $this->assertFalse( DateBlockingService::is_global_holiday( '2030-06-15' ) );
    }

    public function test_is_global_holiday_empty_holidays(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $this->assertFalse( DateBlockingService::is_global_holiday( '2030-01-01' ) );
    }

    public function test_is_global_holiday_non_array_holidays(): void {
        Functions\when( 'get_option' )->justReturn( 'invalid' );
        $this->assertFalse( DateBlockingService::is_global_holiday( '2030-01-01' ) );
    }

    public function test_is_global_holiday_entries_without_date_key_skipped(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'name' => 'No date key' ),
            array( 'date' => '2030-03-15', 'name' => 'Custom' ),
        ) );
        $this->assertFalse( DateBlockingService::is_global_holiday( '2030-01-01' ) );
        $this->assertTrue( DateBlockingService::is_global_holiday( '2030-03-15' ) );
    }

    // ==================================================================
    // get_global_holidays()
    // ==================================================================

    public function test_get_all_holidays_without_filter(): void {
        $holidays = DateBlockingService::get_global_holidays();
        $this->assertCount( 5, $holidays );
    }

    public function test_get_holidays_filtered_by_start_date(): void {
        $holidays = DateBlockingService::get_global_holidays( '2030-05-01' );
        // May 1, Sep 7, Dec 25
        $this->assertCount( 3, $holidays );
    }

    public function test_get_holidays_filtered_by_end_date(): void {
        $holidays = DateBlockingService::get_global_holidays( null, '2030-04-30' );
        // Jan 1, Apr 21
        $this->assertCount( 2, $holidays );
    }

    public function test_get_holidays_filtered_by_date_range(): void {
        $holidays = DateBlockingService::get_global_holidays( '2030-03-01', '2030-06-30' );
        // Apr 21, May 1
        $this->assertCount( 2, $holidays );
    }

    public function test_get_holidays_empty_range_returns_empty(): void {
        $holidays = DateBlockingService::get_global_holidays( '2030-02-01', '2030-03-01' );
        $this->assertCount( 0, $holidays );
    }

    public function test_get_holidays_non_array_returns_empty(): void {
        Functions\when( 'get_option' )->justReturn( null );
        $holidays = DateBlockingService::get_global_holidays();
        $this->assertSame( array(), $holidays );
    }

    public function test_get_holidays_entries_without_date_filtered_out(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'name' => 'No date' ),
            array( 'date' => '2030-01-01', 'name' => 'New Year' ),
        ) );
        $holidays = DateBlockingService::get_global_holidays( '2030-01-01', '2030-12-31' );
        $this->assertCount( 1, $holidays );
        $this->assertSame( '2030-01-01', $holidays[0]['date'] );
    }

    // ==================================================================
    // is_date_available() — composite check
    // ==================================================================

    public function test_available_blocked_by_global_holiday(): void {
        $this->assertFalse(
            DateBlockingService::is_date_available( '2030-01-01', '10:00', array() )
        );
    }

    public function test_available_non_holiday_no_restrictions(): void {
        // June 15 is not a holiday, empty working hours = no restrictions
        $this->assertTrue(
            DateBlockingService::is_date_available( '2030-06-15', '10:00', array() )
        );
    }

    public function test_available_outside_working_hours_blocked(): void {
        $working_hours = array(
            // 2030-06-17 is a Monday
            'mon' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
        );
        // 18:00 is outside 09:00-17:00
        $this->assertFalse(
            DateBlockingService::is_date_available( '2030-06-17', '18:00', $working_hours )
        );
    }

    public function test_available_within_working_hours_passes(): void {
        $working_hours = array(
            'mon' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
        );
        // 2030-06-17 Monday, 10:00 within 09-17
        $this->assertTrue(
            DateBlockingService::is_date_available( '2030-06-17', '10:00', $working_hours )
        );
    }

    public function test_available_null_time_checks_working_day(): void {
        $working_hours = array(
            'mon' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
            'sun' => array( 'closed' => true ),
        );
        // 2030-06-17 Monday → open day
        $this->assertTrue(
            DateBlockingService::is_date_available( '2030-06-17', null, $working_hours )
        );
    }

    public function test_available_null_time_closed_day_blocked(): void {
        $working_hours = array(
            'sun' => array( 'closed' => true ),
        );
        // 2030-06-15 is a Saturday, no 'sat' key → unknown = true
        // 2030-06-16 is a Sunday → closed
        $this->assertFalse(
            DateBlockingService::is_date_available( '2030-06-16', null, $working_hours )
        );
    }
}
