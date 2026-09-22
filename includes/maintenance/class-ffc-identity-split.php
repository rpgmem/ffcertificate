<?php
/**
 * Identity split
 *
 * Creates an account for records that belong to somebody who has none, and
 * moves them onto it (#1386). The third verb of the identity queue, and the
 * thinnest: it is {@see IdentityRelink} with the target created first.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Give records of their own to somebody who has no account.
 */
class IdentitySplit {

	/**
	 * The identifiers a split can carry onto a new account.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

	/**
	 * How many characters of a hash reach the log.
	 *
	 * @var int
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Create an account for one identifier's records and move them onto it.
	 *
	 * THE OPERATOR SUPPLIES THE ADDRESS, AND THAT IS NOT A CONVENIENCE.
	 *
	 * WordPress requires `user_email` to be unique, and every typo finding the
	 * production audit carries reports `shared_email` -- both identifiers sit
	 * under the address the existing account already uses. So the new account
	 * CANNOT inherit the one on the records: there is no address to give it
	 * except one a person provides, case by case (#1386, decision 1).
	 *
	 * PER IDENTIFIER, ONCE OR TWICE -- NEVER BOTH AT ONCE
	 *
	 * An account holding two identifiers that both belong elsewhere is two
	 * people, not one, so a single new account taking both would recreate the
	 * conflict under a new login. Splitting each in turn produces two
	 * accounts, which is what two people need.
	 *
	 * WHY THE AGREEMENT RULE IS SATISFIED BY CONSTRUCTION HERE, AND WHY THAT
	 * IS NOT A LOOPHOLE
	 *
	 * The new account is seeded with the identifier being split before the
	 * move, so {@see IdentityRelink} finds a match rather than refusing. That
	 * reads like a way around the rule and is not one: what the rule guards
	 * against is records landing on a PRE-EXISTING account belonging to
	 * somebody else, and an account created in this call belongs to nobody
	 * yet. The operator's assertion that these records are a separate person
	 * IS the decision, and the address they had to supply is what makes it
	 * deliberate rather than a click.
	 *
	 * @param string $hash  The stored hash whose records get their own account.
	 * @param string $email The address for the new account.
	 * @param int    $actor Who decided it, for the log.
	 * @param string $field `rf` or `cpf`; which column the hash sits in.
	 * @return array{account: int, moved: array<string, int>, from: int}|WP_Error
	 */
	public function split( string $hash, string $email, int $actor = 0, string $field = 'rf' ): array|WP_Error {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error(
				'ffc_identity_split_unknown_field',
				__( 'That is not an identifier this can split.', 'ffcertificate' )
			);
		}

		$hash = trim( $hash );

		// THE ONE FUNCTION DECIDES WHAT AN ADDRESS IS.
		//
		// `normalize_email()` lowercases and trims, and `IdentifierIdiomTest`
		// holds that no file writes its own: an operator typing `Joao@...`
		// must reach the same person as `joao@...`, and the address here is
		// checked against every existing account before it opens a new one.
		// Validity stays a separate question, which is the two calls below.
		$email = DataSanitizer::normalize_email( $email );

