<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\ActivityLogAjaxEndpoint;

/**
 * Tests for the Activity Log fetch endpoint introduced in 6.5.8.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Admin\ActivityLogAjaxEndpoint
 */
class ActivityLogAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'sanitize_text_field' )->alias( function ( $s ) {
            return trim( strip_tags( (string) $s ) );
        } );
        Functions\when( 'absint' )->alias( function ( $v ) { return (int) abs( (int) $v ); } );
        Functions\when( 'wp_unslash' )->returnArg();
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
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        ActivityLogAjaxEndpoint::handle();
    }

    public function test_rejects_when_activity_log_disabled(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Functions\when( 'get_option' )->justReturn( array() ); // missing enable_activity_log.

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'currently disabled' );
        ActivityLogAjaxEndpoint::handle();
    }

    // ==================================================================
    // Happy path
    // ==================================================================

    public function test_returns_rendered_table_html_pagination_html_and_counts(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 1 ) );

        // Stub the ActivityLog static methods.
        $log_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
        $log_mock->shouldReceive( 'get_activities' )->andReturn( array(
            array( 'created_at' => '2026-05-14 10:00:00', 'level' => 'info', 'action' => 'submission_created', 'user_id' => 0, 'user_ip' => '127.0.0.1', 'context' => '' ),
        ) );
        $log_mock->shouldReceive( 'count_activities' )->andReturn( 123 );

        // Stub the page-class render helpers — separate process means
        // we can alias the page class without conflict.
        Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminActivityLogPage' )
            ->shouldReceive( 'build_query_args' )->andReturn( array( 'limit' => 50, 'offset' => 0, 'orderby' => 'created_at', 'order' => 'DESC' ) )
            ->getMock()->shouldReceive( 'render_rows_html' )->andReturn( '<tr><td>row</td></tr>' )
            ->getMock()->shouldReceive( 'render_pagination_html' )->andReturn( '<div class="pager">…</div>' );

        $_POST = array( 'nonce' => 'x', 'level' => 'info', 'log_action' => '', 'search' => '', 'paged' => '1' );

        try {
            ActivityLogAjaxEndpoint::handle();
            $this->fail( 'Expected wp_send_json_success to short-circuit' );
        } catch ( \RuntimeException $e ) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith( 'json_success', $msg );
            $this->assertStringContainsString( '<tr><td>row<\/td><\/tr>', $msg );
            $this->assertStringContainsString( '<div class=\"pager\">', $msg );
            $this->assertStringContainsString( '"total_logs":123', $msg );
            $this->assertStringContainsString( '"current_page":1', $msg );
            $this->assertStringContainsString( '"is_empty":false', $msg );
        }
    }

    public function test_empty_result_sets_is_empty_true(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 1 ) );

        $log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
        $log->shouldReceive( 'get_activities' )->andReturn( array() );
        $log->shouldReceive( 'count_activities' )->andReturn( 0 );

        Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminActivityLogPage' )
            ->shouldReceive( 'build_query_args' )->andReturn( array( 'limit' => 50, 'offset' => 0, 'orderby' => 'created_at', 'order' => 'DESC' ) )
            ->getMock()->shouldReceive( 'render_rows_html' )->andReturn( '' )
            ->getMock()->shouldReceive( 'render_pagination_html' )->andReturn( '' );

        $_POST = array( 'nonce' => 'x' );

        try {
            ActivityLogAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '"is_empty":true', $e->getMessage() );
            $this->assertStringContainsString( '"total_logs":0', $e->getMessage() );
        }
    }
}
