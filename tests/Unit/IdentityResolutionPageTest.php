<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityRepair;

/**
 * The identity-resolution worklist screen (#1368).
 *
 * What these assert is the WIRING and the GATE — that the submenu is
 * registered under the capability the issue split out, and that the queue
 * reads the check-digit scan. That the scan finds the right values is
 * `IdentityConflictQueryTest`'s job, through the seam this exercises.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityResolutionPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain\Monkey and preload the class under test.
	 *
	 * The preload is the pcov attribution fix CLAUDE.md records: a class first
	 * autoloaded DURING a test method reports 0% even when fully exercised.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		// The queue is read through `IdentityWorklist`, which holds it in a
		// transient so a screen worked item by item costs one scan rather than
		// one per resolution (#1397). A store that starts empty makes every
		// case here take a fresh list, which is what they are all about.
		$held = array();

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$held ) {
				return $held[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$held ) {
				$held[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$held ) {
				unset( $held[ $key ] );

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
	 * A page whose query seam is a double, so no table has to stand up.
	 *
	 * @param array<int, array<string, mixed>> $findings What the scan reports.
	 * @return IdentityResolutionPage
	 */
	private function page_reading( array $findings ): IdentityResolutionPage {
		$query = Mockery::mock( IdentityConflictQuery::class );
		$query->shouldReceive( 'multiple_identities' )->andReturn( array() );
		$query->shouldReceive( 'shared_identities' )->andReturn( array() );
		$query->shouldReceive( 'rf_check_digit_failures' )
			->once()
			->with( IdentityResolutionPage::LIMIT )
			->andReturn( $findings );
		$query->shouldReceive( 'rf_scan_coverage' )->andReturn(
			array(
				'stores'     => 3,
				'examined'   => count( $findings ),
				'unreadable' => 0,
			)
		);

		return new class( $query ) extends IdentityResolutionPage {

			/** @var IdentityConflictQuery */
			private $double;

			/**
			 * @param IdentityConflictQuery $double Stand-in for the real query.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}
		};
	}

	/**
	 * The screen registers one submenu, under the capability #1368 split out.
	 *
	 * The capability is the point of the whole PR: `ffc_manage_settings_dangerzone`
	 * would also hand this operator delete-all and the cleanups.
	 */
	public function test_it_registers_the_submenu_under_its_own_capability(): void {
		$captured = array();

		Functions\when( 'add_submenu_page' )->alias(
			static function ( ...$args ) use ( &$captured ) {
				$captured[] = $args;
				return 'hook';
			}
		);

		( new IdentityResolutionPage() )->register_menu();

		$this->assertCount( 1, $captured, 'The screen must register exactly one submenu.' );
		$this->assertSame( IdentityResolutionPage::PARENT, $captured[0][0], 'The submenu must hang off the Certificate menu.' );
		$this->assertSame(
			'ffc_manage_identities',
			$captured[0][3],
			'The screen must be gated on ffc_manage_identities, never on the danger-zone cap it was split out of.'
		);
		$this->assertSame( IdentityResolutionPage::MENU_SLUG, $captured[0][4] );
	}

	/**
	 * The menu slug and the page-scope class agree.
	 *
	 * `AdminPageScopeTest` freezes the map; this pins the other half, so a
	 * slug renamed here without the view cannot pass by agreeing with itself.
	 */
	public function test_the_page_scope_class_follows_the_slug(): void {
		$this->assertStringStartsWith( 'ffc-', IdentityResolutionPage::MENU_SLUG );

		$expected = 'ffc-page-' . substr( IdentityResolutionPage::MENU_SLUG, strlen( 'ffc-' ) );
		$view     = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'wrap ffc-admin-page ' . $expected,
			$view,
			'The view\'s div.wrap must carry the generic anchor plus the page class derived from the slug.'
		);
	}

	/**
	 * `init()` hooks the registration onto `admin_menu`.
	 */
	public function test_init_hooks_the_registration(): void {
		$page = new IdentityResolutionPage();

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_menu', array( $page, 'register_menu' ) );

		$page->init();
	}

	/**
	 * The queue reads the check-digit scan, at this screen's own limit.
	 *
	 * The limit is deliberately above the audit card's 50: this is a worklist
	 * an operator works to zero, not a sample.
	 */
	public function test_the_queue_reads_the_check_digit_scan(): void {
		$findings = array(
			array(
				IdentityConflictQuery::COLUMN_RELATED   => '7',
				IdentityConflictQuery::COLUMN_ROW_IDS   => 'submissions:4,9',
				IdentityConflictQuery::ALIAS_ROW_COUNT  => 2,
			),
		);

		$queue = $this->page_reading( $findings )->queue();

		$this->assertCount( 1, $queue );
		$this->assertSame(
			IdentityQueue::TIER_ISOLATED,
			$queue[0][ IdentityQueue::COLUMN_TIER ],
			'A check-digit failure no account-side finding explains is its own tier.'
		);
		$this->assertSame( 'submissions:4,9', $queue[0][ IdentityConflictQuery::COLUMN_ROW_IDS ] );

		$this->assertGreaterThan(
			50,
			IdentityResolutionPage::LIMIT,
			'A worklist capped at the audit card\'s sample size would hide its own tail.'
		);
	}

	/**
	 * The screen refuses a visitor without the capability.
	 */
	public function test_it_refuses_a_visitor_without_the_capability(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( IdentityResolutionPage::CAPABILITY )
			->andReturn( false );

		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'died' ) );

		$this->expectException( \RuntimeException::class );

		( new IdentityResolutionPage() )->render_page();
	}

	/**
	 * The screen never touches the database itself.
	 *
	 * It DOES write now — that is what the repair is — but every write goes
	 * through `IdentityRepair`, which owns the transaction, the refusals and
	 * the ordering. A `$wpdb` call appearing on this side would be a second
	 * write path with none of that, and it would look fine in review.
	 *
	 * This replaces the read-only assertion PR 2 carried: that claim stopped
	 * being true the moment the repair landed, and a test whose premise is
	 * false still passes.
	 */
	public function test_the_screen_never_writes_directly(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' )
			. (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// The CALL shapes, never the bare token: this class's own docblock
		// explains why the query seam exists and says `$wpdb` doing it, and a
		// test that cannot tell prose from a call is the trap CLAUDE.md
		// records for suppression scanners.
		foreach ( array( '$wpdb->', 'global $wpdb', 'update_option(', 'update_user_meta(', 'wp_update_user(' ) as $direct ) {
			$this->assertStringNotContainsString(
				$direct,
				$source,
				sprintf( 'Every write belongs to IdentityRepair; `%s` on this side is a second path without its transaction.', $direct )
			);
		}
	}

	/**
	 * The write is gated on the capability AND a nonce keyed to the finding.
	 *
	 * Keyed per finding on purpose: a nonce valid for any row would let a
	 * correction confirmed for one person be replayed against another.
	 */
	public function test_the_write_is_gated_on_the_capability_and_a_keyed_nonce(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( 'Capabilities::current_user_can_admin_or( self::CAPABILITY )', $page );
		$this->assertStringContainsString( 'check_admin_referer( self::REPAIR_NONCE . $subject )', $page );
	}

	/**
	 * The consolidation is gated exactly as the repair is, on its OWN nonce.
	 *
	 * Its own action rather than a mode on the repair: the two take different
	 * input and make different promises, and one handler branching on which
	 * field arrived is one mistake away from writing the wrong number.
	 */
	public function test_the_consolidation_is_gated_on_the_capability_and_its_own_nonce(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( 'check_admin_referer( self::CONSOLIDATE_NONCE . $wrong )', $page );
		$this->assertNotSame(
			IdentityResolutionPage::REPAIR_NONCE,
			IdentityResolutionPage::CONSOLIDATE_NONCE,
			'A nonce shared between the two would let one confirm the other.'
		);
	}

	/**
	 * THE CONSOLIDATION CARRIES TWO HASHES AND NO VALUE.
	 *
	 * The number written is the account's sound identifier, which the service
	 * reads in memory. If the screen posted it instead, a stored RF or CPF
	 * would travel through the browser and sit in an operator's view for no
	 * reason at all — and the one-click tier would become a question.
	 */
	public function test_the_consolidation_posts_no_value(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$form = strstr( $view, 'CONSOLIDATE_ACTION' );
		$this->assertIsString( $form, 'The consolidation form must be in the view.' );

		$form = (string) strstr( $form, '</form>', true );

		$this->assertStringContainsString( 'name="ffc_target"', $form );
		$this->assertStringNotContainsString( 'name="ffc_rf"', $form );
		$this->assertStringNotContainsString( 'type="text"', $form, 'Nothing is typed into a consolidation.' );
	}

	/**
	 * A HANDLER THAT ACTS ON AN IDENTIFIER MUST SAY WHICH IDENTIFIER.
	 *
	 * Third time this assertion has been rewritten, and the first two were
	 * wrong in the same way: `2`, then a list of verb names, both of which are
	 * a census of today's shape. The merge is what finally showed the claim
	 * itself was false — it writes without naming an identifier at all,
	 * because it is between two ACCOUNTS and moves everything they hold.
	 *
	 * What actually holds is a mechanism: a handler that reads `ffc_subject`
	 * is acting on one stored hash, and a hash means nothing without the
	 * column it sits in — `rf_hash` and `cpf_hash` are different questions.
	 * So the two move together whatever verbs exist, and a handler that reads
	 * neither is outside the rule rather than an exception to it.
	 */
	public function test_a_handler_acting_on_an_identifier_names_which_one(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		// PER HANDLER, NOT A COUNT OVER THE FILE.
		//
		// This compared two `substr_count()`s and broke when the merge began
		// reading `ffc_subject` to scope its NONCE without acting on the
		// identifier -- a correct handler failing an invariant stated as an
		// equality between two numbers. What the rule means is narrower and
		// says itself: a handler that hands the subject to a SERVICE must
		// also hand it the column, because a stored hash means nothing
		// without the column it sits in.
		$bodies = array_slice( preg_split( '/\n\tpublic function handle_/', $page ) ?: array(), 1 );

		$this->assertNotEmpty( $bodies, 'The scan found no handler at all, so it proves nothing.' );

		$acting = 0;

		foreach ( $bodies as $body ) {
			if ( ! preg_match( '/\)->\w+\(\s*\$subject,/', $body ) ) {
				continue;
			}

			++$acting;

			$this->assertStringContainsString(
				'self::posted_field()',
				$body,
				'A handler passing the subject to a service must name the column it sits in.'
			);
		}

		$this->assertGreaterThan( 0, $acting, 'The scan found no handler acting on an identifier.' );
		$this->assertStringContainsString( 'in_array( $field, IdentityRepair::FIELDS, true )', $page );
	}

	/**
	 * The handler hands the repair the HASH and the value, never the row ids.
	 *
	 * Re-evaluation at confirmation time is the whole point: between listing a
	 * finding and confirming it the operator went and asked a person, and rows
	 * may have arrived or left.
	 */
	public function test_the_handler_passes_no_row_ids(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringNotContainsString( 'ffc_row_ids', $page );
		$this->assertStringContainsString( "RequestInput::get_post_string( 'ffc_subject', '' )", $page );
	}

	/**
	 * The screen knows what the scan READ, not only what it reported.
	 *
	 * An empty failure list means three unrelated things — no store carries
	 * the columns, nothing decrypted, or the data is genuinely fine — and only
	 * the third justifies telling an operator so. This is the `#1071` /
	 * `#1094` rule applied to a screen rather than to a guard.
	 */
	public function test_the_queue_carries_what_the_scan_read(): void {
		$query = Mockery::mock( IdentityConflictQuery::class );
		$query->shouldReceive( 'multiple_identities' )->andReturn( array() );
		$query->shouldReceive( 'shared_identities' )->andReturn( array() );
		$query->shouldReceive( 'rf_check_digit_failures' )->once()->andReturn( array() );
		$query->shouldReceive( 'rf_scan_coverage' )->once()->andReturn(
			array(
				'stores'     => 3,
				'examined'   => 40,
				'unreadable' => 40,
			)
		);

		$page = new class( $query ) extends IdentityResolutionPage {

			/** @var IdentityConflictQuery */
			private $double;

			/**
			 * @param IdentityConflictQuery $double Stand-in for the real query.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}
		};

		$this->assertSame( array(), $page->queue() );
		$this->assertSame(
			array(
				'stores'     => 3,
				'examined'   => 40,
				'unreadable' => 40,
			),
			$page->coverage(),
			'An empty list with nothing readable must not be presentable as a clean result.'
		);
	}

	/**
	 * EVERY VARIABLE THE VIEW DECLARES IS ONE `render_page()` ASSIGNS.
	 *
	 * The view is `require`d into the caller's scope and is excluded from
	 * PHPStan by the `views/` carve-out, so a variable it reads and nobody
	 * sets is invisible to every gate -- it surfaces as an undefined-variable
	 * warning on the live screen, or as a section that silently renders
	 * nothing. That is not hypothetical: building this sprint, two separate
	 * edits meant to add an assignment here did not apply, and the second was
	 * found only because PHPStan noticed the METHODS it would have called had
	 * become unused. The first had no such tell.
	 *
	 * Reads the docblock rather than the body because the docblock is the
	 * contract the view states -- a `@var` line with no assignment is the
	 * defect, whichever side was written first.
	 */
	public function test_the_view_is_handed_every_variable_it_declares(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		// Everything the view names, minus everything it makes itself: what is
		// left it can only have been handed. Reading the DECLARED list instead
		// was the first version of this test, and it passed against the very
		// defect it was written for -- the missing variable had no `@var` line
		// either, so the contract and the code were wrong together.
		preg_match_all( '/\$(ffc_identity_\w+)/', $view, $named );
		preg_match_all( '/\$(ffc_identity_\w+)\s*=[^=]/', $view, $made );
		preg_match_all( '/function\s*\([^)]*\$(ffc_identity_\w+)/', $view, $taken );
		preg_match_all( '/use\s*\([^)]*\$(ffc_identity_\w+)/', $view, $closed );
		preg_match_all( '/as\s+\$(ffc_identity_\w+)\s*(?:=>\s*\$(ffc_identity_\w+))?/', $view, $looped );

		$local = array_merge( $made[1], $taken[1], $closed[1], $looped[1], array_filter( $looped[2] ) );
		$given = array_values( array_diff( array_unique( $named[1] ), $local ) );

		$this->assertNotEmpty( $given, 'The scan found nothing handed in, so it proves nothing.' );

		foreach ( $given as $name ) {
			$this->assertMatchesRegularExpression(
				'/\$' . preg_quote( $name, '/' ) . '\s*=/',
				$page,
				sprintf( 'The view reads `$%s` without making it, and `render_page()` never assigns it.', $name )
			);
		}
	}

	/**
	 * The view refuses to call an unread scan clean.
	 *
	 * Asserted against the markup because the branch is in the view, which is
	 * outside the coverage scope by the same carve-out `phpstan.neon.dist`
	 * makes — so what can be pinned is that the three states exist and that
	 * the reassuring sentence is reachable only from the third.
	 */
	public function test_the_view_separates_the_four_empty_states(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( '0 === $ffc_identity_stores', $view, 'No store scanned is its own state.' );
		$this->assertStringContainsString( '$ffc_identity_examined === $ffc_identity_unreadable', $view, 'Nothing readable is its own state.' );
		$this->assertStringContainsString( '0 === $ffc_identity_examined', $view, 'Nothing FOUND is its own state, distinct from nothing readable.' );
		$this->assertStringContainsString( 'this is not a clean result', $view, 'Both unread states must say so.' );

		// Anchored on the opening of each LITERAL, never on a fragment: a
		// comment in the view that discusses one of these sentences would
		// otherwise match earlier than the message and invert the ordering.
		// That is exactly what the first version of this test did.

		// Ordering is the assertion, not presence: every branch above the last
		// one is a case the reassuring sentence must never speak for. The
		// first pass had three branches and let "0 examined" fall through to
		// it, rendering "0 stored values checked and every one satisfies".
		$reassuring = strpos( $view, "'Nothing to resolve: %1\$s stored values checked" );
		$unreadable = strpos( $view, "'Found %s stored values and could not read" );
		$none_found = strpos( $view, "'No stored RF was found at all" );

		$this->assertIsInt( $reassuring );
		$this->assertIsInt( $unreadable );
		$this->assertIsInt( $none_found );
		$this->assertGreaterThan( $unreadable, $reassuring, 'The reassuring sentence must come after the unreadable state.' );
		$this->assertGreaterThan( $none_found, $reassuring, 'The reassuring sentence must come after the nothing-found state.' );
	}

	/**
	 * The coverage is stated beside a queue that HAS findings (#1407).
	 *
	 * This is the case that was missing, and the shape of the miss is worth
	 * keeping: the three coverage numbers were read inside
	 * `if ( array() === $ffc_identity_panels )`, so the only screen state
	 * that never said how much of the data had been read was the state an
	 * operator actually works. The empty branch was careful and complete; the
	 * non-empty one asked nothing.
	 *
	 * Asserted on ordering rather than on presence, for the reason the test
	 * above gives: presence was already true.
	 */
	public function test_the_scan_coverage_is_stated_before_the_empty_branch(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$read  = strpos( $view, "\$ffc_identity_examined   = (int) ( \$ffc_identity_coverage['examined']" );
		$strip = strpos( $view, 'class="ffc-identity-scan ' );
		$empty = strpos( $view, 'array() === $ffc_identity_panels' );

		$this->assertIsInt( $read, 'The view must read what the scan covered.' );
		$this->assertIsInt( $strip, 'The view must draw the coverage strip.' );
		$this->assertIsInt( $empty, 'The empty-queue branch must still exist.' );

		$this->assertLessThan( $empty, $read, 'The coverage must be read outside the empty-queue branch, not inside it.' );
		$this->assertLessThan( $empty, $strip, 'The coverage strip must render whether or not the queue is empty.' );
	}

	/**
	 * The strip's verdict is three states, and the reassuring one is narrowest.
	 *
	 * The same trap the empty branch fell into once, in a place it is seen far
	 * more often: a strip that always reads as a tick is a claim the scan did
	 * not make. So an unread or partly-read scan must have somewhere else to
	 * land, and the conditions are asserted rather than the wording.
	 */
	public function test_the_strip_refuses_to_call_a_partial_scan_whole(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( '$ffc_identity_read_none = 0 === $ffc_identity_stores', $view, 'No store scanned must reach the unread verdict.' );
		$this->assertStringContainsString( '$ffc_identity_examined === $ffc_identity_unreadable', $view, 'Nothing readable must reach the unread verdict.' );
		$this->assertStringContainsString( '$ffc_identity_read_part = ! $ffc_identity_read_none', $view, 'The partial verdict must be reachable only when the scan read something.' );
		$this->assertStringContainsString( '|| $ffc_identity_truncated', $view, "The scan's own cap must make the reading partial." );
		$this->assertStringContainsString( '|| array() !== $ffc_identity_capped', $view, 'A check that returned a full page must make the reading partial.' );

		// The whole-reading verdict is the `else` of both, so it cannot be
		// reached while either flag is set. Anchored on the branch rather than
		// on the sentence, because the sentence is translatable and the
		// ordering is what carries the guarantee.
		$none  = strpos( $view, 'if ( $ffc_identity_read_none ) : ?>' );
		$part  = strpos( $view, 'elseif ( $ffc_identity_read_part ) : ?>' );
		$whole = strpos( $view, "esc_html_e( 'The scan read the data'" );

		$this->assertIsInt( $none );
		$this->assertIsInt( $part );
		$this->assertIsInt( $whole );
		$this->assertGreaterThan( $part, $whole, 'The reassuring verdict must come after the partial one.' );
		$this->assertGreaterThan( $none, $part, 'The partial verdict must come after the unread one.' );
	}

	/**
	 * The counters are the panels, counted — never a second list.
	 *
	 * A strip fed by its own query would drift from the bodies the moment a
	 * tier was dropped, filtered or capped on one side and not the other, and
	 * the operator would have no way to tell which number was the true one.
	 * Both read `$ffc_identity_panels`, which `IdentityQueuePanels::build()`
	 * has already emptied of the tiers holding nothing.
	 */
	public function test_the_counters_are_derived_from_the_panels(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'$ffc_identity_remaining += (int) $ffc_identity_panel[\'total\'];',
			$view,
			'The remaining count must be the panels summed.'
		);
		$this->assertStringContainsString(
			'$ffc_identity_remaining = count( $ffc_identity_orphans );',
			$view,
			'Orphans are a population of their own and must be counted as one.'
		);

		// The strip derives its chip class from the tier exactly as the panel
		// heading does, so a category cannot be one colour above and another
		// below.
		$this->assertSame(
			2,
			substr_count( $view, "'ffc-identity-chip-' . preg_replace( '/[^a-z]/', '', " ),
			'The chip class must be derived from the tier in both places, and nowhere else.'
		);
	}

	/**
	 * The CSV is offered only to somebody who can actually have it.
	 *
	 * The export lives behind `ffc_manage_settings_dangerzone`, which is
	 * deliberately not this screen's capability — the queue is read live here
	 * precisely so working it does not depend on holding that one. So the
	 * link is built or it is empty, and the view prints nothing for an empty
	 * one: the same decision the merge panel already makes, because an
	 * offered control that answers `wp_die` is worse than an absent one.
	 */
	public function test_the_export_link_is_gated_on_the_capability_that_serves_it(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertMatchesRegularExpression(
			'/\$ffc_identity_export_url\s*=\s*Capabilities::current_user_can_admin_or\(\s*\x27ffc_manage_settings_dangerzone\x27\s*\)/',
			$page,
			'The link must be built only for a holder of the capability the export itself checks.'
		);
		$this->assertStringContainsString(
			'IdentityAuditExportSource::NONCE',
			$page,
			'The link must carry the nonce the export verifies, read from the source rather than retyped.'
		);
		$this->assertStringContainsString(
			"'' !== \$ffc_identity_export_url",
			$view,
			'The view must print nothing when there is no link to print.'
		);
	}

	/**
	 * Just the two account tiers' markup, from the cards to the panel the
	 * isolated tier opens.
	 *
	 * Bounded on BOTH sides deliberately. The first version of these tests
	 * ran to the orphan comment far below and so swallowed the isolated
	 * table, which is sprint 3's and still a `wp-list-table` — the
	 * assertions then failed for a defect that was not there.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function account_tier_block( string $view ): string {
		$from = strpos( $view, 'class="ffc-identity-cards"' );
		$to   = strpos( $view, '$ffc_identity_rows = array();' );

		$this->assertIsInt( $from, 'The account tiers must be drawn as cards.' );
		$this->assertIsInt( $to, 'The isolated tier must still open with its own list.' );
		$this->assertGreaterThan( $from, $to, 'The account tiers come before the isolated one.' );

		return substr( $view, $from, $to - $from );
	}

	/**
	 * The two account tiers are drawn as cards, and every verdict survives
	 * the change of shape (#1407 sprint 2).
	 *
	 * The risk in replacing a table with a card is silent loss: a column
	 * whose contents nobody re-read simply stops being rendered, and the
	 * screen looks better while saying less. All four verdicts are asserted
	 * here for that reason, `unreadable` and `absent` above all -- those two
	 * are what `IdentityQueue::tiered()` refuses to treat as a failure, so a
	 * card that dropped them would make the screen assert the opposite of
	 * what the scan found.
	 */
	public function test_the_account_tiers_render_as_cards_carrying_every_verdict(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$cards = strpos( $view, 'class="ffc-identity-cards"' );
		$this->assertIsInt( $cards, 'The two account tiers must be drawn as cards.' );

		// The table is gone from THIS block, not from the file: the isolated
		// and orphan bodies are sprint 3's, so anchoring on the file having
		// no `wp-list-table` at all would fail for the right reason at the
		// wrong time.
		$block = $this->account_tier_block( $view );
		$this->assertStringNotContainsString( 'wp-list-table', $block, 'The account tiers must no longer render a list table.' );

		foreach (
			array(
				'VERDICT_INVALID'    => 'fails its check digit',
				'VERDICT_VALID'      => 'well formed',
				'VERDICT_UNREADABLE' => 'could not be read',
				'VERDICT_ABSENT'     => 'not stored where this can read',
			) as $verdict => $said
		) {
			$this->assertStringContainsString(
				'IdentityConflictQuery::' . $verdict,
				$block,
				sprintf( 'The card must still distinguish %s.', $verdict )
			);
			$this->assertStringContainsString( $said, $block, sprintf( 'The card must still say what %s means.', $verdict ) );
		}

		// A value nobody could read is not a failure, and the tone it takes
		// is what says so on screen.
		$this->assertSame(
			2,
			substr_count( $block, "\$ffc_identity_tone = 'unknown'" ),
			'Unreadable and absent must both be drawn as unknown, never as a failure.'
		);
	}

	/**
	 * The arrow exists exactly where the write has a direction.
	 *
	 * `IdentityQueue::tiered()` sets `wrong` and `right` only where one
	 * identifier failed and every other was READ — so drawing the pair as
	 * a before and after is honest there and nowhere else. On the decision
	 * tier the hashes are a set, and an arrow over them would assert the
	 * choice the check digits explicitly refused to make.
	 */
	public function test_the_arrow_is_drawn_only_where_the_digits_decided(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'$ffc_identity_directed = IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier',
			$view,
			'Only the mechanical tier may be drawn as directed.'
		);
		$this->assertStringContainsString( "&& '' !== \$ffc_identity_wrong", $view, 'A direction needs the hash that goes away.' );
		$this->assertStringContainsString( "&& '' !== \$ffc_identity_right", $view, 'A direction needs the hash that survives.' );
		$this->assertStringContainsString(
			'$ffc_identity_directed && ! $ffc_identity_first',
			$view,
			'The arrow must be drawn only between the two hashes of a directed pair.'
		);
		$this->assertStringContainsString(
			'ffc-identity-card-arrow" aria-hidden="true"',
			$view,
			'The arrow is decoration: the sentence and the chips already say which survives.'
		);
	}

	/**
	 * Replacing the table changed no form.
	 *
	 * The cards are markup and CSS. Every nonce, action and field name the
	 * four verbs post is asserted to still be emitted, because a card that
	 * quietly dropped a hidden input would fail as a refused write on a live
	 * screen and nowhere else — the forms are in a view, which PHPStan and
	 * the coverage report both skip.
	 */
	public function test_the_cards_changed_no_form(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		foreach (
			array(
				'IdentityResolutionPage::CONSOLIDATE_NONCE',
				'IdentityResolutionPage::CONSOLIDATE_ACTION',
				'IdentityResolutionPage::RELINK_NONCE',
				'IdentityResolutionPage::RELINK_ACTION',
				'IdentityResolutionPage::SPLIT_NONCE',
				'IdentityResolutionPage::SPLIT_ACTION',
				'name="ffc_key"',
				'name="ffc_subject"',
				'name="ffc_target"',
				'name="ffc_field"',
				'name="ffc_account"',
				'name="ffc_email"',
				'IdentityResolutionPage::REPAIR_NONCE',
				'IdentityResolutionPage::REPAIR_ACTION',
				'IdentityResolutionPage::ADOPT_NONCE',
				'IdentityResolutionPage::ADOPT_ACTION',
				'name="ffc_rf"',
				'name="ffc_cpf"',
			) as $token
		) {
			$this->assertStringContainsString( $token, $view, sprintf( 'The cards must still post %s.', $token ) );
		}

		// The search dialog reaches the move form by id, so the card has to
		// keep emitting the ids it binds to.
		foreach ( array( 'data-ffc-input=', 'data-ffc-split=', 'data-ffc-submit=' ) as $hook ) {
			$this->assertStringContainsString( $hook, $view, sprintf( 'The search dialog binds on %s.', $hook ) );
		}
	}

	/**
	 * The card names the stores and invents no record count.
	 *
	 * `ALIAS_ROW_COUNT` is produced only by the RF check-digit scan, so the
	 * two account tiers have no count to show — and a card that showed one
	 * would be showing a number per hash while its sentence spoke about a
	 * finding. Asserted rather than left to a comment, because the tier that
	 * DOES have a count is sprint 3's and the temptation to make the two
	 * cards match is exactly what this forbids.
	 */
	public function test_the_account_cards_state_the_stores_and_no_count(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$block = $this->account_tier_block( $view );

		$this->assertStringContainsString( 'IdentityConflictQuery::COLUMN_STORES', $block, 'The card must name the stores.' );
		$this->assertStringNotContainsString(
			'IdentityConflictQuery::ALIAS_ROW_COUNT',
			$block,
			'These two tiers carry no row count; showing one would mean inventing it.'
		);
	}

	/**
	 * No list table is left on the screen (#1407 sprint 3).
	 *
	 * The three bodies went one sprint at a time, so this is the assertion
	 * that could only be written once the last one did — and it is worth
	 * having as a whole-file check rather than a third per-block one,
	 * because what it forbids is a table coming BACK.
	 */
	public function test_no_panel_body_is_a_list_table_any_more(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringNotContainsString( 'wp-list-table', $view, 'Every panel body is a card now.' );
		$this->assertSame(
			3,
			substr_count( $view, 'class="ffc-identity-cards"' ),
			'Three bodies: the two account tiers together, the isolated tier, and the orphans.'
		);
	}

	/**
	 * The isolated tier states the count it HAS, and the account tiers still
	 * state none.
	 *
	 * The two cards are deliberately not identical. `ALIAS_ROW_COUNT` is
	 * selected by the check-digit scan and by neither account-side query, so
	 * the number is real here and would be invented there — and the pull to
	 * make two cards of the same shape agree is exactly what would invent it.
	 * Both halves are asserted together so neither can be "fixed" alone.
	 */
	public function test_only_the_tier_that_has_a_row_count_states_one(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringNotContainsString(
			'IdentityConflictQuery::ALIAS_ROW_COUNT',
			$this->account_tier_block( $view ),
			'The account tiers carry no row count.'
		);
		$this->assertStringContainsString(
			'IdentityConflictQuery::ALIAS_ROW_COUNT',
			$view,
			'The isolated tier does carry one, and dropping it would lose how far a correction reaches.'
		);
	}

	/**
	 * What the isolated and orphan cards say that no other card does.
	 *
	 * Each of these is a state the table had a column for, and a column is
	 * the easiest thing to lose when markup is rewritten: the three name
	 * outcomes (a name, none recorded, no store that records one installed),
	 * the truncation notice over the row ids, the refusal where a value names
	 * more than one account, and — on the orphan card — what the record
	 * LACKS, which is the whole reason `Open the account` may refuse.
	 */
	public function test_the_two_remaining_cards_keep_every_state_the_table_had(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		foreach (
			array(
				'No store that records a name is installed',
				'No name recorded beside these rows.',
				'and more',
				'Too many rows to list.',
				'Names more than one account',
				'IdentityConflictQuery::COLUMN_ROW_IDS_TRUNCATED',
				'IdentityConflictQuery::parse_row_ids',
				'ffc-identity-orphan-missing',
				'ffc-identity-orphan-present',
			) as $state
		) {
			$this->assertStringContainsString( $state, $view, sprintf( 'The cards must still express %s.', $state ) );
		}

		// The orphan card says which of the two situations it is in, because
		// that is what decides whether the operator links or opens.
		$this->assertStringContainsString(
			'$ffc_identity_orphan[\'accounts\'] )',
			$view,
			'The orphan card must still distinguish an identifier some account files from one none does.'
		);
	}

	/**
	 * The resolved counter knows every verb the screen has (#1407 sprint 4).
	 *
	 * `RESOLVED_ACTIONS` is a list of names written in five OTHER files, one
	 * per service — the shape `CLAUDE.md` records as going stale in silence,
	 * because nothing makes a constant and the code it describes disagree
	 * loudly. So the services are the measurement and the constant is checked
	 * against them, in BOTH directions: a sixth verb that logs an
	 * `identity_*` action fails here rather than being quietly uncounted, and
	 * a name left in the list after its service stopped logging it fails too.
	 */
	public function test_the_resolved_counter_names_every_identity_verb(): void {
		$dir = __DIR__ . '/../../includes/maintenance/';

		$logged = array();

		foreach ( (array) glob( $dir . '*.php' ) as $file ) {
			$source = (string) file_get_contents( (string) $file );

			// The call is `ActivityLog::log(` and the action is its first
			// argument, on the next line in every one of these services —
			// matched across the newline rather than line by line, because a
			// one-line scan sees the call and not the name.
			if ( preg_match_all( "/ActivityLog::log\(\s*'(identity_\w+)'/", $source, $found ) ) {
				$logged = array_merge( $logged, $found[1] );
			}
		}

		sort( $logged );
		$logged = array_values( array_unique( $logged ) );

		$this->assertNotEmpty( $logged, 'The scan found no identity verb at all, so it proves nothing.' );

		$declared = IdentityResolutionPage::RESOLVED_ACTIONS;
		sort( $declared );

		$this->assertSame(
			$logged,
			$declared,
			'The resolved counter and the services that log a resolution must name the same verbs.'
		);
	}

	/**
	 * The window is the held queue's, and an unread queue counts nothing.
	 *
	 * A rolling window would drift out of step with `N left` — which counts
	 * the list taken at that instant and held still — and start reporting a
	 * different sitting's work beside it. The two must reset together, which
	 * is what `Read the queue again` does.
	 */
	public function test_the_resolved_count_is_measured_from_the_queue_it_sits_beside(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'$ffc_identity_resolved  = $this->resolved_since( $ffc_identity_taken_at );',
			$page,
			'The count must be measured from when the queue was taken, never from a clock.'
		);
		$this->assertStringContainsString(
			'if ( $taken_at <= 0 ) {',
			$page,
			'A queue that was never read has no window, so it counts nothing.'
		);
		$this->assertStringContainsString(
			"'user_id'   => \$operator,",
			$page,
			"The held list is this operator's, so another operator's work explains nothing about it."
		);
		$this->assertStringContainsString(
			'$ffc_identity_resolved > 0',
			$view,
			'An operator who has resolved nothing yet does not need to be told so.'
		);
	}
}
