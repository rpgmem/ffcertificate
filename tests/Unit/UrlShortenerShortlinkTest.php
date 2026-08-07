<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerShortlink;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerShortlink: the pre_get_shortlink filter that publishes
 * a page's short URL as the WordPress canonical shortlink.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerShortlink
 */
class UrlShortenerShortlinkTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerShortlink $shortlink;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    /** @var UrlShortenerRepository|Mockery\MockInterface */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        class_exists( '\\FreeFormCertificate\\UrlShortener\\UrlShortenerShortlink' );

        Functions\when( '__' )->returnArg();

        $this->service   = Mockery::mock( UrlShortenerService::class );
        $this->repo      = Mockery::mock( UrlShortenerRepository::class );
        $this->shortlink = new UrlShortenerShortlink( $this->service );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_registers_filter(): void {
        $this->shortlink->init();
        $this->assertNotFalse(
            has_filter( 'pre_get_shortlink', 'FreeFormCertificate\UrlShortener\UrlShortenerShortlink->filter_shortlink()' )
        );
    }

    public function test_returns_short_url_for_exposed_type_with_active_record(): void {
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );
        $this->repo->shouldReceive( 'findByPostId' )->with( 42 )->andReturn(
            [ 'short_code' => 'abc123', 'status' => 'active' ]
        );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );
        $this->service->shouldReceive( 'get_short_url' )->with( 'abc123' )->andReturn( 'https://example.com/go/abc123' );

        $result = $this->shortlink->filter_shortlink( false, 42, 'post' );

        $this->assertSame( 'https://example.com/go/abc123', $result );
    }

    public function test_passthrough_for_non_exposed_type(): void {
        Functions\when( 'get_post_type' )->justReturn( 'post' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );
        $this->service->shouldNotReceive( 'get_repository' );

        $this->assertFalse( $this->shortlink->filter_shortlink( false, 42, 'post' ) );
    }

    public function test_passthrough_when_no_record(): void {
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );
        $this->repo->shouldReceive( 'findByPostId' )->with( 42 )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );

        $this->assertFalse( $this->shortlink->filter_shortlink( false, 42, 'post' ) );
    }

    public function test_passthrough_when_record_not_active(): void {
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );
        $this->repo->shouldReceive( 'findByPostId' )->with( 42 )->andReturn(
            [ 'short_code' => 'abc123', 'status' => 'disabled' ]
        );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );
        $this->service->shouldNotReceive( 'get_short_url' );

        $this->assertFalse( $this->shortlink->filter_shortlink( false, 42, 'post' ) );
    }

    public function test_passthrough_when_id_zero_and_not_singular(): void {
        Functions\when( 'is_singular' )->justReturn( false );
        $this->service->shouldNotReceive( 'get_exposed_post_types' );

        $this->assertFalse( $this->shortlink->filter_shortlink( false, 0, 'query' ) );
    }

    public function test_resolves_queried_object_when_id_zero_and_singular(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 77 );
        Functions\when( 'get_post_type' )->justReturn( 'page' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );
        $this->repo->shouldReceive( 'findByPostId' )->with( 77 )->andReturn(
            [ 'short_code' => 'zzz', 'status' => 'active' ]
        );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );
        $this->service->shouldReceive( 'get_short_url' )->with( 'zzz' )->andReturn( 'https://example.com/go/zzz' );

        $result = $this->shortlink->filter_shortlink( false, 0, 'query' );

        $this->assertSame( 'https://example.com/go/zzz', $result );
    }

    public function test_preserves_incoming_value_on_passthrough(): void {
        // A prior filter may have already provided a shortlink string.
        Functions\when( 'get_post_type' )->justReturn( 'post' );
        $this->service->shouldReceive( 'get_exposed_post_types' )->andReturn( [ 'page' ] );

        $this->assertSame(
            'https://other/sl',
            $this->shortlink->filter_shortlink( 'https://other/sl', 42, 'post' )
        );
    }
}
