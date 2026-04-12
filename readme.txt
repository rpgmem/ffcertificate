=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 5.1.0
Requires PHP: 7.4
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

= 5.1.0 (2026-04-11) =

New public CSV download feature allowing form organizers without WordPress admin access to retrieve submissions via a revocable per-form hash, gated by form expiration and a configurable download quota.

* Feat: New `[ffc_csv_download]` shortcode — public page where visitors enter a form ID + hash to download submissions as CSV
* Feat: New `PublicCsvDownload` handler on `admin-post(_nopriv)_ffc_public_csv_download` — validates nonce, honeypot, CAPTCHA, per-IP rate limit, form-level enable flag, hash equality, geofence expiration, and per-form download quota
* Feat: New `PublicCsvExporter` with AJAX batched 3-step export (start → batch ×N → download) matching the admin `CsvExporter` architecture — prevents memory exhaustion and webserver timeouts on large datasets; synchronous streaming preserved as a no-JS fallback
* Feat: New `Geofence::get_form_end_timestamp()` and `has_form_expired()` helpers — public CSV download is released only after the form's configured end date/time
* Feat: New "Public CSV Download" metabox on the form editor — toggle, read-only hash with regenerate/reset controls, download counter, per-form quota override, and a ready-to-share URL preview
* Feat: New `public_csv_default_limit` setting on the Advanced tab — default quota suggested to the admin when enabling the feature per-form (default: 1)
* Test: 45 new unit tests — 12 for Geofence form expiration helpers, 14 for PublicCsvExporter column/row layout, 19 for PublicCsvDownload request validation branches and happy path
* Chore: Zero PHPStan level 6 errors — cleared 26 pre-existing static analysis findings across 13 files (array shape PHPDoc, dead code removal, `QRcode::raw()` stub, `$calendar` pre-initialization, PHPDoc parse error fix, redundant `?? ''` removal)
* UX: `[ffc_csv_download]` now reuses the same CSS classes as `[ffc_verification]` (`ffc-verification-container`, `ffc-verification-form`, `ffc-form-field`, `ffc-input`, `ffc-submit-btn`) — inherits the card layout, dark-mode and focus ring from the main stylesheet, no more inline `<style>` block
* UX: Progress bar overlay with real batch-by-batch feedback on the CSV download page — shows record count, progress percentage, and status messages. Minimum 1.5 s display threshold; graceful degradation when JS is unavailable
* UX: `ffc-frontend.css` auto-enqueued on `[ffc_csv_download]` pages; new `ffc-csv-download.js` enqueued only on CSV download pages
* Docs: Added a `[ffc_csv_download]` row to the Shortcodes table in the Documentation tab describing the Form ID + hash workflow, expiration/quota gating, and the optional `title` attribute
* Feat: New "Obsolete Shortcode Cleanup" section on the Data Migrations tab — scans published posts, pages and reusable blocks (`wp_block`) for embedded `[ffc_form id="..."]` shortcodes pointing at forms ended more than N days ago (default 90, configurable 1-3650) and removes those obsolete shortcodes from `post_content`. Admin-only, nonce-protected, handles both Classic and Gutenberg-wrapped shortcode formats
* Feat: New `ObsoleteShortcodeCleaner` service (`find_expired_form_ids`, `scan_posts_for_expired_forms`, `strip_shortcodes_from_content`, `run($days, ['dry_run' => bool])`) plus a `Geofence::has_form_expired_by_days()` helper reused from the public CSV download's expiration helper
* Feat: Cleanup UI enforces a dry-run → apply flow — the "Remove shortcodes now" button is disabled until a preview has been recorded in the last 5 minutes. Report shows grace window, expired forms, posts scanned, posts affected and shortcodes removed, plus a table of the first 50 affected posts with edit links
* Safety: `wp_update_post()` automatically creates WordPress revisions for modified posts/pages, giving admins a manual rollback path; only `[ffc_form]` shortcodes pointing at expired IDs are removed — the rest of the content is left untouched
* Test: New **ObsoleteShortcodeCleanerTest** — 19 tests covering regex quote styles, extra-attribute handling, classic + Gutenberg + mixed removal, dry-run vs apply pipelines, report truncation at `REPORT_LIMIT`, empty-result short-circuits, and `wp_update_post()` no-op skipping

