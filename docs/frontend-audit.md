# Auditoria de Frontend — TO‑DO tracker

> Documento vivo. É a referência única desta auditoria para que **nada se perca** entre sessões
> (o ambiente remoto é efêmero). Fluxo de trabalho acordado:
>
> 1. Cada item abaixo é **planejado antes de atuar** (seção "Plano" com prós/contras de cada proposta).
> 2. Atuamos **em ordem, um item por vez**.
> 3. Tudo numa **PR única**; cada sprint/item entregue = **um commit** nessa PR.
> 4. Ao concluir um item, marcar o checkbox e registrar o commit/sprint aqui.
>
> Branch: `claude/frontend-code-audit-k51Wb` → base `develop`.
> Snapshot da auditoria: 2026-06-02.

## Legenda de status
- ⬜ Não iniciado · 🟨 Em planejamento · 🟦 Plano aprovado / em execução · ✅ Concluído · ⏸️ Adiado / fora de escopo

---

## Item 1 — CSS inline que deveria estar em arquivos dedicados  ⬜

**Dívida real (CSS inline em telas admin navegáveis)** — ~10 arquivos, ~150 ocorrências de `style="..."`:

| Arquivo | Ocorrências | Esforço |
|---|---|---|
| `includes/recruitment/class-ffc-recruitment-notice-edit-page-renderer.php` | 35 | Médio |
| `includes/settings/views/ffc-tab-migrations.php` | 31 | Médio-alto |
| `includes/audience/class-ffc-audience-admin-calendar.php` | 19 | Médio |
| `includes/recruitment/class-ffc-recruitment-admin-page.php` | 17 | Baixo |
| `includes/audience/class-ffc-audience-admin-audience.php` | 13 | Baixo |
| `includes/settings/views/ffc-tab-geolocation.php` | 11 | Baixo |
| `includes/recruitment/class-ffc-recruitment-candidate-edit-page.php` | 10 | Baixo |
| `includes/reregistration/class-ffc-reregistration-admin.php` | 8 | Baixo |
| `includes/audience/class-ffc-audience-shortcode.php` | 6 (frontend) | Baixo-médio |

**Legítimo (NÃO mover — e-mail/PDF/ficha não carregam CSS externo):**
- `includes/scheduling/class-ffc-email-template-service.php` (bloco `<style>`)
- `includes/self-scheduling/class-ffc-self-scheduling-appointment-receipt-handler.php` (bloco `<style>`)
- `includes/reregistration/class-ffc-ficha-generator.php` (10 — HTML/PDF de impressão)
- `includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php` (48 — e-mail)
- `templates/emails/*`

**Verificar antes:** `includes/settings/views/documentation/15-examples.php` (14) — se os `style=` estão
dentro de `<code>` como exemplos para o usuário copiar, **não é dívida**.

### Plano
_A preencher antes de atuar (prós/contras das abordagens: arquivo único `ffc-admin-shared.css` vs. por feature;
impacto em `npm run build:js`/minificação e enqueue condicional)._

---

## Item 2 — Arquivos JS grandes que deveriam ser quebrados  🟦 (csv-download ✅ s2; audience ✅ s3; geofence pendente)

Piores ofensores (responsabilidades separáveis):

- 🔴 **`assets/js/ffc-csv-download.js` (1127)** — 7 fluxos independentes (info-screen, download em lote,
  preview de certificado, "abrir antes", "adiar fechamento", "exceção de agenda", overlays/progress).
- 🔴 **`assets/js/ffc-audience.js` (1439)** — 5-6 domínios (calendário, modal/focus-trap, AJAX de booking,
  validação, utils).
- 🟠 **`assets/js/ffc-geofence-frontend.js` (1307)** — validação de data/hora acoplada à lógica de GPS
  (domínios distintos); separar datetime ↔ GPS ↔ preflight de permissão.
- 🟡 **`assets/js/ffc-pdf-generator.js` (872)** — coeso; opcional extrair overlay/errors.

**Modelo a replicar (já no projeto):** `assets/js/ffc-user-dashboard-*.js` e `assets/js/ffc-frontend-helpers.js`
(submódulos internos com namespace `window.FFC.*`).

### Plano (APROVADO — Arquitetura 1; escopo: csv-download + audience + geofence)

