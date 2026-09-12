<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\BadgeHtml;

/**
 * Tests for the shared inline-styled badge helper.
 *
 * Covers the public render() contract: classes are emitted, the supplied
 * background color flows through, the label is HTML-escaped, the tooltip
 * branch flips cursor:help and adds title="", and the no-tooltip branch
 * keeps cursor:default.
 *
 * @covers \FreeFormCertificate\Core\BadgeHtml
 */
class BadgeHtmlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_emits_shape_family_and_variant_classes(): void {
		$html = BadgeHtml::render( 'ffc-badge', 'ffc-badge-success', '#d4edda', 'OK' );

		$this->assertStringContainsString( 'class="ffc-pill ffc-badge ffc-badge-success"', $html );
	}

	/**
	 * The shape is a class, not a `style` declaration — that is the whole point
	 * of #1193, and an inline padding/radius would silently outrank `.ffc-pill`
	 * on every screen.
	 */
	public function test_shape_is_not_emitted_inline(): void {
		$html = BadgeHtml::render( 'b', 'v', '#fff', 'L' );

		$this->assertStringNotContainsString( 'padding:', $html );
		$this->assertStringNotContainsString( 'border-radius:', $html );
		$this->assertStringNotContainsString( 'font-size:', $html );
		$this->assertStringNotContainsString( 'font-weight:', $html );
		$this->assertStringNotContainsString( 'display:', $html );
	}

	/**
	 * An empty fragment must not leave a double space in `class`, which is how
	 * the adjutancy badge renders: family only, no variant.
	 */
	public function test_empty_variant_leaves_no_stray_space(): void {
		$html = BadgeHtml::render( 'ffc-recruitment-adjutancy-badge', '', '#fff', 'Adj' );

		$this->assertStringContainsString( 'class="ffc-pill ffc-recruitment-adjutancy-badge"', $html );
	}

	public function test_emits_supplied_background_color(): void {
		$html = BadgeHtml::render( 'b', 'v', '#abcdef', 'Label' );

		$this->assertStringContainsString( 'background:#abcdef', $html );
	}

	public function test_emits_label_text(): void {
		$html = BadgeHtml::render( 'b', 'v', '#fff', 'My Label' );

		$this->assertStringContainsString( '>My Label<', $html );
	}

	public function test_no_tooltip_uses_cursor_default(): void {
		$html = BadgeHtml::render( 'b', 'v', '#fff', 'L' );

		$this->assertStringContainsString( 'cursor:default', $html );
		$this->assertStringNotContainsString( 'title=', $html );
	}

	public function test_tooltip_uses_cursor_help_and_title_attribute(): void {
		$html = BadgeHtml::render( 'b', 'v', '#fff', 'L', 'Hover me' );

		$this->assertStringContainsString( 'cursor:help', $html );
		$this->assertStringContainsString( 'title="Hover me"', $html );
	}
}
