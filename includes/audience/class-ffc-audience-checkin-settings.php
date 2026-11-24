<?php
/**
 * Audience Check-in Settings
 *
 * The settings on Scheduling → Settings → Check-in (#2747, #2748).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The check-in settings, read through {@see SettingsReader} from
 * `ffc_settings` and clamped on read as well as on save, so a value written
 * before a bound moved still lands somewhere the code can use. The defaults
 * are declared in `Settings::get_default_settings()`.
 */
final class AudienceCheckinSettings {

	/**
	 * Active terminals per site, by default.
	 */
	public const SITE_LIMIT = 10;

	/**
	 * The most the site limit may be set to.
	 */
	public const SITE_LIMIT_MAX = 100;

	/**
	 * Active terminals per event, by default.
	 */
	public const EVENT_LIMIT = 3;

	/**
	 * The most the per-event limit may be set to.
	 */
	public const EVENT_LIMIT_MAX = 20;

	/**
	 * Hours a terminal outlives the last event it holds, by default.
	 */
	public const AUTO_END_HOURS = 2;

	/**
	 * The most the auto-end may be set to, in hours.
	 */
	public const AUTO_END_HOURS_MAX = 24;

	/**
	 * How many terminals may be active on the site at once.
	 *
	 * @return int
	 */
	public static function site_limit(): int {
		return self::bounded( 'terminal_site_limit', self::SITE_LIMIT, self::SITE_LIMIT_MAX );
	}

	/**
	 * How many terminals may hold one event at once.
	 *
	 * @return int
	 */
	public static function event_limit(): int {
		return self::bounded( 'terminal_event_limit', self::EVENT_LIMIT, self::EVENT_LIMIT_MAX );
	}

	/**
	 * How long a terminal outlives the last event it holds, in seconds.
	 *
	 * @return int
	 */
	public static function auto_end_seconds(): int {
		return self::bounded( 'terminal_auto_end_hours', self::AUTO_END_HOURS, self::AUTO_END_HOURS_MAX ) * HOUR_IN_SECONDS;
	}

	/**
	 * The same, in hours: what a pairing screen marks first.
	 *
	 * @return int
	 */
	public static function auto_end_hours(): int {
		return max( 1, intdiv( self::auto_end_seconds(), HOUR_IN_SECONDS ) );
	}

	/**
	 * The hours a pairing screen offers for ending by itself (#2781): 1, 2
	 * and 4, plus the settings' value when it is none of them, so what is
	 * marked first is always one of the choices.
	 *
	 * @param int $default_hours The settings' value.
	 * @return array<int, string> Hours => label.
	 */
	public static function auto_end_choices( int $default_hours ): array {
		$hours = array_values( array_unique( array( 1, 2, 4, $default_hours ) ) );
		sort( $hours );
		$options = array();
		foreach ( $hours as $h ) {
			/* translators: %d: hours */
			$options[ $h ] = sprintf( __( '%d h', 'ffcertificate' ), $h );
		}
		return $options;
	}

	/**
	 * Whether a terminal may read codes with its own camera (#2747). Off
	 * unless an administrator turned it on: the wedge reader is the
	 * recommended mode.
	 *
	 * @return bool
	 */
	public static function camera_allowed(): bool {
		return (bool) (int) SettingsReader::get( 'terminal_camera', 0 );
	}

	/**
	 * Whether a new event takes check-in by the projected QR code (#2748).
	 * An event keeps its own switch from then on.
	 *
	 * @return bool
	 */
	public static function default_path_qr(): bool {
		return (bool) (int) SettingsReader::get( 'checkin_path_qr', 1 );
	}

	/**
	 * Whether a new event takes check-in by a terminal (#2748). An event
	 * keeps its own switch from then on.
	 *
	 * @return bool
	 */
	public static function default_path_terminal(): bool {
		return (bool) (int) SettingsReader::get( 'checkin_path_terminal', 1 );
	}

	/**
	 * A whole number between 1 and a maximum, or its default when the stored
	 * value is missing or not a number.
	 *
	 * @param string $key     Setting key.
	 * @param int    $default Default.
	 * @param int    $max     Upper bound.
	 * @return int
	 */
	private static function bounded( string $key, int $default, int $max ): int {
		$value = SettingsReader::get( $key, $default );
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return max( 1, min( $max, (int) $value ) );
	}
}
