<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Maintenance\IdentityQueue;

/**
 * Slicing the worklist into one-at-a-time panels (#1397).
 *
 * @covers \FreeFormCertificate\Admin\IdentityQueuePanels
 */
class IdentityQueuePanelsTest extends TestCase {

	/**
	 * Preload for pcov attribution.
	 */
	protected function setUp(): void {
		parent::setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityQueuePanels' );
	}

	/**
	 * One finding of a tier, keyed as `IdentityQueue` keys them.
	 *
	 * @param string $tier The tier.
	 * @param string $key  The stable key.
	 * @return array<string, mixed>
	 */
	private static function of( string $tier, string $key ): array {
		return array(
			IdentityQueue::COLUMN_TIER => $tier,
			IdentityQueue::COLUMN_KEY  => $key,
		);
	}

	/**
	 * One finding, carrying the check that produced it.
	 *
	 * Separate from `of()` so the tests that predate #1466 keep exercising a
	 * row with NO check -- which is the shape the conservative branch of
	 * `capped_panel()` is written for, and it would stop being covered if
	 * every fixture in this file gained a check.
	 *
	 * @param string $tier  The tier.
	 * @param string $key   The stable key.
	 * @param string $check The check that produced it.
	 * @return array<string, mixed>
	 */
	private static function from( string $tier, string $key, string $check ): array {
		return array(
			IdentityQueue::COLUMN_TIER  => $tier,
			IdentityQueue::COLUMN_KEY   => $key,
			IdentityQueue::COLUMN_CHECK => $check,
		);
	}

	/**
	 * A tier with nothing in it is absent, not empty.
	 *
	 * The counters above the panels and the panels themselves are the same
	 * list, so a heading over no rows cannot appear on one and not the other.
	 */
	public function test_a_tier_with_no_findings_is_not_a_panel(): void {
		$panels = IdentityQueuePanels::build(
			array( self::of( IdentityQueue::TIER_SHARED, 's1' ) )
		);

		$this->assertCount( 1, $panels );
		$this->assertSame( IdentityQueue::TIER_SHARED, $panels[0]['tier'] );
	}

	/**
	 * Nothing at all is no panels, rather than four empty ones.
	 */
	public function test_an_empty_worklist_is_no_panels(): void {
		$this->assertSame( array(), IdentityQueuePanels::build( array() ) );
	}

