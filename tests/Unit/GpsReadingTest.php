<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\Security\GpsReading;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The server's verdict on a submitted GPS reading (#2040).
 *
 * The distance check runs for real, so "inside" and "outside" are what the
 * area's radius says, not what a mock answers.
 *
 * @covers \FreeFormCertificate\Security\GpsReading
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GpsReadingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** One 200 m area and one 1 km area. */
	private const AREAS = array(
		array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 200.0 ),
		array( 'lat' => -22.9, 'lng' => -43.2, 'radius' => 1000.0 ),
	);

	/** Hybrid: user-driven failures pass, technical ones do not. */
	private const HYBRID = array(
		'permission_denied'    => 'allow',
		'no_api'               => 'allow',
		'position_unavailable' => 'block',
		'timeout'              => 'block',
		'safety_timer'         => 'block',
	);

	private const STRICT = array(
		'permission_denied'    => 'block',
		'no_api'               => 'block',
		'position_unavailable' => 'block',
		'timeout'              => 'block',
		'safety_timer'         => 'block',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Mockery::getConfiguration()->setConstantsMap(
			array( 'FreeFormCertificate\Core\Debug' => array( 'AREA_GEOFENCE' => 'debug_geofence' ) )
		);
		$debug = Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' );
		$debug->shouldReceive( 'log_geofence' );
		$debug->shouldReceive( 'is_enabled' )->andReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A reading of the given accuracy and age at a point.
	 *
	 * @return array{reading: array{lat: float, lng: float, accuracy: float, age: int}|null, status: string}
	 */
	private static function reading( float $lat, float $lng, float $accuracy = 10.0, int $age = 1000 ): array {
		return array(
			'reading' => array(
				'lat'      => $lat,
				'lng'      => $lng,
				'accuracy' => $accuracy,
				'age'      => $age,
			),
			'status'  => '',
		);
	}

	public function test_a_reading_inside_an_area_passes_and_outside_fails(): void {
		$this->assertSame(
			array( 'pass' => true, 'reason' => GpsReading::REASON_INSIDE ),
			GpsReading::decide( self::AREAS, self::reading( -23.5487, -46.63 ), self::STRICT, 600 )
		);
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_OUTSIDE ),
			GpsReading::decide( self::AREAS, self::reading( -23.0, -45.0 ), self::HYBRID, 600 )
		);
	}

	public function test_an_error_up_to_the_tolerance_times_the_radius_is_still_inside(): void {
		// 300 m of error in a 200 m area: 1.5 × the radius, the default.
		$this->assertSame(
			array( 'pass' => true, 'reason' => GpsReading::REASON_INSIDE ),
			GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 300.0 ), self::STRICT, 600 )
		);
		// One metre more cannot tell inside from outside.
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_INACCURATE ),
			GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 301.0 ), self::STRICT, 600 )
		);
		// The administrator's tolerance moves the line.
		$this->assertFalse( GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 201.0 ), self::STRICT, 600, 1.0 )['pass'] );
		$this->assertTrue( GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 600.0 ), self::STRICT, 600, 3.0 )['pass'] );
	}

	public function test_an_unsure_reading_follows_position_unavailable(): void {
		$imprecise = self::reading( -23.55, -46.63, 900.0 );

		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_INACCURATE ),
			GpsReading::decide( self::AREAS, $imprecise, self::HYBRID, 600 )
		);
		$this->assertSame(
			array( 'pass' => true, 'reason' => GpsReading::REASON_INACCURATE ),
			GpsReading::decide( self::AREAS, $imprecise, array( 'position_unavailable' => 'allow' ) + self::STRICT, 600 )
		);
	}

	public function test_a_point_outside_whose_error_reaches_the_area_is_unsure(): void {
		// About 300 m north of the 200 m area's centre, with 150 m of error:
		// the error circle reaches 50 m into the area.
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_INACCURATE ),
			GpsReading::decide( self::AREAS, self::reading( -23.5473, -46.63, 150.0 ), self::STRICT, 600 )
		);
		// With 50 m of error it falls short of the area: outside.
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_OUTSIDE ),
			GpsReading::decide( self::AREAS, self::reading( -23.5473, -46.63, 50.0 ), self::STRICT, 600 )
		);
	}

	public function test_an_imprecise_reading_far_from_every_area_is_outside_even_when_the_policy_allows_unsure_ones(): void {
		// Belo Horizonte with 2 km of error reaches neither area.
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_OUTSIDE ),
			GpsReading::decide( self::AREAS, self::reading( -19.92, -43.94, 2000.0 ), array( 'position_unavailable' => 'allow' ) + self::STRICT, 600 )
		);
	}

	public function test_each_area_is_judged_with_its_own_radius(): void {
		// A 50 m area in Rio and a 100 m one in Sao Paulo.
		$areas = array(
			array( 'lat' => -22.9, 'lng' => -43.2, 'radius' => 50.0 ),
			array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 100.0 ),
		);

		// 80 m of error in Sao Paulo: judged against its 100 m, not Rio's 50 m.
		$this->assertSame(
			array( 'pass' => true, 'reason' => GpsReading::REASON_INSIDE ),
			GpsReading::decide( $areas, self::reading( -23.55, -46.63, 80.0 ), self::STRICT, 600 )
		);
		// 70 m of error in Rio is inside Rio's area at 1.5 × 50 m, whichever order the areas come in.
		$this->assertTrue( GpsReading::decide( array_reverse( $areas ), self::reading( -22.9, -43.2, 70.0 ), self::STRICT, 600 )['pass'] );
	}

	public function test_clamp_weak_share_keeps_the_value_in_range(): void {
		$this->assertSame( 0.5, GpsReading::clamp_weak_share( null ) );
		$this->assertSame( 0.5, GpsReading::clamp_weak_share( 'half' ) );
		$this->assertSame( 0.1, GpsReading::clamp_weak_share( '0' ) );
		$this->assertSame( 1.0, GpsReading::clamp_weak_share( 4 ) );
		$this->assertSame( 0.7, GpsReading::clamp_weak_share( '0.66' ) );
	}

	public function test_clamp_tolerance_keeps_the_value_in_range(): void {
		$this->assertSame( 1.5, GpsReading::clamp_tolerance( null ) );
		$this->assertSame( 1.5, GpsReading::clamp_tolerance( 'lots' ) );
		$this->assertSame( 1.0, GpsReading::clamp_tolerance( '0.2' ) );
		$this->assertSame( 3.0, GpsReading::clamp_tolerance( 10 ) );
		$this->assertSame( 2.3, GpsReading::clamp_tolerance( '2.34' ) );
	}

	public function test_a_reading_older_than_the_cache_lifetime_follows_position_unavailable(): void {
		// The lifetime plus a minute of slack, in milliseconds.
		$this->assertTrue( GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 10.0, 660000 ), self::STRICT, 600 )['pass'] );
		$this->assertSame(
			array( 'pass' => false, 'reason' => GpsReading::REASON_INACCURATE ),
			GpsReading::decide( self::AREAS, self::reading( -23.55, -46.63, 10.0, 660001 ), self::STRICT, 600 )
		);
	}

	public function test_no_reading_follows_no_api_or_the_case_the_browser_reported(): void {
		$missing = array( 'reading' => null, 'status' => '' );
		$denied  = array( 'reading' => null, 'status' => 'permission_denied' );
		$timeout = array( 'reading' => null, 'status' => 'timeout' );

		$this->assertSame( array( 'pass' => true, 'reason' => GpsReading::REASON_MISSING ), GpsReading::decide( self::AREAS, $missing, self::HYBRID, 600 ) );
		$this->assertSame( array( 'pass' => false, 'reason' => GpsReading::REASON_MISSING ), GpsReading::decide( self::AREAS, $missing, self::STRICT, 600 ) );
		$this->assertSame( array( 'pass' => true, 'reason' => GpsReading::REASON_FAILED ), GpsReading::decide( self::AREAS, $denied, self::HYBRID, 600 ) );
		$this->assertSame( array( 'pass' => false, 'reason' => GpsReading::REASON_FAILED ), GpsReading::decide( self::AREAS, $timeout, self::HYBRID, 600 ) );
		// A case the policy does not name is a block.
		$this->assertFalse( GpsReading::decide( self::AREAS, $denied, array(), 600 )['pass'] );
	}

	public function test_a_form_without_areas_has_nothing_to_check(): void {
		$this->assertTrue( GpsReading::decide( array(), array( 'reading' => null, 'status' => '' ), self::STRICT, 600 )['pass'] );
	}

	public function test_parse_takes_numbers_in_range_only(): void {
		$this->assertSame(
			array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 12.5, 'age' => 300 ),
			GpsReading::parse( array( 'lat' => '-23.5', 'lng' => '-46.6', 'accuracy' => '12.5', 'age' => '300' ) )
		);
		foreach (
			array(
				array( 'lat' => '', 'lng' => '-46.6', 'accuracy' => '1', 'age' => '1' ),
				array( 'lat' => 'x', 'lng' => '-46.6', 'accuracy' => '1', 'age' => '1' ),
				array( 'lat' => '91', 'lng' => '-46.6', 'accuracy' => '1', 'age' => '1' ),
				array( 'lat' => '-23.5', 'lng' => '181', 'accuracy' => '1', 'age' => '1' ),
				array( 'lat' => '-23.5', 'lng' => '-46.6', 'accuracy' => '-1', 'age' => '1' ),
				array( 'lat' => '-23.5', 'lng' => '-46.6', 'accuracy' => '1', 'age' => '-1' ),
				array( 'lat' => '-23.5', 'lng' => '-46.6', 'accuracy' => '1' ),
			) as $fields
		) {
			$this->assertNull( GpsReading::parse( $fields ), (string) json_encode( $fields ) );
		}
	}

	public function test_from_request_reads_the_posted_fields_and_drops_an_unknown_status(): void {
		$post = array(
			'ffc_geo_lat'      => '-23.5',
			'ffc_geo_lng'      => '-46.6',
			'ffc_geo_accuracy' => '8',
			'ffc_geo_age'      => '20',
			'ffc_geo_status'   => 'made_up',
		);
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )
			->andReturnUsing( static fn( string $key, string $default = '' ): string => $post[ $key ] ?? $default );

		$this->assertSame(
			array(
				'reading' => array( 'lat' => -23.5, 'lng' => -46.6, 'accuracy' => 8.0, 'age' => 20 ),
				'status'  => '',
			),
			GpsReading::from_request()
		);
	}

	public function test_from_request_keeps_a_known_status_without_a_reading(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )
			->andReturnUsing( static fn( string $key, string $default = '' ): string => 'ffc_geo_status' === $key ? 'timeout' : $default );

		$this->assertSame( array( 'reading' => null, 'status' => 'timeout' ), GpsReading::from_request() );
	}
}
