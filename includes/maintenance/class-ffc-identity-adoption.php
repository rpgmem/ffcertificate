<?php
/**
 * Orphan adoption
 *
 * Gives an orphaned record an account: the one it belongs to, or a new one
 * (#1397 sprint 6).
 *
 * IT DOES NOT CREATE A USER, AND THAT IS THE WHOLE DESIGN.
 *
 * `UserCreator::get_or_create_user_dual()` is the plugin's one path from an
 * identity to an account, and `IdentityConvergenceGuardTest` exists to keep it
 * that way — a second creation path is how two accounts come to exist for one
 * person, which is the population this whole queue is cleaning up. So this
 * class validates, hashes and hands over. Everything that follows is already
 * that method's: the two matching branches before creation, the capability
 * grant, the adoption of every unlinked record carrying those hashes, the
 * `ffc_adopt_orphaned_identity_records` action for candidacies, and the write
 * into the identity index.
 *
 * IT HANDS OVER THROUGH FILTERS, NOT BY NAMING THE CLASS.
 *
 * Naming `UserCreator` from here adds a `Maintenance > UserDashboard` edge
 * the module baseline does not carry, and `ModuleBoundaryTest` said so the
 * moment this was written that way. `IdentitySplit` met the same wall and
 * answered it with `ffc_create_identity_account`; these two are that shape
 * again — a FILTER rather than an action, because unlike
 * `ffc_grant_certificate_capabilities` this needs the answer back.
 *
 * WHICH ALSO MEANS "CREATE" IS THE WRONG WORD FOR WHAT THE OPERATOR ASKS FOR.
 *
 * Supplying CPF, RF and e-mail asks for *the account for this person*. If one
 * already answers to those identifiers it is used, and the orphan is adopted
 * into it — which is the right outcome and not a failed creation.
 *
 * ALL THREE ARE REQUIRED, DELIBERATELY.
 *
 * `get_or_create_user_dual()` accepts any one of them. This refuses fewer than
 * three because an account opened from one identifier is an account the
 * resolver will fail to match on the next submission that carries a different
 * one — which manufactures the duplicate this screen exists to resolve. The
 * moment of opening an account is the one moment all three are cheap to
 * collect.
 *
 * @package FreeFormCertificate\Maintenance
 * @since   6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Open (or find) the account an orphaned record belongs to.
 */
class IdentityAdoption {

	/**
	 * How many characters of a hash reach the log.
	 *
	 * @var int
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Adopt the records carrying these identifiers into an account.
	 *
	 * @param string $cpf   The CPF the operator supplied.
	 * @param string $rf    The RF the operator supplied.
	 * @param string $email The address the operator supplied.
	 * @param int    $actor Who decided it, for the log.
	 * @return array{account: int, created: bool}|WP_Error
	 */
	public function adopt( string $cpf, string $rf, string $email, int $actor = 0 ): array|WP_Error {
		$cpf   = SensitiveFieldRegistry::normalize( 'cpf', $cpf );
		$rf    = SensitiveFieldRegistry::normalize( 'rf', $rf );
		$email = SensitiveFieldRegistry::normalize( 'email', $email );

		if ( '' === $cpf || '' === $rf || '' === $email ) {
			return new WP_Error(
				'ffc_identity_adopt_incomplete',
				__( 'Opening an account needs all three: CPF, RF and an e-mail address. An account opened from one identifier is one the resolver will fail to match on the next record carrying another.', 'ffcertificate' )
			);
		}

		// A CPF CAN BE CHECKED HERE; AN ADDRESS CAN ONLY BE FORMAT-CHECKED.
		//
		// Different confidences, so they are refused with different reasons
		// rather than one "invalid input". The RF's check digit is checked for
		// the same reason the repair checks it: a number that fails it was
		// never issued to anybody.
		if ( ! IdentityRepair::well_formed( 'cpf', $cpf ) ) {
			return new WP_Error(
				'ffc_identity_adopt_bad_cpf',
				__( 'That CPF does not satisfy its own check digits. Confirm it before opening an account on it.', 'ffcertificate' )
			);
		}

		// `IdentityRepair::well_formed()` rather than
		// `DocumentFormatter::validate_rf()`: the latter enforces the check
		// digit only behind an opt-in, for reasons that are about refusing a
		// REGISTRATION. An account opened from a value an operator confirmed
		// is judged the way a correction is, through the same rule.
		if ( ! IdentityRepair::well_formed( 'rf', $rf ) ) {
			return new WP_Error(
				'ffc_identity_adopt_bad_rf',
				__( 'That RF does not satisfy its own check digit. Confirm it with HR before opening an account on it.', 'ffcertificate' )
			);
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'ffc_identity_adopt_bad_email',
				__( 'That is not an address this can use. Unlike the two numbers, an address can only be checked for shape — confirm it is the person\'s.', 'ffcertificate' )
			);
		}

