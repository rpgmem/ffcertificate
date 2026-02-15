# Plano de Melhorias: Sistema de Usuários FFC

## Sprints 1-5: Concluídas (v4.9.7 - v4.9.10)

> **Status:** Todas implementadas e verificadas.
>
> - Sprint 1: Capabilities & Correções Estruturais
> - Sprint 2: Tabela ffc_user_profiles, Hook de Deleção & Email Change
> - Sprint 3: LGPD/Privacy — Exporters & Erasers
> - Sprint 4: Dashboard Editável, Appointments Anônimos & Username
> - Sprint 5: Robustez, Performance & FK Constraints

---

## Decisões de Arquitetura (Novas Funcionalidades)

1. **Audiences** → Estender módulo existente (`includes/audience/`). Tabela `wp_ffc_audiences` já possui `parent_id` para hierarquia.
2. **Custom Fields** → Definições em tabela dedicada (`wp_ffc_custom_fields`). Dados do usuário em JSON no `wp_usermeta` (key: `ffc_custom_fields_data`).
3. **Recadastramento** → Módulo novo (`includes/reregistration/`). Tela admin separada. Vinculado a audiences.
4. **Ficha PDF** → Geração on-demand client-side (html2canvas + jsPDF), mesmo padrão dos certificados.
5. **E-mails** → 3 templates em arquivos na pasta do plugin. Todas as notificações desabilitadas por padrão. Admin ativa/desativa.
6. **Permissão** → Capability global `ffc_manage_reregistration`. Admin + usuários com esta cap gerenciam tudo.
7. **Idioma** → Plugin em inglês com suporte i18n (text domain `ffcertificate`).

---

## Sprint 6: Custom Fields — Infraestrutura & Admin

> **Escopo:** Criar sistema de campos personalizados vinculados a audiences
> **Risco:** Médio (nova tabela, admin UI complexa com drag-and-drop)
> **Depende de:** Sprints 1-5 (concluídas)

### 6.1 Criar tabela `wp_ffc_custom_fields`

**Modificar:** `includes/class-ffc-activator.php`

```sql
CREATE TABLE wp_ffc_custom_fields (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    audience_id BIGINT(20) UNSIGNED NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_label VARCHAR(250) NOT NULL,
    field_type VARCHAR(50) NOT NULL DEFAULT 'text',
    field_options JSON DEFAULT NULL,
    validation_rules JSON DEFAULT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audience_id (audience_id),
    KEY idx_field_key (field_key),
    KEY idx_sort_order (audience_id, sort_order),
    CONSTRAINT fk_custom_fields_audience
        FOREIGN KEY (audience_id) REFERENCES {prefix}ffc_audiences(id) ON DELETE CASCADE
) {charset_collate};
```

**Tipos de campo suportados (`field_type`):**
- `text` — Texto livre
- `number` — Numérico
- `date` — Data (datepicker)
- `select` — Dropdown (opções em `field_options.choices[]`)
- `checkbox` — Checkbox (sim/não)
- `textarea` — Texto longo

**Regras de validação (`validation_rules` JSON):**
```json
{
    "min_length": 3,
    "max_length": 100,
    "format": "cpf|email|phone|custom_regex",
    "custom_regex": "^[0-9]{5}-[0-9]{3}$",
    "custom_regex_message": "Format: XXXXX-XXX"
}
```

**Opções do campo (`field_options` JSON):**
```json
{
    "choices": ["Option A", "Option B", "Option C"],
    "placeholder": "Select...",
    "help_text": "Additional instructions for the user"
}
```

### 6.2 Criar Custom Fields Repository

**Novo arquivo:** `includes/reregistration/class-ffc-custom-field-repository.php`

- Namespace: `FreeFormCertificate\Reregistration`
- Estender `AbstractRepository` ou standalone
- Métodos:
  - `get_by_audience(int $audience_id, bool $active_only = true): array`
  - `get_by_audience_with_parents(int $audience_id): array` — busca campos do audience + todos os pais na hierarquia
  - `create(array $data): int|false`
  - `update(int $field_id, array $data): bool`
  - `delete(int $field_id): bool`
  - `deactivate(int $field_id): bool` — SET is_active = 0 (dados preservados)
  - `reactivate(int $field_id): bool`
  - `reorder(array $field_ids): bool` — atualiza sort_order em batch
  - `get_all_for_user(int $user_id): array` — busca campos de todos os audiences do usuário

