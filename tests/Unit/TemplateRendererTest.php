<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\TemplateRenderer;

/**
 * @covers \FreeFormCertificate\Generators\TemplateRenderer
 */
class TemplateRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Generators\TemplateRenderer' );
		\class_exists( '\FreeFormCertificate\Core\TokenResolver' );
		\class_exists( '\FreeFormCertificate\Generators\ValidationUrlPlaceholders' );
		\class_exists( '\FreeFormCertificate\Generators\MagicLinkHelper' );

		Functions\when( 'site_url' )->alias( fn( $p = '' ) => 'https://ex/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'untrailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) );
		Functions\when( 'trailingslashit' )->alias( fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'home_url' )->alias( fn( $p = '' ) => 'https://ex' . (string) $p );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_email_runs_tokens_then_validation_dsl(): void {
		$out = TemplateRenderer::email(
			'Hi {{name}} — {{validation_url link:m>"Download"}}',
			array( '{{name}}' => 'Ana' ),
			array( 'magic_token' => 'tok1' )
		);

		$this->assertStringContainsString( 'Hi Ana', $out );
		$this->assertStringContainsString( '#token=tok1', $out );
		$this->assertStringContainsString( 'Download', $out );
		$this->assertStringNotContainsString( '{{name}}', $out );
		$this->assertStringNotContainsString( '{{validation_url', $out );
	}

	public function test_email_without_dsl_just_substitutes_tokens(): void {
		$out = TemplateRenderer::email( '<p>{{greeting}}</p>', array( '{{greeting}}' => 'Ola' ), array() );
		$this->assertSame( '<p>Ola</p>', $out );
	}
}
