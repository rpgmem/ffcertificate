<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Shortcodes\DashboardAssetManager;

/**
 * Tests for DashboardAssetManager: user_has_audience_groups() and
 * enqueue_assets() permission logic.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardAssetManager
 */
class DashboardAssetManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<string, bool> Track enqueued scripts/styles */
    private array $enqueued = array();

    /** @var array<string, mixed> Track localized scripts */
    private array $localized = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );

        // Define constants if not defined
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
        }
        if ( ! defined( 'FFC_VERSION' ) ) {
            define( 'FFC_VERSION', '4.12.0' );
        }

        // Mock $wpdb (needed by ReregistrationSubmissionRepository)
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();

        // Track enqueue calls
        $this->enqueued = array();
        $this->localized = array();
        $enqueued = &$this->enqueued;
        $localized = &$this->localized;

        Functions\when( 'wp_enqueue_style' )->alias( function ( $handle ) use ( &$enqueued ) {
            $enqueued[ $handle ] = true;
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function ( $handle ) use ( &$enqueued ) {
            $enqueued[ $handle ] = true;
        } );
        Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
            $localized[ $name ] = $data;
        } );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
        Functions\when( 'rest_url' )->justReturn( 'https://example.com/wp-json/ffc/v1/' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'wp_logout_url' )->justReturn( 'https://example.com/logout' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
        Functions\when( 'wp_timezone_string' )->justReturn( 'America/Sao_Paulo' );
        Functions\when( 'get_option' )->justReturn( array() );

        // Stubs for namespaced functions used by repositories
        Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Reregistration\wp_cache_set' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Reregistration\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Reregistration\user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Audience\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Audience\wp_cache_set' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Audience\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Audience\user_can' )->justReturn( false );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // user_has_audience_groups() — admin user always true
    // ==================================================================

    public function test_user_has_audience_returns_true_for_admin(): void {
        Functions\when( 'user_can' )->alias( function ( $user_id, $cap ) {
            return $cap === 'manage_options';
        } );

        $result = DashboardAssetManager::user_has_audience_groups( 1 );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // user_has_audience_groups() — non-admin without audiences (db returns empty)
    // ==================================================================

    public function test_user_has_audience_returns_false_when_no_groups(): void {
        Functions\when( 'user_can' )->justReturn( false );

        // $wpdb->get_results returns empty by default (no audience membership)
        $result = DashboardAssetManager::user_has_audience_groups( 42 );

        $this->assertFalse( $result );
    }

    // ==================================================================
    // user_has_audience_groups() — non-admin with audiences
    // ==================================================================

    public function test_user_has_audience_returns_true_when_user_has_groups(): void {
        Functions\when( 'user_can' )->justReturn( false );

        // Make $wpdb->get_results return audience data for this test
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'id' => 1, 'name' => 'Group A' ) ) );

        $result = DashboardAssetManager::user_has_audience_groups( 42 );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // enqueue_assets() — enqueues required styles and scripts
    // ==================================================================

    public function test_enqueue_assets_registers_required_assets(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        DashboardAssetManager::enqueue_assets();

        $this->assertArrayHasKey( 'ffc-common', $this->enqueued );
        $this->assertArrayHasKey( 'ffc-dashboard', $this->enqueued );
        $this->assertArrayHasKey( 'ffc-working-hours', $this->enqueued );
        $this->assertArrayHasKey( 'ffc-reregistration-frontend', $this->enqueued );
    }

    // ==================================================================
    // enqueue_assets() — localizes dashboard script with correct data
    // ==================================================================

    public function test_enqueue_assets_localizes_dashboard_data(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_current_user_id' )->justReturn( 5 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        DashboardAssetManager::enqueue_assets();

        $this->assertArrayHasKey( 'ffcDashboard', $this->localized );
        $dashboard = $this->localized['ffcDashboard'];

        $this->assertArrayHasKey( 'ajaxUrl', $dashboard );
        $this->assertArrayHasKey( 'restUrl', $dashboard );
        $this->assertArrayHasKey( 'nonce', $dashboard );
        $this->assertArrayHasKey( 'strings', $dashboard );
        $this->assertFalse( $dashboard['viewAsUserId'] );
    }

    // ==================================================================
    // enqueue_assets() — admin user sees all tabs
    // ==================================================================

    public function test_enqueue_assets_admin_sees_certificates_and_appointments(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->alias( function ( $u, $cap ) {
            return $cap === 'manage_options';
        } );

        // $wpdb returns empty for audience queries (admin bypasses via user_can)
        DashboardAssetManager::enqueue_assets();

        $dashboard = $this->localized['ffcDashboard'];
        $this->assertTrue( $dashboard['canViewCertificates'] );
        $this->assertTrue( $dashboard['canViewAppointments'] );
    }

    // ==================================================================
    // enqueue_assets() — view-as mode passes user ID
    // ==================================================================

    public function test_enqueue_assets_view_as_mode(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        DashboardAssetManager::enqueue_assets( 99 );

        $dashboard = $this->localized['ffcDashboard'];
        $this->assertSame( 99, $dashboard['viewAsUserId'] );
        $this->assertTrue( $dashboard['isAdminViewing'] );
    }

    // ==================================================================
    // enqueue_assets() — working hours localization
    // ==================================================================

    public function test_enqueue_assets_localizes_working_hours(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'asset_suffix' )->andReturn( '' );
        $utilsMock->shouldReceive( 'enqueue_dark_mode' )->once();

        $user = Mockery::mock( 'WP_User' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_user_by' )->justReturn( $user );
        Functions\when( 'user_can' )->justReturn( false );

        DashboardAssetManager::enqueue_assets();

        $this->assertArrayHasKey( 'ffcWorkingHours', $this->localized );
        $wh = $this->localized['ffcWorkingHours'];
        $this->assertArrayHasKey( 'days', $wh );
        $this->assertCount( 7, $wh['days'] );
        $this->assertSame( 0, $wh['days'][0]['value'] );
    }
}
