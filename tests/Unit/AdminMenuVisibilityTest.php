<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminMenuVisibility;

/**
 * Tests for the admin-menu-visibility scoping layer.
 *
 * Covers the three hooks (`admin_menu`, `admin_init`, `admin_bar_menu`)
 * + the resolve-policy fast paths (admin bypass, no-role bypass,
 * non-FFC role bypass).
 *
 * @covers \FreeFormCertificate\Admin\AdminMenuVisibility
 */
class AdminMenuVisibilityTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.com/wp-admin/' . $p );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_three_hooks_at_priority_9999(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_menu', array( AdminMenuVisibility::class, 'apply_menu_visibility' ), 9999 );
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_init', array( AdminMenuVisibility::class, 'block_url_access' ) );
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_bar_menu', array( AdminMenuVisibility::class, 'prune_admin_bar' ), 9999 );

		AdminMenuVisibility::init();
	}

	public function test_apply_menu_visibility_is_noop_for_admin_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'remove_menu_page' )->never();

		AdminMenuVisibility::apply_menu_visibility();
	}

	public function test_apply_menu_visibility_removes_core_menus_for_recruitment_role(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user        = (object) array( 'roles' => array( 'ffc_recruitment_manager' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$removed = array();
		Functions\when( 'remove_menu_page' )->alias(
			static function ( string $slug ) use ( &$removed ): void {
				$removed[] = $slug;
			}
		);

		AdminMenuVisibility::apply_menu_visibility();

		// Spot-check the shared hidden-menus set.
		$this->assertContains( 'edit.php', $removed );
		$this->assertContains( 'edit-comments.php', $removed );
		$this->assertContains( 'tools.php', $removed );
		$this->assertContains( 'plugins.php', $removed );
		$this->assertContains( 'themes.php', $removed );
	}

	public function test_apply_menu_visibility_skips_users_with_no_ffc_role(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = (object) array( 'roles' => array( 'subscriber' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\expect( 'remove_menu_page' )->never();

		AdminMenuVisibility::apply_menu_visibility();
	}

	public function test_block_url_access_redirects_recruitment_role_off_unrelated_admin_url(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = (object) array( 'roles' => array( 'ffc_recruitment_auditor' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		// Operator hits `/wp-admin/edit-comments.php` — outside their allow-list.
		global $pagenow;
		$pagenow = 'edit-comments.php';
		$_SERVER['QUERY_STRING'] = '';

		$redirected_to = null;
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ) use ( &$redirected_to ): void {
				$redirected_to = $url;
				throw new \RuntimeException( 'redirect' );
			}
		);

		try {
			AdminMenuVisibility::block_url_access();
			$this->fail( 'Expected wp_safe_redirect() to fire' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'admin.php?page=ffc-recruitment', $redirected_to ?? '' );
		}
	}

	public function test_block_url_access_allows_recruitment_role_on_their_admin_page(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = (object) array( 'roles' => array( 'ffc_recruitment_auditor' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		global $pagenow;
		$pagenow             = 'admin.php';
		$_GET['page']        = 'ffc-recruitment';
		$_SERVER['QUERY_STRING'] = 'page=ffc-recruitment';

		Functions\expect( 'wp_safe_redirect' )->never();

		AdminMenuVisibility::block_url_access();

		// Cleanup.
		unset( $_GET['page'] );
	}

	public function test_block_url_access_skips_ajax(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\expect( 'wp_get_current_user' )->never();

		AdminMenuVisibility::block_url_access();
	}

	public function test_block_url_access_always_allows_profile_page(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = (object) array( 'roles' => array( 'ffc_recruitment_auditor' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		global $pagenow;
		$pagenow = 'profile.php';
		$_SERVER['QUERY_STRING'] = '';

		Functions\expect( 'wp_safe_redirect' )->never();

		AdminMenuVisibility::block_url_access();
	}

	public function test_prune_admin_bar_removes_nodes_for_ffc_role(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = (object) array( 'roles' => array( 'ffc_recruitment_manager' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$bar = Mockery::mock();
		$bar->shouldReceive( 'remove_node' )->with( 'new-content' )->once();
		$bar->shouldReceive( 'remove_node' )->with( 'comments' )->once();

		AdminMenuVisibility::prune_admin_bar( $bar );
	}

	public function test_prune_admin_bar_skips_admin_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$bar = Mockery::mock();
		$bar->shouldNotReceive( 'remove_node' );

		AdminMenuVisibility::prune_admin_bar( $bar );
	}
}
