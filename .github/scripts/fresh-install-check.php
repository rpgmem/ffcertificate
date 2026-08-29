<?php
/**
 * Fresh-install activation + uninstall check (CI gate).
 *
 * Closes the gap `testes-smoke.php` documents as its KNOWN LIMIT. That script
 * runs against an ESTABLISHED install, so it proves the tables are there — not
 * that a fresh activation would create them. The defect class it therefore
 * cannot see is the one that shipped in 6.0.1: column-level `COMMENT` clauses
 * made `dbDelta()` fail silently and four recruitment tables were never created
 * on activation. On an already-populated site those tables exist from an
 * earlier release, so nothing looks wrong.
 *
 * This runs against a throwaway WordPress installed by the workflow onto an
 * empty database, so `Activator::activate()` executes for real — options and
 * all, which is why an empty schema alone would not do. Unlike the post-deploy
 * smoke it fires on pull requests, so it can gate a merge instead of raising an
 * alarm after one.
 *
 * A fresh install also makes a check possible that no established install can
 * make honestly: the set of `ffc_*` tables and options in the database is
 * EXACTLY what the plugin just created, with no legacy residue to explain away.
 * So the comparison against `uninstall.php` runs in both directions — every
 * declared object must exist, and every existing object must be declared. That
 * second direction is the `ffc_foreign_keys_db_version` class (#991): an option
 * introduced after the audit that enumerated its five siblings, left behind on
 * deletion because nothing forced the list to keep up.
 *
 * The uninstall phase then deletes the plugin with the Danger Zone opt-in on
 * and asserts the footprint is gone — which also proves the manifest this file
 * reads is the manifest the uninstaller actually acts on.
 *
 * WHAT IT STILL DOES NOT PROVE. The runner's MySQL is not the host's MariaDB.
 * Pin the service image to the host's server version (the activate phase prints
 * both `@@version` and `@@sql_mode` for exactly that purpose) and this gets
 * close, but `testes-smoke.php` remains the only check that sees the provider's
 * real stack. The two are complements, not substitutes.
 *
 * Usage:
 *   php fresh-install-check.php <wp-root> <uninstall.php> activate
 *   php fresh-install-check.php <wp-root> <uninstall.php> uninstall
 *
 * The uninstall phase runs after the plugin directory is deleted, so the
 * workflow copies this file and `uninstall.php` out of the tree first — hence
 * the explicit manifest path rather than deriving it from a plugin directory.
 *
 * Exit codes: 0 = pass, 1 = a check failed.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

/**
 * Options that may legitimately exist without appearing in uninstall.php.
 *
 * Add an entry ONLY with the reason inline. An option that is merely missing
 * from the delete list does not belong here — add it to `uninstall.php`, which
 * is the whole point of this check.
 *
 * @var array<string, string> Option name => justification.
 */
const FFC_FRESH_ALLOWED_OPTIONS = array();

/**
 * Print a check result.
 *
 * @param bool   $ok     Whether the check passed.
 * @param string $label  Short check name.
 * @param string $detail Context shown after the label.
 * @param bool   $fatal  Whether a failure should fail the run.
 */
function ffc_fresh_check( bool $ok, string $label, string $detail = '', bool $fatal = true ): bool {
	printf( "  [%-4s] %-38s %s\n", $ok ? 'PASS' : ( $fatal ? 'FAIL' : 'WARN' ), $label, $detail );
	return $ok || ! $fatal;
}

/**
 * Every `ffc_*` table present in the database, unprefixed.
 *
 * @return array<int, string>
 */
function ffc_fresh_live_tables(): array {
	global $wpdb;
	$like  = $wpdb->esc_like( $wpdb->prefix . 'ffc_' ) . '%';
	$rows  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	$found = array();
	foreach ( (array) $rows as $row ) {
		$found[] = substr( (string) $row, strlen( $wpdb->prefix ) );
	}
	sort( $found );
	return $found;
}

/**
 * Every `ffc_*` option present in the database.
 *
 * Transients are stored as `_transient_ffc_*`, i.e. with a leading underscore,
 * so this pattern excludes them by construction; they are checked separately in
 * the uninstall phase.
 *
 * @return array<int, string>
 */
function ffc_fresh_live_options(): array {
	global $wpdb;
	$like = $wpdb->esc_like( 'ffc_' ) . '%';
	$rows = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);
	$found = array_map( 'strval', (array) $rows );
	sort( $found );
	return $found;
}

// ---------------------------------------------------------------- arguments

$wp_root   = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/' ) : '';
$manifest  = isset( $argv[2] ) ? (string) $argv[2] : '';
$phase     = isset( $argv[3] ) ? (string) $argv[3] : '';

if ( '' === $wp_root || '' === $manifest || ! in_array( $phase, array( 'activate', 'uninstall' ), true ) ) {
	fwrite( STDERR, "usage: fresh-install-check.php <wp-root> <uninstall.php> activate|uninstall\n" );
	exit( 1 );
}

