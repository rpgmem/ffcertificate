<?php
/**
 * Identity acceptance
 *
 * The record of identity findings an operator has judged impossible to
 * resolve, so they stop being work without being counted as fixed (#1532).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Core\ActivityLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Findings nobody can resolve, remembered so the queue stops asking.
 *
 * WHY A STATE AT ALL, WHEN EVERY OTHER VERB HERE WRITES A ROW.
 *
 * The queue is a live scan with no memory, which is what makes it honest: a
 * finding is listed because it is true right now. That breaks for one
 * population, measured on production -- a stored CPF or RF the person never
 * supplied, which HR cannot trace back to anybody by name. There is no correct
 * value, so the correction panel asks for something nobody can give, and the
 * finding returns on every visit forever. The cost is not the row: it is that
 * the bottom of the list never empties, which is how a panel stops being read.
 * `CLAUDE.md` records that failure mode twice already -- the post-deploy
 * timeout that stays green, and the twelve deploys whose alarm nobody read.
 *
 * ACCEPTANCE IS NOT RESOLUTION, AND NOTHING HERE MAY BLUR THAT.
 *
 * The stored number still fails its check digit. The records are still behind
 * it. `IdentityMerge` and `IdentityRelink` still refuse over it, deliberately
 * -- see `accepted_hashes()`. What is recorded is that nobody can fix it,
 * which is a different fact from its being fixed, and the two have to read
 * differently everywhere they surface. In particular the verbs here are
 * declared OUTSIDE `IdentityResolutionPage::RESOLVED_ACTIONS`, so the
 * "resolved this sitting" counter never counts an acceptance.
 *
 * A DELETED ACCOUNT LEAVES ITS ACCEPTANCE BEHIND, AND THAT IS THE DECISION.
 *
 * For `cross_store_multiple_identities` the subject of the key is a `user_id`
 * (see {@see self::HASH_SUBJECT_CHECKS} for which checks are which), so an
 * acceptance survives the account it was taken about. Nothing in `UserCleanup`
 * touches this option, and nothing should: by this project's governing rule --
 * *retain records, drop relationships* -- an acceptance is a decision record,
 * like an activity-log line, and the id is the identity of its key rather than
 * a nullable column, so there is no link to drop without destroying the
 * record. It is an accepted orphan, the shape `CLAUDE.md` already records for
 * `ffc_reregistration_submissions.user_id`, and it is logged in that file's
 * gap inventory rather than only here.
 *
 * What the deletion does do is make the record INERT: the checks read the live
 * stores, so the finding it suppresses is gone from the next scan, and the
 * audit card's count -- which joins in the scan's direction, never the
 * record's -- stops counting it. It stays listed and withdrawable on the
 * screen on purpose, because a row that vanishes is a suppression nobody can
 * withdraw (#1536).
 *
 * @since 6.33.0
 */
class IdentityAcceptance {

	/**
	 * Where the record lives.
	 *
	 * An option rather than a table, on the population that motivated this:
	 * 27 open findings, from a set that had been over a hundred. An option
	 * needs no activator, so it does not engage the schema-reachability guard
	 * (#1311), and it is declared in `uninstall.php` -- which the fresh-install
	 * gate reads as an enforced manifest in both directions.
	 *
	 * It is unbounded in principle. The trigger for moving it to a table is
	 * stated rather than pre-empted, the way `CLAUDE.md` settles #788 / #902 /
	 * #993: an install whose accepted set stops being comfortable to read on
	 * every render of the screen. Not a number guessed here.
	 *
	 * @var string
	 */
	public const OPTION = 'ffc_identity_accepted';

	/**
	 * The person never supplied the number.
	 *
	 * @var string
	 */
	public const REASON_NEVER_SUPPLIED = 'never_supplied';

	/**
	 * HR cannot say whose the number is.
	 *
	 * @var string
	 */
	public const REASON_UNIDENTIFIABLE = 'unidentifiable';

	/**
	 * Why a finding was accepted -- a CLOSED set, and no free text.
	 *
	 * The two entries are the two cases measured on production, and a closed
	 * set is the decision rather than a simplification. A note field is where
	 * an operator writes the number itself, and this option is not a place for
	 * a plaintext CPF: `CLAUDE.md` is explicit that an identifier never lands
	 * anywhere a log or an export can carry it in the clear. A closed set also
	 * makes the accepted list countable by reason, which free text is not.
	 *
	 * A third reason is added when a third case turns up in practice, which is
	 * the same extract-on-proven-need rule the parked decisions use.
	 *
	 * @var array<int, string>
	 */
	public const REASONS = array(
		self::REASON_NEVER_SUPPLIED,
		self::REASON_UNIDENTIFIABLE,
	);

