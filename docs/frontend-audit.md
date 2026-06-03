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

## Item 1 — CSS inline que deveria estar em arquivos dedicados  ✅ (recruitment s8/s10; settings s9; audience s11; reregistration s12)

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
   - ✅ **Dívida extra varrida** (sprint 10): `adjutancy-edit-page` (2) + `reason-edit-page` (3) reusam `.ffc-rec-mt-20/.ffc-rec-ml-half/.ffc-rec-flex-wrap`; `reasons-list-table` badge → nova `.ffc-rec-pill`. Doc-comments (notice:740, settings:300) e `data-ffc-confirm-style` não são CSS.
2. **settings** — `ffc-tab-migrations` (31) + `ffc-tab-geolocation` (11) → `ffc-admin-settings.css`. ✅ (sprint 9) — 15 classes `.ffc-set-*`; dinâmicos (barra `number_format`, `$table_style`) inline; merges entre-linhas nos inputs de localização tratados.
3. **audience** ✅ (sprint 11) — `admin-calendar` (19) + `admin-audience` (1 estático) → 15 classes `.ffc-aud-*` em `ffc-audience-admin.css`. **Decisão:** `display:none` puros (admin-audience 9× + `audience-shortcode` 6× frontend) e os `background-color` data-driven ficam **inline** — são estado de visibilidade togglado por `.show()/.hide()` no JS e cor por-registro, não styling estático. Mover o `display:none` puro arriscaria a interação JS sem teste; o dropdown de busca (com 6 props estáticas além de `display:none`) foi extraído pois é exibido via `.show()` que sobrepõe a classe.
4. **reregistration** ✅ (sprint 12) — `reregistration-admin` (7 estáticos) → 5 classes `.ffc-rereg-*` em `ffc-reregistration-admin.css`. Modal `#ffc-submission-details-modal` mantém `display:none` inline (estado togglado por `.show()/.hide()`); `background:` por-audiência fica inline (dinâmico).

**Item 1 concluído.** Todas as 4 features extraídas; padrão consistente (classes feature-local no CSS já enfileirado da feature, zero mudança de enqueue, dinâmicos + `display:none` puros de estado mantidos inline).

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

## Item 3 — Arquivos PHP com múltiplas responsabilidades a fragmentar  ✅ (candidate-edit s13; rate-limit s14; recruitment-admin-page s15; audience-loader s16)

| Arquivo | Linhas | Veredito |
|---|---|---|
| `includes/security/class-ffc-rate-limit-checker.php` | 1226 | **Fragmentar** → Checker (API) + Repository (queries) + Config |
| `includes/recruitment/class-ffc-recruitment-candidate-edit-page.php` | 1104 | **Fragmentar** → extrair JS inline p/ `assets/js/`, usar padrão Renderer |
| `includes/audience/class-ffc-audience-loader.php` | 1131 | **Revisar** → separar REST/validação do loader |
| `includes/recruitment/class-ffc-recruitment-admin-page.php` | 1128 | **Revisar** → controller com renderização + state transitions |

**OK (grandes mas coesos — referência de bom design):** `...notice-edit-page-renderer.php` (1601, view-only,
métodos estáticos, já extraído de god-object), `...csv-importer.php` (1551, import atômico), repositories.

### Plano (proposto — aguardando escolha de escopo)

Gates: PHPStan 8 + WPCS + PHPUnit (8.3/8.4) + coverage floor. **Risco de regressão > JS/CSS.**
Regra geral: preservar contrato público + comportamento; testes existentes = rede de segurança.

**A) `rate-limit-checker` (1226) → facade + Repository (+ Config).** ✅ (sprint 14) — `RateLimitChecker`
1226→1026 linhas; 7 helpers privados de persistência (`get_count_from_db`, `increment_counter`,
`get_submission_count`, `is_temporarily_blocked`, `block_temporarily`, `get_window_start/end`) extraídos
para `RateLimitRepository` nova (autoload por convenção `class-ffc-rate-limit-repository.php`); 13 call-sites
repontados `self::` → `RateLimitRepository::`. API pública 100% intacta (zero mudança de call-site externo/teste).
**Verificado localmente** (composer install no ambiente): PHPUnit completo **4885 testes ✓**, PHPStan 8 ✓, WPCS ✓.

