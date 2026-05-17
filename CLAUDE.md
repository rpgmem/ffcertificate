# CLAUDE.md

Project conventions for Claude (Anthropic CLI / agent sessions) working
on this repository. Sessions inherit nothing across cold starts, so the
durable rules live here.

## Pull-request workflow

The repository has **"Allow auto-merge"** enabled in Settings → General.
Use it on every PR — no manual squash + merge unless auto-merge fails.

After `mcp__github__create_pull_request`:

1. `mcp__github__update_pull_request` with `draft: false` (auto-merge
   only fires on non-draft PRs).
2. `mcp__github__enable_pr_auto_merge` with `mergeMethod: SQUASH`.
3. End your turn. GitHub merges as soon as every required check
   passes; `<github-webhook-activity>` fires the merge event back to
   the session.

Don't poll CI manually after step 2 unless the user asks. The webhook
subscription delivers **failures + comments + the final merge event**;
green completion is silent by design.

## CI gates (all gating, enforced on `main`)

- PHP: PHPStan (level 8) · WPCS · PHPUnit (8.1/8.2/8.3/8.4) · Coverage
  ≥ floor (clover, env `COVERAGE_FLOOR_LINES` in `.github/workflows/ci.yml`).
- JS / CSS: ESLint (zero-error) · Stylelint (zero-error) · Vitest +
  coverage ≥ floor (env `JS_COVERAGE_FLOOR_LINES` in
  `.github/workflows/lint.yml`).
- Misc: CodeQL (javascript) · Composer audit · Review dependency
  changes · Verify minified assets are up to date.

The coverage floors are ratcheted upward in the PR that delivers the
gain — never lowered. The comment block above each `*_FLOOR_LINES`
keeps the audit trail.

## Test infrastructure

- PHP: PHPUnit 9; tests under `tests/Unit` and `tests/Integration`.
- JS: Vitest 2 with jsdom; tests under `tests/js/*.test.js`. Real
  jQuery via `jquery/factory` bound to the jsdom window
  (`tests/js/setup.js`). Scripts under `assets/js/` load via
  `vm.runInThisContext` so V8 coverage attribution survives
  (`tests/js/helpers.js`).
- jsdom has no layout — jQuery `:visible` always reports false for
  shown elements. Assert on `css('display')` instead. Disable jQuery
  animation queueing in tests by setting `window.$.fx.off = true` in
  `beforeEach` so `slideUp`/`slideDown`/`fadeOut` apply immediately.

## Source-of-truth files

When editing JS that ships to the browser, also run `npm run build:js`
so the matching `*.min.js` and `.map` stay in sync — the
`Verify minified assets are up to date` CI job fails otherwise.

## Date / time storage convention

Two categories. Pick the right one when adding a new column or touching
an existing one — see #249 for the migration roadmap that retires the
mixed pre-#244 patterns.

### Category A — **Instants** (a moment in physical time)

Things like "the user submitted this form at X", "the admin called this
candidate at Y", "this audit row was written at Z". Use these rules:

- **Schema**: `BIGINT UNSIGNED`.
- **Write**: store `time()` (PHP) — returns UTC unix seconds by
  construction, independent of `date.timezone` or the WP TZ setting.
  Never `current_time('mysql')`, which respects WP TZ and produces a
  string that drifts when the admin changes their site timezone.
- **Read**: pass straight to `DateFormatter::format_datetime($ts)` or
  `wp_date($fmt, $ts)`. Both apply `wp_timezone()` to render. Changing
  the WP TZ re-renders correctly with no data migration.
- **Compare**: ints compare directly — `WHERE ts > UNIX_TIMESTAMP(NOW())`,
  `BETWEEN ? AND ?`, `ORDER BY ts DESC`. No `STR_TO_DATE`, no
  `FROM_UNIXTIME` in the predicate.
- **PHPDoc**: `@var int Unix UTC timestamp (seconds since epoch).`

Existing examples: the Public Operator Access audit ring buffer
(`entry['ts']`) was unix int from day one — that's why the only TZ bug
that ever appeared there (#247) was a rendering choice (`gmdate` →
`wp_date`), never a storage issue.

#### Category A exception — housekeeping timestamps

`created_at` / `updated_at` columns that are (1) MySQL auto-managed
via `DEFAULT CURRENT_TIMESTAMP` (and `ON UPDATE CURRENT_TIMESTAMP`),
or (2) PHP-managed but never rendered to end users, stay as DATETIME.

Rationale: these are audit / sort columns only — they never reach a
display path that would surface TZ drift. BIGINT UNSIGNED would force
PHP responsibility for every INSERT/UPDATE site (MySQL cannot
`DEFAULT CURRENT_TIMESTAMP` on BIGINT) with no user-facing benefit.

Current inventory (snapshot at v6.6.1):

