<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminMenuVisibility;
use FreeFormCertificate\Audience\AudienceAdminPage;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;

/**
 * The Scheduling half of the operator-role allow-list (#1769).
 *
 * The list named `ffc-self-scheduling`, `ffc-self-scheduling-appointments`,
 * `ffc-self-scheduling-settings`, `ffc-aud-import` and `ffc-aud-settings`,
 * none of which any code registers, and matched `ffc-scheduling` exactly, so
 * every operator role was redirected off the screens it exists for. These
 * tests read the screens from their registrations rather than from a copy, so
 * a renamed slug fails here instead of redirecting in production.
 *
 * Each test runs in its own process: the registration tests stub WordPress
 * menu and post-type functions that must not stay defined for later classes.
 *
 * @covers \FreeFormCertificate\Admin\AdminMenuVisibility
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminMenuVisibilitySchedulingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_post_type' )->alias(
			static fn( $id ): string => 7 === (int) $id ? AdminMenuVisibility::PERSONAL_CALENDAR_POST_TYPE : 'post'
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				throw new \RuntimeException( $url );
			}
		);
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['QUERY_STRING'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Visit a wp-admin URL as a user holding only `$role`.
	 *
	 * @param string               $role Role slug.
	 * @param string               $url  Path under wp-admin, e.g. `admin.php?page=x`.
	 * @param array<string, mixed> $post `$_POST` body.
	 * @return string|null The redirect target, or null when the screen is reachable.
	 */
	private function visit( string $role, string $url, array $post = array() ): ?string {
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( $role ) ) );

		global $pagenow;
		$parts   = explode( '?', $url, 2 );
		$pagenow = $parts[0];
		$query   = $parts[1] ?? '';
		$_GET    = array();
		parse_str( $query, $_GET );
		$_POST                   = $post;
		$_SERVER['QUERY_STRING'] = $query;

		try {
			AdminMenuVisibility::block_url_access();
		} catch ( \RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		return null;
	}

	/**
	 * The policy, read through reflection: it is private on purpose.
	 *
	 * @return array<string, array{landing_page: string, allowed_pages: list<string>}>
	 */
	private function policy(): array {
		$method = new \ReflectionMethod( AdminMenuVisibility::class, 'policy' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * Every Scheduling screen, read from the code that registers it.
	 *
	 * @return list<string> `?page=` slugs and the Personal Calendars list URL.
	 */
	private function registered_scheduling_screens(): array {
		$slugs = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_menu_page' )->alias(
			static function ( $page_title, $menu_title, $cap, string $slug ) use ( &$slugs ): string {
				$slugs[] = $slug;
				return 'hook';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent, $page_title, $menu_title, $cap, string $slug ) use ( &$slugs ): string {
				$slugs[] = $slug;
				return 'hook';
			}
		);

		$audience = new AudienceAdminPage();
		$audience->init();
		$audience->add_admin_menus();
		( new SelfSchedulingAdmin() )->add_submenu_pages();

		$post_types = array();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'register_post_type' )->alias(
			static function ( string $type ) use ( &$post_types ): void {
				$post_types[] = $type;
			}
		);
		( new SelfSchedulingCPT() )->register_calendar_cpt();
		foreach ( $post_types as $type ) {
			$slugs[] = 'edit.php?post_type=' . $type;
		}

		return $slugs;
	}

	public function test_each_self_scheduling_literal_equals_its_registration(): void {
		$screens = $this->registered_scheduling_screens();
		$this->assertContains( AdminMenuVisibility::APPOINTMENTS_PAGE, $screens, 'SelfSchedulingAdmin registers the Appointments page under this slug.' );
		$this->assertContains( 'edit.php?post_type=' . AdminMenuVisibility::PERSONAL_CALENDAR_POST_TYPE, $screens, 'SelfSchedulingCPT registers this post type.' );

		$actions = array();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$actions ): void {
				$actions[] = $hook;
			}
		);
		new SelfSchedulingCPT();
		$this->assertContains( 'admin_action_' . AdminMenuVisibility::DUPLICATE_CALENDAR_ACTION, $actions, 'The Duplicate row action dispatches this admin action.' );
	}

	public function test_the_audience_suffixes_are_exactly_the_registered_scheduling_menu(): void {
		$screens  = $this->registered_scheduling_screens();
		$audience = array_values( array_filter( $screens, static fn( string $s ): bool => str_starts_with( $s, AudienceAdminPage::MENU_SLUG ) ) );

		$expected = array( AudienceAdminPage::MENU_SLUG );
		foreach ( AdminMenuVisibility::AUDIENCE_SCREEN_SUFFIXES as $suffix ) {
			$expected[] = AudienceAdminPage::MENU_SLUG . '-' . $suffix;
		}
		sort( $audience );
		sort( $expected );
		$this->assertSame( $expected, $audience );
	}

	public function test_every_scheduling_entry_in_the_allow_list_names_a_registered_screen(): void {
		$screens   = $this->registered_scheduling_screens();
		$screens[] = 'admin.php?action=' . AdminMenuVisibility::DUPLICATE_CALENDAR_ACTION;
		$checked   = 0;

		foreach ( $this->policy() as $role => $policy ) {
			// ffc_readonly spans every area; AdminMenuVisibilityReachTest checks
			// its entries, and every other role's, against all registrations.
			if ( ! preg_match( '/^ffc_(appointments|calendars|audiences)_/', $role ) ) {
				continue;
			}
			foreach ( $policy['allowed_pages'] as $entry ) {
				++$checked;
				$this->assertContains( $entry, $screens, "{$role} allows {$entry}, which nothing registers." );
			}
		}
		$this->assertGreaterThan( 0, $checked, 'The scan read no allow-list entry.' );
	}

	public function test_every_role_lands_on_a_screen_its_own_policy_allows(): void {
		$policies = $this->policy();
		$this->assertNotEmpty( $policies );

		foreach ( $policies as $role => $policy ) {
			$landing = $policy['landing_page'];
			$url     = str_contains( $landing, '.php' ) ? $landing : 'admin.php?page=' . $landing;
			$this->assertNull( $this->visit( $role, $url ), "{$role} is redirected off its own landing page {$url}." );
		}
	}

	/**
	 * Screens each role's capabilities open, by tier.
	 *
	 * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
	 */
	public function reachable_screens(): array {
		$cases = array();
		foreach ( array( 'viewer', 'operator', 'manager', 'admin' ) as $tier ) {
			$role                                     = 'ffc_appointments_' . $tier;
			$cases[ "{$role} list" ]                  = array( $role, 'admin.php?page=ffc-appointments' );
			$cases[ "{$role} list filtered" ]         = array( $role, 'admin.php?page=ffc-appointments&status=pending&calendar_id=3' );
		}
		$cases['ffc_appointments_manager detail'] = array( 'ffc_appointments_manager', 'admin.php?page=ffc-appointments&appointment=5' );

		foreach ( array( 'viewer', 'manager' ) as $tier ) {
			$role                                    = 'ffc_calendars_' . $tier;
			$cases[ "{$role} list" ]                 = array( $role, 'edit.php?post_type=ffc_self_scheduling' );
			$cases[ "{$role} list searched" ]        = array( $role, 'edit.php?s=clinic&post_status=all&post_type=ffc_self_scheduling' );
			$cases[ "{$role} editor" ]               = array( $role, 'post.php?post=7&action=edit' );
		}
		$cases['ffc_calendars_manager new']       = array( 'ffc_calendars_manager', 'post-new.php?post_type=ffc_self_scheduling' );
		$cases['ffc_calendars_manager save']      = array( 'ffc_calendars_manager', 'post.php', array( 'post_ID' => '7', 'action' => 'editpost' ) );
		$cases['ffc_calendars_manager duplicate'] = array( 'ffc_calendars_manager', 'admin.php?action=ffc_duplicate_calendar&post=7&_wpnonce=x' );

		$audience_screens = array( 'ffc-scheduling', 'ffc-scheduling-dashboard', 'ffc-scheduling-calendars', 'ffc-scheduling-environments', 'ffc-scheduling-audiences', 'ffc-scheduling-bookings', 'ffc-scheduling-settings' );
		foreach ( array( 'viewer', 'operator', 'manager' ) as $tier ) {
			$role = 'ffc_audiences_' . $tier;
			foreach ( $audience_screens as $slug ) {
				$cases[ "{$role} {$slug}" ] = array( $role, 'admin.php?page=' . $slug );
			}
		}
		$cases['ffc_audiences_manager members']     = array( 'ffc_audiences_manager', 'admin.php?page=ffc-scheduling-audiences&action=members&id=3' );
		$cases['ffc_audiences_operator import tab'] = array( 'ffc_audiences_operator', 'admin.php?page=ffc-scheduling-settings&tab=import' );

		// The cross-domain read-only role holds every view cap of the three.
		foreach ( array_merge( array( 'ffc-appointments' ), $audience_screens ) as $slug ) {
			$cases[ "ffc_readonly {$slug}" ] = array( 'ffc_readonly', 'admin.php?page=' . $slug );
		}
		$cases['ffc_readonly personal calendars'] = array( 'ffc_readonly', 'edit.php?post_type=ffc_self_scheduling' );
		$cases['ffc_readonly calendar editor']    = array( 'ffc_readonly', 'post.php?post=7&action=edit' );

		return $cases;
	}

	/**
	 * @dataProvider reachable_screens
	 *
	 * @param string               $role Role slug.
	 * @param string               $url  wp-admin URL.
	 * @param array<string, mixed> $post `$_POST` body.
	 */
	public function test_each_operator_role_reaches_the_scheduling_screens_its_caps_open( string $role, string $url, array $post = array() ): void {
		$this->assertNull( $this->visit( $role, $url, $post ), "{$role} is redirected off {$url}." );
	}

	/**
	 * Screens outside the role's capabilities still redirect to its landing.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function blocked_screens(): array {
		return array(
			'appointments viewer on audience calendars' => array( 'ffc_appointments_viewer', 'admin.php?page=ffc-scheduling-calendars', 'admin.php?page=ffc-appointments' ),
			'appointments viewer on personal calendars' => array( 'ffc_appointments_viewer', 'edit.php?post_type=ffc_self_scheduling', 'admin.php?page=ffc-appointments' ),
			'calendars viewer duplicating'              => array( 'ffc_calendars_viewer', 'admin.php?action=ffc_duplicate_calendar&post=7', 'edit.php?post_type=ffc_self_scheduling' ),
			'calendars manager on another post type'    => array( 'ffc_calendars_manager', 'post.php?post=8&action=edit', 'edit.php?post_type=ffc_self_scheduling' ),
			'calendars manager on posts'                => array( 'ffc_calendars_manager', 'post-new.php?post_type=post', 'edit.php?post_type=ffc_self_scheduling' ),
			'calendars manager on appointments'         => array( 'ffc_calendars_manager', 'admin.php?page=ffc-appointments', 'edit.php?post_type=ffc_self_scheduling' ),
			'audiences viewer on appointments'          => array( 'ffc_audiences_viewer', 'admin.php?page=ffc-appointments', 'admin.php?page=ffc-scheduling' ),
			'audiences viewer on an unknown action'     => array( 'ffc_audiences_viewer', 'admin.php?action=ffc_duplicate_calendar', 'admin.php?page=ffc-scheduling' ),
		);
	}

	/**
	 * @dataProvider blocked_screens
	 *
	 * @param string $role    Role slug.
	 * @param string $url     wp-admin URL.
	 * @param string $landing Where the role is sent.
	 */
	public function test_screens_outside_the_roles_caps_still_redirect( string $role, string $url, string $landing ): void {
		$this->assertSame( 'https://example.com/wp-admin/' . $landing, $this->visit( $role, $url ) );
	}
}
