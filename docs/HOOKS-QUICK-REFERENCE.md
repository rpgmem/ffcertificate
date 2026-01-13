# 📋 HOOKS - REFERÊNCIA RÁPIDA

## 🔔 **ACTIONS - Lista Completa**

| Hook | Parâmetros | Quando dispara |
|------|------------|----------------|
| `ffc_before_form_render` | `$form_id` | Antes de exibir formulário |
| `ffc_after_form_render` | `$form_id` | Após exibir formulário |
| `ffc_before_submission_process` | `$form_id`, `$data` | Antes de processar submissão |
| `ffc_after_submission_saved` | `$submission_id`, `$form_id`, `$data` | Após salvar no banco |
| `ffc_submission_status_changed` | `$submission_id`, `$old_status`, `$new_status` | Ao mudar status |
| `ffc_before_pdf_generate` | `$submission_id`, `$form_id` | Antes de gerar PDF |
| `ffc_after_pdf_generated` | `$submission_id`, `$pdf_path` | Após gerar PDF |
| `ffc_qr_code_generated` | `$submission_id`, `$qr_path` | Após gerar QR code |
| `ffc_before_email_send` | `$to`, `$subject`, `$submission_id` | Antes de enviar email |
| `ffc_after_email_sent` | `$to`, `$result`, `$submission_id` | Após enviar email |
| `ffc_admin_menu_registered` | - | Após registrar menus admin |
| `ffc_bulk_action_executed` | `$action`, `$submission_ids`, `$count` | Após ação em massa |

---

## 🔧 **FILTERS - Lista Completa**

| Hook | Parâmetros | Retorno | Quando usar |
|------|------------|---------|-------------|
| `ffc_form_fields` | `$fields`, `$form_id` | `array` | Modificar campos do form |
| `ffc_form_config` | `$config`, `$form_id` | `array` | Modificar config do form |
| `ffc_allowed_html_tags` | `$allowed` | `array` | Adicionar tags HTML permitidas |
| `ffc_validate_submission_data` | `$errors`, `$data`, `$form_id` | `array` | Validação customizada |
| `ffc_cpf_validation_required` | `$required`, `$form_id` | `bool` | Habilitar/desabilitar validação CPF |
| `ffc_pdf_content` | `$content`, `$submission_id`, `$data` | `string` | Modificar conteúdo PDF |
| `ffc_pdf_filename` | `$filename`, `$submission_id`, `$data` | `string` | Modificar nome arquivo PDF |
| `ffc_qr_code_data` | `$qr_data`, `$submission_id` | `string` | Modificar dados QR code |
| `ffc_email_subject` | `$subject`, `$submission_id`, `$form_id` | `string` | Modificar assunto email |
| `ffc_email_body` | `$body`, `$submission_id`, `$data` | `string` | Modificar corpo email |
| `ffc_email_headers` | `$headers`, `$submission_id` | `array` | Modificar headers email |
| `ffc_admin_columns` | `$columns` | `array` | Modificar colunas admin |
| `ffc_success_message` | `$message`, `$form_id`, `$submission_id` | `string` | Modificar mensagem sucesso |
| `ffc_rate_limit_config` | `$config` | `array` | Modificar config rate limit |
| `ffc_honeypot_enabled` | `$enabled`, `$form_id` | `bool` | Habilitar/desabilitar honeypot |

---

## 🎯 **USE CASES - Mapa Rápido**

| Necessidade | Hook a usar | Exemplo |
|-------------|-------------|---------|
| Integrar com CRM | `ffc_after_submission_saved` | Enviar dados para HubSpot |
| Backup de PDFs | `ffc_after_pdf_generated` | Upload para S3 |
| Notificações Slack | `ffc_after_submission_saved` | Webhook Slack |
| Validação customizada | `ffc_validate_submission_data` | Validar telefone |
| Adicionar watermark | `ffc_pdf_content` | Inserir marca d'água |
| Tracking Analytics | `ffc_after_submission_saved` | Google Analytics event |
| Email customizado | `ffc_email_body` | Template próprio |
| Campos dinâmicos | `ffc_form_fields` | Adicionar seleção |
| Assinatura digital | `ffc_after_pdf_generated` | TCPDF signature |
| Sistema aprovação | `ffc_submission_status_changed` | Workflow |

---

## 💡 **SNIPPETS ÚTEIS**

### **1. Log todas as submissões:**
```php
add_action('ffc_after_submission_saved', function($id, $form_id, $data) {
    error_log("New submission #$id from " . $data['email']);
}, 10, 3);
```

### **2. Desabilitar email para form específico:**
```php
add_filter('ffc_send_email', function($send, $form_id) {
    return $form_id !== 5; // Não enviar para form 5
}, 10, 2);
```

### **3. Adicionar campo de telefone:**
```php
add_filter('ffc_form_fields', function($fields) {
    $fields[] = ['name' => 'phone', 'label' => 'Telefone', 'required' => true];
    return $fields;
});
```

### **4. Customizar nome do PDF:**
```php
add_filter('ffc_pdf_filename', function($filename, $id, $data) {
    return sanitize_file_name($data['name']) . '-certificate.pdf';
}, 10, 3);
```

### **5. Webhook genérico:**
```php
add_action('ffc_after_submission_saved', function($id, $form_id, $data) {
    wp_remote_post('https://your-webhook.com', [
        'body' => json_encode(['id' => $id, 'data' => $data])
    ]);
}, 10, 3);
```

---

## 🚨 **ERROS COMUNS**

### **Erro 1: Hook não executa**
```php
// ❌ ERRADO - Falta priority e accepted_args
add_action('ffc_after_submission_saved', 'my_function');

// ✅ CORRETO
add_action('ffc_after_submission_saved', 'my_function', 10, 3);
```

### **Erro 2: Dados indefinidos**
```php
// ❌ ERRADO
$email = $data['email']; // Pode não existir

// ✅ CORRETO
$email = $data['email'] ?? 'sem-email@exemplo.com';
```

### **Erro 3: Filter sem return**
```php
// ❌ ERRADO
add_filter('ffc_pdf_content', function($content) {
    $content .= 'Footer';
    // Esqueceu de retornar!
});

// ✅ CORRETO
add_filter('ffc_pdf_content', function($content) {
    return $content . 'Footer';
});
```

---

## 📊 **PRIORIDADES**

| Prioridade | Quando usar | Exemplo |
|-----------|-------------|---------|
| 1-5 | Executar ANTES de tudo | Modificações críticas |
| 10 | Padrão | Maioria dos casos |
| 15-20 | Executar DEPOIS | Limpeza, log final |
| 999 | Debug, última chance | Logging completo |

---

## 🔗 **LINKS RÁPIDOS**

- **Doc completa:** HOOKS-DOCUMENTATION.md
- **Exemplos práticos:** Ver seção "Exemplos Práticos" na doc
- **Casos reais:** Ver seção "Casos de Uso Reais" na doc

---

**Versão:** 1.0.0  
**Última atualização:** 2026-01-13
