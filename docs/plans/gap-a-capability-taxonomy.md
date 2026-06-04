# Plano — GAP A: padronização da taxonomia de capabilities + modelo de 3 estados

> Documento de planejamento para revisão. Origem: auditoria do mapa de
> permissionamento registrada na issue #488 (GAP A — capabilities "mortas").
> **Decisões já travadas com o mantenedor estão marcadas ✅.**

## 1. Motivação

A issue #488 mapeou 3 capabilities **definidas, catalogadas e atribuídas a
roles, porém sem nenhum gate que as cheque** ("mortas"):

- `ffc_manage_certificates` — *Submissions* usa `manage_options`; *Certificates
  Dashboard* usa `edit_others_posts`.
- `ffc_manage_user_custom_fields` — a tela de campos personalizados usa
  `edit_user`.
- `ffc_manage_recruitment_settings` — redundante: settings de recrutamento são
  gated pela umbrella `ffc_manage_recruitment`.

Ao revisar, o mantenedor estabeleceu duas premissas que ampliam o escopo:

1. **Modelo de 3 estados por superfície** — *não vê* / *só vê* / *vê e edita*,
   com o admin do WordPress (`manage_options`) por cima de tudo.
2. **Padrão de nomes de capabilities** unificado, válido para o plugin inteiro,
   aplicado de uma vez via migração ("migração completa junto").

## 2. Premissa de segurança (vale para toda a mudança)

Na ativação e no upgrade, o role `administrator` recebe **todas** as
`ADMIN_CAPABILITIES`:

- `Activator::register_user_role()` — concede no momento da ativação.
- `Loader::ensure_admin_capabilities()` — auto-heal idempotente por versão
  (`ffc_admin_caps_version_v2`).

Logo, ligar gates de caps que hoje estão "mortos" **não tranca o admin** — ele
já possui as caps. Existe também o helper
`Utils::current_user_can_admin_or( string $cap ): bool` (admin **ou** portador
do cap granular).

## 3. Padrão de nomenclatura ✅ (ratificado)

```
ffc_<ação>_[own_]<domínio>[_<qualificador>]
```

- **Ações (vocabulário fechado):** `view` (só lê) · `manage` (lê e edita:
  criar/editar/excluir/configurar) · `export` · `import` · `edit` (editar
  registro existente — mais estreito que manage) · `delete`.
  Especiais de fluxo: `book`, `cancel`, `download`, `call`, `bypass`.
- **`own_`** — escopo do próprio usuário (frontend).
- **Domínios canônicos:** `certificates`, `appointments`, `audiences`,
  `reregistration`, `custom_fields`, `activity_log`, `settings`, `recruitment`,
  `forms_api`.
- **Qualificadores:** `_pii`, `_settings`, `_reasons`, `_history`.
- **Par base do modelo 3-estados (todo domínio admin):**
  `ffc_view_<domínio>` (só vê) + `ffc_manage_<domínio>` (vê e edita).

### Regra de gate (padrão)

```
canView = current_user_can('manage_options') || view_cap || manage_cap
canEdit = current_user_can('manage_options') || manage_cap
```

O role de *manage* **não** precisa carregar também o cap de *view* — a
visibilidade é concedida por `canView`, que já inclui o manage.

## 4. Modelo de 3 estados

| Estado | Condição | Comportamento |
|---|---|---|
| Não vê | sem view **e** sem manage | menu/aba/seção **oculta**; URL direta → negada |
| Só vê | tem **view** | render **read-only**: inputs `disabled`, sem botão salvar, ações de linha/bulk ocultas |
| Vê e edita | tem **manage** | render normal + salvar/ações |
| Admin WP | `manage_options` | sempre vê e edita |

## 5. Domínio de auto-agendamento ✅

Investigação do código (validador/REST/shortcode) confirmou:

- "self-scheduling" é **um domínio só**; o eixo *público vs logado* **não é
  capability** — é o atributo **por calendário** `scheduling_visibility
  enum('public','private')`. Calendário público = anônimo agenda (capless por
  design); privado = login + cap de booking.
- `ffc_view_self_scheduling` não denota "tipo" de agendamento — é o
  "ver os próprios agendamentos", com nome herdado ambíguo.

**Decisão:** domínio único **`appointments`** (resolve a ambiguidade e alinha
com `book`/`cancel` que já usam "appointments"). A **delegação por
visibilidade** (manage público vs privado) é uma feature à parte, registrada na
issue **#489**, fora do escopo deste plano.

## 6. Renomeações ✅ (migração no mesmo PR)

| # | Antigo | Novo | Observação |
|---|---|---|---|
| 1 | `ffc_view_certificate_history` | `ffc_view_own_certificate_history` | |
| 2 | `ffc_view_self_scheduling` | `ffc_view_own_appointments` | ⚠ **reverte** a migração 4.5.0 (`MigrationRenameCapabilities` mapeou `own_appointments → self_scheduling`); usar **nova** option-key de migração |
| 3 | `ffc_view_audience_bookings` | `ffc_view_own_audience_bookings` | |
| 4 | `ffc_book_appointments` | `ffc_book_own_appointments` | |
| 5 | `ffc_manage_self_scheduling` | `ffc_manage_appointments` | |
| 6 | `ffc_certificate_update` | `ffc_edit_certificates` | |
| 7 | `ffc_manage_user_custom_fields` | `ffc_manage_custom_fields` | |
| 8 | `ffc_import_recruitment_csv` | `ffc_import_recruitment` | |
| 9 | `ffc_call_recruitment_candidates` | `ffc_call_recruitment` | |
| 10 | `ffc_read_forms_api` | `ffc_view_forms_api` | |

