<?php
/**
 * FormEditorSaveValidator
 *
 * Validation cluster extracted from {@see FormEditorSaveHandler} (#591 phase-3).
 * Pure validation helpers — no persistence, no POST handling. Each method takes
 * its inputs as parameters and returns error lists / derived data; none rely on
 * mutable instance state.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.7.x (Extracted from FormEditorSaveHandler — #591 phase-3, Sprint E5b)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation helpers for form editor save operations.
 */
class FormEditorSaveValidator {

	/**
	 * Minimum length (characters) required for each geofence block /
	 * error message when its parent restriction is enabled.
	 *
	 * Empty or too-short messages produced silent frontend failures: a
	 * legitimate date/time or geolocation block returned an empty
	 * `message`, which ffc-core.js (`err.fromServer = !!serverMsg`)
	 * treated as "no server message" and surfaced as a generic
	 * "Connection error" — the user saw nothing actionable. The runtime
	 * fallback in {@see \FreeFormCertificate\Security\Geofence::message_or_default()}
	 * guarantees a non-empty string at render time; this save-side gate
	 * pushes the requirement upstream so operators author a meaningful
	 * message instead of relying on the generic fallback. Tune here.
	 */
	public const GEOFENCE_MESSAGE_MIN_LENGTH = 25;

