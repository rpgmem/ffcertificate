<?php
/**
 * Identity queue
 *
 * Turns the identity audit's findings into a worklist an operator can act on,
 * by tier: what the check digits decide on their own, what needs a person, and
 * what is not one account's problem at all (#1386).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compose the identity findings into a tiered worklist.
 */
class IdentityQueue {

	/**
	 * One account, two identifiers, exactly one of which fails its check
	 * digits. The wrong one is identified and the right one is the other, so
	 * the correction needs no value from anybody.
	 *
	 * @var string
	 */
	public const TIER_MECHANICAL = 'mechanical';

	/**
	 * One account, more than one identifier, and the check digits do not
	 * single one out -- none fails, or more than one does, or there are more
	 * than two. A person decides: repair, split or relink.
	 *
	 * @var string
	 */
	public const TIER_DECISION = 'decision';

	/**
	 * One identifier, more than one account. Not a typo on anybody's record:
	 * the decision is which account survives, which is a merge.
	 *
	 * @var string
	 */
	public const TIER_SHARED = 'shared';

	/**
	 * One account whose identifiers share an address and are not variants of
	 * each other -- the shared-institutional-mailbox reading (#1368).
	 *
	 * The other account-side tiers rest on one premise: an address identifies
	 * a person, so an address appearing under two of the account's
	 * identifiers means one person typed a number twice. That premise is
	 * sound at two identifiers and degrades as the count grows -- nine CPFs
	 * across ten submissions under one address is an account submitting on
	 * behalf of other people, or a department's shared mailbox, and a REPAIR
	 * is not a thing to do to it.
	 *
	 * SO THE SCREEN WITHHOLDS CONSOLIDATE HERE, AND THAT IS THE POINT OF THE
	 * TIER. Measured on production, 38 of the account-side findings carry this
	 * shape, and every one of them was being offered consolidate, move and
	 * split -- and consolidate is the one that writes one person's number onto
	 * another person's records.
	 *
	 * MOVE AND SPLIT ARE OFFERED, WHICH THIS SENTENCE ONCE DENIED (#1461).
	 * The sweep that created this tier removed all three verbs for a harm that
	 * described one: consolidate rewrites an identifier, while a move relocates
	 * records without touching any number and a split creates an account
	 * nobody else uses. Withholding them left the screen telling an operator
	 * to decide with HR and then offering nowhere to put the answer -- and the
	 * verbs were never unreachable in the first place, since both handlers are
	 * tier-agnostic; what #1368 removed was the buttons.
	 *
	 * The two are exclusive per identifier, and where both are supplied SPLIT
	 * WINS: a wrong split leaves the records alone on a fresh account, so it is
	 * still correctable, while a move mixes them into another person's records
	 * and the data can no longer separate them. That is the merge's reasoning
	 * -- one person out of two, and nothing afterwards can tell them apart --
	 * applied here. The move therefore carries an acknowledgement and the split
	 * does not: the address a split makes the operator type is already its
	 * deliberateness gate.
	 *
	 * It is decided BEFORE the mechanical test and therefore outranks it,
	 * which refuses a two-identifier case the check digits could have
	 * resolved. That is deliberate and the costs are not symmetric: refusing
	 * a genuine typo costs an operator a manual correction, while accepting a
	 * shared mailbox costs somebody else's records. The screen says which
	 * account to read; it does not guess which reading is true.
	 *
	 * @var string
	 */
	public const TIER_MAILBOX = 'mailbox';

	/**
	 * What joins a cursor key's parts.
	 *
	 * A constant because a second reader now exists: the resolution page reads
	 * the tier off a posted key to decide whether a shared-mailbox move needs
	 * its acknowledgement. Two literals would be two places to edit the next
	 * time this has to change -- and it has changed once already, when a raw
	 * `|` turned out to be refused by the production WAF (#1459).
	 *
	 * `IdentityQueueKeyTest` holds what a separator may be, which is the rule
	 * this value has to satisfy rather than a restatement of the value.
	 *
	 * @var string
	 */
	public const KEY_SEPARATOR = '-';

