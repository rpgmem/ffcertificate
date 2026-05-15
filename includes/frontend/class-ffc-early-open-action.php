<?php
/**
 * EarlyOpenAction — flip a form's scheduled start datetime to "now"
 * via the existing CSV-public hash.
 *
 * Use case: a trusted operator (palestrante / event MC / assistant)
 * hits the public CSV download page before the form's scheduled start
 * and clicks "Start Form Now" — the form's `date_start` + `time_start`
 * inside `_ffc_geofence_config` get rewritten to the current local
 * time, so visitors who reload the form page see it as open.
 *
 * Security model:
 *   - Credential is the form's existing CSV-public hash (already
 *     validated upstream by the public AJAX endpoint).
 *   - Eligibility is naturally one-shot via the form's own state:
 *     once `date_start` is now/past, the button is hidden on every
 *     subsequent load, so a leaked hash can only trigger this within
 *     the narrow `now() < start_datetime` window before the action
 *     naturally fires by time.
 *   - Cache propagation: after writing the new datetime, both the
 *     plugin's own FormCache and any third-party page-cache plugin
 *     (W3TC, LiteSpeed, Super Cache, WP Rocket, custom via the
 *     ffc_form_cache_purged hook) are notified.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.5.6
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service that decides whether a form is eligible for early-open and
 * executes the start-datetime flip when so.
 */
class EarlyOpenAction {

	/**
	 * Inspect the form's current state + supplied hash and return
	 * whether the early-open action can run.
	 *
	 * Eligibility checklist:
	 *   1. Form post exists and is published.
	 *   2. CSV-public is enabled on the form (the hash IS the credential).
	 *   3. The supplied hash matches the form's `_ffc_csv_public_hash`.
	 *   4. Datetime restrictions are enabled on the form.
	 *   5. `date_start` is set AND in the future (form not yet open).
	 *   6. `date_end` (if set) is in the future (form hasn't ended).
	 *
	 * @param int    $form_id Form post id.
	 * @param string $hash    Plaintext hash supplied by the public page.
	 * @return array{ok: false, reason: string}|array{ok: true} ok=true when eligible.
	 *                                          reason is a stable string
	 *                                          tag for telemetry / UX
	 *                                          (`unknown_form`, `csv_disabled`,
	 *                                          `bad_hash`, `early_open_disabled`,
	 *                                          `datetime_disabled`, `no_start_date`,
	 *                                          `not_today`, `already_started`,
	 *                                          `already_ended`).
	 */
	public static function is_eligible( int $form_id, string $hash ): array {
		if ( $form_id <= 0 || 'ffc_form' !== get_post_type( $form_id ) ) {
			return array(
				'ok'     => false,
				'reason' => 'unknown_form',
			);
		}

		if ( '1' !== (string) get_post_meta( $form_id, '_ffc_csv_public_enabled', true ) ) {
			return array(
				'ok'     => false,
				'reason' => 'csv_disabled',
			);
		}

		$stored_hash = (string) get_post_meta( $form_id, '_ffc_csv_public_hash', true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $hash ) ) {
			return array(
				'ok'     => false,
				'reason' => 'bad_hash',
			);
		}

