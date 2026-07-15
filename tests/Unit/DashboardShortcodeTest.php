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
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
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
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
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

        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
        $ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();
        $ri_mock->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
            return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
        } )->byDefault();

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

        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();
        $ri_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
        $ri_mock->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
            return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
        } )->byDefault();

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

    // ==================================================================
    // send_nocache_headers() — shortcode present → cache-busting fires
    // ==================================================================

    public function test_send_nocache_headers_sets_donotcachepage_when_shortcode_present(): void {
        global $post;
        $post = Mockery::mock( 'WP_Post' );
        $post->post_content = '[user_dashboard_personal]';

        Functions\when( 'has_shortcode' )->justReturn( true );

        $nocache_called = false;
        Functions\when( 'nocache_headers' )->alias( function () use ( &$nocache_called ) {
            $nocache_called = true;
        } );
        $action_fired = false;
        Functions\when( 'do_action' )->alias( function ( $hook ) use ( &$action_fired ) {
            if ( 'litespeed_control_set_nocache' === $hook ) {
                $action_fired = true;
            }
        } );
        // header() is a native PHP function Brain\Monkey cannot redefine. Under
        // CLI, output has already been emitted, so the real call would raise a
        // "headers already sent" warning. Suppress it — the assertions below
        // verify the observable side effects (DONOTCACHEPAGE, nocache_headers,
        // the LiteSpeed action) rather than the raw header() call.
        $prev = error_reporting( error_reporting() & ~E_WARNING );
        try {
            DashboardShortcode::send_nocache_headers();
        } finally {
            error_reporting( $prev );
        }

        $this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
        $this->assertTrue( $nocache_called );
        $this->assertTrue( $action_fired );
    }

    // ==================================================================
    // render() — all capability tabs visible (full render path)
    // ==================================================================

    /**
     * Wire up every collaborator the full render path touches so all tab
     * branches are exercised. Alias-mocks the shortcode's direct collaborators.
     */
    private function wireFullRenderCollaborators( bool $view_as = false, ?array $reregistrations = null ): void {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_user_by' )->justReturn( $user );
        // Every capability check returns true so all cap-gated tabs render.
        Functions\when( 'user_can' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        $view_mode = Mockery::mock( 'alias:\FreeFormCertificate\Shortcodes\DashboardViewMode' );
        $view_mode->shouldReceive( 'get_view_as_user_id' )->andReturn( $view_as ? 55 : false )->byDefault();
        $view_mode->shouldReceive( 'render_admin_viewing_banner' )->andReturn( '<div class="ffc-view-as-banner">viewing</div>' )->byDefault();

        $asset_mgr = Mockery::mock( 'alias:\FreeFormCertificate\Shortcodes\DashboardAssetManager' );
        $asset_mgr->shouldReceive( 'enqueue_assets' )->andReturn( null )->byDefault();
        $asset_mgr->shouldReceive( 'user_has_audience_groups' )->andReturn( true )->byDefault();

        $reader = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
        $reader->shouldReceive( 'get_all_by_user' )->andReturn( array( array( 'id' => 1 ) ) )->byDefault();

        $recruitment = Mockery::mock( 'alias:\FreeFormCertificate\Recruitment\RecruitmentDashboardSection' );
        $recruitment->shouldReceive( 'render_for_user' )->andReturn( '<div class="ffc-recruitment-body">calls</div>' )->byDefault();

        // Reregistration banners: empty unless the caller supplies a set.
        $frontend = Mockery::mock( 'alias:\FreeFormCertificate\Reregistration\ReregistrationFrontend' );
        $frontend->shouldReceive( 'get_user_reregistrations' )
            ->andReturn( null === $reregistrations ? array() : $reregistrations )->byDefault();

        // DateFormatter is only reached by the actionable-banner branch, but
        // alias-mock it unconditionally so it's stubbed whenever banners render.
        $fmt = Mockery::mock( 'alias:\FreeFormCertificate\Core\DateFormatter' );
        $fmt->shouldReceive( 'format_date' )->andReturn( '01/01/2030' )->byDefault();

        $ri = Mockery::mock( 'alias:\FreeFormCertificate\Core\RequestInput' );
        $ri->shouldReceive( 'get_get_string' )->andReturnUsing( function ( $key, $default = '' ) {
            return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? $_GET[ $key ] : $default;
        } )->byDefault();
    }

    public function test_render_all_tabs_visible_when_all_caps_granted(): void {
        $this->wireFullRenderCollaborators( false );

        $output = DashboardShortcode::render();

        // Every cap-gated tab button + panel present.
        $this->assertStringContainsString( 'ffc-tab-certificates', $output );
        $this->assertStringContainsString( 'ffc-tab-appointments', $output );
        $this->assertStringContainsString( 'ffc-tab-audience', $output );
        $this->assertStringContainsString( 'ffc-tab-reregistrations', $output );
        $this->assertStringContainsString( 'ffc-tab-recruitment', $output );
        $this->assertStringContainsString( 'tab-certificates', $output );
        $this->assertStringContainsString( 'tab-recruitment', $output );
        // Server-rendered recruitment body reused as tab content.
        $this->assertStringContainsString( 'ffc-recruitment-body', $output );
        // Reregistration form panel present when reregistrations tab visible.
        $this->assertStringContainsString( 'ffc-rereg-form-panel', $output );
    }

    // ==================================================================
    // render() — admin view-as mode renders the banner
    // ==================================================================

    public function test_render_shows_admin_viewing_banner_in_view_as_mode(): void {
        // view_as user 55 differs from current user 1 → banner renders.
        $this->wireFullRenderCollaborators( true );

        $output = DashboardShortcode::render();

        $this->assertStringContainsString( 'ffc-view-as-banner', $output );
    }

    // ==================================================================
    // render() — reregistration banners (all status branches)
    // ==================================================================

    public function test_render_reregistration_banners_all_statuses(): void {
        Functions\when( '_n' )->alias( function ( $single, $plural, $n ) {
            return 1 === $n ? $single : $plural;
        } );

        $this->wireFullRenderCollaborators( false, array(
            // Completed / approved (can_submit false).
            array(
                'id'                => 10,
                'title'             => 'Approved Campaign',
                'can_submit'        => false,
                'submission_status' => 'approved',
                'magic_link'        => 'https://example.com/ficha/10',
                'end_date'          => '2030-01-01',
            ),
            // Submitted / pending review (can_submit false, no magic link).
            array(
                'id'                => 11,
                'title'             => 'Submitted Campaign',
                'can_submit'        => false,
                'submission_status' => 'submitted',
                'magic_link'        => '',
                'end_date'          => '2030-01-01',
            ),
            // Actionable — in_progress (can_submit true), far deadline.
            array(
                'id'                => 12,
                'title'             => 'In Progress Campaign',
                'can_submit'        => true,
                'submission_status' => 'in_progress',
                'end_date'          => '2030-01-01',
            ),
            // Actionable — rejected (can_submit true), urgent (deadline soon).
            array(
                'id'                => 13,
                'title'             => 'Rejected Campaign',
                'can_submit'        => true,
                'submission_status' => 'rejected',
                'end_date'          => gmdate( 'Y-m-d', time() + 86400 ),
            ),
            // Actionable — not started (can_submit true), else branch.
            array(
                'id'                => 14,
                'title'             => 'New Campaign',
                'can_submit'        => true,
                'submission_status' => 'not_started',
                'end_date'          => gmdate( 'Y-m-d', time() + ( 5 * 86400 ) ),
            ),
        ) );

        $output = DashboardShortcode::render();

        $this->assertStringContainsString( 'Approved Campaign', $output );
        $this->assertStringContainsString( 'ffc-rereg-completed', $output );
        $this->assertStringContainsString( 'Submitted Campaign', $output );
        $this->assertStringContainsString( 'ffc-rereg-pending-review', $output );
        $this->assertStringContainsString( 'In Progress Campaign', $output );
        $this->assertStringContainsString( 'Continue Reregistration', $output );
        $this->assertStringContainsString( 'Rejected Campaign', $output );
        $this->assertStringContainsString( 'Resubmit Reregistration', $output );
        // Urgent class present for the near-deadline rejected banner.
        $this->assertStringContainsString( 'ffc-rereg-urgent', $output );
        $this->assertStringContainsString( 'New Campaign', $output );
        $this->assertStringContainsString( 'Complete Reregistration', $output );
    }
}
