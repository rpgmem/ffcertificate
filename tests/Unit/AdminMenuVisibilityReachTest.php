<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\Admin;
use FreeFormCertificate\Admin\AdminMenuVisibility;
use FreeFormCertificate\Admin\CertificatesDashboard;
use FreeFormCertificate\Admin\CertTemplateAdminScreen;
use FreeFormCertificate\Admin\CertTemplateCpt;
use FreeFormCertificate\Admin\CPT;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Admin\Settings;
use FreeFormCertificate\Audience\AudienceAdminPage;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingAdmin;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;
use FreeFormCertificate\UrlShortener\QrGeneratorPage;
use FreeFormCertificate\UrlShortener\UrlShortenerAdminPage;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Every operator role reaches every screen its capabilities open (#1776).
 *
 * The allow-list was written by hand per role, so `ffc_readonly` held the view
 * capability of the certificates, reregistration, short URL and date message
 * screens and was redirected off all of them, and the certificate roles landed
 * on the forms' list, which their capabilities do not open. These tests read
 * every admin screen from the code that registers it, with the capability it
 * is registered under, and hold the allow-list to that map in both directions:
 * a role reaches each screen it may open, and no entry names a screen nothing
 * registers.
 *
 * Each test runs in its own process: registering the menus stubs WordPress
 * functions that must not stay defined for later classes.
 *
 * @covers \FreeFormCertificate\Admin\AdminMenuVisibility
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminMenuVisibilityReachTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The capability `CertTemplateAdminScreen::handle_preview()` checks.
	 */
	private const PREVIEW_CAP = 'ffc_view_forms';

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
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'add_filter' )->justReturn( true );
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
	 * @param string $role Role slug.
	 * @param string $url  Path under wp-admin, e.g. `admin.php?page=x`.
	 * @return string|null The redirect target, or null when the screen is reachable.
	 */
	private function visit( string $role, string $url ): ?string {
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'roles' => array( $role ) ) );

		global $pagenow;
		$parts   = explode( '?', $url, 2 );
		$pagenow = $parts[0];
		$query   = $parts[1] ?? '';
		$_GET    = array();
		parse_str( $query, $_GET );
		$_POST                   = array();
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
	 * Every admin screen the plugin registers, with the capability WordPress
	 * gates it on, read from the registrations themselves.
	 *
	 * @return array{screens: array<string, string>, actions: list<string>} Screen
	 *         URL => capability, and the `admin_action_*` suffixes registered.
	 */
	private function registrations(): array {
		$screens = array();
		$actions = array();

		$page = static function ( string $slug, string $cap ) use ( &$screens ): string {
			$screens[ 'admin.php?page=' . $slug ] = $cap;
			return 'hook';
		};
		Functions\when( 'add_menu_page' )->alias(
			static fn( $page_title, $menu_title, string $cap, string $slug ): string => $page( $slug, $cap )
		);
		Functions\when( 'add_submenu_page' )->alias(
			static fn( $parent, $page_title, $menu_title, string $cap, string $slug ): string => $page( $slug, $cap )
		);
		Functions\when( 'register_post_type' )->alias(
			static function ( string $type, array $args ) use ( &$screens ): void {
				$screens[ 'edit.php?post_type=' . $type ] = (string) $args['capabilities']['edit_posts'];
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$actions ): bool {
				if ( str_starts_with( $hook, 'admin_action_' ) ) {
					$actions[] = substr( $hook, strlen( 'admin_action_' ) );
				}
				return true;
			}
		);

		$bare = static fn( string $class ): object => ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		( new CertificatesDashboard() )->register_menu();
		$bare( Admin::class )->register_admin_menu();
		$bare( IdentityResolutionPage::class )->register_menu();
		$bare( Settings::class )->add_settings_page();
		$bare( CPT::class )->register_form_cpt();
		$bare( CertTemplateCpt::class )->register();
		( new ReregistrationAdmin() )->add_menu();
		RecruitmentAdminPage::register_menu();
		$bare( UrlShortenerAdminPage::class )->register_menu();
		( new QrGeneratorPage() )->register_menu();
		( new DateMessagesAdminPage() )->register_menu();
		$audience = new AudienceAdminPage();
		$audience->init();
		$audience->add_admin_menus();
		( new SelfSchedulingAdmin() )->add_submenu_pages();
		$calendars = new SelfSchedulingCPT();
		$calendars->register_calendar_cpt();
		new CPT();
		new CertTemplateAdminScreen();

		return array(
			'screens' => $screens,
			'actions' => $actions,
		);
	}

	/**
	 * Whether a role holding `$caps` passes the capability a screen is gated on.
	 *
	 * @param string       $cap  Screen capability.
	 * @param list<string> $caps The role's capabilities.
	 */
	private function holds( string $cap, array $caps ): bool {
		// The Settings menu is registered under a virtual capability that any
		// page-entry capability grants (Settings::grant_settings_page_meta_cap()).
		if ( 'ffc_view_settings_page' === $cap ) {
			return array() !== array_intersect( Settings::page_entry_caps(), $caps );
		}
		return in_array( $cap, $caps, true );
	}

	/**
	 * The capabilities of each scoped role, from its definition.
	 *
	 * @return array<string, list<string>>
	 */
	private function scoped_role_caps(): array {
		$definitions = CapabilityManager::module_roles_definition();
		$roles       = array();
		$undefined   = array();
		foreach ( array_keys( $this->policy() ) as $role ) {
			if ( isset( $definitions[ $role ] ) ) {
				$roles[ $role ] = $definitions[ $role ]['caps'];
			} else {
				$undefined[] = $role;
			}
		}
		// The one role RoleRegistrar defines on its own, with `read` and
		// `ffc_manage_recruitment` only, which gate no menu.
		$this->assertSame( array( 'ffc_recruitment_manager' ), $undefined );
		return $roles;
	}

	public function test_the_registrations_read_every_area(): void {
		$registered = $this->registrations();
		$screens    = $registered['screens'];

		// An independent recount: one screen per area this test must reach,
		// each named by its own registering class.
		$expected = array(
			'admin.php?page=' . CertificatesDashboard::MENU_SLUG,
			'admin.php?page=' . Admin::SUBMISSIONS_PAGE,
			'admin.php?page=' . IdentityResolutionPage::MENU_SLUG,
			'admin.php?page=' . Settings::MENU_SLUG,
			'admin.php?page=' . ReregistrationAdmin::MENU_SLUG,
			'admin.php?page=' . ReregistrationAdmin::CUSTOM_FIELDS_SLUG,
			'admin.php?page=' . RecruitmentAdminPage::PAGE_SLUG,
			'admin.php?page=' . QrGeneratorPage::SLUG,
			'admin.php?page=' . DateMessagesAdminPage::MENU_SLUG,
			'admin.php?page=' . AudienceAdminPage::MENU_SLUG,
			'edit.php?post_type=' . CPT::POST_TYPE,
			'edit.php?post_type=' . CertTemplateCpt::POST_TYPE,
		);
		foreach ( $expected as $url ) {
			$this->assertArrayHasKey( $url, $screens, "The scan did not read {$url}." );
		}
		$this->assertContains( CertTemplateAdminScreen::PREVIEW_ACTION, $registered['actions'] );
	}

	public function test_each_literal_equals_its_registration(): void {
		$screens = $this->registrations()['screens'];
		$this->assertArrayHasKey( 'admin.php?page=' . AdminMenuVisibility::SHORT_URLS_PAGE, $screens, 'UrlShortenerAdminPage registers the Short URLs page under this slug.' );
		$this->assertSame( DateMessagesAdminPage::MENU_SLUG, AdminMenuVisibility::DATE_MESSAGES_PAGE );
	}

	public function test_every_allow_list_entry_names_a_registered_screen(): void {
		$registered = $this->registrations();
		$checked    = 0;

		foreach ( $this->policy() as $role => $policy ) {
			$entries = $policy['allowed_pages'];
			$entries[] = $policy['landing_page'];
			foreach ( $entries as $entry ) {
				++$checked;
				if ( str_starts_with( $entry, 'admin.php?action=' ) ) {
					$this->assertContains( substr( $entry, strlen( 'admin.php?action=' ) ), $registered['actions'], "{$role} allows {$entry}, which no admin action registers." );
					continue;
				}
				if ( str_starts_with( $entry, 'admin.php?page=' ) ) {
					parse_str( substr( $entry, strlen( 'admin.php?' ) ), $query );
					$entry = (string) $query['page'];
				}
				$url = str_contains( $entry, '.php' ) ? $entry : 'admin.php?page=' . $entry;
				$this->assertArrayHasKey( $url, $registered['screens'], "{$role} allows {$entry}, which nothing registers." );
			}
		}
		$this->assertGreaterThan( count( $this->policy() ), $checked, 'The scan read fewer entries than there are roles.' );
	}

	public function test_each_role_reaches_every_screen_its_capabilities_open(): void {
		$registered = $this->registrations();
		$reached    = 0;

		foreach ( $this->scoped_role_caps() as $role => $caps ) {
			foreach ( $registered['screens'] as $url => $cap ) {
				if ( ! $this->holds( $cap, $caps ) ) {
					continue;
				}
				++$reached;
				$this->assertNull( $this->visit( $role, $url ), "{$role} holds {$cap} and is redirected off {$url}." );
			}
			if ( in_array( self::PREVIEW_CAP, $caps, true ) ) {
				$url = 'admin.php?action=' . CertTemplateAdminScreen::PREVIEW_ACTION . '&post=7&_wpnonce=x';
				$this->assertNull( $this->visit( $role, $url ), "{$role} holds " . self::PREVIEW_CAP . " and is redirected off {$url}." );
			}
		}
		$this->assertGreaterThan( 0, $reached, 'No role held the capability of any screen.' );
	}

	public function test_the_readonly_role_reaches_the_screens_of_issue_1776(): void {
		$urls = array(
			'admin.php?page=ffc-certificates-dashboard',
			'admin.php?page=ffc-submissions',
			'edit.php?post_type=ffc_form&page=ffc-submissions',
			'edit.php?post_type=ffc_form',
			'edit.php?post_type=ffc_cert_template',
			'admin.php?page=ffc-reregistration',
			'admin.php?page=ffc-short-urls',
			'admin.php?page=ffc-date-messages&tab=history',
			'admin.php?page=ffc-recruitment&tab=settings',
		);
		foreach ( $urls as $url ) {
			$this->assertNull( $this->visit( 'ffc_readonly', $url ), "ffc_readonly is redirected off {$url}." );
		}
	}

	public function test_every_certificate_role_lands_on_a_screen_its_capabilities_open(): void {
		foreach ( array( 'viewer', 'operator', 'manager', 'admin' ) as $tier ) {
			$this->assertSame( CertificatesDashboard::MENU_SLUG, $this->policy()[ 'ffc_certificates_' . $tier ]['landing_page'] );
		}
		$this->assertSame(
			'https://example.com/wp-admin/admin.php?page=ffc-certificates-dashboard',
			$this->visit( 'ffc_certificates_viewer', 'edit.php?post_type=post' )
		);
	}

	/**
	 * Screens that need more than a view capability stay off the read-only
	 * role's list, so it is still redirected from them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function readonly_blocked_screens(): array {
		return array(
			'qr generator'  => array( 'admin.php?page=ffc-qr-generator' ),
			'identities'    => array( 'admin.php?page=ffc-identities' ),
			'custom fields' => array( 'admin.php?page=ffc-custom-fields' ),
			'duplicate'     => array( 'admin.php?action=ffc_duplicate_form&post=7' ),
			'posts'         => array( 'edit.php' ),
		);
	}

	/**
	 * @dataProvider readonly_blocked_screens
	 *
	 * @param string $url wp-admin URL.
	 */
	public function test_the_readonly_role_is_still_redirected_off_write_screens( string $url ): void {
		$this->assertSame(
			'https://example.com/wp-admin/admin.php?page=ffc-settings&tab=activity_log',
			$this->visit( 'ffc_readonly', $url )
		);
	}
}
