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

Major release with complete architectural overhaul, new features, and WordPress Plugin Check compliance.

**Architecture:**
* Complete migration to PHP namespaces (`FreeFormCertificate\*`)
* PSR-4 autoloader with 17 namespace modules
* Repository pattern for all database access
* Strategy pattern for data migrations
* 88 PHP classes organized in 14 directories

**New Features:**
* Appointment Calendar system with booking, approval workflow, email notifications, and PDF receipts
* User Dashboard with personal certificate and appointment management (`[user_dashboard_personal]`)
* REST API controller for external integrations
* QR Code generator on certificates linking to verification page
* Geofencing with GPS and IP-based area restrictions
* Data encryption for sensitive fields (email, CPF, IP)
* Migration framework with progress tracking, batch processing, and 5 built-in strategies
* Activity Log with full audit trail
* IP Geolocation integration
* Admin submission edit page
* Admin user columns for certificate counts

**Improvements:**
* WordPress Plugin Check compliance (security, escaping, sanitization, nonce verification)
* All output properly escaped with `esc_html()`, `esc_attr()`, `wp_kses()`
* All input sanitized with `sanitize_text_field()`, `absint()`, `wp_unslash()`
* Nonce verification on all form submissions and admin actions
* Translator comments on all translation strings with placeholders
* Ordered placeholders (`%1$s`, `%2$s`) in all translation strings
* CDN scripts replaced with locally bundled copies (html2canvas, jsPDF)
* Text domain changed from `ffc` to `wp-ffcertificate`

**Database:**
* New tables: `ffc_calendars`, `ffc_appointments`, `ffc_blocked_dates`, `ffc_rate_limits`, `ffc_rate_limit_logs`, `ffc_activity_logs`
* New columns on `ffc_submissions`: encryption support, user link fields
* Automated migration system for schema changes

= 2.9.1 (2025-12-29) =
* Fixed: Magic links fatal error (critical)
* Fixed: Duplicate require in loader
* Added: Rate Limiter with configurable thresholds
* Added: Activity Log audit system
* Added: Form Cache for performance
* Added: CPF validation and 20+ utility helper functions

= 2.9.0 (2025-12-28) =
* Added: QR Code generation on certificates

= 2.8.0 (2025-12-28) =
* Added: Magic Links for one-click certificate access via email
* Added: Certificate preview page with modern responsive layout
* Added: Magic token column with database index
* Added: Automatic token backfill for existing submissions
* Added: Rate limiting (10 attempts/minute per IP) for verification
* Improved: Email template with magic link button and certificate preview
* Improved: AJAX verification without page reload

= 2.7.0 (2025-12-28) =
* Refactored: Complete modular architecture with 15 specialized classes
* Refactored: FFC_Frontend reduced from 600 to 150 lines
* Applied: Single Responsibility Principle throughout
* Added: Dependency injection via FFC_Loader

= 2.6.0 (2025-12-28) =
* Refactored: Complete code reorganization with modular OOP structure
* Added: Full internationalization (i18n) support with .pot file
* Added: Consolidated CSS (removed all inline styles)
* Fixed: Missing method calls, duplicate metabox registration, SMTP toggle

= 2.0.0 =
* Refactored: PDF generation from simple image to high-fidelity A4 Landscape using jsPDF
* Added: Dynamic Math Captcha with hash validation
* Added: Reprint logic for certificate recovery
* Added: PDF download buttons in admin submissions list
* Improved: Mobile optimization with progress overlay
* Fixed: CORS issues with image rendering

= 1.5.0 =
* Added: Ticket system with single-use codes
* Added: Form cloning functionality
* Added: Global settings tab with automatic log cleanup

= 1.0.0 =
* Initial release with Form Builder, PDF certificate generation, and CSV export

== Upgrade Notice ==

= 4.0.0 =
Major release. All classes migrated to PHP namespaces. New calendar system, user dashboard, QR codes, geofencing, and encryption. Database migration runs automatically. Backup recommended before updating. Requires PHP 7.4+.

= 2.8.0 =
New Magic Links feature for one-click certificate access. Database migration runs automatically. Backup recommended.

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