**A) [plano original]** Classe `final` 100% estática,
chamada como `RateLimitChecker::...` em 3 arquivos + **7 suítes de teste**. Abordagem **facade
(baixo risco)**: manter TODOS os métodos públicos em `RateLimitChecker` (zero mudança de call-site/teste);
extrair os helpers **privados de persistência** (`get_count_from_db`, `increment_counter`,
`get_submission_count`, `is_temporarily_blocked`, `block_temporarily`, `get_window_start/end`) para
`RateLimitRepository` nova; o facade delega. Config (`get_settings` é público → fica no facade; só
helpers privados de leitura migram). Cobertura preservada (exercitada via API pública).

**B) `candidate-edit-page` (1104) → extrair JS inline.** ✅ (sprint 13) — 2 blocos `<script>` →
`assets/js/ffc-recruitment-candidate-edit.js` (2 handlers delegados: PII reveal/hide + adjutancy swap),
enqueue+localize (`ffcRecruitmentCandidateEdit`) no assets-manager; página 1104→989 linhas. **Teste novo**
(`recruitment-candidate-edit.test.js`, 9 casos cobrindo ambos handlers) — coverage de linhas 83.15% > floor 82.

**C) `audience-loader` (1131) / `recruitment-admin-page` (1128) — "Revisar".** **Avaliados (s14+):**

- `audience-loader` — singleton instance-based (`use AjaxTrait`). Mistura bootstrap/hooks/enqueue (loader) com
  **~13 handlers `ajax_*` (~700 das 1131 linhas)** = violação real de SRP. Corte natural: extrair os handlers para
  `AudienceAjaxController` (que reusa `AjaxTrait`); loader mantém boot+enqueue. **Risco: ALTO** — `AudienceLoaderTest`
  (14 testes) cobre init/hooks/enqueue/REST-route + os 2 helpers privados, mas **0 dos `ajax_*`**. Mover ~700 linhas
  de AJAX sem rede de teste de comportamento (só PHPStan/WPCS) é arriscado para admin.
- `recruitment-admin-page` — all-static. Mistura controller (`render_page`/`dispatch_action`) + **~600 linhas de
  `render_*_tab/form` (view)** + helpers de badge. `dispatch_action` (~90) faz state-transitions. `RecruitmentAdminPageTest`
  (6 testes) cobre **só os badges**; render/dispatch **não testados**. Predominantemente view coesa — **próximo do
  "OK" `notice-edit-page-renderer`** (1601, classificado bom design), exceto pelo `dispatch_action` embutido.

**Decisão (mantenedor): test-first para ambos.** Execução:

- ✅ **`recruitment-admin-page` (sprint 15)** — descoberta na execução: os `render_*_tab` dependem de `WP_List_Table`
  (runtime wp-admin) → **estruturalmente difíceis de unit-testar** (como os JS excluídos de coverage por FullCalendar);
  ficam como view coesa (justificado, perto do "OK" `notice-edit-page-renderer`). O que **é** testável e tem
  responsabilidade distinta — `dispatch_action` (state-transitions, sem list-table) — foi extraído para
  `RecruitmentAdminActions::dispatch()` **test-first** (`RecruitmentAdminActionsTest`, 7 casos cobrindo os 4 branches
  delete-* + gates de id-zero / referenced-reason / unknown-action). Página 1128→1029. PHPUnit/PHPStan 8/WPCS ✓.
- ✅ **`audience-loader` (sprint 16)** — extraídos os ~13 handlers `ajax_*` + 2 helpers privados para
  `AudienceAjaxController` (usa `AjaxTrait`; `register()` registra os 13 `wp_ajax_*`); loader 1131→408, perde o
  `use AjaxTrait` (não mais usado) e delega via `$this->ajax_controller->register()`. Movimento verbatim
  (PHPStan 8 confirma resolução de todos os símbolos). **Test-first:** os 3 testes de helper (antes em
  `AudienceLoaderTest` por reflexão) movidos para `AudienceAjaxControllerTest` + registro dos 13 hooks +
  caracterização de 2 handlers (create-booking, check-conflicts). `AudienceLoaderTest` segue 11/11 (teste de
  hooks passa pois o controller registra os mesmos nomes). PHPStan 8 + WPCS + PHPUnit ✓.

