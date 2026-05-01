=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 6.0.0
Requires PHP: 8.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create dynamic forms, generate PDF certificates, and validate authenticity with magic link access.

== Description ==

Free Form Certificate is a complete WordPress solution for creating dynamic forms, generating PDF certificates, scheduling appointments, and verifying document authenticity. Built with a fully namespaced, modular architecture using the Repository pattern and Strategy pattern for maximum maintainability.

= Core Features =

* **Drag & Drop Form Builder** - Custom fields: Text, Email, Number, Date, Select, Radio, Textarea, Hidden, Info Block, and Embed (Media).
* **Client-Side PDF Generation** - A4 landscape certificates using html2canvas and jsPDF, with custom background images.
* **Magic Links** - One-click certificate access via unique, cryptographically secure URLs sent by email.
* **Verification System** - Certificate authenticity validation via unique code or magic token.
* **QR Codes** - Auto-generated QR codes on certificates linking to the verification page.

= Self-Scheduling (Personal Calendars) =

* **Calendar Management** - Create multiple calendars with configurable time slots, durations, and business hours.
* **Appointment Booking** - Frontend booking widget with real-time slot availability.
* **Email Notifications** - Confirmation, approval, cancellation, and reminder emails.
* **PDF Receipts** - Downloadable appointment receipts generated client-side.
* **Admin Dashboard** - Manage, approve, and export appointments.

= Audience Scheduling (Group Bookings) =

* **Audience Management** - Create audiences (groups) with hierarchical structure and color coding.
* **Environment Management** - Configure physical spaces with calendars, working hours, and capacity.
* **Group Bookings** - Schedule activities for entire audiences or individual users.
* **CSV Import & Export** - Import and export audiences and members from/to CSV files with user creation.
* **Conflict Detection** - Real-time conflict checking before booking confirmation.
* **Email Notifications** - Automatic notifications for new bookings and cancellations.

= Reregistration =

* **Campaign Management** - Create reregistration campaigns linked to audiences with configurable periods.
* **Custom Fields** - Define per-audience custom fields (text, textarea, number, date, select, checkbox) with validation.
* **Email Notifications** - Invitation, reminder, and confirmation emails with configurable templates.
* **Approval Workflow** - Manual or auto-approve submissions with admin review interface.
* **Ficha PDF** - Generate PDF records for submissions with customizable templates.
* **Dashboard Integration** - Users see reregistration banners and can submit/download ficha from their dashboard.

= Security & Restrictions =

* **Geofencing** - Restrict form access by GPS coordinates or IP-based areas.
* **Rate Limiting** - Configurable attempt limits per IP with automatic blocking.
* **ID-Based Restriction** - Control certificate issuance via CPF/RF document validation.
* **Ticket System** - Import single-use access codes for exclusive form access.
* **Allowlist / Denylist** - Whitelist or block specific IDs.
* **Math Captcha & Honeypot** - Built-in bot protection on all forms.
* **Data Encryption** - Sensitive fields (email, CPF, IP) encrypted at rest.

= Administration =

* **Activity Log** - Full audit trail of admin and user actions.
* **User Dashboard** - Personalized frontend dashboard for certificates and appointments.
* **CSV Export** - Export submissions and appointments with date and form filters.
* **Data Migrations** - Automated migration framework with progress tracking and rollback.
* **SMTP Configuration** - Built-in SMTP settings for reliable email delivery.
* **REST API** - Full REST API for external integrations.

== Installation ==

1. Upload the `ffcertificate` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Navigate to "Free Form Certificate" to create your first form.
4. Use the shortcode `[ffc_form id="FORM_ID"]` on any page or post.

== Frequently Asked Questions ==

= How do I create a form? =

1. Go to "Free Form Certificate" > "Add New Form".
2. Enter a title and use the Form Builder to add fields.
3. Configure the certificate layout in the "Certificate Layout" section.
4. Save and copy the generated shortcode.

= What are Magic Links? =

