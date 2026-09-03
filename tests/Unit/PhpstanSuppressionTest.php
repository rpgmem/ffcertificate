<?php
/**
 * PHPStan-suppression guard (#1027).
 *
 * Two things go wrong with a `@phpstan-ignore` and neither one fails anything
 * on its own, which is why both needed a guard.
 *
 * THE FORM. `@phpstan-ignore-next-line <identifier>` does **not** filter by
 * that identifier — the older `-next-line` variant suppresses *every* error on
 * the line and treats the trailing text as a comment. Only `@phpstan-ignore
 * <identifier>` narrows. The repository used the broad form in all 31 places
 * while naming an identifier at each, so every one of them read as narrow and
 * behaved as a blanket. It was hiding one real error — a `get_results()` that
 * can return null from a method declared `: array` — which surfaced the moment
 * the form was corrected.
 *
 * THE REASON. 17 of those 31 sat under a docblock whose entire prose was the
 * word `Description.`, so nothing recorded what was being suppressed or why it
 * was safe. A suppression without a reason cannot be told apart from one nobody
 * understands.
 *
 * WHY THE DOCBLOCK FORM AT ALL. The bare inline `// @phpstan-ignore … (reason)`
 * is unusable here: WPCS's `InlineComment.InvalidEndChar` demands a closing
 * period, and PHPStan's parser then rejects the `.` after the parenthesis. The
 * docblock is the way out of that conflict, which is why the check below accepts
 * prose from the surrounding docblock rather than requiring an inline reason.
 *
 * WHAT THIS CANNOT SEE. Whether a reason is *true*. It checks that one exists
 * and is not the placeholder; only reading the query tells you the interpolated
 * fragment really is code-chosen. It also cannot tell a still-needed suppression
 * from one a later refactor made dead — that is measured by removing it and
 * re-running PHPStan, which CI does for the whole file at once.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring and settings-default guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PhpstanSuppressionTest extends TestCase {

	/**
	 * Prose that says nothing. Anything matching is treated as absent.
	 *
	 * @var array<int, string>
	 */
	private const PLACEHOLDERS = array( 'description.', 'description', 'todo.', 'todo', 'n/a', '-' );

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every PHP file under includes/.
	 *
	 * @return array<int, string>
	 */
	private static function sources(): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Repository-relative path, for readable failure messages.
	 */
	private static function relative( string $path ): string {
		return ltrim( str_replace( self::root(), '', $path ), '/' );
	}

	public function test_no_suppression_uses_the_non_filtering_next_line_form(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $i => $line ) {
				if ( str_contains( $line, '@phpstan-ignore-next-line' ) ) {
					$offenders[] = self::relative( $file ) . ':' . ( $i + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These use `@phpstan-ignore-next-line`, which suppresses EVERY error on the line —\n"
			. "the identifier written after it is just a comment, so the suppression is far broader\n"
			. "than it reads. Use `@phpstan-ignore <identifier>`, which actually filters:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_every_suppression_names_an_identifier(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $i => $line ) {
				if ( ! str_contains( $line, '@phpstan-ignore' ) || str_contains( $line, '@phpstan-ignore-next-line' ) ) {
					continue; // The broad form is the other test's failure to report.
				}

				// The identifier is the first token after the tag, e.g. `argument.type`.
				if ( 1 !== preg_match( '/@phpstan-ignore\s+[a-zA-Z]+\.[a-zA-Z]+/', $line ) ) {
					$offenders[] = self::relative( $file ) . ':' . ( $i + 1 ) . ' — ' . trim( $line );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A suppression with no identifier silences every error on its line:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_every_suppression_carries_a_reason(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			$lines = explode( "\n", (string) file_get_contents( $file ) );

			foreach ( $lines as $i => $line ) {
				if ( ! str_contains( $line, '@phpstan-ignore' ) || str_contains( $line, '@phpstan-ignore-next-line' ) ) {
					continue; // The broad form is the other test's failure to report.
				}

				if ( '' === self::reason_for( $lines, $i ) ) {
					$offenders[] = self::relative( $file ) . ':' . ( $i + 1 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"These suppressions say what they silence but not why the code is correct.\n"
			. "Write the reason inline after the identifier, or as prose in the docblock the tag\n"
			. "sits in — `Description.` is not a reason:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	/**
	 * The reason attached to the suppression on line `$index`, or '' when there
	 * is none.
	 *
	 * Two shapes count. Inline — anything after the identifier on the same line,
	 * as the three `if.alwaysFalse (dbDelta creates the table)` migrations use.
	 * Or docblock — any prose line in the `/** … *\/` the tag sits inside, which
	 * is the shape forced by the WPCS/PHPStan conflict described above.
	 *
	 * @param array<int, string> $lines Source lines.
	 * @param int                $index Zero-based index of the tag line.
	 */
	private static function reason_for( array $lines, int $index ): string {
		if ( 1 === preg_match( '/@phpstan-ignore\s+[a-zA-Z]+\.[a-zA-Z]+\s+(\S.*)$/', $lines[ $index ], $m ) ) {
			$inline = trim( $m[1], " \t*/" );
			if ( '' !== $inline && ! self::is_placeholder( $inline ) ) {
				return $inline;
			}
		}

		// Walk up out of the docblock the tag is in, collecting its prose.
		$prose = array();
		for ( $i = $index - 1; $i >= 0 && $i > $index - 30; $i-- ) {
			$line = trim( $lines[ $i ] );

			if ( str_starts_with( $line, '/**' ) ) {
				break; // Top of the docblock.
			}
			if ( ! str_starts_with( $line, '*' ) ) {
				break; // Not in a docblock at all.
			}

			$text = trim( ltrim( $line, '*' ) );
			if ( '' !== $text && ! str_starts_with( $text, '@' ) && ! self::is_placeholder( $text ) ) {
				$prose[] = $text;
			}
		}

		return implode( ' ', $prose );
	}

	/**
	 * Whether a line of prose is one of the known non-reasons.
	 */
	private static function is_placeholder( string $text ): bool {
		return in_array( strtolower( trim( $text ) ), self::PLACEHOLDERS, true );
	}

	/**
	 * The extraction is only meaningful if it finds the suppressions at all. A
	 * refactor that changed how they are written would make every check above
	 * pass while inspecting nothing.
	 */
	public function test_the_guard_can_see_the_suppressions(): void {
		$found = 0;

		foreach ( self::sources() as $file ) {
			$found += substr_count( (string) file_get_contents( $file ), '@phpstan-ignore' );
		}

		$this->assertGreaterThan(
			20,
			$found,
			'Found suspiciously few PHPStan suppressions — the tag pattern probably no longer matches.'
		);
	}
}