**Item 3 concluído** (4/4 arquivos): candidate-edit (s13), rate-limit (s14), recruitment-admin-page (s15), audience-loader (s16).

Entrega incremental: 1 arquivo/sprint, cada um com sua bateria de gates verde antes do próximo.

---

## Item 4 — Arquivos fragmentados que deveriam ser consolidados  ✅ (avaliado + cleanup — sprint 17)

A maioria da fragmentação JS é **justificada por enqueue condicional**. Avaliação dos candidatos:

- **`assets/js/ffc-calendar-admin.js` (28 linhas) → ❌ não fundir; REMOVIDO.** Investigado: era um **stub vazio**
  (`bindEvents()` no-op desde 4.1.0). O objeto localizado `ffcSelfSchedulingAdmin` (ajaxurl/nonce/strings) **não
  era lido por nenhum JS** e o nonce `ffc_self_scheduling_admin_nonce` **nunca era verificado** server-side; as deps
  `jquery-ui-sortable/datepicker` + `jquery-ui-theme` não tinham widget (nenhuma view usa). Fundir um stub vazio no
  `calendar-core.js` (que carrega também no **frontend**) só espalharia código morto e mexeria num arquivo testado.
  Ação correta = **remover** o JS + enqueue + localize + nonce + jquery-ui-theme + o teste smoke + a entrada de
  coverage-exclude. (sprint 17)
- **`assets/css/ffc-calendar-admin.css` (46 linhas) → ✅ MANTER.** São os badges de status (`.ffc-status-*`) usados
  na tela admin de appointments; é o único CSS admin daquela tela — **sem alvo de fusão válido** (fundir em CSS
  frontend carregaria estilos admin no frontend). Continua enfileirado (só ele).
- **`ffc-recruitment-admin.css` + `ffc-recruitment-public.css` → ✅ MANTER separados** (contextos disjuntos
  admin vs público — a própria separação é o correto).

**Manter separados:** dashboard (8 arquivos), audience (frontend/admin), geofence (frontend/admin/validation) —
a divisão acompanha o carregamento por página.

**Conclusão:** Item 4 não tinha fusão segura/valiosa; o único ganho real foi remover o stub morto `calendar-admin.js`.

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

## Item 6 — Dívida técnica espalhada / não tratada  ⏸️ (avaliado s18; única ação acionável adiada como feature (B))

8 marcadores reais (`for now`), **todos intencionais e com roadmap** — nenhum é bug:
- **Acionável (baixa prioridade):** `includes/self-scheduling/class-ffc-self-scheduling-appointment-email-handler.php:446`
  — `get_cancellation_url()` retorna placeholder do dashboard em vez de página de cancelamento dedicada.
- Os outros 7 (`includes/recruitment/*`, `includes/core/class-ffc-date-formatter.php:275`) são features adiadas /
  escopo deliberado, documentados no próprio comentário.

Não há `TODO/FIXME/HACK/XXX` pendentes. "Temporary"/"XXX" achados são nomes de métodos legítimos
(`block_temporarily`) ou placeholders em exemplos.

### Plano / Avaliação (sprint 18)

**Investigado o único acionável (`get_cancellation_url`).** Fluxo real de cancelamento existe e funciona:
AJAX `ffc_cancel_appointment` (nonce `ffc_self_scheduling_nonce`) → `cancel_appointment($id, $token, $reason)`
que autoriza por **admin**, **dono logado** (`ffc_cancel_own_appointments`), OU **token** (`hash_equals` contra
`confirmation_token` — permite cancelar **sem login**). Há `wp_ajax_nopriv_ffc_cancel_appointment`.

