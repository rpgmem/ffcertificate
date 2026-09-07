<?php
/**
 * Level-9 errors, reported only for the classes that read rows out of `$wpdb`.
 *
 * `$wpdb` returns every column as a string — WordPress uses mysqli without
 * native types, so an `INT` column arrives as `'7'` and only `NULL` stays
 * null. Classes that declare a row as `array<string, mixed>` therefore hand
 * PHPStan nothing to check: passing `mixed` into a typed parameter is a
 * level-9 check and the main gate runs at level 8. That is not an oversight
 * in any one class, it is invisible by construction — and it is how a fatal
 * `TypeError` reached production through a green CI (rpgmem/ffcertificate#1058).
 *
 * This report closes that gap without raising the level of the whole
 * repository: `phpstan-rows.neon.dist` analyses the same tree at level 9, and
 * this script narrows the output to the files that actually read rows.
 *
 * The file list is DISCOVERED, never committed. A new class that reads rows is
 * in scope the moment it is written, which is the failure mode a hand-kept
 * list has: the 46th reader is added and nobody notices it is unmeasured.
 *
 * Two things it does not see. A class that reads rows only through a helper
 * (`DatabaseHelperTrait`, a repository) does not match the pattern and is not
 * counted — the helper itself is, which is where the type should be declared
 * anyway. And it cannot tell an honest row shape from a dishonest one:
 * declaring `id: int` when the column arrives as `'7'` satisfies level 9 and
 * keeps the bug. That half is human review against the `CREATE TABLE`, the
 * same limitation `AssertionCoverageTest` documents for itself.
 *
 * Usage:
 *   vendor/bin/phpstan analyse -c phpstan-rows.neon.dist --error-format=json \
 *     --no-progress > rows.json || true
 *   php .github/scripts/phpstan-rows-report.php rows.json [max-errors]
 *
 * Exits non-zero when the count exceeds `max-errors` (default 0).
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

/**
 * Absolute paths of the files that read rows straight from `$wpdb`.
 *
 * **Two receivers, and the second one was missed until #1087.** Most classes
 * call the global `$wpdb->get_results(…)`, but every repository that extends
 * `AbstractRepository` binds wpdb as a property and calls
 * `$this->wpdb->get_results(…)` — which does not contain the substring
 * `$wpdb->` at all. The original pattern required that substring, so eight
 * repositories were invisible to this gate while it reported
 * "Every class that reads a row declares what the row holds": the count was
 * honest, the denominator was not. The scan covers both idioms now.
 *
 * **`$user_query->get_results()` is deliberately NOT matched.** It is
 * `WP_User_Query`, a core object with its own typed return — not a row read
 * off one of the plugin's tables. A pattern loose enough to catch every
 * `->get_results` would pull it in and measure the wrong thing, which is why
 * the receiver is named rather than wildcarded.
 *
 * @param string $includes_dir Absolute path to the plugin's `includes/`.
 * @return array<int, string> Sorted; empty when the scan finds nothing.
 */
function ffc_row_reading_files( string $includes_dir ): array {
	$found = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$source = (string) file_get_contents( $file->getPathname() );

		if ( preg_match( '/\$(?:this->)?wpdb->(get_row|get_results|get_col)\b/', $source ) ) {
			$found[] = $file->getPathname();
		}
	}

	sort( $found );

	return $found;
}

$report_path = $argv[1] ?? '';
$max_errors  = isset( $argv[2] ) ? (int) $argv[2] : 0;

if ( '' === $report_path || ! is_readable( $report_path ) ) {
	fwrite( STDERR, "usage: php phpstan-rows-report.php <phpstan-json> [max-errors]\n" );
	exit( 2 );
}

$decoded = json_decode( (string) file_get_contents( $report_path ), true );

if ( ! is_array( $decoded ) || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
	// A malformed report must fail loudly. Reading it as "no errors" is how a
	// broken analysis becomes a green gate.
	fwrite( STDERR, "Could not read a PHPStan JSON report from {$report_path}.\n" );
	exit( 2 );
}

$root    = dirname( __DIR__, 2 );
$readers = ffc_row_reading_files( $root . '/includes' );

if ( array() === $readers ) {
	fwrite( STDERR, "Found no classes reading rows from \$wpdb — the scan is looking at the wrong tree.\n" );
	exit( 2 );
}

$per_file = array();
$total    = 0;

foreach ( $readers as $file ) {
	if ( ! isset( $decoded['files'][ $file ] ) ) {
		continue;
	}

	$count = count( $decoded['files'][ $file ]['messages'] );

	if ( 0 === $count ) {
		continue;
	}

	$per_file[ str_replace( $root . '/', '', $file ) ] = $count;
	$total                                            += $count;
}

arsort( $per_file );

printf( "Row-reading classes: %d\n", count( $readers ) );
printf( "Level-9 errors in them: %d (allowed: %d)\n\n", $total, $max_errors );

foreach ( $per_file as $file => $count ) {
	printf( "%5d  %s\n", $count, $file );
}

if ( array() === $per_file ) {
	print "None. Every class that reads a row declares what the row holds.\n";
}

if ( $total > $max_errors ) {
	printf( "\nFAIL: %d error(s) over the allowance of %d.\n", $total - $max_errors, $max_errors );
	exit( 1 );
}

print "\nPASS\n";
exit( 0 );
