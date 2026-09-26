<?php
/**
 * Identity repair
 *
 * Rewrites one stored RF across every store that holds it, for the operator
 * working the queue #1377 delivered. The check digit says a number is wrong
 * and never what the right one is, so the correct value arrives from HR and
 * this class is what writes it (#1368).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_* tables, for which WordPress exposes no API, and a repair must read and write the live rows: a cached answer would be precisely wrong.
/**
 * Rewrite a stored RF, everywhere it is stored.
 */
class IdentityRepair {

	/**
	 * The identifier repaired when a caller names none.
	 *
	 * RF because that is the queue this class was written for. It is no longer
	 * the only one it handles: #1386 needs the same verbs over CPF, whose
	 * conflicts the production audit carries too -- the earlier note here,
	 * that the CPF path already refuses a mistyped value at the form, was
	 * true of the FORM and never of the rows already stored.
	 */
	public const FIELD = 'rf';

	/**
	 * The identifiers this class can rewrite.
	 *
	 * Both stored as an encrypted value beside a searchable hash, in the same
	 * three stores and in the same index, so one set of verbs serves both --
	 * what differs is only the rule that says a value is well formed.
	 *
	 * @since 6.28.3
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'rf', 'cpf' );

	/**
	 * Store table (unprefixed) => the encryption context that owns its shape.
	 *
	 * The context is what makes this a rewrite rather than a reimplementation:
	 * `SensitiveFieldRegistry::encrypt_fields()` is the single policy source
	 * the forward path uses, so a repair produces the same pair a submission
	 * would. Reimplementing `encrypt()` + `hash()` here would be a second
	 * policy that agrees today and drifts later.
	 *
	 * `ffc_user_profiles` is deliberately absent: it is the identity index and
	 * carries a hash with no ciphertext, so it is updated separately, through
	 * its repository.
	 */
	private const STORES = array(
		'ffc_submissions'                  => SensitiveFieldRegistry::CONTEXT_SUBMISSION,
		'ffc_self_scheduling_appointments' => SensitiveFieldRegistry::CONTEXT_APPOINTMENT,
		'ffc_recruitment_candidate'        => SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE,
	);

	/**
	 * The store that holds each identifier exactly once.
	 *
	 * `ffc_recruitment_candidate` declares `UNIQUE KEY uq_rf_hash` AND
	 * `UNIQUE KEY uq_cpf_hash`, which the other two stores do not, so it is
	 * the one place a consolidation can be refused by the schema rather than
	 * by a decision -- and it is that for either identifier.
	 *
	 * @since 6.28.3
	 * @var string
	 */
	private const UNIQUE_RF_STORE = 'ffc_recruitment_candidate';

	/**
	 * How many characters of a hash reach the log.
	 *
	 * A prefix identifies the finding across two log lines without being the
	 * hash: `CLAUDE.md` forbids logging raw PII and a full hash of a
	 * seven-digit number is not far from the number, since the space is small
	 * enough to enumerate against a known salt.
	 */
	public const LOG_PREFIX = 12;

	/**
	 * Everything a repair decides, without writing anything (#1397 sprint 4).
	 *
	 * SPLIT OUT FOR THE REASON `IdentityRelink::moving()` WAS.
	 *
	 * The screen has to tell an operator what the correction would do BEFORE
	 * they commit it -- above all that the value they confirmed already
	 * belongs to somebody else, which is a different finding rather than a
	 * failed correction. Answering that from a second copy of these checks is
	 * how a preflight and a write come to disagree about who owns a number, so
	 * there is one path: `repair()` is this plus the write, and the preflight
	 * calls this.
	 *
	 * It reads and hashes; it writes nothing. The hash of the confirmed value
	 * is what the caller does not have and must not be told, so `shared_with`
	 * carries the ACCOUNT that holds it and never the hash itself.
	 *
	 * @since 6.28.4
	 * @param string $subject_hash The stored hash to replace.
	 * @param string $confirmed    The value HR confirmed.
	 * @param string $field        `rf` or `cpf`; defaults to {@see self::FIELD}.
	 * @param int    $only_account Restrict to one account's rows; 0 means every row carrying the hash.
	 * @return array{subject: string, found: array{rows: array<string, array<int, int>>, accounts: array<int, int>}, account: int, consolidates: bool, pair: array<string, string|null>, shared_with: int}|WP_Error
	 */
	public function plan( string $subject_hash, string $confirmed, string $field = self::FIELD, int $only_account = 0 ): array|WP_Error {
		global $wpdb;

		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error(
				'ffc_identity_repair_unknown_field',
				__( 'That is not an identifier this can rewrite.', 'ffcertificate' )
			);
		}

