<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment for Free Form Certificate plugin
 *
 * @package FreeFormCertificate
 * @subpackage Tests
 */

// Define test constants
define('FFC_TESTS_DIR', __DIR__);
define('FFC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('FFC_PLUGIN_FILE', FFC_PLUGIN_DIR . 'wp-ffcertificate.php');

// WordPress test constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// Plugin constants
define('FFC_VERSION', '4.0.0');
define('FFC_HTML2CANVAS_VERSION', '1.4.1');
define('FFC_JSPDF_VERSION', '2.5.1');
define('FFC_MIN_WP_VERSION', '5.0');
define('FFC_MIN_PHP_VERSION', '7.4');
define('FFC_DEBUG', false);
define('FFC_PLUGIN_URL', 'http://example.com/wp-content/plugins/wp-ffcertificate/');

// WordPress constants (minimal mock)
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/tmp/wordpress/wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

// Load Composer autoloader
require_once FFC_PLUGIN_DIR . 'vendor/autoload.php';

// Load the plugin's autoloader
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-autoloader.php';
$ffc_autoloader = new FFC_Autoloader(FFC_PLUGIN_DIR . 'includes');
$ffc_autoloader->register();

// Mock WordPress functions that are commonly used
require_once FFC_TESTS_DIR . '/Mocks/wordpress-functions.php';

echo "Free Form Certificate Tests Bootstrap Loaded\n";
echo "Plugin Dir: " . FFC_PLUGIN_DIR . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHPUnit Version: " . PHPUnit\Runner\Version::id() . "\n\n";
