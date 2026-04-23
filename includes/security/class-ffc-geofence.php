<?php
/**
 * Geofence
 *
 * Main geofence validation class.
 * Handles date/time and geolocation restrictions for forms.
 *
 * @package FreeFormCertificate
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since   3.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geofence validation for date/time and geolocation restrictions.
 *
 * @since 3.0.0
 */
class Geofence {

	/**
	 * Check if user can access form (complete validation)
	 *
	 * @param int                  $form_id Form ID.
	 * @param array<string, mixed> $options Validation options.
	 * @return array{allowed: bool, reason?: string, message?: string}
	 */
	public static function can_access_form( int $form_id, array $options = array() ): array {
		$defaults = array(
			'check_datetime' => true,
			'check_geo'      => false, // GPS validation is frontend-only by default.
			'user_location'  => null, // For manual location override (testing).
		);

		$options = wp_parse_args( $options, $defaults );

		// Get form geofence config.
		$config = self::get_form_config( $form_id );

		if ( empty( $config ) ) {
			// No restrictions configured.
			return array(
				'allowed' => true,
				'reason'  => 'no_restrictions',
				'message' => '',
			);
		}

		// Check admin bypass settings (require manage_options capability).
		$bypass_datetime = self::should_bypass_datetime();
		$bypass_geo      = self::should_bypass_geo();

		// PRIORITY 1: Date/Time Validation (skip if admin bypass enabled).
		if ( $options['check_datetime'] && $config['datetime_enabled'] && ! $bypass_datetime ) {
			$datetime_check = self::validate_datetime( $config );

			if ( ! $datetime_check['valid'] ) {
				self::log_access_denied( $form_id, 'datetime_invalid', $datetime_check );

				return array(
					'allowed' => false,
					'reason'  => 'datetime_invalid',
					'message' => $datetime_check['message'],
				);
			}
		}

		// PRIORITY 2: Geolocation Validation (skip if admin bypass enabled).
		if ( $options['check_geo'] && $config['geo_enabled'] && ! $bypass_geo ) {
			$geo_check = self::validate_geolocation( $config, $options['user_location'] );

			if ( ! $geo_check['valid'] ) {
				self::log_access_denied( $form_id, 'geolocation_invalid', $geo_check );

				return array(
					'allowed' => false,
					'reason'  => 'geolocation_invalid',
					'message' => $geo_check['message'],
				);
			}
		}

		// All checks passed.
		return array(
			'allowed' => true,
			'reason'  => 'validated',
			'message' => '',
		);
	}

