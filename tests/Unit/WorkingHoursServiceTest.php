<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\WorkingHoursService;

/**
 * Tests for WorkingHoursService: working hours validation in both keyed and array formats.
 *
 * Uses fixed dates with known day-of-week:
 * - 2025-01-05 = Sunday (0)
 * - 2025-01-06 = Monday (1)
 * - 2025-01-07 = Tuesday (2)
 * - 2025-01-08 = Wednesday (3)
 * - 2025-01-10 = Friday (5)
 * - 2025-01-11 = Saturday (6)
 */
class WorkingHoursServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Keyed format helpers
    // ------------------------------------------------------------------

    private function keyed_hours(): array {
        return array(
            'mon' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
            'tue' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
            'wed' => array( 'start' => '08:00', 'end' => '18:00', 'closed' => false ),
            'thu' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
            'fri' => array( 'start' => '09:00', 'end' => '17:00', 'closed' => false ),
            'sat' => array( 'start' => '10:00', 'end' => '14:00', 'closed' => false ),
            'sun' => array( 'closed' => true ),
        );
    }

    // ------------------------------------------------------------------
    // Array-of-objects format helpers
    // ------------------------------------------------------------------

    private function array_hours(): array {
        return array(
            array( 'day' => 1, 'start' => '09:00', 'end' => '17:00' ),
            array( 'day' => 2, 'start' => '09:00', 'end' => '17:00' ),
            array( 'day' => 3, 'start' => '08:00', 'end' => '12:00' ),
            array( 'day' => 3, 'start' => '13:00', 'end' => '18:00' ), // Wed split shift
            array( 'day' => 4, 'start' => '09:00', 'end' => '17:00' ),
            array( 'day' => 5, 'start' => '09:00', 'end' => '17:00' ),
        );
    }

    // ==================================================================
    // is_within_working_hours() — keyed format
    // ==================================================================

    public function test_keyed_within_range_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', $this->keyed_hours() )
        );
    }

    public function test_keyed_before_start_returns_false(): void {
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '08:59', $this->keyed_hours() )
        );
    }

    public function test_keyed_at_start_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '09:00', $this->keyed_hours() )
        );
    }

    public function test_keyed_at_end_returns_false(): void {
        // End is exclusive
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '17:00', $this->keyed_hours() )
        );
    }

    public function test_keyed_closed_day_returns_false(): void {
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-05', '12:00', $this->keyed_hours() )
        );
    }

    public function test_keyed_missing_start_end_returns_true(): void {
        $hours = array( 'mon' => array( 'closed' => false ) );
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '12:00', $hours )
        );
    }

    // ==================================================================
    // is_within_working_hours() — array-of-objects format
    // ==================================================================

    public function test_array_within_range_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', $this->array_hours() )
        );
    }

    public function test_array_outside_range_returns_false(): void {
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '18:00', $this->array_hours() )
        );
    }

    public function test_array_no_entry_for_day_returns_false(): void {
        // Sunday (0) has no entry in array_hours
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-05', '12:00', $this->array_hours() )
        );
    }

    public function test_array_split_shift_first_range(): void {
        // Wednesday 10:00 — in first range (08:00-12:00)
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-08', '10:00', $this->array_hours() )
        );
    }

    public function test_array_split_shift_second_range(): void {
        // Wednesday 14:00 — in second range (13:00-18:00)
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-08', '14:00', $this->array_hours() )
        );
    }

    public function test_array_split_shift_gap_returns_false(): void {
        // Wednesday 12:30 — between shifts
        $this->assertFalse(
            WorkingHoursService::is_within_working_hours( '2025-01-08', '12:30', $this->array_hours() )
        );
    }

    // ==================================================================
    // is_within_working_hours() — edge cases
    // ==================================================================

    public function test_empty_hours_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', array() )
        );
    }

    public function test_null_hours_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', null )
        );
    }

    public function test_json_string_input_accepted(): void {
        $json = json_encode( $this->keyed_hours() );
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', $json )
        );
    }

    public function test_unknown_format_returns_true(): void {
        // Not keyed, not array-of-objects
        $this->assertTrue(
            WorkingHoursService::is_within_working_hours( '2025-01-06', '10:00', array( 'random' => 'data' ) )
        );
    }

    // ==================================================================
    // is_working_day() — keyed format
    // ==================================================================

    public function test_keyed_working_day_open(): void {
        $this->assertTrue(
            WorkingHoursService::is_working_day( '2025-01-06', $this->keyed_hours() )
        );
    }

    public function test_keyed_working_day_closed(): void {
        $this->assertFalse(
            WorkingHoursService::is_working_day( '2025-01-05', $this->keyed_hours() )
        );
    }

    // ==================================================================
    // is_working_day() — array-of-objects format
    // ==================================================================

    public function test_array_working_day_with_entry(): void {
        $this->assertTrue(
            WorkingHoursService::is_working_day( '2025-01-06', $this->array_hours() )
        );
    }

    public function test_array_working_day_no_entry(): void {
        // Sunday has no entry
        $this->assertFalse(
            WorkingHoursService::is_working_day( '2025-01-05', $this->array_hours() )
        );
    }

    public function test_working_day_empty_hours_returns_true(): void {
        $this->assertTrue(
            WorkingHoursService::is_working_day( '2025-01-06', array() )
        );
    }

    // ==================================================================
    // get_day_ranges() — keyed format
    // ==================================================================

    public function test_keyed_get_ranges_open_day(): void {
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-06', $this->keyed_hours() );
        $this->assertCount( 1, $ranges );
        $this->assertSame( '09:00', $ranges[0]['start'] );
        $this->assertSame( '17:00', $ranges[0]['end'] );
    }

    public function test_keyed_get_ranges_closed_day(): void {
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-05', $this->keyed_hours() );
        $this->assertSame( array(), $ranges );
    }

    public function test_keyed_get_ranges_different_hours(): void {
        // Wednesday has 08:00-18:00
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-08', $this->keyed_hours() );
        $this->assertCount( 1, $ranges );
        $this->assertSame( '08:00', $ranges[0]['start'] );
        $this->assertSame( '18:00', $ranges[0]['end'] );
    }

    // ==================================================================
    // get_day_ranges() — array-of-objects format
    // ==================================================================

    public function test_array_get_ranges_single(): void {
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-06', $this->array_hours() );
        $this->assertCount( 1, $ranges );
        $this->assertSame( '09:00', $ranges[0]['start'] );
    }

    public function test_array_get_ranges_split_shift(): void {
        // Wednesday has two ranges
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-08', $this->array_hours() );
        $this->assertCount( 2, $ranges );
        $this->assertSame( '08:00', $ranges[0]['start'] );
        $this->assertSame( '12:00', $ranges[0]['end'] );
        $this->assertSame( '13:00', $ranges[1]['start'] );
        $this->assertSame( '18:00', $ranges[1]['end'] );
    }

    public function test_array_get_ranges_no_entry(): void {
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-05', $this->array_hours() );
        $this->assertSame( array(), $ranges );
    }

    public function test_get_ranges_empty_hours(): void {
        $ranges = WorkingHoursService::get_day_ranges( '2025-01-06', array() );
        $this->assertSame( array(), $ranges );
    }
}
