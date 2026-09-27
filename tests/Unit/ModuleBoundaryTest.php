<?php
/**
 * Module-boundary guard (#563 / #591 B3).
 *
 * Captures the current cross-module dependency graph of `includes/` as a
 * committed baseline and fails when a NEW edge (sourceModule → targetModule)
 * appears — so cross-module coupling can only shrink, never grow, without a
 * deliberate, reviewed baseline update. This is the "enforce module boundaries"
 * step of B3: no per-module facade is required yet, but the graph is frozen so
 * new violations are caught in CI and the boundaries can be tightened
 * incrementally.
 *
 * A "module" is the first namespace segment after `FreeFormCertificate\`
 * (root-level classes are `Root`). An edge is recorded when a file declared in
 * module A references `FreeFormCertificate\B\...` with B !== A.
 *
 * A REFERENCE THAT BINDS IS COUPLING; ONE THAT MERELY POINTS IS NOT (#1496).
 *
 * This read the file as raw text, so PROSE CREATED AN EDGE. A comment in
 * `IdentityRepair` quoting the PHP error it had measured -- `Class
 * "FreeFormCertificate\Settings\SettingsReader" not found` -- invented a
 * `Maintenance>Settings` edge that no statement in that module makes, and this
 * guard failed on it.
 *
 * The obvious fix, building the graph from non-comment tokens, would have made
 * the guard WEAKER, and measuring caught it in time. `PdfGenerator` declares
 * `object $submission_handler` natively and narrows it in the docblock, so
 * `@phpstan-param \FreeFormCertificate\Submissions\SubmissionHandler` is the
 * only statement of a dependency PHPStan actually enforces: move that class and
 * the analysis breaks. In a codebase whose convention is loose native
 * signatures plus precise phpdoc (`CLAUDE.md` -> "PHPStan/phpdoc idioms"), THE
 * DOCBLOCK IS PART OF THE TYPE SYSTEM. Stripping comments would have deleted a
 * real edge from the graph.
 *
 * So a comment line counts only when it carries a TYPE-BEARING tag. Measured
 * over `includes/` when this was written, and the arithmetic closes against the
 * baseline it replaced: 131 = 127 from code + 1 from a docblock type + 3 from
 * `{@see}` pointers. The three that left --- `Core>Privacy`,
 * `Core>UserDashboard`, `UserDashboard>Migrations` --- were decouplings that had
 * already happened and were never locked in, which is the SILENT half of this
 * defect and the worse one: the loud half announces itself by failing, while a
 * lingering `{@see}` keeps a dead edge alive and the ratchet never tightens.
 *
 * Where the classification is uncertain it ERRS TOWARDS KEEPING the edge -- a
 * `{@see}` sitting on the same line as an `@param` description counts. That
 * direction is deliberate: an edge kept in error is visible in the baseline and
 * costs a reviewer a question, while an edge dropped in error is exactly the
 * silent failure above.
 *
 * SELF-CHECK: none is added, because one is already here and stronger than
 * anything added would be. The register is non-empty and frozen, so `assertSame`
 * against it detects its own collapse -- a scan that reads nothing, or half,
 * loses baselined entries and the `removed` direction fires (`CLAUDE.md` ->
 * "Quality gates and testing", self-check shape 1; a floor would be the NEVER
 * there). The one gap that needed closing is update mode, which writes whatever
 * it computed: it now refuses to write an empty graph.
 *
 * Regenerate the baseline after an INTENTIONAL change:
 *   FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary
 * Review the diff — new edges mean new coupling (justify it); removed edges
 * mean coupling was eliminated (good — lock it in).
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ModuleBoundaryTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/module-boundary-baseline.php';

	/**
	 * Compute the current set of cross-module edges.
	 *
	 * @return array<string, true> Set keyed by "Source>Target".
	 */
	public static function compute_edges(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$edges = array();

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}
			if ( strpos( $path, '/libraries/' ) !== false ) {
				continue;
			}
			$text = file_get_contents( $path );
			if ( false === $text ) {
				continue;
			}
			// Declared module = first namespace segment (Root when bare).
			if ( ! preg_match( '/namespace\s+FreeFormCertificate(?:\\\\([A-Za-z0-9_]+))?(?:\\\\[A-Za-z0-9_]+)*\s*;/', $text, $m ) ) {
				continue;
			}
			$src = empty( $m[1] ) ? 'Root' : $m[1];

			// Referenced modules = first segment after FreeFormCertificate\, read
			// from the code plus the docblock tags that state a type.
			foreach ( self::referenced_modules( $text ) as $tgt ) {
				if ( $tgt === $src ) {
					continue;
				}
				$edges[ $src . '>' . $tgt ] = true;
			}
		}

		ksort( $edges );
		return $edges;
	}

	/**
	 * Tags whose value is a type an analyser resolves, so naming a class there
	 * BINDS this file to it. `{@see}`, and prose generally, do not.
	 *
	 * `@throws` is in the list because an unresolvable exception class is a
	 * PHPStan error like any other. The `phpstan-` and `psalm-` prefixes are
	 * matched rather than enumerated, because a new one of those is always a
	 * type tag and a list would go stale in silence.
	 */
	private const TYPE_TAG = '/@(?:(?:phpstan-|psalm-)?(?:param|var|return|throws)\b|(?:phpstan|psalm)-[a-z-]+)/';

	/**
	 * The modules a file references, reading code and types but not pointers.
	 *
	 * The two halves are gathered into blobs and matched once each, rather than
	 * per token, so a name the tokeniser happens to split across tokens is still
	 * seen. On 8.3/8.4 a namespaced name is one token, but that is a property of
	 * the PHP version and not of this guard, and the whole-blob match costs
	 * nothing to keep.
	 *
	 * @param string $text The file's source.
	 * @return array<int, string> Distinct module names, in no meaningful order.
	 */
	private static function referenced_modules( string $text ): array {
		$code = '';
		$type = '';

		foreach ( token_get_all( $text ) as $token ) {
			if ( ! is_array( $token ) ) {
				// A single-character token: punctuation, which carries no name.
				continue;
			}

			if ( ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= $token[1] . "\n";
				continue;
			}

			// One doc comment is a single token spanning many lines, so the tag
			// and the class it types have to be paired up line by line.
			foreach ( preg_split( '/\R/', $token[1] ) as $line ) {
				if ( preg_match( self::TYPE_TAG, $line ) ) {
					$type .= $line . "\n";
				}
			}
		}

		$found = array();

		foreach ( array( $code, $type ) as $blob ) {
			if ( preg_match_all( '/FreeFormCertificate\\\\([A-Za-z0-9_]+)\\\\/', $blob, $mm ) ) {
				$found = array_merge( $found, $mm[1] );
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * The cross-module dependency graph must not grow new edges.
	 */
	public function test_no_new_cross_module_coupling(): void {
		$current = self::compute_edges();

		// Update mode: rewrite the baseline from the current graph.
		if ( getenv( 'FFC_UPDATE_BOUNDARY_BASELINE' ) ) {
			// An empty graph means the scan broke, not that the coupling went
			// away. Every other direction of this guard detects its own collapse
			// through the frozen register; update mode is the one that would
			// happily write the collapse down as the new truth.
			$this->assertNotEmpty(
				$current,
				'Refusing to write an empty baseline: the scan found no cross-module edge at all, '
				. 'which means it failed to read includes/ rather than that the coupling is gone.'
			);

			$keys = array_keys( $current );
			sort( $keys );
			$body  = "<?php\n/**\n * Module-boundary baseline (#563/#591 B3) — generated.\n"
				. " * Regenerate: FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary\n"
				. " * Each entry is a \"SourceModule>TargetModule\" cross-module edge that\n"
				. " * currently exists and is therefore allowed. The guard fails on any edge\n"
				. " * NOT listed here (new coupling) or any listed edge that no longer exists\n"
				. " * (coupling removed — tighten the baseline).\n */\n\nreturn array(\n";
			foreach ( $keys as $k ) {
				$body .= "\t'" . $k . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Boundary baseline regenerated (' . count( $keys ) . ' edges).' );
		}

		$this->assertFileExists( self::BASELINE_FILE, 'Run with FFC_UPDATE_BOUNDARY_BASELINE=1 to generate the baseline.' );
		/** @var list<string> $baseline_list */
		$baseline_list = require self::BASELINE_FILE;
		$baseline      = array_fill_keys( $baseline_list, true );

		$new_edges = array_values( array_diff( array_keys( $current ), array_keys( $baseline ) ) );
		$removed   = array_values( array_diff( array_keys( $baseline ), array_keys( $current ) ) );

		$this->assertSame(
			array(),
			$new_edges,
			"New cross-module coupling introduced (not in the B3 baseline):\n  "
			. implode( "\n  ", $new_edges )
			. "\nIf this coupling is intentional, regenerate the baseline:\n"
			. '  FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary'
		);

		$this->assertSame(
			array(),
			$removed,
			"Cross-module coupling was removed — tighten the B3 baseline to lock it in:\n  "
			. implode( "\n  ", $removed )
			. "\nRegenerate: FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary"
		);
	}
	/**
	 * A class named only by a pointer is documentation, not coupling.
	 *
	 * This is the shape that produced #1496: the guard read the file as text, so
	 * a `{@see}` and a quoted error message both registered as dependencies.
	 */
	public function test_a_pointer_in_a_comment_is_not_an_edge(): void {
		$source = <<<'PHP'
		<?php
		namespace FreeFormCertificate\Maintenance;

		/**
		 * Healing runs beside {@see \FreeFormCertificate\Privacy\PrivacyHandler}.
		 *
		 * Measured: a standalone script dies with
		 * `Class "FreeFormCertificate\Settings\SettingsReader" not found`.
		 */
		class Thing {
			// See also \FreeFormCertificate\Audience\AudienceReader for the same shape.
		}
		PHP;

		$this->assertSame(
			array(),
			self::referenced_modules( $source ),
			'A pointer, a quoted error and a trailing note are prose: none of them binds this file '
			. 'to another module, so none of them may create an edge.'
		);
	}

	/**
	 * A class named by a type tag IS coupling, wherever it is written.
	 *
	 * Without this case the cheapest way to make the guard green is to ignore
	 * comments outright, which silently deletes the `Generators>Submissions`
	 * edge -- a dependency PHPStan enforces and the native signature does not
	 * state. So this canary is not symmetry: it is what stops the wrong fix.
	 */
	public function test_a_type_tag_in_a_docblock_is_an_edge(): void {
		$source = <<<'PHP'
		<?php
		namespace FreeFormCertificate\Generators;

		class Thing {
			/**
			 * @param  object $handler The handler.
			 * @phpstan-param \FreeFormCertificate\Submissions\SubmissionHandler $handler
			 * @return \FreeFormCertificate\Core\Result|null
			 */
			public function run( object $handler ) {
				return null;
			}
		}
		PHP;

		$found = self::referenced_modules( $source );
		sort( $found );

		$this->assertSame(
			array( 'Core', 'Submissions' ),
			$found,
			'A docblock type is the only statement of the dependency when the native signature is '
			. 'looser, so it must count -- both on a phpstan- tag and on a plain @return.'
		);
	}

}
