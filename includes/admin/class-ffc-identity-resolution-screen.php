<?php
/**
 * Identity resolution screen
 *
 * Everything the identity-resolution view reads that is derived rather than
 * fetched: the panels by tier, each panel's cursor, the scan's own cap, the
 * coverage strips and their verdicts, and the counters (#1925).
 *
 * This was the top third of the view, as loops and assignments ahead of the
 * markup. It is logic -- an empty-scan verdict decided there is the #1071
 * class -- and `includes/admin/views` is excluded from PHPStan and from the
 * coverage scope as markup, so the one place that decides what an unread scan
 * renders as was the one place no gate read. `IdentityResolutionPage` builds
 * this once per render and the view reads its fields.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The derived state of one render of the identity-resolution screen.
 *
 * @phpstan-import-type ScanCoverage from IdentityConflictQuery
 * @phpstan-type CoverageStrip array{column: string, name: string, examined: int, unreadable: int, stores: int, scan_cap: bool, read_none: bool, read_part: bool, state: string}
 */
final class IdentityResolutionScreen {

	/**
	 * The panels, as `IdentityQueuePanels::build()` returned them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $panels;

	/**
	 * The panels keyed by tier.
	 *
	 * ONE PANEL PER TIER, AND THE TIERS WITH NOTHING IN THEM ARE ABSENT.
	 * `IdentityQueuePanels` already dropped those and put the rest in the order
	 * of effort, so this is a lookup rather than a second opinion: the counters
	 * and the panels below are the same list.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $by_tier = array();

	/**
	 * Every panel's own cursor, so a link that moves ONE panel carries the
	 * others where they already are.
	 *
	 * Derived from the panels rather than passed in, which is what stops the
	 * links from disagreeing with what is rendered.
	 *
	 * @var array<string, string>
	 */
	public array $cursors = array();

	/**
	 * The tiers showing their whole list.
	 *
	 * @var array<int, string>
	 */
	public array $listed = array();

	/**
	 * Whether the check-digit SCAN stopped at its own cap.
	 *
	 * TWO CAPS, AND ONLY ONE OF THEM WAS EVER REPORTED.
	 *
	 * This flag is the scan's own: `IdentityCheckDigitScan::check_digit_failures()`
	 * stops after its scan limit of distinct stored values and emits a row
	 * saying so. The page's `$ffc_identity_capped` is the other one -- each of
	 * the three checks behind this worklist is asked for `LIMIT` findings, and a
	 * check that returns a full page has more. That second cap said nothing at
	 * all until #1397, so a queue holding 140 findings of one kind looked
	 * exactly like one holding 100.
	 *
	 * @var bool
	 */
	public bool $truncated = false;

	/**
	 * Which columns' scans reached their cap.
	 *
	 * THE SCAN'S CAP IS PER COLUMN (#1486), so this records WHICH. Two columns
	 * are scanned and each stops at its own cap, so one flag for both would
	 * report a truncated RF scan on the CPF strip and the other way round.
	 *
	 * @var array<string, bool>
	 */
	public array $truncated_columns = array();

	/**
	 * What each scan read, keyed by column, never empty.
	 *
	 * A HELD LIST THAT LOST ITS READINGS MUST SAY SO, NOT GO QUIET.
	 *
	 * An absent record means "nobody scanned this column", and drawing no strip
	 * is right for that. But the worklist transient can come back with no
	 * coverage at all -- written by an older release, or truncated -- and there
	 * the absence is a LOST READING, not a column nobody asked about. Left to
	 * the rule above it would render silence, which an operator reads as
	 * "nothing to report": the #1071 failure wearing a cache, and strictly
	 * worse than the zeroes it used to show. So an empty record becomes one
	 * UNNAMED strip, which the verdict states as having read nothing.
	 *
	 * @var ScanCoverage
	 */
	public array $coverage;

	/**
	 * One reading across both columns, for the empty-queue branches.
	 *
	 * THE EMPTY-QUEUE BRANCHES NEED ONE READING ACROSS BOTH COLUMNS, AND THEY
	 * WERE SILENTLY READING A LOOP LEFTOVER (#1500, fixing #1499).
	 *
	 * Those branches were written when coverage was one flat record, and #1499
	 * moved the three counters inside the per-column loop without moving them.
	 * After the loop they held whichever column ran LAST -- CPF -- so an install
	 * where RF read 3,014 values and CPF read 12,705 reported 12,705 as the
	 * whole reading and said "RF" about it.
	 *
	 * `stores` is a MAX and the other two are SUMS, which is not an oversight.
	 * The same three tables are probed per column, so adding them would claim
	 * six; but "something was scanned" is true if ANY column found a store.
	 * Values examined and values unread are per value, so they add.
	 *
	 * @var array{stores: int, examined: int, unreadable: int}
	 */
	public array $all = array(
		'stores'     => 0,
		'examined'   => 0,
		'unreadable' => 0,
	);

