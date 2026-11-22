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
 * **No allowlist, and that is measured rather than assumed.** Every site the
 * baseline held used `absint()`; there was no `intval()` and no `(int)` cast
 * over a superglobal in `includes/`, and #2557 routed all 43 through the
 * accessors, so the baseline is empty and the guard blocks at zero. Since both accessors are themselves built on
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
		return self::scan()['entries'];
	}

	/**
	 * The files the scan read, relative to `includes/`.
	 *
	 * @return list<string> Sorted.
	 */
	private static function scanned_files(): array {
		return self::scan()['files'];
	}

	/**
	 * One walk over `includes/`.
	 *
	 * @return array{entries: list<string>, files: list<string>}
	 */
	private static function scan(): array {
		$root    = dirname( __DIR__, 2 ) . '/includes';
		$entries = array();
		$files   = array();
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
			$files[] = $relative;

			foreach ( self::casts_in( $relative, $text, $seen ) as $entry ) {
				$entries[] = $entry;
			}
		}

		sort( $entries );
		sort( $files );
		return array(
			'entries' => $entries,
			'files'   => $files,
		);
	}

	/**
	 * The casts one file's source carries.
	 *
	 * A method of its own so the self-check can run it over synthetic source:
	 * the baseline is empty since #2557, and a pattern that stopped matching
	 * would pass the ratchet in silence.
	 *
	 * @param string             $relative Path under `includes/`.
	 * @param string             $text     Source.
	 * @param array<string, int> $seen     Occurrences so far, per (file, key).
	 * @return list<string> Entries, in source order.
	 */
	public static function casts_in( string $relative, string $text, array &$seen ): array {
		// Prose that QUOTES the shape is not the shape. The scan read the file
		// as plain text, so a comment explaining why a given read does NOT use
		// `absint()` on the superglobal was counted as a dynamic-key cast --
		// failing the very PR that wrote the comment (#1212 spent a CI run on
		// it). It is the same distinction CLAUDE.md already pins for
		// suppression annotations: only the token that OPENS the comment
		// counts, not one that appears inside it.
		//
		// `token_get_all()` rather than a regex: recognising a comment with a
		// regular expression requires knowing when `//` is inside a string,
		// and PHP's lexer already knows.
		$text = self::strip_comments( $text );

		// `absint( … )` / `intval( … )` and the `(int)` cast, each of them
		// optionally wrapping a `wp_unslash()`, applied to a superglobal.
		$pattern = '/(?:\b(?:absint|intval)\s*\(\s*|\(\s*int\s*\)\s*)'
			. '(?:wp_unslash\s*\(\s*)?'
			. '\$_(POST|GET|REQUEST)\s*\[\s*([^\]]*?)\s*\]/';

		if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches as $match ) {
			// A quoted literal is reported by name; anything computed is
			// reported as `$dynamic`, because the concatenation itself is
			// not a stable identifier.
			$key = preg_match( "/^'([^']*)'$/", $match[2], $literal )
				? $literal[1]
				: '$dynamic';

			$base          = $relative . '::' . $match[1] . '[' . $key . ']';
			$seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;
			$out[]         = $base . '#' . $seen[ $base ];
		}

		return $out;
	}

	/**
	 * Blank out comments, keeping everything else byte-for-byte.
	 *
	 * It substitutes a space, not nothing: gluing the two sides could join
	 * tokens the comment kept apart.
	 */
	private static function strip_comments( string $code ): string {
		$out = '';
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) ) {
				$out .= ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) ? ' ' : $token[1];
				continue;
			}
			$out .= $token;
		}

		return $out;
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
	 * The scan itself must keep working.
	 *
	 * The baseline is empty since #2557, so an empty result is the expected
	 * one and cannot prove the scan ran — the same class of defect as a gate
	 * that reports success on a missing report (#1071). Two checks stand in
	 * for it: every shape the pattern reads is found in synthetic source, and
	 * a second traversal, repeating the filter, lists the files the walk read.
	 */
	public function test_the_scan_still_finds_the_known_population(): void {
		$seen  = array();
		$cases = array(
			"absint( \$_POST['a'] )"                => 'x.php::POST[a]#1',
			"absint( wp_unslash( \$_GET['b'] ) )"   => 'x.php::GET[b]#1',
			"intval( \$_REQUEST['c'] )"             => 'x.php::REQUEST[c]#1',
			"(int) \$_POST['a']"                    => 'x.php::POST[a]#2',
			"absint( \$_GET[ 'p' . \$i ] )"        => 'x.php::GET[$dynamic]#1',
			"// absint( \$_POST['quoted'] ) in prose" => null,
		);
		foreach ( $cases as $source => $entry ) {
			$this->assertSame(
				null === $entry ? array() : array( $entry ),
				self::casts_in( 'x.php', "<?php\n" . $source . ";\n", $seen ),
				'The scan no longer reads: ' . $source
			);
		}

		$root    = dirname( __DIR__, 2 ) . '/includes';
		$recount = array();
		$walk    = static function ( string $dir ) use ( &$walk, &$recount, $root ): void {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_dir( $path ) ) {
					if ( 'libraries' !== $name ) {
						$walk( $path );
					}
				} elseif ( '.php' === substr( $name, -4 ) ) {
					$recount[] = substr( $path, strlen( $root ) + 1 );
				}
			}
		};
		$walk( $root );
		$recount = array_values( array_diff( $recount, array( self::ACCESSOR_FILE ) ) );
		sort( $recount );

		$this->assertSame( $recount, self::scanned_files(), 'The scan does not walk the files a second traversal finds.' );
	}
}
