<?php
/**
 * dbDelta CREATE-statement guard (#994, #997).
 *
 * Two ways to write a `CREATE TABLE` that `dbDelta()` mishandles silently. Both
 * were live in this plugin, and neither is visible to a test that stubs
 * `dbDelta` — which every activator test does.
 *
 * RULE 1 — no semicolon before the final one.
 * `dbDelta()` splits the string it is given on the semicolon character before
 * executing anything. A semicolon anywhere inside a CREATE statement therefore
 * truncates it mid-column — and because the truncated fragment is still valid
 * enough to reach the server, the failure surfaces only as a `WordPress
 * database error` on the activation request. On a site that already has the
 * table from an earlier release, nothing looks wrong at all.
 *
 * That is not hypothetical. `ffc_self_scheduling_calendars` carried an SQL
 * comment reading `-- Scheduling mode: 'regular' = weekly working hours,
 * 'custom' = ...` with a semicolon in the middle, and a column `COMMENT` string
 * with another. Every fresh install since silently had no calendars table, so
 * self-scheduling was broken for new sites while every upgraded site was fine.
 * It is the same family as #358 (backtick comments read as columns) and the
 * 6.0.1 COMMENT-clause failure that left four recruitment tables uncreated.
 *
 * RULE 2 — every line must be a column or key definition.
 * `dbDelta()` splits the field list on newlines and reads each line as a column
 * definition. Anything that is not one becomes a malformed ALTER, issued every
 * time it runs against a table that already exists:
 *
 *   a blank line   →  `ALTER TABLE … ADD  `` (``)`
 *   an SQL comment →  `ALTER TABLE … ADD COLUMN -- <text>`
 *
 * Both were measured against a real MariaDB rather than reasoned about. Running
 * `dbDelta()` over the three self-scheduling tables as shipped produced **41**
 * database errors: 37 from the 37 blank lines (exactly 1:1), 3 from the comment
 * blocks (one per statement — `dbDelta` keys fields by name, so the repeated
 * `--` collapses), and 1 unrelated duplicate-key attempt. Removing the blank
 * lines took it to 4, removing the comments as well to 1.
 *
 * An earlier reading of this — that comments were harmless — came from grepping
 * for the blank-line ALTER shape only, which could not see the comment shape.
 * Hence the rule is stated as what a line must BE, not as a list of things it
 * must not be.
 *
 * Column-group documentation moves to a PHP comment immediately above the
 * statement, which loses nothing.
 *
 * Both were latent rather than live, because every activator that owns an
 * affected table guards its `dbDelta` call on `table_exists()`. That was
 * measured, not assumed: of the 35 `dbDelta` call sites, 8 have no such guard,
 * and executing them against a real database showed 6 convergent (zero ALTERs
 * on a second run) and 2 — `MigrationDynamicReregFields` — issuing three valid
 * but non-convergent `CHANGE COLUMN`s, because their `json` columns are
 * reported by MariaDB as `longtext`. Those two are unreachable in the steady
 * state anyway: `run()` returns early once its completion flag is set. The
 * malformed ALTERs would fire the day someone adds a column the normal
 * WordPress way.
 *
 * A first pass reported those two as issuing zero queries. That was the
 * instrument, not the code — `$wpdb->queries` stays empty unless `SAVEQUERIES`
 * is defined before the query runs.
 *
 * The CI fresh-install job (`.github/workflows/ci.yml`) catches rule 1 by
 * actually activating into an empty database, which is the stronger check. This
 * guard is the cheap half: it needs no database, runs in the normal PHPUnit
 * gate, and names the offending line instead of leaving one to read a MariaDB
 * syntax error — and it is the only check that sees rule 2 at all, since a
 * fresh install never re-runs `dbDelta` on an existing table.
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
final class ActivatorSqlTest extends TestCase {

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every double-quoted `CREATE TABLE …` literal under `includes/`.
	 *
	 * @return array<int, array{file: string, line: int, sql: string}>
	 */
	public static function create_statements(): array {
		$out  = array();
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}
			$text = (string) file_get_contents( $path );
			if ( ! preg_match_all( '/"CREATE TABLE.*?"\s*;/s', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$out[] = array(
					'file' => ltrim( str_replace( self::root(), '', $path ), '/' ),
					'line' => substr_count( substr( $text, 0, (int) $match[1] ), "\n" ) + 1,
					// Drop the PHP statement terminator, keeping the SQL literal.
					'sql'  => substr( (string) $match[0], 0, -2 ),
				);
			}
		}

		return $out;
	}

	/**
	 * The guard's own input must be readable, or it proves nothing.
	 */
	public function test_the_guard_sees_every_create_statement(): void {
		$captured = count( self::create_statements() );

		// Every `CREATE TABLE` occurrence in a string, however it is quoted. The
		// pattern above only sees double-quoted literals, which is all the plugin
		// uses today — a heredoc would be silently uncovered, so compare counts
		// rather than asserting a floor.
		$present = 0;
		$iter    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root() . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			$path = $file->getPathname();
			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}
			$present += preg_match_all( '/[\'"<]\s*CREATE TABLE/i', (string) file_get_contents( $path ) );
		}

		$this->assertSame(
			$present,
			$captured,
			sprintf(
				'The guard captured %d CREATE TABLE statements but %d are present. A statement '
				. 'written in a shape the pattern cannot see (a heredoc, a single-quoted string) '
				. 'would be silently exempt from both rules — widen create_statements().',
				$captured,
				$present
			)
		);
	}

	/**
	 * No CREATE statement may carry a semicolon before its final one.
	 *
	 * The trailing `) {$charset_collate};` is the statement terminator and is
	 * expected; anything earlier — an SQL comment, a quoted `COMMENT` string —
	 * is what truncates the statement.
	 */
	public function test_no_create_statement_contains_an_interior_semicolon(): void {
		$offenders = array();

		foreach ( self::create_statements() as $statement ) {
			$sql  = rtrim( $statement['sql'] );
			$last = strrpos( $sql, ';' );
			if ( false === $last ) {
				continue;
			}

			foreach ( explode( "\n", substr( $sql, 0, $last ) ) as $offset => $line ) {
				if ( strpos( $line, ';' ) === false ) {
					continue;
				}
				$offenders[] = sprintf(
					'%s:%d  %s',
					$statement['file'],
					$statement['line'] + $offset,
					trim( $line )
				);
			}
		}

		sort( $offenders );

		$this->assertSame(
			array(),
			$offenders,
			"A CREATE TABLE statement contains a semicolon before its final one:\n  "
			. implode( "\n  ", $offenders )
			. "\n\ndbDelta() splits its input on the semicolon character before executing, so"
			. "\nthis truncates the statement mid-column and the table is never created."
			. "\nRewrite the comment or COMMENT string without a semicolon."
		);
	}

	/**
	 * Every line of a CREATE statement must be a column or key definition.
	 *
	 * `dbDelta()` reads them all as one, so a blank line and an SQL comment each
	 * become a malformed ALTER — see the file docblock for the measurement.
	 */
	public function test_no_create_statement_contains_a_non_definition_line(): void {
		$offenders = array();

		foreach ( self::create_statements() as $statement ) {
			foreach ( explode( "\n", $statement['sql'] ) as $offset => $line ) {
				$trimmed = trim( $line );
				if ( '' === $trimmed ) {
					$kind = 'blank line';
				} elseif ( strpos( $trimmed, '--' ) === 0 ) {
					$kind = 'SQL comment';
				} else {
					continue;
				}
				$offenders[] = sprintf( '%s:%d  (%s)', $statement['file'], $statement['line'] + $offset, $kind );
			}
		}

		sort( $offenders );

		$this->assertSame(
			array(),
			$offenders,
			"A CREATE TABLE statement contains a line that is not a column or key definition:\n  "
			. implode( "\n  ", $offenders )
			. "\n\ndbDelta() reads every line of the field list as a column definition, so each"
			. "\nof these becomes a malformed ALTER issued on every run against an existing"
			. "\ntable. Move the documentation to a PHP comment above the statement."
		);
	}
}
