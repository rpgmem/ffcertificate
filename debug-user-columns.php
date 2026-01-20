<?php
/**
 * DEBUG: Verificar geração de links na coluna de usuários
 *
 * Instruções:
 * 1. Faça upload deste arquivo para: wp-content/plugins/wp-ffcertificate/
 * 2. Acesse: https://dresaomiguel.com.br/wp-content/plugins/wp-ffcertificate/debug-user-columns.php
 * 3. Copie os resultados e me envie
 * 4. DELETE este arquivo após usar
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Acesso negado. Você precisa ser administrador.');
}

echo '<h1>🔍 Debug: User Columns Link Generation</h1>';
echo '<style>body{font-family:monospace;padding:20px;line-height:1.6;} table{border-collapse:collapse;margin:20px 0;width:100%;} td,th{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#f0f0f0;font-weight:bold;} .highlight{background:#ffffcc;} .error{background:#ffebee;} .success{background:#e8f5e9;} code{background:#f5f5f5;padding:2px 6px;border-radius:3px;}</style>';

// Test user ID
$test_user_id = 63;

echo '<h2>1. Configuração no Banco de Dados</h2>';
$user_access_settings = get_option('ffc_user_access_settings', array());

echo '<table>';
echo '<tr><th>Item</th><th>Valor</th></tr>';
echo '<tr><td>Opção existe?</td><td>' . (empty($user_access_settings) ? '❌ NÃO' : '✅ SIM') . '</td></tr>';

if (!empty($user_access_settings)) {
    echo '<tr class="highlight"><td><strong>redirect_url configurado</strong></td><td><strong>' . (isset($user_access_settings['redirect_url']) ? esc_html($user_access_settings['redirect_url']) : '❌ NÃO DEFINIDO') . '</strong></td></tr>';

    echo '<tr><td colspan="2"><strong>Todas as configurações:</strong></td></tr>';
    foreach ($user_access_settings as $key => $value) {
        $display_value = is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? implode(', ', $value) : $value);
        echo '<tr><td>&nbsp;&nbsp;' . esc_html($key) . '</td><td>' . esc_html($display_value) . '</td></tr>';
    }
}
echo '</table>';

// Simulate exactly what the code does
echo '<h2>2. Simulação do Código Atual (class-ffc-admin-user-columns.php:67-77)</h2>';

echo '<div style="background:#f5f5f5;padding:15px;border-radius:5px;margin:20px 0;">';
echo '<strong>Código executado:</strong><br><br>';
echo '<code style="display:block;white-space:pre;font-size:11px;">';
echo htmlspecialchars('$user_access_settings = get_option(\'ffc_user_access_settings\', array());
$dashboard_url = isset($user_access_settings[\'redirect_url\']) && !empty($user_access_settings[\'redirect_url\'])
    ? $user_access_settings[\'redirect_url\']
    : home_url(\'/dashboard\');

$view_as_url = add_query_arg(array(
    \'ffc_view_as_user\' => $user_id,
    \'ffc_view_nonce\' => wp_create_nonce(\'ffc_view_as_user_\' . $user_id)
), $dashboard_url);');
echo '</code>';
echo '</div>';

echo '<table>';
echo '<tr><th>Passo</th><th>Resultado</th></tr>';

// Step 1
$step1 = get_option('ffc_user_access_settings', array());
echo '<tr><td>1. get_option(\'ffc_user_access_settings\')</td><td>' . (empty($step1) ? '❌ Array vazio' : '✅ Array com ' . count($step1) . ' itens') . '</td></tr>';

// Step 2
$step2_isset = isset($step1['redirect_url']);
$step2_empty = !empty($step1['redirect_url']);
echo '<tr><td>2. isset($user_access_settings[\'redirect_url\'])</td><td>' . ($step2_isset ? '✅ true' : '❌ false') . '</td></tr>';
echo '<tr><td>3. !empty($user_access_settings[\'redirect_url\'])</td><td>' . ($step2_empty ? '✅ true' : '❌ false') . '</td></tr>';

// Step 3
$dashboard_url = ($step2_isset && $step2_empty) ? $step1['redirect_url'] : home_url('/dashboard');
$class = ($dashboard_url === home_url('/dashboard')) ? 'error' : 'success';
echo '<tr class="' . $class . '"><td><strong>4. $dashboard_url (resultado)</strong></td><td><strong>' . esc_html($dashboard_url) . '</strong></td></tr>';

// Step 4
$view_as_url = add_query_arg(array(
    'ffc_view_as_user' => $test_user_id,
    'ffc_view_nonce' => wp_create_nonce('ffc_view_as_user_' . $test_user_id)
), $dashboard_url);

echo '<tr class="highlight"><td><strong>5. $view_as_url (link final)</strong></td><td><strong><a href="' . esc_url($view_as_url) . '" target="_blank">' . esc_html($view_as_url) . '</a></strong></td></tr>';
echo '</table>';

// Expected vs Actual
echo '<h2>3. Comparação: Esperado vs Atual</h2>';
echo '<table>';
echo '<tr><th>Item</th><th>Valor</th></tr>';
echo '<tr><td>URL que DEVERIA aparecer</td><td><code>https://dresaomiguel.com.br/painel-de-usuario/?ffc_view_as_user=63&amp;ffc_view_nonce=...</code></td></tr>';
echo '<tr class="' . ($dashboard_url !== 'https://dresaomiguel.com.br/painel-de-usuario/' ? 'error' : 'success') . '"><td>URL que ESTÁ sendo gerada</td><td><code>' . esc_html($view_as_url) . '</code></td></tr>';
echo '</table>';

// Cache detection
echo '<h2>4. Detecção de Cache</h2>';
echo '<table>';
echo '<tr><th>Tipo</th><th>Status</th></tr>';

// Check OPcache
if (function_exists('opcache_get_status')) {
    $opcache = opcache_get_status();
    if ($opcache && isset($opcache['opcache_enabled'])) {
        echo '<tr class="error"><td>OPcache PHP</td><td>⚠️ ATIVO - Pode estar cacheando código antigo</td></tr>';

        // Check if this specific file is cached
        $file_path = FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-user-columns.php';
        if (isset($opcache['scripts'][$file_path])) {
            $file_cache = $opcache['scripts'][$file_path];
            echo '<tr class="error"><td>&nbsp;&nbsp;class-ffc-admin-user-columns.php</td><td>🔴 CACHEADO (última atualização: ' . date('Y-m-d H:i:s', $file_cache['timestamp']) . ')</td></tr>';
        }
    } else {
        echo '<tr class="success"><td>OPcache PHP</td><td>✅ Desativado</td></tr>';
    }
} else {
    echo '<tr class="success"><td>OPcache PHP</td><td>✅ Não disponível</td></tr>';
}

// Object cache
echo '<tr><td>Object Cache (Redis/Memcached)</td><td>' . (wp_using_ext_object_cache() ? '⚠️ Ativo' : '✅ Inativo') . '</td></tr>';

// Page cache plugins
$cache_plugins = array(
    'wp-super-cache/wp-cache.php' => 'WP Super Cache',
    'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
    'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
    'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
    'wp-rocket/wp-rocket.php' => 'WP Rocket'
);

$has_cache_plugin = false;
foreach ($cache_plugins as $plugin_path => $plugin_name) {
    if (is_plugin_active($plugin_path)) {
        echo '<tr class="error"><td>' . esc_html($plugin_name) . '</td><td>⚠️ ATIVO</td></tr>';
        $has_cache_plugin = true;
    }
}

if (!$has_cache_plugin) {
    echo '<tr class="success"><td>Plugins de Cache</td><td>✅ Nenhum detectado</td></tr>';
}

echo '</table>';

// File modification time
echo '<h2>5. Data de Modificação dos Arquivos</h2>';
$files_to_check = array(
    'includes/admin/class-ffc-admin-user-columns.php',
    'includes/settings/tabs/class-ffc-tab-user-access.php',
    'includes/settings/views/ffc-tab-user-access.php'
);

echo '<table>';
echo '<tr><th>Arquivo</th><th>Última Modificação</th></tr>';
foreach ($files_to_check as $file) {
    $full_path = FFC_PLUGIN_DIR . $file;
    if (file_exists($full_path)) {
        $mtime = filemtime($full_path);
        echo '<tr><td>' . esc_html($file) . '</td><td>' . date('Y-m-d H:i:s', $mtime) . '</td></tr>';
    } else {
        echo '<tr class="error"><td>' . esc_html($file) . '</td><td>❌ Arquivo não encontrado</td></tr>';
    }
}
echo '</table>';

// Recommendations
echo '<h2>6. Diagnóstico e Soluções</h2>';

if (empty($user_access_settings) || !isset($user_access_settings['redirect_url']) || empty($user_access_settings['redirect_url'])) {
    echo '<div style="background:#ffebee;border-left:4px solid #f44336;padding:15px;margin:20px 0;">';
    echo '<h3>❌ PROBLEMA: Configuração não salva</h3>';
    echo '<p><strong>Causa:</strong> O campo redirect_url não está configurado no banco de dados.</p>';
    echo '<p><strong>Solução:</strong></p>';
    echo '<ol>';
    echo '<li>Acesse: <a href="' . admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=user_access') . '" target="_blank">Settings &gt; User Access</a></li>';
    echo '<li>No campo "Redirect URL", digite: <code>https://dresaomiguel.com.br/painel-de-usuario/</code></li>';
    echo '<li>Clique em "<strong>Save Settings</strong>"</li>';
    echo '<li>Aguarde a mensagem de sucesso</li>';
    echo '<li>Volte aqui e recarregue esta página</li>';
    echo '</ol>';
    echo '</div>';
} else if ($dashboard_url === home_url('/dashboard')) {
    echo '<div style="background:#ffebee;border-left:4px solid #f44336;padding:15px;margin:20px 0;">';
    echo '<h3>❌ PROBLEMA: URL errada mesmo com configuração salva</h3>';
    echo '<p><strong>Causa provável:</strong> Cache de código PHP (OPcache)</p>';
    echo '<p><strong>Soluções (tente na ordem):</strong></p>';
    echo '<ol>';
    echo '<li><strong>Limpar OPcache:</strong> Reinicie o serviço PHP-FPM ou Apache</li>';
    echo '<li><strong>Desativar/Reativar plugin:</strong>';
    echo '<ul>';
    echo '<li>Vá em <a href="' . admin_url('plugins.php') . '" target="_blank">Plugins</a></li>';
    echo '<li>Desative "FFC Certificate"</li>';
    echo '<li>Reative "FFC Certificate"</li>';
    echo '</ul>';
    echo '</li>';
    echo '<li><strong>Forçar recarga do arquivo:</strong>';
    echo '<ul>';
    echo '<li>Via SSH: <code>touch ' . FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-user-columns.php</code></li>';
    echo '</ul>';
    echo '</li>';
    echo '</ol>';
    echo '</div>';
} else {
    echo '<div style="background:#e8f5e9;border-left:4px solid #4caf50;padding:15px;margin:20px 0;">';
    echo '<h3>✅ Configuração CORRETA!</h3>';
    echo '<p>O código está gerando a URL correta: <code>' . esc_html($view_as_url) . '</code></p>';
    echo '<p><strong>Se ainda aparecer URL errada na página de usuários:</strong></p>';
    echo '<ol>';
    echo '<li><strong>Limpe o cache da página:</strong>';
    echo '<ul>';
    if ($has_cache_plugin) {
        echo '<li>Limpe o cache do plugin de cache ativo</li>';
    }
    echo '<li>Force reload no navegador: <kbd>Ctrl+Shift+R</kbd> (Windows/Linux) ou <kbd>Cmd+Shift+R</kbd> (Mac)</li>';
    echo '</ul>';
    echo '</li>';
    echo '<li><strong>Acesse a página de usuários em aba anônima:</strong> <code>' . admin_url('users.php') . '</code></li>';
    echo '</ol>';
    echo '</div>';
}

echo '<hr style="margin:40px 0;">';
echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:5px;">';
echo '<p><strong>⚠️ IMPORTANTE:</strong></p>';
echo '<ul>';
echo '<li><strong>DELETE</strong> este arquivo após usar: <code>rm ' . __FILE__ . '</code></li>';
echo '<li>Este arquivo contém informações sobre sua configuração</li>';
echo '<li>Não deixe este arquivo acessível publicamente</li>';
echo '</ul>';
echo '</div>';
?>