	/**
	 * Logged when a finding is accepted.
	 *
	 * @var string
	 */
	public const LOG_ACCEPTED = 'identity_accepted_unresolvable';

	/**
	 * Logged when an acceptance is withdrawn.
	 *
	 * @var string
	 */
	public const LOG_WITHDRAWN = 'identity_acceptance_withdrawn';

	/**
	 * The identity actions that are deliberately NOT resolutions.
	 *
	 * `IdentityResolutionPage::RESOLVED_ACTIONS` is checked against every
	 * `identity_*` action logged under this directory, in both directions, so
	 * that a sixth verb cannot be added without the counter learning about it.
	 * These two are the first actions to which the answer is "counted by
	 * nothing, on purpose" rather than "add it to the list" -- so they are
	 * declared here, beside the code that logs them, and the guard subtracts
	 * this set instead of growing the other one.
	 *
	 * @var array<int, string>
	 */
	public const NOT_RESOLUTIONS = array(
		self::LOG_ACCEPTED,
		self::LOG_WITHDRAWN,
	);

	/**
	 * How many characters of a hash the log may carry.
	 *
	 * The same reasoning and the same number as `IdentityRepair::LOG_PREFIX`,
	 * and deliberately its own constant for the same reason that one is not
	 * `IdentityQueue::DISPLAY_PREFIX`: what a log keeps and what a screen shows
	 * are free to diverge.
	 *
	 * @var int
	 */
	public const LOG_PREFIX = 12;

	/**
	 * The checks whose `subject` is a hash rather than an account id.
	 *
	 * `IdentityConflictQuery::grouped()` aliases whatever it grouped by as
	 * `subject`, so the column name means a hash in two of the three checks
	 * this queue composes and a user id in the third. That is the trap
	 * `IdentityAuditExportSource` already records, and it is why the key below
	 * carries the CHECK: without it, "accepted" would be a claim about a value
	 * whose meaning nothing states.
	 *
	 * Referenced off `IdentityQueue` rather than retyped, so a rename moves
	 * both halves at once.
	 *
	 * @var array<int, string>
	 */
	public const HASH_SUBJECT_CHECKS = array(
		IdentityQueue::CHECK_SHARED,
		IdentityQueue::CHECK_DIGITS,
	);

	/**
	 * Record field: which check produced the finding.
	 *
	 * @var string
	 */
	public const FIELD_CHECK = 'check';

	/**
	 * Record field: which identifier the finding is about, `rf` or `cpf`.
	 *
	 * THE FIELD, NOT THE COLUMN. `IdentityRepair::FIELDS` is the public
	 * vocabulary -- `IdentityAgreement` speaks it, the refusals speak it, and
	 * the page already validates a posted one through `posted_field()`, while
	 * `IdentityConflictQuery::COLUMNS` is private. The column is `$field .
	 * '_hash'` wherever one is needed, which is the idiom `IdentityRepair` and
	 * `IdentityRecordNames` already repeat; carrying the column here would add
	 * a fourth place encoding that relation for nothing.
	 *
	 * @var string
	 */
	public const FIELD_IDENTIFIER = 'field';

	/**
	 * Record field: the finding's subject, as its check means it.
	 *
	 * @var string
	 */
	public const FIELD_SUBJECT = 'subject';

	/**
	 * Record field: the tier the finding held WHEN it was accepted.
	 *
	 * THE RE-SURFACE SIGNAL, AND THE REASON THE TIER IS NOT IN THE KEY.
	 *
	 * A tier is derived from the account's identifier set and the check
	 * digits' verdicts on it, so it moves when the situation does: an
	 * `isolated` failure whose account later gains a valid sibling becomes
	 * `mechanical`, which is one click from correct. A finding accepted as
	 * unresolvable in one shape has not been judged in the other, so the
	 * acceptance stops applying and the finding returns to the work list
	 * carrying {@see IdentityQueue::COLUMN_WAS_ACCEPTED} -- which is what keeps
	 * this from being a way to bury a finding that became fixable.
	 *
	 * Storing it rather than keying on it is what makes the two properties
	 * coexist: keyed on the tier an acceptance would evaporate on any shape
	 * change; keyed without it at all, a finding that became fixable would
	 * stay buried. The second is the one worth fearing.
	 *
	 * @var string
	 */
	public const FIELD_TIER = 'tier';