### 6.3 Admin UI — Campos Personalizados por Audience

**Modificar:** `includes/audience/class-ffc-audience-admin-audience.php`

- Adicionar aba/seção "Custom Fields" na tela de edição do audience
- Interface drag-and-drop para reordenar campos (jQuery UI Sortable — já no projeto)
- Para cada campo:
  - Label, Key (auto-gerada do label, editável), Type (dropdown)
  - Required (checkbox)
  - Validation rules (condicional por tipo)
  - Options (para select: lista editável)
  - Help text
  - Active/Inactive toggle
- Botões: "Add Field", "Save Order"
- AJAX save para não perder estado

**Novo arquivo JS:** `assets/js/ffc-custom-fields-admin.js`
- Drag-and-drop via jQuery UI Sortable
- Add/remove field rows
- Conditional show/hide de validation rules por tipo
- AJAX save (POST para endpoint dedicado)

**Novo arquivo CSS:** `assets/css/ffc-custom-fields-admin.css`

### 6.4 AJAX Endpoints para Custom Fields Admin

**Modificar:** `includes/audience/class-ffc-audience-admin-audience.php` ou novo handler

- `wp_ajax_ffc_save_custom_fields` — Salva fields em batch (create/update/reorder)
- `wp_ajax_ffc_delete_custom_field` — Deleta/desativa field
- `wp_ajax_ffc_reorder_custom_fields` — Atualiza sort_order
- Validação: nonce, capability check (`manage_options` ou `ffc_manage_reregistration`)

### 6.5 Armazenar dados do usuário em user_meta

**Formato no `wp_usermeta` (meta_key: `ffc_custom_fields_data`):**
```json
{
    "field_12": "João da Silva",
    "field_15": "123.456.789-00",
    "field_18": "2025-06-15",
    "field_20": "Option B",
    "field_22": true
}
```

- Key = `field_{id}` para evitar colisões
- Valor depende do tipo (string, number, boolean, date string)
- Dados de campos desativados permanecem no JSON (campo fica oculto, não deletado)

### 6.6 Admin — Aba "Custom Fields" na edição de usuário (wp-admin)

**Novo arquivo:** `includes/admin/class-ffc-admin-user-custom-fields.php`

- Hook: `show_user_profile` e `edit_user_profile` — adiciona seção
- Hook: `personal_options_update` e `edit_user_profile_update` — salva dados
- Renderiza campos personalizados de todos os audiences do usuário
- Agrupados por audience (heading com nome do audience)
- Validação server-side conforme `validation_rules`
- Leitura de `ffc_custom_fields_data` do user_meta

**Alternativa melhor — aba separada:**
- Usar JavaScript para criar uma aba no user-edit.php (similar a como WooCommerce faz)
- Ou usar `user_edit_form_tag` + CSS para agrupar numa seção distinta

### 6.7 Atualizar uninstall.php

**Modificar:** `uninstall.php`
- Adicionar `DROP TABLE IF EXISTS wp_ffc_custom_fields`
- Adicionar `DELETE FROM wp_usermeta WHERE meta_key = 'ffc_custom_fields_data'`

---

## Sprint 7: Recadastramento — Infraestrutura & Admin

> **Escopo:** Criar sistema de recadastramento vinculado a audiences
> **Risco:** Médio-Alto (nova tabela, admin UI, lógica de cascata hierárquica)
> **Depende de:** Sprint 6 (custom fields)

### 7.1 Criar tabela `wp_ffc_reregistrations`

**Modificar:** `includes/class-ffc-activator.php`

```sql
CREATE TABLE wp_ffc_reregistrations (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(250) NOT NULL,
    audience_id BIGINT(20) UNSIGNED NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    auto_approve TINYINT(1) NOT NULL DEFAULT 0,
    email_invitation_enabled TINYINT(1) NOT NULL DEFAULT 0,
    email_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
    email_confirmation_enabled TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT(20) UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audience_id (audience_id),
    KEY idx_status (status),
    KEY idx_dates (start_date, end_date),
    CONSTRAINT fk_reregistrations_audience
        FOREIGN KEY (audience_id) REFERENCES {prefix}ffc_audiences(id) ON DELETE CASCADE
) {charset_collate};
```

