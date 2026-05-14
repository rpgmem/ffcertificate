<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CacheActionsAjaxEndpoint;

/**
 * Tests for the cache-actions AJAX endpoints (Warm + Clear) introduced in 6.5.5.
 *
 * Run in separate processes because we Mockery::alias the static
 * `Utils` and `FormCache` classes; a sibling test file may have
 * already loaded the real class in the same process otherwise.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @covers \FreeFormCertificate\Admin\CacheActionsAjaxEndpoint
 */
class CacheActionsAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->alias( function ( $singular, $plural, $count ) {
            return $count === 1 ? $singular : $plural;
        } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        // wp_send_json_* throw so we can assert which branch fired.
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            $msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
            throw new \RuntimeException( 'json_error: ' . $msg );
        } );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            // Encode count + message so tests can fish them out.
            $payload = wp_json_encode( $data );
            throw new \RuntimeException( 'json_success: ' . $payload );
        } );
        if ( ! function_exists( 'wp_json_encode' ) ) {
            // phpcs:ignore Generic.PHP.LowerCaseConstant
            eval( 'function wp_json_encode( $data ) { return json_encode( $data ); }' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Capability gate
    // ==================================================================

    public function test_warm_rejects_when_user_lacks_capability(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->with( 'ffc_manage_settings' )
            ->andReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        CacheActionsAjaxEndpoint::handle_warm();
    }

    public function test_clear_rejects_when_user_lacks_capability(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->with( 'ffc_manage_settings' )
            ->andReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        CacheActionsAjaxEndpoint::handle_clear();
    }

    // ==================================================================
    // Happy path
    // ==================================================================

    public function test_warm_returns_count_from_form_cache(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' )
            ->shouldReceive( 'warm_all_forms' )
            ->once()
            ->andReturn( 7 );

        try {
            CacheActionsAjaxEndpoint::handle_warm();
            $this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
            $this->assertStringContainsString( '"count":7', $e->getMessage() );
            $this->assertStringContainsString( 'Cache warmed for 7 forms', $e->getMessage() );
        }
    }

    public function test_warm_singular_message_when_only_one_form(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' )
            ->shouldReceive( 'warm_all_forms' )
            ->andReturn( 1 );

        try {
            CacheActionsAjaxEndpoint::handle_warm();
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '"count":1', $e->getMessage() );
            $this->assertStringContainsString( 'Cache warmed for 1 form.', $e->getMessage() );
        }
    }

    public function test_clear_invokes_form_cache_clear_all(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Submissions\FormCache' )
            ->shouldReceive( 'clear_all_cache' )
            ->once()
            ->andReturn( true );

        try {
            CacheActionsAjaxEndpoint::handle_clear();
            $this->fail( 'Expected wp_send_json_success to short-circuit via exception' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
            $this->assertStringContainsString( 'Cache cleared.', $e->getMessage() );
        }
    }
}
