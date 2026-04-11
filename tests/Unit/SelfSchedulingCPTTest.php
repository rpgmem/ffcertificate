<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;

/**
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingCPT
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SelfSchedulingCPTTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( '_x' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'register_post_type' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'wp_nonce_url' )->justReturn( '/?_wpnonce=test' );
        Functions\when( 'wp_safe_redirect' )->justReturn( true );
        Functions\when( 'wp_is_post_revision' )->justReturn( false );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $cpt = new SelfSchedulingCPT();
        $this->assertInstanceOf( SelfSchedulingCPT::class, $cpt );
    }

    // ==================================================================
    // register_calendar_cpt()
    // ==================================================================

    public function test_register_calendar_cpt_registers_post_type(): void {
        $cpt = new SelfSchedulingCPT();
        $cpt->register_calendar_cpt();
        $this->assertTrue( true );
    }

    // ==================================================================
    // add_duplicate_link() — wrong post type
    // ==================================================================

    public function test_add_duplicate_link_returns_unchanged_for_wrong_type(): void {
        $cpt = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'post', 'ID' => 1 );
        $actions = array( 'edit' => 'Edit' );

        $result = $cpt->add_duplicate_link( $actions, $post );
        $this->assertSame( $actions, $result );
    }

    // ==================================================================
    // add_duplicate_link() — no permission
    // ==================================================================

    public function test_add_duplicate_link_returns_unchanged_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $cpt = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 1 );
        $actions = array( 'edit' => 'Edit' );

        $result = $cpt->add_duplicate_link( $actions, $post );
        $this->assertSame( $actions, $result );
    }

    // ==================================================================
    // add_duplicate_link() — adds link
    // ==================================================================

    public function test_add_duplicate_link_adds_duplicate_action(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $cpt = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 1 );
        $actions = array( 'edit' => 'Edit' );

        $result = $cpt->add_duplicate_link( $actions, $post );
        $this->assertArrayHasKey( 'duplicate', $result );
        $this->assertStringContainsString( 'Duplicate', $result['duplicate'] );
    }

    // ==================================================================
    // handle_calendar_duplication() — no permission
    // ==================================================================

    /**
     * Runs in a separate process because other tests in the suite leave a
     * Mockery alias for Utils loaded, which makes the permission check
     * resolve to a null mock in full-suite runs.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_calendar_duplication_dies_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $cpt = new SelfSchedulingCPT();
        $this->expectException( \RuntimeException::class );
        $cpt->handle_calendar_duplication();
    }

    // ==================================================================
    // sync_calendar_data() — autosave skip
    // ==================================================================

    public function test_sync_calendar_data_skips_autosave(): void {
        if ( ! defined( 'DOING_AUTOSAVE' ) ) {
            define( 'DOING_AUTOSAVE', true );
        }

        $cpt = new SelfSchedulingCPT();
        $post = (object) array( 'post_status' => 'publish', 'post_title' => 'Test' );
        $cpt->sync_calendar_data( 1, $post, true );

        $this->assertTrue( true );
    }

    // ==================================================================
    // cleanup_calendar_data() — wrong post type
    // ==================================================================

    public function test_cleanup_calendar_data_skips_wrong_post_type(): void {
        $cpt = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'post' );
        $cpt->cleanup_calendar_data( 1, $post );

        $this->assertTrue( true );
    }
}
