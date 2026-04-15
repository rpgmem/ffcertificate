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
- Feature branches use the `claude/<short-description>` prefix.
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

## Local checks

Run these before opening a PR — CI runs the same commands.

```bash
vendor/bin/phpstan analyze        # static analysis
vendor/bin/phpunit                # unit tests
composer audit --locked           # advisory check (non-blocking in CI)
```

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

## Reporting security issues

Do not open public issues for vulnerabilities. Use GitHub's private
advisory form — see [SECURITY.md](SECURITY.md) for the exact link and
disclosure timeline.