Magic Links are unique, secure URLs sent via email that allow recipients to instantly access and download their certificates with a single click. Each link contains a cryptographically secure 32-character token.

= How do I set up the verification page? =

The plugin creates a `/valid` page automatically during activation. You can also create a page manually with `[ffc_verification]`.

= How do I create a calendar? =

1. Go to "Free Form Certificate" > "Calendars" > "Add New".
2. Configure business hours, slot duration, and capacity.
3. Use the shortcode `[ffc_calendar id="CALENDAR_ID"]` on any page.

= Can I restrict who generates certificates? =

Yes. In each form's "Restriction & Security" section you can enable allowlist mode, use the ticket system, block IDs via denylist, or restrict by geographic area via geofencing.

= Does the plugin work with page cache plugins (WP Rocket, LiteSpeed Cache, W3 Total Cache)? =

Yes. The plugin includes built-in cache compatibility:

* **Forms (captcha & nonces):** A "Dynamic Fragments" system automatically refreshes captcha challenges and security nonces via AJAX after page load, so forms work correctly even when the HTML is served from a full-page cache.
* **Dashboard pages:** The `[user_dashboard_personal]` shortcode automatically sets the `DONOTCACHEPAGE` constant, sends standard no-cache headers, and triggers LiteSpeed-specific exclusion hooks. This ensures user-specific data is never cached.
* **AJAX endpoints:** All form submissions and data fetching use `admin-ajax.php`, which is excluded from page cache by default in all major cache plugins.
* **Diagnostics:** Go to FFC Settings > Cache tab to see the "Page Cache Compatibility" card, which shows the status of all cache-related features and detects your active cache plugin.

No manual configuration of cache exclusion rules is needed.

= How do I translate the plugin? =

The plugin is fully translation-ready with the `ffcertificate` text domain. Use Loco Translate or Poedit with the `languages/ffcertificate.pot` template file. Portuguese (Brazil) translation is included.

== Screenshots ==

1. Form Builder with drag & drop interface
2. Certificate layout editor with live preview
3. Submissions management with PDF download
4. Security settings (allowlist, tickets, denylist)
5. Frontend certificate generation
6. Magic link email with one-click access
7. Certificate preview page with download button
8. Appointment calendar frontend booking

== Shortcodes ==

= [ffc_form] =
Displays a certificate issuance form.

* `id` (required) - Form ID.

Example: `[ffc_form id="123"]`

= [ffc_verification] =
Displays the certificate verification interface. Automatically detects magic links via the `?token=` parameter.

Example: `[ffc_verification]`

= [ffc_calendar] =
Displays an appointment calendar with booking widget.

* `id` (required) - Calendar ID.

Example: `[ffc_calendar id="456"]`

= [ffc_audience_calendar] =
Displays the audience scheduling calendar for group bookings.

Example: `[ffc_audience_calendar]`

= [user_dashboard_personal] =
Displays the user's personal dashboard with certificates, appointments, audience bookings, and profile.

Example: `[user_dashboard_personal]`

== Layout & Placeholders ==

In the certificate layout editor, use these dynamic tags:

= System Tags =
* `{{auth_code}}` - 12-digit authentication code (formatted XXXX-XXXX-XXXX)
* `{{form_title}}` - Current form title
* `{{submission_date}}` - Issuance date (formatted per WordPress settings)
* `{{submission_id}}` - Numeric submission ID
* `{{validation_url}}` - Verification page URL

= Form Field Tags =
* `{{field_name}}` - Any field name defined in the Form Builder
* Common examples: `{{name}}`, `{{email}}`, `{{cpf_rf}}`, `{{ticket}}`

== Changelog ==

= 5.4.1 (2026-04-24) =