	/**
	 * THE ORDER IS THE EFFORT, AND IT IS NOT THE ORDER THE FINDINGS ARRIVE IN.
	 *
	 * `IdentityQueue::items()` composes account-side findings first, then the
	 * shared ones, then the check-digit failures. The screen offers what the
	 * digits already decided, then what needs only the right number, then the
	 * two that need a judgement.
	 */
	public function test_panels_come_in_the_order_of_effort(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_DECISION, 'd1' ),
				self::of( IdentityQueue::TIER_SHARED, 's1' ),
				self::of( IdentityQueue::TIER_ISOLATED, 'i1' ),
				self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
			)
		);

		$this->assertSame(
			array(
				IdentityQueue::TIER_MECHANICAL,
				IdentityQueue::TIER_ISOLATED,
				IdentityQueue::TIER_DECISION,
				IdentityQueue::TIER_SHARED,
			),
			array_column( $panels, 'tier' )
		);
	}

	/**
	 * With no cursor a panel opens on its first finding, and says how many.
	 */
	public function test_a_panel_opens_on_its_first_finding(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
				self::of( IdentityQueue::TIER_MECHANICAL, 'm2' ),
				self::of( IdentityQueue::TIER_MECHANICAL, 'm3' ),
			)
		);

		$this->assertSame( 0, $panels[0]['index'] );
		$this->assertSame( 3, $panels[0]['total'] );
		$this->assertSame( 'm1', $panels[0]['current'][ IdentityQueue::COLUMN_KEY ] );
		$this->assertSame( '', $panels[0]['previous'], 'The first finding has nothing before it.' );
		$this->assertSame( 'm2', $panels[0]['next'] );
	}

	/**
	 * A cursor names a finding, never a position — which is the whole point of
	 * the key: a list taken again renumbers, and a remembered index would put
	 * the operator in front of a different person.
	 */
	public function test_the_cursor_follows_the_key_through_a_reordering(): void {
		$items = array(
			self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
			self::of( IdentityQueue::TIER_MECHANICAL, 'm2' ),
			self::of( IdentityQueue::TIER_MECHANICAL, 'm3' ),
		);

		$before = IdentityQueuePanels::build( $items, array( IdentityQueue::TIER_MECHANICAL => 'm3' ) );

		// The same three, taken again in another order.
		$after = IdentityQueuePanels::build(
			array( $items[2], $items[0], $items[1] ),
			array( IdentityQueue::TIER_MECHANICAL => 'm3' )
		);

		$this->assertSame( 2, $before[0]['index'] );
		$this->assertSame( 0, $after[0]['index'] );
		$this->assertSame(
			$before[0]['current'][ IdentityQueue::COLUMN_KEY ],
			$after[0]['current'][ IdentityQueue::COLUMN_KEY ],
			'The position moved; the finding in front of the operator did not.'
		);
	}

	/**
	 * The last finding has nothing after it.
	 */
	public function test_the_last_finding_has_no_next(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_ISOLATED, 'i1' ),
				self::of( IdentityQueue::TIER_ISOLATED, 'i2' ),
			),
			array( IdentityQueue::TIER_ISOLATED => 'i2' )
		);

		$this->assertSame( 'i1', $panels[0]['previous'] );
		$this->assertSame( '', $panels[0]['next'] );
	}

	/**
	 * A CURSOR NAMING A FINDING THAT IS GONE IS THE ORDINARY CASE.
	 *
	 * The finding an operator was looking at is exactly the one a successful
	 * write removes. Falling back to the top is the floor — the forms carry
	 * the NEXT key so the common path never reaches it — and it must never
	 * be an error, because there is nothing wrong.
	 */
	public function test_a_cursor_on_a_resolved_finding_falls_back_to_the_top(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_DECISION, 'd2' ),
				self::of( IdentityQueue::TIER_DECISION, 'd3' ),
			),
			array( IdentityQueue::TIER_DECISION => 'd1-just-resolved' )
		);

		$this->assertSame( 0, $panels[0]['index'] );
		$this->assertSame( 'd2', $panels[0]['current'][ IdentityQueue::COLUMN_KEY ] );
	}

	/**
	 * Each panel keeps its own cursor: working one tier does not move another.
	 */
	public function test_each_panel_keeps_its_own_cursor(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
				self::of( IdentityQueue::TIER_MECHANICAL, 'm2' ),
				self::of( IdentityQueue::TIER_SHARED, 's1' ),
				self::of( IdentityQueue::TIER_SHARED, 's2' ),
			),
			array( IdentityQueue::TIER_SHARED => 's2' )
		);

		$this->assertSame( 0, $panels[0]['index'], 'Untouched panels stay at the top.' );
		$this->assertSame( 1, $panels[1]['index'] );
	}

	/**
	 * `See list` is per tier, so one category can be scanned whole while the
	 * others stay one at a time.
	 */
	public function test_the_list_toggle_is_per_tier(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
				self::of( IdentityQueue::TIER_ISOLATED, 'i1' ),
			),
			array(),
			array( IdentityQueue::TIER_ISOLATED )
		);

		$this->assertFalse( $panels[0]['list'] );
		$this->assertTrue( $panels[1]['list'] );
	}

	/**
	 * The scan's truncation signal carries no tier and is not work: it must
	 * not become a panel, and must not be counted as a finding in one.
	 */
	public function test_a_row_with_no_tier_is_not_work(): void {
		$panels = IdentityQueuePanels::build(
			array(
				array( 'identifier_column' => 'rf_hash', 'scan_truncated' => true ),
				self::of( IdentityQueue::TIER_MECHANICAL, 'm1' ),
			)
		);

		$this->assertCount( 1, $panels );
		$this->assertSame( 1, $panels[0]['total'] );
	}

	/**
	 * A count is a floor when the check behind it reached its cap (#1466).
	 *
	 * `IdentityQueue::LIMIT` caps each check at 100, so a category holding
	 * more shows 100 and the counter read `1 of 100` -- a floor printed in the
	 * grammar of a total. The scan already reported the cap in a banner at the
	 * top of the page; what did not was the number the operator reads.
	 */
	public function test_a_panel_whose_check_was_capped_says_its_count_is_a_floor(): void {
		$panels = IdentityQueuePanels::build(
			array( self::from( IdentityQueue::TIER_SHARED, 's1', IdentityQueue::CHECK_SHARED ) ),
			array(),
			array(),
			array( IdentityQueue::CHECK_SHARED )
		);

		$this->assertTrue( $panels[0]['capped'] );
	}

	/**
	 * And is a count when it did not.
	 *
	 * The other direction matters as much: a screen that always hedges tells
	 * an operator nothing, and `worked to zero` stops meaning anything.
	 */
	public function test_a_panel_whose_check_returned_everything_says_its_count_is_a_count(): void {
		$panels = IdentityQueuePanels::build(
			array( self::from( IdentityQueue::TIER_SHARED, 's1', IdentityQueue::CHECK_SHARED ) ),
			array(),
			array(),
			array( IdentityQueue::CHECK_DIGITS )
		);

		$this->assertFalse( $panels[0]['capped'] );
	}

	/**
	 * ONE CAPPED CHECK QUALIFIES EVERY TIER IT FEEDS, AND IT FEEDS THREE.
	 *
	 * This is the case a hand-written tier-to-check table gets wrong, and the
	 * reason `capped_panel()` reads `COLUMN_CHECK` off the items instead.
	 * `IdentityQueue::items()` runs `CHECK_MULTIPLE` once and `tiered()` then
	 * splits its rows into `mechanical`, `decision` and `mailbox` -- so the
	 * cap is one check's and the dishonesty is three counters'.
	 */
	public function test_one_capped_check_qualifies_every_tier_it_feeds(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::from( IdentityQueue::TIER_MECHANICAL, 'm1', IdentityQueue::CHECK_MULTIPLE ),
				self::from( IdentityQueue::TIER_DECISION, 'd1', IdentityQueue::CHECK_MULTIPLE ),
				self::from( IdentityQueue::TIER_MAILBOX, 'x1', IdentityQueue::CHECK_MULTIPLE ),
				self::from( IdentityQueue::TIER_ISOLATED, 'i1', IdentityQueue::CHECK_DIGITS ),
			),
			array(),
			array(),
			array( IdentityQueue::CHECK_MULTIPLE )
		);

		$capped = array();

		foreach ( $panels as $panel ) {
			$capped[ (string) $panel['tier'] ] = (bool) $panel['capped'];
		}

		$this->assertSame(
			array(
				IdentityQueue::TIER_MECHANICAL => true,
				IdentityQueue::TIER_ISOLATED   => false,
				IdentityQueue::TIER_DECISION   => true,
				IdentityQueue::TIER_MAILBOX    => true,
			),
			$capped,
			'The three tiers `CHECK_MULTIPLE` feeds must all be floors, and the one it does not feed must not be.'
		);
	}

	/**
	 * Nothing capped is nothing qualified, which is the ordinary case.
	 */
	public function test_no_capped_check_qualifies_nothing(): void {
		$panels = IdentityQueuePanels::build(
			array(
				self::from( IdentityQueue::TIER_MECHANICAL, 'm1', IdentityQueue::CHECK_MULTIPLE ),
				self::from( IdentityQueue::TIER_SHARED, 's1', IdentityQueue::CHECK_SHARED ),
			)
		);

		foreach ( $panels as $panel ) {
			$this->assertFalse( $panel['capped'], (string) $panel['tier'] );
		}
	}

	/**
	 * A row carrying no check cannot mark the panel, and that is deliberate.
	 *
	 * The alternative -- calling a count a floor because one row lost its
	 * provenance -- makes every count a floor on the first such row, which
	 * tells the operator less than the unqualified number did. The cap for the
	 * scan as a whole is still reported by the page's own banner, which reads
	 * `truncated()` directly rather than through the panels.
	 */
	public function test_a_finding_with_no_check_does_not_mark_the_panel(): void {
		$panels = IdentityQueuePanels::build(
			array( self::of( IdentityQueue::TIER_SHARED, 's1' ) ),
			array(),
			array(),
			array( IdentityQueue::CHECK_SHARED )
		);

		$this->assertFalse( $panels[0]['capped'] );
	}
}
