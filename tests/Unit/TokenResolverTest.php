<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\TokenResolver;

/**
 * @covers \FreeFormCertificate\Core\TokenResolver
 */
class TokenResolverTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\TokenResolver' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_resolves_tokens(): void {
		$out = TokenResolver::resolve(
			'Hi {{name}}, code {{code}}',
			array( '{{name}}' => 'Ana', '{{code}}' => 'ABC' )
		);
		$this->assertSame( 'Hi Ana, code ABC', $out );
	}

	public function test_empty_map_returns_template_unchanged(): void {
		$this->assertSame( '{{name}}', TokenResolver::resolve( '{{name}}', array() ) );
	}

	public function test_is_single_pass_a_value_containing_a_token_is_not_resubstituted(): void {
		// {{a}} -> "{{b}}" must NOT then become the value of {{b}} (single pass).
		$out = TokenResolver::resolve(
			'{{a}} {{b}}',
			array( '{{a}}' => '{{b}}', '{{b}}' => 'X' )
		);
		$this->assertSame( '{{b}} X', $out );
	}

	public function test_leaves_unknown_tokens_literal(): void {
		$this->assertSame( 'keep {{x}}', TokenResolver::resolve( 'keep {{x}}', array( '{{y}}' => 'Y' ) ) );
	}
}
