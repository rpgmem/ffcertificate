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

	public const TIER_UNMASKED = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_UNMASKED;
	public const TIER_REVEAL   = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_REVEAL;
	public const TIER_MASKED   = \FreeFormCertificate\Core\PiiAccessPolicy::TIER_MASKED;

	/**
	 * The recruitment reveal cap handed to the shared policy.
	 */
	private const PII_CAP = 'ffc_view_recruitment_pii';

	/**
	 * The recruitment unmasked-tier role handed to the shared policy.
	 */
	private const UNMASKED_ROLE = 'ffc_recruitment_admin';

	/**
	 * Resolve the access tier for the given user against the given candidate.
	 *
	 * Thin recruitment adapter over {@see \FreeFormCertificate\Core\PiiAccessPolicy}
	 * (the shared 3-tier engine, #739 §3.3): it pins the recruitment reveal cap
	 * + unmasked role and derives the owner from the candidate row, then
	 * delegates the tiering. The candidate row is the source of truth for the
	 * owner check (its `user_id` column); pass null when the candidate isn't
	 * loaded yet — the owner clause then short-circuits and the cap-only path
	 * decides.
	 *
	 * @param object|null $candidate Candidate row (with at least `user_id`), or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return string One of TIER_UNMASKED / TIER_REVEAL / TIER_MASKED.
	 */
	public static function resolve( ?object $candidate, ?int $user_id = null ): string {
		return \FreeFormCertificate\Core\PiiAccessPolicy::resolve(
			self::PII_CAP,
			self::UNMASKED_ROLE,
			self::owner_of( $candidate ),
			$user_id
		);
	}

	/**
	 * Convenience predicate: can this user reveal at all (unmasked OR reveal)?
	 *
	 * @param object|null $candidate Candidate row (with at least `user_id`), or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return bool
	 */
	public static function can_reveal( ?object $candidate, ?int $user_id = null ): bool {
		return \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
			self::PII_CAP,
			self::UNMASKED_ROLE,
			self::owner_of( $candidate ),
			$user_id
		);
	}

	/**
	 * Convenience predicate: does revealing fire an audit row?
	 *
	 * `unmasked` tier skips audit (high-trust roles, no noise); `reveal` tier
	 * audits. The dedup + `audit_pii_reveals` setting are enforced caller-side
	 * (the reveal handler), not here.
	 *
	 * @param object|null $candidate Candidate row, or null.
	 * @param int|null    $user_id   User to check; defaults to current user.
	 * @return bool
	 */
	public static function should_audit( ?object $candidate, ?int $user_id = null ): bool {
		return \FreeFormCertificate\Core\PiiAccessPolicy::should_audit(
			self::PII_CAP,
			self::UNMASKED_ROLE,
			self::owner_of( $candidate ),
			$user_id
		);
	}

	/**
	 * Extract the owner user id from a candidate row (null when absent),
	 * for the shared policy's self-view clause.
	 *
	 * @param object|null $candidate Candidate row.
	 * @return int|null
	 */
	private static function owner_of( ?object $candidate ): ?int {
		return ( $candidate && isset( $candidate->user_id ) ) ? (int) $candidate->user_id : null;
	}
}
