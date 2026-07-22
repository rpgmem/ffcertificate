# CLAUDE.md

Project conventions for Claude (Anthropic CLI / agent sessions) working on this repository. Sessions inherit nothing across cold starts, so the durable rules live here.

**A recurring value across these conventions:** prefer the existing structure over indirection that narrows nothing — churn (renames, façades, per-module "engines", parallel counters/diagnostics) must earn its keep. Where that trade-off bites, the relevant section calls it out as the *bad-façade / namespace-churn trap*.

**Priority when anti-churn and consistency collide:** consistency wins when the inconsistency is *recurring and reader-facing* (every new reader re-pays it); anti-churn wins only when the change is *purely cosmetic* — a rename or reshuffle that removes no confusion. "Earn its keep" is **not** counted in functional gain alone: a persistent inconsistency is a real, compounding cost, not a cosmetic one. This leans consistency-first on purpose, because the project is now mature — low debt, high coverage, the #563-era refactor closed — and churn is cheap on clean, well-tested code, so a consistency fix that disturbs the module-boundary baseline or a namespace is usually worth it. (This is a shift from the refactor era, when churn fought an in-flight baseline and stability rightly came first.)

## Table of contents

1. **[Contributing workflow](#1-contributing-workflow)** — git / PR / release: pull-request workflow, branch naming, develop-branch workflow, versioning, CHANGELOG conventions, what not to do.
2. **[Quality gates and testing](#2-quality-gates-and-testing)** — CI gates (+ coverage floors, module-boundary guard), test infrastructure, build & assets.
3. **[Architecture and patterns](#3-architecture-and-patterns)** — repository pattern, module bootstrap (loaders), shared-service directories, email pipeline.
4. **[Domain conventions](#4-domain-conventions)** — date/time storage, settings reads, capability naming, security & PII.
5. **[Legacy and tech debt](#5-legacy-and-tech-debt)** — compat shims + evidence-gating.

---

## 1. Contributing workflow

### Pull-request workflow

The repository has **"Allow auto-merge"** enabled in Settings → General.
Use it on every PR — no manual squash + merge unless auto-merge fails.

**PR base branch:** by default, target `develop`, not `main`. The only PRs that target `main` are (1) the periodic release PR `develop → main` that consolidates the accumulated batch with a single version bump, and (2) hotfix PRs from `hotfix/*` branches when a critical bug needs to ship without waiting for develop's queue to consolidate. See "Develop branch workflow" below for the full mapping.

After `mcp__github__create_pull_request`:

1. `mcp__github__update_pull_request` with `draft: false` (auto-merge only fires on non-draft PRs).
2. `mcp__github__enable_pr_auto_merge` with `mergeMethod: SQUASH`. 
3. End your turn. GitHub merges as soon as every required check passes; `<github-webhook-activity>` fires the merge event back to the session.

Don't poll CI manually after step 2 unless the user asks. The webhook subscription delivers **failures + comments + the final merge event**; green completion is silent by design.

**Batch related work; gate the draft→ready flip.** Group trivially-related changes — especially docs-only (CHANGELOG / CLAUDE.md / comments) — into one PR; they gain nothing from the per-PR testes deploy and only multiply CI runs and rebase churn. Use **draft** to accumulate commits and keep the window short: open the PR late (work essentially done + locally green), rebase if `develop` moves under it, and treat a second forced rebase as the signal to finish or split. **Before flipping draft→ready + auto-merge:** if the PR completes a pre-agreed unit (a planned sprint/roadmap item with obvious done-criteria), go straight to ready + auto-merge; if the scope is ad-hoc or emerged mid-session — completeness not obvious from a plan — **confirm with the user first**, so follow-ups stay batched in the same PR instead of spawning a trickle of tiny PRs. No calendar deadline on drafts — the signal is drift (develop moving under the branch), not elapsed time.

**Closing an issue — leave no false-positive checkboxes.** When an issue closes as `completed`, its acceptance-criteria / delivery checkboxes must reflect reality: either tick every delivered box (`- [x]`) **or** leave a short closing comment that maps each delivered item to the PR that shipped it. An issue that closes with unticked `- [ ]` boxes for work that actually shipped reads as pending to a later scan — this is exactly the false positive reconciled across #249, #647, #649, #650, #697 (delivered work split across separate PRs, boxes never revisited). Legitimately-deferred boxes stay unticked, but the closing comment must name them as deferred (see #711's engine deferral) so "unticked" is never ambiguous between *done* and *dropped*.

**`Closes #NNN` does not auto-close in this workflow — close feature issues by hand.** GitHub only auto-closes a linked issue when the PR merges into the repository's **default branch**, which is deliberately kept as `main` (see "Develop branch workflow"), not `develop`. So a feature PR that merges to `develop` with `Closes #NNN` in its body leaves the issue **open**. Close it manually — with the checkbox reconciliation above — as soon as its work lands on `develop` (matching the repo's precedent that issues close on work-complete, e.g. #711 / #591 / #249, not on release-to-prod); do **not** wait for the release PR to carry it to `main`, or done work lingers as an open, unticked issue (the #728 case). Genuinely-future work (a removal scheduled releases out, like #730) correctly stays open — this rule is about work that has actually shipped to `develop`.

### Branch naming

Use `claude/<short-kebab-description>` for feature branches that target `develop`. Examples in main history: `claude/js-coverage-sprint-B-audience-smoke`, `claude/csv-import-normalize-cpf-rf`. Use `hotfix/<short-kebab-description>` when cutting an urgent fix from `main` (see "Develop branch workflow" → Hotfixes). The remote refuses pushes to `main` and `develop` directly; always go through a PR.

### Develop branch workflow

Adopted v6.7.7 to decouple iteration cadence from production. Source domain (prod) only sees one version bump per batch; the testes domain runs `develop` HEAD and absorbs the per-PR churn.

#### Branch topology

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

#### Per-PR flow (the common case)

1. Feature branch `claude/<desc>` cut from `develop`.
2. PR targets `develop`. CI gates run identically to PRs against main.
3. **No `FFC_VERSION` bump in the feature PR.** Develop accumulates work under the existing `FFC_VERSION` until release time.
4. CHANGELOG entries go under the `[Unreleased]` section at the top of `CHANGELOG.md` — they stay there across multiple PRs.
5. Auto-merge enabled (SQUASH). Once merged, `deploy-develop.yml` pushes the new HEAD to the testes server within ~1 minute.

#### Dependabot PRs

Dependabot is configured with `target-branch: develop` for all three ecosystems (`composer`, `npm`, `github-actions`) in `.github/dependabot.yml`, so **version updates** open against `develop` correctly.

**Security updates are the exception:** GitHub always raises Dependabot *security* updates against the repository's default branch (`main`), ignoring `target-branch` — this is not configurable. The default branch is intentionally kept as `main` (changing it would have repo-wide side effects), so security-update PRs will keep being born against `main`.

**Rule — retarget to `develop`:** whenever a Dependabot PR opens against `main` (in practice, always a security update), change its base to `develop` and leave a one-line comment stating the reason (*correct flow: every change funnels through `develop` and ships in the consolidated release PR, never as an out-of-band `main` commit*). Then comment `@dependabot rebase` so the lockfile diff is recomputed against `develop`. The PR then rides the normal develop batch with auto-merge like any other.

**Exception — genuine hotfix:** if the security fix is in a **runtime dependency** (shipped inside the plugin, not dev/CI tooling) *and* is severe enough to ship to production immediately, treat it as a hotfix (`hotfix/* → main`) instead of retargeting, then sync develop per "Sync `develop` with `main`". Dev/CI-only deps (`vitest`, `@vitest/coverage-v8`, `undici`, `js-yaml`, PHPStan, etc.) never qualify — they always ride the develop batch.

#### Release PR (`develop → main`)

When the batch on develop is validated against the testes site and ready to ship to prod:

1. Open a single PR `develop → main`.
2. In that PR (committed onto `develop` immediately before opening):
   - Bump `FFC_VERSION` in the three sync sites (`ffcertificate.php` header, `FFC_VERSION` constant, `readme.txt` `Stable tag`). See "Versioning".
   - Rename the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] (YYYY-MM-DD)` and add a fresh empty `[Unreleased]` heading above it. **Then, once the release PR squash-merges into `main`, backfill the release commit reference onto that heading** by appending `` — `<short-sha>` `` (the 7-char short SHA of the squash commit on `main`), matching every other shipped version header. A missing suffix means the backfill was skipped (as for 6.11.1 / 6.11.2, fixed retroactively).
   - Run `npm run build:js` if any JS/CSS in `assets/` changed across the batch and the bundles weren't already rebuilt mid-flight (the "Verify minified assets are up to date" gate would catch this anyway).
3. Auto-merge SQUASH into `main`. The squash commit subject should follow main's convention: `X.Y.Z — <short summary of the batch>`.
4. **Tag the release to publish it.** The GitHub Release + distributable zip are automated — `.github/workflows/release.yml` triggers on pushing a tag matching `v*`: it validates the tag equals the `ffcertificate.php` `Version:` header, builds `ffcertificate-X.Y.Z.zip` (staged via `.distignore`), extracts the `## [X.Y.Z]` section of `CHANGELOG.md` as the release notes, and publishes the GitHub Release. So there is **no manual "create release" step** — after the squash lands on `main`, tag that commit and push:
   ```bash
   git fetch origin
   git checkout main && git pull --ff-only origin main   # local main = the release squash
   git tag vX.Y.Z                                         # tag the release commit
   git push origin vX.Y.Z                                 # → fires release.yml (Release + zip)
   ```
   The tagged commit's `Version:` header must already equal `X.Y.Z` (true post-bump) or the workflow's sanity check fails. Do the CHANGELOG short-SHA backfill (step 2) **before** tagging so the tagged tree carries it. Pushing the tag publishes a production release (public zip + Release notes), so **an agent surfaces these four lines for the user to run rather than pushing the tag itself** — the same production-deploy sign-off that keeps the `develop → main` PR draft until the user confirms.
5. After merge, rebase `develop` on `main` (see Sync below) so the next batch starts from the bumped baseline.

**Landing the bump on `develop` (who can do step 2).** Step 2's bump commit has to sit on `develop` before the `develop → main` PR is opened. A maintainer with direct-push access commits it straight to `develop`. An **agent driving the release cannot push to `develop` directly** (the remote refuses it — see "Branch naming"), so it instead lands the bump via **one dedicated `release: X.Y.Z — bump + finalize CHANGELOG` PR to `develop`** (auto-merge), then opens the `develop → main` PR. That dedicated release-bump PR is **not** what "What not to do" forbids — that prohibition targets *feature* PRs sneaking a version bump; this is the deliberate release-moment bump, delivered through the only channel an agent has. Either way, the `develop → main` PR itself stays **draft until the user confirms the prod deploy**.

#### Hotfix flow (urgent fix that can't wait for the next release)

When a critical bug needs to ship to prod while develop has un-released commits:

1. `git fetch origin && git checkout -b hotfix/<desc> origin/main`.
2. Apply the fix. Bump `FFC_VERSION` as a real patch (e.g. `6.7.7 → 6.7.8`) — hotfixes consume patch numbers, not the `.x.y.z.N` cache-bust convention.
3. PR `hotfix/<desc> → main`. Auto-merge SQUASH.
4. **Then sync develop with the new main** (see below) so develop carries the hotfix and the next release PR doesn't try to "undo" it.

#### Sync `develop` with `main` (post-hotfix or post-release)

```bash
git fetch origin
git checkout develop
git rebase origin/main
git push --force-with-lease origin develop
```

This rewrites develop's SHAs on top of the new `main` tip. Force-push is permitted on `develop` by design (the branch protection deliberately omits "Require linear history" and the push restriction) — see "Branch protection" below. If a feature PR was open against develop at the moment of the rebase, the PR author rebases their branch on the new develop tip; this is the cost of keeping develop linear.

**Post-release — use `reset`, not `rebase`.** The `rebase` above is the **post-hotfix** recipe, where develop still carries un-released commits to replay on top of the new `main`. After a **release** squash-merge, develop was *fully consumed* by the squash — every develop commit is already inside `main`'s single release commit, so a rebase tries to replay them all and conflicts (typically on `CHANGELOG.md`). In that case skip the rebase and reset develop straight to `main`:

```bash
git fetch origin
git checkout develop
git reset --hard origin/main
git push --force-with-lease origin develop
```

Confirm nothing is lost first with `git log --oneline origin/main..develop` — after a release those are only the pre-squash commits, whose content already lives in `main`. (This is the trap that bit the 6.15.0 sync.)

#### Branch protection (`develop`)

Configured in Settings → Branches with intentionally lighter rules than `main`:

- ✅ Require a pull request before merging (no required reviewers — solo maintainer).
- ✅ Require status checks to pass before merging — all gating jobs listed under "CI gates".
- ❌ Require linear history — left off so the rebase workflow above doesn't need admin bypass.
- ❌ Restrict who can push to matching branches — leaving force-push permitted is what makes the rebase sync above mechanical.
- ❌ Require deployments to succeed — `deploy-develop.yml` runs *after* merge, not as a merge gate.

Reasoning: develop is single-maintainer integration territory, not a shared production branch. Stronger protection here would force admin bypass for routine syncs and provide negligible safety benefit.

#### Deploy to testes

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

### Versioning

Three places carry the plugin version and must stay in sync:

1. `ffcertificate.php` plugin header — `* Version: X.Y.Z`. Parsed by WordPress core BEFORE PHP runs, so it must be a literal string.
2. `ffcertificate.php` PHP constant — `define( 'FFC_VERSION', 'X.Y.Z' )`. Source of `?ver=…` on every `wp_enqueue_*` call.
3. `readme.txt` `Stable tag: X.Y.Z`. Parsed by WordPress.org before PHP runs; also a literal string.

When changing the version, update all three in the same commit.

#### Patch vs. cache-bust-only releases

- A "real" patch release (any source-code change) consumes the next patch number: `6.6.2 → 6.6.3`.
- A **cache-bust-only release** (no functional change — exists purely to rotate the `?ver=…` asset cache key after a prior PR shipped an updated `.min.js` / `.min.css` without bumping the version) uses a 4th segment appended to the prior version: `6.6.2 → 6.6.2.1`. The next cache-bust sibling of the same minor would be `6.6.2.2`, and so on. WordPress's `version_compare()` and the plugin update flow both handle 4-segment versions without special-casing.
- Reason for the convention: a cache-bust release carries no new user-visible behavior, only a key rotation. Burning a real patch number on it would imply meaningful changes that aren't there.

#### When to bump

The trigger has not changed — bundled-asset changes still rotate the cache key. What changed with the develop branch workflow is **where the bump lands**:

- **PRs targeting `main`** (release PR `develop → main`, hotfix PR `hotfix/* → main`): bump `FFC_VERSION` in the same PR. The release PR consolidates every `assets/**/*.min.js`, `assets/**/*.min.css`, `templates/**.php`, and `languages/*.l10n.php` / `.mo` change from the develop batch under one version. Hotfix PRs bump their own patch number.
- **PRs targeting `develop`**: do **not** bump. Develop sits at the last released version (the cache key on the testes domain stays stable across the batch), and the testes site sidesteps cache aggressiveness via `SCRIPT_DEBUG=true`. Bumping per-PR on develop would consume version numbers that have no production analog.

The "Verify minified assets are up to date" CI job catches build freshness on both bases but does NOT enforce the version bump — that's still a human discipline on the release PR.

### CHANGELOG conventions

`CHANGELOG.md` follows Keep a Changelog. Per change:

- One entry under the top `[Unreleased]` section, grouped by heading (`Added` / `Changed` / `Fixed` / `Security` / `Removed` / `Deprecated`). Entries stay in `[Unreleased]` across PRs until the release PR renames the heading (see "Release PR").
- **Always cite the issue/PR** (`(#NNN)` / `#NNN`). Every `[Unreleased]` bullet must carry a reference — the linked PR holds the granular detail.
- **No internal roadmap codenames** in the prose — no "Sprint N", "phase N", or letter-codes (`A6`, `B3`, `E5`, …). Describe the change itself and keep entries concise (one tight paragraph, not a wall of class-by-class text).
- Ordinary words that happen to look like codes — "A4" (paper size), "four-phase flow" (literal steps) — are fine; the rule targets roadmap taxonomy only.

### What not to do

- Do not amend or rewrite published commits on `main`. On `develop`, force-push is permitted only for the documented sync-with-main rebase ("Develop branch workflow" → Sync) — never to rewrite arbitrary history.
- Do not skip hooks (`--no-verify`) or signing.
- Do not bypass the coverage floor — bump it forward or restore the lost coverage. Never lower it — the sole exception is an honest re-measure after deleting covered **product** code (never tests); see "CI gates".
- Do not add new untested code paths in a coverage-aware PR; either cover them in the same PR or document the deferral.
- Do not target `main` directly from a feature PR. The only PRs that base on `main` are the release PR (`develop → main`) and hotfix PRs (`hotfix/* → main`).
- Do not bump `FFC_VERSION` in a **feature** PR that targets `develop` — the bump belongs to the release. (The lone exception is the dedicated `release: X.Y.Z` bump PR an agent uses to land the bump on `develop` when it can't direct-push; see "Release PR" → "Landing the bump on `develop`".)

---

## 2. Quality gates and testing

### CI gates (all gating, enforced on `main` and `develop`)

- PHP: PHPStan (level 8) · WPCS · PHPUnit (8.3/8.4) · Coverage ≥ floor (clover, env `COVERAGE_FLOOR_LINES` in `.github/workflows/ci.yml`).
- JS / CSS: ESLint (zero-error) · Stylelint (zero-error) · Vitest + coverage ≥ floor (env `JS_COVERAGE_FLOOR_LINES` in `.github/workflows/lint.yml`).
- Misc: CodeQL (javascript) · Composer audit · Review dependency changes · Verify minified assets are up to date.

The same gates run on PRs to `develop` and on the release PR `develop → main`. Develop must stay deployable to the testes site, so we don't relax gates there — a green develop is the precondition for opening the next release PR.

The coverage floors are ratcheted upward in the PR that delivers the gain — never lowered. The comment block above each `*_FLOOR_LINES` keeps the audit trail.

**One exception — code deletion.** Removing well-covered **product** code (never tests) can legitimately drop the line-% because high-coverage lines left the denominator — that is not a regression to restore. When a deletion PR lowers the measured coverage, an honest re-measure of the floor down to the new figure is allowed, provided the `*_FLOOR_LINES` comment block records the deleting PR and the new baseline. This is the *only* case the floor may move down; deleting or weakening tests to relax it never qualifies.

**Acceptable floor buffer:** when bumping, the floor may sit up to **5 percentage points** below the freshly measured coverage. A buffer of ≤5pp is acceptable for both JS (`JS_COVERAGE_FLOOR_LINES`) and PHP (`COVERAGE_FLOOR_LINES`) — it absorbs v8/clover run-to-run jitter so the gate doesn't flake on fractional swings, without forcing the floor to chase every decimal. So: still ratchet up when a PR delivers a real gain, but leave no more than ~5pp on the table, and never set the floor *above* the lowest run you've actually observed.

**Module-boundary guard (#563 B3).** `tests/Unit/ModuleBoundaryTest.php` freezes the cross-module dependency graph of `includes/` (a "module" = the first namespace segment after `FreeFormCertificate\`; an edge = module A referencing `FreeFormCertificate\B\…`) against the committed baseline `tests/fixtures/module-boundary-baseline.php`. It runs in the normal PHPUnit gate. The graph is a **ratchet that can only shrink**: a *new* edge fails (new cross-module coupling — justify it or route through a facade); a *removed* edge also fails (coupling eliminated — lock the win in). After an intentional change, regenerate + review the diff: `FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary`. Never regenerate just to make a red guard green without understanding the new edge.

### Test infrastructure

- PHP: PHPUnit 9; tests under `tests/Unit` and `tests/Integration`.
- JS: Vitest 2 with jsdom; tests under `tests/js/*.test.js`. Real jQuery via `jquery/factory` bound to the jsdom window (`tests/js/setup.js`). Scripts under `assets/js/` load via `vm.runInThisContext` so V8 coverage attribution survives (`tests/js/helpers.js`).
- jsdom has no layout — jQuery `:visible` always reports false for shown elements. Assert on `css('display')` instead. Disable jQuery animation queueing in tests by setting `window.$.fx.off = true` in `beforeEach` so `slideUp`/`slideDown`/`fadeOut` apply immediately.
- **pcov coverage-attribution gotcha (PHP).** pcov does not attribute coverage to a class first autoloaded *during* a test method, so a freshly-extracted class can report 0% even when fully exercised — this repeatedly bit the #563 repository/god-class splits. Fix: add `@covers \FQCN` to the test class **and** preload the class with `class_exists( '\\FQCN' )` in `setUp()` (right after `Monkey\setUp()`). Spot-check attribution with `php -d pcov.enabled=1 -d pcov.directory=./includes vendor/bin/phpunit --coverage-clover /tmp/cov.xml --filter <Test>`.
- **`templates/` is outside the coverage scope.** `phpunit.xml` includes only `./includes` for coverage, so extracting inline markup from a god-class into `templates/*.php` partials reduces the class without touching the coverage floor (the F1/F2 lesson). Logic stays in `includes/` (and stays covered); pure markup moves to `templates/`.
- **Running the suite locally.** Install pcov once (`apt-get install -y --no-install-recommends php8.4-pcov`); the full suite is ~10 min. Scope while iterating with `vendor/bin/phpunit --filter <Test>`; `vendor/bin/phpstan analyse --no-progress <path>` and `vendor/bin/phpcs --standard=phpcs.xml.dist -q <files>` (auto-fix with `vendor/bin/phpcbf`) reproduce the PHPStan/WPCS gates.
- **PHPStan/phpdoc idioms.** Put `@phpstan-type` on the **class** docblock (not the file docblock); consumers use `@phpstan-import-type X from Y` on their own class docblock. Avoid `@todo (` and other `@tag (` openings — phpdoc parses the `(` and errors; use prose instead.

### Build & assets

When editing assets that ship to the browser, rebuild the minified bundles so the matching `*.min.*` and `.map` files stay in sync — the `Verify minified assets are up to date` CI job fails otherwise:

- **JS** → `npm run build:js` (regenerates `assets/js/*.min.js` + `.map`).
- **CSS** → `npm run build:css` (regenerates `assets/css/*.min.css` + `.map`).
- Both at once → `npm run build`.

---

## 3. Architecture and patterns

### Repository pattern (Reader/Writer split)

Data-access classes are split read-side vs write-side. Two shapes — pick by whether the repo needs multi-statement transactions:

- **Static repos (the common case).** Reads live in `*Reader`, writes in `*Writer`; both `use \FreeFormCertificate\Core\StaticRepositoryTrait` and return the **same** `cache_group()`, so a write invalidates the caches a read populated. **Callers call `*Reader::` / `*Writer::` directly — there is no façade.** The nine static façades (`CustomField`, `Audience`, `AudienceBooking`, `RecruitmentCall`/`Candidate`/`Notice`/`Adjutancy`/`Reason`, `ReregistrationSubmission`) were retired in #563 B3-A (#594). The row-shape `@phpstan-type` and any public constants (field-type lists, status sets, default colors, …) live **on the Reader** as the canonical home; consumers do `@phpstan-import-type … from …Reader`.
- **Instance repos.** `AppointmentRepository`, `SubmissionRepository`, `UrlShortenerRepository` are kept as thin façades that `extend AbstractRepository`, compose a `*Reader` + `*Writer` in the constructor, and expose the inherited generic CRUD. They are the **transactional aggregate root**: `begin_transaction()` → `FOR UPDATE` read → write → `commit()` run on the one shared global `$wpdb` the façade/reader/writer all bind. **Do NOT retire these into separate call sites** — that coherence is the whole point (deliberate B3-A decision; reiterated in each class's docblock).

**Legacy third shape — single-class instance repos (not a template for new code).** Four older classes `extend AbstractRepository` directly for the inherited generic CRUD + cache helpers (implementing only `get_table_name()` / `get_cache_group()`), with **no** `*Reader`/`*Writer` split and **no** transactions: `CalendarRepository`, `BlockedDateRepository` (`ffc_self_scheduling_*`), `FormRepository` (`wp_posts` metadata), `UserProfileRepository` (`ffc_user_profiles`, extracted from `UserManager`/`UserCreator` in #340). They are neither the static pattern nor transactional aggregate roots — they predate the static-default guidance and are **left as-is** (converting them to static would be pure namespace churn against the module-boundary baseline for zero functional gain). So the `extends AbstractRepository` census is 13 classes = these 4 + the 3 transactional façades + their 6 composed `*Reader`/`*Writer` halves. Don't cite these four as examples of the instance-façade pattern, and don't add new ones — new data-access code still follows the static default below.

When splitting or adding a repo, default to static; reach for the instance-façade only when callers need a read-modify-write transaction. **Test note:** a caller's alias mock that stubs both reads and writes must become **two** alias mocks — one on the `*Reader`, one on the `*Writer` — or the write call hits the real (un-mocked) class.

### Module bootstrap (per-module loaders)

Each feature module exposes a single bootstrap entry point — a `*Loader` class whose `init()` wires the module's runtime classes — so the orchestrator (`Loader::init_plugin()`) touches **one symbol per module** instead of newing-up its internals inline. This keeps `Loader` a thin composition root and narrows the `Root → <module>` dependency surface (#563 B3 coupling reduction). Current loaders: `AdminLoader`, `AudienceLoader`, `RecruitmentLoader`, `UrlShortenerLoader`, `ReregistrationLoader`, `SelfSchedulingLoader`.

Pattern: `init()` runs the module's wiring in its original order, gating admin-only pieces behind `is_admin()`; held-alive instances are kept as `protected` properties (so PHPStan doesn't flag them write-only); fire-and-forget `::init()` / one-shot `new` calls need no property. Pin the wiring with a `*LoaderTest` (overload/alias-mock the wired classes; assert each is constructed / `::init()`-ed) carrying `@covers` + a `class_exists()` pcov-preload in `setUp()`.

**Only extract genuine module bootstrap.** A loader is worth it when the `Root → module` edge is *bootstrap wiring* (instantiating the module's runtime classes). It is NOT worth it when the edge is *orchestrator-level lifecycle* — role/capability registration & migration (`RoleRegistrar` / `CapabilityManager` / `CapabilityMigrator`), cron-event registration, or activation/upgrade `maybe_migrate()` — which legitimately belongs to the orchestrator. Extracting those into a "loader" would be indirection that narrows nothing (the bad-facade trap; see "Repository pattern" for the same principle).

**Documented exception — UserDashboard has no loader (deliberate, B3 phase 2).** Its only bootstrap wiring is `AccessControl::init()` + `UserCleanup::init()` (2 calls, left inline in `Loader`). The bulk of its `Root → UserDashboard` surface is capability/role lifecycle (`RoleRegistrar` / `CapabilityManager` / `CapabilityMigrator`) invoked from `Loader::register_ffc_roles_safe()` / `ensure_*_caps()` — orchestrator responsibility, not module bootstrap. A `UserDashboardLoader` would move 2 lines without shrinking the edge, so it was intentionally skipped.

### Shared-service module directories

A few `includes/` modules are small, single-purpose service buckets whose names are generic enough to invite drift. Keep each scoped to its stated purpose — do NOT let it become a "misc" drawer, since renaming to a crisper namespace later would ripple through the module-boundary baseline, every `use` / `@covers` / alias-mock and the autoloader for only a cosmetic gain (the same namespace-churn cost as any cross-module move). The guard is scope discipline, not a rename:

- **`services/` (`\Services`)** — user-centric query/identity services only (`UserService`, `UserIdentifiersQueryService`). A service that isn't about users belongs in its own domain module, not here.
- **`integrations/` (`\Integrations`)** — adapters to *external* systems (`EmailHandler` → SMTP, `IpGeolocation` → geolocation API). A class with no outbound/external dependency is not an integration.
- **`scheduling/` (`\Scheduling`)** — cross-cutting scheduling-domain services shared by the self-scheduling and audience features (`DateBlockingService`, `WorkingHoursService`, `IcsGenerator`, `SchedulingMailer`). Distinct from the `self-scheduling/` and `audience/` feature modules and from the "Scheduling" admin menu: feature UI/handlers go in those modules; only shared scheduling logic lives here.

### Email architecture (one pipeline — #662)

Every plugin-composed email flows through **one shared pipeline**; never hand-roll a chrome, a transport, or an inline body. When adding or touching an email:

1. **Compose the email body only** (the inner content). Resolve placeholders with `Core\TokenResolver::resolve()` (`{{token}}`) — never hand-rolled `str_replace`.
2. **Wrap in the single configurable chrome**: `EmailHelperTrait::ffc_email_document( $body, array( 'recipient' => $to ) )`. The chrome (header/body/footer/wrapper) is admin-configurable via `Core\EmailTemplateOptions` (the "Email Model" box, Settings → SMTP) and rendered by `templates/emails/layout.php` (table-based, inline styles for Gmail/Outlook). There is exactly **one** chrome — `SchedulingMailer::wrap_html` and the per-email cards were retired.
3. **Send through the chokepoint** `Core\EmailService::send()` (or `EmailHelperTrait::ffc_send_mail()`, which sets `text/html`). Never call `wp_mail()` directly. The global "disable all emails" kill-switch is enforced **inside** `EmailService::send()` — do **not** re-gate it caller-side.
4. **Default bodies live in files**, one per case, under `templates/emails/`. Return-array files (`return array( 'body' => __( … ) )`, loaded via the allowlisted `Core\EmailTemplates::load()` / `::body()`) for token/editable-default bodies; echo partials (via `ffc_render_email_partial()`) for handler-built ones. **Never build email HTML inline in a handler class** — extract it to a `templates/emails/*.php` file.
5. **Editable emails edit the body only, never the chrome.** Use `wp_editor` (TinyMCE) + a "Restore Default Text" button wired by the generic `assets/js/ffc-email-restore-default.js` (`data-editor` + `data-default-key`; default supplied via `wp_localize_script['ffcEmailRestoreDefaults']`).
6. **Surface the P5 notice**: `Core\EmailDisabledNotice::render()` at the top of any admin surface that edits an email.

**When consolidating or auditing, grep every send site** (`EmailService::send` / `ffc_send_mail` / `wp_mail(`) and confirm none bypasses `ffc_email_document` — the #662 audit found emails the roadmap table had missed (a plain-text calendar-deletion cancellation, two admin notifications). WordPress-core emails (`wp_new_user_notification`, password resets) are out of scope — they use WP templates, not our chrome.

---

## 4. Domain conventions

### Date / time storage convention

Two categories. Pick the right one when adding a new column or touching an existing one — see #249 for the migration roadmap that retires the mixed pre-#244 patterns.

#### Category A — **Instants** (a moment in physical time)

Things like "the user submitted this form at X", "the admin called this candidate at Y", "this audit row was written at Z". Use these rules:

- **Schema**: `BIGINT UNSIGNED`.
- **Write**: store `time()` (PHP) — returns UTC unix seconds by construction, independent of `date.timezone` or the WP TZ setting. Never `current_time('mysql')`, which respects WP TZ and produces a string that drifts when the admin changes their site timezone.
- **Read**: pass straight to `DateFormatter::format_datetime($ts)` or `wp_date($fmt, $ts)`. Both apply `wp_timezone()` to render. Changing the WP TZ re-renders correctly with no data migration.
- **Compare**: ints compare directly — `WHERE ts > UNIX_TIMESTAMP(NOW())`, `BETWEEN ? AND ?`, `ORDER BY ts DESC`. No `STR_TO_DATE`, no `FROM_UNIXTIME` in the predicate.
- **PHPDoc**: `@var int Unix UTC timestamp (seconds since epoch).`

Existing examples: the Public Operator Access audit ring buffer (`entry['ts']`) was unix int from day one — that's why the only TZ bug that ever appeared there (#247) was a rendering choice (`gmdate` → `wp_date`), never a storage issue.

##### Category A exception — housekeeping timestamps

`created_at` / `updated_at` columns that are (1) MySQL auto-managed via `DEFAULT CURRENT_TIMESTAMP` (and `ON UPDATE CURRENT_TIMESTAMP`), or (2) PHP-managed but never rendered to end users, stay as DATETIME.

Rationale: these are audit / sort columns only — they never reach a display path that would surface TZ drift. BIGINT UNSIGNED would force PHP responsibility for every INSERT/UPDATE site (MySQL cannot `DEFAULT CURRENT_TIMESTAMP` on BIGINT) with no user-facing benefit.

Current inventory (point-in-time snapshot — re-verify a column against the live schema before relying on its row here):

| Table | Pattern | Notes |
| --- | --- | --- |
| `ffc_reregistration_submissions` | P1 (MySQL auto) | `ORDER BY created_at` in repository |
| `ffc_recruitment_*` (6 tables) | P2 (PHP-managed, `NOT NULL`) | written via `current_time('mysql')` |
| `ffc_audience_*` (5 tables) | P1 (MySQL auto) | — |
| `ffc_rate_limit_*` (3 tables) | P1 (MySQL auto) | — |
| `ffc_custom_fields*` (3 tables) | P1 (MySQL auto) | — |
| `ffc_short_urls` | P2 (PHP-managed, `NOT NULL`) | table name is `ffc_short_urls` (not `ffc_url_shortener`, which is only an option-key/meta-box id prefix) |
| `ffc_self_scheduling_*` | P3 (hybrid: `created_at` auto, `updated_at` PHP) | — |
| `ffc_activity_log` | P2 (PHP-managed, `NOT NULL`) | — |

If a future feature renders one of these columns to a user, that column must be migrated to Category A storage at that point — not left as a hidden TZ-drift trap.

#### Category B — **Wall-clock** (a human commitment, no TZ semantics)

Things like "the appointment is on May 20", "the doctor sees the patient at 09:00". The value means the same thing if the user travels or the server changes TZ — converting to UTC introduces DST/ambiguity bugs.

- **Schema**: `DATE`, `TIME`, or `DATETIME` (the combined form). Stored literally, no conversion at read or write.
- **Write**: store what the user picked — `'2026-05-20'`, `'09:00:00'`.
- **Read**: render via `DateFormatter::format_date()` / `format_time()` / `format_datetime()` directly. `wp_date()` applies the site TZ when given a unix int, but with a `DATE` / `TIME` string source you feed the value as-is.
- **PHPDoc**: `@var string Wall-clock DATE in 'Y-m-d' (no timezone semantics).`

Existing examples: `appointment_date` (DATE), `start_time` / `end_time` (TIME), `date_to_assume` (DATE), `time_to_assume` (TIME).

#### Always

- Display goes through `DateFormatter::format_*()`. No `gmdate()`, no `date_i18n()`, no `wp_date()` outside the helper unless there's a documented reason (e.g. building an iCal `DTSTAMP` per RFC 5545).
- Filenames / log keys / API contracts that need a stable ISO format may use `gmdate('Y-m-d\TH:i:s\Z', $ts)` — but the column those filenames represent should still follow Category A or B.

### Settings reads

Read `ffc_settings` via `FreeFormCertificate\Settings\SettingsReader`, not `get_option('ffc_settings')` directly:

- Use typed accessors when one exists (`SettingsReader::emails_disabled()`, `SettingsReader::activity_log_retention_days()`, etc.).
- Fall back to `SettingsReader::get($key, $default)` for keys without a dedicated typed accessor.
- Use `SettingsReader::all()` when a caller reads 5+ keys (SMTP block, DateFormatter format catalog) and array-style access stays clearer than repeated method calls.

The 14 debug-area toggles continue to be read via `Debug::is_enabled($area)` — that helper has the canonical `function_exists('get_option')` defensive check and is the typed reader for that subset.

Classes that already encapsulate `get_option('ffc_settings')` in their own private helper (e.g. `UrlShortenerService::get_settings()`) do NOT need to migrate — they're already centralized.

### Capability naming

All FFC capabilities follow one grammar (ratified in #488, applied plugin-wide):

```
ffc_<action>_[own_]<domain>[_<qualifier>]
```

- **Actions (closed vocabulary):** `view` (read-only) · `manage` (read-write: create/edit/delete/configure) · `export` · `import` · `edit` (modify existing records — narrower than `manage`) · `delete`. Flow-specific verbs: `book`, `cancel`, `download`, `call`, `bypass`.
- **`own_`** marks a self-scoped end-user cap (frontend; the user's own data).
- **Domains (canonical):** `certificates`, `appointments`, `audiences`, `reregistration`, `custom_fields`, `activity_log`, `settings`, `recruitment`, `url_shortener`, `forms_api`.
- **Qualifiers:** `_pii`, `_settings`, `_reasons`, `_history`, `_smtp`, `_dangerzone`. The last two carve the two most sensitive Settings surfaces out of the blanket `ffc_manage_settings` (#711): `ffc_manage_settings_smtp` gates the SMTP transport + Email Model save, and `ffc_manage_settings_dangerzone` gates every destructive maintenance action (delete-all, cleanups, public-access disabler, submission-link audit, migration execution). A dedicated `ffc_export_activity_log` (export tier) was also split out of the read-only `ffc_view_activity_log` so a view-only operator cannot bulk-extract the audit trail. All three ship with one-shot grant migrations that seed the new cap onto current holders, so no one loses access on upgrade.

**Settings-write auth — WP-standard, no engine (deliberate, #711).** Settings-write authorization uses the **WordPress-standard inline pattern**: native nonce funcs (`wp_verify_nonce` / `check_admin_referer` / `check_ajax_referer`) + the existing capability chokepoint `Capabilities::current_user_can_admin_or()`. Do **not** wrap this in a settings persistence/authorize "engine" — one was tried and removed as net-negative indirection (it fit none of the real writers and only hid the nonce from the WPCS sniff).

#### 3-state permission model

Every admin domain exposes a `view`/`manage` pair so each surface has three states — *não vê* / *só vê* / *vê e edita* — with the WP admin (`manage_options`) above all:

```
canView = current_user_can('manage_options') || view_cap || manage_cap
canEdit = current_user_can('manage_options') || manage_cap
```

A `manage` role does **not** need to also carry the `view` cap — `canView` already includes `manage`. Hidden when neither; read-only render (disabled inputs, no save, row/bulk actions hidden) when only `view`. Use `Capabilities::current_user_can_admin_or($cap)` for inline gates; menu/tab caps take the slug directly (admins hold every FFC admin cap via the activation/`ensure_admin_capabilities` grant).

#### Registry, catalog, migration

- Machine list: `CapabilityManager` (`*_CAPABILITIES` consts + `module_roles_definition()`).
- Human metadata: `CapabilityCatalog::groups()`. **Invariant** (enforced by `CapabilityCatalogTest`): `CapabilityCatalog::all_slugs()` must equal `CapabilityManager::get_all_capabilities()` as a set — adding a cap to one without the other fails CI.
- Renames ship with a one-shot, option-flagged migration that rewrites grants on every user (`user_meta`) **and** every role definition (see `CapabilityMigrator::migrate_taxonomy_renames()` + `Loader::ensure_taxonomy_renamed()`; the one-shot migrations live in `CapabilityMigrator` and role lifecycle in `RoleRegistrar` since #563 Sprint 2). Renames are a **breaking change** for external integrations referencing old slugs — call it out in the CHANGELOG.

### Security & PII conventions

A full security audit confirmed these hold plugin-wide — keep them that way (the #596 IP-hash fix was the only gap found):

- **Output escaping.** Escape every echoed value at the output point (`esc_html` / `esc_attr` / `esc_url` / `esc_textarea`, or `wp_kses_post` for rich HTML). The WPCS `EscapeOutput` gate enforces it; a `phpcs:ignore` must be justified (the value is provably pre-escaped). DB-stored data rendered on a higher-privileged screen is still untrusted — escape it (see the #564 stored-XSS).
- **SQL.** All queries go through `$wpdb->prepare()` with `%d` / `%s` / `%i` (identifiers), `esc_like()` for `LIKE`, and an allowlist (or `sanitize_sql_orderby()`) for any request-derived `ORDER BY` / column. Never interpolate request data into SQL.
- **Request entry points.** Every AJAX / REST / `admin_post` handler that mutates or exposes PII checks **both** a capability (`current_user_can` / `Capabilities::current_user_can_*` / a non-`__return_true` `permission_callback`) **and** a CSRF nonce (`check_ajax_referer` / `wp_verify_nonce` / `check_admin_referer`). Destructive actions take a narrower cap (e.g. `ffc_delete_*`). For per-user data, derive the user from `get_current_user_id()` and gate any `viewAsUserId`-style override on `manage_options` — never trust a request-supplied user/owner id (IDOR).
- **PII at rest & display.** CPF / RF / email are stored via `Encryption` (AES-256-CBC, per-record CSPRNG IV, encrypt-then-HMAC, `hash_equals` verify); searchable copies use a salted hash. Display goes through `DocumentFormatter` **masked** unless a PII cap is held. Tokens use `random_bytes` / `wp_generate_password`, never `rand`/`mt_rand`.
- **Never log raw PII.** Debug logs hash IPs / CPF (`substr( hash( 'sha256', $v ), 0, 16 )`) — see `IpGeolocation` / `PreflightTelemetry` (#596) — and stay behind the off-by-default debug toggles regardless.
- **Outbound HTTP.** Validate any request-derived IP/URL before `wp_remote_*` (e.g. `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` to block SSRF); prefer `wp_safe_redirect` for redirects.

---

## 5. Legacy and tech debt

### Legacy compat shims — audit log

Inventory of the legacy compatibility shims that remain in the code by design (snapshot — re-confirm the location in code before removing; paths and lines change with every refactor, so the table cites files/methods, never line numbers). Removing them requires evidence that no production installation depends on them.

| Shim | Location | Risk if removed | Why it stays |
|------|----------|-----------------|--------------|
| `ensure_legacy_caps_renamed()` v1 | `class-ffc-loader.php` (orchestrator) → `CapabilityMigrator` | Medium | Idempotent + version-flagged via `ffc_legacy_caps_renamed_v1`; dormant after the first `plugins_loaded` post-6.2.0. Zero cost. |
| Cron cleanup pre-4.6.15 | `class-ffc-activator.php` (`deactivate()` / cleanup) | Low | 3× `wp_clear_scheduled_hook`. Sites auto-upgrading across skipped old versions keep orphan crons without it. |
| `count` / `success` / `fail` keys in `get_audit_log_summary()` | `class-ffc-public-csv-download.php` | **High** | Public API contract. External consumers (filters/hooks) may depend on them. Removable only with a ⚠ breaking-change banner. |

When a new feature makes one of these shims unsafe or inadequate, open a specific sub-issue + a breaking-change banner in the CHANGELOG.

#### Gathering the evidence to remove a **High**-risk shim

Not every High shim is provable by data. Before proposing to build a diagnostic, check whether the evidence already exists — the resolved `cpf_rf_encrypted` case below is the exemplar:

- **`count`/`success`/`fail` keys of `get_audit_log_summary()` (still present)** — **not provable by any diagnostic.** It is a public static method (`PublicCsvDownload::get_audit_log_summary()`), with no hook/filter and no DB trace; an external consumer reading `['success']`/`['fail']` is invisible to any read-only query. What can be asserted statically: internally `success`/`fail` have **zero** consumers (the metabox migrated to `access_success`/`failed_access`) and only `count` is still read (by the metabox). So de-risk only via a **versioned deprecation cycle** + a breaking-change banner in the CHANGELOG — never via an evidence scan.
- **Resolved exemplar — `cpf_rf_encrypted` (removed after production read 0 pending).** Its evidence already existed in the UI: the `split_cpf_rf` migration card (Settings → Migrations) shows **Pending** = rows still carrying `cpf_rf_hash` in `ffc_submissions` / `ffc_self_scheduling_appointments` (`CpfRfSplitMigrationStrategy::count_table_status()`; a dropped column ⇒ 100% complete). Once a production install read **0 pending**, the legacy `cpf_rf_encrypted` reads (the PDF appointment fallback + its two REST-controller siblings) had no live dependent and were removed. The equivalence that backed the reading: `Encryption` always writes hash + ciphertext together and the migration nulls `cpf_rf`/`cpf_rf_encrypted`/`cpf_rf_hash` atomically per row, so "pending by `cpf_rf_hash`" ⟺ "pending by `cpf_rf_encrypted`". The lesson: a parallel counter keyed on `cpf_rf_encrypted` would have been redundant indirection — the migration card was already the signal (the façade trap that narrows nothing). *(The combined `cpf_rf` view field is separate legacy and its retirement is tracked apart from the shim.)*
