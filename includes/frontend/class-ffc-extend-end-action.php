<?php
/**
 * ExtendEndAction — sibling to EarlyOpenAction but for the close
 * boundary. Lets a trusted operator push the form's `time_end` later
 * within the same calendar day as the configured `date_end`, exactly
 * once per form.
 *
 * Design constraints baked in:
 *   - Form must already have STARTED (between start_ts and end_ts).
 *     Postponing only makes sense for the form's active window.
 *   - New time_end must be strictly later than both `now` and the
 *     current time_end, must be HH:MM-shaped, and must stay within the
 *     calendar day of the configured `date_end` (≤ 23:59 that same day).
 *   - Per-form opt-IN — `_ffc_csv_public_extend_end_enabled` defaults
 *     to `'0'` when unset. Admins must turn it on consciously because
 *     the action extends a public-facing window.
 *   - Strictly one-shot per form. `_ffc_csv_public_end_postponed_at`
 *     is set on success and re-checked on every eligibility call;
 *     once present the button disappears. The admin can edit time_end
 *     manually in the metabox without touching this flag.
 *
 * @package FreeFormCertificate
 * @since   6.5.12
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service: extend the form's scheduled close (postpone time_end).
 */
final class ExtendEndAction {

	/**
	 * One-shot persistence. Stores the Unix timestamp of when the
	 * postponement was applied. Presence = "already postponed".
	 */
	public const META_POSTPONED_AT = '_ffc_csv_public_end_postponed_at';

	/**
	 * Snapshot of the time_end value before the rewrite — kept for the
	 * audit trail surfaced in the admin metabox.
	 */
	public const META_POSTPONED_FROM = '_ffc_csv_public_end_postponed_from';

	/**
	 * Per-form opt-in. Empty / '0' = button hidden; '1' = exposed.
	 */
	public const META_ENABLED = '_ffc_csv_public_extend_end_enabled';

	/**
	 * Check whether an operator presenting the given hash is allowed to
	 * postpone this form's time_end.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $hash    Plaintext hash supplied by the public page.
	 * @return array{ok: false, reason: string}|array{ok: true} ok=true when eligible.
	 *                                          reason is a stable string tag
	 *                                          (`unknown_form`, `csv_disabled`,
	 *                                          `bad_hash`, `extend_end_disabled`,
	 *                                          `datetime_disabled`, `no_end_date`,
	 *                                          `not_today`, `not_started_yet`,
	 *                                          `already_ended`, `already_postponed`).
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

		// Per-form opt-IN — empty value reads as off (conservative
		// default for a public-facing window extension).
		if ( '1' !== (string) get_post_meta( $form_id, self::META_ENABLED, true ) ) {
			return array(
				'ok'     => false,
				'reason' => 'extend_end_disabled',
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

		$end_ts = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $form_id );
		if ( null === $end_ts ) {
			return array(
				'ok'     => false,
				'reason' => 'no_end_date',
			);
		}

		$now      = time();
		$start_ts = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $form_id );
		if ( null !== $start_ts && $start_ts > $now ) {
			return array(
				'ok'     => false,
				'reason' => 'not_started_yet',
			);
		}

		if ( $end_ts <= $now ) {
			return array(
				'ok'     => false,
				'reason' => 'already_ended',
			);
		}

		// Same-day guard — operator can only extend within the
		// configured `date_end`'s calendar day. Pre-empts edge cases
		// where date_end is in the future and the operator is acting
		// many days early.
		$today    = (string) current_time( 'Y-m-d' );
		$date_end = (string) ( $geofence['date_end'] ?? '' );
		if ( $date_end !== $today ) {
			return array(
				'ok'     => false,
				'reason' => 'not_today',
			);
		}

		// One-shot — the meta is set on successful postponement and
		// never auto-cleared. Admin can wipe it from the editor if they
		// want to allow another extension.
		if ( '' !== (string) get_post_meta( $form_id, self::META_POSTPONED_AT, true ) ) {
			return array(
				'ok'     => false,
				'reason' => 'already_postponed',
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Execute the postponement: rewrite `time_end` in `_ffc_geofence_config`,
	 * snapshot the original, flip the one-shot flag, purge caches, audit.
	 *
	 * Caller is responsible for nonce + capability via the AJAX handler;
	 * this re-runs `is_eligible()` defensively so a stale browser tab can't
	 * race against a concurrent admin edit.
	 *
	 * @param int                  $form_id      Form post id.
	 * @param string               $hash         The public hash (re-validated).
	 * @param string               $new_time_end New close as HH:MM (site tz).
	 * @param array<string, mixed> $audit_meta   { user_id, ip, ua }.
	 * @param string               $cpf_digits   Operator's CPF (digits only),
	 *                                           re-validated by the caller's
	 *                                           AJAX endpoint. Written to the
	 *                                           per-form audit ring buffer
	 *                                           alongside `action_postpone_close`
	 *                                           (#243 Sprint 6).
	 * @return array{ok: false, reason: string}|array{ok: true, original_end_iso: string, new_end_iso: string}
	 */
	public static function execute( int $form_id, string $hash, string $new_time_end, array $audit_meta = array(), string $cpf_digits = '' ): array {
		$eligibility = self::is_eligible( $form_id, $hash );
		if ( ! $eligibility['ok'] ) {
			return $eligibility;
		}

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}