Certificate HTML editor gains CodeMirror syntax highlighting with distinct coloring for HTML tags and `{{placeholder}}` tokens, plus a three-option `Code Editor Theme` setting (Auto / Light / Dark, dark by default on fresh installs) with a VS-Code-Dark+-inspired palette; the email body moves to a lightweight visual editor (`wp_editor()` teeny); the global TinyMCE placeholder-protection filter is scoped to the plugin's post type so it no longer touches unrelated admin screens; a new per-calendar admin-bypass toggle replaces the hardcoded all-or-nothing bypass for self-scheduling; and the `[ffc_verification]` result card header stops rendering with the admin preview modal's dark slate background.

* Feat: **CodeMirror integration for the certificate HTML editor** — `ffc_pdf_layout` now renders through WordPress's built-in CodeMirror (`wp_enqueue_code_editor`), giving tags, attributes and strings distinct colors and line numbers. A custom overlay paints `{{placeholder}}` tokens in a separate color so variables stand out from markup. The underlying `<textarea>` is preserved and its value is synced on every change, so form submission and saved HTML remain byte-for-byte identical to the previous plain-textarea path; certificate templates and PDF generation are not affected.
* Feat: **Syntax Highlighting profile notice** — when the user has disabled "Syntax Highlighting" in their WordPress profile, `wp_enqueue_code_editor()` returns false; a small inline description appears below the editor linking to `profile.php#syntax_highlighting` and recommending that the option be enabled for maximum plugin compatibility. The textarea continues to work as before.
* Feat: **Email body upgraded to `wp_editor()` teeny** — the `email_body` field moves from a plain textarea to a minimal-toolbar visual editor (bold, italic, underline, lists, link/unlink, undo/redo). Media buttons stay disabled. Placeholders such as `{{auth_code}}` and `{{name}}` are preserved thanks to the `tiny_mce_before_init` protection still in place.
* Feat: **Per-calendar admin-bypass toggle for self-scheduling** — a new `Admin Bypass` checkbox in the Booking Rules metabox of each `ffc_self_scheduling` calendar decides whether users with `manage_options` / `ffc_scheduling_bypass` skip that specific calendar's booking restrictions (advance-booking window, past-date guard, blocked dates, working hours, daily/interval limits, cancellation allowance/deadline). Slot capacity is always enforced. Replaces the previous hardcoded all-or-nothing bypass. Calendars saved before 5.4.1 default to the historical on-state so behavior is preserved without a migration.
* Feat: **Code Editor Theme setting** — new select on the Advanced settings tab (`Auto` / `Light` / `Dark`) controlling the appearance of the Certificate HTML editor. `Auto` mirrors the plugin's admin Dark Mode preference; fresh installs default to `Dark`. Dark theme ships with a VS-Code-Dark+-inspired palette (background #1e1e1e, blue tags, orange strings, green comments) and keeps `{{placeholder}}` tokens legible with a gold-on-dark overlay. Light theme uses WordPress's default CodeMirror styling with zero extra payload.
* Change: **TinyMCE placeholder filter scoped to `ffc_form`** — `Admin::configure_tinymce_placeholders()` is no longer attached to `tiny_mce_before_init` globally from the constructor. A new `maybe_register_tinymce_placeholder_filter()` method registers the filter on `admin_head` only when `get_current_screen()->post_type === 'ffc_form'`, eliminating side effects on Classic Editor posts and third-party plugin screens.
* Change: **Email body sanitization hardened** — save handler now runs `email_body` through `wp_kses_post()` (the canonical WordPress post-content allowlist) instead of the generic plugin allowlist, aligning with its new authoring surface.
* Change: **CSS placeholder block refactored** — the orphan `.mce-content-body .ffc-placeholder` selector (unreachable while no `wp_editor()` existed) is removed in favor of a single `.ffc-placeholder` rule plus new CodeMirror-specific styles (`.ffc-code-editor-wrapper .CodeMirror`, `.cm-ffc-placeholder-token`, `.ffc-code-editor-notice`).
* Change: **`CalendarRepository::userHasSchedulingBypass()` accepts a calendar context** — the method now takes an optional calendar post ID and consults `_ffc_self_scheduling_config['admin_bypass']`. Callers that pass null (audience/REST admin read paths) keep the historical capability-only semantics unchanged; booking and cancellation flows pass the calendar id so the toggle takes effect.
* Fix: **`[ffc_verification]` result card header showed a black bar instead of the blue gradient** — a later `.ffc-preview-header` rule intended only for the admin preview modal (`#ffc-preview-modal`) was unscoped and overrode the verification card's blue gradient + centered title, also forcing `display: flex; justify-content: space-between` which visually separated the badge from the appointment status label. All five affected modal-only selectors are now scoped to `#ffc-preview-modal`, restoring the intended header on certificate, appointment and reregistration verification results.
* Security (MEDIUM): `wp_kses_post()` applied to `email_body` in the form editor save handler — scripts, forms, and other disallowed tags are stripped on save of the now-rich email template.
* Test: new unit tests covering `maybe_register_tinymce_placeholder_filter()` across three screen states (ffc_form, other post type, null screen), plus four new tests in `CalendarRepositoryTest` for the per-calendar `admin_bypass` consumption path (toggle off, toggle on, legacy calendar without the key, unprivileged user with any setting).
* Chore: new JS module `assets/js/ffc-admin-code-editor.js` (+ minified build) initializes CodeMirror, adds the placeholder overlay, keeps the textarea in sync on change and on submit, and degrades gracefully when the code editor is unavailable.

