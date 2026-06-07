<?php
/**
 * ScheduleExceptionAction — sibling to EarlyOpenAction / ExtendEndAction
 * but for per-submission schedule overrides (#366). Lets an
 * authenticated operator stage a one-use exception that overrides the
 * `{schedule}` placeholder on a single subsequent submission.
 *
 * Design constraints baked in:
 *   - Per-form opt-IN — the operator's button is gated by the
 *     `schedule_exception_enabled` sub-key of `_ffc_geofence_config`,
 *     which defaults to '0'. Admin must turn it on consciously.
 *   - Strictly within the effective baseline window — both override
 *     ends must sit inside the form's effective `[time_start, time_end]`
 *     (where `time_end` reflects any prior Postpone close).
 *   - Override must differ from the baseline — a "no-op exception" is
 *     a misclick, not a use case worth recording.
 *   - Form must already be inside its open window — the participant
 *     submission only makes sense while the form is reachable.
 *   - Token + cookie are issued by {@see ScheduleExceptionSession::create()};
 *     this class only assembles the call. Sprint 5 reads the cookie
 *     on render; Sprint 6 verifies the in-form token on submit.
 *
 * @package FreeFormCertificate
 * @since   6.7.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service: stage a per-submission schedule override.
 */
final class ScheduleExceptionAction {

	/**
	 * Check whether an operator presenting the given hash is allowed to
	 * create a schedule exception for this form RIGHT NOW.
	 *
	 * @param int    $form_id Form post id.
	 * @param string $hash    Plaintext hash supplied by the public page.
	 * @return array{ok: false, reason: string}|array{ok: true} Stable reason
	 *           tags on failure: `unknown_form`, `csv_disabled`,
	 *           `bad_hash`, `schedule_exception_disabled`,
	 *           `datetime_disabled`, `no_window`, `not_started_yet`,
	 *           `already_ended`.
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

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}

		// Per-form opt-IN. Empty / '0' reads as off so legacy forms see
		// zero behaviour change until admin flips the Sprint-2 toggle.
		if ( '1' !== (string) ( $geofence['schedule_exception_enabled'] ?? '' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'schedule_exception_disabled',
			);
		}

		// Schedule exception piggybacks on the datetime window — the
		// participant must still be inside the form's open period for
		// the subsequent submission to land. The form-level baseline
		// (`class_time_start` / `class_time_end`) is the SOURCE for the
		// `{schedule}` placeholder, not the gate; the gate is
		// `time_start` / `time_end` like every other action.
		if ( '1' !== (string) ( $geofence['datetime_enabled'] ?? '' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'datetime_disabled',
			);
		}

		$start_ts = \FreeFormCertificate\Security\Geofence::get_form_start_timestamp( $form_id );
		$end_ts   = \FreeFormCertificate\Security\Geofence::get_form_end_timestamp( $form_id );
		if ( null === $start_ts || null === $end_ts ) {
			return array(
				'ok'     => false,
				'reason' => 'no_window',
			);
		}

		$now = time();
		if ( $start_ts > $now ) {
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

		return array( 'ok' => true );
	}

	/**
	 * Stage a schedule exception. Validates the override against the
	 * effective baseline, computes the operator CPF hash, hands off to
	 * ScheduleExceptionSession::create() to set the cookie + return
	 * the signed token.
	 *
	 * @param int    $form_id        Form post id.
	 * @param string $hash           Public hash (re-validated).
	 * @param string $start_override New start HH:MM (or '' to leave at baseline).
	 * @param string $end_override   New end HH:MM (or '' to leave at baseline).
	 * @param string $cpf_digits     Operator's CPF (digits only), already
	 *                               validated by the AJAX caller. Hashed
	 *                               into the token; never stored plaintext.
	 * @return array{ok: false, reason: string}|array{ok: true, token: string, form_url: string}
	 */
	public static function execute(
		int $form_id,
		string $hash,
		string $start_override,
		string $end_override,
		string $cpf_digits
	): array {
		$eligibility = self::is_eligible( $form_id, $hash );
		if ( ! $eligibility['ok'] ) {
			return $eligibility;
		}

		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}

		$baseline_start = self::resolve_baseline( $geofence, 'start' );
		$baseline_end   = self::resolve_baseline( $geofence, 'end' );

		$validation = self::validate_overrides(
			$start_override,
			$end_override,
			$baseline_start,
			$baseline_end,
			(string) ( $geofence['time_start'] ?? '' ),
			(string) ( $geofence['time_end'] ?? '' )
		);
		if ( ! $validation['ok'] ) {
			return $validation;
		}

		// Empty override means "use the baseline" — collapsing to null
		// here keeps the in-token payload tight and lets Sprint 6 do a
		// clean `null === $override` check before writing the column.
		$start_value = '' === $start_override ? null : $start_override;
		$end_value   = '' === $end_override ? null : $end_override;

