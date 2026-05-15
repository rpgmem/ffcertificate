<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\EarlyOpenAction;

/**
 * Tests for the EarlyOpenAction service introduced in 6.5.6.
 *
 * Run in separate processes — the service depends on three static
 * classes (Geofence, FormCache, ActivityLog) that are easier to alias
 * one-test-at-a-time than to share across the suite.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Frontend\EarlyOpenAction
 */
class EarlyOpenActionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array<string, mixed>> */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        // ActivityLog::log() reads ffc_settings to gate itself; stub
        // the option as activity-log-disabled so the call no-ops and
        // we don't have to mock the whole logging stack.
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 0 ) );
        Functions\when( 'current_time' )->alias( function ( $type ) {
            // Frozen "now" so eligibility checks are reproducible:
            // 2026-05-14 12:00:00 UTC — same instant for every call.
            $ts = 1778760000;
            if ( 'timestamp' === $type ) {
                return $ts;
            }
            return gmdate( $type, $ts );
        } );
        // is_eligible() compares against time(); shadow it inside the
        // EarlyOpenAction namespace so the frozen "now" applies.
        Functions\when( 'FreeFormCertificate\Frontend\time' )->justReturn( 1778760000 );

        $this->meta_store = array();
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return $this->meta_store[ $id ][ $key ] ?? '';
        } );
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->meta_store[ $id ][ $key ] = $value;
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_geofence( ?int $start_ts, ?int $end_ts ): void {
        Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
            ->shouldReceive( 'get_form_start_timestamp' )->andReturn( $start_ts )
            ->getMock()->shouldReceive( 'get_form_end_timestamp' )->andReturn( $end_ts );
    }

    private function stub_formcache(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' )
            ->shouldReceive( 'clear_form_cache' )->andReturn( true )
            ->getMock()->shouldReceive( 'purge_external_caches' )->andReturnNull();
    }

    private function configure_eligible_form( int $form_id, string $hash ): void {
        // date_start must match the frozen "today" (2026-05-14) for the
        // same-day guard in is_eligible() to pass. The button only
        // surfaces when the form is scheduled to start today.
        $this->meta_store[ $form_id ] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => $hash,
            '_ffc_geofence_config'    => array(
                'datetime_enabled' => '1',
                'date_start'       => '2026-05-14',
                'time_start'       => '23:00',
            ),
        );
        // start_ts: later today (6 hours after frozen now). end_ts: tomorrow.
        $this->stub_geofence( 1778781600, 1778846400 );
    }

    // ==================================================================
    // is_eligible() — rejection branches
    // ==================================================================

    public function test_unknown_form_when_post_type_mismatch(): void {
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->stub_geofence( null, null );

        $r = EarlyOpenAction::is_eligible( 99, 'h' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'unknown_form', $r['reason'] );
    }

    public function test_unknown_form_when_id_zero(): void {
        $this->stub_geofence( null, null );
        $r = EarlyOpenAction::is_eligible( 0, 'h' );
        $this->assertSame( 'unknown_form', $r['reason'] );
    }

    public function test_csv_disabled_when_toggle_off(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1]['_ffc_csv_public_enabled'] = '';
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'csv_disabled', $r['reason'] );
    }

    public function test_bad_hash_when_stored_empty(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => '',
        );
        $r = EarlyOpenAction::is_eligible( 1, 'whatever' );
        $this->assertSame( 'bad_hash', $r['reason'] );
    }

    public function test_bad_hash_when_mismatched(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'good',
        );
        $r = EarlyOpenAction::is_eligible( 1, 'bad' );
        $this->assertSame( 'bad_hash', $r['reason'] );
    }

    public function test_early_open_disabled_when_per_form_toggle_off(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'              => '1',
            '_ffc_csv_public_hash'                 => 'h',
            '_ffc_csv_public_start_early_enabled'  => '0',
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'early_open_disabled', $r['reason'] );
    }

    public function test_early_open_enabled_when_per_form_toggle_unset_defaults_to_on(): void {
        // Pre-6.5.8 forms have no stored value — must NOT regress.
        $this->configure_eligible_form( 1, 'h' );
        // configure_eligible_form() doesn't set the new meta — leave unset.
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertTrue( $r['ok'] );
    }

    public function test_datetime_disabled(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array( 'datetime_enabled' => '' ),
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'datetime_disabled', $r['reason'] );
    }

    public function test_no_start_date(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'no_start_date', $r['reason'] );
    }

    public function test_already_started_when_start_in_past(): void {
        // start_ts is in the past relative to frozen now (1778760000).
        $this->stub_geofence( 1778673600, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'already_started', $r['reason'] );
    }

    public function test_already_ended_when_end_in_past(): void {
        // start_ts is in the future, end_ts is in the past.
        $this->stub_geofence( 1778846400, 1778673600 );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'already_ended', $r['reason'] );
    }

    public function test_not_today_when_date_start_in_future(): void {
        // date_start is a future calendar day → button must not appear
        // because the action only rewrites time_start (operator can't
        // shift the form to a different day from this surface).
        $this->stub_geofence( 1778846400, 1778932800 );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array(
                'datetime_enabled' => '1',
                'date_start'       => '2026-05-15',
                'time_start'       => '21:00',
            ),
        );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertSame( 'not_today', $r['reason'] );
    }

    // ==================================================================
    // is_eligible() — happy path
    // ==================================================================

    public function test_ok_when_all_conditions_met(): void {
        $this->configure_eligible_form( 1, 'h' );
        $r = EarlyOpenAction::is_eligible( 1, 'h' );
        $this->assertTrue( $r['ok'] );
    }

    // ==================================================================
    // execute() — happy path
    // ==================================================================

    public function test_execute_writes_time_start_only_and_preserves_date_start(): void {
        // The action narrows the write to time_start. date_start /
        // date_end / time_end / time_mode are all preserved as-is —
        // the operator opens the form earlier within the same scheduled
        // day, nothing else.
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();

        $r = EarlyOpenAction::execute( 1, 'h', array( 'user_id' => 7, 'ip' => '1.2.3.4', 'ua' => 'UA' ) );

        $this->assertTrue( $r['ok'] );
        // Frozen now is 2026-05-14 12:00:00 (UTC for our gmdate stub).
        $this->assertSame( '2026-05-14', $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
        $this->assertSame( '12:00',      $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
        $this->assertSame( '2026-05-14 23:00', $r['original_start_iso'] );
        $this->assertSame( '2026-05-14 12:00', $r['new_start_iso'] );
    }

    public function test_execute_preserves_full_window_on_multi_day_forms(): void {
        // Form scheduled for today with a future date_end. The action
        // only touches time_start — date_end, time_end and time_mode
        // all stay exactly as the admin configured them.
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array(
                'datetime_enabled' => '1',
                'date_start'       => '2026-05-14',
                'time_start'       => '23:00',
                'date_end'         => '2026-05-16',
                'time_end'         => '21:00',
                'time_mode'        => 'daily',
            ),
        );
        $this->stub_geofence( 1778781600, 1778932800 );
        $this->stub_formcache();

        $r = EarlyOpenAction::execute( 1, 'h' );

        $this->assertTrue( $r['ok'] );
        $this->assertSame( '2026-05-14', $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
        $this->assertSame( '12:00',      $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
        // date_end + time_end + time_mode are preserved.
        $this->assertSame( '2026-05-16', $this->meta_store[1]['_ffc_geofence_config']['date_end'] );
        $this->assertSame( '21:00',      $this->meta_store[1]['_ffc_geofence_config']['time_end'] );
        $this->assertSame( 'daily',      $this->meta_store[1]['_ffc_geofence_config']['time_mode'] );
    }

    public function test_execute_short_circuits_when_eligibility_fails(): void {
        // Form exists but has no start date — eligibility fails, no writes.
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            '_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
        );

        $r = EarlyOpenAction::execute( 1, 'h' );

        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'no_start_date', $r['reason'] );
        // Original geofence is untouched.
        $this->assertArrayNotHasKey( 'date_start', $this->meta_store[1]['_ffc_geofence_config'] );
    }
}
