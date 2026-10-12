<!--
Title: `type(scope): summary` — type is feat, fix, docs, test, refactor or chore;
scope is the area (identity, recruitment, scheduling, …). It becomes the squash
subject on `develop`. Base branch: `develop`. Write in English.
Release PRs use the release template: append `?template=release.md` to the
compare URL.
-->

Closes #

<!-- Every PR names its issue. Exempt: trivial docs or chore, Dependabot, the
`release: X.Y.Z` bump and the CHANGELOG SHA backfill. `Closes` does not
auto-close on `develop`: the issue is closed by hand after the merge. -->

## Problem

<!-- What was wrong or missing, as measured. -->

## Change

<!-- What the diff does, and what it deliberately leaves alone. -->

## Tests

<!-- What proves it: the tests added, and the evidence they bite — e.g.
"reverting X makes test Y fail". Name any gate that was run only in CI. -->

## Risk

<!-- Data, migrations, capabilities, anything an install upgrades into. Delete
if none. -->

## Screenshots

<!-- For a change an operator sees: before / after. Delete if none. -->

## Checklist

- [ ] Base is `develop`; no version bump
- [ ] `CHANGELOG.md` — one bullet at the end of its heading under `[Unreleased]`, citing the issue, ≤300 characters
- [ ] Documentation topic under `includes/settings/views/documentation/` updated, if an operator sees or configures the change
- [ ] Strings in the `.pot` and `.po`, catalogues recompiled with `php .github/scripts/ffc-compile-translations.php`
- [ ] Minified assets rebuilt (`npm run build`) if JS or CSS changed
- [ ] Fast checks run locally: PHPUnit for the touched tests, PHPStan, PHPCS; the full suite when the change can reach another file
