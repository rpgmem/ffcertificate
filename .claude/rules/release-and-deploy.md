---
paths:
  - ".github/workflows/**"
  - ".github/scripts/**"
---

# Release, hotfix, sync and deploy

This is the release half of `CLAUDE.md` "Develop branch workflow", moved here (#2865). It loads when a session touches `.github/workflows/` or `.github/scripts/`, and `CLAUDE.md` sends a session here before any release, hotfix, sync or deploy work. The branch topology, the per-PR flow, Dependabot, versioning and the CHANGELOG conventions stay in `CLAUDE.md`.

## Release PR (`develop → main`)

When the batch on develop is validated against the testes site and ready to ship to prod:

1. Open a single PR `develop → main`.
2. In that PR (committed onto `develop` immediately before opening):
   - Bump `FFC_VERSION` in the three sync sites (`ffcertificate.php` header, `FFC_VERSION` constant, `readme.txt` `Stable tag`). See `CLAUDE.md` "Versioning".
   - Rename the `[Unreleased]` heading in `CHANGELOG.md` to `[X.Y.Z] (YYYY-MM-DD)` and add a fresh empty `[Unreleased]` heading above it. The release commit reference is appended to that heading later, at step 6 — **not** here, and not before the tag.
   - **Rewrite the `== Upgrade Notice ==` entry in `readme.txt` to summarise THIS release, replacing the previous one.** It is what WordPress prints under the plugin on Dashboard → Updates, so it is the only sentence an operator reads before deciding to apply the update — and the only place a ⚠ breaking change can warn them beforehand. **Exactly one entry, naming the version `Stable tag` declares, at most 300 characters**, ending with the section's pointer to `CHANGELOG.md` for the detail and the issues. One entry because `get_latest_release()` fetches `releases/latest`, so an entry for an older version is text no code path can display; 300 because that is the WordPress.org norm, adopted although the directory does not bind us, so publishing there later never means rewriting entries. `ReadmeUpgradeNoticeTest` enforces all three and calls the updater's own parser rather than mirroring it. **The translated readme carries the same entry, translated** (`languages/readme-pt_BR.txt`), because a pt_BR site is shown that one (#2342); the guard holds it to the same three rules and cannot see whether it says the same thing. **This is not a second changelog** — that mirror existed, drifted, and was deleted, which the file's own `== Changelog ==` section records; a 300-character summary of the version being offered is a different artefact from a per-release history, and the pointer is what keeps it honest rather than partial.
   - **Correct the `@since` tags the batch wrote against the wrong version.** A feature PR cannot know which release it will ship in, so an `@since` written mid-batch names the version `develop` was *sitting at*, not the one the code debuts in. The release PR is the only moment the number is known; `git diff origin/main...origin/develop -- '*.php' | grep '^+.*@since'` finds them. No gate reads these.
   - **Cross-check the documentation against the batch.** For each `Added` / `Changed` bullet in the section being released that changes what an operator sees or configures, confirm its topic changed in the batch: `git diff --stat origin/main...origin/develop -- includes/settings/views/documentation` lists the topics that did. A bullet with no matching topic is a gap to close before the tag, not after. This is a narrow check on purpose — rereading every topic each release is the step that gets skipped in silence; the full audit (#1638's method) is a periodic one-off.
   - **Cross-check the translated readme.** `git diff origin/main...origin/develop -- readme.txt languages/readme-pt_BR.txt`: a change on one side only is a translation left behind (see `CLAUDE.md` "Pull-request workflow").
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
   **The checks are chained with `&&` because a check that only prints does not stop anything.** The tag has landed on the wrong commit twice: once pushed onto a local `main` the `pull` had not yet advanced, and once with this guard written down and followed, because the `pull --ff-only` aborted on a dirty `package-lock.json` and the recipe was pasted as one block, so `git tag` ran after the check printed the wrong values. The check must be able to interrupt. Two mechanics, both easy to get wrong:

   - **Compare against `origin/main`, never a literal SHA.** `git rev-parse --short` returns the shortest *unambiguous* length, which grows with the repository, so a hard-coded short SHA rejects a correct HEAD. The comparison above states the real invariant: *this is the commit that is `main`'s tip, and it declares the version being tagged*.
   - **`git fetch --tags` does not move a local tag that already exists.** After deleting and re-pushing a tag, a local checkout keeps pointing at the bad commit. Read `git ls-remote origin refs/tags/vX.Y.Z`, or fetch with `--force`.

   The tagged commit's `Version:` header must already equal `X.Y.Z` (true post-bump) or the workflow's sanity check fails. **That check is a backstop, not the guard** — it fires after the tag is already public. It failing is the good outcome (nothing is published, so there is no wrong zip or Release to retract), but the tag still has to be deleted and re-pushed. The tagged tree will **not** carry its own CHANGELOG short-SHA suffix, and that is expected — see step 6. Pushing the tag publishes a production release (public zip + Release notes), so **an agent surfaces this sequence for the user to run rather than pushing the tag itself** — the same production-deploy sign-off that keeps the `develop → main` PR draft until the user confirms.

   **A published Release body is the CHANGELOG section verbatim, and an agent cannot edit one.** The `Generate release notes from CHANGELOG` step awks the `## [X.Y.Z]` section out, so the body and the file agree by construction until the file is edited afterwards, and nothing reconciles them: `release.yml` runs once, on the tag. The GitHub MCP server exposes releases **read-only** and there is no `gh`, so editing a published body is the maintainer's to do. What an agent can deliver is the exact replacement text: run the workflow's own awk over the edited `CHANGELOG.md`.
5. After merge, **sync `develop` with `main`** (see Sync below) so the next batch starts from the bumped baseline. After a *release* this is a hard `reset`, **not** a rebase — the squash already contains every develop commit, so a rebase tries to replay them all and conflicts.
6. **Backfill the release commit reference onto the CHANGELOG heading.** Append `` — `<short-sha>` `` to the `## [X.Y.Z]` heading, matching every other shipped version header. **The width is whatever `git rev-parse --short` returns for the squash commit on `main`, never a fixed number** — the same growing length step 4 warns about. It lands on the **post-release `develop`** through a normal PR, and it must come **after** step 5: the sync is a hard reset to `main`, so a backfill landing before it is silently discarded. The consequence is structural, not a mistake: the tag pushed at step 4 never carries its own suffix, and `main` acquires it one release later, when this commit rides the next release PR. So a missing suffix on the **newest** shipped heading is normal; a missing suffix on an **older** one means the backfill was skipped.

**Landing the bump on `develop` (who can do step 2).** Step 2's bump commit has to sit on `develop` before the `develop → main` PR is opened. A maintainer with direct-push access commits it straight to `develop`. An **agent driving the release does not push to `develop` directly** — not because it cannot (see `CLAUDE.md` "Branch naming": the credential has bypass), but because a direct push skips CI on the very commit that sets the version every install will read. So it lands the bump via **one dedicated `release: X.Y.Z — bump + finalize CHANGELOG` PR to `develop`** (auto-merge), then opens the `develop → main` PR. That dedicated release-bump PR is **not** what `CLAUDE.md` "What not to do" forbids — that prohibition targets *feature* PRs sneaking a version bump; this is the deliberate release-moment bump, delivered through the only channel an agent has. Either way, the `develop → main` PR itself stays **draft until the user confirms the prod deploy**.

## Hotfix flow (urgent fix that can't wait for the next release)

When a critical bug needs to ship to prod while develop has un-released commits:

1. `git fetch origin && git checkout -b hotfix/<desc> origin/main`.
2. Apply the fix. Bump `FFC_VERSION` as a real patch (e.g. `6.7.7 → 6.7.8`) — hotfixes consume patch numbers, not the `.x.y.z.N` cache-bust convention.
3. PR `hotfix/<desc> → main`. Auto-merge SQUASH.
4. **Then sync develop with the new main** (see below) so develop carries the hotfix and the next release PR doesn't try to "undo" it.

## Sync `develop` with `main` (post-hotfix or post-release)

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

Confirm nothing is lost first with `git log --oneline origin/main..develop` — after a release those are only the pre-squash commits, whose content already lives in `main`.

## Branch protection (rulesets on `develop` and `main`)

Both branches are governed by **rulesets** (Settings → Rules → Rulesets), not classic branch protection — converted on 2026-10-07. Each branch carries **two** rulesets, and the split is deliberate, not duplication: rules in different rulesets stack, so each ruleset's **bypass list** applies only to its own rules.

| Ruleset | Rules | Bypass |
| --- | --- | --- |
| `develop-locked` | Restrict deletions | **none** |
| `develop-ci-gates` | Require a pull request (0 approvals) · Require status checks · branches up to date (strict) | Repository admin |
| `main-locked` | Restrict deletions · **Block force pushes** | **none** |
| `main-ci-gates` | Require a pull request (0 approvals) · Require status checks · branches up to date (strict) | Repository admin |

- **`*-locked` holds what nobody may ever do, the maintainer included**: delete either branch, or rewrite `main`. Merging the pair into one ruleset would put those rules behind the admin bypass, which is why there are two.
- **`*-ci-gates` holds what an emergency may need to skip.** The admin bypass is what lets the post-release sync land — that force-push is not a PR and does not wait for checks — and it is the bypass the agent's credential reports using (see `CLAUDE.md` "Branch naming").
- **Force push stays allowed on `develop` and nowhere else.** `Block force pushes` is off in both `develop` rulesets because the "Sync `develop` with `main`" recipes above depend on it; on `main` it is on, without bypass. Turning it on for `develop` would break the next release's sync, not just inconvenience it.
- **The required checks are every gating job in `CLAUDE.md` "CI gates", by job name** — including both `Fresh install` legs, `Analyze (javascript)` (CodeQL) and `Row shapes (level 9)` — with GitHub Actions as their source. The shard jobs are not listed: `Coverage (PHP 8.3)` and `PHPUnit (PHP 8.3)` gate the `Coverage shard N` jobs, and `PHPUnit (PHP 8.4)` gates the `PHPUnit 8.4 shard N` jobs. Each gate keeps a required name and runs nothing itself, so the shard count can change without touching branch protection; `PHPUnit (PHP 8.3)` has no plain run of its own because the coverage shards are that run (#2058). The invariant that matters when adding a job: **a required check must run on every PR to that branch** (no `paths:` filter, no job-level `if:` that can skip it), or every PR waits forever on a check that never reports — the reason `assets.yml` dropped its path filters.
- **Off on purpose**: linear history (the merge-commit and rebase flows above need it off), required deployments (`deploy-develop.yml` runs *after* merge), signed commits, required reviewers (solo maintainer).

**An agent cannot read this configuration, so the table above is a record and not a reading.** The GitHub MCP server exposes no ruleset or branch-protection endpoint — `list_branches` answers `protected: true` and nothing further — and there is no `gh`. The table is the maintainer's record of the UI on the date above, which has already disagreed with the UI once: check the UI before relying on any line of it.

## Deploy to testes

`.github/workflows/deploy-develop.yml` runs on every `push` to `develop` and rsyncs the working tree to the testes server. Required GitHub secrets (Settings → Secrets and variables → Actions):

| Secret | Example | Notes |
| --- | --- | --- |
| `TESTES_SSH_HOST` | `ssh.testes.example.com` or `185.239.210.8` | DNS or IP of the testes host. **Hostname only, no port, no protocol prefix.** |
| `TESTES_SSH_USER` | `wp-deploy` | Account with write access to the plugin dir |
| `TESTES_SSH_KEY` | `-----BEGIN OPENSSH PRIVATE KEY-----…` | Private half of a dedicated keypair; public half goes in `~/.ssh/authorized_keys` on the testes host. **Must have no passphrase** — generate with `ssh-keygen -t ed25519 -N "" -f <path>`. GitHub Actions cannot enter passphrases interactively; a passphrase-protected key surfaces as `Permission denied (publickey,password)` in the rsync step, indistinguishable from a wrong key. |
| `TESTES_SSH_PORT` | `65002` | **Optional.** Defaults to `22`. Managed hosting (Hostinger, KingHost, Locaweb) usually exposes SSH on a high port — set this when so. |
| `TESTES_REMOTE_PATH` | `/var/www/testes/wp-content/plugins/ffcertificate` | Absolute path; no trailing slash |

The rsync uses `--delete`, so anything in the remote path that isn't in the develop working tree is removed on each deploy. The workflow excludes `.git/`, `.github/`, `vendor/`, `node_modules/`, `tests/`, and dev tooling (PHPStan, PHPUnit, PHPCS configs) — those don't belong in a runtime plugin dir.

**The path is checked before anything is sent, because `--delete` empties whatever directory it is pointed at.** The workflow refuses a `TESTES_REMOTE_PATH` that is not absolute or does not end in `/wp-content/plugins/ffcertificate`. The check needs no connection: rsync creates only the last component, so a wrong prefix fails rather than building a tree. What the check cannot do is tell the testes site from production, because one hosting account serves both; that stays the secret's job.

The testes server should have `SCRIPT_DEBUG=true` in `wp-config.php`. It serves the non-minified assets, and it is also what makes `AssetHelper::register_dev_cache_busting()` version every plugin asset by its file time (`ver=FFC_VERSION.<mtime>`), so a merge reaches the browser without a release bump. `SCRIPT_DEBUG` on its own only swaps `.min` for the readable file under the same `?ver=`; the file-time versioning is what busts the cache.

**Filling the testes site with fictitious data is `.github/scripts/testes-seed.php` (#1855).** It empties every `ffc_*` table `uninstall.php` declares (read through `ffc-uninstall-manifest.php`), the plugin's post types, the `@example.test` accounts and the `ffc` transients, then writes 20+ records per module through the plugin's own repositories, so CPF, RF and e-mail are encrypted and hashed with that site's key, which no SQL dump can do. Real users, a real user's profile row and every setting stay. The deploy excludes `.github/`, so copy the script and the manifest to one directory on the host and run `php testes-seed.php /path/to/wp-load.php --yes-wipe --site=<home_url>` (or `wp eval-file testes-seed.php yes-wipe site=<home_url>`: WP-CLI rejects an unknown `--flag`, even after `--`). **The guard is the URL**: without the flag, or with a `--site` that is not `home_url()`, it refuses and changes nothing, because testes and production share one hosting account and the URL is the only thing that tells them apart. It blocks e-mail while it runs and unschedules the wp-cron events it queued; a second run leaves the same counts.

## Post-deploy smoke (alarm, not a gate)

After the rsync, `deploy-develop.yml` scp's `.github/scripts/testes-smoke.php` to the host, runs it over SSH and deletes it. It boots the real WordPress and asserts three things, then reports the scheduled `ffc*` crons without judging them (which crons should exist depends on the per-module toggles, so a strict expectation would fail on a deliberate configuration):

1. **The plugin is active** — a deploy that lands files onto a deactivated plugin looks healthy from the filesystem and does nothing.
2. **The host's live `FFC_VERSION` equals the version in the commit being deployed.** A managed-hosting restart can outlast the rsync's retries, so the site quietly stays on a stale version while the workflow reports success.
3. **Every table `uninstall.php` knows about exists.** The expected list is *parsed out of `uninstall.php`* rather than duplicated — that file is already obliged to know every table, so a new one is covered the moment it is added there. It is read as text and never included (including it would run the uninstaller). If the parse yields nothing the smoke **fails loudly** instead of passing on an empty list.

This exists because the host is the only place with the provider's real MariaDB, PHP SAPI and wp-config — the stack that has produced dbDelta failures CI could not reproduce.

Two constraints to keep in mind before extending it. **It can never be a merge gate** — this workflow fires on push to `develop`, so the merge already happened; gating belongs in CI. And it runs against an **established install**, so the table check proves the tables are there, not that a fresh activation would create them; that is the `fresh-install` CI job (`CLAUDE.md` "Quality gates and testing" → "Fresh-install gate").

**The step blocks: a red smoke is a red deploy job.** Two exits stay green on purpose. A **timeout** (124) is a warning: the rsync has already succeeded, so a host that does not answer in 120s is latency, not a plugin defect, and reddening deploys for it is how an alarm becomes noise people learn to skip. And a host whose CLI is older than the plugin's PHP floor **skips** with an explanation — point the workflow at the host's newer binary (often `php83`). Everything else — a stale version, a missing table, a fatal in the plugin — fails the job, and **so does a boot that ends before the verdict**: `wp_die()` on an unreachable database exits **0**, so a shutdown guard turns any early end into a failure. Trust the step's own `SMOKE PASSED` line, not an exit code. How the step earned its blocking status is in `docs/engineering/workflow-history.md`.
