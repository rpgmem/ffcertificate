---
paths:
  - "phpcs.xml.dist"
  - "phpcs-tests.xml.dist"
  - "phpstan.neon.dist"
  - "phpunit.xml.dist"
  - ".stylelintrc.json"
---

# Config-level exclusions

This is `CLAUDE.md` "Quality gates and testing" → "Config-level exclusions", moved here (#2867) so it loads when a session edits a lint or test configuration.

## Config-level exclusions — audited verdicts

A rule switched off in `phpcs.xml.dist`, `.stylelintrc.json`, `phpstan.neon.dist` or `phpunit.xml.dist` is a `phpcs:disable` with repo-wide blast radius, so the same measurement was run over all four. The inline annotations are the audited part; these are recorded here so the verdicts are not re-litigated.

**`Generic.CodeAnalysis.UnusedFunctionParameter` stays off — measured, not assumed.** The verdict rests on the *shape* of what it reports, not on the total, and the total is not reproducible from a bare "enabling it": it depends on the standard the sniff is run under (`vendor/bin/phpcs --standard=Generic --sniffs=Generic.CodeAnalysis.UnusedFunctionParameter includes/` reported 43 when this was written and 94 when #1261 re-ran it). **Most of what it reports is structural**: 14 are parameters consumed by a `templates/*.php` partial the method `include`s (the sniff cannot see across an include — and extracting markup to `templates/` is this project's own convention, so the architecture *creates* this class), and 19 are signatures fixed by their caller (WP hook callbacks taking `$atts` / `$hook` / `$update` / `$request`, a guard interface's `$ctx`). Turning it on would mean 33 new annotations — exactly the churn #1035 removed. The 10 genuine ones were read and fixed in the same pass; re-run it as a one-off if the population grows, never as a gate.

**`Squiz.PHP.CommentedOutCode` stays off.** Every finding is a false positive and the verdict has survived a re-measure (9 findings when the rule was written, 16 when #1261 re-ran it over `includes/`, the same shapes throughout): the heuristic scores an explanatory comment as code when it quotes an enum (`// 'daily' or 'span'`), shows a shape (`// [{day: 0-6, …}]`) or names a token (`// Remove {{ and }}`). Zero real commented-out code.

**Stylelint: `declaration-block-no-shorthand-property-overrides` and `declaration-block-no-redundant-longhand-properties` are ON** (both measured 0 findings, so they cost nothing and guard a real bug class — a shorthand silently wiping an earlier longhand). `no-descending-specificity` and `no-duplicate-selectors` stay off; enabling both over the non-minified sheets reports them in the hundred-and-few range for the first (103 when #1261 measured it) against a handful for the second, and every duplicate-selector hit is a deliberate section split — a token block and a layout block under the same selector, each with its own comment — where merging would destroy the intent.

**The `views/` carve-out is markup-only, and the two lists must stay in step.** `phpstan.neon.dist` (`excludePaths`) and `phpunit.xml.dist` (`<coverage><exclude>`) both skip `includes/admin/views` and `includes/settings/views` — thousands of lines of markup, i.e. the same rationale as `templates/` above. **The invariant is what those directories never DO, not a count of tokens they contain (#1536):** **every nonce occurrence is `wp_nonce_field` / `wp_nonce_url`, so these files GENERATE a nonce and verify none** (37 of 37 when #1536 measured it, zero `wp_verify_nonce` / `check_admin_referer` / `check_ajax_referer`), **both `wpdb` hits read `$wpdb->prefix` to print a table name with no query**, and a `current_user_can` decides whether a link is drawn while the destination gates itself. No query, no write, no verification — the gates belong to the handlers, and that survives the next view growing a nonce field. **`includes/self-scheduling/views` is deliberately NOT excluded** — it holds real logic (capability gates, `RequestInput` reads, list-table wiring), so "a directory named `views` is markup" is *not* a repo-wide truth; judge by content. Two further paths, `includes/views` and `includes/libraries`, were listed for years and have never existed in the history — removed, since PHPStan's `(?)` optional-path suffix meant nothing ever reported them.
