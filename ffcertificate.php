<?php
/*
Plugin Name:        Free Form Certificate
Plugin URI:         https://github.com/rpgmem/ffcertificate
Description:        Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
Version:            5.0.0
Requires PHP:       7.4
Author:             Alex Meusburger
Author URI:         https://github.com/rpgmem
License:             GPLv3 or later
License URI:         https://www.gnu.org/licenses/gpl-3.0.html
Text Domain:        ffcertificate
Domain Path:        /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized version management
 */
define( 'FFC_VERSION', '5.0.0' );                // Plugin version (WordPress Plugin Check compliance)
// External libraries versions
define( 'FFC_HTML2CANVAS_VERSION', '1.4.1' );   // html2canvas - https://html2canvas.hertzen.com/
define( 'FFC_JSPDF_VERSION', '2.5.1' );         // jsPDF - https://github.com/parallax/jsPDF
define( 'FFC_JQUERY_UI_VERSION', '1.12.1' );    // jQuery UI theme (CDN) - https://code.jquery.com/ui/

define( 'FFC_MIN_WP_VERSION', '6.2' );          // Minimum WordPress (required for %i identifier placeholder)
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
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Scoped to plugin bootstrap, not a public API.
$ffc_autoloader = new FFC_Autoloader( FFC_PLUGIN_DIR . 'includes' );
$ffc_autoloader->register();

/**
 * Register activation hook
 *
 * ✅ All classes loaded via PSR-4 autoloader (registered above)
 * No manual require_once needed - autoloader handles everything
 */
register_activation_hook( __FILE__, array( '\FreeFormCertificate\Activator', 'activate' ) );

/**
 * Run the plugin
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Public API function
function ffcertificate_run() {
    new \FreeFormCertificate\Loader();
}

ffcertificate_run();