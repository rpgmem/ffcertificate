<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityWorklist;

/**
 * Holding the queue still while it is worked (#1397).
 *
 * THE TWO PROMISES, AND NEITHER IS VISIBLE FROM A SINGLE READ.
 *
 * A list that is re-taken between two clicks renumbers itself, so "the next
 * one" silently becomes a different person; and a list re-taken on every
 * resolution costs a full decrypting scan per click. Both only show up across
 * a SEQUENCE of reads, so every case here drives at least two.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityWorklist
 */
class IdentityWorklistTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The fake transient store, keyed as WordPress would key it.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/**
	 * How many times the underlying scan ran.
	 *
	 * @var int
	 */
	private int $scans = 0;

	/**
	 * Set up Brain\Monkey and a transient store that behaves like one.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityWorklist' );

		$this->store = array();
		$this->scans = 0;

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->store[ $key ] );

				return true;
			}
		);
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * One finding, keyed the way `IdentityQueue` keys them.
	 *
	 * @param string $key  The stable key.
	 * @param string $tier The tier.
	 * @return array<string, mixed>
	 */
	private static function finding( string $key, string $tier = IdentityQueue::TIER_DECISION ): array {
		return array(
			'subject'                    => $key,
			'identifier_column'          => 'rf_hash',
			IdentityQueue::COLUMN_TIER   => $tier,
			IdentityQueue::COLUMN_KEY    => $key,
			IdentityQueue::COLUMN_CHECK  => IdentityQueue::CHECK_MULTIPLE,
		);
	}

	/**
	 * A worklist whose scan is a double that counts its own runs.
	 *
	 * @param array<int, array<string, mixed>> $items     What the scan finds.
	 * @param array<int, string>               $truncated Checks that hit the cap.
	 * @return IdentityWorklist
	 */
	private function worklist( array $items, array $truncated = array() ): IdentityWorklist {
		$queue = Mockery::mock( IdentityQueue::class );
		$queue->shouldReceive( 'items' )->andReturnUsing(
			function () use ( $items ) {
				++$this->scans;

				return $items;
			}
		);
		$queue->shouldReceive( 'coverage' )->andReturn(
			array(
				'stores'     => 3,
				'examined'   => 3036,
				'unreadable' => 0,
			)
		);
		$queue->shouldReceive( 'truncated' )->andReturn( $truncated );

		return new class( $queue ) extends IdentityWorklist {

			/** @var IdentityQueue */
			private $double;

			/**
			 * @param IdentityQueue $double Stand-in for the tiering.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityQueue
			 */
			protected function queues(): IdentityQueue {
				return $this->double;
			}
		};
	}

	/**
	 * THE COST PROMISE: reading twice scans once.
	 *
	 * This is the whole reason the class exists. A screen worked item by item
	 * is loaded once per resolution, and a live read decrypts every distinct
	 * stored identifier — about 3,000 in production.
	 */
	public function test_a_held_list_is_not_scanned_again(): void {
		$list = $this->worklist( array( self::finding( 'a' ), self::finding( 'b' ) ) );

		$list->get( 7 );
		$list->get( 7 );
		$list->get( 7 );

		$this->assertSame( 1, $this->scans, 'Three reads of a held list must cost one scan.' );
	}

	/**
	 * THE POSITION PROMISE: resolving one finding moves nothing else.
	 *
	 * The obvious implementation — drop the list and take it again — passes
	 * "the resolved item is gone" and fails this: every other item is
	 * renumbered, so a cursor sitting on one is now on another.
	 */
	public function test_resolving_removes_that_one_and_reorders_nothing(): void {
		$list = $this->worklist(
			array( self::finding( 'a' ), self::finding( 'b' ), self::finding( 'c' ) )
		);

		$list->get( 7 );
		$list->resolved( 7, 'b' );

		$keys = array_map(
			static fn( $item ) => $item[ IdentityQueue::COLUMN_KEY ],
			$list->get( 7 )[ IdentityWorklist::ITEMS ]
		);

		$this->assertSame( array( 'a', 'c' ), $keys );
		$this->assertSame( 1, $this->scans, 'Resolving must not cost a scan.' );
	}

	/**
	 * Two operators hold two lists: one person's progress is not the other's.
	 */
	public function test_each_operator_holds_their_own_list(): void {
		$list = $this->worklist( array( self::finding( 'a' ), self::finding( 'b' ) ) );

		$list->get( 7 );
		$list->get( 9 );
		$list->resolved( 7, 'a' );

		$this->assertCount( 1, $list->get( 7 )[ IdentityWorklist::ITEMS ] );
		$this->assertCount( 2, $list->get( 9 )[ IdentityWorklist::ITEMS ], 'The other operator keeps both.' );
	}

	/**
	 * A key nobody holds is not an error — the same finding may have been
	 * resolved in another tab, or the list may have expired and been taken
	 * again without it. Both are already true when this is called.
	 */
	public function test_resolving_an_unknown_key_changes_nothing(): void {
		$list = $this->worklist( array( self::finding( 'a' ) ) );

		$list->get( 7 );
		$list->resolved( 7, 'not-in-the-list' );

		$this->assertCount( 1, $list->get( 7 )[ IdentityWorklist::ITEMS ] );
	}

	/**
	 * Resolving before anything was taken must not create a list, and must not
	 * throw — the operator may be acting on a page whose list has expired.
	 */
	public function test_resolving_with_nothing_held_is_a_no_op(): void {
		$list = $this->worklist( array( self::finding( 'a' ) ) );

		$list->resolved( 7, 'a' );

		$this->assertSame( 0, $this->scans );
	}

	/**
	 * Taking again is the one control that rescans, and it replaces the held
	 * list — including the progress made against the old one.
	 */
	public function test_taking_again_rescans_and_replaces(): void {
		$list = $this->worklist( array( self::finding( 'a' ), self::finding( 'b' ) ) );

		$list->get( 7 );
		$list->resolved( 7, 'a' );
		$this->assertCount( 1, $list->get( 7 )[ IdentityWorklist::ITEMS ] );

		$list->take( 7 );

		$this->assertCount( 2, $list->get( 7 )[ IdentityWorklist::ITEMS ] );
		$this->assertSame( 2, $this->scans );
	}

	/**
	 * Forgetting means the next read takes a fresh list.
	 */
	public function test_forgetting_makes_the_next_read_scan(): void {
		$list = $this->worklist( array( self::finding( 'a' ) ) );

		$list->get( 7 );
		$list->forget( 7 );
		$list->get( 7 );

		$this->assertSame( 2, $this->scans );
	}

	/**
	 * THE COVERAGE AND THE CAP TRAVEL WITH THE LIST.
	 *
	 * Both are properties of the scan that produced it, so reading them from a
	 * later call would answer about a scan that never happened — and coverage
	 * is what stops an empty queue from reading as a clean one (#1384).
	 */
	public function test_the_held_list_carries_what_the_scan_read_and_what_it_capped(): void {
		$list = $this->worklist( array(), array( IdentityQueue::CHECK_DIGITS ) );

		$held = $list->get( 7 );

		$this->assertSame(
			array(
				'stores'     => 3,
				'examined'   => 3036,
				'unreadable' => 0,
			),
			$held[ IdentityWorklist::COVERAGE ]
		);
		$this->assertSame( array( IdentityQueue::CHECK_DIGITS ), $held[ IdentityWorklist::TRUNCATED ] );
		$this->assertGreaterThan( 0, $held[ IdentityWorklist::TAKEN_AT ] );
	}

	/**
	 * A HELD LIST MISSING ITS COVERAGE MUST NOT READ AS CLEAN.
	 *
	 * A transient written by an earlier release, or clipped by the storage
	 * layer, arrives without the keys this expects. Zeroes are the honest
	 * answer there: the screen's own four-state notice then says nothing was
	 * examined, which is true, rather than printing a reassuring sentence
	 * about a scan that left no record of itself.
	 */
	public function test_a_held_list_missing_its_readings_reports_zero_rather_than_clean(): void {
		$this->store[ IdentityWorklist::TRANSIENT . '7' ] = array(
			IdentityWorklist::ITEMS => array( self::finding( 'a' ) ),
		);

		$held = $this->worklist( array() )->get( 7 );

		$this->assertCount( 1, $held[ IdentityWorklist::ITEMS ] );
		$this->assertSame(
			array(
				'stores'     => 0,
				'examined'   => 0,
				'unreadable' => 0,
			),
			$held[ IdentityWorklist::COVERAGE ]
		);
		$this->assertSame( array(), $held[ IdentityWorklist::TRUNCATED ] );
		$this->assertSame( 0, $this->scans, 'A clipped list is still a held list.' );
	}
}
