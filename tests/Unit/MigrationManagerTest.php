<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationManager;

/**
 * Tests for MigrationManager: batch calculation, grace period logic,
 * delegation to registry/status calculator, and drop-days remaining.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationManager
 */
class MigrationManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private MigrationManager $manager;
    private $registry;
    private $statusCalculator;
    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Define DAY_IN_SECONDS if not already defined
        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }

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

        // WP_Error alias for Migrations namespace
        if ( ! class_exists( 'FreeFormCertificate\Migrations\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\WP_Error' );
        }

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        // Namespaced stubs: FreeFormCertificate\Migrations\*
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( '' );
        Functions\when( 'FreeFormCertificate\Migrations\is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'FreeFormCertificate\Migrations\add_filter' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\update_option' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Migrations\get_current_user_id' )->justReturn( 1 );
        Functions\when( 'FreeFormCertificate\Migrations\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'FreeFormCertificate\Migrations\apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

        // Core namespace stubs
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( '' );
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->justReturn( 0 );

        // Create manager — constructor initializes MigrationRegistry and StatusCalculator
        $this->manager = new MigrationManager();

        // Replace dependencies with mocks via Reflection
        $this->ref = new \ReflectionClass( MigrationManager::class );

        $this->registry = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationRegistry' );
        $this->statusCalculator = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationStatusCalculator' );

        $this->setPrivate( 'registry', $this->registry );
        $this->setPrivate( 'status_calculator', $this->statusCalculator );
    }

    protected function tearDown(): void {
        unset( $_POST['confirm_cleanup'], $_POST['confirm_drop'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function setPrivate( string $name, $value ): void {
        $prop = $this->ref->getProperty( $name );
        $prop->setAccessible( true );
        $prop->setValue( $this->manager, $value );
    }

    // ==================================================================
    // Delegation methods
    // ==================================================================

    public function test_get_migrations_delegates_to_registry(): void {
        $expected = array( 'migrate_email' => array( 'label' => 'Email' ) );
        $this->registry->shouldReceive( 'get_all_migrations' )->once()->andReturn( $expected );

        $this->assertSame( $expected, $this->manager->get_migrations() );
    }

    public function test_is_migration_available_delegates(): void {
        $this->registry->shouldReceive( 'is_available' )->with( 'encrypt_sensitive_data' )->andReturn( true );
        $this->assertTrue( $this->manager->is_migration_available( 'encrypt_sensitive_data' ) );
    }

    public function test_get_migration_status_delegates(): void {
        $status = array( 'is_complete' => true, 'progress' => 100 );
        $this->statusCalculator->shouldReceive( 'calculate' )->with( 'encrypt_sensitive_data' )->andReturn( $status );

        $result = $this->manager->get_migration_status( 'encrypt_sensitive_data' );
        $this->assertSame( $status, $result );
    }

    public function test_get_migration_delegates(): void {
        $migration = array( 'key' => 'encrypt_sensitive_data', 'label' => 'Encrypt' );
        $this->registry->shouldReceive( 'get_migration' )->with( 'encrypt_sensitive_data' )->andReturn( $migration );

        $this->assertSame( $migration, $this->manager->get_migration( 'encrypt_sensitive_data' ) );
    }

    public function test_can_run_migration_delegates(): void {
        $this->statusCalculator->shouldReceive( 'can_run' )->with( 'my_migration' )->andReturn( true );
        $this->assertTrue( $this->manager->can_run_migration( 'my_migration' ) );
    }

    public function test_run_migration_delegates(): void {
        $result = array( 'success' => true, 'processed' => 50 );
        $this->statusCalculator->shouldReceive( 'execute' )->with( 'my_migration', 3 )->andReturn( $result );

        $this->assertSame( $result, $this->manager->run_migration( 'my_migration', 3 ) );
    }

    // ==================================================================
    // migrate_encryption() — Batch calculation
    // ==================================================================

    public function test_migrate_encryption_batch_from_offset(): void {
        // offset=100, limit=50 → batch_number = (int) floor(100/50) + 1 = 3
        $this->statusCalculator->shouldReceive( 'execute' )
            ->with( 'encrypt_sensitive_data', 3 )
            ->andReturn( array( 'success' => true ) );

        $result = $this->manager->migrate_encryption( 100, 50 );
        $this->assertSame( array( 'success' => true ), $result );
    }

    public function test_migrate_encryption_zero_offset(): void {
        // offset=0, limit=50 → batch_number = (int) floor(0/50) + 1 = 1
        $this->statusCalculator->shouldReceive( 'execute' )
            ->with( 'encrypt_sensitive_data', 1 )
            ->andReturn( array( 'success' => true ) );

        $result = $this->manager->migrate_encryption( 0, 50 );
        $this->assertTrue( $result['success'] );
    }

    public function test_migrate_encryption_zero_limit_uses_batch_1(): void {
        // limit=0 → batch = 1 (fallback)
        $this->statusCalculator->shouldReceive( 'execute' )
            ->with( 'encrypt_sensitive_data', 1 )
            ->andReturn( array( 'success' => true ) );

        $result = $this->manager->migrate_encryption( 0, 0 );
        $this->assertTrue( $result['success'] );
    }

    // ==================================================================
    // cleanup_unencrypted_data() — Batch calculation
    // ==================================================================

    public function test_cleanup_batch_calculation(): void {
        // offset=200, limit=100 → batch = (int) floor(200/100) + 1 = 3
        $this->statusCalculator->shouldReceive( 'execute' )
            ->with( 'cleanup_unencrypted', 3 )
            ->andReturn( array( 'success' => true, 'processed' => 100 ) );

        $result = $this->manager->cleanup_unencrypted_data( 200, 100 );
        $this->assertTrue( $result['success'] );
    }

    // ==================================================================
    // get_drop_days_remaining()
    // ==================================================================

    public function test_drop_days_no_completion_date_returns_30(): void {
        // Default: empty string (from setUp).

        $this->assertSame( 30, $this->manager->get_drop_days_remaining() );
    }

    public function test_drop_days_just_completed_returns_30(): void {
        $now = gmdate( 'Y-m-d H:i:s' );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $now );

        $this->assertSame( 30, $this->manager->get_drop_days_remaining() );
    }

    public function test_drop_days_15_days_ago_returns_15(): void {
        $fifteen_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 15 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $fifteen_days_ago );

        $this->assertSame( 15, $this->manager->get_drop_days_remaining() );
    }

    public function test_drop_days_31_days_ago_returns_0(): void {
        $thirty_one_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $thirty_one_days_ago );

        $this->assertSame( 0, $this->manager->get_drop_days_remaining() );
    }

    // ==================================================================
    // can_drop_columns() — Grace period logic
    // ==================================================================

    public function test_can_drop_encryption_not_complete(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->with( 'encrypt_sensitive_data' )
            ->andReturn( array( 'is_complete' => false, 'progress' => 50 ) );

        $result = $this->manager->can_drop_columns();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'encryption_not_complete', $result->get_error_code() );
    }

    public function test_can_drop_no_completion_date(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        // Default: empty string (from setUp).

        $result = $this->manager->can_drop_columns();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_completion_date', $result->get_error_code() );
    }

    public function test_can_drop_grace_period_not_met(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $ten_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $ten_days_ago );

        $result = $this->manager->can_drop_columns();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'grace_period_not_met', $result->get_error_code() );
    }

    public function test_can_drop_after_30_days_returns_true(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $thirty_one_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $thirty_one_days_ago );

        $result = $this->manager->can_drop_columns();
        $this->assertTrue( $result );
    }

    // ==================================================================
    // cleanup_old_data() — 15-day grace period
    // ==================================================================

    public function test_cleanup_old_data_encryption_not_complete(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => false ) );

        $result = $this->manager->cleanup_old_data();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'encryption_not_complete', $result->get_error_code() );
    }

    public function test_cleanup_old_data_grace_period_not_met(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $five_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $five_days_ago );

        $result = $this->manager->cleanup_old_data();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'grace_period_not_met', $result->get_error_code() );
    }

    public function test_cleanup_old_data_requires_confirmation(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $twenty_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $twenty_days_ago );

        // No POST confirmation
        $result = $this->manager->cleanup_old_data();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'confirmation_required', $result->get_error_code() );
    }

    public function test_cleanup_old_data_wrong_confirmation(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $twenty_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $twenty_days_ago );

        $_POST['confirm_cleanup'] = 'wrong text';

        $result = $this->manager->cleanup_old_data();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'confirmation_required', $result->get_error_code() );
    }

    public function test_cleanup_old_data_with_correct_confirmation_executes(): void {
        $this->statusCalculator->shouldReceive( 'calculate' )
            ->andReturn( array( 'is_complete' => true ) );

        $twenty_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) );
        Functions\when( 'FreeFormCertificate\Migrations\get_option' )->justReturn( $twenty_days_ago );

        $_POST['confirm_cleanup'] = 'CONFIRMAR EXCLUSÃO';

        $this->statusCalculator->shouldReceive( 'execute' )
            ->with( 'cleanup_unencrypted', 1 )
            ->andReturn( array( 'success' => true, 'processed' => 42 ) );

        $result = $this->manager->cleanup_old_data();

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 42, $result['processed'] );
    }
}
