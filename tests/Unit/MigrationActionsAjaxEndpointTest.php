<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint;

/**
 * Tests for the migrations JSON-batch endpoint introduced in 6.5.7.
 *
 * Runs each test in a separate process because we Mockery::alias the
 * static `Utils` class + Mockery::mock the concrete `MigrationManager`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint
 */
class MigrationActionsAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );

        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            $msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
            throw new \RuntimeException( 'json_error: ' . $msg );
        } );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            $payload = wp_json_encode( $data );
            throw new \RuntimeException( 'json_success: ' . $payload );
        } );
        if ( ! function_exists( 'wp_json_encode' ) ) {
            // phpcs:ignore Generic.PHP.LowerCaseConstant
            eval( 'function wp_json_encode( $data ) { return json_encode( $data ); }' );
        }

        if ( ! class_exists( '\WP_Error' ) ) {
            // phpcs:ignore Generic.PHP.LowerCaseConstant
            eval( 'class WP_Error { public $msg = "wp_err"; public function __construct( $code = 0, $msg = "" ) { $this->msg = $msg; } public function get_error_message() { return $this->msg; } }' );
        }
    }

    protected function tearDown(): void {
        unset( $_POST['migration_key'], $_POST['nonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Guards
    // ==================================================================

    public function test_rejects_when_user_lacks_capability(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        MigrationActionsAjaxEndpoint::handle();
    }

    public function test_rejects_missing_migration_key(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );

        $_POST = array( 'nonce' => 'x' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Missing migration key' );
        MigrationActionsAjaxEndpoint::handle();
    }

    public function test_rejects_unknown_migration(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );

        Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' )
            ->shouldReceive( 'is_migration_available' )
            ->with( 'unknown' )
            ->andReturn( false );

        $_POST = array( 'nonce' => 'x', 'migration_key' => 'unknown' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Unknown migration' );
        MigrationActionsAjaxEndpoint::handle();
    }

    public function test_run_migration_error_is_surfaced(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'is_migration_available' )->andReturn( true );
        $mgr->shouldReceive( 'run_migration' )->andReturn( new \WP_Error( 'fail', 'Batch failed' ) );

        $_POST = array( 'nonce' => 'x', 'migration_key' => 'my_migration' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Batch failed' );
        MigrationActionsAjaxEndpoint::handle();
    }

    // ==================================================================
    // Happy path
    // ==================================================================

    public function test_happy_path_returns_status_snapshot(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'is_migration_available' )->andReturn( true );
        $mgr->shouldReceive( 'run_migration' )->andReturn( array( 'processed' => 100 ) );
        $mgr->shouldReceive( 'get_migration_status' )->andReturn(
            array(
                'total'       => 5432,
                'migrated'    => 200,
                'pending'     => 5232,
                'percent'     => 3.68,
                'is_complete' => false,
            )
        );

        $_POST = array( 'nonce' => 'x', 'migration_key' => 'my_migration' );

        try {
            MigrationActionsAjaxEndpoint::handle();
            $this->fail( 'Expected wp_send_json_success to short-circuit' );
        } catch ( \RuntimeException $e ) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith( 'json_success', $msg );
            $this->assertStringContainsString( '"processed":100', $msg );
            $this->assertStringContainsString( '"total":5432', $msg );
            $this->assertStringContainsString( '"migrated":200', $msg );
            $this->assertStringContainsString( '"pending":5232', $msg );
            $this->assertStringContainsString( '"is_complete":false', $msg );
        }
    }

    public function test_completion_flag_propagates(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );

        $mgr = Mockery::mock( 'overload:FreeFormCertificate\Migrations\MigrationManager' );
        $mgr->shouldReceive( 'is_migration_available' )->andReturn( true );
        $mgr->shouldReceive( 'run_migration' )->andReturn( array( 'processed' => 32 ) );
        $mgr->shouldReceive( 'get_migration_status' )->andReturn(
            array(
                'total'       => 5432,
                'migrated'    => 5432,
                'pending'     => 0,
                'percent'     => 100.0,
                'is_complete' => true,
            )
        );

        $_POST = array( 'nonce' => 'x', 'migration_key' => 'my_migration' );

        try {
            MigrationActionsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '"is_complete":true', $e->getMessage() );
            $this->assertStringContainsString( '"processed":32', $e->getMessage() );
        }
    }
}
