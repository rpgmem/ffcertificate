<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorDeviceLimitMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorDeviceLimitMetabox
 */
class FormEditorDeviceLimitMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorDeviceLimitMetabox $metabox;

    /** @var array<string, mixed> */
    private array $meta_values = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        // Per-key meta values: each key looked up by the renderer.
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
            return $this->meta_values[ $key ] ?? '';
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_parse_args' )->alias( function ( $a, $d ) { return array_merge( (array) $d, (array) $a ); } );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        $this->metabox = new FormEditorDeviceLimitMetabox();
    }

    protected function tearDown(): void {
        $this->reset_rate_limit_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset RateLimitChecker's private static settings cache so a global
     * device state set in one test does not leak into the next.
     */
    private function reset_rate_limit_cache(): void {
        if ( ! class_exists( '\FreeFormCertificate\Security\RateLimitChecker' ) ) {
            return;
        }
        $ref = new \ReflectionProperty( '\FreeFormCertificate\Security\RateLimitChecker', 'settings_cache' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );
    }

    /**
     * Force the global Device Fingerprint subsystem on/off by resetting the
     * settings cache and feeding a matching `ffc_rate_limit_settings` option.
     */
    private function set_global_device_enabled( bool $enabled ): void {
        $this->reset_rate_limit_cache();
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) use ( $enabled ) {
                if ( 'ffc_rate_limit_settings' === $key ) {
                    return array( 'device' => array( 'enabled' => $enabled ) );
                }
                return array();
            }
        );
    }

    private function render(): string {
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 66;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_emits_device_limit_label_row(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc-restriction-label', $html );
        $this->assertStringContainsString( 'physical device', $html );
    }

    public function test_render_disables_warning_when_master_toggle_off(): void {
        $this->meta_values['_ffc_device_limit_enabled'] = '0';

        $html = $this->render();

        $this->assertStringContainsString( 'ffc-restriction-label', $html );
    }

    public function test_render_pre_populates_max_input_from_meta(): void {
        $this->meta_values['_ffc_device_limit_enabled'] = '1';
        $this->meta_values['_ffc_device_limit_max']     = '3';

        $html = $this->render();

        $this->assertStringContainsString( 'value="3"', $html );
    }

    public function test_render_does_not_throw_with_no_meta(): void {
        $html = $this->render();
        $this->assertNotSame( '', $html );
    }

    public function test_render_surfaces_inherited_global_defaults_with_highlight(): void {
        $html = $this->render();

        // Each of the four sub-option descriptions (max / threshold /
        // strong_min / message) shows the value an empty field inherits from
        // global, wrapped in the subtle-highlight `.ffc-global-default` span.
        $this->assertSame(
            4,
            substr_count( $html, 'ffc-global-default' ),
            'max, threshold, strong_min and message must each surface their inherited global value'
        );
        $this->assertStringContainsString( 'inherit the global default', $html );
        $this->assertStringContainsString( 'inherit the global block message', $html );
    }

    // ==================================================================
    // "Enable it" nudge — global ON + form OFF (#647)
    // ==================================================================

    public function test_render_shows_nudge_when_global_on_and_form_off(): void {
        $this->set_global_device_enabled( true );
        $this->meta_values['_ffc_device_limit_enabled'] = '0';

        $html = $this->render();

        $this->assertStringContainsString( 'ffc-device-nudge', $html );
        $this->assertStringContainsString( 'shared devices', $html );
        // The global-off warning must NOT appear when the subsystem is on.
        $this->assertStringNotContainsString( 'Disabled globally', $html );
    }

    public function test_render_hides_nudge_when_form_already_on(): void {
        $this->set_global_device_enabled( true );
        $this->meta_values['_ffc_device_limit_enabled'] = '1';

        $html = $this->render();

        $this->assertStringNotContainsString( 'ffc-device-nudge', $html );
    }

    public function test_render_hides_nudge_when_global_off(): void {
        $this->set_global_device_enabled( false );
        $this->meta_values['_ffc_device_limit_enabled'] = '0';

        $html = $this->render();

        // Global-off shows the "disabled globally" warning, not the nudge.
        $this->assertStringNotContainsString( 'ffc-device-nudge', $html );
        $this->assertStringContainsString( 'Disabled globally', $html );
    }
}
