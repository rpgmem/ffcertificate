# Contributing workflow — history and measurements

This file is the record behind `CLAUDE.md` "Contributing workflow" and `.claude/rules/release-and-deploy.md`, which holds its release, sync and deploy recipe since #2866: the release and tag incidents, the Dependabot and credential findings, the branch-protection reading, how the post-deploy smoke became blocking, and the CHANGELOG measurements.

Each section is the `CLAUDE.md` text as it stood when it moved here (#2860), so its present tense describes that moment. Read `CLAUDE.md` and the code for what is true now.

### 1. Contributing workflow

#### Pull-request workflow

The repository has **"Allow auto-merge"** enabled in Settings → General.
Use it on every PR — no manual squash + merge unless auto-merge fails.

**PR base branch:** by default, target `develop`, not `main`. The only PRs that target `main` are (1) the periodic release PR `develop → main` that consolidates the accumulated batch with a single version bump, and (2) hotfix PRs from `hotfix/*` branches when a critical bug needs to ship without waiting for develop's queue to consolidate. See "Develop branch workflow" below for the full mapping.

After `mcp__github__create_pull_request`:

1. `mcp__github__update_pull_request` with `draft: false` (auto-merge only fires on non-draft PRs).
2. `mcp__github__enable_pr_auto_merge` with `mergeMethod: SQUASH`. 
3. End your turn. GitHub merges as soon as every required check passes; `<github-webhook-activity>` fires the merge event back to the session.

Don't poll CI manually after step 2 unless the user asks. The webhook subscription delivers **failures + comments + the final merge event**; green completion is silent by design.

**Batch related work; gate the draft→ready flip.** Group trivially-related changes — especially docs-only (CHANGELOG / CLAUDE.md / comments) — into one PR; they gain nothing from the per-PR testes deploy and only multiply CI runs and rebase churn. Use **draft** to accumulate commits and keep the window short: open the PR late (work essentially done + locally green), rebase if `develop` moves under it, and treat a second forced rebase as the signal to finish or split. **Before flipping draft→ready + auto-merge:** if the PR completes a pre-agreed unit (a planned sprint/roadmap item with obvious done-criteria), go straight to ready + auto-merge; if the scope is ad-hoc or emerged mid-session — completeness not obvious from a plan — **confirm with the user first**, so follow-ups stay batched in the same PR instead of spawning a trickle of tiny PRs. No calendar deadline on drafts — the signal is drift (develop moving under the branch), not elapsed time.

**The cost of ignoring that first sentence, measured (#1260).** A comment-only translation of 27 test files was split into four PRs because 56 KB of prose is not reviewable in one diff. The size argument holds; *four* did not. Two of them hit the same `CHANGELOG.md` conflict and each cost a rebase, which is precisely the "rebase churn" the rule names. The reviewable unit is the **diff**, so split by size when a single diff would genuinely be unreadable — and then stop, rather than splitting again for tidiness. Where the work is one mechanical pass over many files, two large PRs beat four comfortable ones.

**Documentation travels with the change, in the same PR.** A PR that changes what an operator sees or configures — a screen, a setting, a default, a capability, a menu, an e-mail — updates the topic under `includes/settings/views/documentation/` that describes it, with its strings in the three catalogues. Measured by #1638: an audit of Settings → Documentation found seven shipped features with no or partial coverage, and five of them had shipped after the previous documentation review without touching a topic. The review that catches drift after the fact is the release cross-check below; the place it is cheapest to prevent is here, while the author still knows what changed. `DocumentationCoverageTest` guards the mechanical half (every tab and module has a topic, the index and the topic files agree); whether a topic tells the truth is not reachable by a test, and the guard says so.

**The translated readme travels with `readme.txt`, in the same PR.** `languages/readme-pt_BR.txt` is what "View details" shows a pt_BR administrator (#1716). `PluginDetailsAssetsTest` holds it to `readme.txt` by structure only — the same tab sections, the same count of other sections, the same screenshot captions — and cannot see a sentence: a changed answer in English passes CI with the translation still saying the old thing (#1718). Content agreement between two languages is not reachable by a test, so the discipline belongs to the PR that changes the English, and the release cross-check below reads the two side by side.

**Closing an issue — leave no false-positive checkboxes.** When an issue closes as `completed`, its acceptance-criteria / delivery checkboxes must reflect reality: either tick every delivered box (`- [x]`) **or** leave a short closing comment that maps each delivered item to the PR that shipped it. An issue that closes with unticked `- [ ]` boxes for work that actually shipped reads as pending to a later scan — this is exactly the false positive reconciled across #249, #647, #649, #650, #697 (delivered work split across separate PRs, boxes never revisited). Legitimately-deferred boxes stay unticked, but the closing comment must name them as deferred (see #711's engine deferral) so "unticked" is never ambiguous between *done* and *dropped*.

**`Closes #NNN` does not auto-close in this workflow — close feature issues by hand.** GitHub only auto-closes a linked issue when the PR merges into the repository's **default branch**, which is deliberately kept as `main` (see "Develop branch workflow"), not `develop`. So a feature PR that merges to `develop` with `Closes #NNN` in its body leaves the issue **open**. Close it manually — with the checkbox reconciliation above — as soon as its work lands on `develop` (matching the repo's precedent that issues close on work-complete, e.g. #711 / #591 / #249, not on release-to-prod); do **not** wait for the release PR to carry it to `main`, or done work lingers as an open, unticked issue (the #728 case). Genuinely-future work (a removal scheduled releases out, like #730) correctly stays open — this rule is about work that has actually shipped to `develop`.

#### Issues, pull requests and the merge queue

The shape of an issue and a PR lives in the templates — `.github/ISSUE_TEMPLATE/` (bug, feature, task) and `.github/PULL_REQUEST_TEMPLATE.md` (plus `PULL_REQUEST_TEMPLATE/release.md`, reached with `?template=release.md`). They are the layout; this section is the rules around them, and it is not repeated there. The same standard holds in Total Mail Queue, Email Signature Generator and Institutional Email.

- **An issue before the PR.** Every PR names the issue it closes. Exempt, and only these: a trivial docs or chore change, a Dependabot PR, the `release: X.Y.Z` bump and the CHANGELOG SHA backfill. An agent opens the issue itself, in English, from the task template — the issue is where the problem and the acceptance criteria are written down before the diff argues for one answer.
- **Titles.** Issue: `Area: the problem in one sentence`. PR: `type(scope): summary`, with `type` one of `feat`, `fix`, `docs`, `test`, `refactor`, `chore` and `scope` the area — it becomes the squash subject on `develop`, so it is written for the log. Release titles keep their own shape (see "Release PR").
- **Labels: one type, one area.** Type is `bug`, `enhancement`, `task`, `documentation` or `security`; area is an `area:` label mirroring `Core\PluginAreas`, plus `area:identity` and `area:infra`. `duplicate`, `invalid` and `wontfix` say why something closed unmerged, and `dependencies` / `composer` / `npm` / `github-actions` belong to Dependabot — its config applies them, so deleting one only makes it come back. **An agent cannot create a label**, only apply one that exists: a missing label is the maintainer's to create.
- **A PR carries the labels of the issue it closes.** The agent's tool opens a PR with no labels and cannot set them at creation, so the agent applies them as the next step, before marking it ready. Every issue and every PR carries its type and its area; a PR that only names its issue in the body is unlabelled until someone labels it, which is how the alignment PRs of 2026-10-12 went out bare in three repositories.
- **One merge at a time.** Only one PR is ready with auto-merge armed; every other one waits as a draft. After a merge the next PR takes `develop` by a **merge**, never a rebase, its conflicts are resolved — `CHANGELOG.md` keeps both bullets in heading order, `.mo` / `.l10n.php` are recompiled with `php .github/scripts/ffc-compile-translations.php`, never hand-edited — its touched tests run again, and only then is it flipped to ready and armed. Measured on #1685–#1687 (#1688): three PRs editing `[Unreleased]` and the catalogues conflict on every merge, and resolving each against a `develop` that has already moved is what keeps the resolution checkable.
- **After each merge.** The agent closes the issue by hand (`Closes` does not fire on `develop`, see above), ticking or naming each acceptance criterion.
- **Branches.** With Settings → General → "Automatically delete head branches" on, GitHub deletes a merged PR's branch. Whatever survives — a branch without a PR, a release or sync branch — the agent lists at the end of the batch for the maintainer to delete, since an agent cannot delete a ref ("Branch naming"); local worktrees the agent removes itself.

#### Branch naming

Use `claude/<short-kebab-description>` for feature branches that target `develop`. Examples in main history: `claude/js-coverage-sprint-B-audience-smoke`, `claude/csv-import-normalize-cpf-rf`. Use `hotfix/<short-kebab-description>` when cutting an urgent fix from `main` (see "Develop branch workflow" → Hotfixes). Always go through a PR — the PR is what gets the commit through CI, which a direct push does not.

**What an agent's credential can actually do, measured on the 6.25.0 release — because this was written down wrong for a long time.** This section used to claim the remote *refuses* pushes to `main` and `develop`. It does not: the post-release sync was a `git push --force-with-lease origin develop` by an agent and it went through, reporting `Bypassed rule violations for refs/heads/develop: 12 of 12 required status checks are expected`. The ruleset exists; the credential has **bypass**. So "go through a PR" is a rule about what you *should* do, never a wall that stops you — and a false wall is worse than no wall, because it stops people from checking.

The real limit is a different one, and it is not written anywhere else: **an agent cannot delete a ref at all.** `git push --delete` returns **HTTP 403** on any branch, feature branches included. Confirmed by probe — a throwaway branch was created solely to separate "this branch is protected" from "no deletion passes", and it is the second. So the credential **creates and updates refs, including by force, and never deletes**. Deleting a merged feature branch is the maintainer's to do; an agent that promises otherwise cannot deliver, and leaves the branch behind.

#### Develop branch workflow

Adopted v6.7.7 to decouple iteration cadence from production. Source domain (prod) only sees one version bump per batch; the testes domain runs `develop` HEAD and absorbs the per-PR churn.

##### Branch topology

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

##### Per-PR flow (the common case)

1. Feature branch `claude/<desc>` cut from `develop`.
2. PR targets `develop`. CI gates run identically to PRs against main.
3. **No `FFC_VERSION` bump in the feature PR.** Develop accumulates work under the existing `FFC_VERSION` until release time.
4. CHANGELOG entries go under the `[Unreleased]` section at the top of `CHANGELOG.md` — they stay there across multiple PRs.
5. Auto-merge enabled (SQUASH). Once merged, `deploy-develop.yml` pushes the new HEAD to the testes server within ~1 minute.

##### Dependabot PRs

Dependabot is configured with `target-branch: develop` for all three ecosystems (`composer`, `npm`, `github-actions`) in `.github/dependabot.yml`, so **version updates** open against `develop` correctly.

**Security updates are the exception:** GitHub always raises Dependabot *security* updates against the repository's default branch (`main`), ignoring `target-branch` — this is not configurable. The default branch is intentionally kept as `main` (changing it would have repo-wide side effects), so security-update PRs will keep being born against `main`.

**Rule — retarget to `develop`, and it is automatic (#2515):** whenever a Dependabot PR opens against `main` (in practice, always a security update), its base moves to `develop` with a one-line comment stating the reason (*every change funnels through `develop` and ships in the consolidated release PR, never as an out-of-band `main` commit*). `dependabot-automerge.yml` does it, in a step that runs **before** the one that turns on auto-merge: changing the base turns auto-merge off, and that same workflow used to enable auto-merge on a patch security update whatever its base, which is how total-mail-queue#173 and institutional-email#98 merged straight to `main` and made the next release PR conflict on `package-lock.json`. **The step only acts once it is on `main`**, because a `pull_request` workflow runs as the file stands on the PR's merge commit, which for a security update branched from `main` is `main`'s copy: until the step has shipped in a release, retarget by hand as below. The PR then rides the normal develop batch with auto-merge like any other; if its lockfile conflicts with `develop`, Dependabot rebases it.

**An agent cannot issue that command, and the failure is silent (#1201).** A comment posted through the agent's GitHub tooling has separator characters injected into bot mentions — `@dependabot rebase` arrived on the PR as `·@·d·ependabot r·ebase`, which Dependabot never parses, so nothing happens and nothing reports an error. The PR simply sits. **Do not retry the comment**; it will be mangled the same way.

What works instead, in this order:

1. **Check whether a rebase is needed at all.** It is only needed when the lockfile actually differs between `main` and `develop`: `git diff origin/main origin/develop -- package-lock.json composer.lock`. On the #1201 retarget that diff was **empty** — develop's batch had never touched the lockfile — so the PR's hunks applied to `develop` unchanged and there was nothing to recompute.
2. **If the PR is merely `behind`**, update the branch (`update_pull_request_branch`, the "Update branch" button). That is the whole fix in the common case, and it is what unblocked #1201: it had sat at `behind` for an hour with every gate already green, and merged 14 minutes after the update.
3. **Only if the lockfile genuinely conflicts** does the rebase matter — ask the user to post `@dependabot rebase`, since `@dependabot recreate` from a mangled comment is not an option either.

**#1201's open question is closed, and by observation rather than by reading the setting: `develop` requires a branch to be up to date before merging.** That is what the hour at `behind` with every gate green was — a property of the branch, not of Dependabot's flow or of the retarget. #1336 reproduced it on an ordinary two-line docs PR, which merged only after `update_pull_request_branch`, and its history carries the resulting `Merge branch 'develop' into …` commit — so the evidence lives in the PR rather than in anybody's memory. The rule that follows applies to **every** PR here: read `mergeable_state`, and treat `behind` as a step to take, never a state to wait out. Step 2 above is therefore the common fix on this repository generally, and `behind` is the expected state for any PR that `develop` moved under.

**Exception — genuine hotfix:** if the security fix is in a **runtime dependency** (shipped inside the plugin, not dev/CI tooling) *and* is severe enough to ship to production immediately, treat it as a hotfix (`hotfix/* → main`) instead of retargeting, then sync develop per "Sync `develop` with `main`". Dev/CI-only deps (`vitest`, `@vitest/coverage-v8`, `undici`, `js-yaml`, PHPStan, etc.) never qualify — they always ride the develop batch.

**That exception is unreachable through Dependabot today, and the reason is worth knowing** (measured on the #1201 retarget). The plugin ships **zero** managed third-party runtime dependencies: `composer.json`'s `require` is `php` alone, `package.json`'s `dependencies` is empty, and `.distignore` drops both `/vendor` and `/node_modules` from the zip. So every package Dependabot can see is dev/CI-only, and the decision is mechanical rather than a judgement call — check `require` / `dependencies` and the answer falls out.

The flip side is the part that matters: **the code that actually ships is invisible to Dependabot.** Five third-party bundles are hand-vendored in `libs/js/` — ALTCHA, thumbmark, html2canvas-pro, jsPDF and Nayuki's QR encoder (`qrcodegen`, #2742), each named `<library>-<version>.<ext>` with its licence beside it as `<library>-<version>.LICENSE.txt`, and listed in `VendoredBundleInventoryTest` — all of them enqueued at runtime, all inside the distributable, none in any manifest. A CVE in any of them will never open a PR here; it has to be noticed by a person, so what is running has to be readable off the tree. Tracked in #1202.

**That is why the version lives in the filename and the path is built from the constant**, never from a literal: `FFC_PLUGIN_URL . 'libs/js/jspdf-' . FFC_JSPDF_VERSION . '.umd.min.js'`. The two halves then cannot drift — bumping `FFC_JSPDF_VERSION` without renaming the file 404s the script, which breaks PDF generation loudly. With a literal path the constant is only the `?ver=` argument, so the same bump silently serves the **old** bundle under a new cache key, and nothing anywhere disagrees. `html2canvas` and `jspdf` were in exactly that state until #1203 renamed them. One thing that deliberately stays untouched: a vendored bundle's own `//# sourceMappingURL=` comment still names the upstream filename (`thumbmark` has had the same dangling comment since it was vendored, and no `.map` ships). Editing it would break byte-for-byte verification against upstream, which is the reason to vendor at all.

##### Release PR (`develop → main`)

When the batch on develop is validated against the testes site and ready to ship to prod:

1. Open a single PR `develop → main`.
2. In that PR (committed onto `develop` immediately before opening):
   - Bump `FFC_VERSION` in the three sync sites (`ffcertificate.php` header, `FFC_VERSION` constant, `readme.txt` `Stable tag`). See "Versioning".
   - Rename the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] (YYYY-MM-DD)` and add a fresh empty `[Unreleased]` heading above it. The release commit reference is appended to that heading later, at step 6 — **not** here, and not before the tag.
   - **Rewrite the `== Upgrade Notice ==` entry in `readme.txt` to summarise THIS release, replacing the previous one.** It is what WordPress prints under the plugin on Dashboard → Updates, so it is the only sentence an operator reads before deciding to apply the update — and the only place a ⚠ breaking change can warn them beforehand. **Exactly one entry, naming the version `Stable tag` declares, at most 300 characters**, ending with the section's pointer to `CHANGELOG.md` for the detail and the issues. One entry because `get_latest_release()` fetches `releases/latest`, so an entry for an older version is text no code path can display; 300 because that is the WordPress.org norm, adopted although the directory does not bind us, so publishing there later never means rewriting entries. `ReadmeUpgradeNoticeTest` enforces all three and calls the updater's own parser rather than mirroring it. **The translated readme carries the same entry, translated** (`languages/readme-pt_BR.txt`), because a pt_BR site is shown that one (#2342); the guard holds it to the same three rules and cannot see whether it says the same thing. **This is not a second changelog** — that mirror existed, drifted, and was deleted, which the file's own `== Changelog ==` section records; a 300-character summary of the version being offered is a different artefact from a per-release history, and the pointer is what keeps it honest rather than partial.
   - **Correct the `@since` tags the batch wrote against the wrong version.** A feature PR cannot know which release it will ship in, so an `@since` written mid-batch names the version `develop` was *sitting at*, not the one the code debuts in. Three classes did this in the 6.25.0 batch (`@since 6.24.0` on code that shipped in 6.25.0), while four others had guessed the next number correctly — so the file disagreed with itself. The release PR is the only moment the number is known; `git diff origin/main...origin/develop -- '*.php' | grep '^+.*@since'` finds them. Nothing else will: no gate reads these, and it was found by accident while looking for something else.
   - **Cross-check the documentation against the batch.** For each `Added` / `Changed` bullet in the section being released that changes what an operator sees or configures, confirm its topic changed in the batch: `git diff --stat origin/main...origin/develop -- includes/settings/views/documentation` lists the topics that did. A bullet with no matching topic is a gap to close before the tag, not after. This is a narrow check on purpose — rereading every topic each release is the step that gets skipped in silence; the full audit (#1638's method) is a periodic one-off.
   - **Cross-check the translated readme.** `git diff origin/main...origin/develop -- readme.txt languages/readme-pt_BR.txt`: a change on one side only is a translation left behind (see "Pull-request workflow").
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

   **A published Release body is the CHANGELOG section verbatim, and an agent cannot edit one.** The `Generate release notes from CHANGELOG` step awks the `## [X.Y.Z]` section out, dropping the heading and stopping at the next `## `, so the body and the file say the same thing by construction — until the file is edited afterwards, and then they disagree forever. Nothing reconciles them: `release.yml` runs once, on the tag. The GitHub MCP server exposes releases **read-only** (`list_releases`, `get_release_by_tag`, `get_latest_release`) and there is no `gh`, so editing a published body is the maintainer's to do, like deleting a ref. What an agent can deliver is the exact replacement text — run the workflow's own awk over the edited `CHANGELOG.md` and the output is byte-for-byte what that release would have published. Measured when #1260 translated the 6.23.0 / 6.24.0 / 6.25.0 sections, whose three Release bodies were left in Portuguese by this limit.
5. After merge, **sync `develop` with `main`** (see Sync below) so the next batch starts from the bumped baseline. After a *release* this is a hard `reset`, **not** a rebase — the squash already contains every develop commit, so a rebase tries to replay them all and conflicts.
6. **Backfill the release commit reference onto the CHANGELOG heading.** Append `` — `<short-sha>` `` to the `## [X.Y.Z]` heading, matching every other shipped version header. **The width is whatever `git rev-parse --short` returns for the squash commit on `main`, never a fixed number** — this is the same `--short` property step 4 warns about, seen from the other side: it gives the shortest *unambiguous* length, which grows with the repository. The shipped headings record that growth, which is how to re-read it: seven characters up to 6.28.4, eight from 6.29.0 on, mixed across 6.28.x because each heading carries what the tool returned the moment its backfill was written. This paragraph said "the 7-char short SHA" for four releases after the answer became eight, nine lines below the sentence that explains why it would. It lands on the **post-release `develop`** through a normal PR, and it must come **after** step 5: the sync is a hard reset to `main`, so a backfill landing before it is silently discarded — the order #1004 and `037001f` followed after 6.20.1 and 6.20.0. The consequence is structural, not a mistake to fix: the tag pushed at step 4 never carries its own suffix, and `main` acquires it one release later, when this commit rides the next release PR. So a missing suffix on the **newest** shipped heading is normal; a missing suffix on an **older** one means the backfill was genuinely skipped (as for 6.11.1 / 6.11.2, fixed retroactively).

**Landing the bump on `develop` (who can do step 2).** Step 2's bump commit has to sit on `develop` before the `develop → main` PR is opened. A maintainer with direct-push access commits it straight to `develop`. An **agent driving the release does not push to `develop` directly** — not because it cannot (see "Branch naming": the credential has bypass), but because a direct push skips CI on the very commit that sets the version every install will read. So it lands the bump via **one dedicated `release: X.Y.Z — bump + finalize CHANGELOG` PR to `develop`** (auto-merge), then opens the `develop → main` PR. That dedicated release-bump PR is **not** what "What not to do" forbids — that prohibition targets *feature* PRs sneaking a version bump; this is the deliberate release-moment bump, delivered through the only channel an agent has. Either way, the `develop → main` PR itself stays **draft until the user confirms the prod deploy**.

##### Hotfix flow (urgent fix that can't wait for the next release)

When a critical bug needs to ship to prod while develop has un-released commits:

1. `git fetch origin && git checkout -b hotfix/<desc> origin/main`.
2. Apply the fix. Bump `FFC_VERSION` as a real patch (e.g. `6.7.7 → 6.7.8`) — hotfixes consume patch numbers, not the `.x.y.z.N` cache-bust convention.
3. PR `hotfix/<desc> → main`. Auto-merge SQUASH.
4. **Then sync develop with the new main** (see below) so develop carries the hotfix and the next release PR doesn't try to "undo" it.

##### Sync `develop` with `main` (post-hotfix or post-release)

```bash
git fetch origin
git checkout develop
git rebase origin/main
git push --force-with-lease origin develop
```

This rewrites develop's SHAs on top of the new `main` tip. Force-push is permitted on `develop` by design (neither `develop` ruleset blocks force pushes or requires linear history) — see "Branch protection" below. If a feature PR was open against develop at the moment of the rebase, the PR author rebases their branch on the new develop tip; this is the cost of keeping develop linear.

**Post-release — use `reset`, not `rebase`.** The `rebase` above is the **post-hotfix** recipe, where develop still carries un-released commits to replay on top of the new `main`. After a **release** squash-merge, develop was *fully consumed* by the squash — every develop commit is already inside `main`'s single release commit, so a rebase tries to replay them all and conflicts (typically on `CHANGELOG.md`). In that case skip the rebase and reset develop straight to `main`:

```bash
git fetch origin
git checkout develop
git reset --hard origin/main
git push --force-with-lease origin develop
```

Confirm nothing is lost first with `git log --oneline origin/main..develop` — after a release those are only the pre-squash commits, whose content already lives in `main`. (This is the trap that bit the 6.15.0 sync.)

##### Branch protection (rulesets on `develop` and `main`)

Both branches are governed by **rulesets** (Settings → Rules → Rulesets), not classic branch protection — converted on 2026-10-07. Each branch carries **two** rulesets, and the split is deliberate, not duplication: rules in different rulesets stack, so each ruleset's **bypass list** applies only to its own rules.

| Ruleset | Rules | Bypass |
| --- | --- | --- |
| `develop-locked` | Restrict deletions | **none** |
| `develop-ci-gates` | Require a pull request (0 approvals) · Require status checks · branches up to date (strict) | Repository admin |
| `main-locked` | Restrict deletions · **Block force pushes** | **none** |
| `main-ci-gates` | Require a pull request (0 approvals) · Require status checks · branches up to date (strict) | Repository admin |

- **`*-locked` holds what nobody may ever do, the maintainer included**: delete either branch, or rewrite `main`. Merging the pair into one ruleset would put those rules behind the admin bypass, which is why there are two.
- **`*-ci-gates` holds what an emergency may need to skip.** The admin bypass is what lets the post-release sync land — that force-push is not a PR and does not wait for checks — and it is the bypass the agent's credential reports using (see "Branch naming").
- **Force push stays allowed on `develop` and nowhere else.** `Block force pushes` is off in both `develop` rulesets because the "Sync `develop` with `main`" recipes above depend on it; on `main` it is on, without bypass. Turning it on for `develop` would break the next release's sync, not just inconvenience it.
- **The required checks are every gating job in "CI gates", by job name** — including both `Fresh install` legs, `Analyze (javascript)` (CodeQL) and `Row shapes (level 9)` — with GitHub Actions as their source. The shard jobs are not listed: `Coverage (PHP 8.3)` and `PHPUnit (PHP 8.3)` gate the `Coverage shard N` jobs, and `PHPUnit (PHP 8.4)` gates the `PHPUnit 8.4 shard N` jobs. Each gate keeps a required name and runs nothing itself, so the shard count can change without touching branch protection; `PHPUnit (PHP 8.3)` has no plain run of its own because the coverage shards are that run (#2058). The invariant that matters when adding a job: **a required check must run on every PR to that branch** (no `paths:` filter, no job-level `if:` that can skip it), or every PR waits forever on a check that never reports — the reason `assets.yml` dropped its path filters.
- **Off on purpose**: linear history (the merge-commit and rebase flows above need it off), required deployments (`deploy-develop.yml` runs *after* merge), signed commits, required reviewers (solo maintainer).

**When the conversion was read back, "Require a pull request" existed on neither branch**, although this section had listed it for `develop` for months. Each branch then held a pre-existing ruleset beside the converted one, and neither required a PR; a direct push by a non-admin was held back only by the status-check rule's "must first pass on another ref" behaviour — close to a PR requirement, and not the same thing. The same reading found `Analyze (javascript)` and `Row shapes (level 9)` running on every PR and gating nothing, although "CI gates" listed CodeQL as gating. All three were fixed in the UI on that date.

**An agent cannot read this configuration, so the table above is a record and not a reading.** The GitHub MCP server exposes no ruleset or branch-protection endpoint — `list_branches` answers `protected: true` and nothing further — and there is no `gh`. The table is the maintainer's record of the UI on the date above, and the paragraph just before it is the measured cost of treating such a record as current: check the UI before relying on any line of it.

##### Deploy to testes

`.github/workflows/deploy-develop.yml` runs on every `push` to `develop` and rsyncs the working tree to the testes server. Required GitHub secrets (Settings → Secrets and variables → Actions):

| Secret | Example | Notes |
| --- | --- | --- |
| `TESTES_SSH_HOST` | `ssh.testes.example.com` or `185.239.210.8` | DNS or IP of the testes host. **Hostname only, no port, no protocol prefix.** |
| `TESTES_SSH_USER` | `wp-deploy` | Account with write access to the plugin dir |
| `TESTES_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----…` | Private half of a dedicated keypair; public half goes in `~/.ssh/authorized_keys` on the testes host. **Must have no passphrase** — generate with `ssh-keygen -t ed25519 -N "" -f <path>`. GitHub Actions cannot enter passphrases interactively; a passphrase-protected key surfaces as `Permission denied (publickey,password)` in the rsync step, indistinguishable from a wrong key. |
| `TESTES_SSH_PORT` | `65002` | **Optional.** Defaults to `22`. Managed hosting (Hostinger, KingHost, Locaweb) usually exposes SSH on a high port — set this when so. |
| `TESTES_REMOTE_PATH` | `/var/www/testes/wp-content/plugins/ffcertificate` | Absolute path; no trailing slash |

The rsync uses `--delete`, so anything in the remote path that isn't in the develop working tree is removed on each deploy. The workflow excludes `.git/`, `.github/`, `vendor/`, `node_modules/`, `tests/`, and dev tooling (PHPStan, PHPUnit, PHPCS configs) — those don't belong in a runtime plugin dir.

**The path is checked before anything is sent, because `--delete` empties whatever directory it is pointed at.** The workflow refuses a `TESTES_REMOTE_PATH` that is not absolute or does not end in `/wp-content/plugins/ffcertificate`. The check needs no connection: rsync creates only the last component, so a wrong prefix fails rather than building a tree. Total Mail Queue's first deploy is why this exists: it ran with a placeholder path and left a stray copy in the home directory, and pointed at an existing directory the same mistake would have emptied it. What the check cannot do is tell the testes site from production, because one hosting account serves both. That stays the secret's job.

The testes server should have `SCRIPT_DEBUG=true` in `wp-config.php`. It serves the non-minified assets, and it is also what makes `AssetHelper::register_dev_cache_busting()` version every plugin asset by its file time (`ver=FFC_VERSION.<mtime>`), so a merge reaches the browser without a release bump. **`SCRIPT_DEBUG` alone never did that** — it swaps `.min` for the readable file under the same `?ver=`, and this sentence claimed otherwise until #1594, when a browser kept a pre-#1587 script and #1593's overlay never opened.

**Filling the testes site with fictitious data is `.github/scripts/testes-seed.php` (#1855).** It empties every `ffc_*` table `uninstall.php` declares (read through `ffc-uninstall-manifest.php`), the plugin's post types, the `@example.test` accounts and the `ffc` transients, then writes 20+ records per module through the plugin's own repositories, so CPF, RF and e-mail are encrypted and hashed with that site's key, which no SQL dump can do. Real users, a real user's profile row and every setting stay. The deploy excludes `.github/`, so copy the script and the manifest to one directory on the host and run `php testes-seed.php /path/to/wp-load.php --yes-wipe --site=<home_url>` (or `wp eval-file testes-seed.php yes-wipe site=<home_url>`: WP-CLI rejects an unknown `--flag`, even after `--`). **The guard is the URL**: without the flag, or with a `--site` that is not `home_url()`, it refuses and changes nothing, because testes and production share one hosting account and the URL is the only thing that tells them apart. It blocks e-mail while it runs and unschedules the wp-cron events it queued; a second run leaves the same counts.

##### Post-deploy smoke (alarm, not a gate)

After the rsync, `deploy-develop.yml` scp's `.github/scripts/testes-smoke.php` to the host, runs it over SSH and deletes it. It boots the real WordPress and asserts three things, then reports the scheduled `ffc*` crons without judging them (which crons should exist depends on the per-module toggles, so a strict expectation would fail on a deliberate configuration):

1. **The plugin is active** — a deploy that lands files onto a deactivated plugin looks healthy from the filesystem and does nothing.
2. **The host's live `FFC_VERSION` equals the version in the commit being deployed.** This is the #628 check: the rsync backoff was once shorter than a managed-hosting restart, so every attempt fell inside the same outage and the site quietly stayed on a stale version while the workflow reported success.
3. **Every table `uninstall.php` knows about exists.** The expected list is *parsed out of `uninstall.php`* rather than duplicated — that file is already obliged to know every table, so a new one is covered the moment it is added there. It is read as text and never included (including it would run the uninstaller). If the parse yields nothing the smoke **fails loudly** instead of passing on an empty list.

This exists because the host is the only place with the provider's real MariaDB, PHP SAPI and wp-config — the stack that produced the dbDelta failures CI cannot reproduce (#358 backtick comments read as columns, #822 a migration pointing at a table that never existed, 6.0.1 `COMMENT` clauses leaving four recruitment tables uncreated).

Two constraints to keep in mind before extending it. **It can never be a merge gate** — this workflow fires on push to `develop`, so the merge already happened; gating belongs in CI, against a MySQL service. And it runs against an **established install**, so the table check proves the tables are there, not that a fresh activation would create them; catching a regression in the `CREATE` of an existing table needs a throwaway *WordPress install* (not merely an empty database — `Activator::activate()` reads and writes options). That is the `fresh-install` CI job (see "Quality gates and testing" → "Fresh-install gate"), delivered in #994.

**The step blocks: a red smoke is a red deploy job.** It ran under `continue-on-error: true` while it proved itself against the host's PHP CLI, and #992 set the bar at 10 consecutive green smokes — an evidence gate, not the release-cycle deprecation, which exists for surfaces whose consumers a code scan cannot see; here every deploy is evidence. Runs 525-537 delivered 13, verified from the log output rather than the step conclusion, and the flag came off in #1007. Two exits stay green on purpose. A **timeout** (124) is a warning, not a failure: the rsync has already succeeded, so a host that does not answer in 120s is latency, not a plugin defect, and reddening deploys for it is how an alarm becomes noise people learn to skip. And a host whose CLI is older than the plugin's PHP floor **skips** with an explanation rather than failing forever — point the workflow at the host's newer binary (often `php83`) to enable it. Everything else — a stale version, a missing table, a fatal in the plugin — now fails the job. **So does a boot that ends before the verdict.** `wp_die()` on an unreachable database prints its page and exits **0**, which read as a pass with no check run until a shutdown guard turned any early end into a failure. The gap was measured on a local install with the database stopped. The reason the evidence had to come from the log is worth keeping: while the flag was on, a *failing* smoke still showed the deploy job as green, so the job conclusion proved nothing and only the step's own `SMOKE PASSED` line did.

#### Versioning

Three places carry the plugin version and must stay in sync:

1. `ffcertificate.php` plugin header — `* Version: X.Y.Z`. Parsed by WordPress core BEFORE PHP runs, so it must be a literal string.
2. `ffcertificate.php` PHP constant — `define( 'FFC_VERSION', 'X.Y.Z' )`. Source of `?ver=…` on every `wp_enqueue_*` call.
3. `readme.txt` `Stable tag: X.Y.Z`. Parsed by WordPress.org before PHP runs; also a literal string.

When changing the version, update all three in the same commit. `PluginIdentityTest` fails when they disagree, when the released version has no `## [X.Y.Z]` section in `CHANGELOG.md`, and when the PHP or WordPress floor in the header differs from `readme.txt`, `composer.json` (`require` and `config.platform`) or the `testVersion` / `minimum_wp_version` that `phpcs.xml.dist` judges the code against. The floors there had drifted for two releases (8.1 / 6.2 against 8.3 / 6.8) when it was written (#1675).

##### Patch vs. cache-bust-only releases

- A "real" patch release (any source-code change) consumes the next patch number: `6.6.2 → 6.6.3`.
- A **cache-bust-only release** (no functional change — exists purely to rotate the `?ver=…` asset cache key after a prior PR shipped an updated `.min.js` / `.min.css` without bumping the version) uses a 4th segment appended to the prior version: `6.6.2 → 6.6.2.1`. The next cache-bust sibling of the same minor would be `6.6.2.2`, and so on. WordPress's `version_compare()` and the plugin update flow both handle 4-segment versions without special-casing.
- Reason for the convention: a cache-bust release carries no new user-visible behavior, only a key rotation. Burning a real patch number on it would imply meaningful changes that aren't there.

##### When to bump

The trigger has not changed — bundled-asset changes still rotate the cache key. What changed with the develop branch workflow is **where the bump lands**:

- **PRs targeting `main`** (release PR `develop → main`, hotfix PR `hotfix/* → main`): bump `FFC_VERSION` in the same PR. The release PR consolidates every `assets/**/*.min.js`, `assets/**/*.min.css`, `templates/**.php`, and `languages/*.l10n.php` / `.mo` change from the develop batch under one version. Hotfix PRs bump their own patch number.
- **PRs targeting `develop`**: do **not** bump. Develop sits at the last released version (the cache key on the testes domain stays stable across the batch), and the testes site sidesteps it through the file-time versioning `SCRIPT_DEBUG=true` turns on (see "Deploy to testes"). Bumping per-PR on develop would consume version numbers that have no production analog.

The "Verify minified assets are up to date" CI job catches build freshness on both bases but does NOT enforce the version bump — that's still a human discipline on the release PR.

#### CHANGELOG conventions

`CHANGELOG.md` follows Keep a Changelog. Per change:

- One entry under the top `[Unreleased]` section, grouped by heading (`Added` / `Changed` / `Fixed` / `Security` / `Removed` / `Deprecated`). Entries stay in `[Unreleased]` across PRs until the release PR renames the heading (see "Release PR").
- **Always cite the issue/PR** (`(#NNN)` / `#NNN`). Every `[Unreleased]` bullet must carry a reference — the linked PR holds the granular detail.
- **Append the bullet at the END of its heading's list, never at a fixed point inside it — this removes the DECISION, not the conflict.** Two branches open against `develop` at the same time both edit `[Unreleased]`, and appending does not stop them colliding: two appends land on the same line and git reports it exactly as an insert would. What appending buys is that the resolution is always *keep both, in either order*, with nothing to judge — where inserting before a chosen existing bullet makes the conflict about where the new line goes relative to someone else's. Measured across the #1260 arc, which ran six slices: five conflicts, every one resolved by keeping both. The first version of this bullet claimed appending prevents the conflict; it was written between the two slices that then collided by appending, which is how the claim survived being false. Order of arrival has no value here anyway, because the release PR condenses the section by story.
- **No internal roadmap codenames** in the prose — no "Sprint N", "phase N", or letter-codes (`A6`, `B3`, `E5`, …). Describe the change itself and keep entries concise (one tight paragraph, not a wall of class-by-class text).
- Ordinary words that happen to look like codes — "A4" (paper size), "four-phase flow" (literal steps) — are fine; the rule targets roadmap taxonomy only.
- **Aim for ≤300 characters per bullet.** A *soft* target, not a CI gate — a bullet may exceed it when the detail genuinely earns the length (a breaking-change banner, a subtle regression), but prefer trimming first: the linked PR holds the granular detail, so the CHANGELOG line only needs the *what* + the reference. When a batch accumulates several long or near-duplicate bullets, condense before the release PR (the precedent that set this: the #772-era `[Unreleased]` condensation).

  **6.25.0 measured what "condense" means at scale, because the rule said to do it without saying how much or how to notice it was time.** That `[Unreleased]` reached **85 bullets and 35 KB, median 404 characters**, and went out at **37 bullets and 10.6 KB, median 287** — 70% less text, and still longer than 6.24.0's, which shipped 40 bullets for a smaller batch. Two signals said it was overdue and both are cheap to check: the **median bullet length** sitting well above the 300 target, and **duplicate headings** (`### Added` ×2, `### Changed` ×2) — the tell that batches were appended without anyone re-reading the section as a whole. Condense **by story, not by PR**: nine bullets citing one issue became four, and two CSS issues dissolved into the themes they belonged to. Then prove nothing was lost by measurement rather than memory — every issue that *delivered* work must still be cited (41 of them, checked programmatically); references that leave are only the precedents quoted in prose. This matters more than it looks: `release.yml` extracts that section verbatim as the **public Release notes**, so the section is not an internal file, it is what the user reads.

#### What not to do

- Do not amend or rewrite published commits on `main`. On `develop`, force-push is permitted only for the documented sync-with-main rebase ("Develop branch workflow" → Sync) — never to rewrite arbitrary history.
- Do not skip hooks (`--no-verify`) or signing.
- Do not bypass the coverage floor — bump it forward or restore the lost coverage. Never lower it — the sole exception is an honest re-measure after deleting covered **product** code (never tests); see "CI gates".
- Do not add new untested code paths in a coverage-aware PR; either cover them in the same PR or document the deferral.
- Do not target `main` directly from a feature PR. The only PRs that base on `main` are the release PR (`develop → main`) and hotfix PRs (`hotfix/* → main`).
- Do not bump `FFC_VERSION` in a **feature** PR that targets `develop` — the bump belongs to the release. (The lone exception is the dedicated `release: X.Y.Z` bump PR an agent uses to land the bump on `develop` when it can't direct-push; see "Release PR" → "Landing the bump on `develop`".)

---
