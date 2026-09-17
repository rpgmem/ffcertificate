# Changelog

All notable changes to the **Free Form Certificate** plugin are documented in this file.
The format follows [Keep a Changelog] (https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added

- **A CI guard that blocks Portuguese prose in comments and i18n source strings** (#1260): `CommentLanguageTest` flags a line carrying two distinct Portuguese function words, after quoted spans are removed. Requiring two is what separates a sentence from `example.com` or `DoS`; the use/mention rule is what lets an example the code operates on stay quoted. Tuned against the 160 lines #1278 removed: 59 of their 60 blocks, zero false positives — and it found six more Portuguese lines on a tree three passes had called clean.
- **A guard that the three translation catalogues agree** (#1266): `TranslationCatalogueAgreementTest` compares `.po` ↔ `.mo` ↔ `.l10n.php` by `(msgctxt, msgid)` in both directions, plural forms included. They had disagreed in production for two releases — the `.po` carried #1209's fix and the shipped `.mo` did not, so on this plugin's WP 6.4 floor the string was still wrong. Proven by mutation: a dropped entry, a changed value and an orphan each fail it.
- **A guard that the catalogue covers the source** (#1284): `TranslationSourceCoverageTest` compares the 5,205 literal `__()`/`_x()`/`_n()` calls against `.po` and `.pot`, both ways. It is the direction #1266 cannot see — `languages/` stayed internally consistent through the two releases in which 79 strings had no entry and rendered in English (#1282). An entry that exists but is empty fails it too. 22 entries with no call site are registered, each naming what replaced it.

- **A guard that the uninstall sweep can reach every capability** (#1290): `UninstallCapabilitySweepTest` asserts the sweep's precondition — every live capability carries the `ffc_` prefix — instead of a 60-name declaration that could only be compared with the registry defining it. It also checks the role-removal list, which is by name and so can still fall behind. The behaviour is proven in the `fresh-install` job, which plants a live capability, a retired slug and a synthetic `ffc_` name no list has ever held, then asserts zero residue — with a positive control first, because `0 remaining` reads the same whether the sweep worked or the reader is broken.

- **A guard that no FFC capability sits on a role WordPress owns** (#1302): the `fresh-install` job reads every role definition after activation and fails on an `ffc_` capability outside an `ffc_` role. No static scan reaches it — the role is resolved at runtime — and it would have caught the `subscriber` grant on the day it shipped.

- **A guard that every created table has a name the sweep can find** (#1291): `ActivatorSqlTest` fails when a `CREATE TABLE` under `includes/` resolves to no `ffc_` name — which covers both a table outside the namespace and one written in an idiom the shared collector cannot follow. Blocks at zero; all 36 statements resolve.

- **A guard that only a refresher may name a captcha field** (#1307): `CaptchaFieldNameTest` fails when a script outside the two registered refreshers reaches for `ffc_captcha_ans`, `ffc_captcha_hash` or a `name="altcha"` position — the shape #1305 shipped in, where a hand-written field list left ALTCHA's proof unsendable on a public page. A shrink-only register of two, blocking at zero.

### Changed

- **Eleven Portuguese identifiers renamed to English** (#1260): eight `ReregistrationFieldOptions` methods (`get_sexo_options` → `get_gender_options`, `get_uf_options` → `get_state_options`, …), two local variables and one JS function. Pure rename: no stored row, option or template token is touched, so the diff is symmetric — 98 lines in, 98 out.

- **`CLAUDE.md` now states the language rule** (#1260): every versioned artifact is English — code, comments, assertion messages, CHANGELOG, commits, PR bodies — while the conversation with the maintainer is Brazilian Portuguese. A domain term stays Portuguese only when renaming it would break the database or need a migration.
- **A Portuguese error message shipped as an i18n source string** (#1260): the key-rotation migration reported `'Nao foi possivel decifrar…'`, so a non-Portuguese install had no way to read it and no translation could fix it — a source string is the one place the language is not a preference. Now English.
- **Six CSS-guard tests now read in English** (#1260): `UtilityScopeTest`, `OpacityContrastTest`, `ClassNamingIdiomTest`, `ForcedColorsContourTest`, `TypographyTokensTest` and `AdminPageScopeTest` — docblocks, inline comments, allow-list reasons and every assertion message. Comments and messages only: the token skeleton of all six is byte-identical, so no rule the guards enforce moved.
- **The shared CSS parser and the three large CSS guards now read in English** (#1260): `CssSelectors`, `CssClassEmitters`, `CssClassEmissionTest`, `CssNamespaceAnchorTest` and `StylesheetOwnershipTest`. Comments and messages, plus one deliberate rename: `CssClassEmitters::of()` returns `prefix` / `none` where it returned `prefixo` / `nenhum`, with its only consumer updated in the same commit.
- **The schema and activation guards now read in English** (#1260): `SchemaAgreementTest`, `ActivatorSchemaGuardTest`, `MigrationRegistryTest`, `DeactivatorTest` and the four `*ActivatorTest` files. Comments and messages, plus two test-fixture version strings; no assertion, pattern or option key moved.
- **The key-rotation and encryption guards now read in English** (#1260): the two `KeyRotation*` strategy tests, `EncryptionKeyMemoizationTest` and `DecryptFailureLoggingTest`. Comments and messages, plus four Portuguese local variables and five fixture values; the `'nome'` key stays, because it is a stored key of the reregistration JSON body.
- **Four of `CLAUDE.md`'s own claims were wrong** (#1260): it said the remote refuses pushes to `develop` (it does not — the credential has bypass; what it genuinely cannot do is delete any ref), that a guard baseline held two entries (it is empty), and that the stylesheets weigh 382 KB (432).
- **`CLAUDE.md` records three process findings from the #1260 arc** (#1260): where a `[Unreleased]` bullet goes (append, so parallel branches stop colliding on one line), when the full local suite is evidence and when `--filter` plus CI is enough, and the measured cost of splitting a docs-only pass into four PRs instead of two.
- **Three stale or unverifiable numbers in `CLAUDE.md`, and the rule that stops them recurring** (#1261): it said the `CssNamespaceAnchorTest` baseline was empty in one section and that three entries survived in another — #1202 renamed them, which is what emptied it. `ffc-common.css` was 1,226 lines / 135 tokens / 206 selectors; measured, 1,361 / 91 / 232. The file now states the invariant rather than the reading, and says what was counted so the next reader can check.
- **`CLAUDE.md`'s "CI gates" section split in two** (#1261): 31 KB sat under a heading promising a list of gates, and was really ~15 guards each with its discovery story. The gate list and coverage floors stay; the guards move to their own section with a subsection each, and the config-level exclusions become a third. Prose is byte-identical — inverting the heading change reproduces the section exactly. The two translation guards (#1266, #1284) were missing from the file entirely and are now in it.
- **The CSS arc becomes its own `CLAUDE.md` section** (#1261): §3 "Architecture and patterns" was 67% stylesheets under a heading promising backend patterns, so the four CSS subsections are now §4 "Stylesheets and theme" and the two that followed renumber. Every subsection body is byte-identical; the only prose edited is five cross-references whose target moved.
- **The remaining theme and JS-id guards now read in English** (#1260): `DarkModeCssTest`, `AdminStylesheetTokensTest`, `SpacingTokensTest`, `RadiusAndLayerTokensTest`, `DashboardActionAlignmentTest`, `JsIdSelectors` and `JsSelectorEmitterTest` — the seven files the first two CSS slices missed. Comments, reasons and messages, plus two `dataProvider` labels.
- **The last 35 Portuguese test files now read in English** (#1260): loaders, capabilities, recruitment, reregistration, submissions and the URL shortener — docblocks, comments and assertion messages, plus six fixture values (`umaSenhaBoa1` → `aGoodPassword1`, `Fulano de Tal` → `John Doe`, …). The token skeleton of all 35 is unchanged; the full suite is green at 8,037 tests.
- **The 6.23.0, 6.24.0 and 6.25.0 sections now read in English** (#1260): 126 bullets, the last Portuguese prose in the file. Headings, bullet positions and line count are unchanged — only the prose inside each bullet. The three published Release bodies still carry the Portuguese text: an agent cannot edit one, so the replacement text ships as generated output for the maintainer to paste.
- **The division/sector editor's JavaScript reads in English** (#1260): `ffc-divisao-setor-editor.js` becomes `ffc-division-sector-editor.js`, with its script handle and localized object renamed to match. The `divisao_setor` **field key stays** — it is written into `ffc_custom_fields` and into every stored submission's `data` JSON, so renaming it would need a migration.
- **The reregistration, migrations, submissions and URL-shortener comments read in English** (#1260): 434 lines of Portuguese docblocks and inline comments across 15 production classes. Comments only — the PHP token skeleton of all 15 is unchanged and not one string literal moved, so no behaviour, message or query is touched.
- **The runtime comments now read in English** (#1260): 39 files across `includes/`, `templates/`, `assets/js` and `uninstall.php` — activators, repositories, loaders, the encryption and password-invite core, the theme and badge-colour notes, and five templates. Comments only: every file's token skeleton and string inventory is byte-identical, and the two JS files minify to the same bytes as before.
- **The earlier slices' Portuguese scan was too narrow, and 160 comment lines survived it** (#1260): a dictionary of accented function words misses unaccented prose (`asercao`, `nao`, `so`) and tier names it never listed (`só vê`), so 35 files merged as translated still carried a Portuguese clause mid-paragraph. Found by requiring two distinct Portuguese function words on one line instead of one word anywhere, which also drops the `.com` / `DoS` false positives; three Portuguese assertion messages went with them.
- **The reregistration `Ficha` is now the `Record`, across every English-facing surface** (#1264): 2 classes, 2 methods, a JS function, a constant, an AJAX action and ~50 on-screen strings. The five public `ffcertificate_ficha_*` filters ride `apply_filters_deprecated()` and keep firing until 6.28.0, as does the old `ffc_generate_ficha` AJAX action — a rename there breaks an external integration in silence.
- **Four Portuguese values stay stored, and that is the point** (#1264): the `KIND_RECORD` template kind, the `ffc_reregistration_ficha_template` option, the seeder's `default_ficha` slug and `FilenameHelper`'s `ficha` type all reach the database or a public filter argument. Renaming the seeder slug alone would have inserted a SECOND default template on every existing install.
- **pt_BR reads exactly as before** (#1264): only the English source moved, so `Download Record` still translates to "Baixar Ficha". The rename did surface one real defect — the same document was labelled "Ficha" in three places and "Registro" in a fourth, and the `.l10n.php` resolved to the odd one while the `.mo` resolved to the other, so the word depended on the WordPress version.
- **Every number in `CLAUDE.md` traced to whatever owns it** (#1261): 517 numeric tokens enumerated, roughly forty confirmed against their source and left alone. Three sentences stated a conclusion that was no longer true — `ficha` called an open decision after #1280 settled it, five element selectors called baseline debt after #1202 anchored them, the utility layer called drifting after #1171 tidied it — and ten counts had drifted (Vitest 2 is 5, `tests/` holds more files than stated, four linter tallies). Counts that carry nothing were replaced by the invariant.
- **Nine `CLAUDE.md` cross-references now name their section instead of numbering it** (#1261): the #1288 renumbering had silently broken six of them in `includes/` and `tests/`, because nothing reads that file. Comment-only — the token skeleton and phpdoc tags of all nine files are unchanged.
- **Schema and capability for the reregistration CSV import** (#1214): two tables — `ffc_reregistration_import_jobs` (phase, progress, owner fence) and `ffc_reregistration_import_staging`, which stages each row as `longtext` JSON because a campaign's columns are per-audience `ffc_custom_fields` rows, not a fixed list. Both are in-flight state with a TTL, both are in `uninstall.php`.
- **New capability `ffc_import_reregistration`** (#1214): loading a campaign's answers from a spreadsheet writes encrypted PII for people who never opened the form, so it is carved out of `ffc_manage_reregistration` rather than implied by it. Seeded onto current holders by the existing one-shot migration, whose flag moves to `_v2` — a flagged migration never revisits an install, so a new pair alone would have reached only fresh installs.
- **The reregistration CSV import stages a file** (#1214): `ReregistrationImportStagingService::ingest_job()` resolves the header against one audience's `ffc_custom_fields` — exact match on `field_key` then `field_label`, never fuzzy — and mass-inserts each row as JSON. An unknown column is ignored and reported; a required field with no column at all refuses the file before anything is staged, since an absent column means every row would fail.
- **The reregistration CSV import validates before it writes** (#1214): `validate_job()` resolves each staged row to an existing user, finds its seeded submission and checks every value against the field definition. Any failure blocks the whole job — promotion is batched across requests and cannot undo an earlier batch, so this boundary is the only place all-or-nothing can hold. "Already submitted" skips its row instead, being expected state rather than a defect.
- **A read-only identity probe beside the resolver that creates** (#1214): `UserCreator::resolve_existing_user()` runs the hash and e-mail steps and stops before creating, so a caller can report what resolution *would* do — the `peek()`/`verify()` split the captcha contract already uses. Both it and `get_or_create_user_dual()` now share one lookup, so a probe can never find more than promotion will.
- **Two import rows resolving to one account block the job** (#1214): that is how a shared institutional mailbox presents, since e-mail matching binds every one of them to whichever account the address hits (#1295). The operator sees it before a single write.
- **The reregistration CSV import writes its rows** (#1214): `promote_batch()` resolves each ready row through `get_or_create_user_dual()` — the same resolver certificates and appointments use, so a known person is matched rather than duplicated — adds them to the chosen audience, seeds their submission if the campaign save has not, and writes through `ReregistrationDataProcessor::process_submission()`. Never the `data` JSON directly: that method is where every sensitive value is encrypted.
- **`commit_job()` deletes the staged cleartext** (#1214): promotion already wrote every submission, so unlike recruitment's importer there is nothing to swap at commit — what is left is cleartext CPF, RF and e-mail in a staging table, and dropping it is the phase's whole job. It refuses while any row is unpromoted, and carries no `committed` state — recruitment needs one because its commit can crash between the swap and the cleanup; here the cleanup is the whole phase.
- **The import sends no e-mail, and each half had its own reason** (#1214): creating 500 accounts meant 500 synchronous account notifications from inside a batch loop, and the confirmation reads "we received your reregistration" to somebody who did nothing. Both are suppressed by an explicit flag on the import path, not by the global kill switch. The campaign's own invitation still reaches an imported row — it selects on `invited_at IS NULL` whatever the status — and carries the password-setting link a created account needs.
- **The in-plugin documentation covers the import** (#1214): a section on the Reregistration page for the file format, the check-then-import flow, what the four counts mean, how a row is matched to an account, why no e-mail goes out, and the privacy properties. Plus `ffc_import_reregistration` in that page's capability list, where it had been missing since #1292.
- **A guard that a documentation page listing a capability group lists all of it** (#1214): `DocumentationCapabilityCoverageTest`. Ten pages hand-write capability lists — their descriptions are screen-specific and better than the generated reference page's — and three had fallen behind by one each, two of them the capabilities that decide whether a screen shows somebody's CPF.
- **A `reregistration` capability context that grants nothing** (#1214): the import would otherwise have to reuse `certificate`, which grants three certificate caps to somebody who may hold no certificate — a grant nobody asked for, recorded as if it had been decided.
- **The reregistration import has a screen** (#1214): a panel on the campaign editor with the campaign's own audiences, an upload, and the validation report. Two clicks, deliberately — "Check file" stages and validates and stops, "Import" writes. The recruitment importer flows straight through; here the report exists to be read, and this import cannot be undone.
- **Every phase is fenced to the operator who started the job** (#1214): nonce and `ffc_import_reregistration` are not the whole check, because every operator who can import holds the same capability — without the fence a second one could commit somebody else's job away mid-review. The job id being a random UUID is why that is unlikely by accident and exactly why it is not a defence.
- **Import errors reach the screen as sentences** (#1214): `ReregistrationImportMessages` renders each code the staging service emits, naming the file line and the column at fault. The code stays in the database — a stored sentence would freeze the language of whoever ran validate, and a second operator finishing the job would read it in that one.
- **No temp file, and the plan had budgeted for one** (#1214): the #772 discipline for PII on disk — `wp_upload_dir()/ffc-tmp`, an `.htaccess`, a cleanup cron, and the nginx caveat that comes with it — does not apply here. Staging reads the upload straight into the database, so the only file is PHP's own upload temp, which PHP unlinks at the end of the request.

### Fixed

- **Two documentation pages under-reported who can see decrypted PII** (#1214): the appointments page omitted `ffc_view_appointments_pii` and the submissions-list page omitted `ffc_view_certificates_pii`, so both read as though managing the screen were enough to unmask a CPF. Both lists now name the capability and say the reveal is audited.
- **The import could have written past the form's own sanitizer** (#1214): `process_submission()` encrypts and persists, it does not sanitize — the public form's per-type sanitization lives in `collect_form_data()`, behind a `$_POST` read. Its body is now `sanitize_values()`, which the import calls with the staged payload, so both paths sanitize identically by construction.
- **A concurrent seed would have aborted an import batch** (#1214): promotion checked the return of its `INSERT` before re-reading, but `ffc_reregistration_submissions` carries `UNIQUE KEY idx_reregistration_user` — so another promote, or the campaign being saved in a second tab (#1234), makes that INSERT fail with the row sitting right there. The re-read is the guard now: the question that decides promotion is "is there a row?", not "did my INSERT land?".
- **A Brain\Monkey `when()` in `setUp()` makes a later `expect()` count zero** (#1214): the stub satisfies the call itself, so a test asserting "this phase checks the nonce" passed whether or not it did. Same shape as the `overload:`/static finding above, found the same way — by deleting the check and watching the test stay green. The default stub moved out of `setUp()` into the helper the other tests call.
- **A Mockery expectation on a static method was inert** (#1214): `overload:` intercepts `new`, not a static call, so `shouldReceive( … )->once()` on an overloaded class passes with zero calls and `shouldNotReceive()` passes with any number. Measured while adding a test that passed with the code under test deleted; the three `ReregistrationEmailHandler` expectations are `alias:` now.
- **PDF filenames were Portuguese for every install, translatable or not** (#1264): the three `_x()` prefixes shipped `certificado` / `recibo` / `ficha` as the **source** strings, each translating to itself, so an English site downloaded `certificado_123.pdf`. Sources are now `certificate` / `receipt` / `record`, with pt_BR translating back — nothing changes in Portuguese.
- **The shipped `.mo` was stale, so WP 6.4 never received the #1209 label fix** (#1264): `.l10n.php` carried `Union` → "Sindicato" and `Acknowledgment` → "Termo de Ciência" while the `.mo` still said "Estado" and "Agradecimentos". WordPress reads `.l10n.php` only from 6.5 and this plugin's floor is **6.4**, so on the floor "Union" still collided with the address state — the exact defect #1209 fixed. Recompiled; 8 entries the `.mo` lacked are now in it.
- **The record-template option was never deleted on uninstall** (#1264): `ffc_reregistration_ficha_template` survived a full uninstall, orphaned. The CI fresh-install gate could not see it — that gate compares what *activation* writes, and this option is only written when an admin saves the Reregistration tab.
- **79 source strings had never reached the catalogue, so pt_BR rendered them in English** (#1282): the whole password-invite screen, the reregistration invitation controls, the new Rate Limit captcha-cap fields and the key-rotation migration's error messages. The catalogue was complete against itself and against the `.pot` — nothing inside `languages/` looked wrong — because nothing compares either with the source. Translated; `.mo` and `.l10n.php` recompiled from the `.po`.
- **`ffcertificate.pot` declared one `msgid` twice** (#1284): the appointment-confirmation email body had a block per source site instead of one block with two references, which `msgfmt` rejects outright. Nothing reported it — every reader keys the entries, and keying makes a duplicate vanish rather than fail.
- **40 of the 60 live capabilities survived uninstall on every install** (#1290): removal intersected a hand-written list that named 31 of them, so `ffc_manage_settings`, both PII capabilities and 37 others stayed as user-meta and role grants after deletion. Now a prefix sweep over `ffc_*`, on users **and** on roles the plugin does not own. That second residue is guaranteed, not hypothetical: `AudienceActivator` grants `ffc_view_own_audience_bookings` to WordPress's own `subscriber` role at activation, so every install has carried an FFC capability on a core role since day one, and nothing removed it. `administrator` also kept its FFC capabilities on any install older than 6.16.0.
- **Every install put an FFC capability on WordPress's own `subscriber` role** (#1302): `AudienceActivator` granted `ffc_view_own_audience_bookings` there at activation, reaching every subscriber on the site whether or not they belonged to any audience. It was covering a real gap — only the self-join route granted the capability, while the admin-side paths granted nothing — so the fix is the grant moving to `AudienceWriter::add_member()`, the single point every membership passes through. A one-shot migration seeds existing members and then clears the role.
- **A new WordPress administrator saw "FFC Settings" and none of the six feature menus** (#1302): #747 moved the admin tier onto `ffc_administrator` and stripped the FFC capabilities from the native role, but its back-fill is one-shot — so an administrator created afterwards got neither, and every other top-level menu registers against a raw `ffc_view_*` slug with no `manage_options` escape. `ffc_administrator` now follows the `administrator` role continuously, and the back-fill re-runs once for anyone created in the gap.
- **Table removal no longer depends on somebody remembering the list** (#1291): `uninstall.php` discovers the set with `SHOW TABLES LIKE '<prefix>ffc\_%'` and drops the union of that and the declared list, so a table created but never declared — the `ffc_recruitment_import_staging` case the CI gate caught *after* it shipped — can no longer be left behind. The list stays as the manifest the fresh-install gate and the post-deploy smoke read. Nothing was leaking: 34 declared, 34 created, zero either way.
- **Under ALTCHA, certificate verification could never succeed** (#1305): the `/valid` handler built its payload from four named inputs, two of them the math captcha's, so the field the widget posts — `altcha` — was never sent. The visitor watched the box turn green and was told *"Please complete the verification"*, on every browser, cache cleared or not. It now serializes the form, like the certificate and booking forms always did, so a field a future provider introduces travels on its own.
- **After a refused submission the ALTCHA widget stayed green holding a spent token** (#1305): `verify()` spends the challenge, so every error path — including "document not found", which is not a security failure — asks the client for a fresh one. Both copies of `refreshCaptcha` knew only the math shape and silently did nothing, so the next attempt was rejected with "already used" and re-ticking the box did nothing. It now dispatches on `provider` and resets the widget.
- **The public CSV download's form could be hijacked by the verification handler** (#1305): both carry `.ffc-verification-form`, and with the two shortcodes on one page the delegated handler cancelled the native submit and then threw on a field that is not there, leaving the form dead. It now checks for the field before cancelling anything.

## [6.25.0] (2026-09-15) — `59ccb2b`

### Added

- **A password-setting screen in the reregistration invitation** (#1212): the account was born with a random password nobody told the invitee, and the button led to the dashboard, which requires a login. The link opens the screen; the session is only born once the password exists.
- **Import the answers from the last approved reregistration** (#1213): on entering a new campaign, whoever already had an approved cycle sees a notice offering to bring the data over. Only fields that exist in the current campaign come back, and what was already typed is not overwritten.
- **An authentication code in the footer of the reregistration record** (#1211): `R-XXXX-XXXX-XXXX`, the same format as the invitation email and the certificate. Only on an approved submission.
- **Spacing, radius and stacking now read a scale** (#1169, #1171, #1176): 1,147 spacing declarations (they were **3 of 1,238**), 149 radius values and 16 layer values — without moving a pixel, proven by the values resolved before and after.
- **Page scope in the admin, and the namespace-anchor ratchet goes from 60 to 3** (#1152, #1184): the plugin's 23 `div.wrap` now declare which screen they belong to, and 48 selectors over WordPress markup descend from that anchor instead of reaching every screen where the sheet happens to load.
- **Six new guards** (#1152, #1162, #1168, #1170, #1220): an id the JavaScript looks for must have an emitter; a selector must name something of ours; a bare class in two sheets with no edge between them is forbidden; the contrast meter derives the pairs from the sheets instead of reading a hand-written list.

### Changed

- **Five labels and five requiredness flags on the standard reregistration fields** (#1209): RF becomes "Registro Funcional (RF)", "Apto/Bl" becomes "Complemento", and `rf`, `endereco`, `endereco_numero`, `email_institucional` and `sindicato` become required. Applies to **new audiences**.
- **The PDF bundles carry the version in the filename** (#1202, #1203): bumping the constant without renaming the file now 404s loudly instead of serving the old bundle under a new key — which is what the appointment receipt was doing, announcing `?ver=2.5.1` for a jsPDF 4.2.1.
- **Typography now reads the scale, with a px floor** (#1148, #1157): there were 415 `font-size` declarations and not one read a token. Each step becomes `max(13px, 0.8125rem)`: immune to a theme with `html { font-size: 62.5% }`, which rendered the body at 8.1px.
- **Components get names of their own** (#1151, #1154, #1170, #1171): 38 classes of ours were declared with no prefix, the reregistration modal shared `.ffc-modal` with the shared component, and nine screen components lived in the utilities drawer. Without moving a pixel.
- **The code-editor theme moved to the General tab, next to Dark Mode** (#1148): it sat under Advanced, and one of its three values is `auto`, which follows Dark Mode. Whoever went looking for the editor's theme looked next to the theme.
- **Fixes to the guards themselves and to `CLAUDE.md`** (#1182, #1185, #1187, #1193, #1201): two scans read the wrong source and hid what they were meant to measure; the emission one reported nine classes as orphans while the emitter sat in the repository; and the Dependabot flow told an agent to post a command that fails in silence.

### Deprecated

- ⚠ **`AppointmentRepository::getStatistics()` and `AppointmentReader::getStatistics()`** (#1245): no product caller anywhere in the tree. Both now emit `_deprecated_function()` under `WP_DEBUG` and will be **removed in 6.27.0**. There is no replacement; if your integration uses either, open an issue before that release.

### Removed

- **The six ids the JavaScript looked for and nobody emitted** (#1227): the id-based dependent select, the migrations-dropdown controller (whose markup exists in no revision of the history) and the lookup for `#ffc_bg_image_url`. Eight tests that exercised only dead code went with them.
- **Eight CSS rules that painted nothing** (#1193, #1202): six `.ffc-settings-page` selectors a sibling in the same rule already reached, and the two on `.tablenav` — one restated what WordPress already declares, the other was the property's initial value.

### Fixed

- **The capability migrations swept every user of the install on every request** (#1254): eleven of them, on `plugins_loaded` — possibly landing in an anonymous frontend request. And the completion flag is only written **after** the sweep, so a timeout partway through made the next one start again from zero, indefinitely.
- **Every `encrypt()`/`decrypt()` redid two 10,000-round PBKDF2 derivations** (#1230): 11.56 ms per derivation and **2,384 ms to draw the Submissions screen** — which now costs 0.7 ms. The inputs are PHP constants, so memoising cannot diverge.
- **The daily sweep of expired tickets never ran** (#1234): the callback was registered inside `if ( is_admin() )`, and `wp-cron.php` defines `DOING_CRON` and **never** `WP_ADMIN`. And the retention sweep issued an unbounded `DELETE` — it now runs in pages of 500, at most 20 per run.
- **Six queries whose cost grew with the table** (#1234): a `SELECT DISTINCT` in the Activity Log on every render, 5,000 rows with a `json_decode` each per render of the editor, a `COUNT(*)` on the largest table to answer a boolean, the candidate's history as N+1, the campaign seeding per member, and the decryption-failure log with no ceiling.
- **Four activator chains probed the schema on every request** (#1231, #1234): 48 uncached DDL queries per page, anonymous frontend included, on an install with nothing to migrate. They are now gated on `FFC_VERSION`; and the key-rotation batch's `column_exists()` is now resolved once per batch.
- **Reregistration reminders now go out in batches of 50** (#1232): the daily sweep runs **inside a visitor's request** — unbounded, a campaign of thousands made that visitor pay for thousands of `wp_mail()` calls. The keyset cursor steps past the orphan row that never gets a stamp.
- **The reregistration reminder was resent every day** (#1232): the query uses a **window** and the cron is daily, with no per-submission mark — with `reminder_days = 7`, every pending participant received seven emails. There is now a `reminder_sent_at`, and the Documentation records that a manual resend **replaces** the automatic one.
- **The reregistration invitation re-invited whoever had already received one and never reached whoever joined later** (#1190): it read `status = 'pending'` as "needs an invitation", an approximation that errs in both directions; and whoever belonged only to a child audience saw the banner with no submission row. There is now an `invited_at` and the send is idempotent.
- **Whoever joined the audience after the campaign went active could not reregister** (#1188): the dashboard drew the button and the three AJAX handlers demanded a row nobody created. The row is now born on demand. And the button, which read "Recadastramento Completo" — a state that does not exist — becomes "Fazer o Recadastramento".
- **The job-accumulation group never hid itself** (#1209): the handler looked for an id and a class no PHP emits, and only reacted to `change`. It now selects by `data-field-key` and runs on initialisation. Alongside, "Union" stops being translated as "Estado", which collided with the address's state.
- **A returned draft came back carrying the ciphertext of the sensitive fields** (#1210): the user saw the blob in place of their own CPF and could not edit it. Whoever reads it is the authenticated subject editing their own data; the write stays encrypted.
- **`SchemaAgreementTest` measured 2 of the 6 activators, and its self-check did not catch it** (#1241): it read only the plural of `add_columns_if_missing()` and demanded a `CREATE TABLE` in a fixed shape, which made recruitment's 9 statements invisible. Twelve columns existed only in the migration and were declared; three cannot be, because they are #249 staging columns.
- **Ten text pairs below the WCAG AA contrast floor** (#1168, #1170): four rules hardcoded `color: white` over tokenized grounds and measured 1.23 to 2.78:1 in the dark theme; six used a signal colour as text. And the reregistration's past row measured 3.11:1 by saying "past" with `opacity`.
- **Components lost their shape in high-contrast mode** (#1165, #1193): under `forced-colors` the system discards shadows, so whatever had its boundary only in the background was left with none — the appointment's five status badges became one. 88 controls and badges gain an outline.
- **The recruitment and reregistration badges were painted by three sheets and by the `style` attribute** (#1162, #1183, #1193): inline beats class, so the same pair of classes rendered different shapes depending on the emitter; "Definitive" lost its colour when the ENUM migrated and the sheet did not; and "Cancelled" came out amber in the Appointments list. Each family gets a name of its own.
- **The User Access tab came out 2px indented and 22px narrower than the others** (#1202): it opened a `div.wrap` inside the screen's own `.wrap`, and the core margin counted twice. Alongside, the user dashboard's six panels stop publishing unprefixed ids — `#tab-profile` is unique in the document.
- **The user dashboard loaded nothing until the visitor clicked a tab** (#1170): the rename of `active` to `is-active` did not reach the two sites that read `$('.ffc-tab.active')`. The tests' fixture still used the old class, and it was the fixture holding both cases green.
- **The dashboard's Actions-column buttons misaligned against each other** (#1215): the three had the same height and different tops — an `inline-flex` inherits its baseline from the first flex item, an `inline-block` takes it from the text. They now declare `vertical-align: middle`.
- **The background-image preview never appeared** (#1227): the code that draws it has been correct all along, but neither of the two screens emitted the container it looks for — the lookup failed in silence, guarded by a length check.
- **The shortener's QR cache served the wrong size** (#1233): indexed by `short_code` alone, a REST call with `size=1000` received the metabox's 200px PNG and, with an empty cache, wrote 1000px there. The cache now serves a single size and stores it alongside the PNG.

### Security

- **Each submission's scheduling wrote PII and the certificate credential in clear text into `wp_options`** (#1248): the cron payload carried the whole `submission_data` and the `magic_token` — the authentication behind `?token=…` — twenty lines after the same method had encrypted email, CPF and RF. And the `cron` option is autoloaded. Only the id travels now.
- **Key rotation now covers the users' profile PII** (#1236): `ffc_user_cpf`, `ffc_user_rf` and `ffc_user_rg` were encrypted under the key derived from WordPress's salts — rotating `SECURE_AUTH_KEY` would have made them unreadable forever. And the error about duplicate candidates stops being silent.

## [6.24.0] (2026-09-10) — `c08e59a`

### Fixed

- **The email hub did not offer a token its own default body uses** (#1143): the per-email help list is hand-written text sitting next to a template maintained elsewhere, so it drifts in silence — in both directions. A new guard compares catalogue, allowlist and files: a token used in the default body and absent from the list fails CI.
- **The appointment-approval email did not carry the cancellation link** (#1143): on a calendar that requires approval, the booking email only says "awaiting approval, you will receive the confirmation" — the confirmation **is** the approval email, and it was the only one of the five appointment emails without the link. Whoever actually had a confirmed appointment was precisely who could not cancel from their inbox. The existing coverage proved the token resolving inside a hub override, which says nothing about the body an install with no override receives.
- **An admin number field saved an invalid value through autosave** (#1114): the widget saves on every keystroke and never consulted the field's validity, so `min`, `max` and `step` were decorative there — empty, negative and decimal all went through. Both autosave paths now refuse what the browser considers invalid; toggles are unaffected.
- **Clearing a limit on the Rate Limit tab wrote zero and blocked the whole axis** (#1114): the checkers read `count >= 0` as "limit already reached", so an emptied `ip_max_per_hour` barred every submission from every address. An empty or absent field now keeps the stored value, with a floor equal to the `min` the field itself declares.
- **A required field on the user profile was enforced at neither end** (#1120): `is_required` only drew the asterisk — no input emitted the attribute and the save did not check the flag, so a field marked required stored empty in silence. Now the browser demands it and the server preserves the stored value when the field arrives empty, reporting which ones it preserved.
- **Clearing the reminder field of a reregistration campaign wrote zero** (#1117): `absint( '' )` is `0`, and the sweep reads `DATEDIFF(end_date, CURDATE()) <= reminder_days` — the reminder then went out only on the campaign's last day, too late to be of use, with nothing on screen saying the value had changed.
- **Clearing an admin number field stored that key's floor, in silence** (#1111): `(int) ''` is `0`, so emptying the field wrote the smallest accepted value — and autosave saves on every keystroke, so merely clearing it to retype was enough. The endpoint now refuses an empty value and keeps the stored one; a typed `0` still counts.
- **The access-granted email was never sent by any install** (#1123): `notify_capability_grant` gated the send and was written nowhere — no field, no allowlist, no declared default — so the guard read `false` forever, while the email hub offered the text for editing. It gained a switch on the SMTP tab, off by default to preserve today's behaviour.
- **A half-filled working-hours row was stored in silence** (#1128): the server guard was `isset( $entry['entry1'] )`, and `isset( '' )` is true — so a row with an empty time went through. The public reregistration form was even more permissive: it checked the day alone. One sanitiser now decides for both screens.
- **Reactivating a reregistration campaign notified nobody** (#1125): on expiry the pending submissions become `expired`; reactivation did not reopen them — and the seeding skips whoever already has a row — so the user sat in a state that neither allowed a submission nor drew a notice. The email invitation did not go out either, because it looks for `pending` only. Reactivating now reopens the `expired` ones, without touching `approved`/`submitted`.
- **Whoever joins the audience after activation did not see the campaign** (#1125): the seeding is a one-off event on the transition to active, so that user never got a row and fell into the same silent gap. "No submission" is now a state that can submit — which also covers the submission an administrator deleted, returning the user to the start.
- **Dark mode: all 28 sheets now paint from the palette** (#1126): half of them used no token at all — calendar, shortener, recruitment, reregistration, submissions, certificate dashboard, audiences, email model and the rest painted with hardcoded hex, so badges, modals, tables and the toast bubble stayed light inside the dark screen. The ratchet blocks at zero; whatever stays literal is a decision with the reason written beside it.
- **The palette has to be on the page, and it was not — in four different ways** (#1126): an undeclared custom property **invalidates the whole declaration**, it does not fall back to the previous literal. Sixteen enqueues read `var(--ffc-*)` without declaring `ffc-common`; the dashboard and the notices list redeclared `--ffc-*` locally, which shadows the palette for everything inside; two rules read a token that never existed, hidden behind the fallback value; and the public cancellation page builds its own document, so it never went through the enqueue at all — it came out with no background, no text colour and an unpainted button, **in both themes**.
- **The switch did not reach four public screens** (#1126): calendar, audiences (two paths) and notices enqueued the palette but not the script that puts `.ffc-dark-mode` on `<html>`. Without it every `var(--ffc-*)` resolves through the light block, however tokenized the sheet may be. The palette alone is not the theme.
- **Text that declares no colour inherited near-black, and a form control did not even do that** (#1126): whatever declares no `color` inherits from outside the plugin — from wp-admin's `body` or from the theme — and lands at **1.28:1** on a dark ground; it was the calendar legend and the "Modelo:" label. A `<button>`, `<input>`, `<select>` or `<textarea>` **does not inherit `color`** and the base rule cannot reach it either: there were 18, including every field of the public reregistration form and of the profile edit. The admin notices needed a third answer — naming the text nodes, because any direct declaration beats an inherited value.
- **`opacity` and `.alternate`: two defects no token would fix** (#1126): the agenda's past/cancelled row carried `opacity`, which fades text **and** ground together and pulls down whatever contrast the tokens guaranteed — 3.11:1 measured. And `.alternate` is WordPress's third way of striping a row, the one the plugin hand-writes seven times on the Cache tab alone.
- **An inline colour beat the tokenized sheet** (#1126): the emails-disabled notice carried the whole palette in a PHP `style=""`, on five screens; notice badges and bands hardcoded `#333` over a colour the **administrator picks**. For the second case no token can work — the text is now computed by WCAG luminance, with 4.58:1 guaranteed over any colour of the RGB cube.
- **Three button labels failed the contrast floor** (#1126): success (3.35:1) and warning (3.04:1) over white, and `.ffc-text-warning` using a signal colour as text. The green darkened to `#00801e`; the pairs `on-success` and `on-warning` were born — the first inverts between the themes, the second does not, because the warning is a mid-light tone in both.
- **Switching dark mode only took effect on the next load** (#1126): the selector saves on its own, but nothing repainted the page — in either direction, which reads as a save that failed. Autosave now announces what it wrote and the theme script applies it live, including dropping the operating-system listener when leaving "auto".
- **Ten colour pairs of the palette failed the minimum contrast** (#1132): the worst was the primary button's label in the dark theme — white over the light primary, 2.52:1, ever since the dark theme has existed. Material 3 inverts that pair in the dark (the primary lightens, the text over it darkens) and the plugin had done only half of it. Also fixed: the field and card outlines in both themes and the focus ring, which composed 1.78:1 in the light one.
- **The placeholder and the warning indicator got darker in the light theme** (#1132): `#8c8f94` and `#dba617` come from WordPress's own palette and failed at 3.24:1 and 2.22:1. The fix moves the plugin slightly away from the wp-admin look — a deliberate decision, in favour of legibility.
- **Five of the eight audience mutators did not invalidate the user's audience cache** (#1127): the key lived in a literal group of its own, outside the Reader/Writer pair, so every mutator had to remember on its own — and `bulk_add_members()`, the admin path, did not. It decided the dashboard's reregistration campaign and the profile's custom fields wrongly. The key now lives in the pair's group, with a single invalidation point and a TTL, which it did not have before.
- **Leaving an audience did not clear the cache of whoever left** (#1127): `set_members()` invalidated only the list coming in, so whoever was removed kept seeing the audience. `cascade_self_join()` invalidated nothing — neither by id nor the query version — and it changes `allow_self_join`, which the list returns.

### Changed

- **Cancelling an appointment from the dashboard now opens a dialog, not a `confirm()`** (#1140): the `confirm()` did not say **which** appointment would be cancelled and had no reason field — so a cancellation from the dashboard stored an empty reason while the same one through the email link stored what the person wrote. The endpoint already accepted and sanitised `reason`; the gap was the client's alone. The dialog shows service and date/time, and reuses the same strings as the public page.
- **The modal becomes a shared component in the palette** (#1140): there were two copies — one in audiences, another in the reregistration admin — and the dashboard would have been the third. The audiences one moved to `ffc-common.css`, which every sheet already using it declares as a dependency. The admin one stayed where it is: unscoped selectors, a different screen.
- **Admin number inputs outside the settings tabs now demand a value** (#1117): 19 fields gained `required` — self-scheduling, quiz, recruitment, reregistration and the email model. Eight were left out, each with its reason frozen in a test: in five, empty means "inherit the global" or "no limit", one is not a form field, and in two the requiredness belongs to the field's own definition.
- **`required` now travels with the blocks the JS hides** (#1117, #1120): a hidden required field blocks the submit against a control nobody can see. It closes two cases that were already live — the calendar editor's working-hours rows, and the user profile's two time fields, required inside collapsible sections.
- **The admin's two autosave paths became one widget** (#1116): `data-ffc-autosave-form-key` had its own implementation in `ffc-admin.js` — same job, different event, different badge — and #1114's validity guard had to be written twice to reach both. One widget now reads both attributes; the endpoints stay separate, with their own capability and nonce.
- **The metaboxes' autosave badge now follows dark mode** (#1116): it used hardcoded hex instead of the `--ffc-*` tokens. Along the way it tells a dropped connection from a server refusal, which the old implementation flattened into a generic "failed to save", and the settings tabs' strings stop being English literals in the JS.
- **The settings tabs' number inputs are now required** (#1114): 38 fields gained `required`, and `smtp_port` — the only one with no bounds at all — gained a range. The read-endpoint caps now say on their own card that `0` removes the limit there, unlike the others.
- **The captcha-issuing window's floor lowered from 60s to 1s** (#1111): under a minute the limit becomes decorative — the field now says so as a recommendation, instead of the code refusing, in line with the tab's other limits.
- **Working hours: the row's rule is now enforced, and before the submit** (#1128): the first shift's start and the last one's end are required, the break is optional — a rule that existed only as a `required` in the markup and that nobody had written down. A half-filled row blocks the submit by naming what is missing and opening the collapsed section, instead of the server deciding on its own what to discard; nothing typed is lost. ⚠ A row with no times at all is no longer stored: a day with no row already means "I do not work on this day".
- **No reregistration status produces a blank screen any more** (#1125): the dashboard handled `approved` and `submitted` and fell into a silent `continue` for everything else. It gained a final branch showing the readable status — the two cases that landed there are fixed at the source, and the branch exists so the next status does not repeat the story.
- **Dark mode now repaints what WordPress draws** (#1126): dark mode was only the token swap, and nothing applied those tokens to the core chrome — so `form-table` labels came out dark on a dark ground and the fields stayed white inside the dark card. Labels, fields, cards, metaboxes, list tables, tabs, secondary buttons and notices now follow the palette. Still missing: the half of the admin sheets that use no token at all.

### Removed

- ⚠ **The `$token` argument of `check_verification()`** (#1048): announced deprecated in 6.22.0, removed now per the published schedule. `RateLimiter::check_verification()` and `RateLimitChecker::check_verification()` now take the IP alone. No caller passed the second argument, and whoever does will not break — PHP ignores a surplus argument on a user-defined function.
- **Seven `SettingsReader` accessors that read the wrong option** (#1123): `admin_bar_allowed()`, `wp_admin_blocked()` and `admins_bypassed()` read keys of `ffc_user_access_settings`; `ip_cache_enabled()`, `ip_api_enabled()`, `ip_cache_ttl()` and `gps_cache_ttl()` read keys of `ffc_geolocation_settings`, duplicating correct accessors that already existed. Nothing called them, and nothing wrote those keys into `ffc_settings` — they returned the built-in default forever.

### Added

- **ALTCHA challenge issuing is now configurable** (#1111): a cap and a window in the IP block of the Rate Limit tab, defaults 60 and 600s — the values that were already in force — and a cap of 0 = no limit. It was the system's only limit with no control in the interface: behind institutional NAT it barred the form for everyone after the 60th challenge, with a 429 and no visible error.
- **A write-path guard over `ffc_settings`** (#1123, #993): every key read from the option must be writable into it — by a field, by the autosave allowlist or by a declared default. It blocks at zero, with no baseline, and catches both shapes that motivated the issue: the accessor reading another option's key, and the dormant feature. Alongside it goes a ratchet of the 32 keys read without a declaration, which can only shrink.
- **The dark-mode guard now measures contrast** (#1132): before it only looked for a colour literal — it saw presence, not legibility. It now computes the WCAG ratio of every pair the CSS actually paints, in both themes, and blocks at zero. It would have reported the dark theme's primary button on the day it was written.
- **A colour-literal ratchet per stylesheet** (#1126): the seven converted sheets block at zero; the rest carry the measured number as a budget that can only shrink. Alongside it goes the half that was missing — every sheet reading `var(--ffc-*)` must declare `ffc-common` as a dependency, which is what found the sixteen enqueues with no palette.

## [6.23.0] (2026-09-07) — `e029d6f`

### Security

- **The math captcha was bypassable by replay** (#1053): the token derived from the answer alone, with no expiry and no single use, so a `(answer, token)` pair captured once authenticated any later submission, on any form. It is now signed with a site-derived key, expires in 10 minutes and is consumed on redemption.

### Added

- **An ALTCHA proof-of-work captcha, served entirely by the site itself** (#1053): the challenge comes from a plugin endpoint (`ffc_altcha_challenge`), the work happens in the visitor's browser and the verification is plain PHP — no request leaves the server. The MIT widget is vendored in `libs/js/`, with no CDN. Three modes: math only (the default, unchanged on upgrade), ALTCHA only, and ALTCHA with the math half inside `<noscript>`.
- **A Settings → Captcha tab** (#1053): a choice between the three modes, with each one's effect stated in the option itself — the ALTCHA-only mode is refused at save time without HTTPS, because the widget refuses to run outside a secure context and the form would become impassable. The work factor and the challenge's validity have a floor and a ceiling; below the floor the proof costs nothing, above it a slow phone grinds until the widget gives up at 90s.
- **A `dbDelta` idempotence guard** (#1087): the `fresh-install` job replays every `CREATE TABLE` through `dbDelta()` against the table it has just created; whatever it wants to alter there is an `ALTER` on every future activation — the #997 class, until now measured once by hand and never again. The first measurement found 14 statements in that state; all 14 were fixed and the gate blocks at zero, with no baseline.
- **A schema-agreement guard** (#1087): `tests/Unit/SchemaAgreementTest.php` compares, with no database, the `CREATE TABLE` against the incremental columns of the same file and against declarations of the same table in different files — three tables are declared by more than one class. That is how the fresh installs being born incomplete, below, came to light.
- **A guard against untyped superglobal casts** (#1087): `tests/Unit/RequestInputCastTest.php` freezes into a baseline the integer casts applied directly to `$_POST`/`$_GET`/`$_REQUEST` — 55 today — as a ratchet that can only shrink. A new cast fails CI and points at the right accessor; a removed one fails too, to lock the win in.
- **A level-9 ruler over the classes that read `$wpdb` rows** (#1060): `phpstan-rows.neon.dist` plus `.github/scripts/phpstan-rows-report.php` run level 9 over the same tree as the main gate and report only the files that read rows. This is #1058's blind spot: `$wpdb` returns every column as a string, and passing `mixed` to a typed parameter is only checked at level 9. It came in non-blocking at 243 errors and ends the cycle blocking with zero slack.
- **`Core\ArrayValue`** (#1060): reading a scalar out of an untyped array — decoded JSON, form configuration, a token payload. It replaces the idiom `(string) ( $data['k'] ?? '' )`, which checks nothing: given an array it produces the string `Array`, and given an object with no `__toString` it is fatal.
- **`SettingsReader::get_string()`** (#1060) and **`RequestInput::get_post_raw_array()` / `has_post()`** (#1075): the accessors that were missing. The first refuses a non-scalar value instead of converting it; the second returns the `$_POST` container with no element sanitised, so the caller can sanitise field by field with the right function for each — `get_post_array()` flattens a nested row to `''` and strips the markup from a body that needs `wp_kses_post()`. `has_post()` tells "field absent" from "field empty".

### Changed

- **Honest row shapes across the repository** (#1060, #1075): the 45 classes that read `$wpdb` rows declare what the row contains, derived column by column from the `CREATE TABLE` — every column arrives as a string, and a column without `NOT NULL` is nullable even with a `DEFAULT`. The level-9 ruler went from 243 errors to zero and the job lost its `continue-on-error`. In several of them the type hole was not `$wpdb`: it was the object cache, whose `wp_cache_get()` returns `mixed`.
- **The `SettingsReader`'s three typed accessors follow one rule** (#1060): a scalar is converted, everything else returns the default. `get_int()`/`get_bool()` converted blindly, and they are the silent half of the pair — `(int) array('45')` is `1`, with no warning at all. No value stored in `ffc_settings` is non-scalar, so nothing changes on read.
- Internal (#1053) — the captcha now has a strategy contract (`CaptchaProviderInterface` + `CaptchaProvider::resolve()`), with the math challenge behind it. The 6 verification sites and the 4 retry ones do not change: `validate_security_fields()` remains the single chokepoint and now delegates the captcha half. The two copies of the security block became one, under `templates/`.
- Internal (#1053) — the cached-page fragment endpoint now serves the configured provider's challenge, instead of calling the math captcha directly; the client dispatches on the payload's `provider` field and leaves alone what it does not recognise, rather than applying half a payload. It is the fifth refresh site, the one the contract's unification had not reached.

### Removed

- ⚠ **BREAKING — the `html/` directory and its layout fallback were removed** (#1087, #865): the layout selector is now served **exclusively** by the template pool. Out go the `html/*.html` glob, the load-by-filename path and the whole directory. Both exit conditions were confirmed on a real install — `import_legacy_templates` and `rewrite_html_image_refs` read 0 pending. Anyone still depending on a file in `html/` needs to import it through the pool before upgrading.
- **`MigrationCustomFieldsTables`** (#1087): it created three tables the activators already create **before** it in the same activation, and did nothing else — not even its `get_status()` had a consumer. A shim from when the activators did not yet exist. The option it wrote stays in `uninstall.php`, because existing installs have it.
- `Shortcodes::get_new_captcha_data()` (#1053): a public method with no caller in production — its only consumer was its own test. Generating a challenge is already the captcha contract's responsibility.

### Fixed

- **A fresh install of `ffc_submissions` was born with 7 of its 25 columns** (#1087): the other 18 only arrived later, through `Activator::add_columns()`, on the first change of `FFC_VERSION`. The `CREATE TABLE` now declares all 25 plus the 12 keys — including the two composite indexes that existed only on an already-upgraded install.
- **A fresh install of `ffc_reregistration_submissions` was born without `auth_code` and `magic_token`** (#1087): the two columns only arrived through `add_columns_if_missing()`, on the first change of `FFC_VERSION`. The same class as the submissions table.
- **A fresh install of `ffc_custom_fields` was born with 12 of its 17 columns** (#1087): the user dashboard's activator did not declare `field_group`, `field_source`, `field_profile_key`, `field_mask` and `is_sensitive` — the five that every custom-field INSERT writes. It came out right only because a one-shot migration ran later, in the same activation. The third occurrence of the same class, and the first between declarations in different files.
- **Table declarations that disagreed with what the database stores** (#1087): the 14 the idempotence gate measured, all fixed. `ffc_reregistrations` declared `audience_id`, a column the activator drops immediately afterwards; `auth_code` was declared as a plain index on the two tables where the code converts it to `UNIQUE`; 20 integer columns omitted the display width MariaDB stores; and ten were declared `json`, a type MariaDB implements as `LONGTEXT` plus a CHECK — they become `longtext`, since no query in the plugin uses a native JSON function.
- **Installs carried two indexes on `auth_code` and two on `magic_token`** (#1087): the activator and the migrations indexed the same columns under different names, so whoever went through both paths pays in writes and space for nothing. The declarations now agree, which stops fresh installs inheriting the pair, and activation drops the legacy ones — always conditional on the canonical index existing, so the column is never left unindexed.
- **The AJAX wiring guard could not see a third registration idiom** (#1087): `add_action( 'wp_ajax_' . static::action(), … )` resolves in the subclass, so the dismissible notices' four actions fell outside both directions of the test. The wiring was correct — but by luck, not by verification. Four guards gain a counterweight that fails when their own scan collapses, instead of passing in silence.
- **A cache hit threw away the type the database read had declared** (#1087): `wp_cache_get()` returns `mixed`, so six methods across five repositories lost the row's shape on the cache path — #1072's finding, which never reached them because the ruler could not see them. The ruler measured 45 classes when 53 read rows: it demanded the literal substring `$wpdb->`, so every repository binding wpdb as a property was invisible. With the 30 standing errors fixed, the gate blocks again with zero slack over the right 53 classes.
- **`RequestInput::get_post_int()`/`get_get_int()` read an array as the number 1** (#1087): `absint( array('45') )` is `1`, in silence — and `1` is a plausible id, count and ceiling. The string siblings always checked the type; these two did not. 74 calls fixed in one pass, plus 39 direct casts routed through the helpers — among them the rate limit's ceilings.
- **The form editor's save read `$_POST` untyped in five methods** (#1084): `FormEditorSaveHandler` was the repository's biggest level-9 offender (120 errors). A non-scalar value reached the sanitiser directly — the device-limit message, for instance, was stored as the string `Array` plus a PHP warning — and the list of location ids went through `array_map( 'sanitize_key', … )`, which receives whatever the request nests there.
- **The calendar's save wrote into post meta any key the form sent** (#1075): the configuration and the email configuration were the array coming from `$_POST` with the known keys overwritten, so every unforeseen key survived intact all the way to the database. Both are now rebuilt from the declared list.
- **A numeric field posted as an array became `1`** (#1075): `absint( array( '45' ) )` is `1`, because `intval()` of a non-empty array is 1. A forged `slot_duration[]=45` stored a slot duration of **one minute**. A non-numeric value now falls back to the field's declared default. A working-hours row that was not an array became stored garbage — indexing a string by `['day']` reads character 0 with a warning — and is now discarded.
- **`UserManager::get_profile()` returned different shapes depending on the branch** (#1077): coming from the table it was the raw row — `user_id` as `'42'`, plus an `id` key nobody reads — and coming from the fallback it was an array assembled in PHP. Which branch runs depends on the **install's state**, not on the call, so the same code received different types on different sites.
- **On a fresh install, the General tab showed three QR Code defaults the plugin did not use** (#1076): each correction-level `<option>` passed **its own value** as the `get_option` fallback, so with the key absent all four were marked selected and the browser honoured the last — the screen said "H — Alta (30%)" while the generator used the declared `'M'`, and saving without touching the field stored `H`. Size and margin had the same divergence.
- **The defaults guard (#993) could not see the tabs' views** (#1076): it scans the whole of `includes/`, but recognised only `SettingsReader::get*()` — and the views read through the wrapper `$tab->get_option( 'key', 'default' )`. It now recognises all three forms, and resolves a `Class::CONSTANT` at the read site when the name is unambiguous. That is how the defect above came to light.
- **Two captcha blocks on the same page could receive the same token** (#1063): on a page mixing `[ffc_form]` with `[ffc_self_scheduling]` or `[ffc_csv_download]`, the fragment refresh wrote the same challenge into both — and the token has been single-use since #1053, so whoever submitted first burned the other's. The cause was conceptual: the server issued one challenge per **form**, but what consumes a challenge is the **security block**. The client now reports how many blocks there are.
- **The fragment endpoint accepted lists of unbounded length** (#1063): it is public and nonce-less by construction, so the quantity requested comes from the attacker. `form_ids` triggered one `get_post_meta()` read per id, with no ceiling. Both lists are now capped at 20.
- **The public CSV download refused the captcha it had just accepted** (#1061): the flow has two requests — the details screen and the download — and both validated the same token; with #1053's single use the first burned it. Checking and spending became distinct operations (`peek` in the captcha contract): the details screen checks, the download consumes. A captured pair is still worth one download only.
- **The captcha tripped over two forms on the same page** (#1056): the scheduling refresh rewrote the question in *every* form but, matching by id, swapped the token only in the first — the second then displayed a question its token did not answer. It is now scoped to the form and matches by `name`. The captcha's ids became unique per render, which also fixes the `<label for>` association for screen readers.
- **An appointment was created but reported as a failure** (#1058): `$appointment['id']` arrives from `$wpdb` as a string and `AppointmentReceiptHandler::get_receipt_url()` declares `int` under `strict_types` — the `TypeError` went fatal in the confirmation email, *after* the booking had been committed, and the visitor got an HTTP 500 for an appointment that existed. A committed booking is never reported as a failure again.
- **Every activity-log row for a created appointment was lost** (#1058): `on_appointment_created()` passed the appointment's id in the 4th argument of `ActivityLog::log()`, which is `$user_id` — with `fk_ffc_activity_log_user` in place MySQL rejected the insert.
- **The captcha widget appeared in English, and two controls did nothing** (#1053): the widget resolves the language when it initialises, before the plugin registers its translations, and never looked again — it is now reapplied through the element's own `configure()`. The layout and theme selectors were removed: `bar` and `floating` are positioned off-screen by construction, and the bundle has not a single rule that consumes `theme`. The theme now comes from the `--altcha-*` variables mapped onto the plugin's tokens.
- **The captcha widget did not load on the public CSV download nor on scheduling** (#1053): the `wp_enqueue_script` sat inside a per-page-type branch that covered only the certificate form and the verification, so on the other two the container rendered empty — with no console error, because a custom element that never boots does not complain. Whoever renders the widget now enqueues it, at render time.
- **The ALTCHA widget came out with square corners and the theme's font, not the form's** (#1053): the rounded corners were asked for with `var(--ffc-radius)`, a token that exists only in sheets which do not load on those pages — an undefined variable invalidates the whole declaration in silence. The typography was inherited from the theme, which on a 20px-body theme showed the captcha in a larger font than the question beside it. `CssTokenReferenceTest` now fails when a `var(--ffc-*)` with no fallback does not resolve.
- **In "ALTCHA + math" mode, a visitor without JavaScript saw a notice saying the form would not work** (#1053): the notice belongs to the ALTCHA-only mode, where it is true, and was rendered in the composite one too — in red, right above the math field the visitor was supposed to answer. The widget's empty panel went with it, which without JavaScript is a blue box with no content.
- **The ALTCHA widget's box was too narrow for its own content** (#1053): the widget is born at 320px and its footer pushes the logo to the right, so in a wider box the logo stuck to the text. It now takes the panel's width.
- **An invalid `batch_size` made the migration declare itself complete having processed nothing** (#1060): the configuration array reaches the strategies **after** the `ffcertificate_migrations_registry` filter, so a third party can put anything in `batch_size` — and `(int) 'x'` is `0`, which becomes `LIMIT 0`: no row read, `has_more` false, migration counted as complete. A non-numeric value now falls back to the default, with a floor of 1.
- **A corrupted cursor skipped the migration's first row** (#1060): the cursor is an option, `get_option()` is `mixed` and `(int) array()` is `1` — the `id > 1` sweep never saw row 1. A non-numeric value now restarts at 0, which at worst re-reads rows the migration is idempotent over.
- **A numeric options list made the `select` field impossible to fill in** (#1060): `field_options` is JSON written by the administrator, so `{"choices":[1,2]}` returned integers, while the validator compares the posted value — always a string — with `in_array( …, true )`. No selection matched and every submission was refused as invalid.
- **A non-scalar filter in `ReregistrationRepository::count()` was bound as the string `Array`** (#1060): the same defect already fixed in `find_user_bookings()`, in the other module, where it produced a `WHERE` matching nothing, in silence. `audience_id` was worse: `(int) array()` is `1`, so an unusable filter went on to count audience 1.
- **A non-scalar value in `UserManager::update_profile()` was stored as the string `Array`** (#1060): the patch arrives untyped from the caller and was converted directly; the field is now ignored, which preserves the column instead of corrupting it. The same fix in the read of sensitive metadata, which handed `Array` to the decrypter.
- **The QR Code cache never turned on through its toggle** (#1060): the two writers of the `qr_cache_enabled` key disagree on the type — the form's save stores `int 1`, and autosave, which is what the switch actually calls, stores a boolean. The check was `1 === $value`, and `1 === true` is false, so turning the cache on through the interface left it off.
- **`ReprintDetector::detect()` returned `date` with a different type depending on the branch** (#1060): an integer (unix seconds) when there was a reprint, an empty string when there was not. Only one consumer reads the key, and only in the reprint branch, so the empty branch now returns `0` — a contract whose type depends on the branch cannot be verified.
- **Non-numeric ids in audience bookings became `0`** (#1060): `audience_ids` and `user_ids` arrive inside a `$data` from the caller, and each entry was converted directly with `(int)` — a stray string or `null` became `0` and wrote a join row pointing at an audience that does not exist. Non-numeric entries are now discarded.

## [6.22.0] (2026-09-04) — `aa5ba5b`

### Deprecated
- ⚠ **`check_verification()`'s `$token` argument** (#1048): `RateLimiter::check_verification()` and `RateLimitChecker::check_verification()` accept a token they have never read — verification throttling is keyed on the IP alone (10/hour, 30/day). No caller passes it. It will be **removed in 6.24.0**; call with `$ip` only. A per-token limit is not needed: the 12-character `[A-Z0-9]` auth code (~62 bits, globally unique) makes the distributed brute-force it would defend against unviable.

### Fixed
- **A reregistration submission list could return null from a method declared `: array`** (#1027): `ReregistrationSubmissionReader::get_by_reregistration()` passed `get_results()` straight through, so a failed query fataled instead of returning an empty list. Surfaced by narrowing the PHPStan suppression that hid it.
- **A failed first seed left the certificate-template pool permanently empty** (#865): `CertTemplateSeeder` recorded the seed version even when it created nothing, and that flag short-circuits every later run, so the layout picker fell back to the deprecated `html/` directory. The version is now recorded only once a default is in the pool.
- **Two activity-log bugs** (#1024): unknown actions fell back to `ucwords()`, rendering untranslated English for 49 of the 64 action keys in use — all 49 now have translated labels, with a guard against the fallback. And submission link/unlink passed the action name where the level belongs, so both recorded the generic `submission`.
- **The update screen said the plugin was untested on your WordPress** (#1022): `GitHubUpdater` hard-coded the compatibility fields WordPress reads, so raising `Tested up to` in `readme.txt` (#984) left the shipped value at 7.0, and `Requires at least` had drifted the same way. Both are now read at update-check time.

### Changed
- Internal (#1035) — audited the config-level exclusions, which are `phpcs:disable` with repo-wide reach and had never been reviewed. Two stylelint rules turned on (both measured 0 findings); `UnusedFunctionParameter` and `CommentedOutCode` stay off with the measurements recorded — 33 of 43 and 9 of 9 are structural false positives. Two PHPStan `excludePaths` that never existed removed.
- Internal (#1035) — dropped three dead parameters the unused-parameter measurement surfaced (`return_to_draft`/`bulk_return_to_draft` took a reviewer id while nulling `reviewed_by`; `collect_form_data` took a user id it never read), and replaced the two `@ob_end_clean()` loops with a level-guarded form that cannot spin on an un-removable buffer.
- Internal (#1035) — 80 admin-screen reads of `$_GET` now go through `Core\RequestInput`, which gains the int, key and presence readers it was missing. Each raw read had needed a `phpcs:ignore` — the reader was never the problem, its gaps were. Suppressions 557 → 484 lines; `NonceVerification` mentions 164 → 92. Deliberately GET-only: on a POST write path the sniff is doing real work and the annotation is the record that someone checked.
- Internal (#1035) — the file-level `DirectDatabaseQuery` disables are now scope-gated: a guard fails CI when one of the ~50 classes carrying one grows a query against a WordPress core table, which is the exact condition that justified the disable and was until now only true by inspection. The audit's durable findings are recorded in CLAUDE.md.
- Internal (#1035) — every PHPCS suppression now says why, and 361 are gone (964 → 603): 65 suppressed nothing, 72 named sniffs that never fire, and 375 `DirectDatabaseQuery` annotations became one file-level disable per class. Four classes had an inner `phpcs:enable` cancelling their own file-level `disable`. A guard fails CI on a bare, unexplained or self-cancelling annotation.
- Internal (#1028) — enabled `WordPress.DB.DirectDatabaseQuery` and `WordPress.Security.ValidatedSanitizedInput`, neither of which is in the `WordPress-Extra` standard the ruleset uses, so neither had ever run — which is also why 333 `phpcs:ignore` annotations were inert and were removed. All 179 sites they report were read individually; no unsanitised input was found.
- Internal (#1030) — the vacuous-test register closed: 94 tests that asserted nothing now assert something (tests only, no product change). Most drove a nonce or capability check and ended in `assertTrue( true )`, so deleting the guard left them green. Each was verified by deleting the check and confirming the test fails. Baseline 95 → 1.
- Internal (#1027) — PHPStan suppressions now say what they silence and why. All 31 used `@phpstan-ignore-next-line <id>`, which ignores *every* error on the line, and now use the filtering `@phpstan-ignore <id>` form; 3 were dropped by typing the export WHERE-builder's return `literal-string`. A guard fails CI on a broad or unexplained suppression.
- Internal (#1034) — recorded the caching decision for the twelve uncached repository reads the direct-query audit surfaced (comments only, no code change). Tracing the callers overturned the issue's own premise: `UserService::get_user_statistics()` is not on a render path at all. One read survives as worth caching; eleven say why they are not.

## [6.21.0] (2026-09-01) — `ae38302`

### Added
- **URL Shortener: the short URL is now readable over REST** (#1012): the opted-in post types gain a read-only `ffc_shortlink` field. `pre_get_shortlink` never reached REST — `WP_REST_Posts_Controller` does not call `wp_get_shortlink()` — so external consumers had no way to read it.

### Changed
- Internal (#1008) — **PHPStan stays honest under WordPress stubs 7.1**: the bump types `register_rest_route()`'s namespace as `non-falsy-string`, now declared as such across the 14 controllers that thread it. Four dead checks were dropped; ten sites where the stub over-promises keep their runtime guard behind a narrow `@phpstan-ignore` with the reason inline.
- Internal (#992, #1007) — **the post-deploy smoke now blocks**: it shipped non-blocking while it proved itself against the testes host, and 13 consecutive green runs closed that evidence gate. A failing smoke is now a failing deploy; a 120s timeout stays a warning, since the rsync has already landed.
- Internal (#1005) — **`tests/` is tab-indented, and now enforced**: 250 space-indented files against 170 tab-indented ones, with nothing checking either, so a reformat alone would have drifted back. Reindented, plus a two-sniff `phpcs-tests.xml.dist` over the whole directory. No test logic changed.

### Fixed
- **Posts published from the block editor got their short URL late** (#1013): the auto-create hook was registered only under `is_admin()`, but Gutenberg saves over REST, where that is false. The short URL appeared only once someone reopened the editor — so a freshly published post had none, exactly when a consumer reads `ffc_shortlink` (#1012). `save_post` now runs in both contexts.

## [6.20.1] (2026-08-30) — `f1e4134`

### Added
- Internal (#994) — **fresh-install CI gate**: a job installs a throwaway WordPress on MariaDB, activates into an empty database and compares the result against `uninstall.php` **both ways** — every declared object must exist, and every existing one must be declared — then deletes the plugin and asserts a zero footprint.
- Internal (#990) — **post-deploy smoke on the testes host**: after each `develop` rsync it boots the real WordPress over SSH and checks the plugin is active, that the host's live `FFC_VERSION` matches the deployed commit (the #628 stale-deploy case) and that every declared table exists. An alarm, not a gate.
- Internal (#988, #997) — **static guards**: `AjaxWiringTest` cross-checks every registered `wp_ajax_*` handler against its callers in both directions and flags `ffc_*` options nothing writes (#935, #936); `AssertionCoverageTest` fails CI when a *new* test verifies nothing, freezing the 95 existing cases in a ratchet that can only shrink.

### Changed
- **"Check again" now busts the GitHub-updater cache** (#820): the updater cached the latest-release lookup for 12h, so a fresh release could stay invisible — worse under a persistent object cache. Dashboard → Updates → "Check again" now drops the `ffc_github_update` transient first. Automatic checks still respect the 12h cache.
- **`Tested up to: 7.1`** (#984): verified WordPress 7.1 compatibility. `readme.txt` metadata only; `Requires at least` stays 6.4.

### Fixed
- **Self-scheduling had no calendars table on a fresh install** (#994): an SQL comment and a column `COMMENT` inside the `ffc_self_scheduling_calendars` CREATE each held a semicolon, and `dbDelta()` splits its input on that character — so the statement was truncated and the table never created. Sites upgrading from an earlier release were unaffected, which is why it went unseen. Same family as #358.
- **Email texts hub: the editor opened as raw HTML until you switched emails** (#989): `wp.editor.initialize()` ran at script-parse time, but the `editor` dependency only guarantees the API — `wp_enqueue_editor()` prints the TinyMCE runtime from a later hook. Initialization now waits for DOM ready, and the tab opens on a placeholder with every editor hidden.
- **Uninstall left data behind** (#991, #994): `ffc_foreign_keys_db_version`, the two recruitment CSV-import tables and 21 one-shot capability/migration markers were absent from `uninstall.php`, so deleting the plugin never removed them. Most are written through a constant or variable, so no static scan could have found them — the fresh-install gate did.
- Internal (#993) — **settings defaults that disagreed with themselves**: `obsolete_shortcode_days` (90 declared / 30 read), `qr_default_size` (200 / 256) and `public_csv_default_limit` (1 / 100, plus 0 twice) restated different defaults at their read sites. Three lived in the `SettingsReader` typed accessors and were caller-less, so nothing broke in practice. Aligned, with `SettingsDefaultsTest` guarding against drift.
- Internal (#997) — **dbDelta hygiene in the activators**: `dbDelta()` reads every line of a field list as a column definition, so the 46 blank lines and 35 SQL comments inside twelve `CREATE TABLE` statements were each issued as a malformed `ALTER` against an existing table. Measured on a real MariaDB: 41 database errors before, 0 after — including declaring `validation_code` UNIQUE, which two code paths already treated as the intent. Latent (every activator guards on `table_exists()`), but it would fire on the next column added the normal way.
- Internal (#997) — **tests that verified nothing now do**: the thirteen vacuous methods in schema/activation and security asserted only `assertTrue( true )`; each now pins its real contract — a migration's one-shot flag, the exact roles a grant touches, the autosave bundle a tab must enqueue. Three JS degraded paths where a probed global is absent gained coverage too, the class behind #989.

## [6.20.0] (2026-08-23) — `e01ed47`

### Added
- **Email texts hub — every plugin email editable in one place** (#964, #965): a new **Email texts** hub lets an admin edit the **global default subject + body** of every token-based plugin email — the certificate email, recruitment convocation, the three reregistration emails, audience booking/cancellation, and (tokenized from former echo-partials) the self-scheduling booking confirmation, the five appointment lifecycle emails (approval, cancellation, reminder, waitlist-promotion, waitlisted) and the access-granted + calendar-deletion notices. Each is a `wp_editor` with `{{token}}` help, pre-rendered button/block tokens (`{{receipt_button}}`, `{{cancel_button}}`, `{{waitlist_button}}`, `{{dashboard_button}}`, `{{cancellation_reason_block}}`, …) and a "Restore Default Text" button. Built on a global-override store (`ffc_email_bodies` + `EmailTemplates::effective_body()` cascade, dormant when empty) and gated by a dedicated **`ffc_manage_email_templates`** capability (one-shot migration seeds it onto current Settings holders). Forms and per-calendar/audience bodies can still override; a **per-form Global/Custom toggle** decides whether a form follows the hub. Unconfigured installs render identically.
- **Two documentation hub pages + a Documentation-tab refresh** (#976): adds **Email texts hub** and **Document Templates hub** pages, and brings the Documentation tab in line with the current plugin — Emails & Delivery (three tabs, all editable emails), the per-form Global/Custom toggle, pool-backed certificate/ficha/receipt templates, and Personal Calendars (custom calendars, waitlist/promotion, per-user block cap, `ffc_bypass_appointment_capacity`); the Developer page gains the `ClientIpResolver` filters, `ffcertificate_ficha_template_html` and the shared batched-CSV-export engine. Ten legacy "Section N / #141" docblocks migrated. Documentation-only.
- **Document Templates hub — pool-backed certificate, ficha & receipt templates** (#945, #951): a read-only "Current assignments" overview plus editable/duplicable pool templates for the reregistration **ficha** (new `ficha` kind, seeded default, selected on a new Reregistration settings tab, resolves via `ffcertificate_ficha_template_html`) and the appointment **receipt** (a `_ffc_template_kind` discriminator — absent = certificate, fully retrocompatible; Regular + Custom defaults, per-mode selection on a new Scheduling → Receipt tab; adds `{{appointment_end}}` / `{{appointment_time_range}}` tokens). No data migration; unconfigured installs render exactly as before.
- **"All plugin emails" directory** (#951): a read-only index listing every email the plugin can send, grouped by feature, each with a purpose, a personalisation state (Editable text / On-off only / System default) and an **Open →** deep-link. Discoverability only — no control or capability moves; each group gated by its view cap.
- **Self-scheduling: custom calendars, waitlist & per-user block cap** (#941): calendars can use a `custom` schedule type — explicit date/time blocks with per-block vacancies — instead of the regular weekday pattern (mode locks once a calendar has bookings). A per-calendar **waitlist** (`waitlist_enabled`, optional capacity) queues bookings when a slot/block is full and **auto-promotes** the oldest queued entry (transactional `FOR UPDATE`, promotion email) when a spot frees; a **`max_blocks_per_user`** cap limits distinct blocks per user (counts active + waitlisted; admin overbook via **`ffc_bypass_appointment_capacity`** skips it) with a read-only Occupancy Report meta box. All off/empty by default; existing calendars unchanged.
- **IP Diagnostics detects Cloudflare behind a host proxy** (#920): a new `cloudflare_via_proxy` verdict for the CF → host-LB → PHP topology — the `secure` strategy auto-trusts `CF-Connecting-IP` when the TCP peer is a private/reserved load balancer with a Cloudflare edge as the first public `X-Forwarded-For` hop. The tab is redesigned (Legacy×Secure comparison, a headers table with a Situation column incl. `CF-Ray`, and a warning against pasting Cloudflare ranges into the custom-proxy field).
- **Cloudflare page-cache safety check** (#921): behind Cloudflare, the Cache tab runs a best-effort loopback probe of the edge `cf-cache-status` and warns if HTML is being cached (a "Cache Everything"/APO misconfiguration that can serve forms stale or leak the per-user dashboard), with guidance to exclude FFC URLs. Result cached up to 6h.

### Changed
- **One consistent standard across all default emails + normalized subjects** (#976): every plugin email now follows a single skeleton — an `<h2>` event title (no emoji, semantic colour: green = confirmed/approved, red = cancelled, amber = reminder, purple = waitlist, blue = informational), a greeting, one context line and a details box — and every subject the shared `Event: {{reference}}` shape (colon, no `[{{site_name}}]` prefix, no trailing dashes). The audience booking/cancellation emails gained their previously-missing subjects; admin notifications keep the visual pattern; audience emails drop their body-level sign-off for the shared Email Model footer; CTAs harmonised to brand blue. ⚠️ Because the shipped defaults changed, a per-form/schedule/calendar body still holding the *old* default now reads as **Custom** (keeps its text) until "Restore Default Text"; blank (Global-tracking) entities render the new standard automatically.
- **Email settings restructured into three tabs** (#976): the overloaded **SMTP** tab is split into **SMTP** (transport + the global "Disable all emails" switch), **Email Model** (the shared chrome editor + preview + test-send) and **Email texts** (the per-email hub + directory), ordered SMTP → Email Model → Email texts. The Email texts hub edits **one email at a time** via a feature-grouped selector with TinyMCE initialized on demand instead of stacking ~15 editors; a single "Save email texts" persists all, degrading gracefully to plain textareas. Save flows unchanged — a relocation; every directory deep-link points at the new tab.
- **Recruitment convocation email absorbed into the hub** (#964): its subject + body are edited in the Email texts hub with every other email instead of the bespoke Recruitment → Settings editor (retired, replaced by a link); the dispatcher resolves through `EmailTemplates::effective_body('recruitment-convocation', …)`, and `email_subject`/`email_body_html` are removed from `ffc_recruitment_settings`. A one-shot migration (`ffc_recruitment_email_hub_v1`) moves any customised text into the hub. ⚠️ The convocation body now passes through `wp_kses_post` (previously arbitrary admin HTML) — an accepted, admin-cap-gated tightening.
- **Settings side-nav grouped into domain sections + soft-blue hover** (#951): the 17-item Settings nav (`page=ffc-settings`) — previously flat alphabetical — now renders under seven subheadings (General · Content · Communication · Security & Access · Tools · Go to · System) via a new `SettingsTab::get_group()`, staying alphabetical within each and a single ARIA `tablist` (axe-clean). The Document Templates hub is reached from a Settings tab (the `ffc_cert_template` CPT relabelled kind-neutral, its standalone menu item dropped), and the Scheduling → Receipt tab is now a pure selector that links into that hub. Nav item hover/focus uses the theme-aware `--ffc-primary-light` tint. No tab content changes.
- **IP reads unified on one global strategy toggle** (#927, #931): rate-limiting, geofencing, the public listing throttle and logging now resolve the client IP through the shared `ClientIpResolver` following the **single** `legacy`/`secure` strategy on the IP Diagnostics tab, with `secure` (unspoofable behind Cloudflare / a configured proxy) recommended; the rate-limit log's `ip_address` records the IP actually enforced, and public CSV-download audit logs use the same resolver (unresolvable → `0.0.0.0`). ⚠️ **Breaking:** the client-spoofable `ffc_trust_forwarded_headers` filter and the interim `ffc_rate_limit_ip_source` filter/control are **removed** — set the strategy to `secure` instead.
- Internal (#945, #949, #950) — **code-reuse sweeps**: consolidated wall-clock date/time validation onto `DateFormatter::is_valid_time()` / `is_valid_date()` (replacing ~6 hand-rolled regex copies); extracted the shared CodeMirror initializer into `window.FFCCodeEditor` (`ffc-code-editor-core.js`) used by the form-editor, cert-template and receipt editors (restoring the placeholder overlay / dark theme / disabled notice the receipt tab had lost); folded the Email Model logo picker into `ffc-branding-media.js`; migrated three `$.post` callers to `FFC.request`; and added `RequestInput::is_truthy()`, `SettingsTab::should_enqueue_on()` and `Core\Utils::read_file_contents()`. Behaviour unchanged; tests added.
- Internal (#935) — **comment-only cleanups**: dropped the namespace-migration `(Phase N)` codename from ~50 class docblocks + the bootstrap (issue-anchored phase refs untouched), pointed `DocumentFormatter::validate_phone()` at the `PHONE_REGEX` constant, and removed 18 files' worth of auto-generated duplicate docblock summary lines (492 lines). No runtime change.

### Removed
- **Dead-code sweep — orphaned AJAX handlers, helpers & constants** (#935): removes ten admin-ajax actions with zero JS/template caller (each superseded by a live path) — the two audience placeholders `ffc_audience_create_booking` / `ffc_audience_get_schedule_slots`, the five URL-shortener row actions `ffc_{delete,toggle,trash,restore,empty_trash}_short_url(s)`, `ffc_audience_check_conflicts`, `ffc_generate_tickets` and `ffc_preview_date_format` — plus two unused helpers (`CsvExportTrait::extract_dynamic_values`, `SettingsTab::get_tab_url`), several never-read constants (`FFC_MIN_WP_VERSION`, `FFC_MIN_PHP_VERSION`, `FFC_JQUERY_UI_VERSION`, `RateLimitChecker::WEAK_SIGNALS`, two `ActivityLog::ACTION_*`) and a vestigial no-op (`SelfSchedulingEditor::display_save_errors()`). No user-facing behaviour change.

### Fixed
- **Settings-nav icons render uniformly monochrome** (#951): the tab-nav glyphs are emoji (`.ffc-icon-*`), and some codepoints (🆔, 👤) render in colour in the admin's emoji font while most render flat — an inconsistency impossible to fix per-glyph without whack-a-mole. A single `filter: grayscale(1)` on `.ffc-settings-tabs__icon` desaturates every nav icon uniformly, so any glyph (present or future) reads monochrome regardless of platform. Supersedes the per-glyph icon swaps below. CSS-only.
- **Frontend accessibility: labelled honeypot + named environment filter** (#951): the anti-spam honeypot input (`ffc_honeypot_trap`) on the certificate, self-scheduling and reregistration forms is now wrapped by its existing `<label>` (it was rendered off-screen — not `display:none` — so it stayed in the accessibility tree, meaning a screen-reader user could fill the "invisible" trap and be rejected as a bot); and the audience calendar's environment filter `<select>` gains an `aria-label`. Behaviour-preserving — the spam check keys on the field `name`, unchanged — and no new violations introduced (implicit label association, so no duplicate-id). Found via a one-off axe/WCAG 2.2 AA pass.
- **Reregistration settings-tab icon back to monochrome** (#951): the new **Recadastramento** tab used the `ffc-icon-id` badge (🆔), which the admin emoji font renders as a solid colour and broke the flat/mono icon row in the Settings nav; switched to the `ffc-icon-clipboard` glyph, which that font renders monochrome (as the Activity-Log tab already does). CSS-class-only change; the `ffc-icon-id` badge stays where an ID connotation fits (CPF rate-limit heading, CPF-split migration card).
- **pt_BR translations for recent features** (#951): the appointment-receipt / Document Templates hub UI, the self-scheduling waitlist and custom-calendar/occupancy strings (#941), and the new Settings "Document Templates" tab were showing in English — the pt_BR catalog is brought up to date (73 strings) and the runtime `.mo` / `.l10n.php` regenerated so they load. Translation assets only; no code change.
- **Submission auto-delete now works — and is explicitly opt-in** (#936): the daily cleanup read a standalone `ffc_cleanup_days` option that was never written (the value lives in `ffc_settings['cleanup_days']`), so age-based deletion of old submissions never ran regardless of the configured retention — a silent data-retention gap. It now reads the real setting, gated behind a new **"Auto-delete old submissions"** toggle (default **off**) plus a day window (≥ 1, default 365). ⚠️ Because the feature was dormant, it ships **off** so upgrades change no behaviour; enabling it will permanently delete published submissions older than the window on the next daily run.
- **In-admin Documentation tab accuracy** (#934): the Template Variables reference now describes `{{date}}` / `{{fill_date}}` as the submission date (alias of `{{submission_date}}`), not the PDF generation date, and drops `{{nome}}` (only satisfies save-time validation, never renders); Quiz `{{score}}` / `{{max_score}}` are documented as point sums, not answer/question counts; and a stale "Settings → Geofence" cross-reference now points to "Settings → Geolocation". pt_BR translations updated to match.

### Security
- **Self-scheduling confirmation escapes its scalar tokens** (#965): the booking-confirmation email now `esc_html`-escapes the user- and admin-supplied scalar values it substitutes (`{{user_name}}`, `{{user_email}}`, `{{calendar_title}}`, notes) and sanitises the per-calendar custom body with `wp_kses_post` (was `sanitize_textarea_field`), so an editable/tokenized body can never inject raw HTML from booking data.
- **Per-user dashboard kept off the Cloudflare edge cache** (#921): the `[user_dashboard_personal]` page now also emits `Cloudflare-CDN-Cache-Control: no-store` / `CDN-Cache-Control: no-store` — Cloudflare ignores `DONOTCACHEPAGE` / `X-LiteSpeed-Cache-Control`, so these edge-specific directives keep per-user HTML from being shared across visitors under CDN cache rules that honour them.
- **Recruitment public listing throttle is now fail-closed** (#927, #931): the per-IP rate limit on `[ffc_recruitment_queue]` resolves the client IP via the shared resolver (`RequestInput::get_user_ip()`) instead of a bespoke header walk, and no longer waves through a caller whose IP can't be identified — they share the `0.0.0.0` bucket. Closes the fail-open gap; header-rotation evasion is ineffective once the secure strategy is enabled.

## [6.19.0] (2026-08-09) — `d93c277`

### Fixed
- **Certificate preview resolves the `{{logo_gov}}` / `{{logo_org}}` branding tokens** (#903): the client-side previews (form-editor live preview + public CSV-download preview) now substitute the two branding keys from `CertificatePreviewSamples`, which lacked them (logos showed as raw `{{…}}`); mirrors the URLs the real generators inject (the server-side template preview already resolved them via `PdfHtmlRenderer`).
- Internal — **`@since` docblock audit** against the published-release history (#905): corrected **166 tags across 104 files** that cited never-published "phantom" patch numbers or vague `X.Y.x` placeholders to the release each symbol actually first shipped in, plus forward-dated tags mistakenly set to `6.20.0`/`6.19.0` (#865, #897). Docs only; no runtime change.

### Added
- **Client-IP resolution epic — trusted-proxy + Cloudflare auto-detection + diagnostics** (#899): consolidated all client-IP resolution into one Core `ClientIpResolver` (#900) that owns the historical `legacy` header-walk and a new `secure` trusted-proxy strategy (Cloudflare `CF-Connecting-IP` at a CF edge, right→left `X-Forwarded-For` for configured proxies, else the direct TCP peer); added a read-only *IP Diagnostics* settings tab with trusted-proxy config (`auto`/`cloudflare`/`custom`/`direct`), daily Cloudflare-CIDR refresh (`CloudflareCidrRefresh` cron + bundled fallback) and a header-injection self-test (#901), plus a notice recommending `secure`+Cloudflare (#902). **The effective default stays `legacy` — behaviour is unchanged; the flip to `secure` is deliberately deferred** (gated on shadow-mode telemetry, #902). Opt-in via the `ffc_ip_resolver_mode` / `ffc_trusted_proxies` / `ffc_ip_shadow_logging` filters. Non-breaking; no new cross-module edge.

### Changed
- Internal (#809) — **quality & coverage batch**: PHP line coverage raised to **90.38%** (piso **84 → 86**) — every remaining `api/` REST controller and `self-scheduling/` handler taken to ≥90%, the pcov-mis-attributed `AbstractDismissibleNotice`/`EmailTemplateDefaults` and `UserDashboardActivator` fixed (#910, #912, #913, #914); the `tests/Integration/` layer grew from 1 to 3 wiring smokes (`PluginActivationSmokeTest` + `ModuleWiringSmokeTest`, #911); and the JS tail (`ffc-email-model` + the two `ffc-csv-*` stragglers) went to 100% (#916). Tests only; no runtime change.
- **IP Diagnostics `shadow_logging` is an inline autosave toggle** (#901, #907): the shadow-divergence-logging control became a `.ffc-toggle` that saves on flip (`ffc_update_setting`), kept as a named in-form field so autosave and the form Save never clobber each other (invariant also documented in CLAUDE.md).

## [6.18.0] (2026-08-08) — `cffec5f`

### Fixed
- **Short URLs of posts/pages now follow the current permalink** (#888): a post's short URL stored the permalink captured at creation, so changing the slug (or the site's permalink structure) stranded it; the redirect, the admin *Destination* column and the CSV export now resolve a post-linked short URL from the post's **current** permalink (falling back to the stored target only if the post is gone). Manual links keep their stored destination. Self-healing, no migration.
- **Certificate layout toolbar polish** (#865): the *Import HTML* / *Background Image* / *Load* buttons now share one size, border and dashicon alignment, and the duplicated inline-dashicon + leftover emoji rules were removed.
- **Default certificate templates no longer copy shipped images into the Media Library** (#865): a default with a stale `html/` image ref made the *Rewrite html/ Image References* migration side-load it into every site's uploads; the migration now skips shipped defaults and a seeder bump refreshes their bodies to the `assets/` source.
- Internal (#849) — the GitHub-updater's SHA-256 mismatch error is now an English source string (was hard-coded pt_BR), so the translation flows through the catalog.
- **User-edit FFC role assignments survive the native "Update user" save** (#826): FFC roles are snapshotted and re-applied on `profile_update` (core's single-role `<select>` was collapsing the user back and dropping the FFC role); the role chips now also surface the real failure reason and disable the non-Administrator chips for admin targets (per the #739 policy).
- Internal (#822) — the datetime→unix safety-net migration now targets the real `ffc_self_scheduling_appointments` table (it pointed at a non-existent name and silently no-op'd); behaviour-preserving.

### Added
- **URL Shortener enhancements** (#886, #888, #889): a per-post-type **Expose** column publishes a page's short URL as the WordPress canonical shortlink — the `rel="shortlink"` `<head>` tag + HTTP `Link` header, via `pre_get_shortlink` (opt-in, a server-enforced subset of the shortened types); an **Edit** action changes a *manual* link's destination/title (post-linked ones follow the page); a **Generate missing short URLs** backfill creates links for already-published posts; and `GET /ffc/v1/short-urls/{code}/qr` returns a link's QR as base64 (cap-gated by `ffc_view_url_shortener` via an Application Password, for external automations like N8N). All reuse the existing `qr_cache`/generation and add no module coupling.
- **Certificate template pool** (#865): a database-backed `ffc_cert_template` pool (gated by `ffc_manage_forms` / `ffc_view_forms`) with a **Templates** management screen — list table, Show/Hide visibility toggle, HTML-body editor, **Duplicate** / **Export (.html)** / **Add New** / **Restore defaults**, and a sample-data **Preview** iframe — plus a non-destructive versioned seeder for the shipped defaults. The form editor's layout box wires to it: **Load** reads the pool server-side (replacing the unauthenticated `html/` fetch), **Save as model** stores the current HTML, and **Insert Image** / **Background Image** use `wp.media`. Shipped defaults are read-only (Duplicate to customize). The first step toward retiring the update-fragile `html/` folder.
- **Certificate branding tokens `{{logo_gov}}` / `{{logo_org}}`** (#865): two Settings → General → Branding logo URLs resolve into the certificate, ficha and appointment-receipt templates (Media Library picker, shipped fallback), de-hardcoding the instance-specific logos.
- **Retire the `html/` drop-folder — migration cards** (#865): two Settings → Migrations cards (gated by `ffc_manage_settings_dangerzone`) **import** any user certificate layouts left in `html/` into the template pool and **rewrite** `html/` image references (form layouts, backgrounds and pool bodies) into the Media Library — each distinct file side-loaded once, idempotent, missing files reported; plus an admin notice flagging any remaining `html/` reference.
- **Authenticated operator REST API** (#858, #840): `POST /ffc/v1/operator/certificates` issues a certificate (reusing the public form's `SubmissionHandler` + validation) and `GET …/{id}/pdf` returns its `pdf_data`, both via WordPress **Application Passwords**. Issuance is gated by `ffc_manage_certificates`; the PDF route by that cap **or** certificate ownership. Omits the public path's anti-anonymous defenses (a per-`user_id` rate-limit guards instead) and audits each issuance; additive — the existing handlers are untouched.
- **Encryption Key Health — one-click key/salt suggestions** (#851, #846): while FFC's encryption key still derives from the WordPress salts, the Advanced-tab card offers ready-to-paste `FFC_ENCRYPTION_KEY` / `FFC_HASH_SALT` snippets (64 chars, generated client-side over a PHP-literal-safe charset, never saved or sent) with **Generate another** / **Copy**, each carrying a self-documenting phpdoc comment and the mandatory rotation caveat. Hidden once decoupled.
- **Auto-update via GitHub Releases** (#820): WordPress's update check now sees the plugin's GitHub Releases — the update appears in Dashboard → Updates with the release changelog and the per-plugin auto-update toggle (opt-in). Points at the built `ffcertificate-X.Y.Z.zip`, caches 12h with an ETag request, verifies the package **SHA-256** before install, and adds an `Update URI:` header so wp.org can't hijack the slug.
- Internal (#809) — added a `tests/Integration/` layer: a `PluginBootstrapSmokeTest` boots the real plugin object graph over a stubbed WordPress boundary and asserts it composes and lands its hooks / crons / roles (the wiring the unit `LoaderTest` deliberately excludes). Tests only; the coverage floor is untouched.

### Changed
- Internal (#890) — **plugin-wide inline-style consolidation**: lifted static inline `style="…"` across the admin/settings, audience, recruitment and admin-template views into reusable classes — spacing/layout utilities (`ffc-mt-8/12/16`, `ffc-mb-2/4`, `ffc-ml-18`, `ffc-inline`, `ffc-list-disc`) plus component rules — and moved the colour swatches/dots onto the `--ffc-color` custom property. No behaviour change; email templates, the PDF-rendered certificate/ficha/receipt HTML, JS-toggled `display` states and documentation examples were left inline by necessity.
- ⚠ Internal (#883) — **CSS class-name consistency pass**: aligned shorthand admin classes to the spelled-out `ffc-` kebab convention (`ffc-em-*` → `ffc-email-model-*`, `ffc-sched-exc-*` → `ffc-schedule-exception-*`, the CSV info-table `ffc-j*` → `ffc-info-table-*`, and the margin utilities `ffc-mt15`/`ffc-mt20`/`ffc-mb5` → `ffc-mt-15`/`ffc-mt-20`/`ffc-mb-5`). No behaviour change; **breaking only for custom CSS that overrode the old class names.**
- **Certificate editor UX** (#865): the template edit screen gained the form editor's CodeMirror (read-only for defaults); shipped defaults are read-only except an AJAX-autosaving **Show in Load list** sidebar toggle (now a `.ffc-toggle` switch); and the default templates' full-page background moved from a baked `<img>` into the dedicated **Background Image** field (seed v3, so **Load** carries it into the form like any user template).
- **Shipped default assets moved out of `html/`** (#865): the default background/signature images now live in `assets/img/certificate-defaults/` and the ficha/receipt templates in `templates/documents/`, both versioned/update-safe; only the legacy `html/default_certificate_*` glob remains there as a deprecated fallback.
- Internal (#882) — the `develop → testes` deploy fails fast instead of hanging on a stalled SSH connection (`BatchMode=yes`, a `timeout 300` around `rsync`, a 25-min job backstop). Deploy/CI-only.
- Internal (#850, #849) — swept the ~50 hand-built `<div class="notice …">` admin notices onto WordPress's canonical `wp_admin_notice()` (WP 6.4+) and extracted a shared `AbstractDismissibleNotice` base for the persistent-dismiss notices; **bumped `Requires at least` 6.2 → 6.4** to use it. Behaviour-preserving.
- **Encryption Key Rotation now requires both decoupling constants** (#857): the rotation card and its gate stay hidden until **both** `FFC_ENCRYPTION_KEY` and `FFC_HASH_SALT` are defined (rotating with only the key set would rebuild the search hashes under the shared salt and force a second rotation), and the Advanced panel surfaces a pending-record count linking to it.
- Internal (#809, #822) — updated the vendored **thumbmarkjs** 1.9.1 → 1.10.1 (drop-in; the privacy `logging:false` beacon-disable is preserved), flag-guarded the `user_id → wp_users` foreign-key migration so it stops re-writing an all-skipped audit line on every activation, and pushed audience test coverage (tests only, no product change).
- Docs (#822, #800, #802, #820, #858, #711) — recorded the reregistration accepted-orphan user-deletion decision and rewrote CLAUDE.md §4 with the *"retain records, drop relationships"* principle; and closed documentation-tab gaps for Modules, the Activity Log + its CSV export, Privacy & LGPD, Updates, the operator REST API and the Short URLs export cap. Docs only.

### Deprecated
- **Legacy `html/` certificate-layout glob** (#865): the pre-pool fallback that discovered certificate layouts by globbing the plugin's `html/` folder is deprecated in favour of the database template pool. On the project's 3-release cycle it is scheduled for **removal at N+3**, after which the (emptied) `html/` folder is retired; sites relying on files dropped there should import them (Settings → Migrations) first.

### Removed
- Internal (#849) — removed the now-dead `ffc-device-threshold-notice.js` (the device-threshold notice uses the shared `ffc-dismissible-notice.js` after the `AbstractDismissibleNotice` extraction).
- Internal (#809) — retired the pre-4.6.15 orphan-cron cleanup calls (inert on any install newer than 4.6.15); behaviour-preserving.
- **⚠ (#809) — removed the pre-6.2.0 certificate-cap rename migration.** Dormant on every install that passed through 6.2.0–6.17.0. **Edge case:** a site upgrading directly from **pre-6.2.0** to 6.18.0 skips the rename — first update to any 6.2.0–6.17.0 release, then to 6.18.0.
- **⚠ Breaking (#739) — `CptEditorCompat` shim removed.** WordPress Editors (holders of `edit_others_posts`) no longer receive `ffc_manage_forms` / `ffc_manage_calendars` — completing the 6.16.0 CPT-capability decoupling (its two-release window has closed). **Action:** grant the **FFC Administrator** role — or the management capability — to the users who need it.

### Security
- **⚠ REST PII masking** (#838): the `GET /submissions[/{id}]` and public `POST /verify` endpoints no longer leak unmasked PII — `/verify` now masks cpf/rf/rg/email like the `/valid` page, and `/submissions` returns plaintext only to the `ffc_view_certificates_pii` cap (audited). Per-key masking now lives in the shared `DocumentFormatter::mask_field_value()`. **Breaking for external REST consumers** on a `view`/operator role: grant `ffc_view_certificates_pii` to keep plaintext.
- **Public certificate CSV minimized** (#836): dropped the magic-link **Token** column (a live access credential leaking in bulk) plus the User IP / Consent / Status columns; the admin export is unchanged and `/valid` now emits `noindex`. **Public CSV column layout changed.**
- **Encryption key rotation + FFC→FFC re-keying** (#857, #863, #846, #840): a new **Encryption Key Rotation** migration (Settings → Migrations, gated by `ffc_manage_settings_dangerzone`) re-encrypts submissions + appointments PII under a strong `FFC_ENCRYPTION_KEY` and rebuilds the CPF/RF/email search hashes under `FFC_HASH_SALT`. `Encryption::decrypt()` gains a fallback chain (`active → FFC-previous → WP-derived`) so rows stay readable during rotation — define `FFC_ENCRYPTION_KEY_PREVIOUS` for a zero-downtime FFC→FFC re-key (finish one rotation before starting the next). A key/salt **health surface** (`Encryption::key_health()` → `ok`/`weak`/`placeholder`/`empty`) flags predictable/placeholder secrets via a dismissible notice + an Advanced-tab panel (source, fingerprint, decoupled?), never disabling encryption.
- Rate-limit + input-hardening batch (#839): the `ffc_rate_limits` counter table now hashes non-IP identifiers (email/CPF) at rest; the recruitment classifications CSV import validates extension + a 10 MB cap before reading; the no-JS `[ffc_verification]` fallback applies the same throttle as the AJAX path (closing an un-throttled auth-code probing hole); and the nginx `deny` rule for the CSV-export temp dir is documented.
- **User-deletion integrity — attribution + promoted-candidate coverage** (#834): the `deleted_user` hook now also SET-NULLs the nullable actor-attribution columns (`edited_by`, `approved_by`/`cancelled_by`, `created_by`/`updated_by`, `reviewed_by`, …) and the promoted-candidate link, so a deleted admin/operator's id no longer dangles as stale attribution. The `NOT NULL` `created_by` columns stay accepted orphans on retained records; row-body encrypted PII is scrubbed only by the manual `PrivacyErasers` (LGPD erasure) path, by design.
- Fixed two JS attribute-context escaping gaps (#837, #564): the admin submission-edit user search and the dashboard profile edit now encode `"`/`'` in `data-*` / `value` / `style` attributes, closing a stored-XSS via a crafted `display_name` (a privilege-boundary crossing) and a self-XSS. Bundles rebuilt.
- Dev/CI dependency security bumps (#884): `squizlabs/php_codesniffer` 3.13.5 → 3.13.6 (CVE-2026-67434, OS command injection) plus the npm build-chain advisories (`brace-expansion`, `js-yaml`, `postcss`) via lockfile. Dev/build tooling only — not shipped in the plugin runtime.

## [6.17.0] (2026-07-26) — `6f47824`

### Changed
- Internal (#809) — CI coverage-job hardening: the shard partitioner now weights test files by test-method count and LPT-packs the four shards (isolated-method spread across shards ~31% → ~0%), and the coveralls.io upload step is `continue-on-error` so a 503 outage can't block merge — the line-coverage floor stays enforced by its own separate step.
- Internal (#809) — WPCS suppression audit (comment-only): removed a superfluous `EscapeOutput` ignore, swapped eight silent `FormCache` cache-purge catches for a gated `Debug::log_form` breadcrumb (off by default), and narrowed the file-wide `phpcs:disable` blocks across 46 files (28 no-op/redundant dropped, 18 trimmed to their load-bearing sniff).
- Internal (#809) — recruitment coverage push (tests only, no product change): reader tests for `RecruitmentCallReader` (~35% → ~96%) and `RecruitmentNoticeReader` (~25% → ~94%) (#812), plus the two heaviest renderers `RecruitmentPublicShortcodeRenderer` (~46% → ~99%) and `RecruitmentAdminPage` (~39% → ~99%).
- Internal (#807 follow-up) — raised unit coverage on three under-tested logic classes (tests only, no product change): recruitment `CsvParser` `parse()`/`normalise_id()` (~14% → ~96%), `ScheduleExceptionGuard`'s live-token contract (~13% → ~87%), and the per-email rate limiter's week/month/allowed branches (~40% → ~98%).
- Internal (#807) — extracted inline HTML from four markup-heavy renderers into `templates/` partials (outside the coverage scope), keeping all logic in the class: `RecruitmentPublicShortcodeRenderer`, `ReregistrationFormRenderer`, `AudienceAdminSettings`, `RecruitmentCandidateEditPage`. Behaviour-preserving; adds a first render test for the candidate-edit page.
- **⚠ Activity Log moved into Settings** (#804, #802): it is now a Settings tab (`page=ffc-settings&tab=activity_log`) gated by its own `ffc_view_activity_log` / `ffc_export_activity_log` caps, replacing the standalone Activity Log submenu. Audit-only operators (e.g. the read-only role) reach the Settings page via the computed page-entry meta-cap and see only this tab. **Breaking:** old `page=ffc-activity-log` bookmarks/deep links no longer resolve.
- Internal (#803) — Settings now uses a **per-tab capability model** (#802): each tab declares its own view/manage cap (defaulting to `ffc_view_settings` / `ffc_manage_settings`), the page renders only the tabs a user can view, and the read-only lock keys on the active tab's manage cap. The menu registers under a computed `ffc_view_settings_page` meta-cap so it appears iff ≥1 tab is visible.
- Internal (#800) — decoupled `Settings` from `AdminLoader` (the orchestrator wires it directly as an always-on cross-cutting service) and relocated the Recruitment schema/role lifecycle out of `RecruitmentLoader` into the orchestrator, so the new module toggles can skip a feature bootstrap without disabling the Settings screen or dropping recruitment tables/roles.
- **Settings moved to a dedicated top-level admin menu** (`admin.php?page=ffc-settings`) instead of hanging under the Certificate CPT menu, so the new Modules tab has a module-agnostic home. ⚠ The page URL changed from `edit.php?post_type=ffc_form&page=ffc-settings`; old bookmarks won't resolve. All in-app links and post-save redirects updated (#799).
- The dashboard **"Join Groups"** list now follows the per-node self-join model (#791 follow-up): a group shows a Join/Leave button whenever its own self-join is on — child, top-level, or a parent that also has joinable children — and a non-self-join parent still appears as a header when it has joinable descendants; unrelated groups are hidden.
- Admin label ordering is now **alphabetical by the translated label**, locale-aware (accents collate correctly) with stable anchors, via a new shared `Core\LabelSorter`: the Settings tabs, the Recruitment and Scheduling Settings sub-tabs, and the role→capability editor / per-user permission-panel groups (within each Self-service / Administration section) — the latter replacing module-map order (#787, #797).
- The audience/reregistration and certificate form-editor field-type `<select>`s now show **translated** labels instead of a raw `ucfirst()` of the slug (e.g. "Dependent_select" → "Dependent Select"), alphabetized with display-only types pinned last and sharing one canonical label map across the server-rendered select and the JS field builder (which now also offers Checkbox + Hidden Field). Slugs unchanged — display-only, no migration (#797, #798).
- The six admin CSV exports (submissions, activity-log, url-shortener, appointments, audience-bookings, reregistration) now show the same progress-overlay modal as the public download instead of an inline text line — one shared `FFCProgressOverlay` component drives both surfaces (#786).
- **⚠ Breaking (#772) — CSV export hook rename.** `ffcertificate_csv_export_{headers,filename,data,completed}` → `ffc_export_{headers,filename,data,completed}` (signatures unchanged). External integrations on the old names must migrate — there is no back-compat shim.
- Internal (#772) — consolidated every CSV export behind one source contract with two Core adapters (timeout-safe `BatchedCsvExport`, synchronous `CsvStreamer`), a `type`-based `SourceRegistry` + one AJAX dispatcher, and a shared `ffc-batched-export.js`: the seven data exports moved to the batched engine (now id-DESC keyset order) and the bounded outputs to the sync adapter. Built on the injectable `CsvStreamer` pipeline + paged-appointment-export precursor (#764 / #757).
- Internal (#739 follow-up) — two behaviour-preserving refactors: `AbstractRecruitmentListTable`, a shared fetch→search→sort→paginate→bulk-delete base for the Notices/Adjutancies/Reasons tables (~500 duplicated lines removed), and `RecruitmentPiiAccessPolicy` reduced to a thin adapter over the shared `Core\PiiAccessPolicy` 3-tier engine.
- The FFC role admin surfaces were unified (#739 follow-up): "Blocked Roles" is grouped (FFC end users / WordPress core / third-party) with the admin ladder behind an auto-opening caveat disclosure; role display labels normalized to `FFC <Module> - <Tier>` (pt_BR re-keyed, slugs/caps unchanged); and both surfaces list roles in one canonical order — cross-cutting first, then each module ladder alphabetically, weakest→strongest.

### Added
- **Per-module enable/disable toggles** in the Settings → Modules tab (#800): a switch per feature module (Certificates, Audiences/Scheduling, Self-Scheduling, Reregistration, URL Shortener, Recruitment) that gates the module's bootstrap in `Loader::init_plugin()` **and its scheduled crons** (reregistration expiry/reminders, appointment reminders no longer run for a disabled module). All default ON; disabling hides a module's screens, stops its runtime and crons, but never deletes data. Saves instantly via the settings-autosave endpoint, with a **confirmation prompt** before a toggle goes off (generic `data-ffc-confirm-off` support added to `ffc-admin-autosave.js`).
- New **Modules** tab in Settings (scaffold) — the future home for per-module enable/disable toggles (#799).
- Recruitment convocation email now has a **send mode** (#794): Settings → Recruitment offers Always / Never / Ask. "Ask" surfaces a default-on "Notify by email" checkbox in the call confirmation modal — single and bulk — so the operator decides per call whether to notify the candidate; the global "disable all emails" switch still wins. Gated in `RecruitmentEmailDispatcher::should_send()`.
- Outbound emails are now tagged with a per-function mail-queue source (#793): when the sibling **Total Mail Queue** is active, each email carries an `X-TMQ-Source-*` header so it is attributed per feature (`plugin:ffcertificate_certificate`, `_scheduling`, `_audience`, `_reregistration`, `_recruitment`, `_account`, `_admin`) instead of one blanket `plugin:ffcertificate` row. The queue strips the header; gated on the queue being present (filter `ffcertificate_emit_source_headers`).
- Internal (#764) — an injectable, unit-testable CSV output boundary: `CsvDownloadInterface` / `HttpCsvDownload` / `CsvStreamer` (pure orchestration over an `iterable` of rows).
- CSV export for the **Audience Bookings** page (#760): "Export CSV" gated by `ffc_export_audiences`, carrying the page's schedule/environment/status/date filters (no direct PII — FK ids only).
- CSV export for the **URL Shortener** page (#761): "Export CSV" carrying search/status/sort; adds a dedicated `ffc_export_url_shortener` cap, seeded onto current `ffc_manage_url_shortener` holders via a one-shot migration.

### Fixed
- **URL Shortener QR modal — action-button icons sat ~3px low** (#811): the Copy URL / PNG / SVG buttons are flex-centred, but the shared `.ffc-dashicon-valign` helper added a baseline margin on top; neutralised it for the modal action dashicons so the flex centring aligns them with their labels. CSS-only.
- **Cron lifecycle gaps on module toggle / deactivation** (#800 follow-up): the Certificates daily expired-ticket cleanup (`ffc_daily_expired_tickets_cleanup`) ran unconditionally from `AdminLoader` even with Certificates off — now wired only when the module is on; and `Deactivator::deactivate()` never cleared `ffcertificate_reregistration_expire_hook` (only `uninstall.php` did), leaving a stale daily event — now cleared on deactivate and in `uninstall_cleanup()` (which also unschedules the expired-tickets cron for parity).
- The **Activity Log** Settings tab showed a coloured emoji icon in the tab nav while the others are monochrome (#805, #804 follow-up): `ffc-icon-clipboard` was missing from the nav's dashicon override map and fell back to its emoji glyph. Mapped it to `dashicons-list-view` so it renders mono.
- **URL Shortener menu vanished even with its module toggle ON** (#801, #800 follow-up): `UrlShortenerService::is_enabled()` strict-compared the enable slot against the integer `1`, but the Modules-tab autosave persists it as a boolean `true`, so the module (and its admin menu) went offline. The stored value is now cast to int. Also removed the duplicate "Enable URL Shortener" toggle from the URL Shortener settings tab — the on/off switch lives solely on the Modules tab, and its form no longer zeroes the flag on save.
- **Self-join to a top-level audience failed** with "Group not found or does not allow self-join" (#791): the join/leave/membership-count paths required `parent_id IS NOT NULL`, rejecting the standalone top-level self-join groups that the dashboard's joinable-groups list already offers. Any active self-join audience is now joinable, whether it's a child or a top-level group.
- **Dashboard appointment times were TZ-shifted** (#790): the appointments REST controller rendered the wall-clock start/end by re-interpreting them as UTC instants (`format_time(strtotime(...))`), shifting them by the site offset (e.g. 13:00→10:00 in UTC-3) and making the "Export Calendar" end time wrong. Now uses `format_wallclock_time`; the export consumes a new raw `end_time_raw` field.
- **Audience Bookings** "Export CSV" did nothing — no request, no console error (#772 regression): the click handler sat inside `initCalendarPermissions()`, which early-returns on the bookings page, so it never bound. Moved to its own `initBookingsExport()`; regression test hardened.
- Public operator **CSV download** failed the page-nonce check at download (#772 regression): the shared driver `$.extend`-ed the routing `type` into a serialized-string `startData`, spreading it to char-index keys and dropping `_ffc_pcd_nonce`. It now appends `&type=` to a string payload.
- Appointments **Export CSV** button was a dead `<a href="#">` never wired to the exporter (#758); it is now a real nonce'd `POST` gated by `ffc_export_appointments`, carrying the on-screen filters.
- Self-scheduling booking returned **403** on submit (#755): the serialized payload's trailing fallback `nonce=` overrode the form's; submit now passes the live `ffcCalendar.nonce` and strips the baked `nonce`/`action`.
- FFC role labels showed untranslated in the admin (#739 follow-up): the relabel only ran on `wp_roles_init` (too early); it now also runs at `init:1`, and "Blocked Roles" renders names via `translate_user_role()`.

### Security
- Closed four #739 RBAC escapes — CSRF-protected surfaces on raw WP caps not FFC caps: certificates-calendar REST → `ffc_view_certificates`; `ffc_generate_codes`/`ffc_load_template` → `ffc_manage_forms`; `ffc_search_user` → `ffc_edit_certificates`; Notices bulk-delete → `ffc_delete_recruitment`.

### Removed
- **⚠ Breaking (#730) — legacy audit-log summary keys + `UserDataRestController` facade.** `PublicCsvDownload::get_audit_log_summary()` / `CsvDownloadAuditLog::get_summary()` no longer return the deprecated `success` / `fail` keys — use `access_success` / `failed_access` (`count` stays). The 12 `@deprecated` `get_user_*` delegate methods on `UserDataRestController` are removed — call the sub-controllers (`UserProfileRestController`, `UserCertificatesRestController`, …) directly. Both were deprecated in 6.15.0; the removal window (2nd feature release after) has closed. Zero internal callers; external integrators on the old names must migrate.

## [6.16.0] (2026-07-22) — `4d3c4e0`

### Added
- PII reveal capabilities `ffc_view_certificates_pii` and `ffc_view_appointments_pii` (#739) — carve the decrypted CPF / RF / email out of the plain `view` tier, mirroring recruitment. A shared 3-tier resolver (`Core\PiiAccessPolicy`) grants *unmasked* plaintext to the domain `_admin` role (and WP super-admins), an audited *reveal* tier to holders of the `_pii` cap and to the record's owner, and *masked* to everyone else. The caps ship on the `manager` + new `_admin` tiers; the certificates/appointments ladders gain their fourth (`_admin`) tier.
- Read-only capabilities `ffc_view_forms` and `ffc_view_calendars` (#739) — the *só vê* tier for the certificate-form and self-scheduling-calendar structures, completing the 3-state model on both CPT surfaces (the view tier deferred from the CPT-decoupling PR). A holder now sees the forms/calendars list read-only; a new `map_meta_cap` gate forces every per-post edit/delete on those CPTs to the matching `manage` cap, so viewing never implies editing.
- Hue×tier visual system on the capability editors (#739) — the role editor and the per-user capability panel now paint each capability row with a left accent bar: its colour marks the domain (certificates, forms, appointments, calendars, audiences, …) and its intensity rises with the capability's write-power (`view` faint → `manage` strong → `delete` solid), with the domain hue also carried on the group header. The colour reinforces the existing textual label; the mapping lives in `CapabilityCatalog::group_hue()` / `::cap_tier()`.

### Changed
- ⚠ **Breaking (RBAC redesign, #739):** WordPress administrators now receive FFC access through the dedicated **FFC Administrator** role (`ffc_administrator`) instead of the plugin's capabilities being granted onto the native `administrator` role. On upgrade every existing administrator is automatically given the role, so no access is lost; administrators created **afterwards** are granted it explicitly (on the user-edit screen, by any admin) — they are no longer auto-elevated. The per-user FFC role panel now appears for administrators so the aggregator role can be assigned to them.
- ⚠ **Breaking (RBAC redesign, #739):** the **form** (`ffc_form`) and **calendar** (`ffc_self_scheduling`) post types are decoupled from WordPress's native post capabilities. Form structure is now gated by the new `ffc_manage_forms` capability and calendar structure by the new `ffc_manage_calendars` capability (distinct from `ffc_manage_appointments`, which governs the bookings). Previously a plain WordPress **Editor** could administer every form and calendar via `edit_others_posts` without holding any FFC capability.
- ⚠ **Breaking (RBAC redesign, #739):** capability and role slugs were renamed for a consistent taxonomy. Capability: `ffc_scheduling_bypass` → `ffc_bypass_appointments`. Roles: `ffc_operator` → `ffc_readonly`, `ffc_self_scheduling_manager` → `ffc_appointments_manager`, the auto-assigned end-user role `ffc_user` → `ffc_end_user` (label **FFC End User**), plus the §3.2 taxonomy alignment: `ffc_certificate_manager` → `ffc_certificates_manager`, `ffc_audience_manager` → `ffc_audiences_manager`, and `ffc_recruitment_auditor` → `ffc_recruitment_viewer`. One-shot upgrade migrations rewrite every affected grant on both users (`user_meta`) and role definitions and reassign users onto the renamed roles, so no access is lost on upgrade. **External integrations referencing the old slugs must update** — the old names no longer resolve after the migration runs.
- **Graduated roles (RBAC redesign, #739):** every admin domain now ships a uniform `viewer → operator → manager` role ladder instead of a single per-domain manager, reusing the capabilities that already exist. `operator` adds the domain's primary action to the read-only viewer (certificates +edit, appointments +bypass, audiences +import, reregistration +export, recruitment +call); `manager` is the full domain cap set; the global **FFC Administrator** role tops every ladder. New roles: the viewer/operator tiers for certificates, appointments, audiences and reregistration, `ffc_recruitment_operator`, and `viewer`/`manager` pairs for the two CPT domains (**forms**, **calendars**).

### Deprecated
- WordPress Editors (holders of `edit_others_posts`) can still manage FFC forms and calendars during a two-release compatibility window, with an admin notice, but this stops in **6.18.0**. Grant the FFC Administrator role — or the form/calendar management capability — to the users who need it. (#739)

### Fixed
- Form/calendar CPT menus hidden for every capability holder (#739): the `ffc_form` and `ffc_self_scheduling` post types mapped the per-post **meta** caps `read_post` / `edit_post` / `delete_post` to the *same* strings used for their **primitive** caps (`ffc_view_forms` / `ffc_manage_forms` / `ffc_view_calendars` / `ffc_manage_calendars`). WordPress's `_post_type_meta_capabilities()` copies every `read_post` / `edit_post` / `delete_post` value into the global `$post_type_meta_caps`, registering those strings as meta-cap aliases — so a plain `current_user_can( 'ffc_view_forms' )` (the admin-menu check) was rerouted through `map_meta_cap()` to `read_post` and, with no post ID in a menu context, collapsed to `do_not_allow`. The result: the "Todos os Formulários" / "Adicionar Novo" / "Calendários Pessoais" menus were hidden even for the **FFC Administrator** role that explicitly holds the caps. The CPTs now map only their primitive caps; per-post edit/delete stays gated by `CptCapPolicy`, which forces the write meta-caps to the manage cap on the `map_meta_cap` filter.
- Capability-map hygiene (#739): a defensive, version-flagged migration (`strip_false_ffc_caps()`) removes any stale `ffc_* => false` denial from the FFC-managed roles, and `upgrade_role()` was hardened to strip `0` / `''` denials (not only a strict `false`), so a future multi-role user can't have an admin-granted cap masked by a legacy explicit denial on a peer role.
- Authorization consistency (#739): the migration-runner AJAX endpoint now requires the `ffc_manage_settings_dangerzone` sub-cap, matching its no-JS counterparts — a settings operator without the dangerzone cap could previously run destructive migrations through the AJAX runner.
- Audience delegation (#739): the audience booking, schedule-slot, environment and user-permission AJAX actions now accept `ffc_manage_audiences` (administrator **or** the granular cap) instead of requiring `manage_options`, so the **FFC Audience Manager** role can actually use the interactive screens it is meant to delegate.
- Ticket generation authorization (#739): the `ffc_generate_tickets` AJAX handler now requires the certificates domain cap (`ffc_manage_certificates`, administrator **or** the granular cap) instead of the generic `edit_posts` any WordPress author holds.
- Email sub-cap alignment (#739): the "send a test email" action now requires `ffc_manage_settings_smtp` (it exercises the SMTP transport), and the global "disable all emails" kill-switch now requires `ffc_manage_settings_dangerzone` on every write path (bulk settings save + the per-field autosave endpoint) — a settings operator without those sub-caps can no longer test SMTP or silence all plugin email.
- URL-shortener QR download (#739): the PNG / SVG QR download now accepts the url-shortener `manage` cap as well as `view` (administrator **or** either granular cap), so a manager role that doesn't separately carry the view cap can still download QR codes.
- Recruitment read tier (#739): the recruitment REST read endpoints (candidates list/get, notices, classifications and adjutancies lists) now accept `ffc_view_recruitment` as well as `ffc_manage_recruitment`, reactivating the read-only viewer tier that was defined but never wired. PII in those responses stays masked via `RecruitmentPiiAccessPolicy` (reveal remains per-field + audited), and every write route stays manage-gated.
- Recruitment candidate email on the edit page (#739): the candidate edit page decrypted the email into an editable field for every recruitment manager. It is now masked and disabled for operators below the unmasked PII tier, and `handle_save()` gates the email write on the loaded row's tier — so a manager without `ffc_view_recruitment_pii` can neither read the plaintext nor overwrite/clear the stored address (even by forging the field). Editing the email requires the unmasked tier (WP admin / domain admin).

## [6.15.0] (2026-07-21) — `b5a96fe`

### Deprecated
- Two public API surfaces with no internal callers are now deprecated and scheduled for removal **no earlier than the second feature release after this one** (external consumers are invisible to a code scan, so they get a deprecation window instead of an evidence-gated removal). (#730)
  - `PublicCsvDownload::get_audit_log_summary()` — the legacy `success` / `fail` return keys. Use `access_success` / `download_success` / `failed_access` instead. (`count` is unaffected and stays.)
  - `UserDataRestController` — the public `get_user_*` facade delegate methods (`get_user_certificates`, `get_user_profile`, `get_user_appointments`, `get_user_summary`, …). Call the corresponding sub-controller (`UserCertificatesRestController`, `UserProfileRestController`, …) directly instead.

### Removed
- ⚠ **Breaking (legacy pre-split data):** removed the `cpf_rf_encrypted` legacy-column fallback — the last runtime reads of the pre-split combined CPF/RF column, in the appointment-PDF generator and the two submission REST endpoints. CPF/RF now come exclusively from the split `cpf_encrypted` / `rf_encrypted` columns, introduced in **v5.0.0** (`9742fd9`, "Multi-identifier architecture"). This is safe for any install that has completed the **Settings → Migrations → `split_cpf_rf`** migration (also shipped in v5.0.0; card reads **0 pending**); an install still holding pre-split rows would lose CPF/RF in appointment PDFs, so run that migration to completion first. The admin submission REST responses' combined `cpf_rf` field has been removed (it was already `null` for migrated rows) — read the split `cpf` / `rf` fields instead. The `split_cpf_rf` migration itself is unchanged. (#727, #728)

### Changed
- Settings (#711, #720) — the **"Module settings"** jump list moved from a card at the top of Settings → General into the settings sidebar itself: the Scheduling and Recruitment links now render as nav items directly above the **Advanced** tab, with an external-link marker since they navigate to the module's own page. Same per-module view-capability gating as before.

## [6.14.0] (2026-07-19) — `dba61ae`

### Added
- Settings → General (#711) — a **"Module settings"** index card that links straight to the settings surfaces some modules keep next to themselves (Scheduling settings, Recruitment settings). Each link is shown only to users who can open that module's settings, so it improves discoverability of configuration split across the plugin without moving anything.
- Documentation tab (#674, #697) — greatly expanded the reference, with new or rewritten pages across every functional area: the form builder and per-form settings (dynamic fields, open/close schedule, geofence, per-form email, security/anti-fraud, public operator access), Scheduling (personal calendars and audience calendars), Reregistration campaigns, Recruitment, Short URLs, the Developer Hooks/REST/Forms API, a live-generated **Capabilities & Roles** reference, a **User Dashboard & Access** page, and **Emails & Delivery** — plus an in-page search box and feature-grounded Troubleshooting rows. Every area was verified against the code.
- Settings → SMTP → Email Model (#674) — a **"Send a test email"** button that sends one test message through the shared email pipeline (Email Model chrome + transport) so operators can confirm delivery is working. The message always goes to the **current user's own account email** (never a request-supplied address); it requires the settings-manage capability plus a nonce, and reports the outcome (sent / globally disabled / no address on file / send failed) as a notice on the SMTP tab.
- Settings → SMTP (#674) — a recommendation box for the sibling **`total-mail-queue`** plugin, shown only when it is not active (via `Integrations\MailQueue::is_active()`), pointing operators to queued delivery with automatic retries + true multipart for reliable and bulk sending. Hides itself once the plugin is installed.
- Central detection of the sibling **`total-mail-queue`** plugin (`Integrations\MailQueue::is_active()`), matching on the plugin folder across per-site and network activations and overridable via the new `ffcertificate_mail_queue_active` filter. Every plugin email already funnels through `wp_mail()`, so a mail-queue plugin captures them all for queueing/retry for free — the plugin deliberately ships no queue of its own; this single detector backs that decision and the (upcoming) "install total-mail-queue" recommendation shown only when it is absent. (#673)
- New **`ffcertificate_email`** last-mile filter at the transport chokepoint (`Core\EmailService::send()`): a single place for integrations to inspect or rewrite the fully-composed message — `to`, `subject`, `body`, `headers`, `attachments` — just before `wp_mail()`, instead of hooking `wp_mail` globally and pattern-matching the plugin's mail. Fires **after** the global disable toggle (a disabled send never reaches it) and **before** the `text/plain` alternative is derived, so a rewritten body also drives the derived plain text. (#673)
- Every HTML email the plugin sends now goes out as **`multipart/alternative`** (the HTML plus an auto-derived `text/plain` part), so mail renders in text-only clients and reads better to spam filters. The plain-text part is built once, centrally, at the transport chokepoint (`Core\EmailService::send()`) from the already-composed HTML — it works on WordPress core with no SMTP/queue plugin required — and can be customized or turned off per message via the new `ffcertificate_email_plain_text` filter (return an empty string to send HTML-only). (#673)
- A configurable **"Email Model"** (Settings → SMTP) that styles the single chrome shared by every plugin email — header band (logo or site name, colors, alignment, padding), body card (colors, font, size, padding, width), footer (colors + tokenized text with `{{site_title}}` / `{{recipient}}` / `{{year}}` …) and outer wrapper (color, radius, padding) — with a live preview and a restore-to-defaults button. Backed by a dedicated `ffc_email_template` option (`Core\EmailTemplateOptions`) and a rewritten table-based, inline-styled `templates/emails/layout.php` (Gmail/Outlook-safe); the self-scheduling appointment emails now render their inner content through this shared chrome. Part of the plugin-wide email consolidation. (#662)
- A soft **"emails are turned off" heads-up** now appears at the top of every email-editing surface (form Email tab, recruitment settings, self-scheduling and audience calendar editors, the reregistration campaign editor, the SMTP tab) whenever the global disable toggle is on, so a disabled switch no longer silently swallows mail while an operator edits a template. First step of the plugin-wide email consolidation. (#662)
- Audience calendars can now **notify an admin on new bookings and cancellations** — two per-schedule opt-in toggles (default **off**) plus a recipient field (comma-separated; empty falls back to the site admin email), in the calendar editor's Notifications section. Mirrors the self-scheduling calendars, which already had admin notifications. The admin notification is independent of the existing per-schedule user toggles, so an admin can be alerted even when end users are not (or when the audience is empty). Adds `notify_admin_on_booking` / `notify_admin_on_cancellation` / `admin_notification_emails` columns to the schedules table via an idempotent migration. (#661)
- Form editor → Email tab: a **"Restore Default Text"** button that repopulates the message editor with the default template (after a confirm), for when an operator has edited the body and wants the default back. The helper text also notes that simply clearing the body falls back to the default template when the email is sent. (#660)
- Per-form "Notify Admin on Submission" opt-in (default off) with an optional recipient list, in the form's Email tab. Because re-wiring the dispatch above also revives the admin notification — which previously defaulted to the site admin email with no toggle — it is now gated behind this explicit opt-in so no admin is emailed on every submission without consent. (#649)
- Device-fingerprint limit, "global on / form off" gap: the form editor now shows a neutral nudge when the subsystem is enabled plugin-wide but off for the form (explaining the shared-device trade-off so operators enable it deliberately), and forms in that state render a generic "submissions are logged and may be audited for fraud prevention" line inside the existing LGPD consent block. The line is intentionally generic — no device signal is collected when the form limit is off, so it makes no device-duplicate claim; the honest device disclosure still appears only when the per-form limit is on. (#647)

### Changed
- Documentation tab (#674, #697) — reorganized the tab by functional area: the Quick Navigation is now a tree mirroring the plugin's admin menus (Overview · Certificates & Forms · Scheduling · Reregistration · Recruitment · Short URLs · Developer · Troubleshooting), driven by a single recursive registry so the nav and render order stay in sync, and using WordPress dashicons instead of emoji. The Overview page was rewritten as a concise plugin-wide introduction. Also removed six dead legacy emoji-icon CSS rules left after the dashicons migration (a wider CSS audit kept the rest, which are generated in PHP or come from libraries such as CodeMirror and WP list tables).
- Internal (#673) — the certificate email-body editor's **"Restore Default Text"** button now uses the same shared `assets/js/ffc-email-restore-default.js` as the recruitment and self-scheduling editors (the generic `data-editor` / `data-default-key` button), and the bespoke `ffc-form-editor-email-metabox.js` was retired — one restore-button implementation plugin-wide. Behavior-preserving.
- Internal (#662) — the three editable default email bodies (certificate, recruitment convocation, self-scheduling confirmation) moved from inline PHP methods to `templates/emails/{certificate-user,recruitment-convocation,selfscheduling-confirmation}.php` (loaded via `Core\EmailTemplates`), so **every default email body is now a file**. Also renamed the internal term "miolo" to "email body" throughout the code/comments, and documented the one-pipeline email architecture in `CLAUDE.md`. Behavior-preserving.
- Internal (#662) — the emails that still bypassed the shared chrome now go through it too, fully satisfying "every email → one configurable chrome": the **submission admin notification**, the **self-scheduling admin notification**, the **capability-grant / access-granted** email, and the **"calendar deleted → appointment cancelled"** notification (the last two converted from plain text to branded HTML). Their inner content moved to `templates/emails/{submission-admin-notification,access-granted,calendar-deleted-cancellation}.php` (the self-scheduling admin body was already a file).
- Certificate submitter email (#662) — the editable body is now the **"email body"** wrapped by the shared, admin-configurable "Email Model" chrome (header/footer), instead of being the whole email (reverses the #649 "no locked chrome" send path). The shipped default body was already content-only, so it renders unchanged inside the chrome; per-form custom bodies now gain the shared header/footer too. Completes the plugin-wide email consolidation — every plugin email now shares one configurable chrome.
- Recruitment convocation email (#662) — the editable body is now the **"email body"** wrapped by the shared, configurable "Email Model" chrome (header/footer) like every other plugin email, instead of being the whole email. Its editor in **Recruitment → Settings** moved from a plain `<textarea>` to the **TinyMCE** visual editor and gained a **"Restore Default Text"** button. The text/plain alternative is still derived from the body. Existing custom bodies keep working — they simply render inside the shared chrome now.
- Internal (#662) — the default email "email body" (inner body) templates now load through one shared `Core\EmailTemplates` loader. The audience booking/cancellation default bodies moved out of `AudienceNotificationHandler` into `templates/emails/audience-{booking,cancellation}.php`, and the reregistration handler's bespoke `load_template()` was folded into the shared loader. Behavior-preserving.
- Internal (#662) — retired `Scheduling\SchedulingMailer::wrap_html` (the class-based `<style>` chrome). Audience and reregistration emails now render through the single, admin-configurable chrome ("Email Model" → `ffc_email_document`) like every other plugin email, and their info-box markup was inlined (Gmail/Outlook-safe). Behavior-preserving apart from the unified look.
- Internal (#653) — retired the catch-all `Scheduling\EmailTemplateService`, splitting it into two focused classes: `Scheduling\IcsGenerator` (RFC 5545 `.ics` invite/cancellation building) and `Scheduling\SchedulingMailer` (the shared HTML chrome + `ffcertificate_scheduling_email` filter + transport for audience/reregistration emails). The dead `render_template` (single-brace engine, superseded by `Core\TokenResolver`) and the `format_date`/`format_time` passthroughs were dropped — reregistration now calls `Core\DateFormatter::format_date` directly. Behavior-preserving; the `ffcertificate_scheduling_email` filter contract is unchanged. Completes the email-architecture consolidation.
- Internal (#653) — the self-scheduling appointment emails (booking confirmation, admin notification, approval, cancellation, reminder) no longer build their HTML by string concatenation inside `AppointmentEmailHandler`; each body moved to a `templates/emails/appointment-*.php` partial, wrapped by a shared chrome shell `templates/emails/layout.php` (the single source of the email header band + site-name footer, replacing the `ffc_email_header()` / `ffc_email_footer()` trait helpers). The handler is now a thin data-prep orchestrator that renders partials via two new `EmailHelperTrait` helpers (`ffc_render_email_partial()` / `ffc_email_document()`). Behavior-preserving.
- Internal (#653) — reregistration email templates (`templates/emails/reregistration-*.php`) migrated from single-brace `{token}` to the plugin-wide `{{token}}` engine (via `Core\TokenResolver`), replacing the last use of `EmailTemplateService::render_template`. These templates are shipped files (not admin-editable), so no data migration is needed.
- **BREAKING (#653)** — audience booking/cancellation email templates now use the plugin-wide double-brace token syntax (`{{user_name}}`) instead of single-brace (`{user_name}`), so audience emails share the one placeholder engine. Templates customized and stored per schedule are converted automatically by a one-shot, version-flagged migration (`ffc_audience_email_tokens_migrated_v1`) — only the known audience tokens are rewritten, so literal braces in your markup (CSS, etc.) are left untouched. **External integrations that assemble these templates with the old `{token}` syntax must update to `{{token}}`.**
- Internal (#653) — all outbound email now funnels through a single transport chokepoint `Core\EmailService::send()`, replacing the three prior paths (`EmailHelperTrait::ffc_send_mail`, `EmailTemplateService::send`, and raw `wp_mail` calls in the capability-manager, recruitment dispatcher and self-scheduling CPT). Behavior-preserving — each caller keeps its own headers/content-type (text/html, default, or recruitment's multipart).
- Internal (#653) — introduced the shared `Core\TokenResolver` (single-pass `{{token}}` substitution) and `Generators\TemplateRenderer` (composes the token + validation-URL-DSL pipeline for emails), and routed the certificate email and recruitment dispatcher through them, replacing their bespoke `str_replace` / `strtr` substitution. First step of the email-architecture consolidation; no behavior change (single-pass substitution is marginally safer than the prior sequential `str_replace`).
- The submitter email is now fully driven by its editable/translatable template — subject and body substitute `{{name}}`, `{{form_title}}`, `{{auth_code}}` and `{{date}}`, and the `{{validation_url …}}` link DSL now runs in emails as well (it had been removed), so the magic download link and `/valid` verification link can be placed anywhere in the body (e.g. `{{validation_url link:m>"Download (PDF)"}}`). Substitution runs before sanitising and tolerates TinyMCE-encoded braces. The previously hardcoded heading/auth-code card/button chrome was removed from the send path — the shipped default template (English source, Loco-translatable) now carries all of it. (#649)
- The `{{validation_url …}}` DSL parser now keeps double-quoted custom text with spaces intact (e.g. `link:m>"Download document (PDF)"`); the previous space-split dropped multi-word custom text. Extracted into the shared `ValidationUrlPlaceholders` helper used by both the PDF layout and the email. (#649)
- Internal — audience list/search/count and environment holiday/count query caches now invalidate on write via the shared `CacheVersion` counter (a monotonic per-domain version folded into the cache key) instead of relying on TTL expiry. These caches are keyed by `md5( args )` and can't be enumerated to delete individually, so a stale count or search result could previously survive up to an hour after an audience/environment/holiday mutation; every create/update/delete now bumps the `audience` version so the next read recomputes. Reuses the helper extracted from the recruitment public-listing cache. (#644)

### Fixed
- Documentation tab (#674, #697) — a content audit of the reorganized pages corrected real staleness against the code: the QR placeholder attribute is `error=` (not `error_level=`) with a 50–1000px size range, stale certificate tokens were removed, the recruitment notice status is `definitive` (grouped by adjutancy) and closed notices stay public, reregistration is delivered on the dashboard over AJAX (no shortcode) with the real read-only endpoints, the Forms API uses `per_page`/`page`, and Short URLs is a top-level admin menu.
- Documentation tab (#697) — removed the whole-card collapse behavior from the Quick Navigation: no more disclosure triangle or fold-to-strip on scroll or click. The card stays a fully-expanded sticky panel and only the tree branches open and close, one at a time.
- Self-scheduling **"Confirmation Email" subject/body were editable in the calendar editor but never used** — the booking confirmation always sent a fixed built-in template, silently ignoring what admins typed. Now a non-empty confirmation body/subject is honoured: it becomes the email's editable **"email body"** (tokens `{{user_name}}`, `{{user_email}}`, `{{calendar_title}}`, `{{appointment_date}}`, `{{appointment_time}}`) wrapped by the shared Email Model chrome. Leaving it empty keeps the built-in default (with receipt/cancel buttons), so existing calendars are unaffected. The body editor moved to **TinyMCE** and gained a **"Restore Default Text"** button. (#662)
- The global **"disable all emails" kill-switch is now bypass-proof** — enforced inside the single transport chokepoint `Core\EmailService::send()` rather than relying on each caller to check it. Recruitment convocation emails, audience/self-scheduling calendar notifications, the capability-manager and the certificate send-site did not all gate on the toggle, so turning emails off did not fully silence outbound mail; every path now honours it. (#662)
- Form editor → Email tab: the **"Notify Admin on Submission" toggle failed to auto-save** ("failed to save") — the toggle was wired for incremental autosave but its key was missing from the `FormMetaAjaxEndpoint` allowlist, so flipping it returned a 403 and the choice only persisted through a full form save. Added `send_admin_email` to the allowlist. (#660)
- Self-scheduling calendar editor: the five **email-notification toggles rendered on a single line** — a more-specific base `.ffc-toggle` rule (`display: inline-flex`) overrode the intended per-line stacking. The `.ffc-email-toggles` container is now a flex column, so each toggle sits on its own row without a specificity/`!important` fight. (#660)
- Self-scheduling appointment **reminder email was never sent** — the reminder handler and its whole read/mark pipeline existed, but nothing scheduled a scan or fired the reminder hook, so enabling "Send reminder before appointment" did nothing. Added the missing hourly cron driver (`ffcertificate_self_scheduling_reminder_scan`): it finds confirmed, not-yet-reminded appointments due per their calendar's `reminder_hours_before`, fires the reminder email, and marks them sent (no duplicates); it no-ops when a calendar has reminders off or when emails are globally disabled. (#650)
- Certificate confirmation email to the submitter was never sent — the async handler was hooked to `ffcertificate_process_submission_hook` but nothing scheduled it (orphaned since a refactor), so no user email (nor admin notification) went out. Submissions now schedule that dispatch again. (#649)

### Security
- Activity-log export least-privilege (#711) — bulk CSV export of the audit trail now requires a dedicated `ffc_export_activity_log` capability instead of riding the read-only `ffc_view_activity_log`, so a view-only operator can read the log but no longer extract it. A one-shot migration grants the new cap to every current `ffc_view_activity_log` holder, preserving behavior on upgrade. **New capability:** external integrations that enumerate FFC capabilities should account for the new slug.
- Settings least-privilege: the two most sensitive Settings surfaces are carved out of the blanket `ffc_manage_settings` into dedicated capabilities so each can be delegated or withheld independently (#711). `ffc_manage_settings_smtp` now gates saving the SMTP transport and the Email Model; `ffc_manage_settings_dangerzone` gates every destructive maintenance action — delete-all submissions, obsolete-shortcode and short-URL cleanup, the public-access disabler, the submission-link audit, and migration execution (the `?ffc_migration` runner moves off `manage_options`-only to this delegable cap). A one-shot migration grants both sub-caps to every existing `ffc_manage_settings` holder, so no current admin loses access on upgrade — administrators restrict a role by removing a sub-cap. **New capabilities:** external integrations that enumerate FFC capabilities should account for the two new slugs.

## [6.13.0] (2026-07-15) — `b0d8d9a`

### Security
- Settings → Geolocation and Rate Limit tabs gated their inline form save on a nonce only, not a capability. Because the Settings page opens on `ffc_view_settings` and the read-only affordance is a client-side `<fieldset disabled>`, a view-only user could POST the page nonce directly to change anti-fraud geolocation/rate-limit settings, whitelist their own IP/email/CPF, and add/edit/delete geofence locations. Both tabs now require `ffc_manage_settings` for every mutation, matching all sibling settings paths. (#637)
- Public certificate verification leaked unmasked PII: the `/valid` page renderer printed the bare `rf` (Registro Funcional) in full next to the already-masked `cpf_rf`, and the public `/verify` REST endpoint returned raw `email` and `rf`. Both fields are now masked (`mask_rf()` / `mask_email()`) on the public paths, consistent with the existing CPF masking. (#637)
- Audience booking REST reads (`GET /ffc/v1/audience/bookings` and the conflict probe) applied no schedule-visibility check, so unauthenticated callers could read bookings — dates, times, descriptions, environment and audience-group names — from schedules marked `private`. Reads are now constrained to the caller's readable schedule set (admins/bypass unrestricted, logged-in users their accessible schedules, anonymous users active public schedules only), mirroring the shortcode's visibility gate. (#637)
- CSV exports were vulnerable to spreadsheet formula injection (CSV/DDE): unauthenticated form-submission values reaching the shared `CsvWriter` were written verbatim, so a cell starting with `=`, `+`, `-`, `@`, TAB or CR would execute as a formula when a privileged operator opened the file. Such cells are now neutralized with a leading single quote at the canonical write point, covering every exporter. (#637)
- One-use form "ticket" restrictions could be bypassed by a race condition: the ticket was consumed with a non-atomic post-meta read-modify-write, so two concurrent submissions could both pass the membership check and each issue a certificate from a single ticket. Ticket consumption now makes an atomic `INSERT IGNORE` claim against a UNIQUE `wp_options` row (the same single-use pattern as the scheduling exception tokens), so exactly one concurrent caller wins and the rest are rejected as already-used. (#638)

### Added
- Short URLs admin page now shows a "Settings" shortcut (a standard `.page-title-action` button next to the page title) linking straight to the URL Shortener settings tab (`ffc-settings&tab=url_shortener`). Gated on the settings view cap so it only appears for users who can open that page. (#627)

### Fixed
- The develop→testes deploy no longer excludes the bundled `html/` templates directory. `html/` holds the plugin's built-in certificate/ficha/receipt layouts, which the form editor loads at runtime (`glob( FFC_PLUGIN_DIR . 'html/*.html' )`), but the rsync excluded it — likely mistaken for a coverage-report dir — so the testes site had an empty layout-template picker and 404s when loading a default template. (#629)

### Removed
- Deleted two files that did not belong in the plugin source: `html/atestado_estagios.html` (an install-specific template) and `html/ludmila_santos.png` (a real individual's scanned signature). Neither is referenced by any bundled template or code. (#629)

### Fixed
- Scheduling menu section separators ("Self"/"Audience") lost their dashicons and became clickable on admin screens that don't load `ffc-audience-admin.css` (e.g. the self-scheduling CPT list/new screens). The global fallback registered the separator styles via `wp_add_inline_style( 'admin-menu', … )` on `admin_head`, which fires after `admin_print_styles`, so the inline style was attached too late to ever output. Registered on `admin_enqueue_scripts` instead so the separators keep their icons and non-clickable styling on every admin page. (#625)
- Recruitment admin tabs now highlight the open tab in the wp-admin sidebar. The tab submenus register slugs like `ffc-recruitment&tab=candidates`, but WordPress resolves the "current" row from the `?page=` value alone (always `ffc-recruitment`), so "Notices" stayed highlighted on every tab and internal pages didn't track the sidebar. Added a `submenu_file` filter mapping the current `?tab=` onto its submenu slug. (#625)
- Recruitment admin screens — fixed three latent fatal errors in extracted templates that referenced classes retired in the #594 façade cleanup, so the affected branches would `Fatal error: class not found` when rendered: `notice-edit/general-section.php` (`RecruitmentNoticeRepository` → `RecruitmentNoticeReader`), `notice-edit/classification-filters-form.php` (`RecruitmentAdjutancyRepository` → `RecruitmentAdjutancyReader`), and `admin-page/tabs.php` (added the missing `use` import for `RecruitmentAdminPage`). Surfaced by new render smoke-tests. (#618)

### Changed
- Internal (CI) — the develop→testes deploy keeps its 3 rsync attempts but spaces them further apart (a fixed 120s between attempts instead of 20s/40s, a ~5.5-minute total window): the old backoff was shorter than a typical managed-hosting restart, so all attempts landed inside the same outage and the testes site silently stayed on a stale version. (#628)
- URL Shortener is now a top-level admin menu with its own sidebar icon (`dashicons-admin-links`, contiguous with the other FFC menus) instead of a submenu under the `ffc_form` CPT — it's a standalone module. The menu still only appears when the module is enabled in settings (`UrlShortenerLoader::init()` bails before registering it otherwise). The page URL moves from `edit.php?post_type=ffc_form&page=ffc-short-urls` to `admin.php?page=ffc-short-urls`; all in-page links and redirects updated accordingly. (#625)

### Changed
- Internal (#563 — coverage) — took every remaining sub-80% module to ≥80%: `api` (UserAudience/Form REST controllers), `reregistration` (ficha generator, data processor, activator), `repositories` (submission reader/writer), `settings` (all tab classes), `url-shortener` (admin-page/meta-box/qr-handler), `generators` (PdfGenerator), `(root)` (Loader), `shortcodes` (DashboardShortcode), `submissions` (lifecycle service) and `migrations` (CPF/RF-split strategy). Every `includes/` module is now ≥80% (lowest: audience 80.7%); overall PHP statement coverage 82.95%→86.37%, and the floor `COVERAGE_FLOOR_LINES` is ratcheted 78→82. Tests only.
- Internal (#563 — coverage) — raised `admin` 71.1%→91.3% and `frontend` 70.5%→92.4% (both also clearing 90%) with AJAX-export, list-table, edit-page, render and submission-pipeline tests across SubmissionsList, AdminSubmissionEditPage, the Admin orchestrator, ActivityLogPage, UserCustomFields, ConditionalAssets, FormListColumns, Settings, PublicCsvDownload (+ AJAX handlers), SubmissionPersister, VerificationHandler (+ AJAX), PublicCsvExporter, and the rate-limit/pdf/success stages. Overall PHP statement coverage 77.96%→82.95%; the floor `COVERAGE_FLOOR_LINES` is ratcheted 73→78. Tests only.
- Internal (#563 — coverage) — lifted the last two sub-70% modules over the line: `admin` 65.7%→71.1% (CsvExporter AJAX export, SettingsActionHandler routes, SettingsSaveHandler, PreflightStatsService) and `recruitment` 56.6%→82.0% (the three REST controllers, the four list-tables, the reason/adjutancy edit-pages, CandidateReader, CandidatePersister, and the notice-edit/admin-page renderers). Every `includes/` module is now ≥70%; overall PHP statement coverage 69.16%→77.96%, and the floor `COVERAGE_FLOOR_LINES` is ratcheted 67→73. Tests only.
- Internal (#563 — coverage) — pushed the `frontend`, `url-shortener` and `settings` modules over the 70% line (step #3 cluster): `PublicCsvExporter` sync-limit + AJAX batch/download paths (4%→34%); url-shortener `handle_actions` removal branches, meta-box `enqueue_assets`, qr-handler `generate_svg` (module ~63%→73%); `TabGeolocation` `enqueue_scripts` + location-delete logic (44%→78%, settings module ~60%→70%). Tests only.
- Internal (#563 — coverage) — extended `SelfSchedulingShortcodeTest` to drive the `SelfSchedulingShortcode` render paths end-to-end: the full booking-interface render, the private-visibility (show/hide modes) and private-scheduling messages, the business-hours viewing restriction, the approval notice, and the full `enqueue_assets()` asset/localize path. The shortcode goes 6%→94%; the `self-scheduling` module clears 70% (~70%→~82%). Tests only.
- Internal (#563 — coverage) — added `ActivatorMigrationsTest` covering the #249 instant-column migrations (`maybe_migrate_submission_date_to_unix` incl. the destructive rename path, `maybe_migrate_submitted_at_to_unix`, `maybe_migrate_sibling_instants_to_unix`), `maybe_add_perf_indexes`, and the `upgrade_auth_code_unique_constraints` helper — option-flag short-circuits + table/column-guarded run paths. `Activator` 59%→84%; the `(root)` module clears 70% (~75%). Tests only.
- Internal (#563 — coverage) — extended `QRCodeGeneratorTest` to cover the per-submission QR cache read/write (`get_from_cache`/`save_to_cache`) and the `parse_and_generate()` cache-hit / cache-after-generate paths. `QRCodeGenerator` 67%→76%; the `generators` module clears 70% (~72%). Tests only.
- Internal (#563 — coverage) — extended `IpGeolocationTest` to cover `get_location()` and its fetch/cache/cascade paths (ip-api + ipinfo success/error responses, transient cache hit, primary→alternative cascade, unknown-service guard, request-IP fallback) with URL-dispatched `wp_remote_get` stubs. `IpGeolocation` 38%→97%; the `integrations` module clears 70% (~97%). Tests only.
- Internal (#563 — coverage) — added a dedicated unit test for `CsvStagingService` (the four-phase batched CSV import: ingest → validate → promote → commit), covering each phase's happy path and guard/error branches with alias-mocked collaborators + a partial `$wpdb`. The class goes 0%→94% (353/374); the `recruitment` module ~50%→~55%, overall PHP coverage ~70.2%→~71%.
- Internal (#563 — coverage hygiene) — `@covers`-gap audit: `FormEditorSaveHandler` and `CsvValidator` were exercised by their dedicated tests (`FormEditorSaveHandlerTest`, `RecruitmentCsvImporterTest`) but filtered out of coverage because those tests `@covers`\'d only a sibling/parent class. Added the missing `@covers` (+ `class_exists()` preloads), attributing the existing execution — `FormEditorSaveHandler` 0%→70%, `CsvValidator` 0%→97%, overall PHP coverage 69.16%→70.17%. No new test code; no behavior change.
- Internal (#563 — coverage hygiene) — ratcheted the PHP coverage floor `COVERAGE_FLOOR_LINES` 66 → 67 after the markup-extraction sweep (#605/#606/#607) moved ~788 uncovered statements into `templates/` (out of scope); re-measured 69.16%.
- Internal (#563 — coverage hygiene) — `SubmissionHandlerTest` already exercises `SubmissionHandler` end-to-end (process/update/trash/restore/delete/bulk/decrypt/magic-token, 47 tests), but its `@covers` listed only the extracted `SubmissionLifecycleService`, so PHPUnit filtered the handler's executed lines out (reported 0%). Added the missing `@covers \\FreeFormCertificate\\Submissions\\SubmissionHandler` (+ a `class_exists()` preload), attributing the existing coverage — the handler goes 0%→90% and the `submissions` module 43%→76%. No new test code; no behavior change.

- Internal refactor (#563 — coverage hygiene) — extracted the inline admin markup from `ReregistrationAdminRenderer` (campaign list + row, create/edit form, submissions list + row, audience transfer list) into `templates/admin/reregistration/*.php` partials. Markup is byte-identical; the renderer keeps the data-prep logic and includes each partial (`self::` sibling renderers resolve in the including method scope). The view class shrinks 691→293 lines (403→122 in-scope statements), moving pure presentation out of the coverage scope per the `templates/` convention. The `AdminUI::render_toggle()` calls move into the form partial too, eliminating the `Reregistration→Admin` module-boundary edge (baseline tightened, 130→129).
- Internal refactor (#563 — coverage hygiene) — extracted the inline admin markup from `UrlShortenerAdminPage::render_page()` (stats cards, create form, search/filter, the links table and the QR-code modal) into `templates/admin/url-shortener/short-urls-page.php`. Markup is byte-identical; the controller keeps the data-prep/pagination logic and includes the partial. The class shrinks 626→365 lines (371→174 in-scope statements), moving pure presentation out of the coverage scope per the `templates/` convention.
- Internal refactor (#563 — coverage hygiene) — extracted the inline admin markup from `RecruitmentAdminPageRenderer` (settings tab, candidates CSV-import section, the create-notice/adjutancy/reason forms, the tab nav, the first-run empty state and the REST pointer) into `templates/admin/recruitment/admin-page/*.php` partials. Markup is byte-identical; the renderer keeps only the data-prep/capability logic and includes each partial. The view class shrinks 729→354 lines (437→127 in-scope statements), moving pure presentation out of the coverage scope per the established `templates/` convention.

### Fixed

- Recruitment admin templates — fixed three latent fatal references to symbols removed in the #594 Reader/Writer façade retirement, surfaced while adding render-test coverage (#563). `templates/admin/recruitment/notice-edit/general-section.php` and `classification-filters-form.php` referenced the retired `RecruitmentNoticeRepository` / `RecruitmentAdjutancyRepository` classes (now `RecruitmentNoticeReader` / `RecruitmentAdjutancyReader`), and `templates/admin/recruitment/admin-page/tabs.php` referenced `RecruitmentAdminPage` unqualified in a global-namespace file (added the missing `use` import). Each would have thrown a "class not found" fatal when its branch rendered. (#563)

## [6.12.0] (2026-06-26) — `79f2c09`

### Security

- IP geolocation debug logging — hardened to never store a raw client IP, even with `debug_geofence` enabled. A security audit found `IpGeolocation::get_location()` wrote the raw IP into the ActivityLog `LEVEL_DEBUG` trail on the cache-hit and service-success paths (and via the returned `location.ip` field echoed into the log), inconsistently with the already-hashed `fetch_from_service` path and the salted-hash treatment in `PreflightTelemetry`. All debug-log call sites now emit a truncated SHA-256 (`hash_ip()`) and redact the `ip` field of any logged `location` array via a new `redact_location_ip()` helper; the value returned to callers and the cache entry are unchanged (the contract `location['ip']` is preserved). Low severity — the path is gated behind the off-by-default debug toggle and an IP is low-sensitivity operational data — but it removes a residual LGPD/GDPR exposure and aligns the module with the plugin's IP-hashing convention. No behavior change for callers. (#596)

### Changed

- Internal refactor (#563 — modular-monolith hardening) — decomposed the largest god-classes and the shared static hubs, behavior-preserving throughout (characterization tests first; markup/queries/hooks unchanged): the `FormProcessor` submission flow → a pipeline of testable guard/stage classes (~1047→85 lines); `CapabilityManager` → `CapabilityMigrator` / `RoleRegistrar` + a slim runtime manager; the `Core\Utils` god-utility dismantled into focused homes (`FilenameHelper`, `RequestInput`, `AssetHelper`, `HtmlPolicy`, `Capabilities`, `SuccessHtmlRenderer`, …), leaving only generic primitives; `RateLimitChecker` → per-dimension strategy classes; `RecruitmentCsvImporter` → `CsvParser` / `CsvValidator` / `CsvStagingService` / `CandidatePersister` (~1554→310); the monolithic `Activator` → per-module activators; the five large repositories → `*Reader` / `*Writer` behind a delegating façade; admin-renderer view/logic separation; and typed per-option-group settings readers `GeolocationSettingsReader` / `RateLimitSettingsReader`. Full suite green; PHPStan L8 + WPCS clean. (Detail in the linked PRs.)
- Internal refactor (#589) — retired the `UserManager`→`CapabilityManager` and `ActivityLog`→`ActivityLogQuery` back-compat delegations (all call sites repointed); split `RecruitmentCandidateRepository` into reader/writer; decomposed the public-CSV classes and the `ReregistrationAdmin` / `RecruitmentAdminPage` god-classes into controller + renderer; extracted the large inline admin markup into `templates/` partials; and split `PdfGenerator` into renderer + data-assembly. Behavior-preserving — byte-identical markup, hooks unchanged.
- Internal refactor (#591) — split the remaining admin/handler god-classes behind same-signature delegators (`FormEditorSaveHandler`, `AudienceAdminAudience`, `PrivacyHandler`, `AdminAssetsManager`, and the critical-path `SubmissionHandler` → `SubmissionLifecycleService`); read/write-split the smaller repositories (recruitment notice/adjutancy/reason/call, url-shortener, reregistration-submission); extracted `SettingsActionHandler` from `Settings`; removed the lone dead `DateFormatter::flush_cache()` shim and dead back-compat entry points. The remaining migration-tail shims (`Encryption` plaintext fallback, `cpf_rf_encrypted`, ficha `generation_date`) stay — evidence-gated, not dead code. Behavior-preserving.
- Internal architecture (#563 — module boundaries) — added a dependency-free module-boundary guard (`tests/Unit/ModuleBoundaryTest.php` + committed baseline, 130 edges) that fails CI on any new cross-module coupling and can only shrink — regenerate with `FFC_UPDATE_BOUNDARY_BASELINE=1 vendor/bin/phpunit --filter ModuleBoundary` (#593). Then retired the nine static repository façades, migrating every caller to the `*Reader` / `*Writer` classes directly (row-shape types + public constants rehomed onto the readers; `class_exists()` availability guards and `@phpstan-import-type` consumers repointed). The three **instance** façades (`AppointmentRepository`, `SubmissionRepository`, `UrlShortenerRepository`) are kept by design — they are the transactional aggregate root (`begin_transaction()` → `FOR UPDATE` read → write → `commit()` on one `$wpdb`), not dead delegators. Graph unchanged at 130 edges; full suite green (5513 tests); PHPStan L8 + WPCS clean. (#594)
- Internal architecture (#563 — per-module bootstrap loaders) — introduced single bootstrap entry points (`AdminLoader`, then `ReregistrationLoader` / `SelfSchedulingLoader`) so the orchestrator (`Loader`) wires each module through one symbol instead of newing up its internals inline, narrowing the `Root → <module>` surface. `UserDashboard` was deliberately left without a loader — its `Root` edge is capability/role lifecycle (orchestrator responsibility), not module bootstrap, so a loader would add indirection without shrinking the edge (decision documented in `CLAUDE.md`). Behavior-preserving; each loader pinned by a wiring test. (#600, #601)

## [6.11.2] (2026-06-21) — `1b1b90d`

### Security

- Reregistration admin — fixed a stored-XSS vector (CodeQL `js/xss-through-dom`) in the audience transfer list. Audience name/color/id read from a DOM `data-` attribute were string-concatenated into HTML and injected with `.append()` (both the *selected* and *available* branches), so a malicious audience name or color stored by a delegated audience manager could execute script in the reregistration admin screen. The list is now built with `jQuery('<el>', {…})` + `.text()`/`.attr()` (every value escaped; the color is applied only when it matches a hex pattern). DOM shape, `data-id` and hidden-input values are unchanged. Also hardened `ffc-geofence-admin.js` field-key derivation (CodeQL `js/incomplete-sanitization`) — not exploitable (hardcoded allowlist input) but cleared for good. (#564)

### Changed

- Vendored thumbmarkjs 1.9.0 → 1.9.1 (MIT, `libs/js/`). Patch release; the server algorithm, fingerprint schema, contract and LGPD posture are unchanged, and the telemetry beacon stays unconditionally disabled. (#571)

## [6.11.1] (2026-06-17) — `97278ec`

### Added

- Device Fingerprint limit — **Minimum strong signals** setting (Settings → Rate Limit → Device Fingerprint, with a per-form override in the form editor and an auto-save slot). On top of the match threshold, a fuzzy "same device" match now additionally requires this many high-entropy *strong* signals (canvas, WebGL, audio, fonts, plugins, permissions) to corroborate. The "Signals collected" UI now groups signals visually as **Strong** vs **Weak** (with per-signal badges) so operators can see which signals carry real distinguishing power. Default 2; 0 restores the legacy single-tier behavior.

### Fixed

- Device Fingerprint limit no longer mass-false-blocks legitimate first-time submitters in homogeneous audiences (e.g. an event where most people use the same phone model / browser). The previous single-tier rule treated two visits as the same physical device whenever **any** N-of-13 signals matched, but the *weak* signals (user agent, screen, timezone, CPU/memory, media queries, math precision) are identical across whole fleets of same-model devices — so distinct people collided and everyone after the first was blocked. Matching is now **two-tier**: the threshold count **and** a minimum number of *strong* signals must match before a fuzzy block fires. Submissions that cannot emit enough strong signals (privacy browsers blocking canvas/WebGL) fall back to the cookie path only and are never blocked on weak signals alone; such near-misses are recorded in the rate-limit log (action `suppressed`) for operator visibility. The per-form scoping, the cookie OR-path, the whitelist/manager bypasses, and the reprint exemption are all unchanged.

## [6.11.0] (2026-06-07) — `d753ac3`

### Added

- Privacy Policy Guide: the plugin now contributes suggested privacy-policy text to **Settings → Privacy → Policy Guide** via `wp_add_privacy_policy_content()`. Covers the personal data FFC processes (name, e-mail, CPF/RF, phone, IP, custom fields), encryption at rest, the configured activity-log retention window, who has access, and the data-subject export/erase rights already served by the existing privacy exporters/erasers. Complements the LGPD/GDPR tooling in `PrivacyHandler`.
- Operator access info screen reorganised: the download quota and a short "Open form" link now live in the top summary card, and "Availability Period" + "Event Schedule (Reference)" are merged into one Access-vs-Reference comparison table (single column when only one applies).
- Schedule exception: the operator now sees the participant-form page URL **at validation time** — a clickable preview line ("The participant form opens at: …") rendered on the info screen as soon as the form is validated, before staging the exception. The URL is pre-resolved server-side (`schedule_form_url` in the info payload) via the same resolver the hand-off uses; opening it does not stage or consume a token. #366.

### Changed

- Namespace compliance: the appointments admin list table (the lone plugin class still in the global namespace as `FFC_Appointments_List_Table`, embedded in a view) is now the autoloaded `FreeFormCertificate\SelfScheduling\AppointmentsListTable`. Every plugin class now lives under `FreeFormCertificate\` (only the bootstrap `FFC_Autoloader` stays global, by necessity).
- Inline JS extracted to enqueued files: the admin user-profile custom-data collapsible-section wiring (`ffc-custom-fields-collapse.js`) and the appointments-list row "Cancel" prompt/redirect (`ffc-self-scheduling-admin-appointments.js`, now `data-*`-driven instead of an inline `onclick`). Both covered by new Vitest tests.
- Inline JS extracted: the appointment-receipt "Download PDF" button handler moved from a `wp_add_inline_script` block to an enqueued `ffc-self-scheduling-receipt.js` (data via the localized `ffcReceiptData`), covered by new Vitest tests.
- CSS hygiene: moved the extend-end modal's client-validation styling out of JS inline styles into `ffc-frontend.css` (the `.ffc-extend-end-input-invalid` class now has a real rule plus an `.ffc-extend-end-error` rule), and replaced hardcoded inline `color` styles on the working-hours required asterisks and the booking Cancel link with the existing `.required` / WP-core `.delete-link` classes. No visual change.
- CSS hygiene: removed 13 verified-dead CSS classes (zero references in PHP/JS/templates — e.g. `.ffc-verify-input-group`, `.ffc-success-message`, `.ffc-rereg-field-sm`, `.ffc-recruitment-pcd`). Dynamically-applied and grouped-selector live siblings (`.ffc-event-list-side/below`, `.ffc-recruitment-banner-definitive`, `.ffc-submit-btn`) were preserved; the six superseded `ffc-pdf-*` internal classes were intentionally left in place.
- Form editor → Email tab: enabling "Send Email to User" seeds the editor with a default `{{name}}` body instead of a blank field (forms with a custom message are untouched).
- Form editor → Time tab (multi-day on): End date must now be at least one day after Start — live `min` of start+1 on the input plus server-side `analyze_datetime_order()` flagging both fields on save (single-day forms unaffected).

### Fixed

- Schedule exception (operator entry/exit override): the "Open participant form" hand-off no longer lands on the site home. `ScheduleExceptionAction::resolve_form_url()` now auto-discovers the page that embeds `[ffc_form id="N"`, returning the most recently published embed (the `ffc_schedule_exception_form_url` filter still wins; home stays only as a last-resort fallback) — the lookup deferred in #366. The post-create modal also shows a spinner + "opening in a new tab" notice for a brief forced beat so the hand-off to the new tab is unmistakable.
- Audience bookings: wall-clock `booking_date`/`start_time`/`end_time` no longer shift by the site UTC offset on display — the admin bookings list, the user-dashboard bookings REST response (and its `is_past` flag, now a site-local date comparison), and the booking created/cancelled e-mails all render the literal value via `format_wallclock_date()`/`format_wallclock_time()`. (Same class as the self-scheduling/holiday fix; the audience JS already handled times correctly.)
- Self-scheduling: wall-clock `appointment_date`/`start_time`/`end_time` no longer shift by the site UTC offset on display — new `DateFormatter::format_wallclock_date()`/`format_wallclock_time()` render the literal value across every self-scheduling display (instant API unchanged).
- Scheduling Settings → Holidays: global and per-calendar holiday dates no longer display one day early on sub-UTC sites — both lists render the wall-clock DATE via `format_wallclock_date()`.
- Certificate preview (form editor + operator page) no longer crops borders — renders at the real PDF page size (A4, landscape default) and scales the whole frame to fit the modal, preserving aspect (recomputes on resize).
- Form editor → Time tab: the start+1 `min` floor on the End date is now applied only while "Multiple days" is on, so the hidden mirrored End field no longer fails native validation and blocks save.
- Form editor → Operator: a blank Download Limit now inherits the global default (empty deletes the per-form meta; "Inherit from global" placeholder + `.ffc-global-default` chip showing the current value).
- Form editor → Restriction → Device Fingerprint: a blank max/threshold/message now inherits the global default instead of force-saving `2` (each field shows the inherited value in a `.ffc-global-default` chip; reverts the 6.3.11 hard-default).
- Activation no longer logs a malformed `ALTER TABLE … ADD COLUMN` error — removed backtick-bearing `-- ` SQL comments that dbDelta misread as columns from four schema files (guarded by a test scanning every dbDelta source).
- Fresh install: the reregistration standard-fields seeder no longer logs a `Table 'ffc_audiences' doesn't exist` error — it skips cleanly and re-seeds on a later load when the table isn't present yet.
- i18n: the reregistration working-hours weekday dropdown fallbacks are now English (were Portuguese), and the recruitment notice-edit "Network error" messages are now translatable via a localized string instead of hardcoded — both surface through Loco.
- i18n: the CPF/RF-split and email-rehash migration error strings shown in the Data Migrations report are now wrapped for translation.

### Security

- Added the `ABSPATH` direct-access guard to three audience admin class files (`AudienceAdminCalendar`, `AudienceAdminEnvironment`, `AudienceAdminAudience`) for consistency with the rest of the plugin.

## [6.10.0] (2026-06-05) — `b1ba3db`

### Added

- `ffc_administrator` aggregator role — every FFC capability (admin + end-user), but not `manage_options`, so the whole plugin can be delegated without WP super-admin (GAP F).
- Settings → User Access: a role-capability editor for FFC roles (per-toggle AJAX, global/retroactive, audit-logged).
- Per-user capability editor on the user-edit screen redesigned: grouped cards, search, copyable slug chips, origin (User/Role) badges, assignable role chips, inline audience membership; fixes a latent bug that stripped uncheckable caps on save.
- Recruitment: admin "Undo decision" — send a `hired`/`withdrew`/`not_shown` candidate back to the queue (audited, reason-required, WARNING-level).
- Appointments: login-free cancellation page reached from the e-mail link (token-validated, `noindex`, nonce-guarded).

### Changed

- ⚠ Plugin-wide capability naming standard + 3-state model (breaking for integrations on old slugs): 10 caps renamed, 8 read-only `view` caps added (26 → 34); one-shot migration rewrites grants on every user + role.
- Three previously-inert admin caps now enforced: `ffc_manage_certificates` (Submissions + dashboard), `ffc_manage_custom_fields`, and the recruitment Settings tab (`view`/`manage_recruitment_settings`) — delegation without `manage_options`.
- More blanket `manage_options` gates replaced with delegable caps (GAP B): Settings page, admin submission REST, reregistration Custom Fields, and a new Short URLs domain (`ffc_view`/`manage_url_shortener`); 34 → 36 caps.
- Read-only "só vê" admin tier across modules (GAP C, 3-state): reregistration / appointments / audiences / recruitment open read-only on the `view` cap; writes stay `manage`-gated; `RecruitmentAdminActions::dispatch` hardened to re-check on every destructive action.
- `ffc_operator` is now a complete cross-module read-only auditor (GAP D) — gains `view_custom_fields` / `view_recruitment_settings` / `view_recruitment_reasons` / `view_url_shortener`.
- ⚠ Deletion is its own strict tier (GAP E): seven `ffc_delete_<domain>` caps; delete handlers no longer fall back to `manage`; migration seeds onto `manage` holders.
- ⚠ Bulk CSV export is its own strict tier (GAP G): `ffc_export_appointments` / `_reregistration` / `_audiences`; migration seeds onto `manage` holders.
- ⚠ Bulk CSV import is its own strict tier (GAP H): new `ffc_import_audiences` + `ffc_import_recruitment` tightened (no umbrella fallback); migration seeds onto `manage` holders.
- ⚠ Recruitment Reasons is a strict 3-state tier (GAP I): `ffc_view`/`manage_recruitment_reasons`; closes a bulk-delete cap gap (was nonce-only); migration preserves access.
- Capability editors: the permission list is organized by module (one card per module) with a Self-service / Administration divider, all groups collapsed by default, and surface badges on the exceptions (`API` on `forms_api`, `frontend` on `scheduling_bypass`).
- Dropped a stray cross-domain `ffc_export_certificates` grant from `ffc_self_scheduling_manager` (definition only; no upgrade behavior change).
- Settings page is now a real read-only surface for the `ffc_view_settings` tier: the active tab is wrapped in a disabled `<fieldset>` + a read-only banner.
- Internal frontend audit: inline admin JS extracted to dedicated lint-tested asset files (no behavior change).
- Internal frontend audit: large maintainability refactor splitting the monolithic frontend scripts + oversized PHP classes behind their existing APIs (no behavior change).

### Fixed

- User permissions card now spans the full content width on both editor surfaces.
- Schedule exception (operator exit-time override) is now truly single-use — a `jti` replay ledger claims the token atomically and the banner clears after use.
- Recruitment notice editor: "call out of order" now prompts for a justification even when the classification list is filtered or paginated (authoritative empties map, not the DOM).
- User dashboard: untrusted values escaped + `rel="noopener noreferrer"` on external links (XSS / tabnabbing).
- Geofence: the location cache stores a short-lived "validated" pass token instead of raw GPS coordinates.

## [6.9.0] (2026-06-02) — `ca3c73e`

### Added

- Recruitment notice editor: Subscription (PCD/GERAL) column on both classification tables (shared badge with the public list; bulk-loaded, no N+1).
- Recruitment notice editor UX polish: Definitive tab as the default when definitive rows exist, independent pagination on both tables, public-list count badge + search-button polish, and locked adjutancy/reason slugs on edit.
- `UserCreator::get_or_create_user_dual()` — dual CPF+RF lookup in one SQL pass so recruitment no longer misses a match keyed on the other identifier.

### Changed

- Recruitment CSV import (V11): atomic four-phase DB-staged flow (start → validate → batch → commit) with a 24h-TTL plaintext scratch table; resolves gateway timeouts on large notices.
- Recruitment public list: windowed pagination (7-page window + first/prev/next/last) instead of every page number.

### Fixed

- Recruitment public list: `withdrew` now shows the translated label and the configured badge color.
- Recruitment CSV validate: `lines` was a MariaDB reserved word → aliased to `csv_lines`.
- Recruitment CSV: `list_type` ENUM coercion silently broke staging (V9 widens it to `VARCHAR(50)` + purges legacy `''` rows).
- Recruitment CSV: duplicates sharing RF/email are now caught in validation instead of tripping the UNIQUE index at insert.
- Recruitment CSV: the admin preliminary import moved to start → batch → commit to stop gateway timeouts.

## [6.8.0] (2026-05-31) — `948a1be`

### Added

- Form editor "Time" tab: a "Multiple days" toggle gates the multi-day controls (single-day forms mirror `date_end = date_start`).
- Geofence block/error messages now enforce a 25-char minimum so an empty message can't surface as a generic "Connection error".
- Recruitment: new terminal status `withdrew` (Desistente) — `called`/`accepted` → `withdrew`, own badge color, V8 enum migration.
- `{{schedule}}` / `{{schedule_total}}` documented in Settings → Documentation §2.
- Documentation tab: the Quick Navigation index sticks to the top and auto-collapses on scroll.
- Floating "Back to top" button on every Settings tab.
- "Duplicate this form" is now available from inside the form editor (Publish box), not just the list.
- Certificate form editor adopts a vertical tabbed layout (Layout / Fields / Security / Email / Time / Geolocation / Quiz / Operator), keyboard-navigable + deep-linkable, with failed-save tab flagging.
- Required Certificate Tags are configurable (Settings → Advanced) and enforced before save (`{{auth_code}}` always required).
- Activity Log: granular control over what's recorded — a minimum level + seven on/off categories (Settings → Advanced).
- Activity Log records three delivery events: `pdf_generated`, `certificate_emailed`, `csv_downloaded`.
- Certificates dashboard: each form in the day side-list gets a "view submissions" link (pre-filtered).
- New maintenance tool: Submission ↔ user link audit (report-only, Settings → Data Migrations).
- New maintenance tool: Disable Public Operator Access on old forms (non-destructive, preview-first).
- New maintenance tool: Short URL Cleanup (orphaned / never-clicked / trashed, preview-first).
- The "Termo de Ciência" acknowledgment notice is now editable per-audience (visual editor; ficha PDF + form read from it).

### Changed

- Form editor Time / Geolocation tabs reorganized into full-width cards, with IP-dependent controls gated on the IP toggle.
- Settings → Geolocation: IP-API rows collapse on the master toggle; Fallback Behavior reordered GPS → IP → Both.
- Rate Limit + Activity Log cards gained "Show" collapse toggles for their sub-options.
- Form editor Time tab: the event reference schedule (`class_time_*`, feeds `{{schedule}}`) is a primary top-of-tab section, decoupled from Schedule Exception; save guard requires `{{schedule}}` when filled.
- Import & Export folds into Scheduling Settings as a 4th tab (301 redirect from the old URL).
- Scheduling Settings + Recruitment admin adopt the vertical tab layout (URL contract preserved).
- Settings page adopts the vertical tab layout (per-tab save model unchanged).
- Activity Log: four events raised `info` → `warning` (`data_cleanup`, recruitment classification/adjutancy delete, `tickets_purged_expired`).
- In-plugin documentation refresh (Settings → Documentation), parts 1–4: new Recruitment + Maintenance Tools sections, missing template variables / hooks / shortcodes filled, staleness pass.
- Recruitment editors: public-column grid + reason "applies to" group render as toggle switches.
- User-profile FFC capability fields render as toggle switches.
- Data Migrations cards: proper padding + Short URL Cleanup criteria as toggles.
- Certificate previews fill the full placeholder set from one PHP source (`CertificatePreviewSamples`).
- Activity Log: pre-flight `reason` codes show a human-readable label (stored enum unchanged).
- More boolean admin settings use the `.ffc-toggle` switch component.
- Internal: obsolete-shortcode cleanup runs through a pluggable maintenance-tool framework (`MaintenanceToolInterface` + registry).

### Fixed

- Submitting a form could silently fail when the IP rate limit was disabled (`check_ip_limit` now honors `ip.enabled`; non-empty default messages for every gate).
- Geofence date/time and geolocation blocks could surface with an empty message (runtime `message_or_default()` fallback).
- Certificate preview rendered a hardcoded `{{schedule}}` sample instead of the form's configured time.
- Certificate preview left `{{schedule}}` / `{{schedule_total}}` as raw tokens.
- Settings "Back to top" button didn't float on tabs other than Documentation (now rendered at `<body>` level via `admin_footer`).
- Self-scheduling calendar editor: config toggles rendered as plain checkboxes (`ffc-common.css` now enqueued).
- Ficha PDF: Divisão/Setor cells printed the literal placeholder (dependent-select now exposes `{{<key>_parent}}` / `{{<key>_child}}`).

## [6.7.7] (2026-05-23) — `73c9c9c`

### Fixed

- 5 geofence/spinner messages were unreachable for translators — now flow through `__()` + `wp_localize_script` (English fallback kept).

## [6.7.6] (2026-05-23) — `73c9c9c`

### Fixed

- Multiple GPS/location spinners could stack on one form — `showLoadingMessage()` is now idempotent (at most one spinner).

## [6.7.5] (2026-05-23) — `1176500`

### Fixed

- `/valid` reregistration: submission status moved into a colored body pill; campaign period surfaced.
- `/valid` appointment: removed the doubled gray separator before "Participant Data".
- "Document Invalid" card: manual-verification placeholder uses "validation code" terminology.
- Ficha PDF: reference year shown below the title (from the campaign cycle); footer now "Preenchido em" (submission date).

## [6.7.4] (2026-05-23) — `7505af2`

### Fixed

- `/valid` appointment + reregistration now mask participant CPF / email (the certificate path already did).
- "Document Invalid" failure screen now uses the `.ffc-certificate-preview` card structure (red variant).
- Reregistration magic links resolve after the parent campaign expires (`expired` added to the storage-layer whitelists).

## [6.7.3] (2026-05-23) — `6d091a2`

### Fixed

- 10 hardcoded Portuguese fallback strings in `ffc-reregistration-frontend.js` switched to English (PT-BR still flows via the localize payload).

## [6.7.2] (2026-05-23) — `d1cbd84`

### Fixed

- Post-submission card: removed the duplicate "Success!" preamble; gray actions strip now wraps the "Can't find the PDF?" block; hint summary lifted to H4 size.
- `/valid`: removed the doubled gray separator; CPF / RF / e-mail now masked in the public response; "Recorded schedule" always carries both ends.
- User dashboard reregistrations: download button now appears for `expired` submissions that already carry an `auth_code`.

## [6.7.1] (2026-05-23) — `42851f3`

### Fixed

- Post-submission success card no longer constrained to 450px (matches `/valid` width).
- Schedule-exception banner aligned to the form width with a proper notice card treatment.
- Removed the doubled gray separator on the success card; shrank the oversized "Can't find the PDF?" block.

## [6.7.0] (2026-05-22) — `9dacfae`

Per-submission **schedule exception**: an authenticated operator on the public CSV-download panel can issue a one-use exception that overrides a single submission's `{{schedule}}`.

### Added

- `[ffc_csv_download]` panel: "Entry/exit exception" button (Now / Manual modes) staging the exception via a 30-min HttpOnly cookie.
- `[ffc_form]`: schedule-exception banner + hidden signed token; the cookie is cleared in the same request (strictly one-use).
- Submission handler verifies + persists the override into two new `TIME NULL` columns and emits `schedule_override_created` + `operator_ip_bypass` audit rows.
- `{{schedule}}` / `{{schedule_total}}` placeholders (`DateFormatter::format_schedule*`, 3-tier resolution) across PDF + email.
- `/valid`: "Adjusted entry/exit schedule" block (before → after range pinned at staging time, masked operator CPF).
- Activity Log renderer for the two new actions; per-form admin toggles in the Geofence metabox.
- `ScheduleExceptionSession` + `ScheduleExceptionAction` (HMAC token, form-bound, versioned).

### Changed

- `ffc_submissions`: two new nullable `TIME` columns (Category B wall-clock).
- `ActivityLog` vocabulary: two new action constants.

### Fixed (forward-ported from 6.6.8–6.6.10)

- `[ffc_form]` geofence config not localized for unquoted / extra-attribute shortcodes (now uses `get_shortcode_regex` + `shortcode_parse_atts`).
- URL Shortener admin QR/regenerate/copy buttons inert when JS is combined, and on non-FFC (Gutenberg) screens (`ffc-core` dependency + early registration).
- Form duplication missed four CSV-public-download sub-feature toggles.

## [6.6.12] (2026-05-22) — `da7e499`

### Changed

- Post-submission success card rewritten to share the `/valid` visual language (blue-gradient header, detail rows, single primary CTA); CPF no longer surfaced on this screen; "where to find your certificate" is now a collapsed `<details>` block.
- `/valid` H4 section headers share the H3 blue-underline treatment.

### Removed

- 9 obsolete success-card CSS rules (legacy class names kept as secondaries).

## [6.6.11] (2026-05-22) — `5a2688f`

⚠ Breaking for downstream automations matching PDF filenames.

### Changed (breaking)

- All plugin-generated PDF filenames now follow `{prefix}_{entity_id}_{LETTER}-{code}.pdf` via the shared `Utils::build_pdf_filename()` (prefix translatable; letter `C`/`A`/`R`; no double-prefix).
- Appointment receipt gained its own `ffcertificate_appointment_receipt_filename` filter; ficha uses `auth_code` (or `S{id}`).

### Added

- Central `ffcertificate_pdf_filename` hook fired for every PDF before the per-type hooks (escape hatch to pin a stable shape).

## [6.6.10] (2026-05-22) — `a5fb053`

### Fixed

- Form duplication: four CSV-public-download sub-feature toggles missed by `CPT::handle_form_duplication()` (hotfix; forward-ported into 6.7.0).

## [6.6.9] (2026-05-22) — `b758a28`

### Fixed

- URL Shortener admin QR/regenerate/copy buttons still inert on non-FFC admin screens (hotfix; forward-ported into 6.7.0).

## [6.6.8] (2026-05-22) — `ec9ad9d`

### Fixed

- `[ffc_form]` geofence config not localized for unquoted/extra-attribute shortcodes; URL Shortener admin buttons fail silently when JS is combined (hotfix off `release/6.6.x`; forward-ported into 6.7.0).

## [6.6.7] (2026-05-21) — `04608f5`

### Fixed

- Four public-facing JS enqueues (`ffc-audience`, `ffc-frontend-helpers`, `ffc-calendar-core`, `ffc-geofence-frontend`) missing the `ffc-core` dependency — broke `FFC.request` on sites that combine JS.

## [6.6.6] (2026-05-21) — `3cf84d1`

### Fixed

- `Uncaught TypeError` on certificate reprint — `generate_success_html()` now accepts `int|string` for the unix-int submission date.
- `Unknown column 'action'` on legacy `ffc_activity_log` installs — new `maybe_create_table()` re-runs dbDelta once via a stored DB-version option.

## [6.6.5] (2026-05-21) — `faaaa30`

⚠ Minimum PHP bumped 8.1 → 8.3 (8.1/8.2 installs stay on 6.6.4 via the `Requires PHP` header).

### Changed

- Minimum PHP 8.1 → 8.3 across all declarations; PHPStan target pinned `phpVersion: 80300`; CI matrix slimmed to 8.3/8.4.

### Fixed

- Removed a dead, unreachable branch in `RecruitmentPublicShortcodeRenderer::render_row` (PHPStan 2.1.55; no behavior change).

## [6.6.4] (2026-05-20) — `b6ce71b`

Pre-flight permission checks + server-side reorderings + copy polish.

### Added

- Cookie sanity check (STEP 2 of the geofence pre-flight) — warns visitors with cookies fully blocked.
- GPS permission pre-check via `navigator.permissions.query` (granted/denied/prompt branches; iOS-safe).
- 15th Debug toggle `debug_browser_env` gating an opt-in browser-environment diagnostic log.
- Pre-flight bail telemetry (`ffc_log_preflight_bail`, hashed IP) + per-form "User-friction stats" badges in the editor.

### Changed

- Server: LGPD + email presence checks run before per-field validation (combined `errors` array).
- Server: geofence check moved before the consolidated rate limit (outside-geofence retries don't burn the budget).
- Rate-limit countdown polish (synchronous first paint, `aria-live`, escaped message).
- Empathetic rewording of two admin-facing error messages (raw text moved to a `detail` field).
- Settings → Debug toggles grouped into Client / Server / Admin sections.

### Fixed

- The diagnostic log was always-on — moved behind `debug_browser_env` (default off).

## [6.6.3] (2026-05-20) — `3f86d29`

### Fixed

- Server-side stale-nonce auto-recovery on form submission + certificate verification (`refresh_nonce` + fresh `new_nonce`), with a single client-side retry — fixes "Security check failed" on iOS Safari / cached HTML.

## [6.6.2.1] (2026-05-20) — `908d640`

Cache-bust-only release (no source change) — rotates `?ver=…` so the stale-nonce fix + success-card CSS reach cached clients. Introduces the 4th-segment cache-bust versioning convention.

## [6.6.2] (2026-05-20) — `2102c62`

### Added

- Persistent success card with magic-link URL, auth-code, copy buttons, "Download PDF again", and platform-specific "where to find your certificate" hints.
- Universal "didn't download?" link on the desktop/Android download path; pre-submission "don't close this tab" + offline warnings.

### Changed

- Actionable error panel replaces every blocking `alert` in `ffc-pdf-generator.js` (per-failure copy, CORS guidance).
- Generation overlay accessibility (dialog role, focus trap, `aria-live`, Escape handling).

### Fixed

- Activity-log INSERT failing under the post-4.9.7 FK (system rows now map to `user_id = NULL`).
- `FFC.request is not a function` on public shortcodes (`ffc-core` registered on the frontend + listed as a dependency).

## [6.6.1] (2026-05-18) — `c360f89`

⚠ `GET /forms` REST now uses `page`/`per_page` pagination; the legacy `limit` arg is removed (default `per_page` 10; `X-WP-Total*` + `Link` headers).

### Removed

- `?limit=N` on `GET /forms`; `Utils::debug_log` shim; `PublicCsvDownload::maybe_wipe_legacy_logs`; unused `FFC_DEBUG`; four deprecated CSS aliases.

### Changed

- AJAX migration umbrella closed — ~70 inline jQuery AJAX sites moved to `FFC.request` / `FFC.rest`.
- POST/GET sanitize migration closed — ~120 inline sanitize patterns moved to `Utils::get_*` helpers.
- 5 reuse helpers consolidated (~33 call sites); `ffc_settings` centralized via `SettingsReader` (25 sites).
- Recruitment admin: 5 inline scripts consolidated into `ffc-recruitment-admin.js`.

## [6.6.0] (2026-05-17) — `ff29bf5`

⚠ Breaking for external SQL: four "instant" columns (+ seven siblings) converted from `DATETIME` to `BIGINT UNSIGNED` unix-UTC seconds (names unchanged; idempotent backfill on first load). External SQL must use `UNIX_TIMESTAMP()` / `FROM_UNIXTIME()`.

### Changed

- Category A storage (`#249`): instants stored as unix-UTC int, encapsulated in `DatabaseHelperTrait::migrate_datetime_column_to_unix` (option-flagged, restart-safe).
- DateFormatter now owns the last user-visible display sites (activity log, audience holiday/booking tables, appointments Time column).
- Settings → General: combobox + custom-format fallback for every date/time field, with smart-match on load.

### Fixed

- Public Operator Access audit CSV: timestamp column renders in the site timezone (`wp_date`) instead of UTC.
- DateFormatter: fixed duplicated time on verification cards and raw dates in "Minhas Convocações".

## [6.5.14] (2026-05-15) — `22ef510`

### Added

- Auto-save for the form-editor master toggles (13 boolean keys) via `FormMetaAjaxEndpoint`.
- Audit log: explicit `download_delivered` tag emitted right before bytes leave the server.

### Changed

- Canonical date/time formatting through `DateFormatter` (~25 sites); new `time_format` / PDF format keys; new-install default `'F j, Y'` → `'d/m/Y'`.
- "Public CSV Download" renamed "Public Operator Access"; skip-on-off save semantics for 11 master toggles; unified `.ffc-collapsed-target` visibility pattern.
- Device Fingerprint Limit merged into Restriction & Security (8 → 7 editor sections).
- Independent CSV-download sub-toggle (`_ffc_csv_public_download_enabled`).

### Fixed

- Form-meta autosave broken by a hook-order race (FormEditor enqueue priority bumped to 20).
- Operator Access info screen showed "ready to download" when CSV download was disabled.

## [6.5.13] (2026-05-15) — `29a3e9e`

### Changed

- Public CSV audit summary reports three operator-facing buckets (legacy `count`/`success`/`fail` keys kept as a back-compat shim).
- Admin form save resets the postpone-close one-shot.

## [6.5.12] (2026-05-15) — `ed89e47`

### Added

- "Postpone close" public operator action (`ExtendEndAction`) — push a form's `time_end` later within the same day, once per form, on the public hash.

## [6.5.11] (2026-05-15) — `0d1028f`

### Fixed

- Early-open / geofence edits now purge the page cache for the embedding page (walks `posts_with_shortcode`), not just the unreachable CPT post.

## [6.5.10] (2026-05-15) — `7ad6e97`

### Fixed

- Early-open now actually opens the form — writes only `time_start` (not `time_end`) and adds a same-day (`not_today`) guard.

## [6.5.9] (2026-05-15) — `9976309`

### Changed

- Early-open confirmation modal restyled to match the cert-preview modal chrome.

## [6.5.8] (2026-05-15) — `df826bf`

### Added

- "Start Form Early" per-form on/off toggle (`_ffc_csv_public_start_early_enabled`).

### Changed

- "Start Form Early" metabox section drops the duplicate operator URL for an eligibility status pill.

### Fixed

- Forms-list inline toggles + cache buttons + settings autosave returned "Connection error" — `FFC.request` no longer clobbers a caller-supplied `data.nonce`.

## [6.5.7] (2026-05-14) — `53e18bb`

### Added

- Recruitment Adjutancy edit screen (the row action previously had no handler).

### Changed

- Self-scheduling editor: 10 more `.ffc-toggle` conversions.

### Fixed

- Toggle autosave looked broken because the badge broke the CSS sibling rule (badge now anchored outside the label).
- `WP_Scripts::add` doing_it_wrong notice — four enqueues declared the missing `ffc-admin` handle instead of `ffc-admin-js`.

## [6.5.6] (2026-05-14) — `9b4bcce`

### Added

- "Start Form Now" early-open public operator action (`StartFormNowAction`) on the public hash.
- Daily cron purges unredeemed ticket pools of ended forms (`ExpiredTicketsCleanup`).

### Changed

- "Public CSV Download" metabox renamed "Public Operator Access".
- `FormCache::purge_external_caches()` propagates invalidation to W3TC / LiteSpeed / WP Super Cache / WP Rocket.
- Calendar (6) + Environment (2) edit pages: more `.ffc-toggle` conversions.

### Fixed

- Reregistration "Email Notifications" toggles overlapping their labels (WP-core fieldset rule specificity).

## [6.5.5] (2026-05-14) — `fa5b9f0`

Admin UX modernisation: AJAX-in-place flows + `.ffc-toggle` switches across the admin (form-POST fallbacks kept).

### Changed

- Form-builder "Required?" flag, 28+ settings checkboxes, and 13+ recruitment/reregistration/form/SMTP booleans converted to `.ffc-toggle`.
- Submissions list bulk + per-row trash/restore/delete via AJAX; Activity Log filter/search/pagination via AJAX; Migrations JSON-batch runner; Forms-list inline feature toggles; Cache warm/clear inline.
- Non-boolean settings (33 fields) gained inline auto-save; `SettingsAjaxEndpoint` supports nested option arrays; auto-save wiring is framework-wide (`bootAutoSaveFields`).

## [6.5.4] (2026-05-13) — `e52ab55`

### Changed

- Geolocation fallback policy is now per-case: preset combobox (Tolerant/Hybrid/Strict/Custom) + a five-row matrix (existing installs migrated silently).
- More actionable blocked-form copy with a "Reload page" button; progressive loading messages unified across browsers.
- Settings → Geolocation booleans render as `.ffc-toggle`; admin-bypass toggles save inline; the Geofence Locations table is editable inline.

### Fixed

- Form "flash" before geofence validation (selector fix); iOS Safari accepting stale cached positions (`maximumAge` 30s → 5s); form body staying hidden after a successful first validation; Custom preset's per-case table only appearing after a save.

### Added

- `FFC.request()` AJAX chokepoint, `FFC.Admin.autoSaveField()`, the `.ffc-toggle` component + `AdminUI::render_toggle()`, and the `ffc_update_setting` / `ffc_location_*` endpoints.

## [6.5.3] (2026-05-13) — `87325f0`

### Changed

- Vendored thumbmarkjs 1.8.1 → 1.9.0; jQuery UI theme 1.14.1 → 1.14.2.
- Recruitment CSV import normalizes CPF/RF at parse time (strip punctuation, left-pad; reject over-length with new error codes).

### Fixed

- Form editor: turning the Public CSV Download / Device Fingerprint master toggle OFF didn't persist (hidden `[present]=1` marker).
- Form editor: choosing "No — only Form ID + Hash" for the public CSV CPF gate silently reverted to "Audit".
- Reregistration form: `$.trim is not a function` + empty `fields: {}` under jQuery 4 (native `trim`, relaxed selector).

## [6.5.2] (2026-05-10) — `1d83acc`

### Changed

- User-dashboard god-object split into a panel registry + 8 self-registering files (legacy `ffc-dashboard` handle preserved; table-driven tab dispatch).

## [6.5.1] (2026-05-10) — `e139b61`

### Added

- `AjaxTrait::check_ajax_admin_or()` encoding the "site admin OR delegated `$granular_cap`" gate.

## [6.5.0] (2026-05-10) — `a5d3c71`

### Added

- `maybe_add_perf_indexes` (`idx_created` on three tables); `ReregistrationSubmissionRepository::stream_for_export` (chunked generator); cron `cleanup_stale_export_jobs`.

### Changed

- `AudienceBookingRepository::create` is now atomic (`SELECT … FOR UPDATE` inside a transaction) to prevent double-booking.

## [6.4.1] (2026-05-10) — `c4c629d`

⚠ Security: `GET /forms` + `GET /forms/{id}` previously returned the full `_ffc_form_config` blob to anonymous callers (gate lists, codes, geofence). Rotate generated/validation codes after upgrading if the API was public.

### Added

- `ffc_read_forms_api` capability (Application-Passwords auth); Documentation §19 "REST API Authentication".

### Changed

- `GET /forms*` now require `ffc_read_forms_api` and return a trimmed payload; `limit` clamped at 100; calendar GET routes carry an IP rate-limit circuit breaker (HTTP 429).

## [6.4.0] (2026-05-10) — `fa05a55`

### Added

- Certificates Dashboard (admin) — monthly calendar of forms by GeoFence start date, backed by a new REST endpoint.
- `Csv` / `CsvWriter` / `CsvReader` IO primitives (BOM-once, `;` default, RFC 4180, auto-detect on read).

### Changed

- Eight CSV exporters + two importers migrated to the new primitives (~250 LOC of per-class CSV helpers removed; quoted multi-line cells now parse correctly).

### Fixed

- Audience export templates now emit a UTF-8 BOM; `[ffc_audience]` modals render as overlays again (missing `ffc-shortcode` class).

## [6.3.11] (2026-05-10) — `4d60ac9`

### Added

- `.ffc-shortcode` anchor class on every public wrapper so frontend rules sit at specificity (0,2,0).
- Public CSV Download metabox: colour-coded audit breakdown; Stylelint baseline; `.ffc-initially-hidden` utility.

### Changed

- Public CSV Download metabox disables sub-fields when off (preserving persisted values); CPF mode defaults to `audit` on first enable; whitelist textarea only renders when the persisted mode is `whitelist`.
- Device Fingerprint metabox hard-gates on the global subsystem; `Max per device` defaults to 2; typography variables migrated px → rem.

### Fixed

- Form duplication now copies the eight Public CSV + Device Fingerprint metas; `Undefined array key` warnings on save; `email_hash_rehash` no longer re-surfaces as pending after new submissions.

## [6.3.10] (2026-05-09) — `fc55952`

### Fixed

- Reprint flow now bypasses the device fingerprint limit (reprint detector pre-runs before `check_all`).
- PDF post-download confirmation messages are translatable; pt_BR catalogue back-filled.

### Added

- Friendly "already submitted" notice on `[ffc_form]` (localStorage, soft hint); reprint success card now shows the authentication code.

## [6.3.9] (2026-05-09) — `2454774`

### Changed

- Three CSV exporters now emit `;` (audit log, reregistration, audience templates); audience importer auto-detects `,` vs `;`.

## [6.3.8] (2026-05-08) — `886a3f0`

### Changed

- Device fingerprint disclosure is now a `<details>`/`<summary>` element (short line + expandable specifics).

### Fixed

- `[ffc_csv_download]` CPF field in `audit` mode now correctly marks itself required; form-editor metabox copy aligned with the actual (blocking) behaviour.

## [6.3.7] (2026-05-08) — `096971b`

### Added

- WebView warning banner on `[ffc_form]` / `[ffc_csv_download]` for Android WebView + iOS in-app browsers (open-in-browser / continue-anyway), `ffc-webview-warning.js`.

## [6.3.6] (2026-05-08) — `e778135`

### Fixed

- Certificate downloads no longer fail silently on iOS Safari / Samsung Internet / Android WebView — pre-open the destination tab synchronously inside the click; manual-tap fallback; browser-aware messaging.

## [6.3.5] (2026-05-08) — `3f4ef18`

### Fixed

- `[ffc_csv_download]` info screen showed dates one day early in sub-UTC timezones (anchor with `DateTimeImmutable($date, $tz)` before formatting).

## [6.3.4] (2026-05-07) — `c700bfb`

### Changed

- `[ffc_csv_download]` CPF field wrapped in the same LGPD consent box as `[ffc_form]`.

### Fixed

- `[ffc_csv_download]` CPF field now applies the standard CPF mask + on-blur validation (helper enqueued on the page).

## [6.3.3] (2026-05-07) — `fefe87c`

### Added

- CSV export of the per-form download audit log (CPF decrypted on the fly); "Download audit log (CSV)" button + live count; voluntary logging in `mode = none`.

### Changed

- Audit log stores `cpf_encrypted` instead of the write-only `cpf_hash`; LGPD disclosure updated to "encrypted at rest".

### Removed

- `cpf_hash` from audit-log entries (legacy entries wiped once on upgrade; near-zero install base).

## [6.3.2] (2026-05-07) — `e42f796`

### Added

- 4 new device fingerprint signals (`plugins`, `permissions`, `mediaqueries`, `math`) with their own columns; a dismissable notice suggesting the threshold bump.

### Changed

- Signals schema migration (DB 1.1.0 → 1.2.0); fresh-install default `match_threshold` 5 → 7 (existing installs keep their value); palette/threshold range extended to 13 / 3-12.

## [6.3.1] (2026-05-07) — `779a837`

### Changed

- Device fingerprint collector swapped to the vendored thumbmarkjs (MIT) — server algorithm, schema, contract and LGPD posture unchanged; the library telemetry beacon is unconditionally disabled (grep-tested).

## [6.3.0] (2026-05-07) — `2757589`

Two opt-in anti-fraud features for public form workflows.

### Added

- Per-device submission limit: persistent `ffc_device_id` cookie + up to 9 hashed browser-fingerprint signals with an "N of M" match rule, global + per-form settings, manager bypass, daily cleanup, new `ffc_device_signals` table.
- CPF gate on the public CSV download with five modes (`none`/`audit`/`participants`/`owner`/`whitelist`) + a 100-entry audit log, enforced at all three entry points.

### Changed

- 76 legacy `Utils::debug_log` calls migrated to the per-area `Debug::log_*` system (5 new areas, default off; `debug_log` now a deprecated wrapper).
- FFC role labels now translate on `wp-admin/users.php` (`wp_roles_init` re-applies `__()`).

## [6.2.0] (2026-05-04) — `a7c2955`

Capabilities + roles overhaul: 14 granular admin caps replace blanket `manage_options` gates, 9 pre-built roles wrap them, and an admin-UX scoping layer hides core menus per role.

### Added

- 14 granular admin capabilities (cross-module + per-domain recruitment; umbrella `ffc_manage_recruitment` kept as catch-all).
- 9 roles (Certificate / Self-Scheduling / Audience / Reregistration managers, FFC Operator, + a 4-tier recruitment ladder), registered idempotently so in-place updates self-heal.
- `AdminMenuVisibility` UX layer (hides core menus, blocks direct URLs, prunes the admin bar per role — UX, not the security boundary); `Utils::current_user_can_admin_or()`.

### Changed

- `ffc_certificate_update` reactivated (gates the issued-certificate edit screen); 3 legacy certificate caps renamed to the `ffc_*` namespace (one-time migration); 20+ admin entry points re-gated from `manage_options` to granular caps.

### Removed

- `ffc_reregistration` placeholder cap (never wired; audience-targeting already covers it).

### Fixed

- Recruitment tables + manager role no longer require deactivate/reactivate on in-place updates (hooked at `plugins_loaded`, idempotent); fixed the WP 6.7+ `_load_textdomain_just_in_time` notice; FFC role labels translate on `users.php`.

## [6.1.0] (2026-05-02) — `cbaca70`

Recruitment admin UX parity + dashboard integration + a Preliminary-list visual axis.

### Added

- Full `WP_List_Table` admin (Notices / Adjutancies / Candidates / Reasons) + dedicated edit screens; notice ↔ adjutancy attach UI + REST.
- Semicolon CSV auto-detection; per-adjutancy + per-status configurable badge colors; out-of-order call confirm + reason prompt; 12h public-shortcode cache with admin-write invalidation.
- Preliminary visual statuses + global Reasons catalog (`preview_status` + `preview_reason_id`, new `/recruitment/reasons` REST); optional CSV columns `time_points` + `hab_emebs`; CSV import activity indicator + example download.

### Changed

- Notice status `active` → `definitive` (idempotent migrations); CSV import relocated to the Notice edit screen; `public_columns_config` editor swapped to a checkbox grid (mandatory columns locked); FFC menu positions floated to keep the block contiguous.

### Performance

- Public shortcode candidate fetch: N round-trips → 1 (`get_by_ids`), 30–50% faster on cold cache.

### Fixed

- Out-of-order call detection scoped to the Definitive panel; bulk-call false positives; admin alerts surface `body.message` instead of the raw envelope.

## [6.0.4] (2026-05-01) — `0553f03`

### Added

- Recruitment Candidates tab: CSV import form (notice picker + file → `POST /notices/{id}/import`) with an inline status panel.
- Recruitment Settings tab: editable form via the WP Settings API (email template + public shortcode knobs), replacing the read-only dump.

## [6.0.3] (2026-05-01) — `5f6dca4`

### Fixed

- Capability resolution for multi-role users — `ffc_user` no longer sets FFC caps to `false` (which `array_merge` let overwrite a stronger role's grant).
- `AudienceBookingRepository::count` now honors `start_date` / `end_date` filters (fixes the inflated "upcoming bookings" stat).

### Changed

- Recruitment module i18n: ~59 source-key conversions to English across the public shortcode, candidate-self dashboard, admin page and settings (PT-BR flows via `.po`).

## [6.0.2] (2026-05-01) — `028b4fa`

### Changed

- Recruitment module gets its own top-level menu (`dashicons-groups`, position 28) instead of living under the `ffc_form` CPT; `ffc_form` `menu_name` shortened to "Certificate"; sidebar-visible labels normalized to English source.

## [6.0.1] (2026-05-01) — `7b527dd`

### Fixed

- Recruitment activator: stripped column-level `COMMENT '…'` clauses that `dbDelta` rejected, which had left four recruitment tables un-created on 6.0.0 activation (recover by reactivating).

## [6.0.0] (2026-05-01) — `2cd30e4`

**Recruitment module** for Brazilian public-tender candidate-queue management, plus PHPStan baseline retirement (level 7 → 8, zero errors).

### Added

- Recruitment module: six `ffc_recruitment_*` tables + repositories, atomic CSV importer, two state machines (notice + classification with reopen-freeze), call/promotion/delete services, PCD HMAC hasher, masked-placeholder email dispatcher, 21-route admin REST surface, `[ffc_recruitment_queue]` + `[ffc_recruitment_my_calls]` shortcodes, the Recrutamento submenu, `ffc_manage_recruitment` cap + `ffc_recruitment_manager` role, `uninstall.php` cleanup.
- "Move to form…" bulk action on the Submissions list (identifier-based conflict detection, audited).
- PHPStan repository row aliases; `CONTRIBUTING.md` static-analysis conventions section.

### Changed

- PHPStan level 7 → 8, 231-entry baseline retired (typed every repository row shape, removed 20 `@deprecated Utils::*` shims across 150+ call sites).
- `phpstan-stubs.php` parses `FFC_VERSION` from the plugin file; `AudienceEnvironmentRepository::get_working_hours` typed; `ActivityLog` gains `submission_moved`.

### Fixed

- Null narrowing across 5 PHPStan level-8 hotspots.

### Removed

- `phpstan-baseline.neon`, 20 `@deprecated Utils::*` shims + `PHONE_REGEX`, the `CsvExporter::handle_export_request` stub, two "remove in next major" legacy fallbacks, and three dead properties.

### Security

- Move-action bulk endpoint gated by `manage_options` + nonce (no new capability surface).

---

## [5.4.1] (2026-04-24) — `fbcb4cd`

Certificate HTML editor gains CodeMirror syntax highlighting with distinct coloring for HTML tags and `{{placeholder}}` tokens, plus a three-option `Code Editor Theme` setting (Auto / Light / Dark, dark by default on fresh installs) with a VS-Code-Dark+-inspired palette; the email body moves to a lightweight visual editor (`wp_editor` teeny); the global TinyMCE placeholder-protection filter is scoped to the plugin's post type so it no longer touches unrelated admin screens; a new per-calendar admin-bypass toggle replaces the hardcoded all-or-nothing bypass for self-scheduling; and the `[ffc_verification]` result card header stops rendering with the admin preview modal's dark slate background.

### Added

- **CodeMirror integration for the certificate HTML editor.** `ffc_pdf_layout` now renders through WordPress's built-in CodeMirror via `wp_enqueue_code_editor` — tags, attributes and strings get syntax highlighting, line numbers and auto-closing bracket helpers, configured with lint disabled so valid certificate templates don't get flagged. A new JS module (`assets/js/ffc-admin-code-editor.js`) initializes the editor on top of the existing `<textarea>`, adds a regex overlay (`.cm-ffc-placeholder-token`) that paints `{{placeholder}}` tokens in a separate color from HTML markup, syncs the underlying textarea on every change, and saves before submit. The DOM textarea is preserved, so form submission, save pipeline (`FormEditorSaveHandler::save_form_data`), stored HTML and downstream PDF generation are **byte-for-byte identical** to the previous plain-textarea path.
- **Syntax Highlighting profile notice.** When the user has disabled "Syntax Highlighting" in their WordPress profile, `wp_enqueue_code_editor` returns `false`; the initializer then renders a subtle `<p class="description">` under the textarea linking to `profile.php#syntax_highlighting` with the string _"For the best HTML template experience, enable 'Syntax Highlighting' in your profile."_ The textarea continues to work unchanged.
- **Email body upgraded to `wp_editor` teeny mode.** The `email_body` field (metabox 4 — Email Configuration) moves from a plain `<textarea>` to a minimal visual editor: bold, italic, underline, bullet/numbered lists, link/unlink, undo/redo. `media_buttons => false`, `teeny => true`, custom quicktags. Placeholders such as `{{auth_code}}` and `{{name}}` remain protected thanks to the `tiny_mce_before_init` filter (see _Changed_).
- **Per-calendar admin-bypass toggle for self-scheduling.** A new `Admin Bypass` checkbox in the Booking Rules metabox of each `ffc_self_scheduling` calendar lets the author decide whether users with `manage_options` / `ffc_scheduling_bypass` skip that calendar's booking restrictions (advance-booking window, past-date guard, blocked dates, working hours, daily/interval limits, and cancellation deadline/allowance). Slot capacity is always enforced. The toggle is stored in `_ffc_self_scheduling_config['admin_bypass']`. Backward compatibility: calendars saved before 5.4.1 have no stored key and continue to behave as before (bypass on).
- **Code Editor Theme setting (Advanced tab).** New select field `ffc_settings['code_editor_theme']` with values `auto` / `light` / `dark`. `dark` is the default on fresh installs (registered in `Settings::get_default_settings`); `auto` mirrors the plugin's admin `dark_mode` option (General tab). Lives in a new "Editor Preferences" card in the Advanced settings tab, right after the Activity Log card and before Debug Settings.
- **VS-Code-Dark+-inspired theme for the CodeMirror editor.** New stylesheet `assets/css/ffc-code-editor-dark.css` (+ minified build) registers the `.cm-s-ffc-dark` CodeMirror theme — background `#1e1e1e`, foreground `#d4d4d4`, tags `#569cd6`, attributes `#9cdcfe`, strings `#ce9178`, comments `#6a9955`, selection `#264f78`, matching-bracket `#0e639c`. Placeholder overlay (`.cm-ffc-placeholder-token`) flips to gold-on-dark (`#dcdcaa` on `#1e1e1e`) so `{{foo}}` tokens remain legible on the dark canvas. Theme CSS is only enqueued when the resolved theme is `dark`; light stays on WordPress's default CodeMirror styling with zero extra payload.
- Unit tests: `AdminClassTest` grows from 9 → 12 tests (+3) covering `maybe_register_tinymce_placeholder_filter` across three screen states — `ffc_form`, other post type, and null screen. `CalendarRepositoryTest` gains 4 new tests covering the per-calendar `admin_bypass` consumption path. `AdminAssetsManagerTest` gains 8 new tests covering `resolve_code_editor_theme` across all branches — default-on-fresh-install, explicit light/dark, `auto` following `dark_mode on/off/auto`, invalid stored values, and corrupt (non-array) `ffc_settings` option.

### Changed

- **`tiny_mce_before_init` filter scoped to the `ffc_form` screen.** `Admin::configure_tinymce_placeholders` is no longer attached from the constructor on every admin page load. A new `Admin::maybe_register_tinymce_placeholder_filter` runs on `admin_head`, checks `get_current_screen->post_type === 'ffc_form'`, and only then registers the filter at priority 999. Other admin screens (Classic Editor posts, third-party plugin configuration pages, etc.) are no longer mutated by the plugin's TinyMCE init overrides. `configure_tinymce_placeholders` itself is unchanged — same `noneditable_regexp`, `noneditable_class`, `entity_encoding = raw`, and `protect` array.
- **`email_body` sanitization hardened.** `FormEditorSaveHandler::save_form_data` now runs `email_body` through `wp_kses_post` — the canonical WordPress post-content allowlist — instead of the generic plugin `Utils::get_allowed_html_tags` allowlist. This aligns the sanitizer with the field's new authoring surface (`wp_editor` teeny) and matches what WordPress itself allows in post bodies. `pdf_layout` keeps its broader allowlist since it is a certificate template authored by the admin.
- **CSS placeholder block rewritten for its new actual surface.** `assets/css/ffc-admin.css` now carries a single `.ffc-placeholder` rule (used inside TinyMCE when the teeny editor runs for `email_body`) plus new CodeMirror-aware selectors: `.ffc-code-editor-wrapper.CodeMirror` (bordered wrapper, 260px min-height, monospace font), `.ffc-code-editor-wrapper.cm-ffc-placeholder-token` (colored placeholder tokens inside the code editor), and `.ffc-code-editor-notice` (styling for the profile-option notice).
- **Assets manager gains a code-editor enqueue path.** `AdminAssetsManager::enqueue_form_editor_code_editor` only fires on the `ffc_form` post edit screen; it calls `wp_enqueue_code_editor` with HTML mode and forwards the result (plus i18n strings and the profile URL) to the JS initializer via `wp_localize_script( 'ffc-admin-code-editor', 'ffcCodeEditor', … )`. It now also resolves the effective code-editor theme via the new `AdminAssetsManager::resolve_code_editor_theme` static helper, injects `theme: 'ffc-dark'` into the CodeMirror config and enqueues `ffc-code-editor-dark.css` only when the resolved theme is dark. The JS initializer tags the wrapper with `ffc-code-editor-theme-<theme>` so theme-scoped CSS (border override, etc.) can target it.
- **`CalendarRepository::userHasSchedulingBypass` accepts a calendar context.** The method gains an optional second parameter, `?int $calendar_post_id`. With a non-null id it consults `_ffc_self_scheduling_config['admin_bypass']` and returns `false` when the toggle is off (even for admins). With a null id it falls back to capability-only behavior, preserving every existing caller exactly. Booking-relevant call sites — `AppointmentValidator::validate`, `AppointmentHandler::get_available_slots`, `AppointmentHandler::cancel_appointment`, and `SelfSchedulingShortcode` — now forward the calendar's `post_id` so the toggle actually takes effect during booking flows. Audience/REST admin read paths continue to pass `null` and retain their historical behavior.

### Fixed

- **`[ffc_verification]` result card header rendered with the admin preview modal's dark slate background.** The verification success cards use `.ffc-preview-header` for their blue-gradient banner. A later CSS block — meant only for the admin certificate preview modal — defined a second, unscoped `.ffc-preview-header` rule with `background: #1d2327` and `display: flex; justify-content: space-between`. Cascade resolved the admin rule as the winner on the frontend. Fix: the admin rule is now scoped under `#ffc-preview-modal` so it cannot leak to public pages.

### Removed

- **Orphan TinyMCE-only CSS selector.** `.mce-content-body.ffc-placeholder` is gone — it was only reachable inside an active `wp_editor` context, which the plugin did not render anywhere. The rule's properties merged into the general `.ffc-placeholder` selector now that there is a real TinyMCE target (the email body).
- **Legacy constructor registration of the TinyMCE filter.** The unconditional `add_filter( 'tiny_mce_before_init', … )` call in `Admin::__construct` is replaced by the screen-scoped registrar (see _Changed_). Behavior inside the `ffc_form` editor is unchanged; behavior elsewhere in the admin is simply not touched anymore.
- **Hardcoded all-or-nothing admin bypass for self-scheduling.** The previous `userHasSchedulingBypass` granted bypass to any admin unconditionally, with no way for a calendar author to opt out. Replaced by the per-calendar toggle (see _Added_ / _Changed_); authors that want the old behavior simply leave the checkbox on, which is also the default for legacy calendars migrating into 5.4.1.

### Security

- **MEDIUM (XSS hardening) — email body.** `wp_kses_post` replaces the plugin-specific `wp_kses( …, $allowed_html )` on `email_body` save. Scripts, forms, iframes (and any other tag outside the WordPress post-content allowlist) are stripped on save; rich-text formatting the admin authors in the new visual editor (formatting, links, lists) is preserved.
- **Reduced filter footprint.** The `tiny_mce_before_init` override, which set `entity_encoding = raw` globally, no longer runs on screens unrelated to `ffc_form`. Other plugins' TinyMCE initialization is no longer mutated by a filter installed for a different feature.

### Documentation

- **Historical changelog reconciliation.** Cross-checked the CHANGELOG entries for releases 1.0.0 through 2.9.1 against forensic evidence from twelve archived `wp-ffcertificate-<date>.zip` snapshots (2025-12-12 through 2026-02-02). The pre-existing entries had a systematic ~2-3 week forward drift on the early-version dates (e.g. CHANGELOG dated 1.0.0 to 2025-12-14, but the 12/12/2025 snapshot already carried header version 1.0.7 — making the 14/12/2025 release date for 1.0.0 chronologically impossible). Adjusted dates for 1.0.0, 1.5.0, 2.0.0–2.5.0, 2.6.0, 2.7.0, 2.8.0, 2.9.0, and 2.9.1 to match either the dated entries inside the 4.0.0 zip's `readme.txt` (which gave authoritative dates for 2.6.0–2.9.1 = 2025-12-28/29) or chronologically-consistent approximations bounded by the zip-snapshot evidence (for 1.0.0–2.5.0). Dates from 2.10.0 onward were already coherent with the forensic record and remain unchanged.
- **CHANGELOG content enrichment.**
  - `## 2.5.0 — Internal improvements` with reconstructed content
  - `## 2.9.x development cycle (2026-01-03 → 2026-01-14)` section documenting the dev-only
  - `## 1.0.7 (~2025-12-12)` **Forensic 1.0.7 entry added.** reconstructed from the `wp-ffcertificate_12_12_2025.zip` snapshot.
  - `## 3.0.0 (2026-01-20)` **Claude / AI-assistance attribution.** Added a footnote.
- New `AI-assisted contributions` section in `CONTRIBUTING.md` documents the workflow conventions.
- **`readme.txt` trimmed to the last three releases.**
- **Version-heading format normalized** to match the format used by the other ~88 version entries.
- **Sub-section taxonomy normalized to strict Keep-a-Changelog.** All ~93 historical entries

---

## [5.4.0] (2026-04-23) — `a1f9365`

Encryption and privacy hardening across the user-data surface (centralized sensitive-field policy, payload-driven activity log encryption, auditable decrypt failures, no-leak dual-storage fix), plus the accumulated security audit (Tier 1 + Tier 2), CSV download intermediate screen, and a performance pass for admin submissions at scale.

### Added

- **Centralized sensitive-field policy** via `FreeFormCertificate\Core\SensitiveFieldRegistry` — single declarative map of which fields are encrypted and hashed per write context. Consumed by `SubmissionHandler` and `AppointmentRepository`; replaces three hard-coded lists. Exposes `encrypt_fields`, `plaintext_keys`, `universal_sensitive_keys`, `dynamic_sensitive_keys` (reads `wp_ffc_custom_fields.is_sensitive = 1`, cached), and recursive `contains_sensitive` for payload inspection.
- **`UserProfileFieldMap`** — declarative per-field descriptor for user-profile fields. Each entry names its storage layer (`wp_users`, `ffc_user_profiles`, `wp_usermeta`), whether the value is sensitive, whether it is hashable for lookup, and optional mirror targets (e.g. `display_name` writes back to `wp_users.display_name` after the profile-table write). Sibling of `SensitiveFieldRegistry`; the registry is per write context (submission vs appointment), the map is per user field.
- **`ViewPolicy` enum** — `FULL`, `MASKED`, `HASHED_ONLY`. Declares how a caller wants sensitive fields rendered on read. The service does not elevate privileges; callers validate capability (`current_user_can('manage_options')` or similar) before asking for `FULL`.
- **`UserProfileService::read` / `::write`** — single entry point consolidating reads and writes across the three storage layers, with transparent encryption and hashing for sensitive fields. `read` honours `ViewPolicy`, returns empty arrays for unknown users or empty field lists, and silently drops unregistered field keys. `write` routes each field to its declared storage, encrypts + hashes sensitive values, and applies mirror targets last. A `FULL` read that touches a sensitive field emits one `user_profile_read_full` audit entry carrying only the requester, the target user id, and the field list — never the values. The `$extra_descriptors` parameter lets callers write fields outside the static map (used by the reregistration flow); overrides are per-call and cleared via try/finally so they cannot leak between requests.
- **`email_hash_rehash` migration** — batched, idempotent, cursor-based. Walks `wp_ffc_submissions` and `wp_ffc_self_scheduling_appointments`, decrypts `email_encrypted`, recomputes the salted hash and writes only when it differs.
- **`activity_log_clear_plaintext` migration** — batched UPDATE that NULLs `context` on activity log rows that already hold a ciphertext, closing the dual-storage leak on historical data.
- **CSV download intermediate screen** — after hash validation and before the actual download, an info screen shows form configuration (restrictions, dates, geolocation, quiz, quota) so the operator understands the form context. The download button is only enabled after the form has ended; a certificate preview button is available before the collection period begins.
- **Public CSV sync-export row cap** — new `public_csv_sync_max_rows` setting (Advanced tab, default 2000, range 100–10000). Public CSV downloads exceeding the cap are refused on the synchronous no-JS path and must use the AJAX batched flow, protecting shared hosting from execution-time timeouts on large exports.
- Test coverage: **3234 → 3485 tests** (+251), **8783 assertions**, 0 failures. New suites: `SensitiveFieldPolicyTest`, `SensitiveFieldRegistryTest`, `DecryptFailureLoggingTest`, `UserProfileFieldMapTest`, `UserProfileServiceTest`, `CustomFieldValidatorTest`, `AutoloaderTest`, `UserContextTraitTest`, `MigrationDynamicReregFieldsTest`, `ReregistrationStandardFieldsSeederTest`, `AbstractRepositoryTest`, `GeofenceTest`, `FormListColumnsTest`. Extended coverage for `UserManager::update_extended_profile` / `get_extended_profile`.

### Changed

- **Activity log encryption gate** switched from a hard-coded action whitelist (`submission_created`, `data_accessed`, `data_modified`, `admin_searched`, `encryption_migration_batch`) to payload inspection via `SensitiveFieldRegistry::contains_sensitive`. Any action carrying a sensitive field (including nested payloads like `{fields: {cpf:...}}`) is encrypted automatically; actions with trivial payloads are no longer wrapped in a meaningless ciphertext.
- **`ActivityLog::log`** no longer dual-stores `context` plaintext alongside its ciphertext. Sensitive rows now NULL the plaintext column; `ActivityLogQuery::resolve_context` decrypts on demand on read.
- **`SubmissionHandler` and `AppointmentRepository`** replaced their inline encryption blocks with a single `SensitiveFieldRegistry::encrypt_fields` call plus a `plaintext_keys` strip, preserving the same output shape.
- **`UserManager::update_profile`** is now a thin facade over `UserProfileService::write`. The legacy `sanitize_text_field` + `wp_json_encode('preferences')` pre-processing stays at the facade layer for backward compatibility; routing, upsert and the `display_name → wp_users` mirror move into the service. The `SHOW TABLES LIKE` short-circuit is gone — callers land in the service even when the plugin is mid-activation, which matches every install reachable from admin screens.
- **`UserManager::update_extended_profile`** now routes every key through `UserProfileService::write`. Keys registered in `UserProfileFieldMap` carry their own descriptor; arbitrary reregistration keys get an inline descriptor built at the facade layer and are treated by the service like any other usermeta-backed field. The legacy inline encrypt/hash path that used to live here is gone. Behavior improvement: clearing a sensitive field now also deletes the sibling `*_hash` meta row so a stale hash never outlives the ciphertext.
- **`Encryption::decrypt`** split into a public wrapper and a private helper. The wrapper emits a `decrypt_failure` WARNING to `ActivityLog` whenever a non-empty ciphertext resolves to null, with metadata-only context (`ciphertext_length`, `v2_prefix`). Callers continue to receive `null`; silence is no longer opaque.
- **`SubmissionRestController::decrypt_submission_data`** replaced an inert `try/catch` (decrypt never throws) with an explicit null-check, avoiding the PHP 8.1+ `json_decode(null)` deprecation.
- **Residual `class_exists ? Encryption::hash: hash('sha256')` fallbacks** removed from `SelfSchedulingAppointmentHandler` and `SubmissionRepository::hash`. Encryption is a runtime dependency; the raw-`sha256` branch was unreachable and would have produced hashes no other call site could match.
- **Encryption envelope** now produces authenticated **v2 ciphertexts** (encrypt-then-MAC, HMAC-SHA256 with a separately derived MAC key); legacy v1 ciphertexts remain decryptable.
- Perf: `SubmissionRepository::countByStatus` cached in a 5-minute transient — eliminates the `COUNT(*) … GROUP BY status` scan on every admin submissions page load; cache invalidated on every write that can move a row between statuses.
- Perf: composite index `(form_id, status, submission_date)` on `ffc_submissions` — covers the common admin list pattern (filter by form + status, sort by `submission_date` DESC).
- Perf: activity log cleanup falls back to `admin_init` (transient-gated, 24h) for low-traffic shared hosting where WP-Cron misses scheduled runs.
- Perf: `findPaginated` search — `magic_token` uses prefix match (`'term%'`) so the B-tree index helps; the unencrypted-`data` LIKE fallback is skipped for search terms shorter than 4 characters.
- Code quality: PHPCS **1232 → 0 violations** (105× short-ternary expansion, 152× file header normalization, 142× class-docblock backfill, 299× resolved docblock violations). PHPStan level 7: **0 errors**.
- `SECURITY.md` — supported-versions table updated to mark `5.4.x` as supported (was stuck on `5.1.x`).
- `CONTRIBUTING.md` — Branches section no longer mandates the AI-specific `claude/*` prefix for human contributors; Releasing section now documents the `[Unreleased] → [X.Y.Z]` flow (rename, insert fresh `[Unreleased]`, bump `Version:` + `FFC_VERSION` + `readme.txt` Stable tag in one step) to match the convention used from 5.4.0 onward.
- **Settings admin — visual consistency pass.** Active nav tab now renders on a white panel (`--ffc-bg-card`) instead of a blue underline on the gray strip. Every section `<h2>` across General, SMTP, Cache, Rate Limit, Geolocation, User Access, Advanced and Migrations now carries an `ffc-icon-*` class so the divider line + icon pattern is uniform. Sub-sections that shared a card became their own cards (QR Code Defaults / QR Code Cache / Debug Settings). Drops one orphaned spacing helper.
- **Geofence debug toggle deduplicated.** The Geolocation tab's standalone `debug_enabled` checkbox (stored at `ffc_geolocation_settings.debug_enabled`) overlapped with the Advanced tab's `debug_geofence` toggle (stored at `ffc_settings.debug_geofence`, gated through `Debug::AREA_GEOFENCE`). Both surfaces — frontend `console.log` in `ffc-geofence-frontend.js` and backend `error_log` from `Debug::log_geofence` / `IpGeolocation::debug_log` — now read the single Advanced-tab setting. The Geolocation tab "Debug Mode" card was removed; `Geofence::get_frontend_config` and `Frontend::enqueue_geofence_assets` switched to `Debug::is_enabled( Debug::AREA_GEOFENCE )`; `IpGeolocation::debug_log` dropped its own gate and delegates to `Debug::log_geofence`. Stale `debug_enabled` values left over in `ffc_geolocation_settings` are simply ignored (no migration needed for a debug-only flag that defaults off).
- **CSS dead-code sweep.** Removed 21 unused `.ffc-*` classes that had no references in PHP, JS, HTML templates, or other CSS — surfaced via a full audit of the 19 source files in `assets/css/`. Drops `.ffc-info-box`, `.ffc-qr-info-box` + `.ffc-qr-note`, and eleven layout/spacing/text utilities (`.ffc-inline-flex`, `.ffc-items-center`, `.ffc-gap-*`, `.ffc-mb-0`, etc.). Builds verify the result is byte-identical to the prior bundle minus the dropped selectors.

### Fixed

- **Email hash divergence between tables** — same email produced different `email_hash` values in `wp_ffc_submissions` (salted) and `wp_ffc_self_scheduling_appointments` (raw SHA-256). Cross-entity lookups were silently broken. Unified on `Encryption::hash($email)`; migration `email_hash_rehash` rewrites legacy hashes.
- **`AppointmentRepository::findByCpfRf`** never matched its own writes — `createAppointment` stored a salted hash, `findByCpfRf` queried with a raw SHA-256. Now both read and write use `Encryption::hash`.
- **`UserCleanup::handle_email_change`** reindexed submission `email_hash` with raw SHA-256, overwriting correct salted hashes on every email change. Now mirrors `SubmissionHandler`.
- **`SecurityService::verify_simple_captcha`** rejected the valid answer `0`: `empty('0')` is true in PHP, so subtractions where `n1 === n2` (`answer = 0`) always failed. Now uses `'' === trim($answer)`; hash comparison upgraded to `hash_equals` for timing safety.
- **Silent decrypt failures** in `Encryption::decrypt` now emit an `ActivityLog` warning. `decrypt(...) ?? ''` fallbacks in reprint detector / user manager / CSV exporter remain legitimate, but the failure itself is no longer invisible to auditors.
- Activity log disabled-notice link pointed to `Settings > General`; the toggle lives in `Settings > Advanced`. Link and label updated.

### Security

- **CRITICAL** (LGPD) — Cross-table hash consistency (see _Fixed_ above): dedup and reconciliation between submissions, appointments and user profile now works reliably.
- **HIGH** (LGPD) — Activity log no longer dual-stores `context` plaintext alongside `context_encrypted` for sensitive rows. The ciphertext is authoritative; reads transparently decrypt.
- **HIGH** — `AbstractRepository::build_where_clause` now uses the `%i` identifier placeholder and a `get_allowed_where_columns` allowlist to prevent column-name SQL injection.
- **HIGH** — Timing-safe token comparison via `hash_equals` in the appointment receipt handler.
- **HIGH** (XSS) — Escape user-supplied values (description, cancellation reason, etc.) in audience email templates to prevent stored XSS in recipient mail clients.
- **MEDIUM** (LGPD) — Decrypt failures auditable via `ActivityLog::log('decrypt_failure', WARNING)`, with metadata-only context that cannot leak plaintext nor recurse into the encryption gate.
- **MEDIUM** — `SubmissionRestController` admin endpoints restricted to `manage_options` (was `edit_posts`, accessible to Authors who shouldn't see PII).
- **MEDIUM** — `UserProfileRestController::update_user_profile` sanitizes user input via `sanitize_text_field` + `wp_unslash`.
- **MEDIUM** — `AudienceRepository::get_user_audiences` replaces inline `$id_list` interpolation with parameterized placeholders.
- **MEDIUM** (XSS) — Escape `id`, form title, and action labels in `SubmissionsList::column_default` / `render_actions`; inline-literal output for `$required_attr` in the frontend field renderer; escape `{{form_title}}` before `str_replace` into PDF layouts; wrap admin-configured email body text with `wp_kses_post`.
- **MEDIUM** (crypto) — Encryption produces authenticated v2 ciphertexts (encrypt-then-MAC, HMAC-SHA256 with a separately-derived MAC key); legacy v1 ciphertexts remain decryptable.
- **MEDIUM** (transport) — `IpGeolocation` HTTPS opt-in via `ffc_ipapi_use_https` filter; `sslverify` now follows the scheme.
- **MEDIUM** (IP spoofing) — `RateLimiter` only trusts `REMOTE_ADDR` by default; forwarded headers are gated behind `ffc_trust_forwarded_headers`.
- **MEDIUM** (data leak) — `MagicLinkHelper` replaces `chart.googleapis.com` QR URL with the local `QRCodeGenerator` so magic tokens never reach a third-party service.
- **MEDIUM** (path traversal) — `PdfGenerator` validates the receipt template path is inside the plugin or theme directories; `ReregistrationEmailHandler` allowlists template names; `AudienceNotificationHandler` moves temp ICS files from the public uploads dir to the system temp dir with try/finally cleanup.
- **LOW** — Removed `$e->getMessage` from 5 client-facing error responses across 4 REST controllers to prevent information disclosure.
- **LOW** — `Admin::redirect_with_msg` builds the redirect target from `page`/`post_type` instead of `REQUEST_URI`; removed duplicate `wp_nonce_field` in `FormEditorMetaboxRenderer`; escaped return values in `VerificationResponseRenderer::format_field_value`; `ReprintDetector` hash-column query uses `%i`; `receipt-handler` escapes `bloginfo` with `esc_html` / `esc_url`.
- **LOW** (LGPD) — Hash PII identifiers before logging in `RateLimiter`; hash IP in `IpGeolocation` debug logs.

---

## [5.3.0] (2026-04-17) — `8679cf5`

Full-page cache compatibility, per-form captcha isolation, and CI pipeline improvements.

### Added

- **Full-page cache compatibility** — forms and calendars now work correctly with LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache. Self-scheduling shortcodes with business-hours restrictions send `DONOTCACHEPAGE` + `nocache_headers` to prevent stale "closed" messages. Audience shortcodes for logged-in users prevent cached cross-user content leakage
- **Dynamic Fragments geofence refresh** — the AJAX endpoint now accepts `form_ids[]` and returns fresh geofence date/time configs, so cached pages always display up-to-date availability windows after admin changes
- **Automatic cache purge on save** — `FormCache::purge_page_cache` finds pages embedding a saved form or calendar and purges them from LiteSpeed, WP Rocket, W3TC, and WP Super Cache. Called on both `save_post_ffc_form` and `save_post_ffc_self_scheduling`
- **CSV Download Page URL setting** — new field on the General settings tab for configuring the public CSV download page URL
- **Search forms by ID** — the admin forms list table (`edit.php?post_type=ffc_form`) now supports searching by numeric post ID

### Changed

- **CustomFieldValidator extraction** — validation logic extracted from `CustomFieldRepository` into a dedicated `CustomFieldValidator` class for single-responsibility and testability
- **In-plugin documentation expansion** — expanded the Documentation settings tab with additional sections covering all shortcodes, settings, and features
- Extract reusable composite action `.github/actions/setup-composer` for PHP + Composer setup
- Add Dependabot auto-merge for patch and minor dependency updates
- Promote PHPCS from advisory to gating — PRs must pass WPCS on changed files
- Promote PHPStan from level 6 to **level 7**
- Re-introduce coverage with pcov, scoped to `includes/`, uploaded to Coveralls
- Auto-fix ~83k PHPCS violations via PHPCBF
- Annotate 223 PreparedSQL + NonceVerification false positives
- PHPCS mechanical fixes + PSR-4 suppressions
- Resolve remaining WPCS errors in cache-related files (file docblocks, class docblocks, short ternary operators, missing `@param` tags)

### Fixed

- **Same captcha on all forms** — when multiple forms exist on a cached page, Dynamic Fragments now generates a unique math captcha per form instead of applying a single captcha to all forms
- **PHPUnit test failures** — added missing mocks for `nocache_headers` and `get_posts` in `AudienceShortcodeTest` and `FormCacheTest` after cache compatibility changes
- **Minified assets out of sync** — regenerated `ffc-dynamic-fragments.min.js` with `--source-map` to match the `npm run build` output

### Removed

- Remove duplicate `push: main` trigger from CI and Assets workflows — each PR merge no longer runs the full suite twice
- Remove CodeQL workflow (not applicable to PHP plugin)

---

## [5.2.0] (2026-04-15) — `208ca56`

Raise minimum PHP requirement from 7.4 to 8.1. PHP 7.4 reached end-of-life on 2022-11-28 and PHP 8.0 on 2023-11-26; both are unsupported. The previous lockfile was also resolving `doctrine/instantiator` 2.1.0 — which requires PHP 8.4 — silently breaking `composer install` on PHP 7.4/8.1/8.3 runners.

### Added

- **Named Geofence Locations** — new `GeofenceLocationRegistry` CRUD class stores reusable geofence locations as a WordPress option (`ffc_geofence_locations`), each with name, lat/lng, radius, and per-location default GPS / default IP flags (mutually exclusive). Replaces the legacy "Default Geofencing Areas" textarea on the Geolocation settings tab with a full CRUD table (add, edit, delete with nonce-protected actions)
- Form editor geofence metabox now offers a **radio toggle** (Registered Locations / Custom Coordinates) for both GPS and IP area sources, with a **multi-select dropdown** when "Registered Locations" is selected. Auto-draft forms pre-select the default GPS/IP locations from the registry
- `Geofence::resolve_areas_text` helper transparently resolves location IDs to coordinate text at runtime — existing forms with `geo_area_source = 'custom'` (or missing key) continue to work without migration
- **CSV Downloads column** on the forms list table — shows the public download count (with quota when set) for forms with CSV download enabled
- **GeofenceLocationRegistryTest** — 24 tests covering `get_all`, `get_by_id`, `get_by_ids`, `save` (including default flag mutual exclusivity), sanitization (lat/lng clamping, negative values, radius default, name truncation), `delete`, `get_default_gps`/`get_default_ip`, and `resolve_to_areas_text`
- **GeofenceDatetimeValidationTest** — 12 tests covering daily + span mode datetime validation, all branch paths including edge cases
- **GeofenceGeolocationTest** — 17 tests covering `parse_areas`, `validate_geolocation` with IP fallback scenarios, `has_form_expired_by_days`
- **GeofenceFrontendConfigTest** — 10 tests covering `get_form_config` boolean casting, `get_frontend_config` with admin bypass / partial bypass / regular user
- **LoaderTest** — 6 tests covering constructor hook registration, frontend asset registration and localization

### Changed

- **TabGeolocationTest** — replaced `test_save_settings_saves_main_geo_areas_to_ffc_settings` with `test_save_settings_calls_save_locations` for new registry-based save behavior
- **3154 → 3234 tests** (+80) with all 7646 assertions green
> **BREAKING:** Minimum PHP bumped from **7.4 → 8.1**. Update your server before upgrading. `Plugin Name` header, `FFC_MIN_PHP_VERSION`, `composer.json#require.php` and `readme.txt#Requires PHP` all updated.
- `composer.json#config.platform.php` pinned to `"8.1"` so the lockfile resolves to versions compatible with the declared minimum regardless of the developer's local PHP version.
- `composer.lock` regenerated under PHP 8.1 platform; `doctrine/instantiator` now resolves to `2.0.0` (compatible with PHP ^8.1) instead of `2.1.0` (which required PHP ^8.4).
- CI matrix now covers PHP **8.1, 8.2, 8.3, 8.4** (added 8.2, removed 7.4).
- Zero PHPStan level 6 errors — cleared 4 findings exposed by newer `php-stubs/wordpress-stubs` (v6.9.1) and `szepeviktor/phpstan-wordpress` (v2.0.3):
  - `AdminActivityLogPage::output_csv` PHPDoc: `array<int, array<string, mixed>> $rows` → `array<int, array<array-key, mixed>> $rows` (the method iterates and passes values directly to `fputcsv` without accessing keys by name; the caller builds rows with positional int keys).
  - `UserAudienceRestController::build_joinable_node` PHPDoc: added `array<string, mixed>` value type to `@param $node` and `@return`.
  - `ReregistrationAdmin` details markup: removed dead `|| $formatted === null` branch — `FichaGenerator::format_field_value` returns a non-nullable `string`.

### Fixed

- **Geofence frontend config flags always false** — `get_frontend_config` compared already-cast PHP booleans against the string `'1'` (`'1' === true` is always `false` in strict comparison), causing the JS frontend to never enforce datetime or geolocation restrictions. Backend validation was unaffected. Now compares boolean values directly
- **Submission count link** on forms list — used `form_id` instead of `filter_form_id`, so clicking the count did not filter the submissions list

---

## [5.1.0] (2026-04-11) — `e6aa8d3`

Public CSV download feature: form organizers without WordPress admin access can now retrieve the submissions CSV of a specific form via a revocable per-form hash, gated by form expiration and a configurable download quota. No new dependencies and no schema changes.

### Added

- New `[ffc_csv_download]` shortcode — public page where visitors enter a form ID + hash and receive the submissions CSV as a direct download
- New `PublicCsvDownload` handler on `admin-post(_nopriv)_ffc_public_csv_download` — validates nonce, honeypot, CAPTCHA, per-IP rate limit, form-level enable flag, hash equality, geofence expiration, and per-form download quota before streaming the file
- New `PublicCsvExporter` with AJAX batched 3-step export (start → batch ×N → download) matching the admin `CsvExporter` architecture — prevents memory exhaustion and webserver timeouts on large datasets; column layout mirrors the admin export so both downloads are interchangeable. Synchronous streaming preserved as a no-JS fallback
- New `Geofence::get_form_end_timestamp` and `Geofence::has_form_expired` helpers — the public CSV download is only released after the form's configured end date/time
- New "Public CSV Download" metabox on the form editor — toggle, read-only hash with regenerate control, download counter, reset button, per-form quota override, and a ready-to-share URL preview
- Advanced settings tab now exposes `public_csv_default_limit` — default quota suggested to the admin when enabling the feature on a new form (default: 1)
- Counter is incremented *before* the stream starts to prevent race conditions between concurrent requests
- **GeofenceFormExpirationTest** — 12 tests covering `get_form_end_timestamp` (null on empty/invalid meta, trims whitespace, defaults `time_end` to `23:59:59`, respects `wp_timezone`) and `has_form_expired` (past vs. future, end-of-day default)
- **PublicCsvExporterTest** — 15 tests locking the CSV column layout (15 fixed + 3 edit + N dynamic), fixed-column value mapping, consent yes/no rendering, deleted-form placeholder, dynamic key ordering, RF-only rows, batch-size constants, and JOB_TTL
- **PublicCsvDownloadTest** — 28 tests covering constants, shortcode rendering (nonce, form fields, honeypot, CAPTCHA, URL prefill, flash messages), the 12 failure branches of the validation flow, the happy-path counter-increment observable effect, 8 direct `validate_form_access` unit tests, and AJAX hook registration verification
- UX: `ffc-frontend.css` is now auto-enqueued on pages containing the `[ffc_csv_download]` shortcode (matching how `[ffc_form]` / `[ffc_verification]` already trigger the stylesheet); new `ffc-csv-download.js` is enqueued only on CSV download pages
- New **"Obsolete Shortcode Cleanup"** section on the Data Migrations tab (`ffc_form&page=ffc-settings&tab=migrations`) — scans published posts, pages and reusable blocks (`wp_block`) for embedded `[ffc_form id="..."]` shortcodes pointing at forms whose end date is more than `N` days in the past, and removes those obsolete shortcodes from `post_content`. Configurable grace window (default: **90 days**, clamp 1-3650), admin-only (`manage_options`), nonce-protected
- New `ObsoleteShortcodeCleaner` service (`includes/migrations/class-ffc-obsolete-shortcode-cleaner.php`) with `find_expired_form_ids`, `scan_posts_for_expired_forms`, `extract_form_ids`, `strip_shortcodes_from_content`, `remove_shortcodes_from_post` and a `run($days, ['dry_run' => bool])` pipeline. Handles both Classic editor `[ffc_form id="N"]` and Gutenberg block-wrapped `<!-- wp:shortcode -->[ffc_form id="N"]<!-- /wp:shortcode -->` formats
- New `Geofence::has_form_expired_by_days(int $form_id, int $days)` helper that reuses `get_form_end_timestamp` as the single source of truth for "form is over"
- Cleanup UI enforces a **dry-run → apply** flow — the "Remove shortcodes now" button is disabled until a preview transient has been recorded in the last 5 minutes, preventing blind destructive runs
- Cleanup report shows grace window, expired forms, posts scanned, posts affected and shortcodes removed, plus a table of the first **50** affected posts with edit links and removal counts (and a "… and N more" indicator when truncated)
- New `obsolete_shortcode_days` option (default 90) persisted in `ffc_settings` with its own nonce-protected form directly in the cleanup card
- **Unified dynamic field system** — all ~30 historical "standard" fields (personal data, contacts, work schedule, accumulation, union) and admin-created custom fields now read from a single source (`wp_ffc_custom_fields`). Admins can relabel, reorder, regroup, deactivate, and delete any field without touching PHP
- Schema upgrades via new `MigrationDynamicReregFields` strategy — adds `field_group`, `field_source`, `field_profile_key`, `field_mask`, `is_sensitive` columns to `wp_ffc_custom_fields` + `auth_code`, `magic_token` columns to `wp_ffc_reregistration_submissions` with matching indexes
- **Submission details modal** — new "View Details" button in the reregistration submissions list opens a modal that renders all fields grouped by `field_group`, using labels and types from `wp_ffc_custom_fields`. Sensitive values (CPF/RF/RG) decrypted server-side. `FichaGenerator` internal helpers promoted to public API
- Add GitHub Actions CI workflow (`ci.yml`) — PHPStan + PHPUnit matrix
- Add asset build verification workflow (`assets.yml`) for PRs
- Add source maps to CSS/JS build scripts; add `.map` files to `.gitignore`
- Add `DONOTCACHEPAGE` constant to dashboard cache exclusion
- Detect WP Rocket / W3TC / WP Super Cache in cache settings diagnostic
- Add cache compatibility FAQ to `readme.txt`
- Activity log CSV export with filter support
- **Audience Scheduling — 3-Level Hierarchy:** **3-level audience hierarchy** — audiences now support parent / child / grandchild structure. `get_hierarchical` rewritten to fetch all audiences in a single query and build the tree in PHP; `get_descendant_ids`, `get_ancestor_ids`, `get_possible_parents` and `get_ancestors` added for recursive traversal
- **Audience Scheduling — 3-Level Hierarchy:** Admin audience form updated — parent dropdown shows all eligible parents with indented display (`— name`), breadcrumb shows full ancestor chain, circular reference prevention excludes self and descendants from parent options
- **Audience Scheduling — 3-Level Hierarchy:** CSV import updated — iterative multi-pass algorithm (up to 4 passes) creates audiences whose parents already exist, deferring the rest to the next pass. Sample CSV updated to include 3rd-level example
- **Audience Scheduling — 3-Level Hierarchy:** Frontend calendar audience select (`populateAudienceSelect`) uses recursive `appendNodes(nodes, depth)` with indented names; auto-selection helpers (`getAllDescendantIds`, `collectParentNodes`) and `collapseParentAudiences` made recursive
- **Audience Scheduling — 3-Level Hierarchy:** Shortcode `audience_to_array` shared method recursively maps all hierarchy levels for the frontend JSON payload
- **Audience Scheduling — Isolated Calendar Mode:** **Isolated calendar mode** — new "Ignore conflicts from other calendars" checkbox on the calendar edit form. When enabled, audience same-day and user overlap conflict checks only consider bookings within that calendar; environment conflicts remain per-environment regardless
- **Audience Scheduling — Isolated Calendar Mode:** New `is_isolated` column (`tinyint(1) DEFAULT 0`) on `ffc_audience_schedules` table, added via `add_column_if_missing` migration pattern
- **Audience Scheduling — Isolated Calendar Mode:** `get_user_conflicts` and `get_audience_same_day_bookings` accept optional `$scope_schedule_id` parameter — when set, adds `INNER JOIN` on environments table to filter conflicts to the given schedule only
- **Audience Scheduling — Isolated Calendar Mode:** REST controller resolves `schedule_id` from the selected environment and passes it to conflict checks when the schedule is isolated
- **Audience Scheduling — User Dashboard:** **3rd-level audiences in user profile** — `get_joinable_groups` API rewritten from 2-query parent+children approach to single-query tree builder, matching `get_hierarchical` pattern. `renderJoinableGroups` in `ffc-user-dashboard.js` made recursive
- **Audience Scheduling — User Dashboard:** **Accordion on audience group selection** — parent and sub-parent headers toggle their children on click, starting collapsed with a `+` icon that switches to `−` when expanded. Uses `aria-expanded` for accessibility. Leaf-only nodes render without accordion
- **Audience Scheduling — User Dashboard:** **"Leave all groups" button** — new button in profile actions bar (next to "Change Password") allows users to leave all self-joinable groups at once. Styled with red danger color, shows confirmation dialog with group count, and calls new `POST /user/audience-group/leave-all` endpoint. Button only appears when the user belongs to at least one group

### Changed

- Add array shape PHPDoc (`array<int, string>`, `array<string, mixed>`, `array{items:..., total: int}`) to `CsvExporter` private helpers, `ReregistrationStandardFieldsSeeder::on_audience_created`, `UrlShortenerRepository::findPaginated`, `UrlShortenerService::get_stats` and `UserManager::get_user_identifiers_masked`
- Pre-initialize `$calendar = null;` in `AppointmentAjaxHandler::create` alongside `$pdf_data` / `$appointment` — fixes `variable.undefined` when `findById` throws before the assignment
- Add `QRcode::raw` to `phpstan-stubs.php` — the SVG QR generator already calls it in production, only the static-analysis stub was missing
- Fix `@return` PHPDoc parse error in `UserManager::get_user_identifiers_masked` (`string[}}` → `array<int, string>`)
- Correct `wp_validate_redirect` fallback argument type in `UrlShortenerLoader` (`false` → `''`) to match the WordPress stub signature
- UX: `[ffc_csv_download]` now reuses the same CSS classes as `[ffc_verification]` (`ffc-verification-container`, `ffc-verification-header`, `ffc-verification-form`, `ffc-form-field`, `ffc-input`, `ffc-submit-btn`, `ffc-verify-error` / `ffc-verify-success`) so the public download page inherits the card layout, dark-mode support and focus ring already used by the verification page — no more inline `<style>` block
- UX: Progress bar overlay with real batch-by-batch feedback on the `[ffc_csv_download]` page — shows record count, progress percentage, and status messages throughout the export. Minimum 1.5 s display threshold prevents the overlay from flashing on small exports. Graceful degradation: when JavaScript is unavailable the form falls back to the synchronous `admin-post.php` handler
- New **ObsoleteShortcodeCleanerTest** — 19 tests covering regex quote styles (`id="N"`, `id='N'`, `id=N`), extra-attribute handling, classic + Gutenberg + mixed removal, dry-run vs apply pipelines, report truncation at `REPORT_LIMIT`, empty-result short-circuits, and `wp_update_post` no-op skipping
- Replace inline `<style>` blocks in form-list-columns, audience-admin-page, self-scheduling editor, URL shortener admin, and reregistration custom fields with dedicated CSS classes and `wp_add_inline_style`
- A11y: Add `aria-label` to certificate and booking forms
- A11y: Add `aria-describedby` to ticket field
- **Audience Scheduling — Conflict Behavior:** **Audience same-day conflict downgraded from hard block to soft warning** — booking the same audience group on the same day now shows a dismissible warning with existing booking details instead of blocking entirely. Users can acknowledge and proceed, matching the behavior of user overlap conflicts
- **Audience Scheduling — User Dashboard:** Nested subgroup styling with indented padding for 3rd-level items and headers
- Updated 6 test mocks in **AudienceRepositoryTest** for recursive operations — `test_get_hierarchical`, `test_get_members_includes_children`, `test_get_user_audiences_includes_parents`, `test_get_user_audiences_does_not_duplicate`, `test_get_member_count_includes_children`, and `test_cascade_self_join` now properly mock multi-level `get_results`/`get_row` calls

### Fixed

- iOS Safari PDF download — use `pdf.output('bloburl')` + `window.open` instead of `pdf.save` which relies on `blob:` URLs unsupported since iOS 13.3
- Blank canvas detection — alert user and abort instead of silently generating an empty PDF
- Mobile memory — reduce html2canvas scale from 2x to 1.5x on mobile to prevent memory exhaustion on low-end phones
- Platform-specific success messages — "Check Downloads" on Android, "Tap share icon" on iOS
- Wrap `localStorage` read/write in try/catch for Safari private mode (quota is 0)
- Default to Safari-specific guidance when geofence `error.code` is 0 or undefined (some iOS versions)
- Standardize all migration card icons to use `ffc-icon-*` CSS pattern with consistent alignment
- Cleanup card markup and dashicon alignment issues across migration cards (5 incremental fixes)
- Obsolete shortcode cleanup `save_days` form — nonce and trigger param now sent via URL query string instead of hidden POST fields that were not reaching the handler
- **Audience Scheduling — User Dashboard:** Color dot (`.ffc-audience-dot`) was invisible on subgroup headers — selector was scoped to `.ffc-audience-join-item` only; broadened to apply globally
- **Audience Scheduling — User Dashboard:** Subgroup header rows without join/leave buttons had inconsistent height — added `min-height: 44px` to both item and subgroup header rows
- Booking "Create" button stuck on loading text ("Verificando...") after consecutive bookings — `openBookingModal` reset `disabled` state but not the button text; now also restores `ffcAudience.strings.createBooking`
- Frontend calendar did not display 3rd-level audiences — minified JS (`ffc-audience.min.js`) was stale and still contained the old 2-level rendering logic
- Zero PHPStan level 6 errors — cleared 26 pre-existing static analysis findings across 13 files (`CsvExporter`, `QrcodeGenerator`, reregistration module, self-scheduling handler, URL shortener module, user dashboard module, PHPStan stubs)
- **3090 → 3154 tests** (+64) with all 7415 assertions green

### Removed

- Remove dead code flagged by PHPStan — duplicated `wp_doing_ajax` early-return in `AccessControl::block_wp_admin`; redundant `!== ''` / `!== null` / `!== '0'` checks in the reregistration module; `|| $success` tail in `UserManager::save_profile_data`; empty-guard around the always-populated `$where_clauses` in `UrlShortenerRepository::findPaginated`; redundant `$temp_file === ''` check in `QrcodeGenerator::generate`
- Remove redundant `?? ''` fallbacks on `Encryption::decrypt_field` calls in `CsvExporter::format_csv_row` — the method returns a non-nullable string (same fix already applied to `PublicCsvExporter`)

### Security

- Download hash stored in a dedicated post meta (`_ffc_csv_public_hash`), generated with `bin2hex(random_bytes(16))` and compared via `hash_equals` to mitigate timing attacks
- Reuses `Shortcodes::generate_security_fields` so the public form includes the same honeypot (`ffc_honeypot_trap`) and mathematical CAPTCHA already validated by `SecurityService::validate_security_fields`
- Per-IP rate limiting via `RateLimiter::check_ip_limit`, identical to the public form submission path
- `get_post_type` check blocks the handler from serving data for non-`ffc_form` posts even if a valid hash is supplied
- Empty stored hash short-circuits the comparison — prevents `hash_equals('', '')` from accepting any request before the admin has generated a hash
- AJAX batch jobs scoped by `sha1(IP)` — subsequent batch/download requests verify the caller's IP matches the IP that started the job. Combined with UUID v4 job IDs (122 bits of entropy) this prevents cross-visitor job hijacking
- `wp_update_post` automatically creates WordPress revisions for modified `post` / `page` entries, giving admins a manual rollback path. Only `[ffc_form]` shortcodes pointing at expired IDs are removed — the rest of the content is left untouched
- CPF, RF and RG are now encrypted at rest (AES-256-CBC via the existing `Encryption` class) in both the submission JSON and in `usermeta`. Decryption is transparent in form renderer, PDF generator, CSV exporter, and verification handler
>- Refactor `AppointmentRepository::findByUserId` and `getStatistics` to use single `wpdb->prepare` calls instead of nesting prepared fragments (avoids placeholder re-processing)
- Replace direct ID interpolation in `AbstractRepository::findByIds` with proper `%d` placeholders via `array_fill` + spread operator
- Add `is_uploaded_file` validation in `AudienceAdminImport` for both member and audience CSV uploads (prevents path traversal via crafted `tmp_name`)
- Sanitize `Content-Disposition` filenames across all 6 CSV exporters — strip CR/LF/quote characters and wrap in double quotes (CRLF injection prevention per RFC 6266)
- Centralize honeypot+captcha via `SecurityService` in verification handler; add honeypot field to reregistration form (defense-in-depth)
- Add SRI hash for jQuery UI CDN stylesheet
- Add rate limiting to certificate verification REST endpoint
- Add `X-RateLimit-Limit` / `X-RateLimit-Remaining` headers to REST API responses

### Documentation

- Added a `[ffc_csv_download]` row to the Shortcodes table in the Documentation tab (`ffc-settings&tab=documentation`) describing the Form ID + hash workflow, the expiration/quota gating, and the optional `title` attribute

---

## [5.0.3] (2026-03-27) — `dada78b`

Performance optimizations for URL shortener and QR code generation, new admin columns for forms listing, and Safari/iOS geofence fixes.

### Added

- Add ID column (sortable) to ffc_form listing screen for quick form reference
- Add Shortcode column with copy-to-clipboard button to ffc_form listing screen
- Add Submissions column with batch-loaded count (single GROUP BY query, no N+1) linking to filtered submissions page

### Changed

- Cache plugin settings in UrlShortenerService — single `get_option` per request instead of ~7 repeated calls
- Defer redirect click count increment to `shutdown` hook — redirect response is sent before the DB update
- Add `qr_cache` column to `ffc_short_urls` table — QR code base64 stored in DB, avoids phpqrcode + GD regeneration on every admin page load
- Rewrite SVG QR generation to use `QRcode::raw` matrix directly — eliminates temp file I/O, PNG generation, GD image loading, and pixel-by-pixel color scanning
- UX: Progressive loading messages for Safari/iOS geolocation wait — three timed phases replace the static message so users know the page is alive and receive increasingly specific guidance (t=0s: tap Allow, t=8s: check for prompt, t=20s: check Location Services settings)
- UX: `updateLoadingMessage` helper updates text in-place without removing/re-adding the spinner element

### Fixed

- `isSafari` detection for iPadOS 13+ — modern iPads report Mac desktop user-agent, now detected via `navigator.maxTouchPoints`
- Geofence loading spinner stuck indefinitely when Safari silently ignores geolocation request — added safety timeout (40s Safari / 25s others) with `gps_fallback` honoring
- `maximumAge: 0` on first geolocation attempt forces fresh GPS fix causing unnecessary 20s timeout on Safari — now uses `maximumAge: 30000` to accept recent cached position
- `gps_fallback` admin setting (`allow`/`block`) not passed to frontend — GPS failure always blocked the form regardless of admin configuration
- Safari-specific error messages (Location Services guidance) overridden by generic admin `messageError` — browser-specific messages now always take priority
- Geofence `handleBlocked` signature simplified to 3 arguments, preventing `customMessage` from silently swallowing specific error messages

### Removed

- Remove unnecessary cache invalidation from `incrementClickCount` (click_count not needed for redirect resolution)

---

## [5.0.2] (2026-03-03) — `a9cabcd`

100% unit test coverage across all 21 modules (146 concrete classes), plus bug fixes, new features, and CSS/asset refactoring.

### Added

- Multi-audience transfer list for reregistration campaigns
- URL Shortener documentation added to the Documentation tab
- Logout button added to Profile tab in user dashboard
- Standardized magic link URL format across all document types
- **AdminClassTest** — 9 tests covering constructor, register_admin_menu, configure_tinymce_placeholders, handle_submission_actions, handle_csv_export, handle_migration_action, handle_submission_edit_save
- **FormEditorTest** — 11 tests covering constructor, enqueue_scripts (wrong hook, wrong post type, correct context), add_custom_metaboxes, ajax_generate_codes (no permission, success), ajax_load_template (no permission, empty filename, missing file, success)
- **FormEditorMetaboxRendererTest** — 5 tests covering render_shortcode_metabox, render_box_layout, render_box_builder, render_box_restriction, render_field_row
- **AdminSubmissionEditPageTest** — 4 tests covering constructor, render (no permission, submission not found), handle_save without POST
- **SubmissionsListTest** — 3 tests covering constructor, get_columns, no_items (with WP_List_Table stub)
- **FrontendTest** — 7 tests covering constructor, frontend_assets early returns (no post, non-WP_Post, no shortcodes), asset enqueuing with ffc_form/ffc_verification shortcodes, geofence config localization
- **FrontendShortcodesTest** — 14 tests covering captcha data, security fields, verification page (with/without token), form rendering (invalid ID, no fields, zero ID, full render, password/ticket restrictions, geofence class, select/radio/hidden/info/embed fields, cpf_rf as tel)
- **VerificationResponseRendererTest** — 14 tests covering field labels, field value formatting, appointment verification, reregistration verification, certificate verification, PDF generation
- **ReregistrationAdminTest** — 14 tests covering init, add_menu, enqueue_assets, render_page, handle_actions, ajax_generate_ficha, ajax_count_members
- **ReregistrationCsvExporterTest** — 5 tests covering early returns for missing/wrong action, missing id, invalid nonce, rereg not found
- **ReregistrationCustomFieldsPageTest** — 3 tests covering permission denied, empty audiences, audiences with field counts
- **ReregistrationFormRendererTest** — 3 tests covering basic render, draft population, deadline display
- **AppointmentReceiptHandlerTest** — 5 tests covering add_query_vars, handle_receipt_request (no query var, invalid ID), get_receipt_url (with/without token)
- **SelfSchedulingAdminTest** — 7 tests covering constructor, add_submenu_pages, enqueue_admin_assets (no screen, wrong screen, correct screen, appointments page), render_appointments_page (no permission)
- **SelfSchedulingCPTTest** — 8 tests covering constructor, register_calendar_cpt, add_duplicate_link (wrong type, no permission, success), handle_calendar_duplication (no permission), sync_calendar_data (autosave skip), cleanup_calendar_data (wrong type)
- **SelfSchedulingEditorTest** — 13 tests covering constructor, enqueue_scripts (wrong hook, wrong post type, no screen, correct context), add_custom_metaboxes, render_box_config, render_box_hours, render_box_rules, render_box_email, render_shortcode_metabox (published/draft), display_save_errors
- **SelfSchedulingShortcodeTest** — 6 tests covering constructor, render_calendar (no ID, calendar not found), enqueue_assets (not singular, no post, no shortcode)
- **AudienceAdminAudienceTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- **AudienceAdminBookingsTest** — 2 tests covering constructor, render_page (empty bookings list)
- **AudienceAdminCalendarTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- **AudienceAdminDashboardTest** — 2 tests covering constructor, render_dashboard_page (stats output)
- **AudienceAdminEnvironmentTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- **AudienceAdminImportTest** — 3 tests covering constructor, render_page (import/export interface), handle_csv_import (no action)
- **AudienceAdminSettingsTest** — 4 tests covering constructor, handle_visibility_settings (no action), handle_global_holiday_actions (no permission), render_page (general tab)

### Changed

- Footer refactored to two-column layout with QR code on the left
- Extracted inline CSS/JS to dedicated asset files
- Replaced inline styles with CSS classes in URL Shortener
- Consolidated duplicate button/badge classes into `ffc-common.css`

### Fixed

- AJAX requests blocked for non-admin users — permission check was too restrictive
- Certificate download bypassing verification page with direct AJAX
- Download for regular users — migrated from admin-ajax to REST API
- Reregistration 'Editar' button not working in dashboard
- QR codes encoding permalink instead of short URL, and click counter issues
- Short URL redirects not working when rewrite rules fail
- QR code stacking/visibility issues in html2canvas PDF rendering
- Lazy-loading plugins hiding QR code in appointment PDF
- QR code generation and center download button
- URL Shortener SVG QR generation — use `wp_tempnam` and add filesize check
- Self-scheduling overlay translation, email notice, and PDF error handling
- Reverted PDF button to magic link (undo direct download approach)
- **1051 → 3089 tests** across **153 test files** — 100% class coverage on all 21 modules

### Removed

- Removed 27 unnecessary `!important` declarations
- Removed unused CSS classes and stale internal comments

---

## [5.0.1] (2026-02-22) — `ba65db7`

Security hardening, code quality improvements, URL Shortener test coverage, virtual auth code prefixes, and multiple bug fixes.

### Added

- **UrlShortenerServiceTest** — 40 tests covering create/delete/trash/restore, generate_unique_code (Base62, collision, length), get_short_url, settings (prefix, code_length, redirect_type, enabled, auto_create, post_types), toggle_status, get_stats
- **UrlShortenerRepositoryTest** — 20 tests covering findByShortCode (cache hit/miss), findByPostId (active only, cache), incrementClickCount (success/failure, cache clear), codeExists, findPaginated (WHERE building, search, sort, pagination), getStats
- **UrlShortenerLoaderTest** — 15 tests covering init (hooks conditional on enabled), maybe_flush_rewrite_rules (version tracking), register_rewrite_rules (regex), add_query_vars, handle_redirect (full redirect flow with click tracking), flush_rules (static method)
- **UrlShortenerAdminPageTest** — 17 tests covering handle_actions (nonce, routing), ajax_create (validation, permission), ajax_delete/trash/restore, ajax_empty_trash (bulk delete), ajax_toggle (status toggle)
- **UrlShortenerMetaBoxTest** — 12 tests covering register_meta_box (by post type), on_save_post (auto-create with guards), ajax_regenerate (regeneration flow)
- **UrlShortenerQrHandlerTest** — 7 tests covering generate_qr_base64 (PNG), generate_svg (SVG), handle_download_png/svg, resolve_qr_target (via reflection)
- **UrlShortenerActivatorTest** — 6 tests covering get_table_name, create_tables (idempotent), maybe_migrate (migrations)
- Added virtual prefixes (C/R/A) to authentication codes — `C` for certificates, `R` for reregistrations, `A` for appointments. Display format changes from `XXXX-XXXX-XXXX` to `C-XXXX-XXXX-XXXX` / `R-XXXX-XXXX-XXXX` / `A-XXXX-XXXX-XXXX`
- Prefixes are presentation-only — not stored in database, zero migration needed. Raw 12-char codes remain the source of truth
- Intelligent verification routing on `/valid/` — prefix hints which DB table to search first, with fallback to all others for backward compatibility
- Updated DocumentFormatter with `format_auth_code($code, $prefix)`, `parse_prefixed_code($input)`, and `clean_auth_code($code)` methods
- Updated verification page input to accept prefixed codes (maxlength 16, placeholder `C-XXXX-XXXX-XXXX`)
- Updated JS input mask to dynamically detect prefix and format as `P-XXXX-XXXX-XXXX` or legacy `XXXX-XXXX-XXXX`

### Changed

- All call sites across PDFs, emails, REST APIs, admin views, receipts, and verification responses now pass the appropriate prefix constant
- Centralized CPF/RF and auth_code formatting into DocumentFormatter — replaced scattered inline formatting across admin, API, and frontend layers
- Replaced inline `onclick` handlers with `data-confirm` delegation pattern for safer event handling
- Added summary error feedback on form validation failures
- Applied format masks for CPF, RF, auth_code, and validation_code across all admin and API views (submissions list, appointment detail, REST responses)
- Used translated status labels in reregistration admin views
- Centralized `FFC_VERSION` constant in tests/bootstrap.php from main plugin file

### Fixed

- Elevated `current_user_can('read')` to `manage_options` on sensitive Audience AJAX handlers (save_booking, cancel_booking, get_environments, search_users, save_custom_fields, get_custom_fields)
- Changed `$_GET['booking_id']` to `$_POST['booking_id']` in Audience AJAX POST handler
- Standardized nonce field name to `nonce` across all Audience handlers (was `_wpnonce` in one handler)
- Replaced `stripslashes` with `wp_unslash` in SubmissionsList and VerificationHandler (4 occurrences)
- Improved SQL IN clause pattern in SubmissionRepository and AppointmentRepository — switched from string interpolation of `%s` placeholders to `%d` with `intval` array mapping for integer ID arrays
- Moved rate limiter before format validation in `verify_by_magic_token` to prevent probing token formats without throttling
- Added explicit `json_last_error_msg` check and error logging after `json_decode` in Audience loader
- Added early IP-based rate limit check in `FormProcessor::handle_submission_ajax` before nonce/CAPTCHA — prevents brute-force DoS from consuming server resources on expensive checks
- Added justifications to all bare `phpcs:ignore` comments in URL Shortener admin page (9 comments standardized)
- Encrypted fields (email, CPF, RF) not decrypted in REST API responses for submissions and user certificates
- XSS vulnerability in dashboard JS — sanitized dynamic HTML output with proper escaping
- Appointment creation failing due to non-column keys (`ffc_form_id`, `ffc_calendar_id`) in insert data array
- QR code not appearing in auto-download PDF and duplicate download button on success page
- CPF/RF and email not found for users with only self-scheduling appointments — added join on appointments table in UserCreator
- Certificate verification card narrower than appointment card on `/valid/` — added `width: 100%` to `.ffc-certificate-preview` (root cause: `displayVerificationResult` replaces container innerHTML, removing `.ffc-verify-result` wrapper, so flex `align-items: center` caused shrink-wrap)
- **934 → 1051 tests, 1830 → 2076 assertions**

### Removed

- Removed nonce fallback chain in AjaxTrait — each handler now verifies a single specific nonce action, eliminating timing side-channel
- Removed `wp_rest` as fallback nonce in Self-Scheduling AJAX handlers — only `ffc_self_scheduling_nonce` accepted

### Documentation

- Magic token endpoint documentation already complete (nonce intentionally omitted, rate limiting in place)
- JSON fallback handling in Audience loader already

---

## [5.0.0] (2026-02-19) — `9742fd9`

Multi-identifier architecture: split combined CPF/RF into independent columns, and retirement of 10 completed legacy migrations.

### Added

- Added separate `cpf`, `cpf_encrypted`, `cpf_hash`, `rf`, `rf_encrypted`, `rf_hash` columns to submissions and appointments tables
- Updated core layer (SubmissionHandler, FormProcessor, Encryption) to read/write split columns natively
- Updated admin, API, security, and privacy layers for split cpf/rf columns
- Preserved legacy `cpf_rf` columns during split migration for backward compatibility
- Updated user dashboard layer (UserCreator, UserManager, CapabilityManager) for split columns
- Added `identifier_type` parameter to UserCreator for targeted column lookup (CPF vs RF)
- `test_format_csv_row_rf_only` test case for RF-only submissions
- Appointment detail view in admin panel with decrypted CPF/RF split fields, calendar info, and custom data

### Changed

- Deprecated legacy `cpf_rf` columns across entire plugin with `@deprecated` annotations
- Identifier digit-count classification targets specific hash column (11→CPF, 7→RF) instead of scanning both
- Applied digit-count classification to AppointmentRepository `findByCpfRf`
- MigrationRegistry, MigrationStatusCalculator, and MigrationManager reduced to single-migration focus
- Activator `run_migrations()` — no longer auto-runs retired migrations on activation
- MigrationManager unit tests adapted to simplified architecture
- Privacy/LGPD deletion request success message now tells admins where to find it (Tools > Erase Personal Data)
- **10 completed migrations** retired from admin panel — these ran their course and are no longer needed:
  - `email` field migration (JSON→column extraction, handled at insert since v2.9)
  - `cpf_rf` field migration (JSON→column extraction, handled at insert since v2.9)
  - `auth_code` field migration (JSON→column extraction, handled at insert since v2.9)
  - `magic_tokens` generation (handled at insert since v2.10)
  - `encrypt_sensitive_data` (100% complete, LGPD compliance)
  - `cleanup_unencrypted` (100% complete, replaced by daily cron)
  - `user_link` submissions→users (handled at insert by UserCreator since v3.1)
  - `name_normalization` (handled at insert by FormProcessor since v4.3)
  - `user_capabilities` (handled at insert by UserCreator since v4.4)
  - `data_cleanup` (flag-based, replaced by cron)
  - 7 migration strategy classes (FieldMigration, MagicToken, Encryption, Cleanup, UserLink, NameNormalization, UserCapabilities)
  - 3 legacy migration executor classes (MigrationUserLink, MigrationNameNormalization, MigrationUserCapabilities)
  - `split_cpf_rf` migration remains available for legacy data
  - Result: **934 tests, 1830 assertions, 0 failures**

### Fixed

- Added split cpf/rf column support to `decrypt_submission` and `decrypt_appointment`
- CsvExporterTest updated for split CPF/RF columns (15 fixed headers instead of 14, indices shifted)
- CsvExporter sample_row uses separate `cpf`/`rf` fields instead of combined `cpf_rf`
- Added defensive try-catch to MigrationStatusCalculator strategy initialization to prevent 500 errors from stale opcache or DB issues
- Added try-catch to migrations settings tab view to gracefully handle runtime errors instead of 500
- Implemented missing `action=view` handler for appointments admin page (was causing 500 error)
- Hardened appointments view and render method with try-catch to prevent unhandled 500 errors
- Rewrote appointment URLs to use absolute paths via `admin_url()` and replaced `action=view` with `appointment=X` parameter to avoid WordPress `admin_action_view` dispatch that was causing persistent 500 errors
- Changed appointment action URLs from `action` to `ffc_action` parameter to avoid conflicts with WordPress admin.php action processing
- Corrected confirm/cancel redirect URLs to use `admin.php` instead of `edit.php` for consistency with menu registration
- Added `class_exists` guard around `FFC_Appointments_List_Table` definition to prevent class redeclaration errors
- Added PHP shutdown function error handler to capture fatal errors in appointments page for debug logging
- Calendar export dropdown (`Exportar Calendário`) was clipped by `overflow:hidden` on `.ffc-appointments-table`

### Removed

- Removed legacy `cpf_rf` dual-write; optimized split migration to be the single source of truth
- Removed `cpf_rf_hash` legacy fallback from UserCreator queries

---

## [4.12.26] (2026-02-18) — `aa54fd2`

PHPStan level 6 — zero-baseline compliance. Resolved all 317 static analysis errors across 80+ files without any baseline suppressions.

### Changed

- Added `phpstan-stubs.php` bootstrap file with plugin constants and phpqrcode stubs
- Excluded view directories from `variable.undefined` scanning in `phpstan.neon.dist`

### Fixed

- Added missing `use` import statements for 94 class.notFound errors across admin, API, frontend, and migration files
- Cast `int` to `string` for `esc_html()`, `esc_attr()`, `sprintf()`, `_n()` calls (50 argument.type errors)
- Corrected PHPDoc `@return` types to match native return types (13 return.type errors)
- Resolved include/require path resolution by using `__DIR__` in PHPStan stubs for `FFC_PLUGIN_DIR`
- Added PHPStan stubs for `DB_NAME`, `QR_ECLEVEL_*`, and `QRcode` class constants
- Simplified always-true/false conditions, redundant `empty()` checks, and `!== null` comparisons
- Fixed duplicate array keys, `WP_Error` namespace references, and covariant return types
- Baseline: Reduced from 317 errors to **0** (empty `ignoreErrors` array)

### Removed

- Removed 15 unreachable code blocks after `wp_die()`, `exit`, `wp_send_json_*()` calls
- Removed unused properties (`$form_editor`, `$settings_page`, `$dynamic_fragments`, etc.) flagged as write-only
- Removed redundant `is_array()`/`is_string()` type checks on already-typed variables (15 errors)
- Removed unused constructor parameters (`$email_handler`, `$form_processor`, `$submission_handler`, `$verification_handler`) and updated all callers + tests
- Renamed undefined method calls (`check_limit` → `check_ip_limit`, added `process_bulk_action()`, fixed `generate_qr_code()` static call)

---

## [4.12.25] (2026-02-17) — `828a67d`

Unit tests for EmailHelperTrait, AjaxTrait, and Debug: email sending/parsing helpers, AJAX parameter sanitization with nonce/permission checks, and per-area debug logging.

### Added

- **EmailHelperTraitTest** (20 tests) — `ffc_emails_disabled()` (default off, setting enabled, setting empty), `ffc_parse_admin_emails()` (single/multiple comma-separated, invalid email filtering, empty string admin fallback, custom fallback, whitespace trimming), `ffc_send_mail()` (success/failure wp_mail delegation), `ffc_email_header()` (div/font-family HTML), `ffc_email_footer()` (site name, closing div), `ffc_admin_notification_table()` (table structure, label+value rows, row count, empty details)
- **AjaxTraitTest** (17 tests) — `get_post_param()` (value/default/empty), `get_post_int()` (integer cast, default, negative→positive via absint, non-numeric→zero), `get_post_array()` (sanitized array, missing→empty, non-array→empty), `verify_ajax_nonce()` (valid passes, fallback action accepted, missing nonce sends error with die simulation, custom field name), `check_ajax_permission()` (granted passes, denied sends error)
- **DebugTest** (13 tests) — `is_enabled()` (enabled/disabled/zero/independent areas), `log()` (writes when enabled, skips when disabled), data formatting (null no suffix, string/array/integer data), convenience method delegation (log_pdf, log_email, log_form, log_rest_api, log_migrations, log_activity_log), area constants count (9)

### Fixed

- Added `patchwork.json` to allow Brain\Monkey mocking of PHP built-in `error_log`
- 765 → 815 tests, 1496 → 1563 assertions

---

## [4.12.24] (2026-02-17) — `e1ad48b`

Unit tests for CsvExportTrait, ActivityLogQuery, and AppointmentCsvExporter: dynamic column extraction, query building, CSV row formatting, transient caching.

### Added

- **CsvExportTraitTest** (18 tests) — `build_dynamic_headers()` (snake_case/kebab-case/mixed to Title Case, empty, single word), `decode_json_field()` (plain JSON, empty/invalid/null, custom keys, encrypted fallback), `extract_dynamic_keys()` (multi-row dedup, empty, no JSON), `extract_dynamic_values()` (key ordering, missing key default, array flattening, empty keys)
- **ActivityLogQueryTest** (17 tests) — `get_activities()` (defaults, JSON context decode, invalid/empty context, level/search filter in prepared SQL, orderby whitelist, order normalization), `count_activities()` (integer return, multi-filter query building), `get_stats()` (transient cache hit/miss, DB aggregation), `cleanup()` (deleted count, transient clearing), `run_cleanup()` (settings retention, zero skip, default 90)
- **AppointmentCsvExporterTest** (21 tests) — `format_csv_row()` via Reflection: status labels (6 statuses incl. unknown fallback), consent display (yes/no/unset), user lookups (approved_by/cancelled_by with display name, deleted user ID fallback), calendar title from repo with deleted fallback, dynamic columns (appended, missing key default), `get_fixed_headers()` count and ID-first

### Fixed

- 709 → 765 tests, 1427 → 1496 assertions

---

## [4.12.23] (2026-02-17) — `ea36ec6`

Unit tests for BlockedDateRepository, EmailTemplateService, and ActivityLogSubscriber: recurring pattern matching, ICS generation, email wrapping, cache clearing, hook registrations.

### Added

- **BlockedDateRepositoryTest** (20 tests) — `matchesRecurringPattern()` via Reflection: weekly (blocked/unblocked day, weekend combo, empty/missing days), monthly (blocked/unblocked day of month, empty/missing), yearly (holiday match, ignores year variation, empty/missing dates), invalid/unknown/empty pattern, time parameter passthrough
- **EmailTemplateServiceTest** (24 tests) — `render_template()` (single/multiple vars, unknown placeholders, empty), `wrap_html()` (DOCTYPE, site name, header/content/footer structure), `format_date()`/`format_time()`, `send()` (wrap/no-wrap, wp_mail result), `generate_ics()` (VCALENDAR/VEVENT structure, date/time formatting, UID domain, REQUEST/CANCEL methods, summary/description/location, special char escaping, PRODID)
- **ActivityLogSubscriberTest** (13 tests) — Constructor hook registrations (submission/appointment/settings/cleanup), `on_settings_saved()` cache clearing (wp_cache_delete, delete_transient verification), logging method smoke tests (all 7 event handlers run without error with logging disabled)

### Fixed

- 652 → 709 tests, 1338 → 1427 assertions

---

## [4.12.22] (2026-02-17) — `4ec72ef`

Unit tests for Self-Scheduling and Date Blocking: appointment validation, save handler sanitization, holiday/availability checks.

### Added

- **AppointmentValidatorTest** (24 tests) — `validate()` (missing fields, invalid date/time format, impossible date, CPF/RF validation, slot availability, daily limit, scheduling visibility), `check_booking_interval()` (user ID/email/CPF lookup, skips cancelled, skips different calendar, returns error for upcoming), `is_within_working_hours()` delegation, `get_daily_appointment_count()` delegation
- **SelfSchedulingSaveHandlerTest** (18 tests) — `save_config()` (slot duration/defaults, boolean toggles, visibility validation, private forces scheduling private, description, no POST skip), `save_working_hours()` (sanitization, defaults, no POST skip), `save_email_config()` (boolean toggles, reminder hours, text fields, no POST skip)
- **DateBlockingServiceTest** (18 tests) — `is_global_holiday()` (match, no match, empty, non-array, missing date key), `get_global_holidays()` (all, start/end/range filter, empty range, non-array, missing date entries), `is_date_available()` (holiday blocks, working hours blocks, null time checks working day, closed day)

### Fixed

- 592 → 652 tests, 1235 → 1338 assertions

---

## [4.12.21] (2026-02-17) — `71ac5ab`

Unit tests for Migrations, Scheduling, and Generators: pure logic coverage for data sanitization, working hours, and magic links.

### Added

- **DataSanitizerTest** (31 tests) — `sanitize_field_value()` (custom callbacks, closure, fallback), `clean_json_data()` (JSON string/array, empty removal, zero preservation, invalid input), `extract_field_from_json()` (multi-key lookup, first non-empty match), `is_valid_identifier()` (CPF/RF digit-length validation, formatting), `is_valid_email()` (delegation), `normalize_auth_code()` (space/dash/underscore removal, uppercase)
- **WorkingHoursServiceTest** (30 tests) — `is_within_working_hours()` keyed format (range check, boundary inclusive start/exclusive end, closed day, missing start/end), array-of-objects format (range, no entry, split shift with gap), edge cases (empty/null/JSON string/unknown format); `is_working_day()` (both formats); `get_day_ranges()` (single range, split shift, closed, empty)
- **MagicLinkHelperTest** (32 tests) — `is_valid_token()` (32/64 hex, uppercase, boundary lengths, non-hex, empty), `generate_magic_link()` (URL structure, empty token), `extract_token_from_url()` (ffc_magic, token query, hash fragment, priority, no token), `get_magic_link_html()` (link, copy button, no-copy, empty token), `get_magic_link_qr_code()` (Google Charts URL, custom size, empty), `debug_info()`, `ensure_token()` (null handler, valid handler, invalid-generates-new), `get_magic_link_from_submission()`, `get_verification_page_url()`

### Fixed

- 499 → 592 tests, 1118 → 1235 assertions

---

## [4.12.20] (2026-02-17) — `f5fed1f`

Unit tests for Admin module: comprehensive coverage of settings validation, CSV export formatting, and geofence logic.

### Added

- **FormEditorSaveHandlerTest** (24 tests) — `validate_geofence_config()` (GPS/IP enabled states, combined errors) and `validate_areas_format()` (lat/lng/radius format, range validation, edge values, mixed valid/invalid lines)
- **SettingsSaveHandlerTest** (28 tests) — `save_general_settings()` (dark mode validation, cleanup days, advanced tab debug flags, cache tab), `save_smtp_settings()` (tab-specific disable, SMTP fields, user email settings), `save_qrcode_settings()` (size/margin, cache tab), `save_date_format_settings()` (format/custom, preservation)
- **CsvExporterTest** (25 tests) — `get_fixed_headers()` (14/17 columns with/without edit), `format_csv_row()` (fixed columns, consent formatting, deleted form title, edit columns, dynamic columns, empty optional fields), CsvExportTrait methods (`build_dynamic_headers`, `decode_json_field`, `extract_dynamic_keys`, `extract_dynamic_values`)

### Fixed

- 422 → 499 tests, 974 → 1118 assertions

---

## [4.12.19] (2026-02-17) — `294f87b`

> Refactoring: extract focused classes from DashboardShortcode (720 → 395 lines, 45% reduction).

### Changed

- **DashboardAssetManager** (269 lines) — extracted `enqueue_assets()` with full CSS/JS enqueuing, `wp_localize_script` for dashboard, reregistration, and working-hours components, plus `user_has_audience_groups()` audience membership check
- **DashboardViewMode** (98 lines) — extracted `get_view_as_user_id()` admin view-as validation (nonce, capability, user existence) and `render_admin_viewing_banner()` HTML rendering
- DashboardShortcode retains shortcode registration, cache headers, main render orchestration, login/redirect messages, and reregistration banners

---

## [4.12.18] (2026-02-17) — `264c5e7`

Unit tests for SubmissionHandler: comprehensive coverage of update, decrypt, failure paths, and edge cases.

### Added

- **21 additional SubmissionHandler tests** covering gaps identified in analysis:
  - `update_submission()` (4 tests): encrypts email with hash, encrypts data JSON, strips edit tracking (`is_edited`/`edited_at`) from data before encryption, returns false on repo failure
  - `update_user_link()` (3 tests): sets user_id on link, passes null to unlink, returns false on failure
  - `decrypt_submission_data()` (2 tests): plaintext passthrough preserves all fields, encrypted fields correctly decrypted
  - Failure paths (3 tests): trash/restore/delete return false when repository returns false
  - Bulk empty guards (3 tests): bulk_trash/restore/delete return 0 for empty arrays without hitting repository
  - `get_submission_by_token` edge cases (2 tests): non-hex input returns null, valid hex not found returns null
  - `process_submission` branches (3 tests): consent absent sets 0, CPF mask cleaned before encryption, pre-populated auth_code preserved
  - `ensure_magic_token` (1 test): returns empty string when submission not found

### Fixed

- 401 → 422 tests, 923 → 974 assertions

---

## [4.12.17] (2026-02-17) — `0e9e416`

> Refactoring: extract focused classes from FormProcessor.

### Changed

- **AccessRestrictionChecker** (168 lines) — extracted `check_restrictions` and `consume_ticket` as public static methods for password, denylist, allowlist, and ticket validation
- **ReprintDetector** (164 lines) — extracted `detect_reprint` as a public static method with `build_reprint_result` helper for JSON decoding and field enrichment
- Updated FormProcessorTest and FormProcessorRestrictionsTest to call AccessRestrictionChecker::check() directly (no more Reflection for restriction tests)
- FormProcessor retains AJAX orchestration, quiz scoring, and submission processing as its core responsibility (822 → 548 lines, 33% reduction)

---

## [4.12.16] (2026-02-17) — `7691525`

> Refactoring: extract focused classes from SelfSchedulingEditor (924 → 559 lines, 39% reduction).

### Changed

- **SelfSchedulingCleanupHandler** (303 lines) — extracted AJAX appointment cleanup handler (`handle_cleanup_appointments`) and cleanup metabox rendering (`render_cleanup_metabox`) into a dedicated class with its own constructor hook
- **SelfSchedulingSaveHandler** (141 lines) — extracted `save_calendar_data` into a dedicated class with private helpers for config, working hours, and email config persistence
- SelfSchedulingEditor now delegates save and cleanup responsibilities via constructor composition, retaining only metabox registration, rendering, and asset loading

---

## [4.12.15] (2026-02-17) — `00d771e`

Unit tests for Utils: comprehensive coverage of document validation, formatting, sanitization, captcha, and helper functions.

### Added

- **UtilsTest** — 95 tests covering all 3 groups of Utils methods:
  - Group A (Pure functions, 14 methods): `validate_cpf` (7 tests), `validate_phone` (7 tests), `format_cpf` (3 tests), `validate_rf`/`format_rf` (8 tests), `mask_cpf` (5 tests), `format_auth_code` (3 tests), `format_document` (6 tests), `sanitize_filename` (6 tests), `format_bytes` (6 tests), `truncate` (5 tests), `clean_auth_code`/`clean_identifier` (5 tests), `normalize_brazilian_name` (8 tests)
  - Group B (WordPress mocks, 11 methods): `asset_suffix`, `mask_email` (3 tests), `generate_random_string` (3 tests), `generate_auth_code`, `current_user_can_manage` (2 tests), `verify_simple_captcha` (5 tests), `validate_security_fields` (4 tests), `get_allowed_html_tags`, `generate_simple_captcha`, `recursive_sanitize` (2 tests)
  - Group C (DB mock): `get_submissions_table` (2 tests including multisite prefix)

### Fixed

- 306 → 401 tests, 812 → 923 assertions

---

## [4.12.14] (2026-02-17) — `ac03103`

Unit tests for FormProcessor and PdfGenerator: quiz scoring, restriction checks, URL parsing, filename generation, and data enrichment.

### Added

- **FormProcessorTest** — 21 tests covering `calculate_quiz_score()` (9 tests: correct/wrong answers, partial scoring, non-scored fields, rounding, empty input) and `check_restrictions()` (12 tests: password validation, denylist/allowlist CPF matching, ticket validation/consumption, priority ordering)
- **PdfGeneratorTest** — 32 tests covering `parse_validation_url_params()` (12 tests: link formats, custom text, target/color attributes, combined params), `generate_filename()` (6 tests: title sanitization, auth code appending, special chars, empty fallback), `generate_default_html()` (6 tests: conditional name/auth code rendering), and `enrich_submission_data()` (8 tests: email/date/ID/magic-token enrichment, no-overwrite behavior)

### Fixed
- 253 → 306 tests, 710 → 812 assertions

---

## [4.12.13] (2026-02-17) — `0b81418`

> Refactoring: extract focused classes from ReregistrationAdmin (1,125 → 830 lines).

### Changed

- **ReregistrationCsvExporter** — extracted CSV export logic (`handle_export`) into a standalone class with a single static entry point
- **ReregistrationSubmissionActions** — extracted submission workflow handlers (`handle_approve`, `handle_reject`, `handle_return_to_draft`, `handle_bulk`) into a dedicated class
- **ReregistrationCustomFieldsPage** — extracted custom fields admin submenu page rendering into its own class
- ReregistrationAdmin now delegates to the extracted classes via `handle_actions()`, reducing the main class by 26% (1,125 → 830 lines)

---

## [4.12.12] (2026-02-17) — `2e94fc8`

Unit tests for Reregistration module: field options and data processor.

### Added

- **ReregistrationFieldOptionsTest** — 15 tests covering `get_divisao_setor_map()` structure and content, field option providers (`sexo`, `estado_civil`, `sindicato`, `jornada`, `acumulo`, `uf`), UF 2-letter code validation, and `get_default_working_hours()` structure
- **ReregistrationDataProcessorTest** — 19 tests covering `sanitize_working_hours()` (valid/invalid JSON, missing day key, type casting, optional fields) and `validate_submission()` (required fields, CPF/phone validation, division-department consistency, custom field required/format/regex/email validation)

### Fixed

- **AudienceCsvImporterTest** — 5 tests using Mockery alias mocks for `AudienceRepository` now run in separate processes (`@runInSeparateProcess`) to prevent alias contamination of subsequent test classes
- 218 → 253 tests, 453 → 710 assertions

---

## [4.12.11] (2026-02-17) — `1c41e07`

Unit tests for Audience module: CSV importer and notification handler.

### Added

- **AudienceCsvImporterTest** — 26 tests covering `validate_csv()`, `get_sample_csv()`, `import_members()`, and `import_audiences()` (header normalization, missing columns, empty rows, invalid emails, existing users, duplicate members, parent-before-child creation order, default color fallback)
- **AudienceNotificationHandlerTest** — 10 tests covering `render_template()` variable substitution (user, booking, cancellation, site, and optional keys), subject generation, and default template placeholder completeness

### Fixed

- 182 → 218 tests, 352 → 453 assertions

---

## [4.12.10] (2026-02-17) — `75214c4`

Security hardening: regex validation, AJAX method enforcement, modern CSPRNG, prepared SQL statements.

### Changed

- Rebuilt all minified JS assets

### Fixed

- **LiteSpeed hook prefix warning** — added `phpcs:ignore` for `litespeed_control_set_nocache` in `DashboardShortcode` (hook name is defined by LiteSpeed Cache plugin, not ours)

### Security

- **Regex validation** — custom regex patterns in `ReregistrationDataProcessor` now use `~` delimiter (avoids conflicts with `/` in patterns), and validate the pattern before applying it; invalid patterns are safely skipped instead of suppressed with `@`
- **AJAX method enforcement** — `AudienceLoader::ajax_search_users()` and `ajax_get_environments()` switched from `$_GET` to `$_POST`; updated corresponding JS (`ffc-audience.js`, `ffc-audience-admin.js`) to use `POST` method
- **Modern CSPRNG** — replaced deprecated `openssl_random_pseudo_bytes()` with `random_bytes()` in `Encryption::encrypt()` for IV generation
- **Prepared SQL statements** — 3 `SHOW INDEX` queries in `Activator`, `DatabaseHelperTrait`, and `SelfSchedulingActivator` now use `$wpdb->prepare()` with `%i` identifier placeholder (WordPress 6.2+) instead of string interpolation

---

## [4.12.9] (2026-02-17) — `94f2c8c`

Fix: math captcha showing raw HTML as visible text on cached pages.

### Changed

- Rebuilt all minified JS assets

### Fixed

- **Captcha label raw HTML** — `ffc-dynamic-fragments.js` used `textContent` to set the captcha label which rendered `<span class="required">*</span>` as visible text instead of HTML; separated the required asterisk indicator from the label data and added `<span class="ffc-captcha-label-text">` wrapper so JS targets only the text portion
- **Form processor captcha refresh** — inline captcha generation in `FormProcessor` replaced with `Utils::generate_simple_captcha()` call for consistency

### Security

- All captcha label refreshes now use `.text()`/`textContent` (never `.html()`/`innerHTML`), keeping XSS hardening from v4.12.6

---

## [4.12.8] (2026-02-17) — `83e9599`

> Refactor Utils (dead code removal) and ReregistrationFrontend (1,330 lines → coordinator + 3 sub-classes).

### Changed

- **ReregistrationFrontend** split into 3 focused sub-classes: `ReregistrationFieldOptions` (field option data), `ReregistrationFormRenderer` (form HTML rendering with per-fieldset methods), `ReregistrationDataProcessor` (data collection, validation, submission processing)
- **ReregistrationFormRenderer** — broke 616-line `render_form()` into 8 focused private methods (`render_personal_data_fieldset`, `render_contacts_fieldset`, `render_schedule_fieldset`, `render_accumulation_fieldset`, `render_union_fieldset`, `render_acknowledgment_fieldset`, `render_custom_fields_fieldset`, `render_custom_field`)

### Removed

- **3 unused public methods** from Utils — `is_local_environment()`, `is_valid_ip()`, `validate_email()` + private `get_disposable_email_domains()` (zero external callers)

---

## [4.12.7] (2026-02-17) — `eda6b69`

> Refactor UserDataRestController (1,415 lines → coordinator + 6 sub-controllers).

### Added

- **UserContextTrait** — shared `resolve_user_context()` and `user_has_capability()` methods extracted into reusable trait used by all sub-controllers

### Changed

- **UserDataRestController** split into 6 focused sub-controllers: `UserCertificatesRestController`, `UserProfileRestController`, `UserAppointmentsRestController`, `UserAudienceRestController`, `UserSummaryRestController`, `UserReregistrationsRestController`
- **UserDataRestController** — now a thin coordinator (155 lines) with backward-compatible delegate methods and lazy-loaded sub-controllers

### Fixed

- **UserDataRestControllerTest** — added `wp_cache_get`/`wp_cache_set` stubs to fix 3 pre-existing RateLimiter errors in change_password and privacy_request tests

---

## [4.12.6] (2026-02-17) — `7440601`

Frontend cleanup: console.log removal, XSS hardening, CSS consolidation.

### Changed

- Rebuilt all minified JS and CSS assets

### Fixed

- **CSS `.ffc-badge` overlap** — removed duplicate base class from ffc-admin-submissions.css; canonical definition now lives in ffc-common.css with unified padding/font-size
- **CSS `.ffc-notice-*` overlap** — namespaced audience notice variants under `.ffc-audience-notice` to prevent cascade conflicts with user dashboard notices

### Removed

- **58 console.log calls** from production JS files (ffc-pdf-generator, ffc-admin-pdf, ffc-admin-field-builder, ffc-core); kept console.error/warn for legitimate error reporting; disabled html2canvas debug logging

### Security

- **XSS hardening** — replaced unsafe `.html()` and `.innerHTML` with `.text()`, `.textContent`, and `escapeHtml()` in 7 files: ffc-dynamic-fragments (captcha label), ffc-reregistration-frontend (server messages, select options), ffc-calendar-frontend (error messages, user input, validation code, receipt URL), ffc-frontend (alert messages, error display), ffc-admin-pdf (image preview)

---

## [4.12.5] (2026-02-17) — `e45856e`

Tests for critical classes: SubmissionHandler, UserCreator, CapabilityManager, and UserDataRestController endpoint callbacks.

### Added

- **SubmissionHandlerTest** — 21 tests covering process_submission (encryption, ticket_hash, consent fields, data field), get_submission, get_submission_by_token, trash/restore/delete, bulk operations, and ensure_magic_token
- **UserCreatorTest** — 12 tests covering generate_username (name fields, collision handling, fallback to random, special characters) and get_or_create_user (CPF match, email match, new user creation, error handling, capability granting)
- **CapabilityManagerTest** — 27 tests covering constants, get_all_capabilities, grant/revoke context dispatch, skip existing caps, has_certificate_access, has_appointment_access, get/set/reset user capabilities, register/remove role
- Infrastructure: **Test bootstrap** — added WP stub classes (WP_Error, WP_Role, WP_User) and WordPress crypto constants for encryption-aware testing

### Changed

- **UserDataRestControllerTest** — added 11 endpoint callback error-path tests (not-logged-in, no-capability, invalid-input) beyond the 14 existing route registration tests

### Fixed

- **SubmissionHandler bulk methods** — `bulk_trash_submissions()`, `bulk_restore_submissions()`, `bulk_delete_submissions()` declared `: array` return type but returned `int`; removed incorrect type declaration
- **SubmissionHandler WP_Error namespace** — `new WP_Error(...)` resolved to wrong namespace; fixed to `new \WP_Error(...)`

---

## [4.12.4] (2026-02-17) — `415ac1f`

Performance and reliability: changelog extraction, ticket hash column, LIKE-on-JSON elimination.

### Added

- **CHANGELOG.md** — full version history extracted from readme.txt into dedicated changelog file; readme.txt now shows only recent versions with pointer to CHANGELOG.md
- **`ticket_hash` column** on submissions table — stores deterministic hash of ticket restriction value for indexed lookups; new composite index `idx_form_ticket_hash` on `(form_id, ticket_hash)`

### Changed

- **Eliminated LIKE on JSON** for ticket lookups — `detect_reprint()` now uses indexed `ticket_hash = %s` instead of `data LIKE '%"ticket":"VALUE"%'` when encryption is configured

### Fixed

- **Ticket reprint detection with encryption** — when data encryption was enabled, the `data` column is NULL so LIKE-based ticket lookup always failed silently; now uses `ticket_hash` for hash-based lookup, falling back to LIKE only for legacy unencrypted data

---

## [4.12.3] (2026-02-17) — `e20818e`

Security hardening: SQL injection prevention, XSS mitigation, and modal accessibility improvements.

### Changed

- **Modal ARIA attributes** — added `role="dialog"`, `aria-modal="true"`, `aria-labelledby` to audience booking modal, day detail modal, and admin booking modal
- **Focus trapping** — implemented keyboard focus trap (Tab/Shift+Tab) for audience booking and day modals, matching existing calendar-frontend pattern
- **Focus management** — modals now move focus to close button on open and return focus to trigger element on close
- **Escape key** — audience modals, admin booking modal, and template modal now close on Escape key press
- **Close button labels** — added `aria-label="Close"` to all modal close buttons

### Security

- **RateLimiter SQL** — all 11 queries now use `$wpdb->prepare()` with `%i` identifier placeholder for table names; removed blanket `phpcs:disable` for `PreparedSQL.NotPrepared`
- **IpGeolocation SQL** — `clear_cache()` now uses `$wpdb->prepare()` with `%i` for table name and `%s` for LIKE patterns; removed blanket `phpcs:disable`
- **XSS prevention** — added `escapeHtml()` utility to `ffc-frontend-helpers.js` and `ffc-audience-admin.js`; escaped user-controlled data in error messages, search results, selected user display, and conflict audience names
- **Migration onclick removal** — replaced inline `onclick="return confirm(...)"` with `data-confirm` attribute on migration buttons; JS now reads from data attribute instead of parsing onclick regex

---

## [4.12.2] (2026-02-17) — `5710b33`

> God class refactoring: UserManager and ActivityLog split into single-responsibility classes with full backward compatibility.

### Changed

- **CapabilityManager** — extracted from UserManager; handles all FFC capability constants, role registration, context-based granting, access checks, and per-user capability management
- **UserCreator** — extracted from UserManager; handles get_or_create_user flow, WordPress user creation, orphaned record linking, username generation, metadata sync, and profile creation
- **ActivityLogQuery** — extracted from ActivityLog; handles get_activities, count_activities, get_stats, get_submission_logs, cleanup, and run_cleanup
- **UserManager** — reduced from ~1,150 to ~400 lines; retains profile CRUD and data retrieval methods; delegates capabilities to CapabilityManager and user creation to UserCreator via backward-compatible constant aliases and method wrappers
- **ActivityLog** — reduced from ~800 to ~520 lines; retains core logging, buffer management, and convenience methods; delegates query/stats/cleanup to ActivityLogQuery

---

## [4.12.1] (2026-02-16) — `fd17071`

Test coverage expansion: from 3 to 9 test files, covering critical security and business logic paths.

### Added

- **EncryptionTest** — 19 tests covering AES-256-CBC round-trip, unique IV per encryption, hash determinism, batch encrypt/decrypt, decrypt_field fallback, appointment decryption, Unicode handling, and is_configured()
- **RateLimiterTest** — 14 tests covering IP limit allow/block/cooldown, verification limits (hour/day), user rate limits, check_all blacklist/whitelist integration, email limit bypass, domain and CPF blacklisting
- **FormProcessorRestrictionsTest** — 15 tests covering password validation (required/incorrect/correct), denylist blocking, allowlist enforcement, ticket validation with case-insensitive matching and consumption, denylist-over-allowlist priority, quiz score calculation (correct/wrong/empty/unanswered)
- **SubmissionRestControllerTest** — 8 tests verifying route registration count, admin permission on list/single endpoints, public verify endpoint, auth_code validation (rejects <12 chars), pagination args
- **UserDataRestControllerTest** — 12 tests verifying all 11 user routes require `is_user_logged_in`, profile supports GET+PUT, all route paths registered correctly
- **SubmissionRepositoryTest** — 12 tests covering table name, cache group, ORDER BY sanitization (rejects SQL injection), bulk operations on empty arrays, countByStatus aggregation, cache behavior, insert/update/delete via mock wpdb

### Changed

- **Bootstrap** — added WP_REST_Server stub, OBJECT_K/ARRAY_A/DB_NAME constants, updated FFC_VERSION to match plugin

### Fixed

- Total: **108 tests, 210 assertions** (previously 14 tests, 23 assertions)

---

## [4.12.0] (2026-02-16) — `20166f0`

Full-page cache compatibility: forms now work correctly behind LiteSpeed, Varnish, and other page caches.

### Added

- **Dynamic Fragments endpoint** — lightweight AJAX endpoint (`ffc_get_dynamic_fragments`) returns fresh captcha and nonces on every page load, ensuring forms work on cached pages
- **Client-side captcha refresh** — JavaScript module (`ffc-dynamic-fragments.js`) patches captcha label, hash, and nonces in the DOM immediately after DOMContentLoaded
- **Nonce refresh** — `ffc_frontend_nonce` and `ffc_self_scheduling_nonce` are refreshed via AJAX, preventing expired-nonce errors on cached pages
- **Booking form AJAX pre-fill** — logged-in user name and email are populated via AJAX instead of server-side rendering, preventing cached pages from showing another user's data
- **Dashboard cache exclusion** — pages containing `[user_dashboard_personal]` automatically send `nocache_headers()`, `X-LiteSpeed-Cache-Control: no-cache`, and `litespeed_control_set_nocache` action
- **Page Cache Compatibility card** — new status card in Cache settings tab showing the state of all cache-compatibility features (Dynamic Fragments, Dashboard Exclusion, Object Cache, AJAX Endpoints)
- **Redis detection notice** — Cache settings tab warns when Redis/Memcached is not installed, explaining impact on rate limiter counter persistence

---

## [4.11.0] (2026-02-15) — `a1373c9`

Audience custom fields, reregistration campaigns, ficha PDF, email notifications, and audience hierarchy enhancements.

### Added

- **Audience Custom Fields** — define per-audience custom fields (text, textarea, number, date, select, checkbox) with validation rules (CPF, email, phone, regex)
- **Custom Fields on User Profile** — "FFC Custom Data" section on WordPress user edit screen showing fields grouped by audience with collapsible sections
- **Custom field inheritance** — child audiences inherit fields from parent audiences
- **Reregistration campaigns** — create campaigns linked to audiences with configurable start/end dates, auto-approve, and email settings
- **Reregistration frontend form** — dashboard banner with submission form, draft saving, and field validation
- **Reregistration admin UI** — manage campaigns, review/approve/reject submissions, bulk actions, filters
- **Reregistration email notifications** — invitation (on activation), reminder (N days before deadline via cron), and confirmation (on submission) emails
- **Ficha PDF** — generate PDF records for reregistration submissions with custom template support
- **Ficha download** — available in admin submissions list and user dashboard for submitted/approved submissions
- **Audience hierarchy tree** — recursive rendering with unlimited depth, member counts including children, breadcrumb navigation
- **REST API endpoints** — `GET /user/reregistrations`, `POST /user/reregistration/{id}/submit`, `POST /user/reregistration/{id}/draft`
- **Migration** — `MigrationCustomFieldsTables` ensures tables exist on upgrade from pre-4.11.0 versions
- **Documentation** — 3 new sections: Audience Custom Fields, Reregistration, Ficha PDF
- **pt_BR translations** — all new strings translated to Portuguese
- 3 database tables: `ffc_custom_fields`, `ffc_reregistrations`, `ffc_reregistration_submissions`
- Email templates: `reregistration-invitation.php`, `reregistration-reminder.php`, `reregistration-confirmation.php`
- Ficha HTML template: `html/default_ficha_template.html`

---

## [4.9.10] (2026-02-14) — `c98d898`

Profile UX improvements and audience group self-assignment.

### Added

- **Audience group self-join** — users can join/leave groups marked as "Allow Self-Join" directly from their dashboard (max 2 per user)
- `allow_self_join` column on audiences table with admin toggle in audience edit form
- REST endpoints: `GET /user/joinable-groups`, `POST /user/audience-group/join`, `POST /user/audience-group/leave`
- Audience capabilities are automatically granted when user joins a group

### Changed

- **Edit Profile and Change Password buttons** are now in a prominent action bar below profile fields
- Password form has a styled container with slide animation

---

## [4.9.9] (2026-02-14) — `4554992`

Security hardening, expanded audit trail, and LGPD compliance improvements.

### Added

- **Rate limiting by user_id** — `RateLimiter::check_user_limit()` protects authenticated endpoints (password change: 3/hour, privacy requests: 2/hour)
- **Activity log convenience methods** — `log_password_changed()`, `log_profile_updated()`, `log_capabilities_granted()`, `log_privacy_request()` for comprehensive audit trail
- **Email on capability grant** — optional email notification when certificate, appointment, or audience capabilities are granted (controlled by `notify_capability_grant` setting)
- **LGPD/GDPR usermeta export** — new exporter for `ffc_*` user meta entries via WordPress Privacy Tools
- **LGPD/GDPR usermeta erasure** — `ffc_*` user meta entries are now deleted during privacy erasure requests

### Changed

- Password change, profile update, and privacy request endpoints now log to activity log
- Capability grant methods now track newly granted capabilities and fire activity log + email

---

## [4.9.8] (2026-02-14) — `d9f6ed3`

Dashboard UX improvements and user self-service features.

### Added

- **Dashboard summary cards** — overview at the top showing certificate count, next appointment, and upcoming group events
- **Search and date filters** — filter bar on certificates, appointments, and audience bookings tabs with date range and text search
- **Password change** — users can change their password directly from the Profile tab (no wp-admin access needed)
- **LGPD self-service** — "Export My Data" and "Request Data Deletion" buttons in Profile tab, using WordPress native privacy request system
- **Notes field** — editable personal notes in profile (uses existing `ffc_user_profiles.notes` column)
- **Notification preferences** — toggle switches for appointment confirmation, appointment reminder, and new certificate emails (all disabled by default)
- **Configurable pagination** — choose 10, 25, or 50 items per page on all tabs (persisted in localStorage)
- REST endpoints: `POST /user/change-password`, `POST /user/privacy-request`, `GET /user/summary`

### Changed

- Profile REST API now returns `notes` and `preferences` fields
- `UserManager::update_profile()` now supports `preferences` JSON column

---

## [4.9.7] (2026-02-14) — `f7496c2`

Performance, view-as accuracy, centralized user service, and database referential integrity.

### Added

- **Batch count queries** in admin users list — certificate and appointment counts loaded via single GROUP BY query per table instead of N+1 per-user queries
- **UserService class** — centralized service (`FreeFormCertificate\Services\UserService`) for profile retrieval, capability checks, and user statistics
- **FOREIGN KEY constraints** — 7 FK constraints added to FFC tables referencing `wp_users(ID)`:
  - SET NULL on delete: `ffc_submissions`, `ffc_self_scheduling_appointments`, `ffc_activity_log`
  - CASCADE on delete: `ffc_audience_members`, `ffc_audience_booking_users`, `ffc_audience_schedule_permissions`, `ffc_user_profiles`
  - InnoDB engine check: FKs skipped gracefully if engine is not InnoDB
  - Orphaned references cleaned automatically before FK creation

### Fixed

- **View-as capability check** — admin view-as mode now uses TARGET user's capabilities (not admin's); admin sees exactly what the user would see for certificates, appointments, and audience bookings
- **Autoloader** — added missing `Privacy` and `Services` namespace mappings

---

## [4.9.6] (2026-02-14) — `dccd36c`

Editable user profile, orphaned record linking, and username privacy fix.

### Added

- **PUT /user/profile** REST endpoint — users can update display_name, phone, department, organization from the dashboard
- **Profile edit form** in user dashboard — toggle between read-only view and edit form with save/cancel actions
- **Phone, department, organization** fields displayed in the read-only profile view
- **Orphaned record linking** — `get_or_create_user()` now retroactively links submissions and appointments that share the same cpf_rf_hash but had no user_id
- **Appointment capability auto-grant** — when orphaned appointments are linked, appointment capabilities are granted automatically
- `UserManager::generate_username()` public method — generates unique slugified username from name data

### Fixed

- **Username = email** privacy issue — `create_ffc_user()` now generates username from name slug (e.g. "joao.silva") instead of using email; fallback to `ffc_` + random string
- **MigrationUserLink** updated to use `generate_username()` for new user creation during migration

---

## [4.9.5] (2026-02-14) — `406cb1a`

LGPD/GDPR compliance: WordPress Privacy Tools integration (Export & Erase Personal Data).

### Added

- **Privacy Exporter** — 5 data groups registered with WordPress Export Personal Data tool:
  - FFC Profile (display_name, email, phone, department, organization, member_since)
  - FFC Certificates (form_title, submission_date, auth_code, email, consent)
  - FFC Appointments (calendar, date, time, status, name, email, phone, notes)
  - FFC Audience Groups (audience_name, joined_date)
  - FFC Audience Bookings (environment, date, time, description, status)
- **Privacy Eraser** — registered with WordPress Erase Personal Data tool:
  - Submissions: anonymized (user_id=NULL, email/cpf cleared; auth_code and magic_link preserved for public verification)
  - Appointments: anonymized (user_id=NULL, all PII fields cleared)
  - Audience members/booking users/permissions: deleted
  - User profiles: deleted
  - Activity log: anonymized (user_id=NULL)
- **PrivacyHandler class** with paginated export (50 items/batch) and single-pass erasure
- Encrypted fields decrypted during export for complete data portability

---

## [4.9.4] (2026-02-14) — `008d642`

User profiles table, user deletion handling, and email change tracking.

### Added

- **`ffc_user_profiles` table** — centralized user profile storage (display_name, phone, department, organization, notes, preferences)
- **User deletion hook** — `deleted_user` action anonymizes FFC data (SET NULL on submissions/appointments/activity, DELETE on audience/profiles)
- **Email change handler** — `profile_update` action reindexes `email_hash` on submissions when user email changes
- **Profile methods** in UserManager — `get_profile()`, `update_profile()`, `create_user_profile()` with upsert logic
- **Profile migration** — `MigrationUserProfiles` populates profiles from existing ffc_users (display_name, registration date)
- **REST API profile fields** — `GET /user/profile` now returns `phone`, `department`, `organization` from profiles table
- **UserCleanup class** — handles `deleted_user` and `profile_update` hooks with activity logging

### Fixed

- **uninstall.php** — added `ffc_user_profiles` to DROP TABLE list and migration options to cleanup

---

## [4.9.3] (2026-02-14) — `bca0c47`

> Capability system refactoring: centralized constants, enforced checks, simplified role model.

### Added

- **Centralized capability constants** — `AUDIENCE_CAPABILITIES`, `ADMIN_CAPABILITIES`, `FUTURE_CAPABILITIES` and `get_all_capabilities()` method in UserManager
- **Audience context** — `CONTEXT_AUDIENCE` constant and `grant_audience_capabilities()` for audience group members
- **`download_own_certificates` enforced** — users without this capability no longer receive `magic_link`/`pdf_url` in dashboard API
- **`view_certificate_history` enforced** — users without this capability see only the most recent certificate per form

### Changed

- **Simplified role model** — `ffc_user` role now has all FFC capabilities as `false`; user_meta is the sole source of truth
- **Removed redundant reset** — `reset_user_ffc_capabilities()` no longer called during user creation (role no longer grants caps by default)
- **`upgrade_role()` uses centralized list** — new capabilities added as `false` automatically

### Fixed

- **CSV importer capabilities** — replaced 3 hardcoded `add_cap()` calls with centralized `UserManager::grant_certificate_capabilities()`
- **uninstall.php cleanup** — added 4 missing capabilities: `ffc_scheduling_bypass`, `ffc_view_audience_bookings`, `ffc_reregistration`, `ffc_certificate_update`
- **Admin UI save** — `save_capability_fields()` now references `UserManager::get_all_capabilities()` instead of hardcoded list

---

## [4.9.2] (2026-02-13) — `0870d29`

UX improvements, race condition fix, and PHPCS compliance.

### Added

- **Textarea auto-resize** — textarea fields in certificate forms grow automatically as user types (up to 300px, then scrollbar), with manual resize support

### Fixed

- **Calendar month navigation race condition** — rapid month clicks no longer show stale data; uses incremental fetch ID to discard superseded responses (both self-scheduling and audience calendars)
- **Form field labels capitalization** — labels now respect original formatting regardless of theme CSS
- **LGPD consent box overflow** — encryption warning no longer exceeds consent container bounds
- **Form field attributes** — corrected esc_attr() misuse on HTML attributes (textarea, select, radio, input)
- PHPCS compliance — nonce verification, SQL interpolation, global variable prefixes, unescaped DB parameters, offloaded resources, readme limits

---

## [4.9.1] (2026-02-12) — `ffad599`

Calendar display improvements, custom booking labels, audience badge format, and bug fixes.

### Added

- **Collapse parent audiences** — when a parent audience with all children is selected, display only the parent in frontend badges
- **Audience badge format** option per calendar — choose between name only or "Parent: Child" format
- **Custom booking badge labels** per calendar — configurable singular/plural labels for booking count in day cells (with global fallback)

### Fixed

- **Geofence GPS checkbox** not saving when unchecked — added hidden sentinel field so unchecked state is properly persisted
- **Migration cascade failure** — removed `AFTER` clauses from ALTER TABLE migrations that caused silent failures when referenced columns didn't exist
- **Booking labels missing** from logged-in user config — bookingLabelSingular/bookingLabelPlural were only passed in the public config path
- **Public calendar event details** — REST API now returns description, environment name, and audiences for non-authenticated users on public calendars

---

## [4.9.0] (2026-02-12) — `5aea1ba`

New field types, Quiz/Evaluation mode for scored forms, and certificate quiz tags.

### Added

- **Info Block** field type — display-only rich text content in forms (supports HTML: bold, italic, links, lists)
- **Embed (Media)** field type — embed YouTube, Vimeo, images, or audio via URL with optional caption
- **Quiz / Evaluation Mode** — turn any form into a scored quiz with configurable passing score
- Quiz **points per option** on Radio, Select, and Checkbox fields (comma-separated values matching option order)
- Quiz **max attempts** per CPF/RF — configurable retry limit (0 = unlimited)
- Quiz **score feedback** — show score and correct/incorrect answers after submission
- Quiz **attempt tracking** — submissions tracked by CPF/RF with statuses: published, retry, failed
- Quiz **status badges** in admin submissions list — color-coded badges with score percentage
- Quiz **filter tabs** in admin — filter submissions by Published, Trash, Quiz: Retry, Quiz: Failed
- Certificate tags **{{score}}**, **{{max_score}}**, **{{score_percent}}** for quiz results in PDF layout
- pt_BR translations for all quiz, info block, and embed strings

---

## [4.8.0] (2026-02-11) — `95040fd`

Calendar UX improvements, environment colors, event list panel, admin enhancements, and export functionality.

### Added

- Environment **color picker** — assign distinct colors to each environment (admin + frontend)
- **Event list panel** — optional side or below panel showing upcoming bookings for the current month
- Event list admin settings — enable/disable and position (side or below calendar)
- **All-day event** checkbox — marks bookings as all-day (stores 00:00–23:59), blocks entire environment for the day
- All-day events display "All Day" label instead of time range in day modal and event list
- Holidays now displayed in event list panel alongside bookings (sorted by date)
- **"Multiple audiences" badge** in event list when a booking has more than 2 audiences
- Multiple audiences badge **color configurable** in Audience settings tab
- **CSV export** for members (email, name, audience_name) with optional audience filter
- **CSV export** for audiences (name, color, parent) in import-compatible format
- Import page renamed to **"Import & Export"** with tabbed navigation (Import / Export)
- Admin **feedback notices** for create, save, deactivate, and delete actions on calendars, environments, and audiences
- **Soft-delete pattern** — active items are deactivated first; only inactive items can be permanently deleted (calendars, environments, audiences)
- **Booking View** button in admin — opens AJAX modal with full booking details (audiences, users, creator, status)
- **Booking Cancel** button in admin — AJAX cancel with confirmation prompt and mandatory reason
- **Filter overlay** on submissions page — replaced multi-select with overlay modal, forms ordered by ID desc
- Added `is_all_day`, `show_event_list`, `event_list_position`, and `color` columns with automatic migration

### Changed

- Calendar max-width adjusted to 600px standalone, 1120px with event list (600px + 20px gap + 500px panel)
- Calendar day cells now use `aspect-ratio: 1` for consistent square grid layout
- Environment colors shown as left border on booking items in day modal and event list

### Fixed

- FFC Users redirect — only block wp-admin access when ALL user roles are in the blocked list (was blocking when ANY role matched)
- Environment label not reaching frontend — `get_schedule_environments()` now includes `name` field in config
- Environment label fallback "Ambiente" was not wrapped in `__()` for translation
- Holiday names no longer shown in calendar day cells — displays generic "Holiday" label only (full name in event list)
- Holiday label fix applied to both audience and self-scheduling calendars
- Badge overflow in day cells — badges now truncate with ellipsis instead of overflowing

---

## [4.7.0] (2026-02-09) — `97b1132`

Visibility and scheduling controls for calendars, admin bypass system.

### Added

- Per-calendar **Visibility** control (Public/Private) for self-scheduling calendars
- Per-calendar **Scheduling** control (Public/Private) for self-scheduling calendars
- Audience calendars visibility now supports non-logged-in visitors (public calendars show read-only view)
- Audience scheduling is always private — requires login + audience group membership
- Configurable display modes for private calendars: show message, show title + message, or hide
- Customizable visibility and scheduling messages with login link (%login_url% placeholder)
- Settings in Self-Scheduling and Audience tabs for message configuration
- `ffc_scheduling_bypass` capability for granting admin-level scheduling access to non-admin users
- Admin bypass system — admins can book past dates, out-of-hours, blocked dates, and holidays
- Admin bypass visual indicators — "Private" badge and bypass notice on frontend
- Admin cancellation with mandatory reason in appointments list (prompt for reason)
- Non-logged-in users can view public audience calendars with occupancy data (no personal details)
- `require_login` and `allowed_roles` fields replaced by `visibility` and `scheduling_visibility`
- Existing data automatically migrated (require_login=1 → private/private, 0 → public/public)

### Changed

- Default visibility — self-scheduling: Public/Public, audience: Private
- Slot limit (max_appointments_per_slot) is never bypassed, even for admins
- REST API now filters calendars by visibility for non-authenticated requests
- REST API rejects bookings when scheduling is private and user is not authenticated
- Audience shortcode split into reusable `render_calendar_html()` for shared rendering
- All `current_user_can('manage_options')` scheduling checks use `userHasSchedulingBypass()`
- Added styles for visibility restrictions, scheduling restrictions, admin bypass notices, and badges

---

## [4.6.16] (2026-02-08) — `07b4c36`

Settings UX, dead code removal, code deduplication, version centralization, and bug fixes.

### Added

- UX: Reorganize settings into 9 tabs — new Cache and Advanced tabs, QR Code tab removed
- FFC_JQUERY_UI_VERSION constant for centralized jQuery UI CDN version management
- Dynamic JS console version via wp_localize_script — modules now show FFC_VERSION instead of hardcoded 3.1.0

### Changed

- UX: Move debug flags and danger zone to Advanced tab, cache settings to Cache tab
- Extract duplicate get_option() from 4 tab classes into base SettingsTab class
- Extract duplicate dark mode enqueue into Utils::enqueue_dark_mode() (shared by admin + frontend)
- View files delegate to tab method via Closure::fromCallable instead of inline lambdas

### Fixed

- TypeError in SettingsTab::get_option() — cast return value to string (strict_types with int DB values)
- phpqrcode cache directory warning — disable QR_CACHEABLE (plugin has own QR cache via transients)
- Missing icons on user dashboard — load ffc-common.css (defines .ffc-icon-* classes) + dark mode support
- Outdated FFC_VERSION in tests/bootstrap.php (4.6.13 → 4.6.16)
- Outdated stable tag in readme.txt (4.6.15 → 4.6.16)
- Hardcoded '4.1.0' version in self-scheduling admin enqueues — now uses FFC_VERSION

### Removed

- Remove dead QR Code tab files (class + view), empty Admin::admin_assets(), unused CsvExporter::export_to_csv() wrapper
- Remove 'template' alias from PdfGenerator, empty Loader::run() method, old ffc_ cron hook migration code
- Remove 50+ stale version annotation comments across 21 files
- Remove commented-out debug error_log() and TinyMCE CSS blocks
- Unnecessary defined() fallbacks for library version constants in self-scheduling shortcode
- Remove redundant = null initialization from 16 Loader properties

---

## [4.6.15] (2026-02-08) — `c7506a1`

Plugin Check Compliance: Hook prefix, SQL placeholders, deprecated API removal, and query caching.

### Changed

- Add wp_cache_get/set to 5 audience repository read queries (is_holiday, count, get_user_audiences, search)

### Fixed

- Rename all 44 hook names from ffc_ to ffcertificate_ prefix for WordPress Plugin Check compliance
- Add WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare to phpcs:ignore for dynamic IN() queries
- Add WordPress.DB.PreparedSQL.InterpolatedNotPrepared to phpcs:ignore for safe table name interpolation
- Migrate old ffc_daily_cleanup_hook cron to new ffcertificate_daily_cleanup_hook name on init
- Clean up both old and new cron hook names in deactivator and uninstall.php

### Removed

- Remove deprecated load_plugin_textdomain() call (automatic since WordPress 4.6)

---

## [4.6.14] (2026-02-08) — `9b7ffb9`

Accessibility & Responsive Design: Dark mode, CSS variables, ARIA attributes, and template accessibility.

### Changed

- A11y: Add semantic CSS design tokens (40+ variables for surfaces, text, borders, status colors) in :root
- A11y: Add automatic dark mode via prefers-color-scheme media query with full variable overrides
- A11y: Replace 50+ hardcoded colors in ffc-frontend.css with CSS custom properties
- A11y: Add role="status" and aria-live="polite" to submission success template
- A11y: Add role="progressbar" with aria-valuenow/min/max to migration progress bars
- A11y: Add screen-reader-text labels for activity log filter/search controls
- A11y: Add scope="col" to all documentation tab table headers

---

## [4.6.13] (2026-02-08) — `bdfa1a2`

Performance: Query caching, conditional loading, and N+1 elimination. Quality: i18n, documentation, icon CSS refactor.

### Changed

- Cache RateLimiter settings in static variable (eliminates 10+ repeated get_option + __() calls per request)
- Cache SHOW TABLES check in AdminUserColumns (eliminates N+1 query per user row on users list)
- Cache dashboard URL in AdminUserColumns render_user_actions (eliminates repeated get_option per row)
- Cache INFORMATION_SCHEMA column existence checks in SubmissionRepository (eliminates repeated schema queries)
- Fix ActivityLog get_submission_logs() to use existing cached get_table_columns() instead of raw DESCRIBE
- Conditional class loading — skip admin-only classes (CsvExporter, Admin, AdminAjax, AdminUserColumns, AdminUserCapabilities, SelfSchedulingAdmin, SelfSchedulingEditor, AppointmentCsvExporter) on frontend page loads
- i18n: Wrap 7 hardcoded strings (4 Portuguese, 3 English) with __() for proper translation support
- Move 40+ inline emoji icons from PHP/HTML to CSS utility classes (ffc-icon-*) in ffc-common.css

### Documentation

- Add missing shortcodes [ffc_self_scheduling] and [ffc_audience] to documentation tab
- Add missing PDF placeholders {{submission_id}}, {{main_address}}, {{site_name}} to documentation tab

---

## [4.6.12] (2026-02-08) — `5f153b8`

Quality: Unit testing, i18n compliance, and asset minification.

### Added

- Add PHPUnit test infrastructure (composer.json, phpunit.xml.dist, bootstrap)
- Add 14 unit tests covering Geofence bypass, ActivityLog buffer, and EmailHandler contexts
- Generate minified .min.css and .min.js for all 34 plugin assets (~45% average size reduction)
- Conditional asset loading — serve .min files in production, full files when SCRIPT_DEBUG is on
- Add Utils::asset_suffix() helper for consistent minification suffix across all enqueue calls

### Fixed

- Replace 13 hardcoded Portuguese strings in RateLimiter with __() for proper i18n
- PHPUnit bootstrap load order — define ABSPATH before requiring autoloader (prevents silent exit)

---

## [4.6.11] (2026-02-08) — `b68d66f`

Security hardening: REST API protection, uninstall cleanup, deprecated API removal.

### Added

- Add uninstall.php — full cleanup of all tables, options, roles, capabilities, transients, and cron hooks on plugin deletion

### Fixed

- Replace all deprecated current_time('timestamp') calls (deprecated since WP 5.3) with time() + wp_date()
- Timezone-aware datetime comparisons in Geofence, AppointmentValidator, and AppointmentHandler using DateTimeImmutable + wp_timezone()

### Security

- Add geofence validation (date/time + IP) to REST API form submission endpoint
- Add rate limiting to REST API appointment creation endpoint
- Remove error_reporting() suppression in REST controller (use output buffering only)

---

## [4.6.10] (2026-02-08) — `905708d`

Fix: Race condition in concurrent appointment booking (TOCTOU vulnerability).

### Fixed

- Wrap validate + insert in MySQL transaction with row-level locking (FOR UPDATE)
- Add transaction support (begin/commit/rollback) to AbstractRepository
- AppointmentRepository::isSlotAvailable() now supports FOR UPDATE lock
- AppointmentRepository::getAppointmentsByDate() now supports FOR UPDATE lock
- AppointmentValidator accepts lock flag for capacity queries inside transaction
- Upgrade validation_code index from KEY to UNIQUE KEY (prevents duplicate codes)
- Catch exceptions during booking and rollback on failure

---

## [4.6.9] (2026-02-08) — `86a0020`

Performance: Activity Log optimization with batch writes, auto-cleanup, and stats caching.

### Added

- Automatic log cleanup via daily cron with configurable retention period (default 90 days)
- Add "Log Retention (days)" setting under Settings > General > Activity Log

### Changed

- Buffer activity log writes and flush as single multi-row INSERT on shutdown (or at 20-entry threshold)
- Cache get_stats() results with 1-hour transient, invalidated on cleanup and settings save

### Fixed

- Activator schema mismatch — delegate to ActivityLog::create_table() for consistent schema
- MigrationManager used undefined LEVEL_CRITICAL, changed to LEVEL_ERROR
- Schedule ffc_daily_cleanup_hook cron on activation (was registered but never scheduled)
- Clear cron on plugin deactivation

---

## [4.6.8] (2026-02-08) — `2b7a0cb`

> Refactor: Break down God classes into focused single-responsibility classes.

### Changed

- Extract AppointmentValidator from AppointmentHandler (all validation logic)
- Extract AppointmentAjaxHandler from AppointmentHandler (4 AJAX endpoints)
- Slim AppointmentHandler from 1,027 to 457 lines (core business logic only)
- Extract VerificationResponseRenderer from VerificationHandler (HTML rendering + PDF generation)
- Slim VerificationHandler from 822 to 547 lines (search + verification logic only)
- Wire AppointmentAjaxHandler via Loader using dependency injection

---

## [4.6.7] (2026-02-07) — `c2d4c03`

Accessibility: WCAG 2.1 AA compliance for all frontend components.

### Changed

- A11y: Add aria-required="true" to all required form fields (forms, booking, verification, captcha)
- A11y: Add role="group" and aria-label to radio button groups
- A11y: Add role="dialog", aria-modal="true", aria-labelledby to booking modal
- A11y: Add focus trap inside booking modal (Tab/Shift+Tab cycle)
- A11y: Return focus to trigger element on modal close
- A11y: Time slots rendered with role="option", tabindex="0", keyboard support (Enter/Space)
- A11y: Dashboard tabs use role="tablist"/role="tab"/role="tabpanel" with aria-selected and aria-controls
- A11y: Arrow key navigation between dashboard tabs (Left/Right/Home/End)
- A11y: Replace all alert() calls with accessible inline messages (role="alert")
- A11y: Add aria-invalid and aria-describedby to validation errors (CPF/RF fields)
- A11y: Add role="status" and aria-live="polite" to loading indicators and result regions
- A11y: Add role="alert" and aria-live="assertive" to form error/success message containers
- A11y: Decorative emoji wrapped in aria-hidden="true" in dashboard tabs
- A11y: Focus management after AJAX operations (form errors, booking confirmation)
- A11y: Verification page auth code input gets aria-describedby linking to description text

---

## [4.6.6] (2026-02-07) — `e23dae9`

Reliability: Standardize error handling across all modules.

### Changed

- AJAX error responses now include structured error codes alongside messages
- WP_Error codes propagated through AJAX handlers (FormProcessor, AppointmentHandler)
- AbstractRepository logs $wpdb->last_error on insert/update/delete failures
- AppointmentEmailHandler uses centralized send_mail() with failure logging
- Catch blocks in AppointmentHandler AJAX use debug_log instead of error_log/getMessage exposure

### Fixed

- Encryption catch blocks now use \Exception (namespace bug prevented catching errors)
- wp_mail() return values checked and failures logged in EmailHandler and AppointmentEmailHandler

### Security

- REST API catch blocks no longer expose internal exception messages to clients

---

## [4.6.5] (2026-02-07) — `3da77eb`

Architecture: Internal hook consumption — plugin uses its own hooks for activity logging.

### Added

- ActivityLogSubscriber class listens to ffc_ hooks for decoupled logging
- ffc_settings_saved hook now triggers cache invalidation (options + transients)

### Changed

- Architecture: Plugin "eats its own dog food" — business logic decoupled from logging

### Removed

- Removed direct ActivityLog calls from SubmissionHandler (5 calls → hook-based)
- Removed direct ActivityLog calls from AppointmentHandler (2 calls → hook-based)

---

## [4.6.4] (2026-02-07) — `b29103d`

Extensibility: Add 31 action/filter hooks for developer customization.

### Added

- Submissions: ffc_before_submission_save, ffc_after_submission_save, ffc_before_submission_update, ffc_after_submission_update, ffc_submission_trashed, ffc_submission_restored, ffc_before_submission_delete, ffc_after_submission_delete
- PDF/Certificate: ffc_certificate_data, ffc_certificate_html, ffc_certificate_filename, ffc_after_pdf_generation
- QR Code: ffc_qrcode_url, ffc_qrcode_html
- Email: ffc_before_email_send, ffc_user_email_subject, ffc_user_email_recipients, ffc_user_email_body, ffc_admin_email_recipients, ffc_scheduling_email
- Appointments: ffc_before_appointment_create, ffc_after_appointment_create, ffc_appointment_cancelled, ffc_available_slots
- Audience: ffc_before_audience_booking_create (existing: ffc_audience_booking_created, ffc_audience_booking_cancelled)
- Settings: ffc_settings_before_save, ffc_settings_saved, ffc_before_data_deletion
- Export: ffc_csv_export_data

---

## [4.6.3] (2026-02-07) — `a10a72a`

Security: Permission audit — add missing capability checks to admin handlers.

### Security

- Added `current_user_can('manage_options')` to SettingsSaveHandler (covers all settings + danger zone)
- Added capability check to migration execution handler
- Added capability check to cache warm/clear actions
- Added capability check to date format preview AJAX handler
- Tightened audience booking REST write permission (requires `ffc_view_audience_bookings` capability)

---

## [4.6.2] (2026-02-07) — `c44b819`

Performance: Fix N+1 queries and add composite database indexes.

### Added

- Database: Added composite index idx_form_status (form_id, status) on submissions table
- Database: Added composite index idx_status_submission_date (status, submission_date) on submissions table
- Database: Added composite index idx_email_hash_form_id (email_hash, form_id) on submissions table
- Database: Added composite index idx_calendar_status_date (calendar_id, status, appointment_date) on appointments table
- Database: Added composite index idx_user_status (user_id, status) on appointments table
- Database: Added composite index idx_date_status (booking_date, status) on audience bookings table
- Database: Added composite index idx_created_by_date (created_by, booking_date) on audience bookings table

### Changed

- Batch load form titles in submissions list (replaces per-row get_the_title)
- Batch load calendars in user appointments REST endpoint (replaces per-row findById)
- Batch load audiences in user audience-bookings REST endpoint (replaces per-row query)
- Batch load user data in admin bookings list (replaces per-row get_userdata)
- Added findByIds() batch method to AbstractRepository for reusable multi-ID lookups

---

## [4.6.1] (2026-02-07) — `048e11a`

Security, accessibility, code quality, and structural refactoring.

### Added

- `AudienceAdminDashboard`, `AudienceAdminCalendar`, `AudienceAdminEnvironment`, `AudienceAdminAudience`, `AudienceAdminBookings`, `AudienceAdminSettings`, `AudienceAdminImport`, `FormRestController`, `SubmissionRestController`, `UserDataRestController`, `CalendarRestController`, `AppointmentRestController`

### Changed

- Added `prefers-reduced-motion` media queries to all animations and transitions
- Added `focus-visible` styles for keyboard navigation across admin and frontend
- Added `role="presentation"` to all layout tables and `<tbody>` for HTML consistency
- Added vendor prefixes (`-webkit-`, `-moz-`) for cross-browser CSS support
- Split `AudienceAdminPage` (~2,300 lines) into coordinator + 7 focused sub-classes
- Split `RestController` (~1,940 lines) into coordinator + 5 domain-specific sub-controllers
- Text domain from `wp-ffcertificate` to `ffcertificate`
- Hook prefix from `wp_ffcertificate_` to `ffcertificate_`
- Language files renamed to match new text domain

### Fixed

- Frontend CSS duplication causing style conflicts
- Restored `Loader::run()` method accidentally removed during refactoring
- Renamed calendar asset files with `ffc-` prefix for naming consistency
- Plugin slug from `wp-ffcertificate` to `ffcertificate` (removed restricted "wp-" prefix)

### Removed

- Removed duplicate CSS declarations across stylesheets

### Security

- Fixed SQL injection vulnerabilities with prepared statements in repository queries
- Added `current_user_can('manage_options')` capability checks to audience admin form handlers
- Externalized inline CSS and JavaScript to proper asset files (XSS hardening)

---

## [4.6.0] (2026-02-06) — `c78bf74`

Scheduling consolidation, user dashboard improvements, and bug fixes.

### Added

- Unified scheduling admin menu with visual separators between Self-Scheduling and Audience sections
- Scheduling Dashboard with stats cards (calendars, appointments, environments, audiences, bookings)
- Unified Settings page with tabs for Self-Scheduling, Audience, and Global Holidays
- Global holidays system blocking bookings across all calendars in both scheduling systems
- Pagination to user dashboard (certificates, appointments, audience bookings)
- Audience groups display on user profile tab
- Upcoming/Past/Cancelled section separators on appointments tab (matching audience tab)
- Holiday and Closed legend/display on self-scheduling calendar frontend
- Dashboard icon in admin submenu

### Changed

- Cancel button only visible for future appointments respecting cancellation deadline
- Audience tab column alignment with fixed-width layout and one-tag-per-line
- Calendar frontend styles consistent for logged-in and anonymous users
- Tab labels renamed for clarity (Personal Schedule, Group Schedule, Profile)
- Stat card labels moved to top of each card
- 278 missing pt_BR translations for audience/scheduling system

### Fixed

- 500 error on profile endpoint (missing `global $wpdb`)
- SyntaxError on calendar page (`&&` mangled by `wptexturize`; moved to external JS with JSON config)
- Self-scheduling calendar not rendering (wp_localize_script timing issue; switched to JSON script tag)
- Empty audiences column (wrong table name `ffc_audience_audiences` → `ffc_audiences`)
- Cancel appointment 500 error (TypeError: string given to `findById()` expecting int)
- Error handling in AJAX handlers (use `\Throwable` instead of `\Exception`)
- Appointments tab showing time in date column
- Dashboard tab font consistency
- Missing `ffc-audience-admin.js` and calendar-admin assets causing 404s
- Incorrect translation for `{{submission_date}}` format description

---

## [4.5.0] (2026-02-05)

Audience scheduling system and unified calendar component.

### Added

- Complete audience scheduling system for group bookings (`[ffc_audience_calendar]` shortcode)
- Audience management with hierarchical groups (2-level), color coding, and member management
- Environment management (physical spaces) with per-environment calendars and working hours
- Group booking modal with audience/individual user selection and conflict detection
- CSV import for audiences (name, color, parent) and members (email, name, audience)
- Email notifications for new bookings and cancellations with audience details
- Admin bookings list page with filters by schedule, environment, status, and date range
- Audience bookings tab in user dashboard with monthly calendar view
- Shared `FFCCalendarCore` JavaScript component for both calendar systems
- Unified visual styles (`ffc-common.css`) shared between Self-Scheduling and Audience calendars
- Calendar ID and Shortcode fields on calendar edit page
- Holidays management interface with closed days display in calendar
- Environment selector dropdown in booking modal
- Filter to show/hide cancelled bookings in day modal
- REST API endpoints for audience bookings with conflict checking
- `ffc_audiences`, `ffc_audience_members`, `ffc_environments`, `ffc_audience_bookings`, `ffc_audience_booking_targets`
- `AudienceAdminPage`, `AudienceShortcode`, `AudienceLoader`, `AudienceRestController`, `AudienceCsvImporter`, `AudienceNotificationHandler`, `AudienceRepository`, `EnvironmentRepository`, `AudienceBookingRepository`, `EmailTemplateService`

### Fixed

- Autoloader for `SelfScheduling` namespace file naming
- Multiple int cast issues for repository method calls with database values
- Date parsing timezone offset issues in calendar frontend
- AJAX loop prevention in booking counts fetch

---

## [4.4.0] (2026-02-04)

Per-user capability system and self-scheduling rename.

### Added

- Per-user capability system for certificates and appointments (`ffc_view_own_certificates`, `ffc_cancel_own_appointments`, etc.)
- User Access settings tab for configuring default capabilities per role
- Capability migration for existing users based on submission/appointment history

### Changed

- Calendar system to "Self-Scheduling" (Personal Calendars) for clarity
- CPT labels from "FFC Calendar" to "Personal Calendar" / "Personal Calendars"
- Self-scheduling hooks and capabilities prefixed with `ffc_self_scheduling_`

---

## [4.3.0] (2026-02-02) — `7f01f2e`

WordPress Plugin Check compliance and distribution cleanup.

### Changed

- All output escaped with `esc_html()`, `esc_attr()`, `wp_kses()`
- All input sanitized with `sanitize_text_field()`, `absint()`, `wp_unslash()`
- Nonce verification on all form submissions and admin actions
- Translator comments on all strings with placeholders
- Ordered placeholders (`%1$s`, `%2$s`) in all translation strings
- CDN scripts replaced with locally bundled copies (html2canvas 1.4.1, jsPDF 2.5.1)
- `date()` replaced with `gmdate()`, `rand()` with `wp_rand()`, `wp_redirect()` with `wp_safe_redirect()`
- `parse_url()` replaced with `wp_parse_url()`, `unlink()` with `wp_delete_file()`
- Text domain changed from `ffc` to `ffcertificate`
- Translation files renamed to match new text domain

### Removed

- Removed development files from distribution (tests, docs, CI, composer, phpqrcode cache)

---

## [4.2.0] (2026-01-30)

CSV export enhancements and calendar translations.

### Added

- Expand `custom_data` and `data_encrypted` JSON fields into individual CSV columns
- Decrypt encrypted data for certificate CSV dynamic columns
- 285 missing pt_BR translations for calendar and appointment system

### Changed

- English language file with all new calendar strings

---

## [4.1.1] (2026-01-27)

Appointment receipts, validation codes, and admin improvements.

### Added

- Appointment receipt and confirmation page generation
- Appointment PDF generator for client-side receipts
- Unique validation codes with formatted display (XXXX-XXXX-XXXX)
- Appointments column in admin users list
- Login-as-user link always visible in users list
- Permission checks to dashboard tabs visibility

---

## [4.1.0] (2026-01-27)

New appointment calendar and booking system.

### Added

- Calendar Custom Post Type with configurable time slots, durations, business hours, and capacity
- Frontend booking widget with real-time slot availability (`[ffc_calendar]` shortcode)
- Appointment booking handler with approval workflow (auto-approve or manual)
- Email notifications: confirmation, approval, cancellation, and reminders
- Admin calendar editor with blocked dates management
- CSV export for appointments with date and status filters
- REST API endpoints for calendars and appointments
- CPF/RF field on booking forms with mask validation
- Honeypot and math captcha security on booking forms
- Automatic appointment cancellation when calendar is deleted
- Minimum interval between bookings setting
- Automatic migration for `cpf_rf` columns on appointments table
- Appointment cleanup functionality in calendar settings
- User creation on appointment confirmation
- `ffc_calendars`, `ffc_appointments`, `ffc_blocked_dates`
- `CalendarCpt`, `CalendarEditor`, `CalendarAdmin`, `CalendarActivator`, `CalendarShortcode`, `AppointmentHandler`, `AppointmentEmailHandler`, `AppointmentCsvExporter`, `CalendarRepository`, `AppointmentRepository`, `BlockedDateRepository`

---

## [4.0.0] (2026-01-26) — `9c0509b`

> Breaking release: removal of backward-compatibility aliases and namespace finalization. **First stable tag bump from 2.8.0** since the 2.9.x development cycle began.

_The Data Encryption framework, first introduced during the 2.9.x development cycle, is considered stable and integrated across the codebase from this release forward._

### Changed

- All 88 classes now exclusively use `FreeFormCertificate\*` namespaces
- Converted all remaining `\FFC_*` references to fully qualified namespaces
- Renamed `CSVExporter` to `CsvExporter` for PSR naming consistency
- Added global namespace prefix (`\`) to all WordPress core classes in namespaced files
- CSV export with all DB columns and multi-form filters
- Finalized PSR-4 cleanup across all modules

### Fixed

- Loader initialization with correct namespaced class references
- Class autoloading for restructured file paths
- PHPDoc type hints across 3 files
- CSV export error handling, UTF-8 encoding, and multi-form filters
- REST API 500 error from broken encrypted email search
- `json_decode` null handling for PHP 8+ compatibility

### Removed

> **BREAKING:** Removed all backward-compatibility aliases for old `FFC_*` class names
- Removed all obsolete `require_once` statements (autoloader handles loading)

---

## [3.3.1] (2026-01-25)

Bug fixes for strict types introduction.

### Fixed

- Type errors caused by `strict_types` across multiple classes
- String-to-int conversions for database IDs in multiple locations
- Return type mismatches in `trash`/`restore`/`delete` operations (int|false to bool)
- `log_submission_updated` call with correct parameter type
- `update_submission` return type conversion to bool
- `ensure_magic_token` to return string type consistently
- `json_decode` null check in `detect_reprint`
- `hasEditInfo` return type conversion to int
- `form_id` and `edited_by` type casting in CSV export
- Missing SMTP fields in settings save handler
- Checkbox styles override for WordPress core compatibility
- `$real_submission_date` initialization in both reprint and new submission paths
- Null handling in `get_user_certificates` and `get_user_profile`
- PHP notices in REST API preventing JSON output corruption

---

## [3.3.0] (2026-01-25)

Strict types and full type hints.

### Added

- `declare(strict_types=1)` to all PHP files
- Full type hints (parameter types, return types) across all classes
- Affected: Core, Repositories, Migration Strategies, Settings Tabs, User Dashboard, Shortcodes, Security, Generators, Frontend, Integrations, Submissions

---

## [3.2.0] (2026-01-25)

PSR-4 autoloader and namespace migration.

### Added

- PSR-4 autoloader (`class-ffc-autoloader.php`) with namespace-to-directory mapping
- Backward-compatibility aliases for all old `FFC_*` class names (removed in 4.0.0)
- Developer migration guide and hooks documentation

### Changed

- All 88 classes to PHP namespaces in 15 migration steps
- Namespaces: `FreeFormCertificate\Admin`, `API`, `Calendars`, `Core`, `Frontend`, `Generators`, `Integrations`, `Migrations`, `Repositories`, `Security`, `Settings`, `Shortcodes`, `Submissions`, `UserDashboard`

---

## [3.1.0] (2026-01-24) — `de80749`

_(development version, **not released as stable**; the Stable tag remained at 2.8.0 throughout the 3.x line until 4.0.0 finalization.)_

User dashboard, admin tools, and activity log viewer.

### Added

- User Dashboard system with `ffc_user` role and `[user_dashboard_personal]` shortcode
- Access control class for permission management
- User manager for dashboard data retrieval
- Admin user columns (certificate count, appointment count)
- Debug utility class with configurable logging
- Activity Log admin viewer page with filtering
- Admin assets manager for centralized enqueue
- Admin submission edit page for manual record updates
- Admin notice manager for migration feedback
- Form editor metabox renderer (separated from save handler)
- Dashboard page auto-creation on activation
- User creation email controls

### Changed

- Email handler focused on delivery (removed inline styles)
- REST controller optimized

### Removed

- All inline styles (moved to CSS files)

---

## [3.0.0] (2026-01-20)

Repository pattern, REST API, geofence, and migration manager.

### Added

- Repository pattern (`AbstractRepository`, `SubmissionRepository`, `FormRepository`)
- REST API controller for external integrations
- Geofence class for GPS/IP-based area restrictions
- IP Geolocation integration
- Migration manager with batch processing
- Data sanitizer for input cleaning
- Migration status calculator
- Page manager for auto-created plugin pages
- Magic Link helper class
- Complete JavaScript translations (admin, frontend, form editor, template manager)
- Form Editor and Template Manager i18n

### Changed

- Frontend class as lightweight orchestrator
- GPS cache TTL configuration
- GPS validation with mandatory fields and meter units

### Fixed

- Incomplete CPF/RF cleanup for LGPD compliance
- OFFSET bug in batch migrations
- Slow submission deletion causing 500 errors
- Missing Activity Log methods

_Starting with commit [`53cc4fa`](https://github.com/rpgmem/ffcertificate/commit/53cc4fa4063bb497f5948d79897c022c5c0494e2) (2026-01-17), the plugin is developed in collaboration with [Claude](https://claude.ai/code) (Anthropic) as an AI-powered coding assistant. This is the first AI-assisted contribution; Claude's involvement extends to all subsequent commits and releases unless explicitly noted otherwise in this changelog._

---

## [2.10.0] (2026-01-20)

Rate limiting with dedicated database tables.

### Added

- Rate Limiter with dedicated database tables (`ffc_rate_limits`, `ffc_rate_limit_logs`)
- Rate Limit Activator for table creation
- Configurable rate limit thresholds per action type

### Changed

- Rate Limiter from WordPress transients to Object Cache API

---

## 2.9.x development cycle (2026-01-03 → 2026-01-14)

_(development versions, **not released as stable**; Stable tag remained at 2.8.0 throughout. Reconstructed from forensic source diffs of the `wp-ffcertificate03-01-2026.zip` through `wp-ffcertificate14-01-2026.zip` snapshots.)_

Internal versioning bumped from `2.9.16` → `2.9.17` → `2.9.19` (header) / `FFC_VERSION` constant matched, with the publishable `Stable tag` deliberately frozen at 2.8.0 throughout.

### Added

- First appearance of the **Data Encryption framework** for sensitive fields (email, CPF, IP). The framework continued to evolve through 3.x and was considered fully integrated by 4.0.0.
- REST API controller (`includes/api/class-ffc-rest-controller.php`) for external integrations.
- Repository pattern groundwork — `abstract-repository.php`, `form-repository.php`, `submission-repository.php`.
- Rate Limiter UI/CSS (`assets/css/admin-rate-limit.css`, `assets/js/rate-limit-countdown.js`, `assets/js/rate-limit-frontend.js`) and dedicated settings tab (`includes/settings/class-ffc-tab-rate-limit.php`, `includes/settings/tab-rate-limit.php`).
- Activity Log refinements (`class-ffc-rate-limit-activator.php` joins the activator family).
- Hooks documentation under `docs/HOOKS-DOCUMENTATION.md` and `docs/HOOKS-QUICK-REFERENCE.md`.
- Composer-managed vendor directory and PSR-style structure groundwork (file count grew from ~90 to ~500 between the 23/12 and 03/01 snapshots).
- Pre-compiled localization (`languages/ffc-pt_BR.l10n.php`) for PHP-translation-cache support.
- General admin settings stylesheet (`assets/css/admin-settings.css`) and shared frontend utilities (`assets/js/ffc-utils.js`).

---

## [2.9.1] (2025-12-29)

Activity log, form cache, and magic links fix.

### Added

- Activity Log with `ffc_activity_logs` table for audit trail
- Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
- Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

### Fixed

- Magic Links fatal error (critical bug)
- Duplicate `require` in loader

---

## [2.9.0] (2025-12-28)

QR Code generation on certificates.

### Added

- QR Code generation on certificates linking to verification page
- QR Code generator class using phpqrcode library
- QR Code settings tab with size and error correction configuration

_Note: QR Code work first appeared as experimental code in the 2.5.0 development snapshot, was rolled back, and was resumed and finalized in this release._

---

## [2.8.0] (2025-12-28)

Magic links for one-click certificate access.

### Added

- Magic Links for one-click certificate access via email
- Certificate preview page with modern responsive layout
- `magic_token` column (VARCHAR 32) with database index on `ffc_submissions`
- Automatic token generation using `random_bytes(16)` for all new submissions
- Backward migration: token backfill for existing submissions on activation
- Rate limiting for verification (10 attempts/minute per IP via transients)
- `verify_by_magic_token()` method in Verification Handler
- Magic link detection via `?token=` parameter in Shortcodes class

### Changed

- Email template with magic link button, certificate preview, and fallback URL
- AJAX verification without page reload
- Frontend with loading spinner, download button state management

---

## [2.7.0] (2025-12-28)

> Modular architecture refactoring.

### Added

- `FFC_Shortcodes` class for shortcode rendering
- `FFC_Form_Processor` class for form validation and processing
- `FFC_Verification_Handler` class for certificate verification
- `FFC_Email_Handler` class for email functionality
- `FFC_CSV_Exporter` class for CSV export operations
- Dependency injection container in `FFC_Loader`

### Changed

- Complete modular architecture with 15 specialized classes
- `FFC_Frontend` reduced from 600 to 150 lines (now orchestrator only)
- `FFC_Submission_Handler` to pure CRUD operations (400 to 150 lines)
- Single Responsibility Principle (SRP) throughout

---

## [2.6.0] (2025-12-28) — `7b7a596`

Code reorganization and internationalization.

### Added

- `update_submission()` and `delete_all_submissions()` methods
- Full internationalization (i18n) with all PHP strings wrapped in `__()` / `_e()`
- JavaScript localization via `wp_localize_script()`
- `.pot` translation template file

### Changed

- Complete code reorganization with modular OOP structure
- Separated: `class-ffc-cpt.php` (CPT registration only) from `class-ffc-form-editor.php` (metaboxes)
- Consolidated: All inline styles moved to `ffc-admin.css` and `ffc-frontend.css`

### Fixed

- Missing method calls
- Duplicate metabox registration
- SMTP settings toggle visibility

### Removed

- Dead code and redundancies

---

## [2.5.0] (2025-12-14) — `94fc336`

Development snapshot leading up to the 2.6.0 release; **never published as stable**. Reconstructed from forensic source diffs of the `wp-ffcertificate14-12-2025.zip`, `wp-ffcertificate16-12-2025.zip`, and `wp-ffcertificate23-12-2025.zip` snapshots.

### Added

>- Foundation work for the modular OOP refactor that was finalized in 2.6.0 — first split of `includes/` into `admin/`, `core/`, `data/`, and `frontend/` subdirectories with dedicated classes (`class-ffc-pdf-generator.php`, `class-ffc-submission-controller.php`, `class-ffc-mailer.php`, `class-ffc-template-engine.php`, `class-ffc-repository.php`).
- Initial QR Code experimentation (3 references in `includes/` source). The work was rolled back in the next snapshot (16/12 → 23/12) and resumed/finalized in 2.9.0.
- `FFC_VERSION` constant for CSS/JS cache busting (developer comment in source: _"Adicionamos FFC_VERSION para controle de cache dos arquivos CSS/JS"_).
- Multiple certificate template HTML files and background images bundled in `html/`.

### Changed

- Internal: Local git workflow adopted at this stage (the 23/12 snapshot includes a `.git` directory).

---

## [2.4.0] (2025-12-13)

### Changed

- Internal improvements

---

## [2.3.0] (2025-12-12)

### Changed

- Internal improvements

---

## [2.2.0] (2025-12-11)

### Changed

- Internal improvements

---

## [2.1.0] (2025-12-10)

### Changed

- Internal improvements

---

## [2.0.0] (2025-12-08)

PDF generation overhaul, captcha, and reprint logic.

### Added

- Dynamic Math Captcha with hash validation on backend
- Honeypot field for spam bot protection
- Reprint logic for certificate recovery (duplicate detection)
- PDF download buttons directly in admin submissions list
- Mobile optimization with strategic delays and progress overlay

### Changed

- PDF generation from simple image to high-fidelity A4 Landscape (1123x794px) using jsPDF

### Fixed

- CORS issues with `crossorigin="anonymous"` on image rendering

---

## [1.5.0] (2025-12-05)

Ticket system and form cloning.

### Added

- Ticket system with single-use codes for exclusive form access
- Form cloning (duplication) functionality
- Global settings tab with automatic log cleanup configuration
- Denylist for blocking specific IDs

---

## [1.0.7] (~2025-12-01)

_Reconstructed from forensic source diff of `wp-ffcertificate_12_12_2025.zip`. That snapshot was archived on 2025-12-12 and carried `Version: 1.0.7` in the plugin header — but the 1.0.x patch series logically released **between 1.0.0 (2025-11-25) and 1.5.0 (2025-12-05)**, so the snapshot date is later than the actual release date. The snapshot date (~2025-12-12) is the latest verifiable touchpoint of the 1.0.x line; the release date itself is approximated as ~2025-12-01 to keep the file in chronologically descending order. The 1.0.x patch series was not separately documented in the developer's own changelog inside the 4.0.0 zip; this entry is reconstructed solely from the snapshot's plugin header and file listing._

### Changed

- Maintenance patch series leading from 1.0.0 to 1.5.0; specific change details are unrecoverable from the available evidence.
- Plugin header version stamped at `1.0.7`; no `FFC_VERSION` constant yet (the constant was introduced during the 2.5.0 development cycle).
- Snapshot file inventory: 23 files (`assets/`, `ffc.pot`, `html/`, `includes/`, `readme.txt`, `wp-ffcertificate.php`) — pre-modular monolithic structure.

---

## [1.0.0] (2025-11-25)

Initial release.

### Added

- Form Builder with drag & drop interface (Text, Email, Number, Date, Select, Radio, Textarea, Hidden fields)
- PDF certificate generation (client-side)
- CSV export with form and date filters
- Submissions management in admin
- ID-based restriction (CPF/RF) with allowlist mode
- Asynchronous email notifications via WP-Cron
- Automatic cleanup of old submissions
- Verification shortcode `[ffc_verification]`
