<?php
/**
 * PiiAccessPolicy
 *
 * Generic 3-tier resolver for whether a user may see the decrypted PII
 * (CPF / RF / email) of a record, and whether that view is audited. The
 * certificate-submission and appointment surfaces use it (#739 §3.3); it
 * mirrors the recruitment module's dedicated `RecruitmentPiiAccessPolicy`,
 * generalized so the domain's reveal cap + unmasked role are passed in rather
 * than hard-coded.
 *
 * Tiers:
 *
 *   - `unmasked` — plaintext immediately, no toggle, no audit row. Reserved
 *     for the domain's highest-trust `_admin` role (and WP super-admins).
 *   - `reveal` — a masked placeholder; clicking "Reveal" decrypts and writes
 *     an audit row. The everyday path for the domain's manager (holds the
 *     `_pii` cap) and for the data owner viewing their own record.
 *   - `masked` — never shown; the reveal control is not rendered.
 *
 * @package FreeFormCertificate\Core
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the PII access tier for a (user, record) pair.
 */
final class PiiAccessPolicy {

	public const TIER_UNMASKED = 'unmasked';
	public const TIER_REVEAL   = 'reveal';
	public const TIER_MASKED   = 'masked';

	/**
	 * Resolve the access tier for a user against a record.
	 *
	 * @param string   $pii_cap       The domain reveal cap (e.g. `ffc_view_certificates_pii`).
	 * @param string   $unmasked_role The domain admin role slug granted the unmasked tier.
	 * @param int|null $owner_user_id The record owner's user id (for the self-view clause), or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return string One of TIER_UNMASKED / TIER_REVEAL / TIER_MASKED.
	 */
	public static function resolve( string $pii_cap, string $unmasked_role, ?int $owner_user_id = null, ?int $user_id = null ): string {
		$uid = $user_id ?? get_current_user_id();
		if ( $uid <= 0 ) {
			return self::TIER_MASKED;
		}

		if ( user_can( $uid, 'manage_options' ) ) {
			return self::TIER_UNMASKED;
		}

		// The domain `_admin` role is unmasked — caps alone don't reveal the
		// role, so read it off the user.
		$user = get_user_by( 'id', $uid );
		if ( $user && in_array( $unmasked_role, (array) $user->roles, true ) ) {
			return self::TIER_UNMASKED;
		}

		if ( user_can( $uid, $pii_cap ) ) {
			return self::TIER_REVEAL;
		}

		// Owner clause — a user always sees their own record (audited).
		if ( null !== $owner_user_id && $owner_user_id > 0 && $owner_user_id === $uid ) {
			return self::TIER_REVEAL;
		}

		return self::TIER_MASKED;
	}

	/**
	 * Can this user reveal at all (unmasked OR reveal)?
	 *
	 * @param string   $pii_cap       The domain reveal cap.
	 * @param string   $unmasked_role The domain admin role slug.
	 * @param int|null $owner_user_id The record owner's user id, or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return bool
	 */
	public static function can_reveal( string $pii_cap, string $unmasked_role, ?int $owner_user_id = null, ?int $user_id = null ): bool {
		return self::TIER_MASKED !== self::resolve( $pii_cap, $unmasked_role, $owner_user_id, $user_id );
	}

	/**
	 * Does revealing fire an audit row? Only the `reveal` tier audits;
	 * `unmasked` skips it (high-trust role, no per-field log noise).
	 *
	 * @param string   $pii_cap       The domain reveal cap.
	 * @param string   $unmasked_role The domain admin role slug.
	 * @param int|null $owner_user_id The record owner's user id, or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return bool
	 */
	public static function should_audit( string $pii_cap, string $unmasked_role, ?int $owner_user_id = null, ?int $user_id = null ): bool {
		return self::TIER_REVEAL === self::resolve( $pii_cap, $unmasked_role, $owner_user_id, $user_id );
	}
}
