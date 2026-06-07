<?php
/**
 * CsvDownloadFormInfoBuilder
 *
 * Builds the metadata payload returned by the intermediate "form details"
 * AJAX screen that precedes a public CSV download. Extracted from
 * {@see PublicCsvDownload} as part of the S5 god-object split (issue #141).
 *
 * The orchestrator method ({@see build_form_info}) is public; the per-section
 * helpers (restrictions, datetime, geolocation, quiz, location formatter) are
 * private because they are only ever invoked by build_form_info() in this
 * same class.
 *
 * @package FreeFormCertificate
 * @since   6.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builder for the public CSV download intermediate-screen payload.
 *
 * @since 6.4.0
 */
final class CsvDownloadFormInfoBuilder {

	/**
	 * Build the form metadata payload for the intermediate preview screen.
	 *
	 * @param int $form_id Validated form ID.
	 * @return array<string, mixed>
	 */
	public function build_form_info( int $form_id ): array {
		$form_config     = get_post_meta( $form_id, '_ffc_form_config', true );
		$form_config     = is_array( $form_config ) ? $form_config : array();
		$geofence_config = get_post_meta( $form_id, '_ffc_geofence_config', true );
		$geofence_config = is_array( $geofence_config ) ? $geofence_config : array();

		$now      = time();
		$start_ts = Geofence::get_form_start_timestamp( $form_id );
		$end_ts   = Geofence::get_form_end_timestamp( $form_id );

		$before_start = null !== $start_ts && $now < $start_ts;
		$form_ended   = null !== $end_ts && $now > $end_ts;
		$has_end_date = null !== $end_ts;

		// Quota.
		$limit = (int) get_post_meta( $form_id, PublicCsvDownload::META_LIMIT, true );
		if ( $limit <= 0 ) {
			$default = \FreeFormCertificate\Settings\SettingsReader::get_int( 'public_csv_default_limit', 0 );
			$limit   = $default > 0 ? $default : 1;
		}
		$count           = (int) get_post_meta( $form_id, PublicCsvDownload::META_COUNT, true );
		$quota_exhausted = $count >= $limit;

		// Download blocked reason. Branches checked in order from
		// "needs admin action" to "the download is just turned off for
		// this form". The new `download_disabled` branch (post-#241)
		// surfaces only when the form HAS ended + quota OK — earlier
		// states (no end date / still active / quota out) take
		// priority because they reflect a temporary or fixable
		// condition, while `download_disabled` is an explicit admin
		// choice (the CSV Download sub-toggle is off).
		$download_reason = null;
		if ( ! $has_end_date ) {
			$download_reason = 'no_end_date';
		} elseif ( ! $form_ended ) {
			$download_reason = 'active';
		} elseif ( $quota_exhausted ) {
			$download_reason = 'quota_exhausted';
		} elseif ( '1' !== self::download_enabled_meta( $form_id ) ) {
			$download_reason = 'download_disabled';
		}

		// Submission count.
		$repo             = new \FreeFormCertificate\Repositories\SubmissionRepository();
		$submission_count = $repo->countForExport( array( $form_id ), 'publish' );

		$tz = wp_timezone();

		// `can_schedule_exception` mirrors ScheduleExceptionAction::is_eligible()
		// so the JS can't render a button the server would reject.
		$can_schedule_exception = ! $before_start
			&& ! $form_ended
			&& '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
			&& '1' === (string) ( $geofence_config['schedule_exception_enabled'] ?? '' )
			&& '1' === (string) ( $geofence_config['datetime_enabled'] ?? '' );

		// Pre-resolve the page that embeds this form so the operator gets a
		// clickable "open participant form" link in the summary at
		// validation time. Surfaced for ANY embedded form (not only when the
		// schedule-exception flow is available) — it's a general reference to
		// the public form page. Empty string when the form isn't embedded on
		// any page, in which case the JS shows no link. #366 Sprint 5.
		$schedule_form_url = \FreeFormCertificate\Frontend\ScheduleExceptionAction::find_form_page_url( $form_id );

		return array(
			'form_title'       => get_the_title( $form_id ),
			'submission_count' => $submission_count,
			'restrictions'     => $this->build_restrictions_info( $form_config ),
			'datetime'         => $this->build_datetime_info( $geofence_config, $tz ),
			'geolocation'      => $this->build_geolocation_info( $geofence_config ),
			'quiz'             => $this->build_quiz_info( $form_config ),
			'csv'              => array(
				'limit'     => $limit,
				'count'     => $count,
				'remaining' => max( 0, $limit - $count ),
			),
			'status'           => array(
				'has_start_date'                 => null !== $start_ts,
				'has_end_date'                   => $has_end_date,
				'before_start'                   => $before_start,
				'form_ended'                     => $form_ended,
				// `can_download` powers the "Download CSV" button. Post-#241
				// it ALSO gates on the new `_ffc_csv_public_download_enabled`
				// sub-toggle (empty meta reads as '1' so pre-upgrade forms
				// keep working). Admins can now disable the CSV download
				// without affecting Start Early / Postpone Close.
				'can_download'                   => $form_ended
					&& ! $quota_exhausted
					&& '1' === self::download_enabled_meta( $form_id ),
				// `*_disabled_by_admin` flags distinguish "admin explicitly
				// turned off this sub-feature" (button should render disabled
				// with a tooltip so the operator knows the feature exists)
				// from "feature inapplicable right now due to state" (button
				// is hidden — the info alerts below explain why).
				// Master ON + sub-toggle OFF = disabled-visible. Issue #243.
				'csv_download_disabled_by_admin' => '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' !== self::download_enabled_meta( $form_id ),
				'start_early_disabled_by_admin'  => '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' !== self::start_early_meta( $form_id ),
				'extend_end_disabled_by_admin'   => '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' !== (string) get_post_meta( $form_id, \FreeFormCertificate\Frontend\ExtendEndAction::META_ENABLED, true ),
				'cert_preview_disabled_by_admin' => '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' !== self::preview_enabled_meta( $form_id ),
				'can_preview_cert'               => $before_start
					&& '1' === self::preview_enabled_meta( $form_id ),
				// `can_open_early` powers the "Start Form Now" button — it
				// fires only when CSV public is on (the hash is the cred),
				// the per-form opt-out is on, datetime restrictions are
				// enabled, the form hasn't yet started, and (if there's an
				// end date) it hasn't ended. Matches EarlyOpenAction::is_eligible()
				// exactly so the JS can't see a stale "can-open" state.
				// `_ffc_csv_public_start_early_enabled` defaults to '1' when
				// unset so pre-6.5.8 forms don't regress.
				'can_open_early'                 => $before_start
					&& ! $form_ended
					&& '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' === self::start_early_meta( $form_id )
					&& '1' === (string) ( $geofence_config['datetime_enabled'] ?? '' )
					&& self::is_today_start_date( $geofence_config ),
				// `can_extend_end` powers the "Postergar fim" button —
				// it fires only when CSV public is on, the per-form
				// opt-IN is on, datetime restrictions are enabled, the
				// form has STARTED and not yet ended, today equals the
				// configured date_end, and the one-shot guard hasn't
				// fired. Mirrors ExtendEndAction::is_eligible() so JS
				// can't see a stale "can-extend" state.
				'can_extend_end'                 => ! $before_start
					&& ! $form_ended
					&& '1' === (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true )
					&& '1' === (string) get_post_meta( $form_id, \FreeFormCertificate\Frontend\ExtendEndAction::META_ENABLED, true )
					&& '1' === (string) ( $geofence_config['datetime_enabled'] ?? '' )
					&& self::is_today_end_date( $geofence_config )
					&& '' === (string) get_post_meta( $form_id, \FreeFormCertificate\Frontend\ExtendEndAction::META_POSTPONED_AT, true ),
				'current_time_end'               => isset( $geofence_config['time_end'] ) ? (string) $geofence_config['time_end'] : '',
				// Date-only formatted string for the postpone-close modal
				// (which composes "<date> <current_time_end>" so the time
				// stays in 24h regardless of the site's time_format setting).
				// Empty when no end_ts. Issue #243 Sprint 3.
				'current_date_end_formatted'     => null !== $end_ts
					? \FreeFormCertificate\Core\DateFormatter::format_date( $end_ts, 'default', $tz )
					: '',
				// `can_schedule_exception` powers the Sprint-4 "Entrada/saída
				// diferenciada" button (computed above so it also gates the
				// pre-resolved `schedule_form_url`). #366 Sprint 4.
				'can_schedule_exception'         => $can_schedule_exception,
				// Pre-resolved participant-form page URL, surfaced to the
				// operator on validation. Empty unless the exception flow is
				// available. #366 Sprint 5.
				'schedule_form_url'              => $schedule_form_url,
				// Baseline values for the exception modal — class_time_*
				// wins, geofence time_* falls back. The modal pre-fills
				// both inputs so the operator only edits the side they
				// need. Sprint 6 will read these on the server too via
				// ScheduleExceptionAction::resolve_baseline().
				'schedule_baseline_start'        => '' !== (string) ( $geofence_config['class_time_start'] ?? '' )
					? (string) $geofence_config['class_time_start']
					: (string) ( $geofence_config['time_start'] ?? '' ),
				'schedule_baseline_end'          => '' !== (string) ( $geofence_config['class_time_end'] ?? '' )
					? (string) $geofence_config['class_time_end']
					: (string) ( $geofence_config['time_end'] ?? '' ),
				// Effective form window — used by the JS for client-side
				// "stay within window" feedback. Server is the source of
				// truth (`out_of_window` reason), this is just UX polish.
				'schedule_window_start'          => (string) ( $geofence_config['time_start'] ?? '' ),
				'schedule_window_end'            => (string) ( $geofence_config['time_end'] ?? '' ),
				'schedule_default_mode'          => in_array(
					(string) ( $geofence_config['schedule_default_mode'] ?? 'now' ),
					array( 'now', 'manual' ),
					true
				) ? (string) ( $geofence_config['schedule_default_mode'] ?? 'now' ) : 'now',
				'download_blocked_reason'        => $download_reason,
				'start_date_formatted'           => null !== $start_ts
					? \FreeFormCertificate\Core\DateFormatter::format_datetime( $start_ts, 'default', ' ', $tz )
					: null,
				'end_date_formatted'             => null !== $end_ts
					? \FreeFormCertificate\Core\DateFormatter::format_datetime( $end_ts, 'default', ' ', $tz )
					: null,
			),
		);
	}

