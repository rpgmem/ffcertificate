<?php
/**
 * Identity merge
 *
 * Consolidates two accounts that turned out to be one person, for the shared
 * tier of the identity queue: one identifier, two logins (#1386).
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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and a merge must read and write the live rows: a cached answer would be precisely wrong.
/**
 * Move one account's records onto another the operator chose to keep.
 *
 * @phpstan-import-type Agreement from IdentityAgreement
 */
class IdentityMerge {

	/**
	 * Stores carrying records beside a `user_id`.
	 *
	 * @var array<int, string>
	 */
	private const STORES = array(
		'ffc_submissions',
		'ffc_self_scheduling_appointments',
		'ffc_recruitment_candidate',
	);

	/**
	 * Pure relationships beside a `user_id`, and the column they hang from.
	 *
	 * WHY THESE MOVE, AND WHY LEAVING THEM WAS #1367 AGAIN (#1368)
	 *
	 * They are not records: they are what the person is ALLOWED to do --
	 * membership of an audience, a place on a booking, a permission on one
	 * schedule. Moving the records and not these leaves somebody logging in as
	 * the survivor with their bookable groups attached to the login they no
	 * longer use, which is exactly the shape #1367 cost 1,478 accounts: the row
	 * moved and the thing granting access to it did not. `UserCleanup` already
	 * classifies all three as relationships rather than records, which is why
	 * it DELETES them on account deletion where it nulls a submission's link.
	 *
	 * THE UNIQUE KEY IS WHY THIS IS NOT ONE `UPDATE`
	 *
	 * Each carries `UNIQUE KEY (<parent>, user_id)`, so where the survivor is
	 * already a member of the same audience the donor's row cannot become
	 * theirs -- the update would fail on a duplicate and roll the merge back.
	 * The duplicate is dropped and the rest moved, and BOTH numbers are
	 * reported: "12 memberships move, 3 you already had" is the honest
	 * sentence, and a single total would hide a row that disappeared.
	 *
	 * @var array<string, string> table suffix => the column the unique key pairs with `user_id`.
	 */
	private const RELATIONSHIPS = array(
		'ffc_audience_members'              => 'audience_id',
		'ffc_audience_booking_users'        => 'booking_id',
		'ffc_audience_schedule_permissions' => 'schedule_id',
	);