	/**
	 * Record field: one of {@see self::REASONS}.
	 *
	 * @var string
	 */
	public const FIELD_REASON = 'reason';

	/**
	 * Record field: when it was accepted.
	 *
	 * Category A of `CLAUDE.md`'s date convention -- an instant, stored as
	 * `time()` and rendered through `DateFormatter`, never `current_time()`,
	 * which drifts when the site's timezone changes.
	 *
	 * @var string
	 */
	public const FIELD_AT = 'at';

	/**
	 * Record field: who accepted it.
	 *
	 * @var string
	 */
	public const FIELD_BY = 'by';

	/**
	 * A finding's stable identity in this record.
	 *
	 * CHECK, COLUMN AND SUBJECT -- deliberately not the queue's own key, which
	 * is tier-plus-column-plus-subject. The two answer different questions: the
	 * queue's key identifies a finding within one scan, where the tier is part
	 * of what the operator is looking at, while this one has to survive the
	 * tier changing and must say what the subject MEANS. See
	 * {@see self::FIELD_TIER} and {@see self::HASH_SUBJECT_CHECKS}.
	 *
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @return string
	 */
	public static function key( string $check, string $field, string $subject ): string {
		return implode( IdentityQueue::KEY_SEPARATOR, array( $check, $field, $subject ) );
	}

	/**
	 * The key covering one audit finding, or an empty string when none can.
	 *
	 * ONE OWNER FOR A RULE THREE SURFACES APPLY
	 *
	 * Building `check|field|subject` out of a scan row is four decisions --
	 * which checks are in scope, where the subject lives, that the stored
	 * field is the column without its `_hash` suffix, and that a row carrying
	 * no subject cannot be keyed at all -- and until #1536 each of the three
	 * consumers made them itself: the queue's filter, the CSV's column, and
	 * the audit card's count. Two surfaces reading one option through two
	 * copies of a rule is how they come to disagree about the same join, which
	 * is why `.github/scripts/ffc-create-statements.php` is shared between the
	 * two guards that read it rather than written twice.
	 *
	 * IT RETURNS THE KEY AND NOT THE RECORD, on purpose. What a miss MEANS is
	 * the caller's: the CSV prints `open` for a finding nobody has judged and
	 * leaves the column blank for one that cannot be judged at all, while the
	 * card counts neither. A method answering `?array` would collapse those
	 * two into one `null` and hand each caller back the branch it just
	 * delegated.
	 *
	 * @param string               $check The auditor's key for the check.
	 * @param array<string, mixed> $row   One finding, as the auditor returns it.
	 * @return string The key, or `''` when this row cannot carry an acceptance.
	 */
	public static function key_for_row( string $check, array $row ): string {
		// The checks the identity screen composes, and only those: nothing
		// else has a card to accept from, so a key built for one could never
		// be written and would read as open rather than as out of scope.
		if ( ! in_array( $check, IdentityQueue::CHECKS, true ) ) {
			return '';
		}

		$subject = isset( $row['subject'] ) ? (string) $row['subject'] : '';

		if ( '' === $subject ) {
			return '';
		}

		// THE FIELD, NEVER THE COLUMN. `IdentityRepair::FIELDS` is the public
		// vocabulary the refusals and the accept forms speak, and the column
		// is that name plus `_hash`; keying on the column would make a record
		// written from the screen unfindable from a scan row.
		return self::key(
			$check,
			str_replace( '_hash', '', isset( $row['identifier_column'] ) ? (string) $row['identifier_column'] : '' ),
			$subject
		);
	}

	/**
	 * Every acceptance on record, newest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $key => $record ) {
			// A RECORD THAT CANNOT BE READ IS DROPPED, NEVER REPAIRED.
			//
			// An option holds whatever is in it, including what an earlier
			// release wrote, so the shape is checked before it is trusted --
			// the idiom every state reader here uses. Dropping an unreadable
			// record returns its finding to the work list, which is the safe
			// direction: the operator is asked again rather than a suppression
			// surviving on a shape nothing understands.
			if ( ! is_array( $record ) || ! isset( $record[ self::FIELD_SUBJECT ], $record[ self::FIELD_CHECK ] ) ) {
				continue;
			}

			$out[ (string) $key ] = $record;
		}

		uasort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) ( $b[ self::FIELD_AT ] ?? 0 ) <=> (int) ( $a[ self::FIELD_AT ] ?? 0 );
			}
		);

		return $out;
	}

	/**
	 * One acceptance, or null when the finding is not accepted.
	 *
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @return array<string, mixed>|null
	 */
	public function record( string $check, string $field, string $subject ): ?array {
		$all = $this->all();
		$key = self::key( $check, $field, $subject );

		return $all[ $key ] ?? null;
	}

