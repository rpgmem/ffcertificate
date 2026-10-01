<?php
/**
 * Identity queue panels
 *
 * Slices the held worklist into the panels the screen shows, one finding at a
 * time, and resolves where each panel's cursor is sitting (#1397).
 *
 * @package FreeFormCertificate\Admin
 * @since 6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One panel per tier, each showing one finding with a place in a sequence.
 */
class IdentityQueuePanels {

	/**
	 * The tiers, in the order the screen offers them.
	 *
	 * ORDERED BY DEPENDENCY FIRST, AND BY EFFORT WITHIN THAT.
	 *
	 * Every CORRECTION tier comes before every JUDGEMENT tier, and that is not a
	 * preference. A merge decided while one side carries a wrong number is
	 * decided on false evidence, and correcting the number can DISSOLVE the
	 * finding rather than resolve it -- the two accounts may never have shared
	 * anything, and the pairing was the typo. So the order is the screen's
	 * recommendation: work downwards.
	 *
	 * Within each half, effort orders them. Among the corrections: what the check
	 * digits already decided is first, because it costs a click and no knowledge;
	 * then the failures needing nothing but the right number from HR -- the
	 * largest population and the most mechanical once the first is in hand; then
	 * the ones the digits do not single out, which need that number AND a
	 * judgement. Among the judgements, the shared mailbox is last: it is the only
	 * tier offering no verb resolvable from this screen alone.
	 *
	 * THE RULE USED TO BE STATED AS EFFORT ALONE, AND IT PRODUCED THIS SAME
	 * ORDER BY COINCIDENCE (#1491). That is the part worth fixing: effort and
	 * dependency happen to agree today, so a future tier that is cheap and
	 * judgemental -- a shared mailbox gaining a one-click verb, say -- would rise
	 * by the stated rule and break the dependency, with the docblock sanctioning
	 * it. `IdentityQueuePanelsTest` pins the partition, so effort stays free to
	 * reorder within a half and cannot reorder across it.
	 *
	 * The order is a recommendation and NOT a gate: the verbs enforce the
	 * dependency per finding, which is why a merge whose two sides are clean
	 * stays available while the correction queue is still full. A global gate was
	 * considered and refused -- the correction tiers wait on HR answering, so one
	 * unanswered person would hide every other verb indefinitely, and "the queue
	 * is empty" never verified that THIS pair was clean, only that everything
	 * else was.
	 *
	 * A tier absent from this list is not shown at all, which is how the
	 * orphan tier stays out until the query that finds it exists.
	 *
	 * @var array<int, string>
	 */
	public const ORDER = array(
		IdentityQueue::TIER_MECHANICAL,
		IdentityQueue::TIER_ISOLATED,
		IdentityQueue::TIER_DECISION,
		IdentityQueue::TIER_SHARED,
		IdentityQueue::TIER_MAILBOX,
	);

	/**
	 * The tiers whose verb corrects a number.
	 *
	 * Named so the dependency can be asserted without a test repeating a
	 * literal list, which would then agree with a reordering that broke it.
	 *
	 * @since 6.31.0
	 * @var array<int, string>
	 */
	public const CORRECTIONS = array(
		IdentityQueue::TIER_MECHANICAL,
		IdentityQueue::TIER_ISOLATED,
		IdentityQueue::TIER_DECISION,
	);

	/**
	 * The tiers whose verb consumes an identifier as evidence about people.
	 *
	 * A merge or a move reads a shared number as *these are one person*, which
	 * is why every correction belongs above them.
	 *
	 * @since 6.31.0
	 * @var array<int, string>
	 */
	public const JUDGEMENTS = array(
		IdentityQueue::TIER_SHARED,
		IdentityQueue::TIER_MAILBOX,
	);

	/**
	 * The tiers whose findings may be accepted as impossible to resolve.
	 *
	 * Named here beside its two siblings, and for the same reason they are
	 * named: so the rule can be asserted without a test repeating a literal
	 * list that would then agree with a reordering which broke it.
	 *
	 * TWO TIERS ARE DELIBERATELY ABSENT, and neither is an oversight.
	 *
	 * `mechanical` is one click from correct -- the check digits already said
	 * which value is wrong and which is right -- so there is nothing for HR to
	 * supply and nothing to accept. Offering "nobody can fix this" beside a
	 * button that fixes it is how a control teaches people to ignore it.
	 *
	 * `shared` is a merge, and what blocks a merge is a NUMBER: `IdentityMerge`
	 * refuses while the shared value fails its check digit. That number is
	 * accepted on the panel where numbers live, and the refusal then says so
	 * (#1532). Accepting the pair itself would be a claim about two accounts
	 * that no acceptance is evidence for.
	 *
	 * @since 6.33.0
	 * @var array<int, string>
	 */
	public const ACCEPTABLE = array(
		IdentityQueue::TIER_ISOLATED,
		IdentityQueue::TIER_DECISION,
		IdentityQueue::TIER_MAILBOX,
	);

	/**
	 * Request argument carrying each panel's cursor, keyed by tier.
	 *
	 * @var string
	 */
	public const ARG_AT = 'ffc_at';

	/**
	 * Request argument naming the tiers showing their whole list.
	 *
	 * @var string
	 */
	public const ARG_LIST = 'ffc_list';

