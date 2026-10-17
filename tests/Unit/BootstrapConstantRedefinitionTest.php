<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;

/**
 * No test defines a constant that `tests/bootstrap.php` already defines (#1806, #1809).
 *
 * The bootstrap runs before any test, and again inside every separate-process
 * child (PHPUnit's `TestCaseMethod.tpl` requires it when `preserveGlobalState`
 * is off; when it is on, the parent's constants are copied in instead). So a
 * test-level `if ( ! defined( 'X' ) ) define( 'X', … )` for a bootstrap
 * constant can never run. It still reads as if it did: beyond the `ABSPATH`
 * ones #1806 removed, 71 of them sat under
 * `tests/Unit` — `FFC_PLUGIN_DIR` at `/tmp/ffc_test/` or at a path "that does
 * not exist", `FFC_VERSION` at `4.12.0` — each describing a fixture the test
 * never had, while the code under test read the repository root and the
 * shipped version all along.
 *
 * The bootstrap's own list is READ, never copied here, so a constant the
 * bootstrap gains is covered the moment it is added. Files are tokenized, so a
 * `define()` quoted in a comment or a string is not one. A `define()` whose
 * name is not a string literal fails rather than being skipped: a name the
 * scan cannot read is not a name it has checked.
 */
class BootstrapConstantRedefinitionTest extends TestCase {

	private const BOOTSTRAP = 'tests/bootstrap.php';

	/**
	 * The guard: no file under `tests/` redefines a bootstrap constant.
	 */
	public function test_no_test_file_defines_a_constant_the_bootstrap_defines(): void {
		$owned    = array_flip( $this->bootstrap_constants() );
		$findings = array();

		foreach ( $this->scanned_files() as $relative ) {
			$source = (string) file_get_contents( $this->root() . '/' . $relative );
			foreach ( self::define_calls( $source ) as $call ) {
				if ( null === $call['name'] ) {
					$findings[] = $relative . ':' . $call['line'] . ' define() with a name that is not a string literal';
				} elseif ( isset( $owned[ $call['name'] ] ) ) {
					$findings[] = $relative . ':' . $call['line'] . ' ' . $call['name'];
				}
			}
		}

		$this->assertSame(
			array(),
			$findings,
			"These define() calls can never run: tests/bootstrap.php defines the constant first,\n"
			. "in the parent process and in every separate-process child. Delete them; a test that\n"
			. "needs another value has to reach it some other way than a constant.\n"
		);
	}