**Status do recadastramento (`status`):**
- `draft` — Criado mas não ativo
- `active` — Dentro do período (start_date ≤ now ≤ end_date)
- `expired` — Passou do end_date
- `closed` — Fechado manualmente pelo admin

### 7.2 Criar tabela `wp_ffc_reregistration_submissions`

```sql
CREATE TABLE wp_ffc_reregistration_submissions (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    reregistration_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    data JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    submitted_at DATETIME DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    reviewed_by BIGINT(20) UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_reregistration_user (reregistration_id, user_id),
    KEY idx_user_id (user_id),
    KEY idx_status (status),
    CONSTRAINT fk_rereg_sub_reregistration
        FOREIGN KEY (reregistration_id) REFERENCES {prefix}ffc_reregistrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_rereg_sub_user
        FOREIGN KEY (user_id) REFERENCES {wp_prefix}users(ID) ON DELETE CASCADE
) {charset_collate};
```

**Status da submission (`status`):**
- `pending` — Recadastramento aberto, usuário ainda não preencheu
- `in_progress` — Usuário começou mas não finalizou (rascunho salvo)
- `submitted` — Enviado, aguardando aprovação (quando auto_approve = 0)
- `approved` — Aprovado pelo admin ou auto-aprovado
- `rejected` — Rejeitado pelo admin (com notes de motivo)
- `expired` — Período encerrou sem o usuário preencher

**Campo `data` JSON:**
```json
{
    "standard_fields": {
        "display_name": "João da Silva",
        "email": "joao@example.com",
        "phone": "(11) 99999-9999",
        "department": "Engineering",
        "organization": "Acme Corp"
    },
    "custom_fields": {
        "field_12": "Updated Value",
        "field_15": "123.456.789-00",
        "field_18": "2025-06-15"
    }
}
```

### 7.3 Criar Reregistration Repository

**Novo arquivo:** `includes/reregistration/class-ffc-reregistration-repository.php`

- Métodos:
  - `create(array $data): int|false`
  - `update(int $id, array $data): bool`
  - `delete(int $id): bool`
  - `get_by_id(int $id): array|null`
  - `get_active_for_audience(int $audience_id): array` — busca reregistrations ativos para o audience e seus pais
  - `get_active_for_user(int $user_id): array` — busca todos os reregistrations ativos dos audiences do usuário
  - `get_all(array $filters = []): array` — com filtros: audience_id, status, date_range, paginação
  - `expire_overdue(): int` — cron job: muda status para 'expired' quando end_date passou

### 7.4 Criar Reregistration Submission Repository

**Novo arquivo:** `includes/reregistration/class-ffc-reregistration-submission-repository.php`

- Métodos:
  - `create(array $data): int|false`
  - `update(int $id, array $data): bool`
  - `get_by_reregistration_and_user(int $rereg_id, int $user_id): array|null`
  - `get_by_reregistration(int $rereg_id, array $filters = []): array` — lista submissions com filtros (status, search)
  - `approve(int $id, int $reviewer_id): bool`
  - `reject(int $id, int $reviewer_id, string $notes): bool`
  - `bulk_approve(array $ids, int $reviewer_id): int` — retorna count de aprovados
  - `get_statistics(int $rereg_id): array` — contagem por status
  - `get_for_export(int $rereg_id, array $filters = []): array` — dados para CSV

### 7.5 Admin — Tela de Recadastramento (menu dedicado)

**Novo arquivo:** `includes/reregistration/class-ffc-reregistration-admin.php`

- Registrar submenu em "Free Form Certificate": "Reregistration"
- **Tela de listagem:**
  - Colunas: Title, Audience, Status (badge colorido), Period (start-end), Submissions (count), Auto-approve
  - Filtros: audience, status, period
  - Ações: Edit, Delete, Close, View Submissions
