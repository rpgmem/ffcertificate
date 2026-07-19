<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabGeneral;

/**
 * Tests for TabGeneral: general settings tab.
 *
 * Exercises the real render() (including the shipped view partial) and the
 * real enqueue_scripts() so the tab class body is covered end-to-end.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabGeneral
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabGeneralTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabGeneral */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
        // file-level guard require_once's wp-includes/formatting.php when the
        // function is undefined, which fatals under the test WP-less ABSPATH.
        Functions\when( 'wp_kses_post' )->returnArg();
        class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabGeneral' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();
        // The General view now renders a capability-gated "Module settings"
        // index (rpgmem/ffcertificate#711). Default the cap check to true so the
        // render tests exercise the card; individual tests override as needed.
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );

        $this->tab = new TabGeneral();
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
        $this->assertSame( 'general', $this->tab->get_id() );
    }

    public function test_tab_title(): void {
        $this->assertSame( 'General', $this->tab->get_title() );
    }

    public function test_tab_icon(): void {
        $this->assertSame( 'ffc-icon-settings', $this->tab->get_icon() );
    }

    public function test_tab_order(): void {
        $this->assertSame( 10, $this->tab->get_order() );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    // ==================================================================
    // render() — real view partial include (happy path)
    // ==================================================================

    public function test_render_includes_real_view(): void {
        // The shipped view resolves every $ffcertificate_get_option(...) call
        // through SettingsReader::get (via the bound $settings->get_option
        // closure); stub it to return the passed default so the include runs.
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->andReturnUsing(
            function ( $key, $default = '' ) {
                return $default;
            }
        );

        // WP core date/time formats (divergence notice) — keep aligned with
        // the plugin defaults so the divergence branch is exercised without
        // depending on real option storage.
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'date_format' === $key ) {
                    return 'Y-m-d';
                }
                if ( 'time_format' === $key ) {
                    return 'H:i';
                }
                return $default;
            }
        );

        Functions\when( 'date_i18n' )->alias(
            function ( $fmt, $ts = null ) {
                return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
            }
        );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-settings-wrap', $output );
        $this->assertStringContainsString( 'ffc_date_format', $output );
        $this->assertStringContainsString( 'qr_default_size', $output );

        // Module-settings index is shown when the user can view a module's
        // settings (current_user_can stubbed true in setUp).
        $this->assertStringContainsString( 'ffc-module-settings-index', $output );
        $this->assertStringContainsString( 'page=ffc-scheduling-settings', $output );
        $this->assertStringContainsString( 'page=ffc-recruitment', $output );
    }

    // ==================================================================
    // render() — module-settings index hidden without module caps
    // ==================================================================

    public function test_render_hides_module_index_without_module_caps(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->andReturnUsing(
            function ( $key, $default = '' ) {
                return $default;
            }
        );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'date_i18n' )->alias(
            function ( $fmt, $ts = null ) {
                return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
            }
        );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        // No module view caps -> the index card is not rendered.
        Functions\when( 'current_user_can' )->justReturn( false );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'ffc-module-settings-index', $output );
        // The rest of the tab still renders.
        $this->assertStringContainsString( 'ffc-settings-wrap', $output );
    }

    // ==================================================================
    // render() — happy path with divergence notice shown
    // ==================================================================

    public function test_render_shows_divergence_notice(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->andReturnUsing(
            function ( $key, $default = '' ) {
                if ( 'date_format' === $key ) {
                    return 'd/m/Y';
                }
                if ( 'time_format' === $key ) {
                    return 'g:i a';
                }
                return $default;
            }
        );

        // WP global formats differ from the plugin's -> divergence banner.
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( 'date_format' === $key ) {
                    return 'Y-m-d';
                }
                if ( 'time_format' === $key ) {
                    return 'H:i';
                }
                return $default;
            }
        );

        Functions\when( 'date_i18n' )->alias(
            function ( $fmt, $ts = null ) {
                return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
            }
        );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-settings-divergence-notice', $output );
    }

    // ==================================================================
    // render() — smart-match legacy combined date format -> custom
    // ==================================================================

    public function test_render_smart_matches_legacy_date_format(): void {
        $reader = Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' );
        $reader->shouldReceive( 'get' )->andReturnUsing(
            function ( $key, $default = '' ) {
                // A pre-#248 combined date+time value that strips to a preset.
                if ( 'date_format' === $key ) {
                    return 'Y-m-d H:i';
                }
                return $default;
            }
        );

        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'date_i18n' )->alias(
            function ( $fmt, $ts = null ) {
                return gmdate( (string) $fmt, null === $ts ? time() : (int) $ts );
            }
        );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( null );

        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-settings-wrap', $output );
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
        $_GET['tab'] = 'other';
        Functions\expect( 'wp_enqueue_script' )->never();
        $this->tab->enqueue_scripts( 'ffc_form_page_ffc-settings' );
    }

    public function test_enqueue_scripts_enqueues_autosave_on_general_tab(): void {
        $_GET['tab'] = 'general';

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
