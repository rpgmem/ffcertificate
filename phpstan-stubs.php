<?php
/**
 * PHPStan stubs for plugin constants.
 *
 * Constants are sourced from ffcertificate.php (the single source of truth)
 * via static parsing, so PHPStan and runtime can never drift apart.
 *
 * @package FreeFormCertificate
 */

(static function (): void {
	$plugin_file = __DIR__ . '/ffcertificate.php';
	$source      = is_readable( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';

	preg_match_all(
		"/define\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*'([^']*)'\\s*\\)\\s*;/",
		$source,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		if ( ! defined( $match[1] ) ) {
			define( $match[1], $match[2] );
		}
	}
})();

// Constants computed at runtime in ffcertificate.php — stub them statically.
if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
	define( 'FFC_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
	define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
}
if ( ! defined( 'FFC_DEBUG' ) ) {
	define( 'FFC_DEBUG', false );
}

// WordPress DB constant.
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress' );
}

// phpqrcode constants and class stub (the library lives in includes/libraries
// which is excluded from analysis).
if ( ! defined( 'QR_ECLEVEL_L' ) ) {
	define( 'QR_ECLEVEL_L', 0 );
	define( 'QR_ECLEVEL_M', 1 );
	define( 'QR_ECLEVEL_Q', 2 );
	define( 'QR_ECLEVEL_H', 3 );
}

/**
 * Stub for phpqrcode QRcode class.
 */
class QRcode {
	/**
	 * @param string       $text    Text to encode.
	 * @param string|false $outfile Output file or false to print.
	 * @param int          $level   Error correction level.
	 * @param int          $size    Module size.
	 * @param int          $margin  Margin in modules.
	 */
	public static function png( $text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4 ): void {}

	/**
	 * @param string       $text    Text to encode.
	 * @param string|false $outfile Output file or false to return raw.
	 * @param int          $level   Error correction level.
	 * @return array<int, string>
	 */
	public static function raw( $text, $outfile = false, $level = QR_ECLEVEL_L ): array {
		return array();
	}
}