	/**
	 * Everything a merge decides and how much it would move, without writing.
	 *
	 * THE COUNT IS THE POINT, AND A SECOND COUNT WOULD BE A DIFFERENT ANSWER.
	 *
	 * A merge is the one verb on this screen no other undoes, so the
	 * confirmation has to say what it does — which is a number, per store.
	 * Counting it anywhere but here means two resolutions of "which rows move"
	 * that agree on the day they are written, and the operator acknowledges
	 * the one that is not the one that runs. So `merge()` is this plus the
	 * write, and the preview calls this.
	 *
	 * It counts on the SAME predicate the write updates on — `user_id` equals
	 * the absorbed account — over the same store list, resolved here once.
	 *
	 * `holds` is the other half of the confirmation: how much each side has,
	 * so the operator can see which login is the one in use before choosing
	 * which survives (#1368). It is evidence, never a decision: the data
	 * cannot say which login is the person's real one.
	 *
	 * @since 6.28.4
	 * @param int $survivor The account the operator chose to keep.
	 * @param int $absorbed The account whose records move.
	 * @return array{stores: array<int, string>, counts: array<string, int>, total: int, holds: array{survivor: int, absorbed: int}, relationships: array<string, array{moves: int, duplicates: int}>, matches: array<int, string>, gaps: array<string, string>}|WP_Error
	 */
	public function plan( int $survivor, int $absorbed ): array|WP_Error {
		global $wpdb;

		if ( $survivor <= 0 || $absorbed <= 0 ) {
			return new WP_Error(
				'ffc_identity_merge_no_pair',
				__( 'A merge needs both accounts: the one to keep and the one whose records move.', 'ffcertificate' )
			);
		}

		if ( $survivor === $absorbed ) {
			return new WP_Error(
				'ffc_identity_merge_same_account',
				__( 'Those are the same account, so there is nothing to merge.', 'ffcertificate' )
			);
		}

		$theirs = IdentityAgreement::held_by( $absorbed );
		$ours   = IdentityAgreement::held_by( $survivor );

		if ( array() === $theirs ) {
			return new WP_Error(
				'ffc_identity_merge_nothing_to_move',
				__( 'That account holds no identifier this can read, so there is nothing to merge from it.', 'ffcertificate' )
			);
		}

		$agreement = IdentityAgreement::between( $theirs, $ours );

		// A SHARED NUMBER IS NOT EVIDENCE UNTIL IT IS A NUMBER (#1491).
		//
		// `between()` refuses a DISAGREEMENT and an AMBIGUITY and never asked
		// whether a value is a value, so two accounts holding the SAME invalid
		// identifier passed -- and that is not weak evidence of one person, it is
		// evidence that one of them is wrong. Correcting it can dissolve the
		// finding outright, because the pairing may have been the typo.
		//
		// Asked here rather than inside `between()` because that compares hashes
		// and never decrypts. This is the verb's own I/O, one or two values,
		// negligible beside the transaction below.
		$agreement = IdentityAgreement::with_unusable( $agreement, $theirs, $this->verdicts( $agreement, $theirs ) );

		if ( array() !== $agreement['conflicts'] ) {
			return self::refusal( $agreement, $absorbed, $theirs );
		}

		if ( array() === $agreement['matches'] ) {
			return new WP_Error(
				'ffc_identity_merge_no_agreement',
				__( 'Those accounts share no identifier, and an absent value is not agreement. Correct an identifier first so the two agree.', 'ffcertificate' )
			);
		}

		$stores = array();
		$counts = array();
		$total  = 0;
		$keeps  = 0;

		foreach ( self::STORES as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$stores[] = $table;

			$moving = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $absorbed )
			);

			$counts[ $table ] = $moving;
			$total           += $moving;

