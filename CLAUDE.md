# CLAUDE.md

Project conventions for Claude (Anthropic CLI / agent sessions) working on this repository. Sessions inherit nothing across cold starts, so the durable rules live here.

## Pull-request workflow

The repository has **"Allow auto-merge"** enabled in Settings → General.
Use it on every PR — no manual squash + merge unless auto-merge fails.

**PR base branch:** by default, target `develop`, not `main`. The only PRs that target `main` are (1) the periodic release PR `develop → main` that consolidates the accumulated batch with a single version bump, and (2) hotfix PRs from `hotfix/*` branches when a critical bug needs to ship without waiting for develop's queue to consolidate. See "Develop branch workflow" below for the full mapping.

After `mcp__github__create_pull_request`:

1. `mcp__github__update_pull_request` with `draft: false` (auto-merge only fires on non-draft PRs).
2. `mcp__github__enable_pr_auto_merge` with `mergeMethod: SQUASH`. 
3. End your turn. GitHub merges as soon as every required check passes; `<github-webhook-activity>` fires the merge event back to the session.

Don't poll CI manually after step 2 unless the user asks. The webhook subscription delivers **failures + comments + the final merge event**; green completion is silent by design.

## CI gates (all gating, enforced on `main` and `develop`)

- PHP: PHPStan (level 8) · WPCS · PHPUnit (8.3/8.4) · Coverage ≥ floor (clover, env `COVERAGE_FLOOR_LINES` in `.github/workflows/ci.yml`).
- JS / CSS: ESLint (zero-error) · Stylelint (zero-error) · Vitest + coverage ≥ floor (env `JS_COVERAGE_FLOOR_LINES` in `.github/workflows/lint.yml`).
- Misc: CodeQL (javascript) · Composer audit · Review dependency changes · Verify minified assets are up to date.

The same gates run on PRs to `develop` and on the release PR `develop → main`. Develop must stay deployable to the testes site, so we don't relax gates there — a green develop is the precondition for opening the next release PR.

The coverage floors are ratcheted upward in the PR that delivers the gain — never lowered. The comment block above each `*_FLOOR_LINES` keeps the audit trail.

## Test infrastructure

- PHP: PHPUnit 9; tests under `tests/Unit` and `tests/Integration`.
- JS: Vitest 2 with jsdom; tests under `tests/js/*.test.js`. Real jQuery via `jquery/factory` bound to the jsdom window (`tests/js/setup.js`). Scripts under `assets/js/` load via `vm.runInThisContext` so V8 coverage attribution survives (`tests/js/helpers.js`).
- jsdom has no layout — jQuery `:visible` always reports false for shown elements. Assert on `css('display')` instead. Disable jQuery animation queueing in tests by setting `window.$.fx.off = true` in `beforeEach` so `slideUp`/`slideDown`/`fadeOut` apply immediately.

## Source-of-truth files

When editing JS that ships to the browser, also run `npm run build:js` so the matching `*.min.js` and `.map` stay in sync — the `Verify minified assets are up to date` CI job fails otherwise.

## Date / time storage convention

Two categories. Pick the right one when adding a new column or touching an existing one — see #249 for the migration roadmap that retires the mixed pre-#244 patterns.

### Category A — **Instants** (a moment in physical time)

Things like "the user submitted this form at X", "the admin called this candidate at Y", "this audit row was written at Z". Use these rules:

- **Schema**: `BIGINT UNSIGNED`.
- **Write**: store `time()` (PHP) — returns UTC unix seconds by construction, independent of `date.timezone` or the WP TZ setting. Never `current_time('mysql')`, which respects WP TZ and produces a string that drifts when the admin changes their site timezone.
- **Read**: pass straight to `DateFormatter::format_datetime($ts)` or `wp_date($fmt, $ts)`. Both apply `wp_timezone()` to render. Changing the WP TZ re-renders correctly with no data migration.
- **Compare**: ints compare directly — `WHERE ts > UNIX_TIMESTAMP(NOW())`, `BETWEEN ? AND ?`, `ORDER BY ts DESC`. No `STR_TO_DATE`, no `FROM_UNIXTIME` in the predicate.
- **PHPDoc**: `@var int Unix UTC timestamp (seconds since epoch).`

