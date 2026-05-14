<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\ExpiredTicketsCleanup;

/**
 * Tests for the ExpiredTicketsCleanup daily cron introduced in 6.5.6.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Admin\ExpiredTicketsCleanup
 */
class ExpiredTicketsCleanupTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array<string, mixed>> */
    private array $meta_store = array();

    /** @var array<int, bool> form_id => has_expired */
    private array $expired_map = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 0 ) );

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

    private function stub_geofence(): void {
        $expired = &$this->expired_map;
        Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
            ->shouldReceive( 'has_form_expired' )
            ->andReturnUsing( function ( $id ) use ( &$expired ) {
                return $expired[ $id ] ?? false;
            } );
    }

    private function stub_get_posts( array $ids ): void {
        Functions\when( 'get_posts' )->justReturn( $ids );
    }

    // ==================================================================
    // schedule() / unschedule() — wiring
    // ==================================================================

    public function test_schedule_registers_when_not_scheduled(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        $captured = array();
        Functions\when( 'wp_schedule_event' )->alias( function ( $ts, $recur, $hook ) use ( &$captured ) {
            $captured = compact( 'ts', 'recur', 'hook' );
            return true;
        } );
        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }

        ExpiredTicketsCleanup::schedule();

        $this->assertSame( 'daily', $captured['recur'] );
        $this->assertSame( 'ffc_daily_expired_tickets_cleanup', $captured['hook'] );
    }

    public function test_schedule_skips_when_already_scheduled(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
        $called = false;
        Functions\when( 'wp_schedule_event' )->alias( function () use ( &$called ) {
            $called = true;
            return true;
        } );

        ExpiredTicketsCleanup::schedule();

        $this->assertFalse( $called, 'wp_schedule_event must not be called when already scheduled' );
    }

    public function test_unschedule_clears_when_present(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
        $captured = array();
        Functions\when( 'wp_unschedule_event' )->alias( function ( $ts, $hook ) use ( &$captured ) {
            $captured = compact( 'ts', 'hook' );
            return true;
        } );

        ExpiredTicketsCleanup::unschedule();

        $this->assertSame( 1234567890, $captured['ts'] );
        $this->assertSame( 'ffc_daily_expired_tickets_cleanup', $captured['hook'] );
    }

    public function test_unschedule_noop_when_absent(): void {
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        $called = false;
        Functions\when( 'wp_unschedule_event' )->alias( function () use ( &$called ) {
            $called = true;
            return true;
        } );

        ExpiredTicketsCleanup::unschedule();

        $this->assertFalse( $called );
    }

    // ==================================================================
    // purge_form_if_eligible() — rejection branches
    // ==================================================================

    public function test_skip_when_config_missing(): void {
        $this->stub_geofence();
        // No meta at all → get_post_meta returns '' → not array.
        $this->assertFalse( ExpiredTicketsCleanup::purge_form_if_eligible( 1 ) );
    }

    public function test_skip_when_ticket_gate_off(): void {
        $this->stub_geofence();
        $this->meta_store[1]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '' ),
            'generated_codes_list' => "A\nB",
        );
        $this->expired_map[1] = true;
        $this->assertFalse( ExpiredTicketsCleanup::purge_form_if_eligible( 1 ) );
    }

    public function test_skip_when_form_not_expired(): void {
        $this->stub_geofence();
        $this->meta_store[1]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "A\nB",
        );
        $this->expired_map[1] = false;
        $this->assertFalse( ExpiredTicketsCleanup::purge_form_if_eligible( 1 ) );
    }

    public function test_skip_when_codes_list_empty(): void {
        $this->stub_geofence();
        $this->meta_store[1]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "  \n  ",
        );
        $this->expired_map[1] = true;
        $this->assertFalse( ExpiredTicketsCleanup::purge_form_if_eligible( 1 ) );
    }

    // ==================================================================
    // purge_form_if_eligible() — happy path
    // ==================================================================

    public function test_purge_wipes_codes_and_returns_true(): void {
        $this->stub_geofence();
        $this->meta_store[1]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "A1\nB2\nC3",
        );
        $this->expired_map[1] = true;

        $r = ExpiredTicketsCleanup::purge_form_if_eligible( 1 );

        $this->assertTrue( $r );
        $this->assertSame( '', $this->meta_store[1]['_ffc_form_config']['generated_codes_list'] );
        // Toggle stays — only the codes are wiped.
        $this->assertSame( '1', $this->meta_store[1]['_ffc_form_config']['restrictions']['ticket'] );
    }

    // ==================================================================
    // run() — sweep loop
    // ==================================================================

    public function test_run_counts_purged_forms(): void {
        $this->stub_geofence();
        $this->stub_get_posts( array( 1, 2, 3 ) );

        // Form 1 — eligible.
        $this->meta_store[1]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "X",
        );
        $this->expired_map[1] = true;

        // Form 2 — not expired.
        $this->meta_store[2]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "X",
        );
        $this->expired_map[2] = false;

        // Form 3 — eligible.
        $this->meta_store[3]['_ffc_form_config'] = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "Y\nZ",
        );
        $this->expired_map[3] = true;

        $this->assertSame( 2, ExpiredTicketsCleanup::run() );
    }

    public function test_run_returns_zero_when_no_forms(): void {
        $this->stub_geofence();
        $this->stub_get_posts( array() );
        $this->assertSame( 0, ExpiredTicketsCleanup::run() );
    }
}
