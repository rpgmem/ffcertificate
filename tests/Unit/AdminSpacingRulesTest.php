<?php
/**
 * Three admin spacing rules that WordPress or the markup undid in silence (#2511).
 *
 * Each was correct in its own rule and lost to something outside it, so the
 * rule alone reads fine and only a render showed the defect:
 *
 * - `#poststuff h2` (core, every post editor) beats `.ffc-vtabs__panel-title`
 *   on specificity and zeroed the space under the panel's line.
 * - `.card:last-child { margin-bottom: 0 }` also matched a card that closes
 *   its own `<form>`, so a stack of one-card forms had no gap between cards.
 * - `align-items: end` on the field grid pushed the Label input down whenever
 *   the tag beside it carried a note.
 *
 * It reads the sheets as text: it proves the rules are there, not how they
 * render. The render was measured in #2511.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class AdminSpacingRulesTest extends TestCase {

	/**
	 * The declarations of the rule whose selector list holds `$selector`.
	 *
	 * @param string $sheet    File under assets/css.
	 * @param string $selector One entry of the selector list.
	 * @return string The rule body.
	 */
	private function body_of( string $sheet, string $selector ): string {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $sheet );
		$this->assertNotSame( '', $css, $sheet . ' could not be read' );
		foreach ( CssSelectors::rules( $css ) as $rule ) {
			if ( in_array( $selector, CssSelectors::split_list( $rule['selector'] ), true ) ) {
				return $rule['body'];
			}
		}
		$this->fail( 'No rule for ' . $selector . ' in ' . $sheet );
	}

	public function test_the_panel_title_keeps_its_space_inside_a_meta_box(): void {
		$body = $this->body_of( 'ffc-admin-components.css', '#poststuff .ffc-vtabs__panel-title' );

		$this->assertStringContainsString( 'margin: var(--ffc-spacing-2xs) 0 var(--ffc-spacing-xl)', $body );
		$this->assertStringContainsString( 'padding-bottom:', $body );
		$this->assertStringContainsString( 'font-size:', $body );
	}

	public function test_a_card_closing_a_form_keeps_its_gap_when_another_form_follows(): void {
		$body = $this->body_of( 'ffc-admin-components.css', '.ffc-settings-wrap form:not(:last-child) > .card:last-child' );

		$this->assertStringContainsString( 'margin-bottom: var(--ffc-spacing-2xl)', $body );
		$this->assertSame(
			$body,
			$this->body_of( 'ffc-admin-components.css', '.ffc-boxed form:not(:last-child) > .card:last-child' ),
			'Both box layouts keep the gap'
		);
	}

	public function test_the_field_grid_aligns_its_items_to_the_top(): void {
		$this->assertStringContainsString( 'align-items: start', $this->body_of( 'ffc-admin.css', '.ffc-field-row-grid' ) );
		$this->assertStringContainsString( 'margin-top:', $this->body_of( 'ffc-admin.css', '.ffc-field-row-grid > .ffc-flex-center' ) );
	}
}
