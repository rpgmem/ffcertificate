<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SubmissionsList;

/**
 * @covers \FreeFormCertificate\Admin\SubmissionsList
 */
class SubmissionsListTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_posts' )->justReturn( array() );
        Functions\when( 'add_query_arg' )->justReturn( '/' );
        Functions\when( 'remove_query_arg' )->justReturn( '/' );
        Functions\when( 'wp_nonce_url' )->justReturn( '/?_wpnonce=test' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );
        Functions\when( 'date_i18n' )->alias( function ( $f, $t ) { return date( $f, $t ); } );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        // SubmissionsList extends WP_List_Table which needs $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeList(): SubmissionsList {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        return new SubmissionsList( $handler );
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $list = $this->makeList();
        $this->assertInstanceOf( SubmissionsList::class, $list );
    }

    // ==================================================================
    // get_columns()
    // ==================================================================

    public function test_get_columns_returns_expected_keys(): void {
        $list = $this->makeList();
        $cols = $list->get_columns();

        $this->assertArrayHasKey( 'cb', $cols );
        $this->assertArrayHasKey( 'id', $cols );
        $this->assertArrayHasKey( 'form', $cols );
        $this->assertArrayHasKey( 'email', $cols );
        $this->assertArrayHasKey( 'data', $cols );
        $this->assertArrayHasKey( 'status', $cols );
        $this->assertArrayHasKey( 'submission_date', $cols );
        $this->assertArrayHasKey( 'actions', $cols );
    }

    // ==================================================================
    // no_items()
    // ==================================================================

    public function test_no_items_outputs_message(): void {
        $list = $this->makeList();
        ob_start();
        $list->no_items();
        $output = ob_get_clean();

        $this->assertNotEmpty( $output );
    }
}
