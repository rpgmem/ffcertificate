<?php
/**
 * dbDelta idempotence gate (#1087 passo 7).
 *
 * **The class no other gate sees.** `dbDelta()` compares a `CREATE TABLE`
 * against what MySQL actually stored and ALTERs the difference away. When the
 * two agree it changes nothing; when they disagree in a way the statement can
 * never satisfy, it re-ALTERs on **every single run**, forever, silently.
 *
 * Nothing catches that today. `ActivatorSqlTest` checks the statement as text,
 * without a database. The `fresh-install` job starts from an empty database, so
 * it only ever sees the CREATE succeed — never the second pass. The post-deploy
 * smoke runs against an established install and asserts the tables exist, not
 * that the schema still matches. #997 measured this class exactly once, by hand,
 * against a real MariaDB: the three self-scheduling tables produced **41**
 * database errors before the cleanup and 1 after. It has not been measured
 * since, and two `CREATE TABLE` statements changed in #1091 and #1093.
 *
 * **The check.** After a fresh activation, run every `CREATE TABLE` through
 * `dbDelta()` a second time. `dbDelta` returns the changes it made; against a
 * table it just created from that very statement, that list must be **empty**.
 * Anything in it is a change the statement asks for and the schema will never
 * satisfy — an ALTER on every future run.
 *
 * **The first measurement found 14 such statements; all 14 are fixed.** The gate
 * therefore blocks against zero, and the frozen baseline it needed in the
 * meantime is gone. What the three families taught is worth keeping, because
 * each looks like the opposite of what it is:
 *
 * 1. **Write the display width.** `int unsigned` reads cleaner and is wrong:
 *    MariaDB stores `int(10) unsigned` and compares textually, while WP core's
 *    dbDelta ignores a width-only difference on MySQL 8.0.17+ and *explicitly
 *    not* on MariaDB. `int(10)` is correct on both — do not tidy it away.
 * 2. **MariaDB does not store `json`**, only `LONGTEXT` plus a CHECK, so a
 *    `json` column can never match. These columns are declared `longtext`
 *    because nothing in the plugin uses a native JSON function — every read is
 *    `json_decode` in PHP, and `PreflightStatsService` already aggregates in
 *    memory rather than depend on `JSON_EXTRACT`.
 * 3. **Declare the index the code creates, not the one you asked for.**
 *    `Activator::upgrade_auth_code_unique_constraints()` converts `auth_code`
 *    to `UNIQUE INDEX uq_auth_code`, so a declared plain `KEY` never survived.
 *
 * **One table already lives this in production, and it is clean.**
 * `ffc_device_signals` is the only one whose `dbDelta` is deliberately NOT gated
 * on `table_exists()`, so the same call path can serve a fresh install and the
 * 6.3.1→6.3.2 upgrade — it re-runs against an existing table on every
 * activation, in every install. That made it the obvious suspect, and the first
 * measurement cleared it: it is not among the 14. The drift is in tables whose
 * `dbDelta` only ever runs once, where the repeated ALTER is latent rather than
 * live — which is exactly the shape CLAUDE.md describes for the #997 defects,
 * dormant until someone adds a column the normal WordPress way.
 *
 * Runs after the plugin is active, loading WordPress itself — the same shape as
 * `fresh-install-check.php` in this job, and deliberately **not** `wp eval-file`:
 * that command `eval()`s the file, where a `declare(strict_types=1)` is a fatal
 * error rather than a declaration.
 *
 * Usage: php dbdelta-idempotence-check.php <wp-root>
 *
 * Exit codes: 0 = pass, 1 = a statement drifts, 2 = the scan itself is broken.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

$wp_root = (string) ( $argv[1] ?? '' );

if ( '' === $wp_root || ! is_readable( $wp_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: dbdelta-idempotence-check.php <wp-root>\n" );
	exit( 2 );
}

require_once __DIR__ . '/ffc-create-statements.php';

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$includes = dirname( __DIR__, 2 ) . '/includes';

if ( ! is_dir( $includes ) ) {
	// The plugin was rsynced into the WordPress tree, so resolve from there.
	$includes = WP_PLUGIN_DIR . '/ffcertificate/includes';
}

$statements = ffc_create_statements( $includes );
$present    = ffc_create_statements_present( $includes );

echo "CREATE TABLE statements: " . count( $statements ) . "\n";

// A scan that stopped matching would report "no drift" having looked at
// nothing — the exact failure #1087 passo 3 found in the row ruler, and passo 6
// found in four more guards. Fail on it instead.
if ( array() === $statements ) {
	fwrite( STDERR, "FAIL: no CREATE TABLE statement found at all. The scan is broken, not the schema.\n" );
	exit( 2 );
}

if ( count( $statements ) !== $present ) {
	fwrite(
		STDERR,
		sprintf(
			"FAIL: extracted %d statements but %d are present. One is written in a shape the\n"
			. "extraction cannot see (a heredoc, a single-quoted string) and would be silently\n"
			. "exempt from this gate — widen ffc_create_statements().\n",
			count( $statements ),
			$present
		)
	);
	exit( 2 );
}


$unresolved = array();
$drifting   = array();
$diagnose   = array();
$checked    = 0;

foreach ( $statements as $statement ) {
	$relative = str_replace( dirname( $includes ) . '/', '', $statement['file'] );

	if ( null === $statement['table'] ) {
		$unresolved[] = $relative . ':' . $statement['line'];
		continue;
	}

	$table = $wpdb->prefix . $statement['table'];

	// A table the activation did not create is not this gate's business: the
	// module may be off, or the statement may be reached only by a migration.
	// Saying so is better than silently counting it as clean.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		echo "  skip (not created by this activation): {$statement['table']}\n";
		continue;
	}

	// The literal carries the two interpolations every activator uses.
	$sql = str_replace(
		array( '{$table_name}', '$table_name' ),
		$table,
		trim( $statement['sql'], '"' )
	);
	$sql = preg_replace( '/\{?\$table_[a-z_]+\}?/', $table, $sql );
	$sql = str_replace( array( '{$charset_collate}', '$charset_collate' ), $wpdb->get_charset_collate(), (string) $sql );

	$changes = dbDelta( $sql, false );

	++$checked;

	if ( array() === $changes ) {
		continue;
	}

	// The prefix is an install detail; the finding is not.
	$changes = array_map(
		static function ( string $change ) use ( $wpdb ): string {
			return str_replace( $wpdb->prefix . 'ffc_', 'ffc_', $change );
		},
		$changes
	);
	sort( $changes );

	$drifting[] = $relative . ':' . $statement['line'] . ' (' . $statement['table'] . ")\n      "
		. implode( "\n      ", $changes );

	// A changed TYPE is a spelling the server normalises. An ADDED column or
	// index is the statement and the table genuinely disagreeing — dump the
	// live schema for those, since diagnosing them needs the database that
	// only this job has.
	foreach ( $changes as $change ) {
		if ( 0 === strpos( $change, 'Added ' ) ) {
			$diagnose[ $table ] = true;
			break;
		}
	}
}

if ( array() !== $unresolved ) {
	fwrite(
		STDERR,
		"FAIL: the table name could not be read for these statements, so they were never\n"
		. "checked. A fifth naming idiom appeared — teach ffc_resolve_table_name() rather\n"
		. "than leaving them invisible:\n  " . implode( "\n  ", $unresolved ) . "\n"
	);
	exit( 2 );
}

echo "Checked against the live schema: {$checked}\n\n";

if ( array() !== $diagnose ) {
	echo "Live schema for the statements that disagree beyond a type spelling:\n\n";

	foreach ( array_keys( $diagnose ) as $table ) {
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		echo '  ' . str_replace( "\n", "\n  ", (string) ( $row[1] ?? '?' ) ) . "\n\n";
	}
}

if ( array() !== $drifting ) {
	fwrite(
		STDERR,
		"FAIL: dbDelta wants to change a table it just created from this very statement.\n"
		. "That is an ALTER on every run that reaches it — the #997 class, silent and\n"
		. "permanent. Fix the CREATE TABLE so it describes what the server actually\n"
		. "stores; the live schema printed above says what that is:\n\n  "
		. implode( "\n\n  ", $drifting ) . "\n"
	);
	exit( 1 );
}

echo "PASS: every CREATE TABLE describes what the server stored — dbDelta has nothing to alter.\n";
exit( 0 );
