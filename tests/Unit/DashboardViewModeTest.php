<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RequestInput;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Shortcodes\DashboardViewMode;

/**
 * Tests for DashboardViewMode: admin view-as-user mode validation
 * and banner rendering.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardViewMode
 */
class DashboardViewModeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The banner masks the viewed account's address (#1863).
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) {
			echo $text;
		} );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		// Brain Monkey namespaced stubs — needed when other tests in the
		// suite define `FreeFormCertificate\Core\wp_unslash` (which then
		// intercepts our `RequestInput::get_get_string` callsite).
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) {
			return abs( (int) $v );
		} );

		// Default stubs
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $_GET['ffc_view_as_user'], $_GET['ffc_view_nonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_view_as_user_id()
	// ==================================================================

	public function test_get_view_as_returns_false_without_get_params(): void {
		// No $_GET params set at all
		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertFalse( $result );
	}

	public function test_get_view_as_returns_false_without_nonce_param(): void {
		$_GET['ffc_view_as_user'] = '42';
		// No ffc_view_nonce set

		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertFalse( $result );
	}

	public function test_get_view_as_returns_false_for_non_admin(): void {
		$_GET['ffc_view_as_user'] = '42';
		$_GET['ffc_view_nonce'] = 'valid_nonce';

		Functions\when( 'current_user_can' )->justReturn( false );

		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertFalse( $result );
	}

	public function test_get_view_as_returns_false_on_invalid_nonce(): void {
		$_GET['ffc_view_as_user'] = '42';
		$_GET['ffc_view_nonce'] = 'bad_nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertFalse( $result );
	}

	public function test_get_view_as_returns_false_for_nonexistent_user(): void {
		$_GET['ffc_view_as_user'] = '999';
		$_GET['ffc_view_nonce'] = 'valid_nonce';

		Functions\when( 'get_userdata' )->justReturn( false );

		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertFalse( $result );
	}

	public function test_get_view_as_returns_user_id_on_success(): void {
		$_GET['ffc_view_as_user'] = '42';
		$_GET['ffc_view_nonce'] = 'valid_nonce';

		$user = new \WP_User( 42 );
		$user->display_name = 'John Doe';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$result = DashboardViewMode::get_view_as_user_id();

		$this->assertSame( 42, $result );
	}

	/**
	 * #2246 — the page needs edit_user on the account, like every route.
	 */
	public function test_get_view_as_returns_false_without_edit_user_on_the_target(): void {
		$_GET['ffc_view_as_user'] = '42';
		$_GET['ffc_view_nonce']   = 'valid_nonce';
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'edit_user' !== $cap );
		Functions\when( 'get_userdata' )->justReturn( new \WP_User( 42 ) );

		$this->assertFalse( DashboardViewMode::get_view_as_user_id() );
		$this->assertTrue( DashboardViewMode::is_view_as_request(), 'The link is there, so the page says it is not valid.' );
	}

	public function test_a_request_without_the_link_is_not_a_view_as_request(): void {
		$this->assertFalse( DashboardViewMode::is_view_as_request() );
		$_GET['ffc_view_as_user'] = '42';
		$this->assertFalse( DashboardViewMode::is_view_as_request(), 'Both halves of the link are needed.' );
	}

	public function test_the_invalid_link_notice_is_an_alert_naming_the_way_back(): void {
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$html = DashboardViewMode::render_invalid_link_notice();

		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'data-ffc-notice="view-as-invalid"', $html );
		$this->assertStringContainsString( 'Users screen', $html );
	}

	// ==================================================================
	// render_admin_viewing_banner()
	// ==================================================================

	public function test_render_banner_contains_admin_view_mode_text(): void {
		$user = new \WP_User( 42 );
		$user->display_name = 'Target User';
		$user->user_email = 'target@example.com';

		$admin = new \WP_User( 1 );
		$admin->display_name = 'Admin User';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/dashboard' );

		$html = DashboardViewMode::render_admin_viewing_banner( 42 );

		$this->assertStringContainsString( 'Admin View Mode', $html );
		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-warning"', $html );
		$this->assertStringContainsString( 'ffc-icon-eye', $html );
		$this->assertStringContainsString( 'data-ffc-notice="admin-viewing"', $html );
		$this->assertStringContainsString( '<strong class="ffc-notice-title">Admin View Mode</strong>', $html );
		$this->assertStringContainsString( 'Exit View Mode', $html );
	}

	public function test_render_banner_contains_user_display_name(): void {
		$user = new \WP_User( 42 );
		$user->display_name = 'Jane Smith';
		$user->user_email = 'jane@example.com';

		$admin = new \WP_User( 1 );
		$admin->display_name = 'Admin Boss';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/dashboard' );

		$html = DashboardViewMode::render_admin_viewing_banner( 42 );

		$this->assertStringContainsString( 'Jane Smith', $html );
		// The viewed account's address is masked (#1863).
		$this->assertStringContainsString( 'j***@example.com', $html );
		$this->assertStringNotContainsString( 'jane@example.com', $html );
		$this->assertStringContainsString( 'Admin Boss', $html );
	}

	public function test_render_banner_uses_dashboard_permalink(): void {
		$user = new \WP_User( 42 );
		$user->display_name = 'User';
		$user->user_email = 'user@example.com';

		$admin = new \WP_User( 1 );
		$admin->display_name = 'Admin';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		Functions\when( 'get_option' )->justReturn( 55 );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/my-dashboard/' );

		$html = DashboardViewMode::render_admin_viewing_banner( 42 );

		$this->assertStringContainsString( 'https://example.com/my-dashboard/', $html );
	}

	public function test_render_banner_uses_home_url_fallback(): void {
		$user = new \WP_User( 42 );
		$user->display_name = 'User';
		$user->user_email = 'user@example.com';

		$admin = new \WP_User( 1 );
		$admin->display_name = 'Admin';

		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		// get_option returns falsy (no dashboard page configured)
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com/dashboard' );

		$html = DashboardViewMode::render_admin_viewing_banner( 42 );

		$this->assertStringContainsString( 'https://example.com/dashboard', $html );
	}
}