= 5.0.3 (2026-03-27) =

Performance optimizations for URL shortener and QR code generation, new admin columns for forms listing, and Safari/iOS geofence fixes.

* Perf: Cache plugin settings in UrlShortenerService — single `get_option` per request
* Perf: Defer redirect click count increment to `shutdown` hook
* Perf: Add `qr_cache` column to `ffc_short_urls` table — QR base64 stored in DB
* Perf: Rewrite SVG QR generation to use `QRcode::raw()` matrix directly
* Feat: Add ID, Shortcode and Submissions columns to ffc_form listing screen
* Fix: `isSafari()` detection for iPadOS 13+
* Fix: Geofence loading spinner stuck indefinitely on Safari
* Fix: `gps_fallback` admin setting not passed to frontend
* Fix: Safari-specific error messages overridden by generic admin message
* UX: Progressive loading messages for Safari/iOS geolocation wait

= 5.0.2 (2026-03-03) =

100% unit test coverage across all 21 modules (146 concrete classes), plus bug fixes, new features, and CSS/asset refactoring.

* Fix: AJAX requests blocked for non-admin users
* Fix: Certificate download bypassing verification page with direct AJAX
* Fix: Download for regular users — migrated from admin-ajax to REST API
* Fix: Reregistration 'Editar' button not working in dashboard
* Fix: QR codes encoding permalink instead of short URL, and click counter issues
* Fix: Short URL redirects not working when rewrite rules fail
* Fix: QR code stacking/visibility and lazy-loading issues in PDF rendering
* Fix: Self-scheduling overlay translation, email notice, and PDF error handling
* Fix: Reverted PDF button to magic link (undo direct download approach)
* Feat: Multi-audience transfer list for reregistration campaigns
* Feat: URL Shortener documentation added to the Documentation tab
* Feat: Logout button added to Profile tab in user dashboard
* Feat: Standardized magic link URL format across all document types
* Refactor: Footer two-column layout with QR code on the left
* Refactor: Extracted inline CSS/JS to dedicated asset files, consolidated CSS classes
* Refactor: Removed 27 unnecessary !important declarations and unused CSS
* New: 24 test files added — Admin (5), Self-Scheduling (5), Audience (7), Frontend (3), Reregistration (4)
* Test suite: 1051 → **3089 tests** across **153 test files** — 100% class coverage on all 21 modules

= 5.0.1 (2026-02-22) =

Security hardening, code quality improvements, URL Shortener test coverage, and phpcs:ignore standardization.

* Fix: Removed nonce fallback chain in AjaxTrait — single specific nonce action per handler
* Fix: Removed `wp_rest` as fallback nonce in Self-Scheduling AJAX handlers
* Fix: Elevated permissions on 6 Audience AJAX handlers from `read` to `manage_options`
* Fix: Changed `$_GET['booking_id']` to `$_POST['booking_id']` in Audience POST handler
* Fix: Replaced `stripslashes()` with `wp_unslash()` in 4 locations
* Fix: Improved SQL IN clause pattern with `%d` + `intval()` mapping
* Fix: Moved rate limiter before format validation in magic token verification
* Fix: Added early IP rate limit in FormProcessor before nonce/CAPTCHA
* Fix: Standardized all `phpcs:ignore` comments with justifications
* New: **117 URL Shortener tests** — Service (40), Repository (20), Loader (15), AdminPage (17), MetaBox (12), QrHandler (7), Activator (6)
* Test suite: 934 → **1051 tests**, 1830 → **2076 assertions**

= 5.0.0 (2026-02-19) =

Multi-identifier architecture: split combined CPF/RF into independent columns, and retirement of 10 completed legacy migrations.

* Feat: Added separate `cpf`, `cpf_encrypted`, `cpf_hash`, `rf`, `rf_encrypted`, `rf_hash` columns to submissions and appointments tables
* Feat: Updated core, admin, API, security, and privacy layers for split cpf/rf columns
* Refactor: Removed legacy `cpf_rf` dual-write; split migration is the single source of truth
* Refactor: Deprecated legacy `cpf_rf` columns with `@deprecated` annotations
* Removed: **10 completed migrations** retired from admin panel
* Result: **934 tests, 1830 assertions, 0 failures**

