<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityQueuePanels;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Admin\IdentityResolutionScreen;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;

/**
 * What the identity-resolution screen derives before it draws (#1925).
 *
 * These conditions were source strings asserted against the view while they
 * lived there, because the view is outside the coverage scope. Run here, each
 * one is driven by a value and read back -- which is what an assertion on the
 * #1071 class owes: an unread scan must be shown to land on "read nothing",
 * not merely shown to have a branch that could.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionScreen
 */
class IdentityResolutionScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * One panel as `IdentityQueuePanels::build()` shapes it.
	 *
	 * @param string $tier  The tier.
	 * @param int    $total How many findings it holds.
	 * @param string $key   The current finding's key.
	 * @param bool   $list  Whether it shows its whole list.
	 * @return array<string, mixed>
	 */
	private static function panel( string $tier, int $total, string $key = '', bool $list = false ): array {
		return array(
			'tier'    => $tier,
			'total'   => $total,
			'list'    => $list,
			'current' => array( IdentityQueue::COLUMN_KEY => $key ),
			'items'   => array( array( 'k' => 1 ), array( 'k' => 2 ) ),
		);
	}

	/**
	 * A coverage record for one column.
	 *
	 * @return array{stores: int, examined: int, unreadable: int}
	 */
	private static function read( int $stores, int $examined, int $unreadable ): array {
		return array(
			'stores'     => $stores,
			'examined'   => $examined,
			'unreadable' => $unreadable,
		);
	}

	/**
	 * The one strip a single-column coverage record produces.
	 *
	 * @param array{stores: int, examined: int, unreadable: int} $read     What the scan read.
	 * @param array<int, string>                                 $capped   Checks that returned a full page.
	 * @param array<int, array<string, mixed>>                   $findings The held list.
	 * @return array<string, mixed>
	 */
	private static function strip( array $read, array $capped = array(), array $findings = array() ): array {
		$screen = new IdentityResolutionScreen( array(), $findings, array( 'rf_hash' => $read ), $capped, 0 );

		return $screen->strips[0];
	}

	public function test_a_scan_that_found_no_store_read_nothing(): void {
		$strip = self::strip( self::read( 0, 0, 0 ) );

		$this->assertTrue( $strip['read_none'] );
		$this->assertSame( 'none', $strip['state'] );
		$this->assertSame( 'RF', $strip['name'] );
	}

	public function test_a_scan_that_could_read_none_of_what_it_found_read_nothing(): void {
		$this->assertSame( 'none', self::strip( self::read( 3, 40, 40 ) )['state'] );
	}

	public function test_a_scan_that_found_values_but_examined_none_read_part(): void {
		$strip = self::strip( self::read( 3, 0, 0 ) );

		$this->assertFalse( $strip['read_none'], 'A store was found, so this is not the "read nothing" state.' );
		$this->assertSame( 'partial', $strip['state'] );
	}

	public function test_a_scan_with_some_unreadable_values_read_part(): void {
		$this->assertSame( 'partial', self::strip( self::read( 3, 40, 4 ) )['state'] );
	}

	public function test_a_check_that_returned_a_full_page_makes_the_reading_partial(): void {
		$this->assertSame( 'partial', self::strip( self::read( 3, 40, 0 ), array( IdentityQueue::CHECK_MULTIPLE ) )['state'] );
	}

	public function test_only_a_clean_uncapped_reading_is_whole(): void {
		$strip = self::strip( self::read( 3, 40, 0 ) );

		$this->assertFalse( $strip['read_none'] );
		$this->assertFalse( $strip['read_part'] );
		$this->assertSame( 'whole', $strip['state'] );
	}

	/**
	 * The scan cap is read per column (#1486): one flag for both would report
	 * a truncated RF scan on the CPF strip and the other way round.
	 */
	public function test_each_strip_reads_its_own_columns_scan_cap(): void {
		$findings = array(
			array(
				'identifier_column'                         => 'cpf_hash',
				IdentityConflictQuery::COLUMN_SCAN_TRUNCATED => true,
			),
		);
		$screen   = new IdentityResolutionScreen(
			array(),
			$findings,
			array(
				'rf_hash'  => self::read( 3, 40, 0 ),
				'cpf_hash' => self::read( 3, 40, 0 ),
			),
			array(),
			0
		);

		$this->assertTrue( $screen->truncated );
		$this->assertSame( array( 'cpf_hash' => true ), $screen->truncated_columns );
		$this->assertSame( 'whole', $screen->strips[0]['state'], 'The RF strip must not inherit the CPF cap.' );
		$this->assertFalse( $screen->strips[0]['scan_cap'] );
		$this->assertSame( 'partial', $screen->strips[1]['state'] );
		$this->assertTrue( $screen->strips[1]['scan_cap'] );
	}

	/**
	 * A HELD LIST THAT LOST ITS READINGS MUST SAY SO, NOT GO QUIET.
	 */
	public function test_a_lost_reading_becomes_one_unnamed_strip_that_read_nothing(): void {
		$screen = new IdentityResolutionScreen( array(), array(), array(), array(), 0 );

		$this->assertCount( 1, $screen->strips );
		$this->assertSame( '', $screen->strips[0]['column'] );
		$this->assertSame( '', $screen->strips[0]['name'], 'An unnamed strip renders the verdict that names no identifier.' );
		$this->assertSame( 'none', $screen->strips[0]['state'] );
	}

	/**
	 * Stores is a MAX and the other two are SUMS (#1500).
	 */
	public function test_the_total_across_columns_maxes_stores_and_sums_values(): void {
		$screen = new IdentityResolutionScreen(
			array(),
			array(),
			array(
				'rf_hash'  => self::read( 3, 3014, 10 ),
				'cpf_hash' => self::read( 2, 12705, 5 ),
			),
			array(),
			0
		);

		$this->assertSame(
			array(
				'stores'     => 3,
				'examined'   => 15719,
				'unreadable' => 15,
			),
			$screen->all
		);
	}

	public function test_the_counters_are_the_panels_counted_plus_the_orphans(): void {
		$screen = new IdentityResolutionScreen(
			array(
				self::panel( IdentityQueue::TIER_MECHANICAL, 2 ),
				self::panel( IdentityQueue::TIER_ISOLATED, 5 ),
				self::panel( IdentityQueue::TIER_SHARED, 3 ),
				self::panel( IdentityQueue::TIER_MAILBOX, 7 ),
			),
			array(),
			array(),
			array(),
			4
		);

		$this->assertSame( 21, $screen->remaining );

		$correct = 0;
		$judge   = 0;
		foreach ( array( IdentityQueue::TIER_MECHANICAL => 2, IdentityQueue::TIER_ISOLATED => 5, IdentityQueue::TIER_SHARED => 3, IdentityQueue::TIER_MAILBOX => 7 ) as $tier => $n ) {
			if ( in_array( $tier, IdentityQueuePanels::CORRECTIONS, true ) ) {
				$correct += $n;
			} elseif ( in_array( $tier, IdentityQueuePanels::JUDGEMENTS, true ) ) {
				$judge += $n;
			}
		}

		$this->assertGreaterThan( 0, $correct, 'The fixture must hold a correction, or the split below measures nothing.' );
		$this->assertGreaterThan( 0, $judge, 'And a judgement.' );
		$this->assertSame( $correct, $screen->to_correct );
		$this->assertSame( $judge, $screen->to_judge );
	}

	public function test_panels_are_keyed_by_tier_with_their_cursors_and_list_state(): void {
		$screen = new IdentityResolutionScreen(
			array(
				self::panel( IdentityQueue::TIER_MECHANICAL, 2, 'mech-1' ),
				self::panel( IdentityQueue::TIER_SHARED, 3, 'shared-2', true ),
			),
			array(),
			array(),
			array(),
			0
		);

		$this->assertSame( array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_SHARED ), array_keys( $screen->by_tier ) );
		$this->assertSame(
			array(
				IdentityQueue::TIER_MECHANICAL => 'mech-1',
				IdentityQueue::TIER_SHARED     => 'shared-2',
			),
			$screen->cursors
		);
		$this->assertSame( array( IdentityQueue::TIER_SHARED ), $screen->listed );
	}

	/**
	 * A link that moves one panel carries every other panel where it is.
	 */
	public function test_a_url_moves_one_panel_and_leaves_the_others(): void {
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://x.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '#' . (string) json_encode( $args ) );
		$read = static function ( string $url ): array {
			$at = (int) strpos( $url, '#' );

			return array(
				'url'  => substr( $url, 0, $at ),
				'args' => (array) json_decode( substr( $url, $at + 1 ), true ),
			);
		};

		$screen = new IdentityResolutionScreen(
			array(
				self::panel( IdentityQueue::TIER_MECHANICAL, 2, 'mech-1' ),
				self::panel( IdentityQueue::TIER_SHARED, 3, '' ),
			),
			array(),
			array(),
			array(),
			0
		);

		$moved = $read( $screen->url( IdentityQueue::TIER_SHARED, 'shared-9', array( IdentityQueue::TIER_ISOLATED ) ) );

		$this->assertSame( 'https://x.test/wp-admin/edit.php?post_type=ffc_form', $moved['url'] );
		$this->assertSame( IdentityResolutionPage::MENU_SLUG, $moved['args']['page'] );
		$this->assertSame(
			array(
				IdentityQueue::TIER_MECHANICAL => 'mech-1',
				IdentityQueue::TIER_SHARED     => 'shared-9',
			),
			$moved['args'][ IdentityQueuePanels::ARG_AT ]
		);
		$this->assertSame( IdentityQueue::TIER_ISOLATED, $moved['args'][ IdentityQueuePanels::ARG_LIST ] );

		$still = $read( $screen->url( '', '', array() ) );

		$this->assertSame( array( IdentityQueue::TIER_MECHANICAL => 'mech-1' ), $still['args'][ IdentityQueuePanels::ARG_AT ], 'An empty cursor is dropped, not sent.' );
		$this->assertArrayNotHasKey( IdentityQueuePanels::ARG_LIST, $still['args'], 'No list argument when nothing is listed.' );
	}

	public function test_a_panel_shows_its_current_finding_or_its_whole_list(): void {
		$one  = self::panel( IdentityQueue::TIER_MECHANICAL, 2, 'mech-1' );
		$list = self::panel( IdentityQueue::TIER_MECHANICAL, 2, 'mech-1', true );

		$this->assertSame( array( $one['current'] ), IdentityResolutionScreen::shown( $one ) );
		$this->assertSame( $list['items'], IdentityResolutionScreen::shown( $list ) );
	}

	/**
	 * The toggle keeps every other listed tier and flips this one.
	 */
	public function test_the_list_toggle_flips_one_tier_and_keeps_the_others(): void {
		$screen = new IdentityResolutionScreen(
			array(
				self::panel( IdentityQueue::TIER_MECHANICAL, 2, 'mech-1', true ),
				self::panel( IdentityQueue::TIER_SHARED, 3, '', true ),
				self::panel( IdentityQueue::TIER_ISOLATED, 1 ),
			),
			array(),
			array(),
			array(),
			0
		);

		$this->assertSame( array( IdentityQueue::TIER_MECHANICAL ), $screen->toggle( IdentityQueue::TIER_SHARED, true ), 'A listing panel leaves the list.' );
		$this->assertSame(
			array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_SHARED, IdentityQueue::TIER_ISOLATED ),
			$screen->toggle( IdentityQueue::TIER_ISOLATED, false ),
			'A stepping panel joins the list, after the ones already in it.'
		);
	}

	/**
	 * The acknowledgement is owed only to the finding the refusal named.
	 */
	public function test_a_refusal_is_owed_to_the_finding_it_named_only(): void {
		$refusal = array(
			'type'    => 'error',
			'text'    => 'Refused',
			'code'    => 'ffc_identity_repair_unacknowledged',
			'subject' => 'hashI',
		);

		$this->assertTrue( IdentityResolutionScreen::refused( $refusal, 'hashI' ) );
		$this->assertFalse( IdentityResolutionScreen::refused( $refusal, 'hashJ' ), 'Another finding was not refused.' );
		$this->assertFalse( IdentityResolutionScreen::refused( array_merge( $refusal, array( 'code' => 'other' ) ), 'hashI' ), 'Another refusal asks for nothing.' );
		$this->assertFalse( IdentityResolutionScreen::refused( false, 'hashI' ), 'No outcome refuses nothing.' );
	}
}