- **Tela de criação/edição:**
  - Campos: Title, Audience (select com hierarquia), Start Date, End Date, Auto-approve (toggle)
  - E-mail toggles: Invitation, Reminder, Confirmation (todos off por padrão)
  - Preview: lista de usuários afetados (audience + filhos)
- **Tela de submissions (sub-view):**
  - Colunas: User, Audience, Status, Submitted At, Reviewed At
  - Filtros: audience, status, search (nome/email)
  - Ações individuais: Approve, Reject (com campo notes)
  - Ações em lote: Approve Selected, Send Reminder to Pending
  - Botão: Export CSV
  - Cada linha expansível para ver dados preenchidos

**Novo arquivo JS:** `assets/js/ffc-reregistration-admin.js`
**Novo arquivo CSS:** `assets/css/ffc-reregistration-admin.css`

### 7.6 Admin — Criar reregistration submissions para usuários

**Lógica ao criar/ativar um recadastramento:**
- Buscar todos os membros do audience + audiences filhos (cascata hierárquica)
- Para cada membro, criar um registro em `wp_ffc_reregistration_submissions` com status `pending`
- Se um usuário pertence a múltiplos audiences na hierarquia, apenas um registro (o do audience mais específico)
- Cron job: verificar diariamente e criar submissions para novos membros adicionados ao audience durante o período ativo

### 7.7 CSV Export

**Modificar:** `includes/reregistration/class-ffc-reregistration-admin.php`

- Endpoint: `wp_ajax_ffc_export_reregistration_csv`
- Colunas do CSV:
  - User ID, Name, Email, Audience, Status, Submitted At, Reviewed At, Reviewed By
  - + Todos os campos standard (phone, department, organization)
  - + Todos os custom fields do audience (label como header)
- Filtro por reregistration_id (dados do último recadastramento)

### 7.8 Capability & Menu Registration

**Modificar:** `includes/user-dashboard/class-ffc-user-manager.php`
- Adicionar `ffc_manage_reregistration` ao `ADMIN_CAPABILITIES` array
- Adicionar ao `FUTURE_CAPABILITIES` → `ADMIN_CAPABILITIES`

**Modificar:** `includes/class-ffc-loader.php`
- Inicializar `ReregistrationAdmin`

**Modificar:** `uninstall.php`
- DROP tables: `wp_ffc_reregistrations`, `wp_ffc_reregistration_submissions`
- Remover capability: `ffc_manage_reregistration`

### 7.9 Cron Job — Expirar recadastramentos

**Modificar:** `includes/class-ffc-activator.php` (ou loader)
- Registrar hook: `ffcertificate_reregistration_expiry_check`
- Schedule: `daily`
- Ação:
  1. Buscar reregistrations com status `active` e `end_date < NOW()`
  2. Alterar status para `expired`
  3. Alterar todas as submissions `pending` / `in_progress` para `expired`
  4. Logar no activity log

---

## Sprint 8: Recadastramento — Frontend (Usuário)

> **Escopo:** Formulário de recadastramento no dashboard do usuário + banner
> **Risco:** Médio (formulário dinâmico com campos custom + validação)
> **Depende de:** Sprint 7

### 8.1 Banner de recadastramento no dashboard

**Modificar:** `includes/shortcodes/class-ffc-dashboard-shortcode.php`

- No render do dashboard, verificar se há recadastramentos ativos para o usuário:
  - Chamar `ReregistrationRepository::get_active_for_user($user_id)`
  - Para cada ativo, verificar status da submission do usuário
- Se há pendentes: exibir banner com:
  - Título do recadastramento
  - Prazo (end_date)
  - Link/botão "Complete your reregistration"
  - Estilo: warning box (amarelo/laranja) para chamar atenção

**Modificar CSS:** `assets/css/ffc-user-dashboard.css`
- Estilos para `.ffc-reregistration-banner`

### 8.2 Formulário de recadastramento

**Novo arquivo:** `includes/reregistration/class-ffc-reregistration-frontend.php`

- AJAX endpoint: `wp_ajax_ffc_get_reregistration_form` — retorna HTML ou JSON do formulário
- AJAX endpoint: `wp_ajax_ffc_submit_reregistration` — processa submission
- AJAX endpoint: `wp_ajax_ffc_save_reregistration_draft` — salva rascunho