**Restrição arquitetural que molda tudo:** os dois ofensores são **IIFE única** onde as funções
compartilham estado de closure e helpers privados:
- `ffc-csv-download.js`: `var $container, $form, $btn, $overlay; var cfg/strings;` + funções soltas que
  chamam `showOverlay/hideOverlay/updateProgress/updateStatus/showFlash/lastInfo/disableBtn/enableBtn`.
  O `renderInfoScreen()` faz `.on('click', ...)` direto nos botões de cada fluxo (open-early, extend-end,
  schedule-exception, cert-preview, download) — ou seja, os fluxos hoje estão amarrados ao render.
- `ffc-audience.js`: normaliza `window.ffcAudience` (config+strings+locale) e tem um objeto de estado
  (`config/bookings/holidays/selectedUsers`) + funções soltas que leem `ffcAudience.*`.

#### Arquiteturas avaliadas
| # | Abordagem | Prós | Contras | Veredito |
|---|---|---|---|---|
| 1 | **Núcleo + arquivos-irmãos com namespace** (padrão dashboard core+panels, já no repo e coberto por CI) | Entrega arquivos menores de verdade; carregamento condicional/por-handle; cada arquivo testável isolado; segue a casa | Exige expor superfície pública dos helpers/estado; mais "plumbing" de enqueue (1 handle por arquivo); diff maior; risco na externalização do estado | **RECOMENDADA** |
| 2 | Split físico + concat no build para 1 bundle | Sem mexer no enqueue; preserva closure | Introduz passo de concat inexistente (build:js é 1:1 source→min); quebra o invariante "1 source = 1 min" e o gate "Verify minified assets" | Rejeitada |
| 3 | Modularização **interna** (objetos no mesmo arquivo, `ctx` compartilhado) | Risco mínimo; sem mexer em enqueue/build; melhora legibilidade/testabilidade | Arquivo continua grande (não "quebra em menores", só fragmenta responsabilidades) | Alternativa de baixo risco |

#### Decomposição proposta (Arquitetura 1)
**`ffc-csv-download.js` (1127) → núcleo + 6 irmãos:**
- `ffc-csv-core.js` — `ctx` (cfg/strings/$container/$form/$btn/$overlay), `init()`, dispatch, helpers de UI
  (overlay/flash/progress/status), `lastInfo()`, form-state. Expõe `window.FFCCsv = { ctx, ui, register }`.
- `ffc-csv-info-screen.js` — `onSubmitInfo`, `renderInfoScreen`, builders (restrictions/datetime/geo/quiz/csv/status).
- `ffc-csv-cert-preview.js` — preview modal + sample data + placeholders.
- `ffc-csv-download-flow.js` — `onDownloadClick`, `processBatch`, `onExportComplete`.
- `ffc-csv-open-early.js` · `ffc-csv-extend-end.js` · `ffc-csv-schedule-exception.js` — um modal/fluxo cada.
- **Mudança habilitadora (preserva comportamento):** trocar os `.on('click')` pós-render por **eventos
  delegados** em `$container` no init de cada irmão → desacopla binding da ordem de render.

**`ffc-audience.js` (1439) → núcleo + 4 irmãos:**
- `ffc-audience-core.js` — normalização de config/strings/locale, objeto de estado, `init`, `t()`, namespace.
- `ffc-audience-calendar.js` — render/navegação de mês, badges, feriados.
- `ffc-audience-bookings.js` — fetch + render de bookings do dia.
- `ffc-audience-booking-form.js` — modal, busca/seleção de usuários, check de conflitos, criar/cancelar (AJAX).
- `ffc-audience-utils.js` — datas/horas, escape, label de environment (pode dobrar no core).

#### Enqueue / dependências (a fazer junto)
- `class-ffc-frontend.php:319+` (csv) e o loader de audience: registrar os novos handles com `deps` apontando
  para o núcleo; manter o gate condicional (`$has_csv_download`). `wp_localize_script` continua **só no núcleo**
  (config é global; irmãos leem via namespace).
- **Auditar dependentes do handle `ffc-audience`** antes de renomear: `self-scheduling-shortcode.php:94` usa
  `array('ffc-audience')` — confirmar se é dep de **CSS** (handle homônimo) e não do JS; manter o handle JS
  `ffc-audience` como o núcleo para não quebrar dependentes.

