<?php
/**
 * Request-input cast guard (#1087 passo 2).
 *
 * Freezes the population of integer casts applied *directly* to a superglobal
 * — `absint( $_POST['x'] )`, `intval( $_GET['x'] )`, `(int) $_REQUEST['x'] ` —
 * against a committed baseline, as a ratchet that can only shrink.
 *
 * **Why this class of read is worth freezing.** `absint()` and `(int)` on an
 * array are `1` for a non-empty one and `0` for an empty one, with no notice
 * either way. `1` is a plausible id, a plausible count and a plausible ceiling,
 * so the wrong value looks exactly like a right one: that is how a slot
 * duration became one minute (#1075), how a corrupted cursor skipped row 1
 * (#1079), and how a POSTed array flattened every rate-limit ceiling to 1
 * (#1087 passo 1). The fix is always the same — read through
 * {@see \FreeFormCertificate\Core\RequestInput::get_post_int()} or
 * `get_get_int()`, which guard the scalar and then call `absint()`.
 *
 * **No allowlist, and that is measured rather than assumed.** Every site in
 * the baseline uses `absint()`; there is no `intval()` and no `(int)` cast over
 * a superglobal in `includes/`. Since both accessors are themselves built on
 * `absint()`, they are a drop-in for all of them — there is no signed-integer
 * case needing an exception. Should one ever appear, add the accessor it needs
 * rather than an exception here.
 *
 * **Two things this guard does NOT do.** It sees only the *cast-adjacent*
 * form: `$raw = $_POST['x']; … absint( $raw );` is invisible to it, because
 * following the value would need an AST and this file deliberately stays a
 * text scan like every other guard in this suite. And it sees **presence,
 * never correctness** — a baselined site may be perfectly safe today; what the
 * ratchet guarantees is that the population does not grow.
 *
 * Regenerate the baseline after an INTENTIONAL change:
 *   FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast
 * Review the diff — a new entry means a new unguarded cast (route it through
 * the accessor); a removed entry means one was fixed (lock the win in).
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RequestInputCastTest extends TestCase {

	private const BASELINE_FILE = __DIR__ . '/../fixtures/request-input-casts-baseline.php';

	/**
	 * The accessors' own home: it is where the guarded cast is supposed to live.
	 */
	private const ACCESSOR_FILE = 'core/class-ffc-request-input.php';

	/**
	 * Collect every direct integer cast over a superglobal under `includes/`.
	 *
	 * Entries read `path.php::key#n` — `n` is the occurrence ordinal for that
	 * (file, key) pair, so a second identical read in the same file is its own
	 * entry and the ratchet notices it. The ordinal is stable when lines move;
	 * only adding or removing a read changes the set.
	 *
	 * @return list<string> Sorted entries.
	 */
	public static function collect_casts(): array {
		$root    = dirname( __DIR__, 2 ) . '/includes';
		$entries = array();
		$seen    = array();

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
			$relative = ltrim( str_replace( $root, '', $path ), '/' );
			if ( self::ACCESSOR_FILE === $relative ) {
				continue;
			}
			$text = file_get_contents( $path );
			if ( false === $text ) {
				continue;
			}

			// `absint( … )` / `intval( … )` and the `(int)` cast, each of them
			// optionally wrapping a `wp_unslash()`, applied to a superglobal.
			$pattern = '/(?:\b(?:absint|intval)\s*\(\s*|\(\s*int\s*\)\s*)'
				. '(?:wp_unslash\s*\(\s*)?'
				. '\$_(POST|GET|REQUEST)\s*\[\s*([^\]]*?)\s*\]/';

			if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$superglobal = $match[1];
				$key_expr    = $match[2];

				// A quoted literal is reported by name; anything computed is
				// reported as `$dynamic`, because the concatenation itself is
				// not a stable identifier.
				$key = preg_match( "/^'([^']*)'$/", $key_expr, $literal )
					? $literal[1]
					: '$dynamic';

				$base                = $relative . '::' . $superglobal . '[' . $key . ']';
				$seen[ $base ]       = ( $seen[ $base ] ?? 0 ) + 1;
				$entries[]           = $base . '#' . $seen[ $base ];
			}
		}

		sort( $entries );
		return $entries;
	}

	/**
	 * The population of unguarded integer casts must never grow.
	 */
	public function test_direct_integer_casts_over_superglobals_only_shrink(): void {
		$current = self::collect_casts();

		if ( getenv( 'FFC_UPDATE_REQUEST_CAST_BASELINE' ) ) {
			$body = "<?php\n/**\n * Request-input cast baseline (#1087 passo 2) — generated.\n"
				. " * Regenerate: FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast\n"
				. " *\n"
				. " * Each entry is a direct integer cast over a superglobal that exists today\n"
				. " * and is therefore tolerated. The guard fails on any cast NOT listed here\n"
				. " * (a new one — route it through RequestInput::get_post_int()/get_get_int())\n"
				. " * and on any listed cast that no longer exists (one was fixed — lock it in).\n"
				. " *\n"
				. " * This is a debt register, not a target to grow.\n */\n\nreturn array(\n";
			foreach ( $current as $entry ) {
				$body .= "\t'" . $entry . "',\n";
			}
			$body .= ");\n";
			file_put_contents( self::BASELINE_FILE, $body );
			$this->markTestSkipped( 'Request-input cast baseline regenerated (' . count( $current ) . ' entries).' );
		}

		$this->assertFileExists(
			self::BASELINE_FILE,
			'Run with FFC_UPDATE_REQUEST_CAST_BASELINE=1 to generate the baseline.'
		);

		/** @var list<string> $baseline */
		$baseline = require self::BASELINE_FILE;

		$added   = array_values( array_diff( $current, $baseline ) );
		$removed = array_values( array_diff( $baseline, $current ) );

		$this->assertSame(
			array(),
			$added,
			"New direct integer cast over a superglobal:\n  "
			. implode( "\n  ", $added )
			. "\n\nRead it through RequestInput::get_post_int() / get_get_int() instead — they"
			. " guard the scalar, so a posted array reads as the default rather than as the"
			. " number 1 (#1087).\nIf the cast is genuinely justified, regenerate the baseline"
			. " and say why in the PR:\n"
			. '  FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast'
		);

		$this->assertSame(
			array(),
			$removed,
			"A direct integer cast was removed — tighten the baseline to lock the win in:\n  "
			. implode( "\n  ", $removed )
			. "\nRegenerate: FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast"
		);
	}

	/**
	 * The scan itself must keep working — an empty result would pass the ratchet
	 * silently on the day the pattern stops matching, which is the same class of
	 * defect as a gate that reports success on a missing report (#1071).
	 */
	public function test_the_scan_still_finds_the_known_population(): void {
		$this->assertNotEmpty(
			self::collect_casts(),
			'The scan found nothing at all — the pattern or the path is broken, not the codebase fixed.'
		);
	}
}
