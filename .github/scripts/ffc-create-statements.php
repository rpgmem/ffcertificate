<?php
/**
 * The plugin's `CREATE TABLE` statements, extracted as text (#1087 passo 7).
 *
 * **One extraction, two consumers**, for the reason `uninstall.php` is one
 * manifest: `tests/Unit/ActivatorSqlTest.php` checks the statements against the
 * two dbDelta text rules, and `.github/scripts/dbdelta-idempotence-check.php`
 * runs them against a real MariaDB. A second, private extraction would let the
 * two measure different sets — the denominator failure #1087 spent three steps
 * chasing (the row ruler saw 45 of 53 classes; four guards could pass with an
 * empty scan).
 *
 * The activators are read as **text and never included**: including one would
 * run it.
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

/**
 * Every `CREATE TABLE` literal under `includes/`.
 *
 * The pattern sees double-quoted literals, which is all the plugin uses.
 * {@see ffc_create_statements_present()} counts them by a wider net so a
 * statement written in a shape this cannot see fails loudly instead of being
 * silently exempt.
 *
 * @param string $includes_dir Absolute path to `includes/`.
 * @return array<int, array{file: string, line: int, sql: string, table: string|null}>
 */
function ffc_create_statements( string $includes_dir ): array {
	$out = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}

		$text = (string) file_get_contents( $path );

		if ( ! preg_match_all( '/"CREATE TABLE.*?"\s*;/s', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		$lines = explode( "\n", $text );

		foreach ( $matches[0] as $match ) {
			$line = substr_count( substr( $text, 0, (int) $match[1] ), "\n" ) + 1;

			$out[] = array(
				'file' => $path,
				'line' => $line,
				// Drop the PHP statement terminator, keeping the SQL literal.
				'sql'   => substr( (string) $match[0], 0, -2 ),
				'table' => ffc_resolve_table_name( $lines, $line, $text, $includes_dir ),
			);
		}
	}

	usort(
		$out,
		static function ( array $a, array $b ): int {
			return array( $a['file'], $a['line'] ) <=> array( $b['file'], $b['line'] );
		}
	);

	return $out;
}

/**
 * The `ffc_*` suffix a `CREATE TABLE` writes to.
 *
 * **Four idioms, and the rule that covers them.** The statement always
 * interpolates a variable — `{$table_name}` in most activators, but
 * `$table_limits` / `$table_logs` / `$table_signals` in the rate-limit one — so
 * the variable is read out of the SQL itself rather than assumed. That variable
 * is then assigned either the literal (`$wpdb->prefix . 'ffc_x'`) or a static
 * accessor (`self::get_table_name()`, `SubmissionRepository::get_submissions_table()`)
 * whose body holds the literal.
 *
 * A fifth idiom returns null, and the callers fail on it rather than skipping
 * it — the whole point of #1087 being that a scan which silently covers less
 * than it claims is worse than no scan.
 *
 * @param array<int, string> $lines        Source split by newline.
 * @param int                $line         1-based line of the CREATE.
 * @param string             $text         Whole file, for following an accessor.
 * @param string             $includes_dir Absolute path to `includes/`, for an
 *                                         accessor whose body lives elsewhere.
 * @return string|null The `ffc_*` suffix, or null when no idiom matches.
 */
function ffc_resolve_table_name( array $lines, int $line, string $text, string $includes_dir ): ?string {
	$index = $line - 1;

	// Which variable does the statement interpolate?
	if ( ! preg_match( '/CREATE TABLE \{?(\$[a-z_]+)\}?/i', $lines[ $index ] ?? '', $held ) ) {
		return null;
	}

	$variable = preg_quote( $held[1], '/' );

	// The search stops at the enclosing function, not at a fixed line count.
	// A window is the wrong tool here: the self-scheduling calendars table puts
	// 27 lines of column commentary between its assignment and its SQL, and the
	// rate-limit activator declares all three of its table names at the top and
	// writes the third CREATE 64 lines later. Both defeated a fixed window, in
	// silence — which is the failure mode #1087 exists to stop.
	for ( $i = $index; $i >= 0; $i-- ) {
		$candidate = $lines[ $i ] ?? '';

		// Reached the top of the method without finding the assignment.
		if ( $i < $index && preg_match( '/^\s*(?:public|private|protected|static|function)\s/', $candidate )
			&& ! preg_match( '/' . $variable . '\s*=/', $candidate ) ) {
			return null;
		}

		if ( preg_match( '/' . $variable . '\s*=\s*[^;]*?\'(ffc_[a-z_]+)\'/', $candidate, $literal ) ) {
			return $literal[1];
		}

		// `$table = self::get_table_name();` — follow it to its literal.
		if ( preg_match( '/' . $variable . '\s*=\s*(?:self|static)::([a-z_]+)\(\s*\)/', $candidate, $accessor ) ) {
			return ffc_literal_returned_by( $text, $accessor[1] );
		}

		// `$table = \Some\Class::get_x_table();` — the body lives elsewhere,
		// so the literal is looked up across `includes/` by method name.
		if ( preg_match( '/' . $variable . '\s*=\s*[\\\\A-Za-z0-9_]+::([a-z_]+)\(\s*\)/', $candidate, $foreign ) ) {
			return ffc_literal_returned_anywhere( $includes_dir, $foreign[1] );
		}
	}

	return null;
}

/**
 * The `ffc_*` literal a named method returns, within one file.
 *
 * @param string $text   File contents.
 * @param string $method Method name.
 * @return string|null
 */
function ffc_literal_returned_by( string $text, string $method ): ?string {
	if ( preg_match(
		'/function\s+' . preg_quote( $method, '/' ) . '\s*\([^)]*\)[^{]*\{.*?return[^;]*?\'(ffc_[a-z_]+)\'/s',
		$text,
		$match
	) ) {
		return $match[1];
	}

	return null;
}

/**
 * The `ffc_*` literal a named method returns, looked up across `includes/`.
 *
 * @param string $includes_dir Absolute path to `includes/`.
 * @param string $method       Method name.
 * @return string|null
 */
function ffc_literal_returned_anywhere( string $includes_dir, string $method ): ?string {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}

		$literal = ffc_literal_returned_by( (string) file_get_contents( $path ), $method );

		if ( null !== $literal ) {
			return $literal;
		}
	}

	return null;
}

/**
 * How many `CREATE TABLE` statements are present, by a net wider than the one
 * {@see ffc_create_statements()} uses.
 *
 * Matches any quoting, heredoc included, so the two counts diverge the moment a
 * statement is written in a shape the real extraction cannot see. Comparing
 * them is what keeps this file from exempting a statement in silence.
 *
 * @param string $includes_dir Absolute path to `includes/`.
 * @return int
 */
function ffc_create_statements_present( string $includes_dir ): int {
	$present = 0;

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		if ( substr( $path, -4 ) !== '.php' ) {
			continue;
		}

		$present += preg_match_all( '/[\'"<]\s*CREATE TABLE/i', (string) file_get_contents( $path ) );
	}

	return $present;
}
