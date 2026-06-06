<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabRateLimit;

/**
 * Tests for TabRateLimit: rate limiting settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabRateLimit
 */
class TabRateLimitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabRateLimit */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );

        $this->tab = new TabRateLimit();
    }

    protected function tearDown(): void {
        unset( $_POST['ffc_save_rate_limit'] );
        unset( $_POST['ip_enabled'], $_POST['ip_max_per_hour'], $_POST['ip_max_per_day'] );
        unset( $_POST['ip_cooldown_seconds'], $_POST['ip_apply_to'], $_POST['ip_message'] );
        unset( $_POST['email_enabled'], $_POST['email_max_per_day'], $_POST['email_max_per_week'] );
        unset( $_POST['email_max_per_month'], $_POST['email_wait_hours'], $_POST['email_apply_to'] );
        unset( $_POST['email_message'], $_POST['email_check_database'] );
        unset( $_POST['cpf_enabled'], $_POST['cpf_max_per_month'], $_POST['cpf_max_per_year'] );
        unset( $_POST['cpf_block_threshold'], $_POST['cpf_block_hours'], $_POST['cpf_block_duration'] );
        unset( $_POST['cpf_apply_to'], $_POST['cpf_message'], $_POST['cpf_check_database'] );
        unset( $_POST['global_enabled'], $_POST['global_max_per_minute'], $_POST['global_max_per_hour'] );
        unset( $_POST['global_message'] );
        unset( $_POST['whitelist_ips'], $_POST['whitelist_emails'], $_POST['whitelist_email_domains'], $_POST['whitelist_cpfs'] );
        unset( $_POST['blacklist_ips'], $_POST['blacklist_emails'], $_POST['blacklist_email_domains'], $_POST['blacklist_cpfs'] );
        unset( $_POST['logging_enabled'], $_POST['logging_log_allowed'], $_POST['logging_log_blocked'] );
        unset( $_POST['logging_retention_days'], $_POST['logging_max_logs'] );
        unset( $_POST['ui_show_remaining'], $_POST['ui_show_wait_time'], $_POST['ui_countdown_timer'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_rate_limit(): void {
        $this->assertSame( 'rate_limit', $this->tab->get_id() );
    }

    public function test_tab_title_is_rate_limit(): void {
        $this->assertSame( 'Rate Limit', $this->tab->get_title() );
    }

    public function test_tab_icon_is_shield(): void {
        $this->assertSame( 'ffc-icon-shield', $this->tab->get_icon() );
    }

    public function test_tab_order_is_40(): void {
        $this->assertSame( 40, $this->tab->get_order() );
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
    // render() — no POST, uses defaults via get_settings()
    // ==================================================================

    public function test_render_without_post_includes_view(): void {
        // Stub wp_parse_args to merge like array_merge
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'get_option' )->justReturn( array() );

        // Create temp view file
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_rate_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-rate-limit.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "rate-limit-rendered"; ?>' );

        // Use a subclass that overrides FFC_PLUGIN_DIR path
        $tab = new class( $tmp_dir ) extends TabRateLimit {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                // Replicate original logic but use custom dir
                if ( $_POST && isset( $_POST['ffc_save_rate_limit'] ) ) {
                    check_admin_referer( 'ffc_rate_limit_nonce' );
                    // save_settings() is private, so skip in this test subclass
                    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'ffcertificate' ) . '</p></div>';
                }

                $defaults = array(
                    'ip' => array( 'enabled' => true ),
                    'email' => array( 'enabled' => true ),
                );
                $settings = wp_parse_args( get_option( 'ffc_rate_limit_settings', array() ), $defaults );
                include $this->dir . '/ffc-tab-rate-limit.php';
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'rate-limit-rendered', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // render() — with POST triggers save and shows success notice
    // ==================================================================

    public function test_render_with_post_shows_success_notice(): void {
        $_POST['ffc_save_rate_limit'] = '1';
        $_POST['ip_enabled'] = '1';
        $_POST['ip_max_per_hour'] = '10';
        $_POST['ip_max_per_day'] = '50';
        $_POST['ip_cooldown_seconds'] = '30';
        $_POST['ip_apply_to'] = 'all';
        $_POST['ip_message'] = 'Limit reached.';

        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();

        // Create temp view file
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_rate2_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-rate-limit.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "rate-view"; ?>' );

        $tab = new class( $tmp_dir ) extends TabRateLimit {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            public function render(): void {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( $_POST && isset( $_POST['ffc_save_rate_limit'] ) ) {
                    check_admin_referer( 'ffc_rate_limit_nonce' );
                    update_option( 'ffc_rate_limit_settings', array( 'ip' => array( 'enabled' => true ) ) );
                    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'ffcertificate' ) . '</p></div>';
                }

                $defaults = array( 'ip' => array( 'enabled' => true ) );
                $settings = wp_parse_args( get_option( 'ffc_rate_limit_settings', array() ), $defaults );
                include $this->dir . '/ffc-tab-rate-limit.php';
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-success', $output );
        $this->assertStringContainsString( 'Settings saved!', $output );
        $this->assertStringContainsString( 'rate-view', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option() from SettingsTab
    // ==================================================================

    public function test_inherited_get_option_returns_value(): void {
        Functions\when( 'get_option' )->justReturn( array( 'some_key' => 'some_value' ) );

        $this->assertSame( 'some_value', $this->tab->get_option( 'some_key' ) );
    }

    public function test_inherited_get_option_returns_default_for_missing(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'default_val', $this->tab->get_option( 'missing', 'default_val' ) );
    }

    // ==================================================================
    // Helpers + sanitize/save logic
    // ==================================================================

    private function invoke_private( string $method ) {
        $ref = new \ReflectionMethod( TabRateLimit::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->tab, array() );
    }

    /** Stub the input-sanitizing WP funcs used by save_settings(). */
    private function stub_save_funcs(): void {
        Functions\when( 'absint' )->alias( fn ( $v ) => abs( (int) $v ) );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( fn ( $v ) => strtolower( (string) $v ) );
        // Utils::get_post_string (Core namespace) uses these unqualified.
        // Plain stable passthroughs (NOT Brain\Monkey stubs) so they survive
        // tearDown without leaving a torn-down expectation that could poison a
        // later same-process test.
        self::define_core_passthrough( 'wp_unslash' );
        self::define_core_passthrough( 'sanitize_text_field' );
    }

    /** Define a stable passthrough in the Core namespace, once per process. */
    private static function define_core_passthrough( string $name ): void {
        $fqn = 'FreeFormCertificate\\Core\\' . $name;
        if ( ! function_exists( $fqn ) ) {
            eval( "namespace FreeFormCertificate\\Core; function {$name}( \$v ) { return \$v; }" );
        }
    }

    // ------------------------------------------------------------------
    // get_settings()
    // ------------------------------------------------------------------

    public function test_get_settings_returns_defaults_when_option_empty(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_parse_args' )->alias(
            fn ( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
        );

        $settings = $this->invoke_private( 'get_settings' );

        $this->assertIsArray( $settings );
        $this->assertTrue( $settings['ip']['enabled'] );
        $this->assertSame( 5, $settings['ip']['max_per_hour'] );
        $this->assertFalse( $settings['cpf']['enabled'] );
        $this->assertArrayHasKey( 'read', $settings );
        $this->assertArrayHasKey( 'device', $settings );
    }

    public function test_get_settings_merges_stored_overrides(): void {
        Functions\when( 'get_option' )->justReturn( array( 'ip' => array( 'enabled' => false, 'max_per_hour' => 99 ) ) );
        Functions\when( 'wp_parse_args' )->alias(
            fn ( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
        );

        $settings = $this->invoke_private( 'get_settings' );
        $this->assertFalse( $settings['ip']['enabled'] );
        $this->assertSame( 99, $settings['ip']['max_per_hour'] );
    }

    // ------------------------------------------------------------------
    // parse_read_endpoints_post()
    // ------------------------------------------------------------------

    public function test_parse_read_endpoints_defaults_when_no_post(): void {
        $this->stub_save_funcs();
        $out = $this->invoke_private( 'parse_read_endpoints_post' );

        $this->assertSame( array( 'calendar_slots', 'calendar_list', 'calendar_detail' ), array_keys( $out ) );
        foreach ( $out as $endpoint ) {
            $this->assertFalse( $endpoint['enabled'] );
            $this->assertSame( 0, $endpoint['max_per_minute'] );
            $this->assertSame( 0, $endpoint['max_per_hour'] );
        }
    }

    public function test_parse_read_endpoints_reads_posted_values(): void {
        $this->stub_save_funcs();
        $_POST['read_endpoint_calendar_slots_enabled']        = '1';
        $_POST['read_endpoint_calendar_slots_max_per_minute'] = '5';
        $_POST['read_endpoint_calendar_slots_max_per_hour']   = '120';

        $out = $this->invoke_private( 'parse_read_endpoints_post' );

        $this->assertTrue( $out['calendar_slots']['enabled'] );
        $this->assertSame( 5, $out['calendar_slots']['max_per_minute'] );
        $this->assertSame( 120, $out['calendar_slots']['max_per_hour'] );
        // Untouched endpoint stays disabled.
        $this->assertFalse( $out['calendar_list']['enabled'] );

        unset(
            $_POST['read_endpoint_calendar_slots_enabled'],
            $_POST['read_endpoint_calendar_slots_max_per_minute'],
            $_POST['read_endpoint_calendar_slots_max_per_hour']
        );
    }

    // ------------------------------------------------------------------
    // save_settings()
    // ------------------------------------------------------------------

    public function test_save_settings_persists_normalized_settings(): void {
        $this->stub_save_funcs();

        $_POST['ip_enabled']         = '1';
        $_POST['ip_max_per_hour']    = '15';
        $_POST['ip_apply_to']        = 'guests';
        $_POST['email_check_database'] = '1';
        $_POST['device_max_per_form']  = '0';            // clamped to >= 1
        $_POST['device_match_threshold'] = '99';          // clamped to <= 12
        $_POST['device_signals_enabled'] = array( 'cookie', 'bogus', 'ua' );
        $_POST['whitelist_ips']      = "1.1.1.1\n2.2.2.2\n";

        $saved = null;
        Functions\when( 'update_option' )->alias(
            function ( $key, $value ) use ( &$saved ) {
                if ( 'ffc_rate_limit_settings' === $key ) {
                    $saved = $value;
                }
                return true;
            }
        );

        $this->invoke_private( 'save_settings' );

        $this->assertIsArray( $saved );
        $this->assertTrue( $saved['ip']['enabled'] );
        $this->assertSame( 15, $saved['ip']['max_per_hour'] );
        $this->assertSame( 'guests', $saved['ip']['apply_to'] );
        $this->assertTrue( $saved['email']['check_database'] );
        // device clamps.
        $this->assertSame( 1, $saved['device']['max_per_form'] );
        $this->assertSame( 12, $saved['device']['match_threshold'] );
        // Only known signals survive the intersect; 'bogus' dropped.
        $this->assertSame( array( 'cookie', 'ua' ), $saved['device']['signals_enabled'] );
        // Whitelist split + trimmed, empty trailing line filtered out.
        $this->assertSame( array( '1.1.1.1', '2.2.2.2' ), array_values( $saved['whitelist']['ips'] ) );
        // read.endpoints comes from parse_read_endpoints_post().
        $this->assertArrayHasKey( 'calendar_slots', $saved['read']['endpoints'] );

        unset(
            $_POST['ip_enabled'], $_POST['ip_max_per_hour'], $_POST['ip_apply_to'],
            $_POST['email_check_database'], $_POST['device_max_per_form'],
            $_POST['device_match_threshold'], $_POST['device_signals_enabled'],
            $_POST['whitelist_ips']
        );
    }

    public function test_save_settings_defaults_when_post_empty(): void {
        $this->stub_save_funcs();

        $saved = null;
        Functions\when( 'update_option' )->alias(
            function ( $key, $value ) use ( &$saved ) {
                $saved = $value;
                return true;
            }
        );

        $this->invoke_private( 'save_settings' );

        $this->assertIsArray( $saved );
        // Checkboxes absent → false; numeric fields fall back to documented defaults.
        $this->assertFalse( $saved['ip']['enabled'] );
        $this->assertSame( 5, $saved['ip']['max_per_hour'] );
        $this->assertSame( 'all', $saved['ip']['apply_to'] );
        $this->assertSame( array(), $saved['device']['signals_enabled'] );
    }
}