	/**
	 * One identifier that fails its check digits, which no account-side
	 * finding explains: the account holds only that one, or the row holds no
	 * account at all (an unpromoted candidacy). There is nothing to
	 * consolidate into, so the correct value comes from HR.
	 *
	 * @var string
	 */
	public const TIER_ISOLATED = 'isolated';

	/**
	 * The tier of one item.
	 *
	 * @var string
	 */
	public const COLUMN_TIER = 'tier';

	/**
	 * Hash => verdict, for every identifier the item names.
	 *
	 * @var string
	 */
	public const COLUMN_VERDICTS = 'verdicts';

	/**
	 * On a mechanical item, the identifier that fails its check digits.
	 *
	 * @var string
	 */
	public const COLUMN_WRONG = 'wrong';

	/**
	 * On a mechanical item, the identifier the correction consolidates into.
	 *
	 * @var string
	 */
	public const COLUMN_RIGHT = 'right';

	/**
	 * How many characters of a hash a screen may print.
	 *
	 * A prefix identifies a finding across two rows without being the hash,
	 * and `CLAUDE.md` is explicit that a full hash of a seven-digit number is
	 * not far from the number: the space is small enough to enumerate against
	 * a known salt. Same reasoning as `IdentityRepair::LOG_PREFIX`, and
	 * deliberately not the same constant -- one is what a log keeps and the
	 * other what a screen shows, and they are free to diverge.
	 *
	 * @var int
	 */
	public const DISPLAY_PREFIX = 12;

	/**
	 * Which of the auditor's checks produced an item.
	 *
	 * NAMED AFTER `SubmissionLinkAuditor`'S KEYS ON PURPOSE.
	 *
	 * The screen and the CSV are two pipelines over overlapping questions:
	 * the export runs the `submission_link_audit` tool, whose report has seven
	 * checks, and this worklist composes three of them. An operator holding
	 * both needs one vocabulary, and the auditor's key is the one that already
	 * exists in the file they take to HR -- so an item says which check it
	 * came from, spelled exactly as the `check` column spells it.
	 *
	 * @var string
	 */
	public const CHECK_MULTIPLE = 'cross_store_multiple_identities';

	/**
	 * The shared-identifier check, as the auditor names it.
	 *
	 * @var string
	 */
	public const CHECK_SHARED = 'cross_store_shared_identities';

	/**
	 * The check-digit check, as the auditor names it.
	 *
	 * @var string
	 */
	public const CHECK_DIGITS = 'rf_check_digit';

	/**
	 * Key carrying which check produced an item.
	 *
	 * @var string
	 */
	public const COLUMN_CHECK = 'check';

	/**
	 * Key carrying an item's stable identity across two scans.
	 *
	 * A POSITION IS NOT AN IDENTITY.
	 *
	 * The list is rebuilt from a live scan, so an index means nothing the
	 * moment anything is resolved: item 3 becomes item 2 and a cursor sitting
	 * on 3 has silently moved to a different person. The key is composed of
	 * what the finding IS -- its tier, the column its identifier sits in, and
	 * the subject -- so it survives the list being taken again.
	 *
	 * @var string
	 */
	public const COLUMN_KEY = 'key';

	/**
	 * How many identifiers a mechanical item may name.
	 *
	 * Two, and not "two or more with exactly one failure". Three identifiers
	 * of which one fails leaves TWO survivors and no way to choose between
	 * them, so the check digits have not decided anything -- they have only
	 * narrowed it. Narrowing is a decision, and decisions are Tier B.
	 *
	 * @var int
	 */
	private const MECHANICAL_IDENTIFIERS = 2;

	/**
	 * The cross-store identity questions, as a seam a test can replace.
	 *
	 * @return IdentityConflictQuery
	 */
	protected function conflicts(): IdentityConflictQuery {
		return new IdentityConflictQuery();
	}

