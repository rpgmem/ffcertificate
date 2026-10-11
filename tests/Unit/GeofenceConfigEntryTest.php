<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for the config-array entry points of Geofence (#1668):
 * normalize_config(), check_access() and build_frontend_config().
 *
 * They are what the form adapters (can_access_form, get_form_config,
 * get_frontend_config) now delegate to, and what content that is not a
 * form calls directly. So each test also pins that a config handed in
 * directly needs no form: get_post_meta is never read.
 *
 * @covers \FreeFormCertificate\Security\Geofence
 */
class GeofenceConfigEntryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A config whose date window ended in 2020, so the date check fails.
	 *
	 * @var array<string, mixed>
	 */
	private const EXPIRED = array(
		'datetime_enabled' => '1',
		'geo_enabled'      => '0',
		'date_start'       => '2020-01-01',
		'date_end'         => '2020-01-02',
		'time_mode'        => 'daily',
		'msg_datetime'     => 'Closed.',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_args' )->alias( 'array_merge' );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias(
			static function ( $v ) {
				return abs( (int) $v );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format ) {
				return 'Y-m-d' === $format ? '2026-02-08' : '12:00';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_normalize_config_types_the_switches_and_keeps_the_rest(): void {
		$config = Geofence::normalize_config(
			array(
				'datetime_enabled' => '1',
				'geo_enabled'      => '',
				'geo_gps_enabled'  => 'on',
				'date_start'       => '2026-01-01',
			)
		);

		$this->assertTrue( $config['datetime_enabled'] );
		$this->assertFalse( $config['geo_enabled'] );
		$this->assertTrue( $config['geo_gps_enabled'] );
		$this->assertFalse( $config['geo_ip_enabled'], 'An absent switch is off.' );
		$this->assertFalse( $config['geo_ip_areas_permissive'], 'An absent switch is off.' );
		$this->assertSame( '2026-01-01', $config['date_start'] );
	}

	public function test_check_access_denies_an_expired_window_without_reading_a_form(): void {
		Functions\expect( 'get_post_meta' )->never();

		$result = Geofence::check_access(
			Geofence::normalize_config( self::EXPIRED ),
			array(),
			array( 'content' => 'handle' )
		);

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'datetime_invalid', $result['reason'] );
		$this->assertSame( 'Closed.', $result['message'] );
	}

	public function test_check_access_allows_inside_the_window(): void {
		Functions\expect( 'get_post_meta' )->never();

		$config               = self::EXPIRED;
		$config['date_start'] = '2026-01-01';
		$config['date_end']   = '2026-12-31';

		$result = Geofence::check_access( Geofence::normalize_config( $config ) );

		$this->assertTrue( $result['allowed'] );
		$this->assertSame( 'validated', $result['reason'] );
	}

	public function test_check_access_skips_a_disabled_date_check(): void {
		$config                     = self::EXPIRED;
		$config['datetime_enabled'] = '';

		$result = Geofence::check_access( Geofence::normalize_config( $config ) );

		$this->assertTrue( $result['allowed'], 'An expired window does not block while its switch is off.' );
	}

	public function test_check_access_honours_the_admin_bypass(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = array() ) {
				return 'ffc_geolocation_settings' === $key ? array( 'admin_bypass_datetime' => true ) : $default;
			}
		);

		$result = Geofence::check_access( Geofence::normalize_config( self::EXPIRED ) );

		$this->assertTrue( $result['allowed'] );
	}

	public function test_can_access_form_returns_what_check_access_returns_for_its_meta(): void {
		Functions\when( 'get_post_meta' )->justReturn( self::EXPIRED );

		$this->assertSame(
			Geofence::check_access( Geofence::normalize_config( self::EXPIRED ) ),
			Geofence::can_access_form( 7 )
		);
	}

	public function test_build_frontend_config_leads_with_the_identity_it_is_given(): void {
		Functions\expect( 'get_post_meta' )->never();

		$result = Geofence::build_frontend_config( self::EXPIRED, array( 'contentId' => 'abc' ) );

		$this->assertSame( 'contentId', array_key_first( $result ) );
		$this->assertSame( 'abc', $result['contentId'] );
		$this->assertArrayNotHasKey( 'formId', $result );
		$this->assertTrue( $result['datetime']['enabled'], 'A raw config is normalized on the way in.' );
		$this->assertSame( '2020-01-02', $result['datetime']['dateEnd'] );
	}

	public function test_build_frontend_config_keeps_the_identity_under_full_admin_bypass(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = array() ) {
				return 'ffc_geolocation_settings' === $key
					? array(
						'admin_bypass_datetime' => true,
						'admin_bypass_geo'      => true,
					)
					: $default;
			}
		);

		$result = Geofence::build_frontend_config( self::EXPIRED, array( 'contentId' => 'abc' ) );

		$this->assertSame( 'contentId', array_key_first( $result ) );
		$this->assertTrue( $result['adminBypass'] );
		$this->assertFalse( $result['datetime']['enabled'] );
	}

	public function test_get_frontend_config_is_build_frontend_config_with_the_form_id(): void {
		Functions\when( 'get_post_meta' )->justReturn( self::EXPIRED );

		$this->assertSame(
			Geofence::build_frontend_config( self::EXPIRED, array( 'formId' => 9 ) ),
			Geofence::get_frontend_config( 9 )
		);
	}
}
