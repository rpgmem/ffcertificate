<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\BrandingTokens;

/**
 * Tests for the {{logo_gov}} / {{logo_org}} branding token resolver (#865 Phase 2).
 *
 * @covers \FreeFormCertificate\Core\BrandingTokens
 */
class BrandingTokensTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Core\\BrandingTokens' );
		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function placeholder(): string {
		return FFC_PLUGIN_URL . 'assets/img/certificate-defaults/logo-placeholder.svg';
	}

	public function test_returns_configured_urls(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'logo_gov' => 'https://cdn.example/gov.png',
				'logo_org' => 'https://cdn.example/org.png',
			)
		);

		$this->assertSame( 'https://cdn.example/gov.png', BrandingTokens::logo_gov_url() );
		$this->assertSame( 'https://cdn.example/org.png', BrandingTokens::logo_org_url() );
	}

	public function test_falls_back_to_placeholder_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( $this->placeholder(), BrandingTokens::logo_gov_url() );
		$this->assertSame( $this->placeholder(), BrandingTokens::logo_org_url() );
	}

	public function test_falls_back_when_value_is_blank(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'logo_gov' => '   ',
				'logo_org' => '',
			)
		);

		$this->assertSame( $this->placeholder(), BrandingTokens::logo_gov_url() );
		$this->assertSame( $this->placeholder(), BrandingTokens::logo_org_url() );
	}
}
