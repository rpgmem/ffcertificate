<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * The identity card's action column is one width, not one per tier (#1468).
 *
 * The column is the same component on every card, and it was sized by
 * `flex: 0 1 auto` -- its own MAX-CONTENT. The two tiers that reach that rule
 * do not have the same content: the shared-mailbox move form carries an
 * acknowledgement, which pushes its button onto a second line, so its widest
 * line is the field plus Search while the decision tier's is the field plus
 * Search plus Move. Rendered at 1600px and again at 1920: 719px against 614px,
 * 105px apart and stable across viewport widths.
 *
 * Two grey columns of different widths on one screen, decided by whether a
 * checkbox happens to be in the form.
 *
 * **This guard cannot see widths and never will** -- what a rule renders is a
 * property of the DOM and the viewport, and measuring it needs the browser
 * harness that produced the numbers above. What it can hold is the cause: the
 * column's size must be DECLARED rather than derived from whatever each tier
 * happens to contain. A basis in `em` is that declaration; `auto`, `content`,
 * `max-content` and `fit-content` are the family that reintroduces the defect.
 *
 * @coversNothing Its subject is a stylesheet, not a class — and coverage is
 * scoped to `./includes`, so naming a `@covers` target here would attribute to
 * nothing while reading as though the test covered something.
 */
class IdentityCardColumnWidthTest extends TestCase {

	/**
	 * The rule that turns the action column into a row of two verb groups.
	 *
	 * @var string
	 */
	private const SELECTOR = '.ffc-page-identities .ffc-identity-card-act:has( .ffc-identity-card-verb + .ffc-identity-card-verb )';

	/**
	 * Sizings that resolve against the column's own content.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_DERIVED = array( 'auto', 'content', 'max-content', 'min-content', 'fit-content' );

	/**
	 * The sheet, read once.
	 *
	 * @return string
	 */
	private function sheet(): string {
		return (string) file_get_contents( __DIR__ . '/../../assets/css/ffc-admin.css' );
	}

	/**
	 * The rule's body, as the shared parser reads it.
	 *
	 * @return string
	 */
	private function body(): string {
		foreach ( CssSelectors::rules( $this->sheet() ) as $rule ) {
			if ( self::SELECTOR === trim( (string) $rule['selector'] ) ) {
				return (string) $rule['body'];
			}
		}

		return '';
	}

	/**
	 * The self-check: the rule this guard is about still exists.
	 *
	 * Named rather than counted, and it has to be: a scan that stopped finding
	 * the selector would report the assertion below as satisfied over an empty
	 * string, which is the failure mode every guard here is written against.
	 * If the selector is legitimately renamed, re-point `SELECTOR` -- that is
	 * maintenance this guard owes, not a defect to design away.
	 */
	public function test_the_rule_it_guards_is_still_in_the_sheet(): void {
		$this->assertNotSame(
			'',
			$this->body(),
			'The two-group action-column rule is gone or renamed, so nothing below is being checked.'
		);
	}

	/**
	 * The column declares its width instead of taking its content's.
	 */
	public function test_the_action_column_does_not_size_itself_from_its_own_content(): void {
		$body = $this->body();

		$this->assertMatchesRegularExpression(
			'/\bflex:\s*\d+\s+\d+\s+[\d.]+(em|rem|px|%)\s*;/',
			$body,
			'The action column must declare a flex basis: with a content-derived one it is as wide as whichever tier it is rendering, and the two differ by a checkbox.'
		);

		foreach ( self::CONTENT_DERIVED as $keyword ) {
			$this->assertDoesNotMatchRegularExpression(
				sprintf( '/\bflex(-basis)?:[^;]*\b%s\b/', preg_quote( $keyword, '/' ) ),
				$body,
				sprintf( 'The column sizes itself from its content again, through `%s`.', $keyword )
			);
		}
	}
}