**Especiais mantidos:** `ffc_scheduling_bypass`, `ffc_view_as_user`,
`ffc_manage_recruitment` (umbrella).

## 7. Capabilities de *view* novas ✅ ("view em todas")

`ffc_view_certificates` · `ffc_view_appointments` · `ffc_view_audiences` ·
`ffc_view_reregistration` · `ffc_view_custom_fields` · `ffc_view_settings` ·
`ffc_view_recruitment_settings` · `ffc_view_recruitment_reasons`.

`ffc_view_activity_log` e `ffc_view_recruitment` já existem.
**Total após a mudança: 34 capabilities.**

## 8. Superfícies do GAP A (gates 3 estados)

### Parte 1 — Certificates
- **Menu *Submissions*** (`includes/admin/class-ffc-admin.php:200`) e
  **Certificates Dashboard** (`class-ffc-certificates-dashboard.php:28`):
  `canView(ffc_view_certificates, ffc_manage_certificates)`.
- **Ações** (row/bulk em `class-ffc-submissions-list.php`,
  `submissions-bulk-actions-ajax-endpoint`, `handle_submission_actions()`):
  `canEdit(ffc_manage_certificates)`.
- **Tela de edição de submission:** `ffc_edit_certificates`.

### Parte 2 — Custom fields (definições, na tela de Audiências)
- **Render** `render_custom_fields_section()`
  (`includes/audience/class-ffc-audience-admin-audience.php:352`):
  `canView(ffc_view_custom_fields, ffc_manage_custom_fields)` — read-only com
  controles `disabled` no estado "só vê".
- **AJAX** `ffc_save_custom_fields` / `ffc_delete_custom_field` /
  `ffc_replicate_field_options`
  (`class-ffc-audience-ajax-controller.php`): `canEdit(ffc_manage_custom_fields)`
  (hoje `check_ajax_permission()` cai em `manage_options`).

### Parte 3 — Recruitment settings (`page=ffc-recruitment&tab=settings`)
- **Ver** a aba: `canView(ffc_view_recruitment_settings,
  ffc_manage_recruitment_settings)`; render do form em read-only no estado "só
  vê".
- **Salvar**: filtro
  `option_page_capability_ffc_recruitment_settings_group` →
  `ffc_manage_recruitment_settings` (hoje `options.php` cai em `manage_options`,
  bloqueando recruitment-admin).
- **Caveat:** a página `ffc-recruitment` inteira hoje faz `wp_die` sem a umbrella
  `ffc_manage_recruitment` — o tier "só vê" pleno do recrutamento depende de
  destravar o acesso read-only da UI (item maior; ver #488 P2/P3). Nesta entrega
  a aba fica oculta sem cap e o save passa a exigir o cap granular.

### Demais domínios
Ligar pelo menos o par *view* nas telas que já existem
(appointments/audiences/reregistration/settings), mantendo o `manage` atual.

## 9. Sequência de commits

1. **Padrão + registro + catálogo + migração**
   - Seção "Capability naming" no `CLAUDE.md`.
   - Renomear no `CapabilityManager` (constantes `*_CAPABILITIES`,
     `module_roles_definition()`, `grant_*`, `ffc_managed_role_labels()`) +
     adicionar as 8 caps de view.
   - Atualizar `CapabilityCatalog` (mantém a invariante
     `all_slugs() == get_all_capabilities()` do `CapabilityCatalogTest`).
   - Migração de dados: para cada `old → new`, reescrever grants em `user_meta`
     (todos os usuários) **e** nas definições de role; option-key própria,
     idempotente, **distinta** da de 4.5.0.
   - `Loader`/`Activator`: loop de concessão ao `administrator` passa a usar os
     nomes novos + as view novas.
2. **Gates 3-estados** — Partes 1/2/3 da seção 8.
3. **Roles** — `ffc_operator` (read-only) ganha as view novas; definições
   migradas para os nomes novos; #487 (editor) e #482 (catálogo) refletem via
   catálogo.
4. **Testes + CHANGELOG** — invariante; cada par view/manage (só-vê não edita /
   edita); migração old→new (user_meta + role); `option_page_capability`; aba
   read-only; atualizar testes que citam slugs antigos
   (`AdminUserCapabilitiesTest`, `RecruitmentCapabilityManagerTest`,
   `MigrationRenameCapabilitiesTest`, etc.). CHANGELOG `[Unreleased]` com
   **banner ⚠ breaking-change** (os 10 renames + telas que passaram a exigir
   cap).

## 10. Riscos

- **Rename #2 reverte a migração 4.5.0** — documentar no código e no CHANGELOG;
  usar nova option-key para não colidir.
- **Breaking-change**: os 10 renames quebram integrações externas que
  referenciam os slugs antigos (filters/hooks, Application Passwords). Banner
  obrigatório.
- **Cobertura**: gates novos vêm com teste no mesmo PR — não baixar o floor.

## 11. Fora de escopo (roadmap)

- #489 — delegação de auto-agendamento por visibilidade (manage público vs
  privado).
- #488 P2/P3 — demais caps de view/roles novos (ex.: auditor de rerregistração,
  role `ffc_administrator` agregador) e acesso read-only pleno à UI de
  recrutamento.

## 12. PR

Branch `claude/gap-a-capability-taxonomy` ← `origin/develop`. PR **draft** →
`develop` (este documento é para revisão prévia; a implementação segue em PR
próprio ou nos commits 1–4 acima após aprovação). Sem bump de `FFC_VERSION`
(PR contra develop).
