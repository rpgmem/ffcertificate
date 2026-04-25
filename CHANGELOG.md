# Changelog

All notable changes to the **Free Form Certificate** plugin are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## 5.4.1 (2026-04-24)

Certificate HTML editor gains CodeMirror syntax highlighting with distinct
coloring for HTML tags and `{{placeholder}}` tokens, plus a three-option
`Code Editor Theme` setting (Auto / Light / Dark, dark by default on fresh
installs) with a VS-Code-Dark+-inspired palette; the email body moves to a
lightweight visual editor (`wp_editor()` teeny); the global TinyMCE
placeholder-protection filter is scoped to the plugin's post type so it no
longer touches unrelated admin screens; a new per-calendar admin-bypass
toggle replaces the hardcoded all-or-nothing bypass for self-scheduling;
and the `[ffc_verification]` result card header stops rendering with the
admin preview modal's dark slate background.

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
- **CHANGELOG content enrichment.** Replaced the placeholder `## 2.5.0 — Internal improvements` with reconstructed content describing the modular OOP refactor groundwork, the QR Code experimentation that was rolled back and later resumed in 2.9.0, and the introduction of the `FFC_VERSION` constant. Added a new `## 2.9.x development cycle (2026-01-03 → 2026-01-14)` section documenting the dev-only versions 2.9.16–2.9.19 (header) where the Data Encryption framework, REST API controller, repository pattern, rate limiter UI, and hooks documentation were first introduced — the Stable tag deliberately remained at 2.8.0 throughout the cycle. Added explanatory notes to the 2.9.0 entry (QR Code provenance), 3.1.0 entry (development version, not stable), and 4.0.0 entry (encryption framework finalization). Forensic-derived entries are flagged with their source snapshots so future readers can audit the reconstruction.
- **`readme.txt` trimmed to the last three releases.** The end-user-facing changelog inside `readme.txt` previously duplicated dozens of pre-5.0 entries verbatim from `CHANGELOG.md`. It now retains only the three most recent releases (5.4.1, 5.4.0, 5.3.0) in detail and points readers to `CHANGELOG.md` for the full history. This keeps the WordPress.org plugin page focused on what's new while preserving the complete record in the repo's `CHANGELOG.md`.
- **Forensic 1.0.7 entry added.** A `## 1.0.7 (~2025-12-12)` entry was inserted between 1.0.0 and 1.5.0, reconstructed from the `wp-ffcertificate_12_12_2025.zip` snapshot. The 1.0.x patch series between 1.0.0 and 1.5.0 had not been documented in the developer's own changelog inside the 4.0.0 zip; this entry fills the gap with the verifiable evidence available (header version, file inventory) and is explicitly flagged as forensic.
- **Claude / AI-assistance attribution.** Added a footnote at the end of the `## 3.0.0 (2026-01-20)` entry citing commit [`53cc4fa`](https://github.com/rpgmem/ffcertificate/commit/53cc4fa4063bb497f5948d79897c022c5c0494e2) (2026-01-17) as the first AI-assisted contribution to the project — the geolocation and date/time restrictions system. The note covers all subsequent commits and releases by default, with the convention that any future entry may explicitly disclaim AI involvement when applicable. A new `AI-assisted contributions` section in `CONTRIBUTING.md` documents the workflow conventions (session-URL footers, `claude/<…>` branch prefix) and the same starting reference for human contributors auditing the repo.
- **Version-heading format normalized for the latest two releases.** The `## [5.4.1] - 2026-04-24` and `## [5.4.0] - 2026-04-23` headings (the only two using the bracketed Keep-a-Changelog hyphen-date style in the file) were rewritten to `## 5.4.1 (2026-04-24)` and `## 5.4.0 (2026-04-23)` to match the format used by the other ~88 version entries.

---

## 5.4.0 (2026-04-23)

Encryption and privacy hardening across the user-data surface (centralized
sensitive-field policy, payload-driven activity log encryption, auditable
decrypt failures, no-leak dual-storage fix), plus the accumulated security
audit (Tier 1 + Tier 2), CSV download intermediate screen, and a
performance pass for admin submissions at scale.

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

### New Features

- Feat: **Full-page cache compatibility** — forms and calendars now work correctly with LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache. Self-scheduling shortcodes with business-hours restrictions send `DONOTCACHEPAGE` + `nocache_headers()` to prevent stale "closed" messages. Audience shortcodes for logged-in users prevent cached cross-user content leakage (#37)
- Feat: **Dynamic Fragments geofence refresh** — the AJAX endpoint now accepts `form_ids[]` and returns fresh geofence date/time configs, so cached pages always display up-to-date availability windows after admin changes (#37)
- Feat: **Automatic cache purge on save** — `FormCache::purge_page_cache()` finds pages embedding a saved form or calendar and purges them from LiteSpeed, WP Rocket, W3TC, and WP Super Cache. Called on both `save_post_ffc_form` and `save_post_ffc_self_scheduling` (#37)
- Feat: **CSV Download Page URL setting** — new field on the General settings tab for configuring the public CSV download page URL (#34)
- Feat: **Search forms by ID** — the admin forms list table (`edit.php?post_type=ffc_form`) now supports searching by numeric post ID (#39)

### Bug Fixes

- Fix: **Same captcha on all forms** — when multiple forms exist on a cached page, Dynamic Fragments now generates a unique math captcha per form instead of applying a single captcha to all forms (#38)
- Fix: **PHPUnit test failures** — added missing mocks for `nocache_headers()` and `get_posts()` in `AudienceShortcodeTest` and `FormCacheTest` after cache compatibility changes (#39)
- Fix: **Minified assets out of sync** — regenerated `ffc-dynamic-fragments.min.js` with `--source-map` to match the `npm run build` output (#39)

### Refactoring

- Refactor: **CustomFieldValidator extraction** — validation logic extracted from `CustomFieldRepository` into a dedicated `CustomFieldValidator` class for single-responsibility and testability (#35)
- Refactor: **In-plugin documentation expansion** — expanded the Documentation settings tab with additional sections covering all shortcodes, settings, and features (#35)

### Infrastructure

- CI: Remove duplicate `push: main` trigger from CI and Assets workflows — each PR merge no longer runs the full suite twice (#39)
- CI: Extract reusable composite action `.github/actions/setup-composer` for PHP + Composer setup (#30, #31)
- CI: Remove CodeQL workflow (not applicable to PHP plugin) (#30)
- CI: Add Dependabot auto-merge for patch and minor dependency updates (#29)
- CI: Promote PHPCS from advisory to gating — PRs must pass WPCS on changed files (#28)
- CI: Promote PHPStan from level 6 to **level 7** (#24)
- CI: Re-introduce coverage with pcov, scoped to `includes/`, uploaded to Coveralls (#22)

### Code Quality

- Chore: Auto-fix ~83k PHPCS violations via PHPCBF (#25)
- Chore: Annotate 223 PreparedSQL + NonceVerification false positives (#26)
- Chore: Phase 3 PHPCS mechanical fixes + PSR-4 suppressions (#27)
- Chore: Resolve remaining WPCS errors in cache-related files (file docblocks, class docblocks, short ternary operators, missing `@param` tags) (#36)

---

## 5.2.0 (2026-04-15)

Raise minimum PHP requirement from 7.4 to 8.1. PHP 7.4 reached end-of-life on 2022-11-28 and PHP 8.0 on 2023-11-26; both are unsupported. The previous lockfile was also resolving `doctrine/instantiator` 2.1.0 — which requires PHP 8.4 — silently breaking `composer install` on PHP 7.4/8.1/8.3 runners.

### New Features

- Feat: **Named Geofence Locations** — new `GeofenceLocationRegistry` CRUD class stores reusable geofence locations as a WordPress option (`ffc_geofence_locations`), each with name, lat/lng, radius, and per-location default GPS / default IP flags (mutually exclusive). Replaces the legacy "Default Geofencing Areas" textarea on the Geolocation settings tab with a full CRUD table (add, edit, delete with nonce-protected actions)
- Feat: Form editor geofence metabox now offers a **radio toggle** (Registered Locations / Custom Coordinates) for both GPS and IP area sources, with a **multi-select dropdown** when "Registered Locations" is selected. Auto-draft forms pre-select the default GPS/IP locations from the registry
- Feat: `Geofence::resolve_areas_text()` helper transparently resolves location IDs to coordinate text at runtime — existing forms with `geo_area_source = 'custom'` (or missing key) continue to work without migration
- Feat: **CSV Downloads column** on the forms list table — shows the public download count (with quota when set) for forms with CSV download enabled

### Bug Fixes

- Fix: **Geofence frontend config flags always false** — `get_frontend_config()` compared already-cast PHP booleans against the string `'1'` (`'1' === true` is always `false` in strict comparison), causing the JS frontend to never enforce datetime or geolocation restrictions. Backend validation was unaffected. Now compares boolean values directly
- Fix: **Submission count link** on forms list — used `form_id` instead of `filter_form_id`, so clicking the count did not filter the submissions list

### Test Coverage

- New: **GeofenceLocationRegistryTest** — 24 tests covering `get_all()`, `get_by_id()`, `get_by_ids()`, `save()` (including default flag mutual exclusivity), sanitization (lat/lng clamping, negative values, radius default, name truncation), `delete()`, `get_default_gps()`/`get_default_ip()`, and `resolve_to_areas_text()`
- New: **GeofenceDatetimeValidationTest** — 12 tests covering daily + span mode datetime validation, all branch paths including edge cases
- New: **GeofenceGeolocationTest** — 17 tests covering `parse_areas`, `validate_geolocation` with IP fallback scenarios, `has_form_expired_by_days`
- New: **GeofenceFrontendConfigTest** — 10 tests covering `get_form_config` boolean casting, `get_frontend_config` with admin bypass / partial bypass / regular user
- New: **LoaderTest** — 6 tests covering constructor hook registration, frontend asset registration and localization
- Updated: **TabGeolocationTest** — replaced `test_save_settings_saves_main_geo_areas_to_ffc_settings` with `test_save_settings_calls_save_locations` for new registry-based save behavior
- Test suite: **3154 → 3234 tests** (+80) with all 7646 assertions green

### Breaking Changes

- Breaking: Minimum PHP bumped from **7.4 → 8.1**. Update your server before upgrading. `Plugin Name` header, `FFC_MIN_PHP_VERSION`, `composer.json#require.php` and `readme.txt#Requires PHP` all updated.

### Chore

- Chore: `composer.json#config.platform.php` pinned to `"8.1"` so the lockfile resolves to versions compatible with the declared minimum regardless of the developer's local PHP version.
- Chore: `composer.lock` regenerated under PHP 8.1 platform; `doctrine/instantiator` now resolves to `2.0.0` (compatible with PHP ^8.1) instead of `2.1.0` (which required PHP ^8.4).
- Chore: CI matrix now covers PHP **8.1, 8.2, 8.3, 8.4** (added 8.2, removed 7.4).

### Code Quality

- Chore: Zero PHPStan level 6 errors — cleared 4 findings exposed by newer `php-stubs/wordpress-stubs` (v6.9.1) and `szepeviktor/phpstan-wordpress` (v2.0.3):
  - `AdminActivityLogPage::output_csv()` PHPDoc: `array<int, array<string, mixed>> $rows` → `array<int, array<array-key, mixed>> $rows` (the method iterates and passes values directly to `fputcsv()` without accessing keys by name; the caller builds rows with positional int keys).
  - `UserAudienceRestController::build_joinable_node()` PHPDoc: added `array<string, mixed>` value type to `@param $node` and `@return`.
  - `ReregistrationAdmin` details markup: removed dead `|| $formatted === null` branch — `FichaGenerator::format_field_value()` returns a non-nullable `string`.

---

## 5.1.0 (2026-04-11)

Public CSV download feature: form organizers without WordPress admin access can now retrieve the submissions CSV of a specific form via a revocable per-form hash, gated by form expiration and a configurable download quota. No new dependencies and no schema changes.

### New Features

- Feat: New `[ffc_csv_download]` shortcode — public page where visitors enter a form ID + hash and receive the submissions CSV as a direct download
- Feat: New `PublicCsvDownload` handler on `admin-post(_nopriv)_ffc_public_csv_download` — validates nonce, honeypot, CAPTCHA, per-IP rate limit, form-level enable flag, hash equality, geofence expiration, and per-form download quota before streaming the file
- Feat: New `PublicCsvExporter` with AJAX batched 3-step export (start → batch ×N → download) matching the admin `CsvExporter` architecture — prevents memory exhaustion and webserver timeouts on large datasets; column layout mirrors the admin export so both downloads are interchangeable. Synchronous streaming preserved as a no-JS fallback
- Feat: New `Geofence::get_form_end_timestamp()` and `Geofence::has_form_expired()` helpers — the public CSV download is only released after the form's configured end date/time
- Feat: New "Public CSV Download" metabox on the form editor — toggle, read-only hash with regenerate control, download counter, reset button, per-form quota override, and a ready-to-share URL preview
- Feat: Advanced settings tab now exposes `public_csv_default_limit` — default quota suggested to the admin when enabling the feature on a new form (default: 1)
- Feat: Counter is incremented *before* the stream starts to prevent race conditions between concurrent requests

### Security

- Security: Download hash stored in a dedicated post meta (`_ffc_csv_public_hash`), generated with `bin2hex(random_bytes(16))` and compared via `hash_equals()` to mitigate timing attacks
- Security: Reuses `Shortcodes::generate_security_fields()` so the public form includes the same honeypot (`ffc_honeypot_trap`) and mathematical CAPTCHA already validated by `SecurityService::validate_security_fields()`
- Security: Per-IP rate limiting via `RateLimiter::check_ip_limit()`, identical to the public form submission path
- Security: `get_post_type()` check blocks the handler from serving data for non-`ffc_form` posts even if a valid hash is supplied
- Security: Empty stored hash short-circuits the comparison — prevents `hash_equals('', '')` from accepting any request before the admin has generated a hash
- Security: AJAX batch jobs scoped by `sha1(IP)` — subsequent batch/download requests verify the caller's IP matches the IP that started the job. Combined with UUID v4 job IDs (122 bits of entropy) this prevents cross-visitor job hijacking

### Test Coverage

- New: **GeofenceFormExpirationTest** — 12 tests covering `get_form_end_timestamp()` (null on empty/invalid meta, trims whitespace, defaults `time_end` to `23:59:59`, respects `wp_timezone()`) and `has_form_expired()` (past vs. future, end-of-day default)
- New: **PublicCsvExporterTest** — 15 tests locking the CSV column layout (15 fixed + 3 edit + N dynamic), fixed-column value mapping, consent yes/no rendering, deleted-form placeholder, dynamic key ordering, RF-only rows, batch-size constants, and JOB_TTL
- New: **PublicCsvDownloadTest** — 28 tests covering constants, shortcode rendering (nonce, form fields, honeypot, CAPTCHA, URL prefill, flash messages), the 12 failure branches of the validation flow, the happy-path counter-increment observable effect, 8 direct `validate_form_access()` unit tests, and AJAX hook registration verification
- Test suite: **3090 → 3154 tests** (+64) with all 7415 assertions green

### Code Quality

- Chore: Zero PHPStan level 6 errors — cleared 26 pre-existing static analysis findings across 13 files (`CsvExporter`, `QrcodeGenerator`, reregistration module, self-scheduling handler, URL shortener module, user dashboard module, PHPStan stubs)
- Chore: Add array shape PHPDoc (`array<int, string>`, `array<string, mixed>`, `array{items: ..., total: int}`) to `CsvExporter` private helpers, `ReregistrationStandardFieldsSeeder::on_audience_created()`, `UrlShortenerRepository::findPaginated()`, `UrlShortenerService::get_stats()` and `UserManager::get_user_identifiers_masked()`
- Chore: Remove dead code flagged by PHPStan — duplicated `wp_doing_ajax()` early-return in `AccessControl::block_wp_admin()`; redundant `!== ''` / `!== null` / `!== '0'` checks in the reregistration module; `|| $success` tail in `UserManager::save_profile_data()`; empty-guard around the always-populated `$where_clauses` in `UrlShortenerRepository::findPaginated()`; redundant `$temp_file === ''` check in `QrcodeGenerator::generate()`
- Chore: Remove redundant `?? ''` fallbacks on `Encryption::decrypt_field()` calls in `CsvExporter::format_csv_row()` — the method returns a non-nullable string (same fix already applied to `PublicCsvExporter`)
- Chore: Pre-initialize `$calendar = null;` in `AppointmentAjaxHandler::create()` alongside `$pdf_data` / `$appointment` — fixes `variable.undefined` when `findById()` throws before the assignment
- Chore: Add `QRcode::raw()` to `phpstan-stubs.php` — the SVG QR generator already calls it in production, only the static-analysis stub was missing
- Chore: Fix `@return` PHPDoc parse error in `UserManager::get_user_identifiers_masked()` (`string[}}` → `array<int, string>`)
- Chore: Correct `wp_validate_redirect()` fallback argument type in `UrlShortenerLoader` (`false` → `''`) to match the WordPress stub signature

### Polish

- UX: `[ffc_csv_download]` now reuses the same CSS classes as `[ffc_verification]` (`ffc-verification-container`, `ffc-verification-header`, `ffc-verification-form`, `ffc-form-field`, `ffc-input`, `ffc-submit-btn`, `ffc-verify-error` / `ffc-verify-success`) so the public download page inherits the card layout, dark-mode support and focus ring already used by the verification page — no more inline `<style>` block
- UX: Progress bar overlay with real batch-by-batch feedback on the `[ffc_csv_download]` page — shows record count, progress percentage, and status messages throughout the export. Minimum 1.5 s display threshold prevents the overlay from flashing on small exports. Graceful degradation: when JavaScript is unavailable the form falls back to the synchronous `admin-post.php` handler
- UX: `ffc-frontend.css` is now auto-enqueued on pages containing the `[ffc_csv_download]` shortcode (matching how `[ffc_form]` / `[ffc_verification]` already trigger the stylesheet); new `ffc-csv-download.js` is enqueued only on CSV download pages
- Docs: Added a `[ffc_csv_download]` row to the Shortcodes table in the Documentation tab (`ffc-settings&tab=documentation`) describing the Form ID + hash workflow, the expiration/quota gating, and the optional `title` attribute

### Maintenance Tools

- Feat: New **"Obsolete Shortcode Cleanup"** section on the Data Migrations tab (`ffc_form&page=ffc-settings&tab=migrations`) — scans published posts, pages and reusable blocks (`wp_block`) for embedded `[ffc_form id="..."]` shortcodes pointing at forms whose end date is more than `N` days in the past, and removes those obsolete shortcodes from `post_content`. Configurable grace window (default: **90 days**, clamp 1-3650), admin-only (`manage_options`), nonce-protected
- Feat: New `ObsoleteShortcodeCleaner` service (`includes/migrations/class-ffc-obsolete-shortcode-cleaner.php`) with `find_expired_form_ids()`, `scan_posts_for_expired_forms()`, `extract_form_ids()`, `strip_shortcodes_from_content()`, `remove_shortcodes_from_post()` and a `run($days, ['dry_run' => bool])` pipeline. Handles both Classic editor `[ffc_form id="N"]` and Gutenberg block-wrapped `<!-- wp:shortcode -->[ffc_form id="N"]<!-- /wp:shortcode -->` formats
- Feat: New `Geofence::has_form_expired_by_days(int $form_id, int $days)` helper that reuses `get_form_end_timestamp()` as the single source of truth for "form is over"
- Feat: Cleanup UI enforces a **dry-run → apply** flow — the "Remove shortcodes now" button is disabled until a preview transient has been recorded in the last 5 minutes, preventing blind destructive runs
- Feat: Cleanup report shows grace window, expired forms, posts scanned, posts affected and shortcodes removed, plus a table of the first **50** affected posts with edit links and removal counts (and a "… and N more" indicator when truncated)
- Feat: New `obsolete_shortcode_days` option (default 90) persisted in `ffc_settings` with its own nonce-protected form directly in the cleanup card
- Safety: `wp_update_post()` automatically creates WordPress revisions for modified `post` / `page` entries, giving admins a manual rollback path. Only `[ffc_form]` shortcodes pointing at expired IDs are removed — the rest of the content is left untouched
- Test: New **ObsoleteShortcodeCleanerTest** — 19 tests covering regex quote styles (`id="N"`, `id='N'`, `id=N`), extra-attribute handling, classic + Gutenberg + mixed removal, dry-run vs apply pipelines, report truncation at `REPORT_LIMIT`, empty-result short-circuits, and `wp_update_post()` no-op skipping

### Reregistration — Dynamic Fields System

- Feat: **Unified dynamic field system** — all ~30 historical "standard" fields (personal data, contacts, work schedule, accumulation, union) and admin-created custom fields now read from a single source (`wp_ffc_custom_fields`). Admins can relabel, reorder, regroup, deactivate, and delete any field without touching PHP
- Feat: Schema upgrades via new `MigrationDynamicReregFields` strategy — adds `field_group`, `field_source`, `field_profile_key`, `field_mask`, `is_sensitive` columns to `wp_ffc_custom_fields` + `auth_code`, `magic_token` columns to `wp_ffc_reregistration_submissions` with matching indexes
- Feat: **Submission details modal** — new "View Details" button in the reregistration submissions list opens a modal that renders all fields grouped by `field_group`, using labels and types from `wp_ffc_custom_fields`. Sensitive values (CPF/RF/RG) decrypted server-side. `FichaGenerator` internal helpers promoted to public API
- Security: CPF, RF and RG are now encrypted at rest (AES-256-CBC via the existing `Encryption` class) in both the submission JSON and in `usermeta`. Decryption is transparent in form renderer, PDF generator, CSV exporter, and verification handler

### Security Hardening

- Security: Refactor `AppointmentRepository::findByUserId()` and `getStatistics()` to use single `wpdb->prepare()` calls instead of nesting prepared fragments (avoids placeholder re-processing)
- Security: Replace direct ID interpolation in `AbstractRepository::findByIds()` with proper `%d` placeholders via `array_fill` + spread operator
- Security: Add `is_uploaded_file()` validation in `AudienceAdminImport` for both member and audience CSV uploads (prevents path traversal via crafted `tmp_name`)
- Security: Sanitize `Content-Disposition` filenames across all 6 CSV exporters — strip CR/LF/quote characters and wrap in double quotes (CRLF injection prevention per RFC 6266)
- Security: Centralize honeypot+captcha via `SecurityService` in verification handler; add honeypot field to reregistration form (defense-in-depth)
- Security: Add SRI hash for jQuery UI CDN stylesheet
- Security: Add rate limiting to certificate verification REST endpoint
- Security: Add `X-RateLimit-Limit` / `X-RateLimit-Remaining` headers to REST API responses

### CI/CD

- Feat: Add GitHub Actions CI workflow (`ci.yml`) — PHPStan + PHPUnit matrix
- Feat: Add asset build verification workflow (`assets.yml`) for PRs
- Feat: Add source maps to CSS/JS build scripts; add `.map` files to `.gitignore`

### CSS Refactoring

- Refactor: Replace inline `<style>` blocks in form-list-columns, audience-admin-page, self-scheduling editor, URL shortener admin, and reregistration custom fields with dedicated CSS classes and `wp_add_inline_style()`

### Cache Compatibility

- Feat: Add `DONOTCACHEPAGE` constant to dashboard cache exclusion
- Feat: Detect WP Rocket / W3TC / WP Super Cache in cache settings diagnostic
- Feat: Add cache compatibility FAQ to `readme.txt`

### Accessibility

- A11y: Add `aria-label` to certificate and booking forms
- A11y: Add `aria-describedby` to ticket field

### Additional Features

- Feat: Activity log CSV export with filter support

### Mobile Compatibility

- Fix: iOS Safari PDF download — use `pdf.output('bloburl')` + `window.open()` instead of `pdf.save()` which relies on `blob:` URLs unsupported since iOS 13.3
- Fix: Blank canvas detection — alert user and abort instead of silently generating an empty PDF
- Fix: Mobile memory — reduce html2canvas scale from 2x to 1.5x on mobile to prevent memory exhaustion on low-end phones
- Fix: Platform-specific success messages — "Check Downloads" on Android, "Tap share icon" on iOS
- Fix: Wrap `localStorage` read/write in try/catch for Safari private mode (quota is 0)
- Fix: Default to Safari-specific guidance when geofence `error.code` is 0 or undefined (some iOS versions)

### Migrations Tab UI

- Fix: Standardize all migration card icons to use `ffc-icon-*` CSS pattern with consistent alignment
- Fix: Cleanup card markup and dashicon alignment issues across migration cards (5 incremental fixes)
- Fix: Obsolete shortcode cleanup `save_days` form — nonce and trigger param now sent via URL query string instead of hidden POST fields that were not reaching the handler

### Audience Scheduling — 3-Level Hierarchy

- Feat: **3-level audience hierarchy** — audiences now support parent / child / grandchild structure. `get_hierarchical()` rewritten to fetch all audiences in a single query and build the tree in PHP; `get_descendant_ids()`, `get_ancestor_ids()`, `get_possible_parents()` and `get_ancestors()` added for recursive traversal
- Feat: Admin audience form updated — parent dropdown shows all eligible parents with indented display (`— name`), breadcrumb shows full ancestor chain, circular reference prevention excludes self and descendants from parent options
- Feat: CSV import updated — iterative multi-pass algorithm (up to 4 passes) creates audiences whose parents already exist, deferring the rest to the next pass. Sample CSV updated to include 3rd-level example
- Feat: Frontend calendar audience select (`populateAudienceSelect`) uses recursive `appendNodes(nodes, depth)` with indented names; auto-selection helpers (`getAllDescendantIds`, `collectParentNodes`) and `collapseParentAudiences` made recursive
- Feat: Shortcode `audience_to_array()` shared method recursively maps all hierarchy levels for the frontend JSON payload

### Audience Scheduling — Isolated Calendar Mode

- Feat: **Isolated calendar mode** — new "Ignore conflicts from other calendars" checkbox on the calendar edit form. When enabled, audience same-day and user overlap conflict checks only consider bookings within that calendar; environment conflicts remain per-environment regardless
- Feat: New `is_isolated` column (`tinyint(1) DEFAULT 0`) on `ffc_audience_schedules` table, added via `add_column_if_missing()` migration pattern
- Feat: `get_user_conflicts()` and `get_audience_same_day_bookings()` accept optional `$scope_schedule_id` parameter — when set, adds `INNER JOIN` on environments table to filter conflicts to the given schedule only
- Feat: REST controller resolves `schedule_id` from the selected environment and passes it to conflict checks when the schedule is isolated

### Audience Scheduling — Conflict Behavior

- Change: **Audience same-day conflict downgraded from hard block to soft warning** — booking the same audience group on the same day now shows a dismissible warning with existing booking details instead of blocking entirely. Users can acknowledge and proceed, matching the behavior of user overlap conflicts

### Audience Scheduling — User Dashboard

- Feat: **3rd-level audiences in user profile** — `get_joinable_groups()` API rewritten from 2-query parent+children approach to single-query tree builder, matching `get_hierarchical()` pattern. `renderJoinableGroups()` in `ffc-user-dashboard.js` made recursive
- Feat: **Accordion on audience group selection** — parent and sub-parent headers toggle their children on click, starting collapsed with a `+` icon that switches to `−` when expanded. Uses `aria-expanded` for accessibility. Leaf-only nodes render without accordion
- Fix: Color dot (`.ffc-audience-dot`) was invisible on subgroup headers — selector was scoped to `.ffc-audience-join-item` only; broadened to apply globally
- Fix: Subgroup header rows without join/leave buttons had inconsistent height — added `min-height: 44px` to both item and subgroup header rows
- Feat: **"Leave all groups" button** — new button in profile actions bar (next to "Change Password") allows users to leave all self-joinable groups at once. Styled with red danger color, shows confirmation dialog with group count, and calls new `POST /user/audience-group/leave-all` endpoint. Button only appears when the user belongs to at least one group
- CSS: Nested subgroup styling with indented padding for 3rd-level items and headers

### Bug Fixes

- Fix: Booking "Create" button stuck on loading text ("Verificando...") after consecutive bookings — `openBookingModal()` reset `disabled` state but not the button text; now also restores `ffcAudience.strings.createBooking`
- Fix: Frontend calendar did not display 3rd-level audiences — minified JS (`ffc-audience.min.js`) was stale and still contained the old 2-level rendering logic

### Test Coverage

- Test: Updated 6 test mocks in **AudienceRepositoryTest** for recursive operations — `test_get_hierarchical`, `test_get_members_includes_children`, `test_get_user_audiences_includes_parents`, `test_get_user_audiences_does_not_duplicate`, `test_get_member_count_includes_children`, and `test_cascade_self_join` now properly mock multi-level `get_results`/`get_row` calls

---

## 5.0.3 (2026-03-27)

Performance optimizations for URL shortener and QR code generation, new admin columns for forms listing, and Safari/iOS geofence fixes.

### Performance

- Perf: Cache plugin settings in UrlShortenerService — single `get_option` per request instead of ~7 repeated calls
- Perf: Defer redirect click count increment to `shutdown` hook — redirect response is sent before the DB update
- Perf: Remove unnecessary cache invalidation from `incrementClickCount` (click_count not needed for redirect resolution)
- Perf: Add `qr_cache` column to `ffc_short_urls` table — QR code base64 stored in DB, avoids phpqrcode + GD regeneration on every admin page load
- Perf: Rewrite SVG QR generation to use `QRcode::raw()` matrix directly — eliminates temp file I/O, PNG generation, GD image loading, and pixel-by-pixel color scanning

### New Features

- Feat: Add ID column (sortable) to ffc_form listing screen for quick form reference
- Feat: Add Shortcode column with copy-to-clipboard button to ffc_form listing screen
- Feat: Add Submissions column with batch-loaded count (single GROUP BY query, no N+1) linking to filtered submissions page

### Bug Fixes

- Fix: `isSafari()` detection for iPadOS 13+ — modern iPads report Mac desktop user-agent, now detected via `navigator.maxTouchPoints`
- Fix: Geofence loading spinner stuck indefinitely when Safari silently ignores geolocation request — added safety timeout (40s Safari / 25s others) with `gps_fallback` honoring
- Fix: `maximumAge: 0` on first geolocation attempt forces fresh GPS fix causing unnecessary 20s timeout on Safari — now uses `maximumAge: 30000` to accept recent cached position
- Fix: `gps_fallback` admin setting (`allow`/`block`) not passed to frontend — GPS failure always blocked the form regardless of admin configuration
- Fix: Safari-specific error messages (Location Services guidance) overridden by generic admin `messageError` — browser-specific messages now always take priority
- Fix: Geofence `handleBlocked` signature simplified to 3 arguments, preventing `customMessage` from silently swallowing specific error messages

### Enhancements

- UX: Progressive loading messages for Safari/iOS geolocation wait — three timed phases replace the static message so users know the page is alive and receive increasingly specific guidance (t=0s: tap Allow, t=8s: check for prompt, t=20s: check Location Services settings)
- UX: `updateLoadingMessage()` helper updates text in-place without removing/re-adding the spinner element

---

## 5.0.2 (2026-03-03)

100% unit test coverage across all 21 modules (146 concrete classes), plus bug fixes, new features, and CSS/asset refactoring.

### Bug Fixes

- Fix: AJAX requests blocked for non-admin users — permission check was too restrictive
- Fix: Certificate download bypassing verification page with direct AJAX
- Fix: Download for regular users — migrated from admin-ajax to REST API
- Fix: Reregistration 'Editar' button not working in dashboard
- Fix: QR codes encoding permalink instead of short URL, and click counter issues
- Fix: Short URL redirects not working when rewrite rules fail
- Fix: QR code stacking/visibility issues in html2canvas PDF rendering
- Fix: Lazy-loading plugins hiding QR code in appointment PDF
- Fix: QR code generation and center download button
- Fix: URL Shortener SVG QR generation — use `wp_tempnam` and add filesize check
- Fix: Self-scheduling overlay translation, email notice, and PDF error handling
- Fix: Reverted PDF button to magic link (undo direct download approach)

### New Features

- Feat: Multi-audience transfer list for reregistration campaigns
- Feat: URL Shortener documentation added to the Documentation tab
- Feat: Logout button added to Profile tab in user dashboard
- Feat: Standardized magic link URL format across all document types

### Refactoring

- Refactor: Footer refactored to two-column layout with QR code on the left
- Refactor: Extracted inline CSS/JS to dedicated asset files
- Refactor: Replaced inline styles with CSS classes in URL Shortener
- Refactor: Consolidated duplicate button/badge classes into `ffc-common.css`
- Refactor: Removed 27 unnecessary `!important` declarations
- Refactor: Removed unused CSS classes and stale Phase 3 comments

### Test Coverage — 100% Class Coverage

- New: **AdminClassTest** — 9 tests covering constructor, register_admin_menu, configure_tinymce_placeholders, handle_submission_actions, handle_csv_export, handle_migration_action, handle_submission_edit_save
- New: **FormEditorTest** — 11 tests covering constructor, enqueue_scripts (wrong hook, wrong post type, correct context), add_custom_metaboxes, ajax_generate_codes (no permission, success), ajax_load_template (no permission, empty filename, missing file, success)
- New: **FormEditorMetaboxRendererTest** — 5 tests covering render_shortcode_metabox, render_box_layout, render_box_builder, render_box_restriction, render_field_row
- New: **AdminSubmissionEditPageTest** — 4 tests covering constructor, render (no permission, submission not found), handle_save without POST
- New: **SubmissionsListTest** — 3 tests covering constructor, get_columns, no_items (with WP_List_Table stub)
- New: **FrontendTest** — 7 tests covering constructor, frontend_assets early returns (no post, non-WP_Post, no shortcodes), asset enqueuing with ffc_form/ffc_verification shortcodes, geofence config localization
- New: **FrontendShortcodesTest** — 14 tests covering captcha data, security fields, verification page (with/without token), form rendering (invalid ID, no fields, zero ID, full render, password/ticket restrictions, geofence class, select/radio/hidden/info/embed fields, cpf_rf as tel)
- New: **VerificationResponseRendererTest** — 14 tests covering field labels, field value formatting, appointment verification, reregistration verification, certificate verification, PDF generation
- New: **ReregistrationAdminTest** — 14 tests covering init, add_menu, enqueue_assets, render_page, handle_actions, ajax_generate_ficha, ajax_count_members
- New: **ReregistrationCsvExporterTest** — 5 tests covering early returns for missing/wrong action, missing id, invalid nonce, rereg not found
- New: **ReregistrationCustomFieldsPageTest** — 3 tests covering permission denied, empty audiences, audiences with field counts
- New: **ReregistrationFormRendererTest** — 3 tests covering basic render, draft population, deadline display
- New: **AppointmentReceiptHandlerTest** — 5 tests covering add_query_vars, handle_receipt_request (no query var, invalid ID), get_receipt_url (with/without token)
- New: **SelfSchedulingAdminTest** — 7 tests covering constructor, add_submenu_pages, enqueue_admin_assets (no screen, wrong screen, correct screen, appointments page), render_appointments_page (no permission)
- New: **SelfSchedulingCPTTest** — 8 tests covering constructor, register_calendar_cpt, add_duplicate_link (wrong type, no permission, success), handle_calendar_duplication (no permission), sync_calendar_data (autosave skip), cleanup_calendar_data (wrong type)
- New: **SelfSchedulingEditorTest** — 13 tests covering constructor, enqueue_scripts (wrong hook, wrong post type, no screen, correct context), add_custom_metaboxes, render_box_config, render_box_hours, render_box_rules, render_box_email, render_shortcode_metabox (published/draft), display_save_errors
- New: **SelfSchedulingShortcodeTest** — 6 tests covering constructor, render_calendar (no ID, calendar not found), enqueue_assets (not singular, no post, no shortcode)
- New: **AudienceAdminAudienceTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- New: **AudienceAdminBookingsTest** — 2 tests covering constructor, render_page (empty bookings list)
- New: **AudienceAdminCalendarTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- New: **AudienceAdminDashboardTest** — 2 tests covering constructor, render_dashboard_page (stats output)
- New: **AudienceAdminEnvironmentTest** — 4 tests covering constructor, handle_actions (no permission, with message), render_page (default list)
- New: **AudienceAdminImportTest** — 3 tests covering constructor, render_page (import/export interface), handle_csv_import (no action)
- New: **AudienceAdminSettingsTest** — 4 tests covering constructor, handle_visibility_settings (no action), handle_global_holiday_actions (no permission), render_page (general tab)
- Test suite: **1051 → 3089 tests** across **153 test files** — 100% class coverage on all 21 modules

---

## 5.0.1 (2026-02-22)

Security hardening, code quality improvements, URL Shortener test coverage, virtual auth code prefixes, and multiple bug fixes.

### Security Fixes (Sprint 1)

- Fix: Removed nonce fallback chain in AjaxTrait — each handler now verifies a single specific nonce action, eliminating timing side-channel
- Fix: Removed `wp_rest` as fallback nonce in Self-Scheduling AJAX handlers — only `ffc_self_scheduling_nonce` accepted
- Fix: Elevated `current_user_can('read')` to `manage_options` on sensitive Audience AJAX handlers (save_booking, cancel_booking, get_environments, search_users, save_custom_fields, get_custom_fields)
- Fix: Changed `$_GET['booking_id']` to `$_POST['booking_id']` in Audience AJAX POST handler
- Fix: Standardized nonce field name to `nonce` across all Audience handlers (was `_wpnonce` in one handler)

### Code Quality (Sprint 2)

- Fix: Replaced `stripslashes()` with `wp_unslash()` in SubmissionsList and VerificationHandler (4 occurrences)
- Fix: Improved SQL IN clause pattern in SubmissionRepository and AppointmentRepository — switched from string interpolation of `%s` placeholders to `%d` with `intval()` array mapping for integer ID arrays
- Fix: Moved rate limiter before format validation in `verify_by_magic_token()` to prevent probing token formats without throttling
- Fix: Added explicit `json_last_error_msg()` check and error logging after `json_decode()` in Audience loader

### Security Hardening (Sprint 6)

- Fix: Added early IP-based rate limit check in `FormProcessor::handle_submission_ajax()` before nonce/CAPTCHA — prevents brute-force DoS from consuming server resources on expensive checks
- Fix: Added justifications to all bare `phpcs:ignore` comments in URL Shortener admin page (9 comments standardized)
- Verified: Magic token endpoint documentation already complete (nonce intentionally omitted, rate limiting in place)
- Verified: JSON fallback handling in Audience loader already addressed in Sprint 2

### URL Shortener Tests (Sprints 3–5)

- New: **UrlShortenerServiceTest** — 40 tests covering create/delete/trash/restore, generate_unique_code (Base62, collision, length), get_short_url, settings (prefix, code_length, redirect_type, enabled, auto_create, post_types), toggle_status, get_stats
- New: **UrlShortenerRepositoryTest** — 20 tests covering findByShortCode (cache hit/miss), findByPostId (active only, cache), incrementClickCount (success/failure, cache clear), codeExists, findPaginated (WHERE building, search, sort, pagination), getStats
- New: **UrlShortenerLoaderTest** — 15 tests covering init (hooks conditional on enabled), maybe_flush_rewrite_rules (version tracking), register_rewrite_rules (regex), add_query_vars, handle_redirect (full redirect flow with click tracking), flush_rules (static method)
- New: **UrlShortenerAdminPageTest** — 17 tests covering handle_actions (nonce, routing), ajax_create (validation, permission), ajax_delete/trash/restore, ajax_empty_trash (bulk delete), ajax_toggle (status toggle)
- New: **UrlShortenerMetaBoxTest** — 12 tests covering register_meta_box (by post type), on_save_post (auto-create with guards), ajax_regenerate (regeneration flow)
- New: **UrlShortenerQrHandlerTest** — 7 tests covering generate_qr_base64 (PNG), generate_svg (SVG), handle_download_png/svg, resolve_qr_target (via reflection)
- New: **UrlShortenerActivatorTest** — 6 tests covering get_table_name, create_tables (idempotent), maybe_migrate (migrations)
- Test suite: **934 → 1051 tests, 1830 → 2076 assertions**

### Virtual Auth Code Prefixes

- Feat: Added virtual prefixes (C/R/A) to authentication codes — `C` for certificates, `R` for reregistrations, `A` for appointments. Display format changes from `XXXX-XXXX-XXXX` to `C-XXXX-XXXX-XXXX` / `R-XXXX-XXXX-XXXX` / `A-XXXX-XXXX-XXXX`
- Feat: Prefixes are presentation-only — not stored in database, zero migration needed. Raw 12-char codes remain the source of truth
- Feat: Intelligent verification routing on `/valid/` — prefix hints which DB table to search first, with fallback to all others for backward compatibility
- Feat: Updated DocumentFormatter with `format_auth_code($code, $prefix)`, `parse_prefixed_code($input)`, and `clean_auth_code($code)` methods
- Feat: Updated verification page input to accept prefixed codes (maxlength 16, placeholder `C-XXXX-XXXX-XXXX`)
- Feat: Updated JS input mask to dynamically detect prefix and format as `P-XXXX-XXXX-XXXX` or legacy `XXXX-XXXX-XXXX`
- Updated: All call sites across PDFs, emails, REST APIs, admin views, receipts, and verification responses now pass the appropriate prefix constant

### Bug Fixes

- Fix: Encrypted fields (email, CPF, RF) not decrypted in REST API responses for submissions and user certificates
- Fix: XSS vulnerability in dashboard JS — sanitized dynamic HTML output with proper escaping
- Fix: Appointment creation failing due to non-column keys (`ffc_form_id`, `ffc_calendar_id`) in insert data array
- Fix: QR code not appearing in auto-download PDF and duplicate download button on success page
- Fix: CPF/RF and email not found for users with only self-scheduling appointments — added join on appointments table in UserCreator
- Fix: Certificate verification card narrower than appointment card on `/valid/` — added `width: 100%` to `.ffc-certificate-preview` (root cause: `displayVerificationResult()` replaces container innerHTML, removing `.ffc-verify-result` wrapper, so flex `align-items: center` caused shrink-wrap)

### Refactoring & Improvements

- Refactor: Centralized CPF/RF and auth_code formatting into DocumentFormatter — replaced scattered inline formatting across admin, API, and frontend layers
- Refactor: Replaced inline `onclick` handlers with `data-confirm` delegation pattern for safer event handling
- Improved: Added summary error feedback on form validation failures
- Improved: Applied format masks for CPF, RF, auth_code, and validation_code across all admin and API views (submissions list, appointment detail, REST responses)
- Improved: Used translated status labels in reregistration admin views
- Chore: Centralized `FFC_VERSION` constant in tests/bootstrap.php from main plugin file

---

## 5.0.0 (2026-02-19)

Multi-identifier architecture: split combined CPF/RF into independent columns, and retirement of 10 completed legacy migrations.

### Multi-Identifier (Split CPF/RF)

- Feat: Added separate `cpf`, `cpf_encrypted`, `cpf_hash`, `rf`, `rf_encrypted`, `rf_hash` columns to submissions and appointments tables (Sprint 1)
- Feat: Updated core layer (SubmissionHandler, FormProcessor, Encryption) to read/write split columns natively (Sprint 2)
- Feat: Updated admin, API, security, and privacy layers for split cpf/rf columns (Sprint 3)
- Feat: Preserved legacy `cpf_rf` columns during split migration for backward compatibility (Sprint 4)
- Refactor: Removed legacy `cpf_rf` dual-write; optimized split migration to be the single source of truth (Sprint 5)
- Feat: Updated user dashboard layer (UserCreator, UserManager, CapabilityManager) for split columns (Sprint 5)
- Refactor: Removed `cpf_rf_hash` legacy fallback from UserCreator queries
- Feat: Added `identifier_type` parameter to UserCreator for targeted column lookup (CPF vs RF)
- Refactor: Deprecated legacy `cpf_rf` columns across entire plugin with `@deprecated` annotations
- Fix: Added split cpf/rf column support to `decrypt_submission` and `decrypt_appointment`
- Perf: Identifier digit-count classification targets specific hash column (11→CPF, 7→RF) instead of scanning both
- Perf: Applied digit-count classification to AppointmentRepository `findByCpfRf`

### Migration Cleanup

- Removed: **10 completed migrations** retired from admin panel — these ran their course and are no longer needed:
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
- Kept: `split_cpf_rf` migration remains available for legacy data
- Removed: 7 migration strategy classes (FieldMigration, MagicToken, Encryption, Cleanup, UserLink, NameNormalization, UserCapabilities)
- Removed: 3 legacy migration executor classes (MigrationUserLink, MigrationNameNormalization, MigrationUserCapabilities)
- Simplified: MigrationRegistry, MigrationStatusCalculator, and MigrationManager reduced to single-migration focus
- Simplified: Activator `run_migrations()` — no longer auto-runs retired migrations on activation
- Updated: MigrationManager unit tests adapted to simplified architecture
- Fix: CsvExporterTest updated for split CPF/RF columns (15 fixed headers instead of 14, indices shifted)
- Fix: CsvExporter sample_row uses separate `cpf`/`rf` fields instead of combined `cpf_rf`
- Added: `test_format_csv_row_rf_only` test case for RF-only submissions
- Fix: Added defensive try-catch to MigrationStatusCalculator strategy initialization to prevent 500 errors from stale opcache or DB issues
- Fix: Added try-catch to migrations settings tab view to gracefully handle runtime errors instead of 500
- Fix: Implemented missing `action=view` handler for appointments admin page (was causing 500 error)
- Added: Appointment detail view in admin panel with decrypted CPF/RF split fields, calendar info, and custom data
- Fix: Hardened appointments view and render method with try-catch to prevent unhandled 500 errors
- Fix: Rewrote appointment URLs to use absolute paths via `admin_url()` and replaced `action=view` with `appointment=X` parameter to avoid WordPress `admin_action_view` dispatch that was causing persistent 500 errors
- Fix: Changed appointment action URLs from `action` to `ffc_action` parameter to avoid conflicts with WordPress admin.php action processing
- Fix: Corrected confirm/cancel redirect URLs to use `admin.php` instead of `edit.php` for consistency with menu registration
- Fix: Added `class_exists` guard around `FFC_Appointments_List_Table` definition to prevent class redeclaration errors
- Fix: Added PHP shutdown function error handler to capture fatal errors in appointments page for debug logging
- Fix: Calendar export dropdown (`Exportar Calendário`) was clipped by `overflow:hidden` on `.ffc-appointments-table`
- Improved: Privacy/LGPD deletion request success message now tells admins where to find it (Tools > Erase Personal Data)
- Result: **934 tests, 1830 assertions, 0 failures**

## 4.12.26 (2026-02-18)

PHPStan level 6 — zero-baseline compliance. Resolved all 317 static analysis errors across 80+ files without any baseline suppressions.

- Fix: Added missing `use` import statements for 94 class.notFound errors across admin, API, frontend, and migration files
- Fix: Cast `int` to `string` for `esc_html()`, `esc_attr()`, `sprintf()`, `_n()` calls (50 argument.type errors)
- Fix: Removed 15 unreachable code blocks after `wp_die()`, `exit`, `wp_send_json_*()` calls
- Fix: Corrected PHPDoc `@return` types to match native return types (13 return.type errors)
- Fix: Removed unused properties (`$form_editor`, `$settings_page`, `$dynamic_fragments`, etc.) flagged as write-only
- Fix: Removed redundant `is_array()`/`is_string()` type checks on already-typed variables (15 errors)
- Fix: Removed unused constructor parameters (`$email_handler`, `$form_processor`, `$submission_handler`, `$verification_handler`) and updated all callers + tests
- Fix: Renamed undefined method calls (`check_limit` → `check_ip_limit`, added `process_bulk_action()`, fixed `generate_qr_code()` static call)
- Fix: Resolved include/require path resolution by using `__DIR__` in PHPStan stubs for `FFC_PLUGIN_DIR`
- Fix: Added PHPStan stubs for `DB_NAME`, `QR_ECLEVEL_*`, and `QRcode` class constants
- Fix: Simplified always-true/false conditions, redundant `empty()` checks, and `!== null` comparisons
- Fix: Fixed duplicate array keys, `WP_Error` namespace references, and covariant return types
- Config: Added `phpstan-stubs.php` bootstrap file with plugin constants and phpqrcode stubs
- Config: Excluded view directories from `variable.undefined` scanning in `phpstan.neon.dist`
- Baseline: Reduced from 317 errors to **0** (empty `ignoreErrors` array)

## 4.12.25 (2026-02-17)

Unit tests for EmailHelperTrait, AjaxTrait, and Debug: email sending/parsing helpers, AJAX parameter sanitization with nonce/permission checks, and per-area debug logging.

- New: **EmailHelperTraitTest** (20 tests) — `ffc_emails_disabled()` (default off, setting enabled, setting empty), `ffc_parse_admin_emails()` (single/multiple comma-separated, invalid email filtering, empty string admin fallback, custom fallback, whitespace trimming), `ffc_send_mail()` (success/failure wp_mail delegation), `ffc_email_header()` (div/font-family HTML), `ffc_email_footer()` (site name, closing div), `ffc_admin_notification_table()` (table structure, label+value rows, row count, empty details)
- New: **AjaxTraitTest** (17 tests) — `get_post_param()` (value/default/empty), `get_post_int()` (integer cast, default, negative→positive via absint, non-numeric→zero), `get_post_array()` (sanitized array, missing→empty, non-array→empty), `verify_ajax_nonce()` (valid passes, fallback action accepted, missing nonce sends error with die simulation, custom field name), `check_ajax_permission()` (granted passes, denied sends error)
- New: **DebugTest** (13 tests) — `is_enabled()` (enabled/disabled/zero/independent areas), `log()` (writes when enabled, skips when disabled), data formatting (null no suffix, string/array/integer data), convenience method delegation (log_pdf, log_email, log_form, log_rest_api, log_migrations, log_activity_log), area constants count (9)
- Config: Added `patchwork.json` to allow Brain\Monkey mocking of PHP built-in `error_log`
- Test suite: 765 → 815 tests, 1496 → 1563 assertions

## 4.12.24 (2026-02-17)

Unit tests for CsvExportTrait, ActivityLogQuery, and AppointmentCsvExporter: dynamic column extraction, query building, CSV row formatting, transient caching.

- New: **CsvExportTraitTest** (18 tests) — `build_dynamic_headers()` (snake_case/kebab-case/mixed to Title Case, empty, single word), `decode_json_field()` (plain JSON, empty/invalid/null, custom keys, encrypted fallback), `extract_dynamic_keys()` (multi-row dedup, empty, no JSON), `extract_dynamic_values()` (key ordering, missing key default, array flattening, empty keys)
- New: **ActivityLogQueryTest** (17 tests) — `get_activities()` (defaults, JSON context decode, invalid/empty context, level/search filter in prepared SQL, orderby whitelist, order normalization), `count_activities()` (integer return, multi-filter query building), `get_stats()` (transient cache hit/miss, DB aggregation), `cleanup()` (deleted count, transient clearing), `run_cleanup()` (settings retention, zero skip, default 90)
- New: **AppointmentCsvExporterTest** (21 tests) — `format_csv_row()` via Reflection: status labels (6 statuses incl. unknown fallback), consent display (yes/no/unset), user lookups (approved_by/cancelled_by with display name, deleted user ID fallback), calendar title from repo with deleted fallback, dynamic columns (appended, missing key default), `get_fixed_headers()` count and ID-first
- Test suite: 709 → 765 tests, 1427 → 1496 assertions

## 4.12.23 (2026-02-17)

Unit tests for BlockedDateRepository, EmailTemplateService, and ActivityLogSubscriber: recurring pattern matching, ICS generation, email wrapping, cache clearing, hook registrations.

- New: **BlockedDateRepositoryTest** (20 tests) — `matchesRecurringPattern()` via Reflection: weekly (blocked/unblocked day, weekend combo, empty/missing days), monthly (blocked/unblocked day of month, empty/missing), yearly (holiday match, ignores year variation, empty/missing dates), invalid/unknown/empty pattern, time parameter passthrough
- New: **EmailTemplateServiceTest** (24 tests) — `render_template()` (single/multiple vars, unknown placeholders, empty), `wrap_html()` (DOCTYPE, site name, header/content/footer structure), `format_date()`/`format_time()`, `send()` (wrap/no-wrap, wp_mail result), `generate_ics()` (VCALENDAR/VEVENT structure, date/time formatting, UID domain, REQUEST/CANCEL methods, summary/description/location, special char escaping, PRODID)
- New: **ActivityLogSubscriberTest** (13 tests) — Constructor hook registrations (submission/appointment/settings/cleanup), `on_settings_saved()` cache clearing (wp_cache_delete, delete_transient verification), logging method smoke tests (all 7 event handlers run without error with logging disabled)
- Test suite: 652 → 709 tests, 1338 → 1427 assertions

## 4.12.22 (2026-02-17)

Unit tests for Self-Scheduling and Date Blocking: appointment validation, save handler sanitization, holiday/availability checks.

- New: **AppointmentValidatorTest** (24 tests) — `validate()` (missing fields, invalid date/time format, impossible date, CPF/RF validation, slot availability, daily limit, scheduling visibility), `check_booking_interval()` (user ID/email/CPF lookup, skips cancelled, skips different calendar, returns error for upcoming), `is_within_working_hours()` delegation, `get_daily_appointment_count()` delegation
- New: **SelfSchedulingSaveHandlerTest** (18 tests) — `save_config()` (slot duration/defaults, boolean toggles, visibility validation, private forces scheduling private, description, no POST skip), `save_working_hours()` (sanitization, defaults, no POST skip), `save_email_config()` (boolean toggles, reminder hours, text fields, no POST skip)
- New: **DateBlockingServiceTest** (18 tests) — `is_global_holiday()` (match, no match, empty, non-array, missing date key), `get_global_holidays()` (all, start/end/range filter, empty range, non-array, missing date entries), `is_date_available()` (holiday blocks, working hours blocks, null time checks working day, closed day)
- Test suite: 592 → 652 tests, 1235 → 1338 assertions

## 4.12.21 (2026-02-17)

Unit tests for Migrations, Scheduling, and Generators: pure logic coverage for data sanitization, working hours, and magic links.

- New: **DataSanitizerTest** (31 tests) — `sanitize_field_value()` (custom callbacks, closure, fallback), `clean_json_data()` (JSON string/array, empty removal, zero preservation, invalid input), `extract_field_from_json()` (multi-key lookup, first non-empty match), `is_valid_identifier()` (CPF/RF digit-length validation, formatting), `is_valid_email()` (delegation), `normalize_auth_code()` (space/dash/underscore removal, uppercase)
- New: **WorkingHoursServiceTest** (30 tests) — `is_within_working_hours()` keyed format (range check, boundary inclusive start/exclusive end, closed day, missing start/end), array-of-objects format (range, no entry, split shift with gap), edge cases (empty/null/JSON string/unknown format); `is_working_day()` (both formats); `get_day_ranges()` (single range, split shift, closed, empty)
- New: **MagicLinkHelperTest** (32 tests) — `is_valid_token()` (32/64 hex, uppercase, boundary lengths, non-hex, empty), `generate_magic_link()` (URL structure, empty token), `extract_token_from_url()` (ffc_magic, token query, hash fragment, priority, no token), `get_magic_link_html()` (link, copy button, no-copy, empty token), `get_magic_link_qr_code()` (Google Charts URL, custom size, empty), `debug_info()`, `ensure_token()` (null handler, valid handler, invalid-generates-new), `get_magic_link_from_submission()`, `get_verification_page_url()`
- Test suite: 499 → 592 tests, 1118 → 1235 assertions

## 4.12.20 (2026-02-17)

Unit tests for Admin module: comprehensive coverage of settings validation, CSV export formatting, and geofence logic.

- New: **FormEditorSaveHandlerTest** (24 tests) — `validate_geofence_config()` (GPS/IP enabled states, combined errors) and `validate_areas_format()` (lat/lng/radius format, range validation, edge values, mixed valid/invalid lines)
- New: **SettingsSaveHandlerTest** (28 tests) — `save_general_settings()` (dark mode validation, cleanup days, advanced tab debug flags, cache tab), `save_smtp_settings()` (tab-specific disable, SMTP fields, user email settings), `save_qrcode_settings()` (size/margin, cache tab), `save_date_format_settings()` (format/custom, preservation)
- New: **CsvExporterTest** (25 tests) — `get_fixed_headers()` (14/17 columns with/without edit), `format_csv_row()` (fixed columns, consent formatting, deleted form title, edit columns, dynamic columns, empty optional fields), CsvExportTrait methods (`build_dynamic_headers`, `decode_json_field`, `extract_dynamic_keys`, `extract_dynamic_values`)
- Test suite: 422 → 499 tests, 974 → 1118 assertions

## 4.12.19 (2026-02-17)

Refactoring: extract focused classes from DashboardShortcode (720 → 395 lines, 45% reduction).

- Refactor: **DashboardAssetManager** (269 lines) — extracted `enqueue_assets()` with full CSS/JS enqueuing, `wp_localize_script` for dashboard, reregistration, and working-hours components, plus `user_has_audience_groups()` audience membership check
- Refactor: **DashboardViewMode** (98 lines) — extracted `get_view_as_user_id()` admin view-as validation (nonce, capability, user existence) and `render_admin_viewing_banner()` HTML rendering
- DashboardShortcode retains shortcode registration, cache headers, main render orchestration, login/redirect messages, and reregistration banners

## 4.12.18 (2026-02-17)

Unit tests for SubmissionHandler: comprehensive coverage of update, decrypt, failure paths, and edge cases.

- New: **21 additional SubmissionHandler tests** covering gaps identified in Sprint 17 analysis:
  - `update_submission()` (4 tests): encrypts email with hash, encrypts data JSON, strips edit tracking (`is_edited`/`edited_at`) from data before encryption, returns false on repo failure
  - `update_user_link()` (3 tests): sets user_id on link, passes null to unlink, returns false on failure
  - `decrypt_submission_data()` (2 tests): plaintext passthrough preserves all fields, encrypted fields correctly decrypted
  - Failure paths (3 tests): trash/restore/delete return false when repository returns false
  - Bulk empty guards (3 tests): bulk_trash/restore/delete return 0 for empty arrays without hitting repository
  - `get_submission_by_token` edge cases (2 tests): non-hex input returns null, valid hex not found returns null
  - `process_submission` branches (3 tests): consent absent sets 0, CPF mask cleaned before encryption, pre-populated auth_code preserved
  - `ensure_magic_token` (1 test): returns empty string when submission not found
- Test suite: 401 → 422 tests, 923 → 974 assertions

## 4.12.17 (2026-02-17)

Refactoring: extract focused classes from FormProcessor (822 → 548 lines, 33% reduction).

- Refactor: **AccessRestrictionChecker** (168 lines) — extracted `check_restrictions` and `consume_ticket` as public static methods for password, denylist, allowlist, and ticket validation
- Refactor: **ReprintDetector** (164 lines) — extracted `detect_reprint` as a public static method with `build_reprint_result` helper for JSON decoding and field enrichment
- Updated FormProcessorTest and FormProcessorRestrictionsTest to call AccessRestrictionChecker::check() directly (no more Reflection for restriction tests)
- FormProcessor retains AJAX orchestration, quiz scoring, and submission processing as its core responsibility

## 4.12.16 (2026-02-17)

Refactoring: extract focused classes from SelfSchedulingEditor (924 → 559 lines, 39% reduction).

- Refactor: **SelfSchedulingCleanupHandler** (303 lines) — extracted AJAX appointment cleanup handler (`handle_cleanup_appointments`) and cleanup metabox rendering (`render_cleanup_metabox`) into a dedicated class with its own constructor hook
- Refactor: **SelfSchedulingSaveHandler** (141 lines) — extracted `save_calendar_data` into a dedicated class with private helpers for config, working hours, and email config persistence
- SelfSchedulingEditor now delegates save and cleanup responsibilities via constructor composition, retaining only metabox registration, rendering, and asset loading

## 4.12.15 (2026-02-17)

Unit tests for Utils: comprehensive coverage of document validation, formatting, sanitization, captcha, and helper functions.

- New: **UtilsTest** — 95 tests covering all 3 groups of Utils methods:
  - Group A (Pure functions, 14 methods): `validate_cpf` (7 tests), `validate_phone` (7 tests), `format_cpf` (3 tests), `validate_rf`/`format_rf` (8 tests), `mask_cpf` (5 tests), `format_auth_code` (3 tests), `format_document` (6 tests), `sanitize_filename` (6 tests), `format_bytes` (6 tests), `truncate` (5 tests), `clean_auth_code`/`clean_identifier` (5 tests), `normalize_brazilian_name` (8 tests)
  - Group B (WordPress mocks, 11 methods): `asset_suffix`, `mask_email` (3 tests), `generate_random_string` (3 tests), `generate_auth_code`, `current_user_can_manage` (2 tests), `verify_simple_captcha` (5 tests), `validate_security_fields` (4 tests), `get_allowed_html_tags`, `generate_simple_captcha`, `recursive_sanitize` (2 tests)
  - Group C (DB mock): `get_submissions_table` (2 tests including multisite prefix)
- Test suite: 306 → 401 tests, 812 → 923 assertions

## 4.12.14 (2026-02-17)

Unit tests for FormProcessor and PdfGenerator: quiz scoring, restriction checks, URL parsing, filename generation, and data enrichment.

- New: **FormProcessorTest** — 21 tests covering `calculate_quiz_score()` (9 tests: correct/wrong answers, partial scoring, non-scored fields, rounding, empty input) and `check_restrictions()` (12 tests: password validation, denylist/allowlist CPF matching, ticket validation/consumption, priority ordering)
- New: **PdfGeneratorTest** — 32 tests covering `parse_validation_url_params()` (12 tests: link formats, custom text, target/color attributes, combined params), `generate_filename()` (6 tests: title sanitization, auth code appending, special chars, empty fallback), `generate_default_html()` (6 tests: conditional name/auth code rendering), and `enrich_submission_data()` (8 tests: email/date/ID/magic-token enrichment, no-overwrite behavior)
- Test suite: 253 → 306 tests, 710 → 812 assertions

## 4.12.13 (2026-02-17)

Refactoring: extract focused classes from ReregistrationAdmin (1,125 → 830 lines).

- Refactor: **ReregistrationCsvExporter** — extracted CSV export logic (`handle_export`) into a standalone class with a single static entry point
- Refactor: **ReregistrationSubmissionActions** — extracted submission workflow handlers (`handle_approve`, `handle_reject`, `handle_return_to_draft`, `handle_bulk`) into a dedicated class
- Refactor: **ReregistrationCustomFieldsPage** — extracted custom fields admin submenu page rendering into its own class
- ReregistrationAdmin now delegates to the extracted classes via `handle_actions()`, reducing the main class by 26% (1,125 → 830 lines)

## 4.12.12 (2026-02-17)

Unit tests for Reregistration module: field options and data processor.

- New: **ReregistrationFieldOptionsTest** — 15 tests covering `get_divisao_setor_map()` structure and content, field option providers (`sexo`, `estado_civil`, `sindicato`, `jornada`, `acumulo`, `uf`), UF 2-letter code validation, and `get_default_working_hours()` structure
- New: **ReregistrationDataProcessorTest** — 19 tests covering `sanitize_working_hours()` (valid/invalid JSON, missing day key, type casting, optional fields) and `validate_submission()` (required fields, CPF/phone validation, division-department consistency, custom field required/format/regex/email validation)
- Fix: **AudienceCsvImporterTest** — 5 tests using Mockery alias mocks for `AudienceRepository` now run in separate processes (`@runInSeparateProcess`) to prevent alias contamination of subsequent test classes
- Test suite: 218 → 253 tests, 453 → 710 assertions

## 4.12.11 (2026-02-17)

Unit tests for Audience module: CSV importer and notification handler.

- New: **AudienceCsvImporterTest** — 26 tests covering `validate_csv()`, `get_sample_csv()`, `import_members()`, and `import_audiences()` (header normalization, missing columns, empty rows, invalid emails, existing users, duplicate members, parent-before-child creation order, default color fallback)
- New: **AudienceNotificationHandlerTest** — 10 tests covering `render_template()` variable substitution (user, booking, cancellation, site, and optional keys), subject generation, and default template placeholder completeness
- Test suite: 182 → 218 tests, 352 → 453 assertions

## 4.12.10 (2026-02-17)

Security hardening sprint: regex validation, AJAX method enforcement, modern CSPRNG, prepared SQL statements.

- Security: **Regex validation** — custom regex patterns in `ReregistrationDataProcessor` now use `~` delimiter (avoids conflicts with `/` in patterns), and validate the pattern before applying it; invalid patterns are safely skipped instead of suppressed with `@`
- Security: **AJAX method enforcement** — `AudienceLoader::ajax_search_users()` and `ajax_get_environments()` switched from `$_GET` to `$_POST`; updated corresponding JS (`ffc-audience.js`, `ffc-audience-admin.js`) to use `POST` method
- Security: **Modern CSPRNG** — replaced deprecated `openssl_random_pseudo_bytes()` with `random_bytes()` in `Encryption::encrypt()` for IV generation
- Security: **Prepared SQL statements** — 3 `SHOW INDEX` queries in `Activator`, `DatabaseHelperTrait`, and `SelfSchedulingActivator` now use `$wpdb->prepare()` with `%i` identifier placeholder (WordPress 6.2+) instead of string interpolation
- Fix: **LiteSpeed hook prefix warning** — added `phpcs:ignore` for `litespeed_control_set_nocache` in `DashboardShortcode` (hook name is defined by LiteSpeed Cache plugin, not ours)
- Rebuilt all minified JS assets

## 4.12.9 (2026-02-17)

Fix: math captcha showing raw HTML as visible text on cached pages.

- Fix: **Captcha label raw HTML** — `ffc-dynamic-fragments.js` used `textContent` to set the captcha label which rendered `<span class="required">*</span>` as visible text instead of HTML; separated the required asterisk indicator from the label data and added `<span class="ffc-captcha-label-text">` wrapper so JS targets only the text portion
- Fix: **Form processor captcha refresh** — inline captcha generation in `FormProcessor` replaced with `Utils::generate_simple_captcha()` call for consistency
- Security: All captcha label refreshes now use `.text()`/`textContent` (never `.html()`/`innerHTML`), keeping XSS hardening from v4.12.6
- Rebuilt all minified JS assets

## 4.12.8 (2026-02-17)

Refactor Utils (dead code removal) and ReregistrationFrontend (1,330 lines → coordinator + 3 sub-classes).

- Removed: **3 unused public methods** from Utils — `is_local_environment()`, `is_valid_ip()`, `validate_email()` + private `get_disposable_email_domains()` (zero external callers)
- Refactor: **ReregistrationFrontend** split into 3 focused sub-classes: `ReregistrationFieldOptions` (field option data), `ReregistrationFormRenderer` (form HTML rendering with per-fieldset methods), `ReregistrationDataProcessor` (data collection, validation, submission processing)
- Enhanced: **ReregistrationFormRenderer** — broke 616-line `render_form()` into 8 focused private methods (`render_personal_data_fieldset`, `render_contacts_fieldset`, `render_schedule_fieldset`, `render_accumulation_fieldset`, `render_union_fieldset`, `render_acknowledgment_fieldset`, `render_custom_fields_fieldset`, `render_custom_field`)

## 4.12.7 (2026-02-17)

Refactor UserDataRestController (1,415 lines → coordinator + 6 sub-controllers).

- Refactor: **UserDataRestController** split into 6 focused sub-controllers: `UserCertificatesRestController`, `UserProfileRestController`, `UserAppointmentsRestController`, `UserAudienceRestController`, `UserSummaryRestController`, `UserReregistrationsRestController`
- New: **UserContextTrait** — shared `resolve_user_context()` and `user_has_capability()` methods extracted into reusable trait used by all sub-controllers
- Enhanced: **UserDataRestController** — now a thin coordinator (155 lines) with backward-compatible delegate methods and lazy-loaded sub-controllers
- Fix: **UserDataRestControllerTest** — added `wp_cache_get`/`wp_cache_set` stubs to fix 3 pre-existing RateLimiter errors in change_password and privacy_request tests

## 4.12.6 (2026-02-17)

Frontend cleanup: console.log removal, XSS hardening, CSS consolidation.

- Removed: **58 console.log calls** from production JS files (ffc-pdf-generator, ffc-admin-pdf, ffc-admin-field-builder, ffc-core); kept console.error/warn for legitimate error reporting; disabled html2canvas debug logging
- Security: **XSS hardening** — replaced unsafe `.html()` and `.innerHTML` with `.text()`, `.textContent`, and `escapeHtml()` in 7 files: ffc-dynamic-fragments (captcha label), ffc-reregistration-frontend (server messages, select options), ffc-calendar-frontend (error messages, user input, validation code, receipt URL), ffc-frontend (alert messages, error display), ffc-admin-pdf (image preview)
- Fix: **CSS `.ffc-badge` overlap** — removed duplicate base class from ffc-admin-submissions.css; canonical definition now lives in ffc-common.css with unified padding/font-size
- Fix: **CSS `.ffc-notice-*` overlap** — namespaced audience notice variants under `.ffc-audience-notice` to prevent cascade conflicts with user dashboard notices
- Rebuilt all minified JS and CSS assets

## 4.12.5 (2026-02-17)

Tests for critical classes: SubmissionHandler, UserCreator, CapabilityManager, and UserDataRestController endpoint callbacks.

- New: **SubmissionHandlerTest** — 21 tests covering process_submission (encryption, ticket_hash, consent fields, data field), get_submission, get_submission_by_token, trash/restore/delete, bulk operations, and ensure_magic_token
- New: **UserCreatorTest** — 12 tests covering generate_username (name fields, collision handling, fallback to random, special characters) and get_or_create_user (CPF match, email match, new user creation, error handling, capability granting)
- New: **CapabilityManagerTest** — 27 tests covering constants, get_all_capabilities, grant/revoke context dispatch, skip existing caps, has_certificate_access, has_appointment_access, get/set/reset user capabilities, register/remove role
- Enhanced: **UserDataRestControllerTest** — added 11 endpoint callback error-path tests (not-logged-in, no-capability, invalid-input) beyond the 14 existing route registration tests
- Fix: **SubmissionHandler bulk methods** — `bulk_trash_submissions()`, `bulk_restore_submissions()`, `bulk_delete_submissions()` declared `: array` return type but returned `int`; removed incorrect type declaration
- Fix: **SubmissionHandler WP_Error namespace** — `new WP_Error(...)` resolved to wrong namespace; fixed to `new \WP_Error(...)`
- Infrastructure: **Test bootstrap** — added WP stub classes (WP_Error, WP_Role, WP_User) and WordPress crypto constants for encryption-aware testing

## 4.12.4 (2026-02-17)

Performance and reliability: changelog extraction, ticket hash column, LIKE-on-JSON elimination.

- New: **CHANGELOG.md** — full version history extracted from readme.txt into dedicated changelog file; readme.txt now shows only recent versions with pointer to CHANGELOG.md
- New: **`ticket_hash` column** on submissions table — stores deterministic hash of ticket restriction value for indexed lookups; new composite index `idx_form_ticket_hash` on `(form_id, ticket_hash)`
- Fix: **Ticket reprint detection with encryption** — when data encryption was enabled, the `data` column is NULL so LIKE-based ticket lookup always failed silently; now uses `ticket_hash` for hash-based lookup, falling back to LIKE only for legacy unencrypted data
- Performance: **Eliminated LIKE on JSON** for ticket lookups — `detect_reprint()` now uses indexed `ticket_hash = %s` instead of `data LIKE '%"ticket":"VALUE"%'` when encryption is configured

## 4.12.3 (2026-02-17)

Security hardening: SQL injection prevention, XSS mitigation, and modal accessibility improvements.

- Security: **RateLimiter SQL** — all 11 queries now use `$wpdb->prepare()` with `%i` identifier placeholder for table names; removed blanket `phpcs:disable` for `PreparedSQL.NotPrepared`
- Security: **IpGeolocation SQL** — `clear_cache()` now uses `$wpdb->prepare()` with `%i` for table name and `%s` for LIKE patterns; removed blanket `phpcs:disable`
- Security: **XSS prevention** — added `escapeHtml()` utility to `ffc-frontend-helpers.js` and `ffc-audience-admin.js`; escaped user-controlled data in error messages, search results, selected user display, and conflict audience names
- Security: **Migration onclick removal** — replaced inline `onclick="return confirm(...)"` with `data-confirm` attribute on migration buttons; JS now reads from data attribute instead of parsing onclick regex
- Accessibility: **Modal ARIA attributes** — added `role="dialog"`, `aria-modal="true"`, `aria-labelledby` to audience booking modal, day detail modal, and admin booking modal
- Accessibility: **Focus trapping** — implemented keyboard focus trap (Tab/Shift+Tab) for audience booking and day modals, matching existing calendar-frontend pattern
- Accessibility: **Focus management** — modals now move focus to close button on open and return focus to trigger element on close
- Accessibility: **Escape key** — audience modals, admin booking modal, and template modal now close on Escape key press
- Accessibility: **Close button labels** — added `aria-label="Close"` to all modal close buttons

## 4.12.2 (2026-02-17)

God class refactoring: UserManager and ActivityLog split into single-responsibility classes with full backward compatibility.

- Refactor: **CapabilityManager** — extracted from UserManager; handles all FFC capability constants, role registration, context-based granting, access checks, and per-user capability management
- Refactor: **UserCreator** — extracted from UserManager; handles get_or_create_user flow, WordPress user creation, orphaned record linking, username generation, metadata sync, and profile creation
- Refactor: **ActivityLogQuery** — extracted from ActivityLog; handles get_activities, count_activities, get_stats, get_submission_logs, cleanup, and run_cleanup
- Refactor: **UserManager** — reduced from ~1,150 to ~400 lines; retains profile CRUD and data retrieval methods; delegates capabilities to CapabilityManager and user creation to UserCreator via backward-compatible constant aliases and method wrappers
- Refactor: **ActivityLog** — reduced from ~800 to ~520 lines; retains core logging, buffer management, and convenience methods; delegates query/stats/cleanup to ActivityLogQuery
- No breaking changes: all existing method calls and constant references continue to work via delegation

## 4.12.1 (2026-02-16)

Test coverage expansion: from 3 to 9 test files, covering critical security and business logic paths.

- New: **EncryptionTest** — 19 tests covering AES-256-CBC round-trip, unique IV per encryption, hash determinism, batch encrypt/decrypt, decrypt_field fallback, appointment decryption, Unicode handling, and is_configured()
- New: **RateLimiterTest** — 14 tests covering IP limit allow/block/cooldown, verification limits (hour/day), user rate limits, check_all blacklist/whitelist integration, email limit bypass, domain and CPF blacklisting
- New: **FormProcessorRestrictionsTest** — 15 tests covering password validation (required/incorrect/correct), denylist blocking, allowlist enforcement, ticket validation with case-insensitive matching and consumption, denylist-over-allowlist priority, quiz score calculation (correct/wrong/empty/unanswered)
- New: **SubmissionRestControllerTest** — 8 tests verifying route registration count, admin permission on list/single endpoints, public verify endpoint, auth_code validation (rejects <12 chars), pagination args
- New: **UserDataRestControllerTest** — 12 tests verifying all 11 user routes require `is_user_logged_in`, profile supports GET+PUT, all route paths registered correctly
- New: **SubmissionRepositoryTest** — 12 tests covering table name, cache group, ORDER BY sanitization (rejects SQL injection), bulk operations on empty arrays, countByStatus aggregation, cache behavior, insert/update/delete via mock wpdb
- Improved: **Bootstrap** — added WP_REST_Server stub, OBJECT_K/ARRAY_A/DB_NAME constants, updated FFC_VERSION to match plugin
- Total: **108 tests, 210 assertions** (previously 14 tests, 23 assertions)

## 4.12.0 (2026-02-16)

Full-page cache compatibility: forms now work correctly behind LiteSpeed, Varnish, and other page caches.

- New: **Dynamic Fragments endpoint** — lightweight AJAX endpoint (`ffc_get_dynamic_fragments`) returns fresh captcha and nonces on every page load, ensuring forms work on cached pages
- New: **Client-side captcha refresh** — JavaScript module (`ffc-dynamic-fragments.js`) patches captcha label, hash, and nonces in the DOM immediately after DOMContentLoaded
- New: **Nonce refresh** — `ffc_frontend_nonce` and `ffc_self_scheduling_nonce` are refreshed via AJAX, preventing expired-nonce errors on cached pages
- New: **Booking form AJAX pre-fill** — logged-in user name and email are populated via AJAX instead of server-side rendering, preventing cached pages from showing another user's data
- New: **Dashboard cache exclusion** — pages containing `[user_dashboard_personal]` automatically send `nocache_headers()`, `X-LiteSpeed-Cache-Control: no-cache`, and `litespeed_control_set_nocache` action
- New: **Page Cache Compatibility card** — new status card in Cache settings tab showing the state of all cache-compatibility features (Dynamic Fragments, Dashboard Exclusion, Object Cache, AJAX Endpoints)
- New: **Redis detection notice** — Cache settings tab warns when Redis/Memcached is not installed, explaining impact on rate limiter counter persistence

## 4.11.0 (2026-02-15)

Audience custom fields, reregistration campaigns, ficha PDF, email notifications, and audience hierarchy enhancements.

- New: **Audience Custom Fields** — define per-audience custom fields (text, textarea, number, date, select, checkbox) with validation rules (CPF, email, phone, regex)
- New: **Custom Fields on User Profile** — "FFC Custom Data" section on WordPress user edit screen showing fields grouped by audience with collapsible sections
- New: **Custom field inheritance** — child audiences inherit fields from parent audiences
- New: **Reregistration campaigns** — create campaigns linked to audiences with configurable start/end dates, auto-approve, and email settings
- New: **Reregistration frontend form** — dashboard banner with submission form, draft saving, and field validation
- New: **Reregistration admin UI** — manage campaigns, review/approve/reject submissions, bulk actions, filters
- New: **Reregistration email notifications** — invitation (on activation), reminder (N days before deadline via cron), and confirmation (on submission) emails
- New: **Ficha PDF** — generate PDF records for reregistration submissions with custom template support
- New: **Ficha download** — available in admin submissions list and user dashboard for submitted/approved submissions
- New: **Audience hierarchy tree** — recursive rendering with unlimited depth, member counts including children, breadcrumb navigation
- New: **REST API endpoints** — `GET /user/reregistrations`, `POST /user/reregistration/{id}/submit`, `POST /user/reregistration/{id}/draft`
- New: **Migration** — `MigrationCustomFieldsTables` ensures tables exist on upgrade from pre-4.11.0 versions
- New: **Documentation** — 3 new sections: Audience Custom Fields, Reregistration, Ficha PDF
- New: **pt_BR translations** — all new strings from Sprints 6-11 translated to Portuguese
- New: 3 database tables: `ffc_custom_fields`, `ffc_reregistrations`, `ffc_reregistration_submissions`
- New: Email templates: `reregistration-invitation.php`, `reregistration-reminder.php`, `reregistration-confirmation.php`
- New: Ficha HTML template: `html/default_ficha_template.html`

## 4.9.10 (2026-02-14)

Profile UX improvements and audience group self-assignment.

- Improved: **Edit Profile and Change Password buttons** are now in a prominent action bar below profile fields
- Improved: Password form has a styled container with slide animation
- New: **Audience group self-join** — users can join/leave groups marked as "Allow Self-Join" directly from their dashboard (max 2 per user)
- New: `allow_self_join` column on audiences table with admin toggle in audience edit form
- New: REST endpoints: `GET /user/joinable-groups`, `POST /user/audience-group/join`, `POST /user/audience-group/leave`
- New: Audience capabilities are automatically granted when user joins a group

## 4.9.9 (2026-02-14)

Security hardening, expanded audit trail, and LGPD compliance improvements.

- New: **Rate limiting by user_id** — `RateLimiter::check_user_limit()` protects authenticated endpoints (password change: 3/hour, privacy requests: 2/hour)
- New: **Activity log convenience methods** — `log_password_changed()`, `log_profile_updated()`, `log_capabilities_granted()`, `log_privacy_request()` for comprehensive audit trail
- New: **Email on capability grant** — optional email notification when certificate, appointment, or audience capabilities are granted (controlled by `notify_capability_grant` setting)
- New: **LGPD/GDPR usermeta export** — new exporter for `ffc_*` user meta entries via WordPress Privacy Tools
- New: **LGPD/GDPR usermeta erasure** — `ffc_*` user meta entries are now deleted during privacy erasure requests
- Improved: Password change, profile update, and privacy request endpoints now log to activity log
- Improved: Capability grant methods now track newly granted capabilities and fire activity log + email

## 4.9.8 (2026-02-14)

Dashboard UX improvements and user self-service features.

- New: **Dashboard summary cards** — overview at the top showing certificate count, next appointment, and upcoming group events
- New: **Search and date filters** — filter bar on certificates, appointments, and audience bookings tabs with date range and text search
- New: **Password change** — users can change their password directly from the Profile tab (no wp-admin access needed)
- New: **LGPD self-service** — "Export My Data" and "Request Data Deletion" buttons in Profile tab, using WordPress native privacy request system
- New: **Notes field** — editable personal notes in profile (uses existing `ffc_user_profiles.notes` column)
- New: **Notification preferences** — toggle switches for appointment confirmation, appointment reminder, and new certificate emails (all disabled by default)
- New: **Configurable pagination** — choose 10, 25, or 50 items per page on all tabs (persisted in localStorage)
- New: REST endpoints: `POST /user/change-password`, `POST /user/privacy-request`, `GET /user/summary`
- Improved: Profile REST API now returns `notes` and `preferences` fields
- Improved: `UserManager::update_profile()` now supports `preferences` JSON column

## 4.9.7 (2026-02-14)

Performance, view-as accuracy, centralized user service, and database referential integrity.

- New: **Batch count queries** in admin users list — certificate and appointment counts loaded via single GROUP BY query per table instead of N+1 per-user queries
- New: **UserService class** — centralized service (`FreeFormCertificate\Services\UserService`) for profile retrieval, capability checks, and user statistics
- New: **FOREIGN KEY constraints** — 7 FK constraints added to FFC tables referencing `wp_users(ID)`:
  - SET NULL on delete: `ffc_submissions`, `ffc_self_scheduling_appointments`, `ffc_activity_log`
  - CASCADE on delete: `ffc_audience_members`, `ffc_audience_booking_users`, `ffc_audience_schedule_permissions`, `ffc_user_profiles`
  - InnoDB engine check: FKs skipped gracefully if engine is not InnoDB
  - Orphaned references cleaned automatically before FK creation
- Fix: **View-as capability check** — admin view-as mode now uses TARGET user's capabilities (not admin's); admin sees exactly what the user would see for certificates, appointments, and audience bookings
- Fix: **Autoloader** — added missing `Privacy` and `Services` namespace mappings

## 4.9.6 (2026-02-14)

Editable user profile, orphaned record linking, and username privacy fix.

- New: **PUT /user/profile** REST endpoint — users can update display_name, phone, department, organization from the dashboard
- New: **Profile edit form** in user dashboard — toggle between read-only view and edit form with save/cancel actions
- New: **Phone, department, organization** fields displayed in the read-only profile view
- New: **Orphaned record linking** — `get_or_create_user()` now retroactively links submissions and appointments that share the same cpf_rf_hash but had no user_id
- New: **Appointment capability auto-grant** — when orphaned appointments are linked, appointment capabilities are granted automatically
- Fix: **Username = email** privacy issue — `create_ffc_user()` now generates username from name slug (e.g. "joao.silva") instead of using email; fallback to `ffc_` + random string
- Fix: **MigrationUserLink** updated to use `generate_username()` for new user creation during migration
- New: `UserManager::generate_username()` public method — generates unique slugified username from name data

## 4.9.5 (2026-02-14)

LGPD/GDPR compliance: WordPress Privacy Tools integration (Export & Erase Personal Data).

- New: **Privacy Exporter** — 5 data groups registered with WordPress Export Personal Data tool:
  - FFC Profile (display_name, email, phone, department, organization, member_since)
  - FFC Certificates (form_title, submission_date, auth_code, email, consent)
  - FFC Appointments (calendar, date, time, status, name, email, phone, notes)
  - FFC Audience Groups (audience_name, joined_date)
  - FFC Audience Bookings (environment, date, time, description, status)
- New: **Privacy Eraser** — registered with WordPress Erase Personal Data tool:
  - Submissions: anonymized (user_id=NULL, email/cpf cleared; auth_code and magic_link preserved for public verification)
  - Appointments: anonymized (user_id=NULL, all PII fields cleared)
  - Audience members/booking users/permissions: deleted
  - User profiles: deleted
  - Activity log: anonymized (user_id=NULL)
- New: **PrivacyHandler class** with paginated export (50 items/batch) and single-pass erasure
- New: Encrypted fields decrypted during export for complete data portability

## 4.9.4 (2026-02-14)

User profiles table, user deletion handling, and email change tracking.

- New: **`ffc_user_profiles` table** — centralized user profile storage (display_name, phone, department, organization, notes, preferences)
- New: **User deletion hook** — `deleted_user` action anonymizes FFC data (SET NULL on submissions/appointments/activity, DELETE on audience/profiles)
- New: **Email change handler** — `profile_update` action reindexes `email_hash` on submissions when user email changes
- New: **Profile methods** in UserManager — `get_profile()`, `update_profile()`, `create_user_profile()` with upsert logic
- New: **Profile migration** — `MigrationUserProfiles` populates profiles from existing ffc_users (display_name, registration date)
- New: **REST API profile fields** — `GET /user/profile` now returns `phone`, `department`, `organization` from profiles table
- New: **UserCleanup class** — handles `deleted_user` and `profile_update` hooks with activity logging
- Fix: **uninstall.php** — added `ffc_user_profiles` to DROP TABLE list and migration options to cleanup

## 4.9.3 (2026-02-14)

Capability system refactoring: centralized constants, enforced checks, simplified role model.

- New: **Centralized capability constants** — `AUDIENCE_CAPABILITIES`, `ADMIN_CAPABILITIES`, `FUTURE_CAPABILITIES` and `get_all_capabilities()` method in UserManager
- New: **Audience context** — `CONTEXT_AUDIENCE` constant and `grant_audience_capabilities()` for audience group members
- New: **`download_own_certificates` enforced** — users without this capability no longer receive `magic_link`/`pdf_url` in dashboard API
- New: **`view_certificate_history` enforced** — users without this capability see only the most recent certificate per form
- Fix: **CSV importer capabilities** — replaced 3 hardcoded `add_cap()` calls with centralized `UserManager::grant_certificate_capabilities()`
- Fix: **uninstall.php cleanup** — added 4 missing capabilities: `ffc_scheduling_bypass`, `ffc_view_audience_bookings`, `ffc_reregistration`, `ffc_certificate_update`
- Fix: **Admin UI save** — `save_capability_fields()` now references `UserManager::get_all_capabilities()` instead of hardcoded list
- Changed: **Simplified role model** — `ffc_user` role now has all FFC capabilities as `false`; user_meta is the sole source of truth
- Changed: **Removed redundant reset** — `reset_user_ffc_capabilities()` no longer called during user creation (role no longer grants caps by default)
- Changed: **`upgrade_role()` uses centralized list** — new capabilities added as `false` automatically

## 4.9.2 (2026-02-13)

UX improvements, race condition fix, and PHPCS compliance.

- New: **Textarea auto-resize** — textarea fields in certificate forms grow automatically as user types (up to 300px, then scrollbar), with manual resize support
- Fix: **Calendar month navigation race condition** — rapid month clicks no longer show stale data; uses incremental fetch ID to discard superseded responses (both self-scheduling and audience calendars)
- Fix: **Form field labels capitalization** — labels now respect original formatting regardless of theme CSS
- Fix: **LGPD consent box overflow** — encryption warning no longer exceeds consent container bounds
- Fix: **Form field attributes** — corrected esc_attr() misuse on HTML attributes (textarea, select, radio, input)
- Fix: PHPCS compliance — nonce verification, SQL interpolation, global variable prefixes, unescaped DB parameters, offloaded resources, readme limits

## 4.9.1 (2026-02-12)

Calendar display improvements, custom booking labels, audience badge format, and bug fixes.

- New: **Collapse parent audiences** — when a parent audience with all children is selected, display only the parent in frontend badges
- New: **Audience badge format** option per calendar — choose between name only or "Parent: Child" format
- New: **Custom booking badge labels** per calendar — configurable singular/plural labels for booking count in day cells (with global fallback)
- Fix: **Geofence GPS checkbox** not saving when unchecked — added hidden sentinel field so unchecked state is properly persisted
- Fix: **Migration cascade failure** — removed `AFTER` clauses from ALTER TABLE migrations that caused silent failures when referenced columns didn't exist
- Fix: **Booking labels missing** from logged-in user config — bookingLabelSingular/bookingLabelPlural were only passed in the public config path
- Fix: **Public calendar event details** — REST API now returns description, environment name, and audiences for non-authenticated users on public calendars

## 4.9.0 (2026-02-12)

New field types, Quiz/Evaluation mode for scored forms, and certificate quiz tags.

- New: **Info Block** field type — display-only rich text content in forms (supports HTML: bold, italic, links, lists)
- New: **Embed (Media)** field type — embed YouTube, Vimeo, images, or audio via URL with optional caption
- New: **Quiz / Evaluation Mode** — turn any form into a scored quiz with configurable passing score
- New: Quiz **points per option** on Radio, Select, and Checkbox fields (comma-separated values matching option order)
- New: Quiz **max attempts** per CPF/RF — configurable retry limit (0 = unlimited)
- New: Quiz **score feedback** — show score and correct/incorrect answers after submission
- New: Quiz **attempt tracking** — submissions tracked by CPF/RF with statuses: published, retry, failed
- New: Quiz **status badges** in admin submissions list — color-coded badges with score percentage
- New: Quiz **filter tabs** in admin — filter submissions by Published, Trash, Quiz: Retry, Quiz: Failed
- New: Certificate tags **{{score}}**, **{{max_score}}**, **{{score_percent}}** for quiz results in PDF layout
- New: pt_BR translations for all quiz, info block, and embed strings

## 4.8.0 (2026-02-11)

Calendar UX improvements, environment colors, event list panel, admin enhancements, and export functionality.

- New: Environment **color picker** — assign distinct colors to each environment (admin + frontend)
- New: **Event list panel** — optional side or below panel showing upcoming bookings for the current month
- New: Event list admin settings — enable/disable and position (side or below calendar)
- New: **All-day event** checkbox — marks bookings as all-day (stores 00:00–23:59), blocks entire environment for the day
- New: All-day events display "All Day" label instead of time range in day modal and event list
- New: Holidays now displayed in event list panel alongside bookings (sorted by date)
- New: **"Multiple audiences" badge** in event list when a booking has more than 2 audiences
- New: Multiple audiences badge **color configurable** in Audience settings tab
- New: **CSV export** for members (email, name, audience_name) with optional audience filter
- New: **CSV export** for audiences (name, color, parent) in import-compatible format
- New: Import page renamed to **"Import & Export"** with tabbed navigation (Import / Export)
- New: Admin **feedback notices** for create, save, deactivate, and delete actions on calendars, environments, and audiences
- New: **Soft-delete pattern** — active items are deactivated first; only inactive items can be permanently deleted (calendars, environments, audiences)
- New: **Booking View** button in admin — opens AJAX modal with full booking details (audiences, users, creator, status)
- New: **Booking Cancel** button in admin — AJAX cancel with confirmation prompt and mandatory reason
- New: **Filter overlay** on submissions page — replaced multi-select with overlay modal, forms ordered by ID desc
- Fix: FFC Users redirect — only block wp-admin access when ALL user roles are in the blocked list (was blocking when ANY role matched)
- Fix: Environment label not reaching frontend — `get_schedule_environments()` now includes `name` field in config
- Fix: Environment label fallback "Ambiente" was not wrapped in `__()` for translation
- Fix: Holiday names no longer shown in calendar day cells — displays generic "Holiday" label only (full name in event list)
- Fix: Holiday label fix applied to both audience and self-scheduling calendars
- Fix: Badge overflow in day cells — badges now truncate with ellipsis instead of overflowing
- Changed: Calendar max-width adjusted to 600px standalone, 1120px with event list (600px + 20px gap + 500px panel)
- Changed: Calendar day cells now use `aspect-ratio: 1` for consistent square grid layout
- Changed: Environment colors shown as left border on booking items in day modal and event list
- Migration: Added `is_all_day`, `show_event_list`, `event_list_position`, and `color` columns with automatic migration

## 4.7.0 (2026-02-09)

Visibility and scheduling controls for calendars, admin bypass system.

- New: Per-calendar **Visibility** control (Public/Private) for self-scheduling calendars
- New: Per-calendar **Scheduling** control (Public/Private) for self-scheduling calendars
- New: Audience calendars visibility now supports non-logged-in visitors (public calendars show read-only view)
- New: Audience scheduling is always private — requires login + audience group membership
- New: Configurable display modes for private calendars: show message, show title + message, or hide
- New: Customizable visibility and scheduling messages with login link (%login_url% placeholder)
- New: Settings in Self-Scheduling and Audience tabs for message configuration
- New: `ffc_scheduling_bypass` capability for granting admin-level scheduling access to non-admin users
- New: Admin bypass system — admins can book past dates, out-of-hours, blocked dates, and holidays
- New: Admin bypass visual indicators — "Private" badge and bypass notice on frontend
- New: Admin cancellation with mandatory reason in appointments list (prompt for reason)
- New: Non-logged-in users can view public audience calendars with occupancy data (no personal details)
- Changed: Default visibility — self-scheduling: Public/Public, audience: Private
- Changed: Slot limit (max_appointments_per_slot) is never bypassed, even for admins
- Changed: REST API now filters calendars by visibility for non-authenticated requests
- Changed: REST API rejects bookings when scheduling is private and user is not authenticated
- Migration: `require_login` and `allowed_roles` fields replaced by `visibility` and `scheduling_visibility`
- Migration: Existing data automatically migrated (require_login=1 → private/private, 0 → public/public)
- Refactor: Audience shortcode split into reusable `render_calendar_html()` for shared rendering
- Refactor: All `current_user_can('manage_options')` scheduling checks use `userHasSchedulingBypass()`
- CSS: Added styles for visibility restrictions, scheduling restrictions, admin bypass notices, and badges

## 4.6.16 (2026-02-08)

Settings UX, dead code removal, code deduplication, version centralization, and bug fixes.

- UX: Reorganize settings into 9 tabs — new Cache and Advanced tabs, QR Code tab removed
- UX: Move debug flags and danger zone to Advanced tab, cache settings to Cache tab
- Cleanup: Remove dead QR Code tab files (class + view), empty Admin::admin_assets(), unused CsvExporter::export_to_csv() wrapper
- Cleanup: Remove 'template' alias from PdfGenerator, empty Loader::run() method, old ffc_ cron hook migration code
- Cleanup: Remove 50+ stale version annotation comments across 21 files
- Cleanup: Remove commented-out debug error_log() and TinyMCE CSS blocks
- Refactor: Extract duplicate get_option() from 4 tab classes into base SettingsTab class
- Refactor: Extract duplicate dark mode enqueue into Utils::enqueue_dark_mode() (shared by admin + frontend)
- Refactor: Remove redundant = null initialization from 16 Loader properties
- Refactor: View files delegate to tab method via Closure::fromCallable instead of inline lambdas
- Fix: TypeError in SettingsTab::get_option() — cast return value to string (strict_types with int DB values)
- Fix: phpqrcode cache directory warning — disable QR_CACHEABLE (plugin has own QR cache via transients)
- Fix: Missing icons on user dashboard — load ffc-common.css (defines .ffc-icon-* classes) + dark mode support
- Fix: Outdated FFC_VERSION in tests/bootstrap.php (4.6.13 → 4.6.16)
- Fix: Outdated stable tag in readme.txt (4.6.15 → 4.6.16)
- Fix: Hardcoded '4.1.0' version in self-scheduling admin enqueues — now uses FFC_VERSION
- New: FFC_JQUERY_UI_VERSION constant for centralized jQuery UI CDN version management
- New: Dynamic JS console version via wp_localize_script — modules now show FFC_VERSION instead of hardcoded 3.1.0
- Removed: Unnecessary defined() fallbacks for library version constants in self-scheduling shortcode

## 4.6.15 (2026-02-08)

Plugin Check Compliance: Hook prefix, SQL placeholders, deprecated API removal, and query caching.

- Fix: Remove deprecated load_plugin_textdomain() call (automatic since WordPress 4.6)
- Fix: Rename all 44 hook names from ffc_ to ffcertificate_ prefix for WordPress Plugin Check compliance
- Fix: Add WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare to phpcs:ignore for dynamic IN() queries
- Fix: Add WordPress.DB.PreparedSQL.InterpolatedNotPrepared to phpcs:ignore for safe table name interpolation
- Perf: Add wp_cache_get/set to 5 audience repository read queries (is_holiday, count, get_user_audiences, search)
- Fix: Migrate old ffc_daily_cleanup_hook cron to new ffcertificate_daily_cleanup_hook name on init
- Fix: Clean up both old and new cron hook names in deactivator and uninstall.php

## 4.6.14 (2026-02-08)

Accessibility & Responsive Design: Dark mode, CSS variables, ARIA attributes, and template accessibility.

- A11y: Add semantic CSS design tokens (40+ variables for surfaces, text, borders, status colors) in :root
- A11y: Add automatic dark mode via prefers-color-scheme media query with full variable overrides
- A11y: Replace 50+ hardcoded colors in ffc-frontend.css with CSS custom properties
- A11y: Add role="status" and aria-live="polite" to submission success template
- A11y: Add role="progressbar" with aria-valuenow/min/max to migration progress bars
- A11y: Add screen-reader-text labels for activity log filter/search controls
- A11y: Add scope="col" to all documentation tab table headers

## 4.6.13 (2026-02-08)

Performance: Query caching, conditional loading, and N+1 elimination. Quality: i18n, documentation, icon CSS refactor.

- Perf: Cache RateLimiter settings in static variable (eliminates 10+ repeated get_option + __() calls per request)
- Perf: Cache SHOW TABLES check in AdminUserColumns (eliminates N+1 query per user row on users list)
- Perf: Cache dashboard URL in AdminUserColumns render_user_actions (eliminates repeated get_option per row)
- Perf: Cache INFORMATION_SCHEMA column existence checks in SubmissionRepository (eliminates repeated schema queries)
- Perf: Fix ActivityLog get_submission_logs() to use existing cached get_table_columns() instead of raw DESCRIBE
- Perf: Conditional class loading — skip admin-only classes (CsvExporter, Admin, AdminAjax, AdminUserColumns, AdminUserCapabilities, SelfSchedulingAdmin, SelfSchedulingEditor, AppointmentCsvExporter) on frontend page loads
- i18n: Wrap 7 hardcoded strings (4 Portuguese, 3 English) with __() for proper translation support
- Docs: Add missing shortcodes [ffc_self_scheduling] and [ffc_audience] to documentation tab
- Docs: Add missing PDF placeholders {{submission_id}}, {{main_address}}, {{site_name}} to documentation tab
- Refactor: Move 40+ inline emoji icons from PHP/HTML to CSS utility classes (ffc-icon-*) in ffc-common.css

## 4.6.12 (2026-02-08)

Quality: Unit testing, i18n compliance, and asset minification.

- New: Add PHPUnit test infrastructure (composer.json, phpunit.xml.dist, bootstrap)
- New: Add 14 unit tests covering Geofence bypass, ActivityLog buffer, and EmailHandler contexts
- New: Generate minified .min.css and .min.js for all 34 plugin assets (~45% average size reduction)
- New: Conditional asset loading — serve .min files in production, full files when SCRIPT_DEBUG is on
- New: Add Utils::asset_suffix() helper for consistent minification suffix across all enqueue calls
- Fix: Replace 13 hardcoded Portuguese strings in RateLimiter with __() for proper i18n
- Fix: PHPUnit bootstrap load order — define ABSPATH before requiring autoloader (prevents silent exit)

## 4.6.11 (2026-02-08)

Security hardening: REST API protection, uninstall cleanup, deprecated API removal.

- Security: Add geofence validation (date/time + IP) to REST API form submission endpoint
- Security: Add rate limiting to REST API appointment creation endpoint
- Security: Remove error_reporting() suppression in REST controller (use output buffering only)
- New: Add uninstall.php — full cleanup of all tables, options, roles, capabilities, transients, and cron hooks on plugin deletion
- Fix: Replace all deprecated current_time('timestamp') calls (deprecated since WP 5.3) with time() + wp_date()
- Fix: Timezone-aware datetime comparisons in Geofence, AppointmentValidator, and AppointmentHandler using DateTimeImmutable + wp_timezone()

## 4.6.10 (2026-02-08)

Fix: Race condition in concurrent appointment booking (TOCTOU vulnerability).

- Fix: Wrap validate + insert in MySQL transaction with row-level locking (FOR UPDATE)
- Fix: Add transaction support (begin/commit/rollback) to AbstractRepository
- Fix: AppointmentRepository::isSlotAvailable() now supports FOR UPDATE lock
- Fix: AppointmentRepository::getAppointmentsByDate() now supports FOR UPDATE lock
- Fix: AppointmentValidator accepts lock flag for capacity queries inside transaction
- Fix: Upgrade validation_code index from KEY to UNIQUE KEY (prevents duplicate codes)
- Fix: Catch exceptions during booking and rollback on failure

## 4.6.9 (2026-02-08)

Performance: Activity Log optimization with batch writes, auto-cleanup, and stats caching.

- Perf: Buffer activity log writes and flush as single multi-row INSERT on shutdown (or at 20-entry threshold)
- Feature: Automatic log cleanup via daily cron with configurable retention period (default 90 days)
- Feature: Add "Log Retention (days)" setting under Settings > General > Activity Log
- Perf: Cache get_stats() results with 1-hour transient, invalidated on cleanup and settings save
- Fix: Activator schema mismatch — delegate to ActivityLog::create_table() for consistent schema
- Fix: MigrationManager used undefined LEVEL_CRITICAL, changed to LEVEL_ERROR
- Fix: Schedule ffc_daily_cleanup_hook cron on activation (was registered but never scheduled)
- Fix: Clear cron on plugin deactivation

## 4.6.8 (2026-02-08)

Refactor: Break down God classes into focused single-responsibility classes.

- Refactor: Extract AppointmentValidator from AppointmentHandler (all validation logic)
- Refactor: Extract AppointmentAjaxHandler from AppointmentHandler (4 AJAX endpoints)
- Refactor: Slim AppointmentHandler from 1,027 to 457 lines (core business logic only)
- Refactor: Extract VerificationResponseRenderer from VerificationHandler (HTML rendering + PDF generation)
- Refactor: Slim VerificationHandler from 822 to 547 lines (search + verification logic only)
- Refactor: Wire AppointmentAjaxHandler via Loader using dependency injection

## 4.6.7 (2026-02-07)

Accessibility: WCAG 2.1 AA compliance for all frontend components.

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

## 4.6.6 (2026-02-07)

Reliability: Standardize error handling across all modules.

- Fix: Encryption catch blocks now use \Exception (namespace bug prevented catching errors)
- Fix: wp_mail() return values checked and failures logged in EmailHandler and AppointmentEmailHandler
- Security: REST API catch blocks no longer expose internal exception messages to clients
- Improve: AJAX error responses now include structured error codes alongside messages
- Improve: WP_Error codes propagated through AJAX handlers (FormProcessor, AppointmentHandler)
- Improve: AbstractRepository logs $wpdb->last_error on insert/update/delete failures
- Refactor: AppointmentEmailHandler uses centralized send_mail() with failure logging
- Improve: Catch blocks in AppointmentHandler AJAX use debug_log instead of error_log/getMessage exposure

## 4.6.5 (2026-02-07)

Architecture: Internal hook consumption — plugin uses its own hooks for activity logging.

- New: ActivityLogSubscriber class listens to ffc_ hooks for decoupled logging
- Refactor: Removed direct ActivityLog calls from SubmissionHandler (5 calls → hook-based)
- Refactor: Removed direct ActivityLog calls from AppointmentHandler (2 calls → hook-based)
- New: ffc_settings_saved hook now triggers cache invalidation (options + transients)
- Architecture: Plugin "eats its own dog food" — business logic decoupled from logging

## 4.6.4 (2026-02-07)

Extensibility: Add 31 action/filter hooks for developer customization.

- Submissions: ffc_before_submission_save, ffc_after_submission_save, ffc_before_submission_update, ffc_after_submission_update, ffc_submission_trashed, ffc_submission_restored, ffc_before_submission_delete, ffc_after_submission_delete
- PDF/Certificate: ffc_certificate_data, ffc_certificate_html, ffc_certificate_filename, ffc_after_pdf_generation
- QR Code: ffc_qrcode_url, ffc_qrcode_html
- Email: ffc_before_email_send, ffc_user_email_subject, ffc_user_email_recipients, ffc_user_email_body, ffc_admin_email_recipients, ffc_scheduling_email
- Appointments: ffc_before_appointment_create, ffc_after_appointment_create, ffc_appointment_cancelled, ffc_available_slots
- Audience: ffc_before_audience_booking_create (existing: ffc_audience_booking_created, ffc_audience_booking_cancelled)
- Settings: ffc_settings_before_save, ffc_settings_saved, ffc_before_data_deletion
- Export: ffc_csv_export_data

## 4.6.3 (2026-02-07)

Security: Permission audit — add missing capability checks to admin handlers.

- Security: Added `current_user_can('manage_options')` to SettingsSaveHandler (covers all settings + danger zone)
- Security: Added capability check to migration execution handler
- Security: Added capability check to cache warm/clear actions
- Security: Added capability check to date format preview AJAX handler
- Security: Tightened audience booking REST write permission (requires `ffc_view_audience_bookings` capability)

## 4.6.2 (2026-02-07)

Performance: Fix N+1 queries and add composite database indexes.

- Performance: Batch load form titles in submissions list (replaces per-row get_the_title)
- Performance: Batch load calendars in user appointments REST endpoint (replaces per-row findById)
- Performance: Batch load audiences in user audience-bookings REST endpoint (replaces per-row query)
- Performance: Batch load user data in admin bookings list (replaces per-row get_userdata)
- Performance: Added findByIds() batch method to AbstractRepository for reusable multi-ID lookups
- Database: Added composite index idx_form_status (form_id, status) on submissions table
- Database: Added composite index idx_status_submission_date (status, submission_date) on submissions table
- Database: Added composite index idx_email_hash_form_id (email_hash, form_id) on submissions table
- Database: Added composite index idx_calendar_status_date (calendar_id, status, appointment_date) on appointments table
- Database: Added composite index idx_user_status (user_id, status) on appointments table
- Database: Added composite index idx_date_status (booking_date, status) on audience bookings table
- Database: Added composite index idx_created_by_date (created_by, booking_date) on audience bookings table

## 4.6.1 (2026-02-07)

Security, accessibility, code quality, and structural refactoring.

- Security: Fixed SQL injection vulnerabilities with prepared statements in repository queries
- Security: Added `current_user_can('manage_options')` capability checks to audience admin form handlers
- Security: Externalized inline CSS and JavaScript to proper asset files (XSS hardening)
- Accessibility: Added `prefers-reduced-motion` media queries to all animations and transitions
- Accessibility: Added `focus-visible` styles for keyboard navigation across admin and frontend
- Accessibility: Added `role="presentation"` to all layout tables and `<tbody>` for HTML consistency
- Compatibility: Added vendor prefixes (`-webkit-`, `-moz-`) for cross-browser CSS support
- Refactored: Split `AudienceAdminPage` (~2,300 lines) into coordinator + 7 focused sub-classes
- Refactored: Split `RestController` (~1,940 lines) into coordinator + 5 domain-specific sub-controllers
- Improved: Renamed calendar asset files with `ffc-` prefix for naming consistency
- Improved: Removed duplicate CSS declarations across stylesheets
- Fixed: Frontend CSS duplication causing style conflicts
- Fixed: Restored `Loader::run()` method accidentally removed during refactoring
- New classes: `AudienceAdminDashboard`, `AudienceAdminCalendar`, `AudienceAdminEnvironment`, `AudienceAdminAudience`, `AudienceAdminBookings`, `AudienceAdminSettings`, `AudienceAdminImport`, `FormRestController`, `SubmissionRestController`, `UserDataRestController`, `CalendarRestController`, `AppointmentRestController`
- Changed: Plugin slug from `wp-ffcertificate` to `ffcertificate` (removed restricted "wp-" prefix)
- Changed: Text domain from `wp-ffcertificate` to `ffcertificate`
- Changed: Hook prefix from `wp_ffcertificate_` to `ffcertificate_`
- Changed: Language files renamed to match new text domain

## 4.6.0 (2026-02-06)

Scheduling consolidation, user dashboard improvements, and bug fixes.

- Added: Unified scheduling admin menu with visual separators between Self-Scheduling and Audience sections
- Added: Scheduling Dashboard with stats cards (calendars, appointments, environments, audiences, bookings)
- Added: Unified Settings page with tabs for Self-Scheduling, Audience, and Global Holidays
- Added: Global holidays system blocking bookings across all calendars in both scheduling systems
- Added: Pagination to user dashboard (certificates, appointments, audience bookings)
- Added: Audience groups display on user profile tab
- Added: Upcoming/Past/Cancelled section separators on appointments tab (matching audience tab)
- Added: Holiday and Closed legend/display on self-scheduling calendar frontend
- Added: Dashboard icon in admin submenu
- Improved: Cancel button only visible for future appointments respecting cancellation deadline
- Improved: Audience tab column alignment with fixed-width layout and one-tag-per-line
- Improved: Calendar frontend styles consistent for logged-in and anonymous users
- Improved: Tab labels renamed for clarity (Personal Schedule, Group Schedule, Profile)
- Improved: Stat card labels moved to top of each card
- Fixed: 500 error on profile endpoint (missing `global $wpdb`)
- Fixed: SyntaxError on calendar page (`&&` mangled by `wptexturize`; moved to external JS with JSON config)
- Fixed: Self-scheduling calendar not rendering (wp_localize_script timing issue; switched to JSON script tag)
- Fixed: Empty audiences column (wrong table name `ffc_audience_audiences` → `ffc_audiences`)
- Fixed: Cancel appointment 500 error (TypeError: string given to `findById()` expecting int)
- Fixed: Error handling in AJAX handlers (use `\Throwable` instead of `\Exception`)
- Fixed: Appointments tab showing time in date column
- Fixed: Dashboard tab font consistency
- Fixed: Missing `ffc-audience-admin.js` and calendar-admin assets causing 404s
- Updated: 278 missing pt_BR translations for audience/scheduling system
- Fixed: Incorrect translation for `{{submission_date}}` format description

## 4.5.0 (2026-02-05)

Audience scheduling system and unified calendar component.

- Added: Complete audience scheduling system for group bookings (`[ffc_audience_calendar]` shortcode)
- Added: Audience management with hierarchical groups (2-level), color coding, and member management
- Added: Environment management (physical spaces) with per-environment calendars and working hours
- Added: Group booking modal with audience/individual user selection and conflict detection
- Added: CSV import for audiences (name, color, parent) and members (email, name, audience)
- Added: Email notifications for new bookings and cancellations with audience details
- Added: Admin bookings list page with filters by schedule, environment, status, and date range
- Added: Audience bookings tab in user dashboard with monthly calendar view
- Added: Shared `FFCCalendarCore` JavaScript component for both calendar systems
- Added: Unified visual styles (`ffc-common.css`) shared between Self-Scheduling and Audience calendars
- Added: Calendar ID and Shortcode fields on calendar edit page
- Added: Holidays management interface with closed days display in calendar
- Added: Environment selector dropdown in booking modal
- Added: Filter to show/hide cancelled bookings in day modal
- Added: REST API endpoints for audience bookings with conflict checking
- Fixed: Autoloader for `SelfScheduling` namespace file naming
- Fixed: Multiple int cast issues for repository method calls with database values
- Fixed: Date parsing timezone offset issues in calendar frontend
- Fixed: AJAX loop prevention in booking counts fetch
- New tables: `ffc_audiences`, `ffc_audience_members`, `ffc_environments`, `ffc_audience_bookings`, `ffc_audience_booking_targets`
- New classes: `AudienceAdminPage`, `AudienceShortcode`, `AudienceLoader`, `AudienceRestController`, `AudienceCsvImporter`, `AudienceNotificationHandler`, `AudienceRepository`, `EnvironmentRepository`, `AudienceBookingRepository`, `EmailTemplateService`

## 4.4.0 (2026-02-04)

Per-user capability system and self-scheduling rename.

- Added: Per-user capability system for certificates and appointments (`ffc_view_own_certificates`, `ffc_cancel_own_appointments`, etc.)
- Added: User Access settings tab for configuring default capabilities per role
- Added: Capability migration for existing users based on submission/appointment history
- Renamed: Calendar system to "Self-Scheduling" (Personal Calendars) for clarity
- Renamed: CPT labels from "FFC Calendar" to "Personal Calendar" / "Personal Calendars"
- Improved: Self-scheduling hooks and capabilities prefixed with `ffc_self_scheduling_`

## 4.3.0 (2026-02-02)

WordPress Plugin Check compliance and distribution cleanup.

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
- Removed development files from distribution (tests, docs, CI, composer, phpqrcode cache)

## 4.2.0 (2026-01-30)

CSV export enhancements and calendar translations.

- Added: Expand `custom_data` and `data_encrypted` JSON fields into individual CSV columns
- Added: Decrypt encrypted data for certificate CSV dynamic columns
- Added: 285 missing pt_BR translations for calendar and appointment system
- Updated: English language file with all new calendar strings

## 4.1.1 (2026-01-27)

Appointment receipts, validation codes, and admin improvements.

- Added: Appointment receipt and confirmation page generation
- Added: Appointment PDF generator for client-side receipts
- Added: Unique validation codes with formatted display (XXXX-XXXX-XXXX)
- Added: Appointments column in admin users list
- Added: Login-as-user link always visible in users list
- Added: Permission checks to dashboard tabs visibility

## 4.1.0 (2026-01-27)

New appointment calendar and booking system.

- Added: Calendar Custom Post Type with configurable time slots, durations, business hours, and capacity
- Added: Frontend booking widget with real-time slot availability (`[ffc_calendar]` shortcode)
- Added: Appointment booking handler with approval workflow (auto-approve or manual)
- Added: Email notifications: confirmation, approval, cancellation, and reminders
- Added: Admin calendar editor with blocked dates management
- Added: CSV export for appointments with date and status filters
- Added: REST API endpoints for calendars and appointments
- Added: CPF/RF field on booking forms with mask validation
- Added: Honeypot and math captcha security on booking forms
- Added: Automatic appointment cancellation when calendar is deleted
- Added: Minimum interval between bookings setting
- Added: Automatic migration for `cpf_rf` columns on appointments table
- Added: Appointment cleanup functionality in calendar settings
- Added: User creation on appointment confirmation
- New tables: `ffc_calendars`, `ffc_appointments`, `ffc_blocked_dates`
- New classes: `CalendarCpt`, `CalendarEditor`, `CalendarAdmin`, `CalendarActivator`, `CalendarShortcode`, `AppointmentHandler`, `AppointmentEmailHandler`, `AppointmentCsvExporter`, `CalendarRepository`, `AppointmentRepository`, `BlockedDateRepository`

## 4.0.0 (2026-01-26)

Breaking release: removal of backward-compatibility aliases and namespace finalization. **First stable tag bump from 2.8.0** since the 2.9.x development cycle began.

_The Data Encryption framework, first introduced during the 2.9.x development cycle, is considered stable and integrated across the codebase from this release forward._

- BREAKING: Removed all backward-compatibility aliases for old `FFC_*` class names
- All 88 classes now exclusively use `FreeFormCertificate\*` namespaces
- Converted all remaining `\FFC_*` references to fully qualified namespaces
- Renamed `CSVExporter` to `CsvExporter` for PSR naming consistency
- Removed all obsolete `require_once` statements (autoloader handles loading)
- Added global namespace prefix (`\`) to all WordPress core classes in namespaced files
- Fixed: Loader initialization with correct namespaced class references
- Fixed: Class autoloading for restructured file paths
- Fixed: PHPDoc type hints across 3 files
- Fixed: CSV export error handling, UTF-8 encoding, and multi-form filters
- Fixed: REST API 500 error from broken encrypted email search
- Fixed: `json_decode` null handling for PHP 8+ compatibility
- Enhanced: CSV export with all DB columns and multi-form filters
- Finalized PSR-4 cleanup across all modules

## 3.3.1 (2026-01-25)

Bug fixes for strict types introduction.

- Fixed: Type errors caused by `strict_types` across multiple classes
- Fixed: String-to-int conversions for database IDs in multiple locations
- Fixed: Return type mismatches in `trash`/`restore`/`delete` operations (int|false to bool)
- Fixed: `log_submission_updated` call with correct parameter type
- Fixed: `update_submission` return type conversion to bool
- Fixed: `ensure_magic_token` to return string type consistently
- Fixed: `json_decode` null check in `detect_reprint`
- Fixed: `hasEditInfo` return type conversion to int
- Fixed: `form_id` and `edited_by` type casting in CSV export
- Fixed: Missing SMTP fields in settings save handler
- Fixed: Checkbox styles override for WordPress core compatibility
- Fixed: `$real_submission_date` initialization in both reprint and new submission paths
- Fixed: Null handling in `get_user_certificates` and `get_user_profile`
- Fixed: PHP notices in REST API preventing JSON output corruption

## 3.3.0 (2026-01-25)

Strict types and full type hints.

- Added: `declare(strict_types=1)` to all PHP files
- Added: Full type hints (parameter types, return types) across all classes
- Affected: Core, Repositories, Migration Strategies, Settings Tabs, User Dashboard, Shortcodes, Security, Generators, Frontend, Integrations, Submissions

## 3.2.0 (2026-01-25)

PSR-4 autoloader and namespace migration.

- Added: PSR-4 autoloader (`class-ffc-autoloader.php`) with namespace-to-directory mapping
- Migrated: All 88 classes to PHP namespaces in 15 migration steps
- Namespaces: `FreeFormCertificate\Admin`, `API`, `Calendars`, `Core`, `Frontend`, `Generators`, `Integrations`, `Migrations`, `Repositories`, `Security`, `Settings`, `Shortcodes`, `Submissions`, `UserDashboard`
- Added: Backward-compatibility aliases for all old `FFC_*` class names (removed in 4.0.0)
- Added: Developer migration guide and hooks documentation

## 3.1.0 (2026-01-24)

_(development version, **not released as stable**; the Stable tag remained at 2.8.0 throughout the 3.x line until 4.0.0 finalization.)_

User dashboard, admin tools, and activity log viewer.

- Added: User Dashboard system with `ffc_user` role and `[user_dashboard_personal]` shortcode
- Added: Access control class for permission management
- Added: User manager for dashboard data retrieval
- Added: Admin user columns (certificate count, appointment count)
- Added: Debug utility class with configurable logging
- Added: Activity Log admin viewer page with filtering
- Added: Admin assets manager for centralized enqueue
- Added: Admin submission edit page for manual record updates
- Added: Admin notice manager for migration feedback
- Added: Form editor metabox renderer (separated from save handler)
- Added: Dashboard page auto-creation on activation
- Refactored: Email handler focused on delivery (removed inline styles)
- Refactored: REST controller optimized
- Removed: All inline styles (moved to CSS files)
- Added: User creation email controls

## 3.0.0 (2026-01-20)

Repository pattern, REST API, geofence, and migration manager.

- Added: Repository pattern (`AbstractRepository`, `SubmissionRepository`, `FormRepository`)
- Added: REST API controller for external integrations
- Added: Geofence class for GPS/IP-based area restrictions
- Added: IP Geolocation integration
- Added: Migration manager with batch processing
- Added: Data sanitizer for input cleaning
- Added: Migration status calculator
- Added: Page manager for auto-created plugin pages
- Added: Magic Link helper class
- Refactored: Frontend class as lightweight orchestrator
- Added: Complete JavaScript translations (admin, frontend, form editor, template manager)
- Added: Form Editor and Template Manager i18n
- Improved: GPS cache TTL configuration
- Improved: GPS validation with mandatory fields and meter units
- Fixed: Incomplete CPF/RF cleanup for LGPD compliance
- Fixed: OFFSET bug in batch migrations
- Fixed: Slow submission deletion causing 500 errors
- Fixed: Missing Activity Log methods

_Starting with commit [`53cc4fa`](https://github.com/rpgmem/ffcertificate/commit/53cc4fa4063bb497f5948d79897c022c5c0494e2) (2026-01-17), the plugin is developed in collaboration with [Claude](https://claude.ai/code) (Anthropic) as an AI-powered coding assistant. This is the first AI-assisted contribution; Claude's involvement extends to all subsequent commits and releases unless explicitly noted otherwise in this changelog._

## 2.10.0 (2026-01-20)

Rate limiting with dedicated database tables.

- Added: Rate Limiter with dedicated database tables (`ffc_rate_limits`, `ffc_rate_limit_logs`)
- Added: Rate Limit Activator for table creation
- Added: Configurable rate limit thresholds per action type
- Migrated: Rate Limiter from WordPress transients to Object Cache API

## 2.9.x development cycle (2026-01-03 → 2026-01-14)

_(development versions, **not released as stable**; Stable tag remained at 2.8.0 throughout. Reconstructed from forensic source diffs of the `wp-ffcertificate03-01-2026.zip` through `wp-ffcertificate14-01-2026.zip` snapshots.)_

Internal versioning bumped from `2.9.16` → `2.9.17` → `2.9.19` (header) / `FFC_VERSION` constant matched, with the publishable `Stable tag` deliberately frozen at 2.8.0 throughout.

- Added: First appearance of the **Data Encryption framework** for sensitive fields (email, CPF, IP). The framework continued to evolve through 3.x and was considered fully integrated by 4.0.0.
- Added: REST API controller (`includes/api/class-ffc-rest-controller.php`) for external integrations.
- Added: Repository pattern groundwork — `abstract-repository.php`, `form-repository.php`, `submission-repository.php`.
- Added: Rate Limiter UI/CSS (`assets/css/admin-rate-limit.css`, `assets/js/rate-limit-countdown.js`, `assets/js/rate-limit-frontend.js`) and dedicated settings tab (`includes/settings/class-ffc-tab-rate-limit.php`, `includes/settings/tab-rate-limit.php`).
- Added: Activity Log refinements (`class-ffc-rate-limit-activator.php` joins the activator family).
- Added: Hooks documentation under `docs/HOOKS-DOCUMENTATION.md` and `docs/HOOKS-QUICK-REFERENCE.md`.
- Added: Composer-managed vendor directory and PSR-style structure groundwork (file count grew from ~90 to ~500 between the 23/12 and 03/01 snapshots).
- Added: Pre-compiled localization (`languages/ffc-pt_BR.l10n.php`) for PHP-translation-cache support.
- Added: General admin settings stylesheet (`assets/css/admin-settings.css`) and shared frontend utilities (`assets/js/ffc-utils.js`).

## 2.9.1 (2025-12-29)

Activity log, form cache, and magic links fix.

- Fixed: Magic Links fatal error (critical bug)
- Fixed: Duplicate `require` in loader
- Added: Activity Log with `ffc_activity_logs` table for audit trail
- Added: Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
- Added: Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

## 2.9.0 (2025-12-28)

QR Code generation on certificates.

- Added: QR Code generation on certificates linking to verification page
- Added: QR Code generator class using phpqrcode library
- Added: QR Code settings tab with size and error correction configuration

_Note: QR Code work first appeared as experimental code in the 2.5.0 development snapshot, was rolled back, and was resumed and finalized in this release._

## 2.8.0 (2025-12-28)

Magic links for one-click certificate access.

- Added: Magic Links for one-click certificate access via email
- Added: Certificate preview page with modern responsive layout
- Added: `magic_token` column (VARCHAR 32) with database index on `ffc_submissions`
- Added: Automatic token generation using `random_bytes(16)` for all new submissions
- Added: Backward migration: token backfill for existing submissions on activation
- Added: Rate limiting for verification (10 attempts/minute per IP via transients)
- Added: `verify_by_magic_token()` method in Verification Handler
- Added: Magic link detection via `?token=` parameter in Shortcodes class
- Improved: Email template with magic link button, certificate preview, and fallback URL
- Improved: AJAX verification without page reload
- Improved: Frontend with loading spinner, download button state management

## 2.7.0 (2025-12-28)

Modular architecture refactoring.

- Refactored: Complete modular architecture with 15 specialized classes
- Added: `FFC_Shortcodes` class for shortcode rendering
- Added: `FFC_Form_Processor` class for form validation and processing
- Added: `FFC_Verification_Handler` class for certificate verification
- Added: `FFC_Email_Handler` class for email functionality
- Added: `FFC_CSV_Exporter` class for CSV export operations
- Refactored: `FFC_Frontend` reduced from 600 to 150 lines (now orchestrator only)
- Refactored: `FFC_Submission_Handler` to pure CRUD operations (400 to 150 lines)
- Added: Dependency injection container in `FFC_Loader`
- Applied: Single Responsibility Principle (SRP) throughout

## 2.6.0 (2025-12-28)

Code reorganization and internationalization.

- Refactored: Complete code reorganization with modular OOP structure
- Separated: `class-ffc-cpt.php` (CPT registration only) from `class-ffc-form-editor.php` (metaboxes)
- Added: `update_submission()` and `delete_all_submissions()` methods
- Added: Full internationalization (i18n) with all PHP strings wrapped in `__()` / `_e()`
- Added: JavaScript localization via `wp_localize_script()`
- Added: `.pot` translation template file
- Consolidated: All inline styles moved to `ffc-admin.css` and `ffc-frontend.css`
- Removed: Dead code and redundancies
- Fixed: Missing method calls
- Fixed: Duplicate metabox registration
- Fixed: SMTP settings toggle visibility

## 2.5.0 (2025-12-14)

Development snapshot leading up to the 2.6.0 release; **never published as stable**. Reconstructed from forensic source diffs of the `wp-ffcertificate14-12-2025.zip`, `wp-ffcertificate16-12-2025.zip`, and `wp-ffcertificate23-12-2025.zip` snapshots.

- Added: Foundation work for the modular OOP refactor that was finalized in 2.6.0 — first split of `includes/` into `admin/`, `core/`, `data/`, and `frontend/` subdirectories with dedicated classes (`class-ffc-pdf-generator.php`, `class-ffc-submission-controller.php`, `class-ffc-mailer.php`, `class-ffc-template-engine.php`, `class-ffc-repository.php`).
- Added: Initial QR Code experimentation (3 references in `includes/` source). The work was rolled back in the next snapshot (16/12 → 23/12) and resumed/finalized in 2.9.0.
- Added: `FFC_VERSION` constant for CSS/JS cache busting (developer comment in source: _"Adicionamos FFC_VERSION para controle de cache dos arquivos CSS/JS"_).
- Added: Multiple certificate template HTML files and background images bundled in `html/`.
- Internal: Local git workflow adopted at this stage (the 23/12 snapshot includes a `.git` directory).

## 2.4.0 (2025-12-13)

- Internal improvements

## 2.3.0 (2025-12-12)

- Internal improvements

## 2.2.0 (2025-12-11)

- Internal improvements

## 2.1.0 (2025-12-10)

- Internal improvements

## 2.0.0 (2025-12-08)

PDF generation overhaul, captcha, and reprint logic.

- Refactored: PDF generation from simple image to high-fidelity A4 Landscape (1123x794px) using jsPDF
- Added: Dynamic Math Captcha with hash validation on backend
- Added: Honeypot field for spam bot protection
- Added: Reprint logic for certificate recovery (duplicate detection)
- Added: PDF download buttons directly in admin submissions list
- Added: Mobile optimization with strategic delays and progress overlay
- Fixed: CORS issues with `crossorigin="anonymous"` on image rendering

## 1.5.0 (2025-12-05)

Ticket system and form cloning.

- Added: Ticket system with single-use codes for exclusive form access
- Added: Form cloning (duplication) functionality
- Added: Global settings tab with automatic log cleanup configuration
- Added: Denylist for blocking specific IDs

## 1.0.7 (~2025-12-12)

_Reconstructed from forensic source diff of `wp-ffcertificate_12_12_2025.zip`. The 1.0.x patch series between 1.0.0 and 1.5.0 was not separately documented in the developer's own changelog inside the 4.0.0 zip; this entry is reconstructed solely from that snapshot's plugin header (`Version: 1.0.7`) and file listing._

- Maintenance patch series leading from 1.0.0 to 1.5.0; specific change details are unrecoverable from the available evidence.
- Snapshot file inventory: 23 files (`assets/`, `ffc.pot`, `html/`, `includes/`, `readme.txt`, `wp-ffcertificate.php`) — pre-modular monolithic structure.
- Plugin header version stamped at `1.0.7`; no `FFC_VERSION` constant yet (the constant was introduced during the 2.5.0 development cycle).

## 1.0.0 (2025-11-25)

Initial release.

- Form Builder with drag & drop interface (Text, Email, Number, Date, Select, Radio, Textarea, Hidden fields)
- PDF certificate generation (client-side)
- CSV export with form and date filters
- Submissions management in admin
- ID-based restriction (CPF/RF) with allowlist mode
- Asynchronous email notifications via WP-Cron
- Automatic cleanup of old submissions
- Verification shortcode `[ffc_verification]`
