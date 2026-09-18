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
 * The capability planted by the workflow purely to be swept away (#1290).
 *
 * It names nothing: no list in this repository contains it, and no code path
 * grants it. That is the point — only a removal rule keyed on the `ffc_`
 * prefix can take it out, so its disappearance is what distinguishes a sweep
 * from a list of names that happens to be complete today.
 *
 * The workflow writes it literally in a `wp eval`; the `planted` phase below is
 * what fails if the two ever diverge.
 */
const FFC_FRESH_SYNTHETIC_CAP = 'ffc_zz_synthetic';

/**
 * The table planted by the workflow purely to be dropped (#1291).
 *
 * Unprefixed, like everything `ffc_fresh_live_tables()` returns. It appears in
 * no list in this repository and no activator creates it, so only a removal
 * rule that DISCOVERS its set can drop it — which is what distinguishes the
 * sweep from a hand-written list that happens to be complete today.
 *
 * It is created after the activate phase has run, so it never reaches the
 * "created tables all declared" comparison.
 */
const FFC_FRESH_SYNTHETIC_TABLE = 'ffc_zz_synthetic';

/**
 * The user-meta key planted by the workflow purely to be swept away (#1316).
 *
 * Same role as the synthetic capability and the synthetic table: it appears in
 * no list anywhere in this repository and no code path writes it, so only a
 * removal keyed on the `ffc_` prefix takes it out. Returning to a list of names
 * -- which is what step 7 of `uninstall.php` actually was until #1316, while
 * its own comments claimed otherwise -- leaves it behind and fails the
 * uninstall phase.
 *
 * The workflow writes it literally in a `wp eval`; the `planted` phase is what
 * fails if the two ever diverge.
 */
const FFC_FRESH_SYNTHETIC_META = 'ffc_zz_synthetic_meta';

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

/**
 * Every `ffc_*` user-meta row in the database, as `user <id>: <key>` strings.
 *
 * ESCAPED LIKE THE SWEEP ESCAPES, OR IT MEASURES A DIFFERENT SET
 *
 * `_` is a LIKE wildcard, so a bare `ffc_%` also matches an `ffcx_` key. The
 * uninstaller escapes it deliberately -- over-deleting another plugin's meta is
 * worse than leaving residue -- and a reader that did not would report rows the
 * sweep never promised to remove, which is a false failure rather than a
 * finding.
 *
 * `{$prefix}capabilities` carries no `ffc_` prefix, so it is outside this set by
 * construction: grants are swept separately, and the two must not be conflated.
 *
 * There is no `activate`-phase counterpart to this: user meta is written per
 * user at RUNTIME, never by an activation, so a fresh install legitimately has
 * none and a both-ways comparison would compare two empty sets. What it is
 * worth checking is the deletion direction, which is where the defect was.
 *
 * @param string $prefix Meta-key prefix, from the manifest.
 * @return array<int, string>
 */
function ffc_fresh_live_user_meta( string $prefix ): array {
	global $wpdb;
	$like  = $wpdb->esc_like( $prefix ) . '%';
	$rows  = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT user_id, meta_key FROM %i WHERE meta_key LIKE %s ORDER BY user_id, meta_key',
			$wpdb->usermeta,
			$like
		)
	);
	$found = array();
	foreach ( $rows as $row ) {
		$found[] = 'user ' . (int) $row->user_id . ': ' . (string) $row->meta_key;
	}
	return $found;
}

/**
 * Every FFC capability grant left in the database, as `<where>: <cap>` strings.
 *
 * Reads both residues the uninstaller sweeps (#1290): personal grants in the
 * `{prefix}capabilities` user meta, and role definitions in the
 * `{prefix}user_roles` option. Roles and capabilities share that user meta — a
 * role appears in it as `s:12:"ffc_end_user";b:1;`, exactly like a capability —
 * so a stale membership row of a deleted role is reported here too, which is
 * correct: it is residue either way.
 *
 * The unprefixed names are the pre-6.2.0 certificate capabilities the prefix
 * sweep cannot reach; they are read out of `uninstall.php` rather than repeated
 * here, so the two cannot disagree about the set.
 *
 * @param array<int, string> $legacy Unprefixed capability names to also count.
 * @return array<int, string>
 */
function ffc_fresh_live_capabilities( array $legacy ): array {
	global $wpdb;
	$found = array();

	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT user_id, meta_value FROM %i WHERE meta_key = %s',
			$wpdb->usermeta,
			$wpdb->prefix . 'capabilities'
		)
	);
	foreach ( $rows as $row ) {
		$caps = maybe_unserialize( (string) $row->meta_value );
		if ( ! is_array( $caps ) ) {
			continue;
		}
		foreach ( array_keys( $caps ) as $cap ) {
			$cap = (string) $cap;
			if ( 0 === strpos( $cap, 'ffc_' ) || in_array( $cap, $legacy, true ) ) {
				$found[] = 'user ' . (int) $row->user_id . ': ' . $cap;
			}
		}
	}

	// `wp_roles()` reads the persisted `{prefix}user_roles` option and resolves
	// the multisite prefix itself, which a hard-coded option name would not.
	foreach ( wp_roles()->roles as $slug => $definition ) {
		foreach ( array_keys( (array) ( $definition['capabilities'] ?? array() ) ) as $cap ) {
			$cap = (string) $cap;
			if ( 0 === strpos( $cap, 'ffc_' ) || in_array( $cap, $legacy, true ) ) {
				$found[] = 'role ' . (string) $slug . ': ' . $cap;
			}
		}
	}

	sort( $found );
	return $found;
}

