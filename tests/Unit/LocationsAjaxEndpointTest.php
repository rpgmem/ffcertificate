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
 * GeofenceLocationRegistry is the real class, over an option the test
 * holds: what the endpoint saved or deleted is read off that option (#2121).
 *
 * @covers \FreeFormCertificate\Admin\LocationsAjaxEndpoint
 */
class LocationsAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The stored `ffc_geofence_locations` option.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $stored = array();

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

		$this->stored = array();
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => 'ffc_geofence_locations' === $key ? $this->stored : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				if ( 'ffc_geofence_locations' === $key ) {
					$this->stored = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'new' );
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
		$_POST = array( 'name' => 'X', 'id' => 'loc_bogus', 'lat' => 0, 'lng' => 0, 'radius' => 100 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Location not found' );
		LocationsAjaxEndpoint::handle_save();
	}

	// ==================================================================
	// handle_save() — happy paths
	// ==================================================================

	public function test_save_creates_new_when_id_is_missing(): void {
		$_POST = array( 'name' => 'X', 'lat' => 1.0, 'lng' => 2.0, 'radius' => 100, 'default_gps' => '0', 'default_ip' => '0' );

		try {
			LocationsAjaxEndpoint::handle_save();
		} catch ( \RuntimeException $e ) {
			// wp_send_json_success throws as expected.
		}

		// No id was posted, so the registry minted one.
		$this->assertCount( 1, $this->stored );
		$this->assertSame( 'loc_new', $this->stored[0]['id'] );
		$this->assertSame( 'X', $this->stored[0]['name'] );
		$this->assertSame( 1.0, $this->stored[0]['lat'] );
	}

	public function test_save_updates_existing_when_id_matches(): void {
		$this->stored = array( array( 'id' => 'loc_existing', 'name' => 'old', 'lat' => 0.0, 'lng' => 0.0, 'radius' => 100.0 ) );

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

		$this->assertCount( 1, $this->stored, 'the row was updated, not added' );
		$this->assertSame( 'loc_existing', $this->stored[0]['id'] );
		$this->assertSame( 'New name', $this->stored[0]['name'] );
		$this->assertTrue( $this->stored[0]['default_gps'] );
		$this->assertFalse( $this->stored[0]['default_ip'] );
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
		$this->stored = array( array( 'id' => 'loc_other', 'name' => 'Other' ) );
		$_POST = array( 'id' => 'loc_missing' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Location not found' );
		LocationsAjaxEndpoint::handle_delete();
	}

	public function test_delete_succeeds_when_registry_reports_removed(): void {
		$this->stored = array(
			array( 'id' => 'loc_existing', 'name' => 'Gone' ),
			array( 'id' => 'loc_other', 'name' => 'Kept' ),
		);
		$_POST = array( 'id' => 'loc_existing' );

		try {
			LocationsAjaxEndpoint::handle_delete();
			$this->fail( 'expected a JSON response' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
		}
		$this->assertSame( array( array( 'id' => 'loc_other', 'name' => 'Kept' ) ), $this->stored );
	}
}