= 4.12.26 (2026-02-18) =

PHPStan level 6 — zero-baseline compliance. Resolved all 317 static analysis errors across 80+ files without any baseline suppressions.

* Fix: Added missing `use` import statements for 94 class.notFound errors
* Fix: Cast `int` to `string` for `esc_html()`, `esc_attr()`, `sprintf()`, `_n()` calls (50 errors)
* Fix: Removed 15 unreachable code blocks after `wp_die()`, `exit`, `wp_send_json_*()` calls
* Config: Reduced PHPStan baseline from 317 errors to **0**

= 4.12.25 (2026-02-17) =

Unit tests for EmailHelperTrait, AjaxTrait, and Debug.

* New: **EmailHelperTraitTest** — 20 tests
* New: **AjaxTraitTest** — 17 tests
* New: **DebugTest** — 13 tests
* Config: Added `patchwork.json` for Brain\Monkey `error_log` mocking
* Test suite: 765 → 815 tests, 1496 → 1563 assertions

= 4.12.24 (2026-02-17) =

Unit tests for CsvExportTrait, ActivityLogQuery, and AppointmentCsvExporter.

* New: **CsvExportTraitTest** — 18 tests
* New: **ActivityLogQueryTest** — 17 tests
* New: **AppointmentCsvExporterTest** — 21 tests
* Test suite: 709 → 765 tests, 1427 → 1496 assertions

= 4.12.23 (2026-02-17) =

Unit tests for BlockedDateRepository, EmailTemplateService, and ActivityLogSubscriber.

* New: **BlockedDateRepositoryTest** — 20 tests
* New: **EmailTemplateServiceTest** — 24 tests
* New: **ActivityLogSubscriberTest** — 13 tests
* Test suite: 652 → 709 tests, 1338 → 1427 assertions

= 4.12.22 (2026-02-17) =

Unit tests for Self-Scheduling and Date Blocking.

* New: **AppointmentValidatorTest** — 24 tests
* New: **SelfSchedulingSaveHandlerTest** — 18 tests
* New: **DateBlockingServiceTest** — 18 tests
* Test suite: 592 → 652 tests, 1235 → 1338 assertions

= 4.12.21 (2026-02-17) =

Unit tests for Migrations, Scheduling, and Generators.

* New: **DataSanitizerTest** — 31 tests
* New: **WorkingHoursServiceTest** — 30 tests
* New: **MagicLinkHelperTest** — 32 tests
* Test suite: 499 → 592 tests, 1118 → 1235 assertions

= 4.12.20 (2026-02-17) =

Unit tests for Admin module: settings validation, CSV export formatting, geofence logic.

* New: **FormEditorSaveHandlerTest** — 24 tests
* New: **SettingsSaveHandlerTest** — 28 tests
* New: **CsvExporterTest** — 25 tests
* Test suite: 422 → 499 tests, 974 → 1118 assertions

= 4.12.19 (2026-02-17) =

Refactoring: extract focused classes from DashboardShortcode (720 → 395 lines, 45% reduction).

* Refactor: **DashboardAssetManager** — extracted asset enqueuing + localization (269 lines)
* Refactor: **DashboardViewMode** — extracted admin view-as validation + banner (98 lines)

= 4.12.18 (2026-02-17) =

Unit tests for SubmissionHandler: comprehensive coverage of update, decrypt, failure paths, and edge cases.

* New: **21 additional SubmissionHandler tests** covering update, decrypt, failure paths, bulk guards, edge cases
* Test suite: 401 → 422 tests, 923 → 974 assertions

= 4.12.17 (2026-02-17) =

Refactoring: extract focused classes from FormProcessor (822 → 548 lines, 33% reduction).

* Refactor: **AccessRestrictionChecker** — password, denylist, allowlist, and ticket validation (168 lines)
* Refactor: **ReprintDetector** — reprint detection with JSON decoding and field enrichment (164 lines)

