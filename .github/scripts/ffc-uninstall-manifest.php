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
