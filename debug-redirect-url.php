<?php
/**
 * DEBUG: Verificar Redirect URL Configuration
 *
 * Instruções:
 * 1. Faça upload deste arquivo para: wp-content/plugins/wp-ffcertificate/
 * 2. Acesse: https://dresaomiguel.com.br/wp-content/plugins/wp-ffcertificate/debug-redirect-url.php
 * 3. Copie os resultados e me envie
 * 4. DELETE este arquivo após usar (contém informações sensíveis)
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

echo '<h1>🔍 Debug: Redirect URL Configuration</h1>';
echo '<style>body{font-family:monospace;padding:20px;} table{border-collapse:collapse;margin:20px 0;} td,th{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f0f0f0;}</style>';

// 1. Verificar opção no banco
echo '<h2>1. Configuração Salva no Banco</h2>';
$user_access_settings = get_option('ffc_user_access_settings', array());

if (empty($user_access_settings)) {
    echo '❌ <strong>PROBLEMA:</strong> Opção ffc_user_access_settings NÃO existe ou está vazia!<br>';
    echo 'Solução: Vá em Settings > User Access e clique em "Save Changes"<br><br>';
} else {
    echo '✅ Opção existe no banco<br><br>';
    echo '<table>';
    echo '<tr><th>Key</th><th>Value</th></tr>';
    foreach ($user_access_settings as $key => $value) {
        if ($key === 'redirect_url') {
            echo '<tr style="background:#ffffcc;"><td><strong>' . esc_html($key) . '</strong></td><td><strong>' . esc_html($value) . '</strong></td></tr>';
        } else {
            echo '<tr><td>' . esc_html($key) . '</td><td>' . (is_bool($value) ? ($value ? 'true' : 'false') : esc_html(print_r($value, true))) . '</td></tr>';
        }
    }
    echo '</table>';
}

// 2. Verificar o que o código está lendo
echo '<h2>2. O Que o Código Está Lendo</h2>';
$dashboard_url = isset($user_access_settings['redirect_url']) && !empty($user_access_settings['redirect_url'])
    ? $user_access_settings['redirect_url']
    : home_url('/dashboard');

echo '<table>';
echo '<tr><th>Variável</th><th>Valor</th></tr>';
echo '<tr><td>isset($user_access_settings[\'redirect_url\'])</td><td>' . (isset($user_access_settings['redirect_url']) ? '✅ true' : '❌ false') . '</td></tr>';
echo '<tr><td>!empty($user_access_settings[\'redirect_url\'])</td><td>' . (!empty($user_access_settings['redirect_url']) ? '✅ true' : '❌ false') . '</td></tr>';
echo '<tr style="background:#ffffcc;"><td><strong>$dashboard_url (resultado final)</strong></td><td><strong>' . esc_html($dashboard_url) . '</strong></td></tr>';
echo '<tr><td>home_url(\'/dashboard\')</td><td>' . esc_html(home_url('/dashboard')) . '</td></tr>';
echo '</table>';

// 3. Simular link gerado
echo '<h2>3. Link Que Seria Gerado Para Usuário ID 63</h2>';
$test_user_id = 63;
$view_as_url = add_query_arg(array(
    'ffc_view_as_user' => $test_user_id,
    'ffc_view_nonce' => wp_create_nonce('ffc_view_as_user_' . $test_user_id)
), $dashboard_url);

echo '<table>';
echo '<tr><th>Descrição</th><th>URL</th></tr>';
echo '<tr style="background:#ffffcc;"><td><strong>Link Gerado</strong></td><td><strong><a href="' . esc_url($view_as_url) . '" target="_blank">' . esc_html($view_as_url) . '</a></strong></td></tr>';
echo '</table>';

// 4. Verificar cache
echo '<h2>4. Verificação de Cache</h2>';
echo '<table>';
echo '<tr><th>Tipo de Cache</th><th>Status</th></tr>';

// OPcache
if (function_exists('opcache_get_status')) {
    $opcache_status = opcache_get_status();
    echo '<tr><td>OPcache</td><td>' . ($opcache_status ? '⚠️ Ativo (pode estar cacheando código PHP)' : '✅ Desativado') . '</td></tr>';
} else {
    echo '<tr><td>OPcache</td><td>✅ Não disponível</td></tr>';
}

// Object Cache
echo '<tr><td>Object Cache</td><td>' . (wp_using_ext_object_cache() ? '⚠️ Ativo (Redis/Memcached)' : '✅ Não ativo') . '</td></tr>';

// Plugin de Cache
$cache_plugins = array(
    'wp-super-cache/wp-cache.php' => 'WP Super Cache',
    'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
    'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
    'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
    'wp-rocket/wp-rocket.php' => 'WP Rocket'
);

foreach ($cache_plugins as $plugin_path => $plugin_name) {
    if (is_plugin_active($plugin_path)) {
        echo '<tr><td>' . esc_html($plugin_name) . '</td><td>⚠️ Ativo (limpe o cache)</td></tr>';
    }
}

echo '</table>';

// 5. Ações recomendadas
echo '<h2>5. Ações Recomendadas</h2>';

if (empty($user_access_settings) || empty($user_access_settings['redirect_url'])) {
    echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:5px;">';
    echo '⚠️ <strong>PROBLEMA IDENTIFICADO:</strong> redirect_url não está configurado!<br><br>';
    echo '<strong>Solução:</strong><br>';
    echo '1. Vá em: <a href="' . admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=user_access') . '">Settings > User Access</a><br>';
    echo '2. Configure "URL de Redirecionamento": <code>https://dresaomiguel.com.br/painel-de-usuario/</code><br>';
    echo '3. Clique em "Save Changes"<br>';
    echo '4. Volte aqui e recarregue esta página<br>';
    echo '</div>';
} else {
    if ($dashboard_url === home_url('/dashboard')) {
        echo '<div style="background:#f8d7da;border:1px solid #dc3545;padding:15px;border-radius:5px;">';
        echo '❌ <strong>PROBLEMA:</strong> Código ainda está usando /dashboard/ mesmo com configuração salva!<br><br>';
        echo '<strong>Possível causa:</strong> Cache de código PHP (OPcache)<br><br>';
        echo '<strong>Solução:</strong><br>';
        echo '1. Reinicie PHP-FPM ou Apache<br>';
        echo '2. Ou acesse: <a href="' . admin_url('index.php?ffc_clear_opcache=1') . '">Limpar OPcache</a><br>';
        echo '3. Ou adicione ao wp-config.php: <code>ini_set(\'opcache.enable\', 0);</code><br>';
        echo '</div>';
    } else {
        echo '<div style="background:#d4edda;border:1px solid #28a745;padding:15px;border-radius:5px;">';
        echo '✅ <strong>TUDO CERTO!</strong> Configuração está correta.<br><br>';
        echo 'Se ainda aparecer URL errada na lista de usuários:<br>';
        echo '1. Limpe cache do navegador (Ctrl+Shift+R)<br>';
        echo '2. Limpe cache do WordPress (se usar plugin de cache)<br>';
        echo '3. Recarregue a página de usuários<br>';
        echo '</div>';
    }
}

echo '<br><hr><br>';
echo '<p><strong>⚠️ IMPORTANTE:</strong> DELETE este arquivo após usar! Ele contém informações sobre sua configuração.</p>';
echo '<p>Comando para deletar via SSH: <code>rm ' . __FILE__ . '</code></p>';
?>
