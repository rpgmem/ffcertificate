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

use FreeFormCertificate\Core\Encryption;

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
	 * Compute the candidate-level `pcd_hash`, the storage PCD had before it
	 * moved to the classification (#2245). Read only by the migration that
	 * copies those flags, through {@see self::verify()}; nothing writes it any
	 * more.
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
	 * Context of the per-classification hash (#2245).
	 */
	private const ROW_CONTEXT = 'recruitment_pcd';

	/**
	 * The `pcd_hash` of one notice classification (#2245).
	 *
	 * PCD is recorded per notice, because the same person can be PCD in one
	 * notice and general in another. The domain names the notice, the
	 * candidate and the adjutancy and never the row id, so the batched import
	 * can compute it before the INSERT and "Copy preliminary as definitive"
	 * can copy it unchanged. Every row's value is distinct, so equal hashes
	 * never group the PCD rows; only the server key tells them apart.
	 *
	 * The key is the plugin's own hash salt ({@see Encryption::keyed_hash()}),
	 * so rotating the WordPress salts no longer turns PCD into general once
	 * `FFC_HASH_SALT` is defined.
	 *
	 * @param int  $notice_id    Notice ID.
	 * @param int  $candidate_id Candidate ID.
	 * @param int  $adjutancy_id Adjutancy ID.
	 * @param bool $is_pcd       Whether the row is PCD.
	 * @return string 64 hex characters.
	 */
	public static function compute_for_row( int $notice_id, int $candidate_id, int $adjutancy_id, bool $is_pcd ): string {
		return Encryption::keyed_hash( self::row_domain( $notice_id, $candidate_id, $adjutancy_id, $is_pcd ), self::ROW_CONTEXT );
	}

	/**
	 * Read a classification's PCD flag.
	 *
	 * Checked under the active salt, then under the WordPress-derived one, the
	 * fallback a value hashed before `FFC_HASH_SALT` was defined needs; the
	 * key-rotation migration rewrites those. A missing or unrecognised hash
	 * reads as general, which is what the screens have always shown for it.
	 *
	 * @param object $row Classification row (`notice_id`, `candidate_id`, `adjutancy_id`, `pcd_hash`).
	 * @return bool
	 */
	public static function row_is_pcd( object $row ): bool {
		return true === self::read_row( $row )['pcd'];
	}

	/**
	 * A classification's PCD flag and which salt it was written with.
	 *
	 * @param object $row Classification row.
	 * @return array{pcd: bool|null, legacy: bool} `pcd` is null when neither salt recognises the hash.
	 */
	public static function read_row( object $row ): array {
		$stored = isset( $row->pcd_hash ) ? (string) $row->pcd_hash : '';
		if ( '' === $stored ) {
			return array(
				'pcd'    => null,
				'legacy' => false,
			);
		}
		$notice    = (int) ( $row->notice_id ?? 0 );
		$candidate = (int) ( $row->candidate_id ?? 0 );
		$adjutancy = (int) ( $row->adjutancy_id ?? 0 );
		foreach ( array( true, false ) as $flag ) {
			if ( hash_equals( self::compute_for_row( $notice, $candidate, $adjutancy, $flag ), $stored ) ) {
				return array(
					'pcd'    => $flag,
					'legacy' => false,
				);
			}
		}
		foreach ( array( true, false ) as $flag ) {
			$legacy = Encryption::legacy_keyed_hash( self::row_domain( $notice, $candidate, $adjutancy, $flag ), self::ROW_CONTEXT );
			if ( null !== $legacy && hash_equals( $legacy, $stored ) ) {
				return array(
					'pcd'    => $flag,
					'legacy' => true,
				);
			}
		}
		return array(
			'pcd'    => null,
			'legacy' => false,
		);
	}

	/**
	 * The value a classification hash is computed over.
	 *
	 * @param int  $notice_id    Notice ID.
	 * @param int  $candidate_id Candidate ID.
	 * @param int  $adjutancy_id Adjutancy ID.
	 * @param bool $is_pcd       Whether the row is PCD.
	 * @return string
	 */
	private static function row_domain( int $notice_id, int $candidate_id, int $adjutancy_id, bool $is_pcd ): string {
		return ( $is_pcd ? self::DOMAIN_PCD : self::DOMAIN_NOT_PCD ) . '|' . $notice_id . '|' . $candidate_id . '|' . $adjutancy_id;
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
