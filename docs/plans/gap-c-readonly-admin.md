# Plano — GAP C: tier "só vê" (read-only) nas páginas admin dos módulos

> Origem: #488 (GAP C — *assimetria entre módulos; falta read-only fora do
> recrutamento*). Premissas ratificadas com o mantenedor:
> **(1)** as 4 superfícies (appointments, audiences, reregistration, recruitment)
> ganham o tier "só vê"; **(2)** profundidade "correto + esconder topo".

## Premissas

- O GAP A já **criou** as view caps (`ffc_view_appointments`, `ffc_view_audiences`,
  `ffc_view_reregistration`, `ffc_view_recruitment`) e fez os **manage roles
  carregarem a view**; admins têm todas as caps. Logo
  **`canView = Utils::current_user_can_admin_or($view_cap)`** basta.
- Profundidade: abrir a página ao viewer + manter as escritas em `manage`
  server-side + **esconder os botões de escrita de topo** (criar/salvar/excluir,
  ações de linha). Não desabilitar todo input (polimento total ficou fora).

## Achado de segurança crítico (por superfície)

A segurança do read-only depende de as **escritas serem capadas
independentemente** do gate da página:

| Superfície | Escritas hoje | Abrir página é seguro? |
|---|---|---|
| **Reregistration** | AJAX handler + POST em `ffc_manage_reregistration` | ✅ sim |
| **Audience** | write AJAX em `check_ajax_permission()` (independente da página) | ✅ sim |
| **Appointments** (self-scheduling) | cancel/exports — verificar cap próprio | ⚠ verificar antes |
| **Recruitment** | `RecruitmentAdminActions::dispatch` só faz `check_admin_referer` (**sem re-checar cap** — confia no gate da página) | ❌ **NÃO** — exige hardening primeiro |

→ **Recruitment**: antes de abrir `render_page` ao viewer, é obrigatório gatear
cada ação de escrita de `dispatch()` por cap (`ffc_manage_recruitment` para
delete/promote/attach; `ffc_call_recruitment`/`ffc_import_recruitment` já têm
endpoints próprios). Sem isso, um viewer com a nonce da página apagaria dados.

## Estrutura de menu (bloqueador estrutural)

O menu top-level **"Scheduling"** (`ffc-scheduling`) é registrado pelo
`AudienceAdminPage` com `ffc_manage_audiences` e hospeda **appointments
(self-scheduling) + audiences**. Como o cap do menu-pai governa a visibilidade
dos filhos, ele precisa ir para uma **view cap**. Decisão: pai →
`ffc_view_audiences` (cobre `ffc_operator` + audience-viewers + admins);
submenus de audiência → `ffc_view_audiences`; submenu Appointments →
`ffc_view_appointments`. Caveat: um viewer *só de appointments* (sem
`ffc_view_audiences`) não veria o menu-pai — caso de borda; `ffc_operator`
(o consumidor real read-only) tem ambas. Documentar.

## Padrão de implementação por superfície

1. Cap do menu/submenu → a **view cap** do domínio.
2. Gate de render (`wp_die`/`return`) → `canView($view_cap)`.
3. `$can_edit = Utils::current_user_can_admin_or($manage_cap)` propagado ao
   render para **esconder** botões de criar/salvar/excluir e ações de linha.
4. Escritas (AJAX/POST/dispatch) permanecem em `manage` (recruitment: **adicionar**).

## Status / faseamento

- ✅ **Reregistration** — entregue neste PR. `VIEW_CAPABILITY =
  ffc_view_reregistration`; `render_page` por `canView`; editor (`new`/`edit`)
  e `handle_actions` continuam `manage`; "Add New", Edit/Delete por linha e
  Approve/Reject/Return-to-draft + bulk Apply escondidos para viewers; o título
  da linha aponta para *Submissions* (read) quando não pode editar. Habilita um
  **auditor de rerregistração**.
- ✅ **Audience** — menu "Scheduling" (pai + 7 submenus) → `ffc_view_audiences`.
  `render_page` não tem gate próprio (depende do cap do menu) e as escritas
  vivem em `handle_actions` (`manage_audiences`) + AJAX (`check_ajax_permission`),
  ambas independentes → abrir a leitura é seguro. *(Botões de escrita ainda
  visíveis ao viewer mas barrados server-side — polimento de UI pendente.)*
- ✅ **Appointments** — submenu + `render_appointments_page` →
  `ffc_view_appointments`; Confirm/Cancel da lista escondidos; a mutação
  (confirm/cancel) já era `ffc_manage_appointments`-gated na view.
- ✅ **Recruitment** — `RecruitmentAdminActions::dispatch` **endurecido**
  (re-checa `ffc_manage_recruitment`); `render_page`/menu/abas (exceto settings)
  → `ffc_view_recruitment`; edit screens exigem manage. Demais escritas (edit
  pages, REST call/import/reasons) já tinham cap próprio. Realiza auditor/operator.
  *(Botões "Create/New" por aba ainda visíveis ao viewer — polimento pendente.)*

### Pendente (polimento de UI, não-segurança)
Esconder os botões "Add/Create/New" para viewers em **audiences** (5 páginas) e
**recruitment** (4 abas). As escritas já são barradas server-side; é só UX.

## Testes / gates

Cada superfície: gate allow(view)/deny(sem cap) + writes negadas a viewers;
PHPStan 8 · WPCS · PHPUnit · coverage floor. CHANGELOG `[Unreleased]` por fatia.
