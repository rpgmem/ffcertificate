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
use FreeFormCertificate\Maintenance\IdentityAcceptance;
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
		$query->shouldReceive( 'check_digit_failures_of_both' )
			->once()
			->with( IdentityResolutionPage::LIMIT )
			->andReturn( $findings );
		// The merge form reads this for the pair it draws. Stubbed on the
		// harness rather than per test, because a full Mockery double throws
		// on an unstubbed call and the cases here drive the view end to end.
		$query->shouldReceive( 'account_facts' )->andReturn( array() )->byDefault();
		$query->shouldReceive( 'scan_coverage' )->andReturn(
			array(
				'rf_hash' => array(
					'stores'     => 3,
					'examined'   => count( $findings ),
					'unreadable' => 0,
				),
			)
		);

		// The record is a double rather than a `get_option` stub, for the reason
		// `IdentityQueueTest` states: the function stays taught for the rest of
		// the process, and these tests are about the screen, not an option.
		$accepted = Mockery::mock( IdentityAcceptance::class );
		$accepted->shouldReceive( 'all' )->andReturn( array() )->byDefault();

		return new class( $query, $accepted ) extends IdentityResolutionPage {

			/** @var IdentityConflictQuery */
			private $double;

			/** @var IdentityAcceptance */
			private $accepted_double;

			/**
			 * @param IdentityConflictQuery $double   Stand-in for the real query.
			 * @param IdentityAcceptance    $accepted Stand-in for the record.
			 */
			public function __construct( $double, $accepted ) {
				$this->double          = $double;
				$this->accepted_double = $accepted;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}

			/**
			 * @return IdentityAcceptance
			 */
			protected function acceptances(): IdentityAcceptance {
				return $this->accepted_double;
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
	 * An administrator reaches the screen without holding the granular cap.
	 *
	 * This is the half the refusal test above cannot see: it asserts that a
	 * visitor with neither the cap nor `manage_options` is turned away, which
	 * was true before this gate was fixed and is true after, so it passes
	 * either way and proves nothing about the change.
	 *
	 * What changed is that `render_page()` was the ONE gate of twelve in that
	 * file using bare `current_user_can()`. FFC admin caps are no longer
	 * granted to the native `administrator` role -- they arrive through
	 * `ffc_administrator` (see `Loader::ensure_admin_capabilities()`) -- so an
	 * administrator without that role hit a `wp_die` here with no fallback,
	 * on the one screen whose every other gate would have admitted them.
	 *
	 * The assertion is that the gate is PASSED, not that the page renders:
	 * `get_transient` is the first thing after it, so a sentinel thrown there
	 * says the gate let the request through. Reverting the fix makes this
	 * `wp_die` instead, which is how it was verified.
	 */
	public function test_an_administrator_without_the_granular_capability_reaches_the_screen(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'manage_options' === $cap;
			}
		);

		Functions\expect( 'wp_die' )->never();

		Functions\when( 'get_transient' )->alias(
			static function () {
				throw new \RuntimeException( 'past the gate' );
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'past the gate' );

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
		$query->shouldReceive( 'check_digit_failures_of_both' )->once()->andReturn( array() );
		$query->shouldReceive( 'scan_coverage' )->once()->andReturn(
			array(
				'rf_hash' => array(
					'stores'     => 3,
					'examined'   => 40,
					'unreadable' => 40,
				),
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

			/**
			 * An empty record, so this case stays about what the SCAN read.
			 *
			 * @return IdentityAcceptance
			 */
			protected function acceptances(): IdentityAcceptance {
				return new class() extends IdentityAcceptance {

					/**
					 * @return array<string, array<string, mixed>>
					 */
					public function all(): array {
						return array();
					}
				};
			}
		};

		$this->assertSame( array(), $page->queue() );
		$this->assertSame(
			array(
				'rf_hash' => array(
					'stores'     => 3,
					'examined'   => 40,
					'unreadable' => 40,
				),
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
		// THESE MOVED TO THE CROSS-COLUMN TOTAL (#1500). The per-column names
		// still exist inside the coverage strip's loop, so probing them here
		// would pass on the strip while the empty-queue branches read a
		// leftover -- which is the defect `test_the_empty_queue_notices_read_every_column`
		// exists for.
		$this->assertStringContainsString( "\$ffc_identity_all['examined'] === \$ffc_identity_all['unreadable']", $view, 'Nothing readable is its own state.' );
		$this->assertStringContainsString( "0 === \$ffc_identity_all['examined']", $view, 'Nothing FOUND is its own state, distinct from nothing readable.' );
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
		$none_found = strpos( $view, "'No stored RF or CPF was found at all" );

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

		// The read moved inside a per-column loop (#1486), so what is probed is
		// the loop that opens it -- the ordering this test exists for is
		// unchanged, and the assertion has to name what the view now says.
		$read  = strpos( $view, 'foreach ( $ffc_identity_coverage as $ffc_identity_column => $ffc_identity_read ) :' );
		$strip = strpos( $view, 'class="ffc-identity-scan ' );
		$empty = strpos( $view, 'array() === $ffc_identity_panels' );

		$this->assertIsInt( $read, 'The view must read what the scan covered.' );
		$this->assertIsInt( $strip, 'The view must draw the coverage strip.' );
		$this->assertIsInt( $empty, 'The empty-queue branch must still exist.' );

		$this->assertLessThan( $empty, $read, 'The coverage must be read outside the empty-queue branch, not inside it.' );
		$this->assertLessThan( $empty, $strip, 'The coverage strip must render whether or not the queue is empty.' );
	}

	/**
	 * THE COUNTER BESIDE A CAPPED CATEGORY SAYS THE NUMBER IS A FLOOR (#1466).
	 *
	 * The cap was already detected, held and reported -- but only in the
	 * page-level banner, which speaks about the SCAN, once, while the number
	 * is per category and further down. An operator who scrolled past the
	 * banner read `1 of 100` as one of a hundred.
	 *
	 * Asserted on the WIRING rather than on the wording, because the wording
	 * is a translated string and the defect was never in it: the panels derive
	 * `capped` from their items' own check, and what has to keep being true is
	 * that the page hands them the capped list and the counter reads the
	 * result. Whether the derivation is right is `IdentityQueuePanelsTest`'s,
	 * which proves it over the three tiers one check feeds.
	 */
	public function test_the_counter_says_when_its_total_is_only_a_floor(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString(
			'$ffc_identity_capped',
			substr( $page, (int) strpos( $page, 'IdentityQueuePanels::build(' ) ),
			'The capped checks must reach the panels, or no panel can know whether its own count is a total.'
		);

		$this->assertStringContainsString(
			"\$capped = ! empty( \$panel['capped'] );",
			$view,
			'The header must read the flag the panel carries rather than decide for itself.'
		);

		// BOTH BRANCHES, in both counters: a screen that only ever hedges
		// tells the operator as little as one that never does, so the
		// unqualified form has to survive the change that added the other.
		foreach (
			array(
				'%1$s of at least %2$s',
				'%1$s of %2$s',
				'at least %s findings',
				'%s findings',
			) as $form
		) {
			$this->assertStringContainsString( $form, $view, sprintf( 'The counter must still be able to say: %s', $form ) );
		}
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
		$this->assertStringContainsString( '|| $ffc_identity_scan_cap', $view, "The scan's own cap must make the reading partial." );
		$this->assertStringContainsString( '|| array() !== $ffc_identity_capped', $view, 'A check that returned a full page must make the reading partial.' );

		// THE CAP IS READ PER COLUMN (#1486), which is what this probe pins: two
		// scans run and each stops at its own, so one flag for both would report
		// a truncated RF scan on the CPF strip and the other way round.
		$this->assertStringContainsString(
			'$ffc_identity_scan_cap   = ! empty( $ffc_identity_truncated_columns[ (string) $ffc_identity_column ] );',
			$view,
			"Each strip must read its own column's cap, not any column's."
		);

		// The whole-reading verdict is the `else` of both, so it cannot be
		// reached while either flag is set. Anchored on the branch rather than
		// on the sentence, because the sentence is translatable and the
		// ordering is what carries the guarantee.
		$none  = strpos( $view, 'if ( $ffc_identity_read_none ) {' );
		$part  = strpos( $view, '} elseif ( $ffc_identity_read_part ) {' );
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
	 * THE TIER IS DRAWN, AND WHAT IT OFFERS MOVED (#1368 → #1461).
	 *
	 * It once offered nothing, and this case froze that. #1368 had withheld
	 * all three verbs for a harm it named precisely -- verbs that would write
	 * one person's number onto another person's records -- which describes
	 * CONSOLIDATE and neither of the other two: a move relocates records
	 * without touching a number, and a split creates an account nobody else
	 * uses. So the sweep removed three verbs for a reason that justified one,
	 * and the screen was left telling an operator to decide with HR with
	 * nowhere to put the answer.
	 *
	 * What survives here is the half that is still true and is this file's to
	 * hold: the tier reaches the screen as a card beside the other two. Which
	 * verbs it may offer is `IdentityMailboxVerbsTest`, so the rule and the
	 * rendering are not asserted twice and cannot drift apart.
	 */
	public function test_the_shared_mailbox_tier_is_drawn_beside_the_other_account_tiers(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// THE LOOP'S OWN LIST, not merely the tier appearing somewhere in the
		// block: the card's sentence names it too, so an assertion over the
		// block passes with the tier dropped from the list and never drawn.
		$this->assertStringContainsString(
			'$ffc_identity_account_tiers = array( IdentityQueue::TIER_MECHANICAL, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX );',
			$view,
			'The mailbox tier must be drawn as a card beside the other two account tiers.'
		);
	}

	/**
	 * The verb footer reaches the shared-mailbox panel, and says what differs.
	 *
	 * It used to be suppressed there, because explaining three verbs under a
	 * panel offering none describes buttons that are not on the screen. That
	 * panel now offers two of the three, so suppressing it would hide the
	 * explanation of the buttons it does have -- and the two things a reader
	 * needs are exactly what the shared paragraph cannot say: which verb is
	 * missing and why, and which of the two wins when both are supplied.
	 *
	 * #1464 split those two apart. The absence stayed here, because a control
	 * that is not on the screen cannot explain why: only a paragraph can. The
	 * precedence moved to the move's own barring notice, which says it at the
	 * moment an address is typed -- so this test now pins the fact AND the
	 * site, rather than the paragraph that used to hold both.
	 */
	public function test_the_verb_footer_reaches_the_mailbox_panel_with_its_own_sentence(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringNotContainsString(
			'IdentityQueue::TIER_MAILBOX !== $ffc_identity_this_tier',
			$view,
			'The verb footer is suppressed on the panel again, which now hides the explanation of the two verbs it offers.'
		);

		$this->assertStringContainsString(
			'IdentityQueue::TIER_MAILBOX === $ffc_identity_this_tier',
			$view,
			'The panel has no sentence of its own, so nothing says which verb is withheld there or which one takes precedence.'
		);

		$this->assertStringContainsString(
			'Consolidating is not offered on this panel',
			$view,
			'The sentence must name the verb that is absent: an operator comparing panels otherwise reads it as an oversight.'
		);

		$this->assertStringContainsString(
			'so splitting takes precedence',
			$view,
			'Nothing states the precedence, which is the one rule an operator cannot infer from the two buttons.'
		);

		// AND IT IS STATED WHERE IT APPLIES, WHICH IS NOT THIS PARAGRAPH (#1464).
		//
		// The panel's sentence carried the precedence until the barring did:
		// the move's own notice says it at the moment the address is typed,
		// beside the control it disables, so the paragraph was saying in the
		// abstract what the card now demonstrates. The FACT is still asserted
		// above -- only its site moved, and this pins the site so the two
		// cannot both drop it.
		$this->assertMatchesRegularExpression(
			'/ffc-identity-move-barred.*?so splitting takes precedence/s',
			$view,
			'The precedence left the barring notice, which is the only place an operator meets it at the moment it decides anything.'
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
	 * button's label. Each identifier now opens a group that names it.
	 *
	 * What travels with the group changed in #1464: it was one sentence
	 * describing both verbs, repeated per identifier, and it is now a label
	 * per route, on the control that takes it. The origin is one and the
	 * destination is one -- so the card offers two ROUTES to a single
	 * destination, which is what naming each of them says and a sentence
	 * about both did not.
	 */
	public function test_each_identifier_owns_its_own_verbs(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( 'class="ffc-identity-card-verb"', $view, 'Each identifier opens its own group.' );
		$this->assertStringContainsString( 'ffc-identity-card-verb-head', $view, 'And the group names the identifier it acts on.' );
		$this->assertStringContainsString( 'ffc-identity-card-route', $view, 'And each route to the destination is named on the control that takes it.' );
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

			// AN ACTION NAMED BY A CONSTANT IS STILL AN ACTION, AND THE SCAN
			// COULD NOT SEE ONE (#1532).
			//
			// The rule above assumes the name is a literal AT the call site.
			// `IdentityAcceptance` logs two actions through one private helper
			// and passes the name in, so both were invisible here — and so
			// would a RESOLVING verb written the same way be, which is a hole
			// in the guard rather than a quirk of that class.
			//
			// `LOG_*` AND NOT ANY `identity_*` CONSTANT, because the first
			// attempt at this read `ALIAS_IDENTITY_COUNT = 'identity_count'` --
			// a SQL column alias -- as a sixth verb. So the convention is the
			// narrow one: an action named by a constant declares it under a
			// `LOG_` prefix, and the assertion below is what stops a file from
			// dispatching a logged action through a constant that hides from
			// this net.
			if ( preg_match_all( "/const\s+LOG_\w+\s*=\s*'(identity_\w+)'/", $source, $declared_names ) ) {
				$logged = array_merge( $logged, $declared_names[1] );
			}

			// A FILE THAT PASSES ITS ACTION IN MUST DECLARE IT WHERE THE SCAN
			// LOOKS. That is the residual half of the same hole: centralising
			// the call is fine, naming the action somewhere this cannot read is
			// not.
			if ( preg_match( '/ActivityLog::log\(\s*\$/', $source ) ) {
				$this->assertMatchesRegularExpression(
					"/const\s+LOG_\w+\s*=\s*'identity_\w+'/",
					$source,
					basename( (string) $file ) . ' dispatches a logged action through a variable, so it must declare the name as a LOG_* constant or this scan cannot see it.'
				);
			}
		}

		sort( $logged );
		$logged = array_values( array_unique( $logged ) );

		$this->assertNotEmpty( $logged, 'The scan found no identity verb at all, so it proves nothing.' );

		// ONE SET IS SUBTRACTED, AND THAT IS THE DECISION RATHER THAN A HOLE
		// (#1532).
		//
		// This test's whole value is that a sixth verb cannot be added without
		// the counter learning about it. Accepting a finding as unresolvable is
		// the first logged `identity_*` action whose answer is "counted by
		// nothing, on purpose": it is not a resolution, the stored value is
		// unchanged, and the refusals over it still refuse. Growing
		// `RESOLVED_ACTIONS` would have made the screen report it as work done.
		//
		// So the exemption is DECLARED beside the code that logs it, and it is
		// checked rather than trusted: every name in it must still be found by
		// the scan, so an entry left behind after its service stopped logging
		// fails here instead of silently excusing nothing.
		$exempt = IdentityAcceptance::NOT_RESOLUTIONS;
		sort( $exempt );

		$this->assertNotEmpty( $exempt, 'An empty exemption list means the subtraction below proves nothing.' );
		$this->assertSame(
			$exempt,
			array_values( array_intersect( $logged, $exempt ) ),
			'Every exempted action must still be logged by a service; one that is gone has to leave this list.'
		);

		$counted = array_values( array_diff( $logged, $exempt ) );

		$declared = IdentityResolutionPage::RESOLVED_ACTIONS;
		sort( $declared );

		$this->assertSame(
			$counted,
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

		// WHITESPACE-INSENSITIVE ON PURPOSE (#1500). This pinned the exact run
		// of spaces around the `=`, and adding a longer variable name to that
		// block made `phpcbf` realign it -- so an unrelated change turned this
		// red for a reason that has nothing to do with what it asserts. A
		// formatter is allowed to move whitespace; a test must not read it.
		$this->assertMatchesRegularExpression(
			'/\$ffc_identity_resolved\s*=\s*\$this->resolved_since\( \$ffc_identity_taken_at \);/',
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
		// TWO BRANCHES, NOT THREE, SINCE #1461.
		//
		// The decision tier and the shared-mailbox tier render the same two
		// verbs, so they share one branch and `SHARED` names it. The mailbox
		// tier no longer has one of its own -- which is the change, so a helper
		// still looking for it would fail here rather than in the rule, and
		// that is where a reader would stop looking.
		$marks = array(
			'TIER_MECHANICAL' => 'IdentityQueue::TIER_MECHANICAL === $ffc_identity_tier ) : ?>',
			'SHARED'          => 'elseif ( IdentityQueue::TIER_DECISION === $ffc_identity_tier || IdentityQueue::TIER_MAILBOX === $ffc_identity_tier ) : ?>',
		);

		$from = strpos( $view, $marks[ $tier ] );
		$this->assertIsInt( $from, 'The action column must still branch on ' . $tier . '.' );

		// The shared branch ends at the generic one, named by its own sentence
		// rather than by the bare `else` tag -- which occurs earlier in the
		// file and would cut the branch to nothing. (Naming that tag in a `//`
		// comment is also how this helper stopped parsing once: a `//` comment
		// ENDS at a close-tag, which `CLAUDE.md` records and this line now
		// demonstrates.)
		$next = 'TIER_MECHANICAL' === $tier
			? $marks['SHARED']
			: 'Open the account — this one is not decided here.';

		$to = strpos( $view, $next, (int) $from + 1 );
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
			$this->tier_branch( $view, 'SHARED' ),
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
		$branch = $this->tier_branch( $view, 'SHARED' );

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

	/**
	 * EVERY FORM POSTING A REPAIR NAMES WHICH IDENTIFIER (#1486).
	 *
	 * Asserted PER FORM, which is the gap this closes.
	 * `test_a_handler_acting_on_an_identifier_names_which_one()` asserts that
	 * the handler passes `self::posted_field()` -- and it did, faithfully,
	 * while one of the two forms supplied nothing for it to read. So the
	 * handler-side test passed throughout and nothing ever looked at the markup.
	 *
	 * Without the field `posted_field()` falls back to `IdentityRepair::FIELD`,
	 * which is `rf`. Traced through: a CPF finding reaches
	 * `rows_for( $subject, 'rf_hash', ... )`, matches nothing, and answers
	 * `ffc_identity_repair_gone` -- "Nothing carries that value any more. It was
	 * repaired already." Not a wrong write. A FALSE SENTENCE about a finding
	 * still sitting on the screen, which is the harder kind to debug because
	 * nothing is broken anywhere the operator can see.
	 *
	 * It was unreachable only because the scan was RF-only, which is what
	 * #1486 undoes -- so the two halves had to ship together.
	 */
	public function test_every_repair_form_names_the_identifier_it_corrects(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// PER FORM, BY SPLITTING ON THE FORMS. Counting `name="ffc_field"` over
		// the whole view and comparing totals is the wrong measurement and was
		// the first version of this: SIX forms carry that field, because merge,
		// relink, split and adoption all name an identifier too, while only two
		// post a repair. Equal totals would have been a coincidence and unequal
		// ones prove nothing.
		$forms = array_filter(
			explode( '<form ', $view ),
			static function ( $chunk ) {
				return false !== strpos( $chunk, 'IdentityResolutionPage::REPAIR_ACTION' );
			}
		);

		$this->assertCount( 2, $forms, 'Two forms post a repair: the shared pair\'s and the check-digit tier\'s.' );

		foreach ( $forms as $form ) {
			$this->assertStringContainsString(
				'name="ffc_field"',
				$form,
				'A form posting a repair without naming the identifier silently corrects the RF.'
			);

			// AND READ OFF THE FINDING, never written in: a literal would be
			// right on whichever tier it was copied from and wrong on the other.
			$this->assertStringContainsString(
				"name=\"ffc_field\" value=\"<?php echo esc_attr( str_replace( '_hash', ''",
				$form,
				'The posted field must be derived from the finding\'s identifier column.'
			);
		}
	}

	/**
	 * The corrected-number box accepts a CPF, which is eleven digits (#1486).
	 *
	 * `pattern="[0-9]{7}" maxlength="7"` made an 11-digit CPF impossible to
	 * TYPE -- the browser refuses the submit, about a number the operator read
	 * correctly off the finding. The shared-pair form was already `{7,11}`, so
	 * this is the two forms agreeing rather than a new rule.
	 */
	public function test_no_repair_form_refuses_an_eleven_digit_identifier(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertSame(
			2,
			preg_match_all( '/pattern="\[0-9\]\{7,11\}" maxlength="11"/', $view ),
			'Both forms that post a corrected number must accept 7 to 11 digits.'
		);

		// The orphan-adoption form keeps its own RF-only box on purpose: it asks
		// for a CPF and an RF in two separate inputs, so each states its own
		// width. Anchored on the id so the two cases cannot be confused.
		$this->assertStringNotContainsString(
			'maxlength="7" size="8" required
								id="ffc-rf-',
			$view,
			'The isolated tier must not carry an RF-shaped box while the scan behind it reads CPF too.'
		);
	}

	/**
	 * A HELD LIST WITH NO READINGS STILL STATES THAT IT READ NOTHING (#1486).
	 *
	 * The other half of `IdentityWorklistTest`'s decision to report an empty
	 * record rather than an invented zero. An absent column draws no strip,
	 * correctly -- but a coverage record that is empty ALTOGETHER is a lost
	 * reading, not a column nobody asked about, and left to the same rule it
	 * would render silence. Silence reads as "nothing to report", which is
	 * strictly worse than the zeroes this used to show.
	 */
	public function test_a_lost_reading_renders_as_having_read_nothing(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$empty = strpos( $view, 'if ( array() === $ffc_identity_coverage ) {' );
		$loop  = strpos( $view, 'foreach ( $ffc_identity_coverage as $ffc_identity_column => $ffc_identity_read ) :' );

		$this->assertIsInt( $empty, 'An empty coverage record must be given a strip of its own.' );
		$this->assertIsInt( $loop );
		$this->assertLessThan( $loop, $empty, 'The substitution must happen before the loop, or the loop draws nothing.' );

		$this->assertStringContainsString(
			"esc_html_e( 'This scan read nothing', 'ffcertificate' );",
			$view,
			'The unnamed strip needs the verdict that does not name an identifier.'
		);
	}

	/**
	 * THE PANEL SAYS WHEN THE FORM STILL ADMITS A WRONG RF (#1500).
	 *
	 * The queue and the submission form disagree about a valid RF:
	 * `IdentityRepair::well_formed()` is `validate_rf() &&
	 * rf_check_digit_matches()`, so the `&&` always requires the digit, while
	 * the form judges by `validate_rf()` alone -- which requires it only when
	 * `ffc_validate_rf_check_digit` is on. Off by default. So an operator can
	 * work the panel to zero and watch it refill.
	 *
	 * ASSERTED ON BOTH STATES, because a notice that renders unconditionally is
	 * the same defect as a coverage strip that always reads clean: it stops
	 * being a measurement and becomes decoration. The wording is not asserted --
	 * it is translated, and what has to keep being true is that it is *gated*.
	 */
	public function test_the_panel_says_when_the_form_still_admits_a_wrong_rf(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$gate   = strpos( $view, 'if ( ! $ffc_identity_rf_gate ) {' );
		$notice = strpos( $view, 'New wrong RFs can still arrive' );

		$this->assertIsInt( $gate, 'The notice must be gated on the measured setting, never printed unconditionally.' );
		$this->assertIsInt( $notice, 'The panel must carry the sentence.' );
		$this->assertGreaterThan( $gate, $notice, 'The sentence must sit inside the gate, not beside it.' );

		// AND THE FLAG IS RESOLVED BY THE PAGE, NOT BY THE VIEW. `views/` is
		// markup by convention -- the reason it is carved out of PHPStan and of
		// the coverage scope -- so a settings read there would put logic in the
		// one directory that is excused from being checked.
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		// NOT ASSERTED WITH THE ALIGNMENT SPACES. The first version pinned the
		// exact run of spaces around the `=`, and `phpcbf` realigned the block
		// on the next run and turned the test red -- a formatter is allowed to
		// move whitespace, so an assertion that reads it is asserting the wrong
		// thing. Two fragments instead, neither of which a reformat can touch.
		$this->assertMatchesRegularExpression(
			'/\$ffc_identity_rf_gate\s*=\s*SettingsReader::get_bool\(/',
			$page,
			'The page must resolve the flag and hand it to the view.'
		);

		$this->assertStringContainsString(
			"SettingsReader::get_bool( 'validate_rf_check_digit', false )",
			$page,
			'It must read that setting, defaulting to off as the plugin does.'
		);

		$this->assertStringNotContainsString(
			'SettingsReader',
			$view,
			'The view must not read a setting itself; it is markup, and that is why it is carved out of the gates.'
		);
	}

	/**
	 * THE EMPTY-QUEUE NOTICES READ BOTH COLUMNS, NOT A LOOP LEFTOVER (#1500).
	 *
	 * Those three branches were written when coverage was one flat record, and
	 * #1499 moved the counters inside a per-column loop without moving them.
	 * After the loop they hold whichever column ran LAST, so on production --
	 * RF 3,014 values, CPF 12,705 -- an empty queue reported 12,705 as the whole
	 * reading and called it "RF".
	 *
	 * No test caught it because the fixtures supply ONE column, and with one
	 * column the leftover happens to be the right answer. That is the trap this
	 * file already records for the missing `ffc_field`: the test exercised the
	 * path that was already right.
	 */
	public function test_the_empty_queue_notices_read_every_column(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$aggregate = strpos( $view, '$ffc_identity_all = array(' );
		$loop      = strpos( $view, 'foreach ( $ffc_identity_coverage as $ffc_identity_column => $ffc_identity_read ) :' );

		$this->assertIsInt( $aggregate, 'The view must total the coverage across columns.' );
		$this->assertLessThan( $loop, $aggregate, 'The total must be taken before the per-column loop, not from its leftovers.' );

		// `stores` is a MAX and the other two are SUMS: the same tables are
		// probed per column, so adding them would claim six where there are
		// three, while "something was scanned" is true if ANY column found one.
		$this->assertStringContainsString(
			"\$ffc_identity_all['stores']      = max( \$ffc_identity_all['stores'],",
			$view,
			'Stores must not be summed across columns -- the same tables are probed for each.'
		);

		foreach ( array(
			"0 === \$ffc_identity_all['stores']",
			"\$ffc_identity_all['examined'] > 0 && \$ffc_identity_all['examined'] === \$ffc_identity_all['unreadable']",
			"0 === \$ffc_identity_all['examined']",
		) as $branch ) {
			$this->assertStringContainsString(
				$branch,
				$view,
				sprintf( 'An empty-queue branch must read the total, not one column: %s', $branch )
			);
		}

		// The per-column counters must no longer be read after the strip's loop.
		//
		// ANCHORED ON THAT LOOP'S OWN `endforeach`, never on the first one in
		// the file: without the offset this landed on an unrelated loop some
		// 1,500 lines earlier, so the "tail" it searched included the strip
		// itself and the test failed on a legitimate use. A probe has to be
		// anchored to the construct it is about.
		//
		// The closing tag is NOT written in this comment, and that is not
		// fussiness: a `//` comment ends at a PHP close tag, so quoting the
		// searched-for literal here terminated PHP mode mid-sentence and made
		// the `endforeach` below a parse error. `CLAUDE.md` records the same
		// fact for suppression scanners; it bites prose just as hard.
		$tail = substr( $view, (int) strpos( $view, '<?php endforeach; ?>', $loop ) );

		foreach ( array( '$ffc_identity_examined', '$ffc_identity_unreadable', '$ffc_identity_stores' ) as $leftover ) {
			$this->assertStringNotContainsString(
				$leftover,
				$tail,
				sprintf( 'A per-column counter is still read after the loop, which is the #1499 defect: %s', $leftover )
			);
		}
	}

	/**
	 * THE ORDER-OF-WORK ADVICE IS GATED ON BOTH HALVES EXISTING (#1498).
	 *
	 * Advice that correcting a number can dissolve a finding further down is
	 * about nothing when there is nothing further down, and nothing to correct
	 * is not a plan. So it renders only when a correction tier AND a judgement
	 * tier both hold findings.
	 *
	 * The condition reuses `IdentityQueuePanels::CORRECTIONS` / `::JUDGEMENTS`,
	 * which is what decided the panel order in #1491. A second list of tiers
	 * here would agree with a reordering that broke the dependency -- the same
	 * reason that partition was named rather than spelled out.
	 */
	public function test_the_order_of_work_advice_needs_both_halves_of_the_queue(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'if ( $ffc_identity_to_correct > 0 && $ffc_identity_to_judge > 0 ) :',
			$view,
			'The advice must need a correction and a judgement, not either alone.'
		);

		foreach ( array( 'IdentityQueuePanels::CORRECTIONS', 'IdentityQueuePanels::JUDGEMENTS' ) as $partition ) {
			$this->assertStringContainsString(
				$partition,
				$view,
				sprintf( 'The tiers must be read from the partition that ordered them, never listed again: %s', $partition )
			);
		}

		// COUNTED FROM THE PANELS, never from a query of its own -- this view's
		// own rule, stated where it sums them: the counters are the panels
		// counted and never a second opinion.
		$this->assertMatchesRegularExpression(
			'/\$ffc_identity_to_correct \+= \(int\) \$ffc_identity_panel\[\x27total\x27\];/',
			$view,
			'The correction total must come from the panels the screen already draws.'
		);
	}

	/**
	 * The advice states the rule and carries no count of its own (#1498).
	 *
	 * The chips one line above already give every number. A sentence repeating
	 * them could disagree with them; one stating the rule cannot. It also
	 * sidesteps the plural trap the coverage strip records -- a number inside a
	 * sentence needs `_n()` per number, so three numbers need three calls.
	 */
	public function test_the_order_of_work_advice_carries_no_number(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$start = strpos( $view, 'Work the corrections first.' );

		$this->assertIsInt( $start, 'The advice must be on the screen.' );

		// The literal ends at the closing quote of the `esc_html__()` argument.
		$sentence = substr( $view, $start, (int) strpos( $view, "', 'ffcertificate' )", $start ) - $start );

		$this->assertStringNotContainsString( '%s', $sentence, 'The advice must not carry a count; the chips above state them.' );
		$this->assertStringNotContainsString( '%1$s', $sentence, 'The advice must not carry a count; the chips above state them.' );
		$this->assertStringNotContainsString( '%d', $sentence, 'The advice must not carry a count; the chips above state them.' );
	}

	// =====================================================================
	// Acceptance (#1532)
	// =====================================================================

	/**
	 * Both new verbs are wired, gated and keyed to their own finding.
	 *
	 * Their own nonce actions rather than a mode on an existing verb: a nonce
	 * shared with the repair would let a correction's confirmation suppress a
	 * finding instead, which is a different promise entirely.
	 */
	public function test_the_acceptance_verbs_are_registered_gated_and_separately_keyed(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( "admin_post_' . self::ACCEPT_ACTION, array( \$this, 'handle_accept' )", $page );
		$this->assertStringContainsString( "admin_post_' . self::WITHDRAW_ACTION, array( \$this, 'handle_withdraw' )", $page );
		$this->assertStringContainsString( 'check_admin_referer( self::ACCEPT_NONCE . $subject )', $page );
		$this->assertStringContainsString( 'check_admin_referer( self::WITHDRAW_NONCE . $subject )', $page );

		foreach ( array( IdentityResolutionPage::REPAIR_NONCE, IdentityResolutionPage::CONSOLIDATE_NONCE, IdentityResolutionPage::RELINK_NONCE ) as $other ) {
			$this->assertNotSame( $other, IdentityResolutionPage::ACCEPT_NONCE );
			$this->assertNotSame( $other, IdentityResolutionPage::WITHDRAW_NONCE );
		}

		$this->assertNotSame( IdentityResolutionPage::ACCEPT_NONCE, IdentityResolutionPage::WITHDRAW_NONCE );
	}

	/**
	 * ACCEPTING TAKES THE SCREEN'S CAPABILITY AND NOT A NARROWER ONE.
	 *
	 * The split has its own because it CREATES a login. Accepting writes
	 * nobody's data, so it sits with the rest — and the reason is worth pinning
	 * because the opposite choice looks equally plausible from the outside.
	 */
	public function test_accepting_takes_the_screens_capability(): void {
		$page  = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );
		$start = (int) strpos( $page, 'public function handle_accept(): void {' );

		$this->assertGreaterThan( 0, $start );

		$body = substr( $page, $start, 400 );

		$this->assertStringContainsString( 'Capabilities::current_user_can_admin_or( self::CAPABILITY )', $body );
		$this->assertStringNotContainsString( 'SPLIT_CAPABILITY', $body );
		$this->assertStringNotContainsString( 'MERGE_CAPABILITY', $body );
	}

	/**
	 * WITHDRAWING READS THE QUEUE AGAIN; NO OTHER VERB HERE DOES.
	 *
	 * Every other write REMOVES a finding, so `report()` drops it from the held
	 * list and nothing else moves. This one puts one back, and a held list
	 * cannot gain a finding it was taken without — so the list has to be taken
	 * again, and only when the withdrawal actually succeeded.
	 */
	public function test_withdrawing_reads_the_queue_again_and_only_on_success(): void {
		$page  = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );
		$start = (int) strpos( $page, 'public function handle_withdraw(): void {' );

		$this->assertGreaterThan( 0, $start );

		$body = substr( $page, $start, 900 );

		$this->assertStringContainsString( '$result instanceof WP_Error', $body, 'A failed withdrawal must not reshuffle the list.' );
		$this->assertStringContainsString( '$this->worklists()->take( get_current_user_id(), self::LIMIT );', $body );

		// The accept side must NOT retake: it removes a finding, which
		// `report()` already handles, and retaking would renumber every other
		// position for nothing.
		$accept = substr( $page, (int) strpos( $page, 'public function handle_accept(): void {' ), 900 );

		$this->assertStringNotContainsString( '$this->worklists()->take(', $accept );
	}

	/**
	 * Every posted part is validated before it can be stored.
	 *
	 * An unrecognised check or tier would key a record against no finding — a
	 * suppression that silently does nothing, which is worse than a refusal.
	 */
	public function test_the_posted_check_and_tier_are_validated(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( 'private static function posted_check(): string {', $page );
		$this->assertStringContainsString( 'private static function posted_tier(): string {', $page );
		$this->assertStringContainsString( 'in_array( $tier, IdentityQueuePanels::ORDER, true )', $page );
		$this->assertStringContainsString( 'self::posted_check()', $page );
		$this->assertStringContainsString( 'self::posted_tier()', $page );
	}

	/**
	 * THE CONTROL IS OFFERED ON THREE TIERS, AND THE VIEW NAMES NONE OF THEM.
	 *
	 * The list lives on `IdentityQueuePanels::ACCEPTABLE` with the reason the
	 * other two are absent; a literal list in the view would drift from it.
	 */
	public function test_the_accept_control_is_offered_by_the_declared_tiers_only(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertSame(
			2,
			substr_count( $view, 'IdentityQueuePanels::ACCEPTABLE, true )' ),
			'Both card shapes must gate on the declared list, and neither may spell the tiers out.'
		);
		$this->assertStringContainsString( 'IdentityResolutionPage::ACCEPT_ACTION', $view );

		$this->assertSame(
			array( IdentityQueue::TIER_ISOLATED, IdentityQueue::TIER_DECISION, IdentityQueue::TIER_MAILBOX ),
			IdentityQueuePanels::ACCEPTABLE
		);
		$this->assertNotContains(
			IdentityQueue::TIER_MECHANICAL,
			IdentityQueuePanels::ACCEPTABLE,
			'A tier one click from correct has nothing for HR to supply.'
		);
		$this->assertNotContains(
			IdentityQueue::TIER_SHARED,
			IdentityQueuePanels::ACCEPTABLE,
			'A merge is blocked by a number, and the number is accepted on its own panel.'
		);
	}

	/**
	 * The accepted panel is a list of DECISIONS, with a count and a way back.
	 *
	 * Counted because a suppression nobody can see is how a queue comes to lie
	 * about being finished, and reversible because a judgement made from
	 * incomplete information is the normal case here.
	 */
	public function test_the_accepted_panel_is_counted_and_reversible(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString( 'array() !== $ffc_identity_accepted', $view );
		$this->assertStringContainsString( 'count( $ffc_identity_accepted )', $view );
		$this->assertStringContainsString( 'IdentityResolutionPage::WITHDRAW_ACTION', $view );
		$this->assertStringContainsString( 'IdentityResolutionPage::WITHDRAW_NONCE . $ffc_identity_ar_subject', $view );
		$this->assertStringContainsString( 'IdentityQueue::DISPLAY_PREFIX', $view );
	}

	/**
	 * A FIFTH EMPTY STATE, BECAUSE ACCEPTANCE ADDED ONE.
	 *
	 * The four that existed split an empty list by what the scan managed to
	 * read. This one is different in kind: the scan read fine and found
	 * failures, and they are absent because somebody judged them unresolvable.
	 * Falling into the reassuring branch would claim every stored value
	 * satisfies its check digit, which is false while one acceptance stands.
	 */
	public function test_an_empty_list_with_acceptances_does_not_read_as_a_clean_result(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$branch = (int) strpos( $view, 'elseif ( array() !== $ffc_identity_accepted ) : ?>' );
		$clean  = (int) strpos( $view, 'Nothing to resolve: %1$s stored values checked and every one satisfies its check digit.' );

		$this->assertGreaterThan( 0, $branch, 'The accepted case needs a branch of its own.' );
		$this->assertGreaterThan( 0, $clean );
		$this->assertLessThan( $clean, $branch, 'It must be tested BEFORE the branch that claims every value is sound.' );
	}

	/**
	 * The record reaches the view through the screen's own seam.
	 *
	 * One seam means the queue's filter and the panel below read the SAME
	 * record — two instances are harmless today and one refactor away from the
	 * panel disagreeing with the filter about what is accepted.
	 */
	public function test_the_queue_and_the_panel_read_one_record(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( '$ffc_identity_accepted = $this->acceptances()->all();', $page );
		$this->assertStringContainsString( '$accepted = $this->acceptances();', $page );
		$this->assertStringContainsString( 'protected function acceptances(): IdentityAcceptance {', $page );
	}
}
