<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SubmissionsBulkActionsAjaxEndpoint;

/**
 * Tests for the Submissions bulk-actions AJAX endpoint introduced in 6.5.9.
 *
 * Run in separate processes because we `Mockery::mock( 'overload:…' )`
 * the SubmissionHandler class — once overloaded in one process, any
 * subsequent test in the same process would hit the mocked version.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Admin\SubmissionsBulkActionsAjaxEndpoint
 */
class SubmissionsBulkActionsAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->alias( function ( $s, $p, $c ) {
            return 1 === $c ? $s : $p;
        } );
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return (int) abs( (int) $v ); } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

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
    }

    protected function tearDown(): void {
        $_POST = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Guards
    // ==================================================================

    public function test_rejects_when_user_lacks_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 1 ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        SubmissionsBulkActionsAjaxEndpoint::handle();
    }

    public function test_rejects_unknown_action(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'mystery', 'ids' => array( 1 ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Unknown action' );
        SubmissionsBulkActionsAjaxEndpoint::handle();
    }

    public function test_rejects_empty_ids(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array() );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'No submissions selected' );
        SubmissionsBulkActionsAjaxEndpoint::handle();
    }

    public function test_filters_non_positive_ids_and_rejects_when_nothing_left(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        // Non-numeric strings and 0 collapse to 0 under absint, then
        // the >0 filter drops them. absint(-3) is 3 (positive) — by
        // design — so the test deliberately omits negative literals.
        $_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 0, 'x', '0', '' ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'No submissions selected' );
        SubmissionsBulkActionsAjaxEndpoint::handle();
    }

    // ==================================================================
    // Dispatch — each action maps to the correct bulk_* method
    // ==================================================================

    public function test_trash_dispatches_to_bulk_trash_submissions(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $handler = Mockery::mock( 'overload:FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'bulk_trash_submissions' )
            ->once()
            ->with( array( 1, 2, 3 ) )
            ->andReturn( 3 );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 1, 2, 3 ) );

        try {
            SubmissionsBulkActionsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
            $this->assertStringContainsString( '"action":"trash"', $e->getMessage() );
            $this->assertStringContainsString( '"count":3', $e->getMessage() );
            $this->assertStringContainsString( 'submissions moved to trash', $e->getMessage() );
        }
    }

    public function test_restore_dispatches_to_bulk_restore_submissions(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        Mockery::mock( 'overload:FreeFormCertificate\Submissions\SubmissionHandler' )
            ->shouldReceive( 'bulk_restore_submissions' )
            ->once()
            ->with( array( 42 ) )
            ->andReturn( 1 );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'restore', 'ids' => array( 42 ) );

        try {
            SubmissionsBulkActionsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '"action":"restore"', $e->getMessage() );
            $this->assertStringContainsString( 'submission restored', $e->getMessage() );
        }
    }

    public function test_delete_dispatches_to_bulk_delete_submissions(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        Mockery::mock( 'overload:FreeFormCertificate\Submissions\SubmissionHandler' )
            ->shouldReceive( 'bulk_delete_submissions' )
            ->once()
            ->with( array( 5, 7 ) )
            ->andReturn( 2 );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'delete', 'ids' => array( 5, 7 ) );

        try {
            SubmissionsBulkActionsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '"action":"delete"', $e->getMessage() );
            $this->assertStringContainsString( 'permanently deleted', $e->getMessage() );
        }
    }

    public function test_handler_failure_returns_500_error(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        Mockery::mock( 'overload:FreeFormCertificate\Submissions\SubmissionHandler' )
            ->shouldReceive( 'bulk_trash_submissions' )
            ->andReturn( false );

        $_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 9 ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'bulk operation failed' );
        SubmissionsBulkActionsAjaxEndpoint::handle();
    }

    public function test_action_map_exposes_all_three_actions(): void {
        $map = SubmissionsBulkActionsAjaxEndpoint::action_map();
        $this->assertSame( 'bulk_trash_submissions',   $map['trash'] );
        $this->assertSame( 'bulk_restore_submissions', $map['restore'] );
        $this->assertSame( 'bulk_delete_submissions',  $map['delete'] );
        // move_to_form is intentionally absent — has its own flow.
        $this->assertArrayNotHasKey( 'move_to_form', $map );
    }
}
