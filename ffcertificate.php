<?php
/**
 * Plugin Name:        Free Form Certificate
 * Plugin URI:         https://github.com/rpgmem/ffcertificate
 * Update URI:         https://github.com/rpgmem/ffcertificate
 * Description:        Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.
 * Version:            6.43.0
 * Requires at least:  6.8
 * Requires PHP:       8.3
 * Author:             Alex Meusburger
 * Author URI:         https://github.com/rpgmem
 * License:            AGPLv3 or later
 * License URI:        https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:        ffcertificate
 * Domain Path:        /languages
 *
 * @package FreeFormCertificate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized version management
 */
define( 'FFC_VERSION', '6.43.0' );                 // Plugin version (WordPress Plugin Check compliance)
// External libraries versions.
define( 'FFC_HTML2CANVAS_PRO_VERSION', '2.5.0' ); // html2canvas-pro - https://github.com/yorickshan/html2canvas-pro (MIT, vendored at libs/js/).
define( 'FFC_JSPDF_VERSION', '4.2.1' );         // jsPDF - https://github.com/parallax/jsPDF.
define( 'FFC_THUMBMARK_VERSION', '1.12.0' );    // thumbmarkjs - https://github.com/thumbmarkjs/thumbmarkjs (MIT, vendored at libs/js/).
define( 'FFC_ALTCHA_VERSION', '3.4.0' );        // ALTCHA widget - https://github.com/altcha-org/altcha (MIT, vendored at libs/js/).

define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load the PSR-4 autoloader for the FreeFormCertificate\* namespace.
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-autoloader.php';

// Register the autoloader.
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
 * Bootstrap the plugin by instantiating the main Loader class.
 *
 * @return void
 */
function ffcertificate_run(): void {
	new \FreeFormCertificate\Loader();
}

ffcertificate_run();