Existing examples: the Public Operator Access audit ring buffer (`entry['ts']`) was unix int from day one — that's why the only TZ bug that ever appeared there (#247) was a rendering choice (`gmdate` → `wp_date`), never a storage issue.

#### Category A exception — housekeeping timestamps

`created_at` / `updated_at` columns that are (1) MySQL auto-managed via `DEFAULT CURRENT_TIMESTAMP` (and `ON UPDATE CURRENT_TIMESTAMP`), or (2) PHP-managed but never rendered to end users, stay as DATETIME.

Rationale: these are audit / sort columns only — they never reach a display path that would surface TZ drift. BIGINT UNSIGNED would force PHP responsibility for every INSERT/UPDATE site (MySQL cannot `DEFAULT CURRENT_TIMESTAMP` on BIGINT) with no user-facing benefit.

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

If a future feature renders one of these columns to a user, that column must be migrated to Category A storage at that point — not left as a hidden TZ-drift trap.

### Category B — **Wall-clock** (a human commitment, no TZ semantics)

Things like "the appointment is on May 20", "the doctor sees the patient at 09:00". The value means the same thing if the user travels or the server changes TZ — converting to UTC introduces DST/ambiguity bugs.

- **Schema**: `DATE`, `TIME`, or `DATETIME` (the combined form). Stored literally, no conversion at read or write.
- **Write**: store what the user picked — `'2026-05-20'`, `'09:00:00'`.
- **Read**: render via `DateFormatter::format_date()` / `format_time()` / `format_datetime()` directly. `wp_date()` applies the site TZ when given a unix int, but with a `DATE` / `TIME` string source you feed the value as-is.
- **PHPDoc**: `@var string Wall-clock DATE in 'Y-m-d' (no timezone semantics).`

Existing examples: `appointment_date` (DATE), `start_time` / `end_time` (TIME), `date_to_assume` (DATE), `time_to_assume` (TIME).

### Always

- Display goes through `DateFormatter::format_*()`. No `gmdate()`, no `date_i18n()`, no `wp_date()` outside the helper unless there's a documented reason (e.g. building an iCal `DTSTAMP` per RFC 5545).
- Filenames / log keys / API contracts that need a stable ISO format may use `gmdate('Y-m-d\TH:i:s\Z', $ts)` — but the column those filenames represent should still follow Category A or B.

## Legacy compat shims — audit log

Inventário (snapshot v6.6.1) dos shims de compatibilidade legada que permanecem no código por design. Removê-los requer evidência de que nenhuma instalação em produção depende deles.

| Shim | Local | Risco se removido | Por que fica |
|------|-------|--------|----------|
| `ensure_legacy_caps_renamed()` v1 | `class-ffc-loader.php` | Médio | Idempotent + version-flagged via `ffc_legacy_caps_renamed_v1`; dormant após primeiro `plugins_loaded` post-6.2.0. Custo zero. |
| Cron cleanup pré-4.6.15 | `class-ffc-activator.php:92-94` | Baixo | 3× `wp_clear_scheduled_hook`. Sites com upgrade auto pulando versões antigas mantêm crons órfãos sem isso. |
| Keys `count` / `success` / `fail` em `get_audit_log_summary()` | `class-ffc-public-csv-download.php` | **Alto** | Contrato de API pública. Consumidores externos (filters/hooks) podem depender deles. Removível só com banner ⚠ de breaking change. |
| `cpf_rf_encrypted` legacy column fallback (3-tier) | `class-ffc-pdf-generator.php` | **Alto** | Data loss em PDFs para installs com dados pré-split. Remover só após confirmar que TODAS instalações ativas têm dados migrados para `cpf_encrypted` / `rf_encrypted`. |

Quando uma feature nova tornar um desses shims inseguro ou inadequado, abra sub-issue específica + breaking-change banner no CHANGELOG.

## Settings reads

Read `ffc_settings` via `FreeFormCertificate\Settings\SettingsReader`, not `get_option('ffc_settings')` directly:

- Use typed accessors when one exists (`SettingsReader::emails_disabled()`, `SettingsReader::activity_log_retention_days()`, etc.).
- Fall back to `SettingsReader::get($key, $default)` for keys without a dedicated typed accessor.
- Use `SettingsReader::all()` when a caller reads 5+ keys (SMTP block, DateFormatter format catalog) and array-style access stays clearer than repeated method calls.

The 14 debug-area toggles continue to be read via `Debug::is_enabled($area)` — that helper has the canonical `function_exists('get_option')` defensive check and is the typed reader for that subset.

Classes that already encapsulate `get_option('ffc_settings')` in their own private helper (e.g. `UrlShortenerService::get_settings()`) do NOT need to migrate — they're already centralized.

## Capability naming

All FFC capabilities follow one grammar (ratified in #488, applied plugin-wide):

```
ffc_<action>_[own_]<domain>[_<qualifier>]
```

- **Actions (closed vocabulary):** `view` (read-only) · `manage` (read-write: create/edit/delete/configure) · `export` · `import` · `edit` (modify existing records — narrower than `manage`) · `delete`. Flow-specific verbs: `book`, `cancel`, `download`, `call`, `bypass`.
- **`own_`** marks a self-scoped end-user cap (frontend; the user's own data).
- **Domains (canonical):** `certificates`, `appointments`, `audiences`, `reregistration`, `custom_fields`, `activity_log`, `settings`, `recruitment`, `url_shortener`, `forms_api`.
- **Qualifiers:** `_pii`, `_settings`, `_reasons`, `_history`.

### 3-state permission model

Every admin domain exposes a `view`/`manage` pair so each surface has three states — *não vê* / *só vê* / *vê e edita* — with the WP admin (`manage_options`) above all:

```
canView = current_user_can('manage_options') || view_cap || manage_cap
canEdit = current_user_can('manage_options') || manage_cap
```

A `manage` role does **not** need to also carry the `view` cap — `canView` already includes `manage`. Hidden when neither; read-only render (disabled inputs, no save, row/bulk actions hidden) when only `view`. Use `Utils::current_user_can_admin_or($cap)` for inline gates; menu/tab caps take the slug directly (admins hold every FFC admin cap via the activation/`ensure_admin_capabilities` grant).

### Registry, catalog, migration

- Machine list: `CapabilityManager` (`*_CAPABILITIES` consts + `module_roles_definition()`).
- Human metadata: `CapabilityCatalog::groups()`. **Invariant** (enforced by `CapabilityCatalogTest`): `CapabilityCatalog::all_slugs()` must equal `CapabilityManager::get_all_capabilities()` as a set — adding a cap to one without the other fails CI.
- Renames ship with a one-shot, option-flagged migration that rewrites grants on every user (`user_meta`) **and** every role definition (see `CapabilityManager::migrate_taxonomy_renames()` + `Loader::ensure_taxonomy_renamed()`). Renames are a **breaking change** for external integrations referencing old slugs — call it out in the CHANGELOG.

## Branch naming

Use `claude/<short-kebab-description>` for feature branches that target `develop`. Examples in main history: `claude/js-coverage-sprint-B-audience-smoke`, `claude/csv-import-normalize-cpf-rf`. Use `hotfix/<short-kebab-description>` when cutting an urgent fix from `main` (see "Develop branch workflow" → Hotfixes). The remote refuses pushes to `main` and `develop` directly; always go through a PR.

## Develop branch workflow

Adopted v6.7.7 to decouple iteration cadence from production. Source domain (prod) only sees one version bump per batch; the testes domain runs `develop` HEAD and absorbs the per-PR churn.

### Branch topology

```
main      ─o──────────────────────o────────────  ← PROD (source domain)
                                  ↑
                                  release PR
                                  with consolidated bump
develop   ─o─o─o─o─o─o─o─o─o─o─o─/             ← TESTES (testes domain)
           PR1 PR2 PR3 …                         each merge auto-deploys
```

- **`main`** — the production branch. Updated only by (1) the release PR `develop → main` (squash merge with the final bump + consolidated CHANGELOG entry) and (2) hotfix PRs `hotfix/* → main` when a critical bug bypasses the queue.
- **`develop`** — the integration branch. Default base for every feature PR. Each merge into `develop` triggers `.github/workflows/deploy-develop.yml`, which rsyncs the working tree to the testes server.
- **`hotfix/*`** — short-lived. Cut from `main`, merge back to `main`, then rebase `develop` on top of the new `main` (see Hotfixes).

### Per-PR flow (the common case)

1. Feature branch `claude/<desc>` cut from `develop`.
2. PR targets `develop`. CI gates run identically to PRs against main.
3. **No `FFC_VERSION` bump in the feature PR.** Develop accumulates work under the existing `FFC_VERSION` until release time.
4. CHANGELOG entries go under the `[Unreleased]` section at the top of `CHANGELOG.md` — they stay there across multiple PRs.
5. Auto-merge enabled (SQUASH). Once merged, `deploy-develop.yml` pushes the new HEAD to the testes server within ~1 minute.

### Release PR (`develop → main`)

When the batch on develop is validated against the testes site and ready to ship to prod:

1. Open a single PR `develop → main`.
2. In that PR (committed onto `develop` immediately before opening):
   - Bump `FFC_VERSION` in the three sync sites (`ffcertificate.php` header, `FFC_VERSION` constant, `readme.txt` `Stable tag`). See "Versioning".
   - Rename the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] (YYYY-MM-DD)` and add a fresh empty `[Unreleased]` heading above it.
   - Run `npm run build:js` if any JS/CSS in `assets/` changed across the batch and the bundles weren't already rebuilt mid-flight (the "Verify minified assets are up to date" gate would catch this anyway).
3. Auto-merge SQUASH into `main`. The squash commit subject should follow main's convention: `X.Y.Z — <short summary of the batch>`.
4. After merge, rebase `develop` on `main` (see Sync below) so the next batch starts from the bumped baseline.

### Hotfix flow (urgent fix that can't wait for the next release)

When a critical bug needs to ship to prod while develop has un-released commits:

1. `git fetch origin && git checkout -b hotfix/<desc> origin/main`.
2. Apply the fix. Bump `FFC_VERSION` as a real patch (e.g. `6.7.7 → 6.7.8`) — hotfixes consume patch numbers, not the `.x.y.z.N` cache-bust convention.
3. PR `hotfix/<desc> → main`. Auto-merge SQUASH.
4. **Then sync develop with the new main** (see below) so develop carries the hotfix and the next release PR doesn't try to "undo" it.

### Sync `develop` with `main` (post-hotfix or post-release)

```bash
git fetch origin
git checkout develop
git rebase origin/main
git push --force-with-lease origin develop
```

This rewrites develop's SHAs on top of the new `main` tip. Force-push is permitted on `develop` by design (the branch protection deliberately omits "Require linear history" and the push restriction) — see "Branch protection" below. If a feature PR was open against develop at the moment of the rebase, the PR author rebases their branch on the new develop tip; this is the cost of keeping develop linear.

### Branch protection (`develop`)

Configured in Settings → Branches with intentionally lighter rules than `main`:

- ✅ Require a pull request before merging (no required reviewers — solo maintainer).
- ✅ Require status checks to pass before merging — all 6 gating jobs listed under "CI gates".
- ❌ Require linear history — left off so the rebase workflow above doesn't need admin bypass.
- ❌ Restrict who can push to matching branches — leaving force-push permitted is what makes the rebase sync above mechanical.
- ❌ Require deployments to succeed — `deploy-develop.yml` runs *after* merge, not as a merge gate.

Reasoning: develop is single-maintainer integration territory, not a shared production branch. Stronger protection here would force admin bypass for routine syncs and provide negligible safety benefit.

### Deploy to testes

`.github/workflows/deploy-develop.yml` runs on every `push` to `develop` and rsyncs the working tree to the testes server. Required GitHub secrets (Settings → Secrets and variables → Actions):

| Secret | Example | Notes |
| --- | --- | --- |
| `TESTES_SSH_HOST` | `ssh.testes.example.com` or `185.239.210.8` | DNS or IP of the testes host. **Hostname only, no port, no protocol prefix.** |
| `TESTES_SSH_USER` | `wp-deploy` | Account with write access to the plugin dir |
| `TESTES_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----…` | Private half of a dedicated keypair; public half goes in `~/.ssh/authorized_keys` on the testes host. **Must have no passphrase** — generate with `ssh-keygen -t ed25519 -N "" -f <path>`. GitHub Actions cannot enter passphrases interactively; a passphrase-protected key surfaces as `Permission denied (publickey,password)` in the rsync step, indistinguishable from a wrong key. |
| `TESTES_SSH_PORT` | `65002` | **Optional.** Defaults to `22`. Managed hosting (Hostinger, KingHost, Locaweb) usually exposes SSH on a high port — set this when so. |
| `TESTES_REMOTE_PATH` | `/var/www/testes/wp-content/plugins/ffcertificate` | Absolute path; no trailing slash |

The rsync uses `--delete`, so anything in the remote path that isn't in the develop working tree is removed on each deploy. The workflow excludes `.git/`, `.github/`, `vendor/`, `node_modules/`, `tests/`, and dev tooling (PHPStan, PHPUnit, PHPCS configs) — those don't belong in a runtime plugin dir.

The testes server should have `SCRIPT_DEBUG=true` in `wp-config.php` so non-minified assets load and `?ver=…` cache aggressiveness stays low while iterating.

## Versioning

Three places carry the plugin version and must stay in sync:

1. `ffcertificate.php` plugin header — `* Version: X.Y.Z`. Parsed by WordPress core BEFORE PHP runs, so it must be a literal string.
2. `ffcertificate.php` PHP constant — `define( 'FFC_VERSION', 'X.Y.Z' )`. Source of `?ver=…` on every `wp_enqueue_*` call.
3. `readme.txt` `Stable tag: X.Y.Z`. Parsed by WordPress.org before PHP runs; also a literal string.

When changing the version, update all three in the same commit.

### Patch vs. cache-bust-only releases

- A "real" patch release (any source-code change) consumes the next patch number: `6.6.2 → 6.6.3`.
- A **cache-bust-only release** (no functional change — exists purely to rotate the `?ver=…` asset cache key after a prior PR shipped an updated `.min.js` / `.min.css` without bumping the version) uses a 4th segment appended to the prior version: `6.6.2 → 6.6.2.1`. The next cache-bust sibling of the same minor would be `6.6.2.2`, and so on. WordPress's `version_compare()` and the plugin update flow both handle 4-segment versions without special-casing.
- Reason for the convention: a cache-bust release carries no new user-visible behavior, only a key rotation. Burning a real patch number on it would imply meaningful changes that aren't there.

### When to bump

The trigger has not changed — bundled-asset changes still rotate the cache key. What changed with the develop branch workflow is **where the bump lands**:

- **PRs targeting `main`** (release PR `develop → main`, hotfix PR `hotfix/* → main`): bump `FFC_VERSION` in the same PR. The release PR consolidates every `assets/**/*.min.js`, `assets/**/*.min.css`, `templates/**.php`, and `languages/*.l10n.php` / `.mo` change from the develop batch under one version. Hotfix PRs bump their own patch number.
- **PRs targeting `develop`**: do **not** bump. Develop sits at the last released version (the cache key on the testes domain stays stable across the batch), and the testes site sidesteps cache aggressiveness via `SCRIPT_DEBUG=true`. Bumping per-PR on develop would consume version numbers that have no production analog.

The "Verify minified assets are up to date" CI job catches build freshness on both bases but does NOT enforce the version bump — that's still a human discipline on the release PR.

## What not to do

- Do not amend or rewrite published commits on `main`. On `develop`, force-push is permitted only for the documented sync-with-main rebase ("Develop branch workflow" → Sync) — never to rewrite arbitrary history.
- Do not skip hooks (`--no-verify`) or signing.
- Do not bypass the coverage floor — bump it forward or restore the lost coverage. Never lower it.
- Do not add new untested code paths in a coverage-aware PR; either cover them in the same PR or document the deferral.
- Do not target `main` directly from a feature PR. The only PRs that base on `main` are the release PR (`develop → main`) and hotfix PRs (`hotfix/* → main`).
- Do not bump `FFC_VERSION` in a PR that targets `develop` — the bump belongs to the release PR.