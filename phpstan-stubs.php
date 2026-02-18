<?php
/**
 * PHPStan stubs for plugin constants.
 * These are defined in ffcertificate.php at runtime.
 */

define( 'FFC_VERSION', '4.12.25' );
define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );
define( 'FFC_JSPDF_VERSION', '2.5.1' );
define( 'FFC_JQUERY_UI_VERSION', '1.12.1' );
define( 'FFC_MIN_WP_VERSION', '6.2' );
define( 'FFC_MIN_PHP_VERSION', '7.4' );
define( 'FFC_DEBUG', false );
define( 'FFC_PLUGIN_DIR', '/path/to/plugin/' );
define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );

// WordPress DB constant
define( 'DB_NAME', 'wordpress' );

// phpqrcode constants and class stub
define( 'QR_ECLEVEL_L', 0 );
define( 'QR_ECLEVEL_M', 1 );
define( 'QR_ECLEVEL_Q', 2 );
define( 'QR_ECLEVEL_H', 3 );

class QRcode {
	/**
	 * @param string      $text
	 * @param string|false $outfile
	 * @param int          $level
	 * @param int          $size
	 * @param int          $margin
	 * @return void
	 */
	public static function png( $text, $outfile = false, $level = QR_ECLEVEL_L, $size = 3, $margin = 4 ) {}
}