	/**
	 * Resolve the per-form Start Form Early opt-out. Empty (unset) reads
	 * as '1' so forms saved before 6.5.8 keep the feature enabled.
	 *
	 * @param int $form_id Form post id.
	 * @return string '1' (enabled) or '0' (disabled).
	 */
	private static function start_early_meta( int $form_id ): string {
		$raw = (string) get_post_meta( $form_id, '_ffc_csv_public_start_early_enabled', true );
		return '' === $raw ? '1' : $raw;
	}

	/**
	 * Resolve the per-form CSV Download sub-toggle (post-#241). Empty
	 * meta reads as '1' so pre-upgrade forms keep their CSV download
	 * available; explicit '0' turns just the download off without
	 * affecting Start Early / Postpone Close on the same hash.
	 *
	 * @param int $form_id Form post id.
	 * @return string '1' (enabled) or '0' (disabled).
	 */
	private static function download_enabled_meta( int $form_id ): string {
		$raw = (string) get_post_meta( $form_id, '_ffc_csv_public_download_enabled', true );
		return '' === $raw ? '1' : $raw;
	}

	/**
	 * Resolve the per-form Certificate Preview sub-toggle (#243 Sprint 5).
	 * Empty meta reads as '1' so pre-upgrade forms keep the preview button
	 * available; explicit '0' turns just the preview off without affecting
	 * the other operator features on the same hash.
	 *
	 * @param int $form_id Form post id.
	 * @return string '1' (enabled) or '0' (disabled).
	 */
	private static function preview_enabled_meta( int $form_id ): string {
		$raw = (string) get_post_meta( $form_id, '_ffc_csv_public_preview_enabled', true );
		return '' === $raw ? '1' : $raw;
	}

