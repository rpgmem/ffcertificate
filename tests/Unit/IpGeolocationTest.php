<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\IpGeolocation;

/**
 * Tests for IpGeolocation: Haversine distance calculation, geospatial
 * area containment (AND/OR logic), get_location flow, and cache clearing.
 *
 * @covers \FreeFormCertificate\Integrations\IpGeolocation
 */
class IpGeolocationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // WP_Error is used without leading \ in the Integrations namespace
        if ( ! class_exists( 'FreeFormCertificate\Integrations\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Integrations\WP_Error' );
        }

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );

        // Namespaced stubs: FreeFormCertificate\Integrations\*
        Functions\when( 'FreeFormCertificate\Integrations\get_option' )->justReturn( '' );
        Functions\when( 'FreeFormCertificate\Integrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Integrations\is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'FreeFormCertificate\Integrations\get_transient' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Integrations\set_transient' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Integrations\delete_transient' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Integrations\absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'FreeFormCertificate\Integrations\wp_remote_get' )->justReturn( new \WP_Error( 'test', 'stubbed' ) );
        Functions\when( 'FreeFormCertificate\Integrations\wp_remote_retrieve_body' )->justReturn( '' );

        // Namespaced stubs: FreeFormCertificate\Core\* (for Utils::debug_log)
        if ( ! function_exists( 'FreeFormCertificate\Core\sanitize_text_field' ) ) {
            Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        }
        if ( ! function_exists( 'FreeFormCertificate\Core\wp_unslash' ) ) {
            Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // calculate_distance() — Haversine formula (pure math)
    // ==================================================================

    public function test_same_point_returns_zero(): void {
        $distance = IpGeolocation::calculate_distance( -23.5505, -46.6333, -23.5505, -46.6333 );

        $this->assertEqualsWithDelta( 0.0, $distance, 0.01 );
    }

    public function test_known_distance_sao_paulo_to_rio(): void {
        // São Paulo: -23.5505, -46.6333
        // Rio de Janeiro: -22.9068, -43.1729
        $distance = IpGeolocation::calculate_distance( -23.5505, -46.6333, -22.9068, -43.1729 );

        // Expected: ~357 km = 357,000 m (approx)
        $this->assertEqualsWithDelta( 357000, $distance, 5000 ); // ±5km tolerance
    }

    public function test_known_distance_new_york_to_london(): void {
        // New York: 40.7128, -74.0060
        // London: 51.5074, -0.1278
        $distance = IpGeolocation::calculate_distance( 40.7128, -74.0060, 51.5074, -0.1278 );

        // Expected: ~5,570 km
        $this->assertEqualsWithDelta( 5570000, $distance, 20000 ); // ±20km tolerance
    }

    public function test_antipodal_points_maximum_distance(): void {
        // North Pole to South Pole (approximation)
        $distance = IpGeolocation::calculate_distance( 90.0, 0.0, -90.0, 0.0 );

        // Half circumference of Earth: ~20,015 km
        $this->assertEqualsWithDelta( 20015000, $distance, 50000 ); // ±50km
    }

    public function test_short_distance_within_city(): void {
        // Two points ~1 km apart in São Paulo
        $distance = IpGeolocation::calculate_distance( -23.5505, -46.6333, -23.5505, -46.6220 );

        // ~1.1 km
        $this->assertGreaterThan( 500, $distance );
        $this->assertLessThan( 2000, $distance );
    }

    public function test_equatorial_distance(): void {
        // Two points on the equator, 1 degree apart
        $distance = IpGeolocation::calculate_distance( 0.0, 0.0, 0.0, 1.0 );

        // 1 degree on equator ≈ 111.32 km
        $this->assertEqualsWithDelta( 111320, $distance, 500 );
    }

    public function test_distance_is_symmetric(): void {
        $d1 = IpGeolocation::calculate_distance( -23.5505, -46.6333, -22.9068, -43.1729 );
        $d2 = IpGeolocation::calculate_distance( -22.9068, -43.1729, -23.5505, -46.6333 );

        $this->assertEqualsWithDelta( $d1, $d2, 0.001 );
    }

    // ==================================================================
    // is_within_areas() — Geospatial containment
    // ==================================================================

    public function test_point_inside_single_area(): void {
        $location = array( 'latitude' => -23.5505, 'longitude' => -46.6333 ); // São Paulo
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ) // 5km radius
        );

        $this->assertTrue( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    public function test_point_outside_single_area(): void {
        $location = array( 'latitude' => -22.9068, 'longitude' => -43.1729 ); // Rio
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ) // 5km in SP
        );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    public function test_or_logic_any_area_matches(): void {
        $location = array( 'latitude' => -22.9068, 'longitude' => -43.1729 ); // Rio
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ),  // SP (far)
            array( 'lat' => -22.91, 'lng' => -43.17, 'radius' => 5000 ),  // Rio (near)
        );

        // OR logic: at least one must match
        $this->assertTrue( IpGeolocation::is_within_areas( $location, $areas, 'or' ) );
    }

    public function test_or_logic_none_matches(): void {
        $location = array( 'latitude' => -15.78, 'longitude' => -47.93 ); // Brasília
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ), // SP
            array( 'lat' => -22.91, 'lng' => -43.17, 'radius' => 5000 ), // Rio
        );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas, 'or' ) );
    }

    public function test_and_logic_all_must_match(): void {
        $location = array( 'latitude' => -23.55, 'longitude' => -46.63 );
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 10000 ),  // Within
            array( 'lat' => -23.56, 'lng' => -46.64, 'radius' => 10000 ),  // Within
        );

        $this->assertTrue( IpGeolocation::is_within_areas( $location, $areas, 'and' ) );
    }

    public function test_and_logic_fails_if_one_misses(): void {
        $location = array( 'latitude' => -23.55, 'longitude' => -46.63 );
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 10000 ),  // Within
            array( 'lat' => -22.91, 'lng' => -43.17, 'radius' => 5000 ),   // Far (Rio)
        );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas, 'and' ) );
    }

    public function test_empty_areas_returns_false(): void {
        $location = array( 'latitude' => -23.55, 'longitude' => -46.63 );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, array() ) );
    }

    public function test_missing_latitude_returns_false(): void {
        $location = array( 'longitude' => -46.63 ); // No latitude
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ),
        );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    public function test_missing_longitude_returns_false(): void {
        $location = array( 'latitude' => -23.55 ); // No longitude
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 5000 ),
        );

        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    public function test_zero_coordinates_returns_false(): void {
        // latitude=0, longitude=0 is treated as "empty" by the method
        $location = array( 'latitude' => 0, 'longitude' => 0 );
        $areas = array(
            array( 'lat' => 0, 'lng' => 0, 'radius' => 100 ),
        );

        // empty() returns true for 0, so this should return false
        $this->assertFalse( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    public function test_point_exactly_on_radius_boundary(): void {
        // Create a point exactly at radius distance
        // 1 degree lat ≈ 111.32 km
        $location = array( 'latitude' => -23.55, 'longitude' => -46.63 );
        $areas = array(
            array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 0 ), // 0 meters radius
        );

        // Distance is 0, radius is 0 → 0 <= 0 = true
        $this->assertTrue( IpGeolocation::is_within_areas( $location, $areas ) );
    }

    // ==================================================================
    // get_location() — Integration flow
    // ==================================================================

    public function test_get_location_disabled_returns_error(): void {
        // Default get_option returns empty, so ip_api_enabled is empty
        $result = IpGeolocation::get_location( '8.8.8.8' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ip_api_disabled', $result->get_error_code() );
    }

    public function test_get_location_private_ip_returns_error(): void {
        Functions\when( 'FreeFormCertificate\Integrations\get_option' )->justReturn(
            array( 'ip_api_enabled' => '1', 'ip_api_service' => 'ip-api' )
        );

        $result = IpGeolocation::get_location( '192.168.1.1' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_ip', $result->get_error_code() );
    }

    public function test_get_location_localhost_returns_error(): void {
        Functions\when( 'FreeFormCertificate\Integrations\get_option' )->justReturn(
            array( 'ip_api_enabled' => '1' )
        );

        $result = IpGeolocation::get_location( '127.0.0.1' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_ip', $result->get_error_code() );
    }

    // ==================================================================
    // clear_cache() — Cache operations
    // ==================================================================

    public function test_clear_cache_specific_ip(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';

        $count = IpGeolocation::clear_cache( '8.8.8.8' );

        // delete_transient is stubbed, returns true → count = 1
        $this->assertSame( 1, $count );
    }

    public function test_clear_cache_all(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'DELETE QUERY' );
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 5 );

        $count = IpGeolocation::clear_cache( null );

        $this->assertSame( 5, $count );
    }
}
