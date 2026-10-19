<?php
/**
 * Guard: a redaction mask is written in one place (#1920).
 *
 * `Core\DocumentFormatter` owns every masked shape -- CPF, RF, CIN, email,
 * phone, name, birth date, IP and the generic `a***` -- and every caller asks
 * it by kind or by field key. Before #1920 the same job was done in several
 * places that disagreed: an IP masked as `a.b.c.xxx` beside the shared
 * `a.b.***.***`, a birth date hard-coded as `**`/`**`/`****` in a REST
 * controller, an RF placeholder (`****-*`) matching nothing else, and five
 * callers compensating for masks that handed their input back in clear.
 *
 * So this refuses the two shapes a copy takes: a string literal carrying two
 * consecutive asterisks, and a `str_repeat( '*', … )`, anywhere in `includes/`,
 * `templates/` or a non-minified script, outside the allowed files. A comment
 * is not code, so the PHP side reads tokens and the JS side strips comments
 * first.
 *
 * It sees presence, never truth: a mask built from single asterisks joined at
 * run time passes. What it guarantees is that the obvious way to write a
 * second mask fails CI, which is how the last ones were born.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\InlineStyles;
use FreeFormCertificate\Tests\Support\JsIdSelectors;
use FreeFormCertificate\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class MaskLiteralTest extends TestCase {

	private const PHP_ROOTS = array( 'includes', 'templates' );

	/**
	 * Files allowed to write a mask, each with its reason.
	 */
	private const ALLOWED = array(
		'includes/core/class-ffc-document-formatter.php' => 'the one home of every redaction mask',
		'includes/core/class-ffc-debug.php'              => 'masks values written to the debug log, with their length appended -- a log format, not a display mask',
		'includes/settings/views/documentation/reference-privacy.php' => 'the privacy topic quotes each masked shape to the reader',
		'assets/js/ffc-encryption-key-suggest.js'        => 'writes a PHP docblock into the wp-config snippet it suggests -- comment syntax, not a mask',
	);

	public function test_no_mask_is_written_outside_its_home(): void {
		$found = array();
		foreach ( $this->php_files() as $file ) {
			foreach ( self::php_hits( (string) file_get_contents( self::root() . '/' . $file ) ) as $hit ) {
				$found[] = $file . ': ' . $hit;
			}
		}
		foreach ( InlineStyles::js_files() as $file ) {
			foreach ( self::js_hits( (string) file_get_contents( self::root() . '/' . $file ) ) as $hit ) {
				$found[] = $file . ': ' . $hit;
			}
		}

		$outside = array_values(
			array_filter(
				$found,
				static fn ( string $line ): bool => ! isset( self::ALLOWED[ strstr( $line, ':', true ) ] )
			)
		);

		$this->assertSame(
			array(),
			$outside,
			"A mask belongs in DocumentFormatter: ask it by kind (`mask( DocumentFormatter::MASK_* , \$value )`), by key (`mask_any()`) or for a placeholder (`mask_placeholder()`)."
		);
	}

	public function test_every_allowed_file_still_writes_a_mask(): void {
		foreach ( array_keys( self::ALLOWED ) as $file ) {
			$source = (string) file_get_contents( self::root() . '/' . $file );
			$this->assertNotSame(
				array(),
				str_ends_with( $file, '.js' ) ? self::js_hits( $source ) : self::php_hits( $source ),
				$file . ' is allowed to write a mask and no longer does: drop it from ALLOWED.'
			);
		}
	}

	public function test_the_scan_reads_every_php_file_a_second_traversal_finds(): void {
		$second = array();
		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( InlineStyles::glob_recursive( self::root() . '/' . $root, '*.php' ) as $path ) {
				$second[] = str_replace( self::root() . '/', '', $path );
			}
		}
		sort( $second );

		$this->assertSame( $second, $this->php_files() );
		$this->assertContains( 'includes/core/class-ffc-document-formatter.php', $this->php_files() );
	}

	public function test_the_patterns_find_what_they_claim_and_skip_comments(): void {
		$this->assertCount( 1, self::php_hits( "<?php \$x = '***.***-*';" ) );
		$this->assertCount( 1, self::php_hits( "<?php \$x = \"a**{\$y}\";" ) );
		$this->assertCount( 1, self::php_hits( "<?php \$x = str_repeat( '*', 3 );" ) );
		$this->assertCount( 1, self::php_hits( '<p>**/**/****</p>' ) );
		$this->assertCount( 0, self::php_hits( "<?php /** Docblock ** */ // a ** b\n\$x = '*';" ) );

		$this->assertCount( 1, self::js_hits( "var m = '***@' + d;" ) );
		$this->assertCount( 0, self::js_hits( "/** a ** b */\n// c ** d\nvar url = 'https://x';" ) );
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * @return list<string>
	 */
	private function php_files(): array {
		$out = array();
		foreach ( self::PHP_ROOTS as $root ) {
			foreach ( PhpSource::files_under( $root ) as $file ) {
				$out[] = $file;
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * Mask literals in PHP source: a string or inline HTML carrying `**`, and
	 * a `str_repeat()` of an asterisk.
	 *
	 * @param string $source PHP source.
	 * @return list<string> One entry per hit, `line N: text`.
	 */
	private static function php_hits( string $source ): array {
		$hits   = array();
		$tokens = token_get_all( $source );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) && str_contains( $token[1], '**' ) ) {
				$hits[] = 'line ' . $token[2] . ': ' . trim( $token[1] );
				continue;
			}
			if ( T_STRING === $token[0] && 'str_repeat' === strtolower( $token[1] ) ) {
				$next = self::next_significant( $tokens, $i, 2 );
				if ( is_array( $next ) && T_CONSTANT_ENCAPSED_STRING === $next[0] && in_array( $next[1], array( "'*'", '"*"' ), true ) ) {
					$hits[] = 'line ' . $token[2] . ': str_repeat( ' . $next[1] . ', ... )';
				}
			}
		}

		return $hits;
	}

	/**
	 * The n-th token after `$i` that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @param int               $i      Index to start after.
	 * @param int               $n      Which significant token to return.
	 * @return mixed
	 */
	private static function next_significant( array $tokens, int $i, int $n ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( 0 === --$n ) {
				return $t;
			}
		}

		return null;
	}

	/**
	 * Mask literals in a script: a quoted string carrying `**`.
	 *
	 * @param string $source Script source.
	 * @return list<string>
	 */
	private static function js_hits( string $source ): array {
		$code = JsIdSelectors::strip_comments( $source );
		preg_match_all( '~("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\])*`)~', $code, $m );

		return array_values(
			array_filter(
				$m[1],
				static fn ( string $literal ): bool => str_contains( $literal, '**' )
			)
		);
	}
}
