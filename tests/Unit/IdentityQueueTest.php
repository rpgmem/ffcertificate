<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

/**
 * Tiering the identity findings into a worklist (#1386).
 *
 * The tier decides what an operator is offered, and the mechanical one offers
 * a write with no value typed by anybody -- so a finding tiered wrong
 * consolidates one person's records into a number that is not theirs, and the
 * result passes every later check because it is well-formed. Every boundary is
 * therefore driven, never read off the code.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityQueue
 */
class IdentityQueueTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain\Monkey and preload for pcov attribution.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Maintenance\IdentityQueue' );
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A queue reading from a double.
	 *
	 * @param array<int, array<string, mixed>> $multiple  Accounts holding several identifiers.
	 * @param array<string, string>            $verdicts  Hash => verdict.
	 * @param array<int, array<string, mixed>> $shared    Identifiers held by several accounts.
	 * @param array<int, array<string, mixed>> $failures  Check-digit failures.
	 * @return IdentityQueue
	 */
	private function queue_reading(
		array $multiple,
		array $verdicts = array(),
		array $shared = array(),
		array $failures = array()
	): IdentityQueue {
		$query = Mockery::mock( IdentityConflictQuery::class );
		$query->shouldReceive( 'multiple_identities' )->andReturn( $multiple );
		$query->shouldReceive( 'shared_identities' )->andReturn( $shared );
		$query->shouldReceive( 'rf_check_digit_failures' )->andReturn( $failures );
		$query->shouldReceive( 'check_digit_verdicts' )->andReturn( $verdicts );
		$query->shouldReceive( 'rf_scan_coverage' )->andReturn(
			array(
				'stores'     => 3,
				'examined'   => 2,
				'unreadable' => 0,
			)
		);

		return new class( $query ) extends IdentityQueue {

			/**
			 * The double.
			 *
			 * @var IdentityConflictQuery
			 */
			private $double;

			/**
			 * Take the double.
			 *
			 * @param IdentityConflictQuery $double Stand-in for the real query.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * The query this tiering reads through.
			 *
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}
		};
	}

	/**
	 * One account holding two identifiers, as the finding arrives.
	 *
	 * @param string $related  The joined hashes.
	 * @param array<string, mixed> $verdicts Extra columns the query annotates.
	 * @return array<int, array<string, mixed>>
	 */
	private static function account_holding( string $related, array $verdicts = array() ): array {
		return array(
			array_merge(
				array(
					'subject'                               => '398',
					'identifier_column'                     => 'rf_hash',
					IdentityConflictQuery::COLUMN_RELATED   => $related,
					IdentityConflictQuery::ALIAS_IDENTITY_COUNT => 2,
				),
				$verdicts
			),
		);
	}

	/**
	 * The two columns that make a finding the shared-mailbox reading.
	 *
	 * @return array<string, string>
	 */
	private static function mailbox_columns(): array {
		return array(
			IdentityConflictQuery::COLUMN_EMAIL_VERDICT => IdentityConflictQuery::VERDICT_SHARED_EMAIL,
			IdentityConflictQuery::COLUMN_SHAPE_VERDICT => IdentityConflictQuery::SHAPE_UNRELATED,
		);
	}

	/**
	 * ONE ADDRESS ON NUMBERS THAT ARE NOT VARIANTS OF EACH OTHER (#1368).
	 *
	 * 38 production findings carry this shape and every one of them was being
	 * offered consolidate, move and split. The tier exists so no verb reaches
	 * them.
	 */
	public function test_shared_address_on_unrelated_numbers_is_a_mailbox(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB', self::mailbox_columns() ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_VALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_MAILBOX, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * THE ASYMMETRIC REFUSAL, ASSERTED RATHER THAN DESCRIBED.
	 *
	 * The check digits single one out, so without the two verdicts this is
	 * the mechanical tier and one click resolves it. With them it is refused,
	 * because a number that may be another person's is not a typo to
	 * overwrite. Refusing a real typo costs a manual correction; accepting a
	 * shared mailbox costs somebody else's records.
	 */
	public function test_the_mailbox_reading_outranks_a_mechanical_one(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB', self::mailbox_columns() ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_MAILBOX, $items[0][ IdentityQueue::COLUMN_TIER ] );
		$this->assertArrayNotHasKey( IdentityQueue::COLUMN_WRONG, $items[0] );
		$this->assertArrayNotHasKey( IdentityQueue::COLUMN_RIGHT, $items[0] );
	}

	/**
	 * Each verdict alone is not the reading, and a report cached before the
	 * two columns existed carries neither -- so an absent column must answer
	 * no rather than default into a tier that withholds every verb.
	 *
	 * @dataProvider not_mailbox_columns
	 *
	 * @param array<string, string> $columns What the finding carries.
	 */
	public function test_one_verdict_alone_is_not_the_mailbox_reading( array $columns ): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB', $columns ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_MECHANICAL, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * @return array<string, array<int, array<string, string>>>
	 */
	public static function not_mailbox_columns(): array {
		return array(
			'neither column'      => array( array() ),
			'shared address only' => array(
				array( IdentityConflictQuery::COLUMN_EMAIL_VERDICT => IdentityConflictQuery::VERDICT_SHARED_EMAIL ),
			),
			'unrelated only'      => array(
				array( IdentityConflictQuery::COLUMN_SHAPE_VERDICT => IdentityConflictQuery::SHAPE_UNRELATED ),
			),
			'distinct addresses'  => array(
				array(
					IdentityConflictQuery::COLUMN_EMAIL_VERDICT => IdentityConflictQuery::VERDICT_DISTINCT_EMAILS,
					IdentityConflictQuery::COLUMN_SHAPE_VERDICT => IdentityConflictQuery::SHAPE_UNRELATED,
				),
			),
			'a near miss'         => array(
				array(
					IdentityConflictQuery::COLUMN_EMAIL_VERDICT => IdentityConflictQuery::VERDICT_SHARED_EMAIL,
					IdentityConflictQuery::COLUMN_SHAPE_VERDICT => IdentityConflictQuery::SHAPE_SINGLE_DIGIT_EDIT,
				),
			),
		);
	}

	/**
	 * The shape of all 32 production typos: one account, two RFs, exactly one
	 * of which fails. The check digits have decided, so nobody types a value.
	 */
	public function test_one_failure_among_two_read_values_is_mechanical(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertCount( 1, $items );
		$this->assertSame( IdentityQueue::TIER_MECHANICAL, $items[0][ IdentityQueue::COLUMN_TIER ] );
		$this->assertSame( 'hashA', $items[0][ IdentityQueue::COLUMN_WRONG ] );
		$this->assertSame( 'hashB', $items[0][ IdentityQueue::COLUMN_RIGHT ] );
	}

	/**
	 * Both well-formed: the check digits say nothing about which is the
	 * person's, so a person decides.
	 */
	public function test_two_valid_identifiers_need_a_decision(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_VALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_DECISION, $items[0][ IdentityQueue::COLUMN_TIER ] );
		$this->assertArrayNotHasKey( IdentityQueue::COLUMN_RIGHT, $items[0] );
	}

	/**
	 * Both wrong: there is nothing to consolidate into.
	 */
	public function test_two_invalid_identifiers_need_a_decision(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_INVALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_DECISION, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * THE ONE THAT LOOKS MECHANICAL AND IS NOT.
	 *
	 * One failure and one value nobody could read counts the same as one
	 * failure and one pass, if you only count failures. The unread value may
	 * be the person's real number, so consolidating into the other one would
	 * overwrite the evidence with a guess. Not having read a value is not
	 * evidence about it.
	 */
	public function test_a_failure_beside_an_unreadable_value_is_not_mechanical(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_UNREADABLE,
			)
		)->items();

		$this->assertSame(
			IdentityQueue::TIER_DECISION,
			$items[0][ IdentityQueue::COLUMN_TIER ],
			'An unread value must never be consolidated away.'
		);
	}

	/**
	 * The same trap with the other unread verdict.
	 */
	public function test_a_failure_beside_an_absent_value_is_not_mechanical(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_ABSENT,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_DECISION, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * Three identifiers of which one fails leaves TWO survivors, so the check
	 * digits have narrowed the question rather than answered it.
	 */
	public function test_three_identifiers_are_never_mechanical(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB|hashC' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
				'hashC' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertSame( IdentityQueue::TIER_DECISION, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * One identifier on two accounts is a merge, and never a repair.
	 */
	public function test_an_identifier_on_two_accounts_is_shared(): void {
		$items = $this->queue_reading(
			array(),
			array(),
			array(
				array(
					'subject'                             => 'hashA',
					'identifier_column'                   => 'rf_hash',
					IdentityConflictQuery::COLUMN_RELATED => '5784|6092',
				),
			)
		)->items();

		$this->assertCount( 1, $items );
		$this->assertSame( IdentityQueue::TIER_SHARED, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * A check-digit failure no account-side finding explains is its own work:
	 * somebody who mistyped once on their only row, or a candidacy carrying no
	 * account until promotion.
	 */
	public function test_an_unexplained_failure_is_isolated(): void {
		$items = $this->queue_reading(
			array(),
			array(),
			array(),
			array( array( 'subject' => 'hashZ' ) )
		)->items();

		$this->assertCount( 1, $items );
		$this->assertSame( IdentityQueue::TIER_ISOLATED, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * A worklist that lists one finding twice cannot be worked to zero.
	 *
	 * The failure the account-side finding already names must not come back as
	 * its own item -- it is the same finding seen from the other side.
	 */
	public function test_a_failure_an_account_item_already_names_is_not_repeated(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			),
			array(),
			array( array( 'subject' => 'hashA' ) )
		)->items();

		$this->assertCount( 1, $items, 'hashA is already the mechanical item\'s wrong half.' );
		$this->assertSame( IdentityQueue::TIER_MECHANICAL, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * A failure the SHARED list already names is likewise not repeated.
	 */
	public function test_a_failure_a_shared_item_already_names_is_not_repeated(): void {
		$items = $this->queue_reading(
			array(),
			array(),
			array( array( 'subject' => 'hashA', 'identifier_column' => 'rf_hash' ) ),
			array( array( 'subject' => 'hashA' ) )
		)->items();

		$this->assertCount( 1, $items );
		$this->assertSame( IdentityQueue::TIER_SHARED, $items[0][ IdentityQueue::COLUMN_TIER ] );
	}

	/**
	 * An empty worklist must carry what the scan read, or it cannot be told
	 * apart from a scan that read nothing at all — the #1384 failure exactly.
	 */
	public function test_the_worklist_carries_what_the_scan_read(): void {
		$queue = $this->queue_reading( array() );
		$queue->items();

		$this->assertSame(
			array(
				'stores'     => 3,
				'examined'   => 2,
				'unreadable' => 0,
			),
			$queue->coverage()
		);
	}

	/**
	 * A CHECK THAT RETURNS A FULL PAGE HAS MORE, AND MUST SAY SO.
	 *
	 * Each of the three checks is asked for `$limit` findings. One that hands
	 * back exactly that many is indistinguishable, from the rows alone, from
	 * one that returned everything it had — which is the `#1384` shape: a
	 * capped answer and a complete one look identical. `SubmissionLinkAuditor`
	 * has decided this the same way for its seven checks since 6.27.0.
	 */
	public function test_a_check_that_fills_its_page_is_reported_as_capped(): void {
		$queue = $this->queue_reading(
			array(),
			array(),
			array(
				array( 'subject' => 'hashA', 'identifier_column' => 'rf_hash' ),
				array( 'subject' => 'hashB', 'identifier_column' => 'rf_hash' ),
			)
		);
		$queue->items( 2 );

		$this->assertSame( array( IdentityQueue::CHECK_SHARED ), $queue->truncated() );
	}

	/**
	 * A check with room to spare is not capped, and the register is per scan:
	 * a later call that fills nothing must not inherit the earlier answer.
	 */
	public function test_a_check_with_room_left_is_not_capped_and_the_register_resets(): void {
		$queue = $this->queue_reading(
			array(),
			array(),
			array( array( 'subject' => 'hashA', 'identifier_column' => 'rf_hash' ) )
		);

		$queue->items( 2 );
		$this->assertSame( array(), $queue->truncated() );

		$queue->items( 1 );
		$this->assertSame( array( IdentityQueue::CHECK_SHARED ), $queue->truncated() );

		$queue->items( 2 );
		$this->assertSame( array(), $queue->truncated(), 'A scan must answer about itself, never about the last one.' );
	}

	/**
	 * EVERY ITEM CARRIES A KEY, AND A POSITION IS NOT ONE.
	 *
	 * The key is what a cursor holds onto across two scans. Composed of the
	 * tier, the column and the subject, so two findings that differ in any of
	 * the three are two keys — a hash sitting in `rf_hash` and the same hash
	 * sitting in `cpf_hash` are different questions.
	 */
	public function test_every_item_carries_a_key_and_the_check_that_found_it(): void {
		$items = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			),
			array( array( 'subject' => 'hashS', 'identifier_column' => 'cpf_hash' ) ),
			array( array( 'subject' => 'hashZ' ) )
		)->items();

		$keys = array_column( $items, IdentityQueue::COLUMN_KEY );

		$this->assertCount( 3, $keys );
		$this->assertCount( 3, array_unique( $keys ), 'Two findings must never share a key.' );
		$this->assertSame(
			array(
				IdentityQueue::TIER_MECHANICAL . '|rf_hash|398',
				IdentityQueue::TIER_SHARED . '|cpf_hash|hashS',
				IdentityQueue::TIER_ISOLATED . '||hashZ',
			),
			$keys
		);
		$this->assertSame(
			array( IdentityQueue::CHECK_MULTIPLE, IdentityQueue::CHECK_SHARED, IdentityQueue::CHECK_DIGITS ),
			array_column( $items, IdentityQueue::COLUMN_CHECK )
		);
	}

	/**
	 * THE KEY IS STAMPED AFTER THE TIER IS FINAL.
	 *
	 * The same account-side finding is mechanical or a decision depending on
	 * what the check digits came back with, and the tier is part of the key —
	 * so keying it while it still said `decision` would give one finding two
	 * identities depending on when it was read.
	 */
	public function test_a_mechanical_item_is_keyed_as_mechanical(): void {
		$decided = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_INVALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$undecided = $this->queue_reading(
			self::account_holding( 'hashA|hashB' ),
			array(
				'hashA' => IdentityConflictQuery::VERDICT_VALID,
				'hashB' => IdentityConflictQuery::VERDICT_VALID,
			)
		)->items();

		$this->assertStringStartsWith( IdentityQueue::TIER_MECHANICAL . '|', $decided[0][ IdentityQueue::COLUMN_KEY ] );
		$this->assertStringStartsWith( IdentityQueue::TIER_DECISION . '|', $undecided[0][ IdentityQueue::COLUMN_KEY ] );
	}

	/**
	 * A finding whose identifier list arrives empty must not be judged on an
	 * empty verdict map — zero identifiers is not two.
	 */
	public function test_a_finding_with_no_identifiers_is_a_decision(): void {
		$items = $this->queue_reading( self::account_holding( '' ) )->items();

		$this->assertSame( IdentityQueue::TIER_DECISION, $items[0][ IdentityQueue::COLUMN_TIER ] );
		$this->assertSame( array(), $items[0][ IdentityQueue::COLUMN_VERDICTS ] );
	}
}