#### Testes / gates
- Suítes existentes a adaptar (carregar núcleo + irmão relevante via `loadScript`): `csv-download-deep`,
  `csv-download-open-early`, `csv-and-rereg-frontend`, `audience-smoke`, `dashboard-audience*`.
  Comportamento idêntico → os testes são a rede de segurança. Coverage floor **não pode cair**.
- `npm run build:js` passa a gerar N `.min.js` + `.map` novos (commitar todos). ESLint zero-erro.

#### Sequenciamento (sprints = commits nesta PR)
- **Sprint 2:** split `ffc-csv-download.js` (estrutura mais limpa, funções privadas bem seccionadas → começar por aqui).
- **Sprint 3:** split `ffc-audience.js`.
- **Sprint 4 (opcional):** `ffc-geofence-frontend.js` — separar datetime ↔ GPS ↔ preflight.

**Decisões pendentes para você:** (a) Arquitetura 1 (recomendada) vs 3 (baixo risco); (b) escopo agora:
só csv-download e reavaliar, ou os dois gigantes; (c) incluir geofence no escopo do Item 2.

---

## Item 3 — Arquivos PHP com múltiplas responsabilidades a fragmentar  ⬜

| Arquivo | Linhas | Veredito |
|---|---|---|
| `includes/security/class-ffc-rate-limit-checker.php` | 1226 | **Fragmentar** → Checker (API) + Repository (queries) + Config |
| `includes/recruitment/class-ffc-recruitment-candidate-edit-page.php` | 1104 | **Fragmentar** → extrair JS inline p/ `assets/js/`, usar padrão Renderer |
| `includes/audience/class-ffc-audience-loader.php` | 1131 | **Revisar** → separar REST/validação do loader |
| `includes/recruitment/class-ffc-recruitment-admin-page.php` | 1128 | **Revisar** → controller com renderização + state transitions |

**OK (grandes mas coesos — referência de bom design):** `...notice-edit-page-renderer.php` (1601, view-only,
métodos estáticos, já extraído de god-object), `...csv-importer.php` (1551, import atômico), repositories.

### Plano
_A preencher. PHPStan nível 8 + WPCS + coverage floor; risco de regressão maior que no JS._

---

## Item 4 — Arquivos fragmentados que deveriam ser consolidados  ⬜

A maioria da fragmentação JS é **justificada por enqueue condicional**. Candidatos reais:

- **CSS:** `assets/css/ffc-calendar-admin.css` (46 linhas, só ajustes de list-table) → fundir em calendar core/frontend.
- **JS:** `assets/js/ffc-calendar-admin.js` (28 linhas) → fundir em `assets/js/ffc-calendar-core.js`.
- **Opcional/baixa prioridade:** `ffc-recruitment-admin.css` + `ffc-recruitment-public.css` (contextos disjuntos).

**Manter separados:** dashboard (8 arquivos), audience (frontend/admin), geofence (frontend/admin/validation) —
a divisão acompanha o carregamento por página.

### Plano
_A preencher. Verificar enqueue/handles antes de fundir; cache-bust/versão na release PR._

---

## Item 5 — Falhas de segurança no frontend  🟦 (XSS + tabnabbing ✅ no sprint 1; CSRF descartado)

**🔴 Alta — XSS por output sem escape (confirmado no código):**
- `assets/js/ffc-user-dashboard-appointments.js:152,159` — `apt.calendar_title` e `apt.receipt_url` (href) crus.
- `assets/js/ffc-user-dashboard-certificates.js:87,92,96` — `cert.form_title`, `cert.email`, `cert.magic_link` (href) sem escape.
- **Inconsistência sistêmica:** `helpers.esc()` existe (`ffc-user-dashboard-core.js:23`) e é usado em `core.js:167`
  e em `ffc-user-dashboard-audience.js`, mas esses dois irmãos não. Correção trivial; gap real de defesa.

**🟠 Média — Reverse tabnabbing** (`target="_blank"` sem `rel="noopener noreferrer"`):
- `assets/js/ffc-calendar-frontend.js:427`
- `assets/js/ffc-user-dashboard-appointments.js:159`
- `assets/js/ffc-user-dashboard-certificates.js:96`

