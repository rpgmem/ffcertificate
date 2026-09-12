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
		$html = BadgeHtml::render( 'ffc-badge', 'ffc-badge-success', 'OK' );

		$this->assertStringContainsString( 'class="ffc-pill ffc-badge ffc-badge-success"', $html );
	}

	/**
	 * The whole point of #1193's second half: a status badge carries classes and
	 * nothing else. An inline declaration would outrank the generated rule that
	 * {@see RecruitmentBadgePalette} appends to the module stylesheet.
	 */
	public function test_status_badge_emits_no_style_attribute(): void {
		$html = BadgeHtml::render( 'ffc-badge', 'ffc-badge-success', 'OK' );

		$this->assertStringNotContainsString( 'style=', $html );
	}

	/**
	 * The shape is a class, not a `style` declaration — an inline padding or
	 * radius would silently outrank `.ffc-pill` on every screen.
	 */
	public function test_shape_is_not_emitted_inline(): void {
		$html = BadgeHtml::render( 'b', 'v', 'L' );

		foreach ( array( 'padding:', 'border-radius:', 'font-size:', 'font-weight:', 'display:', 'cursor:' ) as $property ) {
			$this->assertStringNotContainsString( $property, $html );
		}
	}

	/**
	 * An empty fragment must not leave a double space in `class`.
	 */
	public function test_empty_variant_leaves_no_stray_space(): void {
		$html = BadgeHtml::render( 'ffc-recruitment-adjutancy-badge', '', 'Adj' );

		$this->assertStringContainsString( 'class="ffc-pill ffc-recruitment-adjutancy-badge"', $html );
	}

	public function test_emits_label_text(): void {
		$html = BadgeHtml::render( 'b', 'v', 'My Label' );

		$this->assertStringContainsString( '>My Label<', $html );
	}

	public function test_no_tooltip_adds_no_title_and_no_tip_class(): void {
		$html = BadgeHtml::render( 'b', 'v', 'L' );

		$this->assertStringNotContainsString( 'title=', $html );
		$this->assertStringNotContainsString( 'ffc-pill-has-tip', $html );
	}

	/**
	 * `cursor:help` was inline; having a tooltip is a property of the markup, so
	 * it became a class the stylesheet answers.
	 */
	public function test_tooltip_adds_title_attribute_and_tip_class(): void {
		$html = BadgeHtml::render( 'b', 'v', 'L', 'why' );

		$this->assertStringContainsString( 'title="why"', $html );
		$this->assertStringContainsString( 'ffc-pill-has-tip', $html );
	}

	// ==================================================================
	// Cor por linha do banco
	// ==================================================================

	/**
	 * The adjutancy badge is the one colour a generated rule cannot serve: it
	 * is per database row. The value rides the attribute as a custom property;
	 * the rule reading it stays in `ffc-common.css`.
	 */
	public function test_row_color_travels_as_custom_properties(): void {
		$html = BadgeHtml::render_with_row_color( 'ffc-recruitment-adjutancy-badge', '#4a90d9', 'Adj' );

		$this->assertStringContainsString( 'ffc-pill-row-color', $html );
		$this->assertStringContainsString( '--ffc-badge-row-bg:#4a90d9', $html );
		$this->assertStringContainsString( '--ffc-badge-row-text:', $html );
		$this->assertStringNotContainsString( 'background:', $html );
	}

	/**
	 * The value reaches a `<style>`-adjacent position, so a non-hex row value
	 * must not travel verbatim — it falls back instead.
	 */
	public function test_row_color_refuses_a_value_that_is_not_hex(): void {
		$html = BadgeHtml::render_with_row_color( 'b', 'red; } body { display:none', 'L' );

		$this->assertStringNotContainsString( 'display:none', $html );
		$this->assertStringContainsString( '--ffc-badge-row-bg:#e9ecef', $html );
	}

}
