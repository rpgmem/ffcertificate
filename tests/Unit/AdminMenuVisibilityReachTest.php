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
use FreeFormCertificate\UserDashboard\RoleRegistrar;

/**
 * Every operator role reaches every screen its capabilities open (#1776).
 *
 * The allow-list was written by hand per role, so `ffc_readonly` held the view
 * capability of the certificates, reregistration, short URL and date message
 * screens and was redirected off all of them, and the certificate roles landed
 * on the forms' list, which their capabilities do not open. These tests read
 * every admin screen from the code that registers it, with the capability it
 * is registered under, and hold the allow-list to that map in both directions:
 * a role reaches each screen it may open, no entry names a screen nothing
 * registers, and no entry names a screen its role can never open (#1782). The
 * page-less write actions are held to the capability their handler checks, in
 * both directions (#1781).
 *
 * The registrations it stubs leave no later class depending on them: the
 * whole suite ran green with these tests in the shared process (#2091).
 *
 * @covers \FreeFormCertificate\Admin\AdminMenuVisibility
 */
class AdminMenuVisibilityReachTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every page-less `admin.php?action=…` an operator role may list: action =>
	 * the handler that serves it and the capability that handler checks.
	 * `test_each_action_capability_is_the_one_its_handler_checks()` reads each
	 * handler's source to hold this map to the code.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: string}>
	 */
	private static function action_handlers(): array {
		return array(
			CertTemplateAdminScreen::PREVIEW_ACTION        => array( CertTemplateAdminScreen::class, 'handle_preview', 'ffc_view_forms' ),
			CertTemplateAdminScreen::TOGGLE_ACTION         => array( CertTemplateAdminScreen::class, 'handle_toggle_visibility', 'ffc_manage_forms' ),
			CertTemplateAdminScreen::DUPLICATE_ACTION      => array( CertTemplateAdminScreen::class, 'handle_duplicate', 'ffc_manage_forms' ),
			CertTemplateAdminScreen::EXPORT_ACTION         => array( CertTemplateAdminScreen::class, 'handle_export', 'ffc_manage_forms' ),
			CertTemplateAdminScreen::RESTORE_ACTION        => array( CertTemplateAdminScreen::class, 'handle_restore_defaults', 'ffc_manage_forms' ),
			CPT::DUPLICATE_ACTION                          => array( CPT::class, 'handle_form_duplication', 'ffc_manage_forms' ),
			AdminMenuVisibility::DUPLICATE_CALENDAR_ACTION => array( SelfSchedulingCPT::class, 'handle_calendar_duplication', 'ffc_manage_calendars' ),
		);
	}

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
	 * A menu whose screen opens for view OR manage names whichever of the two
	 * the user holds (WordPress gates a menu on one capability), so the
	 * registrations are read as a user holding `$caps`.
	 *
	 * @param list<string> $caps Capabilities `current_user_can()` grants while registering.
	 * @return array{screens: array<string, string>, parents: array<string, string>, actions: list<string>}
	 *         Screen URL => capability, submenu screen URL => its parent, and the
	 *         `admin_action_*` suffixes registered.
	 */
	private function registrations( array $caps = array() ): array {
		$screens = array();
		$parents = array();
		$actions = array();

		Functions\when( 'current_user_can' )->alias( static fn( $cap ): bool => in_array( $cap, $caps, true ) );

		$page = static function ( string $slug, string $cap ) use ( &$screens ): string {
			$screens[ 'admin.php?page=' . $slug ] = $cap;
			return 'hook';
		};
		Functions\when( 'add_menu_page' )->alias(
			static fn( $page_title, $menu_title, string $cap, string $slug ): string => $page( $slug, $cap )
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function ( $parent, $page_title, $menu_title, string $cap, string $slug ) use ( $page, &$parents ): string {
				$parents[ 'admin.php?page=' . $slug ] = (string) $parent;
				return $page( $slug, $cap );
			}
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

		// The visit itself must not see the role's capabilities as
		// `manage_options`: block_url_access() bypasses on that alone.
		Functions\when( 'current_user_can' )->justReturn( false );

		return array(
			'screens' => $screens,
			'parents' => $parents,
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
	 * `ffc_recruitment_manager` is the one role RoleRegistrar defines on its
	 * own; it is read from `register_recruitment_manager_role()` as created on
	 * a fresh install — `read` and `ffc_manage_recruitment` alone, before
	 * `register_module_roles()` adds the tier caps — so the manage-implies-view
	 * rule is exercised on the narrowest set the role can hold (#1782).
	 *
	 * @return array<string, list<string>>
	 */
	private function scoped_role_caps(): array {
		$definitions = CapabilityManager::module_roles_definition();

		$created = array();
		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'add_role' )->alias(
			static function ( string $role, $label, array $caps ) use ( &$created ): void {
				$created[ $role ] = array_keys( array_filter( $caps ) );
			}
		);
		RoleRegistrar::register_recruitment_manager_role();
		$definitions[ CapabilityManager::RECRUITMENT_MANAGER_ROLE ] = array( 'caps' => $created[ CapabilityManager::RECRUITMENT_MANAGER_ROLE ] ?? array() );

		$roles     = array();
		$undefined = array();
		foreach ( array_keys( $this->policy() ) as $role ) {
			if ( isset( $definitions[ $role ] ) ) {
				$roles[ $role ] = $definitions[ $role ]['caps'];
			} else {
				$undefined[] = $role;
			}
		}
		$this->assertSame( array(), $undefined, 'A scoped role has no definition this test can read.' );
		$this->assertSame( array( 'read', 'ffc_manage_recruitment' ), $roles[ CapabilityManager::RECRUITMENT_MANAGER_ROLE ] );
		return $roles;
	}

	/**
	 * The allow-list entries of `$policy` that a role holding `$caps` can never
	 * open: WordPress refuses the screen, or the handler refuses the action.
	 *
	 * An `edit.php?post_type=` entry is usable when the role opens the post
	 * type's list or any submenu registered under it, because the menu links of
	 * those submenus take that form (the certificate roles reach the Dashboard
	 * and Submissions through the forms' list without holding a forms cap).
	 *
	 * @param array{landing_page: string, allowed_pages: list<string>}                                    $policy     The role's policy.
	 * @param list<string>                                                                                 $caps       The role's capabilities.
	 * @param array{screens: array<string, string>, parents: array<string, string>, actions: list<string>} $registered Registrations read as that role.
	 * @return list<string>
	 */
	private function unusable_entries( array $policy, array $caps, array $registered ): array {
		$unusable  = array();
		$entries   = $policy['allowed_pages'];
		$entries[] = $policy['landing_page'];
		foreach ( array_unique( $entries ) as $entry ) {
			if ( str_starts_with( $entry, 'admin.php?action=' ) ) {
				$known = self::action_handlers()[ substr( $entry, strlen( 'admin.php?action=' ) ) ] ?? null;
				if ( null === $known || ! in_array( $known[2], $caps, true ) ) {
					$unusable[] = $entry;
				}
				continue;
			}
			$slug = $entry;
			if ( str_starts_with( $slug, 'admin.php?page=' ) ) {
				parse_str( substr( $slug, strlen( 'admin.php?' ) ), $query );
				$slug = (string) $query['page'];
			}
			$url = str_contains( $slug, '.php' ) ? $slug : 'admin.php?page=' . $slug;
			if ( isset( $registered['screens'][ $url ] ) && $this->holds( $registered['screens'][ $url ], $caps ) ) {
				continue;
			}
			$through_child = false;
			foreach ( $registered['parents'] as $child => $parent ) {
				if ( $parent === $url && $this->holds( $registered['screens'][ $child ], $caps ) ) {
					$through_child = true;
					break;
				}
			}
			if ( ! $through_child ) {
				$unusable[] = $entry;
			}
		}
		return $unusable;
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
		$this->assertSame( QrGeneratorPage::SLUG, AdminMenuVisibility::QR_GENERATOR_PAGE );
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
			$registered = $this->registrations( $caps );
			foreach ( $registered['screens'] as $url => $cap ) {
				if ( ! $this->holds( $cap, $caps ) ) {
					continue;
				}
				++$reached;
				$this->assertNull( $this->visit( $role, $url ), "{$role} holds {$cap} and is redirected off {$url}." );
			}
		}
		$this->assertGreaterThan( 0, $reached, 'No role held the capability of any screen.' );
	}

	public function test_each_role_reaches_exactly_the_actions_its_capabilities_allow(): void {
		$actions = $this->registrations()['actions'];
		$allowed = 0;
		foreach ( $this->scoped_role_caps() as $role => $caps ) {
			foreach ( self::action_handlers() as $action => $handler ) {
				$this->assertContains( $action, $actions, "Nothing registers admin_action_{$action}." );
				$url      = 'admin.php?action=' . $action . '&post=7&_wpnonce=x';
				$redirect = $this->visit( $role, $url );
				if ( in_array( $handler[2], $caps, true ) ) {
					++$allowed;
					$this->assertNull( $redirect, "{$role} holds {$handler[2]} and is redirected off {$url}." );
				} else {
					$this->assertNotNull( $redirect, "{$role} lacks {$handler[2]} and still passes {$url}." );
				}
			}
		}
		$this->assertGreaterThan( 0, $allowed, 'No role held the capability of any action.' );
	}

	public function test_the_forms_manager_reaches_the_form_write_actions_of_issue_1781(): void {
		$urls = array(
			'admin.php?action=ffc_duplicate_form&post=7&_wpnonce=x',
			'admin.php?action=ffc_toggle_cert_template_visibility&post=7&_wpnonce=x',
			'admin.php?action=ffc_duplicate_cert_template&post=7&_wpnonce=x',
			'admin.php?action=ffc_export_cert_template&post=7&_wpnonce=x',
			'admin.php?action=ffc_restore_cert_templates&_wpnonce=x',
		);
		foreach ( $urls as $url ) {
			$this->assertNull( $this->visit( 'ffc_forms_manager', $url ), "ffc_forms_manager is redirected off {$url}." );
			$this->assertNotNull( $this->visit( 'ffc_forms_viewer', $url ), "ffc_forms_viewer passes {$url}." );
		}
	}

	public function test_each_action_capability_is_the_one_its_handler_checks(): void {
		foreach ( self::action_handlers() as $action => $handler ) {
			$reflection = new \ReflectionMethod( $handler[0], $handler[1] );
			$lines      = file( (string) $reflection->getFileName() );
			$this->assertIsArray( $lines );
			$body = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
			$this->assertStringContainsString(
				"current_user_can_admin_or( '{$handler[2]}' )",
				$body,
				"{$handler[0]}::{$handler[1]}() serves {$action} and does not check {$handler[2]}."
			);
		}
	}

	public function test_every_allow_list_entry_is_one_its_role_can_open(): void {
		$checked = 0;
		foreach ( $this->scoped_role_caps() as $role => $caps ) {
			$policy   = $this->policy()[ $role ];
			$checked += count( $policy['allowed_pages'] );
			$this->assertSame( array(), $this->unusable_entries( $policy, $caps, $this->registrations( $caps ) ), "{$role} lists entries its capabilities can never open." );
		}
		$this->assertGreaterThan( count( $this->policy() ), $checked, 'The scan read fewer entries than there are roles.' );
	}

	public function test_an_unusable_entry_is_reported(): void {
		// The canary: the two shapes #1782 removed, and an action whose
		// handler capability the role lacks.
		$viewer = CapabilityManager::module_roles_definition()['ffc_reregistration_viewer']['caps'];
		$policy = array(
			'landing_page'  => ReregistrationAdmin::MENU_SLUG,
			'allowed_pages' => array( ReregistrationAdmin::MENU_SLUG, ReregistrationAdmin::CUSTOM_FIELDS_SLUG, Settings::MENU_SLUG, 'admin.php?action=' . CPT::DUPLICATE_ACTION ),
		);
		$this->assertSame(
			array( ReregistrationAdmin::CUSTOM_FIELDS_SLUG, Settings::MENU_SLUG, 'admin.php?action=' . CPT::DUPLICATE_ACTION ),
			$this->unusable_entries( $policy, $viewer, $this->registrations( $viewer ) )
		);
	}

	public function test_the_recruitment_manager_reaches_recruitment_with_manage_alone(): void {
		// 3-state (#1782): the role is created holding `ffc_manage_recruitment`
		// without `ffc_view_recruitment`, and manage implies view.
		$caps       = $this->scoped_role_caps()[ CapabilityManager::RECRUITMENT_MANAGER_ROLE ];
		$registered = $this->registrations( $caps );
		$url        = 'admin.php?page=' . RecruitmentAdminPage::PAGE_SLUG;

		$this->assertSame( 'ffc_manage_recruitment', $registered['screens'][ $url ] );
		$this->assertNull( $this->visit( CapabilityManager::RECRUITMENT_MANAGER_ROLE, $url . '&tab=candidates' ) );
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
			// Intended, not a gap (#1782). `ffc_readonly` holds
			// `ffc_view_custom_fields`, but that capability only gates the
			// field-definition section of the audience editor, which the role
			// reaches. The Custom Fields screen is the reregistration field
			// editor: registered under `ffc_manage_reregistration`, and
			// `ReregistrationCustomFieldsPage::render()` has no read-only
			// rendering. Opening it read-only would be a new screen mode, a
			// feature rather than a fix.
			'custom fields' => array( 'admin.php?page=ffc-custom-fields' ),
			'duplicate'     => array( 'admin.php?action=ffc_duplicate_form&post=7' ),
			'template copy' => array( 'admin.php?action=ffc_duplicate_cert_template&post=7' ),
			'posts'         => array( 'edit.php' ),
		);
	}

	/**
	 * @dataProvider readonly_blocked_screens
	 *
	 * @param string $url wp-admin URL.
	 */
	public function test_the_readonly_role_is_still_redirected_off_write_screens( string $url ): void {
		// Its landing is the Certificates dashboard since the activity log
		// became FFC Administrator's alone (#1860).
		$this->assertSame(
			'https://example.com/wp-admin/admin.php?page=' . CertificatesDashboard::MENU_SLUG,
			$this->visit( 'ffc_readonly', $url )
		);
	}
}
