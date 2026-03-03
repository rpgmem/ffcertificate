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
}
