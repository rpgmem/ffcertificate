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
 * **The first measurement found 14 such statements**, so the gate blocks against
 * a frozen baseline rather than against zero: `dbdelta-drift-baseline.php` holds
 * what drifts today, a new change fails, and a change that stops happening also
 * fails so the fix is locked in. That file carries the analysis of the three
 * families found and why fixing them is follow-up work rather than part of the
 * pull request that adds the measurement.
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

$baseline_file = __DIR__ . '/dbdelta-drift-baseline.php';
$baseline      = require $baseline_file;

$unresolved = array();
$observed   = array();
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

	// The baseline must not depend on the install's table prefix.
	$changes = array_values(
		array_map(
			static function ( string $change ) use ( $wpdb ): string {
				return str_replace( $wpdb->prefix . 'ffc_', 'ffc_', $change );
			},
			$changes
		)
	);
	sort( $changes );

	$observed[ $relative . '::' . $statement['table'] ] = $changes;

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

ksort( $observed );

echo "Checked against the live schema: {$checked}\n";
echo 'Statements dbDelta wants to alter: ' . count( $observed ) . ' (baseline: ' . count( $baseline ) . ")\n\n";

$appeared  = array();
$vanished  = array();

foreach ( $observed as $key => $changes ) {
	$known = $baseline[ $key ] ?? array();

	foreach ( array_diff( $changes, $known ) as $change ) {
		$appeared[] = $key . "\n      " . $change;
	}
}

foreach ( $baseline as $key => $changes ) {
	foreach ( array_diff( $changes, $observed[ $key ] ?? array() ) as $change ) {
		$vanished[] = $key . "\n      " . $change;
	}
}

// The live schema for the statements whose disagreement is not a type spelling.
// Printed whether or not they are in the baseline: the baseline records that
// they drift, not why, and the why is only visible here.
if ( array() !== $diagnose ) {
	echo "Live schema for the statements that disagree beyond a type spelling:\n\n";

	foreach ( array_keys( $diagnose ) as $table ) {
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		echo '  ' . str_replace( "\n", "\n  ", (string) ( $row[1] ?? '?' ) ) . "\n\n";
	}
}

if ( array() !== $appeared || array() !== $vanished ) {
	if ( array() !== $appeared ) {
		fwrite(
			STDERR,
			"FAIL: dbDelta wants a change that is not in the baseline. Against a table it\n"
			. "just created from this very statement, that is an ALTER on every future run —\n"
			. "the #997 class, silent and permanent. Fix the CREATE TABLE:\n\n  "
			. implode( "\n\n  ", $appeared ) . "\n\n"
		);
	}

	if ( array() !== $vanished ) {
		fwrite(
			STDERR,
			"FAIL: a change in the baseline no longer happens — the drift was fixed. Shrink\n"
			. "the baseline so the win is locked in:\n\n  "
			. implode( "\n\n  ", $vanished ) . "\n\n"
		);
	}

	// There is no local way to regenerate this file: it needs a live MariaDB,
	// and only this job has one. So print what to paste, rather than leaving
	// the next person to transcribe it out of a log.
	fwrite( STDERR, "The measured state, ready to paste into dbdelta-drift-baseline.php:\n\n" );
	fwrite( STDERR, "return array(\n" );

	foreach ( $observed as $key => $changes ) {
		fwrite( STDERR, "\t'" . $key . "' => array(\n" );

		foreach ( $changes as $change ) {
			fwrite( STDERR, "\t\t'" . str_replace( "'", "\\'", $change ) . "',\n" );
		}

		fwrite( STDERR, "\t),\n" );
	}

	fwrite( STDERR, ");\n" );

	exit( 1 );
}

if ( array() === $observed ) {
	echo "PASS: every CREATE TABLE describes what MySQL stored — dbDelta has nothing to alter.\n";
} else {
	echo 'PASS: no new drift. ' . count( $observed ) . " statements still carry the drift the baseline records.\n";
}

exit( 0 );