	/**
	 * The worklist, tiered.
	 *
	 * Composed of two findings rather than one, because neither alone says
	 * what to do. `multiple_identities()` says an account holds two
	 * identifiers and cannot say which is wrong; the check digits say which is
	 * wrong and, on their own, cannot say what the right one is. Together they
	 * do -- but only where exactly one of exactly two fails.
	 *
	 * @param int $limit Sample size per finding.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $limit = 100 ): array {
		$limit  = max( 1, $limit );
		$query  = $this->conflicts();
		$out    = array();
		$spoken = array();

		// RESET FIRST, so a caller reading truncation after a scan that found
		// nothing sees THIS scan's answer rather than the previous call's.
		// Same reason `rf_check_digit_failures()` resets its coverage.
		$this->truncated = array();

		$multiple = $this->capped( self::CHECK_MULTIPLE, $query->multiple_identities( $limit ), $limit );

		foreach ( $multiple as $row ) {
			$row      = (array) $row;
			$column   = (string) ( $row['identifier_column'] ?? '' );
			$hashes   = self::listed( $row[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );
			$verdicts = array() === $hashes ? array() : $query->check_digit_verdicts( $column, $hashes );

			foreach ( $hashes as $hash ) {
				$spoken[ $hash ] = true;
			}

			$out[] = $this->tiered( $row, $verdicts );
		}

		foreach ( $this->capped( self::CHECK_SHARED, $query->shared_identities( $limit ), $limit ) as $row ) {
			$row     = (array) $row;
			$subject = (string) ( $row['subject'] ?? '' );

			if ( '' !== $subject ) {
				$spoken[ $subject ] = true;
			}

			$row[ self::COLUMN_TIER ]     = self::TIER_SHARED;
			$row[ self::COLUMN_VERDICTS ] = array();
			$row[ self::COLUMN_CHECK ]    = self::CHECK_SHARED;
			$row[ self::COLUMN_KEY ]      = self::key_of( $row );

			$out[] = $row;
		}

		// EVERY FINDING APPEARS ONCE, AND THE LEFTOVERS ARE NOT NOTHING.
		//
		// A check-digit failure on an account that already appears above is
		// the SAME finding seen from the other side, and listing it twice
		// makes a worklist an operator cannot work to zero. What is left after
		// that filter is the population the account-side checks cannot see at
		// all: a person who mistyped once on their only row, and a candidacy
		// carrying no account until promotion -- which is the whole reason
		// `rf_check_digit_failures()` scans rows rather than accounts.
		foreach ( $this->capped( self::CHECK_DIGITS, $query->rf_check_digit_failures( $limit ), $limit ) as $row ) {
			$row     = (array) $row;
			$subject = (string) ( $row['subject'] ?? '' );

			if ( '' !== $subject && isset( $spoken[ $subject ] ) ) {
				continue;
			}

			$row[ self::COLUMN_TIER ]     = self::TIER_ISOLATED;
			$row[ self::COLUMN_VERDICTS ] = '' === $subject
				? array()
				: array( $subject => IdentityConflictQuery::VERDICT_INVALID );
			$row[ self::COLUMN_CHECK ]    = self::CHECK_DIGITS;
			$row[ self::COLUMN_KEY ]      = self::key_of( $row );

			$out[] = $row;
		}

		$this->coverage = $query->rf_scan_coverage();

		return $out;
	}

	/**
	 * Remember whether a check reached its cap, and hand its rows back.
	 *
	 * `$count >= $limit` IS THE WHOLE TEST, AND IT IS THE AUDITOR'S.
	 *
	 * `SubmissionLinkAuditor` already decides truncation exactly this way for
	 * its seven checks, and `IdentityAuditExportSource` already prints a row
	 * when one is hit. This screen had neither, so a capped check looked
	 * identical to a complete one -- the same shape as `#1384`, where a
	 * rejected statement and a clean install both answered with no rows.
	 *
	 * It over-reports by one case, deliberately: a check holding EXACTLY
	 * `$limit` findings is called truncated although nothing was dropped.
	 * Saying "there may be more" when there are none costs a re-run; the
	 * other error costs an operator believing a queue is empty.
	 *
	 * @param string                           $check The auditor's key.
	 * @param array<int, array<string, mixed>> $rows  What the check returned.
	 * @param int                              $limit The cap it was given.
	 * @return array<int, array<string, mixed>>
	 */
	private function capped( string $check, array $rows, int $limit ): array {
		if ( count( $rows ) >= $limit ) {
			$this->truncated[] = $check;
		}

		return $rows;
	}

