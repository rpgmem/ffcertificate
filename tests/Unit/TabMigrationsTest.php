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
		$this->assertSame( 'ffc-icon-sync', $this->tab->get_icon() );
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
		$from = strpos( $view, 'class="ffc-migration-conflicts"' );
		$to   = strpos( $view, '<!-- Actions -->', (int) $from );

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

		$this->assertStringContainsString(
			'current_user_can( \FreeFormCertificate\Admin\IdentityResolutionPage::CAPABILITY )',
			$view,
			'The link must be built only for a holder of the capability the screen checks.'
		);
		$this->assertStringContainsString(
			"'' !== \$ffcertificate_identities_url",
			$this->conflicts_block( $view ),
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
