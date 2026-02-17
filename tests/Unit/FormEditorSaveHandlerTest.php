<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorSaveHandler;

/**
 * Tests for FormEditorSaveHandler: geofence validation logic.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 */
class FormEditorSaveHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormEditorSaveHandler */
    private $handler;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $this->handler = new FormEditorSaveHandler();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on FormEditorSaveHandler.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( FormEditorSaveHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->handler, $args );
    }

    // ==================================================================
    // validate_geofence_config()
    // ==================================================================

    public function test_geofence_valid_gps_config_returns_no_errors(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => "-23.5505,-46.6333,500",
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_gps_enabled_no_areas_returns_error(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'GPS', $errors[0] );
    }

    public function test_geofence_ip_permissive_no_areas_returns_error(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '1',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }

    public function test_geofence_both_disabled_returns_no_errors(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_ip_non_permissive_empty_areas_no_error(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_gps_invalid_areas_propagates_format_errors(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => "invalid_format",
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'Invalid format', $errors[0] );
    }

    public function test_geofence_both_gps_and_ip_errors_combined(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '1',
            'geo_areas' => "invalid",
            'geo_ip_areas' => "also_invalid",
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertGreaterThanOrEqual( 2, count( $errors ) );
    }

    // ==================================================================
    // validate_areas_format()
    // ==================================================================

    public function test_areas_valid_single_line(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-23.5505,-46.6333,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_valid_multiple_lines(): void {
        $areas = "-23.5505,-46.6333,500\n-22.9068,-43.1729,1000";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_valid_with_spaces_around_commas(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-23.5505 , -46.6333 , 500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_invalid_format_returns_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "not_valid", 'GPS' ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'Invalid format', $errors[0] );
        $this->assertStringContainsString( 'GPS', $errors[0] );
    }

    public function test_areas_latitude_out_of_range_high(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "91.0,-46.6,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'latitude', $errors[0] );
    }

    public function test_areas_latitude_out_of_range_low(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-91.0,-46.6,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'latitude', $errors[0] );
    }

    public function test_areas_longitude_out_of_range_high(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,181.0,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'longitude', $errors[0] );
    }

    public function test_areas_longitude_out_of_range_low(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-181.0,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'longitude', $errors[0] );
    }

    public function test_areas_zero_radius_returns_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-46.6,0", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'Radius', $errors[0] );
    }

    public function test_areas_edge_latitude_90_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "90.0,-46.6,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_latitude_minus90_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-90.0,-46.6,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_longitude_180_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,180.0,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_longitude_minus180_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-180.0,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_mixed_valid_and_invalid_lines(): void {
        $areas = "-23.5,-46.6,500\ninvalid\n91.0,-46.6,100";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'IP' ) );
        // Line 2 has invalid format, line 3 has invalid latitude
        $this->assertCount( 2, $errors );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }

    public function test_areas_empty_lines_skipped(): void {
        $areas = "-23.5,-46.6,500\n\n-22.9,-43.1,1000";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_integer_coordinates_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23,-46,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_type_label_appears_in_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "bad", 'IP' ) );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }
}