	/**
	 * One coverage strip per column, each with its own verdict.
	 *
	 * ONE STRIP PER COLUMN, AND SUMMING THEM WOULD UNDO THE WHOLE SECTION
	 * (#1486). RF reading 5,000 values cleanly plus a CPF scan that read
	 * NOTHING sums to `stores > 0`, `examined = 5000`, `unreadable = 0` --
	 * which the verdicts render as "The scan read the data". The healthy half
	 * would vouch for the half that never ran, in the one place built to stop
	 * exactly that.
	 *
	 * @var array<int, CoverageStrip>
	 */
	public array $strips = array();

	/**
	 * How many findings the whole queue holds, orphans included.
	 *
	 * THE COUNTERS ARE THE PANELS, COUNTED — NEVER A SECOND OPINION. The shared
	 * tier is counted even where the merge capability is absent and its panel
	 * is therefore not drawn: the finding exists and is somebody's to resolve,
	 * and a counter that hid it would report a shorter queue to the operator
	 * least able to see why.
	 *
	 * @var int
	 */
	public int $remaining = 0;

	/**
	 * Findings in the correction tiers.
	 *
	 * Counted from `IdentityQueuePanels::CORRECTIONS`, so the order-of-work
	 * advice reuses the partition that decided the order rather than restating
	 * which tiers are which -- a second list would agree with a reordering that
	 * broke it.
	 *
	 * @var int
	 */
	public int $to_correct = 0;

	/**
	 * Findings in the judgement tiers. See {@see self::$to_correct}.
	 *
	 * @var int
	 */
	public int $to_judge = 0;

	/**
	 * Derive the screen's state from what the page read.
	 *
	 * @param array<int, array<string, mixed>>                                  $panels   What `IdentityQueuePanels::build()` returned.
	 * @param array<int, array<string, mixed>>                                  $findings The held worklist.
	 * @param array<string, array{stores: int, examined: int, unreadable: int}> $coverage What each scan read.
	 * @param array<int, string>                                                $capped   Checks that returned a full page.
	 * @param int                                                               $orphans  How many orphan findings are drawn.
	 */
	public function __construct( array $panels, array $findings, array $coverage, array $capped, int $orphans ) {
		$this->panels = $panels;

		foreach ( $findings as $finding ) {
			if ( ! empty( $finding[ IdentityConflictQuery::COLUMN_SCAN_TRUNCATED ] ) ) {
				$this->truncated = true;

				$this->truncated_columns[ (string) ( $finding['identifier_column'] ?? '' ) ] = true;
			}
		}

		foreach ( $panels as $panel ) {
			$tier = (string) $panel['tier'];

			$this->by_tier[ $tier ] = $panel;
			$this->cursors[ $tier ] = (string) ( $panel['current'][ IdentityQueue::COLUMN_KEY ] ?? '' );

			if ( ! empty( $panel['list'] ) ) {
				$this->listed[] = $tier;
			}

			$this->remaining += (int) $panel['total'];

			if ( in_array( $tier, IdentityQueuePanels::CORRECTIONS, true ) ) {
				$this->to_correct += (int) $panel['total'];
			} elseif ( in_array( $tier, IdentityQueuePanels::JUDGEMENTS, true ) ) {
				$this->to_judge += (int) $panel['total'];
			}
		}

		$this->remaining += $orphans;

		$this->coverage = array() === $coverage
			? array(
				'' => array(
					'stores'     => 0,
					'examined'   => 0,
					'unreadable' => 0,
				),
			)
			: $coverage;

		foreach ( $this->coverage as $read ) {
			$this->all['stores']      = max( $this->all['stores'], (int) $read['stores'] );
			$this->all['examined']   += (int) $read['examined'];
			$this->all['unreadable'] += (int) $read['unreadable'];
		}

		foreach ( $this->coverage as $column => $read ) {
			$this->strips[] = $this->strip( (string) $column, $read, $capped );
		}
	}

