<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::validate_datetime() method.
 */
class GeofenceDatetimeValidationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────
    // Daily mode tests
    // ──────────────────────────────────────────────────────

    /**
     * Daily mode: current date and time within range → valid.
     */
    public function test_daily_valid_within_date_and_time_range(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }

    /**
     * Daily mode: current date before start date → invalid.
     */
    public function test_daily_before_start_date(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-05-01';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'before_start_date', $result['details']['reason'] );
        $this->assertSame( '2026-05-01', $result['details']['current_date'] );
        $this->assertSame( '2026-06-01', $result['details']['start_date'] );
    }

    /**
     * Daily mode: current date after end date → invalid.
     */
    public function test_daily_after_end_date(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-07-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'after_end_date', $result['details']['reason'] );
        $this->assertSame( '2026-07-15', $result['details']['current_date'] );
        $this->assertSame( '2026-06-30', $result['details']['end_date'] );
    }

    /**
     * Daily mode: date within range but time outside range → invalid.
     */
    public function test_daily_outside_time_range(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '20:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'outside_time_range', $result['details']['reason'] );
        $this->assertSame( 'daily', $result['details']['mode'] );
        $this->assertSame( '20:00', $result['details']['current_time'] );
        $this->assertSame( '08:00', $result['details']['time_start'] );
        $this->assertSame( '17:00', $result['details']['time_end'] );
    }

    /**
     * Daily mode: date range OK, no time restriction → valid.
     */
    public function test_daily_valid_no_time_restriction(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '23:30';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '2026-06-01',
            'date_end'   => '2026-06-30',
            'time_start' => '',
            'time_end'   => '',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }

    /**
     * Daily mode: no date range, time within range → valid.
     */
    public function test_daily_valid_no_date_restriction(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'daily',
            'date_start' => '',
            'date_end'   => '',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }

    /**
     * Daily mode: custom msg_datetime is used in response.
     */
    public function test_daily_uses_custom_message(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-05-01';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'    => 'daily',
            'date_start'   => '2026-06-01',
            'date_end'     => '2026-06-30',
            'time_start'   => '08:00',
            'time_end'     => '17:00',
            'msg_datetime' => 'Custom restriction message.',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'Custom restriction message.', $result['message'] );
    }

    // ──────────────────────────────────────────────────────
    // Span mode tests
    // ──────────────────────────────────────────────────────

    /**
     * Span mode: current time within the datetime span → valid.
     *
     * Uses a span far in the past to far in the future so that time() is
     * always within range regardless of when the test runs.
     */
    public function test_span_valid_within_datetime_range(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'span',
            'date_start' => '2000-01-01',
            'date_end'   => '2099-12-31',
            'time_start' => '00:00',
            'time_end'   => '23:59',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }

    /**
     * Span mode: current time before the start datetime → invalid.
     *
     * Uses a span entirely in the future so time() is always before the start.
     */
    public function test_span_before_start_datetime(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2099-01-01';
            if ( $format === 'H:i' ) return '08:00';
            return '';
        } );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'span',
            'date_start' => '2099-01-01',
            'date_end'   => '2099-12-31',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'before_start_datetime', $result['details']['reason'] );
        $this->assertSame( 'span', $result['details']['mode'] );
        $this->assertArrayHasKey( 'now', $result['details'] );
        $this->assertArrayHasKey( 'start', $result['details'] );
    }

    /**
     * Span mode: current time after the end datetime → invalid.
     *
     * Uses a span entirely in the past so time() is always after the end.
     */
    public function test_span_after_end_datetime(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2000-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'span',
            'date_start' => '2000-01-01',
            'date_end'   => '2000-06-30',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'after_end_datetime', $result['details']['reason'] );
        $this->assertSame( 'span', $result['details']['mode'] );
        $this->assertArrayHasKey( 'now', $result['details'] );
        $this->assertArrayHasKey( 'end', $result['details'] );
    }

    /**
     * Span mode with same start/end dates falls back to daily logic.
     *
     * When date_start === date_end, the $different_dates condition is false,
     * so the span block is skipped and daily mode runs instead.
     */
    public function test_span_falls_back_to_daily_when_same_dates(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array(
            'time_mode'  => 'span',
            'date_start' => '2026-06-15',
            'date_end'   => '2026-06-15',
            'time_start' => '08:00',
            'time_end'   => '17:00',
        );

        $result = Geofence::validate_datetime( $config );

        // Falls through to daily logic; date is within range and time is 12:00 (inside 08:00-17:00).
        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }

    // ──────────────────────────────────────────────────────
    // Edge cases
    // ──────────────────────────────────────────────────────

    /**
     * Empty config (no dates, no times) → valid.
     */
    public function test_no_config_restrictions_returns_valid(): void {
        Functions\when( 'wp_date' )->alias( function( $format ) {
            if ( $format === 'Y-m-d' ) return '2026-06-15';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $config = array();

        $result = Geofence::validate_datetime( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( '', $result['message'] );
        $this->assertSame( array(), $result['details'] );
    }
}
