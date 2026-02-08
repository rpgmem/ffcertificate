=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 4.6.13
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Create dynamic forms, generate PDF certificates, and validate authenticity with magic link access.

== Description ==

Free Form Certificate is a complete WordPress solution for creating dynamic forms, generating PDF certificates, scheduling appointments, and verifying document authenticity. Built with a fully namespaced, modular architecture using the Repository pattern and Strategy pattern for maximum maintainability.

= Core Features =

* **Drag & Drop Form Builder** - Custom fields: Text, Email, Number, Date, Select, Radio, Textarea, Hidden.
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
* **CSV Import** - Import audiences and members from CSV files with user creation.
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

= 4.6.13 (2026-02-08) =

Performance: Query caching, conditional loading, and N+1 elimination. Quality: i18n, documentation, icon CSS refactor.

* Perf: Cache RateLimiter settings in static variable (eliminates 10+ repeated get_option + __() calls per request)
* Perf: Cache SHOW TABLES check in AdminUserColumns (eliminates N+1 query per user row on users list)
* Perf: Cache dashboard URL in AdminUserColumns render_user_actions (eliminates repeated get_option per row)
* Perf: Cache INFORMATION_SCHEMA column existence checks in SubmissionRepository (eliminates repeated schema queries)
* Perf: Fix ActivityLog get_submission_logs() to use existing cached get_table_columns() instead of raw DESCRIBE
* Perf: Conditional class loading — skip admin-only classes (CsvExporter, Admin, AdminAjax, AdminUserColumns, AdminUserCapabilities, SelfSchedulingAdmin, SelfSchedulingEditor, AppointmentCsvExporter) on frontend page loads
* i18n: Wrap 7 hardcoded strings (4 Portuguese, 3 English) with __() for proper translation support
* Docs: Add missing shortcodes [ffc_self_scheduling] and [ffc_audience] to documentation tab
* Docs: Add missing PDF placeholders {{submission_id}}, {{main_address}}, {{site_name}} to documentation tab
* Refactor: Move 40+ inline emoji icons from PHP/HTML to CSS utility classes (ffc-icon-*) in ffc-common.css

= 4.6.12 (2026-02-08) =

Quality: Unit testing, i18n compliance, and asset minification.

* New: Add PHPUnit test infrastructure (composer.json, phpunit.xml.dist, bootstrap)
* New: Add 14 unit tests covering Geofence bypass, ActivityLog buffer, and EmailHandler contexts
* New: Generate minified .min.css and .min.js for all 34 plugin assets (~45% average size reduction)
* New: Conditional asset loading — serve .min files in production, full files when SCRIPT_DEBUG is on
* New: Add Utils::asset_suffix() helper for consistent minification suffix across all enqueue calls
* Fix: Replace 13 hardcoded Portuguese strings in RateLimiter with __() for proper i18n
* Fix: PHPUnit bootstrap load order — define ABSPATH before requiring autoloader (prevents silent exit)

= 4.6.11 (2026-02-08) =

Security hardening: REST API protection, uninstall cleanup, deprecated API removal.

* Security: Add geofence validation (date/time + IP) to REST API form submission endpoint
* Security: Add rate limiting to REST API appointment creation endpoint
* Security: Remove error_reporting() suppression in REST controller (use output buffering only)
* New: Add uninstall.php — full cleanup of all tables, options, roles, capabilities, transients, and cron hooks on plugin deletion
* Fix: Replace all deprecated current_time('timestamp') calls (deprecated since WP 5.3) with time() + wp_date()
* Fix: Timezone-aware datetime comparisons in Geofence, AppointmentValidator, and AppointmentHandler using DateTimeImmutable + wp_timezone()

= 4.6.10 (2026-02-08) =

Fix: Race condition in concurrent appointment booking (TOCTOU vulnerability).

* Fix: Wrap validate + insert in MySQL transaction with row-level locking (FOR UPDATE)
* Fix: Add transaction support (begin/commit/rollback) to AbstractRepository
* Fix: AppointmentRepository::isSlotAvailable() now supports FOR UPDATE lock
* Fix: AppointmentRepository::getAppointmentsByDate() now supports FOR UPDATE lock
* Fix: AppointmentValidator accepts lock flag for capacity queries inside transaction
* Fix: Upgrade validation_code index from KEY to UNIQUE KEY (prevents duplicate codes)
* Fix: Catch exceptions during booking and rollback on failure

