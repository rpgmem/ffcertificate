<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\DynamicFragments;

/**
 * Tests for DynamicFragments: AJAX endpoint that returns fresh captcha/nonce data
 * for pages served from full-page cache.
 *
 * @covers \FreeFormCertificate\Frontend\DynamicFragments
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DynamicFragmentsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array{type: string, data: mixed}> Captured JSON responses */
    private array $json_responses = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );

        $this->json_responses = array();
        $responses = &$this->json_responses;

        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'success', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_success' );
        } );

        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
            $responses[] = array( 'type' => 'error', 'data' => $data );
            throw new \RuntimeException( 'wp_send_json_error' );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: call handle() and catch the RuntimeException from JSON mock.
     */
    private function callHandle( DynamicFragments $fragments ): void {
        try {
            $fragments->handle();
        } catch ( \RuntimeException $e ) {
            // Expected
        }
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_ajax_hooks(): void {
        // add_action is stubbed in setUp — verify constructor completes
        $fragments = new DynamicFragments();
        $this->assertInstanceOf( DynamicFragments::class, $fragments );
    }

    // ==================================================================
    // handle() — anonymous user
    // ==================================================================

    public function test_handle_returns_captcha_and_nonces_for_anonymous(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'generate_simple_captcha' )
            ->once()
            ->andReturn( array( 'label' => '3 + 4', 'hash' => 'abc123' ) );

        Functions\when( 'wp_create_nonce' )->alias( function ( $action ) {
            return 'nonce_' . $action;
        } );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $fragments = new DynamicFragments();
        $this->callHandle( $fragments );

        $this->assertCount( 1, $this->json_responses );
        $data = $this->json_responses[0]['data'];
        $this->assertSame( 'success', $this->json_responses[0]['type'] );

        // Captcha
        $this->assertSame( '3 + 4', $data['captcha']['label'] );
        $this->assertSame( 'abc123', $data['captcha']['hash'] );

        // Nonces
        $this->assertSame( 'nonce_ffc_frontend_nonce', $data['nonces']['ffc_frontend_nonce'] );
        $this->assertSame( 'nonce_ffc_self_scheduling_nonce', $data['nonces']['ffc_self_scheduling_nonce'] );

        // No user data for anonymous
        $this->assertArrayNotHasKey( 'user', $data );
    }

    // ==================================================================
    // handle() — logged-in user
    // ==================================================================

    public function test_handle_includes_user_data_when_logged_in(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'generate_simple_captcha' )
            ->once()
            ->andReturn( array( 'label' => '5 + 2', 'hash' => 'def456' ) );

        Functions\when( 'wp_create_nonce' )->justReturn( 'fresh_nonce' );
        Functions\when( 'is_user_logged_in' )->justReturn( true );

        $user = (object) array(
            'display_name' => 'Maria Silva',
            'user_email'   => 'maria@example.com',
        );
        Functions\when( 'wp_get_current_user' )->justReturn( $user );

        $fragments = new DynamicFragments();
        $this->callHandle( $fragments );

        $data = $this->json_responses[0]['data'];

        // User data included
        $this->assertArrayHasKey( 'user', $data );
        $this->assertSame( 'Maria Silva', $data['user']['name'] );
        $this->assertSame( 'maria@example.com', $data['user']['email'] );
    }

    // ==================================================================
    // handle() — captcha data is always present
    // ==================================================================

    public function test_handle_always_returns_both_nonce_keys(): void {
        $utilsMock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'generate_simple_captcha' )
            ->andReturn( array( 'label' => '1 + 1', 'hash' => 'h' ) );

        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'is_user_logged_in' )->justReturn( false );

        $fragments = new DynamicFragments();
        $this->callHandle( $fragments );

        $nonces = $this->json_responses[0]['data']['nonces'];
        $this->assertArrayHasKey( 'ffc_frontend_nonce', $nonces );
        $this->assertArrayHasKey( 'ffc_self_scheduling_nonce', $nonces );
    }
}
