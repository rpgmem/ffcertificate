<?php
/**
 * Post-deploy smoke for the testes host.
 *
 * Runs ON the testes server (over SSH) right after the rsync, boots the real
 * WordPress install and asserts the things only a real managed-hosting stack
 * can tell us — the host's own MariaDB, its PHP SAPI, its wp-config. It is NOT
 * a merge gate and cannot be one: `deploy-develop.yml` fires on push to
 * `develop`, i.e. after the merge. It is an alarm.
 *
 * It exists because two defect classes have historically escaped CI:
 *
 *   1. A deploy that silently did not land. #628: the rsync backoff was shorter
 *      than a managed-hosting restart, so every attempt fell inside the same
 *      outage and the site quietly stayed on a stale version. Comparing the
 *      host's live FFC_VERSION against the commit being deployed catches that
 *      directly.
 *   2. dbDelta failing against the provider's actual MySQL/MariaDB. Backtick
 *      comments read as columns (#358), a migration pointing at a table name
 *      that never existed (#822), and column-level COMMENT clauses that left
 *      four recruitment tables uncreated on activation (6.0.1). CI's MySQL is
 *      not the host's MariaDB, so only the host can answer this.
 *
 * The expected table list is PARSED out of `uninstall.php` (see
 * `ffc-uninstall-manifest.php`, shared with the CI fresh-install check), never
 * duplicated here. That file is already obliged to know every table (it drops
 * them), so a new table is covered the moment it is added there — and a
 * hand-maintained copy that silently drifts is exactly the failure this script
 * is meant to catch. `uninstall.php` is read as text and never included:
 * including it would run the uninstaller.
 *
 * KNOWN LIMIT — this runs against an ESTABLISHED install, so the table check
 * proves the tables are there, not that a fresh activation would create them.
 * It catches a table added in this release that failed to appear (activation
 * re-runs dbDelta on upgrade), but not a regression in the CREATE statement of
 * a table that already exists. That gap is covered separately by
 * `fresh-install-check.php`, which installs a throwaway WordPress in CI and
 * activates into an empty database — a real install, because
 * `Activator::activate()` reads and writes options and so cannot run against a
 * bare schema. What stays here alone is the provider's actual stack: its
 * MariaDB, its PHP SAPI, its wp-config. The two are complements — the CI check
 * gates a merge, this one raises an alarm after a deploy.
 *
 * Usage (from the deploy workflow):
 *   php testes-smoke.php <absolute-plugin-dir> <expected-FFC_VERSION>
 *
 * Exit codes: 0 = pass or deliberately skipped, 1 = a check failed.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

// The plugin's own floor; the web SAPI meets it, but managed hosts often ship a
// different (older) CLI binary. Rather than fail forever for a reason unrelated
// to the code, say so plainly and skip.
const FFC_SMOKE_MIN_PHP = '8.3';

/**
 * Print a check result and remember whether anything failed.
 *
 * @param bool   $ok      Whether the check passed.
 * @param string $label   Short check name.
 * @param string $detail  Context shown after the label.
 * @param bool   $fatal   Whether a failure should fail the run.
 */
function ffc_smoke_check( bool $ok, string $label, string $detail = '', bool $fatal = true ): bool {
	$mark = $ok ? 'PASS' : ( $fatal ? 'FAIL' : 'WARN' );
	printf( "  [%-4s] %-34s %s\n", $mark, $label, $detail );
	return $ok || ! $fatal;
}

/**
 * Locate wp-load.php by walking up from the plugin directory.
 *
 * The standard layout puts it three levels up (plugins → wp-content → root),
 * but installs that relocate wp-content need the walk.
 *
 * @param string $plugin_dir Absolute plugin directory.
 * @return string|null Absolute path, or null when not found.
 */
function ffc_smoke_find_wp_load( string $plugin_dir ): ?string {
	$dir = $plugin_dir;
	for ( $i = 0; $i < 6; $i++ ) {
		$dir = dirname( $dir );
		if ( '/' === $dir || '' === $dir ) {
			break;
		}
		if ( is_readable( $dir . '/wp-load.php' ) ) {
			return $dir . '/wp-load.php';
		}
	}
	return null;
}

// The expected table list is parsed out of `uninstall.php` by the shared
// manifest reader, which `fresh-install-check.php` also uses — one parser, so
// the two checks cannot disagree about what the plugin's footprint is.
require_once __DIR__ . '/ffc-uninstall-manifest.php';

// ---------------------------------------------------------------- arguments

$plugin_dir = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/' ) : '';
$expected   = isset( $argv[2] ) ? trim( (string) $argv[2] ) : '';

if ( '' === $plugin_dir || '' === $expected ) {
	fwrite( STDERR, "usage: testes-smoke.php <plugin-dir> <expected-version>\n" );
	exit( 1 );
}

echo "FFC post-deploy smoke\n";
echo '  plugin dir      : ' . $plugin_dir . "\n";
echo '  expected version: ' . $expected . "\n";
echo '  php (cli)       : ' . PHP_VERSION . "\n\n";

