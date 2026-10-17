<?php
/**
 * PiiAccessPolicy
 *
 * One resolver for whether a user may see the decrypted personal data (CPF,
 * RF, email, phone, IP, a birth date) of a record, and whether that view is
 * audited. Every module with personal data asks it, handing in the module's
 * reveal capability (`ffc_view_<module>_pii`) and the record's owner.
 *
 * Tiers:
 *
 *   - `unmasked` — the data owner, looking at their own record. Plaintext,
 *     no audit row: nobody else's data is disclosed.
 *   - `reveal` — a masked placeholder; clicking "Reveal" (one value, or every
 *     masked value of a list page at once) decrypts and writes an audit row.
 *     Holders of the module's `_pii` capability: its Manager and
 *     Administrator roles, FFC Administrator and WordPress administrators.
 *   - `masked` — never shown; the reveal control is not rendered. Everyone
 *     else, FFC Read-only included.
 *
 * Until #1863 a third group -- WordPress administrators and each module's
 * `_admin` role -- resolved to `unmasked` and saw plaintext with no audit.
 * That tier is retired: staff always reveal, and every reveal leaves a row in
 * the activity log. `unmasked` now means only "this is your own data".
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
	 * The owner clause is checked first, so a staff member looking at their
	 * own record sees it as its owner does, unmasked.
	 *
	 * @since 6.40.0 The `$unmasked_role` argument is gone with the staff
	 *               unmasked tier (#1863).
	 * @param string   $pii_cap       The module reveal cap (e.g. `ffc_view_certificates_pii`).
	 * @param int|null $owner_user_id The record owner's user id (for the self-view clause), or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return string One of TIER_UNMASKED / TIER_REVEAL / TIER_MASKED.
	 */
	public static function resolve( string $pii_cap, ?int $owner_user_id = null, ?int $user_id = null ): string {
		$uid = $user_id ?? get_current_user_id();
		if ( $uid <= 0 ) {
			return self::TIER_MASKED;
		}

		// Owner clause — a user sees their own record as it is.
		if ( null !== $owner_user_id && $owner_user_id > 0 && $owner_user_id === $uid ) {
			return self::TIER_UNMASKED;
		}

		if ( user_can( $uid, 'manage_options' ) || user_can( $uid, $pii_cap ) ) {
			return self::TIER_REVEAL;
		}

		return self::TIER_MASKED;
	}

	/**
	 * Can this user see the value at all (their own, or through Reveal)?
	 *
	 * @param string   $pii_cap       The module reveal cap.
	 * @param int|null $owner_user_id The record owner's user id, or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return bool
	 */
	public static function can_reveal( string $pii_cap, ?int $owner_user_id = null, ?int $user_id = null ): bool {
		return self::TIER_MASKED !== self::resolve( $pii_cap, $owner_user_id, $user_id );
	}

	/**
	 * Does revealing fire an audit row? Every staff reveal does; only the
	 * owner reading their own record does not.
	 *
	 * @param string   $pii_cap       The module reveal cap.
	 * @param int|null $owner_user_id The record owner's user id, or null.
	 * @param int|null $user_id       User to check; defaults to the current user.
	 * @return bool
	 */
	public static function should_audit( string $pii_cap, ?int $owner_user_id = null, ?int $user_id = null ): bool {
		return self::TIER_REVEAL === self::resolve( $pii_cap, $owner_user_id, $user_id );
	}

	/**
	 * Whether the current user may reveal the module's personal data
	 * whoever owns it: the question a list page asks before drawing its
	 * "Reveal this page" button, and a CSV export before writing clear
	 * values.
	 *
	 * @param string   $pii_cap The module reveal cap.
	 * @param int|null $user_id User to check; defaults to the current user.
	 * @return bool
	 */
	public static function can_reveal_any( string $pii_cap, ?int $user_id = null ): bool {
		return self::TIER_REVEAL === self::resolve( $pii_cap, null, $user_id );
	}
}
