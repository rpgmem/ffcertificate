<?php
/**
 * dbDelta CREATE-statement guard (#994).
 *
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
 * The CI fresh-install job (`.github/workflows/ci.yml`) catches this class by
 * actually activating into an empty database, which is the stronger check. This
 * guard is the cheap half: it needs no database, runs in the normal PHPUnit
 * gate, and names the offending line instead of leaving one to read a MariaDB
 * syntax error.
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
	public function test_the_guard_can_see_the_create_statements(): void {
		$this->assertGreaterThan(
			20,
			count( self::create_statements() ),
			'Found almost no CREATE TABLE literals under includes/ — the pattern probably stopped matching.'
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
}
