<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabMigrations;

/**
 * @covers \FreeFormCertificate\Settings\Tabs\TabMigrations
 */
class TabMigrationsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private TabMigrations $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		// `SettingsTab` requires `ABSPATH . 'wp-includes/formatting.php'` when
		// `wp_kses_post` is undefined, and that path does not exist under the
		// test bootstrap -- so loading this tab fataled unless some EARLIER
		// test in the process happened to have taught Patchwork the function.
		// The suite is green because one does; the file could not be run on
		// its own at all, which is the order-dependence `CLAUDE.md` records as
		// "`--filter` is not evidence". Stubbing it here chooses the branch
		// rather than inheriting it.
		Functions\when( 'wp_kses_post' )->returnArg();

		$this->tab = new TabMigrations();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_id(): void {
		$this->assertSame( 'migrations', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'Data Migrations', $this->tab->get_title() );
	}

	public function test_tab_icon(): void {
		$this->assertSame( 'ffc-icon-database', $this->tab->get_icon() );
	}

	public function test_tab_order(): void {
		$this->assertSame( 80, $this->tab->get_order() );
	}

	/**
	 * Just the conflicts block of the migration card.
	 *
	 * Bounded on both sides, because the markup that follows it is the verb
	 * column of every card and a substring running past it would let the
	 * assertions below pass on somebody else's button.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function conflicts_block( string $view ): string {
		// STARTS AT ITS OWN GATE, not at the div. The URL is built between
		// the two, and the audit card further down now reads the same
		// capability -- so an assertion over the whole view is satisfied by
		// THAT occurrence and stops protecting this one. That is not
		// hypothetical: adding the audit card's link silently disarmed this
		// gate's test, and the mutation caught it before the push.
		$from = strpos( $view, '<?php if ( null !== $ffcertificate_conflicts' );
		$to   = ( false === $from ) ? false : strpos( $view, '<!-- Actions -->', (int) $from );

		$this->assertIsInt( $from, 'The card must report the work its button cannot do.' );
		$this->assertIsInt( $to, 'The conflicts block must sit above the actions.' );

		return substr( $view, (int) $from, (int) $to - (int) $from );
	}

	/**
	 * THE CARD REPORTS THE CONFLICTS AND OFFERS NO RE-RUN (#1368).
	 *
	 * The backfill leaves an account holding two different numbers for one
	 * field empty on purpose — choosing would destroy the evidence that they
	 * disagree — so this is outstanding work the button can never do. A
	 * button there would move the number by zero, which is exactly how an
	 * operator learns to ignore a card.
	 */
	public function test_the_card_reports_conflicts_and_offers_no_rerun(): void {
		$view  = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );
		$block = $this->conflicts_block( $view );

		$this->assertStringNotContainsString(
			'ffc_run_migration',
			$block,
			'Re-running moves this number by zero.'
		);
		// Asserted over the view, not the block: the URL is built just above
		// the markup, where the capability is read, so a block-bounded search
		// would miss it for a reason that is not a defect.
		$this->assertStringContainsString(
			'IdentityResolutionPage::MENU_SLUG',
			$view,
			'The screen that can resolve them must be linked, by its own constant.'
		);
		$this->assertStringContainsString(
			'$ffcertificate_identities_url',
			$block,
			'And the block is where that link is printed.'
		);
		$this->assertStringNotContainsString(
			"'ffc-identities'",
			$view,
			'The slug is read off the class, never retyped.'
		);
	}

	/**
	 * The link is offered only to somebody who can open it.
	 *
	 * An offered control that answers `wp_die` is worse than an absent one —
	 * the same decision the identity screen already makes about its own
	 * export link.
	 */
	public function test_the_identity_link_is_gated_on_the_capability(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$block = $this->conflicts_block( $view );

		$this->assertStringContainsString(
			'current_user_can( \FreeFormCertificate\Admin\IdentityResolutionPage::CAPABILITY )',
			$block,
			'The link must be built only for a holder of the capability the screen checks.'
		);
		$this->assertStringContainsString(
			"'' !== \$ffcertificate_identities_url",
			$block,
			'And the block must print nothing where there is no link to print.'
		);
	}

	/**
	 * A count that could not be taken renders nothing at all.
	 *
	 * `null` is not `0`: one means no conflicts, the other means the scan
	 * never ran. A block rendered off the second would tell an operator that
	 * an install with conflicts has none.
	 */
	public function test_an_unread_conflict_count_renders_no_block(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		// THE RENDER GATE, not the string anywhere in the file. The
		// complete-message branch below tests the same condition in the brace
		// form, so an unanchored search finds that one and passes with the
		// block's own gate relaxed -- which is how the first version of this
		// assertion survived the mutation it exists for.
		$this->assertStringContainsString(
			'<?php if ( null !== $ffcertificate_conflicts && $ffcertificate_conflicts > 0 ) : ?>',
			$view,
			'Both an unread count and a zero must render nothing.'
		);
	}

	/**
	 * The card does not claim every record migrated over a number it has
	 * just reported as outstanding.
	 */
	public function test_the_complete_message_does_not_contradict_the_conflicts(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$all = strpos( $view, 'All records have been successfully migrated.' );
		$if  = strpos( $view, 'if ( null !== $ffcertificate_conflicts && $ffcertificate_conflicts > 0 ) {' );

		$this->assertIsInt( $all, 'The complete message must still exist for the cards with nothing outstanding.' );
		$this->assertIsInt( $if, 'It must be the branch that runs when nothing is outstanding.' );
		$this->assertLessThan( (int) $all, (int) $if, 'The qualified sentence comes first; "All records" is the else.' );
	}

	/**
	 * Just the audit card's row of buttons.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function audit_actions( string $view ): string {
		$from = strpos( $view, '$ffcertificate_sa_scan_url' );
		$from = ( false === $from ) ? false : strpos( $view, 'ffc-migration-actions', (int) $from );
		$to   = ( false === $from ) ? false : strpos( $view, '</div>', (int) $from );

		$this->assertIsInt( $from, 'The audit card must keep its row of buttons.' );
		$this->assertIsInt( $to, 'That row must close.' );

		return substr( $view, (int) $from, (int) $to - (int) $from );
	}

	/**
	 * THE LOOP CLOSES BOTH WAYS (#1368).
	 *
	 * This card reads and never writes: every verb that resolves what it
	 * finds lives on the identity screen, which already offers this card's
	 * CSV. Until now nothing pointed the other way, so an operator holding
	 * both capabilities read a list of findings with no route to the screen
	 * that acts on them.
	 *
	 * A link, and deliberately not the screen moved here — the two surfaces
	 * answer different question sets and sit behind different capabilities.
	 */
	public function test_the_audit_card_links_to_the_screen_that_resolves_its_findings(): void {
		$view    = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );
		$actions = $this->audit_actions( $view );

		$this->assertStringContainsString(
			'IdentityResolutionPage::MENU_SLUG',
			$actions,
			'The audit card must name the screen that can act on its findings.'
		);
		$this->assertStringContainsString(
			'current_user_can( \FreeFormCertificate\Admin\IdentityResolutionPage::CAPABILITY )',
			$actions,
			'And offer it only to somebody who can open it.'
		);
	}

	/**
	 * Just the audit card's statistics region.
	 *
	 * Bounded for the reason `audit_actions()` and `complete_branch()` already
	 * give: the card holds several numeric blocks, and an unbounded search
	 * lets an assertion about this one pass on another.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function audit_stats( string $view ): string {
		$from = strpos( $view, '<div class="ffc-migration-stats">' );
		$to   = ( false === $from ) ? false : strpos( $view, '$ffcertificate_sa_findings = array();', (int) $from );

		$this->assertIsInt( $from, 'The audit card must keep its statistics grid.' );
		$this->assertIsInt( $to, 'That grid is followed by the clickable findings; one of the two moved.' );

		return substr( $view, (int) $from, (int) $to - (int) $from );
	}

	/**
	 * THE CARD REPORTS THE ACCEPTED SPLIT AND SUBTRACTS NOTHING (#1536).
	 *
	 * An accepted finding is one nobody can resolve -- the number was never
	 * supplied, and HR cannot trace it. That is a decision about what to do,
	 * not a change to the data, so this card goes on counting it and says how
	 * many of its findings are in that state. Showing the smaller number
	 * instead would make the scan disagree with the database it scanned, and
	 * showing only the bare total is what left the two surfaces over this one
	 * scan explaining themselves differently: the CSV carries a reason per row
	 * while the card's number could never reach zero.
	 */
	public function test_the_audit_card_reports_the_accepted_split(): void {
		$stats = $this->audit_stats( (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' ) );

		$this->assertStringContainsString(
			"number_format_i18n( \$ffcertificate_sa_c )",
			$stats,
			"The headline per check must stay the scan's own count, with no arithmetic applied to it."
		);

		$this->assertStringContainsString(
			'ffc-migration-stat-note',
			$stats,
			'The split belongs under the count it qualifies.'
		);

		$this->assertStringContainsString(
			'%1$s accepted · %2$s open',
			$stats,
			'Per check, both halves are named: a lone "3 accepted" leaves the reader subtracting.'
		);

		$this->assertStringContainsString(
			'$ffcertificate_sa_total - $ffcertificate_sa_acc',
			$stats,
			'And the summary states how many remain open across the whole report.'
		);

		$this->assertStringContainsString(
			'does not change anything stored',
			$stats,
			'The summary must say acceptance is not resolution, which is the sentence this whole state turns on.'
		);
	}

	/**
	 * A REPORT CACHED BEFORE THIS RELEASE CARRIES NO `accepted` KEY.
	 *
	 * The scan stores its report in a transient, so the first render after an
	 * upgrade reads one written by the previous build. Every read of the new
	 * key falls back rather than assuming it is there -- the same care the
	 * `account_status` reads in this card already take, and the reason that
	 * one carries its own comment.
	 */
	public function test_the_accepted_reads_survive_a_report_from_an_older_build(): void {
		$stats = $this->audit_stats( (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' ) );

		$reads = preg_match_all( '/\$ffcertificate_sa_(?:report|checks\[[^\]]+\])\[.accepted.\]/', $stats, $found );

		$this->assertGreaterThan( 0, $reads, 'The card reads the accepted count somewhere, or this case is measuring nothing.' );

		foreach ( $found[0] as $read ) {
			$this->assertStringContainsString(
				'isset( ' . $read . ' )',
				$stats,
				"`{$read}` is read without an `isset` guard, so a transient from an older build renders a notice."
			);
		}
	}

	/**
	 * Just the complete branch of the actions column.
	 *
	 * Bounded on both sides for the reason `conflicts_block()` already gives:
	 * the run button sits in the `else`, and an unbounded search would let
	 * every assertion below pass on it instead.
	 *
	 * @param string $view The view's source.
	 * @return string
	 */
	private function complete_branch( string $view ): string {
		$from = strpos( $view, '<!-- Actions -->' );
		$from = ( false === $from ) ? false : strpos( $view, '<?php if ( $ffcertificate_is_complete ) : ?>', (int) $from );
		$to   = ( false === $from ) ? false : strpos( $view, '<?php else : ?>', (int) $from );

		$this->assertIsInt( $from, 'The actions column must branch on completion.' );
		$this->assertIsInt( $to, 'That branch must have an else -- the run button.' );

		return substr( $view, (int) $from, (int) $to - (int) $from );
	}

	/**
	 * THE RE-CHECK CONTROL LIVES IN THE COMPLETE BRANCH, AND ONLY THERE (#1530).
	 *
	 * Below a hundred per cent the ordinary button is the verb, and re-arming
	 * there would discard the progress already made. At a hundred the branch
	 * held only a disabled seal, which is the gap: an account that becomes
	 * workable after the walk passed it has nothing to revisit it.
	 */
	public function test_the_recheck_control_is_offered_only_on_the_complete_branch(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$this->assertStringContainsString(
			'ffc_rearm_migration',
			$this->complete_branch( $view ),
			'The re-check control belongs to the branch that had no verb.'
		);
		$this->assertSame(
			1,
			substr_count( $view, "'ffc_rearm_migration' =>" ),
			'One control: a second site would mean the pending branch can discard its own progress.'
		);
	}

	/**
	 * It carries its own nonce action, never the run button's.
	 *
	 * A shared key would let a link minted for one press the other, and the
	 * handler verifies exactly this string.
	 */
	public function test_the_recheck_control_carries_its_own_nonce_action(): void {
		$block = $this->complete_branch(
			(string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' )
		);

		$this->assertStringContainsString( "'ffc_rearm_' . \$ffcertificate_key", $block );
		$this->assertStringNotContainsString( "'ffc_migration_' . \$ffcertificate_key", $block );
	}

	/**
	 * The offer is the strategy's to make, not the view's to infer.
	 *
	 * The flag travels in the status array the card already reads, so this
	 * view never learns which option holds whose cursor -- and a card without
	 * the method simply omits it rather than being listed here by key.
	 */
	public function test_the_recheck_control_is_gated_on_the_status_flag(): void {
		$block = $this->complete_branch(
			(string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' )
		);

		$this->assertStringContainsString( "! empty( \$ffcertificate_status['rearmable'] )", $block );
		$this->assertStringNotContainsString( 'identity_index_backfill', $block, 'The view must not name the card it is offered on.' );
	}

	/**
	 * It stays out of the auto-run driver's reach.
	 *
	 * `ffc-admin-migrations.js` binds `.ffc-migration-actions a.button-primary`
	 * and pulls `ffc_run_migration` out of the href. A primary class here
	 * would hand this link to a loop that cannot find a key in it.
	 */
	public function test_the_recheck_control_is_not_the_driver_s_button(): void {
		$view  = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );
		$block = $this->complete_branch( $view );

		$start = strpos( $block, '$ffcertificate_rearm_url' );
		$this->assertIsInt( $start, 'The control must build its own URL.' );

		$anchor = substr( $block, (int) $start );

		$this->assertStringContainsString( 'class="button button-secondary ffc-icon-sync"', $anchor );
		$this->assertStringNotContainsString( 'button-primary', $anchor );

		// The driver's own selector, asserted here so a rename on either side
		// fails rather than silently re-coupling the two.
		$this->assertStringContainsString(
			'.ffc-migration-actions a.button-primary',
			(string) file_get_contents( __DIR__ . '/../../assets/js/ffc-admin-migrations.js' )
		);
	}

	public function test_render_error_when_view_missing(): void {
		$tab = new class() extends TabMigrations {
			public function render(): void {
				$view_file = '/tmp/nonexistent_ffc/ffc-tab-migrations.php';
				if ( file_exists( $view_file ) ) {
					include $view_file;
				} else {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Migrations view file not found.', 'ffcertificate' );
					echo '</p></div>';
				}
			}
		};

		ob_start();
		$tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not found', $output );
	}

}
