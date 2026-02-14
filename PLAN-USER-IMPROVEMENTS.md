# Plano de Melhorias: Sistema de Usuários FFC

## Decisões de Arquitetura (Aprovadas)

1. **User Delete** → Anonimizar (user_id = NULL, dados sensíveis removidos)
2. **User Profiles** → Tabela dedicada `wp_ffc_user_profiles`
3. **Capabilities** → Implementar checks reais + padronizar nos constants
4. **LGPD/Privacy** → Implementar exporters e erasers agora

---

## Sprint 1: Fundação — Capabilities & Correções Estruturais
> **Escopo:** Corrigir inconsistências existentes sem alterar o schema do banco
> **Risco:** Baixo (refatoração interna, sem breaking changes)

### 1.1 Padronizar capabilities nos constants do UserManager
**Arquivo:** `includes/user-dashboard/class-ffc-user-manager.php`
- Adicionar `AUDIENCE_CAPABILITIES` array com `ffc_view_audience_bookings`
- Adicionar `ADMIN_CAPABILITIES` array com `ffc_scheduling_bypass`
- Adicionar `ALL_FFC_CAPABILITIES` que consolida todos os arrays
- Adicionar método `grant_audience_capabilities()`
- Atualizar `get_user_ffc_capabilities()` para incluir audience + admin caps
- Atualizar `set_user_capability()` para validar contra ALL_FFC_CAPABILITIES
- Adicionar `CONTEXT_AUDIENCE = 'audience'` constant

### 1.2 Implementar checks das capabilities não verificadas
**Arquivos afetados:**
- `includes/api/class-ffc-user-data-rest-controller.php`
  - GET /user/certificates: Verificar `download_own_certificates` ao gerar `pdf_url`/`magic_link` (retornar URL vazia se capability ausente)
  - GET /user/certificates: Verificar `view_certificate_history` para filtrar submissões (se desabilitado, mostrar apenas a mais recente por form_id)
- `includes/frontend/class-ffc-verification-handler.php`
  - Verificar `download_own_certificates` no acesso via dashboard (não afeta magic link público)

### 1.3 Corrigir CSV Importer — capabilities incompletas
**Arquivo:** `includes/audience/class-ffc-audience-csv-importer.php`
- Linhas 382-385: Substituir `add_cap` manual por `UserManager::grant_certificate_capabilities()`

### 1.4 Corrigir uninstall.php — capabilities faltantes
**Arquivo:** `uninstall.php`
- Linhas 128-135: Adicionar:
  - `ffc_scheduling_bypass`
  - `ffc_view_audience_bookings`
  - `ffc_reregistration`
  - `ffc_certificate_update`
- Idealmente: referenciar array centralizado (mas uninstall.php precisa ser standalone)

### 1.5 AdminUserCapabilities — usar constants centralizados
**Arquivo:** `includes/admin/class-ffc-admin-user-capabilities.php`
- `save_capability_fields()` linhas 258-273: Substituir lista hardcoded por referência ao UserManager::ALL_FFC_CAPABILITIES
- Garantir que novas capabilities futuras se propaguem automaticamente

### 1.6 Simplificar modelo híbrido de capabilities (role vs user_meta)
**Problema:** A role `ffc_user` define capabilities como `true` por padrão, mas `create_ffc_user()`
faz `set_role('ffc_user')` → `reset_user_ffc_capabilities()` (zera tudo) → `grant_context_capabilities()`
(readiciona por contexto). Se a role ganhar novas capabilities no futuro, usuários existentes não herdam.

