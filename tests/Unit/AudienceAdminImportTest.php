<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminImport;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminImport
 * @covers \FreeFormCertificate\Audience\AudienceMembersExportSource
 * @covers \FreeFormCertificate\Audience\AudienceAudiencesExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminImportTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Preload so pcov attributes the coverage these tests drive through the
        // export sources; pcov skips a class first autoloaded mid-test (#772).
        class_exists( '\\FreeFormCertificate\Audience\AudienceMembersExportSource' );
        class_exists( '\\FreeFormCertificate\Audience\AudienceAudiencesExportSource' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'sanitize_sql_orderby' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'submit_button' )->justReturn( '' );
        // render_content() now enqueues the tab-switch asset instead of
        // echoing an inline <script>; stub the enqueue so the render path
        // doesn't fatal under Brain\Monkey.
        Functions\when( 'wp_enqueue_script' )->justReturn( null );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
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
        unset( $_POST['ffc_import_action'], $_POST['_wpnonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $page = new AudienceAdminImport( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminImport::class, $page );
    }

    // ==================================================================
    // render_content() — renders import/export tabs
    // ==================================================================

    public function test_render_page_renders_interface(): void {
        $page = new AudienceAdminImport( 'ffc-scheduling' );
        ob_start();
        $page->render_content();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-import-tab', $output );
    }

    // ==================================================================
    // handle_csv_import() — no action
    // ==================================================================

    public function test_handle_csv_import_does_nothing_without_action(): void {
        unset( $_POST['ffc_import_action'] );
        $page = new AudienceAdminImport( 'ffc-scheduling' );
        $page->handle_csv_import();
        $this->assertTrue( true );
    }

    // ==================================================================
    // export_members_csv() / export_audiences_csv() — streaming path
    // ==================================================================

    /**
     * A CsvDownloadInterface that captures the export bytes instead of writing
     * to php://output / calling exit.
     */
    private function buffered_download(): CsvDownloadInterface {
        return new class() implements CsvDownloadInterface {
            public bool $finished = false;
            public string $output = '';
            /** @var resource|null */
            private $stream = null;

            public function send_headers( string $filename ): void {
                unset( $filename );
            }

            public function open_stream() {
                if ( ! is_resource( $this->stream ) ) {
                    $this->stream = fopen( 'php://memory', 'w+' );
                }
                return $this->stream;
            }

            public function finish(): void {
                $this->finished = true;
                if ( is_resource( $this->stream ) ) {
                    rewind( $this->stream );
                    $this->output = (string) stream_get_contents( $this->stream );
                }
            }
        };
    }

    public function test_export_members_csv_streams_header_and_rows(): void {
        $download = $this->buffered_download();

        $reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
        $reader->shouldReceive( 'get_all' )->andReturn(
            array( (object) array( 'id' => 1, 'name' => 'Turma A' ) )
        );
        $reader->shouldReceive( 'get_members' )->with( 1 )->andReturn( array( 10, 11 ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
            ->shouldReceive( 'get_export_filename' )->andReturn( 'members-export.csv' );

        $u10 = (object) array( 'user_email' => 'a@example.com', 'display_name' => 'Ana' );
        $u11 = (object) array( 'user_email' => 'b@example.com', 'display_name' => 'Bruno' );
        Functions\when( 'get_user_by' )->alias(
            static fn ( $field, $id ) => 10 === $id ? $u10 : ( 11 === $id ? $u11 : false )
        );

        $page = new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) );
        $ref  = new \ReflectionMethod( AudienceAdminImport::class, 'export_members_csv' );
        $ref->setAccessible( true );
        $ref->invoke( $page );

        $this->assertTrue( $download->finished, 'stream finished' );
        $this->assertStringContainsString( 'email;name;audience_name', $download->output, 'header' );
        // Assert on substrings that do not cross fputcsv's RFC-4180 quoting so
        // the test is robust to whether the audience name gets enclosed.
        $this->assertStringContainsString( 'a@example.com;Ana;', $download->output );
        $this->assertStringContainsString( 'b@example.com;Bruno;', $download->output );
        $this->assertStringContainsString( 'Turma A', $download->output, 'audience name row present' );
    }

    public function test_export_audiences_csv_streams_hierarchical_rows(): void {
        $download = $this->buffered_download();

        $child  = (object) array( 'name' => 'Child', 'color' => null );
        $parent = (object) array(
            'name'     => 'Parent',
            'color'    => '#abcdef',
            'children' => array( $child ),
        );
        Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
            ->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );
        Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
            ->shouldReceive( 'get_export_filename' )->andReturn( 'audiences-export.csv' );

        $page = new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) );
        $ref  = new \ReflectionMethod( AudienceAdminImport::class, 'export_audiences_csv' );
        $ref->setAccessible( true );
        $ref->invoke( $page );

        $this->assertTrue( $download->finished, 'stream finished' );
        $this->assertStringContainsString( 'name;color;parent', $download->output, 'header' );
        $this->assertStringContainsString( 'Parent;#abcdef;', $download->output, 'parent row (no parent col)' );
        $this->assertStringContainsString( 'Child;#3788d8;Parent', $download->output, 'child row (default color, parent set)' );
    }
}
