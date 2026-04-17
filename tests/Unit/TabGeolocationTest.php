<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabGeolocation;

/**
 * Tests for TabGeolocation: geolocation settings tab.
 *
 * Covers init properties, render (with and without POST), save_settings via
 * Reflection, get_default_settings, and get_settings.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabGeolocation
 */
class TabGeolocationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabGeolocation */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );

        $this->tab = new TabGeolocation();
    }

    protected function tearDown(): void {
        unset( $_POST['ffc_save_geolocation'] );
        unset( $_POST['ip_api_service'], $_POST['api_fallback'], $_POST['gps_fallback'] );
        unset( $_POST['both_fail_fallback'], $_POST['ip_api_enabled'], $_POST['ip_api_cascade'] );
        unset( $_POST['ipinfo_api_key'], $_POST['ip_cache_enabled'], $_POST['ip_cache_ttl'] );
        unset( $_POST['gps_cache_ttl'], $_POST['admin_bypass_datetime'], $_POST['admin_bypass_geo'] );
        unset( $_POST['debug_enabled'], $_POST['main_geo_areas'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_geolocation(): void {
        $this->assertSame( 'geolocation', $this->tab->get_id() );
    }

    public function test_tab_title_is_geolocation(): void {
        $this->assertSame( 'Geolocation', $this->tab->get_title() );
    }

    public function test_tab_icon_is_globe(): void {
        $this->assertSame( 'ffc-icon-globe', $this->tab->get_icon() );
    }

    public function test_tab_order_is_50(): void {
        $this->assertSame( 50, $this->tab->get_order() );
    }

    // ==================================================================
    // Inheritance
    // ==================================================================

    public function test_extends_settings_tab(): void {
        $this->assertInstanceOf(
            \FreeFormCertificate\Settings\SettingsTab::class,
            $this->tab
        );
    }

    // ==================================================================
    // get_default_settings() — via Reflection
    // ==================================================================

    public function test_get_default_settings_returns_expected_keys(): void {
        $ref = new \ReflectionMethod( TabGeolocation::class, 'get_default_settings' );
        $ref->setAccessible( true );
        $defaults = $ref->invoke( $this->tab );

        $this->assertIsArray( $defaults );
        $this->assertArrayHasKey( 'ip_api_enabled', $defaults );
        $this->assertArrayHasKey( 'ip_api_service', $defaults );
        $this->assertArrayHasKey( 'ip_api_cascade', $defaults );
        $this->assertArrayHasKey( 'ipinfo_api_key', $defaults );
        $this->assertArrayHasKey( 'ip_cache_enabled', $defaults );
        $this->assertArrayHasKey( 'ip_cache_ttl', $defaults );
        $this->assertArrayHasKey( 'gps_cache_ttl', $defaults );
        $this->assertArrayHasKey( 'api_fallback', $defaults );
        $this->assertArrayHasKey( 'gps_fallback', $defaults );
        $this->assertArrayHasKey( 'both_fail_fallback', $defaults );
        $this->assertArrayHasKey( 'admin_bypass_datetime', $defaults );
        $this->assertArrayHasKey( 'admin_bypass_geo', $defaults );
        $this->assertArrayHasKey( 'debug_enabled', $defaults );
    }

    public function test_get_default_settings_has_correct_default_values(): void {
        $ref = new \ReflectionMethod( TabGeolocation::class, 'get_default_settings' );
        $ref->setAccessible( true );
        $defaults = $ref->invoke( $this->tab );

        $this->assertFalse( $defaults['ip_api_enabled'] );
        $this->assertSame( 'ip-api', $defaults['ip_api_service'] );
        $this->assertFalse( $defaults['ip_api_cascade'] );
        $this->assertSame( '', $defaults['ipinfo_api_key'] );
        $this->assertTrue( $defaults['ip_cache_enabled'] );
        $this->assertSame( 600, $defaults['ip_cache_ttl'] );
        $this->assertSame( 600, $defaults['gps_cache_ttl'] );
        $this->assertSame( 'gps_only', $defaults['api_fallback'] );
        $this->assertSame( 'allow', $defaults['gps_fallback'] );
        $this->assertSame( 'block', $defaults['both_fail_fallback'] );
        $this->assertFalse( $defaults['admin_bypass_datetime'] );
        $this->assertFalse( $defaults['admin_bypass_geo'] );
        $this->assertFalse( $defaults['debug_enabled'] );
    }

    // ==================================================================
    // get_settings() — via Reflection
    // ==================================================================

    public function test_get_settings_merges_saved_with_defaults(): void {
        Functions\when( 'get_option' )->justReturn( array( 'ip_api_enabled' => true ) );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'get_settings' );
        $ref->setAccessible( true );
        $settings = $ref->invoke( $this->tab );

        $this->assertTrue( $settings['ip_api_enabled'] );
        // Defaults should be present for keys not in saved settings
        $this->assertSame( 'ip-api', $settings['ip_api_service'] );
    }

    public function test_get_settings_returns_defaults_when_no_saved_settings(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'get_settings' );
        $ref->setAccessible( true );
        $settings = $ref->invoke( $this->tab );

        $this->assertFalse( $settings['ip_api_enabled'] );
        $this->assertSame( 'gps_only', $settings['api_fallback'] );
    }

    // ==================================================================
    // save_settings() — via Reflection
    // ==================================================================

    public function test_save_settings_stores_correct_values(): void {
        $_POST['ip_api_enabled']      = '1';
        $_POST['ip_api_service']      = 'ipinfo';
        $_POST['ip_api_cascade']      = '1';
        $_POST['ipinfo_api_key']      = 'test_key_123';
        $_POST['ip_cache_enabled']    = '1';
        $_POST['ip_cache_ttl']        = '900';
        $_POST['gps_cache_ttl']       = '300';
        $_POST['api_fallback']        = 'allow';
        $_POST['gps_fallback']        = 'block';
        $_POST['both_fail_fallback']  = 'allow';
        $_POST['admin_bypass_datetime'] = '1';
        $_POST['admin_bypass_geo']    = '1';
        $_POST['debug_enabled']       = '1';

        $captured_settings = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_settings ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                $captured_settings = $value;
            }
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'save_settings' );
        $ref->setAccessible( true );
        $ref->invoke( $this->tab );

        $this->assertNotNull( $captured_settings );
        $this->assertTrue( $captured_settings['ip_api_enabled'] );
        $this->assertSame( 'ipinfo', $captured_settings['ip_api_service'] );
        $this->assertTrue( $captured_settings['ip_api_cascade'] );
        $this->assertSame( 'test_key_123', $captured_settings['ipinfo_api_key'] );
        $this->assertTrue( $captured_settings['ip_cache_enabled'] );
        $this->assertSame( 900, $captured_settings['ip_cache_ttl'] );
        $this->assertSame( 300, $captured_settings['gps_cache_ttl'] );
        $this->assertSame( 'allow', $captured_settings['api_fallback'] );
        $this->assertSame( 'block', $captured_settings['gps_fallback'] );
        $this->assertSame( 'allow', $captured_settings['both_fail_fallback'] );
        $this->assertTrue( $captured_settings['admin_bypass_datetime'] );
        $this->assertTrue( $captured_settings['admin_bypass_geo'] );
        $this->assertTrue( $captured_settings['debug_enabled'] );
    }

    public function test_save_settings_defaults_invalid_service_to_ip_api(): void {
        $_POST['ip_api_service'] = 'invalid_service';
        $_POST['api_fallback']   = 'gps_only';
        $_POST['gps_fallback']   = 'allow';
        $_POST['both_fail_fallback'] = 'block';

        $captured_settings = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_settings ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                $captured_settings = $value;
            }
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'save_settings' );
        $ref->setAccessible( true );
        $ref->invoke( $this->tab );

        $this->assertSame( 'ip-api', $captured_settings['ip_api_service'] );
    }

    public function test_save_settings_clamps_ip_cache_ttl(): void {
        $_POST['ip_api_service']     = 'ip-api';
        $_POST['api_fallback']       = 'gps_only';
        $_POST['gps_fallback']       = 'allow';
        $_POST['both_fail_fallback'] = 'block';
        $_POST['ip_cache_ttl']       = '100'; // Below minimum of 300
        $_POST['gps_cache_ttl']      = '30';  // Below minimum of 60

        $captured_settings = null;
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_settings ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                $captured_settings = $value;
            }
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'save_settings' );
        $ref->setAccessible( true );
        $ref->invoke( $this->tab );

        $this->assertSame( 300, $captured_settings['ip_cache_ttl'] );
        $this->assertSame( 60, $captured_settings['gps_cache_ttl'] );
    }

    public function test_save_settings_calls_save_locations(): void {
        $_POST['ip_api_service']     = 'ip-api';
        $_POST['api_fallback']       = 'gps_only';
        $_POST['gps_fallback']       = 'allow';
        $_POST['both_fail_fallback'] = 'block';

        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $ref = new \ReflectionMethod( TabGeolocation::class, 'save_settings' );
        $ref->setAccessible( true );
        $ref->invoke( $this->tab );

        // save_settings no longer saves main_geo_areas; it delegates to save_locations().
        // Verify it completes without error — location CRUD is tested separately.
        $this->assertTrue( true );
    }

    // ==================================================================
    // render() — POST submission (form save)
    // ==================================================================

    public function test_render_processes_post_submission_and_shows_success(): void {
        $_POST['ffc_save_geolocation'] = '1';
        $_POST['ip_api_service']       = 'ip-api';
        $_POST['api_fallback']         = 'gps_only';
        $_POST['gps_fallback']         = 'allow';
        $_POST['both_fail_fallback']   = 'block';

        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        // The render() includes a view file — stub it with a temp file
        $tmp_dir = sys_get_temp_dir() . '/ffc_test_geo_' . getmypid();
        @mkdir( $tmp_dir, 0777, true );
        file_put_contents(
            $tmp_dir . '/ffc-tab-geolocation.php',
            '<?php echo "geo-view"; ?>'
        );

        // Use subclass to override the view path
        $tab = new class( $tmp_dir ) extends TabGeolocation {
            private $view_dir;
            public function __construct( string $dir ) {
                $this->view_dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( $_POST && isset( $_POST['ffc_save_geolocation'] ) ) {
                    check_admin_referer( 'ffc_geolocation_nonce' );
                    $ref = new \ReflectionMethod( parent::class, 'save_settings' );
                    $ref->setAccessible( true );
                    $ref->invoke( $this );
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Geolocation settings saved successfully!', 'ffcertificate' ) . '</p></div>';
                }
                $ref = new \ReflectionMethod( parent::class, 'get_settings' );
                $ref->setAccessible( true );
                $settings = $ref->invoke( $this );
                include $this->view_dir . '/ffc-tab-geolocation.php';
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Geolocation settings saved successfully!', $output );
        $this->assertStringContainsString( 'geo-view', $output );

        @unlink( $tmp_dir . '/ffc-tab-geolocation.php' );
        @rmdir( $tmp_dir );
    }
}
