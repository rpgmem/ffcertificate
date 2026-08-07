<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Capabilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerBackfillHandler;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerBackfillHandler: the batched AJAX that creates missing
 * short URLs for published posts of the shortened post types.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerBackfillHandler
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class UrlShortenerBackfillHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerBackfillHandler $handler;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    /** @var UrlShortenerRepository|Mockery\MockInterface */
    private $repo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        class_exists( '\\FreeFormCertificate\\UrlShortener\\UrlShortenerBackfillHandler' );

        Functions\when( '__' )->returnArg();

        // AjaxTrait reads POST through RequestInput (\FreeFormCertificate\Core\*).
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );

        // Nonce + capability pass by default.
        $_POST['nonce'] = 'valid';
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        // get_permalink resolves inside the UrlShortener namespace.
        Functions\when( 'FreeFormCertificate\UrlShortener\get_permalink' )->justReturn( 'https://example.com/p' );

        $this->service = Mockery::mock( UrlShortenerService::class );
        $this->repo    = Mockery::mock( UrlShortenerRepository::class );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );
        $this->handler = new UrlShortenerBackfillHandler( $this->service );
    }

    protected function tearDown(): void {
        unset( $_POST['nonce'], $_POST['cursor'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_registers_ajax_hook(): void {
        $this->handler->init();
        $this->assertNotFalse(
            has_action( 'wp_ajax_ffc_url_shortener_backfill', 'FreeFormCertificate\UrlShortener\UrlShortenerBackfillHandler->ajax_backfill()' )
        );
    }

    public function test_first_page_creates_rows_and_returns_total(): void {
        unset( $_POST['cursor'] ); // First page → cursor 0.

        $this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( [ 'post', 'page' ] );
        $this->repo->shouldReceive( 'findBackfillCandidates' )
            ->with( [ 'post', 'page' ], PHP_INT_MAX, 50 )
            ->andReturn( [
                [ 'ID' => 10, 'post_title' => 'A' ],
                [ 'ID' => 8, 'post_title' => 'B' ],
            ] );
        $this->service->shouldReceive( 'create_short_url' )->twice()->andReturn( [ 'success' => true ] );
        $this->repo->shouldReceive( 'countBackfillCandidates' )->with( [ 'post', 'page' ] )->andReturn( 5 );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( static function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $this->handler->ajax_backfill();

        $this->assertSame( 2, $captured['created'] );
        $this->assertSame( 2, $captured['processed'] );
        $this->assertSame( 8, $captured['next_cursor'] );
        $this->assertTrue( $captured['done'] );
        $this->assertSame( 5, $captured['total'] );
    }

    public function test_subsequent_page_uses_cursor_and_omits_total(): void {
        $_POST['cursor'] = '8';

        $this->service->shouldReceive( 'get_enabled_post_types' )->andReturn( [ 'post' ] );

        // A full page (BATCH_SIZE rows) → not done, no total re-count.
        $rows = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $rows[] = [ 'ID' => 100 - $i, 'post_title' => 'T' . $i ];
        }
        $this->repo->shouldReceive( 'findBackfillCandidates' )
            ->with( [ 'post' ], 8, 50 )
            ->andReturn( $rows );
        $this->service->shouldReceive( 'create_short_url' )->andReturn( [ 'success' => true ] );
        $this->repo->shouldNotReceive( 'countBackfillCandidates' );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( static function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $this->handler->ajax_backfill();

        $this->assertSame( 50, $captured['processed'] );
        $this->assertFalse( $captured['done'] );
        $this->assertSame( 51, $captured['next_cursor'] ); // 100 - 49.
        $this->assertArrayNotHasKey( 'total', $captured );
    }

    public function test_invalid_nonce_aborts_before_work(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        $this->service->shouldNotReceive( 'get_enabled_post_types' );

        Functions\when( 'wp_send_json_error' )->alias( static function () {
            throw new \RuntimeException( 'nonce_failed' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'nonce_failed' );

        $this->handler->ajax_backfill();
    }
}
