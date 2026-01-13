# 📚 DOCUMENTAÇÃO COMPLETA DE HOOKS - Form for Certificates

## 🎯 **INTRODUÇÃO**

Este documento lista todos os **actions** e **filters** disponíveis no plugin Form for Certificates, permitindo que desenvolvedores estendam e customizem o comportamento do plugin.

---

## 📖 **ÍNDICE**

1. [Actions (Ações)](#actions)
2. [Filters (Filtros)](#filters)
3. [Exemplos Práticos](#exemplos-práticos)
4. [Casos de Uso Reais](#casos-de-uso-reais)

---

## 🔔 **ACTIONS**

Actions permitem executar código customizado em pontos específicos do ciclo de vida do plugin.

### **1. Formulário e Submissões**

#### **`ffc_before_form_render`**
Executado antes do formulário ser renderizado no frontend.

**Parâmetros:**
- `$form_id` (int) - ID do formulário

**Exemplo:**
```php
add_action('ffc_before_form_render', function($form_id) {
    // Adicionar analytics tracking
    echo '<script>trackFormView(' . $form_id . ');</script>';
}, 10, 1);
```

---

#### **`ffc_after_form_render`**
Executado após o formulário ser renderizado.

**Parâmetros:**
- `$form_id` (int) - ID do formulário

**Exemplo:**
```php
add_action('ffc_after_form_render', function($form_id) {
    // Adicionar informações adicionais
    echo '<p class="form-help">Precisa de ajuda? <a href="/contato">Entre em contato</a></p>';
}, 10, 1);
```

---

#### **`ffc_before_submission_process`**
Executado antes de processar a submissão.

**Parâmetros:**
- `$form_id` (int) - ID do formulário
- `$data` (array) - Dados submetidos

**Exemplo:**
```php
add_action('ffc_before_submission_process', function($form_id, $data) {
    // Log de tentativa de submissão
    error_log('Form ' . $form_id . ' submission attempt by ' . $data['email']);
}, 10, 2);
```

---

#### **`ffc_after_submission_saved`**
Executado após salvar submissão no banco.

**Parâmetros:**
- `$submission_id` (int) - ID da submissão criada
- `$form_id` (int) - ID do formulário
- `$data` (array) - Dados salvos

**Exemplo:**
```php
add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    // Integrar com CRM
    $crm = new MyCRM();
    $crm->createContact([
        'name' => $data['name'],
        'email' => $data['email'],
        'source' => 'Certificate Form #' . $form_id
    ]);
}, 10, 3);
```

---

#### **`ffc_submission_status_changed`**
Executado quando o status de uma submissão muda.

**Parâmetros:**
- `$submission_id` (int) - ID da submissão
- `$old_status` (string) - Status anterior
- `$new_status` (string) - Novo status

**Exemplo:**
```php
add_action('ffc_submission_status_changed', function($submission_id, $old_status, $new_status) {
    if ($new_status === 'trash') {
        // Notificar admin sobre certificado deletado
        wp_mail('admin@site.com', 'Certificado deletado', 'ID: ' . $submission_id);
    }
}, 10, 3);
```

---

### **2. PDF e Certificados**

#### **`ffc_before_pdf_generate`**
Executado antes de gerar o PDF.

**Parâmetros:**
- `$submission_id` (int) - ID da submissão
- `$form_id` (int) - ID do formulário

**Exemplo:**
```php
add_action('ffc_before_pdf_generate', function($submission_id, $form_id) {
    // Preparar dados adicionais
    update_post_meta($submission_id, '_pdf_generation_time', time());
}, 10, 2);
```

---

#### **`ffc_after_pdf_generated`**
Executado após gerar o PDF.

**Parâmetros:**
- `$submission_id` (int) - ID da submissão
- `$pdf_path` (string) - Caminho do PDF gerado

**Exemplo:**
```php
add_action('ffc_after_pdf_generated', function($submission_id, $pdf_path) {
    // Fazer backup do PDF
    copy($pdf_path, '/backup/certificates/' . basename($pdf_path));
    
    // Ou enviar para S3, Dropbox, etc
}, 10, 2);
```

---

#### **`ffc_qr_code_generated`**
Executado após gerar QR code.

**Parâmetros:**
- `$submission_id` (int) - ID da submissão
- `$qr_path` (string) - Caminho do QR code

**Exemplo:**
```php
add_action('ffc_qr_code_generated', function($submission_id, $qr_path) {
    // Adicionar watermark ao QR code
    $image = imagecreatefrompng($qr_path);
    // ... adicionar marca d'água
    imagepng($image, $qr_path);
}, 10, 2);
```

---

### **3. Email**

#### **`ffc_before_email_send`**
Executado antes de enviar email.

**Parâmetros:**
- `$to` (string) - Email destinatário
- `$subject` (string) - Assunto
- `$submission_id` (int) - ID da submissão

**Exemplo:**
```php
add_action('ffc_before_email_send', function($to, $subject, $submission_id) {
    // Log de envio
    error_log("Sending certificate to: $to");
}, 10, 3);
```

---

#### **`ffc_after_email_sent`**
Executado após enviar email.

**Parâmetros:**
- `$to` (string) - Email destinatário
- `$result` (bool) - Sucesso do envio
- `$submission_id` (int) - ID da submissão

**Exemplo:**
```php
add_action('ffc_after_email_sent', function($to, $result, $submission_id) {
    if (!$result) {
        // Notificar admin se falhar
        wp_mail('admin@site.com', 'Falha no envio', "Não foi possível enviar para $to");
    }
}, 10, 3);
```

---

### **4. Admin**

#### **`ffc_admin_menu_registered`**
Executado após registrar menus do admin.

**Parâmetros:** Nenhum

**Exemplo:**
```php
add_action('ffc_admin_menu_registered', function() {
    // Adicionar submenu customizado
    add_submenu_page(
        'edit.php?post_type=ffc_form',
        'Relatórios',
        'Relatórios',
        'manage_options',
        'ffc-reports',
        'my_custom_reports_page'
    );
});
```

---

#### **`ffc_bulk_action_executed`**
Executado após ação em massa no admin.

**Parâmetros:**
- `$action` (string) - Tipo de ação (approve, trash, etc)
- `$submission_ids` (array) - IDs afetados
- `$count` (int) - Quantidade

**Exemplo:**
```php
add_action('ffc_bulk_action_executed', function($action, $submission_ids, $count) {
    if ($action === 'approve') {
        // Enviar notificação em massa
        foreach ($submission_ids as $id) {
            // ... enviar email de aprovação
        }
    }
}, 10, 3);
```

---

## 🔧 **FILTERS**

Filters permitem modificar dados antes de serem processados ou exibidos.

### **1. Formulário**

#### **`ffc_form_fields`**
Filtra campos do formulário antes de renderizar.

**Parâmetros:**
- `$fields` (array) - Array de campos
- `$form_id` (int) - ID do formulário

**Retorno:** `array` - Campos modificados

**Exemplo:**
```php
add_filter('ffc_form_fields', function($fields, $form_id) {
    // Adicionar campo customizado
    $fields[] = [
        'name' => 'phone',
        'label' => 'Telefone',
        'type' => 'text',
        'required' => true
    ];
    return $fields;
}, 10, 2);
```

---

#### **`ffc_form_config`**
Filtra configuração do formulário.

**Parâmetros:**
- `$config` (array) - Configuração do formulário
- `$form_id` (int) - ID do formulário

**Retorno:** `array` - Configuração modificada

**Exemplo:**
```php
add_filter('ffc_form_config', function($config, $form_id) {
    // Forçar tamanho de papel
    $config['pdf_size'] = 'A4';
    $config['pdf_orientation'] = 'landscape';
    return $config;
}, 10, 2);
```

---

#### **`ffc_allowed_html_tags`**
Filtra tags HTML permitidas (já implementado em FFC_Utils).

**Parâmetros:**
- `$allowed` (array) - Tags permitidas

**Retorno:** `array` - Tags modificadas

**Exemplo:**
```php
add_filter('ffc_allowed_html_tags', function($allowed) {
    // Permitir tag <video>
    $allowed['video'] = [
        'src' => true,
        'controls' => true,
        'width' => true,
        'height' => true
    ];
    return $allowed;
});
```

---

### **2. Validação**

#### **`ffc_validate_submission_data`**
Filtra/valida dados antes de salvar.

**Parâmetros:**
- `$errors` (array) - Erros de validação
- `$data` (array) - Dados submetidos
- `$form_id` (int) - ID do formulário

**Retorno:** `array` - Array de erros (vazio se válido)

**Exemplo:**
```php
add_filter('ffc_validate_submission_data', function($errors, $data, $form_id) {
    // Validação customizada
    if (isset($data['phone']) && !preg_match('/^\d{10,11}$/', $data['phone'])) {
        $errors[] = 'Telefone inválido';
    }
    return $errors;
}, 10, 3);
```

---

#### **`ffc_cpf_validation_required`**
Filtra se validação de CPF é obrigatória.

**Parâmetros:**
- `$required` (bool) - Se é obrigatório
- `$form_id` (int) - ID do formulário

**Retorno:** `bool`

**Exemplo:**
```php
add_filter('ffc_cpf_validation_required', function($required, $form_id) {
    // Desativar validação para form específico
    if ($form_id === 42) {
        return false;
    }
    return $required;
}, 10, 2);
```

---

### **3. PDF e Conteúdo**

#### **`ffc_pdf_content`**
Filtra conteúdo do PDF antes de gerar.

**Parâmetros:**
- `$content` (string) - Conteúdo HTML
- `$submission_id` (int) - ID da submissão
- `$data` (array) - Dados da submissão

**Retorno:** `string` - Conteúdo modificado

**Exemplo:**
```php
add_filter('ffc_pdf_content', function($content, $submission_id, $data) {
    // Adicionar watermark
    $watermark = '<div style="position:absolute;top:50%;left:50%;opacity:0.1;font-size:72px;">DRAFT</div>';
    return $watermark . $content;
}, 10, 3);
```

---

#### **`ffc_pdf_filename`**
Filtra nome do arquivo PDF.

**Parâmetros:**
- `$filename` (string) - Nome do arquivo
- `$submission_id` (int) - ID da submissão
- `$data` (array) - Dados

**Retorno:** `string` - Nome modificado

**Exemplo:**
```php
add_filter('ffc_pdf_filename', function($filename, $submission_id, $data) {
    // Nome baseado em dados
    $name = sanitize_file_name($data['name']);
    return 'certificate-' . $name . '-' . time() . '.pdf';
}, 10, 3);
```

---

#### **`ffc_qr_code_data`**
Filtra dados do QR code.

**Parâmetros:**
- `$qr_data` (string) - URL/dados do QR
- `$submission_id` (int) - ID da submissão

**Retorno:** `string` - Dados modificados

**Exemplo:**
```php
add_filter('ffc_qr_code_data', function($qr_data, $submission_id) {
    // Adicionar parâmetros tracking
    return add_query_arg(['utm_source' => 'qrcode'], $qr_data);
}, 10, 2);
```

---

### **4. Email**

#### **`ffc_email_subject`**
Filtra assunto do email.

**Parâmetros:**
- `$subject` (string) - Assunto
- `$submission_id` (int) - ID da submissão
- `$form_id` (int) - ID do formulário

**Retorno:** `string` - Assunto modificado

**Exemplo:**
```php
add_filter('ffc_email_subject', function($subject, $submission_id, $form_id) {
    $form = get_post($form_id);
    return '[' . $form->post_title . '] ' . $subject;
}, 10, 3);
```

---

#### **`ffc_email_body`**
Filtra corpo do email.

**Parâmetros:**
- `$body` (string) - Corpo do email (HTML)
- `$submission_id` (int) - ID da submissão
- `$data` (array) - Dados

**Retorno:** `string` - Corpo modificado

**Exemplo:**
```php
add_filter('ffc_email_body', function($body, $submission_id, $data) {
    // Adicionar footer customizado
    $footer = '<p style="color:#999;">Enviado por ' . get_bloginfo('name') . '</p>';
    return $body . $footer;
}, 10, 3);
```

---

#### **`ffc_email_headers`**
Filtra headers do email.

**Parâmetros:**
- `$headers` (array) - Headers
- `$submission_id` (int) - ID da submissão

**Retorno:** `array` - Headers modificados

**Exemplo:**
```php
add_filter('ffc_email_headers', function($headers, $submission_id) {
    // Adicionar reply-to
    $headers[] = 'Reply-To: suporte@meusite.com';
    return $headers;
}, 10, 2);
```

---

### **5. Admin e Display**

#### **`ffc_admin_columns`**
Filtra colunas na lista de submissions.

**Parâmetros:**
- `$columns` (array) - Colunas

**Retorno:** `array` - Colunas modificadas

**Exemplo:**
```php
add_filter('ffc_admin_columns', function($columns) {
    // Adicionar coluna customizada
    $columns['phone'] = 'Telefone';
    return $columns;
});
```

---

#### **`ffc_success_message`**
Filtra mensagem de sucesso.

**Parâmetros:**
- `$message` (string) - Mensagem
- `$form_id` (int) - ID do formulário
- `$submission_id` (int) - ID da submissão

**Retorno:** `string` - Mensagem modificada

**Exemplo:**
```php
add_filter('ffc_success_message', function($message, $form_id, $submission_id) {
    return $message . ' <a href="/meus-certificados">Ver todos certificados</a>';
}, 10, 3);
```

---

### **6. Rate Limiting e Segurança**

#### **`ffc_rate_limit_config`**
Filtra configuração de rate limiting.

**Parâmetros:**
- `$config` (array) - Configuração
  - `ip_limit` (int) - Limite por IP
  - `email_limit` (int) - Limite por email
  - `cpf_limit` (int) - Limite por CPF

**Retorno:** `array` - Config modificado

**Exemplo:**
```php
add_filter('ffc_rate_limit_config', function($config) {
    // Aumentar limite para IPs confiáveis
    $trusted_ips = ['203.0.113.50', '198.51.100.1'];
    if (in_array(FFC_Utils::get_user_ip(), $trusted_ips)) {
        $config['ip_limit'] = 100;
    }
    return $config;
});
```

---

#### **`ffc_honeypot_enabled`**
Filtra se honeypot está habilitado.

**Parâmetros:**
- `$enabled` (bool) - Se está habilitado
- `$form_id` (int) - ID do formulário

**Retorno:** `bool`

**Exemplo:**
```php
add_filter('ffc_honeypot_enabled', function($enabled, $form_id) {
    // Desabilitar para form interno
    if ($form_id === 10) {
        return false;
    }
    return $enabled;
}, 10, 2);
```

---

CONTINUA...

## 🎯 **EXEMPLOS PRÁTICOS**

### **Exemplo 1: Integração com CRM (HubSpot)**

```php
/**
 * Envia dados para HubSpot após submissão
 */
add_action('ffc_after_submission_saved', 'integrate_with_hubspot', 10, 3);

function integrate_with_hubspot($submission_id, $form_id, $data) {
    $hubspot_api_key = 'your-api-key';
    
    $contact_data = [
        'properties' => [
            [
                'property' => 'email',
                'value' => $data['email']
            ],
            [
                'property' => 'firstname',
                'value' => $data['name']
            ],
            [
                'property' => 'certificate_form_id',
                'value' => $form_id
            ],
            [
                'property' => 'certificate_submission_id',
                'value' => $submission_id
            ]
        ]
    ];
    
    wp_remote_post('https://api.hubapi.com/contacts/v1/contact/', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $hubspot_api_key
        ],
        'body' => json_encode($contact_data)
    ]);
}
```

---

### **Exemplo 2: Backup Automático em Cloud**

```php
/**
 * Faz backup do PDF no Amazon S3
 */
add_action('ffc_after_pdf_generated', 'backup_pdf_to_s3', 10, 2);

function backup_pdf_to_s3($submission_id, $pdf_path) {
    require_once 'aws-autoloader.php';
    
    $s3 = new Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'credentials' => [
            'key'    => 'YOUR_KEY',
            'secret' => 'YOUR_SECRET',
        ]
    ]);
    
    try {
        $s3->putObject([
            'Bucket' => 'my-certificates-backup',
            'Key'    => 'certificates/' . basename($pdf_path),
            'SourceFile' => $pdf_path
        ]);
        
        error_log('PDF backed up to S3: ' . $submission_id);
    } catch (Exception $e) {
        error_log('S3 backup failed: ' . $e->getMessage());
    }
}
```

---

### **Exemplo 3: Notificação no Slack**

```php
/**
 * Notifica canal do Slack sobre nova submissão
 */
add_action('ffc_after_submission_saved', 'notify_slack', 10, 3);

function notify_slack($submission_id, $form_id, $data) {
    $webhook_url = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    
    $form = get_post($form_id);
    
    $message = [
        'text' => '🎓 Novo Certificado Gerado!',
        'attachments' => [
            [
                'color' => '#36a64f',
                'fields' => [
                    [
                        'title' => 'Formulário',
                        'value' => $form->post_title,
                        'short' => true
                    ],
                    [
                        'title' => 'Nome',
                        'value' => $data['name'],
                        'short' => true
                    ],
                    [
                        'title' => 'Email',
                        'value' => $data['email'],
                        'short' => true
                    ],
                    [
                        'title' => 'Submission ID',
                        'value' => $submission_id,
                        'short' => true
                    ]
                ]
            ]
        ]
    ];
    
    wp_remote_post($webhook_url, [
        'body' => json_encode($message),
        'headers' => ['Content-Type' => 'application/json']
    ]);
}
```

---

### **Exemplo 4: Validação Customizada de Email Corporativo**

```php
/**
 * Permite apenas emails corporativos
 */
add_filter('ffc_validate_submission_data', 'validate_corporate_email', 10, 3);

function validate_corporate_email($errors, $data, $form_id) {
    // Aplicar apenas para form específico
    if ($form_id !== 5) {
        return $errors;
    }
    
    $allowed_domains = ['empresa.com.br', 'filial.empresa.com.br'];
    $email = $data['email'];
    $domain = substr(strrchr($email, "@"), 1);
    
    if (!in_array($domain, $allowed_domains)) {
        $errors[] = 'Apenas emails corporativos são permitidos.';
    }
    
    return $errors;
}
```

---

### **Exemplo 5: Adicionar Código de Barras ao PDF**

```php
/**
 * Adiciona código de barras ao conteúdo do PDF
 */
add_filter('ffc_pdf_content', 'add_barcode_to_pdf', 10, 3);

function add_barcode_to_pdf($content, $submission_id, $data) {
    // Gerar código de barras
    $auth_code = get_post_meta($submission_id, '_ffc_auth_code', true);
    
    $barcode_html = '
    <div style="text-align:center;margin-top:20px;">
        <img src="https://api.barcodeapi.com/code128/' . $auth_code . '" 
             alt="Barcode" 
             style="max-width:300px;">
    </div>';
    
    return $content . $barcode_html;
}
```

---

### **Exemplo 6: Sistema de Aprovação Manual**

```php
/**
 * Requer aprovação manual antes de gerar PDF
 */
add_filter('ffc_auto_generate_pdf', 'require_manual_approval', 10, 2);

function require_manual_approval($auto_generate, $form_id) {
    // Forms que requerem aprovação
    $approval_required = [3, 7, 12];
    
    if (in_array($form_id, $approval_required)) {
        return false; // Não gerar automaticamente
    }
    
    return $auto_generate;
}

/**
 * Gerar PDF após aprovação
 */
add_action('ffc_submission_status_changed', 'generate_pdf_on_approval', 10, 3);

function generate_pdf_on_approval($submission_id, $old_status, $new_status) {
    if ($new_status === 'approved' && $old_status !== 'approved') {
        // Trigger geração de PDF
        do_action('ffc_manual_pdf_generation', $submission_id);
    }
}
```

---

### **Exemplo 7: Múltiplos Idiomas no PDF**

```php
/**
 * Traduz conteúdo do PDF baseado no idioma do usuário
 */
add_filter('ffc_pdf_content', 'translate_pdf_content', 10, 3);

function translate_pdf_content($content, $submission_id, $data) {
    $user_lang = $data['language'] ?? 'pt_BR';
    
    $translations = [
        'pt_BR' => [
            'certificate_title' => 'Certificado de Conclusão',
            'issued_to' => 'Emitido para'
        ],
        'en_US' => [
            'certificate_title' => 'Certificate of Completion',
            'issued_to' => 'Issued to'
        ],
        'es_ES' => [
            'certificate_title' => 'Certificado de Finalización',
            'issued_to' => 'Emitido para'
        ]
    ];
    
    $trans = $translations[$user_lang] ?? $translations['pt_BR'];
    
    foreach ($trans as $key => $value) {
        $content = str_replace('{{' . $key . '}}', $value, $content);
    }
    
    return $content;
}
```

---

### **Exemplo 8: Analytics e Tracking**

```php
/**
 * Registra eventos no Google Analytics
 */
add_action('ffc_after_submission_saved', 'track_submission_in_ga', 10, 3);

function track_submission_in_ga($submission_id, $form_id, $data) {
    $measurement_id = 'G-XXXXXXXXXX';
    $api_secret = 'your-api-secret';
    
    $form = get_post($form_id);
    
    $event_data = [
        'client_id' => $data['ga_client_id'] ?? uniqid(),
        'events' => [
            [
                'name' => 'certificate_generated',
                'params' => [
                    'form_name' => $form->post_title,
                    'form_id' => $form_id,
                    'submission_id' => $submission_id,
                    'user_email' => $data['email']
                ]
            ]
        ]
    ];
    
    wp_remote_post(
        "https://www.google-analytics.com/mp/collect?measurement_id={$measurement_id}&api_secret={$api_secret}",
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($event_data)
        ]
    );
}
```

---

## 🎯 **CASOS DE USO REAIS**

### **Caso 1: Sistema de Treinamento Corporativo**

**Necessidade:** Empresa precisa emitir certificados após conclusão de treinamentos, integrando com LMS.

**Implementação:**
```php
// 1. Receber webhook do LMS quando curso for concluído
add_action('rest_api_init', function() {
    register_rest_route('ffc/v1', '/lms-webhook', [
        'methods' => 'POST',
        'callback' => 'process_lms_completion'
    ]);
});

function process_lms_completion($request) {
    $data = $request->get_json_params();
    
    // Criar submissão automaticamente
    $submission_data = [
        'name' => $data['student_name'],
        'email' => $data['student_email'],
        'course' => $data['course_name'],
        'completion_date' => $data['completed_at'],
        'score' => $data['final_score']
    ];
    
    // Processar via plugin
    do_action('ffc_create_submission_from_api', 5, $submission_data);
}

// 2. Enviar certificado e atualizar LMS
add_action('ffc_after_pdf_generated', 'update_lms_system', 10, 2);

function update_lms_system($submission_id, $pdf_path) {
    $data = get_post_meta($submission_id, '_ffc_submission_data', true);
    
    // Atualizar LMS via API
    wp_remote_post('https://lms.empresa.com/api/certificates', [
        'body' => [
            'student_email' => $data['email'],
            'certificate_url' => wp_get_attachment_url(get_post_thumbnail_id($submission_id)),
            'issued_at' => current_time('mysql')
        ]
    ]);
}
```

---

### **Caso 2: Evento com Múltiplas Palestras**

**Necessidade:** Emitir certificados diferentes para cada palestra assistida.

**Implementação:**
```php
// Criar formulário dinâmico baseado nas palestras
add_filter('ffc_form_fields', 'add_talk_selection', 10, 2);

function add_talk_selection($fields, $form_id) {
    if ($form_id !== 8) return $fields;
    
    $talks = get_posts(['post_type' => 'talk', 'posts_per_page' => -1]);
    
    $talk_options = [];
    foreach ($talks as $talk) {
        $talk_options[] = [
            'value' => $talk->ID,
            'label' => $talk->post_title
        ];
    }
    
    $fields[] = [
        'name' => 'attended_talks',
        'label' => 'Palestras Assistidas',
        'type' => 'checkbox',
        'options' => $talk_options,
        'required' => true
    ];
    
    return $fields;
}

// Customizar conteúdo do PDF
add_filter('ffc_pdf_content', 'customize_talk_certificate', 10, 3);

function customize_talk_certificate($content, $submission_id, $data) {
    $talks = $data['attended_talks'] ?? [];
    
    if (empty($talks)) return $content;
    
    $talk_list = '<ul>';
    foreach ($talks as $talk_id) {
        $talk = get_post($talk_id);
        $talk_list .= '<li>' . $talk->post_title . '</li>';
    }
    $talk_list .= '</ul>';
    
    $content = str_replace('{{talk_list}}', $talk_list, $content);
    
    return $content;
}
```

---

### **Caso 3: Certificados com Assinatura Digital**

**Necessidade:** Adicionar assinatura digital aos PDFs para validade legal.

**Implementação:**
```php
// Assinar PDF digitalmente após geração
add_action('ffc_after_pdf_generated', 'digitally_sign_pdf', 10, 2);

function digitally_sign_pdf($submission_id, $pdf_path) {
    require_once 'tcpdf/tcpdf.php';
    
    // Carregar certificado digital
    $certificate = file_get_contents('/path/to/certificate.crt');
    $private_key = file_get_contents('/path/to/private.key');
    
    // Configurar assinatura
    $info = [
        'Name' => get_bloginfo('name'),
        'Location' => 'Brasil',
        'Reason' => 'Certificação de Curso',
        'ContactInfo' => get_bloginfo('admin_email')
    ];
    
    // Assinar PDF
    $pdf = new TCPDF();
    $pdf->setSignature($certificate, $private_key, 'password', '', 2, $info);
    
    // Salvar PDF assinado
    $pdf->Output($pdf_path, 'F');
    
    // Registrar assinatura
    update_post_meta($submission_id, '_pdf_digitally_signed', true);
    update_post_meta($submission_id, '_pdf_signature_date', current_time('mysql'));
}
```

---

### **Caso 4: Gamificação com Badges**

**Necessidade:** Emitir badges progressivos conforme completam certificados.

**Implementação:**
```php
// Contar certificados do usuário
add_action('ffc_after_submission_saved', 'check_badge_unlock', 10, 3);

function check_badge_unlock($submission_id, $form_id, $data) {
    $email = $data['email'];
    
    // Contar certificados do usuário
    global $wpdb;
    $table = FFC_Utils::get_submissions_table();
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE email = %s AND status = 'publish'",
        $email
    ));
    
    $badges = [
        5 => 'Iniciante',
        10 => 'Intermediário',
        25 => 'Avançado',
        50 => 'Expert',
        100 => 'Master'
    ];
    
    foreach ($badges as $required => $badge_name) {
        if ($count == $required) {
            // Enviar email de conquista
            $subject = "🏆 Parabéns! Você desbloqueou: $badge_name";
            $message = "Você completou $count certificados e ganhou o badge $badge_name!";
            wp_mail($email, $subject, $message);
            
            // Registrar badge
            $user = get_user_by('email', $email);
            if ($user) {
                add_user_meta($user->ID, '_ffc_badge_' . $required, current_time('mysql'));
            }
        }
    }
}
```

---

CONTINUA...

### **Caso 5: Integração com Zapier**

**Necessidade:** Conectar com milhares de apps via Zapier.

**Implementação:**
```php
// Criar webhook para Zapier
add_action('ffc_after_submission_saved', 'send_to_zapier', 10, 3);

function send_to_zapier($submission_id, $form_id, $data) {
    $zapier_webhook = 'https://hooks.zapier.com/hooks/catch/XXXXX/YYYYY/';
    
    $payload = [
        'submission_id' => $submission_id,
        'form_id' => $form_id,
        'form_name' => get_the_title($form_id),
        'name' => $data['name'],
        'email' => $data['email'],
        'submitted_at' => current_time('mysql'),
        'auth_code' => get_post_meta($submission_id, '_ffc_auth_code', true),
        'pdf_url' => wp_get_attachment_url(get_post_thumbnail_id($submission_id))
    ];
    
    wp_remote_post($zapier_webhook, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload)
    ]);
}
```

---

### **Caso 6: Certificados Expiráveis**

**Necessidade:** Certificados que expiram após X meses.

**Implementação:**
```php
// Adicionar data de expiração
add_action('ffc_after_submission_saved', 'set_expiration_date', 10, 3);

function set_expiration_date($submission_id, $form_id, $data) {
    // Certificado expira em 12 meses
    $expiration = strtotime('+12 months');
    update_post_meta($submission_id, '_ffc_expires_at', $expiration);
}

// Verificar expiração ao validar
add_filter('ffc_certificate_is_valid', 'check_expiration', 10, 2);

function check_expiration($is_valid, $submission_id) {
    $expires_at = get_post_meta($submission_id, '_ffc_expires_at', true);
    
    if ($expires_at && time() > $expires_at) {
        return false; // Expirado
    }
    
    return $is_valid;
}

// Adicionar info no PDF
add_filter('ffc_pdf_content', 'add_expiration_to_pdf', 10, 3);

function add_expiration_to_pdf($content, $submission_id, $data) {
    $expires_at = get_post_meta($submission_id, '_ffc_expires_at', true);
    
    if ($expires_at) {
        $expiry_date = date('d/m/Y', $expires_at);
        $expiry_html = '<p style="text-align:center;margin-top:30px;font-size:10px;">
            Validade: ' . $expiry_date . '
        </p>';
        $content .= $expiry_html;
    }
    
    return $content;
}

// Cron para notificar expirações próximas
add_action('ffc_daily_cron', 'notify_upcoming_expirations');

function notify_upcoming_expirations() {
    global $wpdb;
    $table = FFC_Utils::get_submissions_table();
    
    // Buscar certificados que expiram em 30 dias
    $threshold = strtotime('+30 days');
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = 'publish' 
         AND meta_key = '_ffc_expires_at' 
         AND meta_value < %d 
         AND meta_value > %d",
        $threshold,
        time()
    ));
    
    foreach ($results as $submission) {
        $data = json_decode($submission->data, true);
        $email = $data['email'];
        
        // Enviar notificação
        wp_mail(
            $email,
            'Seu certificado expirará em breve',
            'Seu certificado expira em ' . date('d/m/Y', $submission->expires_at)
        );
    }
}
```

---

## 📋 **REFERÊNCIA RÁPIDA**

### **Actions Mais Usados:**

| Hook | Quando usar |
|------|-------------|
| `ffc_after_submission_saved` | Integração com sistemas externos (CRM, LMS) |
| `ffc_after_pdf_generated` | Backup, assinatura digital, upload cloud |
| `ffc_after_email_sent` | Log, tracking, notificações |
| `ffc_submission_status_changed` | Workflows de aprovação |

### **Filters Mais Usados:**

| Hook | Quando usar |
|------|-------------|
| `ffc_pdf_content` | Customizar layout/conteúdo do PDF |
| `ffc_validate_submission_data` | Validações customizadas |
| `ffc_email_body` | Personalizar emails |
| `ffc_form_fields` | Adicionar campos dinâmicos |

---

## 🛠️ **TEMPLATE DE EXTENSÃO**

### **Criando um Addon/Extensão:**

```php
<?php
/**
 * Plugin Name: FFC Custom Extension
 * Description: Minha extensão customizada para Form for Certificates
 * Version: 1.0.0
 */

class FFC_Custom_Extension {
    
    public function __construct() {
        // Registrar hooks
        add_action('ffc_after_submission_saved', [$this, 'on_submission'], 10, 3);
        add_filter('ffc_pdf_content', [$this, 'modify_pdf'], 10, 3);
    }
    
    public function on_submission($submission_id, $form_id, $data) {
        // Sua lógica aqui
    }
    
    public function modify_pdf($content, $submission_id, $data) {
        // Modificar PDF
        return $content;
    }
}

// Inicializar apenas se FFC estiver ativo
add_action('plugins_loaded', function() {
    if (class_exists('FFC_Utils')) {
        new FFC_Custom_Extension();
    }
});
```

---

## 📚 **RECURSOS ADICIONAIS**

### **Debugging Hooks:**

```php
// Ver todos os hooks executados
add_action('all', function($hook) {
    if (strpos($hook, 'ffc_') === 0) {
        error_log('FFC Hook: ' . $hook);
    }
});

// Ver dados de um hook específico
add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    error_log('Submission Data: ' . print_r($data, true));
}, 999, 3);
```

### **Remover Hooks:**

```php
// Remover action
remove_action('ffc_after_email_sent', 'function_name', 10);

// Remover filter
remove_filter('ffc_pdf_content', 'function_name', 10);
```

### **Prioridade de Execução:**

```php
// Executar ANTES de outros hooks (prioridade baixa)
add_action('ffc_after_submission_saved', 'my_function', 5, 3);

// Executar DEPOIS de outros hooks (prioridade alta)
add_action('ffc_after_submission_saved', 'my_function', 20, 3);

// Padrão é 10
```

---

## 🎓 **MELHORES PRÁTICAS**

### **1. Sempre verificar se dados existem:**
```php
add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    // ✅ BOM
    if (isset($data['email']) && !empty($data['email'])) {
        // usar $data['email']
    }
    
    // ❌ RUIM
    $email = $data['email']; // Pode causar erro
}, 10, 3);
```

### **2. Usar try-catch para integrações externas:**
```php
add_action('ffc_after_submission_saved', function($submission_id, $form_id, $data) {
    try {
        // Integração com API externa
        $api->sendData($data);
    } catch (Exception $e) {
        error_log('API Error: ' . $e->getMessage());
        // Não quebrar o processo do plugin
    }
}, 10, 3);
```

### **3. Prefixar funções:**
```php
// ✅ BOM
add_action('ffc_after_submission_saved', 'mycompany_process_submission', 10, 3);
function mycompany_process_submission($submission_id, $form_id, $data) {
    // ...
}

// ❌ RUIM (pode conflitar com outros plugins)
add_action('ffc_after_submission_saved', 'process_submission', 10, 3);
```

### **4. Documentar hooks customizados:**
```php
/**
 * Fires after custom processing
 * 
 * @param int   $submission_id Submission ID
 * @param array $custom_data   Custom data
 * @since 1.0.0
 */
do_action('mycompany_after_custom_process', $submission_id, $custom_data);
```

---

## 🔗 **LINKS ÚTEIS**

- **WordPress Plugin Handbook:** https://developer.wordpress.org/plugins/hooks/
- **FFC_Utils Reference:** Ver class-ffc-utils.php
- **Repository Pattern:** Ver abstract-repository.php
- **REST API:** Ver class-ffc-rest-controller.php

---

## ✅ **CHECKLIST DE DESENVOLVIMENTO**

Ao criar extensões para FFC:

- [ ] Verificar se FFC está ativo antes de executar código
- [ ] Usar prefixo em nomes de funções
- [ ] Adicionar try-catch em integrações externas
- [ ] Verificar existência de dados antes de usar
- [ ] Logar erros com error_log()
- [ ] Usar prioridades apropriadas
- [ ] Documentar código
- [ ] Testar com WP_DEBUG ativo
- [ ] Verificar compatibilidade com versões PHP

---

## 📞 **SUPORTE**

Para dúvidas sobre hooks específicos ou necessidades customizadas, consulte a documentação do código-fonte ou entre em contato com o desenvolvedor do plugin.

---

**Última atualização:** 2026-01-13  
**Versão do documento:** 1.0.0  
**Compatível com:** Form for Certificates 2.9.17+

