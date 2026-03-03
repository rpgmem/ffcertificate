<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CPT;

/**
 * Tests for CPT: constructor hook registration, register_form_cpt,
 * add_duplicate_link, handle_form_duplication, and translate_views.
 *
 * @covers \FreeFormCertificate\Admin\CPT
 */
class CptTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface Alias mock for Utils */
    private $utils_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WP function stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( '_x' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'esc_url' )->returnArg();

        // Utils alias mock
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'current_user_can_manage' )->andReturn( true )->byDefault();
        $this->utils_mock->shouldReceive( 'debug_log' )->byDefault();
        $this->utils_mock->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault();
        $this->utils_mock->shouldReceive( 'truncate' )->andReturnUsing( function ( $str ) {
            return $str;
        } )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_GET['post'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_init_action(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'init', $registered );
    }

    public function test_constructor_registers_post_row_actions_filter(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'post_row_actions', $registered );
    }

    public function test_constructor_registers_duplicate_action(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'admin_action_ffc_duplicate_form', $registered );
    }

    public function test_constructor_registers_views_filter(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'views_edit-ffc_form', $registered );
    }

    // ==================================================================
    // register_form_cpt()
    // ==================================================================

    public function test_register_form_cpt_calls_register_post_type(): void {
        $captured_type = null;
        $captured_args = null;

        Functions\expect( 'register_post_type' )
            ->once()
            ->andReturnUsing( function ( $type, $args ) use ( &$captured_type, &$captured_args ) {
                $captured_type = $type;
                $captured_args = $args;
            } );

        $cpt = new CPT();
        $cpt->register_form_cpt();

        $this->assertSame( 'ffc_form', $captured_type );
        $this->assertFalse( $captured_args['public'] );
        $this->assertTrue( $captured_args['show_ui'] );
        $this->assertTrue( $captured_args['show_in_menu'] );
        $this->assertSame( 'dashicons-feedback', $captured_args['menu_icon'] );
        $this->assertSame( array( 'title' ), $captured_args['supports'] );
        $this->assertFalse( $captured_args['has_archive'] );
        $this->assertSame( 'post', $captured_args['capability_type'] );
    }

    // ==================================================================
    // add_duplicate_link()
    // ==================================================================

    public function test_add_duplicate_link_returns_unmodified_actions_for_non_ffc_post(): void {
        $post = (object) array(
            'ID'        => 1,
            'post_type' => 'post',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertSame( $actions, $result );
        $this->assertArrayNotHasKey( 'duplicate', $result );
    }

    public function test_add_duplicate_link_returns_unmodified_actions_when_user_cannot_manage(): void {
        $this->utils_mock->shouldReceive( 'current_user_can_manage' )->andReturn( false );

        $post = (object) array(
            'ID'        => 1,
            'post_type' => 'ffc_form',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertSame( $actions, $result );
        $this->assertArrayNotHasKey( 'duplicate', $result );
    }

    public function test_add_duplicate_link_adds_duplicate_action_for_ffc_form(): void {
        Functions\when( 'wp_nonce_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();

        $post = (object) array(
            'ID'        => 42,
            'post_type' => 'ffc_form',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertArrayHasKey( 'duplicate', $result );
        $this->assertStringContainsString( 'Duplicate', $result['duplicate'] );
        $this->assertStringContainsString( '<a href=', $result['duplicate'] );
    }

    // ==================================================================
    // handle_form_duplication()
    // ==================================================================

    public function test_handle_form_duplication_dies_when_user_cannot_manage(): void {
        $this->utils_mock->shouldReceive( 'current_user_can_manage' )->andReturn( false );

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/permission/' );

        $cpt->handle_form_duplication();
    }

    public function test_handle_form_duplication_dies_for_invalid_post(): void {
        $_GET['post'] = '999';

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_post' )->justReturn( null );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Invalid post/' );

        $cpt->handle_form_duplication();
    }

    public function test_handle_form_duplication_dies_for_wrong_post_type(): void {
        $_GET['post'] = '10';

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $wrong_type_post = (object) array(
            'ID'         => 10,
            'post_type'  => 'page',
            'post_title' => 'Some Page',
        );
        Functions\when( 'get_post' )->justReturn( $wrong_type_post );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Invalid post/' );

        $cpt->handle_form_duplication();
    }

    // ==================================================================
    // translate_views()
    // ==================================================================

    public function test_translate_views_replaces_labels_in_known_views(): void {
        $views = array(
            'all'     => '<a href="edit.php" class="current">All <span class="count">(5)</span></a>',
            'publish' => '<a href="edit.php?status=publish">Published <span class="count">(3)</span></a>',
            'draft'   => '<a href="edit.php?status=draft">Draft <span class="count">(2)</span></a>',
        );

        $cpt = new CPT();
        $result = $cpt->translate_views( $views );

        // Since __ returns arg 1, the label text in the map for 'all' is 'All'
        // The regex replacement should place the mapped label before the <span
        $this->assertStringContainsString( 'All', $result['all'] );
        $this->assertStringContainsString( 'Published', $result['publish'] );
        $this->assertStringContainsString( 'Draft', $result['draft'] );
        $this->assertStringContainsString( '<span', $result['all'] );
    }

    public function test_translate_views_preserves_unknown_views(): void {
        $views = array(
            'custom_view' => '<a href="edit.php?status=custom">Custom <span class="count">(1)</span></a>',
        );

        $cpt = new CPT();
        $result = $cpt->translate_views( $views );

        // Unknown keys should not be altered
        $this->assertSame( $views['custom_view'], $result['custom_view'] );
    }

    public function test_translate_views_returns_empty_array_for_empty_input(): void {
        $cpt = new CPT();
        $result = $cpt->translate_views( array() );

        $this->assertSame( array(), $result );
    }
}