		$original_time_end = (string) ( $geofence['time_end'] ?? '' );
		$date_end          = (string) ( $geofence['date_end'] ?? '' );

		// Validate the new clock value: HH:MM strictly, must extend the
		// current time_end forward, must be after `now`, and must not
		// roll past midnight on the same calendar day.
		$validation = self::validate_new_time_end( $new_time_end, $original_time_end, $date_end );
		if ( ! $validation['ok'] ) {
			return $validation;
		}

		$geofence['time_end'] = $new_time_end;
		update_post_meta( $form_id, '_ffc_geofence_config', $geofence );

		// Flip the one-shot flag + snapshot.
		update_post_meta( $form_id, self::META_POSTPONED_AT, time() );
		update_post_meta( $form_id, self::META_POSTPONED_FROM, $original_time_end );

		// Aggressive cache purge — the `ffc_form` CPT is `'public' => false`,
		// so per-post invalidation can't reach the page hosting the
		// shortcode. Mirrors what `EarlyOpenAction::execute()` does for
		// the same reason (see #233).
		\FreeFormCertificate\Submissions\FormCache::clear_form_cache( $form_id );
		\FreeFormCertificate\Submissions\FormCache::purge_external_caches( $form_id, 'end_postponed' );
		\FreeFormCertificate\Submissions\FormCache::purge_all_pages( $form_id, 'end_postponed' );

		// Audit log — best-effort, doesn't block the action on failure.
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'end_postponed',
				'warning',
				array(
					'form_id'           => $form_id,
					'date_end'          => $date_end,
					'original_time_end' => $original_time_end,
					'new_time_end'      => $new_time_end,
					'triggered_by_ip'   => (string) ( $audit_meta['ip'] ?? '' ),
					'triggered_by_ua'   => (string) ( $audit_meta['ua'] ?? '' ),
				),
				(int) ( $audit_meta['user_id'] ?? 0 )
			);
		}

		// Per-form audit ring buffer (#243 Sprint 6). See parallel comment
		// in EarlyOpenAction::execute() for the rationale; this one tags
		// `action_postpone_close` instead.
		$cpf_mode_for_audit = (string) get_post_meta( $form_id, '_ffc_csv_public_cpf_mode', true );
		$validator          = new CsvDownloadValidator();
		$validator->record_download_log_entry( $form_id, $cpf_mode_for_audit, $cpf_digits, 'action_postpone_close' );

		return array(
			'ok'               => true,
			'original_end_iso' => trim( $date_end . ' ' . $original_time_end ),
			'new_end_iso'      => trim( $date_end . ' ' . $new_time_end ),
		);
	}

	/**
	 * Validate the user-supplied new time_end. Returns the same envelope
	 * shape as is_eligible() — `ok: true` on success or
	 * `ok: false, reason: <tag>` for one of: `bad_time_format`,
	 * `not_extending` (new ≤ current time_end), `past_now` (new ≤ now),
	 * `out_of_day` (rolls past 23:59 same day).
	 *
	 * @param string $new_time_end     User-supplied HH:MM.
	 * @param string $current_time_end Stored time_end.
	 * @param string $date_end         Stored date_end (Y-m-d).
	 * @return array{ok: false, reason: string}|array{ok: true}
	 */
	private static function validate_new_time_end( string $new_time_end, string $current_time_end, string $date_end ): array {
		// Strict HH:MM with values in range. Reject anything else outright.
		if ( 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $new_time_end ) ) {
			return array(
				'ok'     => false,
				'reason' => 'bad_time_format',
			);
		}

		// Must move forward relative to the existing close time.
		if ( '' !== $current_time_end && strcmp( $new_time_end, $current_time_end ) <= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'not_extending',
			);
		}

		// Must be later than "now" in the site timezone — postponing to
		// a moment that's already in the past doesn't help anyone.
		$now_time = (string) current_time( 'H:i' );
		if ( '' !== $date_end && (string) current_time( 'Y-m-d' ) === $date_end && strcmp( $new_time_end, $now_time ) <= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'past_now',
			);
		}

		// Same-day enforcement — '24:00' isn't representable in our HH:MM
		// regex above, so passing the regex already guarantees the value
		// stays within today. This branch is left here as a defensive
		// rail for future relaxations.
		if ( strcmp( $new_time_end, '23:59' ) > 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'out_of_day',
			);
		}

		return array( 'ok' => true );
	}
}
