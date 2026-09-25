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
use FreeFormCertificate\Tests\Support\SchemaColumns;

/**
 * @coversNothing
 */
final class SchemaAgreementTest extends TestCase {

	/**
	 * Files that declare a table AND complete it incrementally.
	 *
	 * TWO IDIOMS, AND THE SINGULAR IS THE MAJORITY (#1241)
	 *
	 * `DatabaseHelperTrait` exposes `add_column_if_missing()` and
	 * `add_columns_if_missing()`, and nothing forces an activator to pick one.
	 * This scan looked for the PLURAL only, which has 4 occurrences against the
	 * singular's 40 -- so it measured 2 of the 6 activators.
	 *
	 * The self-check did not catch it because it charged that the list was not
	 * empty, and the plural idiom exists in two files. A measured minority
	 * sustained the appearance of a live scan. It is the shape CLAUDE.md
	 * describes as "never count as clean what it did not look at", with the
	 * aggravation of looking as though it had.
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

			// Does `add_columns_if_missing(` contain `add_column` as a prefix? No:
			// `column` and `columns` diverge before the parenthesis, so the two
			// searches are independent.
			$has_incremental = false !== strpos( $source, 'add_column_if_missing(' )
				|| false !== strpos( $source, 'add_columns_if_missing(' );

			if ( $has_incremental && false !== strpos( $source, 'CREATE TABLE' ) ) {
				$found[] = $path;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Columns of every `CREATE TABLE` in a file, summed.
	 *
	 * Summed on purpose: an activator may declare several tables, and matching
	 * each incremental call to its own statement would mean resolving the table
	 * variable. A column present in ANY create in the same file is enough to say
	 * it is not missing from a fresh install -- which is the failure this guards.
	 *
	 * IT READS THE SHARED PARSER, NOT A REGEX OF ITS OWN (#1241)
	 *
	 * This method used to have its own regex, requiring literally
	 * `CREATE TABLE {$table_name} (...) {$charset_collate}`. Two real shapes
	 * escaped it, and {@see self::declarations_by_table()}'s docblock already
	 * said why that would happen: *"a private second scan here is how the three
	 * would end up measuring different sets"*.
	 *
	 *   - `RateLimitActivator` names its tables `$table_limits`, `$table_logs`
	 *     and `$table_signals` -- 3 invisible statements.
	 *   - `RecruitmentActivator` closes with `) ENGINE=InnoDB {$charset_collate};`
	 *     -- 9 invisible statements, and that is the case that produces a FALSE
	 *     POSITIVE: without seeing the create, every incremental column in the
	 *     file looks absent from it.
	 *
	 * @param string $path The file path.
	 * @return list<string>
	 */
	private function create_table_columns( string $path ): array {
		$columns = array();

		foreach ( $this->create_statements() as $statement ) {
			if ( $statement['file'] !== $path ) {
				continue;
			}

			$columns = array_merge( $columns, $this->columns_of( $statement['sql'] ) );
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Columns the two incremental idioms deliver, in one file.
	 *
	 * THE TWO QUOTE FORMS ARE THE DETAIL THAT COSTS (#1241)
	 *
	 * The type appears in SINGLE quotes when it is simple (`'LONGTEXT NULL'`) and
	 * in DOUBLE quotes when it carries a `COMMENT '...'` inside. An extractor
	 * that accepts only the first case drops precisely the richest declarations
	 * -- `AudienceActivator`'s 13 -- and the number falls in a way that looks
	 * like good news. It is the extractor having stopped looking.
	 *
	 * THE TYPE IS THE DISCRIMINATOR, NOT THE NAME
	 *
	 * Anchoring on the column name alone would make a REST argument schema
	 * (`'code' => array( 'type' => 'string' )`) count as a column. What separates
	 * the two is the call: the singular idiom requires a literal
	 * `add_column_if_missing(`, and the plural requires the `'type'` key on the
	 * line after `array(`.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	private function incremental_columns( string $source ): array {
		return SchemaColumns::incremental( $source );
	}

	/**
	 * STAGING columns, which must NOT be in the `CREATE TABLE` (#1241).
	 *
	 * WHY THEY ARE THE OPPOSITE OF DEBT
	 *
	 * #249's DATETIME -> BIGINT migration adds a temporary column, fills it, and
	 * at the end RENAMES it to the final name
	 * (`ALTER TABLE ... CHANGE submission_date_ts submission_date ...`). The
	 * shared helper builds the name as `$column . '_ts'`.
	 *
	 * It is added by `add_column_if_missing()`, so the scan finds it -- but
	 * declaring it on a fresh install would create a permanent column onto which
	 * the `CHANGE` would then try to rename another. #1241's step 3, followed to
	 * the letter, would introduce that defect in three of the fifteen columns it
	 * lists.
	 *
	 * THE SIGNAL IS IN THE FILE ITSELF, NOT IN A SUFFIX
	 *
	 * Excluding everything ending in `_ts` would be a rule about the NAME, and a
	 * legitimate column with that suffix would start being ignored in silence.
	 * What is looked for here is the evidence that the file renames it away: the
	 * name appearing as the SOURCE of a `CHANGE`. A column that stays has no such
	 * line.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	private function staging_columns( string $source ): array {
		return SchemaColumns::staging( $source );
	}

	/**
	 * Every `CREATE TABLE` in `includes/`, through the shared parser.
	 *
	 * @return list<array{table: string|null, sql: string, file: string}>
	 */
	private function create_statements(): array {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';

		/** @var list<array{table: string|null, sql: string, file: string}> $statements */
		$statements = ffc_create_statements( dirname( __DIR__, 2 ) . '/includes' );

		return $statements;
	}

	/**
	 * Column names from a `CREATE TABLE` body.
	 *
	 * The optional `ENGINE=...` before `{$charset_collate}` is what was missing
	 * (#1241): without it, `RecruitmentActivator`'s nine statements had no body
	 * extracted -- in BOTH directions of this guard, because
	 * {@see self::declarations_by_table()} uses the same regex.
	 *
	 * @param string $sql The complete statement.
	 * @return list<string>
	 */
	private function columns_of( string $sql ): array {
		return SchemaColumns::of_create( $sql );
	}

	/**
	 * The scan must MEASURE every file that declares and completes a table.
	 *
	 * The previous self-check charged only that the list was not empty -- and it
	 * never was, because the plural idiom exists in two files. Two measured out
	 * of six looked like a live scan (#1241).
	 *
	 * This one charges what the dbDelta gate charges: that the count seen matches
	 * that of a WIDER NET, built here in a deliberately naive way. If the two
	 * diverge, somebody narrowed the scan without noticing.
	 */
	public function test_the_scan_measures_every_file_that_declares_and_completes_a_table(): void {
		$measured = $this->activator_files();

		$this->assertNotEmpty(
			$measured,
			'No activator declares both a CREATE TABLE and an incremental column call — the guard premise is gone.'
		);

		$wide     = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( preg_match( '/add_columns?_if_missing\s*\(/', $source )
				&& false !== strpos( $source, 'CREATE TABLE' ) ) {
				$wide[] = $path;
			}
		}

		sort( $wide );

		$this->assertSame(
			$wide,
			$measured,
			"The wide net finds files the scan does not measure — it has been narrowed."
		);
	}

	/**
	 * Every `CREATE TABLE` the shared parser finds must have a readable body.
	 *
	 * It is the rule the dbDelta gate states -- *"never count as clean what it
	 * did not look at"* -- applied to the body regex, which is where this guard's
	 * two directions meet.
	 *
	 * It would have caught the `ENGINE=InnoDB` blindness the day it arrived: the
	 * parser found 34 statements and the regex extracted 25, and the 9 missing
	 * were all `RecruitmentActivator`'s -- in BOTH directions.
	 */
	public function test_every_create_statement_the_parser_finds_has_a_readable_body(): void {
		$statements = $this->create_statements();

		$this->assertNotEmpty( $statements, 'The shared parser found no statement at all.' );

		$unreadable = array();

		foreach ( $statements as $statement ) {
			if ( array() === $this->columns_of( $statement['sql'] ) ) {
				$unreadable[] = ( $statement['table'] ?? '?' ) . ' (' . basename( $statement['file'] ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$unreadable,
			"Statements the parser finds and this file cannot read — the scan counts them clean without looking:\n  "
			. implode( "\n  ", $unreadable )
		);
	}

	public function test_every_incremental_column_is_also_in_a_create_table(): void {
		$failures = array();

		foreach ( $this->activator_files() as $path ) {
			$source      = (string) file_get_contents( $path );
			$create      = $this->create_table_columns( $path );
			$incremental = $this->incremental_columns( $source );

			// Vacuity is measured BEFORE the staging exclusion. "The extractor
			// found nothing" is a defect; "everything it found was a staging
			// column" is a legitimate state -- it is `RecruitmentActivator`'s
			// case, whose only incremental call is #249's migration. Charging the
			// already-filtered list would conflate the two (#1241).
			$this->assertNotEmpty(
				array_merge( $incremental, $this->staging_columns( $source ) ),
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
		$by_table = array();

		foreach ( $this->create_statements() as $statement ) {
			if ( null === $statement['table'] ) {
				continue;
			}

			// One body reader, shared: this was a third copy of that regex, and
			// #1241's missing `ENGINE=` broke all three at once.
			$body = SchemaColumns::body_of( $statement['sql'] );

			if ( null === $body ) {
				continue;
			}

			$columns = array();
			$keys    = array();

			foreach ( explode( "\n", $body ) as $line ) {
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