= 4.12.16 (2026-02-17) =

Refactoring: extract focused classes from SelfSchedulingEditor (924 → 559 lines, 39% reduction).

* Refactor: **SelfSchedulingCleanupHandler** — AJAX cleanup handler and metabox rendering (303 lines)
* Refactor: **SelfSchedulingSaveHandler** — calendar data persistence (141 lines)

= 4.12.15 (2026-02-17) =

Unit tests for Utils: comprehensive coverage of document validation, formatting, sanitization, captcha, and helper functions.

* New: **UtilsTest** — 95 tests covering all Utils methods in 3 groups
* Test suite: 306 → 401 tests, 812 → 923 assertions

= 4.12.14 (2026-02-17) =

Unit tests for FormProcessor and PdfGenerator: quiz scoring, restriction checks, URL parsing, and data enrichment.

* New: **FormProcessorTest** — 21 tests for quiz scoring and restriction validation
* New: **PdfGeneratorTest** — 32 tests for URL param parsing, filename generation, default HTML, and data enrichment
* Test suite: 253 → 306 tests, 710 → 812 assertions

= 4.12.13 (2026-02-17) =

Refactoring: extract focused classes from ReregistrationAdmin (1,125 → 830 lines).

* Refactor: **ReregistrationCsvExporter** — CSV export logic extracted into standalone class
* Refactor: **ReregistrationSubmissionActions** — submission workflow handlers (approve/reject/return/bulk) extracted
* Refactor: **ReregistrationCustomFieldsPage** — custom fields admin page extracted
* ReregistrationAdmin reduced by 26% via delegation to extracted classes

= 4.12.12 (2026-02-17) =

Unit tests for Reregistration module: field options and data processor.

* New: **ReregistrationFieldOptionsTest** — 15 tests covering field option providers and divisao-setor mapping
* New: **ReregistrationDataProcessorTest** — 19 tests covering working hours sanitization and submission validation
* Fix: **AudienceCsvImporterTest** — alias mock tests run in separate processes to prevent cross-class contamination
* Test suite: 218 → 253 tests, 453 → 710 assertions

= 4.12.11 (2026-02-17) =

Unit tests for Audience module: CSV importer and notification handler.

* New: **AudienceCsvImporterTest** — 26 tests covering CSV validation, sample generation, member import, and audience import logic
* New: **AudienceNotificationHandlerTest** — 10 tests covering template rendering, subject generation, and default templates
* Test suite: 182 → 218 tests, 352 → 453 assertions

= 4.12.10 (2026-02-17) =

Security hardening sprint: regex validation, AJAX method enforcement, modern CSPRNG, prepared SQL statements.

* Security: **Regex validation** — custom regex patterns in `ReregistrationDataProcessor` now use `~` delimiter and validate the pattern before applying; invalid patterns are safely skipped
* Security: **AJAX method enforcement** — `AudienceLoader` search and environment handlers switched from `$_GET` to `$_POST`; updated JS to use POST
* Security: **Modern CSPRNG** — replaced deprecated `openssl_random_pseudo_bytes()` with `random_bytes()` in Encryption
* Security: **Prepared SQL statements** — 3 `SHOW INDEX` queries now use `$wpdb->prepare()` with `%i` placeholder
* Fix: **LiteSpeed hook prefix warning** — added `phpcs:ignore` for third-party `litespeed_control_set_nocache` hook
* Rebuilt all minified JS assets

= 4.12.9 (2026-02-17) =

Fix: math captcha showing raw HTML `<span class="required">*</span>` as visible text on cached pages.

* Fix: **Captcha label raw HTML** — `ffc-dynamic-fragments.js` used `textContent` to set the captcha label which stripped HTML; separated the required asterisk indicator from the label data and added `<span class="ffc-captcha-label-text">` wrapper so JS targets only the text portion
* Fix: **Form processor captcha refresh** — inline captcha generation in `FormProcessor` replaced with `Utils::generate_simple_captcha()` call for consistency
* Security: All captcha label refreshes now use `.text()`/`textContent` (never `.html()`/`innerHTML`), keeping XSS hardening from v4.12.6
* Rebuilt all minified JS assets