	/**
	 * The tier a finding was accepted under, or null when it is not accepted.
	 *
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @return string|null
	 */
	public function accepted_tier( string $check, string $field, string $subject ): ?string {
		$record = $this->record( $check, $field, $subject );

		if ( null === $record ) {
			return null;
		}

		$tier = $record[ self::FIELD_TIER ] ?? '';

		return is_string( $tier ) && '' !== $tier ? $tier : null;
	}

	/**
	 * The accepted hashes of one identifier column, as a set.
	 *
	 * WHAT THIS IS FOR, AND WHAT IT DELIBERATELY DOES NOT DO.
	 *
	 * `IdentityMerge` and `IdentityRelink` refuse while a shared identifier
	 * fails its own check digit, and both tell the operator to correct it
	 * under "Numbers to correct" -- the panel an acceptance takes it out of.
	 * Left alone, that is #1523 returning: a refusal pointing at a panel the
	 * finding has left.
	 *
	 * So they read this and say the right thing instead. They do NOT stop
	 * refusing. An accepted number is still a number that cannot be anybody's,
	 * so it is still not evidence that two accounts are one person -- and
	 * "nobody can fix it" is not a licence to merge on evidence known to be
	 * bad. The acceptance changes the work list and the sentence, never the
	 * verdict.
	 *
	 * Only the hash-subject checks are read, because a record from the
	 * account-side check carries a user id in the same field.
	 *
	 * @param string $field The identifier, `rf` or `cpf`.
	 * @return array<string, true>
	 */
	public function accepted_hashes( string $field ): array {
		$out = array();

		foreach ( $this->all() as $record ) {
			if ( (string) ( $record[ self::FIELD_IDENTIFIER ] ?? '' ) !== $field ) {
				continue;
			}

			if ( ! in_array( (string) ( $record[ self::FIELD_CHECK ] ?? '' ), self::HASH_SUBJECT_CHECKS, true ) ) {
				continue;
			}

			$subject = (string) ( $record[ self::FIELD_SUBJECT ] ?? '' );

			if ( '' !== $subject ) {
				$out[ $subject ] = true;
			}
		}

		return $out;
	}

