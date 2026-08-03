<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabModulos;

/**
 * Tests for TabModulos: the Modules settings tab scaffold.
 *
 * Covers init() tab metadata and both branches of render() (the real view
 * include on success, the error notice when the view file is missing).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabModulos
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabModulosTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabModulos */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wp_admin_notice' )->alias(
            static function ( $message, $args = array() ) {
                $ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
                $ffc_cls  = 'notice notice-' . $ffc_type;
                if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
                if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
                $ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
                echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
            }
        );

        // Pre-stub wp_kses_post BEFORE the SettingsTab base autoloads — its
        // file-level guard require_once's wp-includes/formatting.php when the
        // function is undefined, fataling under the WP-less test ABSPATH.
        Functions\when( 'wp_kses_post' )->returnArg();
        class_exists( '\\FreeFormCertificate\\Settings\\Tabs\\TabModulos' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        // The Modules view reads each module's on/off slot via SettingsReader
        // (→ get_option) and renders a toggle per module via AdminUI.
        Functions\when( 'get_option' )->justReturn( array() );

        $this->tab = new TabModulos();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_modulos(): void {
        $this->assertSame( 'modulos', $this->tab->get_id() );
    }

    public function test_tab_title_is_modules(): void {
        $this->assertSame( 'Modules', $this->tab->get_title() );
    }

    public function test_tab_icon_is_package(): void {
        $this->assertSame( 'ffc-icon-package', $this->tab->get_icon() );
    }

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
    }

    // ==================================================================
    // render()
    // ==================================================================

    public function test_render_includes_view_on_success(): void {
        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-icon-package', $output );
        $this->assertStringContainsString( 'Modules', $output );
        // A per-module toggle is rendered for every canonical module: assert the
        // Certificates and Recruitment autosave keys are present.
        $this->assertStringContainsString( 'module_certificates_enabled', $output );
        $this->assertStringContainsString( 'module_recruitment_enabled', $output );
        // URL Shortener reuses its historical slot rather than a module_ key.
        $this->assertStringContainsString( 'url_shortener_enabled', $output );
    }

    public function test_render_shows_certificates_cron_pause_note(): void {
        ob_start();
        $this->tab->render();
        $output = ob_get_clean();

        // Certificates carries a heads-up note that disabling it pauses the
        // daily expired-ticket cleanup cron (the #806 gating).
        $this->assertStringContainsString( 'ffc-module-note', $output );
        $this->assertStringContainsString( 'expired-ticket cleanup is paused', $output );
    }

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
}
