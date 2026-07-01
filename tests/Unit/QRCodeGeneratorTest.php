<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QRCodeGenerator;

/**
 * Tests for QRCodeGenerator: placeholder parsing, parameter validation,
 * error correction mapping, HTML formatting, and cache logic.
 *
 * @covers \FreeFormCertificate\Generators\QRCodeGenerator
 */
class QRCodeGeneratorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private QRCodeGenerator $generator;
    private \ReflectionClass $ref;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock $wpdb for DatabaseHelperTrait
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'update' )->andReturn( 1 )->byDefault();

        // Global WP stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'wp_delete_file' )->alias( function( $f ) { if ( file_exists( $f ) ) { @unlink( $f ); } } );

        // Namespaced stubs: FreeFormCertificate\Generators\*
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'wp_delete_file' )->alias( function( $f ) { if ( file_exists( $f ) ) { @unlink( $f ); } } );

        $this->generator = new QRCodeGenerator();
        $this->ref = new \ReflectionClass( QRCodeGenerator::class );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: invoke private method
     */
    private function invokePrivate( string $method, array $args = [] ) {
        $m = $this->ref->getMethod( $method );
        $m->setAccessible( true );
        return $m->invoke( $this->generator, ...$args );
    }

    // ==================================================================
    // parse_placeholder_params() — Placeholder parsing
    // ==================================================================

    public function test_parse_default_placeholder(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code}}' ] );

        $this->assertSame( 200, $params['size'] );
        $this->assertSame( 2, $params['margin'] );
        $this->assertSame( 'M', $params['error_level'] );
    }

    public function test_parse_single_size_param(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:size=150}}' ] );

        $this->assertSame( 150, $params['size'] );
        $this->assertSame( 2, $params['margin'] );   // default
        $this->assertSame( 'M', $params['error_level'] ); // default
    }

    public function test_parse_all_params(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:size=300:margin=0:error=H}}' ] );

        $this->assertSame( 300, $params['size'] );
        $this->assertSame( 0, $params['margin'] );
        $this->assertSame( 'H', $params['error_level'] );
    }

    public function test_parse_margin_only(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:margin=5}}' ] );

        $this->assertSame( 200, $params['size'] );  // default
        $this->assertSame( 5, $params['margin'] );
    }

    public function test_parse_error_level_only(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:error=L}}' ] );

        $this->assertSame( 'L', $params['error_level'] );
    }

    public function test_parse_lowercase_error_converts_to_uppercase(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:error=h}}' ] );

        $this->assertSame( 'H', $params['error_level'] );
    }

    // ==================================================================
    // Validation ranges
    // ==================================================================

    public function test_size_below_minimum_clamped_to_50(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:size=10}}' ] );

        $this->assertSame( 50, $params['size'] );
    }

    public function test_size_above_maximum_clamped_to_1000(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:size=5000}}' ] );

        $this->assertSame( 1000, $params['size'] );
    }

    public function test_margin_negative_becomes_absolute_value(): void {
        // absint(-5) = 5, then max(0, min(10, 5)) = 5
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:margin=-5}}' ] );

        $this->assertSame( 5, $params['margin'] );
    }

    public function test_margin_above_maximum_clamped_to_10(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:margin=99}}' ] );

        $this->assertSame( 10, $params['margin'] );
    }

    public function test_invalid_error_level_defaults_to_M(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:error=X}}' ] );

        $this->assertSame( 'M', $params['error_level'] );
    }

    public function test_part_without_equals_is_ignored(): void {
        $params = $this->invokePrivate( 'parse_placeholder_params', [ '{{qr_code:invalid:size=150}}' ] );

        $this->assertSame( 150, $params['size'] );
    }

    // ==================================================================
    // get_error_correction_constant() — Error level mapping
    // ==================================================================

    public function test_error_level_L(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'L' ] );
        $this->assertSame( QR_ECLEVEL_L, $constant );
    }

    public function test_error_level_M(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'M' ] );
        $this->assertSame( QR_ECLEVEL_M, $constant );
    }

    public function test_error_level_Q(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'Q' ] );
        $this->assertSame( QR_ECLEVEL_Q, $constant );
    }

    public function test_error_level_H(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'H' ] );
        $this->assertSame( QR_ECLEVEL_H, $constant );
    }

    public function test_error_level_lowercase_resolves(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'h' ] );
        $this->assertSame( QR_ECLEVEL_H, $constant );
    }

    public function test_error_level_unknown_defaults_to_M(): void {
        $constant = $this->invokePrivate( 'get_error_correction_constant', [ 'Z' ] );
        $this->assertSame( QR_ECLEVEL_M, $constant );
    }

    // ==================================================================
    // format_as_img_tag() — HTML output
    // ==================================================================

    public function test_format_img_tag_generates_correct_html(): void {
        $base64 = base64_encode( 'fake-png-data' );
        $html = $this->invokePrivate( 'format_as_img_tag', [ $base64, 200 ] );

        $this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
        $this->assertStringContainsString( $base64, $html );
        $this->assertStringContainsString( 'width:200px', $html );
        $this->assertStringContainsString( 'height:200px', $html );
        $this->assertStringContainsString( 'alt="QR Code"', $html );
    }

    public function test_format_img_tag_different_size(): void {
        $html = $this->invokePrivate( 'format_as_img_tag', [ 'abc123', 300 ] );

        $this->assertStringContainsString( 'width:300px', $html );
        $this->assertStringContainsString( 'height:300px', $html );
    }

    public function test_format_img_tag_empty_base64_returns_empty(): void {
        $html = $this->invokePrivate( 'format_as_img_tag', [ '', 200 ] );

        $this->assertSame( '', $html );
    }

    // ==================================================================
    // is_cache_enabled() — Settings check
    // ==================================================================

    public function test_cache_disabled_by_default(): void {
        // get_option returns empty array, no qr_cache_enabled key
        $enabled = $this->invokePrivate( 'is_cache_enabled', [] );
        $this->assertFalse( $enabled );
    }

    public function test_cache_enabled_when_setting_is_1(): void {
        Functions\when( 'get_option' )->justReturn(
            array( 'qr_cache_enabled' => 1 )
        );

        // Need to recreate generator to pick up new setting
        $gen = new QRCodeGenerator();
        $ref = new \ReflectionClass( $gen );
        $m = $ref->getMethod( 'is_cache_enabled' );
        $m->setAccessible( true );

        $this->assertTrue( $m->invoke( $gen ) );
    }

    // ==================================================================
    // generate() — QR Code generation
    // ==================================================================

    public function test_generate_empty_url_returns_empty(): void {
        $result = $this->generator->generate( '' );

        $this->assertSame( '', $result );
    }

    public function test_generate_produces_base64_output(): void {
        $result = $this->generator->generate( 'https://example.com/verify?t=abc123' );

        $this->assertNotEmpty( $result );
        // Verify it's valid base64
        $decoded = base64_decode( $result, true );
        $this->assertNotFalse( $decoded );
        // Should be a PNG (starts with PNG signature)
        $this->assertStringContainsString( 'PNG', $decoded );
    }

    public function test_generate_with_custom_params(): void {
        $params = array( 'size' => 300, 'margin' => 0, 'error_level' => 'H' );
        $result = $this->generator->generate( 'https://example.com', $params );

        $this->assertNotEmpty( $result );
    }

    // ==================================================================
    // parse_and_generate() — Full workflow
    // ==================================================================

    public function test_parse_and_generate_returns_img_tag(): void {
        $html = $this->generator->parse_and_generate(
            '{{qr_code:size=100}}',
            'https://example.com/verify?t=test123'
        );

        $this->assertStringContainsString( '<img src="data:image/png;base64,', $html );
        $this->assertStringContainsString( 'width:100px', $html );
    }

    public function test_parse_and_generate_default_params(): void {
        $html = $this->generator->parse_and_generate(
            '{{qr_code}}',
            'https://example.com'
        );

        $this->assertStringContainsString( 'width:200px', $html );
    }

    public function test_parse_and_generate_empty_url_returns_empty(): void {
        $html = $this->generator->parse_and_generate( '{{qr_code}}', '' );

        $this->assertSame( '', $html );
    }

    // ==================================================================
    // Defaults from settings
    // ==================================================================

    public function test_defaults_loaded_from_settings(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'qr_default_size'        => '300',
            'qr_default_margin'      => '0',
            'qr_default_error_level' => 'H',
        ) );

        $gen = new QRCodeGenerator();
        $ref = new \ReflectionClass( $gen );
        $prop = $ref->getProperty( 'defaults' );
        $prop->setAccessible( true );
        $defaults = $prop->getValue( $gen );

        $this->assertSame( 300, $defaults['size'] );
        $this->assertSame( 0, $defaults['margin'] );
        $this->assertSame( 'H', $defaults['error_level'] );
    }

    public function test_defaults_unchanged_when_no_settings(): void {
        $prop = $this->ref->getProperty( 'defaults' );
        $prop->setAccessible( true );
        $defaults = $prop->getValue( $this->generator );

        $this->assertSame( 200, $defaults['size'] );
        $this->assertSame( 2, $defaults['margin'] );
        $this->assertSame( 'M', $defaults['error_level'] );
    }

    // ──────────────────────────────────────────────────────────────────.
    // clear_cache() — the wp_ffc_submissions.qr_code_cache column write
    // path. Two branches: per-submission (UPDATE WHERE id) and full wipe
    // (UPDATE … WHERE qr_code_cache IS NOT NULL). Both bail when the
    // cache column doesn't exist on the table.
    // ──────────────────────────────────────────────────────────────────.

    public function test_clear_cache_returns_false_when_cache_column_missing(): void {
        // DatabaseHelperTrait::column_exists() runs a SHOW COLUMNS query.
        // Default get_results stub returns [], so the column is treated
        // as missing and clear_cache() short-circuits to false without
        // attempting any UPDATE.
        global $wpdb;
        $wpdb->shouldNotReceive( 'update' );
        $wpdb->shouldNotReceive( 'query' );

        $this->assertFalse( $this->generator->clear_cache( 42 ) );
        $this->assertFalse( $this->generator->clear_cache( 0 ) );
    }

    public function test_clear_cache_single_submission_uses_update_by_id(): void {
        global $wpdb;
        // Override the default get_results so the column-existence check
        // returns a row (column present).
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );

        $captured = array();
        $wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturnUsing(
                function ( $table, $data, $where ) use ( &$captured ) {
                    $captured = compact( 'table', 'data', 'where' );
                    return 1;
                }
            );

        $this->assertTrue( $this->generator->clear_cache( 42 ) );
        $this->assertSame( 'wp_ffc_submissions', $captured['table'] );
        $this->assertNull( $captured['data']['qr_code_cache'], 'qr_code_cache must be NULLed' );
        $this->assertSame( 42, $captured['where']['id'] );
    }

    public function test_clear_cache_all_submissions_runs_bulk_update_query(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );

        // The bulk wipe path composes its UPDATE via wpdb->prepare;
        // capture the template at that boundary to verify the shape.
        $sql_captured = null;
        $wpdb->shouldReceive( 'prepare' )
            ->andReturnUsing(
                function ( $sql ) use ( &$sql_captured ) {
                    $sql_captured = $sql;
                    return $sql;
                }
            );
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 7 );

        $this->assertTrue( $this->generator->clear_cache( 0 ) );
        $this->assertStringContainsString( 'SET qr_code_cache = NULL', (string) $sql_captured );
        $this->assertStringContainsString( 'IS NOT NULL', (string) $sql_captured );
    }

    public function test_clear_cache_returns_false_when_no_rows_affected(): void {
        // Even with the column present, if nothing was cached the bulk
        // query reports 0 affected rows — surface that as false so the
        // admin maintenance tool can say "nothing to clear".
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

        $this->assertFalse( $this->generator->clear_cache( 0 ) );
    }

    // ──────────────────────────────────────────────────────────────────.
    // get_cache_stats() — read-only summary used by Settings → Cache.
    // ──────────────────────────────────────────────────────────────────.

    public function test_get_cache_stats_returns_disabled_shape_when_column_missing(): void {
        // Default get_results → empty → column missing.
        $stats = $this->generator->get_cache_stats();

        $this->assertFalse( $stats['enabled'] );
        $this->assertSame( 0, $stats['total_submissions'] );
        $this->assertSame( 0, $stats['cached_qr_codes'] );
        $this->assertSame( '0 KB', $stats['cache_size'] );
        $this->assertSame( 'N/A', $stats['cache_dir'] );
    }

    public function test_get_cache_stats_counts_total_and_cached_when_column_present(): void {
        // Mirror test_cache_enabled_when_setting_is_1: the class-internal
        // get_option() resolves through the namespaced stub, not the
        // global one.
        Functions\when( 'get_option' )->justReturn(
            array( 'qr_cache_enabled' => 1 )
        );

        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );

        // Two consecutive get_var() calls: total then cached.
        $count = 0;
        $wpdb->shouldReceive( 'get_var' )
            ->andReturnUsing(
                function () use ( &$count ) {
                    return ( ++$count === 1 ) ? '120' : '50';
                }
            );

        $stats = $this->generator->get_cache_stats();

        $this->assertTrue( $stats['enabled'] );
        $this->assertSame( 120, $stats['total_submissions'] );
        $this->assertSame( 50, $stats['cached_qr_codes'] );
        $this->assertIsString( $stats['cache_size'] );
        $this->assertStringContainsString( 'qr_code_cache', $stats['cache_dir'] );
    }

    // ──────────────────────────────────────────────────────────────────.
    // generate_magic_link_qr() — the helper PdfGenerator delegates to
    // when the certificate template carries the magic-link QR variant.
    // Only the two early-return branches are unit-testable here; the
    // happy path drives \QRcode::png and is exercised by integration
    // tests.
    // ──────────────────────────────────────────────────────────────────.

    public function test_generate_magic_link_qr_returns_empty_when_submission_not_found(): void {
        // wpdb->get_row default is null → "no such submission".
        $this->assertSame( '', $this->generator->generate_magic_link_qr( 999 ) );
    }

    public function test_generate_magic_link_qr_returns_empty_when_magic_token_blank(): void {
        global $wpdb;
        // Submission exists but its magic_token column is empty — same
        // contract: no token, no link, no QR.
        $wpdb->shouldReceive( 'get_row' )->andReturn( array( 'magic_token' => '' ) );

        $this->assertSame( '', $this->generator->generate_magic_link_qr( 7 ) );
    }

    // ──────────────────────────────────────────────────────────────────.
    // get_from_cache() / save_to_cache() — the per-submission cache
    // read/write the parse_and_generate() path uses when a submission_id
    // is supplied and caching is on. Private; driven via reflection.
    // ──────────────────────────────────────────────────────────────────.

    public function test_get_from_cache_returns_false_when_column_missing(): void {
        // Default get_results → [] → column treated as absent.
        $this->assertFalse( $this->invokePrivate( 'get_from_cache', array( 5 ) ) );
    }

    public function test_get_from_cache_returns_cached_value_when_present(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 'CACHEDB64' );

        $this->assertSame( 'CACHEDB64', $this->invokePrivate( 'get_from_cache', array( 5 ) ) );
    }

    public function test_get_from_cache_returns_false_when_value_empty(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'get_var' )->andReturn( '' );

        $this->assertFalse( $this->invokePrivate( 'get_from_cache', array( 5 ) ) );
    }

    public function test_save_to_cache_updates_when_column_present(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );

        $captured = array();
        $wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturnUsing(
                function ( $table, $data, $where ) use ( &$captured ) {
                    $captured = compact( 'table', 'data', 'where' );
                    return 1;
                }
            );

        $this->assertTrue( $this->invokePrivate( 'save_to_cache', array( 42, 'NEWB64' ) ) );
        $this->assertSame( 'wp_ffc_submissions', $captured['table'] );
        $this->assertSame( 'NEWB64', $captured['data']['qr_code_cache'] );
        $this->assertSame( 42, $captured['where']['id'] );
    }

    public function test_save_to_cache_returns_false_on_update_failure(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'update' )->andReturn( false );

        $this->assertFalse( $this->invokePrivate( 'save_to_cache', array( 42, 'B64' ) ) );
    }

    // ──────────────────────────────────────────────────────────────────.
    // parse_and_generate() — the cache-hit short-circuit and the
    // cache-write-after-generate paths (submission_id + caching on).
    // ──────────────────────────────────────────────────────────────────.

    public function test_parse_and_generate_serves_from_cache_when_available(): void {
        Functions\when( 'get_option' )->justReturn(
            array( 'qr_cache_enabled' => 1 )
        );
        global $wpdb;
        // Column present + cached value → cache-hit branch (no \QRcode render).
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 'CACHEDB64' );

        $html = $this->generator->parse_and_generate( '{{qr_code}}', 'https://example.com', 5 );

        $this->assertStringContainsString( 'CACHEDB64', $html );
        $this->assertStringContainsString( '<img', $html );
    }

    public function test_parse_and_generate_caches_after_generation_on_miss(): void {
        Functions\when( 'get_option' )->justReturn(
            array( 'qr_cache_enabled' => 1 )
        );
        global $wpdb;
        // Column present, but nothing cached yet → generate then save_to_cache.
        $wpdb->shouldReceive( 'get_results' )
            ->andReturn( array( (object) array( 'Field' => 'qr_code_cache' ) ) );
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );
        $wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

        $html = $this->generator->parse_and_generate( '{{qr_code}}', 'https://example.com', 7 );

        $this->assertStringContainsString( '<img', $html );
    }
}