	/**
	 * Validate date/time restrictions
	 *
	 * @param array<string, mixed> $config Form geofence configuration.
	 * @return array<string, mixed>
	 */
	public static function validate_datetime( array $config ): array {
		$now          = time();
		$current_date = wp_date( 'Y-m-d', $now );
		$current_time = wp_date( 'H:i', $now );
		$time_mode    = $config['time_mode'] ?? 'daily';

		// Determine if time validation is needed.
		$has_time_range  = ! empty( $config['time_start'] ) && ! empty( $config['time_end'] );
		$has_date_range  = ! empty( $config['date_start'] ) && ! empty( $config['date_end'] );
		$different_dates = $has_date_range && $config['date_start'] !== $config['date_end'];

		// MODE 1: Time spans across dates (start datetime → end datetime).
		if ( 'span' === $time_mode && $has_date_range && $has_time_range && $different_dates ) {
			$tz             = wp_timezone();
			$start_datetime = ( new \DateTimeImmutable( $config['date_start'] . ' ' . $config['time_start'], $tz ) )->getTimestamp();
			$end_datetime   = ( new \DateTimeImmutable( $config['date_end'] . ' ' . $config['time_end'], $tz ) )->getTimestamp();

			if ( $now < $start_datetime ) {
				return array(
					'valid'   => false,
					'message' => $config['msg_datetime'] ?? __( 'This form is not yet available.', 'ffcertificate' ),
					'details' => array(
						'reason' => 'before_start_datetime',
						'mode'   => 'span',
						'now'    => $now,
						'start'  => $start_datetime,
					),
				);
			}

			if ( $now > $end_datetime ) {
				return array(
					'valid'   => false,
					'message' => $config['msg_datetime'] ?? __( 'This form is no longer available.', 'ffcertificate' ),
					'details' => array(
						'reason' => 'after_end_datetime',
						'mode'   => 'span',
						'now'    => $now,
						'end'    => $end_datetime,
					),
				);
			}

			// Within the datetime span - allow access.
			return array(
				'valid'   => true,
				'message' => '',
				'details' => array(),
			);
		}

		// MODE 2: Daily time range (default behavior)
		// Check date range first.
		if ( ! empty( $config['date_start'] ) && $current_date < $config['date_start'] ) {
			return array(
				'valid'   => false,
				'message' => $config['msg_datetime'] ?? __( 'This form is not yet available.', 'ffcertificate' ),
				'details' => array(
					'reason'       => 'before_start_date',
					'current_date' => $current_date,
					'start_date'   => $config['date_start'],
				),
			);
		}

		if ( ! empty( $config['date_end'] ) && $current_date > $config['date_end'] ) {
			return array(
				'valid'   => false,
				'message' => $config['msg_datetime'] ?? __( 'This form is no longer available.', 'ffcertificate' ),
				'details' => array(
					'reason'       => 'after_end_date',
					'current_date' => $current_date,
					'end_date'     => $config['date_end'],
				),
			);
		}

		// Then check daily time range (if within date range).
		if ( $has_time_range ) {
			// Default to 00:00 - 23:59 if empty.
			$time_start = ! empty( $config['time_start'] ) ? $config['time_start'] : '00:00';
			$time_end   = ! empty( $config['time_end'] ) ? $config['time_end'] : '23:59';

			if ( $current_time < $time_start || $current_time > $time_end ) {
				return array(
					'valid'   => false,
					'message' => $config['msg_datetime'] ?? __( 'This form is only available during specific hours.', 'ffcertificate' ),
					'details' => array(
						'reason'       => 'outside_time_range',
						'mode'         => 'daily',
						'current_time' => $current_time,
						'time_start'   => $time_start,
						'time_end'     => $time_end,
					),
				);
			}
		}

		return array(
			'valid'   => true,
			'message' => '',
			'details' => array(),
		);
	}

	/**
	 * Validate geolocation restrictions (IP-based backend validation)
	 *
	 * @param array<string, mixed>      $config Form geofence configuration.
	 * @param array<string, mixed>|null $user_location Manual location override.
	 * @return array<string, mixed>
	 */
	public static function validate_geolocation( array $config, ?array $user_location = null ): array {
		// Parse areas.
		$areas = self::parse_areas( self::resolve_areas_text( $config, 'geo_area_source', 'geo_area_location_ids', 'geo_areas' ) );

		if ( empty( $areas ) ) {
			return array(
				'valid'   => true, // No areas defined = no restriction.
				'message' => '',
				'details' => array( 'reason' => 'no_areas_defined' ),
			);
		}

		// Get user location (IP-based or provided).
		if ( null === $user_location && ! empty( $config['geo_ip_enabled'] ) ) {
			$user_location = \FreeFormCertificate\Integrations\IpGeolocation::get_location();

			if ( is_wp_error( $user_location ) ) {
				// IP API failed - apply fallback.
				return self::handle_ip_fallback( $config, $user_location );
			}
		}

		if ( empty( $user_location ) || empty( $user_location['latitude'] ) || empty( $user_location['longitude'] ) ) {
			return array(
				'valid'   => false,
				'message' => $config['msg_geo_error'] ?? __( 'Unable to determine your location.', 'ffcertificate' ),
				'details' => array( 'reason' => 'location_unavailable' ),
			);
		}

		// Determine areas to check (IP areas or GPS areas).
		$check_areas = $areas; // Default: same areas.

		if ( ! empty( $config['geo_ip_enabled'] ) && ! empty( $config['geo_ip_areas_permissive'] ) ) {
			// Use more permissive IP-specific areas if configured.
			$ip_areas = self::parse_areas( self::resolve_areas_text( $config, 'geo_ip_area_source', 'geo_ip_area_location_ids', 'geo_ip_areas' ) );
			if ( ! empty( $ip_areas ) ) {
				$check_areas = $ip_areas;
			}
		}

		// Check if within allowed areas.
		$within = \FreeFormCertificate\Integrations\IpGeolocation::is_within_areas( $user_location, $check_areas, 'or' ); // Always OR logic for multiple areas.

		if ( ! $within ) {
			return array(
				'valid'   => false,
				'message' => $config['msg_geo_blocked'] ?? __( 'This form is not available in your location.', 'ffcertificate' ),
				'details' => array(
					'reason'        => 'outside_allowed_areas',
					'user_location' => $user_location,
					'areas_count'   => count( $check_areas ),
				),
			);
		}

		return array(
			'valid'   => true,
			'message' => '',
			'details' => array( 'user_location' => $user_location ),
		);
	}

