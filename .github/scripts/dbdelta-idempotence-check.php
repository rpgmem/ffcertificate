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
 * **One table already lives this in production.** `ffc_device_signals` is the
 * only one whose `dbDelta` is deliberately NOT gated on `table_exists()`, so
 * the same call path can serve a fresh install and the 6.3.1→6.3.2 upgrade. It
 * therefore re-runs against an existing table on every activation, in every
 * install — which makes it the first thing this gate should be trusted on.
 *
 * Run inside WordPress (`wp eval-file`), after the plugin is active.
 *
 * Usage: wp eval-file .github/scripts/dbdelta-idempotence-check.php
 *
 * @package FreeFormCertificate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress (wp eval-file).\n" );
	exit( 2 );
}

require_once __DIR__ . '/ffc-create-statements.php';
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
$checked    = 0;

foreach ( $statements as $statement ) {
	$relative = str_replace( dirname( $includes ) . '/', '', $statement['file'] ) . ':' . $statement['line'];

	if ( null === $statement['table'] ) {
		$unresolved[] = $relative;
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

	if ( array() !== $changes ) {
		$drifting[] = $relative . ' (' . $statement['table'] . ")\n      " . implode( "\n      ", $changes );
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

if ( array() !== $drifting ) {
	fwrite(
		STDERR,
		"FAIL: dbDelta wants to change a table it just created from this very statement.\n"
		. "That is an ALTER on every future run — the #997 class, silent and permanent.\n"
		. "Fix the CREATE TABLE so it describes what MySQL actually stores:\n\n  "
		. implode( "\n\n  ", $drifting ) . "\n"
	);
	exit( 1 );
}

echo "PASS: every CREATE TABLE describes what MySQL stored — dbDelta has nothing to alter.\n";
exit( 0 );
