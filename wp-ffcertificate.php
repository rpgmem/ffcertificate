<?php
/*
Plugin Name: Free Form Certificate
Plugin URI:  https://github.com/rpgmem/wp-ffcertificate
Description: Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
Version: 4.0.0
Author: Alex Meusburger
Author URI: https://github.com/rpgmem
Text Domain: ffc
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized version management
 */
define( 'FFC_VERSION', '4.0.0' );              // Plugin version (Namespaces Phase 4 - BC Aliases Removed)
// External libraries versions
define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );   // html2canvas - https://html2canvas.hertzen.com/
define( 'FFC_JSPDF_VERSION', '2.5.1' );         // jsPDF - https://github.com/parallax/jsPDF

define( 'FFC_MIN_WP_VERSION', '5.0' );          // Minimum WordPress
define( 'FFC_MIN_PHP_VERSION', '7.4' );         // Minimum PHP
define( 'FFC_DEBUG', false );                   // Debug mode
define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * ✅ PSR-4 Autoloader (Phase 1-4: Namespace Migration Complete)
 *
 * Load the PSR-4 autoloader to enable namespace support.
 * All classes use FreeFormCertificate\* namespace.
 *
 * ⚠️ BREAKING CHANGE (v4.0.0): Old class names (FFC_*) removed.
 * Use namespaced classes: FreeFormCertificate\Core\Utils, etc.
 *
 * @since 3.2.0 (Phase 1-2)
 * @since 4.0.0 (Phase 4 - BC aliases removed)
 */
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-autoloader.php';

// Register the autoloader
$ffc_autoloader = new FFC_Autoloader( FFC_PLUGIN_DIR . 'includes' );
$ffc_autoloader->register();

/**
 * ✅ Load critical classes for activation hook
 *
 * IMPORTANT: These must be loaded BEFORE register_activation_hook
 * Autoloader will handle loading via global namespace prefix (\FFC_*)
 */
require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-utils.php';
require_once FFC_PLUGIN_DIR . 'includes/migrations/class-ffc-migration-manager.php';
require_once FFC_PLUGIN_DIR . 'includes/security/class-ffc-rate-limit-activator.php';
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';

/**
 * Register activation hook
 *
 * Uses global namespace prefix for backward compatibility during transition
 */
register_activation_hook( __FILE__, array( '\FFC_Activator', 'activate' ) );

/**
 * Load the main plugin loader
 */
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

/**
 * Run the plugin
 */
function run_free_form_certificate() {
    $plugin = new \FreeFormCertificate\Loader();
    $plugin->run();
}

run_free_form_certificate();