= 4.6.9 (2026-02-08) =

Performance: Activity Log optimization with batch writes, auto-cleanup, and stats caching.

* Perf: Buffer activity log writes and flush as single multi-row INSERT on shutdown (or at 20-entry threshold)
* Feature: Automatic log cleanup via daily cron with configurable retention period (default 90 days)
* Feature: Add "Log Retention (days)" setting under Settings > General > Activity Log
* Perf: Cache get_stats() results with 1-hour transient, invalidated on cleanup and settings save
* Fix: Activator schema mismatch — delegate to ActivityLog::create_table() for consistent schema
* Fix: MigrationManager used undefined LEVEL_CRITICAL, changed to LEVEL_ERROR
* Fix: Schedule ffc_daily_cleanup_hook cron on activation (was registered but never scheduled)
* Fix: Clear cron on plugin deactivation

= 4.6.8 (2026-02-08) =

Refactor: Break down God classes into focused single-responsibility classes.

* Refactor: Extract AppointmentValidator from AppointmentHandler (all validation logic)
* Refactor: Extract AppointmentAjaxHandler from AppointmentHandler (4 AJAX endpoints)
* Refactor: Slim AppointmentHandler from 1,027 to 457 lines (core business logic only)
* Refactor: Extract VerificationResponseRenderer from VerificationHandler (HTML rendering + PDF generation)
* Refactor: Slim VerificationHandler from 822 to 547 lines (search + verification logic only)
* Refactor: Wire AppointmentAjaxHandler via Loader using dependency injection

= 4.6.7 (2026-02-07) =

Accessibility: WCAG 2.1 AA compliance for all frontend components.

* A11y: Add aria-required="true" to all required form fields (forms, booking, verification, captcha)
* A11y: Add role="group" and aria-label to radio button groups
* A11y: Add role="dialog", aria-modal="true", aria-labelledby to booking modal
* A11y: Add focus trap inside booking modal (Tab/Shift+Tab cycle)
* A11y: Return focus to trigger element on modal close
* A11y: Time slots rendered with role="option", tabindex="0", keyboard support (Enter/Space)
* A11y: Dashboard tabs use role="tablist"/role="tab"/role="tabpanel" with aria-selected and aria-controls
* A11y: Arrow key navigation between dashboard tabs (Left/Right/Home/End)
* A11y: Replace all alert() calls with accessible inline messages (role="alert")
* A11y: Add aria-invalid and aria-describedby to validation errors (CPF/RF fields)
* A11y: Add role="status" and aria-live="polite" to loading indicators and result regions
* A11y: Add role="alert" and aria-live="assertive" to form error/success message containers
* A11y: Decorative emoji wrapped in aria-hidden="true" in dashboard tabs
* A11y: Focus management after AJAX operations (form errors, booking confirmation)
* A11y: Verification page auth code input gets aria-describedby linking to description text

= 4.6.6 (2026-02-07) =

Reliability: Standardize error handling across all modules.

* Fix: Encryption catch blocks now use \Exception (namespace bug prevented catching errors)
* Fix: wp_mail() return values checked and failures logged in EmailHandler and AppointmentEmailHandler
* Security: REST API catch blocks no longer expose internal exception messages to clients
* Improve: AJAX error responses now include structured error codes alongside messages
* Improve: WP_Error codes propagated through AJAX handlers (FormProcessor, AppointmentHandler)
* Improve: AbstractRepository logs $wpdb->last_error on insert/update/delete failures
* Refactor: AppointmentEmailHandler uses centralized send_mail() with failure logging
* Improve: Catch blocks in AppointmentHandler AJAX use debug_log instead of error_log/getMessage exposure

= 4.6.5 (2026-02-07) =

Architecture: Internal hook consumption — plugin uses its own hooks for activity logging.

* New: ActivityLogSubscriber class listens to ffc_ hooks for decoupled logging
* Refactor: Removed direct ActivityLog calls from SubmissionHandler (5 calls → hook-based)
* Refactor: Removed direct ActivityLog calls from AppointmentHandler (2 calls → hook-based)
* New: ffc_settings_saved hook now triggers cache invalidation (options + transients)
* Architecture: Plugin "eats its own dog food" — business logic decoupled from logging

= 4.6.4 (2026-02-07) =

