---
paths:
  - "assets/css/**"
---

# Stylesheets — architecture, naming, page scope and theme

This is the CSS-only half of `CLAUDE.md` "Stylesheets and theme", moved here (#2865) so it loads when a session works in `assets/css/`. The half that also governs markup written in PHP and JS — Icons, Controls, Buttons, Admin layout and Inline CSS — stays in `CLAUDE.md`. The measurements and renders behind these rules are in `docs/engineering/css-measurements.md`.

## Stylesheet architecture (one sheet per screen — deliberately not a bundle)

`assets/css/` holds one non-minified sheet per screen or component family — admin-only, frontend-only, or both — and each is enqueued by the screens that need it (re-count with `git ls-files assets/css | grep -v '\.min\.' | grep -c '\.css$'`). **`wp_enqueue_style` is the entry-point mechanism.** There is no bundler and no preprocessor: `npm run build:css` runs `cleancss` over each sheet in place, and `build:js` does the same with `terser`. The stack is plain CSS, PHP templates and jQuery.

**Per-screen, not two bundles — measured, not preferred.** Collapsing the sheets into `admin.css` + `front.css` (the usual advice for an app you own) costs roughly double on every screen:

| Screen | Per-screen sheets (when measured) | Single bundle |
| --- | ---: | ---: |
| Appointments (admin) | 113 KB · 7 sheets | 220 KB (**1.9×**) |
| User dashboard (frontend) | 99 KB · 4 sheets | 218 KB (**2.2×**) |

The bytes are the smaller reason. Conditional loading is what keeps the plugin's CSS **off** the screens that are not ours — a single admin bundle would land on `users.php`, the post editor, and any screen whose gate happens to match, which is precisely the blast radius `CssNamespaceAnchorTest` exists to bound. Granularity here is a safety property, not an optimisation.

**The plugin is a guest in someone else's document, and that settles two whole layers.** On the frontend the surrounding document belongs to the site's theme; in wp-admin it belongs to WordPress. So:

- **No reset, ever** — no `normalize`, no global `box-sizing`, no `* { margin: 0 }`. Measured: zero in the codebase, and it must stay zero. A reset is for whoever owns `<html>`.
- **No bare element selectors** — `p`, `a`, `h1`-`h6`, `body` styled unqualified would repaint the host theme. Across **every** sheet, **no selector is fully unqualified**: each carries a class, an id or an attribute somewhere (run `CssSelectors::of()` over the sheets and reject anything matching no `[.#[]`; `:root` is the single allowlisted exception). Reintroducing one is architecture to argue for, not debt to inherit.

**How sheets relate.** `ffc-common.css` is the root: it declares the whole `--ffc-*` palette on `:root`, the wp-admin base pair, and the shared components. Every module sheet declares it as a `wp_enqueue_style` dependency, which is both what makes a module's override of it deterministic and what `AdminStylesheetTokensTest` direction B enforces. The admin base is a chain — `ffc-pdf-core → ffc-common → ffc-admin-utilities → ffc-admin-css → ffc-admin-submissions-css`, with `ffc-admin-components` (the components several admin screens share, such as the collapsible `.ffc-section` and the boxed `.card`) hanging off `ffc-common` in the same base — and a dependency edge is the *only* thing that fixes the order between two sheets; without one it falls to enqueue order, i.e. the order modules are wired in `Loader` (see the component-ownership guard in `CLAUDE.md` "Quality gates and testing").

Three facts about that graph that cost time to rediscover. **Two handles can name one file**: `ffc-admin.css` is `ffc-admin` on `users.php` and `ffc-admin-css` on FFC screens, with different dependency lists. **One handle can be enqueued with different dependency lists at different sites** (`ffc-admin-settings`), and WordPress keeps whichever registered first. And **a gate can be wider than its handle's name**: `is_ffc_page()` matches *any* `?page=ffc-*` screen, so a sheet gated on it reaches far beyond the screen its docblock names — gate on the screen itself (`ffc-admin-submissions-css` is gated on `Admin::SUBMISSIONS_PAGE`).

**Four guards hold this shape**, and each is described in `CLAUDE.md` "Quality gates and testing": `CssNamespaceAnchorTest` (every selector names something we own), `StylesheetOwnershipTest` (two sheets declaring one class must have an edge), `AdminStylesheetTokensTest` (a sheet reading tokens declares `ffc-common`; no colour literals), `DarkModeCssTest` + `TypographyTokensTest` (the palette and the scale). The CSS parser they share lives in `tests/Support/CssSelectors.php`.

**Standing decisions, so an ITCSS-shaped proposal is not re-evaluated from scratch.** The layered advice (settings · tools · generic · elements · objects · components · utilities, with per-area bundles) is sound for a standalone app with a bundler and a document of its own. Mapped onto this plugin it splits three ways, and the split was measured rather than argued:

- **Already in place, by another route.** *Settings* — the token palette and the typography scale, as native custom properties, which beat preprocessor variables here because they respond to the dark theme at runtime. *Utilities* — `ffc-admin-utilities.css`. *Entry points* — per screen, which is finer than per area.
- **Would be a defect here.** *Generic* (a reset) and *Elements* (bare tag styling) both assume ownership of the document, which a plugin does not have. **BEM** is churn: the `ffc-` prefix already carries the isolation BEM's naming would, over ~1,000 classes. What *is* worth keeping from that advice is component-specific naming.
- **Genuinely worth doing, and done:** a **page-scope class on the admin `wrap`** — see "Page scope on the admin `wrap`" below.

**No preprocessor.** Its one real-sounding argument is a mixin for *the same visual shape under different owners*, and it has not survived measurement here: what looked like one shape copied by three modules (`.ffc-status-badge`) was three different badges wearing one name, which is a rename, not a mixin. The duplication that *is* real is the skin pair — a rule whose only two declarations are a `--ffc-*-bg` background and a `--ffc-*-text` colour — and the tokens already give it "change the colour in one place", with no build step. Revisit **only** if a shape appears that a shared class cannot carry, and decide it from proven duplication, not on spec (the #788 / #902 / #993 criterion).

## Naming and composition inside the sheets (#1167)

The previous section settles *how many sheets and why*; this one settles *how a class is named and how a component is composed*. Measure with the shared parser (`tests/Support/CssSelectors.php` — the same one the guards read, so the numbers cannot disagree with them); nearly every class carries the `ffc-` prefix, and the unprefixed remainder is almost entirely vendor (CodeMirror, WordPress).

**Where each methodology already sits:**

| | Level | Evidence |
| --- | --- | --- |
| **OOCSS** | partial | Compound units such as `.ffc-day.ffc-selected` split object from modifier. The **skin** half is not split: many rules declare one `background: var(--ffc-X-bg); color: var(--ffc-X-text)` pair under their own name, and that stays so on purpose ("Theme" below) |
| **BEM** | a few islands | `__` elements and `--` modifiers live only inside `ffc-settings-tabs`, `ffc-vtabs`, `ffc-qr-modal`, `ffc-blocked-roles` and the autosave badges |
| **SMACSS** | state layer yes, layout layer absent | Every `is-` / `has-` class appears compounded with an `ffc-` class, never bare — a bare `.is-open` would fail `CssNamespaceAnchorTest`. No `l-`, no `js-` |
| **Atomic / utility-first** | exists, kept small on purpose | `ffc-admin-utilities.css`, which `UtilityScopeTest` holds to one bare class per rule (no descendant, no pseudo-class, no element sub-selector) |

**Renaming a class is safe only as far as you can find who emits it, and a grep cannot.** `tests/Support/CssClassEmitters.php` is the scan; `CssClassEmissionTest` keeps it honest. It reads every shape a class reaches markup in — a literal attribute, the jQuery/DOM class API, a class used as a **selector inside a string** (`'.ffc-timeslot:not(.ffc-timeslot-full)'` — renaming without touching it breaks the handler silently), a name **assembled at runtime** (concatenation, an echo inside the attribute, a `printf` placeholder, PHP interpolation; such a name exists as a literal nowhere, so the scan records its prefix), a token concatenated onto an open attribute in either direction (`'<table class="ffc-appointments-table' + (past ? ' past-appointments' : '')`), several classes in one string, and a library option whose value is a class (jQuery UI's `sortable({ placeholder: 'ffc-sortable-placeholder' })`).

**A class with no findable emitter is an open question, never proof of dead code.** It is genuinely dead, or emitted by a shape the scan does not know — then **teach the scan, never lower the guard** — or applied by something outside our code. `CssClassEmissionTest` keeps three lists: `TEMPLATE_API` (classes published for the certificate body an administrator writes, which lives in the database; deleting one breaks every certificate using it), `LEGACY_SHIM` (logged in `CLAUDE.md` "Legacy and tech debt") and `WITHOUT_EMITTER` (still open). **Read the file before concluding from a grep**: the template API is declared in a section heading of `ffc-pdf-core.css`.

Mechanics of the scan, each a trap it fell into once. **Too loose reports nothing wrong**: a bare `ffc-` prefix (from `'ffc-' + Date.now()`) covers every class, so a prefix needs `ffc-` plus a segment, and the loose forms need the word `class` (or `cls`) nearby, or every `wp_enqueue_style` handle counts as a class; the multi-class form needs **two or more** tokens, since a handle is always one. **Quoted strings must be matched whole, escapes included**, never by alternation, or the scan loses quote parity at the first apostrophe and reads the rest of the file shifted — the same reason `CssSelectors` has to be quote-aware.

**Name by function, not by word.** What the interaction turns on and off is a **state** and stays unprefixed as `is-` / `has-`; everything else of ours — a **variant** the data dictates, and an **element** of the component — takes `ffc-`. `tests/Unit/ClassNamingIdiomTest.php` refuses a bare variant such as `.consent-given`, with the vendor names that legitimately stay unprefixed listed by family and by name, each with its reason. Beyond readability, a variant assembled at runtime out of a bare word is invisible to `CssClassEmitters` by construction, which is how dead rules hide.

**Rename the token, never the selector.** Collapsing `.ffc-detail-row .value.code` into `.ffc-detail-value-code` drops specificity from (0,4,0) to (0,2,0), which is a different render wearing a rename's clothes. Prove a rename moved nothing statically: every rule before, with the rename map applied and any deleted dead rules removed, must be byte-identical in selector *and* declaration to what is there after, and every new name must have a findable emitter.

**Radius and layers read tokens too.** `border-radius` reads `--ffc-radius-*`, and `z-index` reads the layer scale. **The escalation values are deliberately NOT collapsed** — `9999`, `100001`, `999999` and the PDF sheet's `2147483647` are different answers to "be above the wp-admin bar, which is 99999", and merging them changes the stacking ORDER between elements on the screens where they coexist, which needs screen-by-screen verification. **Naming one is not collapsing it**: `--ffc-z-topmost` declares the `999999` every full-screen editor overlay already used, and moves no element relative to any other.

**Moving a component out of `ffc-admin-utilities.css` narrows its reach.** That sheet is a dependency of `ffc-admin-css` and is enqueued in one place (`AdminAssetsManager::enqueue_admin_base_styles()`), so everything in it reaches every FFC admin screen; a component moved to its owner sheet vanishes wherever that sheet is not enqueued, with nothing to catch it. Read the target sheet's gate against the emitting screen, and prove the move per screen, with only that screen's sheets loaded — loading all of them hides exactly the defect the move risks.

**What this means for the theme arc:** a third *colour* theme costs one line per colour token, because dark is light overridden and **the dark block redefines the colour tokens and only the colour tokens** — every one except `--ffc-text-on-warning`, and no non-colour token at all (re-measure by comparing the two blocks' keys). A **density** theme (compact/comfortable) would need spacing, radius and `z-index` to be swappable per theme, which they are not today.

**Standing decisions, so they are not re-litigated:**

- **No BEM across the base.** The `ffc-` prefix already carries the isolation BEM's naming would, over ~1,000 classes; converting is churn against a property that already holds. The BEM islands stay as they are — internally coherent, and rewriting them to the house idiom is churn in the other direction. What survives from the idea is **component-specific naming**, which #1151 / #1154 / #1162 have been doing one component at a time.
- **No ITCSS with per-area bundles.** Measured in the previous section: 1.9×/2.2× per screen, and conditional loading is a safety property rather than an optimisation. Two of the seven layers (*generic*/reset, *elements*/bare tags) presuppose owning the document, which a plugin does not.
- **No Tailwind-style utility-first.** It needs a CSS build the project does not have (`cleancss` minifies in place, it does not compile) and a class scan that cannot see what PHP assembles at runtime. The utility layer that does make sense here already exists; the work is keeping it small (#1171), not growing it.
- **No layout layer (`l-`).** Zero occurrences, and the split it would express is already made per sheet.
- **A scale is worth building only where a second consumer of the same value exists.** Colour, typography and — since #1169 — spacing have earned theirs. A one-off number, an optical nudge or a hairline stays literal with the reason inline, exactly as the typography section describes.
- **Spacing reads the scale** (#1169). One ladder, `--ffc-spacing-*`: `2 · 4 · 6 · 8 · 10 · 12 · 16 · 20 · 24`, chosen because it kept most declarations exact where a pure 8-point ladder would have moved many by 3px or more. `SpacingTokensTest` freezes the remaining literals per sheet. A value between two rungs snaps by **relationship, not rounding** — read what the code already does for the same job (`gap` went up, other properties went down) — and a value that is the exact midpoint of a wide gap snaps rather than becoming a rung, because an eleven-rung ladder is a dictionary. Utilities read the step, never the pixel (`.ffc-mt-xl`): a utility named by its value re-breaks every time the scale moves. **A sheet enqueued without `ffc-common` must not read the scale** — `ffc-pdf-core.css` and `ffc-code-editor-dark.css` are deliberately independent (print is light, the editor theme is dark in both), so a token there would invalidate the whole declaration.

## Page scope on the admin `wrap` (#1184)

Every admin screen the plugin draws says so in its markup. Two classes on the `div.wrap`, and both have real consumers — this is not one level held in reserve:

- **`ffc-admin-page`** — every admin screen of ours. The anchor for a rule that holds on all of them: `ffc-admin.css` is enqueued **twice under two handles** — `ffc-admin-css` on `is_ffc_page()` screens and `ffc-admin` on `users.php` — so its rules reach a core screen.
- **`ffc-page-<slug>`** — one screen, where `<slug>` is the screen's `?page=` minus the leading `ffc-`. The anchor for a rule that belongs to one screen but sits in a sheet whose gate is wider.

**A sheet that serves a whole menu family anchors on the family, not on the generic.** `ffc-audience-admin.css` is gated on `strpos( $hook, 'ffc-scheduling' )`, so its `.column-*` rules descend from `[class*="ffc-page-scheduling-"]` — one selector that *mirrors the gate*, at the specificity of a class. Anchoring on the generic `ffc-admin-page` instead would make `StylesheetOwnershipTest` stop reporting a collision **without making it any less possible**: the same risk, now invisible. Anchor at the level the rule's scope actually is.

**A screen WordPress draws takes a third anchor, and it costs nothing:** the post editor for `ffc_self_scheduling` has no `wrap` of ours, so `ffc-calendar-editor.css` anchors on `body.post-type-ffc_self_scheduling` — a class core already prints. The same shape as `.post-type-ffc_form`, which the guard's anchored-forms list already carried.

`tests/Unit/AdminPageScopeTest.php` enforces it and freezes the screen map. **It is deliberately the cheap half — it proves the anchor is in the markup, never that a rule reads it.** What charges the reading is `CssNamespaceAnchorTest`, and that is where the debt shrinks. Splitting the two is what makes the delivery provable by construction: adding a class no rule reads cannot move a pixel, which is the strongest proof of zero movement there is.

**What anchoring taught about narrowing a rule** — each settled only by a render with the real screen's sheets plus WordPress core's own:

- **Narrowing a rule to the screen its docblock names is not always safe; split by what the rule MEANS.** A row action of one screen takes that screen's anchor. A generic affordance (a tooltip family on every FFC screen) takes `ffc-admin-page`, or narrowing it silently drops it from the other screens. Measure what still matches before narrowing.
- **Build the test case from the emitter, not from what the selector suggests.** Synthetic markup that no screen renders makes a harness report a divergence for markup that does not exist; read the emitter for which file prints it.
- **A removal that measures zero cannot tell "identical to core" from "never wins"; a control mutation can.** Flip the value in place: if the render then moves on the target elements, the rule is the cascade winner and is a no-op because the value is identical — only that case is a safe deletion.

**An anchor is the wrong fix for a name that must be unique.** An id anchored inside a container still sits in the document, so a theme declaring `#tab-profile` breaks `getElementById`, `aria-controls` and the event delegation; rename it instead (the dashboard panels are `ffc-tabpanel-<key>`).

Two more things to know:

1. **`?page=` is not available as a derived source.** Some slugs exist as a literal **nowhere** in the repository — they are built by concatenation (`self::MENU_SLUG . '-dashboard'`) — so the screen map is a frozen register in `AdminPageScopeTest`, not a derivation.
2. **`.ffc-settings-wrap` is a layout class, not a page scope**: it means "this screen uses the vertical-tab design" and sits on more than one screen. Never nest a `div.wrap` inside another: core's `.wrap` margin doubles horizontally (the inner one collapses vertically), so the inner content lands indented and narrower than its siblings.
## Theme (one palette, one root class — #1126)

Every colour the plugin paints comes from a `var(--ffc-*)` token declared in `assets/css/ffc-common.css`. The dark theme is that same sheet's `:root.ffc-dark-mode` block redefining part of the tokens — **the dark theme is the light theme overridden, never a second set**, which is why `DarkModeCssTest::palette('dark')` merges the two before measuring. `AssetHelper::enqueue_dark_mode()` puts the class on `<html>`, reading `ffc_settings['dark_mode']` (`off` / `on` / `auto`).

**The plugin's own setting is the single source of truth. There is ZERO `prefers-color-scheme` in the CSS** — the OS is consulted inside `ffc-dark-mode.js`, and only when the setting is `auto`. A rule keyed on the media query would silently outrank the administrator's choice; don't add one.

**Seven things the #1126 arc measured that are not guessable, each of which shipped as a defect first:**

1. **The palette alone is not the theme.** A fully tokenized sheet renders light when the toggle script never reaches the page — every `var(--ffc-*)` resolves through the light block. Four public screens were in exactly that state.
2. **An undeclared custom property invalidates the WHOLE declaration.** It does not fall back to the literal that was there before, so the element renders with no colour at all. Tokenizing a sheet whose enqueue does not depend on `ffc-common` makes the screen *worse*. A local `:root { --ffc-… }` inside a component is the same defect wearing a different hat: it shadows the palette for everything inside, so `:root.ffc-dark-mode` can never reach it.
3. **Text that declares no `color` inherits from OUTSIDE this repository** — core's `body { color: #3c434a }` in wp-admin, the active theme on a public page. Both are near-black: **1,28:1** on a dark ground. The palette therefore carries a base pair per root we own (`body.wp-admin` — never bare `body`, since the class also lands on public pages).
4. **A form control does not inherit `color` at all.** `<button>`, `<input>`, `<select>` and `<textarea>` take `buttontext` / `fieldtext` from the user agent, so the base pair cannot reach them. **A rule that paints a control's ground must paint its text.**
5. **`opacity` on a row fades text and ground together**, multiplying down whatever contrast the tokens guaranteed — and the pair meter cannot see it, because the tokens stay correct. Say state with colour, not with opacity. `OpacityContrastTest` freezes every `opacity` below 1 in the sheets, per sheet and per selector, each with its reason, a ratchet both ways; it cannot read contrast (what fading does depends on what is behind the element, which is DOM, not stylesheet), so what it guarantees is that none enters without somebody having looked. Three categories are legitimate: an **inactive component** (SC 1.4.3 exempts it), a **transient state** (loading, `:hover`), and **`opacity: 0`**.

**Read the emitter for the root before believing a contrast measurement.** Measuring an element in a bare page — no `body.wp-admin`, no `.ffc-shortcode` wrapper — reproduces defect 3 perfectly, because the base pair is keyed on exactly those roots, and reports a defect the real screen does not have. A static guard cannot replace that render: many rules paint a surface token without declaring text, and nearly all are correct because their text descends from a root that is on the list. What separates a real defect from those is the computed colour under the real ancestors.

6. **An inline `style=""` beats the tokenized class.** Tokenizing is dead code while an inline colour exists. Where the background is a colour the *operator* picks, no token can work — the foreground comes from `Core\ContrastColor::on()`, which computes it by WCAG luminance. Its two candidates are pure black and white on purpose: the palette's near-black `#1d2327` drops the worst mid-tone of the RGB cube to **3,99:1**, while black holds **4,58:1** over any colour.
7. **Categorical hues are not theme colours.** The capability-group hues — one per group, and `CapabilityCatalogTest` fails a group the sheet gives none — must stay distinguishable from each other, so they stay literal with the reason inline. Same for `#adminmenu` (it follows the user's own wp-admin colour scheme), vendor brand colours, an editor theme that is dark in both themes, and print.

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

**The contrast meter is two halves, and both stay.** `DarkModeCssTest::pairs()` is a **hand-written list of token pairs**, which measures what somebody remembered to list and is the only way to see a pair that **inheritance** creates (the base text over `--ffc-gray-100`, a label coloured by its container). The second half **derives** the pairs: every rule that declares `color` and a background *in the same rule* is measured in both themes against the 4.5:1 text floor, blocking at zero.

Three rules the derived scan holds:

1. **A named colour is a literal.** `white` is a word, so a hex/`rgb()`/`hsl()` regex misses it; direction A of `AdminStylesheetTokensTest` counts named colours, only inside a colour-valued property (`font-family: 'Whitney'` is not a literal).
2. **Unresolvable must FAIL, never skip.** A colour the scan cannot resolve (`!important`, a named colour) fails the test and asks to be taught; skipping it would pass over exactly the worst defects.
3. **Only inactive controls may sit below the floor** — they carry `cursor: not-allowed` (a full time slot, a `readonly`/`disabled` field), and SC 1.4.3 exempts text that is part of an inactive component. They sit in `DERIVED_EXCEPTIONS` with that reason, and a test fails when an exception stops matching any real pair.

**The shared skin object was measured and not built** (#1168). The tokens already deliver "change the colour in one place", so the duplicated `--ffc-X-bg` / `--ffc-X-text` pairs are syntactic, and migrating the status-badge families would need a status→skin map in PHP **and** JS, because the class is built by concatenation (`'ffc-dashboard-status-' . $status`). The guard delivers the property that mattered — a wrong pair cannot ship — without the churn.

**Standing decisions, so they are not re-litigated:**

- **Print and PDF are light by definition.** `ffc-pdf-core.css` and the certificate preview's white paper are documents that may be printed; they do not follow the theme.
- **Core screens stay light** — `profile.php`, `users.php`, the post-editor metabox. The content area of every core colour scheme is white, so our fields rendering light there is *consistent*, not a bug. Listed in `TOGGLE_NOT_NEEDED` with that reason.
- **The code editor has its own setting**, `code_editor_theme` on the General tab, immediately below Dark Mode: `dark` (default), `light`, or `auto` (follows `dark_mode`). The two sit side by side because `auto` resolves by reading `dark_mode`. One autosave key lives on one tab only: `SettingsAutosaveFieldPlacementTest` refuses a key on two tabs, because each tab's Save rebuilds its own fields and two copies drift.
- **Typography reads the scale** (#1148). Seven steps in `ffc-common.css` — `2xs` 11px · `xs` 12px · `sm` 13px · `base` 14px · `lg` 16px · `xl` 18px · `2xl` 24px — frozen by `TypographyTokensTest`, a per-sheet ratchet in the mould of the colour one. Four families legitimately keep a literal, and a fifth needs arguing: a **glyph box** sized by `font-size` (a dashicon, a `&times;`, a stat card's icon — not typography); a **hero number** a card sets deliberately above the scale; an **`em`** relative to its parent because the component is dropped into contexts of different sizes; and a value already one step below the floor inside a **phone `@media`**, where rounding up would erase the distinction the rule makes. Two sizes for one selector are almost always a deliberate `@media` step down, not drift. A value between two steps is resolved by **relationship, not rounding**: headings go up, so they keep their hierarchy, and body text goes down.
- **Each step is `max(<px floor>, <rem>)`, and the form is the point** (#1157). `rem` resolves against the DOCUMENT root, which on the frontend belongs to the site's theme: under the common `html { font-size: 62.5% }` idiom a plain `rem` scale shrinks to unreadable sizes. The px floor removes that; the `rem` half keeps the browser font-size preference these public-sector forms want. The one honest loss: under a root-shrinking theme the user's preference is absorbed by the floor until it exceeds it. **The two halves must agree at a 16px root** — `max(13px, 0.8125rem)` is an identity, not a range — and `TypographyTokensTest` checks exactly that, because a mistyped pair is plausible on both sides and only shows up rendered.
- **The public design overrides tokens, never rules, and only in the light theme** (#1677). `Core\PublicDesign` (Settings → Public Design) prints a `<style>` after `ffc-common` on public pages, through `style_loader_tag` so every enqueue site is reached. It sets `--ffc-form-width`, `--ffc-radius-sm`/`-md` and the font on `.ffc-shortcode`, and the colour tokens under `:root:not(.ffc-dark-mode) .ffc-shortcode`, so the dark palette `DarkModeCssTest` measures is never mixed with a colour picked for white. The text on the accent comes from `ContrastColor::on()`; a public component that should follow the design reads a token, not a literal.
- **A theme change repaints live.** The autosave widget announces `ffc:setting-saved` on `document`; `ffc-dark-mode.js` listens and re-applies, including dropping the OS listener when leaving `auto`. The widget must not learn which keys repaint — it states the fact, an interested script acts.

Open items and the measurements behind them are in #1148 — among them the typography scale, which already exists and is already semantic, and the trade that makes multiple themes cost more than it looks.
