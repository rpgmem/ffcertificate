<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Deactivator;

/**
 * Tests for Deactivator: deactivation hook cleanup and destructive uninstall.
 *
 * @covers \FreeFormCertificate\Deactivator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DeactivatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var Mockery\MockInterface */
    private $utils_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb = $wpdb;

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        // Default stubs — overridden per test as needed
        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( 'wp_die: ' . $msg );
        });
        Functions\when( 'delete_option' )->justReturn( true );
        Functions\when( 'get_posts' )->justReturn( array() );
        Functions\when( 'wp_delete_post' )->justReturn( null );

        // Utils alias mock for get_submissions_table
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' )
            ->byDefault();

        // Default wpdb stubs for uninstall_cleanup
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' )->byDefault();
        $this->wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_POST['confirm_uninstall'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // deactivate()
    // ==================================================================

    public function test_deactivate_clears_scheduled_hooks(): void {
        $cleared = array();
        Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
            $cleared[] = $hook;
        });

        Deactivator::deactivate();

        $this->assertContains( 'ffcertificate_daily_cleanup_hook', $cleared );
        $this->assertContains( 'ffc_daily_cleanup_hook', $cleared );
        $this->assertContains( 'ffc_process_submission_hook', $cleared );
        $this->assertContains( 'ffc_warm_cache_hook', $cleared );
        $this->assertCount( 4, $cleared );
    }

    public function test_deactivate_flushes_rewrite_rules(): void {
        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        });

        Deactivator::deactivate();

        $this->assertTrue( $flushed );
    }

    // ==================================================================
    // uninstall_cleanup()
    // ==================================================================

    public function test_uninstall_cleanup_aborts_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $_POST['confirm_uninstall'] = 'yes';

        // Should return early — no wp_die, no delete_option
        $deleted = false;
        Functions\when( 'delete_option' )->alias( function () use ( &$deleted ) {
            $deleted = true;
        });

        Deactivator::uninstall_cleanup();

        $this->assertFalse( $deleted, 'uninstall_cleanup should abort when user lacks activate_plugins capability' );
    }

    public function test_uninstall_cleanup_dies_without_confirm_post(): void {
        // No $_POST['confirm_uninstall'] set at all
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/wp_die/' );

        Deactivator::uninstall_cleanup();
    }

    public function test_uninstall_cleanup_dies_with_wrong_confirm(): void {
        $_POST['confirm_uninstall'] = 'no';

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/wp_die/' );

        Deactivator::uninstall_cleanup();
    }

    public function test_uninstall_cleanup_drops_submissions_table(): void {
        $_POST['confirm_uninstall'] = 'yes';

        $this->utils_mock->shouldReceive( 'get_submissions_table' )
            ->once()
            ->andReturn( 'wp_ffc_submissions' );

        $queries = array();
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$queries ) {
            $args = func_get_args();
            $queries[] = $args[0];
            return 'PREPARED_QUERY';
        });
        $this->wpdb->shouldReceive( 'query' )->andReturn( 1 );

        Deactivator::uninstall_cleanup();

        $drop_queries = array_filter( $queries, function ( $q ) {
            return strpos( $q, 'DROP TABLE IF EXISTS' ) !== false;
        });
        $this->assertNotEmpty( $drop_queries, 'Should execute a DROP TABLE query for the submissions table' );
    }

    public function test_uninstall_cleanup_deletes_options(): void {
        $_POST['confirm_uninstall'] = 'yes';

        $deleted_options = array();
        Functions\when( 'delete_option' )->alias( function ( $option ) use ( &$deleted_options ) {
            $deleted_options[] = $option;
        });

        Deactivator::uninstall_cleanup();

        $this->assertContains( 'ffc_db_version', $deleted_options );
        $this->assertContains( 'ffc_settings', $deleted_options );
        $this->assertCount( 2, $deleted_options );
    }

    public function test_uninstall_cleanup_clears_cron_hooks(): void {
        $_POST['confirm_uninstall'] = 'yes';

        $cleared = array();
        Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
            $cleared[] = $hook;
        });

        Deactivator::uninstall_cleanup();

        $this->assertContains( 'ffcertificate_daily_cleanup_hook', $cleared );
        $this->assertContains( 'ffcertificate_process_submission_hook', $cleared );
        $this->assertContains( 'ffc_daily_cleanup_hook', $cleared );
        $this->assertContains( 'ffc_process_submission_hook', $cleared );
        $this->assertContains( 'ffc_warm_cache_hook', $cleared );
        $this->assertCount( 5, $cleared );
    }

    public function test_uninstall_cleanup_deletes_form_posts(): void {
        $_POST['confirm_uninstall'] = 'yes';

        Functions\when( 'get_posts' )->justReturn( array( 10, 20, 30 ) );

        $deleted_ids = array();
        Functions\when( 'wp_delete_post' )->alias( function ( $id, $force ) use ( &$deleted_ids ) {
            $deleted_ids[] = $id;
        });

        Deactivator::uninstall_cleanup();

        $this->assertSame( array( 10, 20, 30 ), $deleted_ids );
    }

    public function test_uninstall_cleanup_flushes_rewrite_rules(): void {
        $_POST['confirm_uninstall'] = 'yes';

        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        });

        Deactivator::uninstall_cleanup();

        $this->assertTrue( $flushed );
    }
}
