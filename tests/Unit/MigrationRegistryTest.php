<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationRegistry;

/**
 * Tests for MigrationRegistry: centralized registry for all available migrations.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationRegistry
 */
class MigrationRegistryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

        // Namespaced stubs: FreeFormCertificate\Migrations\*
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor / register_migrations
    // ==================================================================

    public function test_constructor_registers_default_migrations(): void {
        $registry = new MigrationRegistry();

        $all = $registry->get_all_migrations();

        $this->assertIsArray( $all );
        $this->assertNotEmpty( $all );
        $this->assertArrayHasKey( 'split_cpf_rf', $all );
    }

    // ==================================================================
    // get_all_migrations
    // ==================================================================

    public function test_get_all_migrations_returns_array_with_split_cpf_rf(): void {
        $registry = new MigrationRegistry();

        $all = $registry->get_all_migrations();

        $this->assertIsArray( $all );
        $this->assertCount( 1, $all );
        $this->assertArrayHasKey( 'split_cpf_rf', $all );
    }

    public function test_split_cpf_rf_migration_has_expected_keys(): void {
        $registry   = new MigrationRegistry();
        $migration  = $registry->get_all_migrations()['split_cpf_rf'];

        $expected_keys = array( 'name', 'description', 'icon', 'batch_size', 'order', 'requires_column' );

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $migration, "Missing expected key: {$key}" );
        }

        // Verify specific values
        $this->assertSame( 'Split CPF/RF', $migration['name'] );
        $this->assertSame( 'ffc-icon-id', $migration['icon'] );
        $this->assertSame( 50, $migration['batch_size'] );
        $this->assertSame( 1, $migration['order'] );
        $this->assertTrue( $migration['requires_column'] );
    }

    // ==================================================================
    // get_migration
    // ==================================================================

    public function test_get_migration_returns_correct_config_for_split_cpf_rf(): void {
        $registry  = new MigrationRegistry();
        $migration = $registry->get_migration( 'split_cpf_rf' );

        $this->assertIsArray( $migration );
        $this->assertSame( 'Split CPF/RF', $migration['name'] );
        $this->assertSame( 50, $migration['batch_size'] );
        $this->assertSame( 1, $migration['order'] );
        $this->assertTrue( $migration['requires_column'] );
    }

    public function test_get_migration_returns_null_for_nonexistent_key(): void {
        $registry = new MigrationRegistry();

        $this->assertNull( $registry->get_migration( 'nonexistent_migration' ) );
    }

    // ==================================================================
    // exists
    // ==================================================================

    public function test_exists_returns_true_for_split_cpf_rf(): void {
        $registry = new MigrationRegistry();

        $this->assertTrue( $registry->exists( 'split_cpf_rf' ) );
    }

    public function test_exists_returns_false_for_nonexistent_key(): void {
        $registry = new MigrationRegistry();

        $this->assertFalse( $registry->exists( 'nonexistent_migration' ) );
    }

    // ==================================================================
    // is_available
    // ==================================================================

    public function test_is_available_delegates_to_exists_and_returns_true(): void {
        $registry = new MigrationRegistry();

        // is_available should return true for an existing migration, same as exists
        $this->assertSame(
            $registry->exists( 'split_cpf_rf' ),
            $registry->is_available( 'split_cpf_rf' )
        );
        $this->assertTrue( $registry->is_available( 'split_cpf_rf' ) );
    }

    public function test_is_available_delegates_to_exists_and_returns_false(): void {
        $registry = new MigrationRegistry();

        // is_available should return false for a nonexistent migration, same as exists
        $this->assertSame(
            $registry->exists( 'nonexistent_migration' ),
            $registry->is_available( 'nonexistent_migration' )
        );
        $this->assertFalse( $registry->is_available( 'nonexistent_migration' ) );
    }

    // ==================================================================
    // apply_filters hook
    // ==================================================================

    public function test_apply_filters_is_called_during_registration(): void {
        // Reset Brain\Monkey so we can set up expectations
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();

        // Expect apply_filters to be called with the registry filter hook
        Functions\expect( 'FreeFormCertificate\Migrations\apply_filters' )
            ->once()
            ->with( 'ffcertificate_migrations_registry', Mockery::type( 'array' ) )
            ->andReturnUsing( function( $hook, $migrations ) {
                return $migrations;
            } );

        $registry = new MigrationRegistry();

        // Verify the registry still works correctly after filter pass-through
        $this->assertArrayHasKey( 'split_cpf_rf', $registry->get_all_migrations() );
    }

    public function test_apply_filters_allows_adding_custom_migrations(): void {
        // Reset Brain\Monkey so we can set up expectations
        Monkey\tearDown();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();

        // Simulate a plugin adding a custom migration via the filter
        Functions\expect( 'FreeFormCertificate\Migrations\apply_filters' )
            ->once()
            ->with( 'ffcertificate_migrations_registry', Mockery::type( 'array' ) )
            ->andReturnUsing( function( $hook, $migrations ) {
                $migrations['custom_migration'] = array(
                    'name'            => 'Custom Migration',
                    'description'     => 'A custom migration added by a plugin',
                    'icon'            => 'ffc-icon-custom',
                    'batch_size'      => 25,
                    'order'           => 2,
                    'requires_column' => false,
                );
                return $migrations;
            } );

        $registry = new MigrationRegistry();
        $all      = $registry->get_all_migrations();

        // Both the default and custom migration should be present
        $this->assertArrayHasKey( 'split_cpf_rf', $all );
        $this->assertArrayHasKey( 'custom_migration', $all );
        $this->assertSame( 'Custom Migration', $all['custom_migration']['name'] );
    }
}
