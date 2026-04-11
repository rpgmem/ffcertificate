<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::get_form_end_timestamp() and has_form_expired().
 *
 * These helpers power the public CSV download feature (v5.1.0): the
 * download is only released after the form's configured end date/time.
 */
class GeofenceFormExpirationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Fixed UTC timezone keeps date math deterministic across environments.
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    //  get_form_end_timestamp()
    // ==================================================================

    public function test_get_form_end_timestamp_returns_null_when_meta_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_returns_null_when_meta_not_array(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'junk-string' );

        $this->assertNull( Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_returns_null_when_date_end_missing(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled' => '1',
            'date_start'       => '2026-01-01',
            // date_end intentionally absent
        ) );

        $this->assertNull( Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_returns_null_when_date_end_empty_string(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => '   ',
        ) );

        $this->assertNull( Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_defaults_time_end_to_end_of_day(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => '2026-06-15',
            // time_end not set → implementation uses 23:59:59
        ) );

        $expected = ( new \DateTimeImmutable( '2026-06-15 23:59:59', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_uses_configured_time_end(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => '2026-06-15',
            'time_end' => '17:30',
        ) );

        $expected = ( new \DateTimeImmutable( '2026-06-15 17:30', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_trims_whitespace(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => '  2026-06-15  ',
            'time_end' => ' 09:00 ',
        ) );

        $expected = ( new \DateTimeImmutable( '2026-06-15 09:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 10 ) );
    }

    public function test_get_form_end_timestamp_returns_null_on_invalid_date(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => 'not-a-date',
            'time_end' => 'nope',
        ) );

        $this->assertNull( Geofence::get_form_end_timestamp( 10 ) );
    }

    // ==================================================================
    //  has_form_expired()
    // ==================================================================

    public function test_has_form_expired_false_when_no_end_configured(): void {
        Functions\when( 'get_post_meta' )->justReturn( array() );

        $this->assertFalse( Geofence::has_form_expired( 10 ) );
    }

    public function test_has_form_expired_false_when_end_is_in_the_future(): void {
        $future = ( new \DateTimeImmutable( '@' . ( time() + 3600 ) ) )
            ->setTimezone( new \DateTimeZone( 'UTC' ) );
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => $future->format( 'Y-m-d' ),
            'time_end' => $future->format( 'H:i' ),
        ) );

        // Even if the same-minute end timestamp is a few seconds in the future,
        // has_form_expired() must return false until time() strictly exceeds it.
        $this->assertFalse( Geofence::has_form_expired( 10 ) );
    }

    public function test_has_form_expired_true_when_end_is_in_the_past(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => '2000-01-01',
            'time_end' => '00:00:00',
        ) );

        $this->assertTrue( Geofence::has_form_expired( 10 ) );
    }

    public function test_has_form_expired_uses_end_of_day_default_for_missing_time(): void {
        // A date-only value should include the full day — the form is not
        // expired at 00:00 of the end date, only after 23:59:59.
        $tomorrow = gmdate( 'Y-m-d', time() + 86400 );
        Functions\when( 'get_post_meta' )->justReturn( array(
            'date_end' => $tomorrow,
        ) );

        $this->assertFalse( Geofence::has_form_expired( 10 ) );
    }
}