		$hash_column   = $field . '_hash';
		$cipher_column = $field . '_encrypted';
		$subject_hash  = trim( $subject_hash );

		if ( '' === $subject_hash ) {
			return new WP_Error(
				'ffc_identity_repair_no_subject',
				__( 'No finding was named.', 'ffcertificate' )
			);
		}

		$normalized = SensitiveFieldRegistry::normalize( $field, $confirmed );

		if ( ! self::well_formed( $field, $normalized ) ) {
			return new WP_Error(
				'ffc_identity_repair_invalid',
				__( 'That number does not satisfy its own check digits, so it cannot be the corrected value either. Confirm it with HR.', 'ffcertificate' )
			);
		}

		$new_hash = SensitiveFieldRegistry::hash_identifier( $field, $normalized );

		if ( ! is_string( $new_hash ) || '' === $new_hash ) {
			return new WP_Error(
				'ffc_identity_repair_not_configured',
				__( 'Encryption is not configured, so an identifier cannot be rewritten.', 'ffcertificate' )
			);
		}

		if ( $new_hash === $subject_hash ) {
			return new WP_Error(
				'ffc_identity_repair_unchanged',
				__( 'The value stored is already the one you confirmed.', 'ffcertificate' )
			);
		}

		$found = $this->rows_for( $subject_hash, $hash_column, $only_account );

		// Idempotent by construction: a finding repaired by somebody else
		// between the list and the confirmation resolves to nothing, and that
		// is a result rather than a failure.
		if ( array() === $found['rows'] ) {
			// A SCOPED REPAIR THAT MATCHES NOTHING IS NOT "ALREADY REPAIRED".
			//
			// Correcting the document on ONE of two logins sharing a number
			// finds nothing when that login's rows have moved or were never
			// its own, and telling the operator it was already fixed would
			// send them away from a finding that is still there.
			if ( $only_account > 0 ) {
				return new WP_Error(
					'ffc_identity_repair_not_theirs',
					__( 'That account holds no record carrying this identifier, so there is nothing of theirs to correct. Reload the queue.', 'ffcertificate' )
				);
			}

			return new WP_Error(
				'ffc_identity_repair_gone',
				__( 'Nothing carries that value any more — it was repaired already. Reload the queue.', 'ffcertificate' )
			);
		}

		// ONE CORRECTION CANNOT SERVE TWO PEOPLE.
		//
		// A finding is a hash, and two people can mistype into the same wrong
		// number. Writing the confirmed value across all of them would put one
		// person's RF on the other's row -- silently, because both rows then
		// hold a value whose check digit is fine.
		if ( count( $found['accounts'] ) > 1 ) {
			return new WP_Error(
				'ffc_identity_repair_ambiguous',
				sprintf(
					/* translators: %d: how many accounts the finding names. */
					__( 'This finding names %d accounts, so one corrected value cannot serve it: the same wrong number was typed by more than one person. Resolve them separately.', 'ffcertificate' ),
					count( $found['accounts'] )
				)
			);
		}

		$account      = $found['accounts'][0] ?? 0;
		$collides     = $this->rows_for( $new_hash, $hash_column );
		$consolidates = false;
		$shared_with  = 0;

