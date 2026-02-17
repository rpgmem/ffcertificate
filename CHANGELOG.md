# Changelog

All notable changes to the **Free Form Certificate** plugin are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

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

Visibility and scheduling controls for calendars, admin bypass system, public audience calendars.

## 4.6.16 (2026-02-08)

Settings UX reorganization, dead code removal, version centralization, dashboard icon fixes.

## 4.6.15 (2026-02-08)

Plugin Check compliance: hook prefix rename, SQL placeholders, query caching.

## 4.6.14 (2026-02-08)

Accessibility: dark mode, CSS variables, ARIA attributes, template accessibility.

## 4.6.13 (2026-02-08)

Performance: query caching, conditional loading, N+1 elimination, icon CSS refactor.

## 4.6.12 (2026-02-08)

Unit testing infrastructure, i18n compliance, asset minification (~45% size reduction).

## 4.6.11 (2026-02-08)

Security: REST API protection, uninstall cleanup, deprecated API removal.

## 4.6.10 (2026-02-08)

Fix: race condition in concurrent appointment booking (transaction locking).

## 4.6.9 (2026-02-08)

Performance: Activity Log batch writes, auto-cleanup, stats caching.

## 4.6.8 (2026-02-08)

Refactor: break down God classes into focused single-responsibility classes.

## 4.6.7 (2026-02-07)

Accessibility: WCAG 2.1 AA compliance for all frontend components.

## 4.6.6 (2026-02-07)

Reliability: standardized error handling across all modules.

## 4.6.5 (2026-02-07)

Architecture: internal hook consumption for decoupled activity logging.

## 4.6.4 (2026-02-07)

Extensibility: 31 action/filter hooks for developer customization.

## 4.6.3 (2026-02-07)

Security: permission audit, missing capability checks added to admin handlers.

## 4.6.2 (2026-02-07)

Performance: N+1 query fixes, 7 composite database indexes added.

## 4.6.1 (2026-02-07)

Security hardening, accessibility, slug change to `ffcertificate`, structural refactoring.

## 4.6.0 (2026-02-06)

Scheduling consolidation, user dashboard improvements, global holidays, bug fixes.

## 4.5.0 (2026-02-05)

Complete audience scheduling system for group bookings. 5 new database tables.

## 4.4.0 (2026-02-04)

Per-user capability system, self-scheduling rename.

## 4.3.0 (2026-02-02)

WordPress Plugin Check compliance and distribution cleanup.

## 4.2.0 (2026-01-30)

CSV export enhancements and calendar translations.

## 4.1.0 (2026-01-27)

Appointment calendar and booking system. 3 new database tables.

## 4.0.0 (2026-01-26)

Breaking: removed backward-compatibility aliases, namespace finalization.

## 3.0.0 – 3.3.1

Repository pattern, REST API, strict types, PSR-4 autoloader, user dashboard.

## 1.0.0 – 2.10.0

Initial release through rate limiting. Core form builder, PDF generation, magic links, QR codes.