= 4.12.8 (2026-02-17) =

Refactor Utils (dead code removal) and ReregistrationFrontend (1,330 lines → coordinator + 3 sub-classes).

* Removed: **3 unused public methods** from Utils (`is_local_environment`, `is_valid_ip`, `validate_email`)
* Refactor: **ReregistrationFrontend** split into `ReregistrationFieldOptions`, `ReregistrationFormRenderer`, `ReregistrationDataProcessor`
* Enhanced: **ReregistrationFormRenderer** — 616-line `render_form()` broken into 8 focused per-fieldset methods

= 4.12.7 (2026-02-17) =

Refactor UserDataRestController (1,415 lines → coordinator + 6 sub-controllers).

* Refactor: **UserDataRestController** split into 6 focused sub-controllers with backward-compatible delegate methods
* New: **UserContextTrait** — shared user-context resolution extracted into reusable trait
* Fix: **UserDataRestControllerTest** — fixed 3 pre-existing RateLimiter test errors

= 4.12.6 (2026-02-17) =

Frontend cleanup: console.log removal, XSS hardening, CSS consolidation.

* Removed: **58 console.log calls** from production JS — kept error/warn for legitimate reporting
* Security: **XSS hardening** — replaced unsafe `.html()` and `.innerHTML` with safe DOM methods in 7 files
* Fix: **CSS `.ffc-badge` overlap** — unified base class in ffc-common.css
* Fix: **CSS `.ffc-notice-*` overlap** — namespaced audience variants to prevent cascade conflicts
* Rebuilt all minified JS and CSS assets

= 4.12.5 (2026-02-17) =

Tests for critical classes: SubmissionHandler, UserCreator, CapabilityManager, and UserDataRestController endpoint callbacks.

* New: **SubmissionHandlerTest** — 21 tests covering process_submission (encryption, ticket_hash, consent fields), get/trash/restore/delete, bulk operations, and ensure_magic_token
* New: **UserCreatorTest** — 12 tests covering generate_username and get_or_create_user flows
* New: **CapabilityManagerTest** — 27 tests covering constants, grant/revoke, access checks, role management
* Enhanced: **UserDataRestControllerTest** — added 11 endpoint callback error-path tests
* Fix: **SubmissionHandler bulk methods** — removed incorrect `: array` return type (methods return `int`)
* Fix: **SubmissionHandler WP_Error namespace** — fixed missing backslash prefix

= 4.12.4 (2026-02-17) =

Performance and reliability: changelog extraction, ticket hash column, LIKE-on-JSON elimination.

* New: **CHANGELOG.md** — full version history extracted from readme.txt into dedicated changelog file; readme.txt now shows only recent versions with pointer to CHANGELOG.md
* New: **`ticket_hash` column** on submissions table — stores deterministic hash of ticket restriction value for indexed lookups; new composite index `idx_form_ticket_hash` on `(form_id, ticket_hash)`
* Fix: **Ticket reprint detection with encryption** — when data encryption was enabled, the `data` column is NULL so LIKE-based ticket lookup always failed silently; now uses `ticket_hash` for hash-based lookup, falling back to LIKE only for legacy unencrypted data
* Performance: **Eliminated LIKE on JSON** for ticket lookups — `detect_reprint()` now uses indexed `ticket_hash = %s` instead of `data LIKE '%"ticket":"VALUE"%'` when encryption is configured

= 4.12.3 (2026-02-17) =

Security hardening: SQL injection prevention, XSS mitigation, and modal accessibility improvements.

