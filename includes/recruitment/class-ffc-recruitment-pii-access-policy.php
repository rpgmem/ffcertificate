<?php
/**
 * Recruitment PII Access Policy.
 *
 * Centralizes the decision of whether a given user can see the decrypted
 * CPF / RF / email of a given candidate, and whether that view should
 * trigger an audit-log entry. Used by the candidate detail screen and by
 * the REST endpoint that powers the on-click "Reveal" button (issue
 * #330).
 *
 * Three-tier policy:
 *
 *   - `unmasked` — the operator sees the plaintext value immediately, no
 *     toggle, no audit row written. Reserved for the highest-trust roles
 *     where every action is already assumed to be operator-driven and
 *     the noise of a per-field log would obscure real anomalies.
 *
 *   - `reveal` — the operator sees a masked placeholder; clicking
 *     "Reveal" hits the REST endpoint, which decrypts and returns the
 *     value, and writes an audit entry (subject to dedup + the
 *     `audit_pii_reveals` setting). This is the everyday path for
 *     auditors / managers / the candidate themselves when they reach
 *     the screen.
 *
 *   - `masked` — the operator never sees the value, even by clicking
 *     anything. The button is not rendered.
 *
 * Resolution rules:
 *
 *   1. WordPress super-admins (`manage_options`) and members of the
 *      `ffc_recruitment_admin` role → `unmasked`.
 *   2. Users holding `ffc_view_recruitment_pii` (granular cap; assigned
 *      to managers and auditors by default) → `reveal`.
 *   3. The candidate themselves (when the row's `user_id` matches the
 *      requester) → `reveal`. Owner of the data always has the right
 *      to see their own information; the audit row marks the access.
 *   4. Everyone else → `masked`.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the PII access tier for a (user, candidate) pair.
 */
final class RecruitmentPiiAccessPolicy {

	public const TIER_UNMASKED = 'unmasked';
	public const TIER_REVEAL   = 'reveal';
	public const TIER_MASKED   = 'masked';

	/**
	 * Resolve the access tier for the given user against the given candidate.
	 *
	 * The candidate row is the source of truth for the owner check (its
	 * `user_id` column). Pass null when the candidate isn't loaded yet —
	 * the owner clause then short-circuits to false and the cap-only path
	 * decides.
	 *
	 * @param object|null $candidate Candidate row (with at least `user_id`), or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return string One of TIER_UNMASKED / TIER_REVEAL / TIER_MASKED.
	 */
	public static function resolve( ?object $candidate, ?int $user_id = null ): string {
		$uid = $user_id ?? get_current_user_id();
		if ( $uid <= 0 ) {
			return self::TIER_MASKED;
		}

		if ( user_can( $uid, 'manage_options' ) ) {
			return self::TIER_UNMASKED;
		}

		// Role check — `ffc_recruitment_admin` is the highest-trust
		// recruitment role and shouldn't be slowed down by per-field
		// clicks. Caps alone don't tell us the role, so we hit get_user().
		$user = get_user_by( 'id', $uid );
		if ( $user && in_array( 'ffc_recruitment_admin', (array) $user->roles, true ) ) {
			return self::TIER_UNMASKED;
		}

		if ( user_can( $uid, 'ffc_view_recruitment_pii' ) ) {
			return self::TIER_REVEAL;
		}

		// Owner clause — the candidate themselves sees their own data.
		// Audit still fires (so the trail captures who-saw-what across
		// the lifetime of the row).
		if ( $candidate && isset( $candidate->user_id ) && (int) $candidate->user_id === $uid ) {
			return self::TIER_REVEAL;
		}

		return self::TIER_MASKED;
	}

	/**
	 * Convenience predicate: can this user reveal at all (unmasked OR reveal)?
	 *
	 * @param object|null $candidate Candidate row (with at least `user_id`), or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return bool
	 */
	public static function can_reveal( ?object $candidate, ?int $user_id = null ): bool {
		$tier = self::resolve( $candidate, $user_id );
		return self::TIER_MASKED !== $tier;
	}

	/**
	 * Convenience predicate: does revealing fire an audit row?
	 *
	 * `unmasked` tier explicitly skips audit (high-trust roles, no noise).
	 * `reveal` tier always audits (subject to dedup + setting toggle).
	 *
	 * @param object|null $candidate Candidate row, or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return bool
	 */
	public static function should_audit( ?object $candidate, ?int $user_id = null ): bool {
		return self::TIER_REVEAL === self::resolve( $candidate, $user_id );
	}
}