Extensibility: Add 31 action/filter hooks for developer customization.

* Submissions: ffc_before_submission_save, ffc_after_submission_save, ffc_before_submission_update, ffc_after_submission_update, ffc_submission_trashed, ffc_submission_restored, ffc_before_submission_delete, ffc_after_submission_delete
* PDF/Certificate: ffc_certificate_data, ffc_certificate_html, ffc_certificate_filename, ffc_after_pdf_generation
* QR Code: ffc_qrcode_url, ffc_qrcode_html
* Email: ffc_before_email_send, ffc_user_email_subject, ffc_user_email_recipients, ffc_user_email_body, ffc_admin_email_recipients, ffc_scheduling_email
* Appointments: ffc_before_appointment_create, ffc_after_appointment_create, ffc_appointment_cancelled, ffc_available_slots
* Audience: ffc_before_audience_booking_create (existing: ffc_audience_booking_created, ffc_audience_booking_cancelled)
* Settings: ffc_settings_before_save, ffc_settings_saved, ffc_before_data_deletion
* Export: ffc_csv_export_data

= 4.6.3 (2026-02-07) =

Security: Permission audit — add missing capability checks to admin handlers.

* Security: Added `current_user_can('manage_options')` to SettingsSaveHandler (covers all settings + danger zone)
* Security: Added capability check to migration execution handler
* Security: Added capability check to cache warm/clear actions
* Security: Added capability check to date format preview AJAX handler
* Security: Tightened audience booking REST write permission (requires `ffc_view_audience_bookings` capability)

= 4.6.2 (2026-02-07) =

Performance: Fix N+1 queries and add composite database indexes.

* Performance: Batch load form titles in submissions list (replaces per-row get_the_title)
* Performance: Batch load calendars in user appointments REST endpoint (replaces per-row findById)
* Performance: Batch load audiences in user audience-bookings REST endpoint (replaces per-row query)
* Performance: Batch load user data in admin bookings list (replaces per-row get_userdata)
* Performance: Added findByIds() batch method to AbstractRepository for reusable multi-ID lookups
* Database: Added composite index idx_form_status (form_id, status) on submissions table
* Database: Added composite index idx_status_submission_date (status, submission_date) on submissions table
* Database: Added composite index idx_email_hash_form_id (email_hash, form_id) on submissions table
* Database: Added composite index idx_calendar_status_date (calendar_id, status, appointment_date) on appointments table
* Database: Added composite index idx_user_status (user_id, status) on appointments table
* Database: Added composite index idx_date_status (booking_date, status) on audience bookings table
* Database: Added composite index idx_created_by_date (created_by, booking_date) on audience bookings table

= 4.6.1 (2026-02-07) =

Security, accessibility, code quality, and structural refactoring.

* Security: Fixed SQL injection vulnerabilities with prepared statements in repository queries
* Security: Added `current_user_can('manage_options')` capability checks to audience admin form handlers
* Security: Externalized inline CSS and JavaScript to proper asset files (XSS hardening)
* Accessibility: Added `prefers-reduced-motion` media queries to all animations and transitions
* Accessibility: Added `focus-visible` styles for keyboard navigation across admin and frontend
* Accessibility: Added `role="presentation"` to all layout tables and `<tbody>` for HTML consistency
* Compatibility: Added vendor prefixes (`-webkit-`, `-moz-`) for cross-browser CSS support
* Refactored: Split `AudienceAdminPage` (~2,300 lines) into coordinator + 7 focused sub-classes
* Refactored: Split `RestController` (~1,940 lines) into coordinator + 5 domain-specific sub-controllers
* Improved: Renamed calendar asset files with `ffc-` prefix for naming consistency
* Improved: Removed duplicate CSS declarations across stylesheets
* Fixed: Frontend CSS duplication causing style conflicts
* Fixed: Restored `Loader::run()` method accidentally removed during refactoring
* New classes: `AudienceAdminDashboard`, `AudienceAdminCalendar`, `AudienceAdminEnvironment`, `AudienceAdminAudience`, `AudienceAdminBookings`, `AudienceAdminSettings`, `AudienceAdminImport`, `FormRestController`, `SubmissionRestController`, `UserDataRestController`, `CalendarRestController`, `AppointmentRestController`
* Changed: Plugin slug from `wp-ffcertificate` to `ffcertificate` (removed restricted "wp-" prefix)
* Changed: Text domain from `wp-ffcertificate` to `ffcertificate`
* Changed: Hook prefix from `wp_ffcertificate_` to `ffcertificate_`
* Changed: Language files renamed to match new text domain