**O problema:** `get_cancellation_url()` gera URL do dashboard `?tab=appointments&action=cancel&appointment_id=X`
**sem token nem nonce**, e o JS do dashboard (`ffc-user-dashboard-appointments.js`) **não lê** `action`/`appointment_id`
— cancela só via botão. Logo: o link do e-mail leva o usuário logado à aba (onde ele acha o agendamento e clica
Cancelar manualmente), mas os params `action=cancel&appointment_id` são **mortos**; convidado não-logado não tem
caminho fácil. O comentário "placeholder… implement later" está desatualizado.

**Opções (escopos diferentes):**
- **(A) Dashboard consome o deep-link** — o painel de appointments, ao carregar com `action=cancel&appointment_id`,
  abre o confirm de cancelamento daquele agendamento (reusa o fluxo botão/AJAX existente). Resolve os params mortos;
  só ajuda **usuário logado**. JS + teste; baixo risco (confirm-gated, nonce-gated, aditivo).
- **(B) Cancelamento por token sem login** — `get_cancellation_url` passa o `confirmation_token`; nova
  página/endpoint público processa o token (a "página dedicada" do comentário). É **feature nova** (fora do tema
  refactor da auditoria), maior superfície.
- **(C) Cleanup mínimo** — remover os params mortos (link → aba de appointments) + corrigir o comentário. Zero
  risco, sem ganho de UX.

**Recomendação:** (A) é o melhor custo/benefício dentro do escopo (transforma params mortos em fluxo útil para o
caso comum); (B) é feature à parte; (C) é o mínimo honesto.

**Decisão (mantenedor): (B)** — implementar a **página/endpoint de cancelamento por token** (link do e-mail carrega
o `confirmation_token`; processa sem login via `hash_equals`) como **feature separada, depois** (não nesta PR de
auditoria). Item 6 fica **⏸️** com a análise acima registrada; os outros 7 marcadores `for now` seguem
intencionais/documentados (sem ação). Ver "Item 9" abaixo para o acompanhamento da feature.

---

## Item 7 — Bug: caixa de justificativa some ao "chamar" fora de ordem com a listagem filtrada  ✅ (avaliado + corrigido s18)

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

**Status.** Avaliado (sprint 18). Causa-raiz confirmada; correção planejada.

### Avaliação (sprint 18) — causa-raiz confirmada

**Causa-raiz.** Em `RecruitmentNoticeEditPageRenderer::render_classification_actions_script()` o JS detecta
"fora de ordem" escaneando o **DOM renderizado** — `panel.querySelectorAll("tr[data-cls-id]")` em
`ffcRecruitmentLowestEmpty()` (single-call) e no threshold do bulk-call — para achar a linha `empty` de menor
rank por adjutancy. Mas o filtro da tela é **server-side**: `render_classifications_section()` faz
`RecruitmentClassificationFilterManager::apply_filters($definitive_rows, $filters)` **antes** de renderizar
(linhas 772-773), então o `<tbody>` só contém as linhas filtradas. Filtrando para mostrar só o candidato de rank
alto, as linhas `empty` de rank menor **somem do DOM** → o JS computa esse candidato como o "menor empty" → acha
que ele é o próximo da fila → **não marca OOO** → não abre o modal de justificativa → segue direto para a data.
Sem filtro, todas as linhas estão no DOM → detecção correta. Afeta **single-call e bulk-call** (ambos usam o scan).

**Severidade: bug de UX, NÃO bypass de auditoria.** O servidor é a autoridade e reforça corretamente:
`RecruitmentCallService` (linha ~329) computa `RecruitmentClassificationRepository::find_lowest_rank_empty(notice_id,
adjutancy_id, list_type)` direto do **banco** (per-adjutancy, não filtrado pelo UI) e, se a chamada é OOO **sem**
`out_of_order_reason`, **rejeita** com `recruitment_out_of_order_requires_reason`. Logo, uma chamada OOO filtrada
sem justificativa **falha no servidor** — a integridade/ordem/audit não é furada. O defeito é o operador não ser
avisado upfront (preenche a data e bate no erro do servidor / erro no single-call).

### Plano de correção (proposto)

