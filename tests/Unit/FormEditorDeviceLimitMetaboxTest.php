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
        Monkey\tearDown();
        parent::tearDown();
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
}
