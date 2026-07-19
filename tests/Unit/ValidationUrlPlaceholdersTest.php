<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\ValidationUrlPlaceholders;

/**
 * Tests for the shared {{validation_url ...}} DSL processor (#649), extracted
 * from PdfHtmlRenderer so PDF and email share one implementation.
 *
 * @covers \FreeFormCertificate\Generators\ValidationUrlPlaceholders
 */
class ValidationUrlPlaceholdersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Generators\ValidationUrlPlaceholders' );
		\class_exists( '\FreeFormCertificate\Generators\MagicLinkHelper' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// parse() — pure string parsing (no WP deps)
	// ==================================================================

	public function test_parse_empty_returns_defaults(): void {
		$r = ValidationUrlPlaceholders::parse( '' );
		$this->assertSame( 'm', $r['to'] );
		$this->assertSame( 'v', $r['text'] );
		$this->assertSame( '', $r['target'] );
		$this->assertSame( '', $r['color'] );
	}

	public function test_parse_standard_forms(): void {
		$this->assertSame( array( 'to' => 'm', 'text' => 'v' ), $this->slice( ValidationUrlPlaceholders::parse( 'link:m>v' ) ) );
		$this->assertSame( array( 'to' => 'v', 'text' => 'm' ), $this->slice( ValidationUrlPlaceholders::parse( 'link:v>m' ) ) );
		$this->assertSame( array( 'to' => 'm', 'text' => 'm' ), $this->slice( ValidationUrlPlaceholders::parse( 'link:m>m' ) ) );
		$this->assertSame( array( 'to' => 'v', 'text' => 'v' ), $this->slice( ValidationUrlPlaceholders::parse( 'link:v>v' ) ) );
	}

	public function test_parse_custom_text_single_word(): void {
		$r = ValidationUrlPlaceholders::parse( 'link:m>"Verify"' );
		$this->assertSame( 'm', $r['to'] );
		$this->assertSame( 'Verify', $r['text'] );
	}

	/**
	 * The #649 fix: custom text containing spaces must survive tokenization.
	 * The old naive `preg_split('/\s+/')` split this apart and dropped it.
	 */
	public function test_parse_custom_text_with_spaces(): void {
		$r = ValidationUrlPlaceholders::parse( 'link:m>"⬇️ Download document (PDF)"' );
		$this->assertSame( 'm', $r['to'] );
		$this->assertSame( '⬇️ Download document (PDF)', $r['text'] );
	}

	public function test_parse_custom_text_with_spaces_plus_color(): void {
		$r = ValidationUrlPlaceholders::parse( 'link:m>"Download document (PDF)" color:#ffffff' );
		$this->assertSame( 'm', $r['to'] );
		$this->assertSame( 'Download document (PDF)', $r['text'] );
		$this->assertSame( '#ffffff', $r['color'] );
	}

	public function test_parse_target_and_color(): void {
		$this->assertSame( '_blank', ValidationUrlPlaceholders::parse( 'target:_blank' )['target'] );
		$this->assertSame( 'blue', ValidationUrlPlaceholders::parse( 'color:blue' )['color'] );
		$this->assertSame( '#2271b1', ValidationUrlPlaceholders::parse( 'color:#2271b1' )['color'] );
	}

	public function test_parse_combined_and_ignores_unknown(): void {
		$r = ValidationUrlPlaceholders::parse( 'unknown:value link:v>m target:_blank color:red' );
		$this->assertSame( 'v', $r['to'] );
		$this->assertSame( 'm', $r['text'] );
		$this->assertSame( '_blank', $r['target'] );
		$this->assertSame( 'red', $r['color'] );
	}

	// ==================================================================
	// process() — full replacement
	// ==================================================================

	public function test_process_default_uses_magic_href_and_valid_text(): void {
		$this->stub_urls();
		$out = ValidationUrlPlaceholders::process( 'X {{validation_url}} Y', array( 'magic_token' => 'tok123' ) );

		$this->assertStringContainsString( 'href="https://example.com/valid/#token=tok123"', $out );
		$this->assertStringContainsString( '>https://example.com/valid<', $out ); // text = /valid
		$this->assertStringContainsString( 'class="ffc-validation-link"', $out );
	}

	public function test_process_custom_text_with_spaces_download_button(): void {
		$this->stub_urls();
		$out = ValidationUrlPlaceholders::process(
			'{{validation_url link:m>"⬇️ Download document (PDF)" color:#ffffff}}',
			array( 'magic_token' => 'tok123' )
		);

		$this->assertStringContainsString( 'href="https://example.com/valid/#token=tok123"', $out );
		$this->assertStringContainsString( '⬇️ Download document (PDF)', $out );
		$this->assertStringContainsString( 'style="color: #ffffff;"', $out );
	}

	public function test_process_valid_link_form(): void {
		$this->stub_urls();
		$out = ValidationUrlPlaceholders::process( '{{validation_url link:v>v}}', array( 'magic_token' => 'tok123' ) );

		$this->assertStringContainsString( 'href="https://example.com/valid"', $out );
		$this->assertStringNotContainsString( '#token=', $out );
	}

	public function test_process_no_placeholder_is_noop(): void {
		$this->stub_urls();
		$this->assertSame( 'nothing here', ValidationUrlPlaceholders::process( 'nothing here', array() ) );
	}

	public function test_process_without_token_falls_back_to_valid(): void {
		$this->stub_urls();
		$out = ValidationUrlPlaceholders::process( '{{validation_url}}', array() );

		// No token → magic falls back to /valid.
		$this->assertStringContainsString( 'href="https://example.com/valid"', $out );
		$this->assertStringNotContainsString( '#token=', $out );
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/** @param array{to: string, text: string, target: string, color: string} $r */
	private function slice( array $r ): array {
		return array( 'to' => $r['to'], 'text' => $r['text'] );
	}

	private function stub_urls(): void {
		Functions\when( 'site_url' )->alias( fn( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'untrailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) );
		Functions\when( 'trailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'home_url' )->alias( fn( $p = '' ) => 'https://example.com' . (string) $p );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}
}