**Arquivo:** `includes/user-dashboard/class-ffc-user-manager.php`
- Alterar `register_role()`: Role `ffc_user` passa a ter TODAS as capabilities como `false` por padrão (apenas `read` = true)
- Capabilities são concedidas EXCLUSIVAMENTE via user_meta (per-user), não mais via role
- Remover `reset_user_ffc_capabilities()` do fluxo de criação — não é mais necessário pois role não concede nada
- Simplifica o fluxo para: `set_role('ffc_user')` → `grant_context_capabilities()`
- `upgrade_role()`: Adicionar novas capabilities como `false` (role nunca concede por padrão)
- Resultado: **user_meta é a única fonte de verdade**, role apenas agrupa/identifica

**Impacto:** Elimina o conflito role vs user_meta. Migrações futuras de capabilities ficam mais previsíveis.

---

## Sprint 2: Tabela ffc_user_profiles, Hook de Deleção & Email Change
> **Escopo:** Criar infraestrutura nova de perfil + tratamento de deleção + email
> **Risco:** Médio (nova tabela, migração de dados, novo hook)

### 2.1 Criar tabela `wp_ffc_user_profiles`
**Modificar:** `includes/class-ffc-activator.php`
- Adicionar método `create_user_profiles_table()`

**Schema:**
```sql
CREATE TABLE wp_ffc_user_profiles (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    display_name VARCHAR(250) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    department VARCHAR(250) DEFAULT '',
    organization VARCHAR(250) DEFAULT '',
    notes TEXT DEFAULT '',
    preferences JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_user_id (user_id)
);
```

### 2.2 Migration: Popular profiles com dados existentes
**Novo arquivo:** `includes/migrations/class-ffc-migration-user-profiles.php`
- Para cada `ffc_user`:
  - Copiar `display_name` de wp_users
  - Copiar `ffc_registration_date` de wp_usermeta → `created_at`
  - Extrair nomes de submissions (campo `nome_completo` etc.)
- Batch processing com batch_size configurável
- Suporte a dry_run para preview

### 2.3 Refatorar UserManager para gravar em ffc_user_profiles
**Arquivo:** `includes/user-dashboard/class-ffc-user-manager.php`
- `create_ffc_user()`: Criar registro em ffc_user_profiles após wp_create_user
- `sync_user_metadata()`: Gravar em ffc_user_profiles + manter wp_users.display_name sincronizado
- Adicionar métodos: `get_profile()`, `update_profile()`

### 2.4 Atualizar REST API para ler de ffc_user_profiles
**Arquivo:** `includes/api/class-ffc-user-data-rest-controller.php`
- `get_user_profile()`: Fonte primária = ffc_user_profiles, fallback = wp_users
- Incluir novos campos (phone, department, organization) na resposta

### 2.5 Implementar hook `deleted_user` — Anonimização
**Novo arquivo:** `includes/user-dashboard/class-ffc-user-cleanup.php`

```php
class UserCleanup {
    public static function init(): void {
        add_action('deleted_user', [__CLASS__, 'anonymize_user_data']);
    }

    public static function anonymize_user_data(int $user_id): void {
        // ffc_submissions: SET user_id = NULL
        // ffc_self_scheduling_appointments: SET user_id = NULL
        // ffc_audience_members: DELETE
        // ffc_audience_booking_users: DELETE
        // ffc_audience_schedule_permissions: DELETE
        // ffc_user_profiles: DELETE
        // ffc_activity_log: SET user_id = NULL (manter audit trail)
        // Log: "User data anonymized"
    }
}
```

### 2.6 Tratar mudança de email do WordPress
**Problema:** Se o usuário (ou admin) troca o email no WordPress, novas submissions com o email antigo
não vinculam ao mesmo user_id. O hash de email nas submissions fica desatualizado.

**Arquivo:** `includes/user-dashboard/class-ffc-user-cleanup.php` (ou novo handler)
- Registrar `add_action('profile_update', [__CLASS__, 'handle_email_change'], 10, 3)`
- Detectar se `user_email` mudou (comparar old_user_data com novo)
- Quando email muda:
  - Atualizar `ffc_user_profiles` se necessário
  - Reindexar `email_hash` nas submissions ligadas ao user_id (para que buscas por hash do novo email também encontrem)
  - Logar a mudança no activity log
