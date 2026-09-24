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
	 * ORDERED BY EFFORT, NOT BY TAXONOMY.
	 *
	 * What the check digits already decided comes first, because it costs a
	 * click and no knowledge. Then the failures that need nothing but the
	 * right number from HR -- the largest population and the most mechanical
	 * once the first one is in hand. The two that need a judgement come last,
	 * and after them the one that cannot be judged from this screen at all:
	 * the shared mailbox is the only tier offering no verb, so it costs the
	 * most and belongs at the end of a list ordered by effort.
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
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( array $findings, array $at = array(), array $listed = array() ): array {
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
			);
		}

		return $panels;
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