**Tornar a detecção client-side autoritativa** (não depender do DOM filtrado):
1. **Servidor** — em `render_classifications_section()`, antes do `apply_filters`, computar do conjunto
   **não-filtrado** (ou via consulta dedicada ao repo) o mapa `empties_by_adjutancy = { adj_slug: [{id,rank}…
   ordenado], … }` das classificações `empty` da lista **definitive** do edital (a fonte autoritativa que o
   `find_lowest_rank_empty` já usa). Passar à view (data-attribute no painel definitive ou objeto localizado).
2. **JS** — `ffcRecruitmentLowestEmpty()` e o threshold do bulk passam a usar esse mapa do servidor em vez de
   `panel.querySelectorAll`. Lógica idêntica (single: menor rank do mapa; bulk: menor empty fora da seleção).
3. **Testes** — PHP para a nova consulta/repo + o cálculo do mapa; o reforço server-side já é coberto
   (`RecruitmentCallServiceTest`). Idealmente um teste de detecção (mas o JS é inline `<script>` — caracterizar via
   a montagem do mapa no renderer).

**Esforço:** médio. **Risco:** baixo-médio (aditivo no servidor + troca da fonte no JS; comportamento sem filtro
inalterado). **Recomendação:** corrigir — é a única falha funcional real achada na auditoria (mesmo sendo UX, fura
a expectativa do operador e gera erro confuso).

### Correção (sprint 18) — implementada

Novo helper `RecruitmentNoticeEditPageRenderer::compute_empties_by_adjutancy($rows)` constrói o mapa autoritativo
`{ adjutancySlug: [{id,rank}…] }` das classificações `empty` a partir do `$definitive_rows` **não-filtrado**
(computado antes do `apply_filters`). Emitido na view como `data-ffc-empties` no painel `data-ffc-clspanel="definitive"`.
No JS inline (`render_classification_actions_script`), `ffcRecruitmentLowestEmpty()` e o threshold do bulk passaram a
ler esse mapa via novo `ffcRecruitmentEmptiesMap()` (JSON.parse do atributo) em vez de `panel.querySelectorAll`. Isso
conserta **também** o caso de paginação (não só o filtro), já que a fonte deixa de ser o DOM (filtrado **e** paginado).
**Test-first:** `RecruitmentNoticeEditPageRendererTest` (3 casos: agrupa/ordena/exclui não-empty; mapa vazio sem
empties; fallback `#id` quando o slug não resolve). PHPUnit completo 4898 ✓ · PHPStan 8 ✓ · WPCS ✓.

---

## Item 8 — Feature: admin retroceder o status final de um candidato para "antes de chamado"  ⬜ (avaliar juntos antes de fazer)

**Pedido.** Permitir que o admin **retroceda o status final** de um candidato de convocação para um estado
**anterior ao "chamado"** (ex.: voltar de `hired`/`accepted`/`not_shown`/`called` → `empty`/aguardando), desfazendo
uma chamada/decisão.

**Contexto técnico (a investigar na avaliação).** Os status de classificação são geridos por
`RecruitmentClassificationStateMachine` (+ `RecruitmentClassificationRepository`); os estados conhecidos hoje são
`empty` (aguardando), `called`, `accepted`, `not_shown`, `hired` (ver `classification_status_label()` em
`RecruitmentAdminPage`). Há ações de transição (call/promote) e provavelmente regras de quais transições são
permitidas. "Retroceder" significa adicionar uma transição reversa (e decidir efeitos colaterais: fila/ordem de
convocação, audit log, e-mails já disparados, vaga liberada, etc.).

**Pontos a decidir juntos (antes de implementar):**
- Quais status finais podem retroceder e para qual estado exatamente (só `called→empty`, ou também
  `hired/accepted/not_shown→empty`?).
- Efeitos colaterais: a posição na fila volta? Reabre a vaga? Registra no activity log? Notifica o candidato?
- Permissão/gating (cap `ffc_manage_recruitment`) + confirmação destrutiva na UI (como o padrão `data-ffc-confirm-*`).
- Idempotência/concorrência com a máquina de estados existente; cobertura de teste da nova transição.

