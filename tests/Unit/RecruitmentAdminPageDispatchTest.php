<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;

/**
 * Dispatch-level tests for {@see RecruitmentAdminPage} — the `render_page()`
 * router, `register_menu()` wiring and the four 3-state cap gate helpers that
 * the helper-focused sibling test (`RecruitmentAdminPageTest`) declared out of
 * scope.
 *
 * The collaborators are the real classes: `Capabilities`, the four edit pages,
 * the action dispatcher and the tab renderer. Capability outcomes are driven
 * per test by a granted-caps allowlist behind `current_user_can()`, which
 * never grants `manage_options`, so each 3-state branch is reachable. The
 * tab renderer's list tables read a `$wpdb` double that answers every
 * recruitment table with no rows and refuses anything else, and the router's
 * branch is observed in the markup the real renderer prints.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPage
 */
class RecruitmentAdminPageDispatchTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int,string> Capabilities `current_user_can()` grants. */
	private $granted = array();

	/** @var list<string> URLs `wp_safe_redirect()` was asked to go to. */
	private array $redirects = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->database();

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

		class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage' );

		// WP escaping / i18n stubs, as the real renderer's own test stubs them.
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $s ) { echo $s; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_editor' )->alias(
			static function ( $content, $id ) {
				echo '<textarea id="' . $id . '">' . $content . '</textarea>';
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) )
		);
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args, $url = '' ) => $url . '?' . http_build_query( (array) $args )
		);
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'settings_fields' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		// Every option is empty: the recruitment settings fall back to their
		// defaults and the Email Model's disabled notice stays silent.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// LabelSorter takes the WordPress branch only when get_locale() exists, and
		// whether it does depends on what ran earlier in the process: choose it here.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		$this->granted = array();
		Functions\when( 'current_user_can' )->alias(
			fn( $cap ): bool => in_array( $cap, $this->granted, true )
		);

		// wp_die halts in production; make it observable as an exception.
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '' ) {
				throw new \RuntimeException( 'wp_die:' . (string) $msg );
			}
		);
		// wp_safe_redirect is followed by `exit` in the dispatcher; throwing
		// stands in for it and records where the request was sent.
		$this->redirects = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) {
				$this->redirects[] = (string) $url;
				throw new \RuntimeException( 'redirected' );
			}
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database whose recruitment tables are empty. Any other read, and any
	 * write at all, is not declared, so it fails the test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $query, ...$args ) => $query . ' -- ' . (string) json_encode( $args )
		);
		$recruitment_only = function ( $query ) {
			if ( false === strpos( (string) $query, 'wp_ffc_recruitment_' ) ) {
				$this->fail( 'Unexpected query: ' . (string) $query );
			}
		};
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $query ) use ( $recruitment_only ) {
				$recruitment_only( $query );
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $query ) use ( $recruitment_only ) {
				$recruitment_only( $query );
				return 0;
			}
		);

		return $wpdb;
	}

	/** Capture render_page() output. */
	private function render(): string {
		ob_start();
		try {
			RecruitmentAdminPage::render_page();
		} finally {
			$out = (string) ob_get_clean();
		}
		return $out;
	}

	/**
	 * The heading the real renderer opens a tab's screen with.
	 *
	 * @param string $icon  Registry icon class of the tab.
	 * @param string $label Tab label.
	 * @return string
	 */
	private function tab_heading( string $icon, string $label ): string {
		return '<div class="card"><h2 class="' . $icon . '">' . $label . '</h2>';
	}

	/** The notices tab's screen opened, in the panel the router named. */
	private function assertNoticesTab( string $out ): void {
		$this->assertStringContainsString( 'id="ffc-recruitment-tabpanel-notices"', $out );
		$this->assertStringContainsString( $this->tab_heading( 'ffc-icon-megaphone', 'Notices' ), $out );
	}

	// ------------------------------------------------------------------
	// render_page() — access gate
	// ------------------------------------------------------------------

	public function test_render_page_opens_for_the_manage_tier_without_the_view_cap(): void {
		// 3-state (#1782): manage implies view. The 6.0.0 Recruitment Manager
		// role is created with `ffc_manage_recruitment` alone.
		$this->granted = array( 'ffc_manage_recruitment' );
		$_GET          = array();

		$out = $this->render();

		$this->assertNoticesTab( $out );
		$this->assertTrue( RecruitmentAdminPage::can_view() );
	}

	public function test_can_view_needs_the_view_or_the_manage_cap(): void {
		$this->granted = array( 'ffc_view_recruitment' );
		$this->assertTrue( RecruitmentAdminPage::can_view() );
		$this->granted = array( 'ffc_view_recruitment_settings', 'ffc_call_recruitment' );
		$this->assertFalse( RecruitmentAdminPage::can_view() );
	}

	public function test_render_page_denies_without_view_cap(): void {
		$this->granted = array(); // no caps
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die:' );
		RecruitmentAdminPage::render_page();
	}

	// ------------------------------------------------------------------
	// render_page() — edit-screen hijack
	// ------------------------------------------------------------------

	public function test_render_page_edit_notice_requires_manage_cap(): void {
		$this->granted = array( 'ffc_view_recruitment' ); // view only, not manage
		$_GET          = array( 'action' => 'edit-notice' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die:' );
		$this->render();
	}

	public function test_render_page_edit_notice_renders_edit_screen(): void {
		$this->granted = array( 'ffc_view_recruitment', 'ffc_manage_recruitment' );
		$_GET          = array(
			'action'  => 'edit-notice',
			'ffc_msg' => 'saved',
		);

		$out = $this->render();

		$this->assertStringContainsString( 'ffc-recruitment-admin', $out );
		// The flash notice the real renderer draws for `saved`.
		$this->assertStringContainsString( 'notice-success', $out );
		$this->assertStringContainsString( 'Saved.', $out );
		// The real notice edit page, reached with no id.
		$this->assertStringContainsString( 'Notice not found.', $out );
		$this->assertStringNotContainsString( 'ffc-recruitment-tabpanel-', $out, 'An edit screen replaces the tab strip.' );
	}

	public function test_render_page_edit_candidate_and_adjutancy_and_reason_screens(): void {
		// The reason edit page gates on its own manage cap (GAP I), so the
		// user holds it too; the other two gate on `ffc_manage_recruitment`.
		$this->granted = array( 'ffc_view_recruitment', 'ffc_manage_recruitment', 'ffc_manage_recruitment_reasons' );

		foreach (
			array(
				'edit-candidate' => 'Candidate not found.',
				'edit-adjutancy' => 'Adjutancy not found.',
				'edit-reason'    => 'Reason not found.',
			) as $action => $screen
		) {
			$_GET = array( 'action' => $action );
			$out  = $this->render();
			$this->assertStringContainsString( 'wrap ffc-admin-page ffc-page-recruitment ffc-recruitment-admin', $out );
			$this->assertStringContainsString( $screen, $out, $action . ' routes to its own edit page' );
		}
	}

	// ------------------------------------------------------------------
	// render_page() — action dispatch + tab routing
	// ------------------------------------------------------------------

	public function test_render_page_dispatches_non_edit_action_then_renders_tab(): void {
		// A view-only user's crafted delete link reaches the dispatcher, which
		// refuses it without the delete cap: no redirect, and the tab renders.
		$this->granted = array( 'ffc_view_recruitment' );
		$_GET          = array( 'action' => 'delete-notice' );

		$out = $this->render();

		$this->assertSame( array(), $this->redirects );
		$this->assertNoticesTab( $out );
		$this->assertStringContainsString( 'ffc-recruitment-admin', $out );
	}

	public function test_render_page_hands_a_non_edit_action_to_the_dispatcher(): void {
		// With the delete cap the dispatcher acts on the action: it sends the
		// request back to the action's own tab before any tab renders.
		$this->granted = array( 'ffc_view_recruitment', 'ffc_delete_recruitment' );
		$_GET          = array( 'action' => 'delete-adjutancy' );

		try {
			$this->render();
			$this->fail( 'The dispatcher should have redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame(
			array( 'https://example.com/wp-admin/admin.php?page=ffc-recruitment&tab=adjutancies' ),
			$this->redirects
		);
	}

	public function test_render_page_defaults_to_notices_tab(): void {
		$this->granted = array( 'ffc_view_recruitment' );
		$_GET          = array();

		$this->assertNoticesTab( $this->render() );
	}

	public function test_render_page_renders_adjutancies_and_candidates_tabs(): void {
		$this->granted = array( 'ffc_view_recruitment' );

		$_GET = array( 'tab' => 'adjutancies' );
		$out  = $this->render();
		$this->assertStringContainsString( $this->tab_heading( 'ffc-icon-building', 'Adjutancies' ), $out );

		$_GET = array( 'tab' => 'candidates' );
		$out  = $this->render();
		$this->assertStringContainsString( $this->tab_heading( 'ffc-icon-id', 'Candidates' ), $out );
	}

	public function test_render_page_settings_tab_needs_settings_view_cap(): void {
		// Holds the page view cap but NOT the settings view cap → the router
		// downgrades the settings tab back to notices.
		$this->granted = array( 'ffc_view_recruitment' );
		$_GET          = array( 'tab' => 'settings' );

		$out = $this->render();

		$this->assertNoticesTab( $out );
		$this->assertStringNotContainsString( $this->tab_heading( 'ffc-icon-settings', 'Settings' ), $out );
	}

	public function test_render_page_settings_tab_renders_with_settings_view_cap(): void {
		$this->granted = array( 'ffc_view_recruitment', 'ffc_view_recruitment_settings' );
		$_GET          = array( 'tab' => 'settings' );

		$out = $this->render();

		$this->assertStringContainsString( 'id="ffc-recruitment-tabpanel-settings"', $out );
		$this->assertStringContainsString( $this->tab_heading( 'ffc-icon-settings', 'Settings' ), $out );
	}

	public function test_render_page_reasons_tab_needs_reasons_cap(): void {
		$this->granted = array( 'ffc_view_recruitment' ); // no reasons cap
		$_GET          = array( 'tab' => 'reasons' );

		$out = $this->render();

		$this->assertNoticesTab( $out );
		$this->assertStringNotContainsString( $this->tab_heading( 'ffc-icon-clipboard', 'Reasons' ), $out );
	}

	public function test_render_page_reasons_tab_renders_with_reasons_view_cap(): void {
		$this->granted = array( 'ffc_view_recruitment', 'ffc_view_recruitment_reasons' );
		$_GET          = array( 'tab' => 'reasons' );

		$out = $this->render();

		$this->assertStringContainsString( $this->tab_heading( 'ffc-icon-clipboard', 'Reasons' ), $out );
	}

	public function test_render_page_unknown_tab_falls_back_to_notices(): void {
		$this->granted = array( 'ffc_view_recruitment' );
		$_GET          = array( 'tab' => 'bogus' );

		$this->assertNoticesTab( $this->render() );
	}

	// ------------------------------------------------------------------
	// 3-state cap helpers
	// ------------------------------------------------------------------

	public function test_can_view_settings_true_for_view_or_manage(): void {
		$this->granted = array( 'ffc_view_recruitment_settings' );
		$this->assertTrue( RecruitmentAdminPage::can_view_settings() );

		$this->granted = array( 'ffc_manage_recruitment_settings' );
		$this->assertTrue( RecruitmentAdminPage::can_view_settings() );

		$this->granted = array();
		$this->assertFalse( RecruitmentAdminPage::can_view_settings() );
	}

	public function test_can_edit_settings_needs_manage_cap(): void {
		$this->granted = array( 'ffc_manage_recruitment_settings' );
		$this->assertTrue( RecruitmentAdminPage::can_edit_settings() );

		$this->granted = array( 'ffc_view_recruitment_settings' );
		$this->assertFalse( RecruitmentAdminPage::can_edit_settings() );
	}

	public function test_can_view_reasons_true_for_view_or_manage(): void {
		$this->granted = array( 'ffc_view_recruitment_reasons' );
		$this->assertTrue( RecruitmentAdminPage::can_view_reasons() );

		$this->granted = array( 'ffc_manage_recruitment_reasons' );
		$this->assertTrue( RecruitmentAdminPage::can_view_reasons() );

		$this->granted = array();
		$this->assertFalse( RecruitmentAdminPage::can_view_reasons() );
	}

	public function test_can_edit_reasons_needs_manage_cap(): void {
		$this->granted = array( 'ffc_manage_recruitment_reasons' );
		$this->assertTrue( RecruitmentAdminPage::can_edit_reasons() );

		$this->granted = array( 'ffc_view_recruitment_reasons' );
		$this->assertFalse( RecruitmentAdminPage::can_edit_reasons() );
	}

	// ------------------------------------------------------------------
	// register_menu()
	// ------------------------------------------------------------------

	public function test_register_menu_registers_top_level_and_per_tab_submenus(): void {
		$menuCalls    = 0;
		$submenuCalls = 0;
		$filterAdded  = false;

		Functions\when( 'add_menu_page' )->alias(
			function () use ( &$menuCalls ) {
				++$menuCalls;
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$submenuCalls ) {
				++$submenuCalls;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function () use ( &$filterAdded ) {
				$filterAdded = true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		global $submenu;
		$submenu = array( RecruitmentAdminPage::PAGE_SLUG => array( array( 'auto-generated' ) ) );

		RecruitmentAdminPage::register_menu();

		$this->assertSame( 1, $menuCalls, 'one top-level menu' );
		$this->assertSame( 5, $submenuCalls, 'five per-tab submenus' );
		$this->assertTrue( $filterAdded, 'submenu_file highlight filter wired' );
		// The auto-generated duplicate first row was cleared.
		$this->assertSame( array(), $submenu[ RecruitmentAdminPage::PAGE_SLUG ] );
	}

	/**
	 * The capability each menu entry is registered with, for a user holding `$caps`.
	 *
	 * @param list<string> $caps Capabilities `current_user_can()` grants.
	 * @return array<string, string> Menu slug => capability.
	 */
	private function menu_caps_for( array $caps ): array {
		$registered = array();
		Functions\when( 'current_user_can' )->alias( static fn( $cap ): bool => in_array( $cap, $caps, true ) );
		Functions\when( 'add_menu_page' )->alias(
			static function ( $page_title, $menu_title, $cap, $slug ) use ( &$registered ): void {
				$registered[ 'menu:' . $slug ] = $cap;
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent, $page_title, $menu_title, $cap, $slug ) use ( &$registered ): void {
				$registered[ $slug ] = $cap;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		RecruitmentAdminPage::register_menu();
		return $registered;
	}

	public function test_register_menu_names_the_manage_cap_for_a_manage_only_user(): void {
		// 3-state (#1782): WordPress gates a menu on one capability, so a user
		// holding manage without view gets the menu registered under manage.
		$caps = $this->menu_caps_for( array( 'ffc_manage_recruitment' ) );

		$this->assertSame( 'ffc_manage_recruitment', $caps[ 'menu:' . RecruitmentAdminPage::PAGE_SLUG ] );
		$this->assertSame( 'ffc_manage_recruitment', $caps[ RecruitmentAdminPage::PAGE_SLUG ] );
		$this->assertSame( 'ffc_manage_recruitment', $caps[ RecruitmentAdminPage::PAGE_SLUG . '&tab=candidates' ] );
		$this->assertSame( 'ffc_view_recruitment_settings', $caps[ RecruitmentAdminPage::PAGE_SLUG . '&tab=settings' ] );
	}

	public function test_register_menu_names_the_view_cap_whenever_the_user_holds_it(): void {
		foreach ( array( array(), array( 'ffc_view_recruitment' ), array( 'ffc_view_recruitment', 'ffc_manage_recruitment' ) ) as $held ) {
			$caps = $this->menu_caps_for( $held );
			$this->assertSame( 'ffc_view_recruitment', $caps[ 'menu:' . RecruitmentAdminPage::PAGE_SLUG ] );
			$this->assertSame( 'ffc_view_recruitment', $caps[ RecruitmentAdminPage::PAGE_SLUG ] );
		}
	}

	public function test_register_menu_names_the_settings_manage_cap_for_a_settings_manage_only_user(): void {
		$caps = $this->menu_caps_for( array( 'ffc_view_recruitment', 'ffc_manage_recruitment_settings' ) );
		$this->assertSame( 'ffc_manage_recruitment_settings', $caps[ RecruitmentAdminPage::PAGE_SLUG . '&tab=settings' ] );

		$caps = $this->menu_caps_for( array( 'ffc_view_recruitment', 'ffc_view_recruitment_settings', 'ffc_manage_recruitment_settings' ) );
		$this->assertSame( 'ffc_view_recruitment_settings', $caps[ RecruitmentAdminPage::PAGE_SLUG . '&tab=settings' ] );
	}
}
