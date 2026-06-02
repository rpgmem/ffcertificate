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

## Item 1 — CSS inline que deveria estar em arquivos dedicados  🟦 (recruitment ✅ s8; settings/audience/rereg pendentes)

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

### Plano — APROVADO (Abordagem A; entrega incremental, 1 feature por commit)

**Abordagem A — reuso por feature (zero mudança de enqueue).** Cada tela admin já dá enqueue do
CSS da própria feature (recruitment→`ffc-recruitment-admin.css` via assets-manager por screen;
settings→`ffc-admin-settings.css`; audience→`ffc-audience-admin.css`; reregistration→
`ffc-reregistration-admin.css`) e o `ffc-admin-utilities.css` (utilitários `.ffc-mt-10`, `.ffc-mb5`,
`.ffc-w100`, text utils…) carrega nas telas admin. Logo: mover `style="..."` estático para classes
semânticas no CSS da feature (ou utilitário existente/novo) **não exige tocar nenhum enqueue**.

**Regras:**
- Estilos genuinamente **dinâmicos** (largura de barra computada, `display:` de estado inicial,
  `style` vindo de variável) **permanecem inline** — não são dívida movível.
- Espaçamentos genéricos repetidos → utilitários (reusar/estender `ffc-admin-utilities.css`).
- Padrões de componente → classes semânticas no CSS da feature.
- `npm run build` (build:css) regenera `*.min.css`; gate "Verify minified assets" cobre.

**Ordem incremental (1 commit por feature):**
1. ✅ **recruitment** (sprint 8) — `notice-edit-page-renderer` + `admin-page` + `candidate-edit` → 29 classes `.ffc-rec-*` em `ffc-recruitment-admin.css`. Dinâmicos (`$prev_display`, `$cfg['style']`, `$style`) e `data-ffc-confirm-style="..."` (falso-positivo do grep, **não é CSS**) ficaram inline.
   - **Dívida extra descoberta** (não inventariada antes, varrer num passe futuro do recruitment): `class-ffc-recruitment-adjutancy-edit-page.php` (~2), `class-ffc-recruitment-reason-edit-page.php` (~3), `class-ffc-recruitment-reasons-list-table.php` (~1 badge).
2. **settings** — `ffc-tab-migrations` (31) + `ffc-tab-geolocation` (11) → `ffc-admin-settings.css`.
3. **audience** — `admin-calendar` (19) + `admin-audience` (13) → `ffc-audience-admin.css`; `audience-shortcode` (6 frontend) → `ffc-audience.css`.
4. **reregistration** — `reregistration-admin` (8) → `ffc-reregistration-admin.css`.

Cada etapa para revisão antes da próxima.

**Antes verificado:** `documentation/15-examples.php` (14) — `style=` dentro de exemplos `<code>` p/ o usuário copiar → **não é dívida**, fica.

---

## Item 2 — Arquivos JS grandes que deveriam ser quebrados  ✅ (csv-download s2; audience s3; geofence s5)

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

## Item 7 — Bug: caixa de justificativa some ao "chamar" fora de ordem com a listagem filtrada  ⬜ (último a tratar)

**Sintoma.** No admin de convocação de um edital, ao **filtrar** os candidatos e então clicar
em **"chamar"** um usuário que está **fora de ordem**, a **caixa de justificativa não aparece** —
o fluxo pula direto para a etapa de **data da convocação**. Fazendo a mesma ação **sem filtrar**
(listagem com todos os candidatos), o comportamento é correto: a caixa de justificativa é exibida.

**Impacto.** Permite convocar fora de ordem sem registrar justificativa quando a lista está filtrada —
fura o controle de auditoria/ordem da fila exatamente no caminho em que o operador mais usa filtros.

**Hipótese de causa (a confirmar).** A detecção de "fora de ordem" provavelmente calcula a posição do
candidato contra o **subconjunto visível/filtrado** em vez da **lista completa ordenada** da fila. Com a
lista filtrada, o candidato chamado aparenta estar "na ordem" (ou a posição esperada não é computável),
então o gate que dispara o modal de justificativa não ativa e o fluxo segue para a data.

**Onde investigar.** JS/admin do recruitment/convocação (handler do botão "chamar" + cálculo de
posição/next-in-queue) e o endpoint AJAX correspondente. Conferir se a ordem é derivada do DOM filtrado
ou de uma fonte canônica (ranking completo do edital) no servidor.

**Status.** Não corrigido. Tratar por **último** no roadmap, após os itens de refactor/segurança acima.

### Plano
_A preencher — reproduzir, localizar o gate de justificativa, e fazer a checagem de ordem usar a fila
completa (não a lista filtrada). Cobrir com teste (filtrado vs. não-filtrado)._

---

## Ordem de execução sugerida

1. **Item 5 — Segurança** (rápido, alto valor, baixo risco): escapar outputs do dashboard + `rel=noopener`.
2. **Item 5 — CSRF**: confirmar caminho do POST e adicionar nonce se for form tradicional.
3. **Item 2 — Split** `ffc-csv-download.js` e `ffc-audience.js` (maior ROI de manutenibilidade).
4. **Item 1 — Extração de CSS inline** dos 2 maiores (recruitment-notice-edit, tab-migrations).
5. **Item 3 — Fragmentação PHP** (`rate-limit-checker`) e **Item 4 — consolidações** pequenas (oportunístico).
6. **Item 6 — Dívida técnica** (avaliar atuar no único item acionável ou adiar).
7. **Item 7 — Bug da justificativa fora de ordem com lista filtrada** (último; bug funcional, fora do escopo de refactor).

## Log de sprints (commits desta PR)
| # | Item | Descrição | Commit |
|---|---|---|---|
| 0 | — | tracker da auditoria criado | inicial |
| 1 | 5 | XSS escape (dashboard) + `rel=noopener` + escAttr helper + testes | sprint 1 |
| 2 | 2 | split `ffc-csv-download.js` 1127→núcleo `FFCCsv` + 6 irmãos (info-screen, cert-preview, download-flow, open-early, extend-end, schedule-exception); enqueue + eslint global + testes adaptados | sprint 2 |
| 3 | 2 | split `ffc-audience.js` 1439→núcleo `FFCAudience` (state+utils) + 3 irmãos (calendar, bookings, booking-form); enqueue (shortcode) + testes adaptados | sprint 3 |
| 4 | 5 | fix CodeQL (#479): escapar cores/labels derivados do `data-config` (DOM) nos sinks `.html()/.append()` de `ffc-audience-calendar/bookings.js` | sprint 4 |
| 5 | 2 | split `ffc-geofence-frontend.js` 1307→núcleo `FFCGeofence` + 3 irmãos via `Object.assign` (datetime, gps, preflight); enqueue + 11 testes adaptados | sprint 5 |
| 6 | 5 | fix CodeQL (#479): cache de geofence guarda passe `{validated,expires}` em vez de lat/lng cru no localStorage (sem dado sensível em repouso); cache só após validação in-area; testes adaptados | sprint 6 |
| 7 | 7 | roadmap: registrar bug da caixa de justificativa que some ao "chamar" fora de ordem com a listagem de convocados filtrada (doc-only; tratar por último) | sprint 7 |
| 8 | 1 | extração de CSS inline da feature **recruitment** (notice-edit + admin-page + candidate-edit) → 29 classes `.ffc-rec-*` em `ffc-recruitment-admin.css`; dinâmicos/`data-*` preservados inline; Stylelint + `php -l` + build ok | sprint 8 |
