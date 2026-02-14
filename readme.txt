=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 4.9.9
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

= 4.9.9 (2026-02-14) =

Security hardening, expanded audit trail, and LGPD compliance improvements.

* New: **Rate limiting by user_id** — `RateLimiter::check_user_limit()` protects authenticated endpoints (password change: 3/hour, privacy requests: 2/hour)
* New: **Activity log convenience methods** — `log_password_changed()`, `log_profile_updated()`, `log_capabilities_granted()`, `log_privacy_request()` for comprehensive audit trail
* New: **Email on capability grant** — optional email notification when certificate, appointment, or audience capabilities are granted (controlled by `notify_capability_grant` setting)
* New: **LGPD/GDPR usermeta export** — new exporter for `ffc_*` user meta entries via WordPress Privacy Tools
* New: **LGPD/GDPR usermeta erasure** — `ffc_*` user meta entries are now deleted during privacy erasure requests
* Improved: Password change, profile update, and privacy request endpoints now log to activity log
* Improved: Capability grant methods now track newly granted capabilities and fire activity log + email

= 4.9.8 (2026-02-14) =

Dashboard UX improvements and user self-service features.

* New: **Dashboard summary cards** — overview at the top showing certificate count, next appointment, and upcoming group events
* New: **Search and date filters** — filter bar on certificates, appointments, and audience bookings tabs with date range and text search
* New: **Password change** — users can change their password directly from the Profile tab (no wp-admin access needed)
* New: **LGPD self-service** — "Export My Data" and "Request Data Deletion" buttons in Profile tab, using WordPress native privacy request system
* New: **Notes field** — editable personal notes in profile (uses existing `ffc_user_profiles.notes` column)
* New: **Notification preferences** — toggle switches for appointment confirmation, appointment reminder, and new certificate emails (all disabled by default)
* New: **Configurable pagination** — choose 10, 25, or 50 items per page on all tabs (persisted in localStorage)
* New: REST endpoints: `POST /user/change-password`, `POST /user/privacy-request`, `GET /user/summary`
* Improved: Profile REST API now returns `notes` and `preferences` fields
* Improved: `UserManager::update_profile()` now supports `preferences` JSON column

= 4.9.7 (2026-02-14) =

Performance, view-as accuracy, centralized user service, and database referential integrity.

* New: **Batch count queries** in admin users list — certificate and appointment counts loaded via single GROUP BY query per table instead of N+1 per-user queries
* New: **UserService class** — centralized service (`FreeFormCertificate\Services\UserService`) for profile retrieval, capability checks, and user statistics
* New: **FOREIGN KEY constraints** — 7 FK constraints added to FFC tables referencing `wp_users(ID)`:
  - SET NULL on delete: `ffc_submissions`, `ffc_self_scheduling_appointments`, `ffc_activity_log`
  - CASCADE on delete: `ffc_audience_members`, `ffc_audience_booking_users`, `ffc_audience_schedule_permissions`, `ffc_user_profiles`
  - InnoDB engine check: FKs skipped gracefully if engine is not InnoDB
  - Orphaned references cleaned automatically before FK creation
* Fix: **View-as capability check** — admin view-as mode now uses TARGET user's capabilities (not admin's); admin sees exactly what the user would see for certificates, appointments, and audience bookings
* Fix: **Autoloader** — added missing `Privacy` and `Services` namespace mappings

= 4.9.6 (2026-02-14) =

Editable user profile, orphaned record linking, and username privacy fix.

* New: **PUT /user/profile** REST endpoint — users can update display_name, phone, department, organization from the dashboard
* New: **Profile edit form** in user dashboard — toggle between read-only view and edit form with save/cancel actions
* New: **Phone, department, organization** fields displayed in the read-only profile view
* New: **Orphaned record linking** — `get_or_create_user()` now retroactively links submissions and appointments that share the same cpf_rf_hash but had no user_id
* New: **Appointment capability auto-grant** — when orphaned appointments are linked, appointment capabilities are granted automatically
* Fix: **Username = email** privacy issue — `create_ffc_user()` now generates username from name slug (e.g. "joao.silva") instead of using email; fallback to `ffc_` + random string
* Fix: **MigrationUserLink** updated to use `generate_username()` for new user creation during migration
* New: `UserManager::generate_username()` public method — generates unique slugified username from name data

