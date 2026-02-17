# Changelog

All notable changes to the **Free Form Certificate** plugin are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

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

Breaking release: removal of backward-compatibility aliases and namespace finalization.

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

## 2.10.0 (2026-01-20)

Rate limiting with dedicated database tables.

- Added: Rate Limiter with dedicated database tables (`ffc_rate_limits`, `ffc_rate_limit_logs`)
- Added: Rate Limit Activator for table creation
- Added: Configurable rate limit thresholds per action type
- Migrated: Rate Limiter from WordPress transients to Object Cache API

## 2.9.1 (2026-01-19)

Activity log, form cache, and magic links fix.

- Fixed: Magic Links fatal error (critical bug)
- Fixed: Duplicate `require` in loader
- Added: Activity Log with `ffc_activity_logs` table for audit trail
- Added: Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
- Added: Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

## 2.9.0 (2026-01-18)

QR Code generation on certificates.

- Added: QR Code generation on certificates linking to verification page
- Added: QR Code generator class using phpqrcode library
- Added: QR Code settings tab with size and error correction configuration

## 2.8.0 (2026-01-16)

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

## 2.7.0 (2026-01-14)

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

## 2.6.0 (2026-01-12)

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

## 2.5.0 (2026-01-10)

- Internal improvements

## 2.4.0 (2026-01-04)

- Internal improvements

## 2.3.0 (2026-01-03)

- Internal improvements

## 2.2.0 (2025-12-24)

- Internal improvements

## 2.1.0 (2025-12-23)

- Internal improvements

## 2.0.0 (2025-12-22)

PDF generation overhaul, captcha, and reprint logic.

- Refactored: PDF generation from simple image to high-fidelity A4 Landscape (1123x794px) using jsPDF
- Added: Dynamic Math Captcha with hash validation on backend
- Added: Honeypot field for spam bot protection
- Added: Reprint logic for certificate recovery (duplicate detection)
- Added: PDF download buttons directly in admin submissions list
- Added: Mobile optimization with strategic delays and progress overlay
- Fixed: CORS issues with `crossorigin="anonymous"` on image rendering

## 1.5.0 (2025-12-18)

Ticket system and form cloning.

- Added: Ticket system with single-use codes for exclusive form access
- Added: Form cloning (duplication) functionality
- Added: Global settings tab with automatic log cleanup configuration
- Added: Denylist for blocking specific IDs

## 1.0.0 (2025-12-14)

Initial release.

- Form Builder with drag & drop interface (Text, Email, Number, Date, Select, Radio, Textarea, Hidden fields)
- PDF certificate generation (client-side)
- CSV export with form and date filters
- Submissions management in admin
- ID-based restriction (CPF/RF) with allowlist mode
- Asynchronous email notifications via WP-Cron
- Automatic cleanup of old submissions
- Verification shortcode `[ffc_verification]`