= 5.4.0 (2026-04-23) =

Encryption and privacy hardening across the user-data surface, the accumulated security audit (Tier 1 + Tier 2), CSV download intermediate screen, a performance pass for admin submissions at scale, and the `UserProfileService` refactor consolidating profile reads and writes through a single entry point.

* Feat: **Centralized sensitive-field policy** via `FreeFormCertificate\Core\SensitiveFieldRegistry` — single declarative map replacing three hard-coded lists. Consumed by `SubmissionHandler` and `AppointmentRepository`.
* Feat: **`UserProfileFieldMap`** — per-field descriptor declaring storage layer (`wp_users`, `ffc_user_profiles`, `wp_usermeta`), sensitivity, hashability, and optional mirror targets (e.g. `display_name` → `wp_users.display_name`).
* Feat: **`ViewPolicy` enum** (`FULL`, `MASKED`, `HASHED_ONLY`) declaring how sensitive fields are rendered on read. The service audits but does not elevate privileges; callers validate capability.
* Feat: **`UserProfileService::read()` / `::write()`** — single entry point consolidating profile reads and writes across the three storage layers with transparent encryption, hashing, and mirror syncing. `FULL` reads that touch sensitive fields emit a metadata-only audit entry. `write()` accepts `$extra_descriptors` for dynamic reregistration keys outside the static map; overrides are per-call and cleared via try/finally.
* Feat: **`email_hash_rehash` migration** — batched, idempotent, cursor-based. Rewrites legacy unsalted `email_hash` values in `wp_ffc_submissions` and `wp_ffc_self_scheduling_appointments`.
* Feat: **`activity_log_clear_plaintext` migration** — NULLs the `context` plaintext column on activity log rows that already hold a ciphertext, closing the dual-storage leak on historical data.
* Feat: **CSV download intermediate screen** — info screen showing form restrictions, dates, geolocation, quiz, and quota between hash validation and download. Download button only enabled after the form has ended; certificate preview available before the collection period begins.
* Feat: **Public CSV sync-export row cap** — new `public_csv_sync_max_rows` setting (Advanced tab, default 2000, range 100–10000).
* Change: **Activity log encryption gate** switched from a hard-coded action whitelist to payload inspection via `SensitiveFieldRegistry::contains_sensitive()`. Actions carrying sensitive fields (including nested payloads) are encrypted automatically; actions with trivial payloads are no longer wrapped in a meaningless ciphertext.
* Change: **`ActivityLog::log()`** no longer dual-stores `context` plaintext alongside its ciphertext. Sensitive rows NULL the plaintext column; reads decrypt transparently via `ActivityLogQuery::resolve_context()`.
* Change: **`UserManager::update_profile()` and `::update_extended_profile()`** are now thin facades over `UserProfileService::write()`. Legacy inline encrypt/hash paths are gone. Behavior improvement: clearing a sensitive field now also deletes the sibling `*_hash` meta row.
* Change: **`Encryption::decrypt()`** split into a public wrapper and a private helper; emits a `decrypt_failure` WARNING to `ActivityLog` whenever a non-empty ciphertext resolves to null.
* Change: Residual `class_exists ? Encryption::hash : hash('sha256')` fallbacks removed from `SelfSchedulingAppointmentHandler` and `SubmissionRepository::hash()`.
* Change: Encryption envelope produces authenticated **v2 ciphertexts** (encrypt-then-MAC, HMAC-SHA256); legacy v1 ciphertexts remain decryptable.
* Perf: `SubmissionRepository::countByStatus()` cached in a 5-minute transient; composite index `(form_id, status, submission_date)` on `ffc_submissions`; activity log cleanup admin_init fallback; `findPaginated()` search optimizations.
* Fix: **Email hash divergence** between `wp_ffc_submissions` and `wp_ffc_self_scheduling_appointments` — same email produced different hashes, breaking cross-entity lookups. Unified on `Encryption::hash`.
* Fix: **`AppointmentRepository::findByCpfRf`** never matched its own writes (raw SHA-256 read vs salted write).
* Fix: **`UserCleanup::handle_email_change`** reindexed submission `email_hash` with raw SHA-256, overwriting correct salted hashes on every email change.
* Fix: **`SecurityService::verify_simple_captcha`** rejected valid answer `0` (`empty('0')` is true in PHP). `hash_equals()` used for timing-safe comparison.
* Fix: **`json_decode(null)` deprecation** in `SubmissionRestController::decrypt_submission_data` (PHP 8.1+).
* Fix: Activity log disabled-notice link pointed to `Settings > General`; toggle lives in `Settings > Advanced`.
* Security (CRITICAL / LGPD): cross-table hash consistency — dedup and reconciliation between submissions, appointments and user profile now works reliably.
* Security (HIGH / LGPD): activity log no longer dual-stores PII plaintext alongside ciphertext.
* Security (MEDIUM / LGPD): decrypt failures auditable — HMAC mismatch, key-rotation breakage and corruption stop being silent.
* Security (HIGH): column-name SQL injection hardening in `AbstractRepository::build_where_clause()` via `%i` placeholder and allowlist.
* Security (HIGH): timing-safe token comparison via `hash_equals()` in appointment receipt handler; escape user-supplied values in audience email templates.
* Security (MEDIUM): `SubmissionRestController` admin endpoints restricted to `manage_options`; `UserProfileRestController` sanitizes user input; `AudienceRepository` replaces inline `IN()` interpolation with parameterized placeholders.
* Security (MEDIUM / XSS): escape output in `SubmissionsList`, frontend field renderer, PDF layout `{{form_title}}` substitution, and admin-configured email bodies.
* Security (MEDIUM / transport): `IpGeolocation` HTTPS opt-in; `RateLimiter` only trusts `REMOTE_ADDR` unless `ffc_trust_forwarded_headers` is enabled; magic-link QR codes generated locally.
* Security (MEDIUM / path traversal): validate receipt template path; allowlist reregistration email templates; move ICS temp files out of public uploads.
* Security (LOW): remove `$e->getMessage()` from client-facing error responses; `Admin::redirect_with_msg()` builds target from `page`/`post_type`; various minor output-escaping fixes.
* Security (privacy): hash PII identifiers before logging (`RateLimiter`, `IpGeolocation`) for LGPD compliance.
* Docs: `SECURITY.md` supported-versions table updated to `5.4.x`. `CONTRIBUTING.md` Branches section no longer mandates the `claude/*` prefix for human contributors; Releasing section documents the `[Unreleased] → [X.Y.Z]` workflow.
* Test: **3234 → 3485 tests** (+251) with **8783 assertions** — new suites for `SensitiveFieldRegistry`, `SensitiveFieldPolicyTest`, `DecryptFailureLoggingTest`, `UserProfileFieldMapTest`, `UserProfileServiceTest`, `CustomFieldValidator`, `Autoloader`, `UserContextTrait`, `MigrationDynamicReregFields`, `ReregistrationStandardFieldsSeeder`, `AbstractRepository`, `Geofence`, `FormListColumns`, and new coverage for `UserManager::update_extended_profile` / `get_extended_profile`.
* Chore: WPCS **1232 → 0 errors** across 161 files; PHPStan level 7 **3 → 0 errors**.

