<?php
/*
Plugin Name: Free Form Certificate
Description: Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
Version: 2.9.16
Author: Alex Meusburger
Text Domain: ffc
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized version management
 */
define( 'FFC_VERSION', '2.9.16' );       // Plugin version
// External libraries versions
define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );  // html2canvas - https://html2canvas.hertzen.com/
define( 'FFC_JSPDF_VERSION', '2.5.1' );        // jsPDF - https://github.com/parallax/jsPDF

define( 'FFC_MIN_WP_VERSION', '5.0' );  // Minimum WordPress
define( 'FFC_MIN_PHP_VERSION', '7.4' ); // Minimum PHP
define( 'FFC_DEBUG', false );           // Debug mode
define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin textdomain
 */
function ffc_load_textdomain() {
    load_plugin_textdomain( 'ffc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ffc_load_textdomain' );

/**
 * ✅ Load critical classes for activation hook
 * 
 * IMPORTANT: These must be loaded BEFORE register_activation_hook
 * because FFC_Activator::activate() needs them
 */
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-utils.php';              // 1. Utils (used by Migration Manager)
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-migration-manager.php';  // 2. Migration Manager (used by Activator)
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';          // 3. Activator (uses Migration Manager)

/**
 * Register activation hook
 * 
 * ✅ Now FFC_Migration_Manager exists when activate() is called
 */
register_activation_hook( __FILE__, array( 'FFC_Activator', 'activate' ) );

/**
 * Load the main plugin loader
 * 
 * Loader will load all other dependencies
 */
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

/**
 * Run the plugin
 */
function run_free_form_certificate() {
    $plugin = new Free_Form_Certificate_Loader();
    $plugin->run();
}

run_free_form_certificate();