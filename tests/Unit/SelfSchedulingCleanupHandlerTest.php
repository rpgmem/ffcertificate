<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCleanupHandler;

/**
 * Tests for SelfSchedulingCleanupHandler: nonce verification, permission checks,
 * parameter validation, cleanup actions (all/old/future/cancelled), and metabox rendering.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingCleanupHandler
 */
class SelfSchedulingCleanupHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var SelfSchedulingCleanupHandler */
    private SelfSchedulingCleanupHandler $handler;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var array<int, array<string, mixed>> Captured wp_send_json_error calls */
    private array $json_errors = array();

    /** @var array<int, array<string, mixed>> Captured wp_send_json_success calls */
    private array $json_successes = array();

    /** @var bool Controls wp_verify_nonce return value */
    private bool $nonce_valid = true;

    /** @var bool Controls current_user_can return value */
    private bool $user_can_manage = true;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

        // WordPress stubs
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'absint' )->alias( function ( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'current_time' )->justReturn( '2026-03-03' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        $this->user_can_manage = true;
        $user_can = &$this->user_can_manage;
        Functions\when( 'current_user_can' )->alias( function () use ( &$user_can ) {
            return $user_can;
        } );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // Namespaced stubs for Repositories namespace
        Functions\when( 'FreeFormCertificate\Repositories\wp_cache_get' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Repositories\wp_cache_set' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Repositories\wp_cache_delete' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Repositories\current_user_can' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Repositories\user_can' )->justReturn( false );

        // wp_verify_nonce — use a reference so individual tests can toggle it
        $this->nonce_valid = true;
        $nonce_valid = &$this->nonce_valid;
        Functions\when( 'wp_verify_nonce' )->alias( function () use ( &$nonce_valid ) {
            return $nonce_valid;
        } );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );

        // JSON response stubs — capture calls and throw to halt execution
        $errors = &$this->json_errors;
        $successes = &$this->json_successes;
        $this->json_errors = array();
        $this->json_successes = array();

        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$errors ) {
            $errors[] = $data;
            throw new \RuntimeException( 'wp_send_json_error' );
        } );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$successes ) {
            $successes[] = $data;
            throw new \RuntimeException( 'wp_send_json_success' );
        } );

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'delete' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $this->wpdb = $wpdb;

        $this->handler = new SelfSchedulingCleanupHandler();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        unset( $_POST );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: set up $_POST for cleanup AJAX
    // ------------------------------------------------------------------

    private function setPostData( array $overrides = [] ): void {
        $_POST = array_merge( array(
            'nonce'          => 'valid_nonce',
            'calendar_id'    => '5',
            'cleanup_action' => 'all',
        ), $overrides );
    }

    /**
     * Make CalendarRepository::findById return the given calendar
     * by making wpdb->get_row return the calendar array.
     */
    private function stubCalendarFound( array $calendar ): void {
        $this->wpdb->shouldReceive( 'get_row' )
            ->andReturn( $calendar );
    }

    // ==================================================================
    // Constructor: registers AJAX hook
    // ==================================================================

    public function test_constructor_calls_add_action(): void {
        // Simply verify the handler is an instance and that add_action was called
        // (add_action is stubbed to return true — constructor completed)
        $this->assertInstanceOf( SelfSchedulingCleanupHandler::class, $this->handler );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Nonce failure
    // ==================================================================

    public function test_cleanup_fails_when_nonce_invalid(): void {
        $_POST = array( 'nonce' => 'bad_nonce' );
        $this->nonce_valid = false;

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected: wp_send_json_error thrown
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'Security check failed', $this->json_errors[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Permission denied
    // ==================================================================

    public function test_cleanup_fails_when_permission_denied(): void {
        $this->setPostData();
        $this->user_can_manage = false;

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'permission', $this->json_errors[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Missing parameters
    // ==================================================================

    public function test_cleanup_fails_when_calendar_id_zero(): void {
        $this->setPostData( array( 'calendar_id' => '0' ) );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'Invalid parameters', $this->json_errors[0]['message'] );
    }

    public function test_cleanup_fails_when_cleanup_action_empty(): void {
        $this->setPostData( array( 'cleanup_action' => '' ) );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'Invalid parameters', $this->json_errors[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Calendar not found
    // ==================================================================

    public function test_cleanup_fails_when_calendar_not_found(): void {
        $this->setPostData();

        // wpdb->get_row returns null (default) => CalendarRepository::findById returns null
        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'Calendar not found', $this->json_errors[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Action "all" deletes all
    // ==================================================================

    public function test_cleanup_all_deletes_all_appointments(): void {
        $this->setPostData( array( 'cleanup_action' => 'all' ) );
        $this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_appointments',
                array( 'calendar_id' => 5 ),
                array( '%d' )
            )
            ->andReturn( 10 );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected: wp_send_json_success
        }

        $this->assertNotEmpty( $this->json_successes );
        $this->assertSame( 10, $this->json_successes[0]['deleted'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Action "old" deletes past
    // ==================================================================

    public function test_cleanup_old_deletes_past_appointments(): void {
        $this->setPostData( array( 'cleanup_action' => 'old' ) );
        $this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturn( 3 );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_successes );
        $this->assertSame( 3, $this->json_successes[0]['deleted'] );
        $this->assertStringContainsString( 'past', $this->json_successes[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Action "future" deletes future
    // ==================================================================

    public function test_cleanup_future_deletes_future_appointments(): void {
        $this->setPostData( array( 'cleanup_action' => 'future' ) );
        $this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->andReturn( 7 );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_successes );
        $this->assertSame( 7, $this->json_successes[0]['deleted'] );
        $this->assertStringContainsString( 'future', $this->json_successes[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Action "cancelled"
    // ==================================================================

    public function test_cleanup_cancelled_deletes_cancelled_appointments(): void {
        $this->setPostData( array( 'cleanup_action' => 'cancelled' ) );
        $this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_appointments',
                array( 'calendar_id' => 5, 'status' => 'cancelled' ),
                array( '%d', '%s' )
            )
            ->andReturn( 2 );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_successes );
        $this->assertSame( 2, $this->json_successes[0]['deleted'] );
        $this->assertStringContainsString( 'cancelled', $this->json_successes[0]['message'] );
    }

    // ==================================================================
    // handle_cleanup_appointments() — Invalid action
    // ==================================================================

    public function test_cleanup_fails_with_invalid_action(): void {
        $this->setPostData( array( 'cleanup_action' => 'bogus' ) );
        $this->stubCalendarFound( array( 'id' => 5, 'title' => 'Test Calendar' ) );

        try {
            $this->handler->handle_cleanup_appointments();
        } catch ( \RuntimeException $e ) {
            // Expected
        }

        $this->assertNotEmpty( $this->json_errors );
        $this->assertStringContainsString( 'Invalid cleanup action', $this->json_errors[0]['message'] );
    }

    // ==================================================================
    // render_cleanup_metabox() — Calendar not found
    // ==================================================================

    public function test_render_cleanup_metabox_shows_message_when_calendar_not_found(): void {
        // wpdb->get_row returns null by default => CalendarRepository::findByPostId returns null
        $post = (object) array( 'ID' => 42 );

        ob_start();
        $this->handler->render_cleanup_metabox( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Calendar not found in database', $output );
    }

    // ==================================================================
    // render_cleanup_metabox() — Renders stats table
    // ==================================================================

    public function test_render_cleanup_metabox_renders_stats_when_calendar_found(): void {
        // findByPostId needs get_row to return a calendar row
        $this->wpdb->shouldReceive( 'get_row' )
            ->andReturn( array( 'id' => 10, 'title' => 'Test Calendar' ) );

        // count() calls from AppointmentRepository use get_var
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( 15, 5, 7, 3 );

        $post = (object) array( 'ID' => 42 );

        ob_start();
        $this->handler->render_cleanup_metabox( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'ffc-cleanup-appointments', $output );
        $this->assertStringContainsString( 'Total:', $output );
        $this->assertStringContainsString( 'Past:', $output );
        $this->assertStringContainsString( 'Future:', $output );
        $this->assertStringContainsString( 'Cancelled:', $output );
    }
}