* Security: **RateLimiter SQL** — all 11 queries now use `$wpdb->prepare()` with `%i` identifier placeholder for table names; removed blanket `phpcs:disable` for `PreparedSQL.NotPrepared`
* Security: **IpGeolocation SQL** — `clear_cache()` now uses `$wpdb->prepare()` with `%i` for table name and `%s` for LIKE patterns; removed blanket `phpcs:disable`
* Security: **XSS prevention** — added `escapeHtml()` utility to `ffc-frontend-helpers.js` and `ffc-audience-admin.js`; escaped user-controlled data in error messages, search results, selected user display, and conflict audience names
* Security: **Migration onclick removal** — replaced inline `onclick="return confirm(...)"` with `data-confirm` attribute on migration buttons; JS now reads from data attribute instead of parsing onclick regex
* Accessibility: **Modal ARIA attributes** — added `role="dialog"`, `aria-modal="true"`, `aria-labelledby` to audience booking modal, day detail modal, and admin booking modal
* Accessibility: **Focus trapping** — implemented keyboard focus trap (Tab/Shift+Tab) for audience booking and day modals, matching existing calendar-frontend pattern
* Accessibility: **Focus management** — modals now move focus to close button on open and return focus to trigger element on close
* Accessibility: **Escape key** — audience modals, admin booking modal, and template modal now close on Escape key press
* Accessibility: **Close button labels** — added `aria-label="Close"` to all modal close buttons

= 4.12.2 (2026-02-17) =

God class refactoring: UserManager and ActivityLog split into single-responsibility classes with full backward compatibility.

* Refactor: **CapabilityManager** — extracted from UserManager; handles all FFC capability constants, role registration, context-based granting, access checks, and per-user capability management
* Refactor: **UserCreator** — extracted from UserManager; handles get_or_create_user flow, WordPress user creation, orphaned record linking, username generation, metadata sync, and profile creation
* Refactor: **ActivityLogQuery** — extracted from ActivityLog; handles get_activities, count_activities, get_stats, get_submission_logs, cleanup, and run_cleanup
* Refactor: **UserManager** — reduced from ~1,150 to ~400 lines; retains profile CRUD and data retrieval methods; delegates capabilities to CapabilityManager and user creation to UserCreator via backward-compatible constant aliases and method wrappers
* Refactor: **ActivityLog** — reduced from ~800 to ~520 lines; retains core logging, buffer management, and convenience methods; delegates query/stats/cleanup to ActivityLogQuery
* No breaking changes: all existing method calls and constant references continue to work via delegation

= 4.12.1 (2026-02-16) =

Test coverage expansion: from 3 to 9 test files, covering critical security and business logic paths.

* New: **EncryptionTest** — 19 tests covering AES-256-CBC round-trip, unique IV per encryption, hash determinism, batch encrypt/decrypt, decrypt_field fallback, appointment decryption, Unicode handling, and is_configured()
* New: **RateLimiterTest** — 14 tests covering IP limit allow/block/cooldown, verification limits (hour/day), user rate limits, check_all blacklist/whitelist integration, email limit bypass, domain and CPF blacklisting
* New: **FormProcessorRestrictionsTest** — 15 tests covering password validation (required/incorrect/correct), denylist blocking, allowlist enforcement, ticket validation with case-insensitive matching and consumption, denylist-over-allowlist priority, quiz score calculation (correct/wrong/empty/unanswered)
* New: **SubmissionRestControllerTest** — 8 tests verifying route registration count, admin permission on list/single endpoints, public verify endpoint, auth_code validation (rejects <12 chars), pagination args
* New: **UserDataRestControllerTest** — 12 tests verifying all 11 user routes require `is_user_logged_in`, profile supports GET+PUT, all route paths registered correctly
* New: **SubmissionRepositoryTest** — 12 tests covering table name, cache group, ORDER BY sanitization (rejects SQL injection), bulk operations on empty arrays, countByStatus aggregation, cache behavior, insert/update/delete via mock wpdb
* Improved: **Bootstrap** — added WP_REST_Server stub, OBJECT_K/ARRAY_A/DB_NAME constants, updated FFC_VERSION to match plugin
* Total: **108 tests, 210 assertions** (previously 14 tests, 23 assertions)

= 4.12.0 (2026-02-16) =

Full-page cache compatibility: forms now work correctly behind LiteSpeed, Varnish, and other page caches.