	/**
	 * Match the same-day guard `EarlyOpenAction::is_eligible()` enforces:
	 * the early-open button only surfaces when the configured `date_start`
	 * equals the current site-tz date. Mirrors the server check so the JS
	 * can't see a stale "can-open" state across a date boundary.
	 *
	 * @param array<string, mixed> $geofence Geofence config.
	 * @return bool True when date_start matches today (site tz).
	 */
	private static function is_today_start_date( array $geofence ): bool {
		$date_start = isset( $geofence['date_start'] ) ? trim( (string) $geofence['date_start'] ) : '';
		if ( '' === $date_start ) {
			return false;
		}
		return (string) current_time( 'Y-m-d' ) === $date_start;
	}

	/**
	 * Sibling of {@see is_today_start_date()} for the close boundary —
	 * mirrors the `not_today` branch in `ExtendEndAction::is_eligible()`.
	 *
	 * @param array<string, mixed> $geofence Geofence config.
	 * @return bool True when date_end matches today (site tz).
	 */
	private static function is_today_end_date( array $geofence ): bool {
		$date_end = isset( $geofence['date_end'] ) ? trim( (string) $geofence['date_end'] ) : '';
		if ( '' === $date_end ) {
			return false;
		}
		return (string) current_time( 'Y-m-d' ) === $date_end;
	}

