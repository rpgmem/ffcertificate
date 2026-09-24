<?php
/**
 * Identity worklist
 *
 * The tiered findings of {@see IdentityQueue}, taken once and held still while
 * an operator works through them (#1397).
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.28.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A list of findings that does not move under the person working it.
 */
class IdentityWorklist {

	/**
	 * Transient prefix; the operator's user id completes it.
	 *
	 * Per operator, not per site: two people working the queue are working
	 * two positions in it, and one shared list would move under both.
	 *
	 * `uninstall.php` removes every `_transient_ffc_%` by wildcard, so this
	 * needs no entry in the manifest the fresh-install gate enforces.
	 *
	 * @var string
	 */
	public const TRANSIENT = 'ffc_identity_worklist_';

	/**
	 * How long a taken list stays taken, in seconds.
	 *
	 * Long enough to work a sitting, short enough that a list nobody came
	 * back to expires rather than being handed to them stale a day later.
	 * The screen prints when it was taken, so the number is a floor on
	 * surprise rather than the thing keeping the operator informed.
	 *
	 * @var int
	 */
	public const TTL = 1800;

	/**
	 * Key carrying when the list was taken, as a unix timestamp.
	 *
	 * Category A of `CLAUDE.md`'s date convention: an instant, stored as
	 * `time()`, rendered through `DateFormatter`. Never `current_time()`,
	 * which drifts when the site's timezone changes.
	 *
	 * @var string
	 */
	public const TAKEN_AT = 'taken_at';

	/**
	 * Key carrying the findings.
	 *
	 * @var string
	 */
	public const ITEMS = 'items';

	/**
	 * Key carrying what the check-digit scan read.
	 *
	 * @var string
	 */
	public const COVERAGE = 'coverage';

	/**
	 * Key carrying which checks reached their cap.
	 *
	 * @var string
	 */
	public const TRUNCATED = 'truncated';

	/**
	 * Take the tiering to work through, so one seam chain serves the screen.
	 *
	 * INJECTED RATHER THAN BUILT, because the caller already owns one.
	 * `IdentityResolutionPage` composes its `IdentityQueue` around its OWN
	 * `conflicts()` seam, and a worklist that built a second one would quietly
	 * read through a different query than everything else on that screen --
	 * which is how a test starts proving something about a double nothing
	 * under test uses.
	 *
	 * @param IdentityQueue|null $queue The tiering, or null to build one.
	 */
	public function __construct( ?IdentityQueue $queue = null ) {
		$this->queue = $queue;
	}

	/**
	 * The tiering this worklist reads through, when one was handed in.
	 *
	 * @var IdentityQueue|null
	 */
	private ?IdentityQueue $queue = null;

	/**
	 * The list as it stands for one operator, taking it if none is held.
	 *
	 * WHY THIS IS HELD AT ALL, AGAINST THE REASONING THAT SAID NOT TO.
	 *
	 * `IdentityResolutionPage::queue()` reads live on every render, and its
	 * docblock argues that well: reading the Migrations tab's cached report
	 * would make the queue depend on somebody holding a DIFFERENT capability
	 * pressing Run first, which is the coupling this screen's own capability
	 * exists to remove. That argument is about whose report it is, and it
	 * still holds -- this list is taken by the operator, from this screen,
	 * under this capability, and nobody else can fill it.
	 *
	 * What changed is the cost. A live read decrypts every distinct stored
	 * identifier (production: ~3,000), which is affordable once per visit and
	 * not once per resolution -- and a screen that is worked item by item is
	 * loaded once per resolution. Holding the list also makes a POSITION
	 * mean something: a list rebuilt between two clicks renumbers itself, so
	 * "the next one" silently becomes a different person.
	 *
	 * @param int $user_id The operator.
	 * @param int $limit   Sample size per check, when a list has to be taken.
	 * @return array{items: array<int, array<string, mixed>>, coverage: array{stores: int, examined: int, unreadable: int}, truncated: array<int, string>, taken_at: int}
	 */
	public function get( int $user_id, int $limit = 100 ): array {
		$held = get_transient( self::TRANSIENT . $user_id );

		if ( is_array( $held ) && isset( $held[ self::ITEMS ] ) && is_array( $held[ self::ITEMS ] ) ) {
			return self::shaped( $held );
		}

		return $this->take( $user_id, $limit );
	}