= 5.3.0 (2026-04-17) =

Full-page cache compatibility, per-form captcha isolation, and CI pipeline improvements.

* Feat: **Full-page cache compatibility** — forms and calendars now work correctly with LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache. Business-hours restricted calendars send no-cache headers to prevent stale content; audience shortcodes prevent cross-user cache leakage for logged-in users.
* Feat: **Dynamic Fragments geofence refresh** — AJAX endpoint returns fresh geofence configs per form so cached pages always show up-to-date availability windows.
* Feat: **Automatic cache purge on save** — saving a form or calendar automatically purges cached pages that embed it (LiteSpeed, WP Rocket, W3TC, WP Super Cache).
* Feat: **CSV Download Page URL setting** — new field on General settings tab.
* Feat: **Search forms by ID** — admin forms list now supports searching by numeric post ID.
* Fix: **Same captcha on all forms** — multiple forms on a cached page now each get a unique math captcha.
* Refactor: **CustomFieldValidator extraction** from CustomFieldRepository for single-responsibility.
* Refactor: Expanded in-plugin Documentation tab with additional sections.
* CI: Remove duplicate push triggers, extract composite action, add Dependabot auto-merge, promote PHPCS to gating, promote PHPStan to level 7.
* Chore: Auto-fix ~83k PHPCS violations, annotate 223 false positives, Phase 3 mechanical fixes.

