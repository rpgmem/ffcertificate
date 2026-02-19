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
 * Tests for MigrationManager: delegation to registry/status calculator.
 *
 * v5.0.0: Simplified after retiring 10 completed migrations.
 * Removed tests for migrate_encryption, cleanup_unencrypted_data,
 * cleanup_old_data, can_drop_columns, get_drop_days_remaining.
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
        Functions\when( 'current_time' )->justReturn( '2026-02-19 12:00:00' );
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
        Functions\when( 'FreeFormCertificate\Migrations\current_time' )->justReturn( '2026-02-19 12:00:00' );
        Functions\when( 'FreeFormCertificate\Migrations\apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

        // Core namespace stubs
        Functions\when( 'FreeFormCertificate\Core\get_option' )->justReturn( '' );
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->justReturn( 0 );

        // Create manager WITHOUT calling constructor to avoid loading
        // CpfRfSplitMigrationStrategy which uses DatabaseHelperTrait
        // (patchwork stream wrapper cannot parse the trait file)
        $this->ref = new \ReflectionClass( MigrationManager::class );
        $this->manager = $this->ref->newInstanceWithoutConstructor();

        $this->registry = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationRegistry' );
        $this->statusCalculator = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationStatusCalculator' );

        $this->setPrivate( 'registry', $this->registry );
        $this->setPrivate( 'status_calculator', $this->statusCalculator );
    }

    protected function tearDown(): void {
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
        $expected = array( 'split_cpf_rf' => array( 'name' => 'Split CPF/RF' ) );
        $this->registry->shouldReceive( 'get_all_migrations' )->once()->andReturn( $expected );

        $this->assertSame( $expected, $this->manager->get_migrations() );
    }

    public function test_is_migration_available_delegates(): void {
        $this->registry->shouldReceive( 'is_available' )->with( 'split_cpf_rf' )->andReturn( true );
        $this->assertTrue( $this->manager->is_migration_available( 'split_cpf_rf' ) );
    }

    public function test_is_migration_available_returns_false_for_retired(): void {
        $this->registry->shouldReceive( 'is_available' )->with( 'encrypt_sensitive_data' )->andReturn( false );
        $this->assertFalse( $this->manager->is_migration_available( 'encrypt_sensitive_data' ) );
    }

    public function test_get_migration_status_delegates(): void {
        $status = array( 'is_complete' => false, 'percent' => 50, 'total' => 100, 'migrated' => 50, 'pending' => 50 );
        $this->statusCalculator->shouldReceive( 'calculate' )->with( 'split_cpf_rf' )->andReturn( $status );

        $result = $this->manager->get_migration_status( 'split_cpf_rf' );
        $this->assertSame( $status, $result );
    }

    public function test_get_migration_delegates(): void {
        $migration = array( 'name' => 'Split CPF/RF', 'batch_size' => 50 );
        $this->registry->shouldReceive( 'get_migration' )->with( 'split_cpf_rf' )->andReturn( $migration );

        $this->assertSame( $migration, $this->manager->get_migration( 'split_cpf_rf' ) );
    }

    public function test_get_migration_returns_null_for_retired(): void {
        $this->registry->shouldReceive( 'get_migration' )->with( 'email' )->andReturn( null );

        $this->assertNull( $this->manager->get_migration( 'email' ) );
    }

    public function test_can_run_migration_delegates(): void {
        $this->statusCalculator->shouldReceive( 'can_run' )->with( 'split_cpf_rf' )->andReturn( true );
        $this->assertTrue( $this->manager->can_run_migration( 'split_cpf_rf' ) );
    }

    public function test_run_migration_delegates(): void {
        $result = array( 'success' => true, 'processed' => 50 );
        $this->statusCalculator->shouldReceive( 'execute' )->with( 'split_cpf_rf', 3 )->andReturn( $result );

        $this->assertSame( $result, $this->manager->run_migration( 'split_cpf_rf', 3 ) );
    }

    public function test_run_migration_default_batch_zero(): void {
        $result = array( 'success' => true, 'processed' => 50, 'has_more' => true );
        $this->statusCalculator->shouldReceive( 'execute' )->with( 'split_cpf_rf', 0 )->andReturn( $result );

        $this->assertSame( $result, $this->manager->run_migration( 'split_cpf_rf' ) );
    }
}
