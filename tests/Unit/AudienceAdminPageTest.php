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
 * Tests for AudienceAdminPage: menu registration, separator insertion,
 * form submission delegation, and CSS output.
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

    public function test_init_registers_admin_head_hook(): void {
        $page = new AudienceAdminPage();

        $page->init();

        $this->assertTrue(
            has_action( 'admin_head', 'FreeFormCertificate\Audience\AudienceAdminPage->print_menu_separator_css()' ) !== false,
            'init() should register print_menu_separator_css on admin_head hook'
        );
    }

    public function test_init_registers_menu_separators_hook(): void {
        $page = new AudienceAdminPage();

        $page->init();

        $this->assertTrue(
            has_action( 'admin_menu', 'FreeFormCertificate\Audience\AudienceAdminPage->add_menu_separators()' ) !== false,
            'init() should register add_menu_separators on admin_menu hook'
        );
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

        $page->add_admin_menus();

        // Should register: dashboard, calendars, environments, audiences, bookings, import, settings = 7
        $this->assertCount( 7, $submenu_pages, 'Should register 7 submenu pages' );

        // add_submenu_page args: parent_slug, page_title, menu_title, capability, menu_slug, callback
        $submenu_slugs = array_column( $submenu_pages, 4 );
        $this->assertContains( 'ffc-scheduling-dashboard', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-calendars', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-environments', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-audiences', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-bookings', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-import', $submenu_slugs );
        $this->assertContains( 'ffc-scheduling-settings', $submenu_slugs );
    }

    // ==================================================================
    // add_menu_separators() when submenu is empty
    // ==================================================================

    public function test_add_menu_separators_does_nothing_when_submenu_missing(): void {
        global $submenu;
        $submenu = array();

        $page = new AudienceAdminPage();

        // Should not throw
        $page->add_menu_separators();

        $this->assertArrayNotHasKey( 'ffc-scheduling', $submenu );
    }

    // ==================================================================
    // add_menu_separators() inserts separator items
    // ==================================================================

    public function test_add_menu_separators_inserts_separator_items(): void {
        global $submenu;
        $submenu = array();
        $submenu['ffc-scheduling'] = array(
            array( 'Dashboard', 'manage_options', 'ffc-scheduling-dashboard' ),
            array( 'Calendars', 'manage_options', 'ffc-scheduling-calendars' ),
            array( 'Environments', 'manage_options', 'ffc-scheduling-environments' ),
            array( 'Audiences', 'manage_options', 'ffc-scheduling-audiences' ),
            array( 'Bookings', 'manage_options', 'ffc-scheduling-bookings' ),
            array( 'Import', 'manage_options', 'ffc-scheduling-import' ),
            array( 'Settings', 'manage_options', 'ffc-scheduling-settings' ),
        );

        $page = new AudienceAdminPage();
        $page->add_menu_separators();

        // Extract slugs
        $slugs = array_column( $submenu['ffc-scheduling'], 2 );

        $this->assertContains( '#ffc-separator-audience', $slugs, 'Should insert audience separator' );
        $this->assertContains( '#ffc-separator-tools', $slugs, 'Should insert tools separator' );
    }

    // ==================================================================
    // handle_form_submissions() skips non-scheduling pages
    // ==================================================================

    public function test_handle_form_submissions_skips_non_scheduling_page(): void {
        $_GET['page'] = 'some-other-page';

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $page = new AudienceAdminPage();

        $page->init();

        // Should not throw; nothing should be delegated
        $page->handle_form_submissions();

        // If we reach here without error, the test passes
        $this->assertTrue( true );

        unset( $_GET['page'] );
    }

    // ==================================================================
    // print_menu_separator_css() outputs CSS
    // ==================================================================

    public function test_print_menu_separator_css_outputs_style_tag(): void {
        $page = new AudienceAdminPage();

        ob_start();
        $page->print_menu_separator_css();
        $output = ob_get_clean();

        $this->assertStringContainsString( '<style>', $output, 'Should output a <style> tag' );
        $this->assertStringContainsString( '#ffc-separator-', $output, 'Should reference separator selectors' );
        $this->assertStringContainsString( 'pointer-events: none', $output, 'Separators should have pointer-events: none' );
    }

    public function test_print_menu_separator_css_contains_dashicons_references(): void {
        $page = new AudienceAdminPage();

        ob_start();
        $page->print_menu_separator_css();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'dashicons', $output, 'CSS should reference dashicons font family' );
        $this->assertStringContainsString( '#ffc-separator-self', $output, 'CSS should style the self separator' );
        $this->assertStringContainsString( '#ffc-separator-audience', $output, 'CSS should style the audience separator' );
        $this->assertStringContainsString( '#ffc-separator-tools', $output, 'CSS should style the tools separator' );
    }
}