// ---------------------------------------------------------------- arguments

$wp_root   = isset( $argv[1] ) ? rtrim( (string) $argv[1], '/' ) : '';
$manifest  = isset( $argv[2] ) ? (string) $argv[2] : '';
$phase     = isset( $argv[3] ) ? (string) $argv[3] : '';

if ( '' === $wp_root || '' === $manifest || ! in_array( $phase, array( 'activate', 'planted', 'uninstall' ), true ) ) {
	fwrite( STDERR, "usage: fresh-install-check.php <wp-root> <uninstall.php> activate|planted|uninstall\n" );
	exit( 1 );
}

if ( ! is_readable( $manifest ) ) {
	fwrite( STDERR, "manifest not readable: {$manifest}\n" );
	exit( 1 );
}

require_once __DIR__ . '/ffc-uninstall-manifest.php';

$expected_tables  = ffc_manifest_tables( $manifest );
$expected_options = ffc_manifest_options( $manifest );
$legacy_caps      = ffc_manifest_legacy_capabilities( $manifest );
$meta_prefix      = ffc_manifest_user_meta_prefix( $manifest );

// A parser that quietly returns nothing would turn every check below into a
// vacuous pass, which is worse than no check at all.
if ( array() === $expected_tables || array() === $expected_options || array() === $legacy_caps || '' === $meta_prefix ) {
	fwrite( STDERR, "could not parse the table/option/capability/user-meta manifest out of uninstall.php — fix the parser.\n" );
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

	// No FFC capability may sit on a role WordPress owns (#1302).
	//
	// This is the direction no static scan reaches: the role is resolved at
	// runtime (`get_role( 'subscriber' )->add_cap( … )`), so only a real
	// activation shows where the capability landed. It would have caught the
	// defect it was written for on the day it shipped — `AudienceActivator`
	// granted `ffc_view_own_audience_bookings` to `subscriber`, on every
	// install, from activation.
	//
	// A fresh install is what makes the reading honest: every role here was
	// either created by this activation or shipped with WordPress, so there is
	// no third party to blame for a stray grant.
	$core_role_grants = array_values(
		array_filter(
			ffc_fresh_live_capabilities( $legacy_caps ),
			static function ( string $entry ): bool {
				if ( 0 !== strpos( $entry, 'role ' ) ) {
					return false;
				}
				$slug = substr( $entry, strlen( 'role ' ), (int) strpos( $entry, ':' ) - strlen( 'role ' ) );
				return 0 !== strpos( $slug, 'ffc_' );
			}
		)
	);
	$failed = ! ffc_fresh_check(
		array() === $core_role_grants,
		'no FFC capability on a core role',
		array() === $core_role_grants
			? 'none'
			: implode( ' | ', $core_role_grants ) . ' — grant it to the user, or to an ffc_ role'
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

} elseif ( 'planted' === $phase ) {

	// THE POSITIVE CONTROL, and the reason it is a phase of its own.
	//
	// `no capabilities left behind` reports `0 remaining` when the sweep worked
	// AND when the reader is broken — an empty scan reading as clean is the
	// #1071 / #1094 class, and the first version of this check had exactly that
	// shape: it shipped green without anyone knowing whether the instrument
	// could see a grant at all.
	//
	// So the same function runs against the same database moments BEFORE the
	// uninstall, when the residue is known to be there. A non-zero here is what
	// makes the zero afterwards a measurement rather than a collapse.
	$planted = ffc_fresh_live_capabilities( $legacy_caps );

	$failed = ! ffc_fresh_check(
		array() !== $planted,
		'the capability reader sees grants',
		array() !== $planted ? count( $planted ) . ' found' : 'NOTHING — the reader is broken, so the uninstall phase would pass vacuously'
	) || $failed;

	// Each shape separately, because they are three different code paths and a
	// reader that sees only user meta would still look healthy above.
	foreach ( array(
		'user ' => 'on a user',
		'role ' => 'on a role',
	) as $where => $label ) {
		$hits   = array_filter(
			$planted,
			static fn( string $entry ): bool => 0 === strpos( $entry, $where )
		);
		$failed = ! ffc_fresh_check(
			array() !== $hits,
			'planted grants seen ' . $label,
			array() !== $hits ? implode( ' | ', $hits ) : 'none — the workflow stopped planting them, or this branch is dead'
		) || $failed;
	}

	$synthetic = array_filter(
		$planted,
		static fn( string $entry ): bool => false !== strpos( $entry, FFC_FRESH_SYNTHETIC_CAP )
	);
	$failed    = ! ffc_fresh_check(
		array() !== $synthetic,
		'the synthetic capability is planted',
		array() !== $synthetic
			? implode( ' | ', $synthetic )
			: FFC_FRESH_SYNTHETIC_CAP . ' was not granted — without it the uninstall phase cannot tell a prefix sweep from a complete list'
	) || $failed;

	$planted_table = in_array( FFC_FRESH_SYNTHETIC_TABLE, ffc_fresh_live_tables(), true );
	$failed        = ! ffc_fresh_check(
		$planted_table,
		'the synthetic table is planted',
		$planted_table
			? FFC_FRESH_SYNTHETIC_TABLE
			: FFC_FRESH_SYNTHETIC_TABLE . ' was not created — without it the uninstall phase cannot tell a discovered set from a complete list'
	) || $failed;

	$legacy_seen = array_filter(
		$planted,
		static fn( string $entry ): bool => (bool) preg_match( '/: (' . implode( '|', array_map( 'preg_quote', $legacy_caps ) ) . ')$/', $entry )
	);
	$failed      = ! ffc_fresh_check(
		array() !== $legacy_seen,
		'an unprefixed legacy name is planted',
		array() !== $legacy_seen ? implode( ' | ', $legacy_seen ) : 'none — the unprefixed half of the sweep is untested'
	) || $failed;

	// The user-meta reader needs its own control for a reason the others do
	// not: there is no `activate` phase in which it runs against a known
	// non-empty set, because an activation writes no user meta at all. Its only
	// other appearance is the uninstall phase, where it is expected to find
	// nothing — so without this step a broken reader would read as a clean
	// sweep forever (#1316).
	$planted_meta = ffc_fresh_live_user_meta( $meta_prefix );

	$failed = ! ffc_fresh_check(
		array() !== $planted_meta,
		'the user-meta reader sees rows',
		array() !== $planted_meta ? implode( ' | ', $planted_meta ) : 'NOTHING — the reader is broken, so the uninstall phase would pass vacuously'
	) || $failed;

	$synthetic_meta = array_filter(
		$planted_meta,
		static fn( string $entry ): bool => false !== strpos( $entry, FFC_FRESH_SYNTHETIC_META )
	);
	$failed         = ! ffc_fresh_check(
		array() !== $synthetic_meta,
		'the synthetic user meta is planted',
		array() !== $synthetic_meta
			? implode( ' | ', $synthetic_meta )
			: FFC_FRESH_SYNTHETIC_META . ' was not written — without it the uninstall phase cannot tell a prefix sweep from a complete list'
	) || $failed;

} else {

	// The uninstaller ran with the Danger Zone opt-in on, so the footprint must
	// be gone. This also proves the manifest above is the one the uninstaller
	// acts on, not a list that merely looks right.
	// The synthetic table planted before the uninstall is the mutation (#1291):
	// it is in no list, so a removal that intersected a hand-written set would
	// leave it here. The reader itself needs no separate control — the activate
	// phase ran the same function and found all 34 declared tables.
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

	// The residue #1290 measured: 40 of the 60 live capabilities survived
	// uninstall, because removal intersected a hand-written list. It is now a
	// prefix sweep over both user meta and role definitions, and this is where
	// that sweep is proven to RUN — the unit guard compares lists with lists and
	// cannot see a loop that never executes.
	//
	// The workflow grants three shapes before deleting the plugin: a live
	// capability, a retired slug no current list names, and `ffc_zz_synthetic`,
	// which has never appeared in any list in this repository. Only a prefix
	// sweep removes the last one, so it is the mutation — hard-coding a list
	// again would leave it behind and fail here.
	$live_caps = ffc_fresh_live_capabilities( $legacy_caps );
	$failed    = ! ffc_fresh_check(
		array() === $live_caps,
		'no capabilities left behind',
		array() === $live_caps ? '0 remaining' : implode( ' | ', $live_caps )
	) || $failed;

	// The residue #1316 measured. Step 7 of `uninstall.php` deleted two meta
	// keys BY NAME while both of its own `phpcs:ignore` justifications claimed
	// it matched a prefix, so everything the user profile writes survived an
	// opt-in uninstall -- including `ffc_user_cpf` / `_rf` / `_rg`, which hold
	// AES-256-CBC ciphertext of the document numbers.
	//
	// No static scan could have caught it: exactly ONE literal `ffc_*` meta key
	// exists under `includes/`, because every other one is assembled at runtime
	// from a prefix plus a field name. And no gate could either -- this job read
	// user meta only for `{$prefix}capabilities`, so the blind spot was
	// structural rather than an oversight in one run.
	//
	// `ffc_zz_synthetic_meta`, planted before the uninstall, is the mutation:
	// it is in no list, so a return to naming keys leaves it here.
	$live_meta = ffc_fresh_live_user_meta( $meta_prefix );
	$failed    = ! ffc_fresh_check(
		array() === $live_meta,
		'no user meta left behind',
		array() === $live_meta ? '0 remaining' : implode( ' | ', $live_meta )
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
