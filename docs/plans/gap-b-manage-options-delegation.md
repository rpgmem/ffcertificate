# Plano — GAP B: eliminar `manage_options` "cego" em superfícies delegáveis

> Documento de planejamento para revisão. Origem: issue #488 (GAP B —
> *"`manage_options` ainda domina (~48 ocorrências)"*).
> **Decisões já travadas com o mantenedor estão marcadas ✅.**

## 1. Motivação

O #488 apontou que muitas telas prometidas como delegáveis (6.2.0) seguem
exigindo `manage_options` (admin pleno), apesar de existirem caps granulares.
A auditoria de `develop` confirmou ~48 ocorrências de `manage_options`, mas a
**maioria é bypass de admin** (`passa se manage_options`) — esse é o próprio
modelo "admin faz tudo" e **fica intacto**. Só um punhado são gates de
**autorização única** (`deny a menos que manage_options`) em superfícies
delegáveis.

## 2. Premissa de segurança (igual ao GAP A)

Admins recebem todas as `ADMIN_CAPABILITIES` na ativação/upgrade
(`Activator::register_user_role()` + `Loader::ensure_admin_capabilities()`).
Trocar `manage_options` por um cap granular **não tranca o admin**. Padrão:
`Utils::current_user_can_admin_or($cap)`; em menu cap, passar o slug direto
(admin já o possui).

## 3. Decisões travadas ✅

- **Settings = página inteira** → `ffc_manage_settings` libera toda a página de
  Settings (SMTP, segurança/geofence, rate-limit, locations). É o que o catálogo
  já descreve ("Access the plugin Settings page").
- **Short URLs = novo domínio `url_shortener`** → criar `ffc_view_url_shortener`
  e `ffc_manage_url_shortener` no padrão (registrar em `CapabilityManager` +
  `CapabilityCatalog`). Total de caps passa de 34 (pós-GAP A) para **36**.
- **Sequência: depois do GAP A** → GAP B entra **já em 3 estados completos**
  (oculto / só vê / vê e edita) por superfície, reusando as caps de *view* que o
  GAP A cria. **Depende do GAP A mergeado.**

## 4. Não mexer (KEEP) — ~40 ocorrências

| Categoria | Exemplos | Por quê |
|---|---|---|
| **Bypass de admin** (`passa se manage_options`) | `admin-menu-visibility`, `audience-schedule-repository`, `audience-shortcode`, `dashboard-asset-manager`, `dashboard-shortcode`, `calendar-repository`, `recruitment-pii-access-policy`, `access-control` (`bypass_for_admins`), `user-context-trait`, `user-appointments-rest:186`, `user-profile-rest:434`, `self-scheduling-receipt-handler`, `geofence` bypass | É o modelo "admin sempre passa" |
| **Gestão de permissão** (editar quem-pode-o-quê) | `admin-user-capabilities` (126/565/697), `role-capability-editor:285` | Editar permissões de terceiros = superadmin |
| **Migração / infra** | `admin.php:598` (migrations), `device-threshold-upgrade-notice`, `geofence` test endpoints (658/672), separadores de menu (`audience-admin-page:362-363`), default do `ajax-trait` | Operação perigosa / não delegável |
| **Já delegados** | `public-csv-download:816`, `rate-limit-checker:426` | Já usam `current_user_can_admin_or('ffc_manage_settings')` |

## 5. Trocar (SWAP) — alvos do GAP B, em 3 estados

Regra: `canView = manage_options || view_cap || manage_cap` ·
`canEdit = manage_options || manage_cap`.

### B1 — Settings (domínio `settings`)
- **Menu Settings** (`class-ffc-settings.php:142`): `canView(ffc_view_settings,
  ffc_manage_settings)`. Render read-only no estado "só vê".
- **Settings AJAX** (`class-ffc-settings-ajax-endpoint.php`): default e entradas
  `'cap' => 'manage_options'` (126–466 e fallback :496) → `ffc_manage_settings`
  (tier edita). Entradas que já apontam para caps de módulo (ex.: recrutamento)
  ficam.
- **Locations AJAX / geofence** (`class-ffc-locations-ajax-endpoint.php:59,102`):
  `canEdit(ffc_manage_settings)`.