= 4.9.5 (2026-02-14) =

LGPD/GDPR compliance: WordPress Privacy Tools integration (Export & Erase Personal Data).

* New: **Privacy Exporter** — 5 data groups registered with WordPress Export Personal Data tool:
  - FFC Profile (display_name, email, phone, department, organization, member_since)
  - FFC Certificates (form_title, submission_date, auth_code, email, consent)
  - FFC Appointments (calendar, date, time, status, name, email, phone, notes)
  - FFC Audience Groups (audience_name, joined_date)
  - FFC Audience Bookings (environment, date, time, description, status)
* New: **Privacy Eraser** — registered with WordPress Erase Personal Data tool:
  - Submissions: anonymized (user_id=NULL, email/cpf cleared; auth_code and magic_link preserved for public verification)
  - Appointments: anonymized (user_id=NULL, all PII fields cleared)
  - Audience members/booking users/permissions: deleted
  - User profiles: deleted
  - Activity log: anonymized (user_id=NULL)
* New: **PrivacyHandler class** with paginated export (50 items/batch) and single-pass erasure
* New: Encrypted fields decrypted during export for complete data portability

= 4.9.4 (2026-02-14) =

User profiles table, user deletion handling, and email change tracking.

* New: **`ffc_user_profiles` table** — centralized user profile storage (display_name, phone, department, organization, notes, preferences)
* New: **User deletion hook** — `deleted_user` action anonymizes FFC data (SET NULL on submissions/appointments/activity, DELETE on audience/profiles)
* New: **Email change handler** — `profile_update` action reindexes `email_hash` on submissions when user email changes
* New: **Profile methods** in UserManager — `get_profile()`, `update_profile()`, `create_user_profile()` with upsert logic
* New: **Profile migration** — `MigrationUserProfiles` populates profiles from existing ffc_users (display_name, registration date)
* New: **REST API profile fields** — `GET /user/profile` now returns `phone`, `department`, `organization` from profiles table
* New: **UserCleanup class** — handles `deleted_user` and `profile_update` hooks with activity logging
* Fix: **uninstall.php** — added `ffc_user_profiles` to DROP TABLE list and migration options to cleanup

= 4.9.3 (2026-02-14) =

Capability system refactoring: centralized constants, enforced checks, simplified role model.

* New: **Centralized capability constants** — `AUDIENCE_CAPABILITIES`, `ADMIN_CAPABILITIES`, `FUTURE_CAPABILITIES` and `get_all_capabilities()` method in UserManager
* New: **Audience context** — `CONTEXT_AUDIENCE` constant and `grant_audience_capabilities()` for audience group members
* New: **`download_own_certificates` enforced** — users without this capability no longer receive `magic_link`/`pdf_url` in dashboard API
* New: **`view_certificate_history` enforced** — users without this capability see only the most recent certificate per form
* Fix: **CSV importer capabilities** — replaced 3 hardcoded `add_cap()` calls with centralized `UserManager::grant_certificate_capabilities()`
* Fix: **uninstall.php cleanup** — added 4 missing capabilities: `ffc_scheduling_bypass`, `ffc_view_audience_bookings`, `ffc_reregistration`, `ffc_certificate_update`
* Fix: **Admin UI save** — `save_capability_fields()` now references `UserManager::get_all_capabilities()` instead of hardcoded list
* Changed: **Simplified role model** — `ffc_user` role now has all FFC capabilities as `false`; user_meta is the sole source of truth
* Changed: **Removed redundant reset** — `reset_user_ffc_capabilities()` no longer called during user creation (role no longer grants caps by default)
* Changed: **`upgrade_role()` uses centralized list** — new capabilities added as `false` automatically

= 4.9.2 (2026-02-13) =

UX improvements, race condition fix, and PHPCS compliance.