	/**
	 * Handle IP geolocation fallback when API fails
	 *
	 * @param array<string, mixed> $config Form configuration.
	 * @param \WP_Error            $error Error from IP API.
	 * @return array<string, mixed>
	 */
	private static function handle_ip_fallback( array $config, $error ): array {
		$global_settings = get_option( 'ffc_geolocation_settings', array() );
		$fallback        = $global_settings['api_fallback'] ?? 'gps_only';

		// Use centralized debug system.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_geofence(
				'IP API failed, applying fallback',
				array(
					'error'    => $error->get_error_message(),
					'fallback' => $fallback,
				)
			);
		}

		switch ( $fallback ) {
			case 'allow':
				return array(
					'valid'   => true,
					'message' => '',
					'details' => array(
						'reason' => 'ip_fallback_allow',
						'error'  => $error->get_error_message(),
					),
				);

			case 'block':
				return array(
					'valid'   => false,
					'message' => $config['msg_geo_error'] ?? __( 'Location verification failed.', 'ffcertificate' ),
					'details' => array(
						'reason' => 'ip_fallback_block',
						'error'  => $error->get_error_message(),
					),
				);

			case 'gps_only':
			default:
				// GPS validation happens on frontend, so we can't validate here.
				return array(
					'valid'   => true, // Allow through, GPS will validate on frontend.
					'message' => '',
					'details' => array(
						'reason' => 'ip_fallback_gps',
						'note'   => 'GPS validation on frontend',
					),
				);
		}
	}

	/**
	 * Compute the absolute end timestamp for a form based on its geofence config.
	 *
	 * Uses `_ffc_geofence_config['date_end']` combined with `time_end` when present
	 * (respecting `wp_timezone()`). Returns null when no `date_end` is configured,
	 * signalling that the caller cannot make an "is expired" decision.
	 *
	 * Unlike `validate_datetime()`, this helper ignores the `datetime_enabled` flag
	 * — it is intended for features (like public CSV download) that need to know
	 * when a form's collection window ends regardless of whether geofence is active.
	 *
	 * @param int $form_id Form post ID.
	 * @return int|null Unix timestamp of the end moment, or null if `date_end` is missing.
	 */
	public static function get_form_end_timestamp( int $form_id ): ?int {
		$config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( empty( $config ) || ! is_array( $config ) ) {
			return null;
		}

		$date_end = isset( $config['date_end'] ) ? trim( (string) $config['date_end'] ) : '';
		if ( '' === $date_end ) {
			return null;
		}

		$time_end = isset( $config['time_end'] ) ? trim( (string) $config['time_end'] ) : '';
		if ( '' === $time_end ) {
			$time_end = '23:59:59';
		}

		try {
			$dt = new \DateTimeImmutable( $date_end . ' ' . $time_end, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}

		return $dt->getTimestamp();
	}

	/**
	 * Return the absolute start timestamp for a form, regardless of geofence state.
	 *
	 * Mirrors {@see get_form_end_timestamp()} but for the start boundary.
	 *
	 * @param int $form_id Form post ID.
	 * @return int|null Unix timestamp of the start moment, or null if `date_start` is missing.
	 */
	public static function get_form_start_timestamp( int $form_id ): ?int {
		$config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( empty( $config ) || ! is_array( $config ) ) {
			return null;
		}

		$date_start = isset( $config['date_start'] ) ? trim( (string) $config['date_start'] ) : '';
		if ( '' === $date_start ) {
			return null;
		}

		$time_start = isset( $config['time_start'] ) ? trim( (string) $config['time_start'] ) : '';
		if ( '' === $time_start ) {
			$time_start = '00:00:00';
		}

		try {
			$dt = new \DateTimeImmutable( $date_start . ' ' . $time_start, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}

		return $dt->getTimestamp();
	}

	/**
	 * Check whether a form has already ended.
	 *
	 * Returns true only when `get_form_end_timestamp()` returns a valid
	 * timestamp AND the current time is strictly after that timestamp.
	 *
	 * @param int $form_id Form post ID.
	 * @return bool
	 */
	public static function has_form_expired( int $form_id ): bool {
		$end = self::get_form_end_timestamp( $form_id );
		if ( null === $end ) {
			return false;
		}
		return time() > $end;
	}

	/**
	 * Check whether a form ended more than `$days` ago.
	 *
	 * Used by the obsolete shortcode cleanup feature to decide which forms
	 * qualify for sweeping embedded `[ffc_form]` shortcodes off published
	 * posts/pages.
	 *
	 * Returns false for forms that don't have a `date_end` configured or
	 * whose end timestamp is still in the future / within the grace window.
	 *
	 * @param int $form_id Form post ID.
	 * @param int $days    Grace window in days. Must be >= 0.
	 * @return bool
	 */
	public static function has_form_expired_by_days( int $form_id, int $days ): bool {
		$end = self::get_form_end_timestamp( $form_id );
		if ( null === $end ) {
			return false;
		}
		if ( $days < 0 ) {
			$days = 0;
		}
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		return $end < $cutoff;
	}

	/**
	 * Get form geofence configuration
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>|null Configuration array or null if none
	 */
	public static function get_form_config( int $form_id ) {
		$config = get_post_meta( $form_id, '_ffc_geofence_config', true );

		if ( empty( $config ) || ! is_array( $config ) ) {
			return null;
		}

		// Ensure boolean fields are properly typed.
		$config['datetime_enabled']        = ! empty( $config['datetime_enabled'] );
		$config['geo_enabled']             = ! empty( $config['geo_enabled'] );
		$config['geo_gps_enabled']         = ! empty( $config['geo_gps_enabled'] );
		$config['geo_ip_enabled']          = ! empty( $config['geo_ip_enabled'] );
		$config['geo_ip_areas_permissive'] = ! empty( $config['geo_ip_areas_permissive'] );

		return $config;
	}

	/**
	 * Parse areas from textarea format
	 *
	 * Format: "lat, lng, radius" (one per line)
	 *
	 * @param string $areas_text Raw textarea content.
	 * @return array<int, array<string, float>> Array of areas with 'lat', 'lng', 'radius'
	 */
	public static function parse_areas( string $areas_text ): array {
		if ( empty( $areas_text ) ) {
			return array();
		}

		$lines = explode( "\n", $areas_text );
		$areas = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$parts = array_map( 'trim', explode( ',', $line ) );

			if ( count( $parts ) !== 3 ) {
				continue; // Invalid format.
			}

			$lat    = floatval( $parts[0] );
			$lng    = floatval( $parts[1] );
			$radius = floatval( $parts[2] );

			// Validate coordinates.
			if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || $radius <= 0 ) {
				continue;
			}

			$areas[] = array(
				'lat'    => $lat,
				'lng'    => $lng,
				'radius' => $radius,
			);
		}

		return $areas;
	}

	/**
	 * Check if admin should bypass datetime restrictions
	 *
	 * @return bool True if current user is admin and bypass is enabled
	 */
	public static function should_bypass_datetime(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$settings = get_option( 'ffc_geolocation_settings', array() );
		return ! empty( $settings['admin_bypass_datetime'] );
	}

	/**
	 * Check if admin should bypass geolocation restrictions
	 *
	 * @return bool True if current user is admin and bypass is enabled
	 */
	public static function should_bypass_geo(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$settings = get_option( 'ffc_geolocation_settings', array() );
		return ! empty( $settings['admin_bypass_geo'] );
	}

	/**
	 * Get frontend configuration for form (JavaScript)
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>|null Configuration for frontend or null if no restrictions
	 */
	public static function get_frontend_config( int $form_id ) {
		$config = self::get_form_config( $form_id );

		if ( empty( $config ) ) {
			return null;
		}

		// Check admin bypass for each restriction type.
		$bypass_datetime = self::should_bypass_datetime();
		$bypass_geo      = self::should_bypass_geo();

		// If both restrictions are bypassed, return special config.
		if ( $bypass_datetime && $bypass_geo ) {
			return array(
				'formId'      => $form_id,
				'adminBypass' => true,
				'bypassInfo'  => array(
					'hasDatetime' => $config['datetime_enabled'],
					'hasGeo'      => $config['geo_enabled'],
				),
				'datetime'    => array( 'enabled' => false ),
				'geo'         => array( 'enabled' => false ),
				'global'      => array(
					'debug' => class_exists( '\FreeFormCertificate\Core\Debug' )
					&& \FreeFormCertificate\Core\Debug::is_enabled( \FreeFormCertificate\Core\Debug::AREA_GEOFENCE ),
				),
			);
		}

		// Build frontend config.
		// Check if any bypass is active.
		$has_partial_bypass = $bypass_datetime || $bypass_geo;

		// Get global geolocation settings.
		$geolocation_settings = get_option( 'ffc_geolocation_settings', array() );
		$gps_cache_ttl        = ! empty( $geolocation_settings['gps_cache_ttl'] )
			? absint( $geolocation_settings['gps_cache_ttl'] )
			: 600; // Default 10 minutes.
		$gps_fallback         = $geolocation_settings['gps_fallback'] ?? 'allow';

		$frontend_config = array(
			'formId'      => $form_id,
			'adminBypass' => $has_partial_bypass,
			'bypassInfo'  => $has_partial_bypass ? array(
				'hasDatetime' => $bypass_datetime && $config['datetime_enabled'],
				'hasGeo'      => $bypass_geo && $config['geo_enabled'],
			) : null,
			'datetime'    => array(
				'enabled'   => ! $bypass_datetime && $config['datetime_enabled'],
				'dateStart' => $config['date_start'] ?? '',
				'dateEnd'   => $config['date_end'] ?? '',
				'timeStart' => $config['time_start'] ?? '',
				'timeEnd'   => $config['time_end'] ?? '',
				'timeMode'  => $config['time_mode'] ?? 'daily', // 'span' or 'daily'
				'message'   => $config['msg_datetime'] ?? '',
				'hideMode'  => $config['datetime_hide_mode'] ?? 'message', // 'hide' or 'message'
			),
			'geo'         => array(
				'enabled'        => ! $bypass_geo && $config['geo_enabled'],
				'gpsEnabled'     => ! $bypass_geo && $config['geo_gps_enabled'],
				'ipEnabled'      => ! $bypass_geo && $config['geo_ip_enabled'],
				'areas'          => self::parse_areas( self::resolve_areas_text( $config, 'geo_area_source', 'geo_area_location_ids', 'geo_areas' ) ),
				'gpsIpLogic'     => $config['geo_gps_ip_logic'] ?? 'or', // 'and' or 'or'
				'messageBlocked' => $config['msg_geo_blocked'] ?? '',
				'messageError'   => $config['msg_geo_error'] ?? '',
				'hideMode'       => $config['geo_hide_mode'] ?? 'message', // 'hide' or 'message'
				'gpsFallback'    => $gps_fallback, // 'allow' or 'block' — honoured by frontend on GPS failure
				'cacheEnabled'   => true, // Always enable frontend cache.
				'cacheTtl'       => $gps_cache_ttl, // From global settings.
			),
			'global'      => array(
				'debug' => class_exists( '\FreeFormCertificate\Core\Debug' )
					&& \FreeFormCertificate\Core\Debug::is_enabled( \FreeFormCertificate\Core\Debug::AREA_GEOFENCE ),
			),
		);

		return $frontend_config;
	}

	/**
	 * Resolve area text from config, supporting both named locations and custom text.
	 *
	 * @param array<string, mixed> $config     Form geofence configuration.
	 * @param string               $source_key Config key for the source type.
	 * @param string               $ids_key    Config key for location IDs array.
	 * @param string               $text_key   Config key for raw text fallback.
	 * @return string Areas in "lat, lng, radius" format (one per line).
	 */
	private static function resolve_areas_text( array $config, string $source_key, string $ids_key, string $text_key ): string {
		$source = $config[ $source_key ] ?? 'custom';

		if ( 'locations' === $source && ! empty( $config[ $ids_key ] ) && is_array( $config[ $ids_key ] ) ) {
			return GeofenceLocationRegistry::resolve_to_areas_text( $config[ $ids_key ] );
		}

		return $config[ $text_key ] ?? '';
	}

	/**
	 * Log access denied event
	 *
	 * @param int                  $form_id Form ID.
	 * @param string               $reason Denial reason.
	 * @param array<string, mixed> $details Additional details.
	 */
	private static function log_access_denied( int $form_id, string $reason, array $details = array() ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		\FreeFormCertificate\Core\ActivityLog::log_access_denied( $reason, \FreeFormCertificate\Core\Utils::get_user_ip() );

		// Use centralized debug system.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_geofence(
				'Access denied',
				array_merge(
					array(
						'form_id' => $form_id,
						'reason'  => $reason,
					),
					$details
				)
			);
		}
	}
}
