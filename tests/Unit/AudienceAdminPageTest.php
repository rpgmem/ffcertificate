<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminPage;

/**
 * Tests for AudienceAdminPage: menu registration and order, the legacy
 * redirects, and form submission delegation.
 */
class AudienceAdminPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// MENU_SLUG constant
	// ==================================================================

	public function test_menu_slug_constant_is_ffc_scheduling(): void {
		$this->assertSame( 'ffc-scheduling', AudienceAdminPage::MENU_SLUG );
	}

	// ==================================================================
	// init() registers hooks
	// ==================================================================

	public function test_init_registers_admin_menu_hook(): void {
		$page = new AudienceAdminPage();

		$page->init();

		$this->assertTrue(
			has_action( 'admin_menu', 'FreeFormCertificate\Audience\AudienceAdminPage->add_admin_menus()' ) !== false,
			'init() should register add_admin_menus on admin_menu hook'
		);
	}

	public function test_init_registers_admin_init_hook(): void {
		$page = new AudienceAdminPage();

		$page->init();

		$this->assertTrue(
			has_action( 'admin_init', 'FreeFormCertificate\Audience\AudienceAdminPage->handle_form_submissions()' ) !== false,
			'init() should register handle_form_submissions on admin_init hook'
		);
	}

	public function test_init_registers_no_menu_css(): void {
		$page = new AudienceAdminPage();

		$page->init();

		// The separators and their inline icon-font CSS left with #1761.
		$this->assertFalse( has_action( 'admin_enqueue_scripts', 'FreeFormCertificate\Audience\AudienceAdminPage->print_menu_separator_css()' ) );
		$this->assertFalse( method_exists( $page, 'print_menu_separator_css' ) );
	}

	public function test_init_registers_menu_order_hook(): void {
		$page = new AudienceAdminPage();

		$page->init();

		$this->assertSame( 99, has_action( 'admin_menu', 'FreeFormCertificate\Audience\AudienceAdminPage->order_menu()' ) );
	}

	public function test_init_registers_the_environments_redirect(): void {
		$page = new AudienceAdminPage();

		$page->init();

		$this->assertNotFalse( has_action( 'admin_init', 'FreeFormCertificate\Audience\AudienceAdminPage->redirect_legacy_environments_url()' ) );
	}

	// ==================================================================
	// add_admin_menus() calls add_menu_page and add_submenu_page
	// ==================================================================

	public function test_add_admin_menus_registers_main_menu_page(): void {
		$page = new AudienceAdminPage();

		$page->init();

		$menu_pages = [];
		Functions\when( 'add_menu_page' )->alias( function () use ( &$menu_pages ) {
			$menu_pages[] = func_get_args();
		} );
		$submenu_pages = [];
		Functions\when( 'add_submenu_page' )->alias( function () use ( &$submenu_pages ) {
			$submenu_pages[] = func_get_args();
		} );

		// The Settings submenu asks who is reading (#2244): an audience viewer.
		Functions\when( 'current_user_can' )->justReturn( true );
		$page->add_admin_menus();

		$this->assertCount( 1, $menu_pages, 'Should register exactly 1 top-level menu page' );
		// add_menu_page args: page_title, menu_title, capability, menu_slug, callback, icon_url, position
		$this->assertSame( 'ffc-scheduling', $menu_pages[0][3], 'Top-level menu slug should be ffc-scheduling' );
	}

	public function test_add_admin_menus_registers_all_submenu_pages(): void {
		$page = new AudienceAdminPage();

		$page->init();

		Functions\when( 'add_menu_page' )->justReturn( '' );
		$submenu_pages = [];
		Functions\when( 'add_submenu_page' )->alias( function () use ( &$submenu_pages ) {
			$submenu_pages[] = func_get_args();
		} );

		// The Settings submenu asks who is reading (#2244): an audience viewer.
		Functions\when( 'current_user_can' )->justReturn( true );
		$page->add_admin_menus();

		// Should register: dashboard, calendars, environments (hidden),
		// audiences, bookings, attendance (hidden, #1947), settings = 7.
		// Import & Export is now the 4th tab of Settings, not a separate
		// submenu entry.
		$this->assertCount( 7, $submenu_pages, 'Should register 7 submenu pages' );

		// add_submenu_page args: parent_slug, page_title, menu_title, capability, menu_slug, callback
		$submenu_slugs = array_column( $submenu_pages, 4 );
		$this->assertContains( 'ffc-scheduling-dashboard', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-calendars', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-environments', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-audiences', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-bookings', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-attendance', $submenu_slugs );
		$this->assertContains( 'ffc-scheduling-settings', $submenu_slugs );
		$this->assertNotContains( 'ffc-scheduling-import', $submenu_slugs );
	}

	public function test_the_settings_submenu_opens_to_a_personal_calendars_setup_holder(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_calendars' === $cap );
		$page = new AudienceAdminPage();
		$page->init();

		Functions\when( 'add_menu_page' )->justReturn( '' );
		$caps = array();
		Functions\when( 'add_submenu_page' )->alias( function () use ( &$caps ) {
			$args              = func_get_args();
			$caps[ $args[4] ] = $args[3];
		} );

		$page->add_admin_menus();

		$this->assertSame( 'ffc_manage_calendars', $caps['ffc-scheduling-settings'] );
		$this->assertSame( 'ffc_view_audiences', $caps['ffc-scheduling-audiences'], 'Only Settings opens to them.' );
	}

	public function test_every_remaining_slug_keeps_its_capability_and_environments_is_hidden(): void {
		$page = new AudienceAdminPage();
		$page->init();

		Functions\when( 'add_menu_page' )->justReturn( '' );
		$submenu_pages = array();
		Functions\when( 'add_submenu_page' )->alias( function () use ( &$submenu_pages ) {
			$args                       = func_get_args();
			$submenu_pages[ $args[4] ] = array( 'parent' => $args[0], 'cap' => $args[3], 'callback' => $args[5] );
		} );

		// The Settings submenu asks who is reading (#2244): an audience viewer.
		Functions\when( 'current_user_can' )->justReturn( true );
		$page->add_admin_menus();

		foreach ( array( 'dashboard', 'calendars', 'environments', 'audiences', 'bookings', 'settings' ) as $suffix ) {
			$this->assertSame( 'ffc_view_audiences', $submenu_pages[ 'ffc-scheduling-' . $suffix ]['cap'], $suffix );
		}
		foreach ( array( 'dashboard', 'calendars', 'audiences', 'bookings', 'settings' ) as $suffix ) {
			$this->assertSame( 'ffc-scheduling', $submenu_pages[ 'ffc-scheduling-' . $suffix ]['parent'], $suffix );
		}
		// No parent: a page WordPress still routes and gates, with no menu row (#1761).
		$this->assertSame( '', $submenu_pages['ffc-scheduling-environments']['parent'] );
		// It renders the Audience Calendars page, which shows the Environments view.
		$this->assertSame( 'render_page', $submenu_pages['ffc-scheduling-environments']['callback'][1] );
		$this->assertSame( $submenu_pages['ffc-scheduling-calendars']['callback'], $submenu_pages['ffc-scheduling-environments']['callback'] );
	}

	// ==================================================================
	// order_menu()
	// ==================================================================

	public function test_order_menu_does_nothing_when_submenu_missing(): void {
		global $submenu;
		$submenu = array();

		$page = new AudienceAdminPage();

		// Should not throw
		$page->order_menu();

		$this->assertArrayNotHasKey( 'ffc-scheduling', $submenu );
	}

	public function test_order_menu_is_seven_rows_with_no_separators(): void {
		global $submenu;
		$submenu = array();
		// As WordPress leaves it: the parent duplicate first, the CPT list,
		// then the pages in registration order (Appointments at priority 25).
		$submenu['ffc-scheduling'] = array(
			array( 'Scheduling', 'ffc_view_audiences', 'ffc-scheduling' ),
			array( 'Personal Calendars', 'edit_posts', 'edit.php?post_type=ffc_self_scheduling' ),
			array( 'New Personal Calendar', 'edit_posts', 'post-new.php?post_type=ffc_self_scheduling' ),
			array( 'Dashboard', 'ffc_view_audiences', 'ffc-scheduling-dashboard' ),
			array( 'Audience Calendars', 'ffc_view_audiences', 'ffc-scheduling-calendars' ),
			array( 'Audiences', 'ffc_view_audiences', 'ffc-scheduling-audiences' ),
			array( 'Audience Bookings', 'ffc_view_audiences', 'ffc-scheduling-bookings' ),
			array( 'Settings', 'ffc_view_audiences', 'ffc-scheduling-settings' ),
			array( 'Appointments', 'ffc_view_appointments', 'ffc-appointments' ),
		);

		( new AudienceAdminPage() )->order_menu();

		$this->assertSame(
			array(
				'ffc-scheduling-dashboard',
				'edit.php?post_type=ffc_self_scheduling',
				'ffc-appointments',
				'ffc-scheduling-calendars',
				'ffc-scheduling-audiences',
				'ffc-scheduling-bookings',
				'ffc-scheduling-settings',
			),
			array_column( $submenu['ffc-scheduling'], 2 )
		);
		// Each row keeps the capability it was registered with.
		$this->assertSame( 'ffc_view_appointments', $submenu['ffc-scheduling'][2][1] );
		$this->assertSame( 'edit_posts', $submenu['ffc-scheduling'][1][1] );
	}

	public function test_order_menu_keeps_an_unknown_row_at_the_end(): void {
		global $submenu;
		$submenu = array();
		$submenu['ffc-scheduling'] = array(
			array( 'Settings', 'ffc_view_audiences', 'ffc-scheduling-settings' ),
			array( 'Extension', 'manage_options', 'third-party-page' ),
			array( 'Dashboard', 'ffc_view_audiences', 'ffc-scheduling-dashboard' ),
		);

		( new AudienceAdminPage() )->order_menu();

		$this->assertSame( array( 'ffc-scheduling-dashboard', 'ffc-scheduling-settings', 'third-party-page' ), array_column( $submenu['ffc-scheduling'], 2 ) );
	}

	// ==================================================================
	// redirect_legacy_environments_url()
	// ==================================================================

	/**
	 * Run the redirect with `$_GET` set, returning [ url, status ] or null.
	 *
	 * @param array<string, mixed> $get  The query.
	 * @param array<string, mixed> $post The body.
	 * @return array{0: string, 1: int}|null
	 */
	private function environments_redirect( array $get, array $post = array() ): ?array {
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				$pairs = array();
				foreach ( $args as $key => $value ) {
					$pairs[] = $key . '=' . $value;
				}
				return $url . '?' . implode( '&', $pairs );
			}
		);
		$redirect = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url, $status ) use ( &$redirect ) {
				$redirect = array( $url, $status );
				throw new \RuntimeException( 'exit' );
			}
		);

		$_GET  = $get;
		$_POST = $post;
		try {
			( new AudienceAdminPage() )->redirect_legacy_environments_url();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'exit', $e->getMessage() );
		} finally {
			$_GET  = array();
			$_POST = array();
		}
		return $redirect;
	}

	public function test_environments_slug_redirects_to_the_view(): void {
		$this->assertSame(
			array( 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-calendars&view=environments', 301 ),
			$this->environments_redirect( array( 'page' => 'ffc-scheduling-environments' ) )
		);
	}

	public function test_environments_redirect_carries_every_query_arg(): void {
		$redirect = $this->environments_redirect(
			array(
				'page'        => 'ffc-scheduling-environments',
				'action'      => 'delete',
				'id'          => '9',
				'schedule_id' => '4',
				'message'     => 'created',
				'_wpnonce'    => 'abc123',
				'paged'       => '2',
			)
		);

		$this->assertNotNull( $redirect );
		$this->assertSame( 301, $redirect[1] );
		$query = (string) parse_url( $redirect[0], PHP_URL_QUERY );
		parse_str( $query, $args );
		$this->assertSame(
			array(
				'page'        => 'ffc-scheduling-calendars',
				'action'      => 'delete',
				'id'          => '9',
				'schedule_id' => '4',
				'message'     => 'created',
				'_wpnonce'    => 'abc123',
				'paged'       => '2',
				'view'        => 'environments',
			),
			$args
		);
	}

	public function test_environments_redirect_encodes_values(): void {
		$redirect = $this->environments_redirect(
			array(
				'page' => 'ffc-scheduling-environments',
				's'    => 'a&b=c',
			)
		);
		$this->assertNotNull( $redirect );
		$this->assertStringContainsString( 's=a%26b%3Dc', $redirect[0] );
	}

	public function test_environments_redirect_leaves_a_post_where_it_landed(): void {
		// A form opened before the move posts to the old slug: a redirect
		// would drop its body, so the save is handled there.
		$this->assertNull(
			$this->environments_redirect(
				array( 'page' => 'ffc-scheduling-environments', 'action' => 'edit', 'id' => '5' ),
				array( 'ffc_action' => 'save_environment' )
			)
		);
	}

	public function test_environments_redirect_skips_other_pages(): void {
		$this->assertNull( $this->environments_redirect( array( 'page' => 'ffc-scheduling-calendars', 'view' => 'environments' ) ) );
		$this->assertNull( $this->environments_redirect( array() ) );
	}

	// ==================================================================
	// redirect_legacy_import_url()
	// ==================================================================

	public function test_redirect_legacy_import_url_skips_unrelated_pages(): void {
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET['page'] = 'some-other-page';
		$redirected   = false;
		Functions\when( 'wp_safe_redirect' )->alias( function () use ( &$redirected ) {
			$redirected = true;
			return true;
		} );

		( new AudienceAdminPage() )->redirect_legacy_import_url();
		$this->assertFalse( $redirected );
		unset( $_GET['page'] );
	}

	public function test_redirect_legacy_import_url_skips_when_page_param_absent(): void {
		unset( $_GET['page'] );
		$redirected = false;
		Functions\when( 'wp_safe_redirect' )->alias( function () use ( &$redirected ) {
			$redirected = true;
			return true;
		} );

		( new AudienceAdminPage() )->redirect_legacy_import_url();
		$this->assertFalse( $redirected );
	}

	public function test_redirect_legacy_import_url_keeps_the_query_args(): void {
		// The old Import page's "Download Sample" link carried the request in
		// its query string; a redirect that rebuilt the URL from `page` and
		// `tab` alone dropped `download_sample` and `_wpnonce`, so the sample
		// was never sent (#1771).
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = $k . '=' . $v;
				}
				return $url . '?' . implode( '&', $pairs );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url, $status ) {
				throw new \RuntimeException( $status . ' ' . $url );
			}
		);
		$_GET = array(
			'page'            => 'ffc-scheduling-import',
			'download_sample' => 'members',
			'_wpnonce'        => 'abc123',
		);

		$location = '';
		try {
			( new AudienceAdminPage() )->redirect_legacy_import_url();
		} catch ( \RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			$_GET = array();
		}

		$this->assertStringStartsWith( '301 https://example.com/wp-admin/admin.php?', $location );
		$this->assertStringContainsString( 'page=ffc-scheduling-settings', $location );
		$this->assertStringContainsString( 'tab=import', $location );
		$this->assertStringContainsString( 'download_sample=members', $location );
		$this->assertStringContainsString( '_wpnonce=abc123', $location );
		$this->assertStringNotContainsString( 'ffc-scheduling-import', $location );
	}

	// ==================================================================
	// handle_form_submissions() skips non-scheduling pages
	// ==================================================================

	public function test_handle_form_submissions_skips_non_scheduling_page(): void {
		$_GET['page'] = 'some-other-page';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// #1030: "nothing should be delegated" was a comment, not a check.
		// Every delegate begins by asking Capabilities whether the user may
		// act, so a capability never consulted is the proof the page guard
		// returned before delegating to any of the six.
		// Asserted through current_user_can(), which Capabilities delegates to,
		// rather than by alias-mocking Capabilities: that class is already
		// autoloaded by the time this test runs and Mockery cannot alias it.
		Functions\expect( 'current_user_can' )->never();

		$page = new AudienceAdminPage();

		$page->init();
		$page->handle_form_submissions();

		unset( $_GET['page'] );
	}

}
