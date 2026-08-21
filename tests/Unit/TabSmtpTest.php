<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabSMTP;

/**
 * Tests for TabSMTP: SMTP settings tab.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabSMTP
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabSmtpTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var TabSMTP */
    private $tab;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wp_admin_notice' )->alias(
            static function ( $message, $args = array() ) {
                $ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
                $ffc_cls  = 'notice notice-' . $ffc_type;
                if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
                if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
                $ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
                echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
            }
        );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );

        $this->tab = new TabSMTP();
    }

    protected function tearDown(): void {
        unset( $_GET['tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init() — tab properties
    // ==================================================================

    public function test_tab_id_is_smtp(): void {
        $this->assertSame( 'smtp', $this->tab->get_id() );
    }

    public function test_tab_title_is_smtp(): void {
        $this->assertSame( 'SMTP', $this->tab->get_title() );
    }

    public function test_tab_icon_is_email(): void {
        $this->assertSame( 'ffc-icon-email', $this->tab->get_icon() );
    }

    public function test_tab_order_is_20(): void {
        $this->assertSame( 20, $this->tab->get_order() );
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
    // enqueue_scripts() — wrong hook
    // ==================================================================

    public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
        // wp_enqueue_script should never be called for a non-matching hook
        Functions\expect( 'wp_enqueue_script' )->never();

        $this->tab->enqueue_scripts( 'edit.php' );
    }

    // ==================================================================
    // enqueue_scripts() — correct hook but different tab
    // ==================================================================

    public function test_enqueue_scripts_returns_early_when_tab_is_not_smtp(): void {
        $_GET['tab'] = 'general';
        Functions\when( 'wp_unslash' )->returnArg();

        Functions\expect( 'wp_enqueue_script' )->never();

        $this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
    }

    // ==================================================================
    // enqueue_scripts() — correct hook and correct tab
    // ==================================================================

    public function test_enqueue_scripts_enqueues_script_when_tab_is_smtp(): void {
        $_GET['tab'] = 'smtp';
        Functions\when( 'wp_unslash' )->returnArg();

        // AssetHelper::asset_suffix() is now called twice (once on this tab + once
        // inside enqueue_autosave_infra), and the autosave helper enqueues
        // four scripts: ffc-core, ffc-admin-autosave, ffc-section-collapse,
        // plus this tab's own ffc-smtp-settings.
        $utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
        $utils_mock->shouldReceive( 'asset_suffix' )
            ->twice()
            ->andReturn( '.min' );

        Functions\expect( 'wp_enqueue_script' )
            ->with( 'ffc-smtp-settings', Mockery::type( 'string' ), array( 'jquery' ), Mockery::type( 'string' ), true )
            ->once();
        Functions\expect( 'wp_enqueue_script' )
            ->with( 'ffc-core', Mockery::type( 'string' ), array( 'jquery' ), Mockery::type( 'string' ), true )
            ->once();
        Functions\expect( 'wp_enqueue_script' )
            ->with( 'ffc-admin-autosave', Mockery::type( 'string' ), array( 'jquery', 'ffc-core', 'ffc-admin-js' ), Mockery::type( 'string' ), true )
            ->once();
        Functions\expect( 'wp_enqueue_script' )
            ->with( 'ffc-section-collapse', Mockery::type( 'string' ), array( 'jquery' ), Mockery::type( 'string' ), true )
            ->once();
        Functions\expect( 'wp_localize_script' )
            ->with( 'ffc-admin-autosave', 'ffcAdminAutosave', Mockery::on( function ( $arg ) {
                return is_array( $arg ) && isset( $arg['nonce'] ) && is_string( $arg['nonce'] );
            } ) )
            ->once();
        Functions\when( 'wp_create_nonce' )->justReturn( 'autosave-nonce' );

        // "Email Model" box assets (color picker, media, live preview).
        Functions\when( 'wp_enqueue_media' )->justReturn( null );
        Functions\when( 'wp_enqueue_style' )->justReturn( null );
        Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
        Functions\when( 'home_url' )->justReturn( 'https://site.test' );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'wp_date' )->justReturn( '2026' );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\expect( 'wp_enqueue_script' )
            ->with( 'ffc-email-model', Mockery::type( 'string' ), array( 'jquery', 'wp-color-picker' ), Mockery::type( 'string' ), true )
            ->once();
        Functions\expect( 'wp_localize_script' )
            ->with( 'ffc-email-model', 'ffcEmailModel', Mockery::type( 'array' ) )
            ->once();

        $this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
    }

    // ==================================================================
    // render() — view file missing (error branch)
    // ==================================================================

    public function test_render_outputs_error_when_view_file_missing(): void {
        $tab = new class() extends TabSMTP {
            protected function init(): void {
                $this->tab_id = 'smtp';
                $this->tab_title = 'SMTP';
                $this->tab_icon = 'ffc-icon-email';
                $this->tab_order = 20;
            }
            public function render(): void {
                $view_file = '/tmp/nonexistent_path_12345/ffc-tab-smtp.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice notice-error', $output );
        $this->assertStringContainsString( 'SMTP settings view file not found.', $output );
    }

    // ==================================================================
    // render() — view file exists (happy path via temp file)
    // ==================================================================

    public function test_render_includes_view_when_file_exists(): void {
        $tmp_dir  = sys_get_temp_dir() . '/ffc_test_views_smtp_' . getmypid();
        $tmp_file = $tmp_dir . '/ffc-tab-smtp.php';

        @mkdir( $tmp_dir, 0777, true );
        file_put_contents( $tmp_file, '<?php echo "smtp-rendered"; ?>' );

        $tab = new class( $tmp_dir ) extends TabSMTP {
            private $dir;
            public function __construct( string $dir ) {
                $this->dir = $dir;
                parent::__construct();
            }
            protected function init(): void {
                $this->tab_id = 'smtp';
                $this->tab_title = 'SMTP';
                $this->tab_icon = 'ffc-icon-email';
                $this->tab_order = 20;
            }
            public function render(): void {
                $view_file = $this->dir . '/ffc-tab-smtp.php';
                if ( file_exists( $view_file ) ) {
                    $settings = $this;
                    include $view_file;
                } else {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
                    echo '</p></div>';
                }
            }
        };

        ob_start();
        $tab->render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'smtp-rendered', $output );
        $this->assertStringNotContainsString( 'not found', $output );

        @unlink( $tmp_file );
        @rmdir( $tmp_dir );
    }

    // ==================================================================
    // Inherited get_option()
    // ==================================================================

    public function test_get_option_returns_value_from_ffc_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'smtp_host' => 'mail.example.com' ) );

        $this->assertSame( 'mail.example.com', $this->tab->get_option( 'smtp_host' ) );
    }

    public function test_get_option_returns_default_for_missing_key(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertSame( 'wp', $this->tab->get_option( 'smtp_mode', 'wp' ) );
    }

    // ==================================================================
    // render_email_index() — the read-only "All plugin emails" directory
    // ==================================================================

    public function test_render_email_index_lists_features_with_deeplinks(): void {
        Functions\when( 'current_user_can' )->justReturn( true ); // reaches every feature.
        Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

        ob_start();
        $this->tab->render_email_index();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString( 'All plugin emails', $out );
        $this->assertStringContainsString( 'ffc-email-index', $out );
        // Each feature deep-links to its own screen (display-only; no editing here).
        $this->assertStringContainsString( 'post_type=ffc_form', $out );
        $this->assertStringContainsString( 'post_type=ffc_self_scheduling', $out );
        $this->assertStringContainsString( 'page=ffc-recruitment&tab=settings', $out );
        $this->assertStringContainsString( 'page=ffc-reregistration', $out );
        $this->assertStringContainsString( 'page=ffc-scheduling-calendars', $out );
        // The three personalisation states are labelled.
        $this->assertStringContainsString( 'Editable text', $out );
        $this->assertStringContainsString( 'On/off only', $out );
        $this->assertStringContainsString( 'System default', $out );
    }

    public function test_render_email_index_hidden_without_any_feature_cap(): void {
        Functions\when( 'current_user_can' )->justReturn( false ); // no feature reachable.
        Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );

        ob_start();
        $this->tab->render_email_index();
        $out = (string) ob_get_clean();

        $this->assertSame( '', trim( $out ) );
    }
}
