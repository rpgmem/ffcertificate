<?php
/**
 * Identity relink
 *
 * Moves the records carrying one identifier to the account they belong to.
 * The shared primitive behind relink, split and merge (#1386): the three
 * differ only in how the target account is named.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and a relink must read and write the live rows: a cached answer would be precisely wrong.
/**
 * Move the records carrying one identifier to another account.
 *
 * @phpstan-import-type Agreement from IdentityAgreement
 */
class IdentityRelink {

	/**
	 * The identifiers a relink can move, and compare on.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

	/**
	 * Stores carrying an identifier beside a `user_id`.
	 *
	 * The same three {@see IdentityRepair} rewrites, for the same reason:
	 * `ffc_user_profiles` is the identity index rather than a record store, so
	 * it is carried separately and never moved.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * How many characters of a hash reach the log.
	 *
	 * @var int
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Move every record carrying one identifier to a target account.
	 *
	 * THE TWO SIDES MUST AGREE ON SOMETHING, AND NULL IS NOT AGREEMENT.
	 *
	 * Moving records between accounts on an operator's say-so alone is how one
	 * person's certificate ends up under another person's login. So the move
	 * is only allowed when the records and the target account carry the SAME
	 * value for at least one identifier -- and where the target holds no value
	 * of that kind at all, the records' own is accepted as true, which is what
	 * lets an account with only a CPF take in records that also carry an RF
	 * and end up holding both (#1386, decision 2).
	 *
	 * A second non-null value that DISAGREES refuses the whole move, whatever
	 * else matches: two different RFs under one matching CPF is the typo this
	 * queue exists for, and the repair comes first. Completing that move would
	 * leave the TARGET holding two, which is the same defect one account along.
	 *
	 * SO THE ORDINARY SHAPE OF A RELINK IS A GAP, AND THAT FALLS OUT RATHER
	 * THAN BEING CHOSEN. A target already carrying the moving identifier on its
	 * own rows makes those rows part of the moving set, which names two
	 * accounts and is refused as ambiguous -- correctly, because one identifier
	 * on two accounts is a MERGE, where the decision is which account survives.
	 * What is left is the case this verb is for: the records agree with the
	 * target on the OTHER identifier, and the target gains this one.
	 *
	 * @param string $hash   The stored hash whose records move.
	 * @param int    $target The account they move to.
	 * @param int    $actor  Who decided it, for the log.
	 * @param string $field  `rf` or `cpf`; which column the hash sits in.
	 * @return array{moved: array<string, int>, gained: array<int, string>, from: int}|WP_Error
	 */
	public function relink( string $hash, int $target, int $actor = 0, string $field = 'rf' ): array|WP_Error {
		global $wpdb;

		// Named here as well as inside `verdict()`, because the split moved
		// the read in front of it and an operator who supplies neither half
		// should still be told about the half that is theirs. One message,
		// two call sites -- never two messages.
		$unnamed = self::unnamed_target( $target );

		if ( null !== $unnamed ) {
			return $unnamed;
		}

		$moving = $this->moving( $hash, $field );

		if ( is_wp_error( $moving ) ) {
			return $moving;
		}

		$agreement = $this->verdict( $moving, $target );

		if ( is_wp_error( $agreement ) ) {
			return $agreement;
		}

		$origin = $moving['origin'];
		$moved  = array();

		// The rows and the index must not be able to disagree about who owns
		// what, and this writes to as many as four tables. Rolled back as one
		// on any failure -- which assumes InnoDB, as every ffc_* table is.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $moving['rows'] as $table => $ids ) {
			$done = $wpdb->update(
				$table,
				array( 'user_id' => $target ),
				array( $field . '_hash' => $hash ),
				array( '%d' ),
				array( '%s' )
			);

			if ( false === $done ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_relink_failed',
					sprintf(
						/* translators: %s: the store whose write failed. */
						__( 'The store %s refused the move, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$moved[ $table ] = count( $ids );
		}

		// THE INDEX FOLLOWS THE RECORDS, INCLUDING INTO ITS EMPTY SLOTS.
		//
		// `ffc_user_profiles` holds ONE hash per identifier per account, so an
		// account that gains records carrying a value it had no value for
		// gains that value -- which is the gap-filling half of the rule above,
		// and the reason an account with only a CPF ends up holding both.
		$gains           = $agreement['gaps'];
		$gains[ $field ] = $hash;

		$index = array();
		foreach ( $gains as $gained_field => $gained_hash ) {
			$index[ $gained_field . '_hash' ] = $gained_hash;
		}

		if ( ! $this->reindex( $target, $index ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_relink_index_failed',
				__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		// THE ORIGIN STOPS CLAIMING WHAT IT NO LONGER HOLDS.
		//
		// Every row carrying the value left, so an index still naming it would
		// resolve a person to an account holding nothing of theirs -- which is
		// worse than an empty slot, because it answers confidently.
		if ( $origin > 0 && ! $this->unindex( $origin, $field . '_hash', $hash ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_relink_index_failed',
				__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		$wpdb->query( 'COMMIT' );

		// Records that became this account's are useless behind an account
		// that cannot read them -- #1367's defect, one account at a time.
		do_action( 'ffc_grant_certificate_capabilities', $target );

		ActivityLog::log(
			'identity_records_relinked',
			ActivityLog::LEVEL_WARNING,
			array(
				// Prefixes, never the hashes and never the values.
				'identifier' => substr( $hash, 0, self::LOG_PREFIX ),
				'field'      => $field,
				'from'       => $origin,
				'to'         => $target,
				'stores'     => $moved,
				'matched'    => $agreement['matches'],
				'gained'     => array_keys( $agreement['gaps'] ),
			),
			$actor
		);

		return array(
			'moved'  => $moved,
			'gained' => array_keys( $agreement['gaps'] ),
			'from'   => $origin,
		);
	}

	/**
	 * The records one identifier would move, and what refuses before a target
	 * is even named.
	 *
	 * SPLIT OUT SO THE DIALOG AND THE WRITE CANNOT DISAGREE (#1397 sprint 3).
	 *
	 * The account-search dialog has to show every refusal BEFORE the operator
	 * commits, which means evaluating the same rule the write applies. A
	 * second copy of that rule would agree on the day it is written and drift
	 * the first time one side is corrected -- and what it decides is whether
	 * one person's record lands under another person's login, so the drift is
	 * invisible and permanent. There is one path instead: `relink()` is this
	 * method plus {@see self::verdict()} plus the write, and the dialog calls
	 * the same two.
	 *
	 * The split is by what each half DEPENDS ON, which is also what makes the
	 * dialog cheap: this half reads only the identifier, so the search runs it
	 * ONCE and then asks `verdict()` per candidate.
	 *
	 * @since 6.28.4
	 * @param string $hash  The stored hash whose records would move.
	 * @param string $field `rf` or `cpf`; which column the hash sits in.
	 * @return array{rows: array<string, array<int, int>>, accounts: array<int, int>, identifiers: array<string, array<int, string>>, origin: int, count: int, hash: string, field: string}|WP_Error
	 */
	public function moving( string $hash, string $field = 'rf' ): array|WP_Error {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error(
				'ffc_identity_relink_unknown_field',
				__( 'That is not an identifier this can move.', 'ffcertificate' )
			);
		}

		$hash = trim( $hash );

		if ( '' === $hash ) {
			return new WP_Error(
				'ffc_identity_relink_no_target',
				__( 'A relink needs both the records to move and the account to move them to.', 'ffcertificate' )
			);
		}

		$moving = $this->rows_carrying( $field . '_hash', $hash );

		if ( array() === $moving['rows'] ) {
			return new WP_Error(
				'ffc_identity_relink_gone',
				__( 'Nothing carries that identifier any more. Reload the queue.', 'ffcertificate' )
			);
		}

		if ( count( $moving['accounts'] ) > 1 ) {
			return new WP_Error(
				'ffc_identity_relink_ambiguous',
				__( 'Those records are split across more than one account, so one move cannot serve them. Resolve them separately.', 'ffcertificate' )
			);
		}

		$count = 0;
		foreach ( $moving['rows'] as $ids ) {
			$count += count( $ids );
		}

		$moving['origin'] = $moving['accounts'][0] ?? 0;
		$moving['count']  = $count;
		$moving['hash']   = $hash;
		$moving['field']  = $field;

		return $moving;
	}

	/**
	 * Whether one account may receive those records, and what it would gain.
	 *
	 * The other half of the rule {@see self::moving()} opens. It takes the
	 * moving set rather than the identifier, so a search evaluating twenty
	 * candidates reads the moving rows once instead of twenty times -- and,
	 * more to the point, evaluates every candidate against exactly the set
	 * the write would move.
	 *
	 * @since 6.28.4
	 * @param array{identifiers: array<string, array<int, string>>, origin: int} $moving What {@see self::moving()} returned.
	 * @param int                                                                $target The account under consideration.
	 * @return Agreement|WP_Error
	 */
	public function verdict( array $moving, int $target ): array|WP_Error {
		$unnamed = self::unnamed_target( $target );

		if ( null !== $unnamed ) {
			return $unnamed;
		}

		if ( $moving['origin'] === $target ) {
			return new WP_Error(
				'ffc_identity_relink_unchanged',
				__( 'Those records already belong to that account.', 'ffcertificate' )
			);
		}

		$agreement = IdentityAgreement::between(
			$moving['identifiers'],
			IdentityAgreement::held_by( $target )
		);

		// THE TIE IS NOT EVIDENCE UNTIL IT IS A NUMBER (#1491).
		//
		// The same question the merge asks, for the same reason: what authorises
		// a move is that the records and the target AGREE about an identifier,
		// and a value failing its own check digit cannot be either person's. It
		// is not a weak tie, it is a wrong number -- and correcting it may leave
		// the records with nothing tying them to that account at all.
		$agreement = IdentityAgreement::with_unusable(
			$agreement,
			$moving['identifiers'],
			$this->verdicts( $agreement, $moving['identifiers'] )
		);

		if ( array() !== $agreement['conflicts'] ) {
			return self::refusal( $agreement, $moving['identifiers'] );
		}

		if ( array() === $agreement['matches'] ) {
			return new WP_Error(
				'ffc_identity_relink_no_agreement',
				__( 'Nothing ties those records to that account: they share no identifier with it, and an absent value is not agreement. Correct an identifier first so the two agree.', 'ffcertificate' )
			);
		}

		return $agreement;
	}

	/**
	 * Judge each agreed-on identifier, by hash.
	 *
	 * The merge's twin, and deliberately a copy rather than a shared parent:
	 * these two classes share no ancestor and inventing one to hold nine lines
	 * would be the facade trap `CLAUDE.md` names. What they must not do is
	 * disagree about the QUESTION, and they cannot -- both hand the answer to
	 * `IdentityAgreement::with_unusable()`, which is where the rule lives.
	 *
	 * `protected` so a test can state what the scan found without an encryption
	 * key, like `reindex()` and `unindex()` beside it.
	 *
	 * @since 6.31.0
	 * @param array<string, mixed>              $agreement What `between()` returned.
	 * @phpstan-param Agreement $agreement
	 * @param array<string, array<int, string>> $records   Field => the moving records' hashes.
	 * @return array<string, array<string, string>> Field => hash => a `VERDICT_*`.
	 */
	protected function verdicts( array $agreement, array $records ): array {
		if ( array() === $agreement['matches'] ) {
			return array();
		}

		$query = new IdentityConflictQuery();
		$out   = array();

		foreach ( $agreement['matches'] as $field ) {
			$held = $records[ $field ] ?? array();

			if ( array() === $held ) {
				continue;
			}

			$out[ $field ] = $query->check_digit_verdicts( $field . '_hash', $held );
		}

		return $out;
	}

	/**
	 * The refusal for a blocked move, saying which of the two reasons blocked.
	 *
	 * ONE SENTENCE FOR TWO CAUSES SENT OPERATORS LOOKING FOR NOTHING (#1477).
	 *
	 * It read "the records and that account hold different values" whatever
	 * had blocked -- including when they hold the SAME value and what blocks
	 * is a second one on the moving side. There is no disagreement to find
	 * there, so the search ends in the operator distrusting the screen.
	 *
	 * The two need different fixes. A disagreement is corrected on whichever
	 * side is wrong. A moving set carrying two values is not one person's
	 * records at all, and the fix is to narrow the selection or split it --
	 * correcting the target cannot make a mixed set coherent.
	 *
	 * @since 6.30.0
	 * @param array<string, mixed>              $agreement What the rule decided.
	 * @phpstan-param Agreement $agreement
	 * @param array<string, array<int, string>> $carried What the moving rows carry, per identifier.
	 * @return WP_Error
	 */
	private static function refusal( array $agreement, array $carried ): WP_Error {
		$disagree  = array();
		$ambiguous = array();
		$unusable  = array();

		// `disagreement` STAYS THE DEFAULT for a reason this class does not know:
		// the oldest and most general of the three, and a refusal that says the
		// wrong thing beats one that says nothing.
		foreach ( $agreement['conflicts'] as $field ) {
			$reason = $agreement['reasons'][ $field ] ?? IdentityAgreement::REASON_DISAGREEMENT;

			if ( IdentityAgreement::REASON_AMBIGUOUS === $reason ) {
				$ambiguous[] = $field;
				continue;
			}

			if ( IdentityAgreement::REASON_UNUSABLE === $reason ) {
				$unusable[] = $field;
				continue;
			}

			$disagree[] = $field;
		}

		$said = array();

		// The same reasoning as `IdentityMerge::refusal()` and for the same
		// reason (#1532): the move still cannot proceed, and an accepted finding
		// has left the panel this sentence used to name.
		$accepted = new IdentityAcceptance();

		foreach ( $unusable as $field ) {
			if ( $accepted->any_accepted( $field, $carried[ $field ] ?? array() ) ) {
				$said[] = sprintf(
					/* translators: %s: the identifier the records and the account share, e.g. RF. */
					__( 'The records and that account share the same %s, and that number fails its own check digit — so it cannot be either person\'s, and it is not what ties them together. It has been accepted as impossible to resolve, so it sits under "Accepted — no resolution possible" rather than among the numbers to correct. That does not unblock this move: if the correct value has since been found, withdraw the acceptance there and correct the number first.', 'ffcertificate' ),
					strtoupper( $field )
				);

				continue;
			}

			$said[] = sprintf(
				/* translators: %s: the identifier the records and the account share, e.g. RF. */
				__( 'The records and that account share the same %s, and that number fails its own check digit — so it cannot be either person\'s, and it is not what ties them together. Correct it first, under "Numbers to correct": afterwards the records may have nothing tying them to that account at all, which is the answer rather than an obstacle.', 'ffcertificate' ),
				strtoupper( $field )
			);
		}

		if ( array() !== $disagree ) {
			$said[] = sprintf(
				/* translators: %s: the identifiers that disagree, comma separated. */
				__( 'The records and that account hold different values for: %s. Correct the identifier first — moving records across a disagreement is how one person\'s record ends up under another person\'s account.', 'ffcertificate' ),
				implode( ', ', array_map( 'strtoupper', $disagree ) )
			);
		}

		foreach ( $ambiguous as $field ) {
			$said[] = sprintf(
				/* translators: 1: how many different values the selected records carry. 2: the identifier, e.g. RF. */
				__( 'The records you selected carry %1$s separate values for %2$s, so they are not all one person\'s — and the account they would land under cannot make them so, even where it agrees with one of those values. Narrow the selection to the records carrying a single %2$s and move them on their own.', 'ffcertificate' ),
				// PLAIN, NOT `number_format_i18n()`. The count is how many distinct
				// values one side carries for one identifier -- a handful at the
				// very worst, so a thousands separator can never apply. Reaching
				// for it would make these two classes the first in their module to
				// need that function defined, and a stub taught for one test stays
				// taught for every test after it.
				(string) count( $carried[ $field ] ?? array() ),
				strtoupper( $field )
			);
		}

		return new WP_Error(
			// NOT `_ambiguous`: that code already means the moving rows span
			// more than one ACCOUNT. Two different refusals under one code is
			// the defect this method exists to fix, one level down. The name
			// is the queue's own `multiple_identities`, which is exactly the
			// finding the operator has to go and resolve.
			array() === $disagree ? 'ffc_identity_relink_multiple_identities' : 'ffc_identity_relink_conflict',
			implode( ' ', $said )
		);
	}

	/**
	 * The refusal for a move with no account named, or null when one is.
	 *
	 * @since 6.28.4
	 * @param int $target The account under consideration.
	 * @return WP_Error|null
	 */
	private static function unnamed_target( int $target ): ?WP_Error {
		if ( $target > 0 ) {
			return null;
		}

		return new WP_Error(
			'ffc_identity_relink_no_target',
			__( 'A relink needs both the records to move and the account to move them to.', 'ffcertificate' )
		);
	}

	/**
	 * Which rows carry a hash, whom they name, and what else they carry.
	 *
	 * The `identifiers` half is what the agreement rule reads: a row holding
	 * an RF usually holds a CPF too, and that second value is the evidence
	 * that these records belong to the person the target account is.
	 *
	 * @param string $column The hash column.
	 * @param string $hash   The value to look for.
	 * @return array{rows: array<string, array<int, int>>, accounts: array<int, int>, identifiers: array<string, array<int, string>>}
	 */
	private function rows_carrying( string $column, string $hash ): array {
		global $wpdb;

		$rows        = array();
		$accounts    = array();
		$identifiers = array();

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$found = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, user_id, cpf_hash, rf_hash FROM %i WHERE %i = %s',
					$table,
					$column,
					$hash
				),
				ARRAY_A
			);

			$ids = array();

			foreach ( (array) $found as $row ) {
				$row   = (array) $row;
				$ids[] = (int) ( $row['id'] ?? 0 );
				$owner = (int) ( $row['user_id'] ?? 0 );

				if ( $owner > 0 && ! in_array( $owner, $accounts, true ) ) {
					$accounts[] = $owner;
				}

				IdentityAgreement::collect( $identifiers, $row );
			}

			if ( array() !== $ids ) {
				$rows[ $table ] = $ids;
			}
		}

		return array(
			'rows'        => $rows,
			'accounts'    => $accounts,
			'identifiers' => $identifiers,
		);
	}

	/**
	 * Point the identity index at what the target now holds.
	 *
	 * @param int                   $user_id The account.
	 * @param array<string, string> $data    Index columns to write.
	 * @return bool
	 */
	protected function reindex( int $user_id, array $data ): bool {
		return ( new UserProfileRepository() )->upsertForUserId( $user_id, $data );
	}

	/**
	 * Clear the origin's index entry, but only where it named the moved value.
	 *
	 * Conditional on the VALUE, never unconditional: the origin may legitimately
	 * be indexed under something else entirely, and blanking that would lose an
	 * answer the move never touched.
	 *
	 * @param int    $user_id The origin account.
	 * @param string $column  The index column.
	 * @param string $hash    The hash that left.
	 * @return bool
	 */
	protected function unindex( int $user_id, string $column, string $hash ): bool {
		global $wpdb;

		$profiles = $wpdb->prefix . 'ffc_user_profiles';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) !== $profiles ) {
			return true;
		}

		$done = $wpdb->update(
			$profiles,
			array( $column => null ),
			array(
				'user_id' => $user_id,
				$column   => $hash,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return false !== $done;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