		// Per-form opt-out. Pre-6.5.8 forms have an empty value, which is
		// treated as enabled so the feature doesn't disappear from forms
		// that already used it through the CSV-public toggle.
		$start_early_raw = get_post_meta( $form_id, '_ffc_csv_public_start_early_enabled', true );
		$start_early     = '' === (string) $start_early_raw ? '1' : (string) $start_early_raw;
		if ( '1' !== $start_early ) {
			return array(
				'ok'     => false,
				'reason' => 'early_open_disabled',
			);
		}

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}
		if ( '1' !== (string) ( $geofence['datetime_enabled'] ?? '' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'datetime_disabled',
			);
		}

		$start_ts = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $form_id );
		if ( null === $start_ts ) {
			return array(
				'ok'     => false,
				'reason' => 'no_start_date',
			);
		}

		$now = time();
		if ( $start_ts <= $now ) {
			return array(
				'ok'     => false,
				'reason' => 'already_started',
			);
		}

		$end_ts = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $form_id );
		if ( null !== $end_ts && $end_ts <= $now ) {
			return array(
				'ok'     => false,
				'reason' => 'already_ended',
			);
		}

		// Same-day guard — the early-open action only rewrites
		// time_start (not date_start), so it must NOT be exposed when
		// the configured start date is not "today" in the site timezone.
		// Otherwise an operator could fire the action and merely shift
		// the clock value while date_start remains in the future
		// (Geofence's date-range check would then still reject the
		// form). The frontend builder mirrors this check so the button
		// is hidden in that case.
		$today      = current_time( 'Y-m-d' );
		$date_start = (string) ( $geofence['date_start'] ?? '' );
		if ( $date_start !== $today ) {
			return array(
				'ok'     => false,
				'reason' => 'not_today',
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Execute the early-open: rewrite the form's `date_start` +
	 * `time_start` inside `_ffc_geofence_config` to "now", invalidate
	 * caches, and emit an Activity Log audit entry.
	 *
	 * Caller is responsible for having validated the hash via
	 * {@see self::is_eligible()} first (the public AJAX endpoint does
	 * this); this method re-runs the eligibility check defensively so
	 * a stale browser tab can't race against a concurrent admin edit.
	 *
	 * @param int                  $form_id    Form post id.
	 * @param string               $hash       The public hash (used
	 *                                         for audit, validated again).
	 * @param array<string, mixed> $audit_meta Caller-supplied context for
	 *                                         the audit row — typically
	 *                                         { user_id, ip, ua }.
	 * @return array{ok: false, reason: string}|array{ok: true, original_start_iso: string, new_start_iso: string}
	 */
	public static function execute( int $form_id, string $hash, array $audit_meta = array() ): array {
		$eligibility = self::is_eligible( $form_id, $hash );
		if ( ! $eligibility['ok'] ) {
			return $eligibility;
		}

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}

		// Snapshot the original window for the audit row.
		$original_date_start = (string) ( $geofence['date_start'] ?? '' );
		$original_time_start = (string) ( $geofence['time_start'] ?? '' );
		$original_time_mode  = (string) ( $geofence['time_mode'] ?? 'daily' );

		// current_time honours the WP site timezone — date_start /
		// time_start are stored as local-naive strings by the geofence
		// metabox save handler, so we match that shape.
		$now_date = current_time( 'Y-m-d' );
		$now_time = current_time( 'H:i' );

		// Scope of the write is intentionally narrow: only `time_start`
		// is rewritten. The configured date_start stays untouched —
		// eligibility (above) and the frontend button guard ensure
		// this action only fires on the same calendar day the form was
		// scheduled to start. date_end / time_end / time_mode are all
		// preserved so the form's scheduled close behaviour is intact.
		$geofence['time_start'] = $now_time;
		$new_time_start         = $now_time;

		update_post_meta( $form_id, '_ffc_geofence_config', $geofence );

		// Invalidate the plugin's own object cache + any page-cache
		// plugins (W3TC/LiteSpeed/Super Cache/WP Rocket/Cloudflare APO
		// via the hook). Both lines added in #225 specifically for this.
		\FreeFormCertificate\Submissions\FormCache::clear_form_cache( $form_id );
		\FreeFormCertificate\Submissions\FormCache::purge_external_caches( $form_id, 'early_open' );

		// Audit log — best-effort, doesn't block the action on failure.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'early_open_executed',
				'warning',
				array(
					'form_id'             => $form_id,
					'date_start'          => $original_date_start,
					'original_time_start' => $original_time_start,
					'time_mode'           => $original_time_mode,
					'new_time_start'      => $new_time_start,
					'triggered_by_ip'     => (string) ( $audit_meta['ip'] ?? '' ),
					'triggered_by_ua'     => (string) ( $audit_meta['ua'] ?? '' ),
				),
				(int) ( $audit_meta['user_id'] ?? 0 )
			);
		}

		return array(
			'ok'                 => true,
			'original_start_iso' => trim( $original_date_start . ' ' . $original_time_start ),
			'new_start_iso'      => $now_date . ' ' . $now_time,
		);
	}
}
