# Contributing to Free Form Certificate

Thanks for your interest in improving the plugin. This document covers the
local setup, the conventions we follow, and the checks that run in CI.

## Requirements

- PHP 8.1+ (matches the plugin's minimum runtime)
- Composer 2
- Node.js 20 (only required when touching `assets/`)

## Setup

```bash
composer install
npm ci          # only needed if you will rebuild assets
npm run setup-hooks   # installs the pre-commit hook that auto-rebuilds assets
```

The pre-commit hook lives in `.githooks/pre-commit` and re-minifies any
staged source under `assets/css/` or `assets/js/`, then re-stages the
generated `*.min.*` files.

## Branches

- `main` is protected; all changes land via pull request.
- Feature branches use a short descriptive name (e.g. `fix/email-hash`
  or `feat/user-profile-service`). AI-assisted branches opened via the
  Claude Code session use the `claude/<short-description>` prefix.
- CI runs on `pull_request` (and on `push` to `main` post-merge), so open
  the PR — even as a draft — to get a green/red signal. Pushing to a
  feature branch without a PR will not trigger CI.
- Dependabot manages `chore(deps)` / `chore(ci)(deps)` branches.

## Commit messages

Follow [Conventional Commits](https://www.conventionalcommits.org/). Recent
history shows the styles we use:

- `feat(scope): ...` — user-visible feature
- `fix(scope): ...` — bug fix
- `chore(ci): ...` / `chore(ci)(deps): ...` — CI or dependency bumps
- `ci: ...` — workflow tweaks
- `docs: ...` — documentation only

Keep the subject under 72 characters and explain *why* in the body when the
diff isn't self-explanatory.

## Static analysis conventions

A few PHPStan annotations recur in repository code; they are deliberate, not
ad-hoc suppressions:

- `@phpstan-ignore-next-line argument.type` on `$wpdb->prepare( ... )` calls
  whose first argument is built by string interpolation (`"... {$where}
  ORDER BY {$orderby} {$order}"`). The WordPress stub annotates `prepare`'s
  first parameter as `literal-string`, which by design rejects any non-literal
  query — even when the interpolated fragments come from `array_fill( '%d' )`
  for `IN (?, ?)` clauses, `sanitize_sql_orderby()`, or other safe-by-construction
  builders. Each ignored site keeps the placeholders/values flowing through
  `prepare`'s sanitization, so the runtime guarantee holds even though the
  static guarantee cannot.
- `@phpstan-type` aliases at the top of each repository class describe the
  shape returned by `$wpdb->get_row` / `get_results` for that table; consumers
  in other namespaces import them via
  `@phpstan-import-type RowName from RepositoryClass`.

## Local checks

Run these before opening a PR — CI runs the same commands.

```bash
vendor/bin/phpstan analyze        # static analysis
vendor/bin/phpunit                # unit tests
composer audit --locked           # security advisories
vendor/bin/phpcs                  # WordPress Coding Standards (gating)
vendor/bin/phpcbf                 # auto-fix many WPCS violations
```

PHPCS runs in CI on changed PHP files and **gates merges**. If your PR
touches a file with pre-existing violations, you must fix them (or run
`vendor/bin/phpcbf` to auto-fix most). Run `vendor/bin/phpcs <file>`
locally before pushing to catch issues early.

If you edit anything under `assets/css/` or `assets/js/`, rebuild the
minified bundles:

```bash
npm run build
git add assets/
```

The `Asset Build Verification` workflow fails the PR if the committed
`*.min.*` files are out of sync with their sources.

## Pull requests

- Keep PRs focused; a single concern per PR makes review easier.
- Include a short summary and a test plan in the description.
- The CI matrix runs PHPUnit on PHP 8.1 / 8.2 / 8.3 / 8.4 plus PHPStan on
  PHP 8.1; all of these must be green to merge.

## Releasing

Between releases, every PR adds entries under `## [Unreleased]` in
`CHANGELOG.md` and leaves the version constants alone. The version only
bumps once, at release time, absorbing everything that accumulated in
`[Unreleased]`.

To cut a release:

1. Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` in
   `CHANGELOG.md` and insert a fresh empty `## [Unreleased]` above it
   (use the standard Keep a Changelog subsections: `Added`, `Changed`,
   `Deprecated`, `Removed`, `Fixed`, `Security`).
2. Bump `Version:` in `ffcertificate.php` **and** the `FFC_VERSION`
   constant in the same file. Update `Stable tag` in `readme.txt` and
   mirror the release notes under `== Changelog ==` in the WordPress
   plugin format.
3. Commit, merge to `main`.
4. Tag `main` with `vX.Y.Z` and push the tag:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```
5. The `Release` workflow builds `ffcertificate-X.Y.Z.zip` (excluding dev
   files via `.distignore`), creates a GitHub Release, and attaches the
   zip. Notes are pulled from the matching `CHANGELOG.md` section.

## AI-assisted contributions

This project uses [Claude](https://claude.ai/code) (Anthropic) as an
AI-powered coding assistant. AI involvement started on **2026-01-17**
with commit
[`53cc4fa`](https://github.com/rpgmem/ffcertificate/commit/53cc4fa4063bb497f5948d79897c022c5c0494e2)
(the geolocation and date/time restrictions system that landed in
v3.0.0) and extends to subsequent releases unless a `CHANGELOG.md`
entry explicitly states otherwise.

- AI-assisted commits include a `https://claude.ai/code/session_…`
  footer in the commit message; the session URL is the audit link
  back to the conversation that produced the change.
- AI-assisted feature branches use the `claude/<short-description>`
  prefix (already documented under [Branches](#branches)).
- All AI-generated changes go through the same PR review and CI
  pipeline as human contributions; the human author is responsible
  for reviewing, accepting, and merging the work.

## Reporting security issues

Do not open public issues for vulnerabilities. Use GitHub's private
advisory form — see [SECURITY.md](SECURITY.md) for the exact link and
disclosure timeline.