	/**
	 * Build access restrictions data for the info response.
	 *
	 * @param array<string, mixed> $config Form config.
	 * @return array<string, bool>
	 */
	private function build_restrictions_info( array $config ): array {
		$restrictions = $config['restrictions'] ?? array();
		$result       = array();

		if ( ! empty( $restrictions['password'] ) && '1' === (string) $restrictions['password'] ) {
			$result['password'] = true;
		}
		if ( ! empty( $restrictions['allowlist'] ) && '1' === (string) $restrictions['allowlist'] ) {
			$result['allowlist'] = true;
		}
		if ( ! empty( $restrictions['denylist'] ) && '1' === (string) $restrictions['denylist'] ) {
			$result['denylist'] = true;
		}
		if ( ! empty( $restrictions['ticket'] ) && '1' === (string) $restrictions['ticket'] ) {
			$result['ticket'] = true;
		}

		return $result;
	}

	/**
	 * Build date/time availability data for the info response.
	 *
	 * @param array<string, mixed> $config   Geofence config.
	 * @param \DateTimeZone        $tz       Site timezone.
	 * @return array<string, mixed>
	 */
	private function build_datetime_info( array $config, \DateTimeZone $tz ): array {
		$date_start = isset( $config['date_start'] ) ? trim( (string) $config['date_start'] ) : '';
		$date_end   = isset( $config['date_end'] ) ? trim( (string) $config['date_end'] ) : '';
		$time_start = isset( $config['time_start'] ) ? trim( (string) $config['time_start'] ) : '';
		$time_end   = isset( $config['time_end'] ) ? trim( (string) $config['time_end'] ) : '';
		$time_mode  = isset( $config['time_mode'] ) ? (string) $config['time_mode'] : 'daily';

		$has_dates = '' !== $date_start || '' !== $date_end;
		$has_times = '' !== $time_start || '' !== $time_end;

		// Anchor each date in the site timezone before formatting. Naive
		// strtotime() reads "Y-m-d" as PHP-process-local (typically UTC)
		// midnight, which then drifts to the previous day after wp_date()
		// converts to a westward TZ like America/Sao_Paulo — manifesting
		// as "Data de início: 11/05/2026" for a configured 2026-05-12. The
		// footer status message uses Geofence::get_form_*_timestamp() and
		// is unaffected; this brings the body in line with the same TZ
		// anchoring approach.
		$to_local_ts = static function ( string $date, \DateTimeZone $tz ): ?int {
			if ( '' === $date ) {
				return null;
			}
			try {
				return ( new \DateTimeImmutable( $date, $tz ) )->getTimestamp();
			} catch ( \Exception $e ) {
				return null;
			}
		};
		$start_ts    = $to_local_ts( $date_start, $tz );
		$end_ts      = $to_local_ts( $date_end, $tz );

		return array(
			'has_dates'      => $has_dates,
			'date_start'     => null !== $start_ts ? \FreeFormCertificate\Core\DateFormatter::format_date( $start_ts, 'default', $tz ) : null,
			'date_start_raw' => '' !== $date_start ? $date_start : null,
			'date_end'       => null !== $end_ts ? \FreeFormCertificate\Core\DateFormatter::format_date( $end_ts, 'default', $tz ) : null,
			'date_end_raw'   => '' !== $date_end ? $date_end : null,
			'has_times'      => $has_times,
			'time_start'     => '' !== $time_start ? $time_start : null,
			'time_end'       => '' !== $time_end ? $time_end : null,
			'time_mode'      => $time_mode,
		);
	}

