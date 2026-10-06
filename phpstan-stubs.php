<?php
/**
 * PHPStan stubs for plugin constants.
 *
 * Constants are sourced from ffcertificate.php (the single source of truth)
 * via static parsing, so PHPStan and runtime can never drift apart.
 *
 * @package FreeFormCertificate
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Analysis-only stubs for the bundled phpqrcode library, which declares these classes together; never loaded at runtime.

$ffc_stub_loader = static function (): void {
	$plugin_file = __DIR__ . '/ffcertificate.php';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read at PHPStan analysis time, not at runtime.
	$source = is_readable( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';

	preg_match_all(
		"/define\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*'([^']*)'\\s*\\)\\s*;/",
		$source,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		if ( ! defined( $match[1] ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- define() with a variable name: the constant name is parsed out of the plugin's own ffcertificate.php define() statements above.
			define( $match[1], $match[2] );
		}
	}
};
$ffc_stub_loader();
unset( $ffc_stub_loader );

// Constants computed at runtime in ffcertificate.php — stub them statically.
if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
	define( 'FFC_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
	define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
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
	define( 'QR_MODE_8', 2 );
}

/**
 * Stub for the phpqrcode QRcode class.
 */
class QRcode {
	/**
	 * Render the QR code as PNG.
	 *
	 * @param string       $text    Text to encode.
	 * @param string|false $outfile Output file or false to print.
	 * @param int          $level   Error correction level.
	 * @param int          $size    Module size.
	 * @param int          $margin  Margin in modules.
	 */
	public static function png( $text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4 ): void {}

	/**
	 * Return the QR code as rows of '0'/'1' characters (binarised matrix).
	 *
	 * @param string       $text    Text to encode.
	 * @param string|false $outfile Output file or false to return the rows.
	 * @param int          $level   Error correction level.
	 * @return array<int, string>|null
	 */
	public static function text( $text, $outfile = false, $level = QR_ECLEVEL_L ): ?array {
		return array();
	}
}

/**
 * Stub for the phpqrcode QRinput class (the split data of one code).
 */
class QRinput {
	/**
	 * Constructor.
	 *
	 * @param int $version Version, 0 for automatic.
	 * @param int $level   Error correction level.
	 */
	public function __construct( $version = 0, $level = QR_ECLEVEL_L ) {}

	/**
	 * Set the version.
	 *
	 * @param int $version Version.
	 * @return int
	 */
	public function setVersion( $version ): int {
		return 0;
	}

	/**
	 * Encode every entry at the current version.
	 *
	 * @return int Bits, or -1.
	 */
	public function createBitStream(): int {
		return 0;
	}

	/**
	 * Pick the smallest version the data fits; throws when none does.
	 *
	 * @return int 0, or -1.
	 */
	public function convertData(): int {
		return 0;
	}
}

/**
 * Stub for the phpqrcode QRsplit class.
 */
class QRsplit {
	/**
	 * Split a string into encoding-mode entries.
	 *
	 * @param string  $text          Text.
	 * @param QRinput $input         Input to fill.
	 * @param int     $mode_hint     Mode hint.
	 * @param bool    $casesensitive Case sensitivity.
	 * @return int 0, or -1.
	 */
	public static function splitStringToQRinput( $text, QRinput $input, $mode_hint, $casesensitive = true ): int {
		return 0;
	}
}

/**
 * Stub for the phpqrcode QRspec class.
 */
class QRspec {
	/**
	 * Data codewords of a version at a level.
	 *
	 * @param int $version Version.
	 * @param int $level   Error correction level.
	 * @return int
	 */
	public static function getDataLength( $version, $level ): int {
		return 0;
	}
}
