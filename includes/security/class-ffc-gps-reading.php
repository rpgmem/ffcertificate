<?php
/**
 * GpsReading
 *
 * The server's decision on the GPS reading a visitor's browser sends with a
 * submission (#2040). Until then the GPS area was checked in the browser
 * only, so turning the script off or posting directly passed a GPS-only
 * form. The browser still checks first, to show its message before the
 * visitor fills the form; this class makes the server the one that decides.
 *
 * The reading is used for the decision and discarded: nothing here stores
 * or logs it.
 *
 * @package FreeFormCertificate\Security
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Integrations\IpGeolocation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and judges a submitted GPS reading.
 */
final class GpsReading {

	/** The "When GPS fails" cases, as the settings store them. */
	public const CASES = array( 'permission_denied', 'no_api', 'position_unavailable', 'timeout', 'safety_timer' );

	/** The decision reasons, as the activity log records them. */
	public const REASON_INSIDE     = 'gps_inside';
	public const REASON_OUTSIDE    = 'gps_server_outside';
	public const REASON_INACCURATE = 'gps_inaccurate';
	public const REASON_MISSING    = 'gps_missing';
	public const REASON_FAILED     = 'gps_failed';

	/** Seconds a reading may be older than the GPS cache lifetime, for slow networks. */
	private const AGE_SLACK = 60;

	/**
	 * The reading the browser posted, or the failure it reported.
	 *
	 * @return array{reading: array{lat: float, lng: float, accuracy: float, age: int}|null, status: string}
	 */
	public static function from_request(): array {
		$status = RequestInput::get_post_string( 'ffc_geo_status' );
		$fields = array(
			'lat'      => RequestInput::get_post_string( 'ffc_geo_lat' ),
			'lng'      => RequestInput::get_post_string( 'ffc_geo_lng' ),
			'accuracy' => RequestInput::get_post_string( 'ffc_geo_accuracy' ),
			'age'      => RequestInput::get_post_string( 'ffc_geo_age' ),
		);

		return array(
			'reading' => self::parse( $fields ),
			'status'  => in_array( $status, self::CASES, true ) ? $status : '',
		);
	}

	/**
	 * A reading made of the posted strings, or null when any is not a number
	 * in range.
	 *
	 * @param array<string, string> $fields lat, lng, accuracy (metres) and age (milliseconds).
	 * @return array{lat: float, lng: float, accuracy: float, age: int}|null
	 */
	public static function parse( array $fields ): ?array {
		foreach ( array( 'lat', 'lng', 'accuracy', 'age' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) || ! is_numeric( $fields[ $key ] ) ) {
				return null;
			}
		}

		$lat      = (float) $fields['lat'];
		$lng      = (float) $fields['lng'];
		$accuracy = (float) $fields['accuracy'];
		$age      = (int) $fields['age'];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $accuracy < 0 || $age < 0 ) {
			return null;
		}

		return array(
			'lat'      => $lat,
			'lng'      => $lng,
			'accuracy' => $accuracy,
			'age'      => $age,
		);
	}

	/**
	 * Whether a reading passes a form's GPS areas.
	 *
	 * A reading inside an area passes; outside fails. A reading whose error
	 * is larger than the smallest area, or older than the GPS cache lifetime,
	 * cannot tell, and goes to the "position unavailable" case of the
	 * "When GPS fails" policy. No reading goes to the case the browser
	 * reported, or to "no API" when it reported none, which is what a script
	 * that never ran or a forged request looks like.
	 *
	 * @param array<int, array{lat: float, lng: float, radius: float}>                                      $areas   The form's GPS areas.
	 * @param array{reading: array{lat: float, lng: float, accuracy: float, age: int}|null, status: string} $input What the browser sent.
	 * @param array<string, string>                                                                         $cases   The policy: case => allow|block.
	 * @param int                                                                                           $max_age GPS cache lifetime in seconds.
	 * @return array{pass: bool, reason: string}
	 */
	public static function decide( array $areas, array $input, array $cases, int $max_age ): array {
		if ( array() === $areas ) {
			return array(
				'pass'   => true,
				'reason' => self::REASON_INSIDE,
			);
		}

		$reading = $input['reading'];
		if ( null === $reading ) {
			$case = '' !== $input['status'] ? $input['status'] : 'no_api';
			return array(
				'pass'   => self::allows( $cases, $case ),
				'reason' => '' !== $input['status'] ? self::REASON_FAILED : self::REASON_MISSING,
			);
		}

		$smallest = min( array_map( static fn( array $area ): float => (float) $area['radius'], $areas ) );
		$stale    = $reading['age'] > ( $max_age + self::AGE_SLACK ) * 1000;
		if ( $reading['accuracy'] > $smallest || $stale ) {
			return array(
				'pass'   => self::allows( $cases, 'position_unavailable' ),
				'reason' => self::REASON_INACCURATE,
			);
		}

		$inside = IpGeolocation::is_within_areas(
			array(
				'latitude'  => $reading['lat'],
				'longitude' => $reading['lng'],
			),
			$areas
		);

		return array(
			'pass'   => $inside,
			'reason' => $inside ? self::REASON_INSIDE : self::REASON_OUTSIDE,
		);
	}

	/**
	 * Whether the policy lets a failure case through.
	 *
	 * @param array<string, string> $cases Case => allow|block.
	 * @param string                $failure One of {@see self::CASES}.
	 * @return bool
	 */
	private static function allows( array $cases, string $failure ): bool {
		return 'allow' === ( $cases[ $failure ] ?? 'block' );
	}
}
