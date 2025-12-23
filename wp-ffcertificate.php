<?php
/*
Plugin Name: Free Form Certificate
Description: Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
Version: 2.6.0
Author: Alex Meusburger
Text Domain: ffc
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Constants
define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load Translation Files
 * This ensures the plugin looks into the /languages folder for translation strings.
 */
function ffc_load_textdomain() {
    load_plugin_textdomain( 'ffc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ffc_load_textdomain' );

// 1. Include the activation class
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';

// 2. Load the utilities class (Essential for allowed HTML tags)
// Important: Load BEFORE the loader so other classes can access it
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-utils.php';

// 3. Register Activation Hook
register_activation_hook( __FILE__, array( 'FFC_Activator', 'activate' ) );

// 4. Load the plugin core loader
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

/**
 * Initialize the plugin logic
 */
function run_free_form_certificate() {
    $plugin = new Free_Form_Certificate_Loader();
    $plugin->run();
}

run_free_form_certificate();