- **NÃO** alterar `email_encrypted` das submissions (são registros históricos — o email na época da emissão)

### 2.7 Registrar hooks no Loader
**Arquivo:** `includes/class-ffc-loader.php`
- Adicionar `UserCleanup::init()` no boot do plugin

### 2.8 Atualizar uninstall.php
**Arquivo:** `uninstall.php`
- Adicionar DROP TABLE `wp_ffc_user_profiles`

---

## Sprint 3: LGPD/Privacy — Exporters & Erasers
> **Escopo:** Integração com WordPress Privacy Tools (Tools > Export/Erase Personal Data)
> **Risco:** Médio (decrypt em batch, volume de dados)
> **Depende de:** Sprint 2 (profiles table + cleanup logic)

### 3.1 Criar Privacy Handler
**Novo arquivo:** `includes/privacy/class-ffc-privacy-handler.php`

```php
class PrivacyHandler {
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
    }
}
```

### 3.2 Implementar Exporter
**Grupos de dados exportados:**

| Grupo | Campos |
|-------|--------|
| FFC Profile | display_name, email, phone, department, organization, member_since |
| FFC Certificates | form_title, submission_date, auth_code, consent_given |
| FFC Appointments | calendar_title, date, time, status, name, email, phone, notes |
| FFC Audience Groups | audience_name, joined_date |
| FFC Audience Bookings | environment, date, time, description, status |

**Lógica:**
- Localizar user_id pelo email
- Descriptografar dados para exportação
- Retornar formato `$export_items[]` do WordPress
- Paginação via `$page` parameter (50 items por batch)

### 3.3 Implementar Eraser
**Ações por tabela:**

| Tabela | Ação |
|--------|------|
| ffc_user_profiles | DELETE registro |
| ffc_submissions | SET user_id = NULL, limpar email_encrypted, cpf_rf_encrypted |
| ffc_self_scheduling_appointments | SET user_id = NULL, limpar email_encrypted, name, phone |
| ffc_audience_members | DELETE registros |
| ffc_audience_booking_users | DELETE registros |
| ffc_audience_schedule_permissions | DELETE registros |
| ffc_activity_log | SET user_id = NULL |

**Importante:** Manter `auth_code`, `magic_token` e `cpf_rf_hash` nas submissions para que certificados já emitidos continuem verificáveis via link público.

### 3.4 Registrar no Loader
**Arquivo:** `includes/class-ffc-loader.php`
- Inicializar `PrivacyHandler::init()`

---

## Sprint 4: Dashboard Editável, Appointments Anônimos & Username
> **Escopo:** Permitir edição de perfil + resolver appointments anônimos + username
> **Risco:** Baixo-Médio (novos endpoints, formulário frontend)
> **Depende de:** Sprint 2 (profiles table)

### 4.1 Endpoint REST para atualizar perfil
**Arquivo:** `includes/api/class-ffc-user-data-rest-controller.php`
- Novo: `PUT /user/profile`
- Campos editáveis: `display_name`, `phone`, `department`, `organization`
- Permission: `is_user_logged_in`
- Sanitização: `sanitize_text_field()` para todos os campos
- Atualizar ffc_user_profiles + wp_users.display_name

### 4.2 Formulário de edição no dashboard
**Arquivo:** `assets/js/ffc-user-dashboard.js`
- Tab "Profile": Botão "Editar Perfil" → modo inline edit
- Campos: display_name (text), phone (tel), department (text), organization (text)
- Validação frontend + chamada REST
- Feedback visual (sucesso/erro)

### 4.3 Melhorar exibição do perfil
- Mostrar todos os campos de ffc_user_profiles
- CPFs/RFs e emails como tags read-only
- Grupos de audiência com badges coloridos
- Seção "Seus Acessos": listar capabilities ativas como ícones (certificados, agendamentos, etc.)