	/**
	 * An item's stable identity, composed of what the finding is.
	 *
	 * @param array<string, mixed> $row The finding, with its tier already set.
	 * @return string
	 */
	private static function key_of( array $row ): string {
		// THE SEPARATOR IS URL-SAFE, AND THAT IS NOT COSMETIC.
		//
		// This key travels in the query string as `ffc_at[<tier>]`, and
		// `add_query_arg()` does NOT encode values -- `build_query()` calls
		// `_http_build_query( …, false )`, so whatever is here reaches the URL
		// literally. A raw `|` is invalid there per RFC 3986 (neither
		// unreserved nor a sub-delim), and it is a command-injection
		// signature to a WAF.
		//
		// Measured on the production host, which runs mod_security: every
		// navigation link 403'd before reaching PHP, and so did the
		// redirect after a correction -- which is why `Reload the list` was
		// the only way to advance, although the stepper had been carrying
		// the next key all along. Two controlled requests settled it: this
		// key with dots loads, while `?ffc_teste=a|b` -- a parameter the
		// plugin does not read -- 403s on its own.
		//
		// Percent-encoding is NOT the fix: mod_security applies
		// `urlDecodeUni` before matching, so `%7C` and `|` are the same
		// input to it. The character has to be absent, not escaped.
		//
		// `-` rather than `.`, although a dot was what the production probe
		// used: the `isolated` tier carries an EMPTY identifier column, so a
		// dot would assemble `isolated..hashZ`, and `..` is the path-traversal
		// signature (CRS 930100) -- trading one refused character for another.
		// A hyphen is equally unreserved, carries no meaning to any layer, and
		// `--` is a signature of nothing. No component ever contains one, so
		// the key also stays readable.
		//
		// `IdentityQueueKeyTest` asserts the INVARIANT -- unreserved
		// characters only, and no `..` anywhere -- rather than this literal,
		// so a future edit reaching for another separator still fails if it
		// picks one a URL cannot carry.
		return implode(
			self::KEY_SEPARATOR,
			array(
				(string) ( $row[ self::COLUMN_TIER ] ?? '' ),
				(string) ( $row['identifier_column'] ?? '' ),
				(string) ( $row['subject'] ?? '' ),
			)
		);
	}

	/**
	 * Which checks reached their cap on the last `items()` call.
	 *
	 * Empty means every check returned everything it had -- which is the only
	 * state in which a total printed beside this list is a total.
	 *
	 * @return array<int, string>
	 */
	public function truncated(): array {
		return $this->truncated;
	}

	/**
	 * Checks capped on the last scan.
	 *
	 * @var array<int, string>
	 */
	private array $truncated = array();

	/**
	 * What the last `items()` call's check-digit scan actually read.
	 *
	 * Carried through because an empty worklist has two meanings and only one
	 * of them is reassuring -- the distinction #1368 shipped and #1384 proved
	 * was load-bearing, when a rejected statement made the scan report zero on
	 * an install holding thousands.
	 *
	 * @return array{stores: int, examined: int, unreadable: int}
	 */
	public function coverage(): array {
		return $this->coverage;
	}

	/**
	 * What the last scan read.
	 *
	 * @var array{stores: int, examined: int, unreadable: int}
	 */
	private array $coverage = array(
		'stores'     => 0,
		'examined'   => 0,
		'unreadable' => 0,
	);