**Formulário contém:**
1. **Campos standard** (pre-populated de `ffc_user_profiles`):
   - Display Name
   - Email (read-only — do wp_users)
   - Phone
   - Department
   - Organization
2. **Campos personalizados** do audience (pre-populated de `ffc_custom_fields_data`):
   - Renderizados dinamicamente conforme `wp_ffc_custom_fields`
   - Ordenados por `sort_order`
   - Apenas campos com `is_active = 1`
   - Inclui campos dos audiences pais (hierarquia)
3. **Botões:**
   - "Save Draft" — salva sem validar obrigatórios
   - "Submit" — valida e envia

### 8.3 Validação do formulário

**Server-side (class-ffc-reregistration-frontend.php):**
- Validar campos required
- Validar format rules:
  - CPF: regex `^\d{3}\.\d{3}\.\d{3}-\d{2}$` + dígitos verificadores
  - Email: `is_email()`
  - Phone: regex básico
  - Custom regex: aplicar se definido em `validation_rules`
- Sanitização: `sanitize_text_field()`, `absint()`, etc. por tipo

**Client-side (JS):**
- Validação em tempo real no blur
- Máscaras de input (CPF, phone) via JS
- Highlight de campos inválidos

**Novo arquivo JS:** `assets/js/ffc-reregistration-frontend.js`
**Novo arquivo CSS:** `assets/css/ffc-reregistration-frontend.css`

### 8.4 Processamento da submission

**Fluxo ao submeter:**
1. Validar todos os campos (server-side)
2. Salvar dados na tabela `wp_ffc_reregistration_submissions` (campo `data` JSON)
3. Atualizar `ffc_user_profiles` com campos standard atualizados
4. Atualizar `ffc_custom_fields_data` no user_meta com custom fields atualizados
5. Se `auto_approve = 1`:
   - Status → `approved`
   - Enviar e-mail de confirmação (se habilitado)
6. Se `auto_approve = 0`:
   - Status → `submitted` (aguardando aprovação)
   - Enviar e-mail de confirmação (se habilitado)
7. Logar no activity log

### 8.5 Integrar no REST API

**Modificar:** `includes/api/class-ffc-user-data-rest-controller.php`

- Novo endpoint: `GET /user/reregistrations` — lista recadastramentos ativos do usuário com status
- Incluir contagem de reregistrations pendentes no `GET /user/summary`

---

## Sprint 9: E-mails & Notificações

> **Escopo:** Templates de e-mail e sistema de envio para recadastramento
> **Risco:** Baixo (reusa infraestrutura existente de e-mail)
> **Depende de:** Sprint 8

### 9.1 Criar templates de e-mail

**Novos arquivos:**
- `templates/emails/reregistration-invitation.php`
- `templates/emails/reregistration-reminder.php`
- `templates/emails/reregistration-confirmation.php`

**Placeholders disponíveis nos templates:**
- `{{user_name}}` — Display name
- `{{reregistration_title}}` — Título do recadastramento
- `{{audience_name}}` — Nome do audience
- `{{start_date}}` — Data de início
- `{{end_date}}` — Data limite
- `{{dashboard_url}}` — Link para o dashboard do usuário
- `{{site_name}}` — Nome do site

**Cada template é um arquivo PHP que retorna array:**
```php
return array(
    'subject' => __('Reregistration Open: {{reregistration_title}}', 'ffcertificate'),
    'body'    => '...HTML template with placeholders...',
);
```

### 9.2 Email Handler para Recadastramento

**Novo arquivo:** `includes/reregistration/class-ffc-reregistration-email-handler.php`

- Métodos:
  - `send_invitation(int $reregistration_id): int` — envia para todos os membros pendentes. Retorna count.
  - `send_reminder(int $reregistration_id, array $user_ids = []): int` — envia para pendentes (todos ou selecionados)
  - `send_confirmation(int $submission_id): bool` — envia confirmação individual
- Usa `FFC_Email_Handler` existente para envio real (SMTP configurado)
- Verifica flags `email_*_enabled` do reregistration antes de enviar
- Logar envios no activity log

### 9.3 Envio automático de convites

**Modificar:** `includes/reregistration/class-ffc-reregistration-admin.php`

