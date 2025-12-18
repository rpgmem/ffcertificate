<?php
/*
Plugin Name: Free Form Certificate
Description: Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
Version: 2.5.0
Author: Alex Meusburger
Text Domain: ffc
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

function run_free_form_certificate() {
    $plugin = new Free_Form_Certificate_Loader();
    $plugin->run();
}

run_free_form_certificate();