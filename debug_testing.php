<?php
/**
 * Debug SEGURO: Simula lógica da lista sem instanciar WP_List_Table
 */

require_once('wp-load.php');

if ( ! current_user_can( 'manage_options' ) ) {
    die('Acesso negado. Faça login como admin.');
}

echo "<h2>🔍 Debug Seguro: Simulação da Lista</h2>";

global $wpdb;
$table_name = FFC_Utils::get_submissions_table()';

// 1. Simular prepare_items()
echo "<h3>1. Simulando prepare_items():</h3>";

$per_page = 20;
$current_page = 1;
$status = 'publish';
$search = '';

$where = array( $wpdb->prepare( "status = %s", $status ) );
if ( ! empty( $search ) ) {
    $where[] = $wpdb->prepare( "(email LIKE %s OR data LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' );
}
$where_clause = 'WHERE ' . implode( ' AND ', $where );

$orderby = 'id';
$order = 'DESC';

echo "Query WHERE: <code>$where_clause</code><br>";
echo "ORDER BY: <code>$orderby $order</code><br>";

// 2. Executar query
$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where_clause" );
echo "Total items encontrados: <strong>$total_items</strong><br>";

$offset = ( $current_page - 1 ) * $per_page;

$items = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM $table_name $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d", $per_page, $offset ),
    ARRAY_A
);

echo "Items retornados pela query: <strong>" . count($items) . "</strong><br>";

if (count($items) == 0) {
    echo "<p style='color: red;'><strong>❌ PROBLEMA: Query não retornou nenhum item!</strong></p>";
    echo "<p>Verifique:</p>";
    echo "<ul>";
    echo "<li>Status correto? WHERE status='$status'</li>";
    echo "<li>Registros existem? Total na tabela: " . $wpdb->get_var("SELECT COUNT(*) FROM $table_name") . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color: green;'>✅ Query OK: Items foram carregados</p>";
}

// 3. Simular format_data_preview para cada item
echo "<h3>2. Simulando Renderização:</h3>";

if (count($items) > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>ID</th>";
    echo "<th>Email</th>";
    echo "<th>CPF/RF</th>";
    echo "<th>Data (Preview)</th>";
    echo "<th>Date</th>";
    echo "</tr>";
    
    foreach ($items as $item) {
        echo "<tr>";
        
        // ID
        echo "<td>" . $item['id'] . "</td>";
        
        // Email
        echo "<td>" . esc_html($item['email']) . "</td>";
        
        // CPF/RF (formato)
        $cpf_rf = $item['cpf_rf'];
        if (!empty($cpf_rf)) {
            if (strlen($cpf_rf) === 11) {
                $formatted = substr($cpf_rf, 0, 3) . '.' . 
                            substr($cpf_rf, 3, 3) . '.' . 
                            substr($cpf_rf, 6, 3) . '-' . 
                            substr($cpf_rf, 9, 2);
            } else {
                $formatted = $cpf_rf;
            }
            echo "<td><code>$formatted</code></td>";
        } else {
            echo "<td>—</td>";
        }
        
        // Data (preview) - TESTAR LÓGICA
        $data_json = $item['data'];
        $data_preview = '';
        
        // ✅ Tratar NULL, 'null', vazio
        if ( $data_json === null || $data_json === 'null' || $data_json === '' ) {
            $data_preview = '<em style="color: #666;">Only mandatory fields</em>';
        } else {
            // Decodificar JSON
            $data = json_decode( $data_json, true );
            if ( ! is_array( $data ) ) {
                $data = json_decode( stripslashes( $data_json ), true );
            }
            
            // Se não é array, erro
            if ( ! is_array( $data ) ) {
                $data_preview = '<em style="color: #999;">Invalid data</em>';
            } elseif ( empty( $data ) ) {
                // Array vazio (só campos obrigatórios)
                $data_preview = '<em style="color: #666;">Only mandatory fields</em>';
            } else {
                // Processar campos extras
                $skip_fields = array( 'email', 'user_email', 'e-mail', 'auth_code', 'cpf_rf', 'cpf', 'rf', 'is_edited', 'edited_at' );
                $preview_items = array();
                $count = 0;
                
                foreach ( $data as $key => $value ) {
                    if ( in_array( $key, $skip_fields ) || $count >= 3 ) {
                        continue;
                    }
                    
                    if ( is_array( $value ) ) {
                        $value = implode( ', ', $value );
                    }
                    
                    $value = strlen( $value ) > 40 ? substr( $value, 0, 40 ) . '...' : $value;
                    $label = ucfirst( str_replace( '_', ' ', $key ) );
                    $preview_items[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value );
                    $count++;
                }
                
                if ( empty( $preview_items ) ) {
                    $data_preview = '<em style="color: #666;">Only mandatory fields</em>';
                } else {
                    $data_preview = implode( '<br>', $preview_items );
                }
            }
        }
        
        echo "<td>$data_preview</td>";
        
        // Date
        echo "<td>" . date_i18n( 'Y-m-d H:i', strtotime($item['submission_date']) ) . "</td>";
        
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>Nenhum item para renderizar.</p>";
}

// 4. Debug do campo data
echo "<hr><h3>3. Debug do Campo 'data':</h3>";
if (count($items) > 0) {
    $sample = $items[0];
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Info</th><th>Valor</th></tr>";
    echo "<tr><td>data (raw)</td><td>" . var_export($sample['data'], true) . "</td></tr>";
    echo "<tr><td>data (length)</td><td>" . strlen($sample['data']) . " caracteres</td></tr>";
    echo "<tr><td>is null?</td><td>" . ($sample['data'] === null ? 'SIM' : 'NÃO') . "</td></tr>";
    echo "<tr><td>is 'null'?</td><td>" . ($sample['data'] === 'null' ? 'SIM' : 'NÃO') . "</td></tr>";
    echo "<tr><td>is empty?</td><td>" . (empty($sample['data']) ? 'SIM' : 'NÃO') . "</td></tr>";
    
    $decoded = json_decode($sample['data'], true);
    echo "<tr><td>json_decode</td><td>" . var_export($decoded, true) . "</td></tr>";
    echo "<tr><td>is_array?</td><td>" . (is_array($decoded) ? 'SIM' : 'NÃO') . "</td></tr>";
    echo "<tr><td>empty(decoded)?</td><td>" . (empty($decoded) ? 'SIM' : 'NÃO') . "</td></tr>";
    echo "</table>";
}

echo "<hr><p><strong>✅ Debug Completo!</strong></p>";
echo "<p>Se a TABELA ACIMA mostra os dados, mas a lista admin está vazia, o problema está na renderização do WP_List_Table.</p>";