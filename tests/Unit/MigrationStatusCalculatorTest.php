<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\MigrationStatusCalculator;

/**
 * Tests for MigrationStatusCalculator: delegation to strategies.
 *
 * The constructor tries to instantiate CpfRfSplitMigrationStrategy which has
 * complex dependencies, so we bypass it using reflection and inject mock
 * strategies directly.
 *
 * @covers \FreeFormCertificate\Migrations\MigrationStatusCalculator
 */
class MigrationStatusCalculatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private MigrationStatusCalculator $calculator;
    private $registry;
    private $strategy;
    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // WP_Error alias for Migrations namespace
        if ( ! class_exists( 'FreeFormCertificate\Migrations\WP_Error' ) ) {
            class_alias( 'WP_Error', 'FreeFormCertificate\Migrations\WP_Error' );
        }

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

        // Namespaced stubs: FreeFormCertificate\Migrations\*
        Functions\when( 'FreeFormCertificate\Migrations\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Migrations\is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'FreeFormCertificate\Migrations\apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );

        // Create calculator WITHOUT calling constructor to avoid loading
        // CpfRfSplitMigrationStrategy which has complex dependencies
        $this->ref = new \ReflectionClass( MigrationStatusCalculator::class );
        $this->calculator = $this->ref->newInstanceWithoutConstructor();

        $this->registry = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationRegistry' );
        $this->strategy = Mockery::mock( 'FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface' );

        $this->setPrivate( 'registry', $this->registry );
        $this->setPrivate( 'strategies', array() );
        $this->setPrivate( 'strategy_errors', array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function setPrivate( string $name, $value ): void {
        $prop = $this->ref->getProperty( $name );
        $prop->setAccessible( true );
        $prop->setValue( $this->calculator, $value );
    }

    private function getPrivate( string $name ) {
        $prop = $this->ref->getProperty( $name );
        $prop->setAccessible( true );
        return $prop->getValue( $this->calculator );
    }

    /**
     * Inject a mock strategy for the given migration key.
     */
    private function injectStrategy( string $key, $strategy = null ): void {
        $strategies = $this->getPrivate( 'strategies' );
        $strategies[ $key ] = $strategy ?? $this->strategy;
        $this->setPrivate( 'strategies', $strategies );
    }

    // ==================================================================
    // Constructor tests
    // ==================================================================

    public function test_constructor_catches_strategy_initialization_errors(): void {
        // The real constructor calls initialize_strategies() which tries to
        // instantiate CpfRfSplitMigrationStrategy. In our test environment
        // this will fail because the class has complex dependencies.
        // The constructor should catch the Throwable and store the error.
        $registry = Mockery::mock( 'FreeFormCertificate\Migrations\MigrationRegistry' );

        // We need to suppress the class_exists check for Utils
        Functions\when( 'FreeFormCertificate\Migrations\Strategies\__' )->returnArg();

        $calculator = new MigrationStatusCalculator( $registry );

        // The constructor should not throw — errors are caught
        $this->assertIsArray( $calculator->get_strategies() );
    }

    // ==================================================================
    // get_strategies tests
    // ==================================================================

    public function test_get_strategies_returns_array(): void {
        $this->assertIsArray( $this->calculator->get_strategies() );
    }

    public function test_get_strategies_returns_empty_array_when_no_strategies(): void {
        $this->assertSame( array(), $this->calculator->get_strategies() );
    }

    public function test_get_strategies_returns_injected_strategies(): void {
        $this->injectStrategy( 'split_cpf_rf' );

        $strategies = $this->calculator->get_strategies();

        $this->assertArrayHasKey( 'split_cpf_rf', $strategies );
        $this->assertSame( $this->strategy, $strategies['split_cpf_rf'] );
    }

    // ==================================================================
    // calculate tests
    // ==================================================================

    public function test_calculate_with_invalid_migration_returns_wp_error(): void {
        $this->registry->shouldReceive( 'exists' )
            ->with( 'nonexistent_migration' )
            ->once()
            ->andReturn( false );

        $result = $this->calculator->calculate( 'nonexistent_migration' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_migration', $result->get_error_code() );
    }

    public function test_calculate_with_valid_migration_delegates_to_strategy(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
            'order'      => 1,
        );
        $expected_status = array(
            'is_complete' => false,
            'percent'     => 50,
            'total'       => 100,
            'migrated'    => 50,
            'pending'     => 50,
        );

        $this->registry->shouldReceive( 'exists' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( true );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'calculate_status' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( $expected_status );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->calculate( 'split_cpf_rf' );

        $this->assertSame( $expected_status, $result );
    }

    public function test_calculate_with_missing_strategy_returns_wp_error(): void {
        $this->registry->shouldReceive( 'exists' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( true );

        // No strategy injected — strategies array is empty

        $result = $this->calculator->calculate( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
    }

    public function test_calculate_with_missing_strategy_includes_error_detail(): void {
        $this->registry->shouldReceive( 'exists' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( true );

        // Set a strategy error to verify it appears in the WP_Error message
        $this->setPrivate( 'strategy_errors', array(
            'split_cpf_rf' => 'Class not found',
        ) );

        $result = $this->calculator->calculate( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
        $this->assertStringContainsString( 'Class not found', $result->get_error_message() );
    }

    public function test_calculate_with_complete_migration(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );
        $expected_status = array(
            'is_complete' => true,
            'percent'     => 100,
            'total'       => 200,
            'migrated'    => 200,
            'pending'     => 0,
        );

        $this->registry->shouldReceive( 'exists' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( true );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'calculate_status' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( $expected_status );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->calculate( 'split_cpf_rf' );

        $this->assertTrue( $result['is_complete'] );
        $this->assertSame( 100, $result['percent'] );
        $this->assertSame( 0, $result['pending'] );
    }

    // ==================================================================
    // can_run tests
    // ==================================================================

    public function test_can_run_with_missing_strategy_returns_wp_error(): void {
        // No strategy injected — will fail to find strategy
        $result = $this->calculator->can_run( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
    }

    public function test_can_run_delegates_to_strategy_and_returns_true(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( true );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->can_run( 'split_cpf_rf' );

        $this->assertTrue( $result );
    }

    public function test_can_run_delegates_to_strategy_and_returns_wp_error(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );

        $can_run_error = new \WP_Error( 'prerequisite_failed', 'Column does not exist' );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->once()
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( $can_run_error );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->can_run( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'prerequisite_failed', $result->get_error_code() );
    }

    public function test_can_run_with_unknown_migration_key_returns_wp_error(): void {
        $result = $this->calculator->can_run( 'unknown_migration' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
        $this->assertStringContainsString( 'unknown_migration', $result->get_error_message() );
    }

    // ==================================================================
    // execute tests
    // ==================================================================

    public function test_execute_checks_can_run_first_and_returns_wp_error_when_cannot_run(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );

        $can_run_error = new \WP_Error( 'prerequisite_failed', 'Missing column' );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( $can_run_error );

        // execute should NOT be called since can_run returned an error
        $this->strategy->shouldNotReceive( 'execute' );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->execute( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'prerequisite_failed', $result->get_error_code() );
    }

    public function test_execute_with_missing_strategy_returns_wp_error(): void {
        // No strategy injected — can_run will fail with strategy_not_found
        $result = $this->calculator->execute( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
    }

    public function test_execute_delegates_to_strategy_on_success(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );
        $expected_result = array(
            'success'   => true,
            'processed' => 50,
            'message'   => 'Batch processed successfully',
        );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( true );

        $this->strategy->shouldReceive( 'execute' )
            ->with( 'split_cpf_rf', $migration_config, 0 )
            ->once()
            ->andReturn( $expected_result );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->execute( 'split_cpf_rf' );

        $this->assertSame( $expected_result, $result );
    }

    public function test_execute_passes_batch_number_to_strategy(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );
        $expected_result = array(
            'success'   => true,
            'processed' => 50,
            'has_more'  => true,
        );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( true );

        $this->strategy->shouldReceive( 'execute' )
            ->with( 'split_cpf_rf', $migration_config, 5 )
            ->once()
            ->andReturn( $expected_result );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->execute( 'split_cpf_rf', 5 );

        $this->assertSame( $expected_result, $result );
    }

    public function test_execute_default_batch_number_is_zero(): void {
        $migration_config = array(
            'name'       => 'Split CPF/RF',
            'batch_size' => 50,
        );
        $expected_result = array(
            'success'   => true,
            'processed' => 50,
        );

        $this->registry->shouldReceive( 'get_migration' )
            ->with( 'split_cpf_rf' )
            ->andReturn( $migration_config );

        $this->strategy->shouldReceive( 'can_run' )
            ->with( 'split_cpf_rf', $migration_config )
            ->once()
            ->andReturn( true );

        $this->strategy->shouldReceive( 'execute' )
            ->with( 'split_cpf_rf', $migration_config, 0 )
            ->once()
            ->andReturn( $expected_result );

        $this->injectStrategy( 'split_cpf_rf' );

        $result = $this->calculator->execute( 'split_cpf_rf' );

        $this->assertSame( $expected_result, $result );
    }

    // ==================================================================
    // Strategy error tracking tests
    // ==================================================================

    public function test_strategy_error_is_included_in_wp_error_message(): void {
        $this->setPrivate( 'strategy_errors', array(
            'split_cpf_rf' => 'Failed to load class file',
        ) );

        $result = $this->calculator->can_run( 'split_cpf_rf' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertStringContainsString( 'Failed to load class file', $result->get_error_message() );
    }

    public function test_strategy_not_found_without_error_detail(): void {
        // No strategy_errors set, so the error message should not contain ':'
        $result = $this->calculator->can_run( 'some_unknown_key' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'strategy_not_found', $result->get_error_code() );
        $this->assertStringContainsString( 'some_unknown_key', $result->get_error_message() );
    }

    // ==================================================================
    // Multiple strategies tests
    // ==================================================================

    public function test_multiple_strategies_can_be_registered(): void {
        $strategy_a = Mockery::mock( 'FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface' );
        $strategy_b = Mockery::mock( 'FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface' );

        $this->injectStrategy( 'migration_a', $strategy_a );
        $this->injectStrategy( 'migration_b', $strategy_b );

        $strategies = $this->calculator->get_strategies();

        $this->assertCount( 2, $strategies );
        $this->assertArrayHasKey( 'migration_a', $strategies );
        $this->assertArrayHasKey( 'migration_b', $strategies );
    }

    public function test_calculate_routes_to_correct_strategy(): void {
        $strategy_a = Mockery::mock( 'FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface' );
        $strategy_b = Mockery::mock( 'FreeFormCertificate\Migrations\Strategies\MigrationStrategyInterface' );

        $config_a = array( 'name' => 'Migration A', 'batch_size' => 25 );
        $config_b = array( 'name' => 'Migration B', 'batch_size' => 100 );

        $status_a = array( 'is_complete' => false, 'percent' => 30 );
        $status_b = array( 'is_complete' => true, 'percent' => 100 );

        $this->injectStrategy( 'migration_a', $strategy_a );
        $this->injectStrategy( 'migration_b', $strategy_b );

        // Test migration_a
        $this->registry->shouldReceive( 'exists' )->with( 'migration_a' )->andReturn( true );
        $this->registry->shouldReceive( 'get_migration' )->with( 'migration_a' )->andReturn( $config_a );
        $strategy_a->shouldReceive( 'calculate_status' )
            ->with( 'migration_a', $config_a )
            ->once()
            ->andReturn( $status_a );

        $result_a = $this->calculator->calculate( 'migration_a' );
        $this->assertSame( $status_a, $result_a );

        // Test migration_b
        $this->registry->shouldReceive( 'exists' )->with( 'migration_b' )->andReturn( true );
        $this->registry->shouldReceive( 'get_migration' )->with( 'migration_b' )->andReturn( $config_b );
        $strategy_b->shouldReceive( 'calculate_status' )
            ->with( 'migration_b', $config_b )
            ->once()
            ->andReturn( $status_b );

        $result_b = $this->calculator->calculate( 'migration_b' );
        $this->assertSame( $status_b, $result_b );
    }
}