* New: **Dynamic Fragments endpoint** — lightweight AJAX endpoint (`ffc_get_dynamic_fragments`) returns fresh captcha and nonces on every page load, ensuring forms work on cached pages
* New: **Client-side captcha refresh** — JavaScript module (`ffc-dynamic-fragments.js`) patches captcha label, hash, and nonces in the DOM immediately after DOMContentLoaded
* New: **Nonce refresh** — `ffc_frontend_nonce` and `ffc_self_scheduling_nonce` are refreshed via AJAX, preventing expired-nonce errors on cached pages
* New: **Booking form AJAX pre-fill** — logged-in user name and email are populated via AJAX instead of server-side rendering, preventing cached pages from showing another user's data
* New: **Dashboard cache exclusion** — pages containing `[user_dashboard_personal]` automatically send `nocache_headers()`, `X-LiteSpeed-Cache-Control: no-cache`, and `litespeed_control_set_nocache` action
* New: **Page Cache Compatibility card** — new status card in Cache settings tab showing the state of all cache-compatibility features (Dynamic Fragments, Dashboard Exclusion, Object Cache, AJAX Endpoints)
* New: **Redis detection notice** — Cache settings tab warns when Redis/Memcached is not installed, explaining impact on rate limiter counter persistence

= 4.11.0 (2026-02-15) =

Audience custom fields, reregistration campaigns, ficha PDF, email notifications, and audience hierarchy enhancements.

* New: **Audience Custom Fields** — define per-audience custom fields (text, textarea, number, date, select, checkbox) with validation rules (CPF, email, phone, regex)
* New: **Custom Fields on User Profile** — "FFC Custom Data" section on WordPress user edit screen showing fields grouped by audience with collapsible sections
* New: **Custom field inheritance** — child audiences inherit fields from parent audiences
* New: **Reregistration campaigns** — create campaigns linked to audiences with configurable start/end dates, auto-approve, and email settings
* New: **Reregistration frontend form** — dashboard banner with submission form, draft saving, and field validation
* New: **Reregistration admin UI** — manage campaigns, review/approve/reject submissions, bulk actions, filters
* New: **Reregistration email notifications** — invitation (on activation), reminder (N days before deadline via cron), and confirmation (on submission) emails
* New: **Ficha PDF** — generate PDF records for reregistration submissions with custom template support
* New: **Ficha download** — available in admin submissions list and user dashboard for submitted/approved submissions
* New: **Audience hierarchy tree** — recursive rendering with unlimited depth, member counts including children, breadcrumb navigation
* New: **REST API endpoints** — `GET /user/reregistrations`, `POST /user/reregistration/{id}/submit`, `POST /user/reregistration/{id}/draft`
* New: **Migration** — `MigrationCustomFieldsTables` ensures tables exist on upgrade from pre-4.11.0 versions
* New: **Documentation** — 3 new sections: Audience Custom Fields, Reregistration, Ficha PDF
* New: **pt_BR translations** — all new strings from Sprints 6-11 translated to Portuguese
* New: 3 database tables: `ffc_custom_fields`, `ffc_reregistrations`, `ffc_reregistration_submissions`
* New: Email templates: `reregistration-invitation.php`, `reregistration-reminder.php`, `reregistration-confirmation.php`
* New: Ficha HTML template: `html/default_ficha_template.html`

For the complete changelog history, see [CHANGELOG.md](CHANGELOG.md).

== Upgrade Notice ==

= 5.0.1 =
Security hardening: nonce fallback removal, permission elevation on Audience AJAX, early IP rate limiting on form submission. 117 new URL Shortener tests (1051 total). No database changes. No breaking changes.

= 5.0.0 =
Multi-identifier architecture: split CPF/RF into independent columns. 10 retired migrations removed. Backup recommended before update.

= 4.12.26 =
PHPStan level 6 zero-baseline compliance. 317 static analysis errors resolved. No database changes. No breaking changes.

= 4.12.6 =
Frontend cleanup: removed 58 console.log calls, hardened XSS in 7 JS files, fixed CSS selector overlaps. No database changes. No breaking changes.

= 4.12.5 =
Tests for critical classes (182 total). Fixed bulk method return types and WP_Error namespace in SubmissionHandler. No database changes. No breaking changes.

= 4.12.4 =
Changelog extracted to CHANGELOG.md. New ticket_hash column on submissions table for indexed ticket lookups (added automatically on activation). Fixes ticket reprint detection when data encryption is enabled. No breaking changes.