	/**
	 * Whether any of these hashes is accepted for this identifier.
	 *
	 * The question the two refusals ask, in the one shape that answers it: they
	 * hold the hashes a side carries per identifier, and need to know whether
	 * the one blocking them is a number somebody already judged unfixable. An
	 * intersection rather than a lookup, because a side may carry more than one
	 * value for a field and the refusal is about whichever of them is accepted.
	 *
	 * @param string             $field  The identifier, `rf` or `cpf`.
	 * @param array<int, string> $hashes The hashes that side carries for it.
	 * @return bool
	 */
	public function any_accepted( string $field, array $hashes ): bool {
		if ( array() === $hashes ) {
			return false;
		}

		$accepted = $this->accepted_hashes( $field );

		if ( array() === $accepted ) {
			return false;
		}

		foreach ( $hashes as $hash ) {
			if ( isset( $accepted[ (string) $hash ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Accept a finding as impossible to resolve.
	 *
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @param string $tier    The tier the finding holds now.
	 * @param string $reason  One of {@see self::REASONS}.
	 * @param int    $by      The operator.
	 * @return true|\WP_Error
	 */
	public function accept( string $check, string $field, string $subject, string $tier, string $reason, int $by ) {
		if ( '' === $check || '' === $subject || '' === $tier ) {
			return new \WP_Error(
				'identity_accept_incomplete',
				__( 'That finding could not be identified, so nothing was accepted.', 'ffcertificate' )
			);
		}

		// The field decides which column every consumer reads, so a value the
		// repair service would refuse is refused here rather than stored and
		// read back as a suppression nothing can match.
		if ( ! in_array( $field, IdentityRepair::FIELDS, true ) ) {
			return new \WP_Error(
				'identity_accept_unknown_field',
				__( 'That identifier is not one this screen manages, so nothing was accepted.', 'ffcertificate' )
			);
		}

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return new \WP_Error(
				'identity_accept_no_reason',
				__( 'Choose why this finding cannot be resolved.', 'ffcertificate' )
			);
		}

		$all = $this->all();

		$all[ self::key( $check, $field, $subject ) ] = array(
			self::FIELD_CHECK      => $check,
			self::FIELD_IDENTIFIER => $field,
			self::FIELD_SUBJECT    => $subject,
			self::FIELD_TIER       => $tier,
			self::FIELD_REASON     => $reason,
			self::FIELD_AT         => time(),
			self::FIELD_BY         => $by,
		);

		$this->put( $all );

		$this->log( self::LOG_ACCEPTED, $check, $field, $subject, $tier, $reason, $by );

		return true;
	}

	/**
	 * Withdraw an acceptance, putting the finding back in the queue.
	 *
	 * AS REACHABLE AS THE ACCEPT, by design. A judgement made from incomplete
	 * information is the normal case here -- HR identifying somebody a year
	 * later must not need a developer.
	 *
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @param int    $by      The operator.
	 * @return true|\WP_Error
	 */
	public function withdraw( string $check, string $field, string $subject, int $by ) {
		$all = $this->all();
		$key = self::key( $check, $field, $subject );

		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error(
				'identity_accept_gone',
				__( 'That acceptance is no longer on record, so there was nothing to withdraw.', 'ffcertificate' )
			);
		}

		$record = $all[ $key ];

		unset( $all[ $key ] );
		$this->put( $all );

		$this->log(
			self::LOG_WITHDRAWN,
			$check,
			$field,
			$subject,
			(string) ( $record[ self::FIELD_TIER ] ?? '' ),
			(string) ( $record[ self::FIELD_REASON ] ?? '' ),
			$by
		);

		return true;
	}

	/**
	 * Persist the record.
	 *
	 * `autoload` false: it is read by one screen and two refusals, never on an
	 * ordinary front-end request.
	 *
	 * @param array<string, array<string, mixed>> $all The whole record.
	 * @return void
	 */
	private function put( array $all ): void {
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Write the decision to the activity log.
	 *
	 * A PREFIX, NEVER THE HASH AND NEVER THE VALUE -- the rule
	 * `IdentityRepair` already follows, for the reason it gives: the log is
	 * read by more people than the screen is, and the hash of a seven-digit
	 * number is not far from the number.
	 *
	 * WARNING rather than INFO, like every verb beside it: suppressing work is
	 * a decision somebody may need to find afterwards.
	 *
	 * THE SEAM IS `write()` BELOW, NOT THIS METHOD. An `alias:` mock of
	 * `ActivityLog` cannot work here -- an alias replaces the class, so
	 * `LEVEL_WARNING` stops existing and this dies before it logs anything --
	 * and a seam at THIS level would be above the truncation, so a test
	 * replacing it would observe the whole hash and prove the opposite of what
	 * it set out to. The seam sits where the context is already built.
	 *
	 * @param string $action  One of {@see self::NOT_RESOLUTIONS}.
	 * @param string $check   The auditor's key for the check.
	 * @param string $field   The identifier, `rf` or `cpf`.
	 * @param string $subject The finding's subject.
	 * @param string $tier    The tier recorded.
	 * @param string $reason  The reason recorded.
	 * @param int    $by      The operator.
	 * @return void
	 */
	private function log( string $action, string $check, string $field, string $subject, string $tier, string $reason, int $by ): void {
		$this->write(
			$action,
			array(
				'check'   => $check,
				'field'   => $field,
				// An account id is already a number this log carries elsewhere;
				// a hash is truncated. Which one this is depends on the check,
				// so the prefix is taken unconditionally -- a 12-character
				// account id does not exist, so nothing is lost.
				'subject' => substr( $subject, 0, self::LOG_PREFIX ),
				'tier'    => $tier,
				'reason'  => $reason,
			),
			$by
		);
	}

	/**
	 * Hand one line to the activity log.
	 *
	 * The seam, for the reason `log()` above states: it sits BELOW the
	 * truncation, so a test replacing it sees the context that would really be
	 * written rather than the arguments that went in.
	 *
	 * @param string               $action  One of {@see self::NOT_RESOLUTIONS}.
	 * @param array<string, mixed> $context What to record.
	 * @param int                  $by      The operator.
	 * @return void
	 */
	protected function write( string $action, array $context, int $by ): void {
		ActivityLog::log( $action, ActivityLog::LEVEL_WARNING, $context, $by );
	}
}
