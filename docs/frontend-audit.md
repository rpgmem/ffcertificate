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

## Item 2 — Arquivos JS grandes que deveriam ser quebrados  ⬜

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

### Plano
_A preencher. Considerar: compatibilidade de carga (handles/enqueue), coverage Vitest (floor não pode cair),
risco de regressão por feature, ordem de fragmentação._

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

## Item 5 — Falhas de segurança no frontend  ⬜  ⟵ recomendado começar por aqui

**🔴 Alta — XSS por output sem escape (confirmado no código):**
- `assets/js/ffc-user-dashboard-appointments.js:152,159` — `apt.calendar_title` e `apt.receipt_url` (href) crus.
- `assets/js/ffc-user-dashboard-certificates.js:87,92,96` — `cert.form_title`, `cert.email`, `cert.magic_link` (href) sem escape.
- **Inconsistência sistêmica:** `helpers.esc()` existe (`ffc-user-dashboard-core.js:23`) e é usado em `core.js:167`
  e em `ffc-user-dashboard-audience.js`, mas esses dois irmãos não. Correção trivial; gap real de defesa.

**🟠 Média — Reverse tabnabbing** (`target="_blank"` sem `rel="noopener noreferrer"`):
- `assets/js/ffc-calendar-frontend.js:427`
- `assets/js/ffc-user-dashboard-appointments.js:159`
- `assets/js/ffc-user-dashboard-certificates.js:96`

**🟠 Média — CSRF em forms HTML:** `templates/verification-page.php:35` e form de submissão em
`includes/frontend/class-ffc-shortcodes.php:244` sem `wp_nonce_field()`.
**Confirmar** se o handling é REST/AJAX (já protegido por `check_ajax_referer`/`X-WP-Nonce`) — se for, risco cai muito.

**Falsos positivos (já seguros):** `innerHTML` estático em `ffc-pdf-generator.js:266` e `recruitment-import-batched.js`
(usa `textContent`); `localStorage` só guarda UUID/IDs (sem PII); validação de CPF com dígito verificador; AJAX com
nonce; URLs com `encodeURIComponent`.

### Plano
_A preencher. PR pequeno: escapar os 5 outputs + `rel=noopener`; testes Vitest; `npm run build:js` p/ min.js;
investigar caminho do POST antes de decidir nonce. Coverage floor JS não pode cair._

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
| — | — | tracker da auditoria criado | _este commit_ |
