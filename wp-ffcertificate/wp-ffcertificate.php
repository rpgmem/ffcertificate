<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://seusite.com.br
 * @since             2.5.0
 * @package           FFC
 *
 * Plugin Name:       WP Free Form Certificate
 * Plugin URI:        https://seusite.com.br/wp-ffcertificate
 * Description:       Solução completa para geração de Certificados e Tickets em PDF via formulários personalizados.
 * Version:           2.5.0
 * Author:            Alex Meusburger
 * Author URI:        https://seusite.com.br
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       ffc
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Definição de Constantes
// Adicionamos FFC_VERSION para controle de cache dos arquivos CSS/JS
define( 'FFC_VERSION', '2.5.0' );
define( 'FFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FFC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// 2. Inclui arquivos essenciais
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

// 3. Registra os Ganchos de Ciclo de Vida (Ativação e Desativação)
function activate_wp_ffc() {
    FFC_Activator::activate();
}

function deactivate_wp_ffc() {
    FFC_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wp_ffc' );
register_deactivation_hook( __FILE__, 'deactivate_wp_ffc' );

// 4. Inicia o Plugin
function run_wp_free_form_certificate() {
    $plugin = new Free_Form_Certificate_Loader();
    $plugin->run();
}

run_wp_free_form_certificate();