		$cpf_hash = SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf );
		$rf_hash  = SensitiveFieldRegistry::hash_identifier( 'rf', $rf );

		if ( ! is_string( $cpf_hash ) || '' === $cpf_hash || ! is_string( $rf_hash ) || '' === $rf_hash ) {
			return new WP_Error(
				'ffc_identity_adopt_not_configured',
				__( 'Encryption is not configured, so an identifier cannot be matched or stored.', 'ffcertificate' )
			);
		}

		$before = $this->existing( $cpf_hash, $rf_hash, $email );
		$result = $this->creator( $cpf_hash, $rf_hash, $email );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$account = (int) $result;

		if ( $account <= 0 ) {
			return new WP_Error(
				'ffc_identity_adopt_failed',
				__( 'No account was opened and none was matched, so nothing was adopted.', 'ffcertificate' )
			);
		}

		ActivityLog::log(
			'identity_orphans_adopted',
			ActivityLog::LEVEL_WARNING,
			array(
				// Prefixes, never the hashes and never the values. The address
				// is not logged at all: it is the one identifier here that is
				// readable as it stands.
				//
				// THE KEY SAYS WHAT IT HOLDS, AND THAT IS NOT COSMETIC (#1448).
				// `SensitiveFieldRegistry::contains_sensitive()` matches on the
				// KEY NAME alone -- `walk_for_sensitive()` tests
				// `isset( $sensitive[ $key ] )` and never looks at the value --
				// so a 12-character hash prefix filed under `cpf` classified this
				// whole context as sensitive. That cost nothing until #1444
				// declared `context_encrypted`, and then every adoption started
				// encrypting two prefixes and a boolean, while the log screen
				// printed the raw JSON with a key naming an identifier that is
				// not in it.
				'cpf_prefix' => substr( $cpf_hash, 0, self::LOG_PREFIX ),
				'rf_prefix'  => substr( $rf_hash, 0, self::LOG_PREFIX ),
				'account'    => $account,
				// Whether a login was opened or an existing one answered. Same
				// action, different blast radius, and the log is where that is
				// legible afterwards.
				'created'    => $before <= 0,
			),
			$actor
		);

		return array(
			'account' => $account,
			'created' => $before <= 0,
		);
	}

	/**
	 * Whether an account already answers to these identifiers, read BEFORE
	 * the call that would create one.
	 *
	 * Asked separately because `get_or_create_user_dual()` returns an id
	 * either way, so afterwards there is no telling which of its three
	 * branches ran — and "an account was opened" is the part an operator and
	 * the log both need to distinguish.
	 *
	 * @param string $cpf_hash The CPF hash.
	 * @param string $rf_hash  The RF hash.
	 * @param string $email    The address.
	 * @return int
	 */
	protected function existing( string $cpf_hash, string $rf_hash, string $email ): int {
		/**
		 * Which account already answers to these identifiers, if any.
		 *
		 * Read-only: it resolves and never creates, which is what makes it
		 * safe to ask before the call that would.
		 *
		 * @since 6.28.4
		 * @param int    $found    Resolved so far; 0 when nothing has.
		 * @param string $cpf_hash CPF hash.
		 * @param string $rf_hash  RF hash.
		 * @param string $email    The address.
		 */
		return (int) apply_filters( 'ffc_resolve_identity_account', 0, $cpf_hash, $rf_hash, $email );
	}

	/**
	 * The one path from an identity to an account, as a seam a test can stand
	 * in for.
	 *
	 * @param string $cpf_hash The CPF hash.
	 * @param string $rf_hash  The RF hash.
	 * @param string $email    The address.
	 * @return int|WP_Error
	 */
	protected function creator( string $cpf_hash, string $rf_hash, string $email ) {
		/**
		 * The account for these identifiers: the one that answers to them, or
		 * a new one, with every unlinked record carrying them adopted into it.
		 *
		 * Unlike `ffc_create_identity_account`, this one DOES resolve first —
		 * an orphan has no account to be taken off, so resolving is the whole
		 * point rather than the thing to avoid.
		 *
		 * @since 6.28.4
		 * @param int|\WP_Error|null $account  Null until something answers.
		 * @param string             $cpf_hash CPF hash.
		 * @param string             $rf_hash  RF hash.
		 * @param string             $email    The address.
		 */
		$account = apply_filters( 'ffc_adopt_identity_account', null, $cpf_hash, $rf_hash, $email );

		if ( $account instanceof WP_Error ) {
			return $account;
		}

		// A FILTER NOBODY ANSWERED IS NOT AN ACCOUNT OF ZERO.
		//
		// `null` is what the default carries through when the listener is
		// absent -- on an install where the module is off, say -- and reading
		// it as "no account" would report a refusal the data never made.
		if ( null === $account ) {
			return new WP_Error(
				'ffc_identity_adopt_no_creator',
				__( 'No account could be opened: nothing on this install is wired to open one.', 'ffcertificate' )
			);
		}

		return (int) $account;
	}
}