	/**
	 * Build geolocation data for the info response.
	 *
	 * @param array<string, mixed> $config Geofence config.
	 * @return array<string, mixed>
	 */
	private function build_geolocation_info( array $config ): array {
		$geo_enabled = ! empty( $config['geo_enabled'] );
		if ( ! $geo_enabled ) {
			return array( 'enabled' => false );
		}

		$gps_enabled = ! empty( $config['geo_gps_enabled'] );
		$ip_enabled  = ! empty( $config['geo_ip_enabled'] );

		$result = array(
			'enabled'     => true,
			'gps_enabled' => $gps_enabled,
			'ip_enabled'  => $ip_enabled,
		);

		// GPS locations.
		if ( $gps_enabled ) {
			$gps_source = $config['geo_area_source'] ?? 'locations';
			if ( 'locations' === $gps_source && ! empty( $config['geo_area_location_ids'] ) ) {
				$locations               = GeofenceLocationRegistry::get_by_ids( (array) $config['geo_area_location_ids'] );
				$result['gps_locations'] = $this->format_locations_for_info( $locations );
			} else {
				$result['gps_custom'] = true;
			}
		}

		// IP locations (only when separate areas are configured).
		if ( $ip_enabled && ! empty( $config['geo_ip_areas_permissive'] ) ) {
			$ip_source = $config['geo_ip_area_source'] ?? 'locations';
			if ( 'locations' === $ip_source && ! empty( $config['geo_ip_area_location_ids'] ) ) {
				$locations              = GeofenceLocationRegistry::get_by_ids( (array) $config['geo_ip_area_location_ids'] );
				$result['ip_locations'] = $this->format_locations_for_info( $locations );
			} else {
				$result['ip_custom'] = true;
			}
		}

		return $result;
	}

	/**
	 * Format registered locations for the info response.
	 *
	 * @param array<int, array<string, mixed>> $locations Raw location data.
	 * @return list<array<string, float|string>>
	 */
	private function format_locations_for_info( array $locations ): array {
		$formatted = array();
		foreach ( $locations as $loc ) {
			$formatted[] = array(
				'name'     => sanitize_text_field( $loc['name'] ?? '' ),
				'lat'      => (float) ( $loc['lat'] ?? 0 ),
				'lng'      => (float) ( $loc['lng'] ?? 0 ),
				'radius'   => (float) ( $loc['radius'] ?? 0 ),
				'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . (float) $loc['lat'] . ',' . (float) $loc['lng'],
			);
		}
		return $formatted;
	}

	/**
	 * Build quiz/evaluation data for the info response.
	 *
	 * @param array<string, mixed> $config Form config.
	 * @return array<string, mixed>
	 */
	private function build_quiz_info( array $config ): array {
		$enabled = ! empty( $config['quiz_enabled'] ) && '1' === (string) $config['quiz_enabled'];
		if ( ! $enabled ) {
			return array( 'enabled' => false );
		}

		return array(
			'enabled'       => true,
			'passing_score' => (int) ( $config['quiz_passing_score'] ?? 0 ),
			'max_attempts'  => (int) ( $config['quiz_max_attempts'] ?? 0 ),
		);
	}
}
