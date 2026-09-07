<?php
/**
 * Schema-agreement guard (#1087).
 *
 * A table declared in two places drifts silently. This file checks the two
 * shapes that duplication takes in this repository.
 *
 * **Within one file — `CREATE TABLE` vs `add_columns_if_missing()`.**
 *
 *   1. The `CREATE TABLE` is what a **fresh install** gets in one statement.
 *   2. The `add_columns_if_missing()` call is what an **existing install** gets
 *      when `FFC_VERSION` changes.
 *
 * Both must name the same columns, or a fresh install and an upgraded install
 * end up with different tables and nothing says so. That is not hypothetical:
 * `ffc_submissions` was born with 7 of its 25 columns until #1091, and
 * `ffc_reregistration_submissions` without `auth_code` / `magic_token` until
 * #1093.
 *
 * **Across files — the same table declared by more than one class.** Three
 * tables are: `ffc_custom_fields` (3 declarations), `ffc_reregistration_submissions`
 * (3) and `ffc_reregistrations` (2), because the activators were written after
 * the migrations that first created those tables and neither side was retired.
 * Nothing compared them, and the third occurrence of the #1091 class was hiding
 * exactly there: `UserDashboardActivator` declared **12 of the 17 columns** of
 * `ffc_custom_fields`, missing the five that `CustomFieldWriter::create()`
 * writes on every insert. A fresh install came out correct only because
 * `MigrationDynamicReregFields` runs later in the same activation and its
 * `dbDelta` added them — and that migration is one-shot, flagged by an option,
 * so any recreation of the table afterwards would have produced a permanently
 * 12-column table.
 *
 * The keys diverged the same way, and that one was already costing something:
 * both paths indexed `auth_code` and `magic_token` under **different names**, so
 * an install that took both ended up carrying two indexes on each column
 * (visible in the `SHOW CREATE TABLE` the idempotence gate dumped). Aligning
 * the declarations stops new installs from inheriting the pair; dropping the
 * duplicates on existing installs is a separate, destructive change.
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
	 * Every `CREATE TABLE` in `includes/`, grouped by the table it writes to.
	 *
	 * Uses the same extraction as `ActivatorSqlTest` and the dbDelta idempotence
	 * gate — one parser, three consumers, for the reason `uninstall.php` is one
	 * manifest. A private second scan here is how the three would end up
	 * measuring different sets.
	 *
	 * @return array<string, array<string, array{columns: list<string>, keys: list<string>}>>
	 *         Table => file basename => its declared columns and key names.
	 */
	private function declarations_by_table(): array {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';

		$by_table = array();

		foreach ( ffc_create_statements( dirname( __DIR__, 2 ) . '/includes' ) as $statement ) {
			if ( null === $statement['table'] ) {
				continue;
			}

			if ( ! preg_match( '/CREATE TABLE [^(]*\((.*)\)\s*\{?\$charset_collate/s', $statement['sql'], $body ) ) {
				continue;
			}

			$columns = array();
			$keys    = array();

			foreach ( explode( "\n", $body[1] ) as $line ) {
				$line = rtrim( trim( $line ), ',' );

				if ( '' === $line ) {
					continue;
				}

				// `PRIMARY KEY` is unnamed, so it is covered by the column set
				// implicitly and comparing it would compare nothing.
				if ( preg_match( '/^(?:UNIQUE )?KEY\s+`?([a-z_][a-z0-9_]*)`?/i', $line, $key ) ) {
					$keys[] = $key[1];
					continue;
				}

				if ( preg_match( '/^(?:PRIMARY KEY)\b/i', $line ) ) {
					continue;
				}

				if ( preg_match( '/^`?([a-z_][a-z0-9_]*)`?\s/i', $line, $column ) ) {
					$columns[] = $column[1];
				}
			}

			$by_table[ $statement['table'] ][ basename( $statement['file'] ) ] = array(
				'columns' => $columns,
				'keys'    => $keys,
			);
		}

		return array_filter(
			$by_table,
			static function ( array $declarations ): bool {
				return count( $declarations ) > 1;
			}
		);
	}

	public function test_the_cross_file_scan_finds_tables_declared_more_than_once(): void {
		// The two checks below compare declarations against each other, so an
		// empty result would make both pass having looked at nothing — the
		// silent-pass shape #1087 passo 6 found in four guards.
		$this->assertNotEmpty(
			$this->declarations_by_table(),
			'No table is declared by more than one file any more. If the duplication was genuinely '
			. 'retired, delete these two checks; if the scan broke, fix it — do not leave it passing on nothing.'
		);
	}

	public function test_every_declaration_of_a_table_names_the_same_columns(): void {
		$failures = array();

		foreach ( $this->declarations_by_table() as $table => $declarations ) {
			$union = array();

			foreach ( $declarations as $declaration ) {
				$union = array_unique( array_merge( $union, $declaration['columns'] ) );
			}

			foreach ( $declarations as $file => $declaration ) {
				foreach ( array_diff( $union, $declaration['columns'] ) as $missing ) {
					$failures[] = $table . ' :: ' . $file . ' is missing ' . $missing;
				}
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"One declaration of a table names a column the others do not, so which columns an\n"
			. "install ends up with depends on which path created the table — the #1091 class,\n"
			. "one level up:\n  " . implode( "\n  ", $failures )
		);
	}

	public function test_every_declaration_of_a_table_names_the_same_keys(): void {
		$failures = array();

		foreach ( $this->declarations_by_table() as $table => $declarations ) {
			$union = array();

			foreach ( $declarations as $declaration ) {
				$union = array_unique( array_merge( $union, $declaration['keys'] ) );
			}

			foreach ( $declarations as $file => $declaration ) {
				foreach ( array_diff( $union, $declaration['keys'] ) as $missing ) {
					$failures[] = $table . ' :: ' . $file . ' is missing ' . $missing;
				}
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"One declaration of a table names an index the others do not. When both paths run,\n"
			. "the table carries both — which is how ffc_reregistration_submissions ended up with\n"
			. "two indexes on auth_code and two on magic_token, under different names:\n  "
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