		if ( array() !== $collides['rows'] ) {
			// A COLLISION ON THE SAME ACCOUNT IS THE TYPO ITSELF.
			//
			// This refusal used to read "the value already exists", and that
			// is the wrong predicate: in the typo the correct value IS the
			// account's other RF, so it always has rows and the refusal fired
			// on every case production has -- all 32 of them (#1386). What
			// makes a correction a merge is the value belonging to ANOTHER
			// account; the same account holding it twice is one person's own
			// duplicate, which is what a repair consolidates.
			//
			// Strict equality against a single-element list is the whole rule:
			// an unowned colliding row (an unpromoted candidacy) contributes
			// no account, so a collision that names nobody, or names anybody
			// else, still refuses.
			$consolidates = $account > 0 && array( $account ) === $collides['accounts'];

			// Whoever holds the confirmed value that is not this account.
			$others = array_values( array_diff( $collides['accounts'], array( $account ) ) );

			if ( ! $consolidates ) {
				// ONE OTHER ACCOUNT IS NOT A MERGE -- IT IS THE CORRECTION'S
				// CONSEQUENCE (#1478).
				//
				// This refused, and the refusal said the write "would merge two
				// identities rather than fix a typo". It would not: no record
				// moves and no account is absorbed, the right number simply
				// lands on the row it belongs to. What the write PRODUCES is
				// two accounts carrying one number -- which is the
				// `cross_store_shared_identities` finding, where the merge is
				// the right verb and is decided separately with the evidence in
				// front of the operator.
				//
				// So the refusal blocked the typo fix because of its own correct
				// consequence, and blocked it in both directions: the merge
				// needs one value per account and the correction needed the
				// value to be unused, so on a pair like #1477's the two verbs
				// deadlocked, each waiting on the other's precondition.
				//
				// It never was the guard against mistyping it reads as, either:
				// confirming a valid number belonging to somebody with no
				// account here passes today and still will. It only ever caught
				// the subset where the wrong target happened to be in this
				// database.
				//
				// Two collisions stay refused, and for reasons the above does
				// not cover. Rows naming NOBODY -- an unpromoted candidacy --
				// leave a state with no finding at all, since every
				// shared-identifier check is per account: nothing would ever
				// report it. And more than one other account would make a third
				// claimant of one number, which is not a consequence anybody
				// asked for.
				if ( $account > 0 && array() !== $collides['accounts'] && 1 === count( $others ) ) {
					$shared_with = (int) $others[0];
				} else {
					// THE ACCOUNT TRAVELS WITH THE REFUSAL, AND THE HASH DOES NOT.
					//
					// A screen that only receives the sentence can say the value
					// belongs to somebody, never to WHOM -- so the operator's next
					// step is a search they should not have to run, for an answer
					// this method already holds. `data` carries the account id, an
					// ordinary admin-visible number; the hash of the confirmed
					// value stays here (#1397 sprint 4).
					return new WP_Error(
						'ffc_identity_repair_collision',
						__( 'That value is already stored against records this cannot attribute to one other account — either they belong to nobody, or to more than one. Correcting into it would leave a claim on the number that no finding on this screen would ever report.', 'ffcertificate' ),
						array( 'account' => $collides['accounts'][0] ?? 0 )
					);
				}
			}

			$unique = $wpdb->prefix . self::UNIQUE_RF_STORE;

			// ONE STORE CANNOT HOLD TWO ROWS WITH ONE IDENTIFIER.
			//
			// `ffc_recruitment_candidate` declares `UNIQUE KEY uq_rf_hash`, so
			// where both sides are candidacies the rewrite would leave two rows
			// sharing one hash and the database would refuse it -- as a
			// duplicate-key error, which says nothing an operator can act on.
			// Refusing here says what is in the way instead.
			//
			// IT NOW GUARDS THE CROSS-ACCOUNT CASE TOO, AND THAT NEEDED NO
			// MOVE (#1478). It has always sat outside the consolidation test;
			// what made it consolidation-only was the early return above, so
			// letting that branch fall through brings the new case under it for
			// free. Worth writing down because the opposite is what it looks
			// like: a guard whose comment says "consolidation" reads as one the
			// new path bypasses, and a reviewer checking for that would have
			// moved code that was already correct.
			if ( isset( $found['rows'][ $unique ], $collides['rows'][ $unique ] ) ) {
				return new WP_Error(
					'ffc_identity_repair_unique_store',
					sprintf(
						/* translators: %s: the store that cannot hold two rows with one identifier. */
						__( 'Both identifiers are recorded in %s, which holds each identifier once, so writing the corrected value there would leave two records claiming one. Decide what becomes of the second record first.', 'ffcertificate' ),
						$unique
					)
				);
			}
		}