### 4.4 Vincular appointments anônimos ao usuário após login
**Problema:** Appointments criados sem login (user_id = NULL) nunca aparecem no dashboard,
mesmo que o usuário depois faça login com o mesmo email/CPF.

**Arquivo:** `includes/user-dashboard/class-ffc-user-manager.php`
- No `get_or_create_user()`: Após identificar/criar o user_id, buscar appointments órfãos:
  ```sql
  UPDATE ffc_self_scheduling_appointments
  SET user_id = %d
  WHERE cpf_rf_hash = %s AND user_id IS NULL
  ```
- Isso vincula retroativamente appointments anônimos ao usuário
- Executar apenas quando user_id é determinado pela primeira vez para aquele CPF/RF

**Arquivo alternativo:** `includes/self-scheduling/class-ffc-self-scheduling-appointment-handler.php`
- Em `create_or_link_user()`: Após vincular, fazer UPDATE nos appointments sem user_id

### 4.5 Resolver username = email
**Problema:** Username é literalmente o email. Se email muda, username fica desatualizado.
WordPress não permite alterar usernames. Afeta futuras integrações SSO.

**Arquivo:** `includes/user-dashboard/class-ffc-user-manager.php`
- Alterar `create_ffc_user()`:
  - Gerar username baseado em slug sanitizado do nome (ex: "joao-silva")
  - Se nome não disponível, usar prefixo `ffc_` + 8 chars aleatórios (ex: "ffc_a3k9m2p1")
  - Garantir unicidade via `username_exists()`
  - Email continua sendo usado como campo `user_email` (não muda)
- Resultado: Username é um identificador estável, email pode mudar livremente

**Arquivo:** `includes/migrations/class-ffc-migration-user-link.php`
- Linha 182: Mesmo ajuste para migração (gerar username a partir do nome em vez do email)

**Nota:** Usuários existentes mantêm o username atual (email). A mudança afeta apenas novos usuários.
Migrar usernames existentes é desnecessariamente arriscado e pode quebrar logins ativos.

---

## Sprint 5: Robustez, Performance & FK Constraints
> **Escopo:** Otimizações, centralização e integridade referencial
> **Risco:** Baixo (otimizações internas)
> **Depende de:** Sprint 2-3

### 5.1 Cache de contagem na lista de usuários admin
**Arquivo:** `includes/admin/class-ffc-admin-user-columns.php`
- Batch query: `SELECT user_id, COUNT(*) FROM ffc_submissions GROUP BY user_id` (single query)
- Cache via transient (invalidar no save/delete de submissions/appointments)

### 5.2 Corrigir view-as + capability no REST
**Arquivo:** `includes/api/class-ffc-user-data-rest-controller.php`
- Quando admin usa view-as: verificar capabilities do usuário-alvo
- Admin vê exatamente o que o usuário veria

### 5.3 Criar UserService centralizado
**Novo arquivo:** `includes/services/class-ffc-user-service.php`

```php
class UserService {
    public static function get_full_profile(int $user_id): array { }
    public static function export_personal_data(int $user_id): array { }
    public static function anonymize_personal_data(int $user_id): array { }
    public static function get_user_statistics(int $user_id): array { }
}
```
- Usado por: REST controller, PrivacyHandler, UserCleanup
- Single point of truth para toda lógica de usuário

### 5.4 Adicionar FOREIGN KEY constraints
**Problema:** `user_id` em todas as tabelas custom é BIGINT sem FK real.
Não há integridade referencial a nível de banco. O hook `deleted_user` (Sprint 2.5)
resolve o efeito prático, mas FKs adicionam uma camada de segurança.