		if ( '' === $hash ) {
			return new WP_Error(
				'ffc_identity_split_no_subject',
				__( 'No records were named to split.', 'ffcertificate' )
			);
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'ffc_identity_split_invalid_email',
				__( 'A split needs a valid e-mail address for the new account.', 'ffcertificate' )
			);
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'ffc_identity_split_email_taken',
				__( 'That address already belongs to an account, so it cannot open a new one. Use a different address — or, if that account is the right one, move the records to it instead of splitting.', 'ffcertificate' )
			);
		}

		$account = $this->create_account( $email );

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		// SEEDED BEFORE THE MOVE, FOR THE REASON THE DOCBLOCK ARGUES.
		//
		// A brand-new account holds nothing, so the agreement rule would refuse
		// every split. Writing the identifier first is what says whose account
		// this is -- and it is written BEFORE the move so that a failure below
		// leaves nothing half-owned once the account is removed.
		if ( ! $this->seed_index( $account, $field . '_hash', $hash ) ) {
			$this->discard_account( $account );

			return new WP_Error(
				'ffc_identity_split_index_failed',
				__( 'The account was created but the identity index refused to record what it holds, so it was removed again and nothing was changed.', 'ffcertificate' )
			);
		}

		$moved = $this->movements()->relink( $hash, $account, $actor, $field );

		// A FAILED SPLIT LEAVES NO ACCOUNT BEHIND.
		//
		// The relink makes its own refusals -- records split across two
		// accounts, records that are already gone -- and any of them here
		// would otherwise leave an empty login nobody asked for, which an
		// operator would have to notice and clean up by hand.
		if ( is_wp_error( $moved ) ) {
			$this->discard_account( $account );

			return $moved;
		}

		ActivityLog::log(
			'identity_records_split',
			ActivityLog::LEVEL_WARNING,
			array(
				// A prefix, never the hash and never the value.
				'identifier' => substr( $hash, 0, self::LOG_PREFIX ),
				'field'      => $field,
				'from'       => $moved['from'],
				'account'    => $account,
				'stores'     => $moved['moved'],
			),
			$actor
		);

		return array(
			'account' => $account,
			'moved'   => $moved['moved'],
			'from'    => $moved['from'],
		);
	}

	/**
	 * Create the account, with the role every FFC account gets.
	 *
	 * No welcome mail is sent, deliberately: this runs from a maintenance
	 * screen while an operator is correcting data, and an account notification
	 * arriving unannounced is a side effect they did not ask for. Telling the
	 * person is theirs to do, through WordPress's own password reset.
	 *
	 * @param string $email The address.
	 * @return int|WP_Error The new account's id.
	 */
	protected function create_account( string $email ) {
		// A FILTER, BECAUSE CREATION LIVES IN ONE PLACE AND IT IS NOT THIS ONE.
		//
		// `IdentityConvergenceGuardTest` holds that a WordPress user is created
		// only by `UserCreator`, which resolves the identifier first so a
		// person never gets a second account. A split is the one case where
		// resolving would defeat the purpose -- the identifier resolves to the
		// account the records are being taken off -- so the exception is argued
		// THERE, in `create_for_identity_split()`, and reached from here through
		// a hook. Naming the class directly would add a `Maintenance >
		// UserDashboard` edge the module baseline does not carry; this is the
		// same shape, and the same reasoning, as
		// `ffc_grant_certificate_capabilities`.
		$created = apply_filters( 'ffc_create_identity_account', null, $email );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$user_id = is_numeric( $created ) ? (int) $created : 0;

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'ffc_identity_split_no_creator',
				__( 'No account could be created: the identity split has nothing wired to create one on this install.', 'ffcertificate' )
			);
		}

		return $user_id;
	}

	/**
	 * Remove an account this call created, after a failure.
	 *
	 * Only ever called on an account created moments earlier in this same
	 * call, which is why deleting is safe here and nowhere else in the queue:
	 * nothing has been moved onto it yet, so there is nothing to lose.
	 *
	 * @param int $user_id The account.
	 * @return void
	 */
	protected function discard_account( int $user_id ): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Record what the new account holds.
	 *
	 * @param int    $user_id The account.
	 * @param string $column  The index column.
	 * @param string $hash    The identifier.
	 * @return bool
	 */
	protected function seed_index( int $user_id, string $column, string $hash ): bool {
		return ( new UserProfileRepository() )->upsertForUserId( $user_id, array( $column => $hash ) );
	}

	/**
	 * The move, as a seam a test can replace.
	 *
	 * @return IdentityRelink
	 */
	protected function movements(): IdentityRelink {
		return new IdentityRelink();
	}
}