* New: **Textarea auto-resize** — textarea fields in certificate forms grow automatically as user types (up to 300px, then scrollbar), with manual resize support
* Fix: **Calendar month navigation race condition** — rapid month clicks no longer show stale data; uses incremental fetch ID to discard superseded responses (both self-scheduling and audience calendars)
* Fix: **Form field labels capitalization** — labels now respect original formatting regardless of theme CSS
* Fix: **LGPD consent box overflow** — encryption warning no longer exceeds consent container bounds
* Fix: **Form field attributes** — corrected esc_attr() misuse on HTML attributes (textarea, select, radio, input)
* Fix: PHPCS compliance — nonce verification, SQL interpolation, global variable prefixes, unescaped DB parameters, offloaded resources, readme limits

= 4.9.1 (2026-02-12) =

Calendar display improvements, custom booking labels, audience badge format, and bug fixes.

* New: **Collapse parent audiences** — when a parent audience with all children is selected, display only the parent in frontend badges
* New: **Audience badge format** option per calendar — choose between name only or "Parent: Child" format
* New: **Custom booking badge labels** per calendar — configurable singular/plural labels for booking count in day cells (with global fallback)
* Fix: **Geofence GPS checkbox** not saving when unchecked — added hidden sentinel field so unchecked state is properly persisted
* Fix: **Migration cascade failure** — removed `AFTER` clauses from ALTER TABLE migrations that caused silent failures when referenced columns didn't exist
* Fix: **Booking labels missing** from logged-in user config — bookingLabelSingular/bookingLabelPlural were only passed in the public config path
* Fix: **Public calendar event details** — REST API now returns description, environment name, and audiences for non-authenticated users on public calendars

= 4.9.0 (2026-02-12) =

New field types, Quiz/Evaluation mode for scored forms, and certificate quiz tags.

* New: **Info Block** field type — display-only rich text content in forms (supports HTML: bold, italic, links, lists)
* New: **Embed (Media)** field type — embed YouTube, Vimeo, images, or audio via URL with optional caption
* New: **Quiz / Evaluation Mode** — turn any form into a scored quiz with configurable passing score
* New: Quiz **points per option** on Radio, Select, and Checkbox fields (comma-separated values matching option order)
* New: Quiz **max attempts** per CPF/RF — configurable retry limit (0 = unlimited)
* New: Quiz **score feedback** — show score and correct/incorrect answers after submission
* New: Quiz **attempt tracking** — submissions tracked by CPF/RF with statuses: published, retry, failed
* New: Quiz **status badges** in admin submissions list — color-coded badges with score percentage
* New: Quiz **filter tabs** in admin — filter submissions by Published, Trash, Quiz: Retry, Quiz: Failed
* New: Certificate tags **{{score}}**, **{{max_score}}**, **{{score_percent}}** for quiz results in PDF layout
* New: pt_BR translations for all quiz, info block, and embed strings

= 4.8.0 (2026-02-11) =

Calendar UX improvements, environment colors, event list panel, admin enhancements, and export functionality.

* New: Environment **color picker** — assign distinct colors to each environment (admin + frontend)
* New: **Event list panel** — optional side or below panel showing upcoming bookings for the current month
* New: Event list admin settings — enable/disable and position (side or below calendar)
* New: **All-day event** checkbox — marks bookings as all-day (stores 00:00–23:59), blocks entire environment for the day
* New: All-day events display "All Day" label instead of time range in day modal and event list
* New: Holidays now displayed in event list panel alongside bookings (sorted by date)
* New: **"Multiple audiences" badge** in event list when a booking has more than 2 audiences
* New: Multiple audiences badge **color configurable** in Audience settings tab
* New: **CSV export** for members (email, name, audience_name) with optional audience filter
* New: **CSV export** for audiences (name, color, parent) in import-compatible format
* New: Import page renamed to **"Import & Export"** with tabbed navigation (Import / Export)
* New: Admin **feedback notices** for create, save, deactivate, and delete actions on calendars, environments, and audiences
* New: **Soft-delete pattern** — active items are deactivated first; only inactive items can be permanently deleted (calendars, environments, audiences)
* New: **Booking View** button in admin — opens AJAX modal with full booking details (audiences, users, creator, status)
* New: **Booking Cancel** button in admin — AJAX cancel with confirmation prompt and mandatory reason
* New: **Filter overlay** on submissions page — replaced multi-select with overlay modal, forms ordered by ID desc
* Fix: FFC Users redirect — only block wp-admin access when ALL user roles are in the blocked list (was blocking when ANY role matched)
* Fix: Environment label not reaching frontend — `get_schedule_environments()` now includes `name` field in config
* Fix: Environment label fallback "Ambiente" was not wrapped in `__()` for translation
* Fix: Holiday names no longer shown in calendar day cells — displays generic "Holiday" label only (full name in event list)
* Fix: Holiday label fix applied to both audience and self-scheduling calendars
* Fix: Badge overflow in day cells — badges now truncate with ellipsis instead of overflowing
* Changed: Calendar max-width adjusted to 600px standalone, 1120px with event list (600px + 20px gap + 500px panel)
* Changed: Calendar day cells now use `aspect-ratio: 1` for consistent square grid layout
* Changed: Environment colors shown as left border on booking items in day modal and event list
* Migration: Added `is_all_day`, `show_event_list`, `event_list_position`, and `color` columns with automatic migration

