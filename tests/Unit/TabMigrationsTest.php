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

	/**
	 * Temporary view directory a test created, removed in tearDown() so a
	 * failing assertion cannot leave it behind.
	 */
	private ?string $tmp_dir = null;

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
		if ( null !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( (array) glob( $this->tmp_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}
			rmdir( $this->tmp_dir );
		}
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

		$complete = $this->complete_branch( $view );
		$this->assertStringContainsString( "\$ffcertificate_status['rearmable']", $complete, 'The re-check control belongs to the branch that had no verb.' );
		$this->assertStringContainsString( '$ffcertificate_rearm_url', $complete );
		$this->assertSame( 1, substr_count( $view, "'ffc_rearm_migration' =>" ), 'The re-arm URL is built once.' );

		// Below a hundred per cent the control appears only through
		// `retryable`, which a strategy sets once its walk has ended, so it has
		// no progress to discard (#2338). `rearmable` stays out of that branch.
		$from    = (int) strpos( $view, '<?php else : ?>', (int) strpos( $view, '<?php if ( $ffcertificate_is_complete ) : ?>' ) );
		$pending = substr( $view, $from, (int) strpos( $view, '<?php endif; ?>' . "\n\t\t\t</div>", $from ) - $from );
		$this->assertStringContainsString( "\$ffcertificate_status['retryable']", $pending );
		$this->assertStringNotContainsString( "\$ffcertificate_status['rearmable']", $pending );
	}

	/**
	 * It carries its own nonce action, never the run button's.
	 *
	 * A shared key would let a link minted for one press the other, and the
	 * handler verifies exactly this string.
	 */
	public function test_the_recheck_control_carries_its_own_nonce_action(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		// The URL is built once, before the two branches that offer it.
		$from  = strpos( $view, '$ffcertificate_rearm_url = wp_nonce_url(' );
		$this->assertIsInt( $from );
		$block = substr( $view, (int) $from, (int) strpos( $view, '?>', (int) $from ) - (int) $from );

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
	 * `ffc-admin-migrations.js` binds `.ffc-migration-actions a.ffc-migration-run`
	 * and pulls `ffc_run_migration` out of the href. That hook here would
	 * hand this link to a loop that cannot find a key in it.
	 */
	public function test_the_recheck_control_is_not_the_driver_s_button(): void {
		$view  = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );
		$block = $this->complete_branch( $view );

		$start = strpos( $block, "'href'     => \$ffcertificate_rearm_url" );
		$this->assertIsInt( $start, 'The control must use its own URL.' );

		$anchor = substr( $block, (int) $start );

		$this->assertStringContainsString( "'href'     => \$ffcertificate_rearm_url", $anchor );
		$this->assertStringNotContainsString( 'ffc-migration-run', $anchor );

		// The driver's own selector, asserted here so a rename on either side
		// fails rather than silently re-coupling the two.
		$this->assertStringContainsString(
			'.ffc-migration-actions a.ffc-migration-run',
			(string) file_get_contents( __DIR__ . '/../../assets/js/ffc-admin-migrations.js' )
		);
	}

	/**
	 * The tab under test with only its view directory redirected, through the
	 * `view_path()` seam: render() is the product's own (#1811).
	 *
	 * @param string $views_dir Directory the tab reads its view from.
	 * @return TabMigrations
	 */
	private function tab_with_views_in( string $views_dir ): TabMigrations {
		return new class( $views_dir ) extends TabMigrations {
			private string $views_dir;
			public function __construct( string $views_dir ) {
				$this->views_dir = $views_dir;
				parent::__construct();
			}
			protected function view_path( string $file ): string {
				return $this->views_dir . '/' . $file;
			}
		};
	}

	public function test_render_error_when_view_missing(): void {
		$tab = $this->tab_with_views_in( sys_get_temp_dir() . '/ffc_test_no_such_view_dir_' . getmypid() );

		ob_start();
		$tab->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'Migrations view file not found.', $output );
	}

	public function test_render_includes_view_when_file_exists(): void {
		$this->tmp_dir = sys_get_temp_dir() . '/ffc_test_views_mig_' . getmypid();
		if ( ! is_dir( $this->tmp_dir ) ) {
			mkdir( $this->tmp_dir, 0777, true );
		}
		file_put_contents( $this->tmp_dir . '/ffc-tab-migrations.php', '<?php echo "migrations-view-rendered";' );

		ob_start();
		$this->tab_with_views_in( $this->tmp_dir )->render();
		$output = ob_get_clean();

		$this->assertSame( 'migrations-view-rendered', $output );
	}


	/**
	 * A finished card closes; pending or unreadable stays open (#2115).
	 */
	public function test_a_card_opens_unless_its_work_is_done_and_readable(): void {
		$this->assertTrue( TabMigrations::card_opens( false, '' ), 'records pending' );
		$this->assertFalse( TabMigrations::card_opens( true, '' ), 'nothing left to do' );
		$this->assertTrue( TabMigrations::card_opens( false, 'boom' ), 'the status check failed' );
		$this->assertTrue( TabMigrations::card_opens( true, 'boom' ), 'an error is never hidden in a closed card' );
	}

	public function test_a_deprecated_entry_names_its_removal_release(): void {
		$this->assertSame( '6.45.0', TabMigrations::removal_of( array( 'removal' => '6.45.0' ) ) );
		$this->assertSame( '', TabMigrations::removal_of( array( 'name' => 'Stays' ) ) );
		$this->assertSame( '', TabMigrations::removal_of( array( 'removal' => 645 ) ), 'a value that is not a version is not read as one' );
	}

	/**
	 * The card is the shared collapsible section, its open state comes from
	 * the rule above, and it keeps the class the batch runner finds it by.
	 */
	public function test_each_card_is_a_collapsible_section_the_runner_still_finds(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$this->assertStringContainsString( '<details id="ffc-migration-<?php echo esc_attr( $ffcertificate_key ); ?>" class="ffc-section ffc-migration-card ', $view );
		$this->assertStringContainsString( 'TabMigrations::card_opens( (bool) $ffcertificate_is_complete, (string) $ffcertificate_status_error ) ? \' open\'', $view );
		$this->assertStringContainsString( '<summary class="ffc-section__summary">', $view );
		$this->assertStringContainsString( '</details>', $view );
	}

	/**
	 * A refused prerequisite reads as the strategy's own message, and a card
	 * that can run reads as nothing (#2651).
	 */
	public function test_an_unmet_prerequisite_reads_as_the_strategy_s_refusal(): void {
		$this->assertSame( '', TabMigrations::unmet_prerequisite( true ) );
		$this->assertSame(
			'Generate a signing key first, under Settings → Certificates.',
			TabMigrations::unmet_prerequisite( new \WP_Error( 'no_signing_key', 'Generate a signing key first, under Settings → Certificates.' ) )
		);
		// Anything but a plain true is a refusal, never a pass: an empty
		// message or an odd value still disables the button.
		$this->assertSame( 'This migration cannot run yet on this site.', TabMigrations::unmet_prerequisite( new \WP_Error( 'x', '' ) ) );
		$this->assertSame( 'This migration cannot run yet on this site.', TabMigrations::unmet_prerequisite( false ) );
		$this->assertSame( 'This migration cannot run yet on this site.', TabMigrations::unmet_prerequisite( null ) );
	}

	/**
	 * The pending branch asks the prerequisite before drawing Run, renders it
	 * disabled with the refusal, and keeps the retry control out (#2651).
	 */
	public function test_a_card_with_an_unmet_prerequisite_offers_no_run(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$this->assertStringContainsString( '$ffcertificate_migration_manager->can_run( $ffcertificate_key )', $view );

		$from   = (int) strpos( $view, "<?php elseif ( '' !== \$ffcertificate_unmet ) : ?>" );
		$this->assertGreaterThan( 0, $from, 'The pending branch has an unmet-prerequisite case.' );
		$branch = substr( $view, $from, (int) strpos( $view, '<?php else : ?>', $from ) - $from );
		$this->assertStringContainsString( "'disabled' => true", $branch );
		$this->assertStringContainsString( 'esc_html( $ffcertificate_unmet )', $branch );
		$this->assertStringNotContainsString( 'ffc-migration-run', $branch, 'No button the batch driver can pick up.' );
		$this->assertStringNotContainsString( '$ffcertificate_migrate_url', $branch );

		// The case sits before the runnable one, so a refusal never reaches it.
		$this->assertLessThan( (int) strpos( $view, "'class'    => 'ffc-migration-run'" ), $from );
		$this->assertStringContainsString( "\$ffcertificate_can_migrate && '' === \$ffcertificate_unmet && ! empty( \$ffcertificate_status['retryable'] )", $view );
	}

	/**
	 * A refusal's action is offered only when it is whole and the user may
	 * open its destination (#2656).
	 */
	public function test_a_prerequisite_action_is_offered_only_whole_and_to_whoever_may_follow_it(): void {
		$granted = array( 'ffc_manage_settings_dangerzone' );
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap ) use ( &$granted ): bool {
				return in_array( $cap, $granted, true );
			}
		);
		$refusal = static fn( $data ) => new \WP_Error( 'no_signing_key', 'Generate a key first.', $data );
		$action  = array(
			'label' => 'Generate a signing key',
			'url'   => 'https://example.test/wp-admin/admin.php?page=ffc-settings&tab=certificates#ffc-integrity-signature',
			'cap'   => 'ffc_manage_settings_dangerzone',
			'icon'  => 'key',
		);

		$this->assertSame(
			array( 'label' => $action['label'], 'url' => $action['url'], 'icon' => 'key' ),
			TabMigrations::prerequisite_action( $refusal( array( 'action' => $action ) ) )
		);
		$this->assertSame( 'link', TabMigrations::prerequisite_action( $refusal( array( 'action' => array_diff_key( $action, array( 'icon' => 0 ) ) ) ) )['icon'] ?? null, 'Without an icon, the link one.' );

		// Nothing to offer: a pass, a refusal without data, a malformed action.
		$this->assertNull( TabMigrations::prerequisite_action( true ) );
		$this->assertNull( TabMigrations::prerequisite_action( new \WP_Error( 'x', 'No data.' ) ) );
		$this->assertNull( TabMigrations::prerequisite_action( $refusal( array( 'action' => 'not an array' ) ) ) );
		foreach ( array( 'label', 'url', 'cap' ) as $missing ) {
			$this->assertNull( TabMigrations::prerequisite_action( $refusal( array( 'action' => array_diff_key( $action, array( $missing => 0 ) ) ) ) ), 'Without ' . $missing . '.' );
		}

		// A destination this user may not open is never offered.
		$granted = array();
		$this->assertNull( TabMigrations::prerequisite_action( $refusal( array( 'action' => $action ) ) ) );
	}

	/**
	 * The unmet branch draws the action under the refusal, and every card
	 * carries the anchor an action may point at (#2656).
	 */
	public function test_the_unmet_branch_draws_the_action_and_cards_are_addressable(): void {
		$view = (string) file_get_contents( __DIR__ . '/../../includes/settings/views/ffc-tab-migrations.php' );

		$from   = (int) strpos( $view, "<?php elseif ( '' !== \$ffcertificate_unmet ) : ?>" );
		$branch = substr( $view, $from, (int) strpos( $view, '<?php else : ?>', $from ) - $from );
		$this->assertStringContainsString( 'null !== $ffcertificate_unmet_action', $branch );
		$this->assertStringContainsString( "'href'  => \$ffcertificate_unmet_action['url']", $branch );
		$this->assertStringNotContainsString( 'ffc-migration-run', $branch );

		$this->assertStringContainsString( '<details id="ffc-migration-<?php echo esc_attr( $ffcertificate_key ); ?>"', $view );
	}
}