		$pair = SensitiveFieldRegistry::encrypt_fields(
			SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array( $field => $normalized )
		);

		if ( empty( $pair[ $cipher_column ] ) || empty( $pair[ $hash_column ] ) ) {
			return new WP_Error(
				'ffc_identity_repair_not_configured',
				__( 'Encryption is not configured, so an identifier cannot be rewritten.', 'ffcertificate' )
			);
		}

		return array(
			'subject'      => $subject_hash,
			'found'        => $found,
			'account'      => $account,
			'consolidates' => $consolidates,
			'pair'         => $pair,
			// Who holds the confirmed value, when anybody does. Zero when the
			// colliding rows name no account at all -- an unpromoted
			// candidacy -- which is why the refusal above cannot be rebuilt
			// from this number and is returned by this method instead.
			// WHO ELSE HOLDS THE CONFIRMED VALUE, AND ZERO WHEN NOBODY DOES.
			//
			// Not `accounts[0]`, which this used to be: on the production case
			// the colliding rows include the subject's OWN other row, so the
			// first account is often this one and naming it would tell the
			// operator their correction collides with themselves. This is the
			// other account specifically, which is the one the acknowledgement
			// is about.
			'shared_with'  => $shared_with,
		);
	}

	/**
	 * Repair one stored RF.
	 *
	 * RE-EVALUATED, NEVER TRUSTED FROM THE LIST
	 *
	 * The only thing this takes from the caller is the subject hash and the
	 * value HR confirmed -- never the row ids the screen displayed. Between
	 * listing a finding and confirming it the operator went and asked a
	 * person, and rows may have arrived or left in the meantime. So the rows
	 * are resolved here, at write time, from the hash.
	 *
	 * @param string $subject_hash The stored hash to replace.
	 * @param string $new_rf       The value HR confirmed.
	 * @param int    $actor        Who confirmed it, for the log.
	 * @param string $field        `rf` or `cpf`; defaults to {@see self::FIELD}.
	 * @param int    $only_account Restrict to one account's rows; 0 means every row carrying the hash.
	 * @param bool   $acknowledged The operator stated they understand the value already belongs to another account. Required only then.
	 * @return array{hash: string, rows: array<string, int>, account: int, shared_with: int}|WP_Error
	 */
	public function repair( string $subject_hash, string $new_rf, int $actor = 0, string $field = self::FIELD, int $only_account = 0, bool $acknowledged = false ): array|WP_Error {
		global $wpdb;

		$plan = $this->plan( $subject_hash, $new_rf, $field, $only_account );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		// THE CORRECTION THAT SHARES A NUMBER IS ACKNOWLEDGED, NOT REFUSED
		// (#1478), AND THE GATE IS HERE RATHER THAN ON THE SCREEN.
		//
		// The `IdentityRelink` precedent reads the tier off an UNTRUSTED posted
		// key in the page handler, and says plainly why that is proportionate
		// there. Here it does not have to be: whether the confirmed value
		// already belongs to somebody is a fact `plan()` has just MEASURED, so
		// the gate sits with the fact and holds for every caller rather than
		// for the one form that remembers to post a box.
		//
		// What earns a gate at all is that this write cannot be undone by
		// another use of this verb. Putting the row back means confirming the
		// value it used to hold, and that value fails its own check digit --
		// which `plan()` refuses, correctly, before anything else. So the
		// screen offers no way back, and the operator has to have meant it.
		if ( $plan['shared_with'] > 0 && ! $acknowledged ) {
			return new WP_Error(
				'ffc_identity_repair_unacknowledged',
				sprintf(
					/* translators: %s: the account that already holds the confirmed value. */
					__( 'The value you confirmed already belongs to account %s. Correcting this one is not a merge and moves no records — but afterwards both accounts carry the number, and this correction cannot be undone here, because putting the old value back means confirming a number that fails its own check digit. Confirm that you mean to, and then decide the merge under "Two accounts, one number".', 'ffcertificate' ),
					(string) $plan['shared_with']
				),
				array( 'account' => $plan['shared_with'] )
			);
		}

		$hash_column   = $field . '_hash';
		$cipher_column = $field . '_encrypted';
		$subject_hash  = $plan['subject'];
		$found         = $plan['found'];
		$account       = $plan['account'];
		$consolidates  = $plan['consolidates'];
		$pair          = $plan['pair'];

		$written = array();

		// The index and the rows must not be able to disagree, and this writes
		// to as many as four tables. Rolled back as one on any failure --
		// which assumes InnoDB, as every ffc_* table is.
		$wpdb->query( 'START TRANSACTION' );

		// THE SCOPE HAS TO BE IN THE `WHERE`, NOT ONLY IN THE PLAN.
		//
		// A scoped repair corrects the document on ONE of two logins sharing
		// a number. `plan()` lists that login's rows, but the update matches
		// on the hash -- so without the account here it would rewrite the
		// other login's rows too, which is the defect this scope exists to
		// prevent, applied with the operator's blessing.
		$where        = array( $hash_column => $subject_hash );
		$where_format = array( '%s' );

		if ( $only_account > 0 ) {
			$where['user_id'] = $only_account;
			$where_format[]   = '%d';
		}

		foreach ( $found['rows'] as $table => $ids ) {
			$done = $wpdb->update(
				$table,
				array(
					$cipher_column => (string) $pair[ $cipher_column ],
					$hash_column   => (string) $pair[ $hash_column ],
				),
				$where,
				array( '%s', '%s' ),
				$where_format
			);

			if ( false === $done ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error(
					'ffc_identity_repair_failed',
					sprintf(
						/* translators: %s: the store whose update failed. */
						__( 'The store %s refused the write, so nothing was changed.', 'ffcertificate' ),
						$table
					)
				);
			}

			$written[ $table ] = count( $ids );
		}

		if ( $account > 0 && ! $this->reindex( $account, (string) $pair[ $hash_column ], $hash_column ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'ffc_identity_repair_index_failed',
				__( 'The rows were rewritten but the identity index refused the same value, so nothing was changed. The two must never disagree.', 'ffcertificate' )
			);
		}

		$wpdb->query( 'COMMIT' );

		// #1367's defect, one account at a time: a record that becomes
		// readable is useless behind an account that cannot read it. The
		// action rather than a direct call because the capability owner is a
		// module this one has no edge to.
		if ( $account > 0 ) {
			do_action( 'ffc_grant_certificate_capabilities', $account );
		}

		ActivityLog::log(
			'identity_rf_repaired',
			ActivityLog::LEVEL_WARNING,
			array(
				// Prefixes, never the hashes and never the value: the log is
				// read by more people than the screen is.
				'from'    => substr( $subject_hash, 0, self::LOG_PREFIX ),
				'to'      => substr( (string) $pair[ $hash_column ], 0, self::LOG_PREFIX ),
				'field'   => $field,
				'stores'  => $written,
				'account' => $account,
				// A consolidation merged two of one person's own records; a
				// plain repair renamed one. Same action, different blast
				// radius, and the log is where that is legible afterwards.
				'merged'  => $consolidates,
			),
			$actor
		);

		return array(
			'hash'        => (string) $pair[ $hash_column ],
			'rows'        => $written,
			'account'     => $account,
			// So the caller can say what the correction produced: the screen's
			// success line names the account now sharing the number and where
			// the merge is decided, which is the operator's next step.
			'shared_with' => $plan['shared_with'],
		);
	}

	/**
	 * Consolidate one account's mistyped identifier into its sound one.
	 *
	 * THE VALUE IS RESOLVED HERE, SO NO SCREEN EVER HOLDS IT.
	 *
	 * On a mechanical finding the correct value is not something HR has to
	 * supply: it is the account's OTHER identifier, which the check digits
	 * already vouched for. Passing it through the browser to come back in a
	 * form field would put a stored RF or CPF in a URL, a POST body and an
	 * operator's screen for no reason at all -- so the caller names the two
	 * HASHES and this reads the value, in memory, and hands it straight to
	 * {@see self::repair()}.
	 *
	 * Every refusal that method makes therefore still applies, and one of them
	 * carries this: the consolidation is only allowed because both hashes sit
	 * on ONE account, which is exactly the collision rule scoped in #1386.
	 *
	 * @since 6.28.3
	 * @param string $wrong_hash The stored hash that fails its check digits.
	 * @param string $right_hash The stored hash to consolidate into.
	 * @param int    $actor      Who confirmed it, for the log.
	 * @param string $field      `rf` or `cpf`; defaults to {@see self::FIELD}.
	 * @param bool   $acknowledged The operator stated they understand the sound value already belongs to another account. Required only then.
	 * @return array{hash: string, rows: array<string, int>, account: int, shared_with: int}|WP_Error
	 */
	public function consolidate( string $wrong_hash, string $right_hash, int $actor = 0, string $field = self::FIELD, bool $acknowledged = false ): array|WP_Error {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error(
				'ffc_identity_repair_unknown_field',
				__( 'That is not an identifier this can rewrite.', 'ffcertificate' )
			);
		}

		$right_hash = trim( $right_hash );

		if ( '' === $right_hash || trim( $wrong_hash ) === $right_hash ) {
			return new WP_Error(
				'ffc_identity_consolidate_no_target',
				__( 'No sound identifier was named to consolidate into.', 'ffcertificate' )
			);
		}

		$plain = $this->value_of( $right_hash, $field );

		// A TARGET THAT CANNOT BE READ IS NOT A TARGET.
		//
		// The queue only offers this where the value decrypted and satisfied
		// its check digits, but the queue was built from a scan taken earlier
		// -- the same reason `repair()` resolves its rows at write time rather
		// than trusting the ones the screen displayed. Between the two the key
		// can change, and a consolidation into a value nobody can read would
		// write a guess over the evidence.
		if ( null === $plain ) {
			return new WP_Error(
				'ffc_identity_consolidate_unreadable',
				__( 'The identifier this would consolidate into could not be read, so there is nothing to write. Confirm the correct number with HR and enter it instead.', 'ffcertificate' )
			);
		}

		// THE ACKNOWLEDGEMENT PASSES THROUGH, AND IT HAS TO (#1478).
		//
		// This is the mechanical panel's verb, and the production case that
		// prompted the change is exactly one of these: the account's sound RF
		// is ALSO on a second login, so consolidating its own typo into it
		// shares the number. Swallowing the flag here would leave that account
		// unresolvable while the manual repair beside it went through.
		//
		// It is not scoped to one account either, deliberately: `repair()`
		// resolves the rows from the hash, and the screen offering this has
		// already established the two values are one person's.
		return $this->repair( $wrong_hash, $plain, $actor, $field, 0, $acknowledged );
	}

	/**
	 * Read one stored identifier back, in memory.
	 *
	 * Returns null rather than a partial answer: a value that does not decrypt
	 * is not a value, and every caller here treats it as a refusal.
	 *
	 * @since 6.28.3
	 * @param string $hash  The stored hash.
	 * @param string $field `rf` or `cpf`.
	 * @return string|null The normalized value, or null.
	 */
	protected function value_of( string $hash, string $field ): ?string {
		global $wpdb;

		$hash_column   = $field . '_hash';
		$cipher_column = $field . '_encrypted';

		foreach ( array_keys( self::STORES ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$cipher = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT %i FROM %i WHERE %i = %s AND %i IS NOT NULL AND %i <> \'\' LIMIT 1',
					$cipher_column,
					$table,
					$hash_column,
					$hash,
					$cipher_column,
					$cipher_column
				)
			);

			if ( ! is_string( $cipher ) || '' === $cipher ) {
				continue;
			}

			$plain = $this->decrypt( $cipher );

			if ( is_string( $plain ) && '' !== $plain ) {
				return $plain;
			}
		}

		return null;
	}

	/**
	 * Read one ciphertext, as a seam a test can replace without a key.
	 *
	 * @since 6.28.3
	 * @param string $cipher The stored ciphertext.
	 * @return string|null
	 */
	protected function decrypt( string $cipher ): ?string {
		return class_exists( Encryption::class ) ? Encryption::decrypt( $cipher ) : null;
	}

	/**
	 * Which rows carry a hash, and which accounts they name.
	 *
	 * @param string $hash         The hash to look for.
	 * @param string $column       The column holding it.
	 * @param int    $only_account Restrict to one account's rows; 0 means all of them.
	 * @return array{rows: array<string, list<int>>, accounts: list<int>}
	 */
	private function rows_for( string $hash, string $column = 'rf_hash', int $only_account = 0 ): array {
		global $wpdb;

		$rows     = array();
		$accounts = array();

		foreach ( array_keys( self::STORES ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// The scope is in the STATEMENT and not in a filter afterwards,
			// because what this returns decides what the write's `WHERE`
			// matches: a scope applied only in PHP would list one account's
			// rows and rewrite both.
			if ( $only_account > 0 ) {
				$found = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, user_id FROM %i WHERE %i = %s AND user_id = %d',
						$table,
						$column,
						$hash,
						$only_account
					),
					ARRAY_A
				);
			} else {
				$found = $wpdb->get_results(
					$wpdb->prepare( 'SELECT id, user_id FROM %i WHERE %i = %s', $table, $column, $hash ),
					ARRAY_A
				);
			}

			$ids = array();

			foreach ( (array) $found as $row ) {
				$ids[] = (int) ( $row['id'] ?? 0 );
				$owner = (int) ( $row['user_id'] ?? 0 );

				if ( $owner > 0 && ! in_array( $owner, $accounts, true ) ) {
					$accounts[] = $owner;
				}
			}

			if ( array() !== $ids ) {
				$rows[ $table ] = $ids;
			}
		}

		return array(
			'rows'     => $rows,
			'accounts' => $accounts,
		);
	}

	/**
	 * Point the identity index at the corrected hash.
	 *
	 * OVERWRITING IS THE DECISION HERE, AND THAT IS THE DIFFERENCE.
	 *
	 * `UserCreator::feed_identity_index()` never overwrites a populated
	 * column, deliberately, so the backfill and the forward path cannot
	 * disagree. In a repair the operator has decided against the value the
	 * index holds -- reusing that rule unexamined would make this write
	 * silently do nothing in exactly the case it exists for (#1368).
	 *
	 * @param int    $user_id  The account that owns the rows.
	 * @param string $new_hash The corrected hash.
	 * @param string $column   The index column to point at it.
	 * @return bool
	 */
	protected function reindex( int $user_id, string $new_hash, string $column = 'rf_hash' ): bool {
		return ( new UserProfileRepository() )->upsertForUserId(
			$user_id,
			array( $column => $new_hash )
		);
	}

	/**
	 * Whether a normalized identifier satisfies its own check digits.
	 *
	 * The RF rule is read directly rather than through `validate_rf()`, which
	 * consults the check digit only when the administrator enabled enforcement
	 * (#1345): whether a CORRECTION is well formed cannot depend on a setting
	 * that decides what the form accepts. `validate_cpf()` has no such switch,
	 * and verifies two digits where the RF rule verifies one.
	 *
	 * PUBLIC SINCE 6.28.4, FOR THE ONE OTHER PLACE THAT NEEDS THIS RULE.
	 *
	 * Opening an account for an orphan (#1397 sprint 6) has to judge a typed
	 * RF exactly as a correction does, and `DocumentFormatter::validate_rf()`
	 * alone is not that rule: it enforces the check digit only when the
	 * `ffc_validate_rf_check_digit` opt-in is on, because refusing a
	 * registration on an inferred rule is worse than storing a typo the audit
	 * finds later. Neither of those is a correction or an account being
	 * opened from a value somebody confirmed, so both add the digit
	 * explicitly — through this, rather than through a second copy.
	 *
	 * @since 6.28.3
	 * @param string $field      `rf` or `cpf`.
	 * @param string $normalized The value, canonicalised.
	 * @return bool
	 */
	public static function well_formed( string $field, string $normalized ): bool {
		if ( 'cpf' === $field ) {
			return DocumentFormatter::validate_cpf( $normalized );
		}

		return DocumentFormatter::validate_rf( $normalized )
			&& DocumentFormatter::rf_check_digit_matches( $normalized );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery
