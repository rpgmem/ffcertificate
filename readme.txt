=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 4.12.6
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
