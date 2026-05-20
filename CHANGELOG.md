# Changelog

All notable changes to the **Free Form Certificate** plugin are documented in this file.
The format follows [Keep a Changelog] (https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

---

## [6.6.2] (2026-05-20)

Public certificate form — user-messaging sweep (PR #352). Six-sprint
PR that overhauls every customer-facing message in the submission +
download flow, plus carries forward the two regression fixes that
were sitting in Unreleased after 6.6.1.

### Added

- **Persistent success card** with magic-link URL, auth-code, copy
  buttons, "Download PDF again" CTA, and platform-specific "where to
  find your certificate" hints (Android Downloads / iOS Files /
  desktop Downloads bar). `Utils::generate_success_html()` now
  accepts the submission ID + handler so it can surface
  `MagicLinkHelper::get_submission_magic_link()` — users who close
  the tab can return any time and re-issue without re-submitting.
- **Universal "didn't download?" link** on the desktop / Android
  Chrome `pdf.save()` path. The iOS / Samsung / WebView branches
  already had a manual fallback; the desktop branch silently failed
  when extensions or strict-tracking config ate the download.
  Overlay now stays open 6 s on desktop (was 2 s) so the user sees
  the link.
- **Pre-submission warnings**: "Please do not close this tab" line
  inside the generation overlay (was only painted in the
  placeholder-tab branches before), plus `navigator.onLine ===
  false` short-circuit on form submit and download click with an
  actionable "you appear to be offline" message.

### Changed

- **Actionable error panel** replaces every blocking `alert()` in
  `ffc-pdf-generator.js`. Lib-load failure, missing wrapper, blank
  canvas, CORS taint (SecurityError on `getImageData`), and
  html2canvas rejection each get a specific headline + actionable
  body copy, with a {Try again, Close} pair (or {Close}-only on
  non-recoverable failures). CORS gets a special "this is a
  server-side problem, contact the organizer" message and no retry
  button.
- **Overlay accessibility** (WCAG 2.1.2 + 2.4.3 + 4.1.2):
  `role="dialog"`, `aria-modal`, `aria-labelledby`,
  `aria-describedby`, `aria-busy` toggled true→false on error
  paint, `aria-live="polite"` on the status line, focus moves into
  the dialog on open and returns to the trigger on close, Tab is
  trapped within the dialog, Escape closes only when interactive
  controls are present (no-op during in-flight render).

### Fixed

- **Activity log INSERT failing under FK after 4.9.7** —
  `ActivityLog::flush_buffer()` was passing `user_id = 0` for
  system-event rows. The `fk_ffc_activity_log_user` constraint
  references `wp_users(ID)`, so every system-event INSERT was
  rejected. The "no user / anonymous" semantics now map to `NULL`
  at INSERT time, and the activator's `create_table()` was
  realigned with the post-FK-migration state (`DEFAULT NULL`) so
  fresh installs land on the right schema.
- **`FFC.request is not a function` on every public shortcode** —
  the AJAX migration in #277 enqueued `ffc-core.js` only on admin
  screens. `ffc-core` is now registered for the frontend in
  `Loader::register_frontend_assets()` and listed as a dependency
  on the 5 public-facing enqueues. Dashboard sibling scripts get
  it transitively via `ffc-dashboard`.

---

## [6.6.1] (2026-05-18)

Code-quality sweep bundle from the umbrella audit (#254): test-gap
closeout (§7), nonce-verification audit (§4 follow-up), dead-code
reauditing (§5 follow-up), and residual cleanup between the §1-§6 PRs
and the §7 batch.

> ### ⚠ Breaking change for `GET /forms` REST consumers
>
> The `GET /forms` REST endpoint now uses real pagination via `page`
> and `per_page` query args (WP REST convention). The legacy `limit`
> arg is **removed** — clients still passing `?limit=N` will have it
> silently ignored. Default `per_page` is **10** (was implicitly up
> to 100). Responses now carry `X-WP-Total`, `X-WP-TotalPages`, and
> a `Link` header (`first`, `prev`, `next`, `last`).
>
> Migration: `?limit=50` → `?per_page=50`; iterate `?page=N` until
> `X-WP-TotalPages` is exhausted. No shim period.

### Removed

- `?limit=N` on `GET /forms` (use `?per_page=N`).
- `Utils::debug_log()` shim (`@deprecated 6.2.0`) — migrate external
  callers to `Debug::log_*()` (see `Debug::AREA_*` constants).
- `PublicCsvDownload::maybe_wipe_legacy_logs()` + its
  `DOWNLOAD_LOG_FORMAT` / `OPTION_LOG_FORMAT` constants. The wipe ran
  on every install during the 6.3.3 → 6.3.6 window.
- Unused `FFC_DEBUG` constant and its PHPStan stub.
- Deprecated CSS aliases: `.ffc-conditional-field`,
  `.ffc-csv-public-disabled`, `.ffc-device-limit-disabled`,
  `.ffc-device-limit-globally-off`. The class emission in the
  geofence metabox row was also removed (visibility is JS-driven now).

### Changed

- **AJAX migration umbrella (#277) closes**. 20 JS files / ~70 inline
  `$.ajax({...})` / `$.post()` / `$.get()` sites migrated to the
  centralised `FFC.request` (admin-ajax) and new `FFC.rest` helpers
  in `assets/js/ffc-core.js`. `FFC.rest` injects `X-WP-Nonce`,
  JSON-encodes write bodies, preserves the jqXHR in `Error.xhr`. The
  helper gained: string-payload support (`$form.serialize()`),
  `err.data` auxiliary fields, `err.xhr`, `options.timeout`,
  both-shape `wp_send_json_error` recognition (bare string or
  `{message}`), and `err.fromServer` to distinguish server-supplied
  messages from the library's fallback. `ffc-dynamic-fragments.js`
  was intentionally left on raw `XMLHttpRequest` for a separate
  refactor; the search now returns only `ffc-core.js` itself.
- **POST/GET sanitize migration umbrella (#276) closes**. ~120 inline
  `sanitize_text_field(wp_unslash($_POST/$_GET[…]))` patterns
  migrated to the four new `Utils::get_post_string` /
  `get_get_string` / `get_post_int` / `get_post_bool` helpers, across
  frontend (36 sites), audience (48 of 55 — 7 use complex gates),
  admin + reregistration (14 files), and the first batch (24 sites
  across core / shortcodes / url-shortener / settings /
  self-scheduling). `RestSupport::get_post_param()` /
  `get_post_int()` now delegate to the static helpers.
- **5 reuse helpers** consolidate scattered patterns:
  `DataSanitizer::normalize_cpf_rf()` (22 sites),
  `Utils::get_export_filename()` (4), `get_day_of_week_number()` (5),
  `sanitize_username_slug()` (2), `get_post_array()` (4). ~33 call
  sites migrated.
- **`ffc_settings` centralized** via new
  `FreeFormCertificate\Settings\SettingsReader` with generic getter
  (`get`, `get_bool`, `get_int`, `all`) + 20 typed accessors. 25 call
  sites migrated; relies on WP's built-in `alloptions` cache (no perf
  change). Debug toggles continue via `Debug::is_enabled($area)`.
- **`GET /forms` real pagination**: `page` / `per_page` query args
  (defaults 1 / 10), `X-WP-Total` / `X-WP-TotalPages` / `Link`
  headers. Out-of-range pages return `[]` with status 200 (not 404).
- **Recruitment admin**: 5 inline `<script>` blocks moved into
  `ffc-recruitment-admin.js` and consolidated into 2 generic
  delegated handlers (`form[data-ffc-create-endpoint]`,
  `input[data-ffc-color-endpoint]`).

### Documentation

- Classify `created_at` / `updated_at` housekeeping columns as a
  documented exception to Category A storage (CLAUDE.md).
- Add "Legacy compat shims — audit log" section to CLAUDE.md
  documenting the 4 by-design shims (capability migration, cron
  cleanup, API-contract keys, encryption fallback).
- Correct misleading "backwards compat" framing on
  `AbstractRepository::get_allowed_where_columns()`,
  `ReregistrationFrontend::get_user_reregistrations()`, and
  `UserDataRestController` (none are shims).

---

## [6.6.0] (2026-05-17)

> ### ⚠ Breaking change for external SQL consumers
>
> This release converts four "instant in time" columns from `DATETIME` to `BIGINT UNSIGNED` storing unix UTC seconds, plus seven sibling instant columns in the same tables:
>
> | Table | Columns |
> | --- | --- |
> | `wp_ffc_submissions` | `submission_date`, `consent_date`, `edited_at` |
> | `wp_ffc_reregistration_submissions` | `submitted_at`, `reviewed_at` |
> | `wp_ffc_recruitment_call` | `called_at`, `cancelled_at` |
> | `wp_ffc_appointments` | `approved_at`, `cancelled_at`, `consent_date`, `reminder_sent_at` |
>
> Column **names** are unchanged. The plugin runs an idempotent backfill on first load that interprets the existing DATETIME literal in the site's timezone and stores it as unix UTC, then drops the old column and renames the new one over it. Inside the plugin everything reads/writes through `DateFormatter` so the change is invisible.
>
> **External SQL (Metabase, PhpMyAdmin, custom reports) needs migration**:
>
> - Old: `WHERE submission_date >= '2026-01-01'` → New: `WHERE submission_date >= UNIX_TIMESTAMP('2026-01-01')`
> - Old: `ORDER BY submission_date DESC` → unchanged (int sorts the same direction)
> - Old: `SELECT DATE(submission_date)` → New: `SELECT DATE(FROM_UNIXTIME(submission_date))`
>
> Caveat documented for the curious: if the admin changed the site timezone at any point between 6.5.x and 6.6.0, the rows written under the old TZ are interpreted at backfill time in the **new** TZ — that introduces a fixed offset per row. Can't be recovered without a TZ-change audit log (we don't keep one). Backup the database before the update if the historical accuracy matters for those rows.

### Changed

- **Sub-escopos (a) (b) (c) (d) (e) (f) of #249 — instants as unix UTC int + auditoria de render + docs + release bump.** End-to-end implementation of the Category A storage convention from CLAUDE.md. Each sub-escopo carries its own dedicated commit on PR #253 (see commit log for the per-column refactor map of writes/reads/tests). The migrations are gated on per-routine option flags so they're safe to re-attempt after a partial-failure restart, and the staging-column → backfill → drop+rename pattern is encapsulated in `DatabaseHelperTrait::migrate_datetime_column_to_unix()` so future Category A column migrations are one helper call.

- **DateFormatter: route the last user-visible display sites through the helper** (#249 sub-escopo d). After #246 / #247 a handful of admin/list screens still rendered dates via `date_i18n()` or `gmdate()` — the Activity Log row metadata, the two Audience holiday tables, the Audience bookings list, and the self-scheduling appointments list's Time column. All five now call `DateFormatter::format_date()` / `format_time()` so the plugin's `ffc_settings` date/time format owns every screen and a future WP-TZ change re-renders without per-site edits. Out of scope (and kept as-is): filenames built with `gmdate('Y-m-d')`, day-of-week int extraction with `(int) gmdate('w', $ts)`, cache/rate-limiter window keys, SQL stored-value round-tripping for Category B wall-clock columns, and the iCal `DTSTAMP` per RFC 5545 — all canonical strings, not display.

- **Settings → General: combobox + custom-format fallback for every date/time field, date-only presets** (closes #248, 4 sprints + docs). Polish on top of #244 / #246 / #247 — every format field (Date Format, Time Format, PDF Date Format override, PDF Time Format override) is now a `<select>` with curated presets plus a "Custom Format" entry that reveals an adjacent text input via the generic `.ffc-collapsed-target` handler from #238 — so picking "Custom" expands the field live without a reload. (1) **Date Format dropdown** lost its three combined date+time presets (`Y-m-d H:i:s`, `d/m/Y H:i`, `d/m/Y H:i:s`); they stopped making sense once `time_format` became its own setting. (2) **Smart-match on load** for legacy installs: a saved value like `"d/m/Y H:i"` is run through `DateFormatter::strip_time_chars()` and the dropdown opens on the stripped equivalent (`"d/m/Y"`) instead of the first option. If the stripped result still isn't a preset (e.g. `"d M Y \a\s H:i"`), it falls through to "Custom Format" with the cleaned value pre-populated. No DB writes — the original setting is preserved bit-for-bit so rollbacks still work. (3) **PDF Date Format override** went from a text input to a `<select>` with `""` ("Inherit"), the same date-only presets, and `custom` → free-form via the new `date_format_pdf_custom` setting. `DateFormatter::resolve_date_format('pdf')` resolves the `custom` sentinel by reading the companion, falling through to the base format when empty. (4) **PDF Time Format override** mirrors the same shape with four time-only presets (`H:i`, `H:i:s`, `g:i a`, `g:i:s a`), `"Inherit"`, and `custom` → `time_format_pdf_custom`. (5) **Base Time Format** also got the same select-plus-custom treatment for parity; pre-#248 free-form values land in the Custom field transparently. (6) **Settings → General "divergence notice"** (#244 Sprint 1.5) now reads through the effective time format so a `custom` choice doesn't render the literal `"custom"` sentinel in the comparison line. (7) `DateFormatter::strip_time_chars()` is now public on the helper surface so the view can call it for smart-match; the runtime resolver gained a private `date_only()` wrapper that layers the default fallback on top — view code can distinguish "valid stripped result" from "fell back to default" without depending on the default. Four new option keys land on the autosave allowlist + defaults map: `date_format_pdf_custom`, `time_format_pdf_custom`, `time_format_custom` (and the pre-existing `date_format_custom`).

### Fixed

- **Public Operator Access audit CSV: timestamp now respects the site timezone.** The "Download audit log (CSV)" export labelled its first column `timestamp_utc` and emitted UTC values via `gmdate()`. Admins outside UTC had to convert in a spreadsheet to make sense of the log. Renamed the column to `timestamp` and switched the formatter to `wp_date('Y-m-d H:i:s', $ts)` so it follows WP's configured timezone (the stored ring-buffer value is still a UTC unix timestamp).

- **DateFormatter: duplicated time on certificate / appointment verification + raw dates in "Minhas Convocações"** (follow-up to #244). Two issues reported by the user after #246 shipped: (1) the `[ffc_verification]` certificate result screen rendered the issue date as e.g. `"12/05/2026 18:57 18:57"` — the appointment result rendered `Data: 20/05/2026 21:00`, the user dashboard's appointments list and "Próximo Agendamento" widget showed the same time-on-date duplication. Root cause: pre-#244 the plugin's own `ffc_settings['date_format']` was effectively decorative (only the Settings → General preview consumed it), so existing installs had values like `"d/m/Y H:i"` saved there. When #244 wired the helper through every display site, `format_datetime()` started concatenating `time_format` after the already-time-bearing `date_format`. Fix: `DateFormatter::resolve_date_format()` now strips PHP time-format chars (`a A B g G h H i s u v`, honouring `\\`-escapes) from `date_format` / `date_format_pdf` on read, falling back to the plugin default when the strip yields an empty result. Pure read-time normalization — the user's saved value is untouched, so the Settings → General preview keeps reflecting what they typed; only display code routes through the cleaned format. (2) **"Minhas Convocações" was rendering raw DB values**: `called_at` / `date_to_assume` / `time_to_assume` echoed in their stored MySQL shape (`"2026-05-02 13:43:52"` / `"2026-05-05"` / `"11:00:00"`) instead of going through `DateFormatter`. Score was rendering as `"100,0000"` instead of two decimals. Fixed: the three datetime cells go through `format_datetime` / `format_date` / `format_time`, and the score uses `number_format_i18n(…, 2)`.

---

## [6.5.14] (2026-05-15)

### Changed

- **Canonical date/time formatting through a single helper** (closes
  #244, PR #246, 4 sprints). New
  `FreeFormCertificate\Core\DateFormatter` (`format_date` /
  `format_time` / `format_datetime` + resolvers) replaces ~25
  user-visible call sites that reached for `get_option('date_format')`
  or hardcoded a format string — the plugin's own
  `ffc_settings['date_format']` setting was decorative before. New
  `time_format`, `date_format_pdf`, `time_format_pdf` settings keys
  (PDF empty inherits the default). New-install default flipped
  `'F j, Y'` → `'d/m/Y'`; sites that explicitly saved `'F j, Y'`
  keep it. Settings → General now renders a divergence notice when
  the plugin's effective format differs from WordPress's globals.
  Self-scheduling slot picker also migrated. Deferred: 2 JS helpers
  in `ffc-geofence-frontend.js` (canonical Y-m-d / H:i strings used
  for backend comparison — localizing them would break the diff).
  22 new DateFormatterTest cases.
- **Operator Access — public-page polish + behaviour cleanup** (PR
  #243). Six fixes: master-OFF message reworded to reference
  "Operator Access" not "CSV download"; action buttons render
  disabled (not hidden) when admin turned a sub-feature off, with
  per-button tooltips; postpone-close modal pins 24h format + does
  client-side validation; CPF/RF i18n strings localized into
  `ffc_csv_download.strings` too (was only `ffc_ajax`); new
  Certificate Preview as the 4th operator-feature toggle; operator
  actions (`action_early_open`, `action_postpone_close`) now write
  to the per-form audit CSV + re-validate the CPF server-side
  (closed a hash-only privilege gap).
- **Master-toggle UX consolidation across the form editor** (closes
  #238, PR #239, 3 sprints). (1) "Public CSV Download" renamed to
  "Public Operator Access" in editor + Settings + docs; meta keys
  (`_ffc_csv_public_*`) kept. (2) Skip-on-off save semantics for 11
  master toggles (Restriction ×4, Email, DateTime, Geolocation,
  IP-Permissive, Quiz, CPF-mode) — sub-meta no longer rewritten when
  the master is off, so values survive a toggle-off-toggle-on cycle.
  (3) Unified visibility via the new `.ffc-collapsed-target` wrapper
  + `.ffc-collapsed` JS pattern in `ffc-admin.js` (reads
  `data-ffc-master` / `data-ffc-master-value`, syncs `aria-hidden` +
  `aria-expanded`). Three previously save-required toggles gain
  live-update. Legacy CSS aliases kept as no-ops until 6.6.0.
- **Form-editor master toggles — follow-up fixes after #238** (PR
  #240). Section 7 master now collapses ALL three sub-features
  (Start Early + Postpone Close were outside the wrapper); "Access
  Hash" input replaced by a single share-link row with a Copy button
  (clipboard + execCommand fallback, new `data-ffc-copy-target`
  convention); Section 3's 4 restriction toggles migrated from
  slideUp/slideDown on `<tr>` to the unified
  `.ffc-collapsed-target` pattern.
- **Device Fingerprint Limit merged into Restriction & Security**
  (PR #240). Form editor 8 → 7 sections; PR #242 inlined the toggle
  as the 5th Restriction item. POST namespace
  (`ffc_device_limit[…]`) unchanged.
- **Section 7 polish — independent CSV Download toggle + collapse
  fix** (PR #242). New `_ffc_csv_public_download_enabled` sub-toggle
  (defaults `'1'`). Admins can keep Operator Access on for
  Start-Early / Postpone-Close while disabling the CSV download for
  read-only deployments. New step 7b in `validate_form_access()`.

### Added

- **Auto-save for the form-editor master toggles** (PR #240). 13
  boolean toggle keys (12 in #240 + `csv_public_download_enabled` in
  #242) auto-save via the new `FormMetaAjaxEndpoint` —
  `wp_ajax_ffc_update_form_meta`, gated on `edit_post` for the exact
  post_id, with inline "Saving…/Saved/Save failed" chip. Intentionally
  NOT on the allowlist: `_ffc_csv_public_enabled` — first-time enable
  generates a hash + bumps cpf_mode, side effects stay in the full
  save handler. PR #241 fixes a hook-order race that broke the
  localize call.
- **Audit log: explicit `download_delivered` tag** (PR #242). The
  ring buffer used to record CPF-validation outcomes only, written
  BEFORE streaming — so a streaming failure left the log lying, and
  `none` mode without a volunteered CPF wrote nothing at all. Both
  download paths now emit `download_delivered` right before bytes
  leave the server. The new tag is excluded from metabox summary
  buckets (would double-count CPF-gated flows); `download_success`
  keeps sourcing from the long-lived `META_COUNT` counter.

### Fixed

- **Form-meta autosave silently broken by hook-order race** (PR
  #241). `FormEditor::enqueue_scripts()` and
  `AdminAssetsManager::enqueue_admin_assets()` both hooked
  `admin_enqueue_scripts` at default priority 10, with FormEditor
  instantiated first — so `wp_localize_script('ffc-admin-js', …)`
  fired against a not-yet-registered handle and WP silently dropped
  the data. Fix: bump FormEditor's hook priority to 20. Regression
  test pins the > 10 contract.
- **Operator Access info screen — misleading "ready to download"
  message when CSV download is disabled** (PR #242). New
  `download_disabled` reason in `CsvDownloadFormInfoBuilder` + JS
  branch in `buildStatusMessage()` renders the localized
  `csvDownloadDisabled` string instead of falling through to the
  success branch.

## [6.5.13] (2026-05-15)

### Changed

- **Public CSV audit summary now reports three operator-facing buckets** (replaces the prior "Total attempts / Successful / Failed" counters). The metabox in the form editor's *Public Operator Access* section displays: (1) **Successful accesses** — CPF + CAPTCHA both validated; computed from `success` / `audit_pass` / `voluntary` rows in the audit ring buffer (CPF passing implies CAPTCHA passed since CAPTCHA runs as an earlier gate); (2) **Successful downloads** — count of CSV files actually delivered; sourced from the long-lived `_ffc_csv_public_count` counter rather than the audit ring buffer, so the number survives log rotation and never under-counts after the buffer fills; (3) **Failed accesses** — every `fail_*` row (wrong/missing/invalid CPF + wrong CAPTCHA + hash mismatch + quota exhausted + future-unknown tags fall through here to prevent silent success inflation). Two new result tags were added to the audit log to make the third bucket complete: `fail_captcha` (honeypot or CAPTCHA rejected) and `fail_other` (hash mismatch, form ended, quota exhausted). `handle_request()` and `ajax_info()` now parse `form_id` before the CAPTCHA gate so post-CAPTCHA failures can be attributed to the right form (pre-form_id rejections — rate-limit, nonce — are still unlogged since they overwhelmingly come from scanners/stale tabs). The legacy `success`/`fail`/`count` keys are still returned by `get_audit_log_summary()` for any unforeseen external consumer.
- **Admin form save now resets the postpone-close one-shot.** When an admin saves a form in the editor, `FormEditorSaveHandler::save_form_data()` now wipes `_ffc_csv_public_end_postponed_at` and `_ffc_csv_public_end_postponed_from` — letting trusted operators on the public download page postpone the close again within the newly-configured window. The admin save is the natural cycle boundary: whatever they're now configuring supersedes the prior operator state. (`EarlyOpenAction` has no persistent flag — its one-shot is structural via `date_start <= now`, so pushing `date_start` back to the future via the metabox already self-re-enables the early-open button.)

---

## [6.5.12] (2026-05-15)

### Added

- **"Postpone close" public operator action** — sibling of "Start Form Now" but for the close boundary. Trusted operators on the venue floor can push a form's `time_end` later within the same calendar day, exactly once per form, using the same CSV-public hash as the credential. New `FreeFormCertificate\Frontend\ExtendEndAction` centralises eligibility (10 stable reason tags including `extend_end_disabled`, `not_started_yet`, `already_postponed`, `bad_time_format`, `not_extending`, `past_now`, `out_of_day`) and execution. **Constraints baked in**: (1) form must already have STARTED — postponing only makes sense for the active window; (2) new `time_end` must be strictly later than both `now` and the current `time_end` and must stay within the configured `date_end`'s calendar day; (3) strictly one-shot per form, persisted via `_ffc_csv_public_end_postponed_at` UTC timestamp (`_ffc_csv_public_end_postponed_from` keeps the prior value as audit); (4) per-form opt-IN — `_ffc_csv_public_extend_end_enabled` defaults to `'0'` (admin must consciously enable since this extends a public-facing window). UI lives next to "Start Form Now" on the public CSV download page; modal reuses the cert-preview chrome (dark header + close ×) with a `<input type="time">` picker pre-populated at `current time_end + 30 min`. POST `ffc_public_extend_end` re-runs eligibility server-side. Aggressive page-cache purge (`FormCache::purge_all_pages()`) fires so the page hosting `[ffc_form id=N]` immediately reflects the new close. Admin metabox in form-editor Section 7 carries the opt-in toggle + a status pill mirroring all 10 eligibility branches (including "Already postponed once — was 14:00"). New Activity Log event `end_postponed` (warning level) audits form_id, original_time_end, new_time_end, IP, UA, user_id.

---

## [6.5.11] (2026-05-15)

### Fixed

- **Early-open / geofence edits: page cache wasn't actually being purged for the user-visible page.** The `ffc_form` CPT is registered with `'public' => false`, so the per-post `flush_post( $form_id )` calls into W3 Total Cache / LiteSpeed / WP Super Cache / WP Rocket were no-ops for the visible surface — what visitors actually see is the WP page that embeds `[ffc_form id=N]` via shortcode, a different post the cache plugins can't link to the form id. Result: after the operator triggered early-open (or the admin edited the geofence config), the form page kept being served from cache showing the stale "not yet started" state. New `FormCache::purge_all_pages( $form_id, $reason )` hits each integration's broad "purge all" API (`W3TC\Dispatcher::component('CacheFlush')->flush_pgcache()`, `LiteSpeed\Purge::purge_all()`, `wp_cache_clear_cache()`, `rocket_clean_domain()`) and is now wired into both `EarlyOpenAction::execute()` and the form-editor geofence save handler. Site-wide purges are heavy-handed, but both code paths are admin-triggered, infrequent, and the alternative (scanning every post for `[ffc_form id=N]` substring matches) is unreliable. Cloudflare APO / Redis page cache / custom CDN integrations get the same signal via the existing `ffc_form_cache_purged` action hook with a `:all` suffix on the reason so they can differentiate the aggressive sweep from the per-post call. Three regression tests cover the new method.

---

## [6.5.10] (2026-05-15)

### Fixed

- **Early-open: form didn't actually open after the action ran.** Two related issues fixed in the same drop:
  - `EarlyOpenAction::execute()` used to overwrite both `date_start` and `time_start` — but in the default `daily` `time_mode` the Geofence validator compares `current_time` against `time_start` AND `time_end`. With the original `time_end` left in place, pushing `time_start` later in the day than `time_end` produced an inverted daily window (e.g. `time_start=22:59`, `time_end=21:00` → "current_time > time_end" → form blocked). The action now narrows the write to `time_start` only and trusts the originally configured `date_start` / `date_end` / `time_end` / `time_mode` — preserving the operator's scheduled close exactly. Two regression tests in `tests/Unit/EarlyOpenActionTest.php` lock the narrowed-write contract.
  - **Same-day guard on the early-open surface.** Since the action now only rewrites `time_start`, exposing the button on a form whose `date_start` is in the future would let an operator merely shift the clock value while date_start still rejects the form — confusing and irreversible. New `not_today` eligibility tag in `EarlyOpenAction::is_eligible()` and a matching predicate in `CsvDownloadFormInfoBuilder::can_open_early`; the form-editor metabox status pill mirrors the same guard. The button now only appears on the form's scheduled start day. Three regression tests cover the new branch.

---

## [6.5.9] (2026-05-15)

### Changed

- **Early-open confirmation modal restyled to match the cert-preview modal chrome.** The "Iniciar formulário agora?" modal on the public download page used a plain white card that visually diverged from the rest of the plugin's overlays. It now uses the same shell as `#ffc-preview-modal`: dark slate header (`#1d2327`) carrying the title + a close ×, structured white body for the warning copy + scheduled / new-start times + cache warning, and a footer that hosts the Cancel-emphasised / Confirm-warning action pair. Backdrop opacity bumped from 0.5 to 0.75 to match the cert-preview overlay so both modals dim the page identically. New `.ffc-open-early-close` button is wired alongside the Cancel button and the backdrop click into the same `closeModal()` handler. Vitest regression test in `tests/js/csv-download-open-early.test.js` covers the new close button.

---

## [6.5.8] (2026-05-15)

### Added

- **Start Form Early — per-form on/off toggle in the metabox.** The Public Operator Access metabox (form editor Section 7) gains a new `Start Form Early` toggle bound to a new `_ffc_csv_public_start_early_enabled` post-meta. When off, `EarlyOpenAction::is_eligible()` rejects with a new `early_open_disabled` reason tag and the public CSV-download page won't show the "Start Form Now" button to operators. Independent of the Public Download master toggle — admins can keep the public download on while disabling the early-start action for read-only deployments. Defaults to '1' when the meta is unset (pre-6.5.8 forms) so the feature doesn't regress for installs already using it. `CsvDownloadFormInfoBuilder::can_open_early` mirrors the same predicate so the JS button gating stays in sync.

### Changed

- **"Start Form Early" metabox section — drop the duplicate operator URL.** The Public Operator Access metabox (form editor Section 7) used to render a *second* URL block under "Start Form Early URL" with its own Copy button. There is no separate URL — operators visit the same public CSV download page (the URL surfaced in the section above) and the "Start Form Now" button appears there when the form is eligible. The section now collapses to a status pill mirroring `EarlyOpenAction::is_eligible()` so admins can see at a glance why the button is / isn't visible.

### Fixed

- **Forms-list inline toggles + cache buttons + settings autosave all returned "Connection error".** The shared `FFC.request` helper was clobbering caller-supplied `data.nonce` with `options.nonce || this.config.nonce || ''`. Six admin features all passed their endpoint-specific nonce via `data.nonce` and got silently overwritten with `FFC.config.nonce` (created for action `ffc_admin_pdf_nonce`), which can't verify against `ffc_update_form_feature`, `ffc_update_setting`, `ffc_cache_warm`, `ffc_cache_clear`, `ffc_activity_log_fetch`, `ffc_migration_run_batch`, or `ffc_submissions_bulk_action`. Server responded 403 → jQuery `.fail()` → user saw "Connection error" on every toggle / button click. Fix: `FFC.request` now resolves the nonce in priority order `options.nonce > data.nonce > FFC.config.nonce > ''`, preserving the per-action nonce when callers pass one. Cache-actions and settings-autosave additionally lacked their nonces in the localization layer — `ffcCacheActions.nonces` now exposes a map keyed by action (warm / clear) and `ffcAdminAutosave.nonce` ships the `ffc_update_setting` nonce on every settings tab that calls `enqueue_autosave_infra()`. Three regression tests in `tests/js/ffc-request.test.js` lock the resolution order and the data-vs-config preservation contract.

---

## [6.5.7] (2026-05-14)

### Added

- **Recruitment — Adjutancy edit screen.** The "Edit" row action on the Adjutancies list table previously generated a `?action=edit-adjutancy` URL with no handler — clicking it just reloaded the same list. A dedicated edit page now renders the General section (slug, name, badge color) and persists via `admin-post.php` → `RecruitmentAdjutancyRepository::update()`. Mirrors `RecruitmentReasonEditPage` structurally. The slug edit path checks `get_by_slug()` before update to surface a clear "slug taken" flash error instead of letting `wpdb->update` return `false` silently when the UNIQUE constraint rejects the row.

### Changed

- **Self-scheduling editor — 10 more `.ffc-toggle` conversions** (`post_type=ffc_self_scheduling`). Settings metabox (5 checkboxes): Allow User Cancellation, Require Manual Approval, Restrict Viewing to Business Hours, Restrict Booking to Business Hours, Admin Bypass. Notifications metabox (5 checkboxes): Send confirmation email to user, Send notification to admin on new booking, Send notification when booking is approved / cancelled, Send reminder before appointment. The `#allow_cancellation` JS hook in `ffc-calendar-editor.js` (toggles the visibility of the "Cancellation Deadline" row) keeps working because `render_toggle()` preserves the inner `<input>`'s `id`. Side fix: `.ffc-email-checkbox-label` CSS rule swapped for `.ffc-email-toggles .ffc-toggle` so the new toggles stack vertically with consistent breathing room.

### Fixed

- **Toggle autosave looked broken because the badge broke the CSS sibling rule.** `FFC.Admin.autoSaveField` injected its "Saving… / Saved" badge by calling `$field.after()` — fine for plain `<input>` fields, but the `.ffc-toggle` markup is `<label><input><span.ffc-toggle-track><span.label></label>`, and placing a `<span>` between the input and the track killed the `input:checked + .ffc-toggle-track` adjacent-sibling rule that recolours the track on toggle-on. Users saw the toggle flip back to the off-looking state right after clicking it and concluded autosave wasn't running (it actually saved fine). The widget now anchors the badge AFTER the wrapping `.ffc-toggle` label, leaving the track adjacent to the input where the CSS expects it. Regression test in `tests/js/admin-autosave.test.js` asserts both that the badge lives outside the label and that the track remains the immediate next sibling of the input.
- **Recruitment — Adjutancies "Edit" row action did nothing.** The list table generated an `?action=edit-adjutancy` URL but neither the page router nor `dispatch_action()` knew what to do with it; the URL just redrew the list. See the Added section for the new edit screen.
- **`WP_Scripts::add` doing_it_wrong notice — four enqueues declared a missing `ffc-admin` dependency.** WordPress 6.9.1 added a `doing_it_wrong` warning when a script is enqueued with a handle that hasn't been registered; the plugin's admin script is registered as `ffc-admin-js` but four call sites mistakenly listed it as `ffc-admin` (the *style* handle) in their deps array — `class-ffc-form-list-columns.php` (forms list inline toggles, added in #210), `class-ffc-settings-tab.php` (settings autosave), `class-ffc-tab-cache.php` (cache actions), and `class-ffc-tab-geolocation.php` (geolocation autosave). All four now declare `ffc-admin-js`. Functional impact was nil — WordPress still loaded the scripts — but the notice spammed `debug.log` on every admin page render.

---

## [6.5.6] (2026-05-14)

### Added

- **Public Operator Access — "Start Form Now" early-open** (closes #224). Trusted operators on the venue floor can flip a form's scheduled `date_start` / `time_start` to "now" without admin login, using the same hash that already authenticates the public CSV download. The button surfaces on the existing `[ffc_csv_download]` shortcode page next to "Preview Certificate" — only when the form is gated by datetime restrictions, public CSV access is on, the form hasn't started yet, and (if there's an end date) it hasn't ended. Confirmation modal emphasises Cancel (autofocus + larger / bolder) over Confirm; supports Esc, backdrop click, and Cancel button to dismiss. POST-only AJAX (`ffc_public_open_early`) prevents prefetch / email-scanner accidental triggers. New `FreeFormCertificate\Frontend\EarlyOpenAction` service centralises eligibility (`is_eligible()` returns one of 7 stable reason tags: `unknown_form`, `csv_disabled`, `bad_hash`, `datetime_disabled`, `no_start_date`, `already_started`, `already_ended`) and execution (writes `current_time('Y-m-d')` / `current_time('H:i')` to `_ffc_geofence_config`, then `FormCache::clear_form_cache()` + `FormCache::purge_external_caches()`). Naturally one-shot via form state — once the start moves into the past, eligibility flips to `already_started` and the button disappears (no token bag needed). Matching admin metabox status pill in the editor (Section 7) shows the current eligibility plus the operator URL with a one-click Copy button when eligible. New Activity Log event `early_open_executed` (warning level) records the original + new datetime, the operator's IP and UA, and the WP user id when logged-in.
- **Daily cron — purge unredeemed ticket pools of ended forms** (closes #224). New `FreeFormCertificate\Admin\ExpiredTicketsCleanup` runs `ffc_daily_expired_tickets_cleanup` once per day. Each tick scans every published `ffc_form` and wipes `_ffc_form_config[generated_codes_list]` for forms that match all three: ticket gate enabled, `Geofence::has_form_expired()` true, and a non-empty codes list. The toggle stays as history; only the codes are cleared. Idempotent — the form drops out of the sweep on subsequent days. Audited via the new `tickets_purged_expired` Activity Log event (info level, includes the count of removed codes). Scheduled in the activator (one-hour offset to avoid collision with the existing daily cleanup) and unscheduled in the deactivator.

### Changed

- **Public CSV Download metabox renamed to "Public Operator Access"** (closes #224). Section 7 of the form editor now reflects the broadened scope of the public hash credential — it gates both the existing CSV download flow and the new "Start Form Now" early-open action. Metabox `id` is unchanged so screen-options preferences (collapsed / hidden state) carry forward.
- **FormCache now propagates invalidation to third-party page-cache plugins** (closes #225). New `FormCache::purge_external_caches( $form_id, $reason = '' )` is a best-effort sweep that calls `\W3TC\Dispatcher::component('CacheFlush')->flush_post( $form_id )`, `\LiteSpeed\Purge::add( 'P_' . $form_id )`, `wpsc_delete_post_cache( $form_id )` (WP Super Cache), `rocket_clean_post( $form_id )` (WP Rocket) — each guarded by `class_exists` / `function_exists` + a try/catch so a misbehaving cache plugin can never break the host action. Closes with `do_action( 'ffc_form_cache_purged', $form_id, $reason )` so Cloudflare APO, Redis page caches, and custom CDN purgers can hook the same signal. New `purge_external_caches_for_all_forms( $reason )` iterates every published form. Wired into: the "Clear all cache" admin AJAX endpoint, the legacy admin_init handler (same button, no-JS fallback), and the form-editor save handler when the geofence config changes — so a manual datetime edit propagates through to the public CSV download page + the rendered form page immediately. `clear_form_cache()` (the hot-path object-cache flush called on every save_post / submission) is unchanged — third-party purges only fire when the public-facing state actually moved.
- **Calendar edit page — 6 more `.ffc-toggle` conversions** (`page=ffc-scheduling-calendars&action=edit`). Notifications block (3 checkboxes): `schedule_notify_booking`, `schedule_notify_cancel`, `schedule_include_ics`. Event list block: `schedule_show_event_list`. Isolated Calendar: `schedule_is_isolated`. `Status` `<select>` (active/inactive) collapses to a hidden-sibling + toggle pair, same shape as the audience Status conversion in #220.
- **Environment edit page — 2 more `.ffc-toggle` conversions** (`page=ffc-scheduling-environments&action=edit`). The per-weekday "Closed" checkbox in the working-hours editor renders as a toggle. `Status` `<select>` collapses to hidden-sibling + toggle.

### Fixed

- **Reregistration "Email Notifications" toggles overlapping their labels.** WordPress admin core ships a `.form-table td fieldset label { display: inline-block }` rule that overrode the plugin's `.ffc-toggle { display: inline-flex }`, collapsing the toggle track over the start of the label text. The reregistration edit page wrapped the three notification toggles in a `<fieldset>` (triggering that rule); `.ffc-toggle` now also declares the rule on `.form-table td .ffc-toggle` + `.form-table td fieldset .ffc-toggle` to win the specificity battle, and the offending `<fieldset>` was replaced with a plain `<div>` since it carried no `<legend>`. `position: relative` is also added so the visually-hidden checkbox is scoped to the label, not the viewport.

---

## [6.5.5] (2026-05-14)

**Admin UX modernisation release.** This drop chases a single thread across the WordPress admin: every flow that used to do a full page reload on every click now has an AJAX path that keeps the user in place, and every boolean feature flag now renders as the `.ffc-toggle` switch shipped in 6.5.4. The form-POST fallbacks and admin_init handlers stay intact so users with JavaScript disabled keep the original behaviour.

### Changed

- **Form-builder "Required?" field flag → `.ffc-toggle`** (closes #221). The per-field "Required?" checkbox in Section 2 of the form editor now renders as a toggle switch in both the PHP template and the JS template that appends new field rows. The `.ffc-field-required` class is preserved on the inner `<input>` so the field-builder serialiser (which reads `$row.find('.ffc-field-required').is(':checked')` when packing rows for `save_post`) keeps working unchanged. `AdminUI::render_toggle()` gains an optional `input_class` arg for this use case.

- **Hotfix: `.ffc-toggle` missing CSS on reregistration / audience pages + 4 more conversions** (closes #220). Visual bug: toggles rendered without their track / background on the reregistration edit screen. Root cause: `ffc-reregistration-admin.css` and `ffc-audience-admin.css` were enqueued with `array()` deps so WordPress's dependency graph never guaranteed `ffc-common.css` (which holds the `.ffc-toggle` rules) would load first. Defensive fix: register `ffc-common` up-front (guarded with `function_exists( 'wp_style_is' )`) and declare it as an explicit dep on the page CSS. While here, 4 more boolean UI elements converted to `.ffc-toggle`: audience `Status` `<select>` (hidden+toggle pair), audience `Allow Self-Join` checkbox, audience CSV importer `Create users` checkbox, recruitment notice `Show preliminary reasons publicly` checkbox.

- **Toggle sweep round 3 — recruitment + reregistration + form-editor + SMTP radios** (closes #218). 13 more boolean UI elements move to `.ffc-toggle`:
  - **Recruitment Settings** (4 checkboxes): the four "Preliminary list — reason required?" flags (`preview_reason_required_denied/granted/appeal_denied/appeal_granted`).
  - **Reregistration Edit** (4 checkboxes): `rereg_auto_approve` + the three email-notification flags (`rereg_email_invitation/reminder/confirmation`).
  - **Form editor — Email Settings (Section 4)** (1 select): the `send_user_email` Yes/No dropdown becomes a hidden-sibling + toggle pair so the stored value stays `'0'` / `'1'`.
  - **SMTP tab** (4 radio pairs explicitly deferred in #208): each "User Creation Emails" Enabled/Disabled radio pair (`send_wp_user_email_submission/appointment/csv_import/migration`) collapses to one toggle. Each row keeps a hidden sibling so the WP POST always carries the field — unchecked toggles save as `'0'`, checked save as `'1'`, no data shape change.

- **Submissions list — bulk + per-row trash / restore / delete via AJAX** (closes #216). The Submissions admin list used to do a full page reload for both the WP-list-table bulk form (select 50 rows → Trash → Apply) and every per-row Trash / Restore / Delete button (anchor link → admin_init handler → redirect). Now a single new `ffc_submissions_bulk_action` endpoint handles all three actions over JSON, accepting a 1-N array of IDs. The matching `<tr>` rows fade out and are removed; a toast confirms the count via `FFC.Admin.showNotification`. Cap-gated on `manage_options`. The destructive Delete still asks for confirmation. `move_to_form` is intentionally left alone — it has its own dedicated modal flow with conflict detection. The legacy admin_init handler stays as the no-JS fallback so every button + the bulk form still work when JS is unavailable.

- **Activity Log — filter / search / pagination via AJAX (no page reload)** (closes #214). The Activity Log admin screen used to reload the entire page on every filter dropdown change, search submission, and pagination click. A new `ffc_activity_log_fetch` endpoint now returns server-rendered `table_html` + `pagination_html` + counts; the JS swaps just those two blocks, and `history.pushState` keeps the URL bookmarkable so the browser back button restores the previous filter. Row + pagination markup is shared with the initial PHP render via two new helpers on `AdminActivityLogPage` (`render_rows_html` / `render_pagination_html`) so the AJAX path can't drift from the server render. The Export CSV link is left alone (`php://output` streaming is already correct); clicking it just pops a small "Preparing CSV download…" toast.

- **Migrations tab — JSON-batch runner replaces the HTML-parsing loop** (closes #212). The migration auto-batch loop used to `$.ajax` the full Settings → Migrations page on every iteration (~50 KB of HTML), parse it on the client, scrape `aria-valuenow` from a re-rendered card to update the progress bar, and increment a *fake* `iterations × 100` counter that was wrong on the last (typically shorter) batch. It now POSTs to a new `ffc_migration_run_batch` AJAX endpoint that returns a small JSON snapshot — `{ processed, total, migrated, pending, percent, is_complete }` — per iteration. Each tick repaints the bar + counters with the real numbers and accumulates the actual `processed` from each batch. Faster, lower bandwidth, accurate. The legacy admin_init handler stays as the no-JS fallback (one batch per click + redirect).

- **Forms list — inline toggles for CSV public / Quiz / Device limit** (closes #210). The Forms admin list (`edit.php?post_type=ffc_form`) gains a new **Features** column with three `.ffc-toggle` switches per row. Flipping any of them persists immediately via a new `ffc_update_form_feature` AJAX endpoint and a small `Saving… / Saved` badge confirms the write — no more opening the editor, clicking a checkbox, hitting Update, and navigating back just to flip one flag. Server-side the endpoint is **capability gated per post**: a user with `edit_post` for form A can flip A's flags but gets a 403 on form B they can't edit. The endpoint also confirms the target is an `ffc_form` post (no flipping these flags on unrelated post types). Each feature has its own meta-key shape: `csv_public_enabled` writes a flat scalar, `quiz_enabled` writes into the `_ffc_form_config` array (preserving siblings like `quiz_show_score`), `device_enabled` writes into the `_ffc_device_limit` array. On error the toggle rolls back so the displayed state never lies about the persisted value.

- **Toggle sweep round 2 — metaboxes + missed settings checkboxes** (closes #208). Visual-only swap of `<input type="checkbox">` to `AdminUI::render_toggle()` across:
  - **5 form-editor metaboxes** (14 toggles): geofence (`datetime_enabled`, `geo_enabled`, `geo_gps_enabled`, `geo_ip_enabled`, `geo_ip_areas_permissive`); device-limit (`ffc_device_limit[enabled]`); public CSV download (`ffc_csv_public[enabled]`); quiz (`quiz_enabled`, `quiz_show_score`, `quiz_show_correct`); restriction (`password`, `allowlist`, `denylist`, `ticket`). Each preserves its original `name` attribute and `id` (so the form-builder JS that toggles dependent fields on `:checked` keeps working) — only the rendering changes. Saved via the post's existing `save_post` hook, no AJAX added.
  - **24 settings-tab checkboxes** missed in the original sweep (#202): URL Shortener (`url_shortener_enabled`, `url_shortener_auto_create`); Rate Limit logging (`logging_enabled`, `logging_log_allowed`, `logging_log_blocked`); Rate Limit UI (`ui_show_remaining`, `ui_show_wait_time`, `ui_countdown_timer`); Rate Limit device-signal tracking matrix (14 individual checkboxes that share `device_signals_enabled[]` — each now renders as a `.ffc-toggle` while keeping the array-submission shape); User Access (`block_wp_admin`, `bypass_for_admins`, `allow_admin_bar`).
  - **SMTP "Email Status" label** renamed to "Disable All Emails" so the toggle ON state lines up with the action it describes.

- **Cache tab "Warm Cache Now" / "Clear Cache" run inline** (closes #206). Both buttons used to be `<a href="?action=…">` redirects that ran the action server-side and refreshed the page. They now intercept the click via a small `ffc-cache-actions.js` module: the action POSTs to one of two new AJAX endpoints (`ffc_cache_warm` / `ffc_cache_clear`), the button shows a "Working…" state, and the result lands as a `FFC.Admin.showNotification` toast — no reload, no lost scroll/tab state. The legacy admin_init handler stays as the no-JS fallback and the original nonce'd href is preserved on the buttons; only the click is intercepted when JS + jQuery + `FFC.request` are available. The destructive Clear button still asks for confirmation.

- **Admin checkbox sweep — 28 settings now render as `.ffc-toggle` switches** (closes #202). The toggle infrastructure shipped in 6.5.4 spreads beyond the Geolocation tab: every boolean feature flag on **Cache** (3), **SMTP** (1), **Advanced** (1 + 14 debug flags), and **Rate Limit** (9) now uses the mobile-style switch styled with the plugin's primary colour. Each switch auto-saves inline via the `ffc_update_setting` AJAX endpoint — debounced 400 ms, with a "Saving… / Saved" badge — so admins no longer have to scroll to the bottom of the tab and click "Save Changes" just to flip one flag. The form-POST bulk save still works for every other field on the tab.
- **Admin auto-save covers non-boolean settings too** (closes #204). The same inline-save badge now fires on **33 more fields** across Cache / Advanced / General / Rate Limit — numeric inputs (`cleanup_days`, `qr_default_*`, `cache_expiration`, `ip_max_per_hour`, every `*_max_per_*` and retention setting in the rate-limit tab, …), select dropdowns (`dark_mode`, `date_format`, `code_editor_theme`, `qr_default_error_level`, `ip_apply_to`), short text (`main_address`, `date_format_custom`), URL (`csv_download_page_url`), and the 5 rate-limit "blocked" message textareas (which use a longer 800 ms debounce so admins can type a sentence without the save firing mid-word). Server-side every value goes through the right sanitiser: integers are cast and clamped to declared `min`/`max`; URLs use `esc_url_raw`; multi-line messages use `sanitize_textarea_field`; everything else uses `sanitize_text_field`. The full-tab POST keeps working as a fallback.
- **Settings AJAX endpoint supports nested option arrays.** `SettingsAjaxEndpoint` allowlist entries can now declare a `path` (ordered list of keys) so a single endpoint can target deeply-nested settings like `ffc_rate_limit_settings[device][bypass_logged_in_managers]` without flattening the option shape. Used by the 9 rate-limit toggles and the 24 nested numeric / select / message fields from the #204 expansion.
- **Auto-save wiring is now framework-wide.** The doc-ready scan that wires every `[data-ffc-autosave-key]` input moved from `ffc-geolocation-settings.js` into `ffc-admin-autosave.js` as `FFC.Admin.bootAutoSaveFields()`. Any admin page that enqueues the autosave widget gets the wiring for free — no per-tab JS needed. Fields can also declare a per-field debounce via `data-ffc-autosave-debounce="<ms>"` (used by the rate-limit message textareas).

---

## [6.5.4] (2026-05-13)

Maintenance + UX release. Fixes a handful of geofence-frontend bugs (form flash on first paint, iOS stale-fix acceptance, blocked form showing only the title, Custom preset not toggling its per-case table in real time), replaces the binary "When GPS fails" admin setting with a preset + per-case matrix that's also exposed inline, and adds a small admin-AJAX infrastructure (FFC.request helper + auto-save widget + toggle-switch CSS) used to make a few atomic settings save instantly and the geofence-locations table edit-able inline.

### Changed

- **Geolocation fallback policy is now per-case** (closes #194). The single "When GPS fails" dropdown was replaced by a preset combobox (`Tolerant` / `Hybrid` / `Strict` / `Custom`) backed by a five-row matrix the admin can edit directly when `Custom` is selected. Each row decides whether to allow or block access for one specific failure mode: user denied permission, browser doesn't support geolocation, position unavailable, browser timeout, and the 40-second iOS safety timer. The previous binary setting routed every failure through the same answer; the new structure lets sites be tolerant with conscious user decisions (permission denied / no API) while still locking the form on technical failures (timeout / safety). New installs default to `Hybrid`; existing installs are migrated silently — `gps_fallback='allow'` → `Tolerant`, `gps_fallback='block'` → `Strict`. No admin action required to preserve the current behaviour.
- **Blocked-form messages got more actionable copy.** The strings shown when GPS fails (permission denied, position unavailable, browser timeout, safety timeout) now tell the user exactly what to do to recover — enable location services, check the connection, reload — instead of restating the error code. Each transient-failure block also renders a **"Reload page" button** so the user doesn't have to figure out how to reload on mobile.
- **Progressive loading messages unified across platforms** (closes #193). Until now only Safari/iOS saw the three-stage "Requesting / Waiting for permission / Still trying" sequence (0/8/20 s). Every other browser got a single static "Detecting your location…" line. Chrome / Edge / Firefox now run the same three stages at the tighter 0/3/10 s cadence that matches their typical permission-prompt timing; Safari keeps its 0/8/20 s. The cache-hit path also mounts the same spinner and holds it for `FFCGeofence.MIN_LOADING_MS` (600 ms) before releasing the form, so admins get a visible "verified" tick instead of an instant transition from spinner-less to form-visible.
- **Boolean settings on Settings → Geolocation render as toggle switches.** The five checkboxes on that tab (IP API enable, cascade, IP cache, admin bypass datetime, admin bypass geo) now use a `.ffc-toggle` mobile-style switch styled with the plugin's primary colour. Underlying `<input type="checkbox">` is preserved for accessibility and form-POST semantics — the change is purely visual.
- **Admin bypass toggles save inline.** `admin_bypass_datetime` and `admin_bypass_geo` now persist instantly (debounced 400 ms) via a new generic `ffc_update_setting` AJAX endpoint, surfacing a "Saving… / Saved" badge next to each switch. The full-form save still works for them as a fallback.
- **Geofence Locations table is now editable inline.** Edit a row's name / lat / lng / radius, change the default-GPS or default-IP radio, click Delete, or fill the footer fields and click "Add location" — each operation persists via a per-row AJAX call (`ffc_location_save` / `ffc_location_delete`) without reloading the page. The form-POST fallback for the same fields is preserved.

### Fixed

- **Form "flash" before geofence validation** (closes #191). The CSS rule meant to hide the certificate form while GPS was being validated used a descendant combinator (`.ffc-shortcode .ffc-has-geofence …`), but the markup puts both classes on the same wrapper — so the rule never matched and the form rendered visible from first paint, only being hidden the moment JS ran. Selector switched to the chained form (`.ffc-shortcode.ffc-has-geofence …`) so the hide rule actually applies. Code comment added explaining the why so a future edit can't silently regress it.
- **iOS Safari accepting stale cached positions** (closes #191). `getCurrentPosition` ran with `maximumAge: 30000` on Safari, so a user who was inside the allowed area moments ago could walk out and iOS would happily return the pre-walk-out fix; the form rendered as valid despite the user being elsewhere. Tightened to `5000` — still avoids the GPS-prompt latency on warm reloads, no longer accepts a fix from before the user moved. Non-Safari unchanged.
- **Form body stayed hidden after a successful first-visit validation** (closes #192). `validateGeolocation` calls `.hide()` on the form body before requesting GPS, which sets an inline `display: none`. On success, `showForm()` only added the `ffc-validated` class — the inline rule should have been overridden by the CSS show rule's `!important` per spec, but at least one real browser path left the form invisible. `showForm()` now also calls `.show()` on the form body so the post-validation transition is independent of cascade resolution order.
- **Custom preset's per-case table only appeared after a save** (closes #195). The radio table mounted with the HTML `hidden` attribute and the toggle JS flipped that attribute. `hidden` is set at user-agent origin (`display:none !important`); WP admin's `.widefat` rule sets `display: table` at author origin, which wins the cascade. Initial state is now driven by an inline `style="display: none"`, and JS toggles visibility through jQuery `.toggle()` (which sets inline display and beats `.widefat`). Selecting "Custom" reveals the radios immediately.

### Internal

- **New `FFC.request(action, data, options)`** in `ffc-core.js` — promise-based admin/frontend AJAX chokepoint wrapping `jQuery.post`. Centralises nonce injection + response unwrapping + error normalisation. Sits alongside the legacy callback-based `FFC.ajax`; new code uses `FFC.request`, old call-sites migrate opportunistically when the file is touched.
- **New `FFC.Admin.autoSaveField($field, config)`** in `ffc-admin-autosave.js` — debounced inline-save widget with "Saving / Saved / Error" badge. Used by `admin_bypass_*` toggles.
- **New `.ffc-toggle` CSS component** + `AdminUI::render_toggle()` PHP helper for mobile-style boolean switches. Markup keeps the native checkbox for accessibility.
- **New admin AJAX endpoints**: `ffc_update_setting` (single-key incremental updater, allowlist + capability + sanitisation) and `ffc_location_save` / `ffc_location_delete` (per-row CRUD for the locations table).

---

## [6.5.3] (2026-05-13)

Maintenance release — bumps two vendored libraries, fixes two form-editor save bugs, normalises CPF/RF values on recruitment CSV import, and clears two jQuery 4 compatibility regressions in the reregistration form. Internal: large JS test-coverage push (49% → 73% line coverage on `assets/js/`), CI tightening, ESLint warnings cleared.

### Changed

- **Vendored thumbmarkjs bumped 1.8.1 → 1.9.0** (`libs/js/thumbmark-1.9.0.umd.js`, `FFC_THUMBMARK_VERSION`). API surface used by `assets/js/ffc-device-signals.js` (`setOption('logging', false)`, `getFingerprintData()`, `stableStringify`) is preserved. The `DeviceSignalsLoggingOffTest::test_vendored_thumbmarkjs_present_at_pinned_path` path assertion was updated to track the new bundle name; both JS and PHP test suites pass against the bumped version.
- **jQuery UI theme bumped 1.14.1 → 1.14.2** (`libs/css/jquery-ui-smoothness.css`, `FFC_JQUERY_UI_VERSION`). The CSS payload is byte-identical between the two upstream releases — only the file-header comment moves to `v1.14.2 - 2026-01-28` — so the visible change is the cache-bust version string emitted by `wp_enqueue_style`.
- **Recruitment CSV import: CPF and RF columns are now normalised at parse time** (#172). The importer strips punctuation (`.`, `-`, spaces, slashes), accepts canonical-length values as-is, and left-pads shorter values with leading zeros up to the canonical width (11 digits for CPF, 7 for RF). Pre-formatted values from Excel/Sheets (`123.456.789-09`, `123.456-7`) are accepted directly; values that exceed the canonical width are rejected with new `recruitment_csv_cpf_too_long` / `recruitment_csv_rf_too_long` error codes instead of being accepted silently. The normalised digit string is written back into the row before downstream consumers see it.

### Fixed

- **Form editor: turning the Public CSV Download (group 7) or Device Fingerprint Limit (group 8) toggle OFF did not persist.** The browser strips unchecked checkboxes from the POST and the admin JS also disables every sub-field on uncheck, so the entire `ffc_csv_public` / `ffc_device_limit` array vanished from `$_POST` and the save handler's `isset()` guard skipped the block — leaving `_ffc_csv_public_enabled` / `_ffc_device_limit_enabled` stuck at `'1'`. Each metabox now emits a hidden `[present]=1` marker outside the `<table>` (out of reach of both the sub-field disable JS and the globally-off disable), so the array is always submitted and the save handler always sees the user's intent to disable.
- **Form editor: explicitly choosing "No — only Form ID + Hash" for the public CSV CPF gate silently reverted to "Audit".** `FormEditorSaveHandler::save_form_data()` unconditionally coerced `'none' → 'audit'` on every save, masking the user's choice. Coercion now only applies on the very first enable transition (toggle flipping `0 → 1` while the dropdown is at the default `'none'`); on later saves the user's explicit selection sticks.
- **Reregistration form: every blur threw `TypeError: $.trim is not a function` under jQuery 4**, blocking all blur-based field validation. `assets/js/ffc-reregistration-frontend.js` now uses the native `String.prototype.trim()` via `($field.val() || '').trim()`.
- **Reregistration form: save-draft and submit always sent `fields: {}` under jQuery 4.** jQuery 4's stricter attribute-value parser rejects the unescaped `[` inside `[name^="fields["]`, so `getFields()` silently returned an empty set. The selector is now `[name^="fields"]` — every reregistration field is named `fields[…]`, so the bare prefix is sufficient.

### Internal

- **JS unit coverage `assets/js/` raised from 7.37% to 72.84%** across multiple sprints. Per-file highlights: `ffc-admin.js` 54 → 95 %, `ffc-frontend.js` 56 → 95 %, `ffc-frontend-helpers.js` 69 → 95 %, `ffc-geofence-frontend.js` 57 → 92 %, `ffc-csv-download.js` 25 → 90 %, `ffc-reregistration-frontend.js` 22 → 96 %, `ffc-audience.js` 0 → 47 %. Two 0% blind spots closed (`ffc-working-hours.js`, `ffc-geofence-admin.js` shell). `JS_COVERAGE_FLOOR_LINES` gate ratcheted from 47 to 70.
- **CI: Coverage job timeout raised 15 → 20 min** to absorb the wider PHP suite (pcov + ~3 800 tests routinely lands at 14-15 min and was tripping the prior ceiling).
- **ESLint: the nine pre-existing `no-unused-vars` warnings cleared.** Dead `escapeHtml` / `getEnvironmentName` helpers removed; legitimate callback-signature args (`onDayClick($day)`, jQuery AJAX `error(xhr, status, error)`) underscore-prefixed.
- **`CLAUDE.md` added at repo root** documenting the auto-merge convention, webhook semantics, CI gates, and test-infrastructure notes that new agent sessions inherit nothing of on cold start.

---

## [6.5.2] (2026-05-10)

**Refactor the user-dashboard god-object into self-registering panels (#142).** `assets/js/ffc-user-dashboard.js` was 1548 LOC of one IIFE owning seven panel renderers, AJAX state, and a tab dispatch built from chained `if/else if`. Adding a panel touched five places. Split into 8 files with a panel registry: core dispatches via `FFCDashboard.panels[tab].render(state, page)`, and adding a panel now needs zero edits to the core file.

### Changed

- **`assets/js/ffc-user-dashboard.js` removed; replaced by 8 sibling files.** `ffc-user-dashboard-core.js` (panel registry, generic event bindings, summary header, tab dispatch); `ffc-user-dashboard-cal-export.js` (ICS / Google / Outlook export utilities, shared by appointments + audience); `ffc-user-dashboard-{certificates,appointments,audience,reregistrations,profile}.js` (per-tab panels self-registering into `FFCDashboard.panels`); `ffc-user-dashboard-audience-join.js` (joinable-groups subsection rendered into the profile panel).
- **`includes/shortcodes/class-ffc-dashboard-asset-manager.php` enqueues all 8 scripts with explicit dependency chain.** The legacy `'ffc-dashboard'` handle is preserved (now points at the core file) so external dependents (`ffc-reregistration-frontend`, etc.) keep working without changes. New handles `ffc-dashboard-{cal-export,certificates,appointments,audience,reregistrations,profile,audience-join}` each declare their deps on core (and `ffc-dashboard-cal-export` for appointments/audience, `ffc-dashboard-profile` for audience-join).
- **Tab dispatch is now table-driven.** The chained `if (target === 'certificates') { … } else if …` in `loadInitialTab`, `switchTab`, `applyTabFilter`, and `handlePagination` (4 places, 5 panels each) becomes `var panel = this.panels[tab]; if (panel) panel.{load,render}(…)`. A future panel just creates a new file and a `wp_enqueue_script` line; no edits to `ffc-user-dashboard-core.js`.

### Deferred (originally part of #142)

- **`assets/js/ffc-audience.js` split.** The original audit grouped this with user-dashboard, but on closer inspection it's a procedural module (35+ functions sharing a `state` object inside one IIFE), not a god-object. Splitting would be source-level reorganisation, not architectural cleanup. Combined with low churn (2 commits in the last 6 months), the cost/benefit doesn't justify the work today. Plan documented in #142 for the day activity on the file justifies it.

---

## [6.5.1] (2026-05-10)

**DRY audit on the AJAX handler boilerplate (#143).** The audit asked for an `AjaxRequestTrait` collapsing the `check_ajax_referer` + `current_user_can` + `wp_send_json_error` triplet — but that trait already exists at `includes/core/class-ffc-ajax-trait.php` since 4.11.2. The remaining ask was call-site adoption across four admin handler classes. After two attempts (initial migration and a Brain Monkey state-rebinding workaround) hit irreducible cross-class test pollution in the suite, the actual call-site swap was reverted on this branch — leaving only the small, low-risk trait extension below as the real shipped change. See "Deferred" for the full reasoning.

### Added

- **`AjaxTrait::check_ajax_admin_or( string $granular_cap )`.** Encodes the "site admin always passes, plus delegated operators with `$granular_cap` pass" contract that admin-export and admin-settings handlers want. Available for new and migrated handlers; no existing handler adopts it in this release.

### Deferred

- **#143 S1 — call-site migration to `AjaxTrait` (4 classes, 8 handlers).** Implemented and tested individually, but the full-suite run hit `Brain\Monkey\Expectation\Exception\MissingFunctionExpectations: "wp_unslash" is not defined nor mocked in this test` inside the trait, on cross-class boundaries only. Diagnosis: Brain Monkey defines an eval'd placeholder for `wp_unslash` the first time any test class stubs it; the placeholder survives `Monkey\tearDown()`, so subsequent test classes' `Functions\when('wp_unslash')->returnArg()` calls register a Patchwork redefine that gets dropped before the test body executes. A workaround using `Functions\expect('wp_unslash')->zeroOrMoreTimes()->andReturnUsing()` rebound the function but interfered with negative-path tests in `SettingsTest`. The clean fixes (move affected tests to `@runInSeparateProcess`, or define `wp_unslash`/`sanitize_text_field` as real functions in `tests/bootstrap.php`) are both bigger than the call-site swap. Tracked for a follow-up that pairs the migration with a Brain Monkey state audit.

### Honest no-ops (audit findings already implemented or not actual duplication)

- **#143 S2 — `Sanitizer` utility class:** 32 inline `array_map('sanitize_text_field', …)` / `array_map('absint', …)` callsites across the codebase. Introduction tested correctly in isolation but reproduced the same Brain Monkey cross-test pollution as S1 — same deferral rationale. The real win is small (each callsite is a single line) and doesn't justify destabilising the test harness.
- **#143 S3 — `DateFormatManager`:** WordPress's `get_option()` already caches via `wp_cache_get`, so a memoising service for `date_format` / `time_format` would only save the function-call overhead, not the query. Marginal value, declined.
- **#143 S4 — `TableNames` / `SettingsKeys` / `Capabilities` constants classes:** the audit estimated 28+ literals; reality is 209 callsites of `wpdb->prefix . 'ffc_X'` alone. Constant-class migration would touch 209 sites for a small typo-protection win — disproportionate churn for the value.
- **#143 S5 — `FormFieldRenderer`:** the 8 classes that implement `render_field`/`render_section` live in 6 distinct domains (admin form-editor metabox, admin custom-fields metabox, reregistration form, settings tab, recruitment public shortcode, generic shortcodes). Each emits different markup against different field-type ecosystems. A unified renderer would either become a god-object or be too thin to add value.
- **#143 S6 — Shared modal CSS:** the two implementations the audit flagged (`assets/css/ffc-audience.css` `.ffc-shortcode .ffc-modal*` vs `assets/css/ffc-admin-move-submissions.css` `.ffc-move-modal*`) actually use different class names, different positioning, and different visual treatment. They're two separate modals, not duplicates of one — extraction would force one to regress visually.

---

## [6.5.0] (2026-05-10)

**Performance and stability (#144).** Three real implementations + three "already done in earlier releases" honest no-ops + one deferred-as-follow-up. Minor bump because the migration adds indexes on existing tables and changes the `create()` invariant on audience bookings (now atomic).

### Added

- **`Activator::maybe_add_perf_indexes()` (#144 S1).** Adds `KEY idx_created (created_at)` to `ffc_recruitment_candidate`, `ffc_recruitment_notice`, and `ffc_reregistration_submissions` on installs that didn't have them. Idempotent — gated on `ffc_perf_indexes_db_version` keyed to `FFC_VERSION`. Hooked from `Loader::init_plugin()` so existing installs pick up the indexes on next page load. The audit also asked about `idx_updated` / other tables; investigation showed `ffc_activity_log`, `ffc_rate_limit_logs`, `ffc_device_signals` already declare `idx_created`, and tables like `ffc_user_profiles` have no query orders/filters on `created_at` — adding indexes there would be pure write overhead, so left alone.
- **`ReregistrationSubmissionRepository::stream_for_export()` (#144 S5).** Generator yielding rows in 500-row chunks, used by `ReregistrationCsvExporter` instead of materialising the full result set. Bounded memory for 50k+ row exports.
- **Cron `cleanup_stale_export_jobs()` (#144 S6).** Hooked into the existing `ffcertificate_daily_cleanup_hook`, walks `_transient_ffc_csv_export_*` and `_transient_ffc_public_csv_*` rows in `wp_options`, unlinks the temp CSV files referenced in the payload, deletes the transient. Reclaims disk space + DB rows from CSV exports the user abandoned mid-flight.

### Changed

- **`AudienceBookingRepository::create()` is now atomic (#144 S2).** Conflict-check + insert run inside a `START TRANSACTION ... COMMIT` block, with a `SELECT ... FOR UPDATE` on the conflict predicate. Before this commit, two concurrent requests for the same `(environment, date, time)` slot could both pass the conflict check (no row existed yet) and both insert. The `idx_env_date_status` index already declared on the table gives InnoDB the row + gap locks needed to block concurrent inserts in the locked range. Mirrors the pattern at `SelfSchedulingAppointmentHandler::create_or_update():140`.

### Honest no-ops (audit findings already implemented)

- **#144 S4 — N+1 in admin user columns:** already batch-loaded since v4.9.7 (`load_certificate_counts`, `load_appointment_counts`, `load_recruitment_notice_counts`). Three GROUP BY queries per page render, regardless of user count.
- **#144 S7 — Activity log pagination:** already implemented; `class-ffc-admin-activity-log-page.php` uses `$per_page = 50` (smaller than the requested LIMIT 100) and `$total_pages` for navigation. Export path keeps the full dump (`limit => 999999`).
- **#144 S8 — Conditional asset enqueue:** every public-frontend `wp_enqueue_scripts` hook in the plugin already gates on `has_shortcode()` against the rendered post content (or enqueues from inside the shortcode's `render()`). No unconditional enqueue exists.

### Deferred to follow-up

- **#144 S3 — Async ficha generation via Action Scheduler:** the work requires bundling Action Scheduler (~150 LOC + two custom tables + a new admin page) which is a wider surface than this PR's scope. Tracked as #148.

---

## [6.4.1] (2026-05-10)

**REST API lockdown (#139).** Plugs a config-blob leak in the public REST surface and adds a circuit-breaker on the public booking calendars. `GET /wp-json/ffc/v1/forms` and `GET /wp-json/ffc/v1/forms/{id}` previously carried `permission_callback => '__return_true'` and returned the full `_ffc_form_config` blob — which on a typical install contains `allowed_users_list`, `denied_users_list`, `validation_code`, `generated_codes_list`, `geo_areas`, `geo_ip_area_location_ids`, `email_body`, `email_subject`. Anyone with the public REST URL could enumerate every form's gating policy.

### Added

- **`ffc_read_forms_api` capability.** New admin-level capability granted to the `administrator` role automatically via `Loader::ensure_admin_capabilities()` (runs once per `FFC_VERSION` change). External integrators authenticate with WordPress Application Passwords (HTTP Basic, since WP 5.6); the linked user must hold the cap.
- **Documentation tab section "19. REST API Authentication".** Distinguishes public-by-design endpoints (form submission, certificate verification, booking calendars) from authenticated endpoints. Includes a curl example using Application Passwords + the new capability.

### Changed

- **`GET /forms` / `GET /forms/{id}` now require `ffc_read_forms_api`.** Permission callback delegates to `current_user_can()`. Trimmed payload: only `id`, `title`, `status`, `date`, `modified`, `link` — the `_ffc_form_config` blob, `fields` array, and `background` are gone. Integrators that need form structure use the public-by-design `GET /forms/{id}/schema` endpoint.
- **`limit` parameter on `GET /forms` clamped at 100.** Out-of-range or non-numeric values coerce to the default of 100. Pagination is a follow-up.
- **Calendar GET routes carry an IP-keyed rate-limit circuit breaker.** `GET /calendars`, `GET /calendars/{id}`, and `GET /calendars/{id}/slots` now reject requests from IPs that have already tripped the rate-limit pool (typically populated by failed/abusive submit/verify hits from the same address). Returns HTTP 429 with `wait_seconds`.

### Documented

- **Public-by-design routes carry an explicit `phpcs:ignore` comment + per-route block docblock.** `GET /forms/{id}/schema`, `POST /forms/{id}/submit`, `POST /verify`, `POST /calendars/{id}/appointments`, and the three calendar GETs each describe the public flow they serve and the secondary defences in play (rate-limit pool, geofence, hash_equals on tokens, CPF/RF validation). Future contributors see the rationale without having to chase the audit trail.

### Security

- No CVE assigned. Pre-6.4.1 the `_ffc_form_config` blob was readable by any anonymous caller via `GET /wp-json/ffc/v1/forms`. The blob contains gate-list metadata (allowed/denied user IDs, generated/validation codes, geofence configuration). Sites running pre-6.4.1 should treat their generated/validation codes and gate-list contents as compromised; rotate codes after upgrading if the API was reachable from the public internet.

---

## [6.4.0] (2026-05-10)

**Unified CSV IO abstraction (#126).** Internal refactor: every CSV the plugin reads or writes now flows through a single pair of primitives — `\FreeFormCertificate\Core\Csv::writer()` / `Csv::reader()` — instead of the eight exporters and two importers each implementing fputcsv/fgetcsv/delimiter detection/BOM handling on their own. No user-facing change for files that already used `;` (the post-6.3.9 default); audience export templates now correctly emit a UTF-8 BOM (previously they were the only files in the plugin that didn't, causing Excel to render accented characters as mojibake until manually fixing encoding). Minor bump because the surface area touched is broad even though behaviour is preserved on every public format.

### Added

- **Certificates Dashboard (admin).** New page registered as the first item under the Certificate menu (`edit.php?post_type=ffc_form&page=ffc-certificates-dashboard`). Renders a monthly calendar of every form keyed by its GeoFence start date (with a fallback to the publication date when the form has no GeoFence configured), plus a side list of forms scheduled for the day clicked in the calendar. Day cells get a count chip — green when at least one form on the day is GeoFence-sourced, gray when all are post_date fallbacks. Backed by a new `GET /ffc/v1/certificates/calendar` REST endpoint gated on `edit_others_posts` (Editor + Administrator). Reuses the existing `FFCCalendarCore` JS grid.
- **Csv / CsvWriter / CsvReader (`includes/core/`).** Public IO primitives (`final class` facade + worker pair). Writer guarantees: BOM emitted exactly once before the first row, `;` delimiter by default, RFC 4180 quoting, optional `skip_bom` flag for append-mode workers picking up after an init writer. Reader guarantees: BOM stripped at byte 0 if present, `,`-vs-`;` auto-detection on the first line (ties → `,` for back-compat), `each(callable)` streams body rows for memory-bounded imports, `header()`/`all()`/`close()` for the convenience cases. 28 PHPUnit cases cover round-trip integrity, BOM contract, all four quoting edge cases, and the resource-vs-string entry points.

### Changed

- **CSV exports migrated to `Csv::writer`.** Eight exporters touched, one per commit: admin submissions (`CsvExporter`), public synchronous + async submissions (`PublicCsvExporter`), self-scheduling appointments (`AppointmentCsvExporter`), recruitment notice template, reregistration submissions (`ReregistrationCsvExporter`), audience admin templates (members + audiences), public-csv-download audit log, admin activity log. Per-exporter mb_convert_encoding and BOM-emission code removed; the writer handles both centrally.
- **CSV imports migrated to `Csv::reader`.** Two importers (recruitment + audience) drop their per-class `detect_delimiter()` / `peek_delimiter()` / `parse_csv_line()` / `strip_utf8_bom()` helpers (~250 LOC removed). Recruitment importer's previous line-splitter (`preg_split('/\r\n|\n|\r/', $content)` + `str_getcsv` per physical line) is replaced with the reader's `fgetcsv`-based parser, which now correctly handles quoted multi-line cells (was a silent parse failure before).
- **CsvExportTrait scope narrowed.** `output_csv()` removed (replaced by the new writer); the trait now contains only the JSON-data-shape helpers (extract_dynamic_keys, decode_json_field, build_dynamic_headers, extract_dynamic_values) used by the submission and appointment exporters to flatten encrypted/plaintext `data` columns into dynamic spreadsheet columns.

### Fixed

- **Audience export templates now emit UTF-8 BOM.** `members-export-*.csv` and `audiences-export-*.csv` were the only CSV files in the plugin without a BOM, so Excel opened them in the wrong encoding by default. The migration to `Csv::writer` standardises BOM emission. Round-trip with the matching importer is unaffected: the importer always tolerated BOM either way.
- **Audience shortcode modals fall behind the calendar instead of appearing as an overlay.** The day-detail and booking modals in `[ffc_audience]` were rendered outside the `.ffc-shortcode` wrapper that the `ffc-calendar-wrapper` refactor introduced, so the `.ffc-shortcode .ffc-modal` rules (which provide `position: fixed`, the dark backdrop and centred placement) never matched. Modals dropped into normal flow and rendered inline below the calendar. Added `ffc-shortcode` to each modal's class list so the existing scoped CSS applies again — minimal change, no layout or selector-broadening side effects.

---

## [6.3.11] (2026-05-10)

**Form-editor UX polish + #50 specificity hardening + duplication fix + small a11y/CSS chores.** This release groups everything that was queued under PR #132: the per-form Public CSV Download and Device Fingerprint metaboxes get the same enable/disable + preservation discipline as the rest of the form editor; the form-duplication action no longer silently drops eight per-form settings; and every public shortcode CSS selector now sits at specificity (0,2,0) so theme overrides at `.entry-content div { ... }` (the historical collision pattern that prompted the `.ffc-input !important` flood) can no longer break the layout.

### Added

- **`.ffc-shortcode` anchor class on every public shortcode wrapper.** New side-class — emitted alongside the existing wrapper class on `[ffc_form]`, `[ffc_verification]`, `[ffc_self_scheduling]`, `[ffc_csv_download]`, `[ffc_audience]`, `[user_dashboard_personal]`, and the two recruitment shortcodes — lets every frontend rule prefix itself with `.ffc-shortcode` and hit (0,2,0) without rewriting selectors per-shortcode. Wrapper-class selectors (e.g. `.ffc-form-wrapper`) use the chained form `.ffc-shortcode.ffc-form-wrapper`; descendants use the descendant form. The `.ffc-input !important` flood is preserved for now (its removal needs per-theme visual QA); the specificity raise is the primary defence. Closes #50.
- **Public CSV Download metabox — visual breakdown of audit attempts.** The audit-log row used to render a single sentence ("N attempts logged"); now shows three colour-coded cards (Total / Successful / Failed). Successful = `success` + `audit_pass` + `voluntary` result tags; Failed = everything else (defaulting unknown future tags to "fail" avoids silently inflating success). Cards use the existing design tokens so dark mode + high-contrast inherit automatically.
- **Stylelint baseline** with `@wordpress/stylelint-config` and pragmatic project overrides (preserves snake_case ID selectors that match plugin form-field IDs, allows named `white`, etc.). New scripts: `npm run lint:css` and `npm run lint:css:fix`. Auto-fix applied across the source CSS: `font-weight: bold/normal` → `700/400`, `:before` → `::before`, redundant single-word font-family quotes dropped.
- **`.ffc-initially-hidden` utility** (no `!important`) for elements that JS reveals via inline `display`. Replaces the inline `style="display:none"` on the verification-page spinner and disambiguates it from the existing `.ffc-hidden` honeypot helper (which uses `position: absolute !important`).

### Changed

- **Public CSV Download metabox now disables every sub-field when "Enable Public Download" is off.** Initial paint is server-rendered via `disabled`; subsequent toggles update inline via JS without a save round-trip. Visual fade through `.ffc-csv-public-disabled`. Save handler short-circuits the sub-field branch when `enabled=0` so toggling the feature off and saving preserves the persisted limit / hash / CPF mode / whitelist instead of overwriting them with the "no fields submitted" defaults that disabled inputs would silently produce.
- **Public CSV Download — CPF mode defaults to `audit` on first enable + save.** Previous behaviour stored `none` (no logging). Now newly-enabled public downloads always log every attempt out of the box; site owners must explicitly opt out by selecting "No — only Form ID + Hash" again after the first save.
- **Public CSV Download — whitelist textarea only renders when persisted mode is `whitelist`.** Switching the dropdown alone no longer reveals it — the user must save the form first. A short tip line is rendered in the slot when the toggle is on but the persisted mode is something else, so the dropdown→save→reveal workflow is discoverable. Save handler also stops deleting the whitelist when the textarea is absent from the POST (mode-in-flight scenario), preserving the persisted list.
- **Device Fingerprint metabox now hard-gates on the global subsystem.** When **Settings → Rate Limit → Device Fingerprint** is OFF, every input including the master "Enable for this form" checkbox renders disabled — existing values stay visible (read-only) so the admin can audit what was configured. The red warning is preserved verbatim. When global is ON but per-form is OFF, the master checkbox stays editable; secondary fields (max, threshold, message) lock until the user enables the per-form override.
- **Device Fingerprint — `Max submissions per device` defaults to 2 when left blank.** Hard default (not inherit-from-global), written to post meta on first enabled save. Threshold and message keep the inherit-from-global semantic (empty deletes the meta so the global default applies at read time). Placeholder updated from "Inherit from global" to "Default: 2".
- **Typography variables migrated from px to rem.** `--ffc-font-size-xs` through `--ffc-font-size-2xl` in `assets/css/ffc-common.css` now expressed as rem fractions of the 16px root (xs=0.6875rem, sm=0.8125rem, base=0.875rem, lg=1rem, xl=1.125rem, 2xl=1.5rem). Honours the user's browser font-size preference for accessibility.

### Fixed

- **Form duplication copies Public CSV + Device Fingerprint settings.** `Cpt::handle_form_duplication` previously copied only fields, config, bg image, and geofence config; everything stored under its own meta key — every option 7 / option 8 setting — was silently lost on duplication. Now copies eight additional config metas: `_ffc_csv_public_enabled`, `_ffc_csv_public_limit`, `_ffc_csv_public_cpf_mode`, `_ffc_csv_public_cpf_whitelist`, `_ffc_device_limit_enabled`, `_ffc_device_limit_max`, `_ffc_device_match_threshold`, `_ffc_device_limit_message`. Hash, counter, and audit log are intentionally NOT copied: a shared hash would let the same pre-shared download URL unlock both forms (security regression), the counter belongs to the original, and the audit log is per-form history. The next save with `_ffc_csv_public_enabled=1` regenerates the hash automatically.
- **`Undefined array key "enable_restriction"` warning on form save.** `FormEditorSaveHandler::save_form_data()` read several `$_POST['ffc_config']` keys directly without a fallback — when the form editor posted `ffc_config` without those checkboxes (default state / conditional rendering), PHP 8+ emitted `Warning` lines into `debug.log` on every save. Behaviour was unchanged (`sanitize_key(null)` returns `''`), but the log was polluted. Every scalar lookup now uses `?? ''`, matching the convention already used for `email_body` and the geofence block below.
- **`email_hash_rehash` migration no longer re-surfaces as pending after new submissions.** The migration's status accounting was cursor-based: any row with `id > cursor` was reported as pending. Once all legacy rows had been walked, new submissions (which already write the salted hash via `Encryption::hash`) inserted ids above the cursor and falsely re-appeared as pending in the admin UI. New `ffc_email_hash_rehash_completed` flag latches the migration as complete once both tables have been walked with no errors; subsequent inserts do not re-trigger the alarm because the buggy write paths were already fixed and new rows are correct by construction.

---

## [6.3.10] (2026-05-09)

**Bugfix release.** Fixes around the per-device submission limit (added in v6.3.0) plus a polish pass on reprint UX and i18n coverage. The server-side gate now correctly lets the reprint flow through; the form gains a friendly client-side hint when the current device has already submitted before; the reprint success card now surfaces the existing certificate's authentication code (it was silently missing on reprint); and several JS-only fallback strings introduced over v6.3.6–v6.3.10 are now translatable through the standard `wp_localize_script` pipeline.

### Fixed

- **Reprint flow now bypasses the device fingerprint limit.** The pipeline order in `FormProcessor::handle_submission_ajax` was: `RateLimiter::check_all` (which contains the device fingerprint N-of-M check) → ... → `ReprintDetector::detect`. So a legitimate user who lost their PDF and tried to re-submit from the same device hit the device gate first, got a "Multiple submissions detected from this device" error, and never reached the reprint detector that would have returned the existing submission. New behaviour: we pre-run `ReprintDetector::detect()` BEFORE `check_all` and, when it flags `is_reprint=true`, set `$skip_device = true` (the same flag the manager bypass already uses, so `RateLimiter::check_all` stays untouched). The reprint detector still runs at its canonical position later for the actual flow; the pre-run is purely a gate decision. ~10 LOC change.
- **PDF post-download confirmation messages are now translatable.** Four user-visible strings shown at the end of the PDF generation flow (`pdfOpenedIOS`, `pdfSavedAndroid`, `pdfDownloaded`, `pdfBlankWarning`) lived only as JS string literals in `assets/js/ffc-pdf-generator.js` and were never extracted by the gettext pipeline — so a pt_BR site would still see *"PDF saved! Check your Downloads folder."* in English even with the Brazilian translation file loaded. They now flow through `wp_localize_script( 'ffc-frontend', 'ffc_ajax', [ 'strings' => [ ... ] ] )` like every other PDF-flow string, are picked up by the existing `__()` extractor, and ship with pt_BR translations.
- **pt_BR catalogue back-filled.** `languages/ffcertificate-pt_BR.po` was missing the strings introduced in v6.3.6 (placeholder-tab + manual-fallback flow), v6.3.7 (WebView preventive banner) and v6.3.10 (already-submitted notice). All ~17 entries are now translated and `ffcertificate-pt_BR.mo` recompiled. The stale `ffcertificate-pt_BR.l10n.php` cache is removed so WordPress 6.5+ regenerates it from the fresh `.mo` on next load.

### Added

- **Friendly "already submitted" notice on `[ffc_form]`.** A new client-side script (`assets/js/ffc-already-submitted-notice.js`) tracks successful submissions in `localStorage.ffc_submitted_forms` (capped at 50 form IDs). On subsequent loads of a form whose ID is in the list, the page renders a dismissible info banner above the form: *"You may have already submitted this form. We detected a previous submission from this device. If you lost your certificate, just fill in your CPF and submit — the system recognises it and returns the existing certificate."* Soft hint, not a hard block — server-side gate (now reprint-aware) remains the source of truth. The banner dismissal is remembered for the session (`sessionStorage`). Three new i18n strings: `title`, `body`, `dismiss`. CSS reuses the design tokens already in `ffc-frontend.css`, mobile-responsive.
- **Reprint success card now shows the authentication code.** Before, the success card on reprint was missing the "Authentication Code:" row because `$submission_data['auth_code']` was never populated for the reprint branch (the value lives on the existing DB row, not on the user-resubmitted form data). The non-quiz reprint branch now copies `auth_code` from the `ReprintDetector` payload, and the quiz reprint branch copies it from the existing submission row, so `templates/submission-success.php` renders the code on reprint exactly as it does on a brand-new submission. (Earlier in this same release line we briefly also embedded the code in the H3 message — that caused it to render twice and was reverted; the dedicated row in the card is the single source of truth.)

### Backwards compatibility

- The actual reprint detection logic is unchanged — same query against `cpf_hash`/`rf_hash` on `wp_ffc_submissions`, same `is_reprint` semantics. Only the ordering relative to the device check changes.
- Users on a fresh device who try to submit a different CPF still hit the device gate as expected (no reprint match, `$skip_device` stays false).
- Manager bypass + reprint stack correctly: manager already had `$skip_device = true`; reprint just adds another path to that same flag.
- Notice script is a pure progressive enhancement — sites with localStorage disabled (private mode, restrictive policies) just don't see it; nothing breaks.

No schema change.

---

## [6.3.9] (2026-05-09)

**Consistency release.** Standardises every plugin-emitted CSV on the semicolon (`;`) delimiter, fixing three exporters that were still using the comma default and breaking Excel-pt-BR / WPS / LibreOffice locale opens. Audience importer gains delimiter auto-detection so legacy comma-separated files keep working.

### Changed

- **Three CSV exporters now emit `;` instead of `,`**, matching the rest of the plugin (which already used `;` since 5.x): the public CSV download audit-log exporter (`PublicCsvDownload::handle_export_log_request`, introduced in 6.3.3), the reregistration submissions exporter (`ReregistrationCsvExporter`), and the audience admin import templates (`AudienceAdminImport::export_*_csv`). Now a Brazilian admin double-clicking the `.csv` no longer sees everything crammed into column A.
- **Audience importer auto-detects `,` vs `;`.** Mirrors the recruitment importer's `detect_delimiter()` strategy: peek the header line, count unquoted occurrences, return whichever wins (ties resolve to `,`). Implemented as a private `peek_delimiter()` static helper that rewinds the file handle so the regular `fgetcsv()` loop runs unchanged. Comma-separated files exported by older versions (or hand-authored elsewhere) continue importing without changes.
- **Sample CSVs in `AudienceCsvImporter::get_sample_csv()`** updated to use `;` to match the live export templates. Cosmetic only — the importer accepts both.

### Backwards compatibility

- **Existing comma-separated CSV files**: still import correctly via the audience importer's auto-detect (added in this release) and the recruitment importer's pre-existing detection. Nothing breaks.
- **Custom downstream parsers (admin's external tooling)**: if a site automated parsing of the reregistration or audit-log CSV against the comma format, those scripts need a one-line update to `;`. Documented here.

No PHP/server schema change. PHPUnit + PHPStan + WPCS clean.

---

## [6.3.8] (2026-05-08)

**UX release — two form-copy fixes.** Shrinks the device fingerprint LGPD disclosure on `[ffc_form]` and corrects the CPF-field labelling in `[ffc_csv_download]`'s `audit` mode where the markup contradicted the server behaviour.

### Changed

- **Device fingerprint disclosure** in the certificate form's consent box is now a native `<details>` / `<summary>` element. Default state shows a single short line ("We anonymously identify your device to prevent duplicate submissions. Learn more."); clicking expands a paragraph with the technical specifics (`thumbmarkjs`, MIT licence, locally processed signals, no third-party transmission). Two i18n strings instead of one. Preserves all LGPD-required information for auditors while cleaning up the form for typical users.
- **CSS polish for the new `<details>` variant**: native disclosure markers hidden on Chrome/Firefox/Safari, focus ring matched to the primary colour token, "Learn more" rendered as an underlined cue that drops the underline once expanded. ~30 LOC added to the existing `.ffc-consent-description` selector chain — no new CSS files.

### Fixed

- **`[ffc_csv_download]` CPF field in `audit` mode now correctly identifies the field as required.** The server-side validator (`PublicCsvDownload::validate_cpf_requirement`) has always rejected an empty or malformed CPF in `audit` mode (returns `'CPF is required to download this CSV.'` and `'Invalid CPF.'` respectively) — only the matching against an allow-list was skipped. The shortcode markup contradicted that on three points: missing the `*` required marker, missing `required aria-required="true"` on the `<input>`, and the description text claiming "does not gate the download". Updated to: `*` shown, `required` set, and the description rewritten to *"Your CPF is required for traceability and is recorded in this form's audit log (encrypted at rest). It is not validated against any allow-list."*
- **Form editor metabox copy aligned with actual behaviour.** The CPF mode dropdown's `audit` option used to read *"Audit — ask but never block"*, which contradicted the server (it does block on missing or malformed CPF; only the list-match step is skipped). Renamed to *"Audit — require CPF, but do not match against any list"*. While there, updated the help text below the dropdown to mention that the CPF is **encrypted at rest** (not hashed — the schema changed in 6.3.3) and to point at the "Download audit log (CSV)" button just below.

No PHP/server change. JS/test coverage unchanged.

---

## [6.3.7] (2026-05-08)

**UX release.** Adds a preventive in-app browser warning banner to `[ffc_form]` and `[ffc_csv_download]`. Builds on the v6.3.6 popup-blocker fallbacks but tries to nudge users to open the page in a real browser **before** they invest time filling the form.

### Added

- **WebView warning banner** — when the page is loaded inside an Android WebView (host app shell uses `; wv)` UA marker) or an iOS in-app browser (Facebook, Instagram, Twitter/X, WhatsApp, LinkedIn, TikTok, Line — detected by the host app's UA marker), a friendly amber banner appears above the form: "Download may fail in this app. To make sure the certificate downloads correctly, please open the page in your main browser (Chrome or Safari)." Two CTAs:
  - **"Open in browser"** — Android WebView gets handed an `intent://...#Intent;package=com.android.chrome;scheme=https;end` deep-link that, on most WebViews, hands control to Chrome. iOS in-app browsers can't be flipped to Safari programmatically (no public API), so they get an `alert()` with the manual menu instructions ("Tap the menu icon (•••) at the bottom of the app and choose 'Open in Safari'").
  - **"Continue anyway"** — dismisses the banner and stores a `sessionStorage` flag so the user isn't re-nagged on subsequent page loads in the same session. The v6.3.6 fallback layers (pre-open tab + manual-tap CSV button) still cover anyone who proceeds.
- New file: `assets/js/ffc-webview-warning.js` (~150 LOC, vanilla JS, no jQuery dep).
- New CSS block at the end of `assets/css/ffc-frontend.css` (`.ffc-webview-warning*`, ~70 LOC), reusing the design tokens already in the stylesheet.
- Five new i18n strings under the `ffc_webview_warning.strings` localized object: `title`, `body`, `openInBrowser`, `continueAnyway`, `iosInstructions`. JS keeps English fallbacks if a site isn't translated yet.

### Why preventive matters even with v6.3.6

The technical fix in v6.3.6 (pre-open tab + manual-tap fallback) catches almost all silent download failures, but Android WebView remains an outlier:
- Many Android WebViews **don't have a built-in PDF viewer**, so opening the blob URL just shows raw bytes.
- The share sheet inside a WebView often lacks "Save to Files" or "Save to Downloads".
- `<a download>` is widely ignored.

Asking the user to switch to Chrome/Safari **once**, before they fill the form, sidesteps all of those quirks for the rest of the session.

### Detection scope

The banner only fires for confirmed in-app browsers:

| Platform | UA marker |
|---|---|
| Android WebView | `; wv)` or `; wv;` |
| Facebook (iOS) | `FBAN`, `FBAV`, `FBIOS` |
| Instagram (iOS) | `Instagram` |
| Twitter/X (iOS) | `TwitterIOS/`, `Twitter for iPhone` |
| WhatsApp (iOS) | `WhatsApp` |
| LinkedIn (iOS) | `LinkedInApp` |
| TikTok (iOS) | `BytedanceWebView`, `musical_ly` |
| Line (iOS) | `Line/` |

Real Safari (mobile + desktop), Chrome iOS (`CriOS`), Android Chrome, Samsung Internet, Firefox, Edge — none of these match. False-positive rate is essentially zero.

No PHP/server change. Existing 3806-test PHPUnit suite stays green.

---

## [6.3.6] (2026-05-08)

**Bugfix release.** Fixes a silent download failure where the certificate PDF would never appear after the spinner finished — no console error, no alert, just the success message overlaying nothing. Originally reported on iOS Safari; further reports confirmed the same bug on Samsung Internet (Android) and Android WebView (in-app browsers like Facebook / Instagram / WhatsApp / TikTok).

### Fixed

- **Certificate downloads no longer fail silently on iOS Safari, Samsung Internet, and Android WebView.** The previous flow called `window.open( blobUrl, '_blank' )` deep inside the `html2canvas` Promise resolver — by then the user-gesture token from the original click was long gone (1-3 s after html2canvas completed) and these browsers' popup blockers dropped the call without a peep, returning `null`. The "PDF aberto em nova aba" overlay still fired, so the user got a green check with nothing to show for it.
  - **Reproducible 100%** on stock iOS Safari (default popup-blocker), Samsung Internet on Galaxy devices, and any in-app WebView. Chrome on iOS happens to work because its in-app shell is more permissive about late `window.open()` calls. Mac Safari was caught in the same code path unnecessarily — `pdf.save()` works on Safari 14+ on macOS.
  - **Pre-open the destination tab synchronously inside the click handler.** New `pdfWindow = window.open( 'about:blank', '_blank' )` runs at the top of `generateAndDownloadPDF()` when the UA matches the at-risk set (iOS Safari, Samsung Internet, Android WebView), while the user-gesture token is still alive. The tab paints a "Gerando seu certificado…" placeholder. After html2canvas resolves, we swap `pdfWindow.location.href` to the blob URL — those browsers allow that on a window the page already owns.
  - **Mac Safari, Chrome (desktop or Android), Firefox and Edge** continue to use `pdf.save()` as before — they honour `<a download>` correctly. The legacy "any browser whose UA contains `safari` and not `chrome` goes through the popup path" detection is gone.
  - **Manual-tap fallback** when the placeholder tab couldn't be opened (popup blocker dialled up) or was closed by the user before the PDF was ready: the in-page overlay now renders an explicit "Tap to open the PDF" link styled as a button. Tapping is a fresh user gesture, so the browser opens the blob URL without further intervention; from there the user uses the system share icon to save / print.
  - Error paths (html2canvas throw, blank-canvas guard, `toDataURL` SecurityError) now close the placeholder tab so the user isn't left with a stuck "Gerando…" tab.
  - **Browser-aware success message**: iOS gets the existing "PDF opened in a new tab. Tap the share icon…" copy; Samsung Internet / WebView users get a Samsung-friendly "PDF opened in a new tab. Use the menu to save or share." copy.
  - Five new i18n strings: `pdfGeneratingTab`, `pdfGeneratingTabHint`, `pdfManualOpenIOS`, `pdfManualHintIOS`, `pdfOpenedAndroidTab`. JS keeps English fallbacks if the strings aren't localised.

No PHP/server change. No schema change. JS-only patch in `assets/js/ffc-pdf-generator.js`. Existing 3806-test PHPUnit suite stays green.

---

## [6.3.5] (2026-05-08)

**Bugfix release.** Fixes a one-day drift in the dates rendered on the `[ffc_csv_download]` info screen for any site whose WordPress timezone is west of UTC (e.g. `America/Sao_Paulo` / BRT).

### Fixed

- **`[ffc_csv_download]` info screen showed dates one day earlier** than what the form admin configured (e.g. a form set to start on `12/05/2026` rendered `11/05/2026` in the body, even though the footer status message correctly said "começará em 12/05/2026"). Root cause: `PublicCsvDownload::build_datetime_info()` ran `strtotime( $date_start )` on a bare `Y-m-d` string, which PHP parses as **UTC** midnight. `wp_date()` then formatted that timestamp in the site timezone (e.g. BRT, UTC-3), shifting the day backwards by 3 hours and crossing the midnight boundary. Fixed by anchoring each date with `new DateTimeImmutable( $date, $tz )` before formatting — same approach `Geofence::get_form_start_timestamp()` (which fed the correct footer message) was already using. Two regression tests added to `PublicCsvDownloadTest`: `_keeps_configured_date_when_tz_is_brt` and `_handles_blank_dates`. 3806 unit tests pass (was 3804).

---

## [6.3.4] (2026-05-07)

**Patch release — UX polish.** Two consistency fixes for the CPF input rendered inside the public CSV download shortcode (`[ffc_csv_download]`): same elegant LGPD consent-box visual that `[ffc_form]` uses, and the same input mask + on-blur validation. Both are pure reuse of helpers that already shipped — no new code paths server-side.

### Changed

- **`[ffc_csv_download]` CPF field** is now wrapped in a `<div class="ffc-lgpd-consent ffc-pcd-cpf-consent">` container (the same outer treatment used on the certificate form's consent block — bordered, primary accent, soft shadow, rounded corners). The audit disclosure text uses the existing `.ffc-consent-description` styling for the left-border accent, and the field label uses `.ffc-consent-text` typography. CSS reuse only — no new selectors added.

### Fixed

- **`[ffc_csv_download]` CPF field now applies the standard CPF mask** (`XXX.XXX.XXX-XX` formatting while typing + invalid/valid styling on blur). Previously the input was raw text. Fix is a 4-line wiring: the existing `assets/js/ffc-frontend-helpers.js` already exposes `window.FFC.Frontend.Masks.applyCpfRf()`, but it wasn't being enqueued on pages that render `[ffc_csv_download]`. Added it as a script dependency of `ffc-csv-download` and called the helper from the shortcode's init function.

Both changes apply to all three CPF-mode variants the shortcode renders (audit / required / optional), so the experience is consistent regardless of how the form was prefilled or which `_ffc_csv_public_cpf_mode` the target form uses.

---

## [6.3.3] (2026-05-07)

**Patch release — auditable CSV downloads.** The per-form CSV download audit log (introduced in 6.3.0) now stores CPFs **encrypted at-rest** instead of one-way-hashed, and gains a one-click CSV export from the form editor for actual auditability. Reuses the same `Encryption` pipeline that already protects `wp_ffc_submissions`.

### Added

- **CSV export endpoint** for the audit log: `admin-post.php?action=ffc_export_csv_public_download_log&form_id=N&_wpnonce=…`. Streams the `_ffc_csv_public_download_log` post-meta as a UTF-8-BOM CSV (`timestamp_utc, ip, mode, cpf, result`). CPFs are decrypted on the fly via `Encryption::decrypt`. Auth: nonce + `edit_post` on the form + `Utils::current_user_can_admin_or('ffc_manage_settings')`.
- **"Download audit log (CSV)" button** in the form editor's "Public CSV Download" metabox, plus a live count ("N attempts logged on this form."). Hidden when the log is empty.
- **Voluntary logging in `mode = 'none'`**: if a user happens to fill the CPF field on a form that doesn't require it (because the shortcode renders the field for safety when the URL has no prefill), and the digits form a valid CPF, the entry is now logged with `result = 'voluntary'`. Junk inputs are silently dropped — they don't compete for the 100-entry cap.

### Changed

- **Audit log schema** — entries now carry `cpf_encrypted` instead of `cpf_hash`. The hash field was write-only since 6.3.0 (no code path read it), so dropping it has no functional impact and matches the pattern the plugin already uses for `wp_ffc_submissions.cpf_encrypted`. Schema flag bumped to `1.3.0` and stored in the new `ffc_csv_public_download_log_format` option.
- **LGPD disclosure** on the public-download shortcode now states the CPF is "encrypted at rest in the form's audit log" rather than just "logged for audit purposes". Wording also adjusted for the `optional` (no-prefill) variant: "If filled, it will be recorded in the form's audit log even when the form does not require it."

### Removed

- **`cpf_hash` field in audit log entries.** Pre-6.3.3 entries (written by 6.3.0/6.3.1/6.3.2) are wiped on the first `plugins_loaded` after upgrade by the new `PublicCsvDownload::maybe_wipe_legacy_logs()`. Justification: 6.3.0 → 6.3.2 all shipped within the same 24-hour window with effectively zero install base, so a clean wipe avoids carrying `[legacy: hashed only]` placeholders forever in CSV exports. The wipe is idempotent (gated by the `ffc_csv_public_download_log_format` option flag).

### Backwards compatibility

- The validation logic for `whitelist`, `participants`, `owner`, `audit` and `none` modes is **untouched**. Only the audit-log writer changed. The participants-mode hash-lookup against `wp_ffc_submissions.cpf_hash` keeps working exactly as before; that's a separate hash on a different table.
- Sites running without `Encryption::is_configured()` (i.e. without `SECURE_AUTH_KEY`/`LOGGED_IN_KEY`) still log entries — `cpf_encrypted` falls back to `''`, the export shows `[encryption disabled]`, and the metabox renders a hint to configure encryption.
- 3804 unit tests pass (was 3799; +5 new in `PublicCsvDownloadTest`).

---

## [6.3.2] (2026-05-07)

**Patch release — broaden the device fingerprint palette.** Builds on the v6.3.1 thumbmarkjs swap by mapping four additional components into the SQL schema and bumping the fresh-install default `match_threshold` from 5 to 7 to keep the same false-positive ratio against the larger 13-signal palette (was 5/9 ≈ 55%, now 7/13 ≈ 54%). Existing sites keep their persisted threshold; a one-shot dismissable admin notice suggests the bump for sites that still hold the legacy default.

### Added

- **4 new fingerprint signals**, all routed through `ThumbmarkJS.getFingerprintData()` and hashed with SubtleCrypto SHA-256 client-side: `plugins`, `permissions`, `mediaqueries`, `math`. Each gets its own column in `wp_ffc_device_signals` (`sig_plugins`, `sig_permissions`, `sig_mediaqueries`, `sig_math`, all `char(64) DEFAULT NULL`).
- **`DeviceThresholdUpgradeNotice`** (dismissable) — surfaces once on `admin_notices` for sites where `device.enabled = true` AND `match_threshold = 5`, suggesting they raise the threshold to 7 in **Settings → Rate Limit → Device Fingerprint**. Persists dismissal in the `ffc_device_threshold_v632_notice_dismissed` option. AJAX-dismissed via the standard WP `notice-dismiss` button.
- Settings UI: 4 new checkboxes ("Browser plugins list", "Permissions API state", "Media queries", "Math precision probes") under "Signals collected". The threshold input range moves from 3-8 to 3-12 (both global and per-form metabox).

### Changed

- **Schema migration** — `RateLimitActivator::create_tables()` now always calls `dbDelta` on the signals table (it used to skip when the table existed). Idempotent; on existing installs `dbDelta` simply ALTERs in the 4 new columns. DB version bumps from `1.1.0` to `1.2.0`; `maybe_create_tables()` triggers the migration on plugin load.
- **Default `match_threshold`** raised from `5` → `7` for fresh installs only. Existing installs keep their saved value (the option is persisted in `ffc_rate_limit_settings` and won't get overwritten); the admin notice above carries the recommendation.
- **`RateLimiter::check_device_limit()`** signal-keys array, `record_device_signals()` write list, settings sanitization whitelist and `get_device_effective_settings()` clamp all extended to the 13-signal palette + threshold range 3-12.

### Backwards compatibility

- Rows written by 6.3.0 / 6.3.1 stay readable. Their 4 new columns are `NULL`, which the SQL `CASE WHEN (sig_X = :x)` aggregate evaluates to `NULL` (not match) — so they keep contributing whatever matches they already had with the original 9 signals, just no contribution from the new 4. No data loss, no forced re-fingerprinting.
- Existing per-form `_ffc_device_match_threshold` overrides outside the new 3-12 clamp are normalised on next save.
- 3799 unit tests pass (was 3793; +6 from the new threshold default test and 5 admin-notice gating tests; the `RateLimitActivatorTest` upgrade-path test was rewritten in place rather than added).

---

## [6.3.1] (2026-05-07)

**Patch release — swap-only.** Replaces the hand-rolled device-fingerprint collector introduced in 6.3.0 with the maintained [thumbmarkjs](https://github.com/thumbmarkjs/thumbmarkjs) library (MIT, vendored at `libs/js/thumbmark-1.8.1.umd.js`). Server algorithm, schema, settings, JSON contract, threshold default, retention, bypass logic and LGPD posture are **all unchanged**.

Why: thumbmarkjs's `stabilizationExclusionRules` keep canvas/audio/fonts/webgl probes stable across Firefox-RFP, Brave, Tor and Safari-Private automatically — quirks we used to chase by hand. Migration is intentionally minimal so the change set stays auditable.

### Changed

- **Device fingerprint collector** — `assets/js/ffc-device-signals.js` now delegates the raw signal probes to `ThumbmarkJS.getFingerprintData()` and hashes each component with SubtleCrypto SHA-256 locally. The 10 SQL columns (`sig_cookie`, `sig_ua`, `sig_screen`, `sig_tz`, `sig_concurrency`, `sig_memory`, `sig_canvas`, `sig_audio`, `sig_webgl`, `sig_fonts`) and the JSON `ffc_device_signals` payload format are unchanged. The cookie continues to be our own `ffc_device_id` UUID in `localStorage` (thumbmarkjs does not manage cross-session cookies).
- **LGPD disclosure** — the per-form consent block now cites the third-party library by name and explicitly states "no data is sent to any third-party server".

### Added

- `libs/js/thumbmark-1.8.1.umd.js` — vendored UMD build (32 KB raw / ~12 KB gzipped). SHA-256: `b3f07b2701030d55fdbeef51f7dd366d3d9bb7dde415056b6e96ef0414ea0d5b`.
- `FFC_THUMBMARK_VERSION` constant pinning the vendored version.
- `tests/Unit/DeviceSignalsLoggingOffTest.php` — regression guard asserting that the JS bootstrap **always** calls `setOption( 'logging', false )` before the first probe and uses `getFingerprintData()` (not the combined-hash `getFingerprint()`).

### Telemetry note

thumbmarkjs ships with `logging: true` by default, which sends a 0.01%-sampled POST to `api.thumbmarkjs.com` once per session "to improve the library". We **unconditionally disable** that beacon at module bootstrap; the disable call is grep-tested by `DeviceSignalsLoggingOffTest`. No fingerprint signal ever leaves the visitor's browser; only the SHA-256 hex hashes computed locally are sent to the WP site itself.

### Backwards compatibility

- `wp_ffc_device_signals` rows written by 6.3.0 remain readable. The cookie-shortcut match continues to work because the cookie hash is still SHA-256 of the same `ffc_device_id` UUID. The 9 non-cookie hashes will differ between 6.3.0 and 6.3.1 (different probe inputs), so a returning user who clears `localStorage` may get one "free" submission before the new fingerprint starts colliding. This is acceptable for a swap release; the cookie persistence carries the most weight in practice.
- No schema migration; `ffc_rate_limit_db_version` stays at `1.1.0`.

---

## [6.3.0] (2026-05-07)

**Two opt-in anti-fraud features for public form workflows.** Adds a CPF gate on the public CSV download (five modes, audit log) and a per-device submission limit on the certificate form (multi-signal browser fingerprint with an "N of M" matching rule, optional bypass for admins / Certificate Managers).

### Added

- **Per-device submission limit (Feature 2 / #113).** New rate-limit subsystem combining a persistent `ffc_device_id` cookie (UUID v4 in `localStorage`) with up to 9 independently-hashed browser fingerprint signals (UA, screen, timezone, hardware concurrency, device memory, canvas, audio context, WebGL renderer, fonts). The server applies an "N of M" matching rule: two submissions are treated as the same device when their cookie hash matches **OR** when at least the configured threshold of non-cookie signals match. New `wp_ffc_device_signals` table (10 SHA-256 char(64) columns + FK to `ffc_submissions`) stores the hashes; `RateLimitActivator` bumps the rate-limit DB version to `1.1.0` and auto-creates the table on plugin load via `maybe_create_tables()`.
  - **Global settings** (Settings → Rate Limit → Device Fingerprint) — new `device.*` block in `ffc_rate_limit_settings` with 9 keys: `enabled` (master switch), `max_per_form`, `match_threshold` (3-8), `signals_enabled` (10 checkboxes), `bypass_logged_in_managers`, `bypass_whitelist_signals` (cookie hash list), `message`, `retention_days`, `log_blocks`. The daily cleanup cron purges old `ffc_device_signals` rows respecting `retention_days`.
  - **Per-form override** — new "8. Device Fingerprint Limit" metabox on `ffc_form` with optional `_ffc_device_limit_max`, `_ffc_device_match_threshold`, `_ffc_device_limit_message`. Empty fields inherit the global default.
  - **Manager bypass** — when `device.bypass_logged_in_managers` is on, users with `manage_options` **OR** the `ffc_manage_settings` capability skip the device check via `Utils::current_user_can_admin_or()`. Bypassed submissions are tagged in the rate-limit log with `reason='manager_bypass'`.
  - **`RateLimiter::check_device_limit()`** — single `CASE WHEN` SQL that sums per-signal matches and compares against the threshold. Plugged into `RateLimiter::check_all()` after the global whitelist and before the global limit, so it sits inside the same single-point-of-truth pipeline.
  - **`assets/js/ffc-device-signals.js`** — vanilla JS module that collects only the enabled signals (passed via `wp_localize_script`), SHA-256 hashes each one with SubtleCrypto, and writes a JSON `ffc_device_signals` hidden input on every `form.ffc-submission-form`. Best-effort: missing APIs / RFP-randomised values are silently skipped. Frontend enqueue is gated on `device.enabled`.
  - **LGPD disclosure** — the form's existing consent block automatically gains a one-line note about the device fingerprint when the limit is active for that form.

- **CPF gate on public CSV download (Feature 1 / #112).** New per-form `_ffc_csv_public_cpf_mode` meta with five modes:
  - `none` — legacy behaviour, no CPF asked.
  - `audit` — ask, format-validate, log, never block.
  - `participants` — CPF must hash-match an existing submission of the form (uses the same `Encryption::hash()` rule as `RateLimiter::get_submission_count`).
  - `owner` — CPF must match the form author's `ffc_user_cpf` user meta.
  - `whitelist` — CPF must appear in `_ffc_csv_public_cpf_whitelist` (textarea, normalised to unique 11-digit strings).
  - **Audit log** — `_ffc_csv_public_download_log` post meta retains the latest 100 attempts as `{ts, ip, mode, sha256(cpf_digits), result}` where result is one of `success | fail_missing | fail_format | fail_match | fail_unknown_mode | audit_pass`.
  - Validation runs at all three entry points: synchronous fallback (`PublicCsvDownload::handle_request`), info AJAX (`PublicCsvDownload::ajax_info`), and the export start AJAX (`PublicCsvExporter::ajax_start`).

### Changed

- **76 legacy `Utils::debug_log()` calls migrated to the per-area `Debug::log_*()` system.** Previously every call fired whenever `WP_DEBUG=true` with no admin toggle — including frontend shortcode renders that polluted production logs. Each call now lands in one of 14 area-specific helpers gated by a checkbox in **Settings → Advanced → Debug**. Five new areas added (`debug_frontend`, `debug_admin`, `debug_self_scheduling`, `debug_audience`, `debug_qrcode`), each defaulting OFF. `Utils::debug_log()` is now a `@deprecated` thin wrapper that delegates to `Debug::AREA_FORM_PROCESSOR` for any third-party callers; new code should use `Debug::log_*()` directly.
- **FFC role labels now translate correctly on `wp-admin/users.php`.** WordPress stores role labels verbatim in `wp_user_roles` at `add_role()` time, and its built-in `translate_user_role()` resolves them against the **default** WP textdomain — so plugin-provided role names never localized even when the .po file was loaded. New `wp_roles_init` hook (`CapabilityManager::relabel_ffc_roles`) re-applies `__( …, 'ffcertificate' )` to every FFC role's `name` + `role_names` entry on every page load, so `users.php` always shows the operator's locale.

### Test coverage

- `RateLimiterTest`: 11 new tests covering `should_bypass_for_manager`, `get_device_effective_settings` (inherit, override, clamp), `check_device_limit` (disabled-noop, whitelist, count-blocks), `record_device_signals` (no-op when disabled, filters disabled signals to NULL).
- `PublicCsvDownloadTest`: 7 new tests covering each CPF mode + audit-log capping at `DOWNLOAD_LOG_MAX`.
- `RateLimitActivatorTest`: existing 8 tests updated for the third table + version bump.
- 3790 unit tests pass total (was 3772).

---

## [6.2.0] (2026-05-04)

**Capabilities + roles overhaul + admin UX scoping + upgrade safety.** 14 granular admin capabilities replace blanket `manage_options` gates so site owners can delegate scoped roles without giving full WP admin. 9 new roles wrap the new caps into pre-built bundles (Certificate Manager, Self-Scheduling Manager, Audience Manager, Reregistration Manager, FFC Operator, plus a 4-tier recruitment ladder: Auditor → Operator → Manager → Admin). A defense-in-depth admin-UX layer hides core WP menus, blocks direct-URL access, and prunes the top admin bar per role. The 3 legacy non-namespaced certificate caps (`view_own_certificates` etc.) are renamed to the consistent `ffc_*` namespace with a one-time idempotent migration. A pre-existing in-place-upgrade bug that left recruitment tables un-created when bypassing reactivation is fixed.

### Added

- **14 new granular capabilities** (`ADMIN_CAPABILITIES` constant, registered on activation + auto-granted to administrators on version bump):
  - Cross-module: `ffc_manage_certificates`, `ffc_export_certificates`, `ffc_manage_self_scheduling`, `ffc_manage_audiences`, `ffc_view_activity_log`, `ffc_manage_user_custom_fields`, `ffc_view_as_user`, `ffc_manage_settings`.
  - Per-domain recruitment: `ffc_view_recruitment`, `ffc_import_recruitment_csv`, `ffc_call_recruitment_candidates`, `ffc_view_recruitment_pii`, `ffc_manage_recruitment_settings`, `ffc_manage_recruitment_reasons`. The umbrella `ffc_manage_recruitment` cap stays as the catch-all backwards-compat for routes that don't match a granular cap.
- **9 new roles** registered idempotently on activation and on `plugins_loaded` so in-place plugin updates self-heal:
  - Cross-module: `ffc_certificate_manager`, `ffc_self_scheduling_manager`, `ffc_audience_manager`, `ffc_reregistration_manager`, `ffc_operator` (read-only generalist).
  - Recruitment tier (each tier inherits from the one above): `ffc_recruitment_auditor` (read-only), `ffc_recruitment_operator` (auditor + can call candidates), `ffc_recruitment_manager` (existing role expanded with the new granular caps), `ffc_recruitment_admin` (full surface incl. settings + reasons).
- **`AdminMenuVisibility`** — defense-in-depth UX layer hiding core WP menus (Posts/Comments/Pages/Tools/Plugins/Themes/Users), blocking direct-URL access (redirect to the role's landing page), and pruning top admin-bar nodes (`new-content`, `comments`) per FFC role. `manage_options` users are exempt; multi-role users inherit the most permissive policy. NOT a security boundary — caps remain the source of truth; this is UX scoping.
- **`Utils::current_user_can_admin_or( $cap )`** helper — passes if the user has `manage_options` OR the granular cap. Used at every gate point swapped from blanket `manage_options` to a granular cap, keeping every site admin's access intact.

### Changed

- **`ffc_certificate_update` reactivated** — was a never-wired placeholder in `FUTURE_CAPABILITIES` since 4.9.3. Promoted to `ADMIN_CAPABILITIES` and now gates `class-ffc-admin-submission-edit-page.php` so non-admin operators can fix typos in issued certificates. The user-edit metabox label was also rewritten from "Future feature" placeholders to a real description.
- **3 legacy certificate caps renamed** to the consistent `ffc_*` namespace + one-time migration (`ffc_legacy_caps_renamed_v1` flag):
  - `view_own_certificates`        → `ffc_view_own_certificates`
  - `download_own_certificates`    → `ffc_download_own_certificates`
  - `view_certificate_history`     → `ffc_view_certificate_history`
  Migration walks every WP user, transfers user-meta grants from the legacy name to the new name, and rewrites the `ffc_user` role definition. Idempotent + version-flagged so re-running is a no-op.
- **20+ admin entry points re-gated** from `manage_options` to the corresponding granular cap (or `manage_options` OR `ffc_*` via `Utils::current_user_can_admin_or()`), including: Activity Log page, CSV exporter, Self-Scheduling admin/CPT/cleanup/CSV, Audience admin (5 pages + 8 menu items), Settings page (4 inline guards + save handler), Dashboard view-as-user, Submission edit page, Reregistration custom-fields page. The recruitment REST controller adds 4 dedicated permission callbacks for the highest-blast-radius routes (CSV import, promote-preview, single + bulk call, reasons CRUD); other recruitment routes stay on the umbrella `ffc_manage_recruitment` cap by design.

### Removed

- **`ffc_reregistration` placeholder cap** — never wired since its 4.9.3 introduction. Audience-targeting on reregistration objects already filters who can submit each form, so the per-user cap was redundant. Removed from `ADMIN_CAPABILITIES`; the `FUTURE_CAPABILITIES` constant is now empty (kept as `array()` so external code referencing it doesn't fatal). `uninstall.php` continues to strip the cap from any user that had it granted.

### Fixed

- **Recruitment tables + manager role no longer require deactivate/reactivate** to land on in-place plugin updates. `register_activation_hook` is the only entry-point firing `RecruitmentActivator::create_tables()` and `CapabilityManager::register_recruitment_manager_role()` in 6.0.0–6.1.0; the WordPress "Update plugin" button DOES NOT fire that hook. Effect on the affected cohort: `maybe_migrate()` ran on `plugins_loaded` against tables that didn't exist, silently failing. Fix: hook `create_tables` at `plugins_loaded` priority 9 + role registration at priority 10 (before `maybe_migrate` at priority 11). All three calls are idempotent — each table-create + role-register short-circuits when the artifact already exists.
- **`_load_textdomain_just_in_time was called incorrectly` notice** (WP 6.7+) emitted by the v6.2.0 role-registration hooks. WP 6.7+ rejects `__()` calls before `init`; the role labels were resolved via `__()` on `plugins_loaded` callbacks. Both `Loader::register_ffc_roles_safe()` and the recruitment-loader's role registration now hook on `init` priority 1 instead of `plugins_loaded`. Non-translated work (`create_tables`, `maybe_migrate`) stays on `plugins_loaded`.
- **FFC role labels not translatable on `wp-admin/users.php`.** WordPress stores the role label verbatim in the `wp_user_roles` option at `add_role()` time, then displays it via `translate_user_role()` against the **default** WP textdomain — so plugin-provided role labels never translate, even after the `.po` is loaded. New `CapabilityManager::relabel_ffc_roles()` hooks `wp_roles_init` and re-applies `__()` against the plugin's textdomain on every page load. Affects the 11 FFC roles (`ffc_user`, `ffc_recruitment_manager`, plus the 9 added in this release).
- **Per-area debug toggles for the legacy `Utils::debug_log()` callsites.** 76 calls across 29 files used to fire whenever `WP_DEBUG=true` with no per-area toggle (e.g. `[FFC] Form shortcode rendered` would spam the log on every shortcode render). Migrated to the existing `Debug::log_*()` system with 5 new areas (`AREA_FRONTEND`, `AREA_ADMIN`, `AREA_SELF_SCHEDULING`, `AREA_AUDIENCE`, `AREA_QRCODE`) so each domain has its own toggle in Settings → Advanced → Debug. `Utils::debug_log()` is now `@deprecated` and delegates to `Debug::AREA_FORM_PROCESSOR` for backwards compat. Existing 9 area toggles are unchanged.

---

## [6.1.0] (2026-05-02)

**Recruitment admin UX parity + dashboard integration + Preliminary list visual axis.** Closes #90. Recruitment admin moves from inline `<table>` placeholders to full `WP_List_Table` listings with pagination, search, sort, bulk actions + dedicated edit screens. Candidate-self section becomes an automatic tab inside `[user_dashboard_personal]`. Preliminary list gains a configurable visual axis (Empty / Denied / Granted / Appeal denied / Appeal granted) backed by a global Reasons catalog without touching the §5.2 state machine.

### Added

- **List-table admin (Notices / Adjutancies / Candidates / Reasons)** — sortable, paginated, searchable, with bulk-delete + nonce-protected row actions.
- **Dedicated edit screens** for notices (5 sections incl. transitions, attach/detach, CSV import, classifications) + candidates (general, sensitive data, classification + call history, hard-delete) + reasons.
- **Notice ↔ adjutancy attach UI + REST** (`PUT/DELETE /notices/{id}/adjutancies/{adjutancy_id}`) — fixes a `recruitment_notice_has_no_adjutancies` 400 that blocked CSV imports on fresh installs.
- **Semicolon CSV delimiter auto-detection** for BR/EU spreadsheet exports (sniffs `,` vs `;` outside quoted segments).
- **Per-adjutancy + per-status badge colors** with operator-configurable palettes via `<input type="color">`. Schema migration v4 adds `color` column to `ffc_recruitment_adjutancy`.
- **Public shortcode polish** — adjutancy filter (functional now; was silently ignored), name search (`?q=`), subscription-type filter (PCD/GERAL), persistent filter UI, BR-format dates (DD-MM-YYYY) + 2-decimal scores.
- **Out-of-order call confirm + reason prompt** (single + bulk).
- **12h public-shortcode cache TTL** with admin-write invalidation via versioned cache key.
- **Dashboard view-as fix** — `RecruitmentDashboardSection::render_for_user( int $user_id )` threads the impersonated user id through; standalone shortcode also honors `DashboardViewMode::get_view_as_user_id()`.
- **Preliminary visual statuses + Reasons catalog.** Schema migration v5 adds `preview_status` ENUM + `preview_reason_id` to `ffc_recruitment_classification` and creates `ffc_recruitment_reason` (slug, label, color, applies_to CSV). New REST surface `/recruitment/reasons`. Per-row inline `preview_status` + `reason` dropdowns on the Preliminary tab; reason dropdown auto-filters by `applies_to`. Per-notice `public_columns_config.preview_reason` toggle controls public visibility.
- **Optional CSV columns `time_points` (DECIMAL) + `hab_emebs` (TINYINT)** — schema migration v6 adds them to `ffc_recruitment_classification`; both default 0 so existing CSVs unaffected.
- **CSV import activity indicator + example-CSV download** on the notice edit page.
- **Notice-status configurable badge colors** (4 sub-keys for draft / preliminary / definitive / closed).
- **Empty-state guidance card** on the Notices tab for fresh installs; **bulk-call date/time pre-fill** via localStorage.
- **`preliminary → definitive` confirm prompt** + **client-side preflight** of the reason-required flag.
- **`Core\BadgeHtml` precursor** — single shared badge HTML helper consolidating 7 near-identical inline-styled renderers.

### Changed

- **Notice status `active` → `definitive`** (intermediate `final` rename retired). Schema migrations v2 + v3 cover both upgrade cohorts idempotently. Error code `recruitment_definitive_to_preliminary_blocked_by_calls` finalized — REST consumers should update.
- **CSV import flow relocated** from the Candidates tab to the Notice edit screen (operator is already editing the target notice — no need for the picker).
- **Public shortcode status branching** rebalanced — `draft` / `preliminary` / `definitive` / `closed` each get appropriate banners + listing visibility.
- **`UserCreator::generate_username`** prefers the email prefix over name-based slugs (aligns recruitment promotion with the legacy form-submission path).
- **`public_columns_config` editor** swapped from raw-JSON textarea to on/off checkbox grid; mandatory columns (rank, name) cannot be turned off.
- **FFC top-level menu positions** floated to 26.1 / 26.2 / 26.3 to keep the FFC block contiguous against third-party plugins claiming integer 26/27/28.
- **Public shortcode "Called" section** sort: newest-first.
- **i18n sweep** — stray pt-BR strings cleared from docstrings; "Not yet called" → "Waiting called".

### Performance

- **Public shortcode candidate-fetch**: N round-trips → 1 (`RecruitmentCandidateRepository::get_by_ids()` warms the object cache with a single `WHERE id IN (...)`). 30–50% latency improvement on cold-cache renders.

### Fixed

- **OOO call detection** scoped to the Definitive panel only (was scanning Preliminary rows whose `status='empty'` invariant produced false positives).
- **Bulk-call OOO false positives** — per-adjutancy threshold now ignores rows in the same selection.
- **Admin alerts** surface `body.message` instead of the raw JSON envelope on REST errors.

### Note

- **No "Trash" / soft-delete** on Notices, Adjutancies, Candidates — per §7-bis of the recruitment plan, hard-delete-with-referential-gates is the explicit choice. The "preserve history" path for notices is `status=closed`.

---

## [6.0.4] (2026-05-01)

**Recruitment admin UI — Candidates tab CSV import + editable Settings tab.** The two §15 surfaces that shipped as MVP placeholders in 6.0.0 / 6.0.2 are now functional: the **Candidates** tab gets a CSV upload form (notice picker + file input) wired to `POST /notices/{id}/import` with an inline status panel; the **Settings** tab moves from a read-only key/value dump to a full edit form backed by the WP Settings API (`options.php` + `register_setting`'s sanitize callback), exposing all 7 sub-keys (email subject / from address / from name / body HTML, public cache TTL, public rate limit, public default page size).

### Added

- **CSV import form on the Candidates tab.** `RecruitmentAdminPage::render_csv_import_form()` lists every notice in `draft` or `preliminary` (the only states that accept preview-list import per §5.1) in a `<select>`, prompts for a CSV file (UTF-8, EN headers, `accept=".csv,text/csv"`), and POSTs the multipart payload to `/wp-json/ffcertificate/v1/recruitment/notices/{id}/import` with `X-WP-Nonce` cookie auth. An inline status span surfaces the JSON response (count + errors on success, error code on rejection). Active / closed notices are filtered out of the dropdown so the operator can't pick a notice the endpoint will reject. If no notice is in `draft`/`preliminary`, the form renders a "create a notice first" hint pointing at the Notices tab.
- **Editable Settings tab.** `RecruitmentAdminPage::render_settings_tab()` now renders an HTML form posting to `options.php` with `settings_fields(RecruitmentSettings::OPTION_GROUP)` so the Settings API runs `RecruitmentSettings::sanitize()` on save. Two sections: **Email template** (subject, from address, from name, HTML body in a code-styled textarea) and **Public shortcode** (cache TTL seconds, per-IP rate limit per minute, default page size). Inline placeholder reference for the email template lists every supported `{{token}}`. Replaces the previous read-only `<table class="widefat">` dump that exposed values without an edit path.

---

## [6.0.3] (2026-05-01)

**Three-fix release.** (1) i18n cleanup of the recruitment module — closes #85. (2) Capability-resolution bug — multi-role users (admin + ffc_user) had FFC caps blocked by ffc_user's explicit `=> false` entries via WP's `array_merge` cap resolution; closes #86. (3) `AudienceBookingRepository::count()` silently ignored `start_date`/`end_date` filters, causing the dashboard's "upcoming bookings" stat to count past + future + cancelled bookings indiscriminately.

### Fixed

- **Capability resolution for multi-role users (#86).** The `ffc_user` role was registered with every FFC cap as `=> false`. WP's `WP_User::get_role_caps()` merges role capability maps via `array_merge()`, and an explicit `false` in one role overwrites a `true` from another — so an `administrator + ffc_user` user (e.g. an admin who is also an end-user with certificates) had every FFC cap silently denied at the role layer regardless of what `administrator` granted. Live repro: `current_user_can('ffc_manage_recruitment')` returned `false` and the new "Recruitment" sidebar menu didn't render. Fix in three layers: `CapabilityManager::register_role()` now creates `ffc_user` with `read => true` only (FFC caps absent — same effect as `false` for single-role users via `empty()` semantics, but doesn't poison the merge for multi-role users); `CapabilityManager::upgrade_role()` strips legacy `=> false` entries from the existing role on first load after upgrade (preserves `=> true` entries, idempotent); `Loader::ensure_admin_capabilities()` also walks the `ffc_user` role on every FFC_VERSION bump and strips lingering `=> false` entries (defense in depth). Per-user grants via `add_cap($user_id, $cap, true)` continue to work unchanged — user-meta `true` always wins over absent role caps.
- **`AudienceBookingRepository::count()` honors `start_date` / `end_date` filters.** Pre-6.0.3 the method only handled `environment_id`, `status`, `booking_date`, `created_by` and silently dropped any other filter key. The `AudienceAdminDashboard::get_audience_stats()` "upcoming bookings" stat passed `start_date => current_time('Y-m-d')` expecting "bookings on or after today" — but the filter was discarded and the count returned every active booking ever made. Added `WHERE booking_date >= %s` and `WHERE booking_date <= %s` clauses mirroring `get_all()` (lines 110-118). Two new unit tests pin the SQL shape.

### Changed (i18n — closes #85)

- **Public shortcode (`class-ffc-recruitment-public-shortcode.php`)** — 21 source-key conversions: error messages (`Notice not found.`, `Notice not yet published.`, `Adjutancy not found for this notice.`, `No candidates classified yet.`, `Notice closed.`, `Too many requests. Please try again in a few seconds.`), section headers (`Not yet called`, `Called`), table column headers (`Rank`, `Name`, `Score`, `Status`, `Date to assume`), filter UI (`Filter by adjutancy:`, `All`), status pills (`Waiting`, `Called`, `Did not show up`, `Hired`).
- **Candidate-self dashboard (`class-ffc-recruitment-dashboard-section.php`)** — 22 source-key conversions: section heading (`My Calls`), classification banners (`Preliminary classification — subject to review`, `Final classification`), block headings (`Your classification(s) for this notice`, `Call history`), empty-state copy (`You have not been called for this notice yet.`, `No classification visible at the moment.`), table columns (`Adjutancy`, `Rank`, `Score`, `Status`, `Called at`, `Date to assume`, `Time`, `Notes`), situação derivations (`Waiting`, `Called`, `Did not show up`, `Hired`, `Cancelled`, `Call reverted`).
- **Admin page (`class-ffc-recruitment-admin-page.php`)** — 16 source-key conversions: cap-deny copy (`Access denied.`), notices table (`Code`, `Name`, `Status`, `Reopened?`, `Created at`, `Yes`), empty states (`No notices registered yet.`, `No adjutancies registered yet.`), candidates-tab placeholder copy, settings-tab read-only banner, form labels (`Create new notice`, `Create`, `Create new adjutancy`), REST help summary (`Available REST endpoints`, `All admin endpoints require the ffc_manage_recruitment capability.`).
- **Settings (`class-ffc-recruitment-settings.php`)** — default email subject (`Convocação - {{notice_code}} - {{adjutancy}}` → `Call - {{notice_code}} - {{adjutancy}}`) and the entire default body HTML template normalized to EN-source. Placeholder tokens (`{{name}}`, `{{notice_code}}`, etc.) are unchanged.
- **`languages/ffcertificate-pt_BR.po`** — appended one PT translation entry per new EN msgid (45 entries total across the four files). The legacy PT-source msgids that the source code no longer references are left in place (orphaned but harmless); a `wp i18n make-pot` regeneration in a future maintenance pass will prune them automatically.
- **Tests** — `RecruitmentDashboardSectionTest` and `RecruitmentPublicShortcodeTest` flipped from asserting on PT-rendered strings (`Classificação preliminar`, `Edital não informado.`, etc.) to asserting on the new EN sources, since Brain\Monkey's `__` stub returns the msgid verbatim. 214 recruitment tests / 629 assertions still green.

---

## [6.0.2] (2026-05-01)

**UX — wp-admin sidebar.** The recruitment module gets its own top-level menu item (icon `dashicons-groups`, position 28) instead of being tucked under the `ffc_form` CPT, mirroring the Audience (position 26) and Reregistration (position 27) modules so the three sibling business modules sit together in the sidebar. The `ffc_form` CPT's `menu_name` is also shortened from "Free Form Certificate" (full plugin name) to just "Certificate" (the actual content the menu manages). All sidebar-visible labels in the recruitment module — menu name, page heading, four tab labels — are also normalized from Portuguese-source to English-source `__()` keys per the §2 i18n convention (Portuguese flows in via `.po`, never as the source string).

### Changed

- **`RecruitmentAdminPage::register_menu()` switched from `add_submenu_page('edit.php?post_type=ffc_form', …)` to `add_menu_page(…, 'dashicons-groups', 28)`.** The four-tab layout (Notices, Adjutancies, Candidates, Settings) is unchanged; only the parent and the sidebar entry move. Tab URLs in `render_tabs()` swap `admin_url('edit.php')` + `post_type=ffc_form` for `admin_url('admin.php')` since the page is no longer a CPT child.
- **`CPT::register_form_cpt()` `menu_name` label changed from "Free Form Certificate" to "Certificate".** The verbose plugin-name label was a holdover from the pre-modularization era; the menu now describes its content (certificates) rather than its owner (the plugin).
- **i18n normalization in `RecruitmentAdminPage`.** The five sidebar-visible labels (menu name, page heading, four tab names + their h2 headers) now use English-source `__()` keys: `Recrutamento` → `Recruitment`, `Editais` → `Notices`, `Matérias` → `Adjutancies`, `Candidatos` → `Candidates`, `Configurações` → `Settings`. Portuguese rendering continues to flow through `languages/ffcertificate-pt_BR.po`. Note: the deeper recruitment surface (form labels, status messages, button text in the public shortcode and candidate-self dashboard) still has ~40 Portuguese-source `__()` keys that need a follow-up sweep — they were not touched in this PR to keep the diff focused on the sidebar UX the user can actually see.

---

## [6.0.1] (2026-05-01)

**Hotfix — recruitment activator: `dbDelta()` rejects column-level `COMMENT '…'` clauses.** The 6.0.0 `CREATE TABLE` statements for `ffc_recruitment_notice`, `ffc_recruitment_candidate`, `ffc_recruitment_classification`, and `ffc_recruitment_call` carried inline `COMMENT 'documentation text'` clauses on several columns. WordPress's `dbDelta()` parser does not understand the `COMMENT '…'` syntax — the apostrophes break its column-definition regex, the malformed SQL is forwarded to the database, and MariaDB rejects it with "syntax error near 'Site TZ'" / "near 'HMAC(salt, (1|0)||id)'" / "near 'Ties allowed'". The four tables were never actually created on activation; only `ffc_recruitment_adjutancy` and `ffc_recruitment_notice_adjutancy` (which had no COMMENT clauses) made it through, leaving the schema half-populated and any recruitment admin / shortcode access broken with `WP_Error` from missing tables.

### Fixed

- **`RecruitmentActivator` — strip every column-level `COMMENT '…'` clause from the four affected `CREATE TABLE` statements** (`includes/recruitment/class-ffc-recruitment-activator.php`). Column semantics live in the entity classes' PHPDoc per the original plan; they don't belong in `dbDelta`-bound SQL. Existing 6.0.0 installs that hit this bug recover by reactivating the plugin: `RecruitmentActivator::create_tables()` skips already-created tables via `table_exists()` and recreates only the four that failed.

---

## [6.0.0] (2026-05-01)

**Recruitment module** for Brazilian public-tender ("concurso público")
candidate queue management, plus PHPStan baseline retirement
(level 7 → 8, zero errors, no baseline).

### Added

- **Recruitment module (PRs #80, #81)**: six new InnoDB tables under
  `ffc_recruitment_*`, six repositories with `@phpstan-type` row
  aliases, atomic CSV importer (single-transaction wipe+reinsert,
  rollback on any validation error), two state machines
  (Notice `draft|preliminary|active|closed`, Classification
  `empty|called|accepted|hired|not_shown` with §5.1 reopen-freeze
  cross-aggregate rule), call/promotion/delete services, PCD HMAC
  hasher, email dispatcher with masked placeholders, 21-route admin
  REST surface under `ffcertificate/v1/recruitment`, `[ffc_recruitment_queue]`
  public shortcode + `[ffc_recruitment_my_calls]` candidate dashboard
  section, wp-admin Recrutamento submenu, `ffc_manage_recruitment`
  capability + `ffc_recruitment_manager` role, single serialized
  `ffc_recruitment_settings` option, `uninstall.php` cleanup. 21 test
  classes, ~229 tests, ~668 assertions.
- **"Move to form…" bulk action** on the Submissions admin list (PR #78).
  Available when the list is filtered by exactly one form; opens a
  modal picker, runs identifier-based conflict detection
  (`cpf_hash`/`rf_hash`/`email_hash`/`user_id`), keeps duplicates in
  the source, reports the conflicting IDs back. New
  `SubmissionRepository::moveBetweenForms()` +
  `SubmissionHandler::move_submissions_between_forms()` + dedicated
  modal JS/CSS bundle. Single `submission_moved` audit entry per call.
- **PHPStan repository row aliases** across audience, reregistration,
  submission, appointment, and blocked-date repositories (PR #76).
- **`CONTRIBUTING.md` "Static analysis conventions" section** (PR #77)
  documenting the deliberate `@phpstan-ignore` patterns on
  `$wpdb->prepare()` literal-string requirements and the
  `@phpstan-type`/`@phpstan-import-type` workflow.

### Changed

- **PHPStan level 7 → 8**, baseline of 231 suppressed errors retired,
  `treatPhpDocTypesAsCertain: false` removed. Reached by typing every
  repository row shape, removing 20 `@deprecated Utils::*` shims (150+
  call sites migrated to `DocumentFormatter`/`AuthCodeService`/
  `SecurityService`/`DataSanitizer`), and hardening nullability across
  ~40 files. (PR #76)
- **`phpstan-stubs.php` parses constants from `ffcertificate.php`** —
  the hand-maintained `FFC_VERSION` had drifted three minors behind. (PR #76)
- **`AudienceEnvironmentRepository::get_working_hours()`** typed
  `array<string, …>` (weekday-slug-keyed) to match the actual JSON
  column shape. (PR #76)
- **`ActivityLog` vocabulary** gains `submission_moved`. (PR #78)

### Fixed

- **Null narrowing across 5 PHPStan level-8 hotspots** (PR #76):
  `UserManager::get_user_emails()` post-decryption type,
  `wp_date()`/`gmdate()` consumers of `strtotime()` results in the
  reregistration + verification + ficha screens, `get_post()` null in
  the frontend form processor, `preg_replace()` return in
  `DocumentFormatter`, `MigrationRegistry::get_migration()` null in
  `MigrationStatusCalculator`.

### Removed

- `phpstan-baseline.neon` (231 entries), 20 `@deprecated Utils::*`
  shim methods + the `PHONE_REGEX` constant,
  `Admin\CsvExporter::handle_export_request()` redirect stub + its
  two hook registrations, two "remove in next major" legacy
  fallbacks (`ReprintDetector` JSON-LIKE scan and
  `VerificationHandler::build_appointment_result()` `cpf_rf` legacy
  column), and three dead `onlyWritten` properties (`Admin::$form_editor`,
  `$settings_page`, `Frontend::$dynamic_fragments`). (PRs #76, #77)

### Security

- Move-action bulk endpoint gated by `manage_options` +
  `bulk-submissions` nonce — no new capability surface. (PR #78)

---

## 5.4.1 (2026-04-24)

Certificate HTML editor gains CodeMirror syntax highlighting with distinct coloring for HTML tags and `{{placeholder}}` tokens, plus a three-option `Code Editor Theme` setting (Auto / Light / Dark, dark by default on fresh installs) with a VS-Code-Dark+-inspired palette; the email body moves to a lightweight visual editor (`wp_editor()` teeny); the global TinyMCE placeholder-protection filter is scoped to the plugin's post type so it no longer touches unrelated admin screens; a new per-calendar admin-bypass toggle replaces the hardcoded all-or-nothing bypass for self-scheduling; and the `[ffc_verification]` result card header stops rendering with the admin preview modal's dark slate background.

### Added

- **CodeMirror integration for the certificate HTML editor.** `ffc_pdf_layout` now renders through WordPress's built-in CodeMirror via `wp_enqueue_code_editor()` — tags, attributes and strings get syntax highlighting, line numbers and auto-closing bracket helpers, configured with lint disabled so valid certificate templates don't get flagged. A new JS module (`assets/js/ffc-admin-code-editor.js`) initializes the editor on top of the existing `<textarea>`, adds a regex overlay (`.cm-ffc-placeholder-token`) that paints `{{placeholder}}` tokens in a separate color from HTML markup, syncs the underlying textarea on every change, and saves before submit. The DOM textarea is preserved, so form submission, save pipeline (`FormEditorSaveHandler::save_form_data`), stored HTML and downstream PDF generation are **byte-for-byte identical** to the previous plain-textarea path.
- **Syntax Highlighting profile notice.** When the user has disabled "Syntax Highlighting" in their WordPress profile, `wp_enqueue_code_editor()` returns `false`; the initializer then renders a subtle `<p class="description">` under the textarea linking to `profile.php#syntax_highlighting` with the string _"For the best HTML template experience, enable 'Syntax Highlighting' in your profile."_ The textarea continues to work unchanged.
- **Email body upgraded to `wp_editor()` teeny mode.** The `email_body` field (metabox 4 — Email Configuration) moves from a plain `<textarea>` to a minimal visual editor: bold, italic, underline, bullet/numbered lists, link/unlink, undo/redo. `media_buttons => false`, `teeny => true`, custom quicktags. Placeholders such as `{{auth_code}}` and `{{name}}` remain protected thanks to the `tiny_mce_before_init` filter (see _Changed_).
- **Per-calendar admin-bypass toggle for self-scheduling.** A new `Admin Bypass` checkbox in the Booking Rules metabox of each `ffc_self_scheduling` calendar lets the author decide whether users with `manage_options` / `ffc_scheduling_bypass` skip that calendar's booking restrictions (advance-booking window, past-date guard, blocked dates, working hours, daily/interval limits, and cancellation deadline/allowance). Slot capacity is always enforced. The toggle is stored in `_ffc_self_scheduling_config['admin_bypass']`. Backward compatibility: calendars saved before 5.4.1 have no stored key and continue to behave as before (bypass on).
- **Code Editor Theme setting (Advanced tab).** New select field `ffc_settings['code_editor_theme']` with values `auto` / `light` / `dark`. `dark` is the default on fresh installs (registered in `Settings::get_default_settings()`); `auto` mirrors the plugin's admin `dark_mode` option (General tab). Lives in a new "Editor Preferences" card in the Advanced settings tab, right after the Activity Log card and before Debug Settings.
- **VS-Code-Dark+-inspired theme for the CodeMirror editor.** New stylesheet `assets/css/ffc-code-editor-dark.css` (+ minified build) registers the `.cm-s-ffc-dark` CodeMirror theme — background `#1e1e1e`, foreground `#d4d4d4`, tags `#569cd6`, attributes `#9cdcfe`, strings `#ce9178`, comments `#6a9955`, selection `#264f78`, matching-bracket `#0e639c`. Placeholder overlay (`.cm-ffc-placeholder-token`) flips to gold-on-dark (`#dcdcaa` on `#1e1e1e`) so `{{foo}}` tokens remain legible on the dark canvas. Theme CSS is only enqueued when the resolved theme is `dark`; light stays on WordPress's default CodeMirror styling with zero extra payload.
- Unit tests: `AdminClassTest` grows from 9 → 12 tests (+3) covering `maybe_register_tinymce_placeholder_filter()` across three screen states — `ffc_form`, other post type, and null screen. `CalendarRepositoryTest` gains 4 new tests covering the per-calendar `admin_bypass` consumption path. `AdminAssetsManagerTest` gains 8 new tests covering `resolve_code_editor_theme()` across all branches — default-on-fresh-install, explicit light/dark, `auto` following `dark_mode on/off/auto`, invalid stored values, and corrupt (non-array) `ffc_settings` option.

### Changed

- **`tiny_mce_before_init` filter scoped to the `ffc_form` screen.** `Admin::configure_tinymce_placeholders()` is no longer attached from the constructor on every admin page load. A new `Admin::maybe_register_tinymce_placeholder_filter()` runs on `admin_head`, checks `get_current_screen()->post_type === 'ffc_form'`, and only then registers the filter at priority 999. Other admin screens (Classic Editor posts, third-party plugin configuration pages, etc.) are no longer mutated by the plugin's TinyMCE init overrides. `configure_tinymce_placeholders()` itself is unchanged — same `noneditable_regexp`, `noneditable_class`, `entity_encoding = raw`, and `protect` array.
- **`email_body` sanitization hardened.** `FormEditorSaveHandler::save_form_data()` now runs `email_body` through `wp_kses_post()` — the canonical WordPress post-content allowlist — instead of the generic plugin `Utils::get_allowed_html_tags()` allowlist. This aligns the sanitizer with the field's new authoring surface (`wp_editor()` teeny) and matches what WordPress itself allows in post bodies. `pdf_layout` keeps its broader allowlist since it is a certificate template authored by the admin.
- **CSS placeholder block rewritten for its new actual surface.** `assets/css/ffc-admin.css` now carries a single `.ffc-placeholder` rule (used inside TinyMCE when the teeny editor runs for `email_body`) plus new CodeMirror-aware selectors: `.ffc-code-editor-wrapper .CodeMirror` (bordered wrapper, 260px min-height, monospace font), `.ffc-code-editor-wrapper .cm-ffc-placeholder-token` (colored placeholder tokens inside the code editor), and `.ffc-code-editor-notice` (styling for the profile-option notice).
- **Assets manager gains a code-editor enqueue path.** `AdminAssetsManager::enqueue_form_editor_code_editor()` only fires on the `ffc_form` post edit screen; it calls `wp_enqueue_code_editor()` with HTML mode and forwards the result (plus i18n strings and the profile URL) to the JS initializer via `wp_localize_script( 'ffc-admin-code-editor', 'ffcCodeEditor', … )`. It now also resolves the effective code-editor theme via the new `AdminAssetsManager::resolve_code_editor_theme()` static helper, injects `theme: 'ffc-dark'` into the CodeMirror config and enqueues `ffc-code-editor-dark.css` only when the resolved theme is dark. The JS initializer tags the wrapper with `ffc-code-editor-theme-<theme>` so theme-scoped CSS (border override, etc.) can target it.
- **`CalendarRepository::userHasSchedulingBypass()` accepts a calendar context.** The method gains an optional second parameter, `?int $calendar_post_id`. With a non-null id it consults `_ffc_self_scheduling_config['admin_bypass']` and returns `false` when the toggle is off (even for admins). With a null id it falls back to capability-only behavior, preserving every existing caller exactly. Booking-relevant call sites — `AppointmentValidator::validate()`, `AppointmentHandler::get_available_slots()`, `AppointmentHandler::cancel_appointment()`, and `SelfSchedulingShortcode` — now forward the calendar's `post_id` so the toggle actually takes effect during booking flows. Audience/REST admin read paths continue to pass `null` and retain their historical behavior.

### Fixed

- **`[ffc_verification]` result card header rendered with the admin preview modal's dark slate background.** The certificate/appointment verification success cards use `.ffc-preview-header` for their blue-gradient banner with centered title. A later CSS block — meant only for the admin certificate preview modal (`#ffc-preview-modal`) — defined a second, unscoped `.ffc-preview-header` rule with `background: #1d2327` and `display: flex; justify-content: space-between`. Because CSS cascade resolved it last, that rule overrode the gradient (producing a black bar) and pushed the badge to the left while any sibling status label was pushed to the right. Scoped all five affected selectors (`.ffc-preview-header`, `.ffc-preview-header h2`, `.ffc-preview-close`, `.ffc-preview-note`, `.ffc-preview-body`) under `#ffc-preview-modal` so they only apply inside the admin modal, restoring the intended centered blue header on the frontend verification results for certificates, appointments and reregistration records.

### Removed

- **Orphan TinyMCE-only CSS selector.** `.mce-content-body .ffc-placeholder` is gone — it was only reachable inside an active `wp_editor()` context, which the plugin did not render anywhere. The rule's properties merged into the general `.ffc-placeholder` selector now that there is a real TinyMCE target (the email body).
- **Legacy constructor registration of the TinyMCE filter.** The unconditional `add_filter( 'tiny_mce_before_init', … )` call in `Admin::__construct()` is replaced by the screen-scoped registrar (see _Changed_). Behavior inside the `ffc_form` editor is unchanged; behavior elsewhere in the admin is simply not touched anymore.
- **Hardcoded all-or-nothing admin bypass for self-scheduling.** The previous `userHasSchedulingBypass()` granted bypass to any admin unconditionally, with no way for a calendar author to opt out. Replaced by the per-calendar toggle (see _Added_ / _Changed_); authors that want the old behavior simply leave the checkbox on, which is also the default for legacy calendars migrating into 5.4.1.

### Security

- **MEDIUM (XSS hardening) — email body.** `wp_kses_post()` replaces the plugin-specific `wp_kses( …, $allowed_html )` on `email_body` save. Scripts, forms, iframes (and any other tag outside the WordPress post-content allowlist) are stripped on save; rich-text formatting the admin authors in the new visual editor (formatting, links, lists) is preserved.
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

## 5.4.0 (2026-04-23)

Encryption and privacy hardening across the user-data surface (centralized sensitive-field policy, payload-driven activity log encryption, auditable decrypt failures, no-leak dual-storage fix), plus the accumulated security audit (Tier 1 + Tier 2), CSV download intermediate screen, and a performance pass for admin submissions at scale.

### Added

- **Centralized sensitive-field policy** via `FreeFormCertificate\Core\SensitiveFieldRegistry` — single declarative map of which fields are encrypted and hashed per write context. Consumed by `SubmissionHandler` and `AppointmentRepository`; replaces three hard-coded lists. Exposes `encrypt_fields()`, `plaintext_keys()`, `universal_sensitive_keys()`, `dynamic_sensitive_keys()` (reads `wp_ffc_custom_fields.is_sensitive = 1`, cached), and recursive `contains_sensitive()` for payload inspection.
- **`UserProfileFieldMap`** — declarative per-field descriptor for user-profile fields. Each entry names its storage layer (`wp_users`, `ffc_user_profiles`, `wp_usermeta`), whether the value is sensitive, whether it is hashable for lookup, and optional mirror targets (e.g. `display_name` writes back to `wp_users.display_name` after the profile-table write). Sibling of `SensitiveFieldRegistry`; the registry is per write context (submission vs appointment), the map is per user field.
- **`ViewPolicy` enum** — `FULL`, `MASKED`, `HASHED_ONLY`. Declares how a caller wants sensitive fields rendered on read. The service does not elevate privileges; callers validate capability (`current_user_can('manage_options')` or similar) before asking for `FULL`.
- **`UserProfileService::read()` / `::write()`** — single entry point consolidating reads and writes across the three storage layers, with transparent encryption and hashing for sensitive fields. `read()` honours `ViewPolicy`, returns empty arrays for unknown users or empty field lists, and silently drops unregistered field keys. `write()` routes each field to its declared storage, encrypts + hashes sensitive values, and applies mirror targets last. A `FULL` read that touches a sensitive field emits one `user_profile_read_full` audit entry carrying only the requester, the target user id, and the field list — never the values. The `$extra_descriptors` parameter lets callers write fields outside the static map (used by the reregistration flow); overrides are per-call and cleared via try/finally so they cannot leak between requests.
- **`email_hash_rehash` migration** — batched, idempotent, cursor-based. Walks `wp_ffc_submissions` and `wp_ffc_self_scheduling_appointments`, decrypts `email_encrypted`, recomputes the salted hash and writes only when it differs.
- **`activity_log_clear_plaintext` migration** — batched UPDATE that NULLs `context` on activity log rows that already hold a ciphertext, closing the dual-storage leak on historical data.
- **CSV download intermediate screen** — after hash validation and before the actual download, an info screen shows form configuration (restrictions, dates, geolocation, quiz, quota) so the operator understands the form context. The download button is only enabled after the form has ended; a certificate preview button is available before the collection period begins.
- **Public CSV sync-export row cap** — new `public_csv_sync_max_rows` setting (Advanced tab, default 2000, range 100–10000). Public CSV downloads exceeding the cap are refused on the synchronous no-JS path and must use the AJAX batched flow, protecting shared hosting from execution-time timeouts on large exports.
- Test coverage: **3234 → 3485 tests** (+251), **8783 assertions**, 0 failures. New suites: `SensitiveFieldPolicyTest`, `SensitiveFieldRegistryTest`, `DecryptFailureLoggingTest`, `UserProfileFieldMapTest`, `UserProfileServiceTest`, `CustomFieldValidatorTest`, `AutoloaderTest`, `UserContextTraitTest`, `MigrationDynamicReregFieldsTest`, `ReregistrationStandardFieldsSeederTest`, `AbstractRepositoryTest`, `GeofenceTest`, `FormListColumnsTest`. Extended coverage for `UserManager::update_extended_profile` / `get_extended_profile`.

### Changed

- **Activity log encryption gate** switched from a hard-coded action whitelist (`submission_created`, `data_accessed`, `data_modified`, `admin_searched`, `encryption_migration_batch`) to payload inspection via `SensitiveFieldRegistry::contains_sensitive()`. Any action carrying a sensitive field (including nested payloads like `{fields: {cpf: ...}}`) is encrypted automatically; actions with trivial payloads are no longer wrapped in a meaningless ciphertext.
- **`ActivityLog::log()`** no longer dual-stores `context` plaintext alongside its ciphertext. Sensitive rows now NULL the plaintext column; `ActivityLogQuery::resolve_context()` decrypts on demand on read.
- **`SubmissionHandler` and `AppointmentRepository`** replaced their inline encryption blocks with a single `SensitiveFieldRegistry::encrypt_fields()` call plus a `plaintext_keys()` strip, preserving the same output shape.
- **`UserManager::update_profile()`** is now a thin facade over `UserProfileService::write()`. The legacy `sanitize_text_field` + `wp_json_encode('preferences')` pre-processing stays at the facade layer for backward compatibility; routing, upsert and the `display_name → wp_users` mirror move into the service. The `SHOW TABLES LIKE` short-circuit is gone — callers land in the service even when the plugin is mid-activation, which matches every install reachable from admin screens.
- **`UserManager::update_extended_profile()`** now routes every key through `UserProfileService::write()`. Keys registered in `UserProfileFieldMap` carry their own descriptor; arbitrary reregistration keys get an inline descriptor built at the facade layer and are treated by the service like any other usermeta-backed field. The legacy inline encrypt/hash path that used to live here is gone. Behavior improvement: clearing a sensitive field now also deletes the sibling `*_hash` meta row so a stale hash never outlives the ciphertext.
- **`Encryption::decrypt()`** split into a public wrapper and a private helper. The wrapper emits a `decrypt_failure` WARNING to `ActivityLog` whenever a non-empty ciphertext resolves to null, with metadata-only context (`ciphertext_length`, `v2_prefix`). Callers continue to receive `null`; silence is no longer opaque.
- **`SubmissionRestController::decrypt_submission_data()`** replaced an inert `try/catch` (decrypt never throws) with an explicit null-check, avoiding the PHP 8.1+ `json_decode(null)` deprecation.
- **Residual `class_exists ? Encryption::hash : hash('sha256')` fallbacks** removed from `SelfSchedulingAppointmentHandler` and `SubmissionRepository::hash()`. Encryption is a runtime dependency; the raw-`sha256` branch was unreachable and would have produced hashes no other call site could match.
- **Encryption envelope** now produces authenticated **v2 ciphertexts** (encrypt-then-MAC, HMAC-SHA256 with a separately derived MAC key); legacy v1 ciphertexts remain decryptable.
- Perf: `SubmissionRepository::countByStatus()` cached in a 5-minute transient — eliminates the `COUNT(*) … GROUP BY status` scan on every admin submissions page load; cache invalidated on every write that can move a row between statuses.
- Perf: composite index `(form_id, status, submission_date)` on `ffc_submissions` — covers the common admin list pattern (filter by form + status, sort by `submission_date` DESC).
- Perf: activity log cleanup falls back to `admin_init` (transient-gated, 24h) for low-traffic shared hosting where WP-Cron misses scheduled runs.
- Perf: `findPaginated()` search — `magic_token` uses prefix match (`'term%'`) so the B-tree index helps; the unencrypted-`data` LIKE fallback is skipped for search terms shorter than 4 characters.
- Code quality: PHPCS **1232 → 0 violations** (105× short-ternary expansion, 152× file header normalization, 142× class-docblock backfill, 299× resolved docblock violations). PHPStan level 7: **0 errors**.
- `SECURITY.md` — supported-versions table updated to mark `5.4.x` as supported (was stuck on `5.1.x`).
- `CONTRIBUTING.md` — Branches section no longer mandates the AI-specific `claude/*` prefix for human contributors; Releasing section now documents the `[Unreleased] → [X.Y.Z]` flow (rename, insert fresh `[Unreleased]`, bump `Version:` + `FFC_VERSION` + `readme.txt` Stable tag in one step) to match the convention used from 5.4.0 onward.
- **Settings admin — visual consistency pass (issue #51).** Active nav tab now renders on a white panel (`--ffc-bg-card`) instead of reading as just a blue underline on the gray strip. Every section `<h2>` across General, SMTP, Cache, Rate Limit, Geolocation, User Access, Advanced and Migrations now carries an `ffc-icon-*` class so the divider line + icon pattern is uniform. Sub-sections that shared a card became their own cards: QR Code Defaults (General), QR Code Cache (Cache), Debug Settings and Public CSV Download (Advanced). Geolocation's "How Geolocation Works" was promoted from an inline `ffc-info-box` to a real section card, its redundant `<hr>` separators between cards were removed, and its save button — along with User Access's — was renamed to "Save Changes" so every tab uses the same wording. Migrations' "Need Help?" title went from `<h3>` to `<h2>` so the standard card delimiter applies. Documentation tab: each numbered section uses `<h3 id="…">` as its anchor target, so a scoped CSS rule now gives `.card > h3[id]` the same border-bottom / 18px / weight-600 treatment as `.card h2`, making every section visibly delimited without churning the 18 anchor ids.
- **Geofence debug toggle deduplicated.** The Geolocation tab's standalone `debug_enabled` checkbox (stored at `ffc_geolocation_settings.debug_enabled`) overlapped with the Advanced tab's `debug_geofence` toggle (stored at `ffc_settings.debug_geofence`, gated through `Debug::AREA_GEOFENCE`). Both surfaces — frontend `console.log` in `ffc-geofence-frontend.js` and backend `error_log` from `Debug::log_geofence` / `IpGeolocation::debug_log` — now read the single Advanced-tab setting. The Geolocation tab "Debug Mode" card was removed; `Geofence::get_frontend_config` and `Frontend::enqueue_geofence_assets` switched to `Debug::is_enabled( Debug::AREA_GEOFENCE )`; `IpGeolocation::debug_log` dropped its own gate and delegates to `Debug::log_geofence`. Stale `debug_enabled` values left over in `ffc_geolocation_settings` are simply ignored (no migration needed for a debug-only flag that defaults off).
- **CSS dead-code sweep.** Removed 21 unused `.ffc-*` classes that had no references in PHP, JS, HTML templates, or other CSS — surfaced via a full audit of the 19 source files in `assets/css/`. Drops `.ffc-info-box` (orphaned by the Geolocation card promotion above) from `ffc-admin-settings.css`; `.ffc-qr-info-box` + `.ffc-qr-note` from `ffc-admin-submission-edit.css`; eleven layout/spacing/text utilities (`.ffc-inline-flex`, `.ffc-items-center`, `.ffc-gap-sm`, `.ffc-gap-md`, `.ffc-mb-0`, `.ffc-mb-sm`, `.ffc-mb-lg`, `.ffc-mt-md`, `.ffc-mt-lg`, `.ffc-text-center`, `.ffc-text-right`) from `ffc-common.css`; six appointment-verification / success-page classes (`.ffc-certificate-header`, `.ffc-auth-code-display`, `.ffc-detail-label`, `.ffc-detail-value`, `.ffc-success-container`, `.ffc-success-title`) from `ffc-frontend.css`; and `.ffc-download-ficha-btn` from `ffc-user-dashboard.css`. PDF-template helper classes documented for end-user templates (`.ffc-txt-*`, `.ffc-full-width`, `.ffc-full-width-img`, `.ffc-responsive-logo`) were intentionally kept since they are part of the certificate-template authoring surface.

### Fixed

- **Email hash divergence between tables** — same email produced different `email_hash` values in `wp_ffc_submissions` (salted) and `wp_ffc_self_scheduling_appointments` (raw SHA-256). Cross-entity lookups were silently broken. Unified on `Encryption::hash($email)`; migration `email_hash_rehash` rewrites legacy hashes.
- **`AppointmentRepository::findByCpfRf`** never matched its own writes — `createAppointment` stored a salted hash, `findByCpfRf` queried with a raw SHA-256. Now both read and write use `Encryption::hash`.
- **`UserCleanup::handle_email_change`** reindexed submission `email_hash` with raw SHA-256, overwriting correct salted hashes on every email change. Now mirrors `SubmissionHandler`.
- **`SecurityService::verify_simple_captcha`** rejected the valid answer `0`: `empty('0')` is true in PHP, so subtractions where `n1 === n2` (`answer = 0`) always failed. Now uses `'' === trim($answer)`; hash comparison upgraded to `hash_equals()` for timing safety.
- **Silent decrypt failures** in `Encryption::decrypt` now emit an `ActivityLog` warning. `decrypt(...) ?? ''` fallbacks in reprint detector / user manager / CSV exporter remain legitimate, but the failure itself is no longer invisible to auditors.
- Activity log disabled-notice link pointed to `Settings > General`; the toggle lives in `Settings > Advanced`. Link and label updated.

### Security

- **CRITICAL** (LGPD) — Cross-table hash consistency (see _Fixed_ above): dedup and reconciliation between submissions, appointments and user profile now works reliably.
- **HIGH** (LGPD) — Activity log no longer dual-stores `context` plaintext alongside `context_encrypted` for sensitive rows. The ciphertext is authoritative; reads transparently decrypt.
- **HIGH** — `AbstractRepository::build_where_clause()` now uses the `%i` identifier placeholder and a `get_allowed_where_columns()` allowlist to prevent column-name SQL injection.
- **HIGH** — Timing-safe token comparison via `hash_equals()` in the appointment receipt handler.
- **HIGH** (XSS) — Escape user-supplied values (description, cancellation reason, etc.) in audience email templates to prevent stored XSS in recipient mail clients.
- **MEDIUM** (LGPD) — Decrypt failures auditable via `ActivityLog::log('decrypt_failure', WARNING)`, with metadata-only context that cannot leak plaintext nor recurse into the encryption gate.
- **MEDIUM** — `SubmissionRestController` admin endpoints restricted to `manage_options` (was `edit_posts`, accessible to Authors who shouldn't see PII).
- **MEDIUM** — `UserProfileRestController::update_user_profile()` sanitizes user input via `sanitize_text_field` + `wp_unslash`.
- **MEDIUM** — `AudienceRepository::get_user_audiences()` replaces inline `$id_list` interpolation with parameterized placeholders.
- **MEDIUM** (XSS) — Escape `id`, form title, and action labels in `SubmissionsList::column_default()` / `render_actions()`; inline-literal output for `$required_attr` in the frontend field renderer; escape `{{form_title}}` before `str_replace` into PDF layouts; wrap admin-configured email body text with `wp_kses_post()`.
- **MEDIUM** (crypto) — Encryption produces authenticated v2 ciphertexts (encrypt-then-MAC, HMAC-SHA256 with a separately-derived MAC key); legacy v1 ciphertexts remain decryptable.
- **MEDIUM** (transport) — `IpGeolocation` HTTPS opt-in via `ffc_ipapi_use_https` filter; `sslverify` now follows the scheme.
- **MEDIUM** (IP spoofing) — `RateLimiter` only trusts `REMOTE_ADDR` by default; forwarded headers are gated behind `ffc_trust_forwarded_headers`.
- **MEDIUM** (data leak) — `MagicLinkHelper` replaces `chart.googleapis.com` QR URL with the local `QRCodeGenerator` so magic tokens never reach a third-party service.
- **MEDIUM** (path traversal) — `PdfGenerator` validates the receipt template path is inside the plugin or theme directories; `ReregistrationEmailHandler` allowlists template names; `AudienceNotificationHandler` moves temp ICS files from the public uploads dir to the system temp dir with try/finally cleanup.
- **LOW** — Removed `$e->getMessage()` from 5 client-facing error responses across 4 REST controllers to prevent information disclosure.
- **LOW** — `Admin::redirect_with_msg()` builds the redirect target from `page`/`post_type` instead of `REQUEST_URI`; removed duplicate `wp_nonce_field()` in `FormEditorMetaboxRenderer`; escaped return values in `VerificationResponseRenderer::format_field_value`; `ReprintDetector` hash-column query uses `%i`; `receipt-handler` escapes `bloginfo` with `esc_html` / `esc_url`.
- **LOW** (LGPD) — Hash PII identifiers before logging in `RateLimiter`; hash IP in `IpGeolocation` debug logs.

---

## 5.3.0 (2026-04-17)

Full-page cache compatibility, per-form captcha isolation, and CI pipeline improvements.

### Added

- **Full-page cache compatibility** — forms and calendars now work correctly with LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache. Self-scheduling shortcodes with business-hours restrictions send `DONOTCACHEPAGE` + `nocache_headers()` to prevent stale "closed" messages. Audience shortcodes for logged-in users prevent cached cross-user content leakage (#37)
- **Dynamic Fragments geofence refresh** — the AJAX endpoint now accepts `form_ids[]` and returns fresh geofence date/time configs, so cached pages always display up-to-date availability windows after admin changes (#37)
- **Automatic cache purge on save** — `FormCache::purge_page_cache()` finds pages embedding a saved form or calendar and purges them from LiteSpeed, WP Rocket, W3TC, and WP Super Cache. Called on both `save_post_ffc_form` and `save_post_ffc_self_scheduling` (#37)
- **CSV Download Page URL setting** — new field on the General settings tab for configuring the public CSV download page URL (#34)
- **Search forms by ID** — the admin forms list table (`edit.php?post_type=ffc_form`) now supports searching by numeric post ID (#39)

### Changed

- **CustomFieldValidator extraction** — validation logic extracted from `CustomFieldRepository` into a dedicated `CustomFieldValidator` class for single-responsibility and testability (#35)
- **In-plugin documentation expansion** — expanded the Documentation settings tab with additional sections covering all shortcodes, settings, and features (#35)
- Extract reusable composite action `.github/actions/setup-composer` for PHP + Composer setup (#30, #31)
- Add Dependabot auto-merge for patch and minor dependency updates (#29)
- Promote PHPCS from advisory to gating — PRs must pass WPCS on changed files (#28)
- Promote PHPStan from level 6 to **level 7** (#24)
- Re-introduce coverage with pcov, scoped to `includes/`, uploaded to Coveralls (#22)
- Auto-fix ~83k PHPCS violations via PHPCBF (#25)
- Annotate 223 PreparedSQL + NonceVerification false positives (#26)
- Phase 3 PHPCS mechanical fixes + PSR-4 suppressions (#27)
- Resolve remaining WPCS errors in cache-related files (file docblocks, class docblocks, short ternary operators, missing `@param` tags) (#36)

### Fixed

- **Same captcha on all forms** — when multiple forms exist on a cached page, Dynamic Fragments now generates a unique math captcha per form instead of applying a single captcha to all forms (#38)
- **PHPUnit test failures** — added missing mocks for `nocache_headers()` and `get_posts()` in `AudienceShortcodeTest` and `FormCacheTest` after cache compatibility changes (#39)
- **Minified assets out of sync** — regenerated `ffc-dynamic-fragments.min.js` with `--source-map` to match the `npm run build` output (#39)

### Removed

- Remove duplicate `push: main` trigger from CI and Assets workflows — each PR merge no longer runs the full suite twice (#39)
- Remove CodeQL workflow (not applicable to PHP plugin) (#30)

---

## 5.2.0 (2026-04-15)

Raise minimum PHP requirement from 7.4 to 8.1. PHP 7.4 reached end-of-life on 2022-11-28 and PHP 8.0 on 2023-11-26; both are unsupported. The previous lockfile was also resolving `doctrine/instantiator` 2.1.0 — which requires PHP 8.4 — silently breaking `composer install` on PHP 7.4/8.1/8.3 runners.

### Added

- **Named Geofence Locations** — new `GeofenceLocationRegistry` CRUD class stores reusable geofence locations as a WordPress option (`ffc_geofence_locations`), each with name, lat/lng, radius, and per-location default GPS / default IP flags (mutually exclusive). Replaces the legacy "Default Geofencing Areas" textarea on the Geolocation settings tab with a full CRUD table (add, edit, delete with nonce-protected actions)
- Form editor geofence metabox now offers a **radio toggle** (Registered Locations / Custom Coordinates) for both GPS and IP area sources, with a **multi-select dropdown** when "Registered Locations" is selected. Auto-draft forms pre-select the default GPS/IP locations from the registry
- `Geofence::resolve_areas_text()` helper transparently resolves location IDs to coordinate text at runtime — existing forms with `geo_area_source = 'custom'` (or missing key) continue to work without migration
- **CSV Downloads column** on the forms list table — shows the public download count (with quota when set) for forms with CSV download enabled
- **GeofenceLocationRegistryTest** — 24 tests covering `get_all()`, `get_by_id()`, `get_by_ids()`, `save()` (including default flag mutual exclusivity), sanitization (lat/lng clamping, negative values, radius default, name truncation), `delete()`, `get_default_gps()`/`get_default_ip()`, and `resolve_to_areas_text()`
- **GeofenceDatetimeValidationTest** — 12 tests covering daily + span mode datetime validation, all branch paths including edge cases
- **GeofenceGeolocationTest** — 17 tests covering `parse_areas`, `validate_geolocation` with IP fallback scenarios, `has_form_expired_by_days`
- **GeofenceFrontendConfigTest** — 10 tests covering `get_form_config` boolean casting, `get_frontend_config` with admin bypass / partial bypass / regular user
- **LoaderTest** — 6 tests covering constructor hook registration, frontend asset registration and localization

### Changed

- **TabGeolocationTest** — replaced `test_save_settings_saves_main_geo_areas_to_ffc_settings` with `test_save_settings_calls_save_locations` for new registry-based save behavior
- **3154 → 3234 tests** (+80) with all 7646 assertions green
- **BREAKING:** Minimum PHP bumped from **7.4 → 8.1**. Update your server before upgrading. `Plugin Name` header, `FFC_MIN_PHP_VERSION`, `composer.json#require.php` and `readme.txt#Requires PHP` all updated.
- `composer.json#config.platform.php` pinned to `"8.1"` so the lockfile resolves to versions compatible with the declared minimum regardless of the developer's local PHP version.
- `composer.lock` regenerated under PHP 8.1 platform; `doctrine/instantiator` now resolves to `2.0.0` (compatible with PHP ^8.1) instead of `2.1.0` (which required PHP ^8.4).
- CI matrix now covers PHP **8.1, 8.2, 8.3, 8.4** (added 8.2, removed 7.4).
- Zero PHPStan level 6 errors — cleared 4 findings exposed by newer `php-stubs/wordpress-stubs` (v6.9.1) and `szepeviktor/phpstan-wordpress` (v2.0.3):
  - `AdminActivityLogPage::output_csv()` PHPDoc: `array<int, array<string, mixed>> $rows` → `array<int, array<array-key, mixed>> $rows` (the method iterates and passes values directly to `fputcsv()` without accessing keys by name; the caller builds rows with positional int keys).
  - `UserAudienceRestController::build_joinable_node()` PHPDoc: added `array<string, mixed>` value type to `@param $node` and `@return`.
  - `ReregistrationAdmin` details markup: removed dead `|| $formatted === null` branch — `FichaGenerator::format_field_value()` returns a non-nullable `string`.

### Fixed

- **Geofence frontend config flags always false** — `get_frontend_config()` compared already-cast PHP booleans against the string `'1'` (`'1' === true` is always `false` in strict comparison), causing the JS frontend to never enforce datetime or geolocation restrictions. Backend validation was unaffected. Now compares boolean values directly
- **Submission count link** on forms list — used `form_id` instead of `filter_form_id`, so clicking the count did not filter the submissions list

---

## 5.1.0 (2026-04-11)

Public CSV download feature: form organizers without WordPress admin access can now retrieve the submissions CSV of a specific form via a revocable per-form hash, gated by form expiration and a configurable download quota. No new dependencies and no schema changes.

### Added

- New `[ffc_csv_download]` shortcode — public page where visitors enter a form ID + hash and receive the submissions CSV as a direct download
- New `PublicCsvDownload` handler on `admin-post(_nopriv)_ffc_public_csv_download` — validates nonce, honeypot, CAPTCHA, per-IP rate limit, form-level enable flag, hash equality, geofence expiration, and per-form download quota before streaming the file
- New `PublicCsvExporter` with AJAX batched 3-step export (start → batch ×N → download) matching the admin `CsvExporter` architecture — prevents memory exhaustion and webserver timeouts on large datasets; column layout mirrors the admin export so both downloads are interchangeable. Synchronous streaming preserved as a no-JS fallback
- New `Geofence::get_form_end_timestamp()` and `Geofence::has_form_expired()` helpers — the public CSV download is only released after the form's configured end date/time
- New "Public CSV Download" metabox on the form editor — toggle, read-only hash with regenerate control, download counter, reset button, per-form quota override, and a ready-to-share URL preview
- Advanced settings tab now exposes `public_csv_default_limit` — default quota suggested to the admin when enabling the feature on a new form (default: 1)
- Counter is incremented *before* the stream starts to prevent race conditions between concurrent requests
- **GeofenceFormExpirationTest** — 12 tests covering `get_form_end_timestamp()` (null on empty/invalid meta, trims whitespace, defaults `time_end` to `23:59:59`, respects `wp_timezone()`) and `has_form_expired()` (past vs. future, end-of-day default)
- **PublicCsvExporterTest** — 15 tests locking the CSV column layout (15 fixed + 3 edit + N dynamic), fixed-column value mapping, consent yes/no rendering, deleted-form placeholder, dynamic key ordering, RF-only rows, batch-size constants, and JOB_TTL
- **PublicCsvDownloadTest** — 28 tests covering constants, shortcode rendering (nonce, form fields, honeypot, CAPTCHA, URL prefill, flash messages), the 12 failure branches of the validation flow, the happy-path counter-increment observable effect, 8 direct `validate_form_access()` unit tests, and AJAX hook registration verification
- UX: `ffc-frontend.css` is now auto-enqueued on pages containing the `[ffc_csv_download]` shortcode (matching how `[ffc_form]` / `[ffc_verification]` already trigger the stylesheet); new `ffc-csv-download.js` is enqueued only on CSV download pages
- New **"Obsolete Shortcode Cleanup"** section on the Data Migrations tab (`ffc_form&page=ffc-settings&tab=migrations`) — scans published posts, pages and reusable blocks (`wp_block`) for embedded `[ffc_form id="..."]` shortcodes pointing at forms whose end date is more than `N` days in the past, and removes those obsolete shortcodes from `post_content`. Configurable grace window (default: **90 days**, clamp 1-3650), admin-only (`manage_options`), nonce-protected
- New `ObsoleteShortcodeCleaner` service (`includes/migrations/class-ffc-obsolete-shortcode-cleaner.php`) with `find_expired_form_ids()`, `scan_posts_for_expired_forms()`, `extract_form_ids()`, `strip_shortcodes_from_content()`, `remove_shortcodes_from_post()` and a `run($days, ['dry_run' => bool])` pipeline. Handles both Classic editor `[ffc_form id="N"]` and Gutenberg block-wrapped `<!-- wp:shortcode -->[ffc_form id="N"]<!-- /wp:shortcode -->` formats
- New `Geofence::has_form_expired_by_days(int $form_id, int $days)` helper that reuses `get_form_end_timestamp()` as the single source of truth for "form is over"
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
- **Audience Scheduling — 3-Level Hierarchy:** **3-level audience hierarchy** — audiences now support parent / child / grandchild structure. `get_hierarchical()` rewritten to fetch all audiences in a single query and build the tree in PHP; `get_descendant_ids()`, `get_ancestor_ids()`, `get_possible_parents()` and `get_ancestors()` added for recursive traversal
- **Audience Scheduling — 3-Level Hierarchy:** Admin audience form updated — parent dropdown shows all eligible parents with indented display (`— name`), breadcrumb shows full ancestor chain, circular reference prevention excludes self and descendants from parent options
- **Audience Scheduling — 3-Level Hierarchy:** CSV import updated — iterative multi-pass algorithm (up to 4 passes) creates audiences whose parents already exist, deferring the rest to the next pass. Sample CSV updated to include 3rd-level example
- **Audience Scheduling — 3-Level Hierarchy:** Frontend calendar audience select (`populateAudienceSelect`) uses recursive `appendNodes(nodes, depth)` with indented names; auto-selection helpers (`getAllDescendantIds`, `collectParentNodes`) and `collapseParentAudiences` made recursive
- **Audience Scheduling — 3-Level Hierarchy:** Shortcode `audience_to_array()` shared method recursively maps all hierarchy levels for the frontend JSON payload
- **Audience Scheduling — Isolated Calendar Mode:** **Isolated calendar mode** — new "Ignore conflicts from other calendars" checkbox on the calendar edit form. When enabled, audience same-day and user overlap conflict checks only consider bookings within that calendar; environment conflicts remain per-environment regardless
- **Audience Scheduling — Isolated Calendar Mode:** New `is_isolated` column (`tinyint(1) DEFAULT 0`) on `ffc_audience_schedules` table, added via `add_column_if_missing()` migration pattern
- **Audience Scheduling — Isolated Calendar Mode:** `get_user_conflicts()` and `get_audience_same_day_bookings()` accept optional `$scope_schedule_id` parameter — when set, adds `INNER JOIN` on environments table to filter conflicts to the given schedule only
- **Audience Scheduling — Isolated Calendar Mode:** REST controller resolves `schedule_id` from the selected environment and passes it to conflict checks when the schedule is isolated
- **Audience Scheduling — User Dashboard:** **3rd-level audiences in user profile** — `get_joinable_groups()` API rewritten from 2-query parent+children approach to single-query tree builder, matching `get_hierarchical()` pattern. `renderJoinableGroups()` in `ffc-user-dashboard.js` made recursive
- **Audience Scheduling — User Dashboard:** **Accordion on audience group selection** — parent and sub-parent headers toggle their children on click, starting collapsed with a `+` icon that switches to `−` when expanded. Uses `aria-expanded` for accessibility. Leaf-only nodes render without accordion
- **Audience Scheduling — User Dashboard:** **"Leave all groups" button** — new button in profile actions bar (next to "Change Password") allows users to leave all self-joinable groups at once. Styled with red danger color, shows confirmation dialog with group count, and calls new `POST /user/audience-group/leave-all` endpoint. Button only appears when the user belongs to at least one group

### Changed

- Add array shape PHPDoc (`array<int, string>`, `array<string, mixed>`, `array{items: ..., total: int}`) to `CsvExporter` private helpers, `ReregistrationStandardFieldsSeeder::on_audience_created()`, `UrlShortenerRepository::findPaginated()`, `UrlShortenerService::get_stats()` and `UserManager::get_user_identifiers_masked()`
- Pre-initialize `$calendar = null;` in `AppointmentAjaxHandler::create()` alongside `$pdf_data` / `$appointment` — fixes `variable.undefined` when `findById()` throws before the assignment
- Add `QRcode::raw()` to `phpstan-stubs.php` — the SVG QR generator already calls it in production, only the static-analysis stub was missing
- Fix `@return` PHPDoc parse error in `UserManager::get_user_identifiers_masked()` (`string[}}` → `array<int, string>`)
- Correct `wp_validate_redirect()` fallback argument type in `UrlShortenerLoader` (`false` → `''`) to match the WordPress stub signature
- UX: `[ffc_csv_download]` now reuses the same CSS classes as `[ffc_verification]` (`ffc-verification-container`, `ffc-verification-header`, `ffc-verification-form`, `ffc-form-field`, `ffc-input`, `ffc-submit-btn`, `ffc-verify-error` / `ffc-verify-success`) so the public download page inherits the card layout, dark-mode support and focus ring already used by the verification page — no more inline `<style>` block
- UX: Progress bar overlay with real batch-by-batch feedback on the `[ffc_csv_download]` page — shows record count, progress percentage, and status messages throughout the export. Minimum 1.5 s display threshold prevents the overlay from flashing on small exports. Graceful degradation: when JavaScript is unavailable the form falls back to the synchronous `admin-post.php` handler
- New **ObsoleteShortcodeCleanerTest** — 19 tests covering regex quote styles (`id="N"`, `id='N'`, `id=N`), extra-attribute handling, classic + Gutenberg + mixed removal, dry-run vs apply pipelines, report truncation at `REPORT_LIMIT`, empty-result short-circuits, and `wp_update_post()` no-op skipping
- Replace inline `<style>` blocks in form-list-columns, audience-admin-page, self-scheduling editor, URL shortener admin, and reregistration custom fields with dedicated CSS classes and `wp_add_inline_style()`
- A11y: Add `aria-label` to certificate and booking forms
- A11y: Add `aria-describedby` to ticket field
- **Audience Scheduling — Conflict Behavior:** **Audience same-day conflict downgraded from hard block to soft warning** — booking the same audience group on the same day now shows a dismissible warning with existing booking details instead of blocking entirely. Users can acknowledge and proceed, matching the behavior of user overlap conflicts
- **Audience Scheduling — User Dashboard:** Nested subgroup styling with indented padding for 3rd-level items and headers
- Updated 6 test mocks in **AudienceRepositoryTest** for recursive operations — `test_get_hierarchical`, `test_get_members_includes_children`, `test_get_user_audiences_includes_parents`, `test_get_user_audiences_does_not_duplicate`, `test_get_member_count_includes_children`, and `test_cascade_self_join` now properly mock multi-level `get_results`/`get_row` calls

### Fixed

- iOS Safari PDF download — use `pdf.output('bloburl')` + `window.open()` instead of `pdf.save()` which relies on `blob:` URLs unsupported since iOS 13.3
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
- Booking "Create" button stuck on loading text ("Verificando...") after consecutive bookings — `openBookingModal()` reset `disabled` state but not the button text; now also restores `ffcAudience.strings.createBooking`
- Frontend calendar did not display 3rd-level audiences — minified JS (`ffc-audience.min.js`) was stale and still contained the old 2-level rendering logic
- Zero PHPStan level 6 errors — cleared 26 pre-existing static analysis findings across 13 files (`CsvExporter`, `QrcodeGenerator`, reregistration module, self-scheduling handler, URL shortener module, user dashboard module, PHPStan stubs)
- **3090 → 3154 tests** (+64) with all 7415 assertions green

### Removed

- Remove dead code flagged by PHPStan — duplicated `wp_doing_ajax()` early-return in `AccessControl::block_wp_admin()`; redundant `!== ''` / `!== null` / `!== '0'` checks in the reregistration module; `|| $success` tail in `UserManager::save_profile_data()`; empty-guard around the always-populated `$where_clauses` in `UrlShortenerRepository::findPaginated()`; redundant `$temp_file === ''` check in `QrcodeGenerator::generate()`
- Remove redundant `?? ''` fallbacks on `Encryption::decrypt_field()` calls in `CsvExporter::format_csv_row()` — the method returns a non-nullable string (same fix already applied to `PublicCsvExporter`)

### Security

- Download hash stored in a dedicated post meta (`_ffc_csv_public_hash`), generated with `bin2hex(random_bytes(16))` and compared via `hash_equals()` to mitigate timing attacks
- Reuses `Shortcodes::generate_security_fields()` so the public form includes the same honeypot (`ffc_honeypot_trap`) and mathematical CAPTCHA already validated by `SecurityService::validate_security_fields()`
- Per-IP rate limiting via `RateLimiter::check_ip_limit()`, identical to the public form submission path
- `get_post_type()` check blocks the handler from serving data for non-`ffc_form` posts even if a valid hash is supplied
- Empty stored hash short-circuits the comparison — prevents `hash_equals('', '')` from accepting any request before the admin has generated a hash
- AJAX batch jobs scoped by `sha1(IP)` — subsequent batch/download requests verify the caller's IP matches the IP that started the job. Combined with UUID v4 job IDs (122 bits of entropy) this prevents cross-visitor job hijacking
- `wp_update_post()` automatically creates WordPress revisions for modified `post` / `page` entries, giving admins a manual rollback path. Only `[ffc_form]` shortcodes pointing at expired IDs are removed — the rest of the content is left untouched
- CPF, RF and RG are now encrypted at rest (AES-256-CBC via the existing `Encryption` class) in both the submission JSON and in `usermeta`. Decryption is transparent in form renderer, PDF generator, CSV exporter, and verification handler
- Refactor `AppointmentRepository::findByUserId()` and `getStatistics()` to use single `wpdb->prepare()` calls instead of nesting prepared fragments (avoids placeholder re-processing)
- Replace direct ID interpolation in `AbstractRepository::findByIds()` with proper `%d` placeholders via `array_fill` + spread operator
- Add `is_uploaded_file()` validation in `AudienceAdminImport` for both member and audience CSV uploads (prevents path traversal via crafted `tmp_name`)
- Sanitize `Content-Disposition` filenames across all 6 CSV exporters — strip CR/LF/quote characters and wrap in double quotes (CRLF injection prevention per RFC 6266)
- Centralize honeypot+captcha via `SecurityService` in verification handler; add honeypot field to reregistration form (defense-in-depth)
- Add SRI hash for jQuery UI CDN stylesheet
- Add rate limiting to certificate verification REST endpoint
- Add `X-RateLimit-Limit` / `X-RateLimit-Remaining` headers to REST API responses

### Documentation

- Added a `[ffc_csv_download]` row to the Shortcodes table in the Documentation tab (`ffc-settings&tab=documentation`) describing the Form ID + hash workflow, the expiration/quota gating, and the optional `title` attribute

---

## 5.0.3 (2026-03-27)

Performance optimizations for URL shortener and QR code generation, new admin columns for forms listing, and Safari/iOS geofence fixes.

### Added

- Add ID column (sortable) to ffc_form listing screen for quick form reference
- Add Shortcode column with copy-to-clipboard button to ffc_form listing screen
- Add Submissions column with batch-loaded count (single GROUP BY query, no N+1) linking to filtered submissions page

### Changed

- Cache plugin settings in UrlShortenerService — single `get_option` per request instead of ~7 repeated calls
- Defer redirect click count increment to `shutdown` hook — redirect response is sent before the DB update
- Add `qr_cache` column to `ffc_short_urls` table — QR code base64 stored in DB, avoids phpqrcode + GD regeneration on every admin page load
- Rewrite SVG QR generation to use `QRcode::raw()` matrix directly — eliminates temp file I/O, PNG generation, GD image loading, and pixel-by-pixel color scanning
- UX: Progressive loading messages for Safari/iOS geolocation wait — three timed phases replace the static message so users know the page is alive and receive increasingly specific guidance (t=0s: tap Allow, t=8s: check for prompt, t=20s: check Location Services settings)
- UX: `updateLoadingMessage()` helper updates text in-place without removing/re-adding the spinner element

### Fixed

- `isSafari()` detection for iPadOS 13+ — modern iPads report Mac desktop user-agent, now detected via `navigator.maxTouchPoints`
- Geofence loading spinner stuck indefinitely when Safari silently ignores geolocation request — added safety timeout (40s Safari / 25s others) with `gps_fallback` honoring
- `maximumAge: 0` on first geolocation attempt forces fresh GPS fix causing unnecessary 20s timeout on Safari — now uses `maximumAge: 30000` to accept recent cached position
- `gps_fallback` admin setting (`allow`/`block`) not passed to frontend — GPS failure always blocked the form regardless of admin configuration
- Safari-specific error messages (Location Services guidance) overridden by generic admin `messageError` — browser-specific messages now always take priority
- Geofence `handleBlocked` signature simplified to 3 arguments, preventing `customMessage` from silently swallowing specific error messages

### Removed

- Remove unnecessary cache invalidation from `incrementClickCount` (click_count not needed for redirect resolution)

---

## 5.0.2 (2026-03-03)

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
- Removed unused CSS classes and stale Phase 3 comments

---

## 5.0.1 (2026-02-22)

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
- Replaced `stripslashes()` with `wp_unslash()` in SubmissionsList and VerificationHandler (4 occurrences)
- Improved SQL IN clause pattern in SubmissionRepository and AppointmentRepository — switched from string interpolation of `%s` placeholders to `%d` with `intval()` array mapping for integer ID arrays
- Moved rate limiter before format validation in `verify_by_magic_token()` to prevent probing token formats without throttling
- Added explicit `json_last_error_msg()` check and error logging after `json_decode()` in Audience loader
- Added early IP-based rate limit check in `FormProcessor::handle_submission_ajax()` before nonce/CAPTCHA — prevents brute-force DoS from consuming server resources on expensive checks
- Added justifications to all bare `phpcs:ignore` comments in URL Shortener admin page (9 comments standardized)
- Encrypted fields (email, CPF, RF) not decrypted in REST API responses for submissions and user certificates
- XSS vulnerability in dashboard JS — sanitized dynamic HTML output with proper escaping
- Appointment creation failing due to non-column keys (`ffc_form_id`, `ffc_calendar_id`) in insert data array
- QR code not appearing in auto-download PDF and duplicate download button on success page
- CPF/RF and email not found for users with only self-scheduling appointments — added join on appointments table in UserCreator
- Certificate verification card narrower than appointment card on `/valid/` — added `width: 100%` to `.ffc-certificate-preview` (root cause: `displayVerificationResult()` replaces container innerHTML, removing `.ffc-verify-result` wrapper, so flex `align-items: center` caused shrink-wrap)
- **934 → 1051 tests, 1830 → 2076 assertions**

### Removed

- Removed nonce fallback chain in AjaxTrait — each handler now verifies a single specific nonce action, eliminating timing side-channel
- Removed `wp_rest` as fallback nonce in Self-Scheduling AJAX handlers — only `ffc_self_scheduling_nonce` accepted

### Documentation

- Magic token endpoint documentation already complete (nonce intentionally omitted, rate limiting in place)
- JSON fallback handling in Audience loader already

---

## 5.0.0 (2026-02-19)

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

## 4.12.26 (2026-02-18)

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

## 4.12.25 (2026-02-17)

Unit tests for EmailHelperTrait, AjaxTrait, and Debug: email sending/parsing helpers, AJAX parameter sanitization with nonce/permission checks, and per-area debug logging.

### Added

- **EmailHelperTraitTest** (20 tests) — `ffc_emails_disabled()` (default off, setting enabled, setting empty), `ffc_parse_admin_emails()` (single/multiple comma-separated, invalid email filtering, empty string admin fallback, custom fallback, whitespace trimming), `ffc_send_mail()` (success/failure wp_mail delegation), `ffc_email_header()` (div/font-family HTML), `ffc_email_footer()` (site name, closing div), `ffc_admin_notification_table()` (table structure, label+value rows, row count, empty details)
- **AjaxTraitTest** (17 tests) — `get_post_param()` (value/default/empty), `get_post_int()` (integer cast, default, negative→positive via absint, non-numeric→zero), `get_post_array()` (sanitized array, missing→empty, non-array→empty), `verify_ajax_nonce()` (valid passes, fallback action accepted, missing nonce sends error with die simulation, custom field name), `check_ajax_permission()` (granted passes, denied sends error)
- **DebugTest** (13 tests) — `is_enabled()` (enabled/disabled/zero/independent areas), `log()` (writes when enabled, skips when disabled), data formatting (null no suffix, string/array/integer data), convenience method delegation (log_pdf, log_email, log_form, log_rest_api, log_migrations, log_activity_log), area constants count (9)

### Fixed

- Added `patchwork.json` to allow Brain\Monkey mocking of PHP built-in `error_log`
- 765 → 815 tests, 1496 → 1563 assertions

---

## 4.12.24 (2026-02-17)

Unit tests for CsvExportTrait, ActivityLogQuery, and AppointmentCsvExporter: dynamic column extraction, query building, CSV row formatting, transient caching.

### Added

- **CsvExportTraitTest** (18 tests) — `build_dynamic_headers()` (snake_case/kebab-case/mixed to Title Case, empty, single word), `decode_json_field()` (plain JSON, empty/invalid/null, custom keys, encrypted fallback), `extract_dynamic_keys()` (multi-row dedup, empty, no JSON), `extract_dynamic_values()` (key ordering, missing key default, array flattening, empty keys)
- **ActivityLogQueryTest** (17 tests) — `get_activities()` (defaults, JSON context decode, invalid/empty context, level/search filter in prepared SQL, orderby whitelist, order normalization), `count_activities()` (integer return, multi-filter query building), `get_stats()` (transient cache hit/miss, DB aggregation), `cleanup()` (deleted count, transient clearing), `run_cleanup()` (settings retention, zero skip, default 90)
- **AppointmentCsvExporterTest** (21 tests) — `format_csv_row()` via Reflection: status labels (6 statuses incl. unknown fallback), consent display (yes/no/unset), user lookups (approved_by/cancelled_by with display name, deleted user ID fallback), calendar title from repo with deleted fallback, dynamic columns (appended, missing key default), `get_fixed_headers()` count and ID-first

### Fixed

- 709 → 765 tests, 1427 → 1496 assertions

---

## 4.12.23 (2026-02-17)

Unit tests for BlockedDateRepository, EmailTemplateService, and ActivityLogSubscriber: recurring pattern matching, ICS generation, email wrapping, cache clearing, hook registrations.

### Added

- **BlockedDateRepositoryTest** (20 tests) — `matchesRecurringPattern()` via Reflection: weekly (blocked/unblocked day, weekend combo, empty/missing days), monthly (blocked/unblocked day of month, empty/missing), yearly (holiday match, ignores year variation, empty/missing dates), invalid/unknown/empty pattern, time parameter passthrough
- **EmailTemplateServiceTest** (24 tests) — `render_template()` (single/multiple vars, unknown placeholders, empty), `wrap_html()` (DOCTYPE, site name, header/content/footer structure), `format_date()`/`format_time()`, `send()` (wrap/no-wrap, wp_mail result), `generate_ics()` (VCALENDAR/VEVENT structure, date/time formatting, UID domain, REQUEST/CANCEL methods, summary/description/location, special char escaping, PRODID)
- **ActivityLogSubscriberTest** (13 tests) — Constructor hook registrations (submission/appointment/settings/cleanup), `on_settings_saved()` cache clearing (wp_cache_delete, delete_transient verification), logging method smoke tests (all 7 event handlers run without error with logging disabled)

### Fixed

- 652 → 709 tests, 1338 → 1427 assertions

---

## 4.12.22 (2026-02-17)

Unit tests for Self-Scheduling and Date Blocking: appointment validation, save handler sanitization, holiday/availability checks.

### Added

- **AppointmentValidatorTest** (24 tests) — `validate()` (missing fields, invalid date/time format, impossible date, CPF/RF validation, slot availability, daily limit, scheduling visibility), `check_booking_interval()` (user ID/email/CPF lookup, skips cancelled, skips different calendar, returns error for upcoming), `is_within_working_hours()` delegation, `get_daily_appointment_count()` delegation
- **SelfSchedulingSaveHandlerTest** (18 tests) — `save_config()` (slot duration/defaults, boolean toggles, visibility validation, private forces scheduling private, description, no POST skip), `save_working_hours()` (sanitization, defaults, no POST skip), `save_email_config()` (boolean toggles, reminder hours, text fields, no POST skip)
- **DateBlockingServiceTest** (18 tests) — `is_global_holiday()` (match, no match, empty, non-array, missing date key), `get_global_holidays()` (all, start/end/range filter, empty range, non-array, missing date entries), `is_date_available()` (holiday blocks, working hours blocks, null time checks working day, closed day)

### Fixed

- 592 → 652 tests, 1235 → 1338 assertions

---

## 4.12.21 (2026-02-17)

Unit tests for Migrations, Scheduling, and Generators: pure logic coverage for data sanitization, working hours, and magic links.

### Added

- **DataSanitizerTest** (31 tests) — `sanitize_field_value()` (custom callbacks, closure, fallback), `clean_json_data()` (JSON string/array, empty removal, zero preservation, invalid input), `extract_field_from_json()` (multi-key lookup, first non-empty match), `is_valid_identifier()` (CPF/RF digit-length validation, formatting), `is_valid_email()` (delegation), `normalize_auth_code()` (space/dash/underscore removal, uppercase)
- **WorkingHoursServiceTest** (30 tests) — `is_within_working_hours()` keyed format (range check, boundary inclusive start/exclusive end, closed day, missing start/end), array-of-objects format (range, no entry, split shift with gap), edge cases (empty/null/JSON string/unknown format); `is_working_day()` (both formats); `get_day_ranges()` (single range, split shift, closed, empty)
- **MagicLinkHelperTest** (32 tests) — `is_valid_token()` (32/64 hex, uppercase, boundary lengths, non-hex, empty), `generate_magic_link()` (URL structure, empty token), `extract_token_from_url()` (ffc_magic, token query, hash fragment, priority, no token), `get_magic_link_html()` (link, copy button, no-copy, empty token), `get_magic_link_qr_code()` (Google Charts URL, custom size, empty), `debug_info()`, `ensure_token()` (null handler, valid handler, invalid-generates-new), `get_magic_link_from_submission()`, `get_verification_page_url()`

### Fixed

- 499 → 592 tests, 1118 → 1235 assertions

---

## 4.12.20 (2026-02-17)

Unit tests for Admin module: comprehensive coverage of settings validation, CSV export formatting, and geofence logic.

### Added

- **FormEditorSaveHandlerTest** (24 tests) — `validate_geofence_config()` (GPS/IP enabled states, combined errors) and `validate_areas_format()` (lat/lng/radius format, range validation, edge values, mixed valid/invalid lines)
- **SettingsSaveHandlerTest** (28 tests) — `save_general_settings()` (dark mode validation, cleanup days, advanced tab debug flags, cache tab), `save_smtp_settings()` (tab-specific disable, SMTP fields, user email settings), `save_qrcode_settings()` (size/margin, cache tab), `save_date_format_settings()` (format/custom, preservation)
- **CsvExporterTest** (25 tests) — `get_fixed_headers()` (14/17 columns with/without edit), `format_csv_row()` (fixed columns, consent formatting, deleted form title, edit columns, dynamic columns, empty optional fields), CsvExportTrait methods (`build_dynamic_headers`, `decode_json_field`, `extract_dynamic_keys`, `extract_dynamic_values`)

### Fixed

- 422 → 499 tests, 974 → 1118 assertions

---

## 4.12.19 (2026-02-17)

Refactoring: extract focused classes from DashboardShortcode (720 → 395 lines, 45% reduction).

### Changed

- **DashboardAssetManager** (269 lines) — extracted `enqueue_assets()` with full CSS/JS enqueuing, `wp_localize_script` for dashboard, reregistration, and working-hours components, plus `user_has_audience_groups()` audience membership check
- **DashboardViewMode** (98 lines) — extracted `get_view_as_user_id()` admin view-as validation (nonce, capability, user existence) and `render_admin_viewing_banner()` HTML rendering
- DashboardShortcode retains shortcode registration, cache headers, main render orchestration, login/redirect messages, and reregistration banners

---

## 4.12.18 (2026-02-17)

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

## 4.12.17 (2026-02-17)

Refactoring: extract focused classes from FormProcessor.

### Changed

- **AccessRestrictionChecker** (168 lines) — extracted `check_restrictions` and `consume_ticket` as public static methods for password, denylist, allowlist, and ticket validation
- **ReprintDetector** (164 lines) — extracted `detect_reprint` as a public static method with `build_reprint_result` helper for JSON decoding and field enrichment
- Updated FormProcessorTest and FormProcessorRestrictionsTest to call AccessRestrictionChecker::check() directly (no more Reflection for restriction tests)
- FormProcessor retains AJAX orchestration, quiz scoring, and submission processing as its core responsibility (822 → 548 lines, 33% reduction)

---

## 4.12.16 (2026-02-17)

Refactoring: extract focused classes from SelfSchedulingEditor (924 → 559 lines, 39% reduction).

### Changed

- **SelfSchedulingCleanupHandler** (303 lines) — extracted AJAX appointment cleanup handler (`handle_cleanup_appointments`) and cleanup metabox rendering (`render_cleanup_metabox`) into a dedicated class with its own constructor hook
- **SelfSchedulingSaveHandler** (141 lines) — extracted `save_calendar_data` into a dedicated class with private helpers for config, working hours, and email config persistence
- SelfSchedulingEditor now delegates save and cleanup responsibilities via constructor composition, retaining only metabox registration, rendering, and asset loading

---

## 4.12.15 (2026-02-17)

Unit tests for Utils: comprehensive coverage of document validation, formatting, sanitization, captcha, and helper functions.

### Added

- **UtilsTest** — 95 tests covering all 3 groups of Utils methods:
  - Group A (Pure functions, 14 methods): `validate_cpf` (7 tests), `validate_phone` (7 tests), `format_cpf` (3 tests), `validate_rf`/`format_rf` (8 tests), `mask_cpf` (5 tests), `format_auth_code` (3 tests), `format_document` (6 tests), `sanitize_filename` (6 tests), `format_bytes` (6 tests), `truncate` (5 tests), `clean_auth_code`/`clean_identifier` (5 tests), `normalize_brazilian_name` (8 tests)
  - Group B (WordPress mocks, 11 methods): `asset_suffix`, `mask_email` (3 tests), `generate_random_string` (3 tests), `generate_auth_code`, `current_user_can_manage` (2 tests), `verify_simple_captcha` (5 tests), `validate_security_fields` (4 tests), `get_allowed_html_tags`, `generate_simple_captcha`, `recursive_sanitize` (2 tests)
  - Group C (DB mock): `get_submissions_table` (2 tests including multisite prefix)

### Fixed

- 306 → 401 tests, 812 → 923 assertions

---

## 4.12.14 (2026-02-17)

Unit tests for FormProcessor and PdfGenerator: quiz scoring, restriction checks, URL parsing, filename generation, and data enrichment.

### Added

- **FormProcessorTest** — 21 tests covering `calculate_quiz_score()` (9 tests: correct/wrong answers, partial scoring, non-scored fields, rounding, empty input) and `check_restrictions()` (12 tests: password validation, denylist/allowlist CPF matching, ticket validation/consumption, priority ordering)
- **PdfGeneratorTest** — 32 tests covering `parse_validation_url_params()` (12 tests: link formats, custom text, target/color attributes, combined params), `generate_filename()` (6 tests: title sanitization, auth code appending, special chars, empty fallback), `generate_default_html()` (6 tests: conditional name/auth code rendering), and `enrich_submission_data()` (8 tests: email/date/ID/magic-token enrichment, no-overwrite behavior)

### Fixed
- 253 → 306 tests, 710 → 812 assertions

---

## 4.12.13 (2026-02-17)

Refactoring: extract focused classes from ReregistrationAdmin (1,125 → 830 lines).

### Changed

- **ReregistrationCsvExporter** — extracted CSV export logic (`handle_export`) into a standalone class with a single static entry point
- **ReregistrationSubmissionActions** — extracted submission workflow handlers (`handle_approve`, `handle_reject`, `handle_return_to_draft`, `handle_bulk`) into a dedicated class
- **ReregistrationCustomFieldsPage** — extracted custom fields admin submenu page rendering into its own class
- ReregistrationAdmin now delegates to the extracted classes via `handle_actions()`, reducing the main class by 26% (1,125 → 830 lines)

---

## 4.12.12 (2026-02-17)

Unit tests for Reregistration module: field options and data processor.

### Added

- **ReregistrationFieldOptionsTest** — 15 tests covering `get_divisao_setor_map()` structure and content, field option providers (`sexo`, `estado_civil`, `sindicato`, `jornada`, `acumulo`, `uf`), UF 2-letter code validation, and `get_default_working_hours()` structure
- **ReregistrationDataProcessorTest** — 19 tests covering `sanitize_working_hours()` (valid/invalid JSON, missing day key, type casting, optional fields) and `validate_submission()` (required fields, CPF/phone validation, division-department consistency, custom field required/format/regex/email validation)

### Fixed

- **AudienceCsvImporterTest** — 5 tests using Mockery alias mocks for `AudienceRepository` now run in separate processes (`@runInSeparateProcess`) to prevent alias contamination of subsequent test classes
- 218 → 253 tests, 453 → 710 assertions

---

## 4.12.11 (2026-02-17)

Unit tests for Audience module: CSV importer and notification handler.

### Added

- **AudienceCsvImporterTest** — 26 tests covering `validate_csv()`, `get_sample_csv()`, `import_members()`, and `import_audiences()` (header normalization, missing columns, empty rows, invalid emails, existing users, duplicate members, parent-before-child creation order, default color fallback)
- **AudienceNotificationHandlerTest** — 10 tests covering `render_template()` variable substitution (user, booking, cancellation, site, and optional keys), subject generation, and default template placeholder completeness

### Fixed

- 182 → 218 tests, 352 → 453 assertions

---

## 4.12.10 (2026-02-17)

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

## 4.12.9 (2026-02-17)

Fix: math captcha showing raw HTML as visible text on cached pages.

### Changed

- Rebuilt all minified JS assets

### Fixed

- **Captcha label raw HTML** — `ffc-dynamic-fragments.js` used `textContent` to set the captcha label which rendered `<span class="required">*</span>` as visible text instead of HTML; separated the required asterisk indicator from the label data and added `<span class="ffc-captcha-label-text">` wrapper so JS targets only the text portion
- **Form processor captcha refresh** — inline captcha generation in `FormProcessor` replaced with `Utils::generate_simple_captcha()` call for consistency

### Security

- All captcha label refreshes now use `.text()`/`textContent` (never `.html()`/`innerHTML`), keeping XSS hardening from v4.12.6

---

## 4.12.8 (2026-02-17)

Refactor Utils (dead code removal) and ReregistrationFrontend (1,330 lines → coordinator + 3 sub-classes).

### Changed

- **ReregistrationFrontend** split into 3 focused sub-classes: `ReregistrationFieldOptions` (field option data), `ReregistrationFormRenderer` (form HTML rendering with per-fieldset methods), `ReregistrationDataProcessor` (data collection, validation, submission processing)
- **ReregistrationFormRenderer** — broke 616-line `render_form()` into 8 focused private methods (`render_personal_data_fieldset`, `render_contacts_fieldset`, `render_schedule_fieldset`, `render_accumulation_fieldset`, `render_union_fieldset`, `render_acknowledgment_fieldset`, `render_custom_fields_fieldset`, `render_custom_field`)

### Removed

- **3 unused public methods** from Utils — `is_local_environment()`, `is_valid_ip()`, `validate_email()` + private `get_disposable_email_domains()` (zero external callers)

---

## 4.12.7 (2026-02-17)

Refactor UserDataRestController (1,415 lines → coordinator + 6 sub-controllers).

### Added

- **UserContextTrait** — shared `resolve_user_context()` and `user_has_capability()` methods extracted into reusable trait used by all sub-controllers

### Changed

- **UserDataRestController** split into 6 focused sub-controllers: `UserCertificatesRestController`, `UserProfileRestController`, `UserAppointmentsRestController`, `UserAudienceRestController`, `UserSummaryRestController`, `UserReregistrationsRestController`
- **UserDataRestController** — now a thin coordinator (155 lines) with backward-compatible delegate methods and lazy-loaded sub-controllers

### Fixed

- **UserDataRestControllerTest** — added `wp_cache_get`/`wp_cache_set` stubs to fix 3 pre-existing RateLimiter errors in change_password and privacy_request tests

---

## 4.12.6 (2026-02-17)

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

## 4.12.5 (2026-02-17)

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

## 4.12.4 (2026-02-17)

Performance and reliability: changelog extraction, ticket hash column, LIKE-on-JSON elimination.

### Added

- **CHANGELOG.md** — full version history extracted from readme.txt into dedicated changelog file; readme.txt now shows only recent versions with pointer to CHANGELOG.md
- **`ticket_hash` column** on submissions table — stores deterministic hash of ticket restriction value for indexed lookups; new composite index `idx_form_ticket_hash` on `(form_id, ticket_hash)`

### Changed

- **Eliminated LIKE on JSON** for ticket lookups — `detect_reprint()` now uses indexed `ticket_hash = %s` instead of `data LIKE '%"ticket":"VALUE"%'` when encryption is configured

### Fixed

- **Ticket reprint detection with encryption** — when data encryption was enabled, the `data` column is NULL so LIKE-based ticket lookup always failed silently; now uses `ticket_hash` for hash-based lookup, falling back to LIKE only for legacy unencrypted data

---

## 4.12.3 (2026-02-17)

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

## 4.12.2 (2026-02-17)

God class refactoring: UserManager and ActivityLog split into single-responsibility classes with full backward compatibility.

### Changed

- **CapabilityManager** — extracted from UserManager; handles all FFC capability constants, role registration, context-based granting, access checks, and per-user capability management
- **UserCreator** — extracted from UserManager; handles get_or_create_user flow, WordPress user creation, orphaned record linking, username generation, metadata sync, and profile creation
- **ActivityLogQuery** — extracted from ActivityLog; handles get_activities, count_activities, get_stats, get_submission_logs, cleanup, and run_cleanup
- **UserManager** — reduced from ~1,150 to ~400 lines; retains profile CRUD and data retrieval methods; delegates capabilities to CapabilityManager and user creation to UserCreator via backward-compatible constant aliases and method wrappers
- **ActivityLog** — reduced from ~800 to ~520 lines; retains core logging, buffer management, and convenience methods; delegates query/stats/cleanup to ActivityLogQuery

---

## 4.12.1 (2026-02-16)

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

## 4.12.0 (2026-02-16)

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

## 4.11.0 (2026-02-15)

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

## 4.9.10 (2026-02-14)

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

## 4.9.9 (2026-02-14)

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

## 4.9.8 (2026-02-14)

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

## 4.9.7 (2026-02-14)

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

## 4.9.6 (2026-02-14)

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

## 4.9.5 (2026-02-14)

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

## 4.9.4 (2026-02-14)

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

## 4.9.3 (2026-02-14)

Capability system refactoring: centralized constants, enforced checks, simplified role model.

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

## 4.9.2 (2026-02-13)

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

## 4.9.1 (2026-02-12)

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

## 4.9.0 (2026-02-12)

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

## 4.8.0 (2026-02-11)

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

## 4.7.0 (2026-02-09)

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

## 4.6.16 (2026-02-08)

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

## 4.6.15 (2026-02-08)

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

## 4.6.14 (2026-02-08)

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

## 4.6.13 (2026-02-08)

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

## 4.6.12 (2026-02-08)

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

## 4.6.11 (2026-02-08)

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

## 4.6.10 (2026-02-08)

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

## 4.6.9 (2026-02-08)

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

## 4.6.8 (2026-02-08)

Refactor: Break down God classes into focused single-responsibility classes.

### Changed

- Extract AppointmentValidator from AppointmentHandler (all validation logic)
- Extract AppointmentAjaxHandler from AppointmentHandler (4 AJAX endpoints)
- Slim AppointmentHandler from 1,027 to 457 lines (core business logic only)
- Extract VerificationResponseRenderer from VerificationHandler (HTML rendering + PDF generation)
- Slim VerificationHandler from 822 to 547 lines (search + verification logic only)
- Wire AppointmentAjaxHandler via Loader using dependency injection

---

## 4.6.7 (2026-02-07)

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

## 4.6.6 (2026-02-07)

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

## 4.6.5 (2026-02-07)

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

## 4.6.4 (2026-02-07)

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

## 4.6.3 (2026-02-07)

Security: Permission audit — add missing capability checks to admin handlers.

### Security

- Added `current_user_can('manage_options')` to SettingsSaveHandler (covers all settings + danger zone)
- Added capability check to migration execution handler
- Added capability check to cache warm/clear actions
- Added capability check to date format preview AJAX handler
- Tightened audience booking REST write permission (requires `ffc_view_audience_bookings` capability)

---

## 4.6.2 (2026-02-07)

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

## 4.6.1 (2026-02-07)

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

## 4.6.0 (2026-02-06)

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

## 4.5.0 (2026-02-05)

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

## 4.4.0 (2026-02-04)

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

## 4.3.0 (2026-02-02)

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

## 4.2.0 (2026-01-30)

CSV export enhancements and calendar translations.

### Added

- Expand `custom_data` and `data_encrypted` JSON fields into individual CSV columns
- Decrypt encrypted data for certificate CSV dynamic columns
- 285 missing pt_BR translations for calendar and appointment system

### Changed

- English language file with all new calendar strings

---

## 4.1.1 (2026-01-27)

Appointment receipts, validation codes, and admin improvements.

### Added

- Appointment receipt and confirmation page generation
- Appointment PDF generator for client-side receipts
- Unique validation codes with formatted display (XXXX-XXXX-XXXX)
- Appointments column in admin users list
- Login-as-user link always visible in users list
- Permission checks to dashboard tabs visibility

---

## 4.1.0 (2026-01-27)

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

## 4.0.0 (2026-01-26)

Breaking release: removal of backward-compatibility aliases and namespace finalization. **First stable tag bump from 2.8.0** since the 2.9.x development cycle began.

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

- **BREAKING:** Removed all backward-compatibility aliases for old `FFC_*` class names
- Removed all obsolete `require_once` statements (autoloader handles loading)

---

## 3.3.1 (2026-01-25)

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

## 3.3.0 (2026-01-25)

Strict types and full type hints.

### Added

- `declare(strict_types=1)` to all PHP files
- Full type hints (parameter types, return types) across all classes
- Affected: Core, Repositories, Migration Strategies, Settings Tabs, User Dashboard, Shortcodes, Security, Generators, Frontend, Integrations, Submissions

---

## 3.2.0 (2026-01-25)

PSR-4 autoloader and namespace migration.

### Added

- PSR-4 autoloader (`class-ffc-autoloader.php`) with namespace-to-directory mapping
- Backward-compatibility aliases for all old `FFC_*` class names (removed in 4.0.0)
- Developer migration guide and hooks documentation

### Changed

- All 88 classes to PHP namespaces in 15 migration steps
- Namespaces: `FreeFormCertificate\Admin`, `API`, `Calendars`, `Core`, `Frontend`, `Generators`, `Integrations`, `Migrations`, `Repositories`, `Security`, `Settings`, `Shortcodes`, `Submissions`, `UserDashboard`

---

## 3.1.0 (2026-01-24)

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

## 3.0.0 (2026-01-20)

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

## 2.10.0 (2026-01-20)

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

## 2.9.1 (2025-12-29)

Activity log, form cache, and magic links fix.

### Added

- Activity Log with `ffc_activity_logs` table for audit trail
- Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
- Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

### Fixed

- Magic Links fatal error (critical bug)
- Duplicate `require` in loader

---

## 2.9.0 (2025-12-28)

QR Code generation on certificates.

### Added

- QR Code generation on certificates linking to verification page
- QR Code generator class using phpqrcode library
- QR Code settings tab with size and error correction configuration

_Note: QR Code work first appeared as experimental code in the 2.5.0 development snapshot, was rolled back, and was resumed and finalized in this release._

---

## 2.8.0 (2025-12-28)

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

## 2.7.0 (2025-12-28)

Modular architecture refactoring.

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

## 2.6.0 (2025-12-28)

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

## 2.5.0 (2025-12-14)

Development snapshot leading up to the 2.6.0 release; **never published as stable**. Reconstructed from forensic source diffs of the `wp-ffcertificate14-12-2025.zip`, `wp-ffcertificate16-12-2025.zip`, and `wp-ffcertificate23-12-2025.zip` snapshots.

### Added

- Foundation work for the modular OOP refactor that was finalized in 2.6.0 — first split of `includes/` into `admin/`, `core/`, `data/`, and `frontend/` subdirectories with dedicated classes (`class-ffc-pdf-generator.php`, `class-ffc-submission-controller.php`, `class-ffc-mailer.php`, `class-ffc-template-engine.php`, `class-ffc-repository.php`).
- Initial QR Code experimentation (3 references in `includes/` source). The work was rolled back in the next snapshot (16/12 → 23/12) and resumed/finalized in 2.9.0.
- `FFC_VERSION` constant for CSS/JS cache busting (developer comment in source: _"Adicionamos FFC_VERSION para controle de cache dos arquivos CSS/JS"_).
- Multiple certificate template HTML files and background images bundled in `html/`.

### Changed

- Internal: Local git workflow adopted at this stage (the 23/12 snapshot includes a `.git` directory).

---

## 2.4.0 (2025-12-13)

### Changed

- Internal improvements

---

## 2.3.0 (2025-12-12)

### Changed

- Internal improvements

---

## 2.2.0 (2025-12-11)

### Changed

- Internal improvements

---

## 2.1.0 (2025-12-10)

### Changed

- Internal improvements

---

## 2.0.0 (2025-12-08)

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

## 1.5.0 (2025-12-05)

Ticket system and form cloning.

### Added

- Ticket system with single-use codes for exclusive form access
- Form cloning (duplication) functionality
- Global settings tab with automatic log cleanup configuration
- Denylist for blocking specific IDs

---

## 1.0.7 (~2025-12-01)

_Reconstructed from forensic source diff of `wp-ffcertificate_12_12_2025.zip`. That snapshot was archived on 2025-12-12 and carried `Version: 1.0.7` in the plugin header — but the 1.0.x patch series logically released **between 1.0.0 (2025-11-25) and 1.5.0 (2025-12-05)**, so the snapshot date is later than the actual release date. The snapshot date (~2025-12-12) is the latest verifiable touchpoint of the 1.0.x line; the release date itself is approximated as ~2025-12-01 to keep the file in chronologically descending order. The 1.0.x patch series was not separately documented in the developer's own changelog inside the 4.0.0 zip; this entry is reconstructed solely from the snapshot's plugin header and file listing._

### Changed

- Maintenance patch series leading from 1.0.0 to 1.5.0; specific change details are unrecoverable from the available evidence.
- Plugin header version stamped at `1.0.7`; no `FFC_VERSION` constant yet (the constant was introduced during the 2.5.0 development cycle).
- Snapshot file inventory: 23 files (`assets/`, `ffc.pot`, `html/`, `includes/`, `readme.txt`, `wp-ffcertificate.php`) — pre-modular monolithic structure.

---

## 1.0.0 (2025-11-25)

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