= 4.12.3 =
Security hardening: all RateLimiter and IpGeolocation SQL queries now use $wpdb->prepare() with %i identifier placeholder. XSS prevention via escapeHtml() in JavaScript. Modal accessibility with ARIA attributes, focus trapping, and Escape key support. No database changes. No breaking changes.

= 4.12.2 =
God class refactoring: UserManager split into CapabilityManager + UserCreator, ActivityLog split into ActivityLogQuery. All existing calls remain backward-compatible via delegation. No database changes. No breaking changes.

= 4.12.1 =
Test coverage expansion: 6 new test files covering Encryption, RateLimiter, FormProcessor restrictions, REST controllers, and SubmissionRepository. 108 tests with 210 assertions. No code changes, no database changes, no breaking changes.

= 4.12.0 =
Full-page cache compatibility. Forms, captchas, and nonces now work correctly behind LiteSpeed, Varnish, and other page caches via automatic AJAX refresh. Dashboard pages excluded from cache. Booking form pre-fill moved to AJAX. No database changes. No breaking changes.

= 4.11.0 =
Audience custom fields, reregistration campaigns, ficha PDF, and email notifications. 3 new database tables created automatically. Migration ensures safe upgrade from older versions. Backup recommended.

= 4.8.0 =
Environment colors, event list panel, all-day events, booking view/cancel in admin, CSV export for members/audiences, soft-delete pattern. Fixes redirect, labels, and badge overflow. New columns via migration. No breaking changes.

= 4.6.16 =
Settings UX reorganization, dead code removal, and centralized version management. Fixes missing dashboard icons and phpqrcode cache warning. All JS console versions now dynamic. No data changes required.

= 4.6.6 =
Reliability: Standardized error handling — fixed encryption namespace bug, email failure logging, REST API no longer exposes internal errors, AJAX responses include error codes, database errors logged. No data changes required.

= 4.6.5 =
Architecture: ActivityLog decoupled from business logic via new ActivityLogSubscriber class. Logging now happens through plugin hooks instead of direct calls. Settings save triggers automatic cache invalidation. No data changes required.

= 4.6.4 =
Extensibility: Added 31 action/filter hooks across submissions, PDF generation, email, appointments, audience bookings, settings, and CSV export. Developers can now customize all major plugin workflows.

= 4.6.3 =
Security hardening: Added missing capability checks to 5 admin handlers (settings save, migrations, cache actions, date format preview, audience booking REST endpoint). No data changes required.

= 4.6.2 =
Performance improvement: Fixed N+1 database queries in 4 locations (submissions list, appointments, audience bookings, admin bookings). Added 7 composite indexes for faster query performance. Reactivate plugin to apply new indexes.

= 4.6.1 =
BREAKING: Plugin slug changed from `wp-ffcertificate` to `ffcertificate`. Existing installations must deactivate and reactivate. All settings and data are preserved. Security hardening, accessibility improvements, and major structural refactoring.

= 4.6.0 =
Scheduling consolidation with unified admin menu and settings. Global holidays system. User dashboard pagination and improvements. Multiple bug fixes. Translation update with 278 new pt_BR strings.

= 4.5.0 =
New audience scheduling system for group bookings. 5 new database tables created automatically. Backup recommended before update.

= 4.4.0 =
Per-user capability system. Self-scheduling rename. Capability migration runs automatically.

= 4.3.0 =
WordPress Plugin Check compliance. Text domain changed to `ffcertificate`. Translation files renamed. CDN scripts replaced with bundled copies. Recommended update.

= 4.1.0 =
New appointment calendar and booking system. 3 new database tables created automatically. Backup recommended.

= 4.0.0 =
Breaking release. All backward-compatibility aliases removed - old `FFC_*` class names no longer work. Only `FreeFormCertificate\*` namespaces supported. Backup recommended. Requires PHP 7.4+.

= 3.0.0 =
Major internal refactoring with Repository pattern, REST API, geofencing, and migration framework. No breaking changes for end users.

= 2.8.0 =
New Magic Links feature for one-click certificate access. New database column added automatically. Backup recommended.

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
