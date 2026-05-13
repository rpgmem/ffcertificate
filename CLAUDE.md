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
