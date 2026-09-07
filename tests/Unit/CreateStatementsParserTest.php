<?php
/**
 * Tests for `.github/scripts/ffc-create-statements.php` (#1087 passo 7).
 *
 * The parser is the denominator of two gates: `ActivatorSqlTest` checks the
 * statements as text, and the dbDelta idempotence step replays them against a
 * real MariaDB. Neither can be more correct than this file — a statement it
 * cannot see is exempt from both, in silence.
 *
 * That is not a hypothetical worry in this repo. #1087 spent three steps on it:
 * the row ruler measured 45 of 53 classes while printing that every class was
 * covered, and four more guards would have passed with an empty scan. So the
 * parser is tested on the two things that can go wrong — seeing less than is
 * present, and failing to resolve a name — rather than on its happy path.
 *
 * The idempotence script itself is NOT tested here: it needs `ABSPATH`,
 * `dbDelta()` and a live schema, so it only runs in the `fresh-install` CI job.
 * What is testable without a database is exactly this, and it is what decides
 * whether that job looks at the right set.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class CreateStatementsParserTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	protected function setUp(): void {
		parent::setUp();
		require_once self::root() . '/.github/scripts/ffc-create-statements.php';
	}

	public function test_the_extraction_sees_every_statement_that_is_present(): void {
		$extracted = ffc_create_statements( self::root() . '/includes' );
		$present   = ffc_create_statements_present( self::root() . '/includes' );

		$this->assertNotEmpty( $extracted, 'No CREATE TABLE found at all — the parser is broken, not the plugin.' );

		$this->assertCount(
			$present,
			$extracted,
			sprintf(
				'The parser extracted %d statements but %d are present. One is written in a shape '
				. 'it cannot see (a heredoc, a single-quoted string) and would be silently exempt '
				. 'from both gates that read this file.',
				count( $extracted ),
				$present
			)
		);
	}

	public function test_every_statement_resolves_the_table_it_writes_to(): void {
		$unresolved = array();

		foreach ( ffc_create_statements( self::root() . '/includes' ) as $statement ) {
			if ( null === $statement['table'] ) {
				$unresolved[] = ltrim( str_replace( self::root(), '', $statement['file'] ), '/' )
					. ':' . $statement['line'];
			}
		}

		$this->assertSame(
			array(),
			$unresolved,
			"The table name could not be read for these statements, so the dbDelta idempotence\n"
			. "gate would never check them. A fifth naming idiom appeared — teach\n"
			. "ffc_resolve_table_name() rather than leaving them invisible:\n  "
			. implode( "\n  ", $unresolved )
		);
	}

	/**
	 * The four naming idioms the plugin actually uses must all resolve.
	 *
	 * Named rather than counted: each one defeated an earlier version of the
	 * resolver, and a regression would otherwise show only as a slightly smaller
	 * total that nobody reads.
	 */
	public function test_the_four_known_naming_idioms_resolve(): void {
		$tables = array();

		foreach ( ffc_create_statements( self::root() . '/includes' ) as $statement ) {
			$tables[] = $statement['table'];
		}

		// `$table_name = $wpdb->prefix . 'ffc_x'` — the common case.
		$this->assertContains( 'ffc_audiences', $tables );

		// The same, with 27 lines of column commentary between the assignment
		// and the SQL — which is what defeated a 25-line window.
		$this->assertContains( 'ffc_self_scheduling_calendars', $tables );

		// `$table_name = self::get_table_name()` — an accessor in the same file.
		$this->assertContains( 'ffc_short_urls', $tables );

		// `$table_name = SubmissionRepository::get_submissions_table()` — an
		// accessor in another file entirely.
		$this->assertContains( 'ffc_submissions', $tables );

		// A different variable name, declared 64 lines above its CREATE — which
		// is what defeated a 40-line window, and is also the only table whose
		// dbDelta runs unguarded by `table_exists()` on every activation.
		$this->assertContains( 'ffc_device_signals', $tables );
	}

	public function test_an_unknown_naming_idiom_resolves_to_null_rather_than_a_wrong_table(): void {
		$lines = explode(
			"\n",
			"<?php\nfunction whatever() {\n\t\$table = some_helper_nobody_taught_it();\n"
			. "\t\$sql = \"CREATE TABLE {\$table} ( id bigint(20) );\";\n}\n"
		);

		$this->assertNull(
			ffc_resolve_table_name( $lines, 4, implode( "\n", $lines ), self::root() . '/includes' ),
			'An unrecognised idiom must resolve to null so the caller can fail on it, never to a guess.'
		);
	}
}
