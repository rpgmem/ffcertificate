<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityQueuePanels;
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
		// The merge form reads this for the pair it draws. Stubbed on the
		// harness rather than per test, because a full Mockery double throws
		// on an unstubbed call and the cases here drive the view end to end.
		$query->shouldReceive( 'account_facts' )->andReturn( array() )->byDefault();
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
	 * Just the mailbox tier's branch of the verb column.
	 *
	 * Bounded like `account_tier_block()` and for the same reason: the branch
	 * that follows it is the generic one, and a substring running past it
	 * would let the assertions below pass on somebody else's markup.
	 *
	 * SEARCHED FROM THE VERB COLUMN, NOT FROM THE FILE. The card's sentence
	 * answers the same tier a hundred lines above, so an unanchored search
	 * finds that one -- and it contains no control either, which is how the
	 * first version of this helper passed while asserting nothing about the
	 * column it is named after.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function mailbox_verb_branch( string $view ): string {
		$column = strpos( $view, 'class="ffc-identity-card-act"' );

		$this->assertIsInt( $column, 'The card must keep its verb column.' );

		$from = strpos( $view, 'elseif ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>', $column );

		$this->assertIsInt( $from, 'The verb column must answer the mailbox tier explicitly.' );

		$to = strpos( $view, '<?php else : ?>', $from );

		$this->assertIsInt( $to, 'The mailbox branch must be followed by the generic one.' );

		return substr( $view, $from, $to - $from );
	}

	/**
	 * THE 38 FINDINGS THAT ARE OFFERED NOTHING, AND THE REASON (#1368).
	 *
	 * An account whose numbers share one address and are not variants of each
	 * other is a shared mailbox or an account submitting for other people.
	 * Both verbs the account tiers offer are wrong there: consolidating
	 * rewrites a number that may be another person's, splitting detaches
	 * records from an account that may legitimately hold them. So the tier is
	 * rendered, and its verb column carries no control at all.
	 *
	 * Asserted over the bounded branch rather than the file, because the
	 * neighbouring branches are full of exactly the markup this one must not
	 * have.
	 */
	public function test_the_shared_mailbox_tier_is_offered_no_verb(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// THE LOOP'S OWN LIST, not merely the tier appearing somewhere in the
		// block: the card's sentence names it too, so an assertion over the
		// block passes with the tier dropped from the list and never drawn.
		$this->assertStringContainsString(
			'$ffc_identity_account_tiers = array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX );',
			$view,
			'The mailbox tier must be drawn as a card beside the other two account tiers.'
		);

		$branch = $this->mailbox_verb_branch( $view );

		foreach ( array( '<form', 'wp_nonce_field', '<button', '<input' ) as $control ) {
			$this->assertStringNotContainsString(
				$control,
				$branch,
				sprintf( 'The mailbox tier must offer no %s: no write on this screen is correct for it.', $control )
			);
		}

		// An empty column is indistinguishable from a capability the operator
		// lacks. The branch says which question the screen cannot answer.
		$this->assertStringContainsString(
			'decide with HR',
			$branch,
			'The column must say why it is empty, not merely be empty.'
		);
	}

	/**
	 * The paragraph explaining the three verbs does not sit under the panel
	 * that offers none.
	 */
	public function test_the_verb_footer_skips_the_panel_without_verbs(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'IdentityQueue::TIER_MAILBOX !== $ffc_identity_this_tier',
			$view,
			'Explaining consolidate, move and split under a panel that offers none describes buttons that are not there.'
		);
	}

	/**
	 * The tier reaches the screen at all: absent from the panel order it
	 * would be classified and then never rendered, which is a queue an
	 * operator cannot work to zero.
	 */
	public function test_the_mailbox_tier_is_in_the_panel_order(): void {
		$this->assertContains(
			IdentityQueue::TIER_MAILBOX,
			IdentityQueuePanels::ORDER,
			'A tier absent from the order is not shown at all.'
		);
		$this->assertSame(
			IdentityQueue::TIER_MAILBOX,
			IdentityQueuePanels::ORDER[ count( IdentityQueuePanels::ORDER ) - 1 ],
			'The order is by effort, and the tier offering no verb costs the most.'
		);
	}

	/**
	 * THE EVIDENCE SITS WHERE THE CHOICE IS MADE (#1368).
	 *
	 * The issue asks for `account_activity` beside the per-store record
	 * counts on the merge form. Both already existed — the audit measures
	 * them and the CSV exports them — and neither reached this form: #1403
	 * put the counts in the PREVIEW, which is one click after the operator
	 * has already picked a survivor at the radio.
	 */
	public function test_the_merge_choice_states_what_each_login_holds_and_when_it_was_used(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'$ffc_identity_pair_facts = $ffc_identity_facts( $ffc_identity_who );',
			$view,
			'The form must read the facts for the pair it draws.'
		);
		$this->assertStringContainsString( 'ffc-identity-pair-evidence', $view, 'The evidence must be drawn per login.' );
		$this->assertStringContainsString( "\$ffc_identity_fact['rows']", $view, 'The per-store counts must be read.' );
		$this->assertStringContainsString( "\$ffc_identity_fact['activity']", $view, 'The last-activity date must be read.' );

		// The counts must be inside the fieldset the radios live in, not
		// after it: an operator who has to scroll past the choice to find the
		// evidence is being shown it too late, which is the defect.
		$choice = strpos( $view, 'class="ffc-identity-pair-choice"' );
		$closed = strpos( $view, '</fieldset>', (int) $choice );
		$shown  = strpos( $view, 'ffc-identity-pair-evidence' );

		$this->assertIsInt( $choice, 'The merge form must keep its choice fieldset.' );
		$this->assertIsInt( $closed, 'The choice fieldset must close.' );
		$this->assertGreaterThan( (int) $choice, (int) $shown, 'The evidence belongs inside the choice, not before it.' );
		$this->assertLessThan( (int) $closed, (int) $shown, 'The evidence belongs inside the choice, not after it.' );
	}

	/**
	 * A WALL-CLOCK DATE IS NOT AN INSTANT, AND THE WRONG HELPER PRINTS
	 * YESTERDAY.
	 *
	 * `activity_per_account()` returns a site-local `Y-m-d` — it has to,
	 * since the four stores disagree about how a moment is stored. Passing
	 * that to `format_date()` parses it at UTC midnight and re-applies the
	 * site zone, so every date west of UTC renders one day early. The
	 * repository already has the Category B helper for exactly this.
	 */
	public function test_the_activity_date_is_rendered_as_wall_clock(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'DateFormatter::format_wallclock_date(',
			$view,
			'The stored activity date carries no timezone semantics.'
		);

		// SCOPED TO THIS READ, NOT TO THE FILE.
		//
		// `format_date()` is not wrong here in general -- it is the right
		// call for an instant, and this view already renders one three
		// hundred lines above (`format_datetime( $ffc_identity_taken_at )`).
		// Both are `DateFormatter`; the convention has two categories and a
		// method for each. What must not happen is THIS value taking the
		// Category A path, so the refusal is bounded to the statement rather
		// than banning an API from the file and refusing a correct call
		// somebody makes later.
		$from = strpos( $view, "\$ffc_identity_seen = " );

		$this->assertIsInt( $from, 'The activity date must still be resolved into its own variable.' );

		$this->assertStringNotContainsString(
			'DateFormatter::format_date(',
			substr( $view, (int) $from, 400 ),
			'format_date() would re-apply the site timezone to a value already rendered in it.'
		);
	}

	/**
	 * The screen reports evidence and proposes no survivor.
	 *
	 * A deliberate decision carried in #1368 rather than an omission: the
	 * data can say which login holds more records and when each was last
	 * used, and cannot say which is the person's real one. A screen that
	 * pre-selected one would be asserting what it does not know — so the
	 * radio ships with nothing checked and the sentence says whose choice it
	 * is.
	 */
	public function test_the_merge_form_proposes_no_survivor(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$from = strpos( $view, 'class="ffc-identity-pair-choice"' );
		$to   = strpos( $view, '</fieldset>', (int) $from );

		$this->assertIsInt( $from, 'The merge form must keep its choice fieldset.' );

		$choice = substr( $view, (int) $from, (int) $to - (int) $from );

		$this->assertStringNotContainsString( 'checked', $choice, 'No login may be pre-selected.' );
		$this->assertStringContainsString( 'the choice is yours', $choice, 'The form must say whose the decision is.' );
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
	 * No store name reaches the screen as the query wrote it.
	 *
	 * THIS SHIPPED, AND THE TESTES HOST IS WHERE IT WAS SEEN.
	 *
	 * A card read `self_scheduling_appointments|user_profiles`: a machine name
	 * joined by `IdentityConflictQuery::RELATED_SEPARATOR`, a `|` that exists
	 * to survive a `GROUP_CONCAT` and was never meant to be read. Nothing
	 * caught it because every guard on this screen measures markup, colour or
	 * escaping, and this was none of those — it was correct, escaped,
	 * anchored markup carrying an internal value.
	 *
	 * The two queries do not even agree on the shape: the conflict query
	 * strips the prefix before handing a store out, the orphan query keys by
	 * the full table. So the assertion is that every render goes through the
	 * one closure that normalises both, and that none reads the raw column.
	 */
	public function test_every_store_name_is_rendered_through_the_map(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( '$ffc_identity_store_name = static function', $view, 'The screen needs one place that names a store.' );
		$this->assertStringContainsString( '$ffc_identity_store_list = static function', $view, 'And one place that joins a list of them.' );

		// The list joiner is WordPress's own, so the comma and the "and" come
		// from core's translations rather than from a separator invented here.
		$this->assertStringContainsString( 'wp_sprintf_l(', $view, "The list must be joined the way WordPress joins lists." );

		// Four render sites: the account cards, the isolated card's sentence,
		// and the per-store row ids on the isolated and orphan cards.
		$this->assertSame(
			2,
			substr_count( $view, '$ffc_identity_store_list(' ),
			'Both sentences that name several stores must go through the joiner.'
		);
		$this->assertSame(
			2,
			substr_count( $view, 'esc_html( $ffc_identity_store_name(' ),
			'Both row-id lists must name their store through the map.'
		);

		// And the defect itself: the raw column, printed.
		$this->assertStringNotContainsString(
			'esc_html( (string) ( $ffc_identity_item[ IdentityConflictQuery::COLUMN_STORES ]',
			$view,
			'A store list printed as the query joined it carries the separator into the sentence.'
		);
	}

	/**
	 * Every count on the scan strip picks its own plural form.
	 *
	 * ALSO SHIPPED, AND VISIBLE ON THE TESTES HOST AS `1 valores`.
	 *
	 * The strip's three numbers were one string with three placeholders,
	 * which can only ever carry one plural form — so an install with a single
	 * stored value read `1 valores distintos verificados` in Portuguese, and
	 * would be wrong in every language that inflects. `_n()` chooses per
	 * NUMBER, so three numbers need three calls; what sits between them is
	 * punctuation rather than prose, so it is not a fourth string.
	 */
	public function test_the_scan_counts_are_each_plural_aware(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		foreach (
			array(
				"_n( '%s distinct value checked', '%s distinct values checked'",
				"_n( '%s could not be read', '%s could not be read'",
				"_n( '%s store scanned', '%s stores scanned'",
			) as $call
		) {
			$this->assertStringContainsString( $call, $view, sprintf( 'The strip must inflect: %s', $call ) );
		}

		$this->assertStringNotContainsString(
			'%1$s distinct values checked',
			$view,
			'One string carrying all three counts can only ever have one plural form.'
		);
	}

	/**
	 * The decision tier's action column says which identifier each verb is for.
	 *
	 * An account holding two numbers renders MOVE and SPLIT once per number —
	 * eight controls, whose only clue to ownership was the hash inside one
	 * button's label. Each identifier now opens a group that names it, and
	 * the sentence explaining the two verbs travels with them instead of
	 * sitting in a paragraph at the foot of the panel.
	 */
	public function test_each_identifier_owns_its_own_verbs(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( 'class="ffc-identity-card-verb"', $view, 'Each identifier opens its own group.' );
		$this->assertStringContainsString( 'ffc-identity-card-verb-head', $view, 'And the group names the identifier it acts on.' );
		$this->assertStringContainsString( 'ffc-identity-card-verb-note', $view, 'And says what the two verbs do, beside them.' );
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

	/**
	 * Just one tier's branch of the action column.
	 *
	 * Bounded on both sides, because the three branches sit in one `if` and
	 * every one of them prints buttons: a search over the whole view is
	 * satisfied by a neighbour's markup and stops proving anything about the
	 * branch it was written for. That is not hypothetical on this file — four
	 * assertions in this suite have already been caught passing on somebody
	 * else's occurrence.
	 *
	 * @param string $view The view's source.
	 * @param string $tier The tier constant's name, unqualified.
	 * @return string
	 */
	private function tier_branch( string $view, string $tier ): string {
		$marks = array(
			'TIER_MECHANICAL' => 'IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier ) : ?>',
			'TIER_DECISION'   => 'elseif ( IdentityQueue::TIER_DECISION === $ffc_identity_tier ) : ?>',
			'TIER_MAILBOX'    => 'elseif ( IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>',
		);

		$from = strpos( $view, $marks[ $tier ] );
		$this->assertIsInt( $from, 'The action column must still branch on ' . $tier . '.' );

		$next = 'TIER_MECHANICAL' === $tier ? $marks['TIER_DECISION'] : $marks['TIER_MAILBOX'];
		$to   = strpos( $view, $next, (int) $from + 1 );
		$this->assertIsInt( $to, 'That branch must end where the next one opens.' );

		return substr( $view, (int) $from, (int) $to - (int) $from );
	}

	/**
	 * ONE PRIMARY WHERE THERE IS ONE VERB, AND NONE WHERE THERE ARE TWO (#1421).
	 *
	 * Every button on this screen was secondary, so nothing said which
	 * control commits — on a card whose verbs rewrite somebody's records and
	 * cannot be undone, the one that writes and the one that only asks read
	 * alike. The mechanical tier has a single verb and takes the promotion.
	 *
	 * The decision tier deliberately does NOT: its two paths are a
	 * destination that exists and a destination that does not, and the card's
	 * own sentence asks the operator to choose between them. Promoting either
	 * would be the screen answering the question it is asking, which is why
	 * the mockup leaves both alike.
	 */
	public function test_the_card_with_one_verb_promotes_it_and_the_card_with_two_does_not(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'class="button button-primary"',
			$this->tier_branch( $view, 'TIER_MECHANICAL' ),
			'The tier with one verb must say which control commits.'
		);
		$this->assertStringNotContainsString(
			'button-primary',
			$this->tier_branch( $view, 'TIER_DECISION' ),
			'Two destinations, neither promoted: the operator chooses, not the screen.'
		);
	}

	/**
	 * THE HASH IS IN THE NAME, NOT IN THE LABEL (#1421).
	 *
	 * It was in the label because nothing else said which identifier a verb
	 * acted on. #1407 then gave each identifier its own group with the hash
	 * in the heading above the buttons, which made the label a second place
	 * the same twelve characters were written.
	 *
	 * The layout gain is real and smaller than it looks, and it was measured
	 * rather than assumed: every hash is truncated to the same length, so the
	 * two groups' buttons were never different widths. What the shorter label
	 * removes is one wrap row, between roughly 1100 and 1280 CSS pixels — the
	 * card is identical above that band and identical below it.
	 *
	 * What it must not lose is the distinction: two bare "Move" buttons are
	 * one announcement to a screen reader, and a heading two elements away is
	 * part of neither name. So the assertion is two-sided — out of the label,
	 * and still inside the button.
	 */
	public function test_the_move_button_states_its_identifier_without_printing_it(): void {
		$view   = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );
		$branch = $this->tier_branch( $view, 'TIER_DECISION' );

		$from   = strpos( $branch, 'id="ffc-relink-go-' );
		$this->assertIsInt( $from, 'The move verb must still have its own submit.' );
		$to     = strpos( $branch, '</button>', (int) $from );
		$this->assertIsInt( $to, 'That submit must close.' );
		$button = substr( $branch, (int) $from, (int) $to - (int) $from );

		$this->assertStringNotContainsString(
			"'Move %s'",
			$branch,
			'The visible label states the verb; the heading above it states the identifier.'
		);
		$this->assertStringContainsString(
			'screen-reader-text',
			$button,
			'And the hash stays in the accessible name, or the two buttons are announced alike.'
		);
		$this->assertStringContainsString(
			'IdentityQueue::DISPLAY_PREFIX',
			$button,
			'Truncated the way every other hash on this screen is — a stored identifier is never rendered whole.'
		);
	}

	/**
	 * The search affordance is a glyph the screen reader does not read.
	 *
	 * A control that opens a dialog and one that submits a form are the same
	 * grey rectangle otherwise. The glyph is decoration on top of a label
	 * that already says the word, so announcing it would add a second name
	 * for one control.
	 */
	public function test_the_search_buttons_carry_a_glyph_that_is_not_announced(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertSame(
			2,
			substr_count( $view, 'dashicons dashicons-search" aria-hidden="true"' ),
			'Both search buttons — the decision tier\'s and the orphan tier\'s — carry the glyph, and neither announces it.'
		);
		$this->assertSame(
			substr_count( $view, "esc_html_e( 'Search…', 'ffcertificate' )" ),
			substr_count( $view, 'dashicons dashicons-search' ),
			'A glyph without its word is an icon-only control, which this screen does not use.'
		);
	}

	/**
	 * THE WORD LEAVES THE STEPPER AND SURVIVES IN THREE PLACES (#1421).
	 *
	 * The two steppers sit between the position ("3 of 14") and the link to
	 * the list, where the direction is the whole message — so a glyph says it
	 * and two words said it twice. Dropping to an icon is only safe while
	 * every non-visual route to the word still has it, and each of the three
	 * covers a case the others do not: `aria-label` is the accessible NAME,
	 * so a screen reader is unaffected; `title` draws the tooltip the admin
	 * sheets style, which is what a mouse gets; and that family's
	 * `:focus-visible` half — added in the same PR, because it did not exist —
	 * is what a keyboard gets.
	 *
	 * Asserted together for that reason: any one of them alone leaves a real
	 * operator with an unlabelled arrow, and the one most easily lost is the
	 * CSS half, which lives in another file and no PHP test would miss.
	 */
	public function test_the_steppers_are_icons_whose_label_survives_where_the_icon_cannot_be_read(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$from = strpos( $view, "foreach ( array( 'previous', 'next' ) as \$ffc_identity_step )" );
		$this->assertIsInt( $from, 'The two steppers must still be drawn from one branch.' );
		$to = strpos( $view, 'ffc-identity-panel-toggle', (int) $from );
		$this->assertIsInt( $to, 'And end before the link to the list.' );
		$steppers = substr( $view, (int) $from, (int) $to - (int) $from );

		$this->assertSame(
			2,
			substr_count( $steppers, 'aria-label="<?php echo esc_attr( $ffc_identity_step_label ); ?>"' ),
			'Both the live stepper and the disabled one carry the accessible name.'
		);
		$this->assertSame(
			2,
			substr_count( $steppers, 'title="<?php echo esc_attr( $ffc_identity_step_label ); ?>"' ),
			'And both carry the tooltip, which is the only route a pointer has.'
		);
		$this->assertSame(
			2,
			substr_count( $steppers, 'aria-hidden="true"' ),
			'The glyph itself is never announced — the label is the name.'
		);
		$this->assertStringNotContainsString(
			"esc_html_e( 'Previous'",
			$steppers,
			'The word is the label and the tooltip, not the visible text.'
		);

		// THE CSS HALF, ASSERTED HERE BECAUSE NOTHING ELSE DOES. The tooltip
		// this markup now relies on was hover-only, so a keyboard reached an
		// arrow with no visible label at all.
		$sheet = (string) file_get_contents( __DIR__ . '/../../assets/css/ffc-admin-submissions.css' );

		// THE RULE, NOT THE SELECTOR ANYWHERE IN THE SHEET. The phone
		// `@media` block below carries the same selector in order to HIDE the
		// tooltip, so an unanchored search is satisfied by the rule that
		// switches the thing off -- which is how the first version of this
		// assertion survived the mutation it exists for.
		$this->assertStringContainsString(
			".ffc-admin-page .button[title]:hover::after,\n.ffc-admin-page .button[title]:focus-visible::after {",
			$sheet,
			'A tooltip only a pointer can reach is a label a keyboard user does not have.'
		);
	}
}
