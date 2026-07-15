<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabUrlShortener;

/**
 * Tests for TabUrlShortener: URL shortener settings tab.
 *
 * Exercises the real render() (including the shipped view partial) and the
 * real enqueue_scripts() so the tab class body is covered end-to-end.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabUrlShortener
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabUrlShortenerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabUrlShortener */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
        // file-level guard require_once's wp-includes/formatting.php when the
        // function is undefined, which fatals under the test WP-less ABSPATH.
        Functions\when( 'wp_kses_post' )->returnArg();
        class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabUrlShortener' );

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

        $this->tab = new TabUrlShortener();
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id(): void {
        $this->assertSame( 'url_shortener', $this->tab->get_id() );
    }

    public function test_tab_title(): void {
        $this->assertSame( 'URL Shortener', $this->tab->get_title() );
    }

    public function test_tab_icon(): void {
        $this->assertSame( 'ffc-icon-link', $this->tab->get_icon() );
    }

    public function test_tab_order(): void {
        $this->assertSame( 35, $this->tab->get_order() );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    // ==================================================================
    // render() — real view partial include (happy path)
    // ==================================================================

    public function test_render_includes_real_view(): void {
        // The shipped view reads SettingsReader::all() and iterates the
        // public post types; stub both so the include runs cleanly.
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'all' )->andReturn(
            array(
                'url_shortener_enabled'       => 1,
                'url_shortener_prefix'        => 'go',
                'url_shortener_code_length'   => 6,
                'url_shortener_auto_create'   => 1,
                'url_shortener_redirect_type' => 302,
                'url_shortener_post_types'    => array( 'post', 'page' ),
            )
        );
        $reader->shouldReceive( 'get' )->andReturnUsing(
            function ( $key, $default = '' ) {
                return $default;
            }
        );

        $pt       = new \stdClass();
        $pt->name = 'post';
        $labels   = new \stdClass();
        $labels->singular_name = 'Post';
        $pt->labels            = $labels;

        Functions\when( 'get_post_types' )->justReturn( array( 'post' => $pt ) );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-settings-wrap', $output );
        $this->assertStringContainsString( 'url_shortener_prefix', $output );
    }

    // ==================================================================
    // render() — error branch (view file missing)
    // ==================================================================

    public function test_render_error_when_view_missing(): void {
        // Force the real render()'s file_exists() guard to fail so its error
        // branch (the "view file not found" notice) executes.
        Functions\when( 'FreeFormCertificate\\Settings\\Tabs\\file_exists' )->justReturn( false );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'not found', $output );
    }

    // ==================================================================
    // enqueue_scripts()
    // ==================================================================

    public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
        Functions\expect( 'wp_enqueue_script' )->never();
        $this->tab->enqueue_scripts( 'edit.php' );
    }

    public function test_enqueue_scripts_returns_early_for_wrong_tab(): void {
        $_GET['tab'] = 'general';
        Functions\expect( 'wp_enqueue_script' )->never();
        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
    }

    public function test_enqueue_scripts_enqueues_autosave_on_url_shortener_tab(): void {
        $_GET['tab'] = 'url_shortener';

        $utils = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
        $utils->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

        $handles = array();
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$handles ) {
            $handles[] = $h;
        } );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );

        $this->assertContains( 'ffc-core', $handles );
        $this->assertContains( 'ffc-admin-autosave', $handles );
        $this->assertContains( 'ffc-section-collapse', $handles );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_default_for_missing_key(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->with( 'missing', 'fallback' )->andReturn( 'fallback' );

        $this->assertSame( 'fallback', $this->tab->get_option( 'missing', 'fallback' ) );
    }
}
