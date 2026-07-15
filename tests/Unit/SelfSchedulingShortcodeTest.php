<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingShortcode
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingShortcodeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( function ( $data ) { return json_encode( $data ); } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, (array) $atts );
        } );
        Functions\when( 'wp_enqueue_style' )->justReturn( true );
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_page' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_login_url' )->justReturn( '/wp-login.php' );
        Functions\when( 'get_permalink' )->justReturn( '/page/' );
        Functions\when( 'get_privacy_policy_url' )->justReturn( '/privacy-policy/' );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'ID' => 0 ) );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wp_rand' )->justReturn( 0 );
        Functions\when( 'wp_hash' )->justReturn( 'captcha_hash' );
        // Monday 2026-06-22 12:00 — used by the business-hours branch tests.
        Functions\when( 'current_time' )->justReturn( '2026-06-22 12:00:00' );
        Functions\when( 'nocache_headers' )->justReturn( null );
        Functions\when( 'do_action' )->justReturn( null );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }
        if ( ! defined( 'FFC_HTML2CANVAS_VERSION' ) ) {
            define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );
        }
        if ( ! defined( 'FFC_JSPDF_VERSION' ) ) {
            define( 'FFC_JSPDF_VERSION', '2.5.1' );
        }

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $shortcode = new SelfSchedulingShortcode();
        $this->assertInstanceOf( SelfSchedulingShortcode::class, $shortcode );
    }

    // ==================================================================
    // render_calendar() — no ID
    // ==================================================================

    public function test_render_calendar_returns_error_without_id(): void {
        $shortcode = new SelfSchedulingShortcode();
        $result = $shortcode->render_calendar( array( 'id' => 0 ) );

        $this->assertStringContainsString( 'Calendar ID is required', $result );
    }

    // ==================================================================
    // render_calendar() — calendar not found
    // ==================================================================

    public function test_render_calendar_returns_error_for_missing_calendar(): void {
        $shortcode = new SelfSchedulingShortcode();
        $result = $shortcode->render_calendar( array( 'id' => 999 ) );

        $this->assertStringContainsString( 'Calendar not found', $result );
    }

    // ==================================================================
    // enqueue_assets() — not singular
    // ==================================================================

    public function test_enqueue_assets_returns_early_on_non_singular(): void {
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_page' )->justReturn( false );

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — no post
    // ==================================================================

    public function test_enqueue_assets_returns_early_without_post(): void {
        Functions\when( 'is_singular' )->justReturn( true );

        global $post;
        $post = null;

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — no shortcode in content
    // ==================================================================

    public function test_enqueue_assets_returns_early_without_shortcode(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'has_shortcode' )->justReturn( false );

        global $post;
        $post = (object) array( 'post_content' => 'No shortcode here' );

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();
        $this->assertTrue( true );
    }

    // ==================================================================
    // enqueue_assets() — full enqueue path
    // ==================================================================

    public function test_enqueue_assets_enqueues_when_shortcode_present(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'has_shortcode' )->justReturn( true );

        $styles  = array();
        $scripts = array();
        Functions\when( 'wp_enqueue_style' )->alias( function ( $handle ) use ( &$styles ) {
            $styles[] = $handle;
            return true;
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function ( $handle ) use ( &$scripts ) {
            $scripts[] = $handle;
            return true;
        } );

        global $post;
        $post = (object) array( 'post_content' => '[ffc_self_scheduling id="1"]' );

        $shortcode = new SelfSchedulingShortcode();
        $shortcode->enqueue_assets();

        $this->assertContains( 'ffc-calendar-frontend', $styles );
        $this->assertContains( 'ffc-calendar-frontend', $scripts );
        $this->assertContains( 'jspdf', $scripts );
    }

    // ==================================================================
    // render_calendar() — full paths (with injected calendar repository)
    // ==================================================================

    /**
     * Build a shortcode whose private calendar_repository is a mock returning
     * the supplied calendar from findByPostId().
     *
     * @param array<string, mixed> $calendar Calendar row to return.
     * @return SelfSchedulingShortcode
     */
    private function shortcode_with_calendar( array $calendar ): SelfSchedulingShortcode {
        $repo = Mockery::mock( 'FreeFormCertificate\\Repositories\\CalendarRepository' );
        $repo->shouldReceive( 'findByPostId' )->andReturn( $calendar )->byDefault();
        $repo->shouldReceive( 'findById' )->andReturn( $calendar )->byDefault();

        $shortcode = new SelfSchedulingShortcode();
        $ref       = new \ReflectionProperty( SelfSchedulingShortcode::class, 'calendar_repository' );
        $ref->setAccessible( true );
        $ref->setValue( $shortcode, $repo );

        return $shortcode;
    }

    /**
     * Minimal active, public, unrestricted calendar with one working day.
     *
     * @param array<string, mixed> $overrides Keys to override.
     * @return array<string, mixed>
     */
    private function make_calendar( array $overrides = array() ): array {
        $working_hours = array(
            array( 'day' => 1, 'start' => '09:00', 'end' => '17:00' ),
        );

        return array_merge(
            array(
                'id'                       => 42,
                'post_id'                  => 100,
                'title'                    => 'Test Calendar',
                'description'              => 'A calendar',
                'status'                   => 'active',
                'visibility'               => 'public',
                'scheduling_visibility'    => 'public',
                'restrict_viewing_to_hours' => 0,
                'restrict_booking_to_hours' => 0,
                'requires_approval'        => 0,
                'advance_booking_min'      => 0,
                'advance_booking_max'      => 30,
                'working_hours'            => wp_json_encode_test( $working_hours ),
            ),
            $overrides
        );
    }

    public function test_render_calendar_returns_error_for_inactive_status(): void {
        $shortcode = $this->shortcode_with_calendar( $this->make_calendar( array( 'status' => 'inactive' ) ) );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'not accepting bookings', $result );
    }

    public function test_render_calendar_returns_error_without_working_hours(): void {
        $shortcode = $this->shortcode_with_calendar( $this->make_calendar( array( 'working_hours' => '' ) ) );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'no working hours configured', $result );
    }

    public function test_render_calendar_full_success_renders_booking_interface(): void {
        $shortcode = $this->shortcode_with_calendar( $this->make_calendar() );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'ffc-audience-calendar', $result );
        $this->assertStringContainsString( 'data-calendar-id="42"', $result );
        $this->assertStringContainsString( 'ffc-self-scheduling-form', $result );
        // Calendar config JSON is emitted.
        $this->assertStringContainsString( 'ffc-calendar-config-42', $result );
    }

    public function test_render_calendar_shows_approval_notice_when_required(): void {
        $shortcode = $this->shortcode_with_calendar( $this->make_calendar( array( 'requires_approval' => 1 ) ) );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'pending approval', $result );
    }

    public function test_render_calendar_private_visibility_anonymous_shows_message(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
            if ( 'ffc_ss_private_display_mode' === $key ) {
                return 'show_title_message';
            }
            return $default;
        } );

        $shortcode = $this->shortcode_with_calendar( $this->make_calendar( array( 'visibility' => 'private' ) ) );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'ffc-visibility-restricted', $result );
        $this->assertStringContainsString( 'ffc-calendar-title', $result );
    }

    public function test_render_calendar_private_visibility_hide_mode_returns_empty(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
            if ( 'ffc_ss_private_display_mode' === $key ) {
                return 'hide';
            }
            return $default;
        } );

        $shortcode = $this->shortcode_with_calendar( $this->make_calendar( array( 'visibility' => 'private' ) ) );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertSame( '', $result );
    }

    public function test_render_calendar_outside_business_hours_blocks_viewing(): void {
        // working_hours only allows Sunday (day 0); "today" is Monday (day 1),
        // so is_outside_business_hours() returns true and viewing is blocked.
        $calendar  = $this->make_calendar(
            array(
                'restrict_viewing_to_hours' => 1,
                'working_hours'             => wp_json_encode_test(
                    array( array( 'day' => 0, 'start' => '09:00', 'end' => '17:00' ) )
                ),
            )
        );
        $shortcode = $this->shortcode_with_calendar( $calendar );
        $result    = $shortcode->render_calendar( array( 'id' => 100 ) );

        $this->assertStringContainsString( 'ffc-visibility-restricted', $result );
    }

    public function test_render_calendar_private_scheduling_anonymous_disables_booking(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
            return 'ffc_ss_scheduling_message' === $key ? 'Please log in to book.' : $default;
        } );

        $shortcode = $this->shortcode_with_calendar(
            $this->make_calendar( array( 'scheduling_visibility' => 'private' ) )
        );
        $result = $shortcode->render_calendar( array( 'id' => 100 ) );

        // Booking form is replaced by the scheduling-restricted message.
        $this->assertStringContainsString( 'ffc-scheduling-restricted', $result );
        $this->assertStringNotContainsString( 'ffc-self-scheduling-form', $result );
    }
}

/**
 * Local json_encode wrapper so fixtures don't depend on the stubbed
 * wp_json_encode (which the suite aliases to json_encode anyway).
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wp_json_encode_test( $data ): string {
    return (string) json_encode( $data );
}