			$keeps += (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $survivor )
			);
		}

		return array(
			'stores'        => $stores,
			'counts'        => $counts,
			'total'         => $total,
			'holds'         => array(
				'survivor' => $keeps,
				'absorbed' => $total,
			),
			'relationships' => $this->relationship_plan( $survivor, $absorbed ),
			'matches'       => $agreement['matches'],
			'gaps'          => $agreement['gaps'],
		);
	}

	/**
	 * Judge each agreed-on identifier, by hash.
	 *
	 * THE SEAM IS HERE SO A TEST CAN ANSWER WITHOUT A DATABASE.
	 *
	 * `check_digit_verdicts()` decrypts, which is exactly what this class must
	 * not start doing inline: it is the one call in the chain that needs the
	 * encryption key, and a test of the merge should not need one to prove the
	 * merge asks. Overridable, like `reindex()` and `clear_index()` beside it.
	 *
	 * Only the MATCHED fields are asked about. A conflicting field already
	 * blocks, and a gap has no shared value to judge -- so this is at most two
	 * hashes, and usually one.
	 *
	 * @since 6.31.0
	 * @param array<string, mixed>              $agreement What `between()` returned.
	 * @phpstan-param Agreement $agreement
	 * @param array<string, array<int, string>> $records   Field => the moving side's hashes.
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
	 * The refusal for a blocked merge, saying which of the two reasons blocked.
	 *
	 * THE OLD SENTENCE WAS WRONG ON THE COMMON CASE, AND WRONG IN THE
	 * EXPENSIVE DIRECTION (#1477).
	 *
	 * It read "those accounts hold different values for: RF" whatever had
	 * blocked, so an operator whose two accounts carry the SAME RF -- the
	 * pair the queue put in front of them, sharing that very number -- was
	 * told to correct a disagreement that does not exist. There is nothing
	 * to find, so the search ends in the operator distrusting the screen.
	 *
	 * What blocks there is the absorbed account carrying a SECOND number
	 * besides the shared one. That is a real refusal: absorbing it would hand
	 * the survivor an identity nobody has explained, turning a clean account
	 * into another queue item. But it is fixed on the ABSORBED account's own
	 * listing, and not by touching either shared value.
	 *
	 * WHICH LISTING IS NOT NAMED, ON PURPOSE. A multi-valued account sits in
	 * whichever tier its verdicts put it in -- mechanical when exactly one of
	 * its values fails the check digit, the shared mailbox when the address
	 * says so, a plain decision otherwise -- and this class cannot know which
	 * without re-deriving the queue. Naming one would send the operator to the
	 * wrong panel two times in three.
	 *
	 * It is also why the same pair may merge one way and not the other, and
	 * why the refusal does not RECOMMEND the other way: reversing it is
	 * allowed and resolves nothing, since the survivor keeps both values and
	 * gains records that now sit behind an unresolved identity.
	 *
	 * @since 6.30.0
	 * @param array<string, mixed>              $agreement What the rule decided.
	 * @phpstan-param Agreement $agreement
	 * @param int                               $absorbed The account whose records would move.
	 * @param array<string, array<int, string>> $theirs What that account holds, per identifier.
	 * @return WP_Error
	 */
	private static function refusal( array $agreement, int $absorbed, array $theirs ): WP_Error {
		$disagree  = array();
		$ambiguous = array();
		$unusable  = array();

		// ONE PASS, THREE BUCKETS, AND `disagreement` IS STILL THE DEFAULT.
		//
		// A reason this class does not know must read as a disagreement, which is
		// the oldest and most general of the three -- a refusal that says the
		// wrong thing beats one that says nothing. #1491 added the third bucket
		// as a branch here rather than a second loop plus an `array_diff`, which
		// is what it looked like first: two passes undoing each other's work.
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

		foreach ( $unusable as $field ) {
			$said[] = sprintf(
				/* translators: %s: the identifier both accounts hold, e.g. RF. */
				__( 'Both accounts hold the same %s, and that number fails its own check digit — so it cannot be either person\'s, and it is not evidence that they are one person. Correct it first, under "Numbers to correct": doing so may dissolve this pair rather than resolve it, because the shared number may be the typo that paired them.', 'ffcertificate' ),
				strtoupper( $field )
			);
		}

		if ( array() !== $disagree ) {
			$said[] = sprintf(
				/* translators: %s: the identifiers that disagree, comma separated. */
				__( 'Those accounts hold different values for: %s. Correct the identifier first — merging across a disagreement makes one person out of two, and nothing afterwards can tell them apart again.', 'ffcertificate' ),
				implode( ', ', array_map( 'strtoupper', $disagree ) )
			);
		}

		foreach ( $ambiguous as $field ) {
			$said[] = sprintf(
				/* translators: 1: the account whose records would move. 2: how many different values it carries. 3: the identifier, e.g. RF. */
				__( 'Account %1$s holds %2$s separate values for %3$s, not one, so it cannot be absorbed: the survivor would inherit a number nobody has explained, even though the two accounts do agree about the one that brought them here. This screen lists %1$s on its own for that — resolve it there first. Correcting the shared value cannot help, and merging the other way round is allowed but leaves %1$s holding both, with more records behind it.', 'ffcertificate' ),
				// AN ID IS NOT A QUANTITY: `number_format_i18n()` would print
				// account 6499 as "6.499" under pt_BR.
				(string) $absorbed,
				// PLAIN, NOT `number_format_i18n()`. The count is how many distinct
				// values one side carries for one identifier -- a handful at the
				// very worst, so a thousands separator can never apply. Reaching
				// for it would make these two classes the first in their module to
				// need that function defined, and a stub taught for one test stays
				// taught for every test after it.
				(string) count( $theirs[ $field ] ?? array() ),
				strtoupper( $field )
			);
		}

		return new WP_Error(
			// The queue's own vocabulary: `multiple_identities` is precisely
			// the finding on the absorbed account that has to be resolved.
			array() === $disagree ? 'ffc_identity_merge_multiple_identities' : 'ffc_identity_merge_conflict',
			implode( ' ', $said )
		);
	}

	/**
	 * What the relationship tables would do, per table, without writing.
	 *
	 * Two numbers each, because they are two different outcomes and one total
	 * would hide the second: `moves` becomes the survivor's, `duplicates` is
	 * dropped because the survivor already holds that pairing. A table the
	 * install does not have is absent rather than zero -- an absent table and
	 * an empty one are different facts, and the preview says so.
	 *
	 * @param int $survivor The account being kept.
	 * @param int $absorbed The account being emptied.
	 * @return array<string, array{moves: int, duplicates: int}>
	 */
	private function relationship_plan( int $survivor, int $absorbed ): array {
		global $wpdb;

		$out = array();

		foreach ( self::RELATIONSHIPS as $suffix => $parent ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$held = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $absorbed )
			);

			// The donor rows whose pairing the survivor already has. Counted on
			// the same join the delete below runs, so the number the operator
			// acknowledges is the number that happens.
			$duplicates = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i AS d INNER JOIN %i AS s ON s.%i = d.%i AND s.user_id = %d WHERE d.user_id = %d',
					$table,
					$table,
					$parent,
					$parent,
					$survivor,
					$absorbed
				)
			);

			$out[ $table ] = array(
				'moves'      => max( 0, $held - $duplicates ),
				'duplicates' => $duplicates,
			);
		}

		return $out;
	}

	/**
	 * Merge one account into another.
	 *
	 * WHY THIS IS NOT A RELINK, THOUGH IT SHARES THE RULE
	 *
	 * A relink moves the rows carrying ONE identifier and refuses when they
	 * name two accounts -- which is exactly the shared tier, and refusing it
	 * is right there: one identifier on two logins is not a question of where
	 * records go, it is a question of which login survives. That question has
	 * no answer the data can give, so the operator gives it, and this moves
	 * everything the losing account holds rather than one identifier's worth.
	 *
	 * THE SAME AGREEMENT RULE, BETWEEN TWO ACCOUNTS
	 *
	 * They must carry the same value for at least one identifier, and no
	 * second value that disagrees; where the survivor holds nothing of a kind
	 * the other has, it gains it, so a merge of an account with only a CPF and
	 * one with only an RF leaves the survivor holding both (#1386, decision
	 * 2). An absent value is never agreement.
	 *
	 * THE LOSING ACCOUNT IS NOT DELETED
	 *
	 * Deleting a WordPress user fires `deleted_user`, whose cleanup has its
	 * own SET-NULL and DELETE policy, and it cannot be undone. The records
	 * move, the emptied login is reported, and removing it stays the
	 * operator's own action in WordPress.
	 *
	 * @param int $survivor The account the operator chose to keep.
	 * @param int $absorbed The account whose records move.
	 * @param int $actor    Who decided it, for the log.
	 * @return array{moved: array<string, int>, relationships: array<string, array{moves: int, duplicates: int}>, gained: array<int, string>, emptied: int}|WP_Error
	 */
	public function merge( int $survivor, int $absorbed, int $actor = 0 ): array|WP_Error {
		global $wpdb;

		$plan = $this->plan( $survivor, $absorbed );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$agreement = array(
			'matches' => $plan['matches'],
			'gaps'    => $plan['gaps'],
		);

		$moved = array();

		// One transaction across the record stores, the three relationship
		// tables and both index rows. Rolled back whole on any refusal, which
		// assumes InnoDB, as every ffc_* table is.
		$wpdb->query( 'START TRANSACTION' );

		// THE SAME STORES THE PREVIEW COUNTED, AND NOT A SECOND PROBE.
		//
		// Re-resolving here would let the confirmation name a store this loop
		// then skips, or skip one it named — a divergence nobody would see,
		// because both halves would look correct on their own.
		foreach ( $plan['stores'] as $table ) {
			$done = $wpdb->update(
				$table,
				array( 'user_id' => $survivor ),
				array( 'user_id' => $absorbed ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false === $done ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_merge_failed',
					sprintf(
						/* translators: %s: the store whose write failed. */
						__( 'The store %s refused the merge, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$moved[ $table ] = (int) $done;
		}

		// THE RELATIONSHIPS MOVE WITH THE RECORDS (#1368).
		//
		// Delete before update, and in that order for a reason: the unique key
		// pairs the parent with `user_id`, so a donor row whose pairing the
		// survivor already holds cannot become theirs, and updating it first
		// fails on the duplicate and rolls the whole merge back. Dropping it is
		// correct -- the survivor already has that membership -- and the
		// preview counted it as `duplicates` rather than as a move.
		$relationships = array();

		foreach ( self::RELATIONSHIPS as $suffix => $parent ) {
			$table = $wpdb->prefix . $suffix;

			if ( ! isset( $plan['relationships'][ $table ] ) ) {
				continue;
			}

			$dropped = $wpdb->query(
				$wpdb->prepare(
					'DELETE d FROM %i AS d INNER JOIN %i AS s ON s.%i = d.%i AND s.user_id = %d WHERE d.user_id = %d',
					$table,
					$table,
					$parent,
					$parent,
					$survivor,
					$absorbed
				)
			);

			$moved_rows = false === $dropped
				? false
				: $wpdb->update(
					$table,
					array( 'user_id' => $survivor ),
					array( 'user_id' => $absorbed ),
					array( '%d' ),
					array( '%d' )
				);

			if ( false === $dropped || false === $moved_rows ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_merge_failed',
					sprintf(
						/* translators: %s: the relationship table whose write failed. */
						__( 'The relationship table %s refused the merge, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$relationships[ $table ] = array(
				'moves'      => (int) $moved_rows,
				'duplicates' => (int) $dropped,
			);
		}

		// THE SURVIVOR GAINS WHAT IT DID NOT HAVE.
		//
		// The gaps are the identifiers the absorbed account held and this one
		// did not, which is the half of the rule that makes a merge of a
		// CPF-only and an RF-only account leave one login holding both.
		if ( array() !== $agreement['gaps'] ) {
			$index = array();

			foreach ( $agreement['gaps'] as $field => $hash ) {
				$index[ $field . '_hash' ] = $hash;
			}

			if ( ! $this->reindex( $survivor, $index ) ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_merge_index_failed',
					__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
				);
			}
		}

		// THE EMPTIED ACCOUNT STOPS CLAIMING WHAT IT NO LONGER HOLDS.
		//
		// Its records are gone, so an index still naming its identifiers would
		// resolve a person to a login holding nothing of theirs -- which is
		// worse than an empty slot, because it answers confidently. The LOGIN
		// stays; only what it claims is cleared.
		if ( ! $this->clear_index( $absorbed ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_merge_index_failed',
				__( 'The records were moved but the identity index refused the same change, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		$wpdb->query( 'COMMIT' );

		// Records that became this account's are useless behind an account
		// that cannot read them -- #1367's defect, one account at a time.
		do_action( 'ffc_grant_certificate_capabilities', $survivor );

		ActivityLog::log(
			'identity_accounts_merged',
			ActivityLog::LEVEL_WARNING,
			array(
				'kept'          => $survivor,
				'emptied'       => $absorbed,
				'stores'        => $moved,
				'relationships' => $relationships,
				'matched'       => $agreement['matches'],
				'gained'        => array_keys( $agreement['gaps'] ),
			),
			$actor
		);

		return array(
			'moved'         => $moved,
			'relationships' => $relationships,
			'gained'        => array_keys( $agreement['gaps'] ),
			'emptied'       => $absorbed,
		);
	}

	/**
	 * Point the identity index at what the survivor now holds.
	 *
	 * @param int                   $user_id The account.
	 * @param array<string, string> $data    Index columns to write.
	 * @return bool
	 */
	protected function reindex( int $user_id, array $data ): bool {
		return ( new UserProfileRepository() )->upsertForUserId( $user_id, $data );
	}

	/**
	 * Clear what the emptied account claims, leaving the login itself alone.
	 *
	 * @param int $user_id The emptied account.
	 * @return bool
	 */
	protected function clear_index( int $user_id ): bool {
		global $wpdb;

		$profiles = $wpdb->prefix . 'ffc_user_profiles';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) !== $profiles ) {
			return true;
		}

		$done = $wpdb->update(
			$profiles,
			array(
				'cpf_hash' => null,
				'rf_hash'  => null,
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $done;
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