**Status.** Não iniciado. **Avaliar em conjunto antes de codar** (a pedido do mantenedor).

### Plano
_A preencher na sessão de avaliação — mapear `RecruitmentClassificationStateMachine`, definir transições reversas
permitidas + efeitos colaterais, e cobrir com testes da state machine._

---

## Item 9 — Feature: página/endpoint de cancelamento de agendamento por token  ⬜ (decidido no Item 6; implementar depois)

**Origem.** Resolução escolhida (B) para a dívida do Item 6 (`get_cancellation_url`). Hoje o link de cancelamento
nos e-mails de agendamento (`class-ffc-self-scheduling-appointment-email-handler.php` → `get_cancellation_url`)
aponta para o dashboard com params mortos (`action=cancel&appointment_id`) e exige login.

**Objetivo.** Permitir cancelar **sem login** via link do e-mail: `get_cancellation_url()` passa o
`confirmation_token` do agendamento; uma página/endpoint público valida-o (o backend já suporta —
`cancel_appointment($id, $token, $reason)` faz `hash_equals` contra `confirmation_token`, e há
`wp_ajax_nopriv_ffc_cancel_appointment`) e executa o cancelamento com confirmação.

**A definir na implementação:**
- Forma do destino: página dedicada (shortcode/template) vs. endpoint que renderiza um confirm + processa.
- UX de confirmação + motivo opcional (`reason`); mensagens de sucesso/erro (já cancelado, token inválido, cancelamento desabilitado no calendário).
- Segurança: token de uso único? rate-limit? expiração? (o `confirmation_token` já existe por agendamento).
- Atualizar `get_cancellation_url` (remover params mortos, adicionar token) + teste do fluxo nopriv.

**Status.** Não iniciado — feature à parte, fora desta PR de auditoria.

---

## Item 10 — Dívida: JS inline em PHP (recruitment) deveria ir para arquivos `.js` dedicados  ⬜ (registrado s18; extrair depois)

**Origem.** Notado ao corrigir o Item 7: a detecção OOO vive num `echo '<script>'` inline. Levantamento:
`includes/recruitment/class-ffc-recruitment-notice-edit-page-renderer.php` tem **~311 linhas de JS inline** em
**~7 blocos `<script>`** (tab-switch, import CSV, transições de status, ações de classificação + OOO, preview-status,
bulk-call) com **~69 interpolações** PHP (strings i18n via `esc_js`, REST URLs, `wp_create_nonce`). Há **mais JS
inline** em `class-ffc-recruitment-admin-page.php` e outros.

**Por que é dívida.** JS inline não passa por ESLint (gate zero-erro), não tem teste Vitest, não usa o pipeline de
minificação/cache-bust (`?ver=`), e é mais difícil de ler/manter. É a **mesma classe** já tratada no candidate-edit
(Item 3 / sprint 13) e no CSS (Item 1).

**Abordagem proposta (quando for feito).** Extrair para `assets/js/ffc-recruitment-classifications.js` (e talvez um
por tela), movendo as interpolações para um objeto via `wp_enqueue_script` + `wp_localize_script` (REST roots, nonce,
strings, **e o mapa `data-ffc-empties` / config**); remover os `echo '<script>'`. **Test-first** (Vitest, como no
candidate-edit) — o JS extraído entra na cobertura, então cobrir os handlers (single-call OOO, bulk-call, transições)
mantendo o floor. Incremental, 1 bloco/feature por commit (mini-projeto à parte, como o Item 2).

**Status.** Registrado, **adiado** (decisão do mantenedor) — não inflar a PR de auditoria; tratar depois.

---

## Ordem de execução sugerida

