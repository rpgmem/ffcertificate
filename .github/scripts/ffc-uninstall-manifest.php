<?php
/**
 * The plugin's data footprint, read out of `uninstall.php` as text.
 *
 * `uninstall.php` is already obliged to know every table and every option the
 * plugin creates — it drops them. That makes it the one place in the tree that
 * carries a complete manifest, so the CI checks read it rather than keeping
 * their own copy: a hand-maintained duplicate that silently drifts is exactly
 * the failure those checks exist to catch.
 *
 * It is read as TEXT and never included. Including it would run the
 * uninstaller.
 *
 * Shared by `.github/scripts/testes-smoke.php` (post-deploy alarm, established
 * install) and `.github/scripts/fresh-install-check.php` (CI gate, throwaway
 * install). Both scripts run outside Composer's autoloader — one over SSH on a
 * managed host, one against a downloaded WordPress — so this is a plain
 * `require`, not a class.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

/**
 * Unprefixed table names, e.g. `ffc_submissions`.
 *
 * @param string $uninstall_file Absolute path to uninstall.php.
 * @return array<int, string> Sorted, unique; empty when the parse fails.
 */
function ffc_manifest_tables( string $uninstall_file ): array {
	$text = (string) file_get_contents( $uninstall_file );
	if ( ! preg_match_all( "/\\\$wpdb->prefix\s*\.\s*'(ffc_[a-z0-9_]+)'/", $text, $m ) ) {
		return array();
	}
	$tables = array_values( array_unique( $m[1] ) );
	sort( $tables );
	return $tables;
}

/**
 * Option names the uninstaller deletes explicitly.
 *
 * Scoped to the `$ffcertificate_options` array so that option names mentioned
 * elsewhere in the file (the Danger Zone gate reads `ffc_settings`, the
 * transient sweep matches a LIKE pattern) are not mistaken for members of the
 * delete list.
 *
 * @param string $uninstall_file Absolute path to uninstall.php.
 * @return array<int, string> Sorted, unique; empty when the parse fails.
 */
function ffc_manifest_options( string $uninstall_file ): array {
	$text = (string) file_get_contents( $uninstall_file );
	if ( ! preg_match( '/\$ffcertificate_options\s*=\s*array\((.*?)\n\);/s', $text, $block ) ) {
		return array();
	}
	if ( ! preg_match_all( "/'(ffc_[a-z0-9_]+)'/", $block[1], $m ) ) {
		return array();
	}
	$options = array_values( array_unique( $m[1] ) );
	sort( $options );
	return $options;
}

/**
 * Capability names the uninstaller removes BY NAME rather than by prefix.
 *
 * Capability removal is a prefix sweep — every `ffc_*` key on every user and
 * every role — so there is no list of live capabilities to parse and no list to
 * fall behind (#1290). What remains is the handful of pre-6.2.0 names that
 * carry no prefix, which the sweep cannot reach; that is what this returns.
 *
 * An empty result is a parse failure, not "the plugin removes nothing by name":
 * callers must treat it as such, the way the two functions above are treated.
 *
 * @param string $uninstall_file Absolute path to uninstall.php.
 * @return array<int, string> Sorted, unique; empty when the parse fails.
 */
function ffc_manifest_legacy_capabilities( string $uninstall_file ): array {
	$text = (string) file_get_contents( $uninstall_file );
	if ( ! preg_match( '/\$ffcertificate_legacy_caps\s*=\s*array\((.*?)\n\);/s', $text, $block ) ) {
		return array();
	}
	// Quoted only, so the `// ffc_view_own_certificates` comment naming each
	// replacement is not read back as a member of the list.
	if ( ! preg_match_all( "/'([a-z0-9_]+)'/", $block[1], $m ) ) {
		return array();
	}
	$caps = array_values( array_unique( $m[1] ) );
	sort( $caps );
	return $caps;
}