| Table | Pattern | Notes |
| --- | --- | --- |
| `ffc_reregistration_submissions` | P1 (MySQL auto) | `ORDER BY created_at` in repository |
| `ffc_recruitment_*` (6 tables) | P2 (PHP-managed, `NOT NULL`) | written via `current_time('mysql')` |
| `ffc_audience_*` (5 tables) | P1 (MySQL auto) | — |
| `ffc_rate_limit_*` (3 tables) | P1 (MySQL auto) | — |
| `ffc_custom_fields*` (3 tables) | P1 (MySQL auto) | — |
| `ffc_dynamic_rereg_*` (2 tables) | P1 (MySQL auto) | — |
| `ffc_url_shortener` | P2 (PHP-managed, `NOT NULL`) | — |
| `ffc_self_scheduling_*` | P3 (hybrid: `created_at` auto, `updated_at` PHP) | — |
| `ffc_activity_log` | P2 (PHP-managed, `NOT NULL`) | — |

If a future feature renders one of these columns to a user, that
column must be migrated to Category A storage at that point — not
left as a hidden TZ-drift trap.

### Category B — **Wall-clock** (a human commitment, no TZ semantics)

Things like "the appointment is on May 20", "the doctor sees the patient
at 09:00". The value means the same thing if the user travels or the
server changes TZ — converting to UTC introduces DST/ambiguity bugs.

- **Schema**: `DATE`, `TIME`, or `DATETIME` (the combined form). Stored
  literally, no conversion at read or write.
- **Write**: store what the user picked — `'2026-05-20'`, `'09:00:00'`.
- **Read**: render via `DateFormatter::format_date()` /
  `format_time()` / `format_datetime()` directly. `wp_date()` applies
  the site TZ when given a unix int, but with a `DATE` / `TIME` string
  source you feed the value as-is.
- **PHPDoc**: `@var string Wall-clock DATE in 'Y-m-d' (no timezone semantics).`

Existing examples: `appointment_date` (DATE), `start_time` / `end_time`
(TIME), `date_to_assume` (DATE), `time_to_assume` (TIME).

### Always

- Display goes through `DateFormatter::format_*()`. No `gmdate()`,
  no `date_i18n()`, no `wp_date()` outside the helper unless there's
  a documented reason (e.g. building an iCal `DTSTAMP` per RFC 5545).
- Filenames / log keys / API contracts that need a stable ISO format
  may use `gmdate('Y-m-d\TH:i:s\Z', $ts)` — but the column those
  filenames represent should still follow Category A or B.

## Legacy compat shims — audit log

Inventário (snapshot v6.6.1) dos shims de compatibilidade legada que
permanecem no código por design. Removê-los requer evidência de que
nenhuma instalação em produção depende deles.

| Shim | Local | Risco se removido | Por que fica |
|------|-------|--------|----------|
| `ensure_legacy_caps_renamed()` v1 | `class-ffc-loader.php` | Médio | Idempotent + version-flagged via `ffc_legacy_caps_renamed_v1`; dormant após primeiro `plugins_loaded` post-6.2.0. Custo zero. |
| Cron cleanup pré-4.6.15 | `class-ffc-activator.php:92-94` | Baixo | 3× `wp_clear_scheduled_hook`. Sites com upgrade auto pulando versões antigas mantêm crons órfãos sem isso. |
| Keys `count` / `success` / `fail` em `get_audit_log_summary()` | `class-ffc-public-csv-download.php` | **Alto** | Contrato de API pública. Consumidores externos (filters/hooks) podem depender deles. Removível só com banner ⚠ de breaking change. |
| `cpf_rf_encrypted` legacy column fallback (3-tier) | `class-ffc-pdf-generator.php` | **Alto** | Data loss em PDFs para installs com dados pré-split. Remover só após confirmar que TODAS instalações ativas têm dados migrados para `cpf_encrypted` / `rf_encrypted`. |

Quando uma feature nova tornar um desses shims inseguro ou inadequado,
abra sub-issue específica + breaking-change banner no CHANGELOG.

## Settings reads

Read `ffc_settings` via
`FreeFormCertificate\Settings\SettingsReader`, not `get_option('ffc_settings')`
directly:

- Use typed accessors when one exists
  (`SettingsReader::emails_disabled()`,
  `SettingsReader::activity_log_retention_days()`, etc.).
- Fall back to `SettingsReader::get($key, $default)` for keys without
  a dedicated typed accessor.
- Use `SettingsReader::all()` when a caller reads 5+ keys (SMTP block,
  DateFormatter format catalog) and array-style access stays clearer
  than repeated method calls.

The 14 debug-area toggles continue to be read via
`Debug::is_enabled($area)` — that helper has the canonical
`function_exists('get_option')` defensive check and is the typed
reader for that subset.

Classes that already encapsulate `get_option('ffc_settings')` in
their own private helper (e.g. `UrlShortenerService::get_settings()`)
do NOT need to migrate — they're already centralized.

## Branch naming

Use `claude/<short-kebab-description>`. Examples in main history:
`claude/js-coverage-sprint-B-audience-smoke`,
`claude/csv-import-normalize-cpf-rf`. The remote refuses pushes to
`main` directly; always go through a PR.

## What not to do

- Do not amend or rewrite published commits (force-push reserved for
  branches you own and are still mid-rebase).
- Do not skip hooks (`--no-verify`) or signing.
- Do not bypass the coverage floor — bump it forward or restore the
  lost coverage. Never lower it.
- Do not add new untested code paths in a coverage-aware PR; either
  cover them in the same PR or document the deferral.