1. **Item 5 — Segurança** (rápido, alto valor, baixo risco): escapar outputs do dashboard + `rel=noopener`.
2. **Item 5 — CSRF**: confirmar caminho do POST e adicionar nonce se for form tradicional.
3. **Item 2 — Split** `ffc-csv-download.js` e `ffc-audience.js` (maior ROI de manutenibilidade).
4. **Item 1 — Extração de CSS inline** dos 2 maiores (recruitment-notice-edit, tab-migrations).
5. **Item 3 — Fragmentação PHP** (`rate-limit-checker`) e **Item 4 — consolidações** pequenas (oportunístico).
6. **Item 6 — Dívida técnica** (avaliar atuar no único item acionável ou adiar).
7. **Item 7 — Bug da justificativa fora de ordem com lista filtrada** (bug funcional, fora do escopo de refactor).
8. **Item 8 — Feature: retroceder status final de candidato** (avaliar em conjunto antes de implementar).
9. **Item 9 — Feature: cancelamento de agendamento por token** (decidido no Item 6; implementar depois).
10. **Item 10 — Extrair JS inline do recruitment para arquivos .js** (dívida registrada; extrair depois, test-first).

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
| 9 | 1 | extração de CSS inline da feature **settings** (`ffc-tab-migrations` 30 + `ffc-tab-geolocation` 10) → 15 classes `.ffc-set-*` em `ffc-admin-settings.css`; merges entre-linhas nos inputs de localização; dinâmicos inline; Stylelint + `php -l` + build ok | sprint 9 |
| 10 | 1 | varredura extra recruitment (descoberta na s8): `adjutancy-edit` + `reason-edit` reusam classes `.ffc-rec-*`; `reasons-list-table` badge → `.ffc-rec-pill`; Stylelint + `php -l` + build ok | sprint 10 |
| 11 | 1 | extração de CSS inline da feature **audience** (`admin-calendar` 19 + `admin-audience` 1) → 15 classes `.ffc-aud-*` em `ffc-audience-admin.css`; `display:none` puros + cores data-driven mantidos inline (estado JS/por-registro); Stylelint + `php -l` + build ok | sprint 11 |
| 12 | 1 | extração de CSS inline da feature **reregistration** (`reregistration-admin` 7) → 5 classes `.ffc-rereg-*` em `ffc-reregistration-admin.css`; modal `display:none` + `background` dinâmico inline. **Item 1 concluído.** | sprint 12 |
| 13 | 3 | fragmentar `candidate-edit-page` (1104→989): 2 blocos `<script>` inline → `ffc-recruitment-candidate-edit.js` (enqueue+localize); +9 testes; ESLint + `php -l` + build + floor 82 ok | sprint 13 |
| 14 | 3 | fragmentar `rate-limit-checker` (1226→1026): 7 helpers de persistência → `RateLimitRepository` (facade, API pública intacta, 13 call-sites repontados); PHPUnit 4885 ✓ + PHPStan 8 ✓ + WPCS ✓ | sprint 14 |
| 15 | 3 | fragmentar `recruitment-admin-page` (1128→1029): `dispatch_action` (state-transitions) → `RecruitmentAdminActions` **test-first** (+7 testes); render_* ficam (WP_List_Table-coupled, justificado); PHPStan 8 + WPCS + PHPUnit ✓ | sprint 15 |
| 16 | 3 | fragmentar `audience-loader` (1131→408): ~13 handlers `ajax_*` + 2 helpers → `AudienceAjaxController` (facade via `register()`) **test-first** (3 helper tests movidos + registro + 2 caracterizações); PHPStan 8 + WPCS + PHPUnit ✓. **Item 3 concluído.** | sprint 16 |
| 17 | 4 | avaliar consolidações: remover stub morto `ffc-calendar-admin.js` (+ enqueue/localize/nonce/jquery-ui-theme + teste + coverage-exclude); CSS de badges e split recruitment mantidos (escopo correto). Vitest 1045 ✓ + PHPUnit + PHPStan + WPCS ✓. **Item 4 concluído.** | sprint 17 |
| 18 | 7 | corrigir bug do gate de justificativa fora-de-ordem: detecção client-side passa a ler o mapa autoritativo `data-ffc-empties` (servidor, lista não-filtrada/não-paginada) via `compute_empties_by_adjutancy` em vez de varrer o DOM filtrado; conserta filtro + paginação; +3 testes; PHPUnit 4898 + PHPStan 8 + WPCS ✓ | sprint 18 |