- **Clear-all-cache** (`class-ffc-form-cache.php:344`): `ffc_manage_settings`
  (ação de manutenção da página de Settings).

### B2 — Reregistration custom-fields submenu (domínio `reregistration`)
- **Submenu *Custom Fields*** (`class-ffc-reregistration-admin.php:111`):
  hoje `manage_options` enquanto a página-mãe usa `ffc_manage_reregistration`
  (inconsistência citada no #488). → `canView(ffc_view_reregistration,
  ffc_manage_reregistration)`; edições já gated por `ffc_manage_reregistration`.

### B3 — REST admin de submissions (domínio `certificates`, overlap GAP A)
- **`check_admin_permission()`** (`class-ffc-submission-rest-controller.php:396`)
  usado por 2 rotas `READABLE` (:65, :94) → `canView(ffc_view_certificates,
  ffc_manage_certificates)`. A rota `CREATABLE` pública (:119,
  `__return_true`) **não muda**. Coordenar com o GAP A (mesmo domínio).

### B4 — Short URLs (novo domínio `url_shortener`)
- **Menu *Short URLs*** (`class-ffc-url-shortener-admin-page.php:126`):
  `canView(ffc_view_url_shortener, ffc_manage_url_shortener)`.
- **CRUD AJAX** (`ajax_create/delete/toggle/trash/restore/empty_trash`,
  :207–304; meta-box `ajax_regenerate` :269): `check_ajax_permission(
  'ffc_manage_url_shortener')` (hoje default `manage_options`).
- **QR download** (`qr-handler` :231,:254): leitura → `ffc_view_url_shortener`.

## 6. Capabilities novas (GAP B)

| Slug | Grupo (catálogo) | Estado |
|---|---|---|
| `ffc_view_url_shortener` | Administration — Modules | só vê |
| `ffc_manage_url_shortener` | Administration — Modules | vê e edita |

Registrar em `CapabilityManager::ADMIN_CAPABILITIES` + `CapabilityCatalog`
(mantendo a invariante `all_slugs() == get_all_capabilities()`), conceder ao
`administrator` (loop de ativação) e adicionar ao role read-only `ffc_operator`
(a view). Seguem o padrão ratificado no #488 / GAP A.

## 7. Dependência e ordenação

- **Requer GAP A mergeado**: usa as caps de *view* (`ffc_view_settings`,
  `ffc_view_reregistration`, `ffc_view_certificates`) e a infraestrutura de
  padrão/catálogo do GAP A. Cortar a branch do GAP B de `develop` **após** o
  merge do GAP A.
- **Overlap B3 × GAP A**: o domínio `certificates` é tocado nos dois. Se o GAP A
  já gatear o REST admin de submissions, B3 vira no-op; caso contrário, B3
  completa.

## 8. Sequência de commits (na implementação, pós-GAP A)

1. **Caps novas** — `ffc_view_url_shortener` / `ffc_manage_url_shortener` no
   `CapabilityManager` + `CapabilityCatalog`; concessão ao admin; `ffc_operator`
   ganha a view.
2. **Gates B1–B4** — aplicar `canView`/`canEdit` por superfície (seção 5),
   incluindo render read-only no estado "só vê".
3. **Testes + CHANGELOG** — cada superfície (oculto/só-vê/edita); invariante do
   catálogo; atualizar testes de menu/AJAX afetados. CHANGELOG `[Unreleased]`:
   `Changed` — telas Settings, Short URLs, REST de submissions e submenu de
   rerregistração passam a aceitar caps granulares (admins inalterados).

## 9. Riscos

- Baixo: os caps de *manage* já existem (exceto os 2 novos do url_shortener) e
  admins os possuem — sem trancar acesso.
- **Settings = página inteira**: confirmado como desejado; quem tiver
  `ffc_manage_settings` acessa SMTP/segurança/geofence. Documentar no CHANGELOG.
- Cobertura: gates novos vêm com teste — não baixar o floor.

## 10. Relacionado

- #488 (GAP B) · GAP A (#490, padrão + view caps — **pré-requisito**) ·
  #482 / #487 (catálogo e editor de roles refletem as caps novas).
