# Plano de Sprints - FFCertificate

## Sprint 1: Correções de Segurança Alta Prioridade
**Foco: CSRF timing + permissões AJAX fracas**

### 1.1 Corrigir fallback de nonce no AjaxTrait
- **Arquivo:** `includes/core/class-ffc-ajax-trait.php` (linhas 37-54)
- **Problema:** `verify_ajax_nonce()` aceita múltiplas ações sequencialmente, criando timing side-channel
- **Correção:** Cada handler deve usar uma única ação de nonce específica, sem fallback

### 1.2 Remover `wp_rest` como fallback de nonce
- **Arquivo:** `includes/self-scheduling/class-ffc-self-scheduling-appointment-ajax-handler.php` (linhas 52, 161, 191, 234)
- **Problema:** `wp_rest` é nonce global do REST API, não específico para a ação
- **Correção:** Usar apenas `ffc_self_scheduling_nonce` como ação

### 1.3 Elevar permissões nos handlers AJAX do Audience
- **Arquivo:** `includes/audience/class-ffc-audience-loader.php` (linhas 327-354, 361-370, 488-496)
- **Problema:** `current_user_can('read')` permite que qualquer subscriber acesse operações sensíveis
- **Correção:** Usar capability adequada (`manage_options` ou custom capability `ffc_manage_audience`)

### 1.4 Corrigir parâmetro `$_GET` em handler POST
- **Arquivo:** `includes/audience/class-ffc-audience-loader.php` (linha 423)
- **Problema:** Usa `$_GET['booking_id']` dentro de handler AJAX POST
- **Correção:** Trocar para `$_POST['booking_id']`

### 1.5 Padronizar nomes de campo nonce
- **Arquivo:** `includes/audience/class-ffc-audience-loader.php` (linha 572)
- **Problema:** Usa `_wpnonce` como campo enquanto outros handlers usam `nonce`
- **Correção:** Padronizar para `nonce` em todos os handlers do Audience

---

## Sprint 2: Correções de Qualidade de Código Média Prioridade
**Foco: stripslashes, IN clause SQL, validação**

### 2.1 Substituir `stripslashes()` por `wp_unslash()`
- **Arquivos:**
  - `includes/admin/class-ffc-submissions-list.php` (linhas 187, 215)
  - `includes/frontend/class-ffc-verification-handler.php` (linhas 101, 419)
- **Problema:** `stripslashes()` pode corromper JSON com aspas escapadas
- **Correção:** Usar `wp_unslash()` que é context-aware do WordPress

### 2.2 Melhorar padrão de IN clause SQL
- **Arquivos:**
  - `includes/repositories/ffc-submission-repository.php` (linhas 216, 267, 415, 528, 558)
  - `includes/repositories/ffc-appointment-repository.php` (linhas 72-76)
- **Problema:** String interpolation com `implode()` de placeholders em SQL, padrão frágil
- **Correção:** Usar padrão consistente com placeholders gerados e prepare(), removendo phpcs:ignore onde possível

### 2.3 Corrigir ordem rate-limit vs. format-check no magic token
- **Arquivo:** `includes/frontend/class-ffc-verification-handler.php` (linhas 328-341)
- **Problema:** Validação de formato acontece antes do rate limiter, permitindo DoS
- **Correção:** Mover rate limiter para antes da validação de formato

### 2.4 Validar falha de json_decode no Audience
- **Arquivo:** `includes/audience/class-ffc-audience-loader.php` (linhas 697-701)
- **Problema:** Se `json_decode()` falhar e retornar null, o fallback não é adequado
- **Correção:** Adicionar check explícito para `json_last_error()` ou validação do resultado

---

## Sprint 3: Testes URL Shortener - Service & Repository
**Foco: Cobertura das classes core de negócio**

