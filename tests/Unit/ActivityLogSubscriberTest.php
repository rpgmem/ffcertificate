<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ActivityLogSubscriber;

/**
 * Tests for ActivityLogSubscriber: hook registrations, settings-saved
 * cache clearing, and logging method guard behaviour.
 */
class ActivityLogSubscriberTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var ActivityLogSubscriber */
    private $subscriber;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // ActivityLog::log() calls get_option to check if logging is enabled;
        // returning the log disabled flag causes an immediate return-false,
        // preventing any $wpdb usage inside ActivityLog.
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'enable_activity_log' => 0 );
            }
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor — hook registrations
    // ==================================================================

    public function test_registers_submission_hooks(): void {
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_after_submission_save', \Mockery::type( 'array' ), 10, 4 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_after_submission_update', \Mockery::type( 'array' ), 10, 2 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_submission_trashed', \Mockery::type( 'array' ), 10, 1 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_submission_restored', \Mockery::type( 'array' ), 10, 1 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_after_submission_delete', \Mockery::type( 'array' ), 10, 1 )
            ->once();

        // Allow remaining hooks
        Functions\expect( 'add_action' )
            ->with( \Mockery::pattern( '/^ffcertificate_after_appointment|ffcertificate_appointment|ffcertificate_settings|ffcertificate_daily/' ), \Mockery::any(), \Mockery::any(), \Mockery::any() )
            ->zeroOrMoreTimes();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_daily_cleanup_hook', \Mockery::any() )
            ->zeroOrMoreTimes();

        new ActivityLogSubscriber();
    }

    public function test_registers_appointment_hooks(): void {
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_after_appointment_create', \Mockery::type( 'array' ), 10, 3 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_appointment_cancelled', \Mockery::type( 'array' ), 10, 4 )
            ->once();

        // Allow remaining hooks
        Functions\expect( 'add_action' )
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        new ActivityLogSubscriber();
    }

    public function test_registers_settings_and_cleanup_hooks(): void {
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_settings_saved', \Mockery::type( 'array' ), 10, 1 )
            ->once();
        Functions\expect( 'add_action' )
            ->with( 'ffcertificate_daily_cleanup_hook', \Mockery::type( 'array' ) )
            ->once();

        // Allow remaining hooks
        Functions\expect( 'add_action' )
            ->withAnyArgs()
            ->zeroOrMoreTimes();

        new ActivityLogSubscriber();
    }

    // ==================================================================
    // on_settings_saved() — cache clearing
    // ==================================================================

    public function test_settings_saved_clears_wp_caches(): void {
        $cache_deletes = array();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->alias( function ( $key, $group = '' ) use ( &$cache_deletes ) {
            $cache_deletes[] = array( $key, $group );
            return true;
        } );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_settings_saved( array( 'some_key' => 'value' ) );

        $this->assertContains( array( 'ffc_settings', 'options' ), $cache_deletes );
        $this->assertContains( array( 'alloptions', 'options' ), $cache_deletes );
    }

    public function test_settings_saved_clears_transients(): void {
        $transient_deletes = array();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'delete_transient' )->alias( function ( $key ) use ( &$transient_deletes ) {
            $transient_deletes[] = $key;
            return true;
        } );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_settings_saved( array() );

        $this->assertContains( 'ffc_settings_cache', $transient_deletes );
        $this->assertContains( 'ffc_geolocation_cache', $transient_deletes );
        $this->assertContains( 'ffc_activity_stats_7', $transient_deletes );
        $this->assertContains( 'ffc_activity_stats_30', $transient_deletes );
        $this->assertContains( 'ffc_activity_stats_90', $transient_deletes );
    }

    // ==================================================================
    // Logging methods — smoke tests (logging disabled → no DB)
    // ==================================================================

    public function test_on_submission_created_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_submission_created( 1, 10, array( 'cpf_rf' => '12345678901' ), 'test@example.com' );
    }

    public function test_on_submission_updated_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_submission_updated( 1, array( 'field' => 'value' ) );
    }

    public function test_on_submission_trashed_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_submission_trashed( 1 );
    }

    public function test_on_submission_restored_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_submission_restored( 1 );
    }

    public function test_on_submission_deleted_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();
        $subscriber->on_submission_deleted( 1 );
    }

    public function test_on_appointment_created_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();

        $data = array(
            'calendar_id'      => 1,
            'appointment_date' => '2030-01-15',
            'start_time'       => '10:00',
            'status'           => 'confirmed',
            'user_id'          => 42,
            'user_ip'          => '127.0.0.1',
        );
        $subscriber->on_appointment_created( 100, $data, array() );
    }

    public function test_on_appointment_cancelled_runs_without_error(): void {
        Functions\when( 'add_action' )->justReturn( true );

        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'insert' );

        $subscriber = new ActivityLogSubscriber();

        $appointment = array( 'calendar_id' => 1 );
        $subscriber->on_appointment_cancelled( 100, $appointment, 'User request', 42 );
    }

    public function test_on_daily_cleanup_runs_without_error(): void {
        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->atLeast()->once()->andReturn( '' );
        $wpdb->shouldReceive( 'query' )->atLeast()->once()->andReturn( 0 );

        Functions\when( 'add_action' )->justReturn( true );
        $subscriber = new ActivityLogSubscriber();

        $subscriber->on_daily_cleanup();
    }
}