	/**
	 * Build the panels, dropping the tiers with nothing in them.
	 *
	 * AN EMPTY TIER IS NOT RENDERED EMPTY -- IT IS NOT RENDERED.
	 *
	 * A heading over a table with no rows is a thing an operator has to read
	 * before learning there is nothing under it, once per visit, for every
	 * tier they have already cleared. Dropping it here rather than in the
	 * markup means the counters above cannot disagree with the panels below:
	 * both are this list.
	 *
	 * @param array<int, array<string, mixed>> $findings The held worklist.
	 * @param array<string, string>            $at       Tier => the key being shown.
	 * @param array<int, string>               $listed   Tiers showing their whole list.
	 * @param array<int, string>               $capped   Checks that reached their cap, from `IdentityQueue::truncated()`.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( array $findings, array $at = array(), array $listed = array(), array $capped = array() ): array {
		$bucketed = array_fill_keys( self::ORDER, array() );

		foreach ( $findings as $finding ) {
			$finding = (array) $finding;
			$tier    = (string) ( $finding[ IdentityQueue::COLUMN_TIER ] ?? '' );

			// A row carrying no tier is the scan's own truncation signal,
			// which the view reports as a sentence rather than as work.
			if ( ! isset( $bucketed[ $tier ] ) ) {
				continue;
			}

			$bucketed[ $tier ][] = $finding;
		}

		$panels = array();

		foreach ( self::ORDER as $tier ) {
			$items = $bucketed[ $tier ];

			if ( array() === $items ) {
				continue;
			}

			$index = self::index_of( $items, (string) ( $at[ $tier ] ?? '' ) );

			$panels[] = array(
				'tier'     => $tier,
				'items'    => $items,
				'total'    => count( $items ),
				'index'    => $index,
				'current'  => $items[ $index ],
				'previous' => $index > 0 ? self::key_at( $items, $index - 1 ) : '',
				'next'     => $index + 1 < count( $items ) ? self::key_at( $items, $index + 1 ) : '',
				'list'     => in_array( $tier, $listed, true ),
				'capped'   => self::capped_panel( $items, $capped ),
			);
		}

		return $panels;
	}

	/**
	 * Whether this panel's total is a floor rather than a total.
	 *
	 * DERIVED FROM THE PANEL'S OWN ITEMS, NEVER FROM A TIER-TO-CHECK MAP.
	 *
	 * `IdentityQueue::truncated()` answers in CHECKS and this screen counts in
	 * TIERS, and the two are not one to one: `CHECK_MULTIPLE` fans out through
	 * `tiered()` into `mechanical`, `decision` AND `mailbox`, so one capped
	 * check makes three counters floors. A literal table here would have to
	 * know that, would be a claim about a value `IdentityQueue` owns -- the
	 * shape `CLAUDE.md` records as going stale in silence -- and would be
	 * wrong the day a fourth tier is split off the same check.
	 *
	 * Every row carries `COLUMN_CHECK`, so the panel already says which checks
	 * produced it. Reading them off the items costs nothing and cannot
	 * disagree with the scan, which is the same reason the coverage and
	 * truncation readings are taken from the instance that ran the scan.
	 *
	 * A row carrying no check cannot be attributed and therefore cannot mark
	 * the panel -- the alternative is calling every count a floor the moment
	 * one row loses its provenance, which is the opposite of the #1071 rule:
	 * here the conservative answer is the one a reader can act on, and the
	 * page-level banner still reports the cap for the scan as a whole.
	 *
	 * @param array<int, array<string, mixed>> $items  The panel's findings.
	 * @param array<int, string>               $capped Checks that reached their cap.
	 * @return bool
	 */
	private static function capped_panel( array $items, array $capped ): bool {
		if ( array() === $capped ) {
			return false;
		}

		foreach ( $items as $item ) {
			$check = (string) ( ( (array) $item )[ IdentityQueue::COLUMN_CHECK ] ?? '' );

			if ( '' !== $check && in_array( $check, $capped, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Where a key sits in a panel's items, or the top when it sits nowhere.
	 *
	 * A KEY THAT IS GONE MEANS THE LIST MOVED ON, NOT THAT SOMETHING BROKE.
	 *
	 * The finding an operator was looking at is exactly the one a successful
	 * write removes, so the cursor naming a key that is no longer held is the
	 * ORDINARY case after every resolution -- not an error, and not worth a
	 * message. The forms therefore post the key of the NEXT finding and the
	 * redirect carries it, so the common path lands where the operator was
	 * going rather than here. This is the floor under that: an expired list,
	 * a stale bookmark, a hand-edited URL.
	 *
	 * @param array<int, array<string, mixed>> $items The panel's findings.
	 * @param string                           $key   The cursor.
	 * @return int
	 */
	private static function index_of( array $items, string $key ): int {
		if ( '' === $key ) {
			return 0;
		}

		foreach ( $items as $index => $item ) {
			if ( (string) ( $item[ IdentityQueue::COLUMN_KEY ] ?? '' ) === $key ) {
				return (int) $index;
			}
		}

		return 0;
	}

	/**
	 * The key of one item, by position.
	 *
	 * @param array<int, array<string, mixed>> $items The panel's findings.
	 * @param int                              $index The position.
	 * @return string
	 */
	private static function key_at( array $items, int $index ): string {
		return (string) ( $items[ $index ][ IdentityQueue::COLUMN_KEY ] ?? '' );
	}
}
