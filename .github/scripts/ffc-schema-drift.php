<?php
/**
 * A declaration compared against a live server's columns (#1458 proposal 3).
 *
 * THE BLIND SPOT THIS CLOSES, AND WHY NOTHING ELSE COULD
 *
 * Three guards already read the schema and not one of them can see a column
 * that exists on a server and is declared nowhere:
 *
 *   - `SchemaAgreementTest` compares declarations against EACH OTHER. Two
 *     statements agreeing says nothing about a third column the server holds.
 *   - `SchemaWrittenColumnTest` (#1447) compares a WRITE SITE against a
 *     declarer. A column nobody writes is invisible to it by construction --
 *     and "nobody writes it" is exactly what #1458 found.
 *   - `fresh-install-check.php` creates every table from the current `CREATE`,
 *     so the legacy columns CANNOT exist there. A fresh activation can never
 *     produce the condition, which is why CI was stricter than production
 *     (the runner's MariaDB runs `STRICT_TRANS_TABLES`) and still blind.
 *
 * What was missing is a declaration compared against a LIVE, UPGRADED install,
 * and the post-deploy smoke is the only thing in the project that ever sees
 * one. `dbDelta` appends and never drops, so every schema a release ever
 * shipped is still physically present on an old install; the only way to learn
 * that is to ask the server.
 *
 * WHAT #1458 FOUND, WHICH IS WHY THE SEVERITY IS SPLIT
 *
 * `ffc_activity_log` carried three columns no statement declares and no code
 * names. Two were nullable -- dead weight. The third, `action_type`, was
 * `NOT NULL` with no default, so under a strict `sql_mode` EVERY activity-log
 * insert would have failed with `Field 'action_type' doesn't have a default
 * value`. It survived only because that host's mode is permissive.
 *
 * So the two are not the same finding and must not carry the same severity: a
 * hazard fails, dead weight warns. Reddening a deploy for an inert legacy
 * column is how an alarm becomes noise people learn to skip, which is the
 * failure `CLAUDE.md` records twice (the smoke's own timeout, and #1311's
 * twelve unread deploys).
 *
 * THE READERS ARE SHARED, NOT COPIED. `ffc_create_statements()` finds the
 * statements and `SchemaColumns::of_create()` reads their columns -- the same
 * two every schema guard uses, so this cannot disagree with them about what a
 * column is. That is why a `tests/Support/` file travels to the host beside
 * this one: the alternative is a second copy of the one reader that must not
 * have two, and #1241 is what a second copy costs (a regex missing an optional
 * `ENGINE=` left nine statements with no body extracted, in both directions of
 * a guard, because the copy lived there too).
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

/**
 * Every column the tree declares, unioned per table, with the scan's own faults.
 *
 * A UNION, because two statements may declare one table (`ffc_custom_fields`
 * and `ffc_reregistration_submissions` do). `SchemaAgreementTest` already
 * enforces that such declarations name the SAME columns, so the union equals
 * each of them -- taking it anyway means this does not silently depend on that
 * other guard still holding.
 *
 * THE ERRORS ARE RETURNED, NOT LOGGED. Each one makes the comparison
 * meaningless in a specific direction, and a caller that treated them as
 * warnings would report a clean schema off a scan that read nothing -- the
 * #1071 / #1094 rule. A statement whose body yields no columns is the sharpest
 * case: every live column of that table would then read as undeclared, so the
 * flood would be attributed to the schema rather than to the parser.
 *
 * @param string $includes_dir Absolute path to the plugin's `includes/`.
 * @return array{tables: array<string, list<string>>, errors: list<string>}
 */