	/**
	 * Decide one account-side item's tier from its verdicts.
	 *
	 * @param array<string, mixed>  $row      The finding.
	 * @param array<string, string> $verdicts Hash => verdict.
	 * @return array<string, mixed>
	 */
	private function tiered( array $row, array $verdicts ): array {
		$row[ self::COLUMN_VERDICTS ] = $verdicts;
		$row[ self::COLUMN_TIER ]     = self::TIER_DECISION;
		$row[ self::COLUMN_CHECK ]    = self::CHECK_MULTIPLE;

		// THE SHARED MAILBOX IS DECIDED FIRST, SO NO VERB CAN BE OFFERED.
		//
		// Both halves are read rather than derived: `multiple_identities()`
		// already annotates each row with the email verdict and the shape
		// verdict, so this tier costs no query. See `TIER_MAILBOX` for why it
		// outranks the mechanical test instead of falling through to it.
		if ( self::is_mailbox( $row ) ) {
			$row[ self::COLUMN_TIER ] = self::TIER_MAILBOX;
			$row[ self::COLUMN_KEY ]  = self::key_of( $row );

			return $row;
		}

		$invalid = array_keys( $verdicts, IdentityConflictQuery::VERDICT_INVALID, true );
		$valid   = array_keys( $verdicts, IdentityConflictQuery::VERDICT_VALID, true );

		// EVERY identifier must have been READ, not merely not-failed.
		//
		// An `unreadable` or `absent` verdict beside one failure looks exactly
		// like a mechanical case from the counts alone, and is not one: the
		// value nobody could read may be the person's real number. Requiring
		// the two counts to add up to the whole set is what keeps an unread
		// value from being consolidated away. The #1071 / #1094 rule, at the
		// point where it decides a write.
		$decided = count( $invalid ) + count( $valid );

		if (
			self::MECHANICAL_IDENTIFIERS === count( $verdicts )
			&& self::MECHANICAL_IDENTIFIERS === $decided
			&& 1 === count( $invalid )
		) {
			$row[ self::COLUMN_TIER ]  = self::TIER_MECHANICAL;
			$row[ self::COLUMN_WRONG ] = (string) $invalid[0];
			$row[ self::COLUMN_RIGHT ] = (string) $valid[0];
		}

		// LAST, because the key carries the tier and the tier is only final
		// here: a mechanical item keyed while it still said `decision` would
		// change identity the moment the verdicts came back differently.
		$row[ self::COLUMN_KEY ] = self::key_of( $row );

		return $row;
	}

	/**
	 * Whether one account-side finding is the shared-mailbox reading.
	 *
	 * Both verdicts must be present AND say so. An absent column answers no,
	 * which is the safe direction here only because the tier it would
	 * otherwise reach still refuses to write without a decided pair -- a
	 * report cached before the two columns existed therefore reads as it
	 * always did rather than as a mailbox.
	 *
	 * @param array<string, mixed> $row The finding.
	 * @return bool
	 */
	private static function is_mailbox( array $row ): bool {
		$email = (string) ( $row[ IdentityConflictQuery::COLUMN_EMAIL_VERDICT ] ?? '' );
		$shape = (string) ( $row[ IdentityConflictQuery::COLUMN_SHAPE_VERDICT ] ?? '' );

		return IdentityConflictQuery::VERDICT_SHARED_EMAIL === $email
			&& IdentityConflictQuery::SHAPE_UNRELATED === $shape;
	}

	/**
	 * Split a `GROUP_CONCAT` list into its parts, without blanks.
	 *
	 * @param mixed $related The joined list, as the statement returned it.
	 * @return array<int, string>
	 */
	private static function listed( $related ): array {
		if ( ! is_string( $related ) || '' === $related ) {
			return array();
		}

		$out = array();

		foreach ( explode( IdentityConflictQuery::RELATED_SEPARATOR, $related ) as $part ) {
			$part = trim( $part );

			if ( '' !== $part && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}

		return $out;
	}
}
