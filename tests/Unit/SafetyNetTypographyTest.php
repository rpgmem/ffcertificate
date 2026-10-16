<?php
/**
 * The root text-size safety net in `ffc-common.css` (#1832).
 *
 * `.ffc-shortcode` names the scale's base step, and the text and container
 * tags under it take `font-size: inherit`, so a theme that sizes `body`, `p`,
 * `label` or `input` cannot re-size the plugin's unsized text. A render audit
 * measured what that net is worth: with a theme sizing only `body`, 34 plugin
 * text elements took the theme's size before it and none after.
 *
 * The net only works while three properties hold, and each one fails quietly
 * when it breaks, which is why they are pinned here:
 *
 * 1. **It weighs one class, (0,1,0).** Gaining a class or an id would make it
 *    outrank component rules that size their own text; losing `.ffc-shortcode`
 *    from outside `:where()` would drop it to (0,0,0), under a theme's bare
 *    `p { … }`, and it would catch nothing.
 * 2. **It comes first.** At (0,1,0) a component rule wins by coming later in
 *    the cascade, so the net has to precede every rule in the sheet that
 *    styles an element. Only the palette blocks (`:root …`) may sit above it.
 * 3. **It sets `font-size` and nothing else, and names no heading.** A root
 *    `line-height` was measured re-flowing 1,275 elements with no theme loaded;
 *    a heading under `inherit` loses the hierarchy the user agent gives it.
 *
 * What it does NOT see: whether every component sizes its own text. The net
 * makes an unsized component take the root's size instead of the theme's; it
 * cannot tell a component that should be smaller or larger than that.
 *
 * No dependency: it reads the sheet as text.
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
final class SafetyNetTypographyTest extends TestCase {

	/**
	 * Tags the audit found sized by hostile themes; the net must cover each.
	 *
	 * The containers are here because `inherit` takes the PARENT's size: an
	 * inner `p` that inherits from a `div` the theme sized takes the theme's
	 * size, so the chain from the root down has to be closed.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_TAGS = array(
		'p',
		'li',
		'label',
		'td',
		'th',
		'button',
		'input',
		'select',
		'textarea',
		'span',
		'strong',
		'a',
		'div',
		'ul',
		'ol',
		'table',
	);

	/**
	 * The sheet's rules, in order, with whitespace collapsed.
	 *
	 * @return array<int, array{selector: string, body: string}>
	 */
	private static function rules(): array {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$out = array();
		foreach ( CssSelectors::rules( $css ) as $rule ) {
			$out[] = array(
				'selector' => trim( (string) preg_replace( '/\s+/', ' ', $rule['selector'] ) ),
				'body'     => trim( (string) preg_replace( '/\s+/', ' ', $rule['body'] ) ),
			);
		}

		return $out;
	}

	/**
	 * Index of the first rule that is not a palette block.
	 *
	 * @param array<int, array{selector: string, body: string}> $rules Rules.
	 */
	private static function first_styling_rule( array $rules ): int {
		foreach ( $rules as $i => $rule ) {
			if ( ! str_starts_with( $rule['selector'], ':root' ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * The net's three rules, read from where they must be.
	 *
	 * @return array<int, array{selector: string, body: string}>
	 */
	private static function net(): array {
		$rules = self::rules();
		$first = self::first_styling_rule( $rules );

		return array_slice( $rules, max( 0, $first ), 3 );
	}

	/**
	 * Splits a `:where()` argument list into tag names.
	 *
	 * @param string $selector One selector.
	 * @return array<int, string>|null The tags, or null when the shape is not `.ffc-shortcode :where(tags)`.
	 */
	private static function tags_of( string $selector ): ?array {
		if ( 1 !== preg_match( '/^\.ffc-shortcode :where\(([a-z0-9, ]+)\)$/', $selector, $m ) ) {
			return null;
		}

		return array_map( 'trim', explode( ',', $m[1] ) );
	}

	public function test_the_scan_reads_the_palette_and_the_rules_after_it(): void {
		$rules = self::rules();

		// Self-check: an empty or truncated read must never pass as "the net is first".
		$this->assertGreaterThan( 0, self::first_styling_rule( $rules ), 'The palette blocks were not found above the first rule.' );
		$this->assertGreaterThan(
			self::first_styling_rule( $rules ) + 100,
			count( $rules ),
			'Far fewer rules than ffc-common.css carries were read; the parser lost the sheet.'
		);
	}

	public function test_the_root_names_the_base_step_first(): void {
		$net = self::net();

		$this->assertSame( '.ffc-shortcode', $net[0]['selector'] ?? null, 'The first rule after the palette must be the root.' );
		$this->assertSame( 'font-size: var(--ffc-font-size-base);', $net[0]['body'] ?? null );
	}

	public function test_the_text_tags_inherit_at_one_class_of_weight(): void {
		$net  = self::net();
		$tags = array();

		foreach ( CssSelectors::split_list( $net[1]['selector'] ?? '' ) as $selector ) {
			$found = self::tags_of( trim( $selector ) );
			$this->assertNotNull(
				$found,
				"`{$selector}` is not `.ffc-shortcode :where(<tags>)`: anything outside `:where()` "
					. 'changes the weight, and the net must weigh exactly (0,1,0).'
			);
			$tags = array_merge( $tags, (array) $found );
		}

		$this->assertSame( 'font-size: inherit;', $net[1]['body'] ?? null, 'The net sets the size and nothing else.' );
		$this->assertSame( array(), array_values( array_diff( self::REQUIRED_TAGS, $tags ) ), 'Tags the net must cover.' );
		$this->assertSame(
			array(),
			array_values( array_intersect( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'small' ), $tags ) ),
			'A heading under `inherit` loses its hierarchy, and `small` has its own step.'
		);
	}

	public function test_small_takes_the_small_step(): void {
		$net = self::net();

		$this->assertSame( '.ffc-shortcode :where(small)', $net[2]['selector'] ?? null );
		$this->assertSame( 'font-size: var(--ffc-font-size-sm);', $net[2]['body'] ?? null );
	}

	public function test_no_rule_gives_the_root_a_line_height(): void {
		$offenders = array();
		foreach ( self::rules() as $rule ) {
			foreach ( CssSelectors::split_list( $rule['selector'] ) as $selector ) {
				$selector = trim( $selector );
				if ( ( '.ffc-shortcode' === $selector || null !== self::tags_of( $selector ) )
					&& str_contains( $rule['body'], 'line-height' ) ) {
					$offenders[] = $selector;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'A root line-height re-flows every unsized line on the page; set it on the component whose text wraps.'
		);
	}
}
