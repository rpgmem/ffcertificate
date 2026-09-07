<?php
/**
 * `ffc_submissions` schema-agreement guard (#1087).
 *
 * The table is declared in two places, and they must agree:
 *
 *   1. `Activator::create_submissions_table()` — the `CREATE TABLE`, which is
 *      what a **fresh install** gets, in one statement.
 *   2. `Activator::add_columns()` — `add_columns_if_missing()` plus two
 *      `add_index_if_missing()` calls, which is what an **existing install**
 *      gets when `FFC_VERSION` changes.
 *
 * **Why both still exist.** `create_submissions_table()` returns early on
 * `table_exists()`, so `dbDelta` never runs on an install that already has the
 * table — which is exactly why `add_columns()` was written. Dropping the guard
 * so `dbDelta` handles upgrades is the "adds a column the normal WordPress way"
 * scenario CLAUDE.md warns fires the latent malformed-`ALTER` defects, and it
 * would change behaviour for every install on every version bump. That is a
 * larger, separate decision; consolidating the columns into the `CREATE TABLE`
 * (so the shape can declare them, #1087) does not require it.
 *
 * **What this guard buys.** Two declarations of one schema drift silently —
 * the divergence class #993 exists for. A column added to one and not the
 * other means a fresh install and an upgraded install end up with different
 * tables, and nothing would say so. Here it is a red test instead.
 *
 * It compares **column names**, not types: the two sources spell types
 * differently by construction (`BIGINT(20) UNSIGNED DEFAULT NULL` in the PHP
 * array, `bigint(20) unsigned DEFAULT NULL` in the SQL), and normalising that
 * would be re-implementing dbDelta's comparison in a test. Names are what
 * drift when someone adds a column to one place only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SubmissionsSchemaAgreementTest extends TestCase {

	/**
	 * The activator's source, read as text — including it would run it.
	 *
	 * @return string
	 */
	private function activator_source(): string {
		$path = dirname( __DIR__, 2 ) . '/includes/class-ffc-activator.php';
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Column names declared in the `ffc_submissions` CREATE TABLE.
	 *
	 * @return list<string>
	 */
	private function create_table_columns(): array {
		$source = $this->activator_source();

		$matched = preg_match(
			'/CREATE TABLE \{\$table_name\} \((.*?)\) \{\$charset_collate\}/s',
			$source,
			$m
		);
		$this->assertSame( 1, $matched, 'The submissions CREATE TABLE could not be located — the guard premise is gone.' );

		$columns = array();

		foreach ( explode( "\n", $m[1] ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^([a-z_][a-z0-9_]*)\s+/i', $line, $col ) ) {
				$columns[] = $col[1];
			}
		}

		return $columns;
	}

	/**
	 * Column names declared in `add_columns()`.
	 *
	 * @return list<string>
	 */
	private function add_columns_columns(): array {
		$source = $this->activator_source();

		$matched = preg_match(
			'/private static function add_columns\(\).*?\$columns = array\((.*?)\n\t\t\);/s',
			$source,
			$m
		);
		$this->assertSame( 1, $matched, 'add_columns() could not be located — the guard premise is gone.' );

		preg_match_all( "/^\t\t\t'([a-z_][a-z0-9_]*)'\s*=>\s*array\(/m", $m[1], $keys );

		return $keys[1];
	}

	public function test_the_scan_finds_both_declarations(): void {
		// An empty result on either side would make every other assertion pass
		// vacuously — the same "gate that passes in silence" defect the ruler
		// itself guards against (#1071).
		$this->assertNotEmpty( $this->create_table_columns(), 'No column parsed out of the CREATE TABLE.' );
		$this->assertNotEmpty( $this->add_columns_columns(), 'No column parsed out of add_columns().' );
	}

	public function test_every_incremental_column_is_in_the_create_table(): void {
		$create      = $this->create_table_columns();
		$incremental = $this->add_columns_columns();

		$missing = array_values( array_diff( $incremental, $create ) );

		$this->assertSame(
			array(),
			$missing,
			"A column that add_columns() gives an existing install is absent from the CREATE TABLE, so a"
			. " fresh install would not have it:\n  " . implode( "\n  ", $missing )
		);
	}

	public function test_the_create_table_carries_the_indexes_add_columns_adds(): void {
		$source = $this->activator_source();

		$matched = preg_match(
			'/CREATE TABLE \{\$table_name\} \((.*?)\) \{\$charset_collate\}/s',
			$source,
			$m
		);
		$this->assertSame( 1, $matched );

		// The two composite indexes add_index_if_missing() creates on an
		// existing install; a fresh one must not be left without them.
		$this->assertStringContainsString( 'idx_form_cpf_new', $m[1] );
		$this->assertStringContainsString( 'idx_form_rf', $m[1] );
	}
}