= 4.7.0 (2026-02-09) =
Visibility and scheduling controls for calendars, admin bypass system, public audience calendars.

= 4.6.16 (2026-02-08) =
Settings UX reorganization, dead code removal, version centralization, dashboard icon fixes.

= 4.6.15 (2026-02-08) =
Plugin Check compliance: hook prefix rename, SQL placeholders, query caching.

= 4.6.14 (2026-02-08) =
Accessibility: dark mode, CSS variables, ARIA attributes, template accessibility.

= 4.6.13 (2026-02-08) =
Performance: query caching, conditional loading, N+1 elimination, icon CSS refactor.

= 4.6.12 (2026-02-08) =
Unit testing infrastructure, i18n compliance, asset minification (~45% size reduction).

= 4.6.11 (2026-02-08) =
Security: REST API protection, uninstall cleanup, deprecated API removal.

= 4.6.10 (2026-02-08) =
Fix: race condition in concurrent appointment booking (transaction locking).

= 4.6.9 (2026-02-08) =
Performance: Activity Log batch writes, auto-cleanup, stats caching.

= 4.6.8 (2026-02-08) =
Refactor: break down God classes into focused single-responsibility classes.

= 4.6.7 (2026-02-07) =
Accessibility: WCAG 2.1 AA compliance for all frontend components.

= 4.6.6 (2026-02-07) =
Reliability: standardized error handling across all modules.

= 4.6.5 (2026-02-07) =
Architecture: internal hook consumption for decoupled activity logging.

= 4.6.4 (2026-02-07) =
Extensibility: 31 action/filter hooks for developer customization.

= 4.6.3 (2026-02-07) =
Security: permission audit, missing capability checks added to admin handlers.

= 4.6.2 (2026-02-07) =
Performance: N+1 query fixes, 7 composite database indexes added.

= 4.6.1 (2026-02-07) =
Security hardening, accessibility, slug change to `ffcertificate`, structural refactoring.

= 4.6.0 (2026-02-06) =
Scheduling consolidation, user dashboard improvements, global holidays, bug fixes.

= 4.5.0 (2026-02-05) =
Complete audience scheduling system for group bookings. 5 new database tables.

= 4.4.0 (2026-02-04) =
Per-user capability system, self-scheduling rename.

= 4.3.0 (2026-02-02) =
WordPress Plugin Check compliance and distribution cleanup.

= 4.2.0 (2026-01-30) =
CSV export enhancements and calendar translations.

= 4.1.0 (2026-01-27) =
Appointment calendar and booking system. 3 new database tables.

= 4.0.0 (2026-01-26) =
Breaking: removed backward-compatibility aliases, namespace finalization.

= 3.0.0 - 3.3.1 =
Repository pattern, REST API, strict types, PSR-4 autoloader, user dashboard.

= 1.0.0 - 2.10.0 =
Initial release through rate limiting. Core form builder, PDF generation, magic links, QR codes.

== Upgrade Notice ==

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
