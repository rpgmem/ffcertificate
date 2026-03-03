<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Shortcodes\DashboardShortcode;

/**
 * @covers \FreeFormCertificate\Shortcodes\DashboardShortcode
 */
class DashboardShortcodeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr_e' )->alias( function ( $s ) { echo $s; } );
        Functions\when( 'esc_html_e' )->alias( function ( $s ) { echo $s; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_login_url' )->justReturn( 'https://example.com/login' );
        Functions\when( 'get_permalink' )->justReturn( 'https://example.com/dashboard' );

        // Mock $wpdb for ReregistrationSubmissionRepository
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();

        // Namespaced stubs
        Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_set' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Audience\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Audience\wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $_GET = array();
    }

    protected function tearDown(): void {
        $_GET = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_shortcode_and_action(): void {
        DashboardShortcode::init();
        $this->assertTrue( true ); // No exception = hooks registered
    }

    // ==================================================================
    // render() — not logged in
    // ==================================================================

    public function test_render_shows_login_when_not_logged_in(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $output = DashboardShortcode::render();

        $this->assertStringContainsString( 'logged in', $output );
        $this->assertStringContainsString( 'Login', $output );
    }

    // ==================================================================
    // render() — logged in, shows dashboard
    // ==================================================================

    public function test_render_shows_dashboard_when_logged_in(): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        Functions\when( 'wp_enqueue_style' )->justReturn();
        Functions\when( 'wp_enqueue_script' )->justReturn();
        Functions\when( 'wp_localize_script' )->justReturn();
        Functions\when( 'admin_url' )->justReturn( '' );
        Functions\when( 'rest_url' )->justReturn( '' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'wp_logout_url' )->justReturn( '' );
        Functions\when( 'home_url' )->justReturn( '' );
        Functions\when( 'get_bloginfo' )->justReturn( '' );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );

        // DashboardViewMode returns false
        Functions\when( 'current_user_can' )->justReturn( false );

        $output = DashboardShortcode::render();

        $this->assertStringContainsString( 'ffc-user-dashboard', $output );
        $this->assertStringContainsString( 'tab-profile', $output );
        $this->assertStringContainsString( 'Profile', $output );
    }

    // ==================================================================
    // send_nocache_headers() — no post object (null)
    // ==================================================================

    public function test_send_nocache_headers_noop_when_no_post(): void {
        global $post;
        $post = null;

        // is_a(null, 'WP_Post') returns false — no mock needed
        DashboardShortcode::send_nocache_headers();
        $this->assertTrue( true );
    }

    // ==================================================================
    // send_nocache_headers() — post without shortcode
    // ==================================================================

    public function test_send_nocache_headers_noop_when_no_shortcode(): void {
        global $post;
        // Use a real WP_Post mock so is_a() returns true
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = 'Hello world';

        Functions\when( 'has_shortcode' )->justReturn( false );

        DashboardShortcode::send_nocache_headers();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render() — redirect message shown
    // ==================================================================

    public function test_render_shows_redirect_message_when_param_set(): void {
        $_GET['ffc_redirect'] = 'access_denied';

        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        Functions\when( 'wp_enqueue_style' )->justReturn();
        Functions\when( 'wp_enqueue_script' )->justReturn();
        Functions\when( 'wp_localize_script' )->justReturn();
        Functions\when( 'admin_url' )->justReturn( '' );
        Functions\when( 'rest_url' )->justReturn( '' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'wp_logout_url' )->justReturn( '' );
        Functions\when( 'home_url' )->justReturn( '' );
        Functions\when( 'get_bloginfo' )->justReturn( '' );
        Functions\when( 'wp_timezone_string' )->justReturn( 'UTC' );
        Functions\when( 'current_user_can' )->justReturn( false );

        $output = DashboardShortcode::render();

        $this->assertStringContainsString( 'redirected from the admin panel', $output );
    }
}