if ( ! is_readable( $manifest ) ) {
	fwrite( STDERR, "manifest not readable: {$manifest}\n" );
	exit( 1 );
}

require_once __DIR__ . '/ffc-uninstall-manifest.php';

$expected_tables  = ffc_manifest_tables( $manifest );
$expected_options = ffc_manifest_options( $manifest );

// A parser that quietly returns nothing would turn every check below into a
// vacuous pass, which is worse than no check at all.
if ( array() === $expected_tables || array() === $expected_options ) {
	fwrite( STDERR, "could not parse the table/option manifest out of uninstall.php — fix the parser.\n" );
	exit( 1 );
}

echo "FFC fresh-install check ({$phase})\n";
echo '  wp root  : ' . $wp_root . "\n";
echo '  manifest : ' . count( $expected_tables ) . ' tables, ' . count( $expected_options ) . " options\n";
echo '  php      : ' . PHP_VERSION . "\n\n";

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

require_once $wp_root . '/wp-load.php';

global $wpdb;
$failed = false;

$live_tables  = ffc_fresh_live_tables();
$live_options = ffc_fresh_live_options();

if ( 'activate' === $phase ) {

	// Which server actually answered. Not a check — the number that tells us
	// whether this runner resembles the host closely enough to be believed.
	ffc_fresh_check(
		true,
		'database server',
		(string) $wpdb->get_var( 'SELECT VERSION()' ) . '  sql_mode=' . (string) $wpdb->get_var( 'SELECT @@sql_mode' ),
		false
	);

	$slug   = 'ffcertificate/ffcertificate.php';
	$active = (array) get_option( 'active_plugins', array() );
	$failed = ! ffc_fresh_check( in_array( $slug, $active, true ), 'plugin active', $slug ) || $failed;

	// 1. Every declared table was created by this activation. The 6.0.1 class:
	//    dbDelta accepting a CREATE statement it cannot execute.
	$missing = array_values( array_diff( $expected_tables, $live_tables ) );
	$failed  = ! ffc_fresh_check(
		array() === $missing,
		'declared tables created',
		array() === $missing
			? count( $expected_tables ) . '/' . count( $expected_tables )
			: count( $missing ) . ' never created: ' . implode( ', ', $missing )
	) || $failed;

	// 2. Nothing was created that uninstall.php does not know how to drop. On a
	//    fresh install every ffc_ table is one this activation made, so an
	//    extra can only mean the delete list fell behind an activator.
	$undeclared = array_values( array_diff( $live_tables, $expected_tables ) );
	$failed     = ! ffc_fresh_check(
		array() === $undeclared,
		'created tables all declared',
		array() === $undeclared
			? 'no strays'
			: implode( ', ', $undeclared ) . ' — add to uninstall.php'
	) || $failed;

	// 3. Same, for options. This is the ffc_foreign_keys_db_version case (#991):
	//    a marker written by a migration added after the audit that enumerated
	//    the delete list, so deletion left it behind on every install.
	$stray_options = array_values(
		array_diff( $live_options, $expected_options, array_keys( FFC_FRESH_ALLOWED_OPTIONS ) )
	);
	$failed = ! ffc_fresh_check(
		array() === $stray_options,
		'written options all declared',
		array() === $stray_options
			? count( $live_options ) . ' written, all listed'
			: implode( ', ', $stray_options ) . ' — add to uninstall.php'
	) || $failed;

	// Declared-but-absent options are NOT a failure: most are written later by a
	// migration or a settings save, not by activation. Reported so the list's
	// dead weight stays visible.
	$unwritten = array_values( array_diff( $expected_options, $live_options ) );
	ffc_fresh_check(
		true,
		'declared options not yet written',
		(string) count( $unwritten ) . ' (written on demand, not at activation)',
		false
	);

} else {

	// The uninstaller ran with the Danger Zone opt-in on, so the footprint must
	// be gone. This also proves the manifest above is the one the uninstaller
	// acts on, not a list that merely looks right.
	$failed = ! ffc_fresh_check(
		array() === $live_tables,
		'no tables left behind',
		array() === $live_tables ? '0 remaining' : implode( ', ', $live_tables )
	) || $failed;

	$failed = ! ffc_fresh_check(
		array() === $live_options,
		'no options left behind',
		array() === $live_options ? '0 remaining' : implode( ', ', $live_options )
	) || $failed;

	$transients = (array) $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		  WHERE option_name LIKE '\\_transient\\_ffc\\_%'
		     OR option_name LIKE '\\_transient\\_timeout\\_ffc\\_%'"
	);
	$failed = ! ffc_fresh_check(
		array() === $transients,
		'no transients left behind',
		array() === $transients ? '0 remaining' : implode( ', ', array_map( 'strval', $transients ) )
	) || $failed;
}

echo "\n" . ( $failed ? "FRESH-INSTALL CHECK FAILED\n" : "FRESH-INSTALL CHECK PASSED\n" );
exit( $failed ? 1 : 0 );