- Ao ativar um recadastramento (status → `active`):
  - Se `email_invitation_enabled = 1`: envia convite a todos os membros
- Cron job (opcional, configurável): enviar lembrete X dias antes do end_date
  - Schedule: diário
  - Condição: `email_reminder_enabled = 1` AND status = `active` AND end_date - now ≤ reminder_days
  - Reminder days: configurável no recadastramento (default: 7)

### 9.4 Ações de e-mail no admin

**Modificar:** `includes/reregistration/class-ffc-reregistration-admin.php`

- Botão "Send Reminder" na tela de submissions (ação em lote)
- Bulk action: selecionar usuários pendentes → "Send Reminder"
- Confirmação via modal antes de enviar

---

## Sprint 10: Ficha PDF

> **Escopo:** Geração on-demand de ficha do recadastramento
> **Risco:** Baixo (reusa engine de PDF existente)
> **Depende de:** Sprint 8

### 10.1 Template HTML da ficha (placeholder)

**Novo arquivo:** `html/default_ficha_template.html`

- Template placeholder com placeholders para:
  - Dados standard: `{{display_name}}`, `{{email}}`, `{{phone}}`, `{{department}}`, `{{organization}}`
  - Dados custom: `{{custom_field_LABEL}}` (resolvidos dinamicamente)
  - Dados do recadastramento: `{{reregistration_title}}`, `{{submitted_at}}`, `{{status}}`
  - Metadados: `{{audience_name}}`, `{{generation_date}}`
- Sem foto de perfil
- Layout será substituído pelo HTML fornecido pelo usuário futuramente

### 10.2 Gerador de ficha

**Novo arquivo:** `includes/reregistration/class-ffc-ficha-generator.php`

- Método: `generate_ficha_data(int $submission_id): array`
  - Busca submission + user profile + custom fields
  - Processa template HTML substituindo placeholders
  - Retorna: `['html' => '...', 'filename' => '...', 'user' => [...]]`
- Segue mesmo padrão de `FFC_PDF_Generator::generate_pdf_data()`
- Hooks para extensibilidade:
  - `ffcertificate_ficha_data`
  - `ffcertificate_ficha_html`
  - `ffcertificate_ficha_filename`

### 10.3 Download no admin

**Modificar:** `includes/reregistration/class-ffc-reregistration-admin.php`

- Botão "Download Ficha" em cada submission aprovada
- AJAX endpoint: `wp_ajax_ffc_generate_ficha` — retorna HTML processado para geração client-side
- JavaScript: usa html2canvas + jsPDF (mesma infra dos certificados)

### 10.4 Download pelo usuário

**Modificar:** `includes/reregistration/class-ffc-reregistration-frontend.php`

- Após submission aprovada, exibir botão "Download Ficha" no dashboard
- Mesma lógica client-side de geração

---

## Sprint 11: Audience Enhancements & Admin User Tab

> **Escopo:** Melhorias no módulo audience (hierarquia visual, departments/org) + aba no user edit
> **Risco:** Baixo-Médio
> **Depende de:** Sprint 6

### 11.1 Melhorar visualização de hierarquia no admin

**Modificar:** `includes/audience/class-ffc-audience-admin-audience.php`

- Exibir audiences em árvore hierárquica (tree view) no admin
- Indentação visual por nível (similar a categorias do WordPress)
- Mostrar count de membros por audience (incluindo filhos)
- Breadcrumb no topo ao editar audience filho

### 11.2 Campos department/organization no audience

**Nota:** A hierarquia de audiences já suporta a estrutura Organização → Departamento → Equipe.
- Organização = audience raiz (nível 0)
- Departamento = audience filho (nível 1)
- Equipe = audience neto (nível 2+)

Não é necessário campos separados — a hierarquia reflete a estrutura organizacional.

### 11.3 Aba "Custom Data" na edição de usuário (wp-admin)

**Implementar o que foi definido no Sprint 6.6:**
- Aba separada "FFC Custom Data" na tela de edição do usuário
- Campos agrupados por audience
- Seções colapsáveis por audience
- Leitura e gravação de `ffc_custom_fields_data`

---

