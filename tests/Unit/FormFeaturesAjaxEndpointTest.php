<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormFeaturesAjaxEndpoint;

/**
 * Tests for the forms-list inline-toggle endpoint introduced in 6.5.6.
 *
 * @covers \FreeFormCertificate\Admin\FormFeaturesAjaxEndpoint
 */
class FormFeaturesAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * In-memory post-meta store; tests poke it via stubbed
     * get_post_meta / update_post_meta below.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return (int) abs( (int) $v ); } );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );

        $this->meta_store = array();
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return $this->meta_store[ $id ][ $key ] ?? '';
        } );
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->meta_store[ $id ][ $key ] = $value;
            return true;
        } );

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
        unset( $_POST['form_id'], $_POST['feature'], $_POST['value'], $_POST['nonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Guards
    // ==================================================================

    public function test_rejects_missing_form_id(): void {
        $_POST = array( 'nonce' => 'x', 'feature' => 'csv_public_enabled', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Missing form id' );
        FormFeaturesAjaxEndpoint::handle();
    }

    public function test_rejects_when_user_cannot_edit_the_form(): void {
        Functions\when( 'current_user_can' )->alias( function ( $cap, $id = null ) {
            return ! ( 'edit_post' === $cap && 42 === $id );
        } );

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'csv_public_enabled', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        FormFeaturesAjaxEndpoint::handle();
    }

    public function test_rejects_non_form_post(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_post_type' )->justReturn( 'page' );

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'csv_public_enabled', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Target is not a form' );
        FormFeaturesAjaxEndpoint::handle();
    }

    public function test_rejects_unknown_feature(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'mystery', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Unknown feature' );
        FormFeaturesAjaxEndpoint::handle();
    }

    // ==================================================================
    // Writes
    // ==================================================================

    public function test_flat_feature_writes_meta_directly(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'csv_public_enabled', 'value' => '1' );

        try {
            FormFeaturesAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
        }

        $this->assertSame( '1', $this->meta_store[42]['_ffc_csv_public_enabled'] );
    }

    public function test_flat_feature_falsey_value_writes_empty_string(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->meta_store[42]['_ffc_csv_public_enabled'] = '1';

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'csv_public_enabled', 'value' => '0' );

        try {
            FormFeaturesAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( '', $this->meta_store[42]['_ffc_csv_public_enabled'] );
    }

    public function test_nested_feature_writes_path_preserving_siblings(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->meta_store[42]['_ffc_form_config'] = array(
            'quiz_enabled'       => '',
            'quiz_show_score'    => '1',
            'unrelated_setting'  => 'keep me',
        );

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'quiz_enabled', 'value' => '1' );

        try {
            FormFeaturesAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $config = $this->meta_store[42]['_ffc_form_config'];
        $this->assertSame( '1', $config['quiz_enabled'] );
        $this->assertSame( '1', $config['quiz_show_score'] );
        $this->assertSame( 'keep me', $config['unrelated_setting'] );
    }

    public function test_nested_feature_creates_array_when_missing(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        // No _ffc_device_limit meta yet.

        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'device_enabled', 'value' => 'true' );

        try {
            FormFeaturesAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( array( 'enabled' => '1' ), $this->meta_store[42]['_ffc_device_limit'] );
    }

    public function test_per_post_capability_isolated_across_forms(): void {
        // User can edit form 42 but not form 99.
        Functions\when( 'current_user_can' )->alias( function ( $cap, $id = null ) {
            return 'edit_post' === $cap && 42 === $id;
        } );

        // Form 42 — accepted.
        $_POST = array( 'nonce' => 'x', 'form_id' => '42', 'feature' => 'csv_public_enabled', 'value' => '1' );
        try {
            FormFeaturesAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
        }
        $this->assertSame( '1', $this->meta_store[42]['_ffc_csv_public_enabled'] );

        // Form 99 — rejected.
        $_POST = array( 'nonce' => 'x', 'form_id' => '99', 'feature' => 'csv_public_enabled', 'value' => '1' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        FormFeaturesAjaxEndpoint::handle();
    }
}
