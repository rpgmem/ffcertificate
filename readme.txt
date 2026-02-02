=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 4.0.0
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

= Appointment Calendar =

* **Calendar Management** - Create multiple calendars with configurable time slots, durations, and business hours.
* **Appointment Booking** - Frontend booking widget with real-time slot availability.
* **Email Notifications** - Confirmation, approval, cancellation, and reminder emails.
* **PDF Receipts** - Downloadable appointment receipts generated client-side.
* **Admin Dashboard** - Manage, approve, and export appointments.

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

1. Upload the `wp-ffcertificate` folder to `/wp-content/plugins/`.
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

The plugin is fully translation-ready with the `wp-ffcertificate` text domain. Use Loco Translate or Poedit with the `languages/wp-ffcertificate.pot` template file. Portuguese (Brazil) translation is included.

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

= [user_dashboard_personal] =
Displays the user's personal dashboard with their certificates and appointments.

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

= 4.0.0 (2026-02-02) =

Major release with complete architectural overhaul, new subsystems, and WordPress Plugin Check compliance.

**Architecture (Breaking Change):**
* Complete migration to PHP namespaces (`FreeFormCertificate\*`) - old `FFC_*` class names removed
* PSR-4 autoloader with 17 namespace modules
* Repository pattern for all database access (`AbstractRepository`, `SubmissionRepository`, `FormRepository`, `AppointmentRepository`, `CalendarRepository`, `BlockedDateRepository`)
* Strategy pattern for data migrations (`EncryptionMigration`, `FieldMigration`, `CleanupMigration`, `MagicTokenMigration`, `UserLinkMigration`)
* `declare(strict_types=1)` and full type hints across all classes
* 88 PHP classes organized in 14 directories

**Appointment Calendar System (New):**
* Calendar CPT with configurable time slots, durations, business hours, and capacity
* Frontend booking widget with real-time slot availability (`[ffc_calendar]` shortcode)
* Approval workflow: auto-approve or manual admin approval
* Email notifications: confirmation, approval, cancellation, and reminders
* PDF appointment receipts generated client-side
* Admin management: view, approve, cancel, and bulk cleanup appointments
* CSV export for appointments with date and status filters
* Automatic appointment cancellation when calendar is deleted
* CPF/RF field, honeypot, and math captcha on booking forms
* Blocked dates management per calendar

**User Dashboard (New):**
* Personal frontend dashboard for logged-in users (`[user_dashboard_personal]` shortcode)
* View personal certificates and appointment history
* Custom `ffc_user` role with dashboard access
* Access control system with permission checks
* Admin user columns showing certificate and appointment counts

**REST API (New):**
* Full REST API controller for external integrations
* Endpoints for submissions, calendars, and appointments
* Authentication via WordPress REST API nonces

**Security & Data Protection (New):**
* Geofencing: restrict form access by GPS coordinates or IP-based areas with configurable radius
* GPS cache TTL configuration for performance
* Data encryption for sensitive fields (email, CPF/RF, IP) at rest
* IP Geolocation integration for location-based features
* LGPD/GDPR compliance: encrypted data cleanup migration

**Migration Framework (New):**
* Migration manager with batch processing and progress tracking
* Migration registry with dependency resolution
* 5 built-in strategies: encryption, field transformation, cleanup, magic token, user link
* Automatic AJAX processing without memory overload
* Migration status calculator for admin dashboard

**Administration (New):**
* Activity Log with full audit trail and admin viewer page
* Admin submission edit page for manual record updates
* Admin notice manager for migration and action feedback
* Assets manager for centralized CSS/JS enqueue
* Form editor split into renderer and save handler classes
* Settings tabs: General, SMTP, QR Code, Rate Limit, Geolocation, Migrations, User Access, Documentation
* Debug utility class with configurable logging

**Code Quality (WordPress Plugin Check):**
* All output escaped with `esc_html()`, `esc_attr()`, `wp_kses()`
* All input sanitized with `sanitize_text_field()`, `absint()`, `wp_unslash()`
* Nonce verification on all form submissions and admin actions
* Translator comments on all strings with placeholders
* Ordered placeholders (`%1$s`, `%2$s`) in all translation strings
* CDN scripts replaced with locally bundled copies (html2canvas 1.4.1, jsPDF 2.5.1)
* `date()` replaced with `gmdate()`, `rand()` with `wp_rand()`, `wp_redirect()` with `wp_safe_redirect()`
* `parse_url()` replaced with `wp_parse_url()`, `unlink()` with `wp_delete_file()`
* Text domain changed from `ffc` to `wp-ffcertificate`
* Translation files renamed to match new text domain
* Removed development files from distribution (tests, docs, CI, composer, phpqrcode cache)

**Database:**
* New tables: `ffc_calendars`, `ffc_appointments`, `ffc_blocked_dates`, `ffc_rate_limits`, `ffc_rate_limit_logs`, `ffc_activity_logs`
* New columns on `ffc_submissions`: `edited_at`, `edited_by`, `data_encrypted`, `email_encrypted`, `cpf_rf_encrypted`, `user_ip_encrypted`
* New columns on `ffc_forms`: encryption and user link support
* Automated migration system for all schema changes

= 3.3.0 (2026-01-25) =
* Added: `declare(strict_types=1)` to all PHP files
* Added: Full type hints (parameter types, return types) across all classes
* Affected: Core, Repositories, Migration Strategies, Settings Tabs, User Dashboard, Shortcodes, Security, Generators, Frontend, Integrations, Submissions

= 3.2.0 (2026-01-26) =
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

= 2.9.1 (2025-12-29) =
* Fixed: Magic Links fatal error (critical bug)
* Fixed: Duplicate `require` in loader
* Added: Activity Log with `ffc_activity_logs` table for audit trail
* Added: Form Cache with daily WP-Cron warming (`ffc_warm_cache_hook`)
* Added: Utils class with CPF validation and 20+ helper functions (`get_user_ip`, `format_cpf`, `sanitize_cpf`, etc.)

= 2.9.0 (2025-12-28) =
* Added: QR Code generation on certificates linking to verification page
* Added: QR Code generator class using phpqrcode library
* Added: QR Code settings tab with size and error correction configuration

= 2.8.0 (2025-12-28) =
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

= 2.7.0 (2025-12-28) =
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

= 2.6.0 (2025-12-28) =
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

= 2.0.0 =
* Refactored: PDF generation from simple image to high-fidelity A4 Landscape (1123x794px) using jsPDF
* Added: Dynamic Math Captcha with hash validation on backend
* Added: Honeypot field for spam bot protection
* Added: Reprint logic for certificate recovery (duplicate detection)
* Added: PDF download buttons directly in admin submissions list
* Added: Mobile optimization with strategic delays and progress overlay
* Fixed: CORS issues with `crossorigin="anonymous"` on image rendering

= 1.5.0 =
* Added: Ticket system with single-use codes for exclusive form access
* Added: Form cloning (duplication) functionality
* Added: Global settings tab with automatic log cleanup configuration
* Added: Denylist for blocking specific IDs

= 1.0.0 =
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

= 4.0.0 =
Breaking release. All classes migrated to PHP namespaces - old FFC_* class names no longer available. New calendar system, user dashboard, QR codes, geofencing, encryption, and migration framework. 6 new database tables created automatically. Backup recommended. Requires PHP 7.4+.

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
