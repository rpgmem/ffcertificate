<?php
/**
 * Recruitment PCD Hasher
 *
 * Computes the `pcd_hash` value stored on `ffc_recruitment_candidate`. The
 * hash is verifiable for both PCD and non-PCD candidates while remaining
 * non-enumerable on column scan: an attacker dumping the column sees only
 * opaque hashes, but anyone with the candidate's row (i.e. with knowledge
 * of `candidate_id` and the secret salt) can recompute both domains and
 * confirm the PCD value.
 *
 * Formula (per §3.4 of the implementation plan):
 *
 *   pcd_hash = HMAC-SHA256(secret, ("1"|"0") || candidate_id)
 *
 * Both PCD=true and PCD=false produce a valid hash. The column is NOT NULL
 * — every candidate carries either the PCD or non-PCD hash, never null.
 *
 * The HMAC key is derived from `wp_salt('auth')` so the hash is stable
 * across requests within a site but differs across sites.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helper that computes the candidate `pcd_hash` value.
 *
 * No dependencies beyond `wp_salt()`. Unit tests can stub `wp_salt` via
 * Brain\Monkey to assert determinism.
 */
final class RecruitmentPcdHasher {

	/**
	 * Domain prefix prepended to the candidate ID for PCD candidates.
	 */
	private const DOMAIN_PCD = '1';

	/**
	 * Domain prefix prepended to the candidate ID for non-PCD candidates.
	 */
	private const DOMAIN_NOT_PCD = '0';

	/**
	 * Compute the `pcd_hash` for a given candidate.
	 *
	 * @param int  $candidate_id The candidate's row ID (auto-incremented at INSERT).
	 * @param bool $is_pcd       Whether this candidate is registered as PCD.
	 * @return string Hex-encoded SHA-256 HMAC (64 chars).
	 */
	public static function compute( int $candidate_id, bool $is_pcd ): string {
		$domain = $is_pcd ? self::DOMAIN_PCD : self::DOMAIN_NOT_PCD;
		$key    = self::secret_key();

		return hash_hmac( 'sha256', $domain . $candidate_id, $key );
	}

	/**
	 * Verify whether a stored hash matches a candidate's PCD claim.
	 *
	 * Used by the recruitment admin UI / REST handlers to display the PCD
	 * badge without persisting plaintext anywhere. Both domains are tried
	 * against the stored hash; if neither matches, the record has been
	 * tampered with (or the salt rotated) and the caller should fall back
	 * to "unknown".
	 *
	 * @param string $stored_hash  The `pcd_hash` column value.
	 * @param int    $candidate_id The candidate's row ID.
	 * @return bool|null True when PCD; false when not PCD; null when neither
	 *                   domain matches (anomaly).
	 */
	public static function verify( string $stored_hash, int $candidate_id ): ?bool {
		if ( '' === $stored_hash ) {
			return null;
		}

		$pcd_hash     = self::compute( $candidate_id, true );
		$not_pcd_hash = self::compute( $candidate_id, false );

		if ( hash_equals( $pcd_hash, $stored_hash ) ) {
			return true;
		}
		if ( hash_equals( $not_pcd_hash, $stored_hash ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Derive the HMAC secret key from the WP installation's auth salt.
	 *
	 * `wp_salt('auth')` is stable per site (rotates only when admin rotates
	 * keys) and unguessable from outside the server, which is exactly the
	 * property we need: on-server code can verify, off-server attackers
	 * cannot enumerate the column.
	 *
	 * @return string
	 */
	private static function secret_key(): string {
		// Suffix scopes the key to this module so a leak of one HMAC value
		// cannot be replayed against unrelated `hash_hmac(_, _, wp_salt())`
		// uses elsewhere in the codebase.
		return wp_salt( 'auth' ) . '|ffc_recruitment_pcd';
	}
}
