<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\LocationsAjaxEndpoint;

/**
 * Tests for the per-row locations AJAX endpoint introduced in 6.5.4.
 *
 * @covers \FreeFormCertificate\Admin\LocationsAjaxEndpoint
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LocationsAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface|null */
    private $registry_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            $msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
            throw new \RuntimeException( 'json_error: ' . $msg );
        } );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            throw new \RuntimeException( 'json_success' );
        } );

        // Overload GeofenceLocationRegistry to control its return values.
        $this->registry_mock = Mockery::mock( 'alias:\FreeFormCertificate\Security\GeofenceLocationRegistry' );
    }

    protected function tearDown(): void {
        unset( $_POST['id'], $_POST['name'], $_POST['lat'], $_POST['lng'], $_POST['radius'], $_POST['default_gps'], $_POST['default_ip'] );
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    // ==================================================================
    // handle_save() — validation
    // ==================================================================

    public function test_save_rejects_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $_POST = array( 'name' => 'X' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        LocationsAjaxEndpoint::handle_save();
    }

    public function test_save_rejects_empty_name(): void {
        $_POST = array( 'name' => '   ' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'name is required' );
        LocationsAjaxEndpoint::handle_save();
    }

    public function test_save_with_unknown_id_returns_404(): void {
        $this->registry_mock->shouldReceive( 'get_by_id' )->with( 'loc_bogus' )->andReturn( null );
        $_POST = array( 'name' => 'X', 'id' => 'loc_bogus', 'lat' => 0, 'lng' => 0, 'radius' => 100 );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Location not found' );
        LocationsAjaxEndpoint::handle_save();
    }

    // ==================================================================
    // handle_save() — happy paths
    // ==================================================================

    public function test_save_creates_new_when_id_is_missing(): void {
        $captured = null;
        $this->registry_mock->shouldReceive( 'save' )->andReturnUsing( function ( $loc ) use ( &$captured ) {
            $captured = $loc;
            return 'loc_new';
        } );
        $this->registry_mock->shouldReceive( 'get_by_id' )->with( 'loc_new' )->andReturn(
            array( 'id' => 'loc_new', 'name' => 'X', 'lat' => 1.0, 'lng' => 2.0, 'radius' => 100.0 )
        );

        $_POST = array( 'name' => 'X', 'lat' => 1.0, 'lng' => 2.0, 'radius' => 100, 'default_gps' => '0', 'default_ip' => '0' );

        try {
            LocationsAjaxEndpoint::handle_save();
        } catch ( \RuntimeException $e ) {
            // wp_send_json_success throws as expected.
        }

        $this->assertSame( 'X', $captured['name'] );
        $this->assertSame( 1.0, $captured['lat'] );
        $this->assertArrayNotHasKey( 'id', $captured );
    }

    public function test_save_updates_existing_when_id_matches(): void {
        $captured = null;
        $this->registry_mock->shouldReceive( 'get_by_id' )->with( 'loc_existing' )->andReturn( array( 'id' => 'loc_existing', 'name' => 'old' ) );
        $this->registry_mock->shouldReceive( 'save' )->andReturnUsing( function ( $loc ) use ( &$captured ) {
            $captured = $loc;
            return 'loc_existing';
        } );

        $_POST = array(
            'id'     => 'loc_existing',
            'name'   => 'New name',
            'lat'    => 10,
            'lng'    => 20,
            'radius' => 500,
            'default_gps' => 'true',
            'default_ip'  => '0',
        );

        try {
            LocationsAjaxEndpoint::handle_save();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( 'loc_existing', $captured['id'] );
        $this->assertSame( 'New name', $captured['name'] );
        $this->assertTrue( $captured['default_gps'] );
        $this->assertFalse( $captured['default_ip'] );
    }

    // ==================================================================
    // handle_delete()
    // ==================================================================

    public function test_delete_rejects_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $_POST = array( 'id' => 'loc_x' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        LocationsAjaxEndpoint::handle_delete();
    }

    public function test_delete_rejects_missing_id(): void {
        $_POST = array();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Missing location id' );
        LocationsAjaxEndpoint::handle_delete();
    }

    public function test_delete_returns_404_when_registry_reports_not_found(): void {
        $this->registry_mock->shouldReceive( 'delete' )->with( 'loc_missing' )->andReturn( false );
        $_POST = array( 'id' => 'loc_missing' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Location not found' );
        LocationsAjaxEndpoint::handle_delete();
    }

    public function test_delete_succeeds_when_registry_reports_removed(): void {
        $this->registry_mock->shouldReceive( 'delete' )->with( 'loc_existing' )->andReturn( true );
        $_POST = array( 'id' => 'loc_existing' );

        try {
            LocationsAjaxEndpoint::handle_delete();
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
        }
    }
}
