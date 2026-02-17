<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Tests for SubmissionRepository: table name, cache group, allowed columns, bulk ops, status counting.
 *
 * Uses a mock wpdb to avoid real database access while testing repository logic.
 */
class SubmissionRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private SubmissionRepository $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock global $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        // Stub WP cache functions
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        $this->repo = new SubmissionRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Table name and cache group
    // ------------------------------------------------------------------

    public function test_table_name_is_ffc_submissions(): void {
        $ref = new \ReflectionClass( $this->repo );
        $table = $ref->getProperty( 'table' );
        $table->setAccessible( true );

        $this->assertSame( 'wp_ffc_submissions', $table->getValue( $this->repo ) );
    }

    public function test_cache_group_is_ffc_submissions(): void {
        $ref = new \ReflectionClass( $this->repo );
        $prop = $ref->getProperty( 'cache_group' );
        $prop->setAccessible( true );

        $this->assertSame( 'ffc_submissions', $prop->getValue( $this->repo ) );
    }

    // ------------------------------------------------------------------
    // Allowed order columns
    // ------------------------------------------------------------------

    public function test_allowed_order_columns_include_submission_fields(): void {
        $ref = new \ReflectionClass( $this->repo );
        $method = $ref->getMethod( 'get_allowed_order_columns' );
        $method->setAccessible( true );

        $columns = $method->invoke( $this->repo );

        $this->assertContains( 'id', $columns );
        $this->assertContains( 'form_id', $columns );
        $this->assertContains( 'email', $columns );
        $this->assertContains( 'auth_code', $columns );
        $this->assertContains( 'submission_date', $columns );
        $this->assertContains( 'status', $columns );
    }

    public function test_sanitize_order_rejects_invalid_column(): void {
        $ref = new \ReflectionClass( $this->repo );
        $method = $ref->getMethod( 'sanitize_order_column' );
        $method->setAccessible( true );

        // SQL injection attempt should fall back to 'id'
        $this->assertSame( 'id', $method->invoke( $this->repo, 'id; DROP TABLE' ) );
        $this->assertSame( 'id', $method->invoke( $this->repo, 'nonexistent' ) );
    }

    public function test_sanitize_order_accepts_valid_column(): void {
        $ref = new \ReflectionClass( $this->repo );
        $method = $ref->getMethod( 'sanitize_order_column' );
        $method->setAccessible( true );

        $this->assertSame( 'submission_date', $method->invoke( $this->repo, 'submission_date' ) );
    }

    // ------------------------------------------------------------------
    // bulkUpdateStatus / bulkDelete edge cases
    // ------------------------------------------------------------------

    public function test_bulk_update_status_returns_zero_for_empty_ids(): void {
        $this->assertSame( 0, $this->repo->bulkUpdateStatus( [], 'trash' ) );
    }

    public function test_bulk_delete_returns_zero_for_empty_ids(): void {
        $this->assertSame( 0, $this->repo->bulkDelete( [] ) );
    }

    // ------------------------------------------------------------------
    // countByStatus()
    // ------------------------------------------------------------------

    public function test_count_by_status_returns_all_statuses(): void {
        global $wpdb;

        $mock_results = array(
            'publish'          => (object) array( 'status' => 'publish', 'count' => '15' ),
            'trash'            => (object) array( 'status' => 'trash', 'count' => '3' ),
            'quiz_in_progress' => (object) array( 'status' => 'quiz_in_progress', 'count' => '2' ),
        );

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT status, COUNT(*) as count FROM wp_ffc_submissions GROUP BY status' );
        $wpdb->shouldReceive( 'get_results' )->andReturn( $mock_results );

        $result = $this->repo->countByStatus();

        $this->assertSame( 15, $result['publish'] );
        $this->assertSame( 3, $result['trash'] );
        $this->assertSame( 2, $result['quiz_in_progress'] );
        $this->assertSame( 0, $result['quiz_failed'] ); // not in mock = 0
    }

    public function test_count_by_status_returns_zeros_when_empty(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        $result = $this->repo->countByStatus();

        $this->assertSame( 0, $result['publish'] );
        $this->assertSame( 0, $result['trash'] );
        $this->assertSame( 0, $result['quiz_in_progress'] );
        $this->assertSame( 0, $result['quiz_failed'] );
    }

    // ------------------------------------------------------------------
    // findByAuthCode() cache behavior
    // ------------------------------------------------------------------

    public function test_find_by_auth_code_returns_cached_value(): void {
        // Override wp_cache_get to return a cached submission
        $cached = array( 'id' => 1, 'auth_code' => 'ABCD-EFGH-IJKL' );
        Functions\when( 'wp_cache_get' )->justReturn( $cached );

        $result = $this->repo->findByAuthCode( 'ABCD-EFGH-IJKL' );

        $this->assertSame( $cached, $result );
    }

    // ------------------------------------------------------------------
    // insert() and update() via parent
    // ------------------------------------------------------------------

    public function test_insert_returns_insert_id_on_success(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
        $wpdb->insert_id = 42;

        $result = $this->repo->insert( array( 'email' => 'test@example.com', 'form_id' => 1 ) );

        $this->assertSame( 42, $result );
    }

    public function test_insert_returns_false_on_failure(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'insert' )->once()->andReturn( false );
        $wpdb->last_error = 'Duplicate entry';

        $result = $this->repo->insert( array( 'email' => 'test@example.com' ) );

        $this->assertFalse( $result );
    }

    public function test_update_returns_rows_on_success(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

        $result = $this->repo->update( 1, array( 'status' => 'trash' ) );

        $this->assertSame( 1, $result );
    }

    // ------------------------------------------------------------------
    // updateStatus() delegates to update()
    // ------------------------------------------------------------------

    public function test_update_status_calls_update(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'update' )
            ->once()
            ->with( 'wp_ffc_submissions', array( 'status' => 'trash' ), array( 'id' => 5 ) )
            ->andReturn( 1 );

        $result = $this->repo->updateStatus( 5, 'trash' );

        $this->assertSame( 1, $result );
    }

    // ------------------------------------------------------------------
    // delete()
    // ------------------------------------------------------------------

    public function test_delete_returns_rows_affected(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'delete' )
            ->once()
            ->with( 'wp_ffc_submissions', array( 'id' => 10 ) )
            ->andReturn( 1 );

        $result = $this->repo->delete( 10 );

        $this->assertSame( 1, $result );
    }
}