= 4.6.0 (2026-02-06) =

Scheduling consolidation, user dashboard improvements, and bug fixes.

* Added: Unified scheduling admin menu with visual separators between Self-Scheduling and Audience sections
* Added: Scheduling Dashboard with stats cards (calendars, appointments, environments, audiences, bookings)
* Added: Unified Settings page with tabs for Self-Scheduling, Audience, and Global Holidays
* Added: Global holidays system blocking bookings across all calendars in both scheduling systems
* Added: Pagination to user dashboard (certificates, appointments, audience bookings)
* Added: Audience groups display on user profile tab
* Added: Upcoming/Past/Cancelled section separators on appointments tab (matching audience tab)
* Added: Holiday and Closed legend/display on self-scheduling calendar frontend
* Added: Dashboard icon in admin submenu
* Improved: Cancel button only visible for future appointments respecting cancellation deadline
* Improved: Audience tab column alignment with fixed-width layout and one-tag-per-line
* Improved: Calendar frontend styles consistent for logged-in and anonymous users
* Improved: Tab labels renamed for clarity (Personal Schedule, Group Schedule, Profile)
* Improved: Stat card labels moved to top of each card
* Fixed: 500 error on profile endpoint (missing `global $wpdb`)
* Fixed: SyntaxError on calendar page (`&&` mangled by `wptexturize`; moved to external JS with JSON config)
* Fixed: Self-scheduling calendar not rendering (wp_localize_script timing issue; switched to JSON script tag)
* Fixed: Empty audiences column (wrong table name `ffc_audience_audiences` → `ffc_audiences`)
* Fixed: Cancel appointment 500 error (TypeError: string given to `findById()` expecting int)
* Fixed: Error handling in AJAX handlers (use `\Throwable` instead of `\Exception`)
* Fixed: Appointments tab showing time in date column
* Fixed: Dashboard tab font consistency
* Fixed: Missing `ffc-audience-admin.js` and calendar-admin assets causing 404s
* Updated: 278 missing pt_BR translations for audience/scheduling system
* Fixed: Incorrect translation for `{{submission_date}}` format description

= 4.5.0 (2026-02-05) =

Audience scheduling system and unified calendar component.

* Added: Complete audience scheduling system for group bookings (`[ffc_audience_calendar]` shortcode)
* Added: Audience management with hierarchical groups (2-level), color coding, and member management
* Added: Environment management (physical spaces) with per-environment calendars and working hours
* Added: Group booking modal with audience/individual user selection and conflict detection
* Added: CSV import for audiences (name, color, parent) and members (email, name, audience)
* Added: Email notifications for new bookings and cancellations with audience details
* Added: Admin bookings list page with filters by schedule, environment, status, and date range
* Added: Audience bookings tab in user dashboard with monthly calendar view
* Added: Shared `FFCCalendarCore` JavaScript component for both calendar systems
* Added: Unified visual styles (`ffc-common.css`) shared between Self-Scheduling and Audience calendars
* Added: Calendar ID and Shortcode fields on calendar edit page
* Added: Holidays management interface with closed days display in calendar
* Added: Environment selector dropdown in booking modal
* Added: Filter to show/hide cancelled bookings in day modal
* Added: REST API endpoints for audience bookings with conflict checking
* Fixed: Autoloader for `SelfScheduling` namespace file naming
* Fixed: Multiple int cast issues for repository method calls with database values
* Fixed: Date parsing timezone offset issues in calendar frontend
* Fixed: AJAX loop prevention in booking counts fetch
* New tables: `ffc_audiences`, `ffc_audience_members`, `ffc_environments`, `ffc_audience_bookings`, `ffc_audience_booking_targets`
* New classes: `AudienceAdminPage`, `AudienceShortcode`, `AudienceLoader`, `AudienceRestController`, `AudienceCsvImporter`, `AudienceNotificationHandler`, `AudienceRepository`, `EnvironmentRepository`, `AudienceBookingRepository`, `EmailTemplateService`

= 4.4.0 (2026-02-04) =

Per-user capability system and self-scheduling rename.

