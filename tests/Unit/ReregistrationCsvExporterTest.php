<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationCsvExporter;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationCsvExporter
 */
class ReregistrationCsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_file_name' )->returnArg();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        unset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // handle_export() — no action parameter
    // ==================================================================

    public function test_handle_export_returns_early_without_action(): void {
        unset( $_GET['action'] );
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true ); // Early return, no error
    }

    // ==================================================================
    // handle_export() — wrong action
    // ==================================================================

    public function test_handle_export_returns_early_with_wrong_action(): void {
        $_GET['action'] = 'something_else';
        $_GET['id'] = 1;
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — missing id
    // ==================================================================

    public function test_handle_export_returns_early_without_id(): void {
        $_GET['action'] = 'export_csv';
        unset( $_GET['id'] );
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — invalid nonce
    // ==================================================================

    public function test_handle_export_returns_early_with_invalid_nonce(): void {
        $_GET['action'] = 'export_csv';
        $_GET['id'] = '5';
        $_GET['_wpnonce'] = 'bad_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — reregistration not found
    // ==================================================================

    public function test_handle_export_returns_early_when_rereg_not_found(): void {
        $_GET['action'] = 'export_csv';
        $_GET['id'] = '5';
        $_GET['_wpnonce'] = 'valid';
        Functions\when( 'wp_verify_nonce' )->justReturn( true );

        $repoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
        $repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( null );

        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }
}