function ffc_schema_declared_columns( string $includes_dir ): array {
	$tables = array();
	$errors = array();

	if ( ! is_dir( $includes_dir ) ) {
		return array(
			'tables' => array(),
			'errors' => array( 'includes/ not found at ' . $includes_dir ),
		);
	}

	$statements = ffc_create_statements( $includes_dir );

	// The wider net the parser offers, for the reason it offers it: the two
	// counts diverge the moment a statement is written in a shape the real
	// extraction cannot see, and a statement this cannot see is one it would
	// exempt in silence.
	//
	// IT IS COUNTED BEFORE THE EMPTY CHECK, NOT AFTER, and that order is the
	// whole difference between an accurate diagnosis and a misleading one. With
	// the early return first, a tree whose statements are ALL written in an
	// unreadable shape reports `no CREATE TABLE statement was found` -- which
	// tells the reader to go looking for a missing statement instead of for the
	// quoting that hid the ones that are there. Caught by a fixture, because on
	// the real tree the two nets agree and neither branch is ever taken.
	$present = ffc_create_statements_present( $includes_dir );

	if ( $present !== count( $statements ) ) {
		$errors[] = sprintf(
			'the wide net counts %d CREATE TABLE statements and the extraction reads %d — one is written in a shape the extraction cannot see',
			$present,
			count( $statements )
		);
	}

	if ( array() === $statements ) {
		if ( 0 === $present ) {
			$errors[] = 'no CREATE TABLE statement was found under includes/';
		}

		return array(
			'tables' => array(),
			'errors' => $errors,
		);
	}

	foreach ( $statements as $statement ) {
		$where = basename( (string) $statement['file'] ) . ':' . (int) $statement['line'];

		if ( null === $statement['table'] ) {
			$errors[] = 'could not resolve the table name of the statement at ' . $where;
			continue;
		}

		$columns = \FreeFormCertificate\Tests\Support\SchemaColumns::of_create( (string) $statement['sql'] );

		if ( array() === $columns ) {
			$errors[] = 'read no columns out of the statement at ' . $where
				. ' (' . (string) $statement['table'] . ') — every live column of that table would read as undeclared';
			continue;
		}

		foreach ( $columns as $column ) {
			$tables[ (string) $statement['table'] ][ strtolower( $column ) ] = true;
		}
	}

	$out = array();

	foreach ( $tables as $table => $columns ) {
		$names = array_keys( $columns );
		sort( $names );
		$out[ $table ] = $names;
	}

	ksort( $out );

	return array(
		'tables' => $out,
		'errors' => $errors,
	);
}

/**
 * Whether a live column would break an insert that omits it.
 *
 * `NOT NULL` with no default is the shape, and the two exceptions are the ones
 * where the SERVER supplies the value, so an omitting insert is fine:
 * `auto_increment`, and a `CURRENT_TIMESTAMP` default (which MariaDB reports in
 * `Default`, spelled `current_timestamp()` on some versions).
 *
 * Under a permissive `sql_mode` such a column silently takes `''` or `0`, which
 * is why #1458's `action_type` went years unnoticed; under
 * `STRICT_TRANS_TABLES` the same insert errors outright.
 *
 * @param array{null: string, default: string|null, extra: string} $column As `SHOW COLUMNS` reports it.
 * @return bool
 */
function ffc_schema_column_is_strict_hazard( array $column ): bool {
	if ( 'NO' !== strtoupper( trim( $column['null'] ) ) ) {
		return false;
	}

	if ( null !== $column['default'] && '' !== trim( (string) $column['default'] ) ) {
		return false;
	}

	return false === stripos( $column['extra'], 'auto_increment' );
}

/**
 * The drift between what the tree declares and what one table actually holds.
 *
 * PURE ON PURPOSE. The `SHOW COLUMNS` read stays in the caller so this can be
 * driven from fixtures by a unit test, the way `CsvStreamer` is orchestration
 * over an injectable download. The comparison is the part with judgement in it;
 * the query is not.
 *
 * @param list<string>                                                        $declared Declared column names, lowercased.
 * @param array<string, array{null: string, default: string|null, extra: string}> $live     Live columns keyed by lowercased name.
 * @return array{missing: list<string>, hazard: list<string>, inert: list<string>}
 */
function ffc_schema_drift( array $declared, array $live ): array {
	$declared_set = array();
	foreach ( $declared as $name ) {
		$declared_set[ strtolower( $name ) ] = true;
	}

	$missing = array();
	$hazard  = array();
	$inert   = array();

	foreach ( array_keys( $declared_set ) as $name ) {
		if ( ! isset( $live[ $name ] ) ) {
			$missing[] = $name;
		}
	}

	foreach ( $live as $name => $column ) {
		if ( isset( $declared_set[ strtolower( (string) $name ) ] ) ) {
			continue;
		}

		if ( ffc_schema_column_is_strict_hazard( $column ) ) {
			$hazard[] = (string) $name;
			continue;
		}

		$inert[] = (string) $name;
	}

	sort( $missing );
	sort( $hazard );
	sort( $inert );

	return array(
		'missing' => $missing,
		'hazard'  => $hazard,
		'inert'   => $inert,
	);
}
