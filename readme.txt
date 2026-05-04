=== Free Form Certificate ===
Contributors: alexmeusburger
Tags: certificate, form builder, pdf generation, verification, validation
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 6.2.0
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

= 6.2.0 (2026-05-04) =

**Capabilities + roles overhaul + admin UX scoping + upgrade safety + per-area debug toggles + role i18n fix.**

* Feat: 14 granular admin capabilities (cross-module + per-domain recruitment) replace blanket `manage_options` gates so site owners can delegate scoped roles without giving full WP admin.
* Feat: 9 new roles bundle the new caps — Certificate Manager, Self-Scheduling Manager, Audience Manager, Reregistration Manager, FFC Operator, plus a 4-tier recruitment ladder (Auditor → Operator → Manager → Admin).
* Feat: `AdminMenuVisibility` defense-in-depth UX layer hides core WP menus, blocks direct-URL access (redirect to role's landing page), and prunes the top admin bar per FFC role.
* Feat: 5 new debug areas (Frontend, Admin, Self-Scheduling, Audience, QR Code) with per-area toggles in **Settings → Advanced → Debug**. The 76 legacy `Utils::debug_log()` calls migrated to `Debug::log_*()`; `Utils::debug_log()` is now `@deprecated`. Frontend shortcode renders + other internal events no longer pollute production logs.
* Fix: FFC role labels now translate correctly on `wp-admin/users.php`. New `wp_roles_init` hook re-applies `__()` against the plugin's textdomain on every page load (WordPress's built-in `translate_user_role()` resolves against the **default** WP textdomain, so plugin role names never localized).
* Fix: 3 legacy non-namespaced certificate caps (`view_own_certificates`, `download_own_certificates`, `view_certificate_history`) renamed to the consistent `ffc_*` namespace with a one-time idempotent migration.
* Fix: Pre-existing in-place-upgrade bug — recruitment tables left un-created when bypassing reactivation. `RecruitmentActivator::create_tables()` + role registrations now run on `plugins_loaded` (idempotent self-heal).

= 6.1.0 (2026-05-02) =

**Recruitment admin UX parity + dashboard integration + Preliminary list visual axis.** Closes #90.

* Feat: Recruitment admin moves from inline `<table>` placeholders to full `WP_List_Table` listings (sort, search, paginate, bulk actions, row actions) for Notices / Adjutancies / Candidates / Reasons.
* Feat: Dedicated edit screens for notices (5 sections incl. transitions, attach/detach, CSV import, classifications) + candidates (general, sensitive data, classification + call history, hard-delete) + reasons.
* Feat: Notice ↔ adjutancy attach UI + REST — fixes a `recruitment_notice_has_no_adjutancies` 400 that blocked CSV imports on fresh installs.
* Feat: Preliminary visual statuses (Empty / Denied / Granted / Appeal denied / Appeal granted) + global Reasons catalog without touching the §5.2 state machine. Schema migration v5 + v6 (adds `preview_status`, `preview_reason_id`, `time_points`, `hab_emebs`).
* Feat: Per-adjutancy + per-status configurable badge colors via `<input type="color">`.
* Feat: Public shortcode polish — adjutancy filter (functional now; was silently ignored), name search (`?q=`), subscription-type filter (PCD/GERAL), persistent filter UI, BR-format dates (DD-MM-YYYY) + 2-decimal scores, semicolon CSV delimiter auto-detection.
* Feat: Out-of-order call confirm + reason prompt (single + bulk); 12h public-shortcode cache TTL with admin-write invalidation; CSV import activity indicator.
* Change: Notice status `active` → `definitive` (schema migrations v2 + v3 cover both upgrade cohorts).
* Perf: Public shortcode candidate-fetch — N round-trips → 1 via `RecruitmentCandidateRepository::get_by_ids()`. 30–50% cold-cache improvement.

= 6.0.0 (2026-05-01) =

**Recruitment module — Brazilian public-tender ("concurso público") candidate queue management.** PHPStan level 7 → 8 (no baseline).

* Feat: New `[ffc_recruitment_queue]` public shortcode + candidate-self `[ffc_recruitment_my_calls]` dashboard section + wp-admin "Recrutamento" submenu.
* Feat: 21-route admin REST surface under `ffcertificate/v1/recruitment` (notices, classifications, candidates, adjutancies, calls, /me/recruitment).
* Feat: `ffc_manage_recruitment` capability + dedicated `ffc_recruitment_manager` role for delegation without giving `manage_options`.
* Feat: Atomic CSV importer (single-transaction wipe + reinsert with rollback on any validation error).
* Feat: Two state machines — Notice (draft → preliminary → active → closed) + Classification (empty → called → accepted → hired/not_shown) with §5.1 reopen-freeze rule that locks `hired`/`not_shown` once a notice has been reopened.
* Feat: Convocation service (single + bulk + cancel with append-only call history); email dispatch on call create with masked PII placeholders.
* Feat: Centralized §7-bis delete gating (candidate hard-delete only when zero classifications; classification individual delete only when `empty` + draft/preliminary).
* Feat: Submissions admin "Move to form…" bulk action with identifier-based conflict detection.
* Change: PHPStan bumped from level 7 (231-entry baseline) to **level 8 with zero errors and no baseline**. ~70 production call sites + ~80 test references migrated away from `Utils::*` deprecated shims.

For the complete changelog history, see [CHANGELOG.md](CHANGELOG.md).

== Upgrade Notice ==

= 6.2.0 =
Capabilities + roles overhaul: 14 new granular admin caps replace blanket `manage_options` gates, 9 new roles bundle the caps for delegation. Defense-in-depth admin UX layer hides core WP menus + blocks direct-URL access per role. 5 new debug-area toggles in **Settings → Advanced → Debug**. Fixes role translation on `wp-admin/users.php`. Fixes pre-existing in-place-upgrade bug that left recruitment tables un-created. 3 legacy certificate caps renamed to `ffc_*` namespace with one-time idempotent migration. No data loss; backup recommended.

= 6.1.0 =
Recruitment admin UX parity (full WP_List_Table backing, edit screens) + dashboard integration + Preliminary list visual axis. Schema migrations v2–v6 run automatically (notice status `active` → `definitive` rename + new columns for preview status / time points / hab_emebs + `ffc_recruitment_reason` table). Backup recommended before upgrade.

= 6.0.0 =
NEW Recruitment module — Brazilian public-tender candidate queue management. 21 admin REST routes, atomic CSV importer, two state machines, convocation service, dedicated `ffc_recruitment_manager` role. PHPStan level 7 → 8 with zero baseline. 6 new InnoDB tables created on activation. Backup recommended.

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
