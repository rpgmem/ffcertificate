<?php
require_once('wp-load.php');

if (!is_admin()) {
    die('Run from admin');
}

echo "<h1>Migration Status Test</h1>";

// Carregar Migration Manager
require_once WP_PLUGIN_DIR . '/wp-ffcertificate/includes/migrations/class-ffc-migration-manager.php';
$migration_manager = new FFC_Migration_Manager();

// Buscar migration
$migrations = $migration_manager->get_migrations();

echo "<h2>Registered Migrations:</h2>";
echo "<pre>";
print_r($migrations);
echo "</pre>";

// Buscar status da encrypt_sensitive_data
if (isset($migrations['encrypt_sensitive_data'])) {
    echo "<h2>Encrypt Sensitive Data Status:</h2>";
    $status = $migration_manager->get_migration_status('encrypt_sensitive_data');
    echo "<pre>";
    print_r($status);
    echo "</pre>";
} else {
    echo "<p style='color:red;'>Migration 'encrypt_sensitive_data' NOT FOUND!</p>";
}

// Testar contagem direta
global $wpdb;
$table = $wpdb->prefix . 'ffc_submissions';

$total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$pendentes = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE (email_encrypted IS NULL OR email_encrypted = '') AND email IS NOT NULL");
$migradas = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE email_encrypted IS NOT NULL AND email_encrypted != ''");

echo "<h2>Direct Count:</h2>";
echo "<p>Total: {$total}</p>";
echo "<p>Pendentes: {$pendentes}</p>";
echo "<p>Migradas: {$migradas}</p>";
echo "<p>Progresso: " . ($total > 0 ? round(($migradas / $total) * 100, 2) : 0) . "%</p>";
?>