	/**
	 * One column's strip and its verdict.
	 *
	 * The reassuring verdict is the narrowest: the strip states numbers, and the
	 * empty-queue notices say what an unread scan MEANS, so the strip must never
	 * become a cheerier second answer to their question.
	 *
	 * @param string                                             $column The column, or '' for a lost reading.
	 * @param array{stores: int, examined: int, unreadable: int} $read   What its scan read.
	 * @param array<int, string>                                 $capped Checks that returned a full page.
	 * @return CoverageStrip
	 */
	private function strip( string $column, array $read, array $capped ): array {
		$examined   = (int) $read['examined'];
		$unreadable = (int) $read['unreadable'];
		$stores     = (int) $read['stores'];
		$scan_cap   = ! empty( $this->truncated_columns[ $column ] );

		// Read nothing: no store carries the columns this check needs, or every
		// value it found was unreadable. Both are what an encryption key that
		// does not match the data looks like, and neither is evidence about the
		// numbers themselves.
		$read_none = 0 === $stores
			|| ( $examined > 0 && $examined === $unreadable );

		// Read part of it: something was skipped, or a cap cut the reading
		// short. `$capped` is the per-check cap and `$scan_cap` this column's own
		// scan cap — two different limits, and either one makes the counters
		// counts of what was read rather than of what there is.
		$read_part = ! $read_none
			&& ( $unreadable > 0
				|| 0 === $examined
				|| $scan_cap
				|| array() !== $capped );

		if ( $read_none ) {
			$state = 'none';
		} elseif ( $read_part ) {
			$state = 'partial';
		} else {
			$state = 'whole';
		}

		return array(
			'column'     => $column,
			'name'       => IdentityResolutionLabels::identifier_name( $column ),
			'examined'   => $examined,
			'unreadable' => $unreadable,
			'stores'     => $stores,
			'scan_cap'   => $scan_cap,
			'read_none'  => $read_none,
			'read_part'  => $read_part,
			'state'      => $state,
		);
	}

	/**
	 * A screen URL with one panel moved and every other panel left alone.
	 *
	 * @param string             $tier   The panel to move, or '' to move none.
	 * @param string             $key    The finding to show, or '' to leave it.
	 * @param array<int, string> $listed Tiers showing their whole list.
	 * @return string
	 */
	public function url( string $tier, string $key, array $listed ): string {
		$at = $this->cursors;

		if ( '' !== $tier ) {
			$at[ $tier ] = $key;
		}

		$args = array(
			'page'                      => IdentityResolutionPage::MENU_SLUG,
			IdentityQueuePanels::ARG_AT => array_filter( $at ),
		);

		if ( array() !== $listed ) {
			$args[ IdentityQueuePanels::ARG_LIST ] = implode( ',', $listed );
		}

		return add_query_arg( $args, admin_url( 'edit.php?post_type=ffc_form' ) );
	}

	/**
	 * The tiers a panel's "list / one at a time" toggle leaves listed.
	 *
	 * The toggle carries every OTHER listed tier plus or minus this one, so
	 * one category can be scanned whole while the rest stay one at a time.
	 *
	 * @param string $tier    The panel's tier.
	 * @param bool   $listing Whether that panel is listing now.
	 * @return array<int, string>
	 */
	public function toggle( string $tier, bool $listing ): array {
		$others = array_values( array_diff( $this->listed, array( $tier ) ) );

		return $listing ? $others : array_merge( $others, array( $tier ) );
	}

	/**
	 * Whether the last write was refused for want of an acknowledgement, on
	 * this finding.
	 *
	 * What decides that a correction's acknowledgement box is drawn already
	 * checked-for, rather than hidden behind a correction preview (#1478, #1487).
	 *
	 * @param mixed  $outcome The outcome the last handler left, or false.
	 * @param string $subject The finding a form acts on.
	 * @return bool
	 */
	public static function refused( $outcome, string $subject ): bool {
		return is_array( $outcome )
			&& 'ffc_identity_repair_unacknowledged' === (string) ( $outcome['code'] ?? '' )
			&& (string) ( $outcome['subject'] ?? '' ) === $subject;
	}

	/**
	 * What a panel renders: the one finding it is on, or all of them.
	 *
	 * @param array<string, mixed> $panel The panel.
	 * @return array<int, array<string, mixed>>
	 */
	public static function shown( array $panel ): array {
		return empty( $panel['list'] )
			? array( (array) $panel['current'] )
			: (array) $panel['items'];
	}
}