**🟢 CSRF — FALSO POSITIVO, confirmado em sessão (sem ação):**
`templates/verification-page.php:35` e o form de submissão em `includes/frontend/class-ffc-shortcodes.php`
não usam `wp_nonce_field()`, mas:
- ambos chamam `generate_security_fields()` (honeypot + captcha com hash) — `class-ffc-shortcodes.php:51`;
- o envio é via **AJAX** com nonce validado por `wp_verify_nonce()` no handler
  (`class-ffc-verification-handler.php:770`); o nonce viaja pelo script localizado, não pelo `<form>`.
- **Pendência fechada:** não existe handler `template_redirect`/`admin_post` para POST puro — o único leitor
  de `ffc_auth_code` (`class-ffc-verification-handler.php:806`) está depois da verificação de nonce. O form
  exige JS; não há endpoint POST sem-JS desprotegido. Nenhuma ação.

**Falsos positivos (já seguros):** `innerHTML` estático em `ffc-pdf-generator.js:266` e `recruitment-import-batched.js`
(usa `textContent`); `localStorage` só guarda UUID/IDs (sem PII); validação de CPF com dígito verificador; AJAX com
nonce; URLs com `encodeURIComponent`.

### Plano — APROVADO (Abordagem B) e ENTREGUE no sprint 1
- Novo helper `helpers.escAttr()` em `ffc-user-dashboard-core.js` (esc() + encode de `"`) para hrefs.
- `helpers.esc()` nas células: `apt.calendar_title`; `cert.form_title`, `cert.email`, `cert.auth_code`.
- `helpers.escAttr()` + `rel="noopener noreferrer"` nos hrefs de `receipt_url` e `magic_link`.
- `rel="noopener noreferrer"` em `ffc-calendar-frontend.js:427` (href já era quote-safe via `esc()` local).
- Testes Vitest novos (payload `<img onerror>`, quote-breakout, `rel`) em
  `dashboard-appointments.test.js` e `dashboard-certificates.test.js` — suíte: 1037 passes.
- `npm run build:js` regenerou os 4 `.min.js` correspondentes.

---

## Item 6 — Dívida técnica espalhada / não tratada  ⬜

8 marcadores reais (`for now`), **todos intencionais e com roadmap** — nenhum é bug:
- **Acionável (baixa prioridade):** `includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php:446`
  — `get_cancellation_url()` retorna placeholder do dashboard em vez de página de cancelamento dedicada.
- Os outros 7 (`includes/recruitment/*`, `includes/core/class-ffc-date-formatter.php:275`) são features adiadas /
  escopo deliberado, documentados no próprio comentário.

Não há `TODO/FIXME/HACK/XXX` pendentes. "Temporary"/"XXX" achados são nomes de métodos legítimos
(`block_temporarily`) ou placeholders em exemplos.

### Plano
_A preencher (ou marcar ⏸️ se decidirmos não atuar agora)._

---

## Ordem de execução sugerida

1. **Item 5 — Segurança** (rápido, alto valor, baixo risco): escapar outputs do dashboard + `rel=noopener`.
2. **Item 5 — CSRF**: confirmar caminho do POST e adicionar nonce se for form tradicional.
3. **Item 2 — Split** `ffc-csv-download.js` e `ffc-audience.js` (maior ROI de manutenibilidade).
4. **Item 1 — Extração de CSS inline** dos 2 maiores (recruitment-notice-edit, tab-migrations).
5. **Item 3 — Fragmentação PHP** (`rate-limit-checker`) e **Item 4 — consolidações** pequenas (oportunístico).
6. **Item 6 — Dívida técnica** (avaliar atuar no único item acionável ou adiar).

## Log de sprints (commits desta PR)
| # | Item | Descrição | Commit |
|---|---|---|---|
| 0 | — | tracker da auditoria criado | inicial |
| 1 | 5 | XSS escape (dashboard) + `rel=noopener` + escAttr helper + testes | sprint 1 |
| 2 | 2 | split `ffc-csv-download.js` 1127→núcleo `FFCCsv` + 6 irmãos (info-screen, cert-preview, download-flow, open-early, extend-end, schedule-exception); enqueue + eslint global + testes adaptados | sprint 2 |
| 3 | 2 | split `ffc-audience.js` 1439→núcleo `FFCAudience` (state+utils) + 3 irmãos (calendar, bookings, booking-form); enqueue (shortcode) + testes adaptados | sprint 3 |
