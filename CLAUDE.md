# CLAUDE.md

Project conventions for Claude (Anthropic CLI / agent sessions) working on this repository. Sessions inherit nothing across cold starts, so the durable rules live here.

**A recurring value across these conventions:** prefer the existing structure over indirection that narrows nothing — churn (renames, façades, per-module "engines", parallel counters/diagnostics) must earn its keep. Where that trade-off bites, the relevant section calls it out as the *bad-façade / namespace-churn trap*.

**Priority when anti-churn and consistency collide:** consistency wins when the inconsistency is *recurring and reader-facing* (every new reader re-pays it); anti-churn wins only when the change is *purely cosmetic* — a rename or reshuffle that removes no confusion. "Earn its keep" is **not** counted in functional gain alone: a persistent inconsistency is a real, compounding cost, not a cosmetic one. This leans consistency-first on purpose, because the project is now mature — low debt, high coverage, the #563-era refactor closed — and churn is cheap on clean, well-tested code, so a consistency fix that disturbs the module-boundary baseline or a namespace is usually worth it. (This is a shift from the refactor era, when churn fought an in-flight baseline and stability rightly came first.)

## Table of contents

1. **[Contributing workflow](#1-contributing-workflow)** — git / PR / release: pull-request workflow, branch naming, develop-branch workflow, versioning, CHANGELOG conventions, what not to do.
2. **[Quality gates and testing](#2-quality-gates-and-testing)** — CI gates (+ coverage floors, module-boundary guard), test infrastructure, build & assets.
3. **[Architecture and patterns](#3-architecture-and-patterns)** — repository pattern, module bootstrap (loaders), shared-service directories, email pipeline, CSV export, captcha, stylesheet architecture, naming and composition, light/dark theme.
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
   - Rename the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] (YYYY-MM-DD)` and add a fresh empty `[Unreleased]` heading above it. The release commit reference is appended to that heading later, at step 6 — **not** here, and not before the tag.
   - Run `npm run build:js` if any JS/CSS in `assets/` changed across the batch and the bundles weren't already rebuilt mid-flight (the "Verify minified assets are up to date" gate would catch this anyway).
3. Auto-merge SQUASH into `main`. The squash commit subject should follow main's convention: `X.Y.Z — <short summary of the batch>`.
4. **Tag the release to publish it.** The GitHub Release + distributable zip are automated — `.github/workflows/release.yml` triggers on pushing a tag matching `v*`: it validates the tag equals the `ffcertificate.php` `Version:` header, builds `ffcertificate-X.Y.Z.zip` (staged via `.distignore`), extracts the `## [X.Y.Z]` section of `CHANGELOG.md` as the release notes, and publishes the GitHub Release. So there is **no manual "create release" step** — after the squash lands on `main`, tag that commit and push:
   ```bash
   git push origin :refs/tags/vX.Y.Z   # only when re-tagging after a bad tag; skip otherwise
   git tag -d vX.Y.Z                   # ditto

   git checkout main && git fetch origin && git pull --ff-only origin main
   test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" \
     && grep -q "Version:            X.Y.Z" ffcertificate.php \
     && git tag vX.Y.Z && git push origin vX.Y.Z \
     || echo "STOPPED: HEAD=$(git rev-parse HEAD) main=$(git rev-parse origin/main) / $(grep 'Version:' ffcertificate.php)"
   ```
   **The two checks are chained with `&&` because a check that only prints does not stop anything, and that is not a hypothetical.** The tag has landed on the wrong commit TWICE, and the second time a written-down guard was already in place.

   For 6.23.0 the tag was pushed within a minute of the merge, onto a local `main` the `pull` had not yet advanced, so it marked the *previous* release's squash — `Version: 6.22.0` under a tag saying `6.23.0`. That produced the guard.

   For 6.24.0 the guard existed and was followed, and the tag still landed on 6.22.0's squash. The `pull --ff-only` had ABORTED on a dirty `package-lock.json`, leaving HEAD three releases back; `git rev-parse --short HEAD` and the `grep` both printed the wrong values, exactly as designed — and then `git tag` ran anyway, because the whole recipe was pasted as one block and printing is not refusing. **The lesson is not "check": it is that the check must be able to interrupt.**

   Two mechanics the recovery taught, both easy to get wrong a second time:

   - **Compare against `origin/main`, never a literal SHA.** The first repair attempt hard-coded a 7-character SHA against a `git rev-parse --short` that returned 8 — `--short` uses the shortest *unambiguous* length, which grows with the repository — so a correct HEAD was rejected. The comparison above states the real invariant: *this is the commit that is `main`'s tip, and it declares the version being tagged*.
   - **`git fetch --tags` does not move a local tag that already exists.** After deleting and re-pushing the tag, a local checkout keeps pointing at the bad commit and will happily confirm the wrong answer. Read `git ls-remote origin refs/tags/vX.Y.Z`, or fetch with `--force`.

   The workflow's own version check is a backstop and it did fire both times: nothing was published, so there was no wrong zip or Release to retract. But recovering still means deleting a published tag (the first two lines above) rather than never creating a bad one.

   The tagged commit's `Version:` header must already equal `X.Y.Z` (true post-bump) or the workflow's sanity check fails. **That check is a backstop, not the guard** — it fires after the tag is already public. It failing is the good outcome (nothing is published, so there is no wrong zip or Release to retract), but the tag still has to be deleted and re-pushed. The tagged tree will **not** carry its own CHANGELOG short-SHA suffix, and that is expected — see step 6. Pushing the tag publishes a production release (public zip + Release notes), so **an agent surfaces this sequence for the user to run rather than pushing the tag itself** — the same production-deploy sign-off that keeps the `develop → main` PR draft until the user confirms.
5. After merge, **sync `develop` with `main`** (see Sync below) so the next batch starts from the bumped baseline. After a *release* this is a hard `reset`, **not** a rebase — the squash already contains every develop commit, so a rebase tries to replay them all and conflicts.
6. **Backfill the release commit reference onto the CHANGELOG heading.** Append `` — `<short-sha>` `` (the 7-char short SHA of the squash commit on `main`) to the `## [X.Y.Z]` heading, matching every other shipped version header. It lands on the **post-release `develop`** through a normal PR, and it must come **after** step 5: the sync is a hard reset to `main`, so a backfill landing before it is silently discarded — the order #1004 and `037001f` followed after 6.20.1 and 6.20.0. The consequence is structural, not a mistake to fix: the tag pushed at step 4 never carries its own suffix, and `main` acquires it one release later, when this commit rides the next release PR. So a missing suffix on the **newest** shipped heading is normal; a missing suffix on an **older** one means the backfill was genuinely skipped (as for 6.11.1 / 6.11.2, fixed retroactively).

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

#### Post-deploy smoke (alarm, not a gate)

After the rsync, `deploy-develop.yml` scp's `.github/scripts/testes-smoke.php` to the host, runs it over SSH and deletes it. It boots the real WordPress and asserts three things, then reports the scheduled `ffc*` crons without judging them (which crons should exist depends on the per-module toggles, so a strict expectation would fail on a deliberate configuration):

1. **The plugin is active** — a deploy that lands files onto a deactivated plugin looks healthy from the filesystem and does nothing.
2. **The host's live `FFC_VERSION` equals the version in the commit being deployed.** This is the #628 check: the rsync backoff was once shorter than a managed-hosting restart, so every attempt fell inside the same outage and the site quietly stayed on a stale version while the workflow reported success.
3. **Every table `uninstall.php` knows about exists.** The expected list is *parsed out of `uninstall.php`* rather than duplicated — that file is already obliged to know every table, so a new one is covered the moment it is added there. It is read as text and never included (including it would run the uninstaller). If the parse yields nothing the smoke **fails loudly** instead of passing on an empty list.

This exists because the host is the only place with the provider's real MariaDB, PHP SAPI and wp-config — the stack that produced the dbDelta failures CI cannot reproduce (#358 backtick comments read as columns, #822 a migration pointing at a table that never existed, 6.0.1 `COMMENT` clauses leaving four recruitment tables uncreated).

Two constraints to keep in mind before extending it. **It can never be a merge gate** — this workflow fires on push to `develop`, so the merge already happened; gating belongs in CI, against a MySQL service. And it runs against an **established install**, so the table check proves the tables are there, not that a fresh activation would create them; catching a regression in the `CREATE` of an existing table needs a throwaway *WordPress install* (not merely an empty database — `Activator::activate()` reads and writes options). That is the `fresh-install` CI job (see §2, "Fresh-install gate"), delivered in #994.

**The step blocks: a red smoke is a red deploy job.** It ran under `continue-on-error: true` while it proved itself against the host's PHP CLI, and #992 set the bar at 10 consecutive green smokes — an evidence gate, not the release-cycle deprecation, which exists for surfaces whose consumers a code scan cannot see; here every deploy is evidence. Runs 525-537 delivered 13, verified from the log output rather than the step conclusion, and the flag came off in #1007. Two exits stay green on purpose. A **timeout** (124) is a warning, not a failure: the rsync has already succeeded, so a host that does not answer in 120s is latency, not a plugin defect, and reddening deploys for it is how an alarm becomes noise people learn to skip. And a host whose CLI is older than the plugin's PHP floor **skips** with an explanation rather than failing forever — point the workflow at the host's newer binary (often `php83`) to enable it. Everything else — a stale version, a missing table, a fatal in the plugin — now fails the job. The reason the evidence had to come from the log is worth keeping: while the flag was on, a *failing* smoke still showed the deploy job as green, so the job conclusion proved nothing and only the step's own `SMOKE PASSED` line did.

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
- **Aim for ≤300 characters per bullet.** A *soft* target, not a CI gate — a bullet may exceed it when the detail genuinely earns the length (a breaking-change banner, a subtle regression), but prefer trimming first: the linked PR holds the granular detail, so the CHANGELOG line only needs the *what* + the reference. When a batch accumulates several long or near-duplicate bullets, condense before the release PR (the precedent that set this: the #772-era `[Unreleased]` condensation).

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
- Misc: CodeQL (javascript) · Composer audit · Review dependency changes · Verify minified assets are up to date · **Fresh install (PHP 8.3)** — see below.

The same gates run on PRs to `develop` and on the release PR `develop → main`. Develop must stay deployable to the testes site, so we don't relax gates there — a green develop is the precondition for opening the next release PR.

The coverage floors are ratcheted upward in the PR that delivers the gain — never lowered. The comment block above each `*_FLOOR_LINES` keeps the audit trail.

**One exception — code deletion.** Removing well-covered **product** code (never tests) can legitimately drop the line-% because high-coverage lines left the denominator — that is not a regression to restore. When a deletion PR lowers the measured coverage, an honest re-measure of the floor down to the new figure is allowed, provided the `*_FLOOR_LINES` comment block records the deleting PR and the new baseline. This is the *only* case the floor may move down; deleting or weakening tests to relax it never qualifies.

**Acceptable floor buffer:** when bumping, the floor may sit up to **5 percentage points** below the freshly measured coverage. A buffer of ≤5pp is acceptable for both JS (`JS_COVERAGE_FLOOR_LINES`) and PHP (`COVERAGE_FLOOR_LINES`) — it absorbs v8/clover run-to-run jitter so the gate doesn't flake on fractional swings, without forcing the floor to chase every decimal. So: still ratchet up when a PR delivers a real gain, but leave no more than ~5pp on the table, and never set the floor *above* the lowest run you've actually observed.

**Module-boundary guard (#563 B3).** `tests/Unit/ModuleBoundaryTest.php` freezes the cross-module dependency graph of `includes/` (a "module" = the first namespace segment after `FreeFormCertificate\`; an edge = module A referencing `FreeFormCertificate\B\…`) against the committed baseline `tests/fixtures/module-boundary-baseline.php`. It runs in the normal PHPUnit gate. The graph is a **ratchet that can only shrink**: a *new* edge fails (new cross-module coupling — justify it or route through a facade); a *removed* edge also fails (coupling eliminated — lock the win in). After an intentional change, regenerate + review the diff: `FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary`. Never regenerate just to make a red guard green without understanding the new edge.

**Fresh-install gate (#994).** `.github/workflows/ci.yml` → job `fresh-install` installs a throwaway WordPress onto a MariaDB service, activates the plugin into an **empty** database, and runs `.github/scripts/fresh-install-check.php`. It is the only gate that sees a *fresh activation*: the post-deploy smoke runs against the established testes install, where every table already exists from an earlier release, so a `CREATE` that stopped working is invisible there. That is the 6.0.1 class — and it was live again when this job was written (`ffc_self_scheduling_calendars` was never created on any fresh install, because `dbDelta()` splits its input on `;` and an SQL comment inside the statement carried one).

A fresh install also makes a check possible that no established install can make honestly: the `ffc_*` tables and options in the database are **exactly** what this activation created, with no legacy residue to explain away. So the comparison against `uninstall.php` runs **both ways** — every declared object must exist, and every existing object must be declared. The second direction found two tables and 21 activation-written options that deletion had never removed; most are written through a constant or a variable, so no static scan could have seen them. The job then deletes the plugin with the Danger Zone opt-in on and asserts the footprint is zero, which makes `uninstall.php` an **enforced manifest** rather than a list nothing checks.

Three things to know before touching it. **`uninstall.php` is the single manifest** — `.github/scripts/ffc-uninstall-manifest.php` parses it as text (never includes it) and both this job and the post-deploy smoke read it, so the two can't disagree about the footprint; adding a table or option to `uninstall.php` is what covers it everywhere. **The MariaDB service image is a deliberate pin** — the point is to reproduce the provider's dbDelta behaviour, so the smoke prints the host's real `VERSION()` and `@@sql_mode` on every deploy and the pin moves when those drift. It was pinned to `10.11` on a guess and corrected to `11.8` the moment the first smoke reported the host's actual `11.8.8-MariaDB-log` (whose `sql_mode` also lacks `ERROR_FOR_DIVISION_BY_ZERO`) — read the smoke, don't pick a familiar LTS. **It is not a substitute for the smoke**: the runner's MariaDB is not the host's, and only the host has the provider's PHP SAPI and wp-config. The cheap half of the same defect class runs with no database at all in `tests/Unit/ActivatorSqlTest.php`, which enforces two `dbDelta` rules on every `CREATE TABLE` literal: no semicolon before the final one (it splits its input on `;`, truncating the statement), and every line a column or key definition — a blank line or an SQL comment is read as a column and becomes a malformed `ALTER` on every run against an existing table. Measured against a real MariaDB, the three self-scheduling tables produced **41** database errors before that cleanup and 1 after (#997); the survivor is a `Duplicate key name 'validation_code'`, where the CREATE declares a plain `KEY` that `ensure_unique_validation_code_index()` later converts to `UNIQUE`. All of it was latent — every affected activator guards its `dbDelta` call on `table_exists()` — and would fire the day someone adds a column the normal WordPress way.

**dbDelta idempotence gate (#1087).** A step in the `fresh-install` job replays every `CREATE TABLE` through `dbDelta()` against the table it just created from that very statement. `dbDelta` ALTERs away the difference between a statement and what the server stored, so when the two disagree in a way the statement can never satisfy it re-ALTERs on every run that reaches it — the #997 class, which had been measured exactly once, by hand. Nothing else sees it: `ActivatorSqlTest` reads the statement as text with no database, the rest of `fresh-install` only ever watches the CREATE succeed, and the post-deploy smoke asserts the tables exist, not that the schema still matches. The statements come from `.github/scripts/ffc-create-statements.php`, shared with `ActivatorSqlTest` so the two cannot measure different sets, and the gate aborts on an empty scan, a count that diverges from a wider net, or a table name it cannot resolve — it never counts as clean what it did not look at.

**It blocks at zero.** The first measurement found 14 statements drifting and all 14 were fixed, so the frozen baseline that carried them in the meantime is gone — a change the gate reports is a change to fix, never a line to add somewhere. **Diagnosis needs the database, which only that job has**, so on failure the gate dumps `SHOW CREATE TABLE` for the statements whose disagreement is not a type spelling; there is no way to reproduce it locally.

Three things the first measurement taught, all of them the opposite of what they look like. **Write the display width.** `int unsigned` looks cleaner and is wrong here: MariaDB stores `int(10) unsigned` and compares textually, while WP core's `dbDelta` ignores a width-only difference on MySQL 8.0.17+ and *explicitly not on MariaDB*. So `int(10)` is correct on both servers, and it is what WP core writes in its own schema — do not "tidy" it away. **`json` is not stored by MariaDB**, which implements it as `LONGTEXT` plus a CHECK, so a `json` column can never match there. The ten such columns are declared `longtext` (#1087 passo 9) because nothing in the plugin uses a native JSON function — every read is `json_decode` in PHP, and `PreflightStatsService` already aggregates in memory rather than depend on `JSON_EXTRACT`; declaring the intent bought validation the code never relied on, at the cost of a statement that could never be honest. **Declare the index the code actually creates:** `Activator::upgrade_auth_code_unique_constraints()` converts `auth_code` to `UNIQUE INDEX uq_auth_code` on two tables, so a declared plain `KEY auth_code` never survives — the same shape as `validation_code` in the self-scheduling tables.

**Schema-agreement guard (#1087).** `tests/Unit/SchemaAgreementTest.php` covers the two shapes schema duplication takes here, without a database. **Within one file:** a `CREATE TABLE` and an `add_columns_if_missing()` call must name the same columns — a fresh install gets the first, an upgraded install the second, and `ffc_submissions` was born with 7 of its 25 columns until #1091 because nothing compared them. **Across files:** three tables are declared by more than one class (`ffc_custom_fields` ×3, `ffc_reregistration_submissions` ×3, `ffc_reregistrations` ×2, because the activators were written after the migrations that first created them and neither side was retired), and every declaration must name the same columns **and** the same key names. That second direction found the third occurrence of the #1091 class — `UserDashboardActivator` declared 12 of `ffc_custom_fields`'s 17 columns, and a fresh install came out right only because a one-shot migration ran later in the same activation. It also found why installs carry two indexes on `auth_code` and two on `magic_token`: both paths indexed the same columns under different names. It compares **names, not types** (the sources spell types differently by construction) and reads the statements through `.github/scripts/ffc-create-statements.php`, the same parser the dbDelta gate uses.

**Vacuous-test ratchet (#997).** `tests/Unit/AssertionCoverageTest.php` fails when a *new* test method verifies nothing — no Mockery/Brain\Monkey expectation and no assertion beyond a literal `assertTrue( true )`. The 108 existing cases are frozen in `tests/fixtures/vacuous-tests-baseline.php` (95 after #997 stage 2 fixed the 13 in schema/activation and security), a ratchet that can only shrink: a new entry fails (write a real assertion), and a removed one also fails (a test was fixed — lock the win in). Regenerate after an intentional change with `FFC_UPDATE_VACUOUS_BASELINE=1 vendor/bin/phpunit --filter AssertionCoverage` and review the diff.

Two things to know. **A checker that only looks for `$this->assert*` reports most of this suite as vacuous** — `Functions\expect( 'wp_mail' )->once()` and `shouldNotReceive()` are real assertions, so the guard's expectation list must stay in sync with the idioms in use. **It sees presence, never strength**: a test asserting the wrong thing, or asserting against a mock that supplies the very value under test, passes it. That class is only reachable by reading the test or by crossing the boundary it mocks — which is what the fresh-install gate does for schema, and what the remaining stages of #997 cover elsewhere. The baseline is a debt register, not a target to grow.

**AJAX wiring guard.** `tests/Unit/AjaxWiringTest.php` cross-checks, statically and without WordPress, that every registered `wp_ajax_*` handler is referenced somewhere other than its own registrar, that every `action` the client asks for is registered (on `wp_ajax_*`, `admin_post_*` or `admin_action_*`), and that every `get_option( 'ffc_*' )` key has a write path. It targets the "built but never wired" defect class — the ten caller-less handlers swept in #935, and the #936 read of `ffc_cleanup_days`, an option nothing ever wrote, which left submission auto-delete dormant for every install.

Two things to know before touching it. **Registration has two idioms** — the literal `add_action( 'wp_ajax_ffc_x', … )` and the endpoint-class `add_action( 'wp_ajax_' . self::AJAX_ACTION, … )`; both are resolved, and a checker that knows only the first reports every `*AjaxEndpoint` action as unregistered. **The client names an action in at least six shapes** — a `FFC.request` argument, an `action:` key, an `'action=…'` query fragment, a `FormData.append` pair, a hidden input's `value`, and a `wp_localize_script` payload carrying the constant (never a literal). Direction A therefore matches the *bare token*, deliberately loosely: matching shapes exactly reported all six as orphans. Direction B matches precise request positions, because it compares against the registered set and a loose match there would flag nonce names and capability slugs.

It proves only that both ends of the wire exist and agree on a name — not that the handler is reached from the right screen, that its button renders, or that the request succeeds. Those need a real WordPress and a browser. Exceptions go in the `KNOWN_*` allowlists **with the reason inline**; an action that merely lost its caller does not belong there — delete the handler.

**CSS namespace ratchet (#1152).** `tests/Unit/CssNamespaceAnchorTest.php` freezes the selectors in `assets/css/*.css` that name nothing the plugin owns — **60 of 2,424**, listed per sheet with their occurrence count, a ratchet that only shrinks: a new anchorless selector fails, and a baselined one that gained an anchor also fails (drop it from the list to lock the win in). `:root` is the one allowlisted entry, with its reason: it is *how* a custom property is declared, and every property under it is `--ffc-*`.

**It is the only guard in the theme arc that is prevention rather than a defect already shipped** — nothing is broken, no collision observed. A rule like `.button::before { content: '\f123' }` reaches every button on the screen, not only ours; the radius is small today because the sheet loads on two pages, and that is **enqueue luck, not design**. `AudienceAdminPage::print_menu_separator_css()` exists precisely because one rule already had to leave `ffc-audience-admin.css` when its target started appearing on screens where that sheet does not load.

Four things the measurement taught. **Decide the anchor rule before measuring, and mind the token boundary**: `a[href^="#ffc-separator-"]` is anchored — it cannot match anything that is not ours — but the character before `ffc` there is `#`, so `(?:^|[-_])ffc[-_]` drops seven selectors and reports 67 instead of 60; the number then describes the pattern rather than the CSS. **Count selectors, not classes**: in `.appointment-status.status-pending` the second class never appears alone, so the compound exposes *one* name — counting classes reported eleven where there was one. **The scanner has to be quote-aware and skip `@keyframes`**: `img[src^="data:image/png;base64"]` carries a `;` that cuts the selector in half otherwise, and `0%` / `from` are steps, not selectors. And the issue's own recorded figure was **40**, not 60 — it had missed `.button*`, `.card`/`code`, `.form-table*` and the `#tab-*` ids, so re-measure rather than trust the number written down.

What it does not see: inline CSS printed from PHP (`print_menu_separator_css()`, the appointment receipt) and `style=""` attributes — it reads the sheets only. Nor duplication: the same scan found **19 `ffc-*` classes declared bare in more than one sheet** (`.ffc-status-badge` in five), which is a component without a single owner, not a namespace problem, and is tracked apart in #1162.

**Component-ownership guard (#1162).** `tests/Unit/StylesheetOwnershipTest.php` fails when a class is declared **bare** — `.ffc-x { }`, no ancestor and no second class — in two sheets with **no dependency edge between them**, in either direction, direct or transitive. Without that edge the winner is enqueue order, which is the order modules are wired in `Loader`: moving two bootstrap lines repaints a screen. Pairs that cannot coexist on one screen (admin × frontend, distinct admin screens) go in `ALLOWED` with the reason, because that impossibility lives in the *enqueue gates*, which this scan does not read.

**It was not prevention — the defect was live.** `.ffc-status-cancelled` was declared by `ffc-calendar-admin.css` (red, `--ffc-danger-*`) and by `ffc-audience-admin.css` (amber, `--ffc-warning-*`). Appointments is a **submenu of `ffc-scheduling`**, so the audience sheet's gate (`strpos( $hook, 'ffc-scheduling' )`) matches there too and both load; `AudienceLoader` is wired after `SelfSchedulingLoader`, so amber won. In the Status column "Cancelled" rendered amber while its four siblings rendered as the module's own sheet asked. Both families were given their own names — `ffc-appointment-status-*` and `ffc-audience-status-*` — in the #1151 / #1154 mould.

Three things the measurement taught. **`ffc-admin-submissions.css` loads on every `?page=ffc-*` screen**, not only the submissions one: its gate is `is_ffc_page()`, which matches any `ffc-` menu, while the enqueuer's own docblock says "submissions page". That is why its `.ffc-status-badge` still collides with recruitment's and reregistration's — the two entries left in the baseline. **The result is a MERGE, not "the later one wins":** the audience badge was rendering `text-transform` and `letter-spacing` that only the submissions sheet declares, and `white-space: nowrap` that only `ffc-common.css` declares — three properties absent from the component's own sheet, so neither "last wins" nor "first applies" describes what shipped. The rename declares the whole shape on purpose, which keeps the render identical and makes it intentional. And **one handle can be enqueued with different dependency lists at different sites** (`ffc-admin-settings` is one; WordPress keeps whichever registered first) — the scan unions them deliberately, because what matters here is whether the edge is declared *anywhere*.

Two mechanics for anyone extending it. The extractor must read **top-level arguments**, not `explode( ',', … )`: `plugins_url( "…", dirname( __DIR__, 1 ) )` carries an inner comma that cuts the list in the wrong place and drops `ffc-calendar-admin.css` from the graph entirely — and it resolves `self::HANDLE_CSS` against the file's own constants, without which `ffc-recruitment-admin.css` disappears the same way. `test_every_stylesheet_is_reachable_through_an_enqueue()` is what stops either from passing as "clean"; `ffc-appointment-cancellation.css` is the one sheet with no handle, because its handler prints the `<link>`s itself in explicit order. The CSS parser is shared with the #1152 guard through `tests/Support/CssSelectors.php`, for the same reason `.github/scripts/ffc-create-statements.php` is shared: two guards measuring the same sheets must not disagree about what a selector is.

**Suppression guards (#1027, #1035).** Two guards cover the annotations that switch a gate off. `tests/Unit/PhpstanSuppressionTest.php` fails on a `@phpstan-ignore-next-line` (which suppresses *every* error on the line — the identifier after it is only a comment; only `@phpstan-ignore <id>` filters), on one naming no identifier, and on one with no reason. `tests/Unit/PhpcsSuppressionTest.php` fails on a bare annotation, one with no `-- reason` (or one under 15 characters — a floor against "ok"/"see above.", never a quality bar), and on the two structural defects below.

**PHPCS annotations are a flat on/off switch per sniff, not a stack.** A `phpcs:enable X` anywhere in a file turns X back on for the rest of it *no matter who turned it off* — so an inner disable/enable pair silently ends an enclosing file-level `disable` at the `enable`, leaving the tail of the file uncovered while the file-level comment still claims otherwise. This was live in four classes and nothing was red, because each tail stayed covered by whatever the inner pairs covered; it is only reachable by reading, or by the guard that now checks it. When an inner pair names a sniff an outer disable already covers, drop it from the inner pair.

**`WordPress.DB.DirectDatabaseQuery` is disabled at file level in ~50 classes, and that is scope-gated.** The repositories, activators and migrations query the plugin's own `ffc_*` tables, for which WordPress exposes no API, so the justification is a property of the class and one file-level disable beats one annotation per statement (#1035 collapsed 375 of them into 49). The disable is only honest while *every* table the file touches is `ffc_*` — `test_file_level_direct_query_disables_only_cover_plugin_tables()` enforces exactly that, so adding a `wp_posts`/`wp_users`/`wp_usermeta` query to one of those files fails CI. Fix it by using the WP API or by narrowing to per-line annotations; the `CORE_TABLE_EXCEPTIONS` allowlist is for a reference that is unavoidable (`MigrationForeignKeys` must name `wp_users` — its foreign keys point there) and takes the reason inline.

**Both guards see presence, never truth, and neither sees deadness.** A wrong reason passes; a suppression a refactor made inert passes. Deadness is *measured*, not asserted, and the measurement is deliberately out of CI (it costs a full re-run over a rewritten tree): **neutralise** every annotation in place — rewrite the sniff it names to one that cannot fire, **never** rename the `phpcs:`/`@phpstan-` token itself, which turns the directive into an ordinary comment and trips `Squiz.Commenting.InlineComment` on hundreds of lines that were fine — then re-run the tool and map the violations that surface back to the annotations covering them. That produced the 333 removals in #1031 and another 361 in #1035; re-run it when the population grows again, and verify by the gate, never by the classifier.

Three parsing facts the audit learned the hard way, for anyone writing tooling over these. An own-line `phpcs:ignore` covers **its own line and the next one**, not only the next. A `//` comment ends at `?>`, so a sniff list must stop there (and at `*/`) or the close tag is read as a sniff name — and a rewrite that eats it breaks every inline-HTML file. And ~33 annotations are the **trailing form, on the same line as code** (`$result = $wpdb->get_row( // phpcs:ignore …`): deleting the line deletes the statement, which is how one pass produced a parse error and 50 violations. Prose that merely mentions the token inside a docblock sentence is not a directive — only a token that opens its comment counts.

**Config-level exclusions — audited, and mostly correct (#1035 follow-up).** A rule switched off in `phpcs.xml.dist`, `.stylelintrc.json`, `phpstan.neon.dist` or `phpunit.xml.dist` is a `phpcs:disable` with repo-wide blast radius, so the same measurement was run over all four. The inline annotations are the audited part; these are recorded here so the verdicts are not re-litigated.

**`Generic.CodeAnalysis.UnusedFunctionParameter` stays off — measured, not assumed.** Enabling it reports 43, of which **33 are structural false positives**: 14 are parameters consumed by a `templates/*.php` partial the method `include`s (the sniff cannot see across an include — and extracting markup to `templates/` is this project's own convention, so the architecture *creates* this class), and 19 are signatures fixed by their caller (WP hook callbacks taking `$atts` / `$hook` / `$update` / `$request`, a guard interface's `$ctx`). Turning it on would mean 33 new annotations — exactly the churn #1035 removed. The 10 genuine ones were read and fixed in the same pass; re-run it as a one-off if the population grows, never as a gate.

**`Squiz.PHP.CommentedOutCode` stays off.** All 9 findings are false positives: the heuristic scores an explanatory comment as code when it quotes an enum (`// 'daily' or 'span'`), shows a shape (`// [{day: 0-6, …}]`) or names a token (`// Remove {{ and }}`). Zero real commented-out code.

**Stylelint: `declaration-block-no-shorthand-property-overrides` and `declaration-block-no-redundant-longhand-properties` are ON** (both measured 0 findings, so they cost nothing and guard a real bug class — a shorthand silently wiping an earlier longhand). `no-descending-specificity` (92 findings) and `no-duplicate-selectors` stay off; the 4 duplicate-selector hits are all deliberate section splits — a token block and a layout block under the same selector, each with its own comment — where merging would destroy the intent.

**The `views/` carve-out is markup-only, and the two lists must stay in step.** `phpstan.neon.dist` (`excludePaths`) and `phpunit.xml.dist` (`<coverage><exclude>`) both skip `includes/admin/views` and `includes/settings/views` — 8.6k lines with zero `$wpdb`, `update_option`, nonce or capability occurrences, i.e. the same rationale as `templates/` above. **`includes/self-scheduling/views` is deliberately NOT excluded** — it holds real logic (capability gates, `RequestInput` reads, list-table wiring), so "a directory named `views` is markup" is *not* a repo-wide truth; judge by content. Two further paths, `includes/views` and `includes/libraries`, were listed for years and have never existed in the history — removed, since PHPStan's `(?)` optional-path suffix meant nothing ever reported them.

### Test infrastructure

- PHP: PHPUnit 9; tests under `tests/Unit` and `tests/Integration`.
- JS: Vitest 2 with jsdom; tests under `tests/js/*.test.js`. Real jQuery via `jquery/factory` bound to the jsdom window (`tests/js/setup.js`). Scripts under `assets/js/` load via `vm.runInThisContext` so V8 coverage attribution survives (`tests/js/helpers.js`).
- jsdom has no layout — jQuery `:visible` always reports false for shown elements. Assert on `css('display')` instead. Disable jQuery animation queueing in tests by setting `window.$.fx.off = true` in `beforeEach` so `slideUp`/`slideDown`/`fadeOut` apply immediately.
- **pcov coverage-attribution gotcha (PHP).** pcov does not attribute coverage to a class first autoloaded *during* a test method, so a freshly-extracted class can report 0% even when fully exercised — this repeatedly bit the #563 repository/god-class splits. Fix: add `@covers \FQCN` to the test class **and** preload the class with `class_exists( '\\FQCN' )` in `setUp()` (right after `Monkey\setUp()`). Spot-check attribution with `php -d pcov.enabled=1 -d pcov.directory=./includes vendor/bin/phpunit --coverage-clover /tmp/cov.xml --filter <Test>`.
- **A `function_exists()` guard makes tests order-dependent, and the symptom is remote.** `LabelSorter::locale()` guards on `function_exists( 'get_locale' )`, so whether it takes the WordPress branch or its `en_US` fallback depends on whether *any earlier test in the process* stubbed that function — Brain\Monkey defines it via Patchwork, and it stays defined afterwards. Adding a `get_locale` stub to one test class therefore breaks unrelated classes that run later, with `"get_locale" is not defined nor mocked in this test` pointing at a file the PR never touched. Both times this happened (#1053), the fix was to stub the function explicitly in every test that reaches the guarded code, so the branch is *chosen* rather than inherited. **Defining it in `tests/bootstrap.php` does not work** — Patchwork cannot redefine a function declared in an uninstrumented file, so every `Functions\when()` for it starts throwing instead. When you add a stub for a WP function that some `function_exists` guard checks, expect the blast radius to be "every test after this one, alphabetically".
- **`templates/` is outside the coverage scope.** `phpunit.xml` includes only `./includes` for coverage, so extracting inline markup from a god-class into `templates/*.php` partials reduces the class without touching the coverage floor (the F1/F2 lesson). Logic stays in `includes/` (and stays covered); pure markup moves to `templates/`.
- **`tests/` is tab-indented, and that is enforced.** `phpcs.xml.dist` excludes `tests/` on purpose — `WordPress-Docs` would demand a file, class and method docblock in all 420 files. Indentation is the one exception: `phpcs-tests.xml.dist` carries exactly two sniffs (`DisallowSpaceIndent`, `ScopeIndent`) and runs over the whole directory in the PHPCS workflow. The suite was 250 space-indented files against 170 tab-indented ones with nothing enforcing either, so a one-off reformat would have drifted back; the gate is what makes the reindent worth doing. Do not widen that ruleset — line length, docblocks, naming and Yoda conditions are how it stops being cheap. Two mechanics to know. The ruleset **must** set `<arg name="tab-width" value="4"/>`: without it PHPCS measures a tab as one column and reports every tab-indented line as `expected 1 tabs, found 1 spaces` — 105,985 errors, the gate failing the files it exists to approve. The main ruleset never needed it explicitly because WPCS sets it inside `WordPress-Core`; this one references only `Generic` sniffs. And the reindent commit is listed in **`.git-blame-ignore-revs`**, so `git blame` still names whoever wrote a test rather than the reformat — GitHub honours that file automatically, locally it is `git config blame.ignoreRevsFile .git-blame-ignore-revs` once per clone. Add a commit there only when it is genuinely whitespace-only.
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

### CSV export architecture (one contract, two adapters — #772)

Every plugin CSV export flows through **one source contract with two delivery adapters**; never hand-roll `header()` / `fopen('php://output')` / `exit`, an AJAX job loop, or a temp-file scheme. A **source** owns only its domain specifics (auth, columns, row formatting, the query); the Core adapters own the delivery lifecycle.

**The contract (Core).** A source implements exactly one of two interfaces, both in `includes/core/`:

- **`SyncSourceInterface`** — bounded, provably-small output. Methods: `authorize()` · `filename()` · `header()` · `rows(): iterable`. Delivered by **`Core\SyncCsvExport`** → `Core\CsvStreamer` → `Core\HttpCsvDownload` (streams straight to the browser in one request; `CsvStreamer` is the **synchronous-delivery adapter** — pure orchestration over the injectable `CsvDownloadInterface`, so it is unit-testable with a buffered double). Use for outputs whose size is bounded by construction (audit-log ring buffer, static example/sample templates, the audience import/export whose dedup+hierarchy don't survive a cursor).
- **`BatchedExportSourceInterface`** — timeout-safe for datasets of unknown/large size on shared hosts (where `set_time_limit()` is blocked). 16 methods: per-phase `authorize_start`/`authorize_batch`/`authorize_download`, `job_owner_fields`, `sanitize_filters`, `count`, `build_context`, `header`, `filename`, `fetch_page(cursor, size)`, `cursor_of`, `format_row`, `extra_start_response`, `on_complete`, `on_before_download`. Delivered by **`Core\BatchedCsvExport`** (start → batch×N → download over a temp file + transient job). Use for every **data** export (submissions, public forms, url-shortener, activity-log, audience-bookings, appointments, reregistration).

**Registry + dispatcher (dependency inversion).** Core owns the interfaces, the two engines, **`Core\SourceRegistry`** (type → lazy factory) and **`Core\BatchedExportDispatcher`** (one AJAX trio `ffc_export_start` / `ffc_export_batch` / `ffc_export_download`, priv **and** nopriv, routing by the `type` field to the registered source, which owns its own `authorize_*`). The concrete sources live in the **feature modules** and register a factory at bootstrap (`SourceRegistry::register('audience_bookings', fn() => new AudienceBookingExportSource())`). The engine/dispatcher never reference a feature class → **no `Core → feature` edge** (the ` → Core` edges already exist), so `ModuleBoundaryTest` stays green without regenerating the baseline.

**When adding or touching a CSV export:**

1. **Pick the adapter by cardinality:** bounded/static → `SyncSourceInterface`; unknown-size **data** → `BatchedExportSourceInterface`. When in doubt for a data export, batched.
2. **Register the source from the module's bootstrap** under `is_admin()` (true on admin-ajax, so the source is reachable during the export job) — in the module `*Loader`, or a class the module always constructs on admin requests. Never register from an `admin_menu`-hooked method: `admin_menu` does **not** fire on `admin-ajax.php`, so the source would be missing exactly when the dispatcher needs it.
3. **Keyset, never LIMIT/OFFSET, for batched `fetch_page`:** `WHERE … AND id < %d ORDER BY id DESC LIMIT %d`, with `cursor_of()` returning `(int) $row['id']` and the first page cursor `PHP_INT_MAX`. The `id` desc order is stable across concurrent inserts during a long export — this is a **deliberate behaviour change** from any prior on-screen `orderby` (call it out in the CHANGELOG). Add a `count`/keyset method to the domain's **Reader** (not the source); when a shared `count()` can't honour a filter (e.g. a JOIN-only column), add a dedicated `count_for_export` rather than reusing it.
4. **Freeze dynamic columns in `build_context`.** When columns are per-row JSON (submissions, appointments) or per-campaign fields (reregistration), resolve the full column set **once** at start and carry it in the frozen job context — the header must not drift between batches. For row-JSON, scan keys with a lightweight keyset pass over only the JSON columns.
5. **Front-end: drive the button through the shared JS.** The "Export CSV" button calls `window.FFCBatchedExport.run({ type, ajaxUrl, nonce, startData, callbacks })` from `assets/js/ffc-batched-export.js` — never a bespoke start/batch loop. Enqueue `ffc-batched-export` (dep `ffc-core`) on the page and localize the job nonce. **Register the click handler at a point that always runs** — its own method / top-level delegated `$(document).on('click', '#…', …)`, *not* folded into another `init*()` that early-returns on that page (that silently dropped the audience-bookings handler — #783). `startData` may be an object (admin) or a `$form.serialize()` string (public); the driver appends `&type=` to a string and object-merges otherwise — don't hand it a string through `$.extend` (that spread its chars and dropped the page nonce — #781).
6. **Ownership is pluggable via `authorize_*`:** capability + job nonce + `user_id` fence for admin sources; `UUID + per-job nonce + IP-hash` for the anonymous public source. Admin sources gate a dedicated `ffc_export_*` cap via `Capabilities::current_user_can_admin_or()`.

**PII-on-disk is accepted** for the batched sources that decrypt (submissions, appointments, reregistration, public): the temp file lives under `wp_upload_dir()/ffc-tmp` (writable, survives plugin updates, per-site on multisite), guarded by a `.htaccess` `Deny from all`, random-UUID filenames, `unlink` after download, and a daily cleanup cron. **The audit-log export stays synchronous precisely so it never touches disk** (eliminates the risk rather than mitigating it). **Nginx caveat:** `.htaccess` is Apache-only — on nginx the temp dir must be denied at the server block (e.g. `location ^~ /wp-content/uploads/ffc-tmp/ { deny all; }`, or keep `wp-content/uploads` non-executable and unreadable for that path). Document this for any nginx deploy; the in-code `.htaccess` does not cover it.

**When auditing, grep every legacy output site** (`php://output`, `fopen(`, `header( 'Content-Type: text/csv`, `CsvStreamer`, `->stream(`) and confirm each export routes through a source + one of the two adapters — the #772 consolidation retired seven bespoke exporters this way. **One deliberate exception survives the grep:** `Frontend\PublicCsvExporter::stream_form_csv()` still writes to `php://output` directly. It is the graceful-degradation half of the only *public/frontend* export — a plain `<form>` POST to `admin-post.php` that works with JS disabled (admin exports need no such fallback; they run in wp-admin where JS is assured). It is **not** a `SyncSourceInterface` because it is a direct `admin_post` streaming handler (not an AJAX job) and it emits an HTML 413 page over the row cap, which does not fit the `rows(): iterable` contract. Leave it bespoke — do not "fix" it to satisfy the grep.

**The no-JS half is now conditional, and that is deliberate (#1053).** Under the ALTCHA-only captcha mode this path exists but cannot be completed: the widget needs JavaScript, so a visitor without it has no way to pass the challenge and `handle_request()` refuses. That is an accepted consequence rather than a regression — the audience for this export is operators working on a desktop, so the mode is defensible for them — but it does mean "works with JS disabled" is a property of the *math* and *composite* modes only. Keep the handler: it is the whole no-JS path in the two modes that have one, and it is also the rollback that needs no redeploy.

### Captcha architecture (one contract, three modes — #1053)

Every public form is guarded by the same block: a honeypot (provider-independent, owned by `Core\SecurityService`) plus a challenge from whichever strategy is configured. **Never verify or render a challenge directly** — go through the contract.

**The contract** is `Core\Captcha\CaptchaProviderInterface`: `id()` · `render_fields()` · `verify()` · `peek()` · `challenge_payload()`. `Core\Captcha\CaptchaProvider::resolve()` picks the strategy from `ffc_settings['captcha_provider']` and falls back to math on an unknown value — a typo in an option must not take the public forms down. Three strategies: `MathCaptcha` (`math`), `AltchaCaptcha` (`altcha`), `CompositeCaptcha` (`both`, ALTCHA with the math half inside `<noscript>`).

**`verify()` spends the challenge; `peek()` checks it without spending.** This distinction is the contract's, not one strategy's, and it exists because of a live bug: the public CSV download is the plugin's only two-request flow and validated the same token twice, so single-use tokens made the second request reject the answer the visitor had just been told was correct (#1061). The rule: **the challenge is consumed by the action it authorises, not by the metadata read that precedes it.** A `peek()` that merely skips the ledger is wrong — it must refuse an already-spent proof too, or the contradiction moves one request downstream. Any new strategy implements both.

**Composite mode is accessibility, not security.** The server accepts either proof, so an attacker picks the cheaper one and the effective strength equals the math challenge's. Say so wherever it is offered; do not let it be read as "both, therefore stronger".

**Five sites issue a challenge, and all five go through the provider:** the render sites (`SecurityService::render_security_fields()`), the four retry paths (`SecurityService::with_fresh_challenge()`), and the cached-page fragment refresh (`Frontend\DynamicFragments`). The last one was missed once and is the reason this is written down — its client half dispatches on the payload's `provider` key and leaves an unrecognised one alone rather than half-applying it.

**ALTCHA specifics that are not guessable and cost time to rediscover** (all verified against the vendored 3.2.2 bundle, not documentation):

- **The element accepts exactly nine attributes** — `auto`, `challenge`, `configuration`, `display`, `language`, `name`, `theme`, `type`, `workers`. Everything else (`hideLogo`, `hideFooter`, `humanInteractionSignature`, `setCookie`, the floating options) travels as JSON in `configuration`. **Written as an attribute it is ignored in silence** — no console error, no visible failure. `Core\Captcha\CaptchaSettings` is the one place that knows which is which.
- **Translations are not an attribute either.** They live in the `globalThis.$altcha.i18n` store, keyed by language and selected by `language`. `assets/js/ffc-captcha.js` registers the plugin's own strings there, which is what keeps the upstream 52 KB i18n bundle out of the page.
- **`maxnumber` does not exist in 3.x** — the word is absent from the bundle. The solver counts up without a ceiling until it reproduces the hash, so **difficulty is the size of the server's secret number and nothing else** (expected work ≈ half of it). It is still emitted in the challenge because the published format carries it and other clients read it.
- **The widget refuses to run outside a secure context** (`isSecureContext`), throwing rather than degrading. The ALTCHA-only mode is therefore blocked at save time without HTTPS; the composite mode is allowed because its `<noscript>` half still works.
- **Vendor the UMD build** (`libs/js/altcha-<version>.umd.js`). The ESM build needs `<script type="module">`, which only `wp_enqueue_script_module()` emits — WP 6.5+, above this plugin's declared floor of 6.4.
- The wire format is ALTCHA's original ("v1") challenge. Given a top-level `challenge` key the 3.x widget marks it `_version: 1` and posts back `{algorithm, challenge, number, salt, signature, took}` in base64, with the counter hashed **as a decimal string**. The expiry rides in the salt as `?expires=<unix>`; the salt is not signed directly and does not need to be, because `challenge` is the hash of salt plus secret.

**Keys, bounds and privacy defaults live in `CaptchaSettings`** — allowed values for each attribute, and the four bounded numbers. Each is clamped **on read as well as on save**, so a value written before a bound moved still lands somewhere the widget can cope with. The last two are the **challenge-issuing cap and its window** (#1111) — a cap whose window is a private constant is half a setting, since the same number means something different over a minute and an hour — and they are the one exception to "a captcha setting lives in `ffc_settings`": they are stored in `ffc_rate_limit_settings` under `ip.captcha_max_per_window` / `ip.captcha_window_seconds` and edited on the Rate Limit tab, because that tab groups by *what is limited* and this is limited per IP — next to the submission cap an administrator raises for the same institutional-NAT reason. Bounds stay in `CaptchaSettings`; storage belongs to the tab that owns the option. `0` on the cap means no cap, the same convention the per-endpoint read limits use, and it is the only honest answer for heavy NAT, where any per-address number caps the building rather than the farmer — `0` on the *window* is not a value and floors like any other out-of-range number. The window's floor is **1 second, not 60**: under a minute the throttle is mostly decorative (a caller just paces across boundaries), but that is a recommendation the field states, not a range the code refuses — the bounds here exist against a value the runtime cannot use, never to express a security opinion the administrator is not allowed to overrule. Lowering it required fixing what a **cleared field** writes: `(int) ''` is `0`, so every bounded numeric setting in the admin silently stored its own floor when emptied, and with a floor of 1 that would have meant a one-second window. Both write paths now treat empty as "not supplied" — the tab's rebuild-save keeps the stored value, and `SettingsAjaxEndpoint` refuses the write with a 400 (for **every** `int` key, not just these) so the field's badge shows the failure instead of a value nobody typed. The window length is part of the transient's bucket key, so changing it moves callers to a fresh bucket instead of reinterpreting counts taken under the old one. `humanInteractionSignature` (pointer and keyboard timings) and `setCookie` are **forced off and deliberately not configurable**: these are public-sector forms under the LGPD, the proof of work already carries the anti-automation load, and an administrator toggling them back on would change what the site has to disclose without being told so.

**Signing and single use are shared, not per-strategy:** `Core\Captcha\ChallengeSigner` (key derived from `wp_salt('nonce')` — no option, nothing for `uninstall.php` or the fresh-install manifest) and `Core\Captcha\ChallengeStore` (transient ledger; `redeem()` spends, `is_spent()` reads). A new strategy reuses both rather than inventing its own.

### Stylesheet architecture (28 sheets, one per screen — deliberately not a bundle)

`assets/css/` holds **28 non-minified sheets, 382 KB** — 17 admin-only, 7 frontend-only, 4 both — and each is enqueued by the screens that need it. **`wp_enqueue_style` is the entry-point mechanism.** There is no bundler and no preprocessor: `npm run build:css` runs `cleancss` over each sheet in place, and `build:js` does the same with `terser`. The stack is plain CSS, PHP templates and jQuery.

**Per-screen, not two bundles — measured, not preferred.** Collapsing the sheets into `admin.css` + `front.css` (the usual advice for an app you own) costs roughly double on every screen:

| Screen | Today | Single bundle |
| --- | ---: | ---: |
| Appointments (admin) | 113 KB · 7 sheets | 220 KB (**1.9×**) |
| User dashboard (frontend) | 99 KB · 4 sheets | 218 KB (**2.2×**) |

The bytes are the smaller reason. Conditional loading is what keeps the plugin's CSS **off** the screens that are not ours — a single admin bundle would land on `users.php`, the post editor, and any screen whose gate happens to match, which is precisely the blast radius `CssNamespaceAnchorTest` exists to bound. Granularity here is a safety property, not an optimisation.

**The plugin is a guest in someone else's document, and that settles two whole layers.** On the frontend the surrounding document belongs to the site's theme; in wp-admin it belongs to WordPress. So:

- **No reset, ever** — no `normalize`, no global `box-sizing`, no `* { margin: 0 }`. Measured: zero in the codebase, and it must stay zero. A reset is for whoever owns `<html>`.
- **No bare element selectors on the frontend** — `p`, `a`, `h1`-`h6`, `body` styled unqualified would repaint the host theme. Measured: **zero** across the 7 frontend sheets. The five that exist (`code`, `pre code`, `.form-table th`, …) are all admin-side and are already debt in #1152's baseline, not architecture to extend.

**How sheets relate.** `ffc-common.css` is the root: 1,226 lines carrying the 135 `--ffc-*` tokens, the wp-admin base pair, and the shared components across 206 selectors. Every module sheet declares it as a `wp_enqueue_style` dependency, which is both what makes a module's override of it deterministic and what `AdminStylesheetTokensTest` direction B enforces. The admin base is a chain — `ffc-pdf-core → ffc-common → ffc-admin-utilities → ffc-admin-css → ffc-admin-submissions-css` — and a dependency edge is the *only* thing that fixes the order between two sheets; without one it falls to enqueue order, i.e. the order modules are wired in `Loader` (see the component-ownership guard in §2).

Three facts about that graph that cost time to rediscover. **Two handles can name one file**: `ffc-admin.css` is `ffc-admin` on `users.php` and `ffc-admin-css` on FFC screens, with different dependency lists. **One handle can be enqueued with different dependency lists at different sites** (`ffc-admin-settings`), and WordPress keeps whichever registered first. And **a gate can be wider than its handle's name**: `ffc-admin-submissions-css` is gated on `is_ffc_page()`, which matches *any* `?page=ffc-*` screen, so it loads far beyond the submissions page its docblock names.

**Four guards hold this shape**, and each is described in §2: `CssNamespaceAnchorTest` (every selector names something we own), `StylesheetOwnershipTest` (two sheets declaring one class must have an edge), `AdminStylesheetTokensTest` (a sheet reading tokens declares `ffc-common`; no colour literals), `DarkModeCssTest` + `TypographyTokensTest` (the palette and the scale). The CSS parser they share lives in `tests/Support/CssSelectors.php`.

**Standing decisions, so an ITCSS-shaped proposal is not re-evaluated from scratch.** The layered advice (settings · tools · generic · elements · objects · components · utilities, with per-area bundles) is sound for a standalone app with a bundler and a document of its own. Mapped onto this plugin it splits three ways, and the split was measured rather than argued:

- **Already in place, by another route.** *Settings* — the token palette and the typography scale, as native custom properties, which beat preprocessor variables here because they respond to the dark theme at runtime. *Utilities* — `ffc-admin-utilities.css`. *Entry points* — per screen, which is finer than per area.
- **Would be a defect here.** *Generic* (a reset) and *Elements* (bare tag styling) both assume ownership of the document, which a plugin does not have; the second is the very thing #1152 froze as debt. **BEM** is churn: the `ffc-` prefix already carries the isolation BEM's naming would, over ~1,000 classes. What *is* worth keeping from that advice is component-specific naming, which #1151 / #1154 / #1162 have been doing one component at a time.
- **Genuinely worth doing.** A **page-scope class on the admin `wrap`** — only 5 of 25 `class="wrap"` carry an `ffc-` class today, and that missing anchor is exactly what #1152's 60 baselined selectors need in order to be fixed.

**A preprocessor has one argument that sounds real — a mixin for *the same visual shape under different owners* — and the example it was stated over does not survive measurement.** The `.ffc-status-badge` declared bare in three admin sheets looked like one shape three modules were forced to copy. It is not: `3px 8px` / `2xs` / uppercase, `2px 8px` / `xs` / uppercase + `line-height`, and `2px 8px` / `xs` / no uppercase + `nowrap`. **Three different badges wearing one name** — which is a rename in the #1151 / #1154 / #1162 mould, not a mixin. #1168's shared skin object does not resolve those two baseline pairs either, because what collides there is the **structure**, not the colour pair.

The duplication that *is* real is the skin pair itself — `background: var(--ffc-X-bg); color: var(--ffc-X-text)`, **57 rules across 13 sheets that declare nothing else**, plus 37 more carrying it inside a larger component. That has a pure-CSS answer (#1168) and needs no build step, so the preprocessor buys nothing here. Revisit **only** if a shape appears that a shared class cannot carry, and decide it the #788 / #902 / #993 way — from proven duplication, not on spec.

### Naming and composition inside the sheets (#1167)

The previous section settles *how many sheets and why*; this one settles *how a class is named and how a component is composed*, so that a proposal framed as "adopt BEM" or "adopt utility-first" is answered from measurement rather than from scratch. Measured over the 28 sheets with the shared parser (`tests/Support/CssSelectors.php` — the same one the guards read, so the numbers cannot disagree with them): **2,128 rules · 2,512 selectors · 1,196 distinct classes**, of which **1,049 carry the `ffc-` prefix** and 147 do not.

**Where each methodology already sits — measured, not aspirational:**

| | Level | Evidence |
| --- | --- | --- |
| **OOCSS** | partial | 108 occurrences (79 distinct) of the compound unit `.ffc-a.ffc-b` already split object from modifier (`.ffc-day.ffc-selected`). The **skin** half is not split: 57 rules across 13 sheets declare one `background: var(--ffc-X-bg); color: var(--ffc-X-text)` pair and nothing else, under 57 names, with 37 more carrying it inside a larger component (#1168) |
| **BEM** | 5 islands, ~5% | 33 `__` elements + 24 `--` modifiers, all inside `ffc-settings-tabs`, `ffc-form-tabs`, `ffc-qr-modal`, `ffc-blocked-roles` and the autosave badges |
| **SMACSS** | state layer yes, layout layer absent | 12 `is-`/`has-` classes, and **all 12 appear compounded with an `ffc-` class, never bare** — which is why #1152 never saw them, and a bare `.is-open` would fail it today. Zero `l-`, zero `js-` |
| **Atomic / utility-first** | exists, drifting | `ffc-admin-utilities.css`, 42 classes, of which 9 are screen components and 3 are dead (#1171) |

**Three idioms say the same thing, and all three are live** — a prefixed modifier (`.ffc-day.ffc-selected`, 108 occurrences), an unprefixed one (`.ffc-consent-status.consent-given`, 38 names) and a SMACSS state (`.ffc-cap-role.is-on`, 12). Converging them is #1170. Of the 147 unprefixed classes, only 38 are ours: 18 are CodeMirror's (the vendor emits `cm-*`; they cannot be prefixed), 16 are emitted by WordPress (`hndle`, `inside`, `post-type-*`, `column-ffc_*`, `publish`, `trash`), and 6 are bare and already in #1152's baseline. **The 38 are anchored, so this is not a collision risk — it is a readability one**, which by this file's own priority rule is a recurring reader-facing inconsistency rather than a cosmetic one.

**The largest finding is not a methodology — it is a scale that nobody reads.** `--ffc-spacing-*` has existed for as long as the palette and has **3 consumers out of 1,238** spacing declarations; 30 distinct px values are in use. This is the typography story before #1148, with one aggravation: the declared ladder (5 · 10 · 15 · 20 · 30, **550** uses) does not contain the ladder the code actually uses (2 · 4 · 6 · 8 · 12 · 16 · 24, **624** uses), and neither dominates. `border-radius` is half-adopted (68 tokenized against ~133 literals, **71 of those literals being the `4px` that `--ffc-radius-sm` already is**) and `z-index` has no scale at all — `1`, `2`, `1000`, `9999`, `100000`, `100100`, `999999`, `2147483647`, the last being int32's maximum and the classic sign of a stacking war. Tracked in #1169 / #1171.

**What this means for the theme arc:** a third *colour* theme costs 57 lines, because dark is light overridden and only 57 of the 78 tokens change. A **density** theme (compact/comfortable) — the more likely accessibility ask for public-sector forms — is impossible today, because spacing, radius and `z-index` are not tokens. That is the same finding seen from the reuse angle, and it is the reason #1169 is ranked first.

**Standing decisions, so they are not re-litigated:**

- **No BEM across the base.** The `ffc-` prefix already carries the isolation BEM's naming would, over ~1,000 classes; converting is churn against a property that already holds. The 5 BEM islands stay as they are — internally coherent, and rewriting them to the house idiom is churn in the other direction. What survives from the idea is **component-specific naming**, which #1151 / #1154 / #1162 have been doing one component at a time.
- **No ITCSS with per-area bundles.** Measured in the previous section: 1.9×/2.2× per screen, and conditional loading is a safety property rather than an optimisation. Two of the seven layers (*generic*/reset, *elements*/bare tags) presuppose owning the document, which a plugin does not.
- **No Tailwind-style utility-first.** It needs a CSS build the project does not have (`cleancss` minifies in place, it does not compile) and a class scan that cannot see what PHP assembles at runtime. The utility layer that does make sense here already exists; the work is keeping it small (#1171), not growing it.
- **No layout layer (`l-`).** Zero occurrences, and the split it would express is already made per sheet.
- **A scale is worth building only where a second consumer of the same value exists.** Colour and typography earned theirs; spacing has 1,235 literal declarations and is next. A one-off number — an optical nudge, a hairline — stays literal with the reason inline, exactly as the typography section describes.

### Theme (one palette, one root class — #1126)

Every colour the plugin paints comes from a `var(--ffc-*)` token declared in `assets/css/ffc-common.css`. The dark theme is that same sheet's `:root.ffc-dark-mode` block redefining part of the tokens — **the dark theme is the light theme overridden, never a second set**, which is why `DarkModeCssTest::palette('dark')` merges the two before measuring. `AssetHelper::enqueue_dark_mode()` puts the class on `<html>`, reading `ffc_settings['dark_mode']` (`off` / `on` / `auto`).

**The plugin's own setting is the single source of truth. There is ZERO `prefers-color-scheme` in the CSS** — the OS is consulted inside `ffc-dark-mode.js`, and only when the setting is `auto`. A rule keyed on the media query would silently outrank the administrator's choice; don't add one.

**Seven things the #1126 arc measured that are not guessable, each of which shipped as a defect first:**

1. **The palette alone is not the theme.** A fully tokenized sheet renders light when the toggle script never reaches the page — every `var(--ffc-*)` resolves through the light block. Four public screens were in exactly that state.
2. **An undeclared custom property invalidates the WHOLE declaration.** It does not fall back to the literal that was there before, so the element renders with no colour at all. Tokenizing a sheet whose enqueue does not depend on `ffc-common` makes the screen *worse*. A local `:root { --ffc-… }` inside a component is the same defect wearing a different hat: it shadows the palette for everything inside, so `:root.ffc-dark-mode` can never reach it.
3. **Text that declares no `color` inherits from OUTSIDE this repository** — core's `body { color: #3c434a }` in wp-admin, the active theme on a public page. Both are near-black: **1,28:1** on a dark ground. The palette therefore carries a base pair per root we own (`body.wp-admin` — never bare `body`, since the class also lands on public pages).
4. **A form control does not inherit `color` at all.** `<button>`, `<input>`, `<select>` and `<textarea>` take `buttontext` / `fieldtext` from the user agent, so the base pair cannot reach them. **A rule that paints a control's ground must paint its text.**
5. **`opacity` on a row fades text and ground together**, multiplying down whatever contrast the tokens guaranteed — and the pair meter cannot see it by construction, because the tokens stay correct. Say state with colour, not with opacity.
6. **An inline `style=""` beats the tokenized class.** Tokenizing is dead code while an inline colour exists. Where the background is a colour the *operator* picks, no token can work — the foreground comes from `Core\ContrastColor::on()`, which computes it by WCAG luminance. Its two candidates are pure black and white on purpose: the palette's near-black `#1d2327` drops the worst mid-tone of the RGB cube to **3,99:1**, while black holds **4,58:1** over any colour.
7. **Categorical hues are not theme colours.** The twelve capability-group hues must stay distinguishable from each other, so they stay literal with the reason inline. Same for `#adminmenu` (it follows the user's own wp-admin colour scheme), vendor brand colours, an editor theme that is dark in both themes, and print.

**Five guards, and none was written on spec** — each came from a defect that had already shipped:

| Guard | What it blocks |
| --- | --- |
| `AdminStylesheetTokensTest` A | a colour literal per sheet — a ratchet, `0` for the converted ones; counts hex, `rgb()`/`hsl()` **and the named colour** (#1168) |
| …B / B2 | a sheet reading tokens without declaring `ffc-common`; a method enqueuing the palette without the toggle |
| …C | a `var(--ffc-*)` nobody declares |
| …D | the base pair exists, paints through a token, and names only live roots; the notice's text nodes likewise |
| …E | a form control given a ground but no text colour |
| `DarkModeCssTest` | every painted pair against its WCAG floor, both themes (4.5:1 text, 3:1 signal) — **two halves**, see below |

Each carries a self-check that fails when its own scan collapses — an empty result must never read as "clean" (the #1071 / #1094 lesson).

**The contrast meter is two halves, and the line above was aspirational until #1168.** `DarkModeCssTest::pairs()` is a **hand-written list of token pairs** — it measures what somebody remembered to list, and `--ffc-danger` over `--ffc-danger-bg` was never on it, shipping at **4,25:1 in the LIGHT theme** on a public-CSV warning. So a second half now **derives** the pairs: every rule that declares `color` and a background *in the same rule* is measured in both themes against the 4,5:1 text floor, blocking at zero. **Keep both** — they cover different things: the scan only sees what one rule declares together, while the pair that **inheritance** creates (the base text over `--ffc-gray-100`, a label whose colour comes from its container) is invisible to any static scan and is exactly what the curated list is for.

Three things the derived scan measured, none of them guessable:

1. **A sheet certified "0 literals" was painting `white` on four dark grounds.** The literal ratchet's regex was `/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/` — a **named colour is a word**, so it matched nothing, and `ffc-admin-submissions.css` carried `color: white` on the PDF, delete and restore buttons plus a tooltip, measuring **1,23 · 2,52 · 2,68 · 2,78:1** in dark mode. The `2,52` is the same number this file already records as the historical `on-primary` bug: the palette fixed the *token*, these four rules never adopted it. Direction A now counts named colours, only inside a colour-valued property (`font-family: 'Whitney'` is not a literal).
2. **Unresolvable must FAIL, never skip.** The scan's first version could not read `!important` or `white` and reported those four as "unresolvable" — i.e. it would have passed over the worst defects in the repository. A colour the scan cannot resolve now fails the test and asks to be taught, which is the same rule the dbDelta gate states as "never count as clean what it did not look at".
3. **Only two pairs are legitimately below the floor**, and both are **inactive** controls carrying `cursor: not-allowed` — a full time slot and a `readonly`/`disabled` field. SC 1.4.3 exempts text that is part of an inactive component; they sit in `DERIVED_EXCEPTIONS` with that reason, and a test fails when an exception stops matching any real pair.

**The skin object was measured and NOT built** (#1168). Consolidating the 57 rules that declare only a `--ffc-X-bg` / `--ffc-X-text` pair into six shared classes is the textbook OOCSS split, and it was rejected here: the tokens already deliver "change the colour in one place", so the duplication is *syntactic*, and migrating the status-badge families means a status→skin map in PHP **and** JS across ~36 files, because the class is built by concatenation (`'ffc-dashboard-status-' . $status`). The guard delivers the property that mattered — a wrong pair becomes impossible to ship — without the churn.

**Standing decisions, so they are not re-litigated:**

- **Print and PDF are light by definition.** `ffc-pdf-core.css` and the certificate preview's white paper are documents that may be printed; they do not follow the theme.
- **Core screens stay light** — `profile.php`, `users.php`, the post-editor metabox. The content area of every core colour scheme is white, so our fields rendering light there is *consistent*, not a bug. Listed in `TOGGLE_NOT_NEEDED` with that reason.
- **The code editor has its own setting**, `code_editor_theme` on the General tab, immediately below Dark Mode: `dark` (default), `light`, or `auto` (follows `dark_mode`). It is not hard-coded. It sat on Advanced until #1148 and nobody found it there — `auto` resolves by reading `dark_mode`, so the two belong side by side. It was **moved, not mirrored**: `SettingsAutosaveFieldPlacementTest` refuses one autosave key on two tabs, because each tab's Save rebuilds its own fields and two copies drift.
- **Typography reads the scale** (#1148 item 4). Seven steps in `ffc-common.css` — `2xs` 11px · `xs` 12px · `sm` 13px · `base` 14px · `lg` 16px · `xl` 18px · `2xl` 24px — frozen by `TypographyTokensTest`, a per-sheet ratchet in the mould of the colour one. Three things it measured that are the opposite of what they look like. **The `xs` step moved from 11px to 12px**, and that rename cost nothing because the scale had *zero* consumers: 415 `font-size` declarations and not one read a token. 12px had 69 uses — the third most frequent value in the codebase — and the scale skipped it, which is why the sheets invented it. **Seventeen of the eighteen "same selector, two sizes" cases are `@media`**, a deliberate step down on phones, not drift; the opposite hypothesis was the intuitive one and was wrong. **An icon sized by `font-size` is not typography** — it is a glyph box (dashicons, a `&times;`, a stat card's icon), and those stay literal with the reason inline, like the categorical hues. The adoption finished at **23 literals in the whole codebase**, and the four families that keep one are worth knowing before adding a fifth: a glyph box; a hero number a card sets deliberately above the scale; an `em` that is relative to its parent because the component is dropped into contexts of different sizes; and a value already one step below the floor inside a phone `@media`, where rounding up to `2xs` would erase the distinction the rule exists to make. **`15px` is equidistant between `base` and `lg`, and the tie is broken by relationship, not by rounding**: a heading that lands on body size loses its hierarchy, so headings go up and body text goes down — 17 declarations turned on that one rule.
- **Each step is `max(<px floor>, <rem>)`, and the form is the point** (#1157). `rem` resolves against the DOCUMENT root, which on the frontend belongs to the site's theme: under the common `html { font-size: 62.5% }` idiom the whole scale shrinks and `sm` renders at **8.1px** — measured in Chromium, not estimated. The px floor removes that; the `rem` half keeps the browser font-size preference these public-sector forms want. It is not a compromise — it beats `rem` in the case that breaks and `px` in the case that matters. The one honest loss is the combined case: under a root-shrinking theme the user's preference is absorbed by the floor until it exceeds it (at 62.5%, asking for 24px still yields the floor's 13px) — still better than the 12.2px plain `rem` would give there. **The two halves must agree at a 16px root** — `max(13px, 0.8125rem)` is an identity, not a range — and `TypographyTokensTest` checks exactly that, because a mistyped `max(13px, 0.75rem)` is plausible on both sides and only shows up rendered, in a theme nobody here runs. This replaced the earlier "accepted exposure": the survey of real themes #1157 proposed became unnecessary once the answer stopped depending on which theme is installed.
- **A theme change repaints live.** The autosave widget announces `ffc:setting-saved` on `document`; `ffc-dark-mode.js` listens and re-applies, including dropping the OS listener when leaving `auto`. The widget must not learn which keys repaint — it states the fact, an interested script acts.

Open items and the measurements behind them are in #1148 — among them the typography scale, which already exists and is already semantic, and the trade that makes multiple themes cost more than it looks.


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

### Settings writes — autosave toggles (the no-clobber invariant)

On/off **toggle switches** (`.ffc-toggle`, rendered via `AdminUI::render_toggle()`) auto-save on flip: mark the input `data-ffc-autosave-key="<key>"`, add the key to `SettingsAjaxEndpoint::allowlist()` (`{option, path?, type, cap}`), and have the tab call `enqueue_autosave_infra()`. This is the standard for atomic, side-effect-free boolean settings. **Two autosave endpoints exist and stay separate on purpose** (do NOT unify — bad-façade trap): `SettingsAjaxEndpoint` (`ffc_update_setting`) writes WP **options** under a global cap; `FormMetaAjaxEndpoint` (`ffc_update_form_meta`, attribute `data-ffc-autosave-form-key`) writes per-post **meta** gated on `edit_post` of that exact form. **The JS is one widget serving both** (#1116) — `FFC.Admin.autoSaveField` in `assets/js/ffc-admin-autosave.js`, wired by `bootAutoSaveFields()`, which scans **both** attributes and takes `action` / `payload` / `request` / `strings` per endpoint. The endpoints stay separate; only their client did not need to be. That statement was written here before it was true: the form-meta half was a second implementation inside `assets/js/ffc-admin.js` (delegated, `change` only, no debounce, its own badge whose CSS hardcoded hex and therefore ignored dark mode), and the cost was not theoretical — the #1114 validity guard had to be written twice to reach both halves. So a change to autosave behaviour is now one edit; **a screen that renders either attribute must enqueue `ffc-admin-autosave`** (settings tabs get it from `enqueue_autosave_infra()`, the form editor enqueues it directly), or its fields silently have no listener.

Two things the convergence measured, worth not re-deriving. Every one of the 16 form-meta fields is a checkbox from `AdminUI::render_toggle()`, so adopting the settings half's `change`+`input` binding changed nothing visible — there is no text field on that path to "save while typing", and a checkbox's two events coalesce in the debounce. And the **delegated** binding was the half *not* kept, against the original proposal: nothing exercises it (all fields of both kinds are server-rendered at page load), and adopting it would have meant rewriting the half with 115 fields, per-field debounce, multi-checkbox groups and `confirmOff` to gain that. `bootAutoSaveFields()` is idempotent and exported, so DOM inserted later has a supported answer — call it again.

**Invariant that keeps autosave and the form Save from clobbering each other — hold it whenever you add an autosave toggle:**

- The autosave toggle must ALSO be a **named field inside its tab's `<form>`**, so the form's rebuild-save writes the toggle's *current* (auto-saved) state, not a stale/absent one. An autosave-only key that a rebuild-save omits gets silently reset on the next Save.
- Tabs that share `ffc_settings` save through the central `SettingsSaveHandler`, which is **merge-based** (`$clean = get_option(...)`) and gates each section on the hidden `_ffc_tab` marker — so saving tab X only rebuilds tab X's toggles, preserving every other tab's auto-saved keys. Keep both properties (merge base + `_ffc_tab` guard) when touching that handler.
- Tabs with their own option (geolocation, rate-limit, user-access, ip-diagnostics) full-rebuild that option from their own form; every toggle in the option must be read back from `$_POST` there.

### Admin number inputs — `required`, and the three times it is wrong

Every `<input type="number">` in the admin carries `required`, enforced by `tests/Unit/RequiredNumericInputTest.php` — an unlisted one without it fails, and a listed exception that gained it (or vanished) fails too. The reason is the #1114 class: a cleared number field posts the empty string, `absint( '' )` is `0`, and what that `0` means is per-consumer and was never uniform — on the Rate Limit tab it read as "limit already reached" and barred every submission from every address; on a reregistration campaign it collapsed the reminder onto the campaign's last day. The attribute is only the cheap half: **the save handler still has to treat empty as "not supplied"**, because the browser is not the guard (`RequestInput::get_post_string( $k, '' )` then an explicit `'' === …` branch; `ArrayValue::int()` already does this, since `is_numeric( '' )` is false).

Three shapes make `required` a **bug** rather than a missing safeguard, and each is in the guard's `KNOWN_OPTIONAL` with its reason:

1. **Empty is a documented value.** The device-limit and public-CSV fields say "Inherit from global" and their save handler *deletes the meta* so the read side falls back; the audience calendar's says "Leave empty for no limit". `required` removes the only way to express it.
2. **The control is not a form field.** `ffc_qty_codes` has no `name` — it is the argument to an AJAX button. A control without a name is still a candidate for constraint validation, so `required` there blocks the surrounding form while never being submitted. Same for an empty "add a new row" line inside a shared `<form>` (`ffc_location_new[…]`, caught in #1115 before it shipped).
3. **Requiredness belongs to the field's definition.** User-defined custom fields carry `is_required`; the markup must emit the attribute from that flag, never hardcode it. Both renderers do (#1120 closed the user-profile side, where the flag had only ever drawn an asterisk). Two types stay out on purpose: a **checkbox** would have to be *ticked* to satisfy `required`, a different promise from "fill this in"; and a value posted through a **hidden** input is barred from constraint validation outright, so marking it required does nothing.

**A `required` field inside a JS-hidden block blocks the submit against a control nobody can see** — constraint validation ignores visibility, and only `disabled` bars a control (which would drop it from the POST). So the attribute travels with the block: `FFC.setRequiredWithin( $container, visible )` strips it and puts it back, using a marker attribute so a re-show never *promotes* fields that were never required. Call it wherever a block is shown or hidden — the calendar editor's four blocks, the quiz rows, and the collapsible audience sections on the user-profile screen all do. This was not hypothetical: the working-hours rows had carried `required` inside `.ffc-regular-only` since 4.1.0, so a stored row with an empty time jammed the save of any calendar switched to custom mode.

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
- **User-deletion integrity is two layers over a *deliberate subset*, not every user-reference column.** The primary is the app-layer `UserCleanup` hook (`deleted_user`), which handles `user_id` on the user-owned tables: **SET NULL** (retain the row, drop the link) for `submissions` / `appointments` / `activity_log` (the last via `ActivityLogQuery::redact_user_id`), **DELETE** (purge the row) for the pure-relationship audience tables (`audience_members` / `audience_booking_users` / `audience_schedule_permissions`) and `user_profiles`. `MigrationForeignKeys` adds a DB-level `user_id → wp_users` FK backstop on *those same* tables — `ON DELETE SET NULL` where the hook nulls, `ON DELETE CASCADE` where it deletes, plus `activity_log` (FK-only) — so integrity holds even if the hook is bypassed. This FK migration is **not** legacy: `dbDelta()` cannot emit FKs, so it is the sole path that creates them (absent on a fresh install until it runs), guarded by `ffc_foreign_keys_db_version`. **Beyond `user_id` (#834):** the hook also runs an **authorship sweep** (`UserCleanup::anonymize_authorship()`) that SET-NULLs the **nullable** actor-attribution columns (`submissions.edited_by`; `appointments.approved_by`/`cancelled_by`; `self_scheduling_calendars.created_by`/`updated_by`; `blocked_dates.created_by`; `audience_bookings.cancelled_by`; `reregistration_submissions.reviewed_by`; `recruitment_call.cancelled_by`; `short_urls.created_by`) **and** the promoted-candidate link `recruitment_candidate.user_id`. The sweep runs on **every** deletion (an actor/candidate need not have data-subject footprint, so it sits *outside* the #322 footprint short-circuit that still gates the `user_id` blocks) — cost is extra unindexed UPDATEs on the cold deletion path. It is **app-layer only**: no FK backstop is added for attribution columns (they are low-risk actor refs, not primary user-owned records), and the `NOT NULL` attribution columns are left as accepted orphans (see the gaps note).
  - **The governing principle** (apply it when adding any user-linked table): *retain records, drop relationships.* A row that is a **record** (a submission, an appointment, an audit line, a reregistration) is retained and its user link anonymised — SET NULL when the column is nullable, or left as an **accepted orphan** when it is `NOT NULL` and the row is a retained/legal record (the `activity_log` precedent). A row that is a pure **relationship** (a membership, a permission grant) is deleted with the user. Both layers key **only on `user_id`** — see the gap inventory below before assuming a new column is covered.
  - **Recruitment candidate:** `ffc_recruitment_candidate.user_id` is `DEFAULT NULL` (a candidate isn't a WP user until promotion; documented in its activator). Pre-promotion there's nothing to null; **post-promotion the link is now SET NULL by the authorship sweep (#834)** — the candidacy record + its PII are retained, only the WP-user link drops.
  - **`ffc_reregistration_submissions` — accepted orphan (#822, decided).** Its `user_id` (`NOT NULL`) is covered by neither layer *on purpose*: a reregistration is a retained record, and the identifying PII lives in the `data` JSON body (partly `Encryption`-encrypted, partly plaintext), so nulling the FK alone would be a half-measure. The row is retained; the `user_id` is an accepted orphan; JOIN consumers degrade to "—". There is **no separate custom-field value table** — those values are the `data` column (and `wp_usermeta` under `ffc_%`, which WP core + the manual `PrivacyErasers` clean, but the automatic `deleted_user` hook does not).
  - **#834 resolution (was "known remaining gaps"):** (1) **attribution columns** — the *nullable* ones are now nulled by the authorship sweep above; the six `NOT NULL` ones (`audiences`/`audience_schedules`/`audience_holidays`/`audience_bookings.created_by`, `reregistrations.created_by`, `recruitment_call.created_by`) stay **accepted orphans** (a plain `SET NULL` would be rejected; they're retained-record actor refs) — nulling them would need an `ALTER … MODIFY … DEFAULT NULL` first, not worth it. (2) `recruitment_candidate.user_id` post-promotion — **now covered** (SET NULL). (3) **Row-body PII on account deletion — decided policy, not a gap:** the automatic `deleted_user` hook anonymises the *link* (`user_id` + nullable attribution) but deliberately does **not** scrub the encrypted PII columns in the row body (`*_encrypted`/`*_hash`). Account deletion ≠ an erasure request: the **manual `PrivacyErasers`** (WP GDPR eraser) is the path that additionally clears `email_encrypted`/`cpf_encrypted`/`rf_encrypted`/… so certificates/appointments stay verifiable records until a subject actually exercises erasure. The only residual is the app-layer-only nature of the attribution sweep (no FK backstop) — an accepted design choice, not a follow-up.

---

## 5. Legacy and tech debt

### Legacy compat shims — audit log

Inventory of the legacy compatibility shims that remain in the code by design (snapshot — re-confirm the location in code before removing; paths and lines change with every refactor, so the table cites files/methods, never line numbers). Removing them requires evidence that no production installation depends on them.

_The two shims previously tracked here — the `ensure_legacy_caps_renamed()` v1 pre-6.2.0 cap-rename migration and the pre-4.6.15 orphan-cron cleanup (activator + deactivator/uninstall) — were removed in 6.18.0 (#809) as scheduled._

_The `html/` layout fallback (#865 phase-4) was removed in 6.23.0 (#1087), leaving this inventory empty. **The whole `html/` directory went with it**, because both of its exit conditions were met and confirmed on a real install: `import_legacy_templates` reads 0 pending, and so does `rewrite_html_image_refs` — the migration that side-loads `html/*.png` into the Media Library and was the only reason the images had to stay._

_The chain that makes deleting the images safe is worth keeping, because it is not obvious. That migration deliberately **skips shipped defaults** (a stale ref on a default is repaired by re-seeding, never by rewriting it to an uploads URL), so it alone would not have covered them. What covers them is `CertTemplateSeeder::maybe_seed()`: on any version bump it calls `restore()`, which refreshes each existing default's body to the shipped source — and `SEED_VERSION` 2 was precisely the #871 fix that moved those bodies off `html/` and onto `assets/`. Defaults by re-seed, everything else by the migration; between the two, nothing points at `html/` any more._

_Two related things to know if this ever comes up again. The seeder reads `templates/certificate-defaults/`, **not** `html/` — had it read the latter, deleting those three files would have broken seeding on every fresh install, which is the very condition that authorised the removal. And both migrations degrade without the directory rather than fatal: `glob()` on a missing path returns nothing (status "100% complete"), and the rewrite records a "Missing html/ file" error per target instead of throwing._

When a new shim is added, log it here (Shim · Location · Risk if removed · Why it stays), and when a new feature makes one unsafe or inadequate, open a specific sub-issue + a breaking-change banner in the CHANGELOG.

#### Resolved exemplar — how the `html/` fallback was retired (evidence, not a deprecation cycle)

Kept because the *method* generalises, and because what it got wrong the first time is the useful part.

Retiring it was **evidence-gated** (the `cpf_rf_encrypted` shape below), *not* a versioned deprecation cycle. The cycle exists for surfaces whose consumers a code scan cannot see — a public method an external integration might call. Both render sites were internal, with no hook, no filter and no external caller, so nothing invisible could depend on them; what they depended on was **install state**, which is observable.

Two conditions were written down, and they are genuinely different — conflating them is the mistake that nearly retired the shim early:

1. **The pool seeds on every install** — the fallback's own written condition, and the one that was *not* met before 6.22.0. The `CertTemplateSeeder::pool_has_defaults()` retry is what made it hold.
2. **Settings → Migrations → `import_legacy_templates` reads 0 pending** — every file an admin dropped into `html/` has been imported. It had read 0 in production for some time, but it measures *imports*, not seeding, and on its own said nothing about condition 1.

The seeder fix and the removal shipped in **different releases** (6.22.0 → 6.23.0), so an install with an empty pool received the repair, and was observed to have taken it, before losing the safety net.

**The lesson worth carrying: the written conditions were incomplete.** Measuring the surface at removal time found *four* sites reading `html/`, not the two the inventory named, and a **third** exit condition nobody had written down — `rewrite_html_image_refs` side-loads `html/*.png` into the Media Library by reading them off disk, so the images could not go until that migration also read 0 pending. The first removal commit therefore deleted the code and the three `.html` files and **kept the images**; they went in a second commit, once that condition was attested too. So: re-measure the surface before retiring a shim, and do not trust the inventory's own list of conditions to be exhaustive — it records what was known when the shim was logged, not what is true when it is removed.

#### Gathering the evidence to remove a **High**-risk shim

Not every High shim is provable by data. Before proposing to build a diagnostic, check whether the evidence already exists — the resolved `cpf_rf_encrypted` case below is the exemplar:

- **Resolved — `success`/`fail` keys of `get_audit_log_summary()` (removed in 6.17.0, #730).** They were **not provable by any diagnostic** (a public static method with no hook/filter/DB trace; an external consumer reading `['success']`/`['fail']` is invisible to any read-only query). Internally they had **zero** consumers (the metabox migrated to `access_success`/`failed_access`), so they were de-risked exactly as prescribed — a **versioned deprecation cycle** (announced 6.15.0) + a ⚠ breaking-change banner in the CHANGELOG, removed at the 2nd feature release after the notice — never via an evidence scan. `count` was NOT deprecated (the metabox reads it) and stays.
- **Resolved exemplar — `cpf_rf_encrypted` (removed after production read 0 pending).** Its evidence already existed in the UI: the `split_cpf_rf` migration card (Settings → Migrations) shows **Pending** = rows still carrying `cpf_rf_hash` in `ffc_submissions` / `ffc_self_scheduling_appointments` (`CpfRfSplitMigrationStrategy::count_table_status()`; a dropped column ⇒ 100% complete). Once a production install read **0 pending**, the legacy `cpf_rf_encrypted` reads (the PDF appointment fallback + its two REST-controller siblings) had no live dependent and were removed. The equivalence that backed the reading: `Encryption` always writes hash + ciphertext together and the migration nulls `cpf_rf`/`cpf_rf_encrypted`/`cpf_rf_hash` atomically per row, so "pending by `cpf_rf_hash`" ⟺ "pending by `cpf_rf_encrypted`". The lesson: a parallel counter keyed on `cpf_rf_encrypted` would have been redundant indirection — the migration card was already the signal (the façade trap that narrows nothing). *(The combined `cpf_rf` view field is separate legacy and its retirement is tracked apart from the shim.)*

### Parked architecture decisions

- **Import consolidation (recruitment ↔ audience) — parked / not planned (#788).** The #772 export contract deliberately does **not** extend to import: import↔export share only cheap scaffolding (uuid + `{processed,total,done}` + TTL), while store (temp file vs staging DB), arity (3 vs 4 phases) and direction all diverge — unifying would be the bad-façade trap. One batched importer (recruitment `CsvStagingService`) exists today; audience is single-request. Revisit **only** on a trigger — real audience-import timeouts in production, or a 3rd server-side import duplicating the stage-all→validate→promote shape — then extract from the *proven* duplication (the #772 way, which factored `BatchedCsvExport` out of two existing clones), not on spec. Full audit + verdict + lessons in #788.
- **Client-IP default flip (legacy → secure) — parked / not planned (#902, epic #899).** The consolidated `Core\ClientIpResolver` ships the trusted-proxy + Cloudflare-autodetect decision tree, but only as an **admin opt-in** (the `secure` strategy on the IP Diagnostics tab), with a notice strongly recommending `secure` + Cloudflare while `legacy` stays effective (#908). Forcing the effective default to `secure` was **deliberately not done**: the non-IP defence layers (device fingerprint, nonce + capability + rate-limit per `user_id`) already carry the load, so a stronger IP signal is a quality improvement, not the only wall — and a forced flip risks collapsing rate-limit/geofence into one bucket on shared hosts (the plugin's typical deployment). The shadow-divergence gate that would have justified the flip (`ffc_ip_shadow_logging`) is opt-in and **off by default**, so it never accumulates data on its own; keeping #902 open behind it would defer forever by inertia. Revisit **only** on a trigger — concrete evidence of an exploitable IP spoof (rate-limit/geofence actually bypassed via a forged header), or a consumer that genuinely needs the strong IP signal — then extract the flip from the *proven* need, not on spec (the #788 criterion). Full rationale in #902 / #899.
- **Declarative settings registry — parked / not planned (#993).** A single `ffc_settings` key is known in up to six places (`Settings::get_default_settings()`, the `SettingsSaveHandler` sanitiser, `SettingsAjaxEndpoint::allowlist()`, a `SettingsReader` typed accessor, every read-site default literal, the tab view), and nothing forces them to agree. The proposal is one declaration per key — `{ default, type, sanitize, cap, autosave }` — that every consumer derives from, making divergence **unrepresentable** rather than merely detectable. Deliberately **not built**: it touches `Settings`, `SettingsReader`, `SettingsSaveHandler`, `SettingsAjaxEndpoint`, ~17 read sites and probably the module-boundary baseline (days, not hours), which is the bad-façade trap unless the duplication is actually biting. The **cheaper guard landed instead** and is what the 6.20.1 CHANGELOG entry citing #993 refers to: the 5 measured divergences across 3 keys were aligned and `tests/Unit/SettingsDefaultsTest.php` now fails CI when a read-site default disagrees with the declared one — so **do not read that entry as the issue being resolved**. Revisit **only** on a trigger — a new divergence the guard catches that *cannot* be fixed by aligning a literal (two consumers legitimately needing different defaults for one key), a **third** consumer of the key list (uninstall + the autosave allowlist are the two today), or one of the **17 keys read through `SettingsReader` but never declared** producing a #936-style dormant feature. Full design, measurements and verdict in #993.
