<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

class GeofenceLocationRegistryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // get_all() tests
    // ---------------------------------------------------------------

    public function test_get_all_returns_empty_array_when_no_option(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $result = GeofenceLocationRegistry::get_all();

        $this->assertSame( array(), $result );
    }

    public function test_get_all_returns_empty_array_when_option_is_not_array(): void {
        Functions\when( 'get_option' )->justReturn( 'invalid' );

        $result = GeofenceLocationRegistry::get_all();

        $this->assertSame( array(), $result );
    }

    public function test_get_all_returns_locations_array(): void {
        $locations = array(
            array( 'id' => 'loc_1', 'name' => 'Office', 'lat' => 40.0, 'lng' => -74.0, 'radius' => 5000.0 ),
            array( 'id' => 'loc_2', 'name' => 'Campus', 'lat' => 34.0, 'lng' => -118.0, 'radius' => 10000.0 ),
        );
        Functions\when( 'get_option' )->justReturn( $locations );

        $result = GeofenceLocationRegistry::get_all();

        $this->assertCount( 2, $result );
        $this->assertSame( 'loc_1', $result[0]['id'] );
        $this->assertSame( 'loc_2', $result[1]['id'] );
    }

    // ---------------------------------------------------------------
    // get_by_id() tests
    // ---------------------------------------------------------------

    public function test_get_by_id_returns_matching_location(): void {
        $locations = array(
            array( 'id' => 'loc_a', 'name' => 'A' ),
            array( 'id' => 'loc_b', 'name' => 'B' ),
        );
        Functions\when( 'get_option' )->justReturn( $locations );

        $result = GeofenceLocationRegistry::get_by_id( 'loc_b' );

        $this->assertIsArray( $result );
        $this->assertSame( 'B', $result['name'] );
    }

    public function test_get_by_id_returns_null_for_unknown_id(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_a', 'name' => 'A' ),
        ) );

        $result = GeofenceLocationRegistry::get_by_id( 'nonexistent' );

        $this->assertNull( $result );
    }

    // ---------------------------------------------------------------
    // get_by_ids() tests
    // ---------------------------------------------------------------

    public function test_get_by_ids_returns_matched_locations(): void {
        $locations = array(
            array( 'id' => 'loc_1', 'name' => 'One' ),
            array( 'id' => 'loc_2', 'name' => 'Two' ),
            array( 'id' => 'loc_3', 'name' => 'Three' ),
        );
        Functions\when( 'get_option' )->justReturn( $locations );

        $result = GeofenceLocationRegistry::get_by_ids( array( 'loc_1', 'loc_3' ) );

        $this->assertCount( 2, $result );
        $this->assertSame( 'One', $result[0]['name'] );
        $this->assertSame( 'Three', $result[1]['name'] );
    }

    public function test_get_by_ids_skips_unknown_ids(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'One' ),
        ) );

        $result = GeofenceLocationRegistry::get_by_ids( array( 'loc_1', 'nonexistent' ) );

        $this->assertCount( 1, $result );
    }

    // ---------------------------------------------------------------
    // save() tests
    // ---------------------------------------------------------------

    public function test_save_generates_id_when_empty(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-1234' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'update_option' )->justReturn( true );

        $id = GeofenceLocationRegistry::save( array(
            'name'   => 'Test',
            'lat'    => 40.0,
            'lng'    => -74.0,
            'radius' => 5000,
        ) );

        $this->assertStringStartsWith( 'loc_', $id );
    }

    public function test_save_updates_existing_location(): void {
        $existing = array(
            array( 'id' => 'loc_x', 'name' => 'Old', 'lat' => 10.0, 'lng' => 20.0, 'radius' => 1000.0, 'default_gps' => false, 'default_ip' => false ),
        );
        Functions\when( 'get_option' )->justReturn( $existing );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array(
            'id'     => 'loc_x',
            'name'   => 'Updated',
            'lat'    => 15.0,
            'lng'    => 25.0,
            'radius' => 2000,
        ) );

        $this->assertCount( 1, $saved_data );
        $this->assertSame( 'Updated', $saved_data[0]['name'] );
        $this->assertSame( 15.0, $saved_data[0]['lat'] );
    }

    public function test_save_clears_default_gps_from_others(): void {
        $existing = array(
            array( 'id' => 'loc_a', 'name' => 'A', 'lat' => 10.0, 'lng' => 20.0, 'radius' => 1000.0, 'default_gps' => true, 'default_ip' => false ),
            array( 'id' => 'loc_b', 'name' => 'B', 'lat' => 30.0, 'lng' => 40.0, 'radius' => 2000.0, 'default_gps' => false, 'default_ip' => false ),
        );
        Functions\when( 'get_option' )->justReturn( $existing );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array(
            'id'          => 'loc_b',
            'name'        => 'B',
            'lat'         => 30.0,
            'lng'         => 40.0,
            'radius'      => 2000,
            'default_gps' => true,
            'default_ip'  => false,
        ) );

        $this->assertFalse( $saved_data[0]['default_gps'] );
        $this->assertTrue( $saved_data[1]['default_gps'] );
    }

    public function test_save_clears_default_ip_from_others(): void {
        $existing = array(
            array( 'id' => 'loc_a', 'name' => 'A', 'lat' => 10.0, 'lng' => 20.0, 'radius' => 1000.0, 'default_gps' => false, 'default_ip' => true ),
            array( 'id' => 'loc_b', 'name' => 'B', 'lat' => 30.0, 'lng' => 40.0, 'radius' => 2000.0, 'default_gps' => false, 'default_ip' => false ),
        );
        Functions\when( 'get_option' )->justReturn( $existing );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array(
            'id'          => 'loc_b',
            'name'        => 'B',
            'lat'         => 30.0,
            'lng'         => 40.0,
            'radius'      => 2000,
            'default_gps' => false,
            'default_ip'  => true,
        ) );

        $this->assertFalse( $saved_data[0]['default_ip'] );
        $this->assertTrue( $saved_data[1]['default_ip'] );
    }

    // ---------------------------------------------------------------
    // sanitize_location() tests (exercised through save)
    // ---------------------------------------------------------------

    public function test_save_clamps_latitude(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array( 'name' => 'Test', 'lat' => 100.0, 'lng' => 0.0, 'radius' => 500 ) );
        $this->assertSame( 90.0, $saved_data[0]['lat'] );
    }

    public function test_save_clamps_negative_latitude(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array( 'name' => 'Test', 'lat' => -100.0, 'lng' => 0.0, 'radius' => 500 ) );
        $this->assertSame( -90.0, $saved_data[0]['lat'] );
    }

    public function test_save_clamps_longitude(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array( 'name' => 'Test', 'lat' => 0.0, 'lng' => 200.0, 'radius' => 500 ) );
        $this->assertSame( 180.0, $saved_data[0]['lng'] );
    }

    public function test_save_defaults_radius_when_zero_or_negative(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        GeofenceLocationRegistry::save( array( 'name' => 'Test', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 0 ) );
        $this->assertSame( 1000.0, $saved_data[0]['radius'] );
    }

    public function test_save_truncates_name_to_100_chars(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
        Functions\when( 'sanitize_key' )->alias( function ( $v ) { return $v; } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return $v; } );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        $long_name = str_repeat( 'A', 150 );
        GeofenceLocationRegistry::save( array( 'name' => $long_name, 'lat' => 0.0, 'lng' => 0.0, 'radius' => 1000 ) );
        $this->assertSame( 100, mb_strlen( $saved_data[0]['name'] ) );
    }

    // ---------------------------------------------------------------
    // delete() tests
    // ---------------------------------------------------------------

    public function test_delete_removes_location_and_returns_true(): void {
        $existing = array(
            array( 'id' => 'loc_1', 'name' => 'One' ),
            array( 'id' => 'loc_2', 'name' => 'Two' ),
        );
        Functions\when( 'get_option' )->justReturn( $existing );

        $saved_data = null;
        Functions\when( 'update_option' )->alias( function ( $key, $data ) use ( &$saved_data ) {
            $saved_data = $data;
            return true;
        } );

        $result = GeofenceLocationRegistry::delete( 'loc_1' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $saved_data );
        $this->assertSame( 'loc_2', $saved_data[0]['id'] );
    }

    public function test_delete_returns_false_for_unknown_id(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'One' ),
        ) );

        $result = GeofenceLocationRegistry::delete( 'nonexistent' );

        $this->assertFalse( $result );
    }

    // ---------------------------------------------------------------
    // get_default_gps() / get_default_ip() tests
    // ---------------------------------------------------------------

    public function test_get_default_gps_returns_matching_location(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'A', 'default_gps' => false ),
            array( 'id' => 'loc_2', 'name' => 'B', 'default_gps' => true ),
        ) );

        $result = GeofenceLocationRegistry::get_default_gps();

        $this->assertIsArray( $result );
        $this->assertSame( 'loc_2', $result['id'] );
    }

    public function test_get_default_gps_returns_null_when_none_set(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'default_gps' => false ),
        ) );

        $this->assertNull( GeofenceLocationRegistry::get_default_gps() );
    }

    public function test_get_default_ip_returns_matching_location(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'A', 'default_ip' => true ),
            array( 'id' => 'loc_2', 'name' => 'B', 'default_ip' => false ),
        ) );

        $result = GeofenceLocationRegistry::get_default_ip();

        $this->assertIsArray( $result );
        $this->assertSame( 'loc_1', $result['id'] );
    }

    // ---------------------------------------------------------------
    // resolve_to_areas_text() tests
    // ---------------------------------------------------------------

    public function test_resolve_to_areas_text_formats_correctly(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'Office', 'lat' => 40.7128, 'lng' => -74.006, 'radius' => 5000.0 ),
            array( 'id' => 'loc_2', 'name' => 'Campus', 'lat' => 34.0522, 'lng' => -118.2437, 'radius' => 10000.0 ),
        ) );

        $result = GeofenceLocationRegistry::resolve_to_areas_text( array( 'loc_1', 'loc_2' ) );

        $expected = "40.7128, -74.006, 5000\n34.0522, -118.2437, 10000";
        $this->assertSame( $expected, $result );
    }

    public function test_resolve_to_areas_text_skips_unknown_ids(): void {
        Functions\when( 'get_option' )->justReturn( array(
            array( 'id' => 'loc_1', 'name' => 'Office', 'lat' => 40.7128, 'lng' => -74.006, 'radius' => 5000.0 ),
        ) );

        $result = GeofenceLocationRegistry::resolve_to_areas_text( array( 'loc_1', 'nonexistent' ) );

        $this->assertSame( '40.7128, -74.006, 5000', $result );
    }

    public function test_resolve_to_areas_text_returns_empty_for_no_matches(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $result = GeofenceLocationRegistry::resolve_to_areas_text( array( 'nonexistent' ) );

        $this->assertSame( '', $result );
    }
}
