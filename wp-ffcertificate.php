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

// 1. Inclui a classe de ativação
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';

// 2. Carrega a classe de utilitários (Essencial para as tags HTML permitidas)
// Importante: Carregar ANTES do loader para que as outras classes já a enxerguem
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-utils.php';

// 3. Registra o Hook de Ativação
register_activation_hook( __FILE__, array( 'FFC_Activator', 'activate' ) );

// 4. Carrega o núcleo do plugin
require_once FFC_PLUGIN_DIR . 'includes/class-ffc-loader.php';

function run_free_form_certificate() {
    $plugin = new Free_Form_Certificate_Loader();
    $plugin->run();
}

run_free_form_certificate();