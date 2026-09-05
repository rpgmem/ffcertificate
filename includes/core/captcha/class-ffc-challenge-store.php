<?php
/**
 * ChallengeStore
 *
 * One-time-use ledger for captcha challenges. A signed challenge proves it
 * was issued by this site and has not expired; this class is what stops the
 * same proof being presented twice.
 *
 * Issuing is deliberately stateless — nothing is written when a challenge is
 * rendered, only when one is redeemed — so the cost falls on submissions
 * rather than on every page view of a cached form.
 *
 * Entries are transients, which matters for two repo-wide gates: the
 * `_transient_ffc_%` sweep in `uninstall.php` already removes them, and the
 * fresh-install manifest reconciliation never sees them, because a transient
 * is stored as `_transient_ffc_*` and the manifest matches `ffc_%`.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redemption ledger for captcha challenges.
 */
class ChallengeStore {

	/**
	 * Transient key prefix.
	 *
	 * Deliberately starts with `ffc_` (no leading underscore) so the stored
	 * option name is `_transient_ffc_…`, which the uninstall sweep matches.
	 *
	 * @var string
	 */
	private const PREFIX = 'ffc_captcha_used_';

	/**
	 * Redeem a challenge, refusing a second presentation of the same proof.
	 *
	 * Concurrency note: WordPress offers no atomic set-if-absent for
	 * transients, so two requests presenting the same proof in the same
	 * instant can both be admitted. The window is milliseconds and the prize
	 * is one extra submission that the per-IP and per-document rate limits
	 * still bound, whereas an `add_option()` ledger — the only atomic
	 * alternative — would write `ffc_*` options that the fresh-install gate
	 * would then report as undeclared.
	 *
	 * @param string $proof Signature identifying the challenge.
	 * @param int    $ttl   Seconds to remember the redemption for.
	 * @return bool True when this is the first redemption, false on replay.
	 */
	public static function redeem( string $proof, int $ttl ): bool {
		if ( '' === $proof ) {
			return false;
		}

		$key = self::key( $proof );

		if ( false !== \get_transient( $key ) ) {
			return false;
		}

		\set_transient( $key, 1, max( 60, $ttl ) );

		return true;
	}

	/**
	 * Build the transient key for a proof.
	 *
	 * The proof is hashed rather than embedded: it keeps the key inside the
	 * option-name length limit regardless of the signature format a future
	 * provider uses.
	 *
	 * @param string $proof Signature identifying the challenge.
	 * @return string Transient key.
	 */
	private static function key( string $proof ): string {
		return self::PREFIX . substr( hash( 'sha256', $proof ), 0, 32 );
	}
}
