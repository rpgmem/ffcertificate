<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerService: short URL creation, code generation,
 * settings accessors, CRUD operations, and statistics.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerService
 */
class UrlShortenerServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerService $service;

    /** @var UrlShortenerRepository|Mockery\MockInterface */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub common WP functions
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_title' )->alias( function ( $title ) {
            return strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $title ) );
        } );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'current_time' )->justReturn( '2026-02-22 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, $args );
        } );

        // Namespaced WP function stubs (resolved inside FreeFormCertificate\UrlShortener)
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );
        Functions\when( 'FreeFormCertificate\UrlShortener\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\sanitize_title' )->alias( function ( $title ) {
            return strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $title ) );
        } );
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_url_raw' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'FreeFormCertificate\UrlShortener\current_time' )->justReturn( '2026-02-22 12:00:00' );
        Functions\when( 'FreeFormCertificate\UrlShortener\get_current_user_id' )->justReturn( 1 );
        Functions\when( 'FreeFormCertificate\UrlShortener\__' )->returnArg();

        $this->repo    = Mockery::mock( UrlShortenerRepository::class );
        $this->service = new UrlShortenerService( $this->repo );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // create_short_url()
    // ==================================================================

    public function test_create_short_url_success(): void {
        $this->repo->shouldReceive( 'codeExists' )->andReturn( false );
        $this->repo->shouldReceive( 'insert' )->once()->andReturn( 42 );
        $this->repo->shouldReceive( 'findById' )->with( 42 )->once()->andReturn( [
            'id'         => 42,
            'short_code' => 'abc123',
            'target_url' => 'https://example.com/page',
            'status'     => 'active',
        ] );

        $result = $this->service->create_short_url( 'https://example.com/page', 'Test Link' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 42, $result['data']['id'] );
    }

    public function test_create_short_url_empty_url_returns_error(): void {
        Functions\when( 'esc_url_raw' )->justReturn( '' );

        $result = $this->service->create_short_url( '' );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    public function test_create_short_url_returns_existing_for_post_id(): void {
        $existing = [
            'id'         => 10,
            'short_code' => 'exists',
            'post_id'    => 5,
            'status'     => 'active',
        ];
        $this->repo->shouldReceive( 'findByPostId' )->with( 5 )->once()->andReturn( $existing );

        $result = $this->service->create_short_url( 'https://example.com', '', 5 );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 10, $result['data']['id'] );
    }

    public function test_create_short_url_insert_failure_returns_error(): void {
        $this->repo->shouldReceive( 'codeExists' )->andReturn( false );
        $this->repo->shouldReceive( 'insert' )->once()->andReturn( false );

        $result = $this->service->create_short_url( 'https://example.com/page' );

        $this->assertFalse( $result['success'] );
        $this->assertArrayHasKey( 'error', $result );
    }

    // ==================================================================
    // generate_unique_code()
    // ==================================================================

    public function test_generate_unique_code_returns_correct_length(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_code_length' => 8 ] );
        $this->repo->shouldReceive( 'codeExists' )->andReturn( false );

        $code = $this->service->generate_unique_code();

        $this->assertSame( 8, strlen( $code ) );
    }

    public function test_generate_unique_code_uses_base62_charset(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );
        $this->repo->shouldReceive( 'codeExists' )->andReturn( false );

        $code = $this->service->generate_unique_code( 6 );

        $this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]+$/', $code );
    }

    public function test_generate_unique_code_retries_on_collision(): void {
        $call_count = 0;
        $this->repo->shouldReceive( 'codeExists' )->andReturnUsing( function () use ( &$call_count ) {
            $call_count++;
            // First call collides, second succeeds
            return $call_count <= 1;
        } );

        $code = $this->service->generate_unique_code( 6 );

        $this->assertSame( 6, strlen( $code ) );
        $this->assertSame( 2, $call_count );
    }

    public function test_generate_unique_code_increases_length_after_max_attempts(): void {
        $attempt = 0;
        $this->repo->shouldReceive( 'codeExists' )->andReturnUsing( function () use ( &$attempt ) {
            $attempt++;
            // First 10 attempts collide (max_attempts), then succeed with length+1
            return $attempt <= 10;
        } );

        $code = $this->service->generate_unique_code( 4 );

        // Should be 5 chars (4 + 1 fallback)
        $this->assertSame( 5, strlen( $code ) );
    }

    public function test_generate_unique_code_custom_length(): void {
        $this->repo->shouldReceive( 'codeExists' )->andReturn( false );

        $code = $this->service->generate_unique_code( 10 );

        $this->assertSame( 10, strlen( $code ) );
    }

    // ==================================================================
    // get_short_url()
    // ==================================================================

    public function test_get_short_url_builds_correct_url(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_prefix' => 'go' ] );

        $url = $this->service->get_short_url( 'abc123' );

        $this->assertSame( 'https://example.com/go/abc123', $url );
    }

    public function test_get_short_url_custom_prefix(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_prefix' => 'link' ] );

        $url = $this->service->get_short_url( 'xyz789' );

        $this->assertSame( 'https://example.com/link/xyz789', $url );
    }

    // ==================================================================
    // get_prefix()
    // ==================================================================

    public function test_get_prefix_default_is_go(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertSame( 'go', $this->service->get_prefix() );
    }

    public function test_get_prefix_from_settings(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_prefix' => 'link' ] );

        $this->assertSame( 'link', $this->service->get_prefix() );
    }

    public function test_get_prefix_sanitizes_value(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_prefix' => 'My Link!' ] );

        $prefix = $this->service->get_prefix();

        // sanitize_title strips spaces and special chars
        $this->assertMatchesRegularExpression( '/^[a-z0-9\-]+$/', $prefix );
    }

    // ==================================================================
    // get_code_length()
    // ==================================================================

    public function test_get_code_length_default_is_6(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertSame( 6, $this->service->get_code_length() );
    }

    public function test_get_code_length_clamped_to_min_4(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_code_length' => 2 ] );

        $this->assertSame( 4, $this->service->get_code_length() );
    }

    public function test_get_code_length_clamped_to_max_10(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_code_length' => 99 ] );

        $this->assertSame( 10, $this->service->get_code_length() );
    }

    public function test_get_code_length_from_settings(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_code_length' => 8 ] );

        $this->assertSame( 8, $this->service->get_code_length() );
    }

    // ==================================================================
    // get_redirect_type()
    // ==================================================================

    public function test_get_redirect_type_default_is_302(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertSame( 302, $this->service->get_redirect_type() );
    }

    public function test_get_redirect_type_accepts_301(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_redirect_type' => 301 ] );

        $this->assertSame( 301, $this->service->get_redirect_type() );
    }

    public function test_get_redirect_type_accepts_307(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_redirect_type' => 307 ] );

        $this->assertSame( 307, $this->service->get_redirect_type() );
    }

    public function test_get_redirect_type_invalid_falls_back_to_302(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_redirect_type' => 404 ] );

        $this->assertSame( 302, $this->service->get_redirect_type() );
    }

    // ==================================================================
    // is_enabled()
    // ==================================================================

    public function test_is_enabled_true_by_default(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertTrue( $this->service->is_enabled() );
    }

    public function test_is_enabled_returns_true_when_setting_is_1(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_enabled' => 1 ] );

        $this->assertTrue( $this->service->is_enabled() );
    }

    public function test_is_enabled_returns_false_when_disabled(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_enabled' => 0 ] );

        $this->assertFalse( $this->service->is_enabled() );
    }

    // ==================================================================
    // is_auto_create_enabled()
    // ==================================================================

    public function test_is_auto_create_enabled_true_by_default(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertTrue( $this->service->is_auto_create_enabled() );
    }

    public function test_is_auto_create_enabled_returns_false_when_disabled(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_auto_create' => 0 ] );

        $this->assertFalse( $this->service->is_auto_create_enabled() );
    }

    // ==================================================================
    // get_enabled_post_types()
    // ==================================================================

    public function test_get_enabled_post_types_default(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [] );

        $this->assertSame( [ 'post', 'page' ], $this->service->get_enabled_post_types() );
    }

    public function test_get_enabled_post_types_from_array(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_post_types' => [ 'post', 'product' ] ] );

        $this->assertSame( [ 'post', 'product' ], $this->service->get_enabled_post_types() );
    }

    public function test_get_enabled_post_types_from_csv_string(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_post_types' => 'post, page, product' ] );

        $result = $this->service->get_enabled_post_types();

        $this->assertContains( 'post', $result );
        $this->assertContains( 'page', $result );
        $this->assertContains( 'product', $result );
    }

    public function test_get_enabled_post_types_empty_falls_back_to_default(): void {
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( [ 'url_shortener_post_types' => [] ] );

        $this->assertSame( [ 'post', 'page' ], $this->service->get_enabled_post_types() );
    }

    // ==================================================================
    // delete_short_url()
    // ==================================================================

    public function test_delete_short_url_success(): void {
        $this->repo->shouldReceive( 'delete' )->with( 1 )->once()->andReturn( 1 );

        $this->assertTrue( $this->service->delete_short_url( 1 ) );
    }

    public function test_delete_short_url_failure(): void {
        $this->repo->shouldReceive( 'delete' )->with( 99 )->once()->andReturn( false );

        $this->assertFalse( $this->service->delete_short_url( 99 ) );
    }

    // ==================================================================
    // trash_short_url()
    // ==================================================================

    public function test_trash_short_url_success(): void {
        $this->repo->shouldReceive( 'update' )
            ->with( 1, Mockery::on( function ( $data ) {
                return $data['status'] === 'trashed' && isset( $data['updated_at'] );
            } ) )
            ->once()
            ->andReturn( 1 );

        $this->assertTrue( $this->service->trash_short_url( 1 ) );
    }

    // ==================================================================
    // restore_short_url()
    // ==================================================================

    public function test_restore_short_url_sets_disabled(): void {
        $this->repo->shouldReceive( 'update' )
            ->with( 1, Mockery::on( function ( $data ) {
                return $data['status'] === 'disabled' && isset( $data['updated_at'] );
            } ) )
            ->once()
            ->andReturn( 1 );

        $this->assertTrue( $this->service->restore_short_url( 1 ) );
    }

    // ==================================================================
    // toggle_status()
    // ==================================================================

    public function test_toggle_status_active_to_disabled(): void {
        $this->repo->shouldReceive( 'findById' )->with( 1 )->once()->andReturn( [
            'id' => 1, 'status' => 'active',
        ] );
        $this->repo->shouldReceive( 'update' )
            ->with( 1, Mockery::on( function ( $data ) {
                return $data['status'] === 'disabled';
            } ) )
            ->once()
            ->andReturn( 1 );

        $this->assertTrue( $this->service->toggle_status( 1 ) );
    }

    public function test_toggle_status_disabled_to_active(): void {
        $this->repo->shouldReceive( 'findById' )->with( 2 )->once()->andReturn( [
            'id' => 2, 'status' => 'disabled',
        ] );
        $this->repo->shouldReceive( 'update' )
            ->with( 2, Mockery::on( function ( $data ) {
                return $data['status'] === 'active';
            } ) )
            ->once()
            ->andReturn( 1 );

        $this->assertTrue( $this->service->toggle_status( 2 ) );
    }

    public function test_toggle_status_not_found_returns_false(): void {
        $this->repo->shouldReceive( 'findById' )->with( 999 )->once()->andReturn( null );

        $this->assertFalse( $this->service->toggle_status( 999 ) );
    }

    // ==================================================================
    // get_stats()
    // ==================================================================

    public function test_get_stats_delegates_to_repository(): void {
        $stats = [
            'total_links'   => 10,
            'active_links'  => 7,
            'total_clicks'  => 500,
            'trashed_links' => 2,
        ];
        $this->repo->shouldReceive( 'getStats' )->once()->andReturn( $stats );

        $this->assertSame( $stats, $this->service->get_stats() );
    }

    // ==================================================================
    // get_repository()
    // ==================================================================

    public function test_get_repository_returns_injected_instance(): void {
        $this->assertSame( $this->repo, $this->service->get_repository() );
    }
}
