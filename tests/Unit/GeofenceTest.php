<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence: area parsing, timestamp helpers, config normalization, expiration checks.
 */
class GeofenceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        });
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // parse_areas
    // ------------------------------------------------------------------

    public function test_parse_areas_empty_string_returns_empty(): void {
        $this->assertSame( array(), Geofence::parse_areas( '' ) );
    }

    public function test_parse_areas_parses_valid_lines(): void {
        $input  = "40.7128, -74.0060, 5000\n34.0522, -118.2437, 10000";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
        $this->assertSame( 40.7128, $result[0]['lat'] );
        $this->assertSame( -74.0060, $result[0]['lng'] );
        $this->assertSame( 5000.0, $result[0]['radius'] );
    }

    public function test_parse_areas_skips_invalid_lines(): void {
        $input  = "40.7128, -74.0060, 5000\ninvalid line\n34.0522, -118.2437, 10000";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
    }

    public function test_parse_areas_rejects_out_of_range_coordinates(): void {
        $input  = "200, -74, 5000\n40, -200, 5000\n40, 74, -100";
        $result = Geofence::parse_areas( $input );

        $this->assertSame( array(), $result );
    }

    public function test_parse_areas_skips_blank_lines(): void {
        $input  = "\n40.0, -74.0, 5000\n\n\n34.0, -118.0, 10000\n";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
    }

    // ------------------------------------------------------------------
    // get_form_end_timestamp
    // ------------------------------------------------------------------

    public function test_get_form_end_timestamp_returns_null_when_no_config(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_returns_null_when_date_end_missing(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'datetime_enabled' => 1 ) );

        $this->assertNull( Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_uses_time_end_when_set(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => '2030-01-15',
                'time_end' => '18:30:00',
            )
        );

        $expected = ( new \DateTimeImmutable( '2030-01-15 18:30:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_defaults_time_to_end_of_day(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'date_end' => '2030-01-15' ) );

        $expected = ( new \DateTimeImmutable( '2030-01-15 23:59:59', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 42 ) );
    }

    // ------------------------------------------------------------------
    // get_form_start_timestamp
    // ------------------------------------------------------------------

    public function test_get_form_start_timestamp_returns_null_when_no_config(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_start_timestamp( 42 ) );
    }

    public function test_get_form_start_timestamp_uses_time_start(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_start' => '2025-01-01',
                'time_start' => '09:00:00',
            )
        );

        $expected = ( new \DateTimeImmutable( '2025-01-01 09:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_start_timestamp( 42 ) );
    }

    // ------------------------------------------------------------------
    // has_form_expired
    // ------------------------------------------------------------------

    public function test_has_form_expired_returns_false_when_no_end_timestamp(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertFalse( Geofence::has_form_expired( 42 ) );
    }

    public function test_has_form_expired_returns_false_when_future_date(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', time() + 86400 ),
                'time_end' => '23:59:59',
            )
        );

        $this->assertFalse( Geofence::has_form_expired( 42 ) );
    }

    public function test_has_form_expired_returns_true_when_past_date(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => '2000-01-01',
                'time_end' => '00:00:00',
            )
        );

        $this->assertTrue( Geofence::has_form_expired( 42 ) );
    }

    // ------------------------------------------------------------------
    // has_form_expired_by_days
    // ------------------------------------------------------------------

    public function test_has_form_expired_by_days_returns_false_when_within_grace(): void {
        // Ended 2 days ago, asking if expired by 5 days grace.
        $ended_at = time() - ( 2 * 86400 );
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', $ended_at ),
                'time_end' => gmdate( 'H:i:s', $ended_at ),
            )
        );

        $this->assertFalse( Geofence::has_form_expired_by_days( 42, 5 ) );
    }

    public function test_has_form_expired_by_days_returns_true_when_past_grace(): void {
        // Ended 10 days ago, asking if expired by 5 days grace.
        $ended_at = time() - ( 10 * 86400 );
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', $ended_at ),
                'time_end' => gmdate( 'H:i:s', $ended_at ),
            )
        );

        $this->assertTrue( Geofence::has_form_expired_by_days( 42, 5 ) );
    }

    // ------------------------------------------------------------------
    // get_form_config
    // ------------------------------------------------------------------

    public function test_get_form_config_returns_null_when_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_config( 42 ) );
    }

    public function test_get_form_config_normalizes_boolean_flags(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'datetime_enabled'        => '1',
                'geo_enabled'             => 1,
                'geo_gps_enabled'         => true,
                'geo_ip_enabled'          => 0,
                'geo_ip_areas_permissive' => false,
            )
        );

        $result = Geofence::get_form_config( 42 );

        $this->assertTrue( $result['datetime_enabled'] );
        $this->assertTrue( $result['geo_enabled'] );
        $this->assertTrue( $result['geo_gps_enabled'] );
        $this->assertFalse( $result['geo_ip_enabled'] );
        $this->assertFalse( $result['geo_ip_areas_permissive'] );
    }

    // ------------------------------------------------------------------
    // should_bypass_datetime / should_bypass_geo
    // ------------------------------------------------------------------

    public function test_should_bypass_datetime_false_for_non_admin(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

        $this->assertFalse( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_datetime_true_for_admin_when_setting_enabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'admin_bypass_datetime' => 1 ) );

        $this->assertTrue( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_datetime_false_for_admin_when_setting_disabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertFalse( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_geo_true_for_admin_when_setting_enabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'admin_bypass_geo' => 1 ) );

        $this->assertTrue( Geofence::should_bypass_geo() );
    }

    // ------------------------------------------------------------------
    // resolve_hide_mode — per-phase hide modes with legacy fallback (#159 S1)
    // ------------------------------------------------------------------

    public function test_resolve_hide_mode_returns_phase_value_when_present(): void {
        $config = array(
            'datetime_hide_mode_before' => 'hide',
            'datetime_hide_mode_during' => 'title_message',
            'datetime_hide_mode_after'  => 'message',
        );
        $this->assertSame( 'hide', Geofence::resolve_hide_mode( $config, 'before' ) );
        $this->assertSame( 'title_message', Geofence::resolve_hide_mode( $config, 'during' ) );
        $this->assertSame( 'message', Geofence::resolve_hide_mode( $config, 'after' ) );
    }

    public function test_resolve_hide_mode_falls_back_to_legacy_key(): void {
        $config = array( 'datetime_hide_mode' => 'hide' );
        $this->assertSame( 'hide', Geofence::resolve_hide_mode( $config, 'before' ) );
        $this->assertSame( 'hide', Geofence::resolve_hide_mode( $config, 'during' ) );
        $this->assertSame( 'hide', Geofence::resolve_hide_mode( $config, 'after' ) );
    }

    public function test_resolve_hide_mode_defaults_to_message_when_nothing_set(): void {
        $this->assertSame( 'message', Geofence::resolve_hide_mode( array(), 'before' ) );
        $this->assertSame( 'message', Geofence::resolve_hide_mode( array(), 'during' ) );
        $this->assertSame( 'message', Geofence::resolve_hide_mode( array(), 'after' ) );
    }

    public function test_resolve_hide_mode_phase_value_overrides_legacy(): void {
        $config = array(
            'datetime_hide_mode'       => 'hide',
            'datetime_hide_mode_after' => 'message',
        );
        $this->assertSame( 'hide', Geofence::resolve_hide_mode( $config, 'before' ) ); // legacy fallback
        $this->assertSame( 'message', Geofence::resolve_hide_mode( $config, 'after' ) ); // phase wins
    }

    // ------------------------------------------------------------------
    // analyze_datetime_order — date/time order validation (#159 S2)
    // ------------------------------------------------------------------

    public function test_analyze_datetime_order_empty_when_valid_daily(): void {
        $config = array(
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '08:00',
            'time_end'   => '18:00',
            'time_mode'  => 'daily',
        );
        $this->assertSame( array(), Geofence::analyze_datetime_order( $config ) );
    }

    public function test_analyze_datetime_order_empty_when_valid_span(): void {
        $config = array(
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-02',
            'time_start' => '22:00',
            'time_end'   => '06:00',
            'time_mode'  => 'span',
        );
        $this->assertSame( array(), Geofence::analyze_datetime_order( $config ) );
    }

    public function test_analyze_datetime_order_flags_both_dates_when_end_before_start(): void {
        $config = array(
            'date_start' => '2026-06-30',
            'date_end'   => '2026-06-01',
            'time_mode'  => 'daily',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'date_start', $errors );
        $this->assertArrayHasKey( 'date_end', $errors );
        $this->assertSame( $errors['date_start'], $errors['date_end'] );
    }

    public function test_analyze_datetime_order_flags_equal_dates_when_multi_day(): void {
        // Multi-day on: the end must be at least the day AFTER the start, so
        // equal dates are invalid and both inputs go red.
        $config = array(
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-01',
            'multi_day'  => '1',
            'time_mode'  => 'daily',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'date_start', $errors );
        $this->assertArrayHasKey( 'date_end', $errors );
    }

    public function test_analyze_datetime_order_allows_equal_dates_when_not_multi_day(): void {
        // Single-day form mirrors date_end = date_start; equal dates are valid
        // when multi_day is off.
        $config = array(
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-01',
            'multi_day'  => '0',
            'time_mode'  => 'daily',
        );
        $this->assertSame( array(), Geofence::analyze_datetime_order( $config ) );
    }

    public function test_analyze_datetime_order_flags_times_in_span_when_composed_inverted(): void {
        $config = array(
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-01',
            'time_start' => '18:00',
            'time_end'   => '08:00',
            'time_mode'  => 'span',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'time_start', $errors );
        $this->assertArrayHasKey( 'time_end', $errors );
        $this->assertArrayNotHasKey( 'date_start', $errors );
    }

    public function test_analyze_datetime_order_flags_times_in_daily_when_end_not_after_start(): void {
        $config = array(
            'time_start' => '18:00',
            'time_end'   => '08:00',
            'time_mode'  => 'daily',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'time_start', $errors );
        $this->assertArrayHasKey( 'time_end', $errors );
    }

    public function test_analyze_datetime_order_skips_partial_configs(): void {
        // Only date_start set — no comparison to make.
        $this->assertSame( array(), Geofence::analyze_datetime_order( array( 'date_start' => '2026-06-01' ) ) );
        // Only times set in span — without dates the comparison is N/A.
        $this->assertSame(
            array(),
            Geofence::analyze_datetime_order(
                array(
                    'time_start' => '10:00',
                    'time_end'   => '20:00',
                    'time_mode'  => 'span',
                )
            )
        );
    }

    public function test_analyze_datetime_order_short_circuits_on_date_inversion(): void {
        // When dates are inverted, the helper returns early — span/daily
        // checks below would just stack a redundant error on the same inputs.
        $config = array(
            'date_start' => '2026-06-30',
            'date_end'   => '2026-06-01',
            'time_start' => '18:00',
            'time_end'   => '08:00',
            'time_mode'  => 'daily',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertCount( 2, $errors );
        $this->assertArrayHasKey( 'date_start', $errors );
        $this->assertArrayHasKey( 'date_end', $errors );
        $this->assertArrayNotHasKey( 'time_start', $errors );
    }

    public function test_analyze_datetime_order_flags_class_time_when_end_not_after_start(): void {
        // Event Schedule (Reference) — `class_time_*` must move forward
        // within a single day to keep `{{schedule}}` semantically valid.
        $config = array(
            'class_time_start' => '14:00',
            'class_time_end'   => '12:00',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'class_time_start', $errors );
        $this->assertArrayHasKey( 'class_time_end', $errors );
        $this->assertSame( $errors['class_time_start'], $errors['class_time_end'] );
    }

    public function test_analyze_datetime_order_class_time_passes_when_in_order(): void {
        $config = array(
            'class_time_start' => '09:00',
            'class_time_end'   => '17:30',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayNotHasKey( 'class_time_start', $errors );
        $this->assertArrayNotHasKey( 'class_time_end', $errors );
    }

    public function test_analyze_datetime_order_class_time_skips_when_only_one_filled(): void {
        // Half-filled (just start, or just end) — silently skip, mirroring
        // the daily/span half-fill behaviour above.
        $errors_only_start = Geofence::analyze_datetime_order( array( 'class_time_start' => '09:00' ) );
        $this->assertSame( array(), $errors_only_start );
        $errors_only_end = Geofence::analyze_datetime_order( array( 'class_time_end' => '17:30' ) );
        $this->assertSame( array(), $errors_only_end );
    }

    public function test_analyze_datetime_order_flags_class_time_alongside_span_mode_inversion(): void {
        // Regression for the user-reported bug: inverted Event Schedule
        // alongside an inverted span-mode Date/Time Restrictions only
        // flagged the latter, because the span branch early-returned
        // before the class_time check at the tail of the function ran.
        // Both pairs of errors must surface so both sets of inputs go red.
        $config = array(
            'date_start'       => '2026-05-24',
            'date_end'         => '2026-05-24',
            'time_start'       => '21:00',
            'time_end'         => '20:00',
            'time_mode'        => 'span',
            'class_time_start' => '21:00',
            'class_time_end'   => '20:00',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        // span check picks up time_*.
        $this->assertArrayHasKey( 'time_start', $errors );
        $this->assertArrayHasKey( 'time_end', $errors );
        // class_time check picks up class_time_* (this was the missing
        // half before the fix that moved it ahead of the early returns).
        $this->assertArrayHasKey( 'class_time_start', $errors );
        $this->assertArrayHasKey( 'class_time_end', $errors );
    }

    public function test_analyze_datetime_order_flags_class_time_alongside_date_inversion(): void {
        // Same regression as above for the date-order short-circuit
        // (which returns earlier than the span branch).
        $config = array(
            'date_start'       => '2026-06-30',
            'date_end'         => '2026-06-01',
            'class_time_start' => '14:00',
            'class_time_end'   => '12:00',
        );
        $errors = Geofence::analyze_datetime_order( $config );
        $this->assertArrayHasKey( 'date_start', $errors );
        $this->assertArrayHasKey( 'date_end', $errors );
        $this->assertArrayHasKey( 'class_time_start', $errors );
        $this->assertArrayHasKey( 'class_time_end', $errors );
    }
}