## Sprint 12: Build, Testes, Version Bump & Release

> **Escopo:** Finalização, build, testes, documentação
> **Risco:** Baixo

### 12.1 Migrations

**Novo arquivo:** `includes/migrations/class-ffc-migration-custom-fields-tables.php`
- Criar tabelas caso upgrade de versão anterior
- Seguro para rodar múltiplas vezes (IF NOT EXISTS)

### 12.2 Atualizar settings

**Modificar:** `includes/settings/tabs/class-ffc-tab-documentation.php`
- Documentar novos endpoints REST
- Documentar custom fields API
- Documentar recadastramento workflow

### 12.3 Traduções

**Modificar:** `languages/ffcertificate-pt_BR.po`
- Adicionar todas as novas strings

### 12.4 Build

```bash
npm run build  # Minificar JS e CSS novos
```

### 12.5 Version bump

**Modificar:** `ffcertificate.php`
- Bump `FFC_VERSION`

**Modificar:** `readme.txt`
- Changelog
- Stable tag

---

## Ordem de Dependências

```
Sprint 6 (Custom Fields — Infra & Admin)
    │
    ├──────────────────────────┐
    ▼                          ▼
Sprint 7 (Recadastramento)  Sprint 11 (Audience Enhancements & User Tab)
    │
    ▼
Sprint 8 (Recadastramento — Frontend)
    │
    ├──────────────┐
    ▼              ▼
Sprint 9 (E-mails)  Sprint 10 (Ficha PDF)
    │              │
    └──────┬───────┘
           ▼
    Sprint 12 (Build & Release)
```

> Sprints 9 e 10 podem rodar em paralelo.
> Sprint 11 pode rodar em paralelo com Sprints 7-10.

---

## Resumo: Novas Tabelas

| Tabela | Sprint | Propósito |
|--------|--------|-----------|
| `wp_ffc_custom_fields` | 6 | Definição de campos personalizados por audience |
| `wp_ffc_reregistrations` | 7 | Campanhas de recadastramento |
| `wp_ffc_reregistration_submissions` | 7 | Respostas dos usuários ao recadastramento |

## Resumo: Novos Arquivos

| Arquivo | Sprint | Propósito |
|---------|--------|-----------|
| `includes/reregistration/class-ffc-custom-field-repository.php` | 6 | CRUD de custom fields |
| `includes/admin/class-ffc-admin-user-custom-fields.php` | 6 | Aba custom fields no user edit |
| `includes/reregistration/class-ffc-reregistration-repository.php` | 7 | CRUD de reregistrations |
| `includes/reregistration/class-ffc-reregistration-submission-repository.php` | 7 | CRUD de submissions |
| `includes/reregistration/class-ffc-reregistration-admin.php` | 7 | Admin UI completa |
| `includes/reregistration/class-ffc-reregistration-frontend.php` | 8 | Frontend (formulário + banner) |
| `includes/reregistration/class-ffc-reregistration-email-handler.php` | 9 | Envio de e-mails |
| `includes/reregistration/class-ffc-ficha-generator.php` | 10 | Geração de ficha PDF |
| `templates/emails/reregistration-invitation.php` | 9 | Template e-mail convite |
| `templates/emails/reregistration-reminder.php` | 9 | Template e-mail lembrete |
| `templates/emails/reregistration-confirmation.php` | 9 | Template e-mail confirmação |
| `html/default_ficha_template.html` | 10 | Template HTML da ficha |
| `assets/js/ffc-custom-fields-admin.js` | 6 | Admin JS para custom fields |
| `assets/js/ffc-reregistration-admin.js` | 7 | Admin JS para recadastramento |
| `assets/js/ffc-reregistration-frontend.js` | 8 | Frontend JS |
| `assets/css/ffc-custom-fields-admin.css` | 6 | Admin CSS para custom fields |
| `assets/css/ffc-reregistration-admin.css` | 7 | Admin CSS para recadastramento |
| `assets/css/ffc-reregistration-frontend.css` | 8 | Frontend CSS |

## Resumo: Arquivos Modificados

