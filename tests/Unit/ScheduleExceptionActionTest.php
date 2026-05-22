<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ScheduleExceptionAction;

/**
 * Tests for ScheduleExceptionAction — Sprint 4 of #366.
 *
 * @covers \FreeFormCertificate\Frontend\ScheduleExceptionAction
 */
class ScheduleExceptionActionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> Reset per-test bag of post-meta values keyed by [post_id][meta_key]. */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // WP helpers used by the action (or its callees).
        Functions\when( 'wp_salt' )->justReturn( 'test-nonce-salt' );
        Functions\when( 'is_ssl' )->justReturn( false );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
        Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        // Cookie path lives in ScheduleExceptionSession; capture but don't
        // assert on it — those assertions live in ScheduleExceptionSessionTest.
        Functions\when( 'setcookie' )->justReturn( true );

        // Reset per-test.
        $this->meta_store = array();
        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                return $this->meta_store[ (int) $post_id ][ $key ] ?? ( $single ? '' : array() );
            }
        );
        Functions\when( 'get_post_type' )->alias(
            fn( $post_id ) => $this->meta_store[ (int) $post_id ]['__post_type'] ?? 'ffc_form'
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a minimum-viable form: existing CPT, CSV public on, valid hash,
     * datetime ON, schedule exception ON, window large enough that "now"
     * sits inside it.
     */
    private function seed_form(
        int $form_id = 42,
        array $overrides = array()
    ): void {
        $defaults = array(
            '__post_type'                 => 'ffc_form',
            '_ffc_csv_public_enabled'     => '1',
            '_ffc_csv_public_hash'        => 'good-hash',
            '_ffc_geofence_config'        => array(
                'datetime_enabled'             => '1',
                'schedule_exception_enabled'   => '1',
                'time_start'                   => '08:00',
                'time_end'                     => '18:00',
                'class_time_start'             => '',
                'class_time_end'               => '',
                'date_start'                   => '2026-05-22',
                'date_end'                     => '2026-05-22',
            ),
        );
        $this->meta_store[ $form_id ] = array_merge( $defaults, $overrides );

        // Stub the geofence timestamp resolver to mean "form open now".
        $now = time();
        $geofence_class = Mockery::mock( 'alias:\FreeFormCertificate\Security\Geofence' );
        $geofence_class->shouldReceive( 'get_form_start_timestamp' )
            ->andReturn( $now - 3600 ) // started 1h ago
            ->byDefault();
        $geofence_class->shouldReceive( 'get_form_end_timestamp' )
            ->andReturn( $now + 3600 ) // ends in 1h
            ->byDefault();
    }

    // ==================================================================
    // is_eligible() — gate cascade
    // ==================================================================

    public function test_is_eligible_rejects_unknown_form(): void {
        $this->meta_store[ 1 ] = array( '__post_type' => 'post' );

        $result = ScheduleExceptionAction::is_eligible( 1, 'whatever' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'unknown_form', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_csv_disabled(): void {
        $this->seed_form( 42, array( '_ffc_csv_public_enabled' => '0' ) );

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'csv_disabled', $result['reason'] );
    }

    public function test_is_eligible_rejects_bad_hash(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::is_eligible( 42, 'wrong-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bad_hash', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_toggle_off(): void {
        $this->seed_form();
        $this->meta_store[ 42 ]['_ffc_geofence_config']['schedule_exception_enabled'] = '0';

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'schedule_exception_disabled', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_datetime_off(): void {
        $this->seed_form();
        $this->meta_store[ 42 ]['_ffc_geofence_config']['datetime_enabled'] = '0';

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'datetime_disabled', $result['reason'] );
    }

    public function test_is_eligible_passes_inside_window(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertTrue( $result['ok'], 'with a green-path seed the action must report eligible' );
    }

    // ==================================================================
    // execute() — validation envelope
    // ==================================================================

    public function test_execute_rejects_inverted_range(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '17:00', '09:00', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'range_inverted', $result['reason'] );
    }

    public function test_execute_rejects_out_of_window(): void {
        $this->seed_form();
        // Window is 08:00-18:00; ask for 19:00 end.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '08:00', '19:00', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'out_of_window', $result['reason'] );
    }

    public function test_execute_rejects_bad_time_format(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', 'not-a-time', '', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bad_time_format', $result['reason'] );
    }

    public function test_execute_rejects_no_change(): void {
        $this->seed_form();
        // Both overrides empty AND baseline (class_time_*) is empty too,
        // so effective range collapses to (time_start, time_end) = baseline.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '', '', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'no_change', $result['reason'] );
    }

    public function test_execute_returns_token_and_url_on_success(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertNotEmpty( $result['token'] );
        $this->assertNotEmpty( $result['form_url'] );
        $this->assertStringContainsString( '.', $result['token'], 'token has body.signature shape' );
    }

    public function test_execute_resolves_class_time_baseline_before_geofence(): void {
        $this->seed_form();
        // class_time_* overrides time_* as the baseline source.
        $this->meta_store[ 42 ]['_ffc_geofence_config']['class_time_start'] = '08:30';
        $this->meta_store[ 42 ]['_ffc_geofence_config']['class_time_end']   = '17:30';

        // Override end at 17:30 (matches class_time_end), start blank.
        // Effective range = (08:30, 17:30) which IS the class baseline → no_change.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '', '17:30', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'no_change', $result['reason'], 'class_time_* must be the baseline source when present' );
    }

    public function test_execute_filter_overrides_form_url(): void {
        $this->seed_form();
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value, $form_id ) {
                if ( 'ffc_schedule_exception_form_url' === $hook ) {
                    return 'https://example.test/custom-form-' . $form_id;
                }
                return $value;
            }
        );

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'https://example.test/custom-form-42', $result['form_url'] );
    }
}
