<?php
/**
 * Schema-agreement guard (#1087).
 *
 * A table declared in two places drifts silently. Two activators declare one:
 *
 *   1. A `CREATE TABLE`, which is what a **fresh install** gets in one statement.
 *   2. An `add_columns_if_missing()` call, which is what an **existing install**
 *      gets when `FFC_VERSION` changes.
 *
 * Both must name the same columns, or a fresh install and an upgraded install
 * end up with different tables and nothing says so. That is not hypothetical:
 * `ffc_submissions` was born with 7 of its 25 columns until #1091, and
 * `ffc_reregistration_submissions` without `auth_code` / `magic_token` until
 * this one.
 *
 * **Why both declarations still exist.** Every one of these activators guards
 * its `dbDelta()` on `table_exists()`, so `dbDelta` never runs on an install
 * that already has the table — which is exactly why the incremental path was
 * written. Dropping the guard so `dbDelta` handles upgrades is the "adds a
 * column the normal WordPress way" scenario CLAUDE.md warns fires the latent
 * malformed-`ALTER` defects, and it would change behaviour for every install
 * on every version bump. Consolidating the columns does not require it.
 *
 * **This is a scan, not a framework.** The population is two calls in the whole
 * repository. A generalised schema-declaration engine over two sites would be
 * indirection that narrows nothing — the trap CLAUDE.md names, and the same
 * call made in #1079 against a `Core\OptionValue::int()` for three sites. What
 * this file does instead is find whatever `add_columns_if_missing()` calls
 * exist and check each against the `CREATE TABLE` in the same file. A third
 * one is covered the day it is written, with no new abstraction.
 *
 * It compares **column names, not types**: the two sources spell types
 * differently by construction (`VARCHAR(20) DEFAULT NULL` in the PHP array,
 * `varchar(20) DEFAULT NULL` in the SQL), and normalising that would be
 * re-implementing dbDelta's comparison inside a test. Names are what drift
 * when someone adds a column to one place only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SchemaAgreementTest extends TestCase {

	/**
	 * Activator files, read as text — including one would run it.
	 *
	 * @return list<string> Absolute paths.
	 */
	private function activator_files(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( false !== strpos( $source, 'add_columns_if_missing(' )
				&& false !== strpos( $source, 'CREATE TABLE' ) ) {
				$found[] = $path;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Column names of every `CREATE TABLE` in a source file, merged.
	 *
	 * Merged on purpose: an activator may declare several tables, and matching
	 * each incremental call to its own statement would need the table variable
	 * resolved. A column present in ANY create of the same file is enough to
	 * say it is not missing from a fresh install — the failure this guards.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	private function create_table_columns( string $source ): array {
		$columns = array();

		preg_match_all(
			'/CREATE TABLE \{\$table_name\} \((.*?)\) \{\$charset_collate\}/s',
			$source,
			$blocks
		);

		foreach ( $blocks[1] as $block ) {
			foreach ( explode( "\n", $block ) as $line ) {
				$line = trim( $line );

				if ( '' === $line || preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line ) ) {
					continue;
				}

				if ( preg_match( '/^([a-z_][a-z0-9_]*)\s+/i', $line, $col ) ) {
					$columns[] = $col[1];
				}
			}
		}

		return $columns;
	}

	/**
	 * Column names passed to every `add_columns_if_missing()` in a file.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	private function incremental_columns( string $source ): array {
		$names = array();

		// Each entry is `'column_name' => array( 'type' => …`, which is what
		// distinguishes a column key from any other quoted string nearby.
		preg_match_all(
			"/'([a-z_][a-z0-9_]*)'\s*=>\s*array\(\s*\n\s*'type'\s*=>/m",
			$source,
			$matches
		);

		foreach ( $matches[1] as $name ) {
			$names[] = $name;
		}

		return $names;
	}

	public function test_the_scan_finds_the_activators_it_is_meant_to_check(): void {
		// An empty result would make every other assertion pass vacuously —
		// the "gate that passes in silence" defect the ruler guards against
		// (#1071), one level up.
		$files = $this->activator_files();

		$this->assertNotEmpty(
			$files,
			'No activator declares both a CREATE TABLE and add_columns_if_missing() — the guard premise is gone.'
		);
	}

	public function test_every_incremental_column_is_also_in_a_create_table(): void {
		$failures = array();

		foreach ( $this->activator_files() as $path ) {
			$source      = (string) file_get_contents( $path );
			$create      = $this->create_table_columns( $source );
			$incremental = $this->incremental_columns( $source );

			$this->assertNotEmpty(
				$incremental,
				basename( $path ) . ': the incremental-column scan found nothing, so its check is vacuous.'
			);

			foreach ( array_diff( $incremental, $create ) as $missing ) {
				$failures[] = basename( $path ) . ' :: ' . $missing;
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"A column that add_columns_if_missing() gives an existing install is absent from every"
			. " CREATE TABLE in the same file, so a fresh install would not have it:\n  "
			. implode( "\n  ", $failures )
		);
	}

	/**
	 * The two composite indexes `Activator::add_columns()` creates through
	 * `add_index_if_missing()` — the ones a fresh install lacked until #1091.
	 *
	 * Kept as a named case rather than folded into the scan above: index calls
	 * take a raw SQL fragment, not a declarative array, so there is nothing to
	 * compare structurally without parsing SQL.
	 */
	public function test_the_submissions_create_table_carries_its_composite_indexes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ffc-activator.php' );

		$this->assertStringContainsString( 'idx_form_cpf_new', $source );
		$this->assertStringContainsString( 'idx_form_rf', $source );
	}
}