| Arquivo | Sprints | Mudanças |
|---------|---------|----------|
| `includes/class-ffc-activator.php` | 6, 7 | Criar 3 novas tabelas |
| `includes/class-ffc-loader.php` | 7, 8 | Inicializar módulos |
| `includes/user-dashboard/class-ffc-user-manager.php` | 7 | Nova capability |
| `includes/audience/class-ffc-audience-admin-audience.php` | 6, 11 | Custom fields UI, hierarquia visual |
| `includes/shortcodes/class-ffc-dashboard-shortcode.php` | 8 | Banner de recadastramento |
| `includes/api/class-ffc-user-data-rest-controller.php` | 8 | Endpoints de reregistration |
| `uninstall.php` | 6, 7 | Cleanup de tabelas e metas |
| `assets/css/ffc-user-dashboard.css` | 8 | Estilos do banner |
| `languages/ffcertificate-pt_BR.po` | 12 | Novas traduções |
| `ffcertificate.php` | 12 | Version bump |
| `readme.txt` | 12 | Changelog |

## Resumo de Impacto

| Sprint | Novos Arquivos | Arquivos Modificados | Complexidade |
|--------|---------------|---------------------|-------------|
| 6 | 4 (PHP: 2, JS: 1, CSS: 1) | 3 | Média-Alta |
| 7 | 5 (PHP: 3, JS: 1, CSS: 1) | 4 | Alta |
| 8 | 3 (PHP: 1, JS: 1, CSS: 1) | 3 | Média |
| 9 | 4 (PHP: 4) | 1 | Baixa |
| 10 | 2 (PHP: 1, HTML: 1) | 1 | Baixa |
| 11 | 0 | 2 | Baixa-Média |
| 12 | 1 (PHP: 1) | 4 | Baixa |

---

## Requisitos Consolidados (Referência)

### Audiences
- [x] Hierarquia (parent_id) — já existente na tabela
- [ ] Visualização em árvore no admin (Sprint 11)
- [ ] Custom fields vinculados (Sprint 6)
- [ ] Cascata hierárquica em recadastramentos (Sprint 7)
- [ ] Usuário pode pertencer a múltiplos audiences em hierarquias diferentes

### Custom Fields
- [ ] Definição por audience (Sprint 6)
- [ ] Tipos: text, number, date, select, checkbox, textarea (Sprint 6)
- [ ] Validação de formato: CPF, email, phone, regex custom (Sprint 6)
- [ ] Drag-and-drop para ordenação (Sprint 6)
- [ ] Desativar = ocultar (dados preservados no JSON) (Sprint 6)
- [ ] Aba separada no user edit do wp-admin (Sprint 11)
- [ ] Campos herdados dos audiences pais (Sprint 6)

### Recadastramento
- [ ] Tela admin dedicada (Sprint 7)
- [ ] Vinculado a audiences (Sprint 7)
- [ ] Data início/fim (Sprint 7)
- [ ] Auto-aprovação configurável (Sprint 7)
- [ ] Cascata para audiences filhos (Sprint 7)
- [ ] Status: pending, in_progress, submitted, approved, rejected, expired (Sprint 7)
- [ ] Formulário frontend com campos standard + custom (Sprint 8)
- [ ] Campos pré-populados (Sprint 8)
- [ ] Banner no dashboard do usuário (Sprint 8)
- [ ] CSV export (Sprint 7)
- [ ] Ações em lote: aprovar, enviar lembrete (Sprint 7)
- [ ] Filtros: audience, status, período (Sprint 7)
- [ ] Um recadastramento por audience por usuário (Sprint 7)

### Ficha PDF
- [ ] Geração on-demand client-side (Sprint 10)
- [ ] Template HTML placeholder (Sprint 10)
- [ ] Sem foto de perfil (Sprint 10)
- [ ] Download no admin e pelo usuário (Sprint 10)

### E-mails
- [ ] 3 tipos: convite, lembrete, confirmação (Sprint 9)
- [ ] Todos desabilitados por padrão (Sprint 9)
- [ ] Templates em arquivos dedicados (Sprint 9)
- [ ] Admin toggle por recadastramento (Sprint 7)

### Permissões
- [ ] `ffc_manage_reregistration` capability global (Sprint 7)
- [ ] Admin + usuários com cap gerenciam tudo (Sprint 7)