	/**
	 * Required certificate tags absent from a PDF layout.
	 *
	 * Reads the configurable required-tag list (Settings → Advanced) via
	 * {@see SettingsReader::required_certificate_tags()} and honours the
	 * historical `{{name}}`/`{{nome}}` alias — a layout using either token
	 * satisfies a `{{name}}` requirement.
	 *
	 * `{{schedule}}` is dynamically added to the required list for this
	 * save when the form has an Event Schedule configured (geofence
	 * `class_time_start` or `class_time_end` non-empty). PdfGenerator
	 * resolves `{{schedule}}` from that field, so a layout that omits
	 * the placeholder would silently drop the operator-configured event
	 * schedule.
	 *
	 * @param string $layout  PDF layout HTML.
	 * @param int    $post_id Form post ID — used to read the per-form
	 *                        geofence config and decide whether
	 *                        `{{schedule}}` is mandatory for this save.
	 * @return array<int, string> Missing `{{tag}}` tokens, in required order.
	 */
	public function missing_required_tags( string $layout, int $post_id ): array {
		$required = \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags();

		// Per-form gate: when Event Schedule is configured, the layout
		// MUST consume {{schedule}} or the operator's configured time
		// silently disappears from the rendered certificate.
		$geofence = get_post_meta( $post_id, '_ffc_geofence_config', true );
		if ( is_array( $geofence )
			&& ( ! empty( $geofence['class_time_start'] ) || ! empty( $geofence['class_time_end'] ) )
			&& ! in_array( '{{schedule}}', $required, true )
		) {
			$required[] = '{{schedule}}';
		}

		$missing = array();
		foreach ( $required as $tag ) {
			$accepted = array( $tag );
			if ( '{{name}}' === $tag ) {
				$accepted[] = '{{nome}}';
			}
			$found = false;
			foreach ( $accepted as $candidate ) {
				if ( false !== strpos( $layout, $candidate ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $tag;
			}
		}
		return $missing;
	}

	/**
	 * Validate geofence configuration.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @return array<int, string> Validation error messages.
	 */
	public function validate_geofence_config( array $config ): array {
		$errors = array();

		$gps_source = $config['geo_area_source'] ?? 'custom';
		$ip_source  = $config['geo_ip_area_source'] ?? 'custom';

		// Defensive defaults — sub-options can be missing from $config when
		// the master toggle is off and skip-on-off semantics (Sprint 2 /
		// #238) preserved the prior values without re-emitting them here.
		$gps_enabled   = $config['geo_gps_enabled'] ?? '0';
		$ip_enabled    = $config['geo_ip_enabled'] ?? '0';
		$ip_permissive = $config['geo_ip_areas_permissive'] ?? '0';
		$geo_areas     = (string) ( $config['geo_areas'] ?? '' );
		$geo_ip_areas  = (string) ( $config['geo_ip_areas'] ?? '' );

		// Check if GPS is enabled but areas/locations are empty.
		if ( '1' === $gps_enabled ) {
			if ( 'locations' === $gps_source ) {
				if ( empty( $config['geo_area_location_ids'] ) ) {
					$errors[] = __( 'GPS Geolocation is enabled but no locations are selected.', 'ffcertificate' );
				}
			} elseif ( '' === trim( $geo_areas ) ) {
				$errors[] = __( 'GPS Geolocation is enabled but no allowed areas are defined.', 'ffcertificate' );
			}
		}

		// Check if IP is enabled with independent areas but areas/locations are empty.
		if ( '1' === $ip_enabled && '1' === $ip_permissive ) {
			if ( 'locations' === $ip_source ) {
				if ( empty( $config['geo_ip_area_location_ids'] ) ) {
					$errors[] = __( 'IP Geolocation is enabled with independent areas but no locations are selected.', 'ffcertificate' );
				}
			} elseif ( '' === trim( $geo_ip_areas ) ) {
				$errors[] = __( 'IP Geolocation is enabled with independent areas but no IP areas are defined.', 'ffcertificate' );
			}
		}

		// Validate datetime order (#159 S2). Date/time order checks live in
		// `Geofence::analyze_datetime_order()` so the metabox renderer can
		// reuse the same map to paint `ffc-input-invalid` on the offending
		// inputs without duplicating the rules.
		$datetime_errors = \FreeFormCertificate\Security\Geofence::analyze_datetime_order( $config );
		if ( ! empty( $datetime_errors ) ) {
			// Dedupe — the helper repeats the same message across paired
			// fields (e.g. both `date_start` and `date_end`); the operator
			// only needs to see each rule once in the admin notice.
			$errors = array_merge( $errors, array_values( array_unique( array_values( $datetime_errors ) ) ) );
		}

		// Validate GPS areas format.
		if ( '1' === $gps_enabled && 'custom' === $gps_source && '' !== trim( $geo_areas ) ) {
			$gps_errors = $this->validate_areas_format( $geo_areas, 'GPS' );
			$errors     = array_merge( $errors, $gps_errors );
		}

		// Validate IP areas format (if using independent areas).
		if ( '1' === $ip_enabled && '1' === $ip_permissive && 'custom' === $ip_source && '' !== trim( $geo_ip_areas ) ) {
			$ip_errors = $this->validate_areas_format( $geo_ip_areas, 'IP' );
			$errors    = array_merge( $errors, $ip_errors );
		}

		// Block / error message minimum length (Time + Geolocation). Only
		// enforced when the owning restriction is enabled — a form that
		// doesn't gate by date/time or location isn't forced to author
		// messages it will never show.
		$message_errors = $this->geofence_message_errors( $config );
		$errors         = array_merge( $errors, $message_errors['time'], $message_errors['geolocation'] );

		return $errors;
	}

	/**
	 * Validate the geofence block / error messages, split by owning tab.
	 *
	 * Returns a two-bucket map so {@see geofence_error_tab_keys()} can route
	 * each failure to the tab that owns the field without re-matching on
	 * message text. Each message is only checked when its parent toggle is
	 * on:
	 *  - `time`        → `msg_datetime` (when `datetime_enabled`)
	 *  - `geolocation` → `msg_geo_blocked` + `msg_geo_error` (when `geo_enabled`)
	 *
	 * Length is measured with `mb_strlen` so accented Portuguese copy isn't
	 * penalised byte-for-byte.
	 *
	 * @param array<string, mixed> $config Merged geofence config.
	 * @return array{time: list<string>, geolocation: list<string>}
	 */
	public function geofence_message_errors( array $config ): array {
		$min = self::GEOFENCE_MESSAGE_MIN_LENGTH;
		$out = array(
			'time'        => array(),
			'geolocation' => array(),
		);

		if ( '1' === ( $config['datetime_enabled'] ?? '0' ) ) {
			if ( mb_strlen( trim( (string) ( $config['msg_datetime'] ?? '' ) ) ) < $min ) {
				$out['time'][] = sprintf(
					/* translators: %d: minimum character count */
					__( 'The date/time "Blocked Message" must be at least %d characters so visitors understand why the form is unavailable.', 'ffcertificate' ),
					$min
				);
			}
		}

		if ( '1' === ( $config['geo_enabled'] ?? '0' ) ) {
			if ( mb_strlen( trim( (string) ( $config['msg_geo_blocked'] ?? '' ) ) ) < $min ) {
				$out['geolocation'][] = sprintf(
					/* translators: %d: minimum character count */
					__( 'The geolocation "Blocked Message" must be at least %d characters.', 'ffcertificate' ),
					$min
				);
			}
			if ( mb_strlen( trim( (string) ( $config['msg_geo_error'] ?? '' ) ) ) < $min ) {
				$out['geolocation'][] = sprintf(
					/* translators: %d: minimum character count */
					__( 'The geolocation "Error Message" must be at least %d characters.', 'ffcertificate' ),
					$min
				);
			}
		}

		return $out;
	}

	/**
	 * Map a geofence validation failure to the tab(s) that own the offending
	 * fields, so the editor's tab script can flag and open the right one.
	 *
	 * Reuses the flat error list from {@see validate_geofence_config()} and the
	 * datetime-order helper instead of re-running the area checks: the
	 * date/time-order messages belong to the "Time" tab; everything else
	 * (GPS/IP area + format errors) belongs to "Geolocation".
	 *
	 * @param array<string, mixed> $config     Merged geofence config.
	 * @param array<int, string>   $all_errors Flat error list for this config.
	 * @return array<int, string> Ordered tab keys, e.g. ['time', 'geolocation'].
	 */
	public function geofence_error_tab_keys( array $config, array $all_errors ): array {
		$keys            = array();
		$datetime_errors = array_values(
			array_unique(
				array_values(
					\FreeFormCertificate\Security\Geofence::analyze_datetime_order( $config )
				)
			)
		);
		$message_errors  = $this->geofence_message_errors( $config );

		// Time tab owns the datetime-order messages plus the date/time
		// "Blocked Message" length error.
		$time_errors = array_merge( $datetime_errors, $message_errors['time'] );
		if ( ! empty( $time_errors ) ) {
			$keys[] = 'time';
		}

		// Geolocation tab owns everything else: GPS/IP area + format errors
		// (whatever remains in $all_errors after removing the time-tab
		// messages and the geo message-length errors) plus those geo
		// message-length errors themselves.
		$area_errors = array_diff( $all_errors, $time_errors, $message_errors['geolocation'] );
		if ( ! empty( $area_errors ) || ! empty( $message_errors['geolocation'] ) ) {
			$keys[] = 'geolocation';
		}
		return $keys;
	}

	/**
	 * Validates area format (latitude, longitude, radius)
	 *
	 * @param string $areas_text Areas text (one per line).
	 * @param string $type Type of area (GPS or IP) for error messages.
	 * @return array<int, string> Array of validation errors
	 */
	public function validate_areas_format( string $areas_text, string $type ): array {
		$errors      = array();
		$lines       = array_filter( array_map( 'trim', explode( "\n", $areas_text ) ) );
		$line_number = 0;

		foreach ( $lines as $line ) {
			++$line_number;

			// Check format: lat,lng,radius.
			if ( ! preg_match( '/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*,\s*\d+(\.\d+)?$/', $line ) ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number */
					__( '%1$s Area line %2$d: Invalid format. Use: latitude, longitude, radius', 'ffcertificate' ),
					$type,
					$line_number
				);
				continue;
			}

			// Parse values.
			$parts  = array_map( 'trim', explode( ',', $line ) );
			$lat    = floatval( $parts[0] );
			$lng    = floatval( $parts[1] );
			$radius = floatval( $parts[2] );

			// Validate latitude range.
			if ( $lat < -90 || $lat > 90 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number, 3: Latitude value */
					__( '%1$s Area line %2$d: Invalid latitude %3$s (must be between -90 and 90)', 'ffcertificate' ),
					$type,
					$line_number,
					$lat
				);
			}

			// Validate longitude range.
			if ( $lng < -180 || $lng > 180 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number, 3: Longitude value */
					__( '%1$s Area line %2$d: Invalid longitude %3$s (must be between -180 and 180)', 'ffcertificate' ),
					$type,
					$line_number,
					$lng
				);
			}

			// Validate radius.
			if ( $radius <= 0 ) {
				$errors[] = sprintf(
					/* translators: 1: Area type (GPS/IP), 2: Line number */
					__( '%1$s Area line %2$d: Radius must be greater than 0', 'ffcertificate' ),
					$type,
					$line_number
				);
			}
		}

		return $errors;
	}
}
