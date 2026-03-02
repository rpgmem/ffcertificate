<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\CalendarRepository;

/**
 * Tests for CalendarRepository: table name, cache group, findByPostId,
 * active calendars, working hours, email config, status updates,
 * public calendars, scheduling bypass, and createFromPost.
 *
 * Uses a mock wpdb to avoid real database access while testing repository logic.
 *
 * @covers \FreeFormCertificate\Repositories\CalendarRepository
 */
class CalendarRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var CalendarRepository */
    private CalendarRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock global $wpdb BEFORE constructing the repository
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $this->wpdb = $wpdb;

        // Stub WP cache functions
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        // Stub common WP functions
        Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_the_title' )->justReturn( 'Test Calendar' );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'user_can' )->justReturn( false );

        // Default prepare stub
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();

        $this->repo = new CalendarRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Table name and cache group
    // ==================================================================

    public function test_table_name_is_ffc_self_scheduling_calendars(): void {
        $ref = new \ReflectionClass( $this->repo );
        $table = $ref->getProperty( 'table' );
        $table->setAccessible( true );

        $this->assertSame( 'wp_ffc_self_scheduling_calendars', $table->getValue( $this->repo ) );
    }

    public function test_cache_group_is_ffc_self_scheduling_calendars(): void {
        $ref = new \ReflectionClass( $this->repo );
        $prop = $ref->getProperty( 'cache_group' );
        $prop->setAccessible( true );

        $this->assertSame( 'ffc_self_scheduling_calendars', $prop->getValue( $this->repo ) );
    }

    public function test_table_name_uses_wpdb_prefix(): void {
        global $wpdb;
        $wpdb->prefix = 'custom_';

        $repo = new CalendarRepository();
        $ref = new \ReflectionClass( $repo );
        $table = $ref->getProperty( 'table' );
        $table->setAccessible( true );

        $this->assertSame( 'custom_ffc_self_scheduling_calendars', $table->getValue( $repo ) );

        // Restore
        $wpdb->prefix = 'wp_';
    }

    // ==================================================================
    // findByPostId()
    // ==================================================================

    public function test_findByPostId_returns_row_on_cache_miss(): void {
        $row = [
            'id' => 1,
            'post_id' => 42,
            'title' => 'My Calendar',
            'status' => 'active',
        ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE post_id = 42'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->findByPostId( 42 );

        $this->assertSame( $row, $result );
    }

    public function test_findByPostId_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE post_id = 999'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $result = $this->repo->findByPostId( 999 );

        $this->assertNull( $result );
    }

    public function test_findByPostId_returns_cached_result_on_cache_hit(): void {
        $cached_row = [
            'id' => 1,
            'post_id' => 42,
            'title' => 'Cached Calendar',
            'status' => 'active',
        ];

        // Build a fresh repo with cache returning data for this specific key.
        // We need a fresh Brain\Monkey context to override wp_cache_get.
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when( 'wp_cache_get' )->alias( function ( $key, $group ) use ( $cached_row ) {
            if ( $key === 'post_42' && $group === 'ffc_self_scheduling_calendars' ) {
                return $cached_row;
            }
            return false;
        } );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_the_title' )->justReturn( 'Test Calendar' );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $this->wpdb = $wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();

        $repo = new CalendarRepository();

        // get_row should NOT be called when cache hits
        $this->wpdb->shouldNotReceive( 'get_row' );

        $result = $repo->findByPostId( 42 );

        $this->assertSame( $cached_row, $result );
    }

    // ==================================================================
    // getActiveCalendars()
    // ==================================================================

    public function test_getActiveCalendars_returns_active_calendars(): void {
        $rows = [
            ['id' => 1, 'title' => 'Calendar A', 'status' => 'active'],
            ['id' => 2, 'title' => 'Calendar B', 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active ORDER BY created_at DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->getActiveCalendars();

        $this->assertCount( 2, $result );
        $this->assertSame( 'Calendar A', $result[0]['title'] );
    }

    public function test_getActiveCalendars_with_limit_and_offset(): void {
        $rows = [
            ['id' => 2, 'title' => 'Calendar B', 'status' => 'active'],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active ORDER BY created_at DESC LIMIT 1 OFFSET 1'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->getActiveCalendars( 1, 1 );

        $this->assertCount( 1, $result );
        $this->assertSame( 'Calendar B', $result[0]['title'] );
    }

    public function test_getActiveCalendars_returns_empty_array_when_none(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active ORDER BY created_at DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        $result = $this->repo->getActiveCalendars();

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    // ==================================================================
    // getWithWorkingHours()
    // ==================================================================

    public function test_getWithWorkingHours_decodes_json_fields(): void {
        $working_hours = ['monday' => ['start' => '09:00', 'end' => '17:00']];
        $email_config = ['send_user_confirmation' => 1, 'send_admin_notification' => 0];

        $row = [
            'id' => 1,
            'title' => 'Calendar',
            'working_hours' => json_encode( $working_hours ),
            'email_config' => json_encode( $email_config ),
        ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 1'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->getWithWorkingHours( 1 );

        $this->assertIsArray( $result );
        $this->assertSame( $working_hours, $result['working_hours'] );
        $this->assertSame( $email_config, $result['email_config'] );
    }

    public function test_getWithWorkingHours_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 999'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $result = $this->repo->getWithWorkingHours( 999 );

        $this->assertNull( $result );
    }

    public function test_getWithWorkingHours_handles_empty_working_hours(): void {
        $row = [
            'id' => 1,
            'title' => 'Calendar',
            'working_hours' => '',
            'email_config' => '',
        ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 1'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->getWithWorkingHours( 1 );

        $this->assertIsArray( $result );
        // Empty strings should remain untouched (not decoded)
        $this->assertSame( '', $result['working_hours'] );
        $this->assertSame( '', $result['email_config'] );
    }

    public function test_getWithWorkingHours_handles_null_json_fields(): void {
        $row = [
            'id' => 1,
            'title' => 'Calendar',
            'working_hours' => null,
            'email_config' => null,
        ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 1'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->getWithWorkingHours( 1 );

        $this->assertIsArray( $result );
        // Null values should remain as-is since empty() is true for null
        $this->assertNull( $result['working_hours'] );
        $this->assertNull( $result['email_config'] );
    }

    // ==================================================================
    // updateWorkingHours()
    // ==================================================================

    public function test_updateWorkingHours_encodes_and_updates(): void {
        $working_hours = ['monday' => ['start' => '09:00', 'end' => '17:00']];

        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) use ( $working_hours ) {
                    return $data['working_hours'] === json_encode( $working_hours )
                        && $data['updated_at'] === '2026-03-01 12:00:00'
                        && $data['updated_by'] === 1;
                } ),
                ['id' => 5]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateWorkingHours( 5, $working_hours );

        $this->assertSame( 1, $result );
    }

    public function test_updateWorkingHours_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( false );

        $result = $this->repo->updateWorkingHours( 5, ['monday' => []] );

        $this->assertFalse( $result );
    }

    // ==================================================================
    // updateEmailConfig()
    // ==================================================================

    public function test_updateEmailConfig_encodes_and_updates(): void {
        $email_config = [
            'send_user_confirmation' => 1,
            'send_admin_notification' => 1,
        ];

        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) use ( $email_config ) {
                    return $data['email_config'] === json_encode( $email_config )
                        && $data['updated_at'] === '2026-03-01 12:00:00'
                        && $data['updated_by'] === 1;
                } ),
                ['id' => 3]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateEmailConfig( 3, $email_config );

        $this->assertSame( 1, $result );
    }

    public function test_updateEmailConfig_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( false );

        $result = $this->repo->updateEmailConfig( 3, ['send_user_confirmation' => 1] );

        $this->assertFalse( $result );
    }

    // ==================================================================
    // updateStatus()
    // ==================================================================

    public function test_updateStatus_updates_with_timestamps(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['status'] === 'inactive'
                        && $data['updated_at'] === '2026-03-01 12:00:00'
                        && $data['updated_by'] === 1;
                } ),
                ['id' => 7]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateStatus( 7, 'inactive' );

        $this->assertSame( 1, $result );
    }

    public function test_updateStatus_to_active(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['status'] === 'active';
                } ),
                ['id' => 7]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateStatus( 7, 'active' );

        $this->assertSame( 1, $result );
    }

    public function test_updateStatus_to_archived(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['status'] === 'archived';
                } ),
                ['id' => 10]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateStatus( 10, 'archived' );

        $this->assertSame( 1, $result );
    }

    public function test_updateStatus_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( false );

        $result = $this->repo->updateStatus( 7, 'inactive' );

        $this->assertFalse( $result );
    }

    // ==================================================================
    // getPublicActiveCalendars()
    // ==================================================================

    public function test_getPublicActiveCalendars_returns_public_active_calendars(): void {
        $rows = [
            ['id' => 1, 'title' => 'Public Calendar', 'status' => 'active', 'visibility' => 'public'],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active AND visibility = public ORDER BY created_at DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->getPublicActiveCalendars();

        $this->assertCount( 1, $result );
        $this->assertSame( 'Public Calendar', $result[0]['title'] );
    }

    public function test_getPublicActiveCalendars_with_limit_and_offset(): void {
        $rows = [
            ['id' => 3, 'title' => 'Third Calendar', 'status' => 'active', 'visibility' => 'public'],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active AND visibility = public ORDER BY created_at DESC LIMIT 5 OFFSET 10'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->getPublicActiveCalendars( 5, 10 );

        $this->assertCount( 1, $result );
    }

    public function test_getPublicActiveCalendars_returns_empty_when_none(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active AND visibility = public ORDER BY created_at DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        $result = $this->repo->getPublicActiveCalendars();

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    // ==================================================================
    // userHasSchedulingBypass() (static method)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_returns_true_for_admin_current_user(): void {
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return $cap === 'manage_options';
        });

        $result = CalendarRepository::userHasSchedulingBypass();

        $this->assertTrue( $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_returns_true_for_bypass_cap_current_user(): void {
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return $cap === 'ffc_scheduling_bypass';
        });

        $result = CalendarRepository::userHasSchedulingBypass();

        $this->assertTrue( $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_returns_false_for_unprivileged_current_user(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $result = CalendarRepository::userHasSchedulingBypass();

        $this->assertFalse( $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_with_specific_user_id_admin(): void {
        Functions\when( 'user_can' )->alias( function ( $user_id, $cap ) {
            return $user_id === 42 && $cap === 'manage_options';
        });

        $result = CalendarRepository::userHasSchedulingBypass( 42 );

        $this->assertTrue( $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_with_specific_user_id_bypass_cap(): void {
        Functions\when( 'user_can' )->alias( function ( $user_id, $cap ) {
            return $user_id === 42 && $cap === 'ffc_scheduling_bypass';
        });

        $result = CalendarRepository::userHasSchedulingBypass( 42 );

        $this->assertTrue( $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_userHasSchedulingBypass_with_specific_user_id_no_caps(): void {
        Functions\when( 'user_can' )->justReturn( false );

        $result = CalendarRepository::userHasSchedulingBypass( 99 );

        $this->assertFalse( $result );
    }

    // ==================================================================
    // createFromPost()
    // ==================================================================

    public function test_createFromPost_inserts_with_defaults(): void {
        $this->wpdb->insert_id = 10;

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['post_id'] === 42
                        && $data['title'] === 'Test Calendar'
                        && $data['description'] === ''
                        && $data['slot_duration'] === 30
                        && $data['slot_interval'] === 0
                        && $data['slots_per_day'] === 0
                        && $data['advance_booking_min'] === 0
                        && $data['advance_booking_max'] === 30
                        && $data['allow_cancellation'] === 1
                        && $data['cancellation_min_hours'] === 24
                        && $data['requires_approval'] === 0
                        && $data['max_appointments_per_slot'] === 1
                        && $data['visibility'] === 'public'
                        && $data['scheduling_visibility'] === 'public'
                        && $data['restrict_viewing_to_hours'] === 0
                        && $data['restrict_booking_to_hours'] === 0
                        && $data['status'] === 'active'
                        && $data['created_at'] === '2026-03-01 12:00:00'
                        && $data['created_by'] === 1
                        && $data['working_hours'] === json_encode( [] )
                        && json_decode( $data['email_config'], true ) === [
                            'send_user_confirmation' => 0,
                            'send_admin_notification' => 0,
                            'send_approval_notification' => 0,
                            'send_cancellation_notification' => 0,
                            'send_reminder' => 0,
                            'reminder_hours_before' => 24,
                        ];
                } )
            )
            ->andReturn( 1 ); // wpdb->insert returns rows affected

        $result = $this->repo->createFromPost( 42 );

        $this->assertSame( 10, $result );
    }

    public function test_createFromPost_merges_custom_data_over_defaults(): void {
        $this->wpdb->insert_id = 11;

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['post_id'] === 42
                        && $data['title'] === 'Custom Title'
                        && $data['slot_duration'] === 60
                        && $data['description'] === 'Custom description'
                        && $data['visibility'] === 'private';
                } )
            )
            ->andReturn( 1 );

        $result = $this->repo->createFromPost( 42, [
            'title' => 'Custom Title',
            'slot_duration' => 60,
            'description' => 'Custom description',
            'visibility' => 'private',
        ] );

        $this->assertSame( 11, $result );
    }

    public function test_createFromPost_returns_false_on_insert_failure(): void {
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturn( false );

        $result = $this->repo->createFromPost( 42 );

        $this->assertFalse( $result );
    }

    public function test_createFromPost_uses_get_the_title_for_default_title(): void {
        // Re-setup with a different get_the_title mock to verify it gets called
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'get_the_title' )->alias( function ( $post_id ) {
            return $post_id === 55 ? 'My Post Title' : 'Default Title';
        } );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 12;
        $this->wpdb = $wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();

        $repo = new CalendarRepository();

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    return $data['title'] === 'My Post Title'
                        && $data['post_id'] === 55;
                } )
            )
            ->andReturn( 1 );

        $result = $repo->createFromPost( 55 );

        $this->assertSame( 12, $result );
    }

    // ==================================================================
    // Inherited AbstractRepository methods (basic coverage)
    // ==================================================================

    public function test_findById_returns_row(): void {
        $row = ['id' => 1, 'title' => 'Test Calendar', 'status' => 'active'];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 1'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->findById( 1 );

        $this->assertSame( $row, $result );
    }

    public function test_findById_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE id = 999'
        );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $result = $this->repo->findById( 999 );

        $this->assertNull( $result );
    }

    public function test_insert_returns_insert_id_on_success(): void {
        $this->wpdb->insert_id = 5;

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                ['title' => 'New Calendar', 'status' => 'active']
            )
            ->andReturn( 1 );

        $result = $this->repo->insert( ['title' => 'New Calendar', 'status' => 'active'] );

        $this->assertSame( 5, $result );
    }

    public function test_insert_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturn( false );

        $result = $this->repo->insert( ['title' => 'Fail Calendar'] );

        $this->assertFalse( $result );
    }

    public function test_update_returns_rows_affected(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                ['title' => 'Updated'],
                ['id' => 1]
            )
            ->andReturn( 1 );

        $result = $this->repo->update( 1, ['title' => 'Updated'] );

        $this->assertSame( 1, $result );
    }

    public function test_delete_returns_rows_affected(): void {
        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                ['id' => 1]
            )
            ->andReturn( 1 );

        $result = $this->repo->delete( 1 );

        $this->assertSame( 1, $result );
    }

    public function test_count_returns_integer(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn(
            'SELECT COUNT(*) FROM wp_ffc_self_scheduling_calendars'
        );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '3' );

        $result = $this->repo->count();

        $this->assertSame( 3, $result );
    }

    public function test_begin_transaction_calls_start_transaction(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->with( 'START TRANSACTION' )
            ->andReturn( true );

        $result = $this->repo->begin_transaction();

        $this->assertTrue( $result );
    }

    public function test_commit_calls_commit(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->with( 'COMMIT' )
            ->andReturn( true );

        $result = $this->repo->commit();

        $this->assertTrue( $result );
    }

    public function test_rollback_calls_rollback(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->once()
            ->with( 'ROLLBACK' )
            ->andReturn( true );

        $result = $this->repo->rollback();

        $this->assertTrue( $result );
    }

    // ==================================================================
    // findAll (via getActiveCalendars / getPublicActiveCalendars)
    // ==================================================================

    public function test_findAll_with_no_conditions(): void {
        $rows = [
            ['id' => 1, 'title' => 'Calendar 1'],
            ['id' => 2, 'title' => 'Calendar 2'],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars ORDER BY id DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->findAll();

        $this->assertCount( 2, $result );
    }

    // ==================================================================
    // Edge cases
    // ==================================================================

    public function test_updateWorkingHours_encodes_complex_schedule(): void {
        $working_hours = [
            'monday'    => ['start' => '08:00', 'end' => '12:00', 'break_start' => '10:00', 'break_end' => '10:30'],
            'tuesday'   => ['start' => '09:00', 'end' => '17:00'],
            'wednesday' => ['start' => '09:00', 'end' => '17:00'],
            'thursday'  => ['start' => '09:00', 'end' => '17:00'],
            'friday'    => ['start' => '09:00', 'end' => '13:00'],
            'saturday'  => null,
            'sunday'    => null,
        ];

        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) use ( $working_hours ) {
                    $decoded = json_decode( $data['working_hours'], true );
                    return $decoded === $working_hours;
                } ),
                ['id' => 1]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateWorkingHours( 1, $working_hours );

        $this->assertSame( 1, $result );
    }

    public function test_updateEmailConfig_encodes_full_config(): void {
        $email_config = [
            'send_user_confirmation' => 1,
            'send_admin_notification' => 1,
            'send_approval_notification' => 1,
            'send_cancellation_notification' => 1,
            'send_reminder' => 1,
            'reminder_hours_before' => 48,
        ];

        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) use ( $email_config ) {
                    $decoded = json_decode( $data['email_config'], true );
                    return $decoded === $email_config;
                } ),
                ['id' => 2]
            )
            ->andReturn( 1 );

        $result = $this->repo->updateEmailConfig( 2, $email_config );

        $this->assertSame( 1, $result );
    }

    public function test_createFromPost_default_email_config_structure(): void {
        $this->wpdb->insert_id = 20;

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_self_scheduling_calendars',
                Mockery::on( function ( $data ) {
                    $email = json_decode( $data['email_config'], true );
                    return is_array( $email )
                        && array_key_exists( 'send_user_confirmation', $email )
                        && array_key_exists( 'send_admin_notification', $email )
                        && array_key_exists( 'send_approval_notification', $email )
                        && array_key_exists( 'send_cancellation_notification', $email )
                        && array_key_exists( 'send_reminder', $email )
                        && array_key_exists( 'reminder_hours_before', $email )
                        && $email['send_user_confirmation'] === 0
                        && $email['reminder_hours_before'] === 24;
                } )
            )
            ->andReturn( 1 );

        $result = $this->repo->createFromPost( 100 );

        $this->assertSame( 20, $result );
    }

    public function test_getPublicActiveCalendars_default_params(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn(
            'SELECT * FROM wp_ffc_self_scheduling_calendars WHERE status = active AND visibility = public ORDER BY created_at DESC'
        );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        // Call with default params (null limit, 0 offset)
        $result = $this->repo->getPublicActiveCalendars( null, 0 );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }
}