For the complete changelog history, see [CHANGELOG.md](CHANGELOG.md).

== Upgrade Notice ==

= 5.4.1 =
Certificate HTML editor now uses CodeMirror with VS-Code-style syntax highlighting (Auto/Light/Dark theme, dark by default). Email body upgraded to a lightweight visual editor with `wp_kses_post()` hardening. Per-calendar admin-bypass toggle for self-scheduling replaces the previous all-or-nothing bypass. Fixed dark-header bug on `[ffc_verification]` result cards. No database changes. No breaking changes.

= 5.4.0 =
Encryption and privacy hardening across the user-data surface (centralized sensitive-field policy, payload-driven activity log encryption, auditable decrypt failures). Tier 1 + Tier 2 security audit applied. CSV download intermediate screen and performance pass for admin submissions at scale. Migration `email_hash_rehash` runs automatically. Backup recommended.

= 5.3.0 =
Full-page cache compatibility (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache). Per-form captcha isolation. CI pipeline improvements. No database changes. No breaking changes.

For older releases, see [CHANGELOG.md](CHANGELOG.md).

== Privacy & Data Handling ==

= Data Collected =
* User submissions (name, email, custom fields)
* IP addresses (for rate limiting and audit trail)
* Appointment bookings (date, time, contact details)
* Submission and action timestamps

= Data Storage =
* Submissions stored in `wp_ffc_submissions` table with optional field encryption
* Appointments stored in `wp_ffc_appointments` table
* Rate limiting data stored in `wp_ffc_rate_limits` table
* Activity logs stored in `wp_ffc_activity_logs` table

= Data Retention =
* Configurable automatic cleanup for old submissions
* Manual deletion available in admin panel
* Deleting a submission invalidates its magic link and QR code
