<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder;

/**
 * Focused tests for the `can_open_early` flag introduced in 6.5.6.
 *
 * The flag must mirror EarlyOpenAction::is_eligible() exactly so the JS
 * never sees a "can-open" state that the server rejects on POST.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder
 */
class CsvDownloadFormInfoBuilderCanOpenEarlyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array<string, mixed>> */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_the_title' )->justReturn( 'Form X' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );
        Functions\when( 'wp_date' )->alias( function ( $fmt, $ts = null, $tz = null ) {
            return gmdate( $fmt, (int) $ts );
        } );
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( 'ffc_settings' === $name ) {
                return array();
            }
            if ( 'date_format' === $name ) {
                return 'Y-m-d';
            }
            if ( 'time_format' === $name ) {
                return 'H:i';
            }
            return $default;
        } );

        $this->meta_store = array();
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return $this->meta_store[ $id ][ $key ] ?? '';
        } );

        // SubmissionRepository::countForExport.
        Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' )
            ->shouldReceive( 'countForExport' )->andReturn( 0 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub Geofence with explicit start/end timestamps. Frozen "now"
     * is whatever PHP's time() returns at test runtime; tests pass
     * `now ± offset` so they remain stable across executions.
     */
    private function stub_geofence( ?int $start_ts, ?int $end_ts ): void {
        Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
            ->shouldReceive( 'get_form_start_timestamp' )->andReturn( $start_ts )
            ->getMock()->shouldReceive( 'get_form_end_timestamp' )->andReturn( $end_ts );
    }

    /**
     * Default form meta — every flag in the affirmative. Tests then
     * flip exactly one toggle to assert it's the discriminating one.
     */
    private function configure_form( int $form_id ): void {
        $this->meta_store[ $form_id ] = array(
            '_ffc_form_config'         => array(),
            '_ffc_geofence_config'     => array( 'datetime_enabled' => '1' ),
            '_ffc_csv_public_enabled'  => '1',
            '_ffc_csv_public_limit'    => 5,
            '_ffc_csv_public_count'    => 0,
        );
    }

    public function test_can_open_early_true_when_all_conditions_met(): void {
        $now = time();
        $this->stub_geofence( $now + 3600, $now + 7200 );
        $this->configure_form( 1 );

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertTrue( $info['status']['can_open_early'] );
    }

    public function test_can_open_early_false_when_form_already_started(): void {
        $now = time();
        $this->stub_geofence( $now - 3600, $now + 3600 );
        $this->configure_form( 1 );

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertFalse( $info['status']['can_open_early'] );
    }

    public function test_can_open_early_false_when_form_already_ended(): void {
        $now = time();
        $this->stub_geofence( $now + 3600, $now - 1800 );
        $this->configure_form( 1 );

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertFalse( $info['status']['can_open_early'] );
    }

    public function test_can_open_early_false_when_csv_public_disabled(): void {
        $now = time();
        $this->stub_geofence( $now + 3600, $now + 7200 );
        $this->configure_form( 1 );
        $this->meta_store[1]['_ffc_csv_public_enabled'] = '';

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertFalse( $info['status']['can_open_early'] );
    }

    public function test_can_open_early_false_when_datetime_restrictions_disabled(): void {
        $now = time();
        $this->stub_geofence( $now + 3600, $now + 7200 );
        $this->configure_form( 1 );
        $this->meta_store[1]['_ffc_geofence_config']['datetime_enabled'] = '';

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertFalse( $info['status']['can_open_early'] );
    }

    public function test_can_open_early_false_when_no_start_date(): void {
        $now = time();
        $this->stub_geofence( null, $now + 7200 );
        $this->configure_form( 1 );

        $info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
        $this->assertFalse( $info['status']['can_open_early'] );
    }
}