if ( version_compare( PHP_VERSION, FFC_SMOKE_MIN_PHP, '<' ) ) {
	echo "SKIPPED — the host's PHP CLI is older than the plugin's floor ("
		. FFC_SMOKE_MIN_PHP . "). The web SAPI may still be fine; this only means\n"
		. "the CLI cannot boot the plugin. Point the workflow at the host's newer PHP\n"
		. "binary (many managed hosts ship one as `php83`) to enable the smoke.\n";
	exit( 0 );
}

// ------------------------------------------------------- pre-boot filesystem

$failed = false;

$uninstall = $plugin_dir . '/uninstall.php';
$main_file = $plugin_dir . '/ffcertificate.php';

$failed = ! ffc_smoke_check( is_readable( $main_file ), 'plugin file present', $main_file ) || $failed;
$failed = ! ffc_smoke_check( is_readable( $uninstall ), 'uninstall.php present', $uninstall ) || $failed;

if ( $failed ) {
	echo "\nAborting: the deploy did not land a usable plugin directory.\n";
	exit( 1 );
}

$expected_tables = ffc_manifest_tables( $uninstall );
$failed          = ! ffc_smoke_check(
	array() !== $expected_tables,
	'table list parsed',
	count( $expected_tables ) . ' tables from uninstall.php'
) || $failed;

if ( array() === $expected_tables ) {
	echo "\nAborting: could not read the table list from uninstall.php — the smoke's\n"
		. "source of truth changed shape. Fix the parser rather than hardcoding a list.\n";
	exit( 1 );
}

// The resolved path is deliberately NOT printed: it carries the site's
// hostname, and the workflow log is not the place for it. GitHub masks the
// configured secrets, but this path is derived rather than a secret, so it
// would come through in full. On failure the abort message below says what to
// do, which is the part an operator actually needs.
$wp_load = ffc_smoke_find_wp_load( $plugin_dir );
$failed  = ! ffc_smoke_check( null !== $wp_load, 'wp-load.php located', null !== $wp_load ? 'found' : 'not found' ) || $failed;

if ( null === $wp_load ) {
	echo "\nAborting: no WordPress install found above the plugin directory.\n";
	exit( 1 );
}

// ------------------------------------------------------------------ boot WP

// WordPress reads these even on CLI; leaving them unset produces noisy notices
// that would drown the report.
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';

// A fatal in the plugin surfaces here as a non-zero exit with PHP's own error —
// which is precisely the loudest signal this smoke can give.
require_once $wp_load;

echo "\n";

// ------------------------------------------------------------------- checks

// 1. The plugin is actually active. A deploy that lands files onto a
//    deactivated plugin looks healthy from the filesystem and does nothing.
$plugin_slug = basename( $plugin_dir ) . '/ffcertificate.php';
$active      = (array) get_option( 'active_plugins', array() );
$network     = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
$is_active   = in_array( $plugin_slug, $active, true ) || in_array( $plugin_slug, $network, true );
$failed      = ! ffc_smoke_check( $is_active, 'plugin active', $plugin_slug ) || $failed;

// 2. The running version matches the commit that was just deployed. This is the
//    #628 check: a silently-failed rsync leaves the host on the old version
//    while the workflow reports success.
$live   = defined( 'FFC_VERSION' ) ? (string) FFC_VERSION : '(constant undefined)';
$failed = ! ffc_smoke_check(
	$live === $expected,
	'live version matches deploy',
	$live === $expected ? $live : $live . ' on host, expected ' . $expected
) || $failed;

// 3. Every table uninstall.php knows about exists. This is the dbDelta check
//    that CI's MySQL cannot make on the host's MariaDB.
global $wpdb;
$missing = array();
foreach ( $expected_tables as $table ) {
	$full  = $wpdb->prefix . $table;
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) );
	if ( $found !== $full ) {
		$missing[] = $table;
	}
}
$failed = ! ffc_smoke_check(
	array() === $missing,
	'schema tables present',
	array() === $missing
		? count( $expected_tables ) . '/' . count( $expected_tables )
		: count( $missing ) . ' missing: ' . implode( ', ', $missing )
) || $failed;

// 4. Which database server actually answered, reported only. CI's fresh-install
//    check runs against a MySQL/MariaDB service image; these two values are how
//    that image gets pinned to what the host really runs, so the dbDelta
//    behaviour CI observes is the behaviour production will get.
ffc_smoke_check(
	true,
	'database server',
	(string) $wpdb->get_var( 'SELECT VERSION()' ) . '  sql_mode=' . (string) $wpdb->get_var( 'SELECT @@sql_mode' ),
	false
);

// 5. Scheduled events, reported only. Which crons should exist depends on the
//    per-module toggles (#800), so a strict expectation here would fail for a
//    deliberate configuration rather than a defect.
$scheduled = array();
foreach ( (array) _get_cron_array() as $events ) {
	foreach ( array_keys( (array) $events ) as $hook ) {
		if ( str_starts_with( (string) $hook, 'ffc' ) ) {
			$scheduled[ $hook ] = true;
		}
	}
}
$hooks = array_keys( $scheduled );
sort( $hooks );
ffc_smoke_check(
	array() !== $hooks,
	'ffc cron events scheduled',
	array() === $hooks ? 'none scheduled' : implode( ', ', $hooks ),
	false
);

echo "\n" . ( $failed ? "SMOKE FAILED\n" : "SMOKE PASSED\n" );
exit( $failed ? 1 : 0 );
