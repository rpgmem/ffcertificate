<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerRepository: table metadata, findByShortCode, findByPostId,
 * incrementClickCount, codeExists, findPaginated, getStats.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerRepository
 */
class UrlShortenerRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerRepository $repo;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        $this->wpdb = $wpdb;

        // Stub WP cache functions
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, $args );
        } );

        $this->repo = new UrlShortenerRepository();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Table name and cache group
    // ==================================================================

    public function test_table_name_is_ffc_short_urls(): void {
        $ref = new \ReflectionClass( $this->repo );
        $table = $ref->getProperty( 'table' );
        $table->setAccessible( true );

        $this->assertSame( 'wp_ffc_short_urls', $table->getValue( $this->repo ) );
    }

    public function test_cache_group_is_ffc_short_urls(): void {
        $ref = new \ReflectionClass( $this->repo );
        $prop = $ref->getProperty( 'cache_group' );
        $prop->setAccessible( true );

        $this->assertSame( 'ffc_short_urls', $prop->getValue( $this->repo ) );
    }

    // ==================================================================
    // findByShortCode()
    // ==================================================================

    public function test_find_by_short_code_returns_record(): void {
        $row = [ 'id' => '1', 'short_code' => 'abc123', 'target_url' => 'https://example.com' ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT * FROM wp_ffc_short_urls WHERE short_code = "abc123"' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->findByShortCode( 'abc123' );

        $this->assertSame( 'abc123', $result['short_code'] );
    }

    public function test_find_by_short_code_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $this->assertNull( $this->repo->findByShortCode( 'nonexistent' ) );
    }

    public function test_find_by_short_code_returns_cached_result(): void {
        $cached = [ 'id' => '1', 'short_code' => 'cached' ];

        // Override cache stub to return cached value for this key
        Functions\when( 'wp_cache_get' )->alias( function ( $key ) use ( $cached ) {
            return $key === 'code_cached' ? $cached : false;
        } );

        // wpdb should NOT be called since cache hit
        $this->wpdb->shouldNotReceive( 'prepare' );
        $this->wpdb->shouldNotReceive( 'get_row' );

        $result = $this->repo->findByShortCode( 'cached' );

        $this->assertSame( 'cached', $result['short_code'] );
    }

    // ==================================================================
    // findByPostId()
    // ==================================================================

    public function test_find_by_post_id_returns_active_record(): void {
        $row = [ 'id' => '5', 'post_id' => '42', 'status' => 'active' ];

        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

        $result = $this->repo->findByPostId( 42 );

        $this->assertSame( '42', $result['post_id'] );
    }

    public function test_find_by_post_id_returns_null_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $this->assertNull( $this->repo->findByPostId( 999 ) );
    }

    public function test_find_by_post_id_uses_cache(): void {
        $cached = [ 'id' => '5', 'post_id' => '42' ];

        Functions\when( 'wp_cache_get' )->alias( function ( $key ) use ( $cached ) {
            return $key === 'post_42' ? $cached : false;
        } );

        $this->wpdb->shouldNotReceive( 'prepare' );

        $result = $this->repo->findByPostId( 42 );

        $this->assertSame( '42', $result['post_id'] );
    }

    // ==================================================================
    // incrementClickCount()
    // ==================================================================

    public function test_increment_click_count_success(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'UPDATE ...' );
        $this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

        $this->assertTrue( $this->repo->incrementClickCount( 1 ) );
    }

    public function test_increment_click_count_failure(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'UPDATE ...' );
        $this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

        $this->assertFalse( $this->repo->incrementClickCount( 1 ) );
    }

    // ==================================================================
    // codeExists()
    // ==================================================================

    public function test_code_exists_returns_true_when_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

        $this->assertTrue( $this->repo->codeExists( 'abc123' ) );
    }

    public function test_code_exists_returns_false_when_not_found(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );

        $this->assertFalse( $this->repo->codeExists( 'nonexistent' ) );
    }

    // ==================================================================
    // findPaginated()
    // ==================================================================

    public function test_find_paginated_returns_items_and_total(): void {
        $items = [
            [ 'id' => '1', 'short_code' => 'aaa', 'status' => 'active' ],
            [ 'id' => '2', 'short_code' => 'bbb', 'status' => 'active' ],
        ];

        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $items );

        $result = $this->repo->findPaginated();

        $this->assertSame( 2, $result['total'] );
        $this->assertCount( 2, $result['items'] );
    }

    public function test_find_paginated_with_search_filter(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive( 'esc_like' )->once()->andReturnUsing( function ( $v ) {
            return $v;
        } );
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$captured_query ) {
            $captured_query = func_get_arg( 0 );
            return 'QUERY';
        } );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        $this->repo->findPaginated( [ 'search' => 'test' ] );

        $this->assertStringContainsString( 'LIKE', $captured_query );
    }

    public function test_find_paginated_with_status_filter(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [
            [ 'id' => '1', 'status' => 'active' ],
        ] );

        $result = $this->repo->findPaginated( [ 'status' => 'active' ] );

        $this->assertSame( 1, $result['total'] );
    }

    public function test_find_paginated_invalid_orderby_falls_back_to_created_at(): void {
        $captured_query = '';
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () use ( &$captured_query ) {
            $args = func_get_args();
            // Capture the items query (the one with ORDER BY)
            if ( strpos( $args[0], 'ORDER BY' ) !== false ) {
                $captured_query = $args[0];
            }
            return 'QUERY';
        } );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [] );

        $this->repo->findPaginated( [ 'orderby' => 'malicious_column' ] );

        $this->assertStringContainsString( 'created_at', $captured_query );
    }

    public function test_find_paginated_empty_results(): void {
        $this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '0' );
        $this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

        $result = $this->repo->findPaginated();

        $this->assertSame( 0, $result['total'] );
        $this->assertSame( [], $result['items'] );
    }

    // ==================================================================
    // getStats()
    // ==================================================================

    public function test_get_stats_returns_aggregated_data(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( [
            'total_links'   => '15',
            'active_links'  => '10',
            'total_clicks'  => '250',
            'trashed_links' => '3',
        ] );

        $stats = $this->repo->getStats();

        $this->assertSame( 15, $stats['total_links'] );
        $this->assertSame( 10, $stats['active_links'] );
        $this->assertSame( 250, $stats['total_clicks'] );
        $this->assertSame( 3, $stats['trashed_links'] );
    }

    public function test_get_stats_handles_null_row(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

        $stats = $this->repo->getStats();

        $this->assertSame( 0, $stats['total_links'] );
        $this->assertSame( 0, $stats['active_links'] );
        $this->assertSame( 0, $stats['total_clicks'] );
        $this->assertSame( 0, $stats['trashed_links'] );
    }

    public function test_get_stats_handles_null_values_in_row(): void {
        $this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'QUERY' );
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( [
            'total_links'   => null,
            'active_links'  => null,
            'total_clicks'  => null,
            'trashed_links' => null,
        ] );

        $stats = $this->repo->getStats();

        $this->assertSame( 0, $stats['total_links'] );
        $this->assertSame( 0, $stats['total_clicks'] );
    }
}
