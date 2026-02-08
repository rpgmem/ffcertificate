<?php
/**
 * PHPUnit bootstrap file for FFCertificate unit tests.
 *
 * Unit tests run WITHOUT WordPress loaded. We mock WP functions
 * via Brain\Monkey so tests are fast and isolated.
 *
 * @package FreeFormCertificate\Tests
 */

// Composer autoloader.
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
    echo "Run 'composer install' before running tests.\n";
    exit( 1 );
}

require_once $autoloader;

// Define WordPress constants BEFORE loading plugin files
// (the FFC autoloader calls exit() if ABSPATH is not defined).
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
    define( 'FFC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
    define( 'FFC_PLUGIN_URL', 'https://example.com/wp-content/plugins/ffcertificate/' );
}
if ( ! defined( 'FFC_VERSION' ) ) {
    define( 'FFC_VERSION', '4.6.12' );
}

// Register the plugin's own PSR-4 autoloader (WordPress file naming conventions).
require_once dirname( __DIR__ ) . '/includes/class-ffc-autoloader.php';
$ffc_autoloader = new \FFC_Autoloader( dirname( __DIR__ ) . '/includes' );
$ffc_autoloader->register();