	/**
	 * Self-check: the token scan of the bootstrap agrees with what executing it defines.
	 *
	 * The recount runs the bootstrap in a fresh PHP process, where every
	 * `if ( ! defined() )` branch fires, and diffs the user constants before
	 * and after. It shares nothing with the token scan, so a scan that loses
	 * a define — or the foreach that names two of them — disagrees here.
	 */
	public function test_the_bootstrap_scan_matches_an_executed_recount(): void {
		$scanned = $this->bootstrap_constants();

		$this->assertNotEmpty( $scanned, 'The scan read no define() in tests/bootstrap.php.' );
		$this->assertContains( 'FFC_PLUGIN_DIR', $scanned );
		$this->assertContains( 'FFC_JSPDF_VERSION', $scanned, 'The foreach-defined constants were not resolved.' );

		$script = '$a = ' . var_export( $this->root() . '/vendor/autoload.php', true ) . ';'
			. 'require_once $a;'
			. '$before = array_keys( get_defined_constants( true )["user"] ?? array() );'
			. 'require ' . var_export( $this->root() . '/' . self::BOOTSTRAP, true ) . ';'
			. '$after = array_keys( get_defined_constants( true )["user"] ?? array() );'
			. 'echo json_encode( array_values( array_diff( $after, $before ) ) );';

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) . ' 2>&1' );
		$this->assertIsString( $output );
		$executed = json_decode( $output, true );
		$this->assertIsArray( $executed, 'The recount produced no JSON: ' . $output );

		sort( $scanned );
		sort( $executed );
		$this->assertSame( $executed, $scanned );
	}

	/**
	 * Self-check: the scanned file list equals a recount by a different traversal.
	 */
	public function test_the_file_scan_matches_an_independent_recount(): void {
		$recount = array();
		$walk    = function ( string $dir ) use ( &$walk, &$recount ): void {
			foreach ( (array) scandir( $dir ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_dir( $path ) ) {
					$walk( $path );
				} elseif ( '.php' === substr( $entry, -4 ) ) {
					$recount[] = substr( $path, strlen( $this->root() ) + 1 );
				}
			}
		};
		$walk( $this->root() . '/tests' );
		$recount = array_values(
			array_filter(
				$recount,
				static fn ( string $f ): bool => self::BOOTSTRAP !== $f && 0 !== strpos( $f, 'tests/Support/' )
			)
		);
		sort( $recount );

		$scanned = $this->scanned_files();

		$this->assertContains( 'tests/Unit/BootstrapConstantRedefinitionTest.php', $scanned );
		$this->assertSame( $recount, $scanned );
	}

	/**
	 * Canary: the detector fires on real calls and only on real calls.
	 */
	public function test_the_detector_reads_calls_not_mentions(): void {
		$source = <<<'PHP'
<?php
// define( 'IN_A_COMMENT', 1 );
/* define( 'IN_A_BLOCK', 1 ); */
$s = "define( 'IN_A_STRING', 1 )";
$o->define( 'A_METHOD', 1 );
Foo::define( 'A_STATIC', 1 );
function define_later() {}
if ( ! defined( 'PLAIN' ) ) {
	define( 'PLAIN', 1 );
}
\define( "QUALIFIED", 2 );
define( $name, 3 );
PHP;

		$this->assertSame(
			array(
				array(
					'name' => 'PLAIN',
					'line' => 9,
				),
				array(
					'name' => 'QUALIFIED',
					'line' => 11,
				),
				array(
					'name'     => null,
					'line'     => 12,
					'variable' => '$name',
				),
			),
			self::define_calls( $source )
		);
	}

	/**
	 * The constants `tests/bootstrap.php` defines, read from its tokens.
	 *
	 * A literal name is read directly. A variable name is resolved through the
	 * `foreach ( array( 'A', 'B' ) as $var )` that binds it; one that cannot be
	 * resolved fails, because the list would silently be short otherwise.
	 *
	 * @return list<string>
	 */
	private function bootstrap_constants(): array {
		$source = (string) file_get_contents( $this->root() . '/' . self::BOOTSTRAP );
		$names  = array();

		foreach ( self::define_calls( $source ) as $call ) {
			if ( null !== $call['name'] ) {
				$names[] = $call['name'];
				continue;
			}
			$resolved = self::foreach_literals( $source, (string) $call['variable'] );
			$this->assertNotEmpty(
				$resolved,
				'tests/bootstrap.php:' . $call['line'] . ' defines a constant this scan cannot resolve; teach it the shape.'
			);
			array_push( $names, ...$resolved );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Every `define()` call in a source: its literal name (or null) and line.
	 *
	 * @param string $source PHP source.
	 * @return list<array{name: ?string, line: int, variable?: string}>
	 */
	private static function define_calls( string $source ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $source ),
				static fn ( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
			)
		);
		$calls  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) ) {
				continue;
			}
			$is_define = ( T_STRING === $t[0] && 'define' === strtolower( $t[1] ) )
				|| ( T_NAME_FULLY_QUALIFIED === $t[0] && '\\define' === strtolower( $t[1] ) );
			if ( ! $is_define || ! isset( $tokens[ $i + 1 ] ) || '(' !== $tokens[ $i + 1 ] ) {
				continue;
			}
			$prev = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue;
			}
			$arg  = $tokens[ $i + 2 ] ?? null;
			$next = $tokens[ $i + 3 ] ?? null;
			$call = array(
				'name' => null,
				'line' => $t[2],
			);
			if ( is_array( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0] && ',' === $next ) {
				$call['name'] = stripslashes( substr( $arg[1], 1, -1 ) );
			} elseif ( is_array( $arg ) && T_VARIABLE === $arg[0] && ',' === $next ) {
				$call['variable'] = $arg[1];
			}
			$calls[] = $call;
		}

		return $calls;
	}

	/**
	 * The string literals of `foreach ( array( … ) as $variable )`.
	 *
	 * @param string $source   PHP source.
	 * @param string $variable The loop variable, `$` included.
	 * @return list<string>
	 */
	private static function foreach_literals( string $source, string $variable ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $source ),
				static fn ( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
			)
		);
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FOREACH !== $tokens[ $i ][0] ) {
				continue;
			}
			$literals = array();
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$literals[] = stripslashes( substr( $t[1], 1, -1 ) );
				}
				if ( is_array( $t ) && T_AS === $t[0] ) {
					$bound = $tokens[ $j + 1 ] ?? null;
					if ( is_array( $bound ) && T_VARIABLE === $bound[0] && $variable === $bound[1] ) {
						return $literals;
					}
					break;
				}
			}
		}

		return array();
	}

	/**
	 * The files the guard reads: every PHP file under `tests/` except the
	 * bootstrap (the owner) and `tests/Support/` (helpers, not tests).
	 *
	 * @return list<string>
	 */
	private function scanned_files(): array {
		return array_values(
			array_filter(
				PhpSource::files_under( 'tests' ),
				static fn ( string $f ): bool => self::BOOTSTRAP !== $f && 0 !== strpos( $f, 'tests/Support/' )
			)
		);
	}

	private function root(): string {
		return dirname( __DIR__, 2 );
	}
}