* Added: Per-user capability system for certificates and appointments (`ffc_view_own_certificates`, `ffc_cancel_own_appointments`, etc.)
* Added: User Access settings tab for configuring default capabilities per role
* Added: Capability migration for existing users based on submission/appointment history
* Renamed: Calendar system to "Self-Scheduling" (Personal Calendars) for clarity
* Renamed: CPT labels from "FFC Calendar" to "Personal Calendar" / "Personal Calendars"
* Improved: Self-scheduling hooks and capabilities prefixed with `ffc_self_scheduling_`

= 4.3.0 (2026-02-02) =

WordPress Plugin Check compliance and distribution cleanup.

* All output escaped with `esc_html()`, `esc_attr()`, `wp_kses()`
* All input sanitized with `sanitize_text_field()`, `absint()`, `wp_unslash()`
* Nonce verification on all form submissions and admin actions
* Translator comments on all strings with placeholders
* Ordered placeholders (`%1$s`, `%2$s`) in all translation strings
* CDN scripts replaced with locally bundled copies (html2canvas 1.4.1, jsPDF 2.5.1)
* `date()` replaced with `gmdate()`, `rand()` with `wp_rand()`, `wp_redirect()` with `wp_safe_redirect()`
* `parse_url()` replaced with `wp_parse_url()`, `unlink()` with `wp_delete_file()`
* Text domain changed from `ffc` to `ffcertificate`
* Translation files renamed to match new text domain
* Removed development files from distribution (tests, docs, CI, composer, phpqrcode cache)

= 4.2.0 (2026-01-30) =

CSV export enhancements and calendar translations.

* Added: Expand `custom_data` and `data_encrypted` JSON fields into individual CSV columns
* Added: Decrypt encrypted data for certificate CSV dynamic columns
* Added: 285 missing pt_BR translations for calendar and appointment system
* Updated: English language file with all new calendar strings

= 4.1.1 (2026-01-27) =

Appointment receipts, validation codes, and admin improvements.

* Added: Appointment receipt and confirmation page generation
* Added: Appointment PDF generator for client-side receipts
* Added: Unique validation codes with formatted display (XXXX-XXXX-XXXX)
* Added: Appointments column in admin users list
* Added: Login-as-user link always visible in users list
* Added: Permission checks to dashboard tabs visibility

= 4.1.0 (2026-01-27) =

New appointment calendar and booking system.

* Added: Calendar Custom Post Type with configurable time slots, durations, business hours, and capacity
* Added: Frontend booking widget with real-time slot availability (`[ffc_calendar]` shortcode)
* Added: Appointment booking handler with approval workflow (auto-approve or manual)
* Added: Email notifications: confirmation, approval, cancellation, and reminders
* Added: Admin calendar editor with blocked dates management
* Added: CSV export for appointments with date and status filters
* Added: REST API endpoints for calendars and appointments
* Added: CPF/RF field on booking forms with mask validation
* Added: Honeypot and math captcha security on booking forms
* Added: Automatic appointment cancellation when calendar is deleted
* Added: Minimum interval between bookings setting
* Added: Automatic migration for `cpf_rf` columns on appointments table
* Added: Appointment cleanup functionality in calendar settings
* Added: User creation on appointment confirmation
* New tables: `ffc_calendars`, `ffc_appointments`, `ffc_blocked_dates`
* New classes: `CalendarCpt`, `CalendarEditor`, `CalendarAdmin`, `CalendarActivator`, `CalendarShortcode`, `AppointmentHandler`, `AppointmentEmailHandler`, `AppointmentCsvExporter`, `CalendarRepository`, `AppointmentRepository`, `BlockedDateRepository`

= 4.0.0 (2026-01-26) =

Breaking release: removal of backward-compatibility aliases and namespace finalization.

