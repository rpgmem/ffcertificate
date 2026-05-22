<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorGeofenceMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorGeofenceMetabox
 */
class FormEditorGeofenceMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorGeofenceMetabox $metabox;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'get_transient' )->justReturn( array() );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        $this->metabox = new FormEditorGeofenceMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( $config = array() ): string {
        Functions\when( 'get_post_meta' )->justReturn( $config );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 77;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_outputs_geofence_container_with_tabbed_layout(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc-geofence-container', $html );
        $this->assertStringContainsString( 'ffc-geofence-tabs', $html );
    }

    public function test_render_emits_datetime_and_geolocation_tab_buttons(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'data-tab="datetime"', $html );
        $this->assertStringContainsString( 'data-tab="geolocation"', $html );
        $this->assertStringContainsString( 'Date & Time', $html );
        $this->assertStringContainsString( 'Geolocation', $html );
    }

    public function test_render_does_not_throw_with_partial_geofence_config(): void {
        $html = $this->render(
            array(
                'date_start' => '2026-01-01',
                'date_end'   => '2026-12-31',
            )
        );

        $this->assertNotSame( '', $html );
        $this->assertStringContainsString( 'ffc-geofence-container', $html );
    }

    public function test_render_does_not_throw_when_config_is_not_array(): void {
        $html = $this->render( '' );
        $this->assertNotSame( '', $html );
    }

    // ==================================================================
    // Schedule exception subsection (#366 Sprint 2)
    // ==================================================================

    public function test_render_emits_schedule_exception_subsection(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc_geofence[schedule_exception_enabled]', $html );
        $this->assertStringContainsString( 'ffc_geofence[class_time_start]', $html );
        $this->assertStringContainsString( 'ffc_geofence[class_time_end]', $html );
        $this->assertStringContainsString( 'ffc_geofence[schedule_default_mode]', $html );
        $this->assertStringContainsString( 'Per-participant entry/exit exception', $html );
    }

    public function test_render_schedule_exception_collapses_when_toggle_off(): void {
        $html = $this->render( array() );

        $this->assertMatchesRegularExpression(
            '/ffc-collapsed-target ffc-collapsed[^"]*"\s+data-ffc-master="ffc_geofence_schedule_exception_enabled"/s',
            $html,
            'Schedule exception sub-options tbody should carry the ffc-collapsed modifier when the toggle is off'
        );
    }

    public function test_render_schedule_exception_expands_when_toggle_on(): void {
        $html = $this->render(
            array(
                'schedule_exception_enabled' => '1',
                'class_time_start'           => '08:00',
                'class_time_end'             => '17:30',
                'schedule_default_mode'      => 'manual',
            )
        );

        // The sub-options tbody must NOT carry the `ffc-collapsed` modifier
        // when the master toggle is on, and the persisted TIME values must
        // reach the rendered inputs.
        $this->assertDoesNotMatchRegularExpression(
            '/ffc-collapsed-target ffc-collapsed[^"]*"\s+data-ffc-master="ffc_geofence_schedule_exception_enabled"/s',
            $html
        );
        $this->assertStringContainsString( 'value="08:00"', $html );
        $this->assertStringContainsString( 'value="17:30"', $html );
    }
}
