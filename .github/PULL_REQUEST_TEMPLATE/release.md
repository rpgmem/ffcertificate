<!--
Title: `release: X.Y.Z — bump + finalize CHANGELOG` (to `develop`), or
`X.Y.Z — <summary of the batch>` (the `develop → main` PR, which stays draft
until the maintainer confirms the production deploy).
See CLAUDE.md → "Release PR (develop → main)".
-->

## Release X.Y.Z

<!-- One paragraph: what the batch delivers, by story. -->

## Checklist

- [ ] Version bumped in the three sites (`ffcertificate.php` header, `FFC_VERSION`, `readme.txt` `Stable tag`)
- [ ] `[Unreleased]` renamed to `[X.Y.Z] (YYYY-MM-DD)`, fresh empty `[Unreleased]` above, section condensed by story
- [ ] `readme.txt` `== Upgrade Notice ==` rewritten for this version, ≤300 characters
- [ ] `@since` tags written mid-batch corrected to `X.Y.Z`
- [ ] Documentation cross-checked against every `Added` / `Changed` bullet
- [ ] Minified assets current

## After merge (maintainer / agent)

1. Maintainer pushes the tag with the guarded recipe in `CLAUDE.md`.
2. Agent resets `develop` to `main` (`--force-with-lease`).
3. Agent opens the SHA-backfill PR for the CHANGELOG heading.
4. Agent lists any branch left behind; maintainer deletes it.