### 3.1 Criar `UrlShortenerServiceTest.php`
- **Métodos a testar (16):**
  - `create_short_url()` - sucesso, erro, post duplicado
  - `generate_unique_code()` - Base62, colisão, comprimento
  - `get_short_url()` - construção de URL
  - `get_prefix()` - sanitização, default 'go'
  - `get_code_length()` - limites 4-10, default 6
  - `get_redirect_type()` - 301/302/307, default 302
  - `is_enabled()` - settings check, default true
  - `is_auto_create_enabled()` - settings check
  - `get_enabled_post_types()` - array parsing
  - `delete_short_url()` / `trash_short_url()` / `restore_short_url()`
  - `toggle_status()` - active↔disabled
  - `get_stats()` - agregação
  - `get_repository()` - getter

### 3.2 Criar `UrlShortenerRepositoryTest.php`
- **Métodos a testar (6 custom + inherited):**
  - `findByShortCode()` - cache hit/miss, null
  - `findByPostId()` - apenas active, cache
  - `incrementClickCount()` - sucesso/falha, cache clear
  - `codeExists()` - existe/não existe
  - `findPaginated()` - WHERE building, search, sort, paginação
  - `getStats()` - agregação com null handling

---

## Sprint 4: Testes URL Shortener - Loader & Admin
**Foco: Cobertura dos handlers HTTP e admin**

### 4.1 Criar `UrlShortenerLoaderTest.php`
- **Métodos a testar (7):**
  - `init()` - hooks condicionais
  - `maybe_flush_rewrite_rules()` - version tracking
  - `register_rewrite_rules()` - regex
  - `add_query_vars()` - adição de query var
  - `handle_redirect()` - fluxo completo de redirect
  - `flush_rules()` - método estático

### 4.2 Criar `UrlShortenerAdminPageTest.php`
- **Métodos a testar (12):**
  - `handle_actions()` - nonce, roteamento
  - `ajax_create()` - validação, permissão
  - `ajax_delete()` / `ajax_trash()` / `ajax_restore()`
  - `ajax_empty_trash()` - bulk delete
  - `ajax_toggle()` - toggle de status

---

## Sprint 5: Testes URL Shortener - MetaBox, QR, Activator
**Foco: Componentes de UI e geração de QR**

### 5.1 Criar `UrlShortenerMetaBoxTest.php`
- **Métodos a testar (7):**
  - `register_meta_box()` - registro por post type
  - `on_save_post()` - auto-create com guards
  - `ajax_regenerate()` - fluxo de regeneração

### 5.2 Criar `UrlShortenerQrHandlerTest.php`
- **Métodos a testar (7):**
  - `generate_qr_base64()` - geração PNG
  - `generate_svg()` - geração SVG
  - `handle_download_png()` / `handle_download_svg()`
  - `resolve_qr_target()` - via reflection

### 5.3 Criar `UrlShortenerActivatorTest.php`
- **Métodos a testar (3):**
  - `get_table_name()` - nome da tabela
  - `create_tables()` - criação idempotente
  - `maybe_migrate()` - migrações

---

## Sprint 6: Hardening Adicional de Segurança
**Foco: Melhorias de segurança de baixa prioridade**

### 6.1 Adicionar rate limiting na submissão de formulário
- **Arquivo:** `includes/frontend/class-ffc-form-processor.php` (linhas 47-51)
- **Problema:** Nenhum rate limiting/CAPTCHA antes de permission checks
- **Correção:** Reordenar: rate limit → CAPTCHA → nonce

### 6.2 Revisar fallback JSON no Audience loader
- **Arquivo:** `includes/audience/class-ffc-audience-loader.php`
- Garantir que todos os `json_decode()` tenham tratamento de erro adequado

### 6.3 Documentar decisões de segurança
- Adicionar comentários claros onde nonce é intencionalmente omitido (magic tokens)
- Padronizar phpcs:ignore comments com justificativas

---

## Resumo

| Sprint | Escopo | Arquivos | Prioridade |
|--------|--------|----------|------------|
| 1 | Segurança Alta | 3 arquivos | ALTA |
| 2 | Qualidade de Código | 4 arquivos | MÉDIA |
| 3 | Testes Service+Repo | 2 novos arquivos | MÉDIA |
| 4 | Testes Loader+Admin | 2 novos arquivos | MÉDIA |
| 5 | Testes MetaBox+QR+Act | 3 novos arquivos | MÉDIA |
| 6 | Hardening Adicional | 2 arquivos | BAIXA |
