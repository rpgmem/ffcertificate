<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabCache;

/**
 * Tests for TabCache: cache & performance settings tab.
 *
 * Exercises the real enqueue_scripts() (autosave infra + the cache-actions
 * script it localises) and both branches of the real render().
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabCache
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabCacheTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabCache */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
        // file-level guard require_once's wp-includes/formatting.php when the
        // function is undefined, fataling under the WP-less test ABSPATH.
        Functions\when( 'wp_kses_post' )->returnArg();
        class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabCache' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $this->tab = new TabCache();
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_cache(): void {
        $this->assertSame( 'cache', $this->tab->get_id() );
    }

    public function test_tab_title_is_cache(): void {
        $this->assertSame( 'Cache', $this->tab->get_title() );
    }

    public function test_tab_icon_is_package(): void {
        $this->assertSame( 'ffc-icon-package', $this->tab->get_icon() );
    }

    public function test_tab_order_is_30(): void {
        $this->assertSame( 30, $this->tab->get_order() );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    // ==================================================================
    // enqueue_scripts()
    // ==================================================================

    public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
        Functions\expect( 'wp_enqueue_script' )->never();
        $this->tab->enqueue_scripts( 'edit.php' );
    }

    public function test_enqueue_scripts_returns_early_when_tab_inactive(): void {
        $_GET['tab'] = 'general';
        Functions\expect( 'wp_enqueue_script' )->never();
        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
    }

    public function test_enqueue_scripts_enqueues_on_cache_tab(): void {
        $_GET['tab'] = 'cache';

        $utils = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
        $utils->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

        $handles = array();
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$handles ) {
            $handles[] = $h;
        } );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );

        // From enqueue_autosave_infra().
        $this->assertContains( 'ffc-core', $handles );
        $this->assertContains( 'ffc-admin-autosave', $handles );
        $this->assertContains( 'ffc-section-collapse', $handles );
        // The tab's own cache-actions script.
        $this->assertContains( 'ffc-cache-actions', $handles );
    }

    // ==================================================================
    // render() — error branch (view file missing)
    // ==================================================================

    public function test_render_outputs_error_when_view_missing(): void {
        // Force the real render()'s file_exists() guard to fail so the
        // error-notice branch executes.
        Functions\when( 'FreeFormCertificate\\Settings\\Tabs\\file_exists' )->justReturn( false );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'not found', $output );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_default_for_missing_key(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->with( 'cache_expiration', '' )->andReturn( '' );

        $this->assertSame( '', $this->tab->get_option( 'cache_expiration' ) );
    }
}
