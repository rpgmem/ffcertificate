<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Stub exposing AjaxTrait's protected methods for testing.
 */
class AjaxTraitStub {
    use \FreeFormCertificate\Core\AjaxTrait;

    public function pub_get_post_param( string $key, string $default = '' ): string {
        return $this->get_post_param( $key, $default );
    }

    public function pub_get_post_int( string $key, int $default = 0 ): int {
        return $this->get_post_int( $key, $default );
    }

    public function pub_get_post_array( string $key ): array {
        return $this->get_post_array( $key );
    }

    public function pub_verify_ajax_nonce( $actions, string $field = 'nonce' ): void {
        $this->verify_ajax_nonce( $actions, $field );
    }

    public function pub_check_ajax_permission( string $cap = 'manage_options' ): void {
        $this->check_ajax_permission( $cap );
    }
}

/**
 * Tests for AjaxTrait: POST parameter sanitization, nonce verification,
 * permission checks.
 */
class AjaxTraitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var AjaxTraitStub */
    private $stub;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );

        $this->stub = new AjaxTraitStub();
    }

    protected function tearDown(): void {
        unset( $_POST['test_field'], $_POST['nonce'], $_POST['tags'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_post_param()
    // ==================================================================

    public function test_post_param_returns_value(): void {
        $_POST['test_field'] = 'hello';
        $this->assertSame( 'hello', $this->stub->pub_get_post_param( 'test_field' ) );
    }

    public function test_post_param_returns_default_when_missing(): void {
        $this->assertSame( 'fallback', $this->stub->pub_get_post_param( 'missing', 'fallback' ) );
    }

    public function test_post_param_empty_default(): void {
        $this->assertSame( '', $this->stub->pub_get_post_param( 'missing' ) );
    }

    // ==================================================================
    // get_post_int()
    // ==================================================================

    public function test_post_int_returns_integer(): void {
        $_POST['test_field'] = '42';
        $this->assertSame( 42, $this->stub->pub_get_post_int( 'test_field' ) );
    }

    public function test_post_int_returns_default_when_missing(): void {
        $this->assertSame( 5, $this->stub->pub_get_post_int( 'missing', 5 ) );
    }

    public function test_post_int_negative_becomes_positive(): void {
        $_POST['test_field'] = '-10';
        $this->assertSame( 10, $this->stub->pub_get_post_int( 'test_field' ) );
    }

    public function test_post_int_non_numeric_returns_zero(): void {
        $_POST['test_field'] = 'abc';
        $this->assertSame( 0, $this->stub->pub_get_post_int( 'test_field' ) );
    }

    // ==================================================================
    // get_post_array()
    // ==================================================================

    public function test_post_array_returns_sanitized_values(): void {
        $_POST['tags'] = array( 'red', 'blue', 'green' );
        $this->assertSame( array( 'red', 'blue', 'green' ), $this->stub->pub_get_post_array( 'tags' ) );
    }

    public function test_post_array_returns_empty_when_missing(): void {
        $this->assertSame( array(), $this->stub->pub_get_post_array( 'missing' ) );
    }

    public function test_post_array_returns_empty_when_not_array(): void {
        $_POST['tags'] = 'not_array';
        $this->assertSame( array(), $this->stub->pub_get_post_array( 'tags' ) );
    }

    // ==================================================================
    // verify_ajax_nonce()
    // ==================================================================

    public function test_nonce_valid_passes(): void {
        $_POST['nonce'] = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

        // Should not call wp_send_json_error
        $called = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$called ) {
            $called = true;
        } );

        $this->stub->pub_verify_ajax_nonce( 'ffc_action' );
        $this->assertFalse( $called );
    }

    public function test_nonce_fallback_action_accepted(): void {
        $_POST['nonce'] = 'valid_nonce';
        Functions\when( 'wp_verify_nonce' )->alias( function ( $nonce, $action ) {
            return $action === 'ffc_fallback' ? 1 : false;
        } );

        $called = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$called ) {
            $called = true;
        } );

        $this->stub->pub_verify_ajax_nonce( array( 'ffc_primary', 'ffc_fallback' ) );
        $this->assertFalse( $called );
    }

    public function test_nonce_missing_sends_error(): void {
        // No $_POST['nonce'] set — wp_send_json_error must throw to simulate die()
        Functions\when( 'wp_send_json_error' )->alias( function () {
            throw new \RuntimeException( 'wp_send_json_error called' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->stub->pub_verify_ajax_nonce( 'ffc_action' );
    }

    public function test_nonce_custom_field_name(): void {
        $_POST['custom_nonce'] = 'value';
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

        $called = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$called ) {
            $called = true;
        } );

        $this->stub->pub_verify_ajax_nonce( 'ffc_action', 'custom_nonce' );
        $this->assertFalse( $called );
    }

    // ==================================================================
    // check_ajax_permission()
    // ==================================================================

    public function test_permission_granted_passes(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $called = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$called ) {
            $called = true;
        } );

        $this->stub->pub_check_ajax_permission( 'edit_posts' );
        $this->assertFalse( $called );
    }

    public function test_permission_denied_sends_error(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $error_sent = false;
        Functions\when( 'wp_send_json_error' )->alias( function () use ( &$error_sent ) {
            $error_sent = true;
        } );

        $this->stub->pub_check_ajax_permission( 'edit_posts' );
        $this->assertTrue( $error_sent );
    }
}
