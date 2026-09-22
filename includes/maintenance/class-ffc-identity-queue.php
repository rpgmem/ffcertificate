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

		foreach ( $query->multiple_identities( $limit ) as $row ) {
			$row      = (array) $row;
			$column   = (string) ( $row['identifier_column'] ?? '' );
			$hashes   = self::listed( $row[ IdentityConflictQuery::COLUMN_RELATED ] ?? '' );
			$verdicts = array() === $hashes ? array() : $query->check_digit_verdicts( $column, $hashes );

			foreach ( $hashes as $hash ) {
				$spoken[ $hash ] = true;
			}

			$out[] = $this->tiered( $row, $verdicts );
		}

		foreach ( $query->shared_identities( $limit ) as $row ) {
			$row     = (array) $row;
			$subject = (string) ( $row['subject'] ?? '' );

			if ( '' !== $subject ) {
				$spoken[ $subject ] = true;
			}

			$row[ self::COLUMN_TIER ]     = self::TIER_SHARED;
			$row[ self::COLUMN_VERDICTS ] = array();

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
		foreach ( $query->rf_check_digit_failures( $limit ) as $row ) {
			$row     = (array) $row;
			$subject = (string) ( $row['subject'] ?? '' );

			if ( '' !== $subject && isset( $spoken[ $subject ] ) ) {
				continue;
			}

			$row[ self::COLUMN_TIER ]     = self::TIER_ISOLATED;
			$row[ self::COLUMN_VERDICTS ] = '' === $subject
				? array()
				: array( $subject => IdentityConflictQuery::VERDICT_INVALID );

			$out[] = $row;
		}

		$this->coverage = $query->rf_scan_coverage();

		return $out;
	}

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

		return $row;
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
