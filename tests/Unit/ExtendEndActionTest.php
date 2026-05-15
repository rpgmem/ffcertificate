<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ExtendEndAction;

/**
 * Tests for the ExtendEndAction service introduced in 6.5.12.
 *
 * Sibling of EarlyOpenAction but for the close boundary. Lets a trusted
 * operator push `time_end` later within the same day, exactly once per
 * form. Mirrors the EarlyOpenActionTest structure for consistency.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Frontend\ExtendEndAction
 */
class ExtendEndActionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array<string, mixed>> */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 0 ) );
        Functions\when( 'current_time' )->alias( function ( $type ) {
            // Frozen "now" — 2026-05-14 12:00:00 UTC.
            $ts = 1778760000;
            if ( 'timestamp' === $type ) {
                return $ts;
            }
            return gmdate( $type, $ts );
        } );
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
            ->getMock()->shouldReceive( 'purge_external_caches' )->andReturnNull()
            ->getMock()->shouldReceive( 'purge_all_pages' )->andReturnNull();
    }

    /**
     * Eligible fixture — form is currently open (started, not ended),
     * configured for today, opt-in on, hash matches, no prior postponement.
     */
    private function configure_eligible_form( int $form_id, string $hash ): void {
        $this->meta_store[ $form_id ] = array(
            '_ffc_csv_public_enabled'             => '1',
            '_ffc_csv_public_hash'                => $hash,
            '_ffc_csv_public_extend_end_enabled'  => '1',
            '_ffc_geofence_config'                => array(
                'datetime_enabled' => '1',
                'date_start'       => '2026-05-14',
                'time_start'       => '10:00',
                'date_end'         => '2026-05-14',
                'time_end'         => '14:00',
            ),
        );
        // start_ts in past, end_ts in future (form is currently open).
        $this->stub_geofence( 1778673600, 1778767200 );
    }

    // ==================================================================
    // is_eligible() — rejection branches
    // ==================================================================

    public function test_unknown_form_when_post_type_mismatch(): void {
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->stub_geofence( null, null );
        $r = ExtendEndAction::is_eligible( 99, 'h' );
        $this->assertSame( 'unknown_form', $r['reason'] );
    }

    public function test_csv_disabled_when_toggle_off(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1]['_ffc_csv_public_enabled'] = '';
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'csv_disabled', $r['reason'] );
    }

    public function test_bad_hash_when_mismatched(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'good',
        );
        $r = ExtendEndAction::is_eligible( 1, 'bad' );
        $this->assertSame( 'bad_hash', $r['reason'] );
    }

    public function test_extend_end_disabled_when_opt_in_unset(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled' => '1',
            '_ffc_csv_public_hash'    => 'h',
            // _ffc_csv_public_extend_end_enabled unset → reads as '0'.
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'extend_end_disabled', $r['reason'] );
    }

    public function test_datetime_disabled_when_geofence_off(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array( 'datetime_enabled' => '' ),
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'datetime_disabled', $r['reason'] );
    }

    public function test_no_end_date(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array( 'datetime_enabled' => '1' ),
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'no_end_date', $r['reason'] );
    }

    public function test_not_started_yet(): void {
        // start in future, end in farther future.
        $this->stub_geofence( 1778767200, 1778800000 );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array(
                'datetime_enabled' => '1',
                'date_end'         => '2026-05-14',
                'time_end'         => '20:00',
            ),
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'not_started_yet', $r['reason'] );
    }

    public function test_already_ended(): void {
        // start in past, end in past.
        $this->stub_geofence( 1778673600, 1778750000 );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array(
                'datetime_enabled' => '1',
                'date_end'         => '2026-05-14',
                'time_end'         => '08:00',
            ),
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'already_ended', $r['reason'] );
    }

    public function test_not_today_when_date_end_is_future(): void {
        $this->stub_geofence( 1778673600, 1778932800 );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array(
                'datetime_enabled' => '1',
                'date_end'         => '2026-05-16',
                'time_end'         => '20:00',
            ),
        );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'not_today', $r['reason'] );
    }

    public function test_already_postponed_blocks_second_run(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ] = '1778759000';
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertSame( 'already_postponed', $r['reason'] );
    }

    // ==================================================================
    // is_eligible() — happy path
    // ==================================================================

    public function test_ok_when_all_conditions_met(): void {
        $this->configure_eligible_form( 1, 'h' );
        $r = ExtendEndAction::is_eligible( 1, 'h' );
        $this->assertTrue( $r['ok'] );
    }

    // ==================================================================
    // execute() — happy path + write semantics
    // ==================================================================

    public function test_execute_writes_new_time_end_and_flips_one_shot(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();

        $r = ExtendEndAction::execute( 1, 'h', '15:30', array( 'user_id' => 7, 'ip' => '1.2.3.4', 'ua' => 'UA' ) );

        $this->assertTrue( $r['ok'] );
        $this->assertSame( '15:30', $this->meta_store[1]['_ffc_geofence_config']['time_end'] );
        // Original window is snapshotted for the audit trail.
        $this->assertSame( '14:00', $this->meta_store[1][ ExtendEndAction::META_POSTPONED_FROM ] );
        // One-shot flag set.
        $this->assertNotEmpty( $this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ] );
        $this->assertSame( '2026-05-14 14:00', $r['original_end_iso'] );
        $this->assertSame( '2026-05-14 15:30', $r['new_end_iso'] );
    }

    public function test_execute_preserves_date_end_and_other_fields(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();

        ExtendEndAction::execute( 1, 'h', '18:45' );

        $this->assertSame( '2026-05-14', $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
        $this->assertSame( '10:00',      $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
        $this->assertSame( '2026-05-14', $this->meta_store[1]['_ffc_geofence_config']['date_end'] );
    }

    // ==================================================================
    // execute() — new_time_end validation
    // ==================================================================

    public function test_execute_rejects_bad_time_format(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();
        $r = ExtendEndAction::execute( 1, 'h', '25:99' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'bad_time_format', $r['reason'] );
    }

    public function test_execute_rejects_non_extending_value(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();
        // Current time_end is 14:00, picking 13:30 doesn't extend.
        $r = ExtendEndAction::execute( 1, 'h', '13:30' );
        $this->assertSame( 'not_extending', $r['reason'] );
    }

    public function test_execute_rejects_equal_value(): void {
        $this->configure_eligible_form( 1, 'h' );
        $this->stub_formcache();
        $r = ExtendEndAction::execute( 1, 'h', '14:00' );
        $this->assertSame( 'not_extending', $r['reason'] );
    }

    public function test_execute_rejects_past_now_value(): void {
        // Eligibility ok (form open). User picks 11:30 — extends from
        // 14:00? No, 11:30 < 14:00 so not_extending fires first. Use a
        // scenario where current time_end < now to test past_now.
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array(
                'datetime_enabled' => '1',
                'date_start'       => '2026-05-14',
                'time_start'       => '08:00',
                'date_end'         => '2026-05-14',
                'time_end'         => '09:00',
            ),
        );
        // start in past, end in past — but we want to test the validate
        // path so stub end_ts as still future (race condition / clock
        // skew where eligibility's end_ts check passed but the stored
        // time_end is stale).
        $this->stub_geofence( 1778673600, 1778763600 );
        $this->stub_formcache();
        // 11:00 > 09:00 (extending) but < 12:00 (now).
        $r = ExtendEndAction::execute( 1, 'h', '11:00' );
        $this->assertSame( 'past_now', $r['reason'] );
    }

    // ==================================================================
    // execute() — short-circuits on eligibility failure
    // ==================================================================

    public function test_execute_short_circuits_when_eligibility_fails(): void {
        $this->stub_geofence( null, null );
        $this->meta_store[1] = array(
            '_ffc_csv_public_enabled'            => '1',
            '_ffc_csv_public_hash'               => 'h',
            '_ffc_csv_public_extend_end_enabled' => '1',
            '_ffc_geofence_config'               => array( 'datetime_enabled' => '1' ),
        );
        $r = ExtendEndAction::execute( 1, 'h', '20:00' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'no_end_date', $r['reason'] );
        // No write happened.
        $this->assertArrayNotHasKey( ExtendEndAction::META_POSTPONED_AT, $this->meta_store[1] );
    }
}