	/**
	 * Scan now and hold the result, replacing whatever was held.
	 *
	 * @param int $user_id The operator.
	 * @param int $limit   Sample size per check.
	 * @return array{items: array<int, array<string, mixed>>, coverage: array{stores: int, examined: int, unreadable: int}, truncated: array<int, string>, taken_at: int}
	 */
	public function take( int $user_id, int $limit = 100 ): array {
		$queue = $this->queues();

		// ORDER MATTERS: coverage and truncation are properties of the scan
		// `items()` just ran, so both are read after it and from the same
		// instance. Reading them from a second instance would answer about a
		// scan that never happened.
		$taken = array(
			self::ITEMS     => $queue->items( $limit ),
			self::COVERAGE  => $queue->coverage(),
			self::TRUNCATED => $queue->truncated(),
			self::TAKEN_AT  => time(),
		);

		set_transient( self::TRANSIENT . $user_id, $taken, self::TTL );

		return self::shaped( $taken );
	}

	/**
	 * Drop one finding from the held list, without scanning again.
	 *
	 * THIS IS WHAT KEEPS A RESOLUTION FROM COSTING A SCAN.
	 *
	 * The obvious way to make a resolved item disappear is to throw the list
	 * away and take it again, which is the per-resolution scan this class
	 * exists to avoid -- and it renumbers everything else at the same time.
	 * Removing the one key leaves every other position where the operator
	 * left it, and the count beside the list falls by exactly one, which is
	 * what "worked to zero" has to mean to be worth printing.
	 *
	 * A key that is not held is not an error: the same finding may have been
	 * resolved from another tab, or the list may have expired and been taken
	 * again without it. Both are already true when this is called.
	 *
	 * @param int    $user_id The operator.
	 * @param string $key     The item's {@see IdentityQueue::COLUMN_KEY}.
	 * @return void
	 */
	public function resolved( int $user_id, string $key ): void {
		$held = get_transient( self::TRANSIENT . $user_id );

		if ( ! is_array( $held ) || ! isset( $held[ self::ITEMS ] ) || ! is_array( $held[ self::ITEMS ] ) ) {
			return;
		}

		$kept = array();

		foreach ( $held[ self::ITEMS ] as $item ) {
			$item = (array) $item;

			if ( (string) ( $item[ IdentityQueue::COLUMN_KEY ] ?? '' ) === $key ) {
				continue;
			}

			$kept[] = $item;
		}

		$held[ self::ITEMS ] = $kept;

		set_transient( self::TRANSIENT . $user_id, $held, self::TTL );
	}

	/**
	 * Forget the held list, so the next read takes a fresh one.
	 *
	 * @param int $user_id The operator.
	 * @return void
	 */
	public function forget( int $user_id ): void {
		delete_transient( self::TRANSIENT . $user_id );
	}

	/**
	 * Fill in whatever a held list is missing.
	 *
	 * A transient written by an earlier release, or truncated by a storage
	 * layer, must not make the screen fatal on a missing key -- and an absent
	 * coverage reading must read as "nothing was examined" rather than as a
	 * clean one, which is the #1071 rule applied to the cache rather than to
	 * the scan.
	 *
	 * @param array<string, mixed> $held What was held.
	 * @return array{items: array<int, array<string, mixed>>, coverage: array{stores: int, examined: int, unreadable: int}, truncated: array<int, string>, taken_at: int}
	 */
	private static function shaped( array $held ): array {
		$coverage = isset( $held[ self::COVERAGE ] ) && is_array( $held[ self::COVERAGE ] )
			? $held[ self::COVERAGE ]
			: array();

		return array(
			self::ITEMS     => isset( $held[ self::ITEMS ] ) && is_array( $held[ self::ITEMS ] )
				? array_values( $held[ self::ITEMS ] )
				: array(),
			self::COVERAGE  => array(
				'stores'     => (int) ( $coverage['stores'] ?? 0 ),
				'examined'   => (int) ( $coverage['examined'] ?? 0 ),
				'unreadable' => (int) ( $coverage['unreadable'] ?? 0 ),
			),
			self::TRUNCATED => isset( $held[ self::TRUNCATED ] ) && is_array( $held[ self::TRUNCATED ] )
				? array_values( array_map( 'strval', $held[ self::TRUNCATED ] ) )
				: array(),
			self::TAKEN_AT  => (int) ( $held[ self::TAKEN_AT ] ?? 0 ),
		);
	}

	/**
	 * The tiering, as a seam a test can replace.
	 *
	 * @return IdentityQueue
	 */
	protected function queues(): IdentityQueue {
		return $this->queue ?? new IdentityQueue();
	}
}
