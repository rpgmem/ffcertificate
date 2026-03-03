<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationUserProfiles;

/**
 * Tests for MigrationUserProfiles: populates ffc_user_profiles from existing ffc_users.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationUserProfiles
 */
class MigrationUserProfilesTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface&\stdClass */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
        $this->wpdb = $wpdb;

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-03-03 12:00:00' );

        // Namespaced stubs
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( '' );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\current_time' )->justReturn( '2026-03-03 12:00:00' );
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );
        Functions\when( 'FreeFormCertificate\Migrations\get_userdata' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\get_user_meta' )->justReturn( '' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // run() — table does not exist
    // ==================================================================

    public function test_run_returns_failure_when_table_does_not_exist(): void {
        // table_exists checks SHOW TABLES LIKE => returns null (no match)
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null );

        $result = MigrationUserProfiles::run();

        $this->assertFalse( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( 1, $result['errors'] );
        $this->assertStringContainsString( 'does not exist', $result['message'] );
    }

    // ==================================================================
    // run() — no ffc_users found
    // ==================================================================

    public function test_run_returns_success_with_zero_processed_when_no_users(): void {
        // table_exists: SHOW TABLES LIKE returns the table name
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles' );

        // get_users returns empty
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );

        $result = MigrationUserProfiles::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
        $this->assertSame( 0, $result['created'] );
        $this->assertSame( 0, $result['skipped'] );
        $this->assertStringContainsString( 'No FFC users found', $result['message'] );
    }

    // ==================================================================
    // run() — user profile already exists (skipped)
    // ==================================================================

    public function test_run_skips_user_that_already_has_profile(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles' );

        // get_users returns one user
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 42 ) );

        // Profile already exists: SELECT id FROM profiles WHERE user_id = 42 returns an id
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( 'wp_ffc_user_profiles', '1' );

        $result = MigrationUserProfiles::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 0, $result['created'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    // ==================================================================
    // run() — user does not exist in WP (skipped)
    // ==================================================================

    public function test_run_skips_user_when_userdata_returns_false(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', null );

        // get_users returns one user
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 99 ) );

        // profile does not exist
        // get_userdata returns false
        Functions\when( 'FreeFormCertificate\Migrations\get_userdata' )->justReturn( false );

        $result = MigrationUserProfiles::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 0, $result['created'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    // ==================================================================
    // run() — successfully creates a profile
    // ==================================================================

    public function test_run_creates_profile_for_user_with_ffc_registration_date(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', null );

        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 10 ) );

        // get_userdata returns a user object
        $user = new \WP_User( 10 );
        $user->display_name = 'John Doe';
        $user->user_registered = '2025-01-15 10:00:00';
        Functions\when( 'FreeFormCertificate\Migrations\get_userdata' )->justReturn( $user );

        // Has ffc_registration_date
        Functions\when( 'FreeFormCertificate\Migrations\get_user_meta' )->justReturn( '2025-02-01 09:00:00' );

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_user_profiles',
                Mockery::on( function ( $data ) {
                    return $data['user_id'] === 10
                        && $data['display_name'] === 'John Doe'
                        && $data['created_at'] === '2025-02-01 09:00:00';
                } ),
                array( '%d', '%s', '%s', '%s' )
            )
            ->andReturn( 1 );

        $result = MigrationUserProfiles::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['processed'] );
        $this->assertSame( 1, $result['created'] );
        $this->assertSame( 0, $result['skipped'] );
    }

    // ==================================================================
    // run() — uses user_registered when no ffc_registration_date
    // ==================================================================

    public function test_run_falls_back_to_user_registered_when_no_ffc_date(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', null );

        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 11 ) );

        $user = new \WP_User( 11 );
        $user->display_name = 'Jane Smith';
        $user->user_registered = '2024-06-20 08:30:00';
        Functions\when( 'FreeFormCertificate\Migrations\get_userdata' )->justReturn( $user );

        // No ffc_registration_date
        Functions\when( 'FreeFormCertificate\Migrations\get_user_meta' )->justReturn( '' );

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_user_profiles',
                Mockery::on( function ( $data ) {
                    return $data['user_id'] === 11
                        && $data['display_name'] === 'Jane Smith'
                        && $data['created_at'] === '2024-06-20 08:30:00';
                } ),
                array( '%d', '%s', '%s', '%s' )
            )
            ->andReturn( 1 );

        $result = MigrationUserProfiles::run();

        $this->assertTrue( $result['success'] );
        $this->assertSame( 1, $result['created'] );
    }

    // ==================================================================
    // run() — dry run mode
    // ==================================================================

    public function test_run_dry_run_does_not_insert_or_update_options(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', null );

        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 20 ) );

        $user = new \WP_User( 20 );
        $user->display_name = 'Dry Run User';
        $user->user_registered = '2025-01-01 00:00:00';
        Functions\when( 'FreeFormCertificate\Migrations\get_userdata' )->justReturn( $user );
        Functions\when( 'FreeFormCertificate\Migrations\get_user_meta' )->justReturn( '' );

        // insert should NOT be called in dry run
        $this->wpdb->shouldNotReceive( 'insert' );

        $result = MigrationUserProfiles::run( 50, true );

        $this->assertTrue( $result['success'] );
        $this->assertTrue( $result['dry_run'] );
        $this->assertSame( 1, $result['created'] );
        $this->assertStringContainsString( 'DRY RUN', $result['message'] );
    }

    // ==================================================================
    // preview() — delegates to run with dry_run=true
    // ==================================================================

    public function test_preview_delegates_to_dry_run(): void {
        // table_exists returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', null );

        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array() );

        $result = MigrationUserProfiles::preview( 10 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 0, $result['processed'] );
    }

    // ==================================================================
    // get_status() — table does not exist
    // ==================================================================

    public function test_get_status_returns_unavailable_when_table_missing(): void {
        // SHOW TABLES LIKE returns null
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( null );

        $status = MigrationUserProfiles::get_status();

        $this->assertFalse( $status['available'] );
        $this->assertFalse( $status['is_complete'] );
        $this->assertStringContainsString( 'does not exist', $status['message'] );
    }

    // ==================================================================
    // get_status() — all profiles created
    // ==================================================================

    public function test_get_status_returns_complete_when_all_profiles_exist(): void {
        // SHOW TABLES LIKE returns match (table exists)
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', '5' );

        // get_users returns 5 users
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 1, 2, 3, 4, 5 ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( '' );

        $status = MigrationUserProfiles::get_status();

        $this->assertTrue( $status['available'] );
        $this->assertSame( 5, $status['total_users'] );
        $this->assertSame( 5, $status['profiles_created'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['is_complete'] );
        $this->assertStringContainsString( 'All user profiles created', $status['message'] );
    }

    // ==================================================================
    // get_status() — some profiles pending
    // ==================================================================

    public function test_get_status_returns_pending_count_when_incomplete(): void {
        // SHOW TABLES LIKE returns match
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'Q' )
            ->andReturn( 'wp_ffc_user_profiles', '3' );

        // get_users returns 5 users but only 3 profiles exist
        Functions\when( 'FreeFormCertificate\Migrations\get_users' )->justReturn( array( 1, 2, 3, 4, 5 ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( '' );

        $status = MigrationUserProfiles::get_status();

        $this->assertTrue( $status['available'] );
        $this->assertSame( 5, $status['total_users'] );
        $this->assertSame( 3, $status['profiles_created'] );
        $this->assertSame( 2, $status['pending'] );
        $this->assertFalse( $status['is_complete'] );
    }
}
