<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Shortcodes\DashboardShortcode;

/**
 * @covers \FreeFormCertificate\Shortcodes\DashboardShortcode
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DashboardShortcodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( function ( $s ) { echo $s; } );
		Functions\when( 'esc_html_e' )->alias( function ( $s ) { echo $s; } );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.com/login' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );

		// Mock $wpdb for ReregistrationSubmissionRepository
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();

		// Namespaced stubs
		Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_get' )->justReturn( false );
		Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_set' )->justReturn( true );
		Functions\when( 'FreeFormCertificate\Audience\wp_cache_get' )->justReturn( false );
		Functions\when( 'FreeFormCertificate\Audience\wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_shortcode_and_action(): void {
		// #1030: "no exception = hooks registered" registered nothing of the
		// sort. An init() that wired neither would have passed.
		$shortcodes = array();
		$actions    = array();
		Functions\when( 'add_shortcode' )->alias(
			static function ( $tag ) use ( &$shortcodes ) {
				$shortcodes[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$actions ) {
				$actions[] = $hook;
				return true;
			}
		);

		DashboardShortcode::init();

		$this->assertContains( 'user_dashboard_personal', $shortcodes );
		$this->assertContains( 'template_redirect', $actions );
	}

	// ==================================================================
	// render() — not logged in
	// ==================================================================

	public function test_render_shows_login_when_not_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$asset_helper = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
		$asset_helper->shouldReceive( 'asset_suffix' )->andReturn( '' );
		$asset_helper->shouldReceive( 'enqueue_dark_mode' )->once();
		$styles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$styles ) {
				$styles[] = $handle;
			}
		);

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'logged in', $output );
		$this->assertStringContainsString( 'Login', $output );
		// #1832: the notice is a plugin root and loads the sheets that style it.
		$this->assertMatchesRegularExpression( '#<div class="ffc-shortcode">\s*<div class="ffc-notice ffc-notice-warning"#', $output );
		$this->assertStringContainsString( 'data-ffc-notice="login-required"', $output );
		$this->assertStringContainsString( '<div class="ffc-notice-actions"><a href=', $output );
		$this->assertSame( array( 'ffc-common', 'ffc-dashboard' ), $styles );
	}

	// ==================================================================
	// render() — logged in, shows dashboard
	// ==================================================================

	public function test_render_shows_dashboard_when_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$user = Mockery::mock( 'WP_User' );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'user_can' )->justReturn( false );

		$utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
		$ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri_mock->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
		$utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();
		$ri_mock->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
		} )->byDefault();

		Functions\when( 'wp_enqueue_style' )->justReturn();
		Functions\when( 'wp_enqueue_script' )->justReturn();
		Functions\when( 'wp_localize_script' )->justReturn();
		Functions\when( 'admin_url' )->justReturn( '' );
		Functions\when( 'rest_url' )->justReturn( '' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'wp_logout_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

		// DashboardViewMode returns false
		Functions\when( 'current_user_can' )->justReturn( false );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'ffc-user-dashboard', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-profile', $output );
		$this->assertStringContainsString( 'Profile', $output );
	}

	// ==================================================================
	// send_nocache_headers() — no post object (null)
	// ==================================================================

	public function test_send_nocache_headers_noop_when_no_post(): void {
		global $post;
		$post = null;

		// Past both guards the method calls nocache_headers(); it never running
		// is the proof the guard returned.
		Functions\expect( 'nocache_headers' )->never();

		// is_a(null, 'WP_Post') returns false — no mock needed
		DashboardShortcode::send_nocache_headers();
	}

	// ==================================================================
	// send_nocache_headers() — post without shortcode
	// ==================================================================

	public function test_send_nocache_headers_noop_when_no_shortcode(): void {
		global $post;
		// Use a real WP_Post mock so is_a() returns true
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = 'Hello world';

		Functions\when( 'has_shortcode' )->justReturn( false );

		// A real WP_Post is present, so the shortcode check is the only thing
		// that can stop it — and nocache_headers() is what it stops.
		Functions\expect( 'nocache_headers' )->never();

		DashboardShortcode::send_nocache_headers();
	}

	// ==================================================================
	// render() — redirect message shown
	// ==================================================================

	public function test_render_shows_redirect_message_when_param_set(): void {
		$_GET['ffc_redirect'] = 'access_denied';

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$user = Mockery::mock( 'WP_User' );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'user_can' )->justReturn( false );

		$utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
		$utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
		$utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();
		$ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri_mock->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri_mock->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$ri_mock->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
		} )->byDefault();

		Functions\when( 'wp_enqueue_style' )->justReturn();
		Functions\when( 'wp_enqueue_script' )->justReturn();
		Functions\when( 'wp_localize_script' )->justReturn();
		Functions\when( 'admin_url' )->justReturn( '' );
		Functions\when( 'rest_url' )->justReturn( '' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'wp_logout_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( '' );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
		Functions\when( 'current_user_can' )->justReturn( false );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'redirected from the admin panel', $output );
		$this->assertStringContainsString( 'data-ffc-notice="admin-redirect"', $output );
	}

	// ==================================================================
	// send_nocache_headers() — shortcode present → cache-busting fires
	// ==================================================================

	public function test_send_nocache_headers_sets_donotcachepage_when_shortcode_present(): void {
		global $post;
		$post = Mockery::mock( 'WP_Post' );
		$post->post_content = '[user_dashboard_personal]';

		Functions\when( 'has_shortcode' )->justReturn( true );

		$nocache_called = false;
		Functions\when( 'nocache_headers' )->alias( function () use ( &$nocache_called ) {
			$nocache_called = true;
		} );
		$action_fired = false;
		Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$action_fired ) {
			if ( 'litespeed_control_set_nocache' === $hook ) {
				$action_fired = true;
			}
		} );
		// header() is a native PHP function Brain\Monkey cannot redefine. Under
		// CLI, output has already been emitted, so the real call would raise a
		// "headers already sent" warning. Suppress it — the assertions below
		// verify the observable side effects (DONOTCACHEPAGE, nocache_headers,
		// the LiteSpeed action) rather than the raw header() call.
		$prev = error_reporting( error_reporting() & ~E_WARNING );
		try {
			DashboardShortcode::send_nocache_headers();
		} finally {
			error_reporting( $prev );
		}

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( $nocache_called );
		$this->assertTrue( $action_fired );
	}

	// ==================================================================
	// render() — all capability tabs visible (full render path)
	// ==================================================================

	/**
	 * Wire up every collaborator the full render path touches so all tab
	 * branches are exercised. Alias-mocks the shortcode's direct collaborators.
	 */
	private function wireFullRenderCollaborators( bool $view_as = false, ?array $reregistrations = null ): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$user = Mockery::mock( 'WP_User' );
		Functions\when( 'get_user_by' )->justReturn( $user );
		// Every capability check returns true so all cap-gated tabs render.
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		// The tab bar prints the Log Out link (#1840).
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_logout_url' )->alias( static fn( $redirect = '' ) => 'https://example.com/wp-login.php?action=logout&redirect_to=' . rawurlencode( $redirect ) . '&_wpnonce=abc' );

		$view_mode = Mockery::mock( 'alias:\FreeFormCertificate\Shortcodes\DashboardViewMode' );
		$view_mode->shouldReceive( 'get_view_as_user_id' )->andReturn( $view_as ? 55 : false )->byDefault();
		$view_mode->shouldReceive( 'render_admin_viewing_banner' )->andReturn( '<div class="ffc-view-as-banner">viewing</div>' )->byDefault();

		$asset_mgr = Mockery::mock( 'alias:\FreeFormCertificate\Shortcodes\DashboardAssetManager' );
		$asset_mgr->shouldReceive( 'enqueue_assets' )->andReturn( null )->byDefault();
		$asset_mgr->shouldReceive( 'user_has_audience_groups' )->andReturn( true )->byDefault();

		$reader = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
		$reader->shouldReceive( 'get_all_by_user' )->andReturn( array( array( 'id' => 1 ) ) )->byDefault();

		$recruitment = Mockery::mock( 'alias:\FreeFormCertificate\Recruitment\RecruitmentDashboardSection' );
		$recruitment->shouldReceive( 'render_for_user' )->andReturn( '<div class="ffc-recruitment-body">calls</div>' )->byDefault();

		// Reregistration banners: empty unless the caller supplies a set.
		$frontend = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationFrontend' );
		$frontend->shouldReceive( 'get_user_reregistrations' )
			->andReturn( null === $reregistrations ? array() : $reregistrations )->byDefault();

		// The approved banner's window is counted on the site clock (#1887).
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'America/Sao_Paulo' ) );

		// DateFormatter is only reached by the actionable-banner branch, but
		// alias-mock it unconditionally so it's stubbed whenever banners render.
		$fmt = Mockery::mock( 'alias:\FreeFormCertificate\Core\DateFormatter' );
		$fmt->shouldReceive( 'format_date' )->andReturn( '01/01/2030' )->byDefault();

		$ri = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
		$ri->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );
		$ri->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		$ri->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$ri->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
		} )->byDefault();
	}

	public function test_render_all_tabs_visible_when_all_caps_granted(): void {
		$this->wireFullRenderCollaborators( false );

		$output = DashboardShortcode::render();

		// Every cap-gated tab button + panel present.
		$this->assertStringContainsString( 'ffc-tab-certificates', $output );
		$this->assertStringContainsString( 'ffc-tab-appointments', $output );
		$this->assertStringContainsString( 'ffc-tab-audience', $output );
		$this->assertStringContainsString( 'ffc-tab-reregistrations', $output );
		$this->assertStringContainsString( 'ffc-tab-recruitment', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-certificates', $output );
		$this->assertStringContainsString( 'ffc-tabpanel-recruitment', $output );
		// Server-rendered recruitment body reused as tab content.
		$this->assertStringContainsString( 'ffc-recruitment-body', $output );
		// Reregistration form panel present when reregistrations tab visible.
		$this->assertStringContainsString( 'ffc-rereg-form-panel', $output );
	}

	/**
	 * Log Out sits in the tab bar, after the tablist and outside it, so it
	 * shows on every tab without being announced as a tab (#1840).
	 */
	public function test_render_prints_log_out_in_the_tab_bar_outside_the_tablist(): void {
		$this->wireFullRenderCollaborators( false );
		$escaped = array();
		Functions\when( 'esc_url' )->alias( static function ( $url ) use ( &$escaped ) {
			$escaped[] = $url;
			return str_replace( '&', '&#038;', $url );
		} );

		$output = DashboardShortcode::render();

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $output );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$links = $xpath->query( '//a[contains(concat(" ", normalize-space(@class), " "), " ffc-logout-link ")]' );
		$this->assertSame( 1, $links->length, 'Exactly one Log Out link is printed.' );
		$link = $links->item( 0 );
		$this->assertSame( 'https://example.com/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fexample.com&_wpnonce=abc', $link->getAttribute( 'href' ) );
		$this->assertContains( 'https://example.com/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fexample.com&_wpnonce=abc', $escaped, 'The URL goes through esc_url().' );
		$this->assertStringContainsString( 'action=logout&#038;redirect_to=', $output, 'The printed attribute is the escaped URL.' );
		$this->assertStringContainsString( 'Log Out', $link->textContent );
		$this->assertSame( 1, $xpath->query( './/span[contains(@class, "ffc-icon-logout")]', $link )->length );

		$parent = $link->parentNode;
		$this->assertInstanceOf( \DOMElement::class, $parent );
		$this->assertStringContainsString( 'ffc-dashboard-tabbar', $parent->getAttribute( 'class' ) );
		$this->assertSame( 0, $xpath->query( 'ancestor::*[@role="tablist"]', $link )->length, 'The link is not inside the tablist.' );
		$this->assertSame( 1, $xpath->query( '../nav[@role="tablist"]', $link )->length, 'The tablist is its sibling in the bar.' );
	}

	// ==================================================================
	// render() — admin view-as mode renders the banner
	// ==================================================================

	public function test_render_shows_admin_viewing_banner_in_view_as_mode(): void {
		// view_as user 55 differs from current user 1 → banner renders.
		$this->wireFullRenderCollaborators( true );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'ffc-view-as-banner', $output );
	}

	// ==================================================================
	// render() — reregistration banners (all status branches)
	// ==================================================================

	public function test_render_reregistration_banners_all_statuses(): void {
		Functions\when( '_n' )->alias( function ( $single, $plural, $n ) {
			return 1 === $n ? $single : $plural;
		} );

		$this->wireFullRenderCollaborators( false, array(
			// Completed / approved (can_submit false).
			array(
				'id'                => 10,
				'title'             => 'Approved Campaign',
				'can_submit'        => false,
				'submission_status' => 'approved',
				'magic_link'        => 'https://example.com/record/10',
				'end_date'          => '2030-01-01',
			),
			// Submitted / pending review (can_submit false, no magic link).
			array(
				'id'                => 11,
				'title'             => 'Submitted Campaign',
				'can_submit'        => false,
				'submission_status' => 'submitted',
				'magic_link'        => '',
				'end_date'          => '2030-01-01',
			),
			// Actionable — in_progress (can_submit true), far deadline.
			array(
				'id'                => 12,
				'title'             => 'In Progress Campaign',
				'can_submit'        => true,
				'submission_status' => 'in_progress',
				'end_date'          => '2030-01-01',
			),
			// Actionable — rejected (can_submit true), urgent (deadline soon).
			array(
				'id'                => 13,
				'title'             => 'Rejected Campaign',
				'can_submit'        => true,
				'submission_status' => 'rejected',
				'end_date'          => gmdate( 'Y-m-d', time() + 86400 ),
			),
			// Actionable — not started (can_submit true), else branch.
			array(
				'id'                => 14,
				'title'             => 'New Campaign',
				'can_submit'        => true,
				'submission_status' => 'not_started',
				'end_date'          => gmdate( 'Y-m-d', time() + ( 5 * 86400 ) ),
			),
		) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Approved Campaign', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-success"', $output );
		$this->assertStringContainsString( 'Submitted Campaign', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-info"', $output );
		$this->assertStringContainsString( 'In Progress Campaign', $output );
		$this->assertStringContainsString( 'Continue Reregistration', $output );
		$this->assertStringContainsString( 'Rejected Campaign', $output );
		$this->assertStringContainsString( 'Resubmit Reregistration', $output );
		// The near-deadline rejected banner takes the danger tone; the far
		// ones keep the warning tone, and each carries its campaign id.
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-danger" role="note" data-ffc-notice="rereg-banner" data-reregistration-id="13"', $output );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-warning" role="note" data-ffc-notice="rereg-banner" data-reregistration-id="12"', $output );
		$this->assertStringContainsString( 'New Campaign', $output );
		$this->assertStringContainsString( 'Complete Reregistration', $output );
	}

	// ==================================================================
	// render() — the approved banner's window and its button (#1887)
	// ==================================================================

	/**
	 * Two rows of a campaign that ended on 2020-01-01: one approved the day
	 * after, one still awaiting review. The margins are years wide, so the
	 * real clock the renderer reads cannot straddle the 30-day cut-off.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function endedCampaignRows(): array {
		return array(
			array(
				'id'                => 20,
				'title'             => 'Long Approved Campaign',
				'can_submit'        => false,
				'submission_status' => 'approved',
				'magic_link'        => 'https://example.com/record/20',
				'end_date'          => '2020-01-01 17:00:00',
				'reviewed_at'       => gmmktime( 12, 0, 0, 1, 2, 2020 ),
			),
			array(
				'id'                => 21,
				'title'             => 'Still Pending Campaign',
				'can_submit'        => false,
				'submission_status' => 'submitted',
				'magic_link'        => 'https://example.com/record/21',
				'end_date'          => '2020-01-01 17:00:00',
				'reviewed_at'       => null,
			),
		);
	}

	public function test_render_omits_an_approved_banner_past_its_window_and_keeps_the_submitted_one(): void {
		$this->wireFullRenderCollaborators( false, $this->endedCampaignRows() );

		$output = DashboardShortcode::render();

		$this->assertStringNotContainsString( 'Long Approved Campaign', $output, 'Past the later of the end and the approval plus 30 days, the approved banner goes.' );
		$this->assertStringNotContainsString( 'has been approved', $output );
		$this->assertStringContainsString( 'Still Pending Campaign', $output, 'A submission awaiting review keeps its banner until a decision.' );
		$this->assertStringContainsString( 'pending review', $output );
	}

	public function test_render_keeps_an_expired_approved_banner_when_the_window_is_zero(): void {
		$this->wireFullRenderCollaborators( false, $this->endedCampaignRows() );
		Functions\when( 'get_option' )->justReturn( array( 'rereg_approved_banner_days' => 0 ) );

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Long Approved Campaign', $output, '0 never hides, which is the behaviour before the setting.' );
	}

	public function test_render_shows_an_approved_banner_inside_its_window_with_the_family_sized_button(): void {
		$this->wireFullRenderCollaborators(
			false,
			array(
				array(
					'id'                => 30,
					'title'             => 'Recent Approved Campaign',
					'can_submit'        => false,
					'submission_status' => 'approved',
					'magic_link'        => 'https://example.com/record/30',
					'end_date'          => '2099-12-31 17:00:00',
					'reviewed_at'       => gmmktime( 12, 0, 0, 1, 2, 2020 ),
				),
			)
		);

		$output = DashboardShortcode::render();

		$this->assertStringContainsString( 'Recent Approved Campaign', $output );
		$this->assertStringContainsString( 'has been approved', $output );

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $output );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$links = $xpath->query( '//*[@data-ffc-notice="rereg-banner"]//a[@href="https://example.com/record/30"]' );
		$this->assertSame( 1, $links->length, 'The banner carries its Record link.' );
		$classes = preg_split( '/\s+/', trim( $links->item( 0 )->getAttribute( 'class' ) ) );
		$this->assertContains( 'ffc-rereg-banner-record', $classes );
		$this->assertNotContains( 'ffc-btn-pdf', $classes, 'The compact table-row class stays off the banner.' );
	}
}
