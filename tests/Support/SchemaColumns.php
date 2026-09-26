<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * What declares a column, read the four ways the plugin declares one (#1447).
 *
 * WHY THIS IS SHARED RATHER THAN PRIVATE
 *
 * `SchemaAgreementTest` had all of this privately, and its own docblock already
 * said why a second copy is the danger: *"a private second scan here is how the
 * three would end up measuring different sets"*. `SchemaWrittenColumnTest`
 * needs the same answer from the other side — is this column declared at all —
 * so the reader moved here rather than being written twice. The same reason
 * `.github/scripts/ffc-create-statements.php` is shared between
 * `ActivatorSqlTest` and the dbDelta idempotence gate, and
 * `tests/Support/CssSelectors.php` between the two stylesheet guards.
 *
 * FOUR DECLARER SHAPES, AND THE HELPER IS THE DOMINANT ONE
 *
 * Measured over `includes/`: 36 `CREATE TABLE` literals, 5 literal
 * `ADD COLUMN`, 40 `add_column_if_missing()` and 5 `add_columns_if_missing()`.
 * So **45 of the 86 declaration sites are the helper**, and a reader that knows
 * only the two SQL shapes sees barely half the schema — which is exactly how a
 * prototype of the written-column guard reported two staging columns as
 * undeclared when an existing guard already classified them correctly.
 *
 * @package FreeFormCertificate\Tests\Support
 */
final class SchemaColumns {

	/**
	 * The parenthesised body of a `CREATE TABLE` statement, or null.
	 *
	 * The optional `ENGINE=...` before `{$charset_collate}` is what was missing
	 * in #1241: without it, `RecruitmentActivator`'s nine statements had no body
	 * extracted at all, in BOTH directions of `SchemaAgreementTest`, because a
	 * second copy of this regex lived there too. It is one function now for the
	 * reason that guard's own comment gives about a private second scan.
	 *
	 * @param string $sql The complete statement.
	 * @return string|null
	 */
	public static function body_of( string $sql ): ?string {
		if ( ! preg_match( '/CREATE TABLE [^(]*\((.*)\)\s*(?:ENGINE=\w+\s*)?\{?\$charset_collate/s', $sql, $body ) ) {
			return null;
		}

		return $body[1];
	}

	/**
	 * Column names from one `CREATE TABLE` body.
	 *
	 * The optional `ENGINE=...` before `{$charset_collate}` is what was missing
	 * in #1241: without it, `RecruitmentActivator`'s nine statements had no body
	 * extracted at all.
	 *
	 * THE INDEX VOCABULARY IS WIDER THAN THE STATEMENTS CURRENTLY USE. Measured:
	 * every index line in all 36 statements is `PRIMARY KEY`, `UNIQUE KEY` or
	 * `KEY`, so the narrower list this grew from was correct — and a future
	 * `INDEX` or `CONSTRAINT` line would have been read as a column named
	 * `index`. Widened when it moved here, because a reader two guards share
	 * should not be right only by what the tree happens to contain today.
	 *
	 * @param string $sql The complete statement.
	 * @return list<string>
	 */
	public static function of_create( string $sql ): array {
		$body = self::body_of( $sql );

		if ( null === $body ) {
			return array();
		}

		$columns = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$line = rtrim( trim( $line ), ',' );

			if ( '' === $line ) {
				continue;
			}

			// The word boundary is load-bearing: without it `CREATE` matches the
			// prefix of `created_at` and a real column disappears. Found while
			// writing #1444's per-file test, and it is the same token-boundary
			// trap CLAUDE.md records for the CSS anchor scan.
			if ( preg_match( '/^(?:PRIMARY\s+KEY\b|UNIQUE\s+(?:KEY|INDEX)\b|FULLTEXT\b|SPATIAL\b|KEY\b|INDEX\b|CONSTRAINT\b|FOREIGN\s+KEY\b)/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^`?([a-z_][a-z0-9_]*)`?\s/i', $line, $column ) ) {
				$columns[] = strtolower( $column[1] );
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Every declared column, keyed by table, from every `CREATE TABLE` literal.
	 *
	 * @param string $includes_dir Absolute path to `includes/`.
	 * @return array<string, array<string, true>> table => column => true
	 */
	public static function declared_by_table( string $includes_dir ): array {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';

		$out = array();

		/** @var list<array{table: string|null, sql: string, file: string}> $statements */
		$statements = ffc_create_statements( $includes_dir );

		foreach ( $statements as $statement ) {
			if ( null === $statement['table'] ) {
				continue;
			}

			foreach ( self::of_create( $statement['sql'] ) as $column ) {
				$out[ $statement['table'] ][ $column ] = true;
			}
		}

		return $out;
	}

	/**
	 * Columns the two incremental helper idioms deliver, in one file.
	 *
	 * THE TWO QUOTE FORMS ARE THE DETAIL THAT COSTS (#1241)
	 *
	 * The type appears in SINGLE quotes when it is simple (`'LONGTEXT NULL'`)
	 * and in DOUBLE quotes when it carries a `COMMENT '...'` inside. An
	 * extractor that accepts only the first drops precisely the richest
	 * declarations — `AudienceActivator`'s 13 — and the number falls in a way
	 * that looks like good news.
	 *
	 * THE TYPE IS THE DISCRIMINATOR, NOT THE NAME
	 *
	 * Anchoring on the column name alone would make a REST argument schema
	 * (`'code' => array( 'type' => 'string' )`) count as a column. What
	 * separates the two is the call: the singular idiom requires a literal
	 * `add_column_if_missing(`, and the plural requires the `'type'` key on the
	 * line after `array(`.
	 *
	 * Staging columns are EXCLUDED, for the reason {@see self::staging()} gives.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	public static function incremental( string $source ): array {
		$names = array();

		preg_match_all(
			'/add_column_if_missing\s*\(\s*[^,]{1,120}?,\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*,/s',
			$source,
			$singular
		);

		preg_match_all(
			'/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>\s*array\(\s*\n\s*[\'"]type[\'"]\s*=>/m',
			$source,
			$plural
		);

		foreach ( array_merge( $singular[1], $plural[1] ) as $name ) {
			$names[] = strtolower( $name );
		}

		$names = array_values( array_unique( $names ) );

		return array_values( array_diff( $names, self::staging( $source ) ) );
	}

	/**
	 * Columns a literal `ADD COLUMN` declares, in one file.
	 *
	 * Five sites, all of them `"ALTER TABLE \`{$table}\` ADD COLUMN <name>
	 * <type>"`. Distinct from the helper above, which is the dominant shape.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	public static function added_by_literal_alter( string $source ): array {
		preg_match_all(
			'/ADD\s+COLUMN\s+`?([a-z_][a-z0-9_]*)`?\s+(?:bigint|int|varchar|text|longtext|mediumtext|datetime|date|time|tinyint|smallint|decimal|char|enum|json|blob)/i',
			$source,
			$matches
		);

		$names = array();
		foreach ( $matches[1] as $name ) {
			$names[] = strtolower( $name );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * STAGING columns, which must NOT be in the `CREATE TABLE` (#1241).
	 *
	 * WHY THEY ARE THE OPPOSITE OF DEBT
	 *
	 * #249's DATETIME -> BIGINT migration adds a temporary column, fills it,
	 * and at the end RENAMES it to the final name (`ALTER TABLE ... CHANGE
	 * submission_date_ts submission_date ...`). Declaring it on a fresh install
	 * would create a permanent column onto which the `CHANGE` would then try to
	 * rename another.
	 *
	 * THE SIGNAL IS IN THE FILE ITSELF, NOT IN A SUFFIX
	 *
	 * Excluding everything ending in `_ts` would be a rule about the NAME, and
	 * a legitimate column with that suffix would start being ignored in
	 * silence. What is looked for is the evidence that the file renames it away:
	 * the name appearing as the SOURCE of a `CHANGE`.
	 *
	 * @param string $source File contents.
	 * @return list<string>
	 */
	public static function staging( string $source ): array {
		preg_match_all(
			'/CHANGE\s+%i\s+%i[^;]*?,\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*,\s*[\'"][a-z_][a-z0-9_]*[\'"]/s',
			$source,
			$renamed
		);

		$names = array();
		foreach ( $renamed[1] as $name ) {
			$names[] = strtolower( $name );
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Columns the INCREMENTAL declarers name, tree-wide and table-agnostic.
	 *
	 * The fallback direction A needs, and deliberately narrower than
	 * {@see self::declared_anywhere()}: attributing an
	 * `add_column_if_missing( $table, … )` call to a table needs the same
	 * variable resolution the write site already does, so a column declared
	 * incrementally counts wherever it was declared.
	 *
	 * IT MUST NOT INCLUDE OTHER TABLES' `CREATE` COLUMNS. Measured: with the
	 * wider set as the fallback, re-introducing #1444 reported
	 * `context_encrypted` and NOT `submission_id`, because some other table's
	 * statement declares that name — the per-table comparison silently degraded
	 * to the union it exists to beat, on the very defect it was written for.
	 *
	 * @param string $includes_dir Absolute path to `includes/`.
	 * @return array<string, true>
	 */
	public static function declared_incrementally( string $includes_dir ): array {
		$out = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			foreach ( array_merge( self::incremental( $source ), self::added_by_literal_alter( $source ), self::staging( $source ) ) as $name ) {
				$out[ $name ] = true;
			}
		}

		return $out;
	}

	/**
	 * Every column any declarer in the tree names, table-agnostic.
	 *
	 * The weaker reading, and deliberately available: a write site whose table
	 * cannot be resolved statically is still checkable against *"does anything
	 * anywhere declare this name"*, which is what keeps such a site from being
	 * silently skipped. {@see \FreeFormCertificate\Tests\Unit\SchemaWrittenColumnTest}
	 * says which sites fall back to it and why.
	 *
	 * @param string $includes_dir Absolute path to `includes/`.
	 * @return array<string, true>
	 */
	public static function declared_anywhere( string $includes_dir ): array {
		$out = self::declared_incrementally( $includes_dir );

		foreach ( self::declared_by_table( $includes_dir ) as $columns ) {
			$out += $columns;
		}

		return $out;
	}
}
