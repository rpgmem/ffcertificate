<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\MagicLinkHelper;

/**
 * Tests for MagicLinkHelper: token validation, URL generation, link extraction.
 */
class MagicLinkHelperTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://example.com' . $path;
        } );
        Functions\when( 'trailingslashit' )->alias( function ( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );

        // Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
        // MagicLinkHelper is in Generators namespace.
        Functions\when( 'FreeFormCertificate\Generators\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
        Functions\when( 'FreeFormCertificate\Generators\wp_parse_url' )->alias( function ( $url, $component = -1 ) {
            return parse_url( $url, $component );
        } );
        Functions\when( 'FreeFormCertificate\Generators\home_url' )->alias( function ( $path = '' ) {
            return \home_url( $path );
        } );
        Functions\when( 'FreeFormCertificate\Generators\trailingslashit' )->alias( function ( $url ) {
            return rtrim( $url, '/' ) . '/';
        } );
        Functions\when( 'FreeFormCertificate\Generators\esc_url' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_html' )->returnArg();
        Functions\when( 'FreeFormCertificate\Generators\esc_attr' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // is_valid_token()
    // ==================================================================

    public function test_valid_32_char_hex_token(): void {
        $this->assertTrue( MagicLinkHelper::is_valid_token( 'abcdef1234567890abcdef1234567890' ) );
    }

    public function test_valid_64_char_hex_token(): void {
        $token = str_repeat( 'abcdef12', 8 );
        $this->assertTrue( MagicLinkHelper::is_valid_token( $token ) );
    }

    public function test_valid_token_uppercase_hex(): void {
        $this->assertTrue( MagicLinkHelper::is_valid_token( 'ABCDEF1234567890ABCDEF1234567890' ) );
    }

    public function test_invalid_token_31_chars(): void {
        $this->assertFalse( MagicLinkHelper::is_valid_token( str_repeat( 'a', 31 ) ) );
    }

    public function test_invalid_token_33_chars(): void {
        $this->assertFalse( MagicLinkHelper::is_valid_token( str_repeat( 'a', 33 ) ) );
    }

    public function test_invalid_token_non_hex(): void {
        $this->assertFalse( MagicLinkHelper::is_valid_token( str_repeat( 'g', 32 ) ) );
    }

    public function test_invalid_token_empty(): void {
        $this->assertFalse( MagicLinkHelper::is_valid_token( '' ) );
    }

    // ==================================================================
    // generate_magic_link()
    // ==================================================================

    public function test_generate_magic_link_builds_url(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::generate_magic_link( $token );
        $this->assertStringContainsString( '#token=' . $token, $url );
        $this->assertStringContainsString( 'https://example.com/valid/', $url );
    }

    public function test_generate_magic_link_empty_token_returns_empty(): void {
        $this->assertSame( '', MagicLinkHelper::generate_magic_link( '' ) );
    }

    // ==================================================================
    // extract_token_from_url()
    // ==================================================================

    public function test_extract_token_from_query_ffc_magic(): void {
        $url = 'https://example.com/valid/?ffc_magic=abc123';
        $this->assertSame( 'abc123', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    public function test_extract_token_from_query_token(): void {
        $url = 'https://example.com/valid/?token=abc123';
        $this->assertSame( 'abc123', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    public function test_extract_token_from_hash_fragment(): void {
        $url = 'https://example.com/valid/#token=abc123';
        $this->assertSame( 'abc123', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    public function test_extract_token_ffc_magic_takes_priority(): void {
        $url = 'https://example.com/valid/?ffc_magic=first&token=second';
        $this->assertSame( 'first', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    public function test_extract_token_no_token_returns_empty(): void {
        $url = 'https://example.com/valid/?other=param';
        $this->assertSame( '', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    public function test_extract_token_no_query_no_fragment_returns_empty(): void {
        $url = 'https://example.com/valid/';
        $this->assertSame( '', MagicLinkHelper::extract_token_from_url( $url ) );
    }

    // ==================================================================
    // get_magic_link_html()
    // ==================================================================

    public function test_html_with_token_contains_link(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $html = MagicLinkHelper::get_magic_link_html( $token );
        $this->assertStringContainsString( '<a href=', $html );
        $this->assertStringContainsString( $token, $html );
        $this->assertStringContainsString( 'ffc-magic-link', $html );
    }

    public function test_html_with_copy_button(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $html = MagicLinkHelper::get_magic_link_html( $token, true );
        $this->assertStringContainsString( 'ffc-copy-magic-link', $html );
        $this->assertStringContainsString( 'Copy', $html );
    }

    public function test_html_without_copy_button(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $html = MagicLinkHelper::get_magic_link_html( $token, false );
        $this->assertStringNotContainsString( 'ffc-copy-magic-link', $html );
    }

    public function test_html_empty_token_returns_no_token_message(): void {
        $html = MagicLinkHelper::get_magic_link_html( '' );
        $this->assertStringContainsString( 'No magic token', $html );
        $this->assertStringContainsString( '<em>', $html );
    }

    // ==================================================================
    // get_magic_link_qr_code()
    // ==================================================================

    public function test_qr_code_returns_google_charts_url(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::get_magic_link_qr_code( $token );
        $this->assertStringContainsString( 'chart.googleapis.com', $url );
        $this->assertStringContainsString( '200x200', $url );
        $this->assertStringContainsString( 'cht=qr', $url );
    }

    public function test_qr_code_custom_size(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::get_magic_link_qr_code( $token, 400 );
        $this->assertStringContainsString( '400x400', $url );
    }

    public function test_qr_code_empty_token_returns_empty(): void {
        $this->assertSame( '', MagicLinkHelper::get_magic_link_qr_code( '' ) );
    }

    // ==================================================================
    // debug_info()
    // ==================================================================

    public function test_debug_info_returns_expected_keys(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $info = MagicLinkHelper::debug_info( $token );
        $this->assertArrayHasKey( 'token', $info );
        $this->assertArrayHasKey( 'token_valid', $info );
        $this->assertArrayHasKey( 'token_length', $info );
        $this->assertArrayHasKey( 'verification_url', $info );
        $this->assertArrayHasKey( 'magic_link', $info );
        $this->assertArrayHasKey( 'qr_code_url', $info );
    }

    public function test_debug_info_valid_token_flag(): void {
        $valid = 'abcdef1234567890abcdef1234567890';
        $invalid = 'short';
        $this->assertTrue( MagicLinkHelper::debug_info( $valid )['token_valid'] );
        $this->assertFalse( MagicLinkHelper::debug_info( $invalid )['token_valid'] );
    }

    public function test_debug_info_token_length(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $this->assertSame( 32, MagicLinkHelper::debug_info( $token )['token_length'] );
    }

    // ==================================================================
    // ensure_token()
    // ==================================================================

    public function test_ensure_token_null_handler_returns_empty(): void {
        $this->assertSame( '', MagicLinkHelper::ensure_token( 1, (object) array() ) );
    }

    public function test_ensure_token_with_valid_handler(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'ensure_magic_token' )
            ->with( 42 )
            ->andReturn( $token );
        $result = MagicLinkHelper::ensure_token( 42, $handler );
        $this->assertSame( $token, $result );
    }

    public function test_ensure_token_invalid_from_handler_generates_new(): void {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'ensure_magic_token' )
            ->with( 42 )
            ->andReturn( 'invalid' );
        $result = MagicLinkHelper::ensure_token( 42, $handler );
        // Should generate a new valid token
        $this->assertTrue( MagicLinkHelper::is_valid_token( $result ) );
    }

    // ==================================================================
    // get_magic_link_from_submission()
    // ==================================================================

    public function test_from_submission_with_magic_token(): void {
        $submission = array( 'id' => 1, 'magic_token' => 'abcdef1234567890abcdef1234567890' );
        $url = MagicLinkHelper::get_magic_link_from_submission( $submission );
        $this->assertStringContainsString( 'abcdef1234567890abcdef1234567890', $url );
    }

    public function test_from_submission_no_token_no_handler_returns_empty(): void {
        $submission = array( 'id' => 1 );
        $url = MagicLinkHelper::get_magic_link_from_submission( $submission );
        $this->assertSame( '', $url );
    }

    // ==================================================================
    // get_verification_page_url()
    // ==================================================================

    public function test_verification_url_fallback(): void {
        // get_option returns 0 (set in setUp), so fallback to home_url
        $url = MagicLinkHelper::get_verification_page_url();
        $this->assertSame( 'https://example.com/valid/', $url );
    }

    public function test_verification_url_contains_valid_path(): void {
        // Fallback URL always ends with /valid/ when no page is configured
        $url = MagicLinkHelper::get_verification_page_url();
        $this->assertStringEndsWith( '/valid/', $url );
    }

    // ==================================================================
    // URL format consistency (standardization)
    // ==================================================================

    public function test_magic_link_uses_hash_fragment_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::generate_magic_link( $token );
        // Canonical format: /valid/#token=xxx (hash fragment, NOT query string)
        $this->assertStringContainsString( '/#token=', $url );
        $this->assertStringNotContainsString( '?token=', $url );
    }

    public function test_magic_link_has_trailing_slash_before_hash(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::generate_magic_link( $token );
        // Must have trailing slash before hash: /valid/#token= (not /valid#token=)
        $this->assertMatchesRegularExpression( '#/valid/\#token=#', $url );
    }

    public function test_magic_link_exact_canonical_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::generate_magic_link( $token );
        $this->assertSame( 'https://example.com/valid/#token=' . $token, $url );
    }

    // ==================================================================
    // Round-trip: generate → extract
    // ==================================================================

    public function test_round_trip_generate_then_extract_returns_original_token(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $url = MagicLinkHelper::generate_magic_link( $token );
        $extracted = MagicLinkHelper::extract_token_from_url( $url );
        $this->assertSame( $token, $extracted );
    }

    public function test_round_trip_64_char_token(): void {
        $token = str_repeat( 'abcdef12', 8 ); // 64 chars
        $url = MagicLinkHelper::generate_magic_link( $token );
        $extracted = MagicLinkHelper::extract_token_from_url( $url );
        $this->assertSame( $token, $extracted );
    }

    // ==================================================================
    // QR code encodes canonical magic link
    // ==================================================================

    public function test_qr_code_url_encodes_canonical_magic_link(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $magic_link = MagicLinkHelper::generate_magic_link( $token );
        $qr_url = MagicLinkHelper::get_magic_link_qr_code( $token );
        // QR code URL should contain the URL-encoded magic link
        $this->assertStringContainsString( urlencode( $magic_link ), $qr_url );
    }

    public function test_qr_code_encodes_hash_fragment_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $qr_url = MagicLinkHelper::get_magic_link_qr_code( $token );
        // The encoded URL must contain the hash fragment format
        $this->assertStringContainsString( urlencode( '#token=' . $token ), $qr_url );
    }

    // ==================================================================
    // get_magic_link_from_submission() — additional edge cases
    // ==================================================================

    public function test_from_submission_generates_canonical_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $submission = array( 'id' => 1, 'magic_token' => $token );
        $url = MagicLinkHelper::get_magic_link_from_submission( $submission );
        // Must use the same canonical format
        $this->assertSame( 'https://example.com/valid/#token=' . $token, $url );
    }

    public function test_from_submission_with_handler_generates_canonical_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'ensure_magic_token' )
            ->with( 42 )
            ->andReturn( $token );
        $submission = array( 'id' => 42 );
        $url = MagicLinkHelper::get_magic_link_from_submission( $submission, $handler );
        $this->assertSame( 'https://example.com/valid/#token=' . $token, $url );
    }

    // ==================================================================
    // get_submission_magic_link() — canonical format
    // ==================================================================

    public function test_submission_magic_link_uses_canonical_format(): void {
        $token = 'abcdef1234567890abcdef1234567890';
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'ensure_magic_token' )
            ->with( 42 )
            ->andReturn( $token );
        $url = MagicLinkHelper::get_submission_magic_link( 42, $handler );
        $this->assertSame( 'https://example.com/valid/#token=' . $token, $url );
    }
}