**Arquivo:** Nova migration ou método no activator
- Adicionar FK constraints com `ON DELETE SET NULL` nas tabelas que anonimizam:
  ```sql
  ALTER TABLE wp_ffc_submissions
    ADD CONSTRAINT fk_submissions_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE SET NULL;

  ALTER TABLE wp_ffc_self_scheduling_appointments
    ADD CONSTRAINT fk_appointments_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE SET NULL;

  ALTER TABLE wp_ffc_activity_log
    ADD CONSTRAINT fk_activity_log_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE SET NULL;
  ```
- Adicionar FK com `ON DELETE CASCADE` nas tabelas que deletam:
  ```sql
  ALTER TABLE wp_ffc_audience_members
    ADD CONSTRAINT fk_audience_members_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE;

  ALTER TABLE wp_ffc_audience_booking_users
    ADD CONSTRAINT fk_booking_users_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE;

  ALTER TABLE wp_ffc_audience_schedule_permissions
    ADD CONSTRAINT fk_schedule_permissions_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE;

  ALTER TABLE wp_ffc_user_profiles
    ADD CONSTRAINT fk_user_profiles_user
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE;
  ```

**Nota:** FKs funcionam como safety net redundante junto com o hook `deleted_user`.
Se o hook falhar por qualquer motivo, o banco garante a integridade.

**Cuidado:** Verificar se wp_users usa InnoDB (padrão desde WP 5.x). MyISAM não suporta FKs.
Incluir check no migration: se engine != InnoDB, pular FKs e logar warning.

---

## Ordem de Dependências

```
Sprint 1 (Capabilities + Modelo Simplificado)
    │
    ▼
Sprint 2 (Profiles + Delete Hook + Email Change)
    │
    ├──────────────────┐
    ▼                  ▼
Sprint 3 (LGPD)    Sprint 4 (Dashboard + Anônimos + Username)
    │                  │
    └──────┬───────────┘
           ▼
    Sprint 5 (Robustez + FK)
```

> Sprints 3 e 4 podem rodar em paralelo.

---

## Mapa Completo: Problema → Sprint

| # | Problema | Sprint | Item |
|---|---|---|---|
| 1 | Nenhum hook `deleted_user` | 2 | 2.5 |
| 2 | Sem Privacy Tools (LGPD export/erase) | 3 | 3.1-3.4 |
| 3 | `download_own_certificates` nunca verificada | 1 | 1.2 |
| 4 | `view_certificate_history` nunca verificada | 1 | 1.2 |
| 5 | `ffc_scheduling_bypass` fora dos constants | 1 | 1.1 |
| 6 | `ffc_view_audience_bookings` fora dos constants | 1 | 1.1 |
| 7 | uninstall.php não remove 4 capabilities | 1 | 1.4 |
| 8 | CSV importer hardcoda capabilities | 1 | 1.3 |
| 9 | Admin UI hardcoda lista de capabilities | 1 | 1.5 |
| 10 | N+1 queries na lista de usuários admin | 5 | 5.1 |
| 11 | view-as verifica capability do admin, não do alvo | 5 | 5.2 |
| 12 | Dados de perfil espalhados sem centralização | 2 | 2.1-2.4 |
| 13 | Sem export centralizado de dados do usuário | 5 | 5.3 |
| 14 | Username = Email (inflexibilidade) | 4 | 4.5 |
| 15 | Sem FK real (integridade referencial) | 5 | 5.4 |
| 16 | Email change não tratado | 2 | 2.6 |
| 17 | Appointments anônimos invisíveis no dashboard | 4 | 4.4 |
| 18 | Modelo híbrido de capabilities (role vs user_meta) | 1 | 1.6 |

---

## Resumo de Impacto

| Sprint | Modificados | Novos | Complexidade |
|--------|-------------|-------|-------------|
| 1 | 5 arquivos | 0 | Baixa |
| 2 | 5 arquivos | 2 novos | Média |
| 3 | 1 arquivo | 1 novo | Média |
| 4 | 3 arquivos | 0-1 | Média |
| 5 | 3 arquivos | 2 novos | Baixa-Média |
