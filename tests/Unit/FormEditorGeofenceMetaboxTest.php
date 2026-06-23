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
        // render_time() / render_geolocation() now enqueue the metabox
        // UI-wiring asset instead of echoing inline <script>; stub the
        // enqueue so the render paths don't fatal under Brain\Monkey.
        Functions\when( 'wp_enqueue_script' )->justReturn( null );

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
        $this->metabox->render_time( $post );
        $this->metabox->render_geolocation( $post );
        return (string) ob_get_clean();
    }

    public function test_render_outputs_both_sections_without_inner_tab_bar(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc-geofence-container', $html );
        // The former inner "Date & Time / Geolocation" button bar is gone —
        // those sections are now two top-level form-editor tabs.
        $this->assertStringNotContainsString( 'ffc-geofence-tabs', $html );
        $this->assertStringNotContainsString( 'data-tab=', $html );
    }

    public function test_render_emits_both_section_master_toggles(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc_geofence[datetime_enabled]', $html );
        $this->assertStringContainsString( 'ffc_geofence[geo_enabled]', $html );
    }

    public function test_render_time_outputs_datetime_section_only(): void {
        Functions\when( 'get_post_meta' )->justReturn( array() );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 77;
        ob_start();
        $this->metabox->render_time( $post );
        $html = (string) ob_get_clean();

        // Carries the POST sentinel + the datetime + schedule-exception fields.
        $this->assertStringContainsString( 'ffc_geofence[_save]', $html );
        $this->assertStringContainsString( 'ffc_geofence[datetime_enabled]', $html );
        $this->assertStringContainsString( 'ffc_geofence[schedule_exception_enabled]', $html );
        // …and none of the geolocation controls.
        $this->assertStringNotContainsString( 'ffc_geofence[geo_enabled]', $html );
    }

    public function test_render_geolocation_outputs_geo_section_only(): void {
        Functions\when( 'get_post_meta' )->justReturn( array() );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 77;
        ob_start();
        $this->metabox->render_geolocation( $post );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'ffc_geofence[geo_enabled]', $html );
        // The area-source radios the (now-extracted) toggleGeoSource handler
        // drives are unique to this section.
        $this->assertStringContainsString( 'ffc_geofence[geo_area_source]', $html );
        // …and none of the datetime controls.
        $this->assertStringNotContainsString( 'ffc_geofence[datetime_enabled]', $html );
    }

    public function test_render_emits_end_min_when_multi_day_on(): void {
        $html = $this->render(
            array(
                'multi_day'  => '1',
                'date_start' => '2026-06-05',
                'date_end'   => '2026-06-10',
            )
        );

        // With multi-day on the End date carries a min of Start + 1 day.
        $this->assertStringContainsString( 'min="2026-06-06"', $html );
    }

    public function test_render_omits_end_min_when_multi_day_off(): void {
        // Regression: with multi-day off the End field is hidden and mirrors
        // Start, so emitting a min of start+1 made the hidden control fail
        // native validation and block submission ("invalid form control … is
        // not focusable"). The min must be absent in this state.
        $html = $this->render(
            array(
                'multi_day'  => '0',
                'date_start' => '2026-06-05',
                'date_end'   => '2026-06-05',
            )
        );

        $this->assertStringContainsString( 'id="ffc_geofence_date_end"', $html );
        $this->assertStringContainsString( 'min=""', $html );
        $this->assertStringNotContainsString( 'min="2026-06-06"', $html );
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

    // ==================================================================
    // Global IP-API kill-switch gate (Sprint 5 — form-editor UX batch)
    // ==================================================================

    public function test_render_geolocation_disables_ip_toggle_when_global_off(): void {
        // get_option( 'ffc_geolocation_settings' ) → empty array
        // ⇒ $global_ip_api_enabled === false ⇒ toggle must be disabled +
        // a warning notice with a link to Settings → Geolocation appears.
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_post_meta' )->justReturn( array() );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 77;

        ob_start();
        $this->metabox->render_geolocation( $post );
        $html = (string) ob_get_clean();

        // The toggle's visible checkbox must carry the disabled attr.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="ffc_geofence\[geo_ip_enabled\]"[^>]*disabled/s',
            $html,
            'IP toggle must be rendered disabled when the global ip_api_enabled is off'
        );
        // And the warning notice links to the global setting tab.
        $this->assertStringContainsString( 'page=ffc-settings&tab=geolocation', $html );
        $this->assertStringContainsString( 'IP geolocation is disabled globally', $html );
    }

    public function test_render_geolocation_enables_ip_toggle_when_global_on(): void {
        // Stub the global option to return ip_api_enabled = true.
        Functions\when( 'get_option' )->alias(
            static function ( $key, $default = array() ) {
                return 'ffc_geolocation_settings' === $key
                    ? array( 'ip_api_enabled' => true )
                    : $default;
            }
        );
        Functions\when( 'get_post_meta' )->justReturn( array() );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 77;

        ob_start();
        $this->metabox->render_geolocation( $post );
        $html = (string) ob_get_clean();

        // The toggle should NOT carry the disabled attr, and the warning
        // notice should NOT appear.
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="ffc_geofence\[geo_ip_enabled\]"[^>]*disabled/s',
            $html
        );
        $this->assertStringNotContainsString( 'IP geolocation is disabled globally', $html );
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