		// Twin representations of the operator CPF for the Sprint 6 audit
		// trail: hash for cryptographic identity, masked for human-readable
		// rendering. Both are derived from the same plaintext; neither
		// reveals the full document number to readers of the audit log.
		$operator_cpf_hash   = '' === $cpf_digits ? '' : hash( 'sha256', $cpf_digits );
		$operator_cpf_masked = '' === $cpf_digits ? '' : \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $cpf_digits );

		$token = ScheduleExceptionSession::create(
			$form_id,
			$start_value,
			$end_value,
			$operator_cpf_hash,
			$operator_cpf_masked,
			$baseline_start,
			$baseline_end
		);

		$form_url = self::resolve_form_url( $form_id );

		return array(
			'ok'       => true,
			'token'    => $token,
			'form_url' => $form_url,
		);
	}

	/**
	 * Resolve the baseline value used to populate the modal's
	 * default range — class_time_* wins, geofence time_* falls back.
	 *
	 * @param array<string, mixed> $geofence Geofence config array.
	 * @param string               $end      'start' or 'end'.
	 */
	private static function resolve_baseline( array $geofence, string $end ): string {
		$class = (string) ( $geofence[ 'class_time_' . $end ] ?? '' );
		if ( '' !== $class ) {
			return $class;
		}
		return (string) ( $geofence[ 'time_' . $end ] ?? '' );
	}

	/**
	 * Validate the override pair against the effective baseline. Reason
	 * tags: `bad_time_format`, `range_inverted`, `out_of_window`,
	 * `no_change`.
	 *
	 * @param string $start_override   Posted start (may be '').
	 * @param string $end_override     Posted end (may be '').
	 * @param string $baseline_start   Effective baseline start (class_time_* or time_*).
	 * @param string $baseline_end     Effective baseline end.
	 * @param string $window_start     Effective form window start (`time_start`).
	 * @param string $window_end       Effective form window end (`time_end`, post-postpone).
	 * @return array{ok: false, reason: string}|array{ok: true}
	 */
	private static function validate_overrides(
		string $start_override,
		string $end_override,
		string $baseline_start,
		string $baseline_end,
		string $window_start,
		string $window_end
	): array {
		// Empty strings are allowed (means "no override on that end").
		// Non-empty must be strict HH:MM.
		foreach ( array( $start_override, $end_override ) as $candidate ) {
			if ( '' !== $candidate && 1 !== preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $candidate ) ) {
				return array(
					'ok'     => false,
					'reason' => 'bad_time_format',
				);
			}
		}

		// Resolve effective start/end after applying overrides.
		$effective_start = '' === $start_override ? $baseline_start : $start_override;
		$effective_end   = '' === $end_override ? $baseline_end : $end_override;

		// Both sides empty AND identical to baseline → spurious submit.
		if ( $effective_start === $baseline_start && $effective_end === $baseline_end ) {
			return array(
				'ok'     => false,
				'reason' => 'no_change',
			);
		}

		// Start strictly before end. Lexicographic compare works for
		// the HH:MM shape we just validated.
		if ( '' !== $effective_start && '' !== $effective_end && strcmp( $effective_start, $effective_end ) >= 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'range_inverted',
			);
		}

		// Effective range must fit inside the form window — but only the
		// end(s) the operator actually OVERRODE are constrained. A side left
		// at baseline (empty override) is the admin's reference schedule,
		// which may legitimately sit outside the override window (e.g. a
		// 00:00–23:59 baseline with a 14:30–23:00 window). The "End now
		// (start stays at baseline)" mode depends on this: it changes only
		// the end, so the unchanged baseline start must not be window-checked.
		// We compare only when both the window bound and the override are
		// present.
		if ( '' !== $window_start && '' !== $start_override && strcmp( $start_override, $window_start ) < 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'out_of_window',
			);
		}
		if ( '' !== $window_end && '' !== $end_override && strcmp( $end_override, $window_end ) > 0 ) {
			return array(
				'ok'     => false,
				'reason' => 'out_of_window',
			);
		}

		return array( 'ok' => true );
	}

	/**
	 * Build the URL the operator will hand to the participant — the page
	 * that embeds this form via the `[ffc_form id=N]` shortcode.
	 *
	 * Resolution order:
	 *   1. The `ffc_schedule_exception_form_url` filter, if a host site
	 *      wants to hard-wire the landing (highest priority, unchanged
	 *      contract).
	 *   2. Auto-discovery: the most recently published page/post that
	 *      contains `[ffc_form id="N"`. This is the Sprint 5 lookup that
	 *      #366 deferred — there's no canonical "form page" because the
	 *      form is a shortcode, not a CPT, so we search post_content the
	 *      same way {@see \FreeFormCertificate\Submissions\FormCache} does
	 *      for cache purging. When a form is embedded on more than one
	 *      page we return the newest embed (`orderby=date DESC`), on the
	 *      assumption that the latest page is the live landing.
	 *   3. `home_url()` as a last-resort fallback when the form isn't
	 *      embedded anywhere we can find — keeps the contract simple
	 *      (always returns a URL).
	 *
	 * Always returns a URL (home as the last-resort fallback). For the
	 * operator hand-off, where a URL must always exist.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function resolve_form_url( int $form_id ): string {
		$url = self::find_form_page_url( $form_id );
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Locate the published page/post that embeds this form via the
	 * `[ffc_form id="N"` shortcode, or '' when none is found.
	 *
	 * Unlike {@see resolve_form_url()}, this does NOT fall back to the site
	 * home — a '' return is a meaningful "the form isn't embedded anywhere"
	 * signal, which the info-screen builder uses to decide whether to show
	 * the operator a clickable "open participant form" link at all (#366
	 * Sprint 5). The create endpoint keeps using resolve_form_url(), which
	 * needs a guaranteed URL for the new-tab hand-off.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function find_form_page_url( int $form_id ): string {
		$candidate = (string) apply_filters( 'ffc_schedule_exception_form_url', '', $form_id );
		if ( '' !== $candidate ) {
			return $candidate;
		}

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				's'              => '[ffc_form id="' . $form_id . '"',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $pages ) ) {
			$url = get_permalink( (int) $pages[0] );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return '';
	}
}
