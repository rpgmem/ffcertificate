<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\AbstractRepository;

class ConcreteTestRepository extends AbstractRepository {

    protected function get_table_name(): string {
        return 'wp_ffc_test_table';
    }

    protected function get_cache_group(): string {
        return 'ffc_test';
    }

    protected function get_allowed_order_columns(): array {
        return array( 'id', 'created_at', 'updated_at', 'status', 'name' );
    }

    protected function get_allowed_where_columns(): array {
        return array( 'id', 'status', 'form_id', 'user_id' );
    }

    public function expose_build_where_clause( array $conditions ): string {
        return $this->build_where_clause( $conditions );
    }

    public function expose_sanitize_order_column( string $column ): string {
        return $this->sanitize_order_column( $column );
    }
}

class UnrestrictedTestRepository extends AbstractRepository {

    protected function get_table_name(): string {
        return 'wp_ffc_unrestricted';
    }

    protected function get_cache_group(): string {
        return 'ffc_unrestricted';
    }

    public function expose_build_where_clause( array $conditions ): string {
        return $this->build_where_clause( $conditions );
    }
}

/**
 * Tests for AbstractRepository: build_where_clause, sanitize_order_column,
 * findById, findByIds, findAll, count, insert, update, delete, transactions.
 */
class AbstractRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface&\wpdb */
    private $wpdb;
    private ConcreteTestRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;

        $this->wpdb = $wpdb;

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        $this->repo = new ConcreteTestRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // build_where_clause
    // ------------------------------------------------------------------

    public function test_build_where_clause_empty_returns_empty_string(): void {
        $this->assertSame( '', $this->repo->expose_build_where_clause( array() ) );
    }

    public function test_build_where_clause_single_condition(): void {
        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i = %s', 'status', 'active' )
            ->andReturn( "`status` = 'active'" );

        $result = $this->repo->expose_build_where_clause( array( 'status' => 'active' ) );
        $this->assertSame( "WHERE `status` = 'active'", $result );
    }

    public function test_build_where_clause_multiple_conditions(): void {
        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i = %s', 'status', 'active' )
            ->andReturn( "`status` = 'active'" );

        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i = %s', 'form_id', 42 )
            ->andReturn( '`form_id` = 42' );

        $result = $this->repo->expose_build_where_clause(
            array(
                'status'  => 'active',
                'form_id' => 42,
            )
        );

        $this->assertStringStartsWith( 'WHERE ', $result );
        $this->assertStringContainsString( "`status` = 'active'", $result );
        $this->assertStringContainsString( '`form_id` = 42', $result );
        $this->assertStringContainsString( ' AND ', $result );
    }

    public function test_build_where_clause_array_value_uses_in(): void {
        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i IN (%s,%s)', 'status', 'active', 'pending' )
            ->andReturn( "`status` IN ('active','pending')" );

        $result = $this->repo->expose_build_where_clause(
            array( 'status' => array( 'active', 'pending' ) )
        );

        $this->assertSame( "WHERE `status` IN ('active','pending')", $result );
    }

    public function test_build_where_clause_rejects_disallowed_columns(): void {
        $result = $this->repo->expose_build_where_clause(
            array( 'malicious_column' => 'value' )
        );

        $this->assertSame( '', $result );
    }

    public function test_build_where_clause_mixed_allowed_and_disallowed(): void {
        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i = %s', 'status', 'active' )
            ->andReturn( "`status` = 'active'" );

        $result = $this->repo->expose_build_where_clause(
            array(
                'status'      => 'active',
                'evil_column' => 'drop table',
            )
        );

        $this->assertSame( "WHERE `status` = 'active'", $result );
    }

    public function test_build_where_clause_unrestricted_repo_allows_any_column(): void {
        global $wpdb;
        $unrestricted = new UnrestrictedTestRepository();

        $wpdb->shouldReceive( 'prepare' )
            ->once()
            ->with( '%i = %s', 'any_column', 'value' )
            ->andReturn( "`any_column` = 'value'" );

        $result = $unrestricted->expose_build_where_clause(
            array( 'any_column' => 'value' )
        );

        $this->assertSame( "WHERE `any_column` = 'value'", $result );
    }

    // ------------------------------------------------------------------
    // sanitize_order_column
    // ------------------------------------------------------------------

    public function test_sanitize_order_column_allows_valid_column(): void {
        $this->assertSame( 'name', $this->repo->expose_sanitize_order_column( 'name' ) );
        $this->assertSame( 'id', $this->repo->expose_sanitize_order_column( 'id' ) );
        $this->assertSame( 'status', $this->repo->expose_sanitize_order_column( 'status' ) );
    }

    public function test_sanitize_order_column_rejects_invalid_column(): void {
        $this->assertSame( 'id', $this->repo->expose_sanitize_order_column( 'evil_column' ) );
        $this->assertSame( 'id', $this->repo->expose_sanitize_order_column( '1; DROP TABLE' ) );
    }

    // ------------------------------------------------------------------
    // findById
    // ------------------------------------------------------------------

    public function test_find_by_id_returns_row(): void {
        $expected = array( 'id' => 1, 'status' => 'active' );

        $this->wpdb->shouldReceive( 'prepare' )
            ->once()
            ->andReturn( "SELECT * FROM `wp_ffc_test_table` WHERE id = 1" );

        $this->wpdb->shouldReceive( 'get_row' )
            ->once()
            ->andReturn( $expected );

        $result = $this->repo->findById( 1 );
        $this->assertSame( $expected, $result );
    }

    public function test_find_by_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'query' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $result = $this->repo->findById( 999 );
        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------
    // findByIds
    // ------------------------------------------------------------------

    public function test_find_by_ids_empty_returns_empty(): void {
        $this->assertSame( array(), $this->repo->findByIds( array() ) );
    }

    public function test_find_by_ids_returns_keyed_results(): void {
        $rows = array(
            array( 'id' => 1, 'status' => 'active' ),
            array( 'id' => 3, 'status' => 'pending' ),
        );

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'query' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

        $result = $this->repo->findByIds( array( 1, 3 ) );

        $this->assertArrayHasKey( 1, $result );
        $this->assertArrayHasKey( 3, $result );
        $this->assertSame( 'active', $result[1]['status'] );
    }

    // ------------------------------------------------------------------
    // insert / update / delete
    // ------------------------------------------------------------------

    public function test_insert_returns_id_on_success(): void {
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturn( 1 );
        $this->wpdb->insert_id = 42;

        $result = $this->repo->insert( array( 'name' => 'test' ) );
        $this->assertSame( 42, $result );
    }

    public function test_insert_returns_false_on_failure(): void {
        $this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

        $result = $this->repo->insert( array( 'name' => 'test' ) );
        $this->assertFalse( $result );
    }

    public function test_update_returns_rows_affected(): void {
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( 1 );

        $result = $this->repo->update( 1, array( 'name' => 'updated' ) );
        $this->assertSame( 1, $result );
    }

    public function test_delete_returns_rows_affected(): void {
        $this->wpdb->shouldReceive( 'delete' )
            ->once()
            ->andReturn( 1 );

        $result = $this->repo->delete( 1 );
        $this->assertSame( 1, $result );
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    public function test_begin_transaction_executes_query(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->with( 'START TRANSACTION' )
            ->once()
            ->andReturn( true );

        $this->assertTrue( $this->repo->begin_transaction() );
    }

    public function test_commit_executes_query(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->with( 'COMMIT' )
            ->once()
            ->andReturn( true );

        $this->assertTrue( $this->repo->commit() );
    }

    public function test_rollback_executes_query(): void {
        $this->wpdb->shouldReceive( 'query' )
            ->with( 'ROLLBACK' )
            ->once()
            ->andReturn( true );

        $this->assertTrue( $this->repo->rollback() );
    }

    // ------------------------------------------------------------------
    // Table name and cache group
    // ------------------------------------------------------------------

    public function test_table_name(): void {
        $ref   = new \ReflectionClass( $this->repo );
        $table = $ref->getProperty( 'table' );
        $table->setAccessible( true );

        $this->assertSame( 'wp_ffc_test_table', $table->getValue( $this->repo ) );
    }

    public function test_cache_group(): void {
        $ref  = new \ReflectionClass( $this->repo );
        $prop = $ref->getProperty( 'cache_group' );
        $prop->setAccessible( true );

        $this->assertSame( 'ffc_test', $prop->getValue( $this->repo ) );
    }
}