* BREAKING: Removed all backward-compatibility aliases for old `FFC_*` class names
* All 88 classes now exclusively use `FreeFormCertificate\*` namespaces
* Converted all remaining `\FFC_*` references to fully qualified namespaces
* Renamed `CSVExporter` to `CsvExporter` for PSR naming consistency
* Removed all obsolete `require_once` statements (autoloader handles loading)
* Added global namespace prefix (`\`) to all WordPress core classes in namespaced files
* Fixed: Loader initialization with correct namespaced class references
* Fixed: Class autoloading for restructured file paths
* Fixed: PHPDoc type hints across 3 files
* Fixed: CSV export error handling, UTF-8 encoding, and multi-form filters
* Fixed: REST API 500 error from broken encrypted email search
* Fixed: `json_decode` null handling for PHP 8+ compatibility
* Enhanced: CSV export with all DB columns and multi-form filters
* Finalized PSR-4 cleanup across all modules

= 3.3.1 (2026-01-25) =

Bug fixes for strict types introduction.

* Fixed: Type errors caused by `strict_types` across multiple classes
* Fixed: String-to-int conversions for database IDs in multiple locations
* Fixed: Return type mismatches in `trash`/`restore`/`delete` operations (int|false to bool)
* Fixed: `log_submission_updated` call with correct parameter type
* Fixed: `update_submission` return type conversion to bool
* Fixed: `ensure_magic_token` to return string type consistently
* Fixed: `json_decode` null check in `detect_reprint`
* Fixed: `hasEditInfo` return type conversion to int
* Fixed: `form_id` and `edited_by` type casting in CSV export
* Fixed: Missing SMTP fields in settings save handler
* Fixed: Checkbox styles override for WordPress core compatibility
* Fixed: `$real_submission_date` initialization in both reprint and new submission paths
* Fixed: Null handling in `get_user_certificates` and `get_user_profile`
* Fixed: PHP notices in REST API preventing JSON output corruption

= 3.3.0 (2026-01-25) =
* Added: `declare(strict_types=1)` to all PHP files
* Added: Full type hints (parameter types, return types) across all classes
* Affected: Core, Repositories, Migration Strategies, Settings Tabs, User Dashboard, Shortcodes, Security, Generators, Frontend, Integrations, Submissions

= 3.2.0 (2026-01-25) =
* Added: PSR-4 autoloader (`class-ffc-autoloader.php`) with namespace-to-directory mapping
* Migrated: All 88 classes to PHP namespaces in 15 migration steps
* Namespaces: `FreeFormCertificate\Admin`, `API`, `Calendars`, `Core`, `Frontend`, `Generators`, `Integrations`, `Migrations`, `Repositories`, `Security`, `Settings`, `Shortcodes`, `Submissions`, `UserDashboard`
* Added: Backward-compatibility aliases for all old `FFC_*` class names (removed in 4.0.0)
* Added: Developer migration guide and hooks documentation

= 3.1.0 (2026-01-24) =
* Added: User Dashboard system with `ffc_user` role and `[user_dashboard_personal]` shortcode
* Added: Access control class for permission management
* Added: User manager for dashboard data retrieval
* Added: Admin user columns (certificate count, appointment count)
* Added: Debug utility class with configurable logging
* Added: Activity Log admin viewer page with filtering
* Added: Admin assets manager for centralized enqueue
* Added: Admin submission edit page for manual record updates
* Added: Admin notice manager for migration feedback
* Added: Form editor metabox renderer (separated from save handler)
* Added: Dashboard page auto-creation on activation
* Refactored: Email handler focused on delivery (removed inline styles)
* Refactored: REST controller optimized
* Removed: All inline styles (moved to CSS files)
* Added: User creation email controls

= 3.0.0 (2026-01-20) =
* Added: Repository pattern (`AbstractRepository`, `SubmissionRepository`, `FormRepository`)
* Added: REST API controller for external integrations
* Added: Geofence class for GPS/IP-based area restrictions
* Added: IP Geolocation integration
* Added: Migration manager with batch processing
* Added: Data sanitizer for input cleaning
* Added: Migration status calculator
* Added: Page manager for auto-created plugin pages
* Added: Magic Link helper class
* Refactored: Frontend class as lightweight orchestrator
* Added: Complete JavaScript translations (admin, frontend, form editor, template manager)
* Added: Form Editor and Template Manager i18n
* Improved: GPS cache TTL configuration
* Improved: GPS validation with mandatory fields and meter units
* Fixed: Incomplete CPF/RF cleanup for LGPD compliance
* Fixed: OFFSET bug in batch migrations
* Fixed: Slow submission deletion causing 500 errors
* Fixed: Missing Activity Log methods

= 2.10.0 (2026-01-20) =
* Added: Rate Limiter with dedicated database tables (`ffc_rate_limits`, `ffc_rate_limit_logs`)
* Added: Rate Limit Activator for table creation
* Added: Configurable rate limit thresholds per action type
* Migrated: Rate Limiter from WordPress transients to Object Cache API

= 2.9.1 (2026-01-19) =
* Fixed: Magic Links fatal error (critical bug)
* Fixed: Duplicate `require` in loader
* Added: Activity Log with `ffc_activity_logs` table for audit trail
* Added: Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
* Added: Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

= 2.9.0 (2026-01-18) =
* Added: QR Code generation on certificates linking to verification page
* Added: QR Code generator class using phpqrcode library
* Added: QR Code settings tab with size and error correction configuration

= 2.8.0 (2026-01-16) =
* Added: Magic Links for one-click certificate access via email
* Added: Certificate preview page with modern responsive layout
* Added: `magic_token` column (VARCHAR 32) with database index on `ffc_submissions`
* Added: Automatic token generation using `random_bytes(16)` for all new submissions
* Added: Backward migration: token backfill for existing submissions on activation
* Added: Rate limiting for verification (10 attempts/minute per IP via transients)
* Added: `verify_by_magic_token()` method in Verification Handler
* Added: Magic link detection via `?token=` parameter in Shortcodes class
* Improved: Email template with magic link button, certificate preview, and fallback URL
* Improved: AJAX verification without page reload
* Improved: Frontend with loading spinner, download button state management

= 2.7.0 (2026-01-14) =
* Refactored: Complete modular architecture with 15 specialized classes
* Added: `FFC_Shortcodes` class for shortcode rendering
* Added: `FFC_Form_Processor` class for form validation and processing
* Added: `FFC_Verification_Handler` class for certificate verification
* Added: `FFC_Email_Handler` class for email functionality
* Added: `FFC_CSV_Exporter` class for CSV export operations
* Refactored: `FFC_Frontend` reduced from 600 to 150 lines (now orchestrator only)
* Refactored: `FFC_Submission_Handler` to pure CRUD operations (400 to 150 lines)
* Added: Dependency injection container in `FFC_Loader`
* Applied: Single Responsibility Principle (SRP) throughout

= 2.6.0 (2026-01-12) =
* Refactored: Complete code reorganization with modular OOP structure
* Separated: `class-ffc-cpt.php` (CPT registration only) from `class-ffc-form-editor.php` (metaboxes)
* Added: `update_submission()` and `delete_all_submissions()` methods
* Added: Full internationalization (i18n) with all PHP strings wrapped in `__()` / `_e()`
* Added: JavaScript localization via `wp_localize_script()`
* Added: `.pot` translation template file
* Consolidated: All inline styles moved to `ffc-admin.css` and `ffc-frontend.css`
* Removed: Dead code and redundancies
* Fixed: Missing method calls
* Fixed: Duplicate metabox registration
* Fixed: SMTP settings toggle visibility

= 2.5.0 (2026-01-10) =
* (Internal improvements)

= 2.4.0 (2026-01-04) =
* (Internal improvements)

= 2.3.0 (2026-01-03) =
* (Internal improvements)

= 2.2.0 (2025-12-24) =
* (Internal improvements)

= 2.1.0 (2025-12-23) =
* (Internal improvements)

= 2.0.0 (2025-12-22) =
* Refactored: PDF generation from simple image to high-fidelity A4 Landscape (1123x794px) using jsPDF
* Added: Dynamic Math Captcha with hash validation on backend
* Added: Honeypot field for spam bot protection
* Added: Reprint logic for certificate recovery (duplicate detection)
* Added: PDF download buttons directly in admin submissions list
* Added: Mobile optimization with strategic delays and progress overlay
* Fixed: CORS issues with `crossorigin="anonymous"` on image rendering

= 1.5.0 (2025-12-18) =
* Added: Ticket system with single-use codes for exclusive form access
* Added: Form cloning (duplication) functionality
* Added: Global settings tab with automatic log cleanup configuration
* Added: Denylist for blocking specific IDs

= 1.0.0 (2025-12-14) =
* Initial release
* Form Builder with drag & drop interface (Text, Email, Number, Date, Select, Radio, Textarea, Hidden fields)
* PDF certificate generation (client-side)
* CSV export with form and date filters
* Submissions management in admin
* ID-based restriction (CPF/RF) with allowlist mode
* Asynchronous email notifications via WP-Cron
* Automatic cleanup of old submissions
* Verification shortcode `[ffc_verification]`

== Upgrade Notice ==

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
