<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerLoader;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerLoader: conditional hook registration, rewrite rules,
 * query vars, redirect handling, and rewrite rule flushing.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerLoader
 */
class UrlShortenerLoaderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private UrlShortenerLoader $loader;

    /** @var UrlShortenerService|Mockery\MockInterface */
    private $service;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock global $wpdb (needed by UrlShortenerRepository created inside Service)
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        // Stub common WP functions
        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );

        // Namespaced stubs
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
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_unslash' )->returnArg();
        Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'FreeFormCertificate\UrlShortener\wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );

        // Create loader and inject a mock service via reflection
        $this->loader  = new UrlShortenerLoader();
        $this->service = Mockery::mock( UrlShortenerService::class );

        $ref = new \ReflectionClass( $this->loader );
        $prop = $ref->getProperty( 'service' );
        $prop->setAccessible( true );
        $prop->setValue( $this->loader, $this->service );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // init()
    // ==================================================================

    public function test_init_registers_hooks_when_enabled(): void {
        $this->service->shouldReceive( 'is_enabled' )->once()->andReturn( true );

        Functions\when( 'is_admin' )->justReturn( false );

        $this->loader->init();

        $this->assertTrue( has_action( 'init', 'FreeFormCertificate\UrlShortener\UrlShortenerLoader->register_rewrite_rules()' ) !== false );
        $this->assertTrue( has_filter( 'query_vars', 'FreeFormCertificate\UrlShortener\UrlShortenerLoader->add_query_vars()' ) !== false );
        $this->assertTrue( has_action( 'parse_request', 'FreeFormCertificate\UrlShortener\UrlShortenerLoader->intercept_short_url()' ) !== false );
        $this->assertTrue( has_action( 'template_redirect', 'FreeFormCertificate\UrlShortener\UrlShortenerLoader->handle_redirect()' ) !== false );
    }

    public function test_init_skips_hooks_when_disabled(): void {
        $this->service->shouldReceive( 'is_enabled' )->once()->andReturn( false );

        $this->loader->init();

        $this->assertFalse( has_action( 'init', 'FreeFormCertificate\UrlShortener\UrlShortenerLoader->register_rewrite_rules()' ) );
    }

    // ==================================================================
    // maybe_flush_rewrite_rules()
    // ==================================================================

    public function test_maybe_flush_flushes_on_first_install(): void {
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( '' );

        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        } );
        Functions\when( 'FreeFormCertificate\UrlShortener\update_option' )->justReturn( true );

        $this->loader->maybe_flush_rewrite_rules();

        $this->assertTrue( $flushed );
    }

    public function test_maybe_flush_skips_when_version_matches(): void {
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( 'go:1' );

        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        } );

        $this->loader->maybe_flush_rewrite_rules();

        $this->assertFalse( $flushed );
    }

    public function test_maybe_flush_flushes_on_prefix_change(): void {
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'link' );
        Functions\when( 'FreeFormCertificate\UrlShortener\get_option' )->justReturn( 'go:1' );

        $flushed = false;
        Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
            $flushed = true;
        } );
        Functions\when( 'FreeFormCertificate\UrlShortener\update_option' )->justReturn( true );

        $this->loader->maybe_flush_rewrite_rules();

        $this->assertTrue( $flushed );
    }

    // ==================================================================
    // register_rewrite_rules()
    // ==================================================================

    public function test_register_rewrite_rules_adds_rule_with_prefix(): void {
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        $captured_regex = '';
        $captured_query = '';
        Functions\when( 'add_rewrite_rule' )->alias( function ( $regex, $query, $position ) use ( &$captured_regex, &$captured_query ) {
            $captured_regex = $regex;
            $captured_query = $query;
        } );

        $this->loader->register_rewrite_rules();

        $this->assertStringContainsString( 'go', $captured_regex );
        $this->assertStringContainsString( 'ffc_short_code', $captured_query );
    }

    public function test_register_rewrite_rules_regex_matches_alphanumeric(): void {
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        $captured_regex = '';
        Functions\when( 'add_rewrite_rule' )->alias( function ( $regex ) use ( &$captured_regex ) {
            $captured_regex = $regex;
        } );

        $this->loader->register_rewrite_rules();

        // Test that the regex matches valid short codes (use # delimiter to avoid conflict with /)
        $this->assertSame( 1, preg_match( '#' . $captured_regex . '#', 'go/abc123' ) );
        $this->assertSame( 1, preg_match( '#' . $captured_regex . '#', 'go/AbC123/' ) );
        // Should not match codes with special chars
        $this->assertSame( 0, preg_match( '#' . $captured_regex . '#', 'go/ab-c!' ) );
    }

    // ==================================================================
    // add_query_vars()
    // ==================================================================

    public function test_add_query_vars_appends_short_code(): void {
        $vars = [ 'existing_var' ];
        $result = $this->loader->add_query_vars( $vars );

        $this->assertContains( 'ffc_short_code', $result );
        $this->assertContains( 'existing_var', $result );
    }

    public function test_add_query_vars_preserves_existing(): void {
        $vars = [ 'foo', 'bar' ];
        $result = $this->loader->add_query_vars( $vars );

        $this->assertCount( 3, $result );
    }

    // ==================================================================
    // handle_redirect()
    // ==================================================================

    public function test_handle_redirect_returns_early_when_no_code(): void {
        Functions\when( 'get_query_var' )->justReturn( '' );
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );
        unset( $_SERVER['REQUEST_URI'] );

        // If it doesn't exit, the test passes (no redirect triggered)
        $this->loader->handle_redirect();

        // If we reach here, early return worked
        $this->assertTrue( true );
    }

    public function test_handle_redirect_redirects_home_for_inactive_code(): void {
        Functions\when( 'get_query_var' )->justReturn( 'abc123' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn( null );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        Functions\when( 'nocache_headers' )->justReturn( null );
        Functions\when( 'wp_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected_home' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected_home' );

        $this->loader->handle_redirect();
    }

    public function test_handle_redirect_redirects_home_for_disabled_code(): void {
        Functions\when( 'get_query_var' )->justReturn( 'xyz789' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'xyz789' )->andReturn( [
            'id'         => 5,
            'short_code' => 'xyz789',
            'target_url' => 'https://target.com',
            'status'     => 'disabled',
        ] );
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );

        Functions\when( 'nocache_headers' )->justReturn( null );
        Functions\when( 'wp_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected_home' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected_home' );

        $this->loader->handle_redirect();
    }

    public function test_handle_redirect_increments_click_and_redirects(): void {
        Functions\when( 'get_query_var' )->justReturn( 'abc123' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn( [
            'id'         => 1,
            'short_code' => 'abc123',
            'target_url' => 'https://target.com/page',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 1 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 302 );

        Functions\when( 'wp_validate_redirect' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "safe_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'safe_redirect:https://target.com/page:302' );

        $this->loader->handle_redirect();
    }

    public function test_handle_redirect_uses_wp_redirect_for_external_urls(): void {
        Functions\when( 'get_query_var' )->justReturn( 'ext123' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'ext123' )->andReturn( [
            'id'         => 2,
            'short_code' => 'ext123',
            'target_url' => 'https://external.com/page',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 2 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 301 );

        // wp_validate_redirect returns false for external URLs
        Functions\when( 'wp_validate_redirect' )->justReturn( false );
        Functions\when( 'wp_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "wp_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_redirect:https://external.com/page:301' );

        $this->loader->handle_redirect();
    }

    public function test_handle_redirect_empty_target_redirects_home(): void {
        Functions\when( 'get_query_var' )->justReturn( 'empty1' );
        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'FreeFormCertificate\UrlShortener\esc_url_raw' )->justReturn( '' );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'empty1' )->andReturn( [
            'id'         => 3,
            'short_code' => 'empty1',
            'target_url' => '',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 3 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 302 );

        Functions\when( 'nocache_headers' )->justReturn( null );
        Functions\when( 'wp_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected_home_empty' );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'redirected_home_empty' );

        $this->loader->handle_redirect();
    }

    // ==================================================================
    // intercept_short_url() — parse_request handler
    // ==================================================================

    public function test_intercept_short_url_redirects_via_uri(): void {
        $_SERVER['REQUEST_URI'] = '/go/abc123';
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'abc123' )->andReturn( [
            'id'         => 10,
            'short_code' => 'abc123',
            'target_url' => 'https://target.com/page',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 10 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 302 );

        Functions\when( 'wp_validate_redirect' )->justReturn( false );
        Functions\when( 'wp_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "wp_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_redirect:https://target.com/page:302' );

        $wp = new \stdClass();
        $this->loader->intercept_short_url( $wp );
    }

    public function test_intercept_short_url_returns_early_for_non_matching_uri(): void {
        $_SERVER['REQUEST_URI'] = '/some-other-page/';
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        $wp = new \stdClass();
        $this->loader->intercept_short_url( $wp );

        // If we reach here, early return worked
        $this->assertTrue( true );
    }

    public function test_intercept_short_url_handles_trailing_slash(): void {
        $_SERVER['REQUEST_URI'] = '/go/TraiL1/';
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'TraiL1' )->andReturn( [
            'id'         => 11,
            'short_code' => 'TraiL1',
            'target_url' => 'https://example.com/dest',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 11 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 301 );

        Functions\when( 'wp_validate_redirect' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "safe_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'safe_redirect:https://example.com/dest:301' );

        $wp = new \stdClass();
        $this->loader->intercept_short_url( $wp );
    }

    public function test_intercept_short_url_works_with_subdirectory(): void {
        $_SERVER['REQUEST_URI'] = '/blog/go/sub123';
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'sub123' )->andReturn( [
            'id'         => 12,
            'short_code' => 'sub123',
            'target_url' => 'https://external.com/',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 12 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 302 );

        Functions\when( 'wp_validate_redirect' )->justReturn( false );
        Functions\when( 'wp_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "wp_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_redirect:https://external.com/:302' );

        $wp = new \stdClass();
        $this->loader->intercept_short_url( $wp );
    }

    // ==================================================================
    // handle_redirect() — URI fallback (when get_query_var is empty)
    // ==================================================================

    public function test_handle_redirect_falls_back_to_uri_when_query_var_empty(): void {
        Functions\when( 'get_query_var' )->justReturn( '' );
        $_SERVER['REQUEST_URI'] = '/go/fallback1';
        $this->service->shouldReceive( 'get_prefix' )->andReturn( 'go' );

        Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
        Functions\when( 'do_action' )->justReturn( null );

        $repo = Mockery::mock( UrlShortenerRepository::class );
        $repo->shouldReceive( 'findByShortCode' )->with( 'fallback1' )->andReturn( [
            'id'         => 20,
            'short_code' => 'fallback1',
            'target_url' => 'https://example.com/target',
            'status'     => 'active',
        ] );
        $repo->shouldReceive( 'incrementClickCount' )->with( 20 )->once();
        $this->service->shouldReceive( 'get_repository' )->andReturn( $repo );
        $this->service->shouldReceive( 'get_redirect_type' )->andReturn( 302 );

        Functions\when( 'wp_validate_redirect' )->justReturn( true );
        Functions\when( 'wp_safe_redirect' )->alias( function ( $url, $code ) {
            throw new \RuntimeException( "safe_redirect:{$url}:{$code}" );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'safe_redirect:https://example.com/target:302' );

        $this->loader->handle_redirect();
    }
}
