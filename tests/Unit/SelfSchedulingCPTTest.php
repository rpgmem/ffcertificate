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
        unset( $_GET['post'] );
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

    // ==================================================================
    // handle_calendar_duplication() — invalid calendar
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_calendar_duplication_dies_for_invalid_calendar(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_post' )->justReturn( null );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
            ->shouldReceive( 'log_self_scheduling' )->andReturnNull();

        $_GET['post'] = '5';

        $cpt = new SelfSchedulingCPT();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid calendar.' );
        $cpt->handle_calendar_duplication();
    }

    // ==================================================================
    // handle_calendar_duplication() — success copies metadata + redirects
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_calendar_duplication_copies_metadata_and_redirects(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_post' )->justReturn(
            (object) array( 'ID' => 5, 'post_type' => 'ffc_self_scheduling', 'post_title' => 'My Cal' )
        );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_insert_post' )->justReturn( 99 );

        $meta = array(
            '_ffc_self_scheduling_config'        => array( 'slot_duration' => 30 ),
            '_ffc_self_scheduling_working_hours' => array( 'mon' => '9-5' ),
            '_ffc_self_scheduling_email_config'  => array( 'enabled' => 1 ),
        );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $meta ) {
            return $meta[ $key ] ?? '';
        } );

        $updated = array();
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $val ) use ( &$updated ) {
            $updated[] = $key;
            return true;
        } );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
            ->shouldReceive( 'log_self_scheduling' )->andReturnNull();

        // wp_safe_redirect → exit; throw to stop before exit.
        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected' );
        } );

        $_GET['post'] = '5';

        $cpt = new SelfSchedulingCPT();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected' );
        $cpt->handle_calendar_duplication();
    }

    // ==================================================================
    // handle_calendar_duplication() — insert failure dies with error
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_calendar_duplication_dies_on_insert_error(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_post' )->justReturn(
            (object) array( 'ID' => 5, 'post_type' => 'ffc_self_scheduling', 'post_title' => 'My Cal' )
        );
        Functions\when( 'is_wp_error' )->justReturn( true );

        $error = Mockery::mock( 'WP_Error' );
        $error->shouldReceive( 'get_error_message' )->andReturn( 'insert failed' );
        Functions\when( 'wp_insert_post' )->justReturn( $error );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
            ->shouldReceive( 'log_self_scheduling' )->andReturnNull();

        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $_GET['post'] = '5';

        $cpt = new SelfSchedulingCPT();
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'insert failed' );
        $cpt->handle_calendar_duplication();
    }

    // ==================================================================
    // sync_calendar_data() — revision skip
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sync_calendar_data_skips_revision(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( true );

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_status' => 'publish', 'post_title' => 'X' );
        $cpt->sync_calendar_data( 1, $post, true );

        $this->assertTrue( true );
    }

    // ==================================================================
    // sync_calendar_data() — non-publish skip
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sync_calendar_data_skips_non_publish(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_status' => 'draft', 'post_title' => 'X' );
        $cpt->sync_calendar_data( 1, $post, true );

        $this->assertTrue( true );
    }

    // ==================================================================
    // sync_calendar_data() — creates a new record when none exists
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sync_calendar_data_creates_new_record(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 3 );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

        $config = wp_json_encode( array( 'description' => 'desc', 'slot_duration' => 45 ) );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $config ) {
            if ( '_ffc_self_scheduling_config' === $key ) {
                return $config;
            }
            if ( '_ffc_self_scheduling_working_hours' === $key ) {
                return array( 'mon' => '9-5' );
            }
            return array( 'enabled' => 1 );
        } );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $repo->shouldReceive( 'findByPostId' )->with( 12 )->andReturn( null );
        $repo->shouldReceive( 'createFromPost' )
            ->once()
            ->with(
                12,
                Mockery::on( function ( $data ) {
                    return 'desc' === $data['description']
                        && 45 === $data['slot_duration']
                        && 'New Cal' === $data['title']
                        && isset( $data['created_at'], $data['created_by'] );
                } )
            )
            ->andReturn( 1 );

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_status' => 'publish', 'post_title' => 'New Cal' );
        $cpt->sync_calendar_data( 12, $post, false );

        $this->assertTrue( true );
    }

    // ==================================================================
    // sync_calendar_data() — updates an existing record
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sync_calendar_data_updates_existing_record(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 3 );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $repo->shouldReceive( 'findByPostId' )->with( 12 )->andReturn( array( 'id' => 88 ) );
        $repo->shouldReceive( 'update' )->once()->with( 88, Mockery::type( 'array' ) )->andReturn( true );
        $repo->shouldReceive( 'createFromPost' )->never();

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_status' => 'publish', 'post_title' => 'Existing' );
        $cpt->sync_calendar_data( 12, $post, true );

        $this->assertTrue( true );
    }

    // ==================================================================
    // cleanup_calendar_data() — deletes record + cancels appointments
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cleanup_calendar_data_deletes_and_cancels_future(): void {
        Functions\when( 'get_current_user_id' )->justReturn( 3 );
        Functions\when( 'current_time' )->justReturn( '2026-01-01' );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );
        Functions\when( 'is_email' )->justReturn( true );
        Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
        Functions\when( 'wp_mail' )->justReturn( true );
        // EmailService::send() reads the global kill-switch via SettingsReader
        // (#662); ffc_settings / ffc_email_template resolve to arrays (emails
        // enabled + chrome defaults), any other key (admin_email) to a string.
        Functions\when( 'get_option' )->alias(
            static function ( $key, $default = false ) {
                return in_array( $key, array( 'ffc_settings', 'ffc_email_template' ), true ) ? array() : '';
            }
        );
        // The cancellation email now renders through the shared chrome.
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'wp_date' )->justReturn( '2026' );
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
            ->shouldReceive( 'log_self_scheduling' )->andReturnNull();
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'decrypt_field' )->andReturn( 'user@example.com' );

        $cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $cal_repo->shouldReceive( 'findByPostId' )->with( 20 )->andReturn( array( 'id' => 5, 'title' => 'Cal A' ) );
        $cal_repo->shouldReceive( 'delete' )->once()->with( 5 )->andReturn( true );

        $appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
        $appt_repo->shouldReceive( 'findByCalendarAfterWithStatus' )
            ->with( 5, '2026-01-01' )
            ->andReturn(
                array(
                    array(
                        'id'               => 100,
                        'appointment_date' => '2026-02-01',
                        'start_time'       => '09:00:00',
                    ),
                )
            );
        $appt_repo->shouldReceive( 'cancel' )->once()->andReturn( true );

        Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
            ->shouldReceive( 'format_wallclock_date' )->andReturn( '01/02/2026' )
            ->shouldReceive( 'format_wallclock_time' )->andReturn( '09:00' )
            ->shouldReceive( 'format_date' )->andReturn( '01/02/2026' ); // chrome footer {{date}}

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'ffc_self_scheduling' );
        $cpt->cleanup_calendar_data( 20, $post );

        $this->assertTrue( true );
    }

    // ==================================================================
    // cleanup_calendar_data() — no calendar record found (no-op)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cleanup_calendar_data_noop_when_no_record(): void {
        $cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
        $cal_repo->shouldReceive( 'findByPostId' )->with( 20 )->andReturn( null );
        $cal_repo->shouldReceive( 'delete' )->never();

        $cpt  = new SelfSchedulingCPT();
        $post = (object) array( 'post_type' => 'ffc_self_scheduling' );
        $cpt->cleanup_calendar_data( 20, $post );

        $this->assertTrue( true );
